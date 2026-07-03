<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\GoogleAccount;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GoogleSyncController extends Controller
{
    public function sync(Request $request, GoogleCalendarService $googleCalendarService)
    {
        $user = $request->user();

        $googleAccount = GoogleAccount::where('user_id', $user->id)->first();

        if (! $googleAccount) {
            return response()->json([
                'message' => 'Google Calendar is not connected.',
            ], 400);
        }

        try {
            $googleEvents = $googleCalendarService->listEvents($googleAccount);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Google Calendar sync failed.',
                'error' => $e->getMessage(),
            ], 503);
        }

        $imported = [];
        $updated = [];
        $conflicts = [];

        foreach ($googleEvents as $googleEvent) {
            if ($googleEvent->getStatus() === 'cancelled') {
                $this->deleteLocalGoogleEvent($user->id, $googleEvent->getId());
                continue;
            }

            $googleStart = $this->getGoogleEventStart($googleEvent);
            $googleEnd = $this->getGoogleEventEnd($googleEvent);
            $isAllDay = $googleEvent->getStart()->getDate() !== null;

            if (! $googleStart || ! $googleEnd) {
                continue;
            }

            $existingLocalEvent = Event::where('user_id', $user->id)
                ->where('google_event_id', $googleEvent->getId())
                ->first();

            if ($existingLocalEvent) {
                $googleUpdatedAt = $googleEvent->getUpdated()
                    ? Carbon::parse($googleEvent->getUpdated())
                    : null;

                $hasChanged =
                    $existingLocalEvent->title !== ($googleEvent->getSummary() ?? 'Untitled Google Event') ||
                    $existingLocalEvent->description !== $googleEvent->getDescription() ||
                    ! $existingLocalEvent->start->equalTo($googleStart) ||
                    ! $existingLocalEvent->end->equalTo($googleEnd) ||
                    $existingLocalEvent->is_all_day !== $isAllDay;

                if ($hasChanged) {
                    $existingLocalEvent->update([
                        'title' => $googleEvent->getSummary() ?? 'Untitled Google Event',
                        'description' => $googleEvent->getDescription(),
                        'start' => $googleStart,
                        'end' => $googleEnd,
                        'is_all_day' => $isAllDay,
                        'google_updated_at' => $googleUpdatedAt,
                        'last_synced_at' => now(),
                        'sync_source' => 'google',
                        'sync_status' => 'synced',
                        'sync_error' => null,
                    ]);

                    $updated[] = $existingLocalEvent;
                } else {
                    $existingLocalEvent->update([
                        'last_synced_at' => now(),
                        'google_updated_at' => $googleUpdatedAt,
                    ]);
                }

                continue;
            }

            $overlappingEvents = $this->findOverlaps(
                $user->id,
                $googleStart,
                $googleEnd
            );

            if ($overlappingEvents->isNotEmpty()) {
                $event = Event::create([
                    'user_id' => $user->id,
                    'title' => $googleEvent->getSummary() ?? 'Untitled Google Event',
                    'description' => $googleEvent->getDescription(),
                    'start' => $googleStart,
                    'end' => $googleEnd,
                    'is_all_day' => $isAllDay,
                    'color' => '#dc2626',
                    'google_event_id' => $googleEvent->getId(),
                    'google_calendar_id' => $googleAccount->calendar_id,
                    'google_updated_at' => $googleEvent->getUpdated()
                        ? Carbon::parse($googleEvent->getUpdated())
                        : null,
                    'last_synced_at' => now(),
                    'sync_source' => 'google',
                    'sync_status' => 'conflict',
                    'sync_error' => 'Imported from Google with overlap conflict.',
                ]);

                $conflicts[] = [
                    'imported_event' => [
                        'id' => $event->id,
                        'title' => $event->title,
                        'start' => $event->start->toDateTimeString(),
                        'end' => $event->end->toDateTimeString(),
                    ],
                    'google_event' => [
                        'google_event_id' => $googleEvent->getId(),
                        'title' => $event->title,
                        'description' => $event->description,
                        'start' => $event->start->toDateTimeString(),
                        'end' => $event->end->toDateTimeString(),
                    ],
                    'overlaps_with' => $overlappingEvents->map(function ($overlap) {
                        return [
                            'id' => $overlap->id,
                            'title' => $overlap->title,
                            'start' => $overlap->start->toDateTimeString(),
                            'end' => $overlap->end->toDateTimeString(),
                        ];
                    })->values(),
                ];

                $imported[] = $event;

                continue;
            }

            $event = Event::create([
                'user_id' => $user->id,
                'title' => $googleEvent->getSummary() ?? 'Untitled Google Event',
                'description' => $googleEvent->getDescription(),
                'start' => $googleStart,
                'end' => $googleEnd,
                'is_all_day' => $isAllDay,
                'color' => '#2563eb',
                'google_event_id' => $googleEvent->getId(),
                'google_calendar_id' => $googleAccount->calendar_id,
                'google_updated_at' => $googleEvent->getUpdated()
                    ? Carbon::parse($googleEvent->getUpdated())
                    : null,
                'last_synced_at' => now(),
                'sync_source' => 'google',
                'sync_status' => 'synced',
                'sync_error' => null,
            ]);

            $imported[] = $event;
        }

        return response()->json([
            'message' => 'Google sync completed.',
            'imported_count' => count($imported),
            'updated_count' => count($updated),
            'conflict_count' => count($conflicts),
            'conflicts' => $conflicts,
        ]);
    }

    private function getGoogleEventStart($googleEvent): ?Carbon
    {
        $start = $googleEvent->getStart();

        if ($start->getDateTime()) {
            return Carbon::parse($start->getDateTime())
                ->setTimezone(config('app.timezone'));
        }

        if ($start->getDate()) {
            return Carbon::parse($start->getDate())
                ->startOfDay();
        }

        return null;
    }

    private function getGoogleEventEnd($googleEvent): ?Carbon
    {
        $end = $googleEvent->getEnd();

        if ($end->getDateTime()) {
            return Carbon::parse($end->getDateTime())
                ->setTimezone(config('app.timezone'));
        }

        if ($end->getDate()) {
            /*
             * Google all-day event end date is exclusive.
             * Example:
             * start = 2026-07-17
             * end = 2026-07-18
             *
             * FullCalendar also expects exclusive end for all-day events,
             * so we keep 2026-07-18 00:00:00.
             */
            return Carbon::parse($end->getDate())
                ->startOfDay();
        }

        return null;
    }

    private function findOverlaps(int $userId, Carbon $start, Carbon $end)
    {
        return Event::where('user_id', $userId)
            ->where(function ($query) use ($start, $end) {
                $query->where('start', '<', $end)
                    ->where('end', '>', $start);
            })
            ->get();
    }

    private function deleteLocalGoogleEvent(int $userId, string $googleEventId): void
    {
        Event::where('user_id', $userId)
            ->where('google_event_id', $googleEventId)
            ->delete();
    }
}
