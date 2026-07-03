<?php

namespace App\Http\Controllers;

use App\Models\GoogleAccount;
use App\Services\GoogleCalendarService;
use App\Services\GoogleSyncService;
use Illuminate\Http\Request;

class GoogleSyncController extends Controller
{
    public function sync(
        Request               $request,
        GoogleCalendarService $googleCalendarService,
        GoogleSyncService     $googleSyncService
    )
    {
        $user = $request->user();

        $googleAccount = GoogleAccount::where('user_id', $user->id)->first();

        if (!$googleAccount) {
            return response()->json([
                'message' => 'Google Calendar is not connected.',
            ], 400);
        }

        $result = $googleSyncService->syncGoogleAccount($googleAccount, $googleCalendarService);

        if (!$result['success']) {
            return response()->json($result, 503);
        }

        return response()->json($result);
    }
}
