<?php
namespace App\Http\Controllers;

use App\Models\CallRecording;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{

    private function imagePath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $path = preg_replace('#^public/#', '', $path);
        return ltrim($path, '/');
    }

    private function publicFileUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $path = preg_replace('#^public/#', '', $path);
        return url('public/' . ltrim($path, '/'));
    }

    public function index(Request $request, Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->first();

        if (! $participant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($participant->hidden_at) {
            return response()->json(['message' => 'Chat deleted'], 404);
        }

        $q = $conversation->messages()
            ->with('sender:id,username,profile_image')
            ->orderBy('id', 'asc')
            ->limit(200);

        if (! is_null($participant->cleared_message_id)) {
            $q->where('messages.id', '>', (int) $participant->cleared_message_id);
        }

        $messages        = $q->get();
        $otherLastReadId = (int) (ConversationParticipant::where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $me->id)
                ->value('last_read_message_id') ?? 0);

        return response()->json([
            'messages' => $messages->map(function ($m) use ($request) {

                Log::info('🧾 returning message urls', [
                    'message_id' => $m->id,
                    'file_path'  => $m->file_path,
                    'file_url'   => $this->publicFileUrl($m->file_path),
                    'app_url'    => config('app.url'),
                ]);
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

                   'file_url'       => $this->publicFileUrl($m->file_path),
                    'file_name'       => $m->file_name,
                    'file_mime'       => $m->file_mime,
                    'file_size'       => $m->file_size,

                    'edited_at'       => $m->edited_at,
                    'deleted_at'      => $m->deleted_at,
                    'deleted_by'      => $m->deleted_by,

                    'created_at'      => $m->created_at,

                    'sender'          => $m->sender ? [
                        'id'            => $m->sender->id,
                        'username'      => $m->sender->username,
                        'profile_image' => $this->imagePath($m->sender->profile_image),
                    ] : null,
                ];
            }),
            'meta'     => [
                'other_last_read_message_id' => $otherLastReadId,
            ],
        ], 200);
    }

    public function storeVoice(Request $request, Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // must be participant
        $isMember = $conversation->participants()
            ->where('user_id', $me->id)
            ->exists();

        if (! $isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'audio'    => ['required', 'file', 'max:10240', 'mimes:webm,ogg,mp3,wav,m4a,aac'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:600'],
        ]);

        $file = $request->file('audio');

        $ext  = strtolower($file->getClientOriginalExtension() ?: 'webm');
        $name = 'voice_' . time() . '_' . uniqid() . '.' . $ext;

        $dir = public_path('uploads/voice');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file->move($dir, $name);

        $relativePath = 'uploads/voice/' . $name;

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id'       => $me->id,
            'body'            => null,
            'message_type'    => 'audio',
            'audio_path'      => $relativePath,
            'audio_duration'  => $request->input('duration'),
            'created_at'      => now(),
        ]);

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $me->id)
            ->update([
                'hidden_at'  => null,
                'updated_at' => now(),
            ]);

        $msg->load('sender:id,username,profile_image');

        $recipientIds = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $me->id)
            ->pluck('user_id')
            ->all();

        broadcast(new \App\Events\MessageSent($msg, $recipientIds))->toOthers();

        return response()->json([
            'message' => [
                'id'              => $msg->id,
                'conversation_id' => $msg->conversation_id,
                'sender_id'       => $msg->sender_id,
                'body'            => $msg->body,
                'message_type'    => $msg->message_type,
                'audio_url'       => $msg->audio_path ? url($msg->audio_path) : null,
                'audio_duration'  => $msg->audio_duration,
                'created_at'      => $msg->created_at,
            ],
        ], 201);
    }

    public function storeFile(Request $request, Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        Log::info('📎 storeFile HIT', [
            'conversation_id' => $conversation->id,
            'me_id'           => $me->id,
            'has_file'        => $request->hasFile('file'),
            'caption_len'     => strlen((string) $request->input('caption', '')),
            'app_url'         => config('app.url'),
            'request_host'    => $request->getSchemeAndHttpHost(),
        ]);

        // must be participant
        $isMember = $conversation->participants()
            ->where('user_id', $me->id)
            ->exists();

        if (! $isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        Log::info('storeFile: reached controller');

        $request->validate([
            'file'    => ['required', 'file', 'max:102400'],
            'caption' => ['nullable', 'string', 'max:2000'],
        ]);

        Log::info('storeFile: passed validation', [
            'mime' => $request->file('file')?->getMimeType(),
            'size' => $request->file('file')?->getSize(),
        ]);
        $file = $request->file('file');

        Log::info('📎 storeFile incoming file', [
            'orig' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'ext'  => $file->getClientOriginalExtension(),
        ]);

        $origName = $file->getClientOriginalName();
        $mime     = $file->getMimeType() ?: 'application/octet-stream';
        $size     = $file->getSize();

        $ext  = strtolower($file->getClientOriginalExtension() ?: '');
        $name = 'file_' . time() . '_' . uniqid() . ($ext ? '.' . $ext : '');

        $dir = public_path('uploads/files');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file->move($dir, $name);

        $relativePath = 'uploads/files/' . $name;

        $generatedUrl = url($relativePath);

        Log::info('📎 storeFile saved paths', [
            'relativePath' => $relativePath,
            'generatedUrl' => $generatedUrl,
        ]);

        $type = str_starts_with($mime, 'image/')
            ? 'image'
            : (str_starts_with($mime, 'video/')
                ? 'video'
                : (str_starts_with($mime, 'audio/') ? 'audio' : 'file'));

        $caption = trim((string) $request->input('caption', ''));
        $caption = $caption !== '' ? $caption : null;

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id'       => $me->id,
            'body'            => $caption,
            'message_type'    => $type,

            'file_path'       => $relativePath,
            'file_name'       => $origName,
            'file_mime'       => $mime,
            'file_size'       => $size,

            'created_at'      => now(),
        ]);

        Log::info('📎 storeFile message created', [
            'message_id'   => $msg->id,
            'db_file_path' => $msg->file_path,
            'db_file_mime' => $msg->file_mime,
            'db_type'      => $msg->message_type,
        ]);

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $me->id)
            ->update([
                'hidden_at'  => null,
                'updated_at' => now(),
            ]);

        $msg->load('sender:id,username,profile_image');

        $recipientIds = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $me->id)
            ->pluck('user_id')
            ->all();

        broadcast(new \App\Events\MessageSent($msg, $recipientIds))->toOthers();

        return response()->json([
            'message' => [
                'id'              => $msg->id,
                'conversation_id' => $msg->conversation_id,
                'sender_id'       => $msg->sender_id,
                'body'            => $msg->body,
                'message_type'    => $msg->message_type,
                'file_url'        => $this->publicFileUrl($msg->file_path),
                'file_name'       => $msg->file_name,
                'file_mime'       => $msg->file_mime,
                'file_size'       => $msg->file_size,

                'created_at'      => $msg->created_at,
            ],
        ], 201);
    }

    public function store(Request $request, Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        // Security: must be participant
        $isMember = $conversation->participants()
            ->where('user_id', $me->id)
            ->exists();

        if (! $isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id'       => $me->id,
            'body'            => $request->input('body'),
            'message_type'    => 'text',
            'created_at'      => now(),
        ]);

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $me->id)
            ->update([
                'hidden_at'  => null,
                'updated_at' => now(),
            ]);

        $msg->load('sender:id,username,profile_image');

        $recipientIds = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $me->id)
            ->pluck('user_id')
            ->all();

        broadcast(new \App\Events\MessageSent($msg, $recipientIds))->toOthers();

        return response()->json([
            'message' => $msg,
        ], 201);
    }

    public function editMessage(Request $request, Conversation $conversation, Message $message)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // must belong to this conversation
        if ((int) $message->conversation_id !== (int) $conversation->id) {
            return response()->json(['message' => 'Invalid message'], 422);
        }

        // must be participant
        $isMember = $conversation->participants()->where('user_id', $me->id)->exists();
        if (! $isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // only sender can edit
        if ((int) $message->sender_id !== (int) $me->id) {
            return response()->json(['message' => 'You can only edit your own message'], 403);
        }

        // cannot edit deleted message
        if (! is_null($message->deleted_at)) {
            return response()->json(['message' => 'Message already deleted'], 422);
        }

        $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        // ✅ 10 minutes window from created_at
        $created = Carbon::parse($message->created_at);
        if (now()->diffInSeconds($created) > 10 * 60) {
            return response()->json(['message' => 'Edit window expired (10 mins)'], 422);
        }

        $message->body      = $request->input('body');
        $message->edited_at = now();
        $message->save();

        $recipientIds = \App\Models\ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $me->id)
            ->pluck('user_id')
            ->all();

        broadcast(new \App\Events\MessageUpdated($message, $recipientIds))->toOthers();

        return response()->json([
            'ok'      => true,
            'message' => [
                'id'              => $message->id,
                'conversation_id' => $message->conversation_id,
                'sender_id'       => $message->sender_id,
                'body'            => $message->body,
                'message_type'    => $message->message_type,
                'edited_at'       => $message->edited_at,
                'created_at'      => $message->created_at,
            ],
        ], 200);
    }

    public function deleteForEveryone(Request $request, Conversation $conversation, Message $message)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ((int) $message->conversation_id !== (int) $conversation->id) {
            return response()->json(['message' => 'Invalid message'], 422);
        }

        $isMember = $conversation->participants()->where('user_id', $me->id)->exists();
        if (! $isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $message->sender_id !== (int) $me->id) {
            return response()->json(['message' => 'You can only delete your own message'], 403);
        }

        if (! is_null($message->deleted_at)) {
            return response()->json(['message' => 'Message already deleted'], 422);
        }

        $otherLastRead = (int) DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $me->id)
            ->value('last_read_message_id');

        if ($otherLastRead >= (int) $message->id) {
            return response()->json(['message' => 'Cannot delete: user has already seen it'], 422);
        }

        $message->deleted_at = now();
        $message->deleted_by = $me->id;
        $message->body       = null;

        $message->file_path = null;
        $message->file_name = null;
        $message->file_mime = null;
        $message->file_size = null;

        $message->audio_path     = null;
        $message->audio_duration = null;
        $message->save();

        // ✅ BROADCAST so receiver updates instantly
        $recipientIds = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $me->id)
            ->pluck('user_id')
            ->all();

        broadcast(new \App\Events\MessageUpdated($message, $recipientIds))->toOthers();

        return response()->json([
            'ok'                 => true,
            'deleted_message_id' => $message->id,
        ], 200);
    }

    public function storeCallRecording(Request $request, Conversation $conversation)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (! $me->is_pro) {
            return response()->json(['message' => 'Pro subscription required'], 403);
        }

        // must be participant
        $isMember = $conversation->participants()
            ->where('user_id', $me->id)
            ->exists();

        if (! $isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'audio'    => ['required', 'file', 'max:51200', 'mimes:webm,ogg,mp3,wav,m4a,aac'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:7200'],
        ]);

        $file = $request->file('audio');
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'webm');
        $name = 'callrec_' . time() . '_' . uniqid() . '.' . $ext;

        $dir = public_path('uploads/call_recordings');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file->move($dir, $name);

        $relativePath = 'uploads/call_recordings/' . $name;

        // ✅ SAVE ONLY IN call_recordings (NOT messages)
        $rec = CallRecording::create([
            'conversation_id' => $conversation->id,
            'user_id'         => $me->id,
            'audio_path'      => $relativePath,
            'duration'        => (int) ($request->input('duration') ?? 0),
        ]);

        return response()->json([
            'recording' => [
                'id'              => $rec->id,
                'conversation_id' => $rec->conversation_id,
                'user_id'         => $rec->user_id,
                'audio_url'       => url($rec->audio_path),
                'duration'        => $rec->duration,
                'created_at'      => $rec->created_at,
            ],
        ], 201);
    }

    public function listCallRecordings(Request $request)
    {
        $me = auth('client')->user();
        if (! $me) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (! $me->is_pro) {
            return response()->json(['message' => 'Pro subscription required'], 403);
        }

        $rows = CallRecording::query()
            ->join('conversation_participants as cp', function ($join) use ($me) {
                $join->on('cp.conversation_id', '=', 'call_recordings.conversation_id')
                    ->where('cp.user_id', '=', $me->id)
                    ->whereNull('cp.hidden_at');
            })
            ->where('call_recordings.user_id', $me->id)
            ->orderByDesc('call_recordings.id')
            ->limit(100)
            ->get([
                'call_recordings.id',
                'call_recordings.conversation_id',
                'call_recordings.user_id',
                'call_recordings.audio_path',
                'call_recordings.duration',
                'call_recordings.created_at',
            ]);

        $data = $rows->map(function ($r) {
            return [
                'id'              => $r->id,
                'conversation_id' => $r->conversation_id,
                'user_id'         => $r->user_id,
                'audio_url'       => $r->audio_path ? url($r->audio_path) : null,
                'duration'        => (int) ($r->duration ?? 0),
                'created_at'      => $r->created_at,
            ];
        });

        return response()->json(['recordings' => $data], 200);
    }
}
