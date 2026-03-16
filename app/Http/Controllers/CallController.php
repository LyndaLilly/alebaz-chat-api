<?php
namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CallController extends Controller
{

   

    public function active(Request $request)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $call = Call::query()
            ->where('status', '!=', 'ended')
            ->where(function ($q) use ($me) {
                $q->where('from_user_id', $me->id)
                    ->orWhere('to_user_id', $me->id);
            })
            ->orderByDesc('created_at')
            ->first();

        if (! $call) {
            return response()->json(['call' => null], 200);
        }

        $isCaller = (int) $call->from_user_id === (int) $me->id;
        $peerId   = $isCaller ? $call->to_user_id : $call->from_user_id;

        $peer = Client::select(['id', 'username', 'display_name', 'profile_image'])
            ->find($peerId);

        return response()->json([
            'call' => [
                'call_id'            => $call->id,
                'conversation_id'    => (int) $call->conversation_id,
                'type'               => $call->type,
                'status'             => $call->status,

                'from_user_id'       => (int) $call->from_user_id,
                'to_user_id'         => (int) $call->to_user_id,
                'is_caller'          => $isCaller,

                'in_call_started_at' => $call->in_call_started_at
                    ? $call->in_call_started_at->toISOString()
                    : null,

                'peer'               => $peer ? [
                    'id'            => $peer->id,
                    'name'          => $peer->display_name ?? $peer->username,
                    'profile_image' => $peer->profile_image,
                ] : null,
            ],
        ], 200);
    }

    // POST /api/calls/start
    public function start(Request $request)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'conversation_id' => ['required', 'integer'],
            'to_user_id'      => ['required', 'integer'],
            'type'            => ['required', 'in:voice,video'],
        ]);

        $toUserId = (int) $request->to_user_id;

        if ((int) $me->id === $toUserId) {
            return response()->json(['message' => 'You cannot call yourself'], 422);
        }

        // ✅ BUSY CHECK: block if ME is already in a call (any conversation)
        $myActive = Call::query()
            ->where('status', '!=', 'ended')
            ->where(function ($q) use ($me) {
                $q->where('from_user_id', $me->id)
                    ->orWhere('to_user_id', $me->id);
            })
            ->first();

        if ($myActive) {
            return response()->json([
                'message' => 'You are already on a call',
                'call_id' => $myActive->id,
            ], 409);
        }

        // ✅ BUSY CHECK: block if RECEIVER is already in a call
        $theirActive = Call::query()
            ->where('status', '!=', 'ended')
            ->where(function ($q) use ($toUserId) {
                $q->where('from_user_id', $toUserId)
                    ->orWhere('to_user_id', $toUserId);
            })
            ->first();

        if ($theirActive) {
            return response()->json([
                'message' => 'User is busy',
            ], 409);
        }

        $callId = (string) Str::uuid();

        // ✅ CREATE DB ROW
        Call::create([
            'id'              => $callId,
            'conversation_id' => (int) $request->conversation_id,
            'from_user_id'    => (int) $me->id,
            'to_user_id'      => $toUserId,
            'type'            => $request->type,
            'status'          => 'ringing',
            'started_at'      => now(),
        ]);

        broadcast(new \App\Events\CallIncoming([
            'call_id'          => $callId,
            'conversation_id'  => (int) $request->conversation_id,

            'from_user_id'     => (int) $me->id,
            'from_user_name'   => $me->display_name ?? $me->username,
            'from_user_avatar' => $me->profile_image,

            'to_user_id'       => $toUserId,
            'type'             => $request->type,
        ]))->toOthers();

        return response()->json(['call_id' => $callId], 201);
    }

    // POST /api/calls/{callId}/signal
    public function signal(Request $request, string $callId)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'to_user_id' => ['required', 'integer'],
            'data'       => ['required', 'array'],
        ]);

        $type = $request->data['type'] ?? null;

        if ($type === 'accepted') {
            Call::where('id', $callId)
                ->where('status', '!=', 'ended')
                ->update(['status' => 'connecting']);
        }

        if ($type === 'offer' || $type === 'answer') {
            // ✅ mark as in_call
            // ✅ set in_call_started_at ONCE (only if null)
            Call::where('id', $callId)
                ->where('status', '!=', 'ended')
                ->update([
                    'status' => 'in_call',
                ]);

            // set started timestamp only once
            Call::where('id', $callId)
                ->whereNull('in_call_started_at')
                ->where('status', '!=', 'ended')
                ->update([
                    'in_call_started_at' => now(),
                ]);
        }

        broadcast(new \App\Events\CallSignal([
            'call_id'      => $callId,
            'from_user_id' => (int) $me->id,
            'to_user_id'   => (int) $request->to_user_id,
            'data'         => $request->data,
        ]))->toOthers();

        return response()->json(['ok' => true], 200);
    }

    // POST /api/calls/{callId}/end
    public function end(Request $request, string $callId)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'to_user_id' => ['required', 'integer'],
            'reason'     => ['nullable', 'string'],
        ]);

        $reason = $request->reason ?? 'ended';

        // ✅ mark ended in DB
        Call::where('id', $callId)->update([
            'status'     => 'ended',
            'ended_at'   => now(),
            'ended_by'   => (int) $me->id,
            'end_reason' => $reason,
        ]);

        broadcast(new \App\Events\CallSignal([
            'call_id'      => $callId,
            'from_user_id' => (int) $me->id,
            'to_user_id'   => (int) $request->to_user_id,
            'data'         => [
                'type'    => 'end',
                'payload' => ['reason' => $reason],
            ],
        ]))->toOthers();

        return response()->json(['ok' => true], 200);
    }
}
