<?php

namespace App\Http\Controllers;

use App\Models\GoogleAccount;
use App\Services\GoogleCalendarService;
use App\Services\GoogleSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleWebhookController extends Controller
{
    public function handle(
        Request $request,
        GoogleCalendarService $googleCalendarService,
        GoogleSyncService $googleSyncService
    ) {
        $channelId =$request->header('X-Goog-Channel-Id');
        $channelToken = $request->header('X-Goog-Channel-Token');
        $resourceId = $request->header('X-Goog-Resource-Id');
        $resourceState = $request->header('X-Goog-Resource-State');
        $messageNumber = $request->header('X-Goog-Message-Number');

        Log::info('Google Calendar webhook received', [
            'channel_id' => $channelId,
            'channel_token' => $channelToken,
            'resource_state' => $resourceState,
            'message_number' => $messageNumber
        ]);

        $googleAccount = GoogleAccount::where('channel_id', $channelId)
            ->where('resource_id', $resourceId)
            ->where('channel_token', $channelToken)
            ->first();

        if (! $googleAccount) {
            Log::warning('Google Calendar webhook rejected', [
                'channel_id' => $channelId,
                'resource_id' => $resourceId,
            ]);

            return response()->json(['message' => 'Google Calendar webhook rejected'], 404);
        }

        if ($resourceState === 'sync') {
            return response()->json([
                'message' => 'Initial sync notification received',
            ]);
        }

        $result = $googleSyncService->syncGoogleAccount(
            $googleAccount,
            $googleCalendarService
        );

        Log::info('Google Calendar webhook sync completed', [
            'google_account_id' => $googleAccount->id,
            'result' => $result,
        ]);

        return response()->json([
            'message' => 'Webhook sync completed.',
            'result' => $result,
        ]);
    }
}
