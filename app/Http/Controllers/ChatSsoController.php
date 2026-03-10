<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatSsoController extends Controller
{
    // Marketplace calls this AFTER it already knows the buyer_id
    public function create(Request $request)
    {
        $data = $request->validate([
            'buyer_id'     => ['required', 'integer'],
            'email'        => ['nullable', 'email', 'max:120'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ]);

        // 1) ensure shadow buyer client exists (reuse your logic)
        $buyerId = (int) $data['buyer_id'];

        $client = Client::firstOrCreate(
            ['buyer_id' => $buyerId],
            [
                'user_type'         => 'buyer',
                'username'          => 'buyer_' . $buyerId,
                'display_name'      => $data['display_name'] ?? ('Buyer ' . $buyerId),
                'email'             => $data['email'] ?? ('buyer_' . $buyerId . '@buyer.local'),
                'verified'          => 1,
                'account_completed' => 1,
                'onboarding_step'   => 4,
            ]
        );

        if (!empty($data['display_name'])) $client->display_name = $data['display_name'];
        if (!empty($data['email'])) $client->email = $data['email'];
        $client->save();

        // 2) create one-time code (expires in 60 seconds)
        $code = Str::random(60);
        $expiresAt = now()->addSeconds(60);

        DB::table('chat_sso_tokens')->insert([
            'code'       => $code,
            'client_id'  => $client->id,
            'expires_at' => $expiresAt,
            'used_at'    => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok'   => true,
            'code' => $code,
            'expires_at' => $expiresAt->toISOString(),
        ]);
    }

    // Chat subdomain calls this with ?sso=code to get a real Sanctum token
    public function exchange(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80'],
        ]);

        $row = DB::table('chat_sso_tokens')
            ->where('code', $data['code'])
            ->first();

        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'Invalid code'], 422);
        }

        if ($row->used_at) {
            return response()->json(['ok' => false, 'message' => 'Code already used'], 422);
        }

        if (now()->gt($row->expires_at)) {
            return response()->json(['ok' => false, 'message' => 'Code expired'], 422);
        }

        $client = Client::find($row->client_id);
        if (!$client) {
            return response()->json(['ok' => false, 'message' => 'Client not found'], 404);
        }

        // mark used
        DB::table('chat_sso_tokens')->where('id', $row->id)->update([
            'used_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $client->createToken('chat-sso-token')->plainTextToken;

        return response()->json([
            'ok' => true,
            'token' => $token,
            'client' => [
                'id'           => $client->id,
                'user_type'    => $client->user_type,
                'buyer_id'     => $client->buyer_id,
                'seller_id'    => $client->seller_id,
                'username'     => $client->username,
                'display_name' => $client->display_name,
                'email'        => $client->email,
                'profile_image'=> $client->profile_image,
            ],
        ]);
    }
}