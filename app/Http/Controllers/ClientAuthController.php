<?php
namespace App\Http\Controllers;

use App\Mail\ClientVerificationMail;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ClientAuthController extends Controller
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

    public function me(Request $request)
    {
        $client = $request->user();

        return response()->json([
            'ok'     => true,
            'client' => [
                'id'                => $client->id,
                'email'             => $client->email,
                'phone'             => $client->phone,
                'username'          => $client->username,
                'profile_image'     => $client->profile_image,
                'verified'          => (bool) $client->verified,
                'account_completed' => (bool) $client->account_completed,
                'onboarding_step'   => (int) $client->onboarding_step,
            ],
        ], 200);
    }

    private int $otpExpiresMinutes     = 10;
    private int $resendCooldownSeconds = 10;
    private int $maxResends            = 5;

    public function startEmail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:120', 'unique:clients,email'],
        ]);

        $code = (string) random_int(100000, 999999);

        $client = Client::create([
            'email'                           => $data['email'],
            'email_verification_code'         => $code,
            'email_verification_expires_at'   => now()->addMinutes($this->otpExpiresMinutes),
            'email_verification_last_sent_at' => now(),
            'email_verification_resend_count' => 0,
            'verified'                        => false,
            'onboarding_step'                 => 1,
            'account_completed'               => false,
        ]);

        try {
            Mail::to($client->email)->send(
                new ClientVerificationMail($client->email, $code, $this->otpExpiresMinutes)
            );
        } catch (\Throwable $e) {
            logger()->error("MAIL FAILED for {$client->email}: " . $e->getMessage());

            // Optional: if you don't want to keep accounts that couldn't get email
            // $client->delete();

            return response()->json([
                'ok'      => false,
                'message' => 'Failed to send verification email. Please try again.',
            ], 500);
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Verification code sent to email',
            'email'   => $client->email,
        ], 201);
    }

    public function verifyEmail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:120'],
            'code'  => ['required', 'digits:6'],
        ]);

        $client = Client::where('email', $data['email'])->first();

        if (! $client) {
            return response()->json([
                'ok'      => false,
                'message' => 'Email not found. Please register again.',
            ], 404);
        }

        // If already verified
        if ($client->email_verified_at) {
            return response()->json([
                'ok'      => true,
                'message' => 'Email already verified.',
                'email'   => $client->email,
            ], 200);
        }

        // Check code exists
        if (! $client->email_verification_code) {
            return response()->json([
                'ok'      => false,
                'message' => 'No verification code found. Please request a new code.',
            ], 400);
        }

        // Check expiry
        if ($client->email_verification_expires_at && now()->gt($client->email_verification_expires_at)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Verification code has expired. Please resend a new code.',
            ], 422);
        }

        // Check code matches
        if ((string) $client->email_verification_code !== (string) $data['code']) {
            return response()->json([
                'ok'      => false,
                'message' => 'Invalid verification code.',
            ], 422);
        }

        // ✅ Mark verified + clear OTP
        $client->update([
            'verified'                      => true,
            'onboarding_step'               => 2,
            'email_verified_at'             => now(),
            'email_verification_code'       => null,
            'email_verification_expires_at' => null,
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Email verified successfully.',
            'email'   => $client->email,
        ], 200);
    }

    // STEP 1b: resend OTP to existing email
    public function resendEmail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:120'],
        ]);

        $client = Client::where('email', $data['email'])->first();

        if (! $client) {
            return response()->json([
                'ok'      => false,
                'message' => 'Email not found. Please register again.',
            ], 404);
        }

        if ((int) $client->email_verification_resend_count >= $this->maxResends) {
            return response()->json([
                'ok'      => false,
                'message' => 'Too many resend attempts. Please try again later.',
            ], 429);
        }

        if ($client->email_verification_last_sent_at) {
            $seconds = now()->diffInSeconds($client->email_verification_last_sent_at);
            if ($seconds < $this->resendCooldownSeconds) {
                return response()->json([
                    'ok'                  => false,
                    'message'             => 'Please wait a moment before resending the code.',
                    'retry_after_seconds' => $this->resendCooldownSeconds - $seconds,
                ], 429);
            }
        }

        $code = (string) random_int(100000, 999999);

        $client->update([
            'email_verification_code'         => $code,
            'email_verification_expires_at'   => now()->addMinutes($this->otpExpiresMinutes),
            'email_verification_last_sent_at' => now(),
            'email_verification_resend_count' => ((int) $client->email_verification_resend_count) + 1,
        ]);

        try {
            Mail::to($client->email)->send(
                new ClientVerificationMail($client->email, $code, $this->otpExpiresMinutes)
            );
        } catch (\Throwable $e) {
            logger()->error("RESEND MAIL FAILED for {$client->email}: " . $e->getMessage());

            return response()->json([
                'ok'      => false,
                'message' => 'Failed to resend verification email. Please try again.',
            ], 500);
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Verification code resent to email',
            'email'   => $client->email,
        ], 200);
    }

    public function saveProfile(Request $request)
    {
        $data = $request->validate([
            'email'         => ['required', 'email'],

            'username'      => ['nullable', 'string', 'min:3', 'max:40', 'unique:clients,username'],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $client = Client::where('email', $data['email'])->first();

        if (! $client) {
            return response()->json(['ok' => false, 'message' => 'Client not found'], 404);
        }

        if (! $client->email_verified_at) {
            return response()->json(['ok' => false, 'message' => 'Verify email first'], 403);
        }

        $imagePath = $client->profile_image;

        if ($request->hasFile('profile_image')) {
            $file     = $request->file('profile_image');
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/clients'), $filename);
            $imagePath = 'uploads/clients/' . $filename;
        }

        $client->update([
            'username'        => $data['username'] ?? $client->username,
            'profile_image'   => $imagePath,
            'onboarding_step' => 3, // move to next step after saving profile page
        ]);

        return response()->json([
            'ok'              => true,
            'message'         => 'Profile saved',
            'username'        => $client->username,
            'profile_image'   => $client->profile_image,
            'onboarding_step' => $client->onboarding_step,
        ], 200);
    }

    public function savePhonePin(Request $request)
    {
        $data = $request->validate([
            'email'        => ['required', 'email'],
            'country_code' => ['required', 'regex:/^\+[1-9]\d{0,3}$/'],
            'phone'        => ['required', 'regex:/^\d{4,14}$/'],
            'pin'          => ['required', 'digits:6'],
        ]);

        $client = Client::where('email', $data['email'])->first();

        if (! $client) {
            return response()->json(['ok' => false, 'message' => 'Client not found'], 404);
        }

        if (! $client->email_verified_at) {
            return response()->json(['ok' => false, 'message' => 'Verify email first'], 403);
        }

        if ((int) $client->onboarding_step < 3) {
            return response()->json(['ok' => false, 'message' => 'Complete profile step first'], 403);
        }

        // remove non digits
        $phone = preg_replace('/\D/', '', $data['phone']);

        // remove leading zero if exists
        $phone = ltrim($phone, '0');

        // build E.164 phone
        $fullPhone = $data['country_code'] . $phone;

        // FINAL GLOBAL VALIDATION
        if (! preg_match('/^\+[1-9]\d{6,14}$/', $fullPhone)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Invalid phone number format.',
            ], 422);
        }

        // unique check
        if (Client::where('phone', $fullPhone)->exists()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Phone already registered.',
            ], 422);
        }

        $client->update([
            'phone'             => $fullPhone,
            'pin'               => Hash::make($data['pin']),
            'account_completed' => true,
            'onboarding_step'   => 4,
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Phone and PIN saved.',
            'phone'   => $client->phone,
        ], 200);
    }

    public function loginWithPin(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:20'],
            'pin'   => ['required', 'digits:6'],
        ]);

        $client = Client::where('phone', $data['phone'])->first();

        if (! $client) {
            return response()->json([
                'ok'      => false,
                'message' => 'Invalid phone or PIN.',
            ], 422);
        }

        if (! $client->account_completed || (int) $client->onboarding_step !== 4) {
            return response()->json([
                'ok'              => false,
                'message'         => 'Complete registration first.',
                'onboarding_step' => $client->onboarding_step,
            ], 403);
        }

        if (! Hash::check($data['pin'], $client->pin)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Invalid phone or PIN.',
            ], 422);
        }

        // Sanctum token
        $token = $client->createToken('client-token')->plainTextToken;

        return response()->json([
            'ok'      => true,
            'message' => 'Login successful',
            'token'   => $token,
            'client'  => [
                'id'                => $client->id,
                'email'             => $client->email,
                'phone'             => $client->phone,
                'username'          => $client->username,
                'profile_image'     => $client->profile_image,
                'verified'          => (bool) $client->verified,
                'account_completed' => (bool) $client->account_completed,
                'onboarding_step'   => (int) $client->onboarding_step,
            ],
        ], 200);
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $me = $request->user();

        $raw = trim($data['q']);
        $q   = trim($data['q']);

        $base = Client::query()
            ->where('id', '!=', $me->id)
            ->where('account_completed', true);

        $displayType = 'username';

        // EMAIL (case-insensitive exact)
        if (str_contains($q, '@')) {
            $displayType = 'email';
            $email       = strtolower($q);

            $base->whereRaw('LOWER(email) = ?', [$email]);
        }
        // PHONE
        else if (preg_match('/^[\d\+\s\-\(\)]+$/', $q)) {
            $displayType = 'phone';

            $digits = preg_replace('/\D+/', '', $q);

            // normalize common NG inputs into E.164
            // 080..., 081..., 090... => +2348..., +2349...
            if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
                $digits    = ltrim($digits, '0');
                $phoneE164 = '+234' . $digits;
            }
            // 2348... => +2348...
            else if (str_starts_with($digits, '234')) {
                $phoneE164 = '+' . $digits;
            }
            // already typed +234... (we removed + above)
            else if (str_starts_with($raw, '+')) {
                $phoneE164 = '+' . $digits;
            }
            // fallback: treat as already international digits, require + prefix
            else {
                $phoneE164 = '+' . $digits;
            }

            $base->where('phone', $phoneE164);
        }
        // USERNAME (starts-with, case-insensitive)
        else {
            $displayType = 'username';
            $name        = strtolower($q);

            $base->whereNotNull('username')
                ->whereRaw('LOWER(username) LIKE ?', [$name . '%']);
        }

        $results = $base
            ->limit(10)
            ->get(['id', 'username', 'email', 'phone', 'profile_image']);

        return response()->json([
            'ok'         => true,
            'query_type' => $displayType,
            'results'    => $results->map(function ($c) use ($displayType) {
                $display = $displayType === 'email'
                    ? $c->email
                    : ($displayType === 'phone'
                        ? $c->phone
                        : $c->username);

                // Fallback display if username is null
                if (! $display) {
                    $display = $c->username ?: ($c->phone ?: $c->email);
                }

                return [
                    'id'            => $c->id,
                    'display'       => $display,     // show what was searched
                    'display_type'  => $displayType, // email | phone | username
                    'username'      => $c->username, // optional helper for confirmation
                    'profile_image' => $c->profile_image,
                ];
            }),
        ], 200);
    }

}
