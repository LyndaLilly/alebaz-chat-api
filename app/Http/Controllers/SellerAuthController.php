<?php
namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class SellerAuthController extends Controller
{
    public function login(Request $request)
    {
        Log::info('Chat login attempt', ['phone' => $request->phone]);

        $request->validate([
            'phone'    => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            // 1) find seller
            $seller = Seller::with([
                'professionalProfile',
                'otherProfile',
            ])->where('phone', $request->phone)->first();

            if (! $seller) {
                return response()->json(['message' => 'Seller not found'], 404);
            }

            // 2) password check
            if (! Hash::check($request->password, $seller->password)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            // 3) verified check
            if (! $seller->verified) {
                return response()->json(['message' => 'Email not verified'], 403);
            }

            // 4) get seller profile image (from market db profile tables)
            $profileImage = null;

            if ((int) $seller->is_professional === 1) {
                $profileImage = optional($seller->professionalProfile)->profile_image;
            } else {
                $profileImage = optional($seller->otherProfile)->profile_image;
            }

            $profileImage = ltrim($profileImage ?? '', '/');
            $profileImage = preg_replace('#/+#', '/', $profileImage);

            $shadow = Client::firstOrCreate(
                ['seller_id' => $seller->id],
                [
                    'user_type'         => 'seller',
                    'username'          => 'seller_' . $seller->id,
                    'display_name'      => trim($seller->firstname . ' ' . $seller->lastname),
                    'email'             => $seller->email ?: ('seller_' . $seller->id . '@seller.local'),
                    'phone'             => null,
                    'verified'          => 1,
                    'account_completed' => 1,
                    'onboarding_step'   => 4,
                    'profile_image'     => $profileImage
                        ? "https://api.alebaz.com/public/uploads/" . $profileImage
                        : null,
                ]
            );

            // 6) keep display_name + profile_image up to date
            $newName = trim($seller->firstname . ' ' . $seller->lastname);

            $shadow->display_name  = $newName ?: $shadow->display_name;
            $shadow->profile_image = $profileImage
                ? "https://api.alebaz.com/public/uploads/" . $profileImage
                : $shadow->profile_image;
            $shadow->save();

            // 7) token must be from Client model
            $token = $shadow->createToken('seller-chat-token')->plainTextToken;

            return response()->json([
                'ok'     => true,
                'token'  => $token,
                'client' => [
                    'id'                => $shadow->id,
                    'email'             => $shadow->email,
                    'phone'             => $shadow->phone,
                    'username'          => $shadow->username,
                    'display_name'      => $shadow->display_name,
                    'profile_image'     => $shadow->profile_image,

                    'verified'          => true,
                    'account_completed' => true,
                    'onboarding_step'   => 4,

                    'user_type'         => 'seller',
                    'seller_id'         => $seller->id,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Chat login exception', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Server error'], 500);
        }
    }

    public function me(Request $request)
    {
        $seller = auth('seller')->user();

        if (! $seller) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $shadow = Client::where('seller_id', $seller->id)->first();

        if (! $shadow) {
            return response()->json(['message' => 'Shadow client not found'], 404);
        }

        return response()->json([
            'ok'     => true,
            'client' => [
                'id'                => $shadow->id,
                'email'             => $shadow->email,
                'phone'             => $shadow->phone,
                'username'          => $shadow->username,
                'display_name'      => $shadow->display_name,
                'user_type'         => $shadow->user_type,     // IMPORTANT
                'seller_id'         => $shadow->seller_id,     // IMPORTANT
                'profile_image'     => $shadow->profile_image, // full url
                'verified'          => true,
                'account_completed' => true,
                'onboarding_step'   => 4,
            ],
        ], 200);
    }

    public function index(Request $request)
    {
        $items = Seller::with(['professionalProfile', 'otherProfile'])
            ->select(['id', 'firstname', 'lastname', 'is_professional'])
            ->orderBy('firstname')
            ->get()
            ->map(function ($s) {

                $shadow = Client::firstOrCreate(
                    ['seller_id' => $s->id],
                    [

                        'user_type' => 'seller',
                        'username'  => 'seller_' . $s->id,
                        'email'     => 'seller_' . $s->id . '@seller.local',
                        'phone'     => null,
                        'verified'  => 1,
                    ]
                );

                $profileImage = ((int) $s->is_professional === 1)
                    ? optional($s->professionalProfile)->profile_image
                    : optional($s->otherProfile)->profile_image;

                $profileImage = ltrim($profileImage ?? '', '/');
                $profileImage = preg_replace('#/+#', '/', $profileImage);

                $profileImageUrl = $profileImage
                    ? "https://api.alebaz.com/public/uploads/" . $profileImage
                    : null;

                return [
                    'seller_id'     => $s->id,      // market seller id
                    'chat_user_id'  => $shadow->id, // chat client id
                    'display'       => trim($s->firstname . ' ' . $s->lastname),
                    'display_type'  => 'seller',
                    'profile_image' => $profileImageUrl,
                ];
            });

        return response()->json(['sellers' => $items]);
    }

}
