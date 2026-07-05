<?php

namespace App\Console\Commands;

use App\Models\GoogleAccount;
use App\Services\GoogleCalendarService;
use App\Services\GoogleSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryPendingGoogleSyncs extends Command
{
    protected $signature = 'google:retry-pending';

    protected $description = 'Retry Google Calendar syncs marked as pending';

    public function handle(
        GoogleCalendarService $googleCalendarService,
        GoogleSyncService $googleSyncService
    ): int {
        $googleAccounts = GoogleAccount::where('sync_status', 'pending')
            ->get();

        if ($googleAccounts->isEmpty()) {
            $this->info('No pending Google Calendar syncs.');

            return self::SUCCESS;
        }

        $successful = 0;
        $failed = 0;

        foreach ($googleAccounts as $googleAccount) {
            $this->info(
                "Retrying Google sync for {$googleAccount->google_email}..."
            );

            try {
                $result = $googleSyncService->syncGoogleAccount(
                    $googleAccount,
                    $googleCalendarService
                );

                if ($result['success']) {
                    $successful++;

                    $this->info(
                        "Sync completed for {$googleAccount->google_email}."
                    );
                } else {
                    $failed++;

                    $this->error(
                        "Sync failed for {$googleAccount->google_email}: "
                        . ($result['error'] ?? 'Unknown error')
                    );
                }
            } catch (\Throwable $exception) {
                $failed++;

                $googleAccount->update([
                    'sync_status' => 'pending',
                    'sync_error' => $exception->getMessage(),
                    'last_sync_attempt_at' => now(),
                ]);

                Log::error('Pending Google Calendar sync retry failed', [
                    'google_account_id' => $googleAccount->id,
                    'message' => $exception->getMessage(),
                ]);

                $this->error(
                    "Sync failed for {$googleAccount->google_email}: "
                    . $exception->getMessage()
                );
            }
        }

        $this->newLine();
        $this->info("Successful: {$successful}");
        $this->info("Failed: {$failed}");

        return $failed > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
