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

    private function imagePath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        // if already a full url, extract only the path part
        if (preg_match('/^https?:\/\//i', $path)) {
            $p    = parse_url($path, PHP_URL_PATH) ?: '';
            $path = ltrim($p, '/');
        }

        // remove leading "public/" if it exists
        $path = preg_replace('#^public/#', '', $path);

        // remove any leading slashes
        return ltrim($path, '/');
    }

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
                    $q->where('user_id', $me->id)
                        ->whereNull('hidden_at'); // ✅ hide chats "deleted" by THIS user only
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
                        'profile_image' => $this->imagePath($other->profile_image),
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
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Server error (check laravel.log)',
            ], 500);
        }
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

        $p->cleared_at = now();
        $p->save();

        return response()->json(['message' => 'Chat cleared for you'], 200);
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

        $p->hidden_at = now();
        $p->save();

        return response()->json(['message' => 'Chat deleted for you'], 200);
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

    public function messages(Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // ✅ Must be a participant AND not hidden (deleted) for this user
        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->first();

        if (! $participant) {
            return response()->json(['message' => 'Not a participant'], 403);
        }

        // Optional: if user deleted the chat, don’t allow loading messages
        if ($participant->hidden_at) {
            return response()->json(['message' => 'Chat deleted'], 404);
        }

        $query = Message::query()
            ->where('conversation_id', $conversation->id);

        // ✅ Clear chat: only show messages after cleared_at for THIS user
        if ($participant->cleared_at) {
            $query->where('created_at', '>', $participant->cleared_at);
        }

        $messages = $query->orderBy('created_at', 'asc')->get();

        return response()->json([
            'messages' => $messages->map(function ($m) {
                return [
                    'id'              => $m->id,
                    'conversation_id' => $m->conversation_id,
                    'sender_id'       => $m->sender_id,
                    'body'            => $m->body,
                    'created_at'      => $m->created_at,
                ];
            }),
        ], 200);
    }
}
