<?php

namespace App\Http\Controllers;

use App\Models\Buyer;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class BuyerAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $buyer = Buyer::with('buyerProfile')->where('email', $request->email)->first();

        if (! $buyer) {
            return response()->json(['message' => 'Buyer not found'], 404);
        }

        if (! Hash::check($request->password, $buyer->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // 1) pull image from buyer profile
        $profileImage =
            optional($buyer->buyerProfile)->buyer_image
            ?? optional($buyer->buyerProfile)->image
            ?? optional($buyer->buyerProfile)->photo
            ?? null;

        // 2) clean it like sellers
        $profileImage = ltrim($profileImage ?? '', '/');
        $profileImage = preg_replace('#/+#', '/', $profileImage);

        /**
         * Buyer images are stored in:
         * public/uploads/buyer_image/xxxx.png
         *
         * Your DB example:
         * buyer_image/1759867519_xxx.jpg
         *
         * So we build:
         * https://api.alebaz.com/public/uploads/buyer_image/xxxx.jpg
         */
        $profileImageUrl = $profileImage
            ? "https://api.alebaz.com/public/uploads/" . $profileImage
            : null;

        // 3) create/get shadow client
        $shadow = Client::firstOrCreate(
            ['buyer_id' => $buyer->id],
            [
                'user_type'         => 'buyer',
                'username'          => 'buyer_' . $buyer->id,
                'display_name'      => trim(($buyer->firstname ?? '') . ' ' . ($buyer->lastname ?? '')),
                'email'             => $buyer->email,
                'verified'          => 1,
                'account_completed' => 1,
                'onboarding_step'   => 4,
                'profile_image'     => $profileImageUrl, // ✅ full url like sellers
            ]
        );

        // 4) keep updated (like sellers)
        $shadow->display_name  = trim(($buyer->firstname ?? '') . ' ' . ($buyer->lastname ?? '')) ?: $shadow->display_name;
        $shadow->email         = $buyer->email;
        $shadow->profile_image = $profileImageUrl ?: $shadow->profile_image;
        $shadow->save();

        $token = $shadow->createToken('buyer-chat-token')->plainTextToken;

        return response()->json([
            'ok'     => true,
            'token'  => $token,
            'client' => [
                'id'            => $shadow->id,
                'email'         => $shadow->email,
                'username'      => $shadow->username,
                'display_name'  => $shadow->display_name,
                'profile_image' => $shadow->profile_image, // full url
                'verified'      => true,
                'account_completed' => true,
                'onboarding_step'   => 4,
                'user_type'     => 'buyer',
                'buyer_id'      => $buyer->id,
            ],
        ], 200);
    }
}