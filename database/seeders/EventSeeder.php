<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Event;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        Event::create([
            'title' => 'Project meeting',
            'description' => 'Discuss calendar app requirements',
            'start' => '2026-07-03 10:00:00',
            'end' => '2026-07-03 11:00:00',
            'color' => '#2563eb',
        ]);

        Event::create([
            'title' => 'Frontend task',
            'description' => 'Implement calendar UI in React',
            'start' => '2026-07-05 14:00:00',
            'end' => '2026-07-05 16:00:00',
            'color' => '#16a34a',
        ]);

        Event::create([
            'title' => 'Backend API',
            'description' => 'Create Laravel API endpoints',
            'start' => '2026-07-08 09:00:00',
            'end' => '2026-07-08 12:00:00',
            'color' => '#dc2626',
        ]);
    }
}
