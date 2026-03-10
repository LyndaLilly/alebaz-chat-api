<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ChatContextController extends Controller
{
    public function create(Request $request)
    {
        $data = $request->validate([
            'seller_id' => ['required','integer'],
            'product_id' => ['required','integer'],
        ]);

        $buyerId = auth()->id(); // or auth('client')->id(), depending on your guard

        // ✅ Pull product snapshot from Alebaz DB (recommended)
        // Replace ProductUpload model/table names with yours
        $product = \App\Models\ProductUpload::with('images')
            ->findOrFail($data['product_id']);

        $token = 'ctx_' . Str::random(40);

        $ctx = \App\Models\ChatContext::create([
            'token' => $token,
            'buyer_id' => $buyerId,
            'seller_id' => $data['seller_id'],
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => $product->price,
            'product_image' => optional($product->images->first())->image_path,
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        return response()->json([
            'success' => true,
            'token' => $ctx->token,
        ]);
    }
}
