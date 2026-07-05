<?php

namespace App\Services;

use App\Models\Event;
use App\Models\GoogleAccount;
use Carbon\Carbon;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Database\Eloquent\Collection;

class GoogleSyncService
{
    public function syncGoogleAccount(
        GoogleAccount $googleAccount,
        GoogleCalendarService $googleCalendarService,
        bool $useSyncToken = true
    ): array {
        $googleAccount->update([
            'sync_status' => 'syncing',
            'sync_error' => null,
            'last_sync_attempt_at' => now(),
        ]);

        $shouldUseSyncToken =
            $useSyncToken &&
            $googleAccount->sync_token !== null;

        try {
            $syncResult = $googleCalendarService->listEvents(
                $googleAccount,
                $shouldUseSyncToken
            );
        } catch (GoogleServiceException $exception) {
            // Google returns 410 when the saved sync token is no longer valid.
            if ($exception->getCode() === 410 && $shouldUseSyncToken) {
                $syncResult = $this->performFullSyncAfterExpiredToken(
                    $googleAccount,
                    $googleCalendarService
                );

                if (! $syncResult['success']) {
                    return $syncResult;
                }

                $syncResult = $syncResult['sync_result'];
            } else {
                return $this->markSyncAsPending(
                    $googleAccount,
                    $exception
                );
            }
        } catch (\Throwable $exception) {
            return $this->markSyncAsPending(
                $googleAccount,
                $exception
            );
        }

        $googleEvents = $syncResult['items'];
        $nextSyncToken = $syncResult['next_sync_token'];

        $imported = [];
        $updated = [];
        $deleted = [];
        $conflicts = [];

        try {
            foreach ($googleEvents as $googleEvent) {
                $googleEventId = $googleEvent->getId();

                if (! $googleEventId) {
                    continue;
                }

                if ($googleEvent->getStatus() === 'cancelled') {
                    $deletedEvent = $this->deleteLocalGoogleEvent(
                        $googleAccount->user_id,
                        $googleEventId
                    );

                    if ($deletedEvent) {
                        $deleted[] = $deletedEvent;
                    }

                    continue;
                }

                $googleStart = $this->getGoogleEventStart($googleEvent);
                $googleEnd = $this->getGoogleEventEnd($googleEvent);
                $isAllDay = $googleEvent->getStart()?->getDate() !== null;

                if (! $googleStart || ! $googleEnd) {
                    continue;
                }

                $existingLocalEvent = Event::where(
                    'user_id',
                    $googleAccount->user_id
                )
                    ->where('google_event_id', $googleEventId)
                    ->first();

                if ($existingLocalEvent) {
                    $wasUpdated = $this->updateExistingEvent(
                        $existingLocalEvent,
                        $googleEvent,
                        $googleStart,
                        $googleEnd,
                        $isAllDay
                    );

                    if ($wasUpdated) {
                        $updated[] = $existingLocalEvent->fresh();
                    }

                    continue;
                }

                $overlappingEvents = $this->findOverlaps(
                    $googleAccount->user_id,
                    $googleStart,
                    $googleEnd
                );

                if ($overlappingEvents->isNotEmpty()) {
                    $event = $this->createLocalEvent(
                        $googleAccount,
                        $googleEvent,
                        $googleStart,
                        $googleEnd,
                        $isAllDay,
                        '#dc2626',
                        'conflict',
                        'Imported from Google with overlap conflict.'
                    );

                    $conflicts[] = $this->formatConflict(
                        $event,
                        $overlappingEvents
                    );

                    $imported[] = $event;

                    continue;
                }

                $event = $this->createLocalEvent(
                    $googleAccount,
                    $googleEvent,
                    $googleStart,
                    $googleEnd,
                    $isAllDay,
                    '#2563eb',
                    'synced',
                    null
                );

                $imported[] = $event;
            }
        } catch (\Throwable $exception) {
            return $this->markSyncAsPending(
                $googleAccount,
                $exception
            );
        }

        $googleAccount->update([
            'sync_token' => $nextSyncToken ?: $googleAccount->sync_token,
            'sync_status' => 'synced',
            'sync_error' => null,
            'last_successful_sync_at' => now(),
        ]);

        return [
            'success' => true,
            'message' => 'Google sync completed.',
            'imported_count' => count($imported),
            'updated_count' => count($updated),
            'deleted_count' => count($deleted),
            'conflict_count' => count($conflicts),
            'conflicts' => $conflicts,
        ];
    }

    private function performFullSyncAfterExpiredToken(
        GoogleAccount $googleAccount,
        GoogleCalendarService $googleCalendarService
    ): array {
        $googleAccount->update([
            'sync_token' => null,
            'sync_status' => 'syncing',
            'sync_error' => null,
        ]);

        try {
            $syncResult = $googleCalendarService->listEvents(
                $googleAccount,
                false
            );

            return [
                'success' => true,
                'sync_result' => $syncResult,
            ];
        } catch (\Throwable $exception) {
            return $this->markSyncAsPending(
                $googleAccount,
                $exception
            );
        }
    }

    private function updateExistingEvent(
        Event $localEvent,
        object $googleEvent,
        Carbon $googleStart,
        Carbon $googleEnd,
        bool $isAllDay
    ): bool {
        $title = $googleEvent->getSummary() ?? 'Untitled Google Event';
        $description = $googleEvent->getDescription();

        $googleUpdatedAt = $googleEvent->getUpdated()
            ? Carbon::parse($googleEvent->getUpdated())
            : null;

        $hasChanged =
            $localEvent->title !== $title ||
            $localEvent->description !== $description ||
            ! $localEvent->start->equalTo($googleStart) ||
            ! $localEvent->end->equalTo($googleEnd) ||
            $localEvent->is_all_day !== $isAllDay;

        if ($hasChanged) {
            $localEvent->update([
                'title' => $title,
                'description' => $description,
                'start' => $googleStart,
                'end' => $googleEnd,
                'is_all_day' => $isAllDay,
                'google_updated_at' => $googleUpdatedAt,
                'last_synced_at' => now(),
                'sync_source' => 'google',
                'sync_status' => 'synced',
                'sync_error' => null,
            ]);

            return true;
        }

        $localEvent->update([
            'google_updated_at' => $googleUpdatedAt,
            'last_synced_at' => now(),
        ]);

        return false;
    }

    private function createLocalEvent(
        GoogleAccount $googleAccount,
        object $googleEvent,
        Carbon $googleStart,
        Carbon $googleEnd,
        bool $isAllDay,
        string $color,
        string $syncStatus,
        ?string $syncError
    ): Event {
        return Event::create([
            'user_id' => $googleAccount->user_id,
            'title' => $googleEvent->getSummary() ?? 'Untitled Google Event',
            'description' => $googleEvent->getDescription(),
            'start' => $googleStart,
            'end' => $googleEnd,
            'is_all_day' => $isAllDay,
            'color' => $color,
            'google_event_id' => $googleEvent->getId(),
            'google_calendar_id' => $googleAccount->calendar_id,
            'google_updated_at' => $googleEvent->getUpdated()
                ? Carbon::parse($googleEvent->getUpdated())
                : null,
            'last_synced_at' => now(),
            'sync_source' => 'google',
            'sync_status' => $syncStatus,
            'sync_error' => $syncError,
        ]);
    }

    private function formatConflict(
        Event $importedEvent,
        Collection $overlappingEvents
    ): array {
        return [
            'imported_event' => [
                'id' => $importedEvent->id,
                'title' => $importedEvent->title,
                'start' => $importedEvent->start->toDateTimeString(),
                'end' => $importedEvent->end->toDateTimeString(),
            ],
            'google_event' => [
                'google_event_id' => $importedEvent->google_event_id,
                'title' => $importedEvent->title,
                'description' => $importedEvent->description,
                'start' => $importedEvent->start->toDateTimeString(),
                'end' => $importedEvent->end->toDateTimeString(),
            ],
            'overlaps_with' => $overlappingEvents
                ->map(function (Event $overlap): array {
                    return [
                        'id' => $overlap->id,
                        'title' => $overlap->title,
                        'start' => $overlap->start->toDateTimeString(),
                        'end' => $overlap->end->toDateTimeString(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    private function getGoogleEventStart(object $googleEvent): ?Carbon
    {
        $start = $googleEvent->getStart();

        if (! $start) {
            return null;
        }

        if ($start->getDateTime()) {
            return Carbon::parse($start->getDateTime())
                ->setTimezone(config('app.timezone'));
        }

        if ($start->getDate()) {
            return Carbon::parse(
                $start->getDate(),
                config('app.timezone')
            )->startOfDay();
        }

        return null;
    }

    private function getGoogleEventEnd(object $googleEvent): ?Carbon
    {
        $end = $googleEvent->getEnd();

        if (! $end) {
            return null;
        }

        if ($end->getDateTime()) {
            return Carbon::parse($end->getDateTime())
                ->setTimezone(config('app.timezone'));
        }

        if ($end->getDate()) {
            // Google and FullCalendar both use an exclusive end date for all-day events.
            return Carbon::parse(
                $end->getDate(),
                config('app.timezone')
            )->startOfDay();
        }

        return null;
    }

    private function findOverlaps(
        int $userId,
        Carbon $start,
        Carbon $end
    ): Collection {
        return Event::where('user_id', $userId)
            ->where('start', '<', $end)
            ->where('end', '>', $start)
            ->get();
    }

    private function deleteLocalGoogleEvent(
        int $userId,
        string $googleEventId
    ): ?array {
        $event = Event::where('user_id', $userId)
            ->where('google_event_id', $googleEventId)
            ->first();

        if (! $event) {
            return null;
        }

        $deletedEvent = [
            'id' => $event->id,
            'title' => $event->title,
            'google_event_id' => $event->google_event_id,
        ];

        $event->delete();

        return $deletedEvent;
    }

    private function markSyncAsPending(
        GoogleAccount $googleAccount,
        \Throwable $exception
    ): array {
        $googleAccount->update([
            'sync_status' => 'pending',
            'sync_error' => $exception->getMessage(),
            'last_sync_attempt_at' => now(),
        ]);

        return [
            'success' => false,
            'message' => 'Google Calendar sync failed.',
            'error' => $exception->getMessage(),
            'imported_count' => 0,
            'updated_count' => 0,
            'deleted_count' => 0,
            'conflict_count' => 0,
            'conflicts' => [],
        ];
    }
}
