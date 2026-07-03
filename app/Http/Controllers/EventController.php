<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\GoogleAccount;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $events = Event::where('user_id', $request->user()->id)->get();

        $events = $events->map(function ($event) {
            return [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'start' => $event->start?->format('Y-m-d H:i:s'),
                'end' => $event->end?->format('Y-m-d H:i:s'),
                'is_all_day' => $event->is_all_day,
                'color' => $event->color,
                'sync_status' => $event->sync_status,
                'google_event_id' => $event->google_event_id,
            ];
        });

        return response()->json($events);
    }

    public function store(Request $request, GoogleCalendarService $googleCalendarService)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $event = Event::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'start' => $data['start'],
            'end' => $data['end'],
            'color' => $data['color'] ?? '#2563eb',
            'sync_source' => 'app',
            'sync_status' => 'local',
            'sync_error' => null,
        ]);

        $googleAccount = GoogleAccount::where('user_id', $request->user()->id)->first();

        if ($googleAccount) {
            try {
                $googleEventId = $googleCalendarService->createEvent($googleAccount, $event);

                $event->update([
                    'google_event_id' => $googleEventId,
                    'google_calendar_id' => $googleAccount->calendar_id,
                    'last_synced_at' => now(),
                    'sync_status' => 'synced',
                    'sync_error' => null,
                ]);
            } catch (\Throwable $e) {
                $event->update([
                    'sync_status' => 'pending',
                    'sync_error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json($event, 201);
    }

    public function show(Request $request, $id)
    {
        $event = Event::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json($event);
    }

    public function update(Request $request, $id, GoogleCalendarService $googleCalendarService)
    {
        $event = Event::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $event->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'start' => $data['start'],
            'end' => $data['end'],
            'color' => $data['color'] ?? '#2563eb',
            'sync_source' => 'app',
            'sync_status' => 'local',
            'sync_error' => null,
        ]);

        $googleAccount = GoogleAccount::where('user_id', $request->user()->id)->first();

        if ($googleAccount && $event->google_event_id) {
            try {
                $googleCalendarService->updateEvent($googleAccount, $event);

                $event->update([
                    'last_synced_at' => now(),
                    'sync_status' => 'synced',
                    'sync_error' => null,
                ]);
            } catch (\Throwable $e) {
                $event->update([
                    'sync_status' => 'pending',
                    'sync_error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json($event);
    }

    public function destroy(Request $request, $id, GoogleCalendarService $googleCalendarService)
    {
        $event = Event::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $googleAccount = GoogleAccount::where('user_id', $request->user()->id)->first();

        if ($googleAccount && $event->google_event_id) {
            try {
                $googleCalendarService->deleteEvent($googleAccount, $event);
            } catch (\Throwable $e) {
                /*
                 * Even if Google fails, we still delete locally.
                 * Later we can implement a deleted_events / sync queue table
                 * if we want to retry Google delete.
                 */
            }
        }

        $event->delete();

        return response()->json([
            'message' => 'Event deleted',
        ]);
    }
}
