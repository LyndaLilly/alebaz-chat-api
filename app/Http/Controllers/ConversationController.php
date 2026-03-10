<?php
namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationController extends Controller
{

    private function imagePath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        // ✅ If already a full URL, DO NOT TOUCH IT
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        // ✅ Relative stored paths
        $path = preg_replace('#^public/#', '', $path);
        return ltrim($path, '/');
    }

    public function createOrGetDm(Request $request)
    {
        $request->validate([
            'user_id' => ['required', 'integer', 'exists:clients,id'],
        ]);

        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $otherId = (int) $request->input('user_id');

        if ((int) $me->id === $otherId) {
            return response()->json(['message' => 'You cannot start a chat with yourself.'], 422);
        }

        $conversation = DB::transaction(function () use ($me, $otherId) {

            $existing = Conversation::query()
                ->where('type', 'dm')
                ->whereHas('participants', fn($q) => $q->where('user_id', $me->id))
                ->whereHas('participants', fn($q) => $q->where('user_id', $otherId))
                ->first();

            if ($existing) {
                // ✅ STEP 2: Unhide chat for ME only (brings it back to sidebar)
                ConversationParticipant::where('conversation_id', $existing->id)
                    ->where('user_id', $me->id)
                    ->update([
                        'hidden_at'  => null,
                        'updated_at' => now(),
                    ]);

                // ✅ Do NOT touch cleared_message_id (so old messages stay gone for me)

                return $existing->load([
                    'clients:id,username,display_name,profile_image,user_type,seller_id',
                    'participants',
                ]);
            }

            $conv = Conversation::create([
                'type'       => 'dm',
                'created_by' => $me->id,
            ]);

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
                'clients:id,username,display_name,profile_image,user_type,seller_id',
                'participants',
            ]);
        });

        return response()->json(['conversation' => $conversation]);
    }

    public function createOrGetSellerDm(Request $request)
    {
        $request->validate([
            'seller_id' => ['required', 'integer'],
        ]);

        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $sellerId = (int) $request->input('seller_id');

        $seller = Seller::with(['professionalProfile', 'otherProfile'])
            ->select(['id', 'firstname', 'lastname', 'email', 'phone', 'is_professional'])
            ->find($sellerId);

        if (! $seller) {
            return response()->json(['message' => 'Seller not found'], 404);
        }

        // ✅ get profile image from market profiles
        $profileImage = ((int) $seller->is_professional === 1)
            ? optional($seller->professionalProfile)->profile_image
            : optional($seller->otherProfile)->profile_image;

        $profileImage = ltrim($profileImage ?? '', '/');
        $profileImage = preg_replace('#/+#', '/', $profileImage);

        $profileUrl = $profileImage
            ? "https://api.alebaz.com/public/uploads/" . $profileImage
            : null;

        $sellerClient = Client::firstOrCreate(
            ['seller_id' => $seller->id],
            [
                'user_type'     => 'seller',
                'username'      => 'seller_' . $seller->id,
                'display_name'  => trim($seller->firstname . ' ' . $seller->lastname),
                'email'         => $seller->email ?: ('seller_' . $seller->id . '@seller.local'),
                'phone'         => null,
                'verified'      => 1,
                'profile_image' => $profileUrl, // ✅ IMPORTANT
            ]
        );

        // ✅ keep it updated even if it already existed
        $sellerClient->display_name  = $sellerClient->display_name ?: trim($seller->firstname . ' ' . $seller->lastname);
        $sellerClient->profile_image = $profileUrl ?: $sellerClient->profile_image;
        $sellerClient->save();

        $dmReq = new Request(['user_id' => $sellerClient->id]);
        $dmReq->setUserResolver(fn() => $me);

        return $this->createOrGetDm($dmReq);
    }

    public function index(Request $request)
    {
        Log::info('GET /conversations index() hit');

        $me = auth('client')->user();
        if (! $me) {
            Log::warning('Conversations index: unauthenticated');
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $userId = (int) $me->id;

        try {
            $conversations = Conversation::query()
                ->join('conversation_participants as cp', function ($join) use ($userId) {
                    $join->on('cp.conversation_id', '=', 'conversations.id')
                        ->where('cp.user_id', '=', $userId)
                        ->whereNull('cp.hidden_at');
                })

            // ✅ IMPORTANT: explicit aliases
                ->selectRaw("
                    conversations.*,

                    cp.last_read_message_id AS cp_last_read_message_id,
                    cp.cleared_at           AS cp_cleared_at,
                    cp.cleared_message_id   AS cp_cleared_message_id,

                    (
                      SELECT m.id
                      FROM messages m
                      WHERE m.conversation_id = conversations.id
                        AND m.id > COALESCE(cp.cleared_message_id, 0)
                      ORDER BY m.id DESC
                      LIMIT 1
                    ) AS last_visible_message_id,

                  (
                    SELECT
                        CASE
                        WHEN m.deleted_at IS NOT NULL THEN 'This message was deleted'
                        ELSE m.body
                        END
                    FROM messages m
                    WHERE m.conversation_id = conversations.id
                        AND m.id > COALESCE(cp.cleared_message_id, 0)
                    ORDER BY m.id DESC
                    LIMIT 1
                    ) AS last_message_body,

                    (
                        SELECT m.deleted_at
                        FROM messages m
                        WHERE m.conversation_id = conversations.id
                            AND m.id > COALESCE(cp.cleared_message_id, 0)
                        ORDER BY m.id DESC
                        LIMIT 1
                        ) AS last_message_deleted_at,

                    (
                    SELECT m.message_type
                    FROM messages m
                    WHERE m.conversation_id = conversations.id
                        AND m.id > COALESCE(cp.cleared_message_id, 0)
                    ORDER BY m.id DESC
                    LIMIT 1
                    ) AS last_message_type,

                    (
                      SELECT m.created_at
                      FROM messages m
                      WHERE m.conversation_id = conversations.id
                        AND m.id > COALESCE(cp.cleared_message_id, 0)
                      ORDER BY m.id DESC
                      LIMIT 1
                    ) AS last_message_created_at,

                    (
                      SELECT m.sender_id
                      FROM messages m
                      WHERE m.conversation_id = conversations.id
                        AND m.id > COALESCE(cp.cleared_message_id, 0)
                      ORDER BY m.id DESC
                      LIMIT 1
                    ) AS last_message_sender_id,

                    (
                      SELECT COUNT(*)
                      FROM messages m
                      WHERE m.conversation_id = conversations.id
                        AND m.sender_id != {$userId}
                        AND m.id > COALESCE(cp.last_read_message_id, 0)
                        AND m.id > COALESCE(cp.cleared_message_id, 0)
                    ) AS unread_count
                ")

                ->with(['clients:id,username,display_name,profile_image,user_type,seller_id'])

            // ✅ DO NOT order by alias (same MySQL issue). Order by expression:
                ->orderByDesc(DB::raw("
                    COALESCE(
                        (
                            SELECT m.id
                            FROM messages m
                            WHERE m.conversation_id = conversations.id
                              AND m.id > COALESCE(cp.cleared_message_id, 0)
                            ORDER BY m.id DESC
                            LIMIT 1
                        ),
                        0
                    )
                "))

                ->get();

            $data = $conversations->map(function ($c) use ($me) {
                $other = $c->clients->firstWhere('id', '!=', $me->id);

                $preview = $c->last_message_body;

                if ($c->last_message_deleted_at) {
                    $preview = 'This message was deleted';
                }

                if (! $preview) {
                    $t = $c->last_message_type ?? null;

                    if ($t === 'audio') {
                        $preview = '🎤 Voice message';
                    } elseif ($t === 'image') {
                        $preview = '🖼️ Photo';
                    } elseif ($t === 'video') {
                        $preview = '🎥 Video';
                    } elseif ($t === 'file') {
                        $preview = '📎 Document';
                    } else {
                        $preview = null;
                    }
                }

                return [
                    'id'           => $c->id,
                    'type'         => $c->type,
                    'unread_count' => (int) ($c->unread_count ?? 0),

                    'other'        => $other ? [
                        'id'            => $other->id,
                        'username'      => $other->username,
                        'display_name'  => $other->display_name,
                        'profile_image' => $this->imagePath($other->profile_image),
                        'user_type'     => $other->user_type ?? null,
                        'seller_id'     => $other->seller_id ?? null,
                    ] : null,

                    'last_message' => $preview ? [
                        'body'         => $preview,
                        'created_at'   => $c->last_message_created_at,
                        'sender_id'    => $c->last_message_sender_id,
                        'message_type' => $c->last_message_type ?? 'text',
                    ] : null,
                ];
            });

            return response()->json(['conversations' => $data], 200);

        } catch (\Throwable $e) {
            Log::error('Conversations index ERROR', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Server error (check laravel.log)'], 500);
        }
    }

    public function messages(Request $request, Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        Log::info('✅ messages() HIT', [
            'me_id'           => $me->id,
            'conversation_id' => $conversation->id,
        ]);

        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->first();

        if (! $participant) {
            return response()->json(['message' => 'Not a participant'], 403);
        }

        Log::info('✅ messages() participant', [
            'me_id'              => $me->id,
            'conversation_id'    => $conversation->id,
            'cleared_message_id' => $participant->cleared_message_id,
        ]);

        if ($participant->hidden_at) {
            return response()->json([
                'messages' => [],
                'meta'     => [
                    'hidden' => true,
                    'note'   => 'Chat is hidden for this user',
                ],
            ], 200);
        }

        $query = Message::query()->where('conversation_id', $conversation->id);

        if (! is_null($participant->cleared_message_id)) {
            $query->where('id', '>', (int) $participant->cleared_message_id);
        }

        $messages = $query->orderBy('created_at', 'asc')->get();

        Log::info('✅ messages() returning', [
            'conversation_id' => $conversation->id,
            'count'           => $messages->count(),
            'first_id'        => $messages->first()?->id,
            'last_id'         => $messages->last()?->id,
        ]);

        return response()->json([
            'messages' => $messages->map(function ($m) use ($request) {

                return [
                    'id'              => $m->id,
                    'conversation_id' => $m->conversation_id,
                    'sender_id'       => $m->sender_id,
                    'body'            => $m->body,
                    'message_type'    => $m->message_type,

                    'audio_url'       => $m->audio_path
                        ? (preg_match('/^https?:\/\//i', $m->audio_path) ? $m->audio_path : url($m->audio_path))
                        : null,
                    'audio_duration'  => $m->audio_duration,

                    'file_url'        => $m->file_path
                        ? (preg_match('/^https?:\/\//i', $m->file_path) ? $m->file_path : url($m->file_path))
                        : null,
                    'file_name'       => $m->file_name,
                    'file_mime'       => $m->file_mime,
                    'file_size'       => $m->file_size,
                    'edited_at'       => $m->edited_at,
                    'deleted_at'      => $m->deleted_at,
                    'deleted_by'      => $m->deleted_by,

                    'created_at'      => $m->created_at,
                ];
            }),
        ], 200);

    }

    public function clearForMe(Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $p = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->first();

        if (! $p) {
            return response()->json(['message' => 'Not a participant'], 403);
        }

        // ✅ clear up to the current latest message in this conversation (both sides)
        $lastMsgId = (int) ($conversation->messages()->max('id') ?? 0);

        $p->cleared_at         = now();      // optional
        $p->cleared_message_id = $lastMsgId; // ✅ boundary
        $p->save();

        return response()->json([
            'message'            => 'Chat cleared for you',
            'cleared_message_id' => $lastMsgId,
        ], 200);
    }

    public function hideForMe(Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $p = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->first();

        if (! $p) {
            return response()->json(['message' => 'Not a participant'], 403);
        }

        // ✅ cutoff so old messages never show again for YOU
        $lastMsgId = (int) ($conversation->messages()->max('id') ?? 0);

        $p->cleared_at         = now();
        $p->cleared_message_id = $lastMsgId;

        // ✅ remove from sidebar
        $p->hidden_at = now();
        $p->save();

        return response()->json([
            'message'            => 'Chat deleted for you',
            'cleared_message_id' => $lastMsgId,
        ], 200);
    }

    public function unhideForMe(Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $p = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->first();

        if (! $p) {
            return response()->json(['message' => 'Not a participant'], 403);
        }

        $p->hidden_at = null;
        $p->save();

        return response()->json(['message' => 'Chat restored for you'], 200);
    }

    public function markAsRead(Request $request, Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $userId = (int) $me->id;

        Log::info('markAsRead hit', [
            'me_id'           => $userId,
            'conversation_id' => $conversation->id,
        ]);

        $p = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->first();

        if (! $p) {
            Log::warning('markAsRead not participant', [
                'me_id'           => $userId,
                'conversation_id' => $conversation->id,
            ]);
            return response()->json(['message' => 'Not a participant'], 403);
        }

        // ✅ Only mark read up to the latest message NOT sent by me
        $lastId = (int) ($conversation->messages()
                ->where('sender_id', '!=', $userId)
                ->max('id') ?? 0);

        Log::info('markAsRead computed lastId (other-only)', [
            'conversation_id' => $conversation->id,
            'me_id'           => $userId,
            'lastId'          => $lastId,
        ]);

        DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->update([
                'last_read_message_id' => $lastId,
                'updated_at'           => now(),
            ]);

        $after = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->first();

        Log::info('markAsRead updated participant', [
            'conversation_id'            => $conversation->id,
            'me_id'                      => $userId,
            'saved_last_read_message_id' => $after?->last_read_message_id,
            'cleared_at'                 => $after?->cleared_at,
        ]);

        $recipientIds = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $userId)
            ->pluck('user_id')
            ->all();

        broadcast(new \App\Events\ConversationRead(
            (int) $conversation->id,
            (int) $userId,
            (int) $lastId,
            $recipientIds
        ))->toOthers();

        return response()->json([
            'ok'                   => true,
            'last_read_message_id' => $lastId,
        ]);
    }
}
