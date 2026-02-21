<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    // GET /api/conversations/{conversation}/messages
    public function index(Request $request, Conversation $conversation)
    {
        $me = auth('client')->user();
        if (!$me) return response()->json(['message' => 'Unauthenticated'], 401);

        // Security: must be participant
        $isMember = $conversation->participants()
            ->where('user_id', $me->id)
            ->exists();

        if (!$isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $messages = $conversation->messages()
            ->with('sender:id,username,profile_image')
            ->orderBy('id', 'asc')
            ->limit(200)
            ->get();

        return response()->json([
            'messages' => $messages,
        ]);
    }

    // POST /api/conversations/{conversation}/messages
    public function store(Request $request, Conversation $conversation)
    {
        $me = auth('client')->user();
        if (!$me) return response()->json(['message' => 'Unauthenticated'], 401);

        $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        // Security: must be participant
        $isMember = $conversation->participants()
            ->where('user_id', $me->id)
            ->exists();

        if (!$isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $me->id,
            'body' => $request->input('body'),
            'created_at' => now(),
        ]);

        $msg->load('sender:id,username,profile_image');

        return response()->json([
            'message' => $msg,
        ], 201);
    }
}