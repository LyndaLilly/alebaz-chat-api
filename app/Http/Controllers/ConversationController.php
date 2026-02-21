<?php
namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationController extends Controller
{
    public function createOrGetDm(Request $request)
    {
        $request->validate([
            'user_id' => ['required', 'integer', 'exists:clients,id'],
        ]);

        /** @var \App\Models\Client|null $me */
        $me = auth('client')->user();

        if (! $me) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $otherId = (int) $request->input('user_id');

        if ($me->id === $otherId) {
            return response()->json([
                'message' => 'You cannot start a chat with yourself.',
            ], 422);
        }

        $conversation = DB::transaction(function () use ($me, $otherId) {

            // Check if DM already exists
            $existing = Conversation::query()
                ->where('type', 'dm')
                ->whereHas('participants', fn($q) =>
                    $q->where('user_id', $me->id)
                )
                ->whereHas('participants', fn($q) =>
                    $q->where('user_id', $otherId)
                )
                ->first();

            if ($existing) {
                return $existing->load([
                    'clients:id,username,profile_image',
                    'participants',
                ]);
            }

            // Create conversation
            $conv = Conversation::create([
                'type'       => 'dm',
                'created_by' => $me->id,
            ]);

            // Add participants
            ConversationParticipant::create([
                'conversation_id' => $conv->id,
                'user_id'         => $me->id,
                'role'            => 'member',
                'created_at'      => now(),
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conv->id,
                'user_id'         => $otherId,
                'role'            => 'member',
                'created_at'      => now(),
            ]);

            return $conv->load([
                'clients:id,username,profile_image',
                'participants',
            ]);
        });

        return response()->json([
            'conversation' => $conversation,
        ]);
    }

    public function index(Request $request)
    {
        Log::info('GET /conversations index() hit');

        $me = auth('client')->user();
        if (! $me) {
            Log::warning('Conversations index: unauthenticated');
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        Log::info('Conversations index: auth ok', ['me_id' => $me->id]);

        try {
            // Optional: log SQL queries (super helpful)
            DB::listen(function ($query) {
                Log::debug('SQL', [
                    'sql'      => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms'  => $query->time,
                ]);
            });

            Log::info('Conversations index: building query...');

            $conversations = Conversation::query()
                ->whereHas('participants', function ($q) use ($me) {
                    $q->where('user_id', $me->id);
                })
                ->with([
                    'clients:id,username,profile_image',
                    'latestMessage',
                ])
                ->orderByDesc(
                    Message::select('created_at')
                        ->whereColumn('messages.conversation_id', 'conversations.id')
                        ->latest()
                        ->take(1)
                )
                ->get();

            Log::info('Conversations index: fetched conversations', [
                'count' => $conversations->count(),
            ]);

            $data = $conversations->map(function ($c) use ($me) {
                $other = $c->clients->firstWhere('id', '!=', $me->id);

                return [
                    'id'           => $c->id,
                    'type'         => $c->type,
                    'other'        => $other ? [
                        'id'            => $other->id,
                        'username'      => $other->username,
                        'profile_image' => $other->profile_image ? url($other->profile_image) : null,
                    ] : null,
                    'last_message' => $c->latestMessage ? [
                        'body'       => $c->latestMessage->body,
                        'created_at' => $c->latestMessage->created_at,
                        'sender_id'  => $c->latestMessage->sender_id,
                    ] : null,
                ];
            });

            Log::info('Conversations index: mapping done');

            return response()->json(['conversations' => $data], 200);

        } catch (\Throwable $e) {
            Log::error('Conversations index ERROR', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                // full trace is long but useful:
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Server error (check laravel.log)',
            ], 500);
        }
    }
}
