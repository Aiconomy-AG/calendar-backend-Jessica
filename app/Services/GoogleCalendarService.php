<?php

namespace App\Services;

use App\Models\GoogleAccount;
use App\Models\Event;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventDateTime;

class GoogleCalendarService
{
    public function getClient(GoogleAccount $googleAccount): Client
    {
        $client = new Client();

        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect'));

        $client->setScopes([
            'https://www.googleapis.com/auth/calendar.events',
        ]);

        $client->setAccessType('offline');

        $client->setAccessToken([
            'access_token' => $googleAccount->access_token,
            'refresh_token' => $googleAccount->refresh_token,
            'expires_in' => $googleAccount->expires_at
                ? now()->diffInSeconds($googleAccount->expires_at, false)
                : 0,
            'created' => now()->timestamp,
        ]);

        if ($client->isAccessTokenExpired() && $googleAccount->refresh_token) {
            $newToken = $client->fetchAccessTokenWithRefreshToken(
                $googleAccount->refresh_token
            );

            if (isset($newToken['access_token'])) {
                $googleAccount->update([
                    'access_token' => $newToken['access_token'],
                    'expires_at' => now()->addSeconds($newToken['expires_in']),
                ]);

                $client->setAccessToken([
                    'access_token' => $newToken['access_token'],
                    'refresh_token' => $googleAccount->refresh_token,
                    'expires_in' => $newToken['expires_in'],
                    'created' => now()->timestamp,
                ]);
            }
        }

        return $client;
    }

    public function getCalendarService(GoogleAccount $googleAccount): Calendar
    {
        return new Calendar($this->getClient($googleAccount));
    }

    public function createEvent(GoogleAccount $googleAccount, Event $event): ?string
    {
        $calendarService = $this->getCalendarService($googleAccount);

        $googleEvent = new GoogleEvent([
            'summary' => $event->title,
            'description' => $event->description,
            'start' => new EventDateTime([
                'dateTime' => $event->start->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ]),
            'end' => new EventDateTime([
                'dateTime' => $event->end->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ]),
        ]);

        $createdGoogleEvent = $calendarService->events->insert(
            $googleAccount->calendar_id,
            $googleEvent
        );

        return $createdGoogleEvent->getId();
    }

    public function updateEvent(GoogleAccount $googleAccount, Event $event): void
    {
        if (! $event->google_event_id) {
            return;
        }

        $calendarService = $this->getCalendarService($googleAccount);

        $googleEvent = new GoogleEvent([
            'summary' => $event->title,
            'description' => $event->description,
            'start' => new EventDateTime([
                'dateTime' => $event->start->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ]),
            'end' => new EventDateTime([
                'dateTime' => $event->end->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ]),
        ]);

        $calendarService->events->update(
            $googleAccount->calendar_id,
            $event->google_event_id,
            $googleEvent
        );
    }

    public function deleteEvent(GoogleAccount $googleAccount, Event $event): void
    {
        if (! $event->google_event_id) {
            return;
        }

        $calendarService = $this->getCalendarService($googleAccount);

        $calendarService->events->delete(
            $googleAccount->calendar_id,
            $event->google_event_id
        );
    }

    // citeste evenimentele din google calendar pe interval (-3,+12) luni
    public function listEvents(GoogleAccount $googleAccount): array
    {
        $calendarService = $this->getCalendarService($googleAccount);

        $events = $calendarService->events->listEvents(
            $googleAccount->calendar_id,
            [
                'singleEvents' => true,
                'orderBy' => 'startTime',
                'showDeleted' => true,
                'timeMin' => now()->subMonths(3)->toRfc3339String(),
                'timeMax' => now()->addMonths(12)->toRfc3339String(),
            ]
        );

        return $events->getItems();
    }
}
