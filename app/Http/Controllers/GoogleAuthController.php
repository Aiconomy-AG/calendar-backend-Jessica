<?php

namespace App\Http\Controllers;

use App\Models\GoogleAccount;
use App\Models\GoogleConnectState;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $state = Str::random(80);

        GoogleConnectState::create([
            'user_id' => $request->user()->id,
            'state' => $state,
            'expires_at' => now()->addMinutes(10),
        ]);

        $url = Socialite::driver('google')
            ->scopes([
                'https://www.googleapis.com/auth/calendar.events',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => $state,
            ])
            ->stateless()
            ->redirect()
            ->getTargetUrl();
        $url = str_replace('https://accounts.google.com//', 'https://accounts.google.com/', $url);

        return response()->json([
            'url' => $url,
        ]);
    }

    public function callback(Request $request, GoogleCalendarService $googleCalendarService)
    {
        $state = $request->query('state');

        $connectState = GoogleConnectState::where('state', $state)
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $googleUser = Socialite::driver('google')
            ->stateless()
            ->user();

        $existingAccount = GoogleAccount::where('user_id', $connectState->user_id)
            ->first();

        $googleAccount = GoogleAccount::updateOrCreate(
            [
                'user_id' => $connectState->user_id,
            ],
            [
                'google_email' => $googleUser->getEmail(),
                'access_token' => $googleUser->token,
                'refresh_token' => $googleUser->refreshToken
                    ?? $existingAccount?->refresh_token,
                'expires_at' => now()->addSeconds($googleUser->expiresIn),
                'calendar_id' => 'primary',
            ]
        );

        try {
            $googleCalendarService->startWatch($googleAccount);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Google watch channel failed', [
                'google_account_id' => $googleAccount->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $connectState->delete();

        return redirect(config('app.frontend_url') . '?google_connected=1');
    }

    public function status(Request $request)
    {
        $googleAccount = GoogleAccount::where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            'connected' => $googleAccount !== null,
            'google_email' => $googleAccount?->google_email,
        ]);
    }

    public function disconnect(Request $request)
    {
        GoogleAccount::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'message' => 'Google Calendar disconnected',
        ]);
    }
}
