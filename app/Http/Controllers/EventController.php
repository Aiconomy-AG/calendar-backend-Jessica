<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index()
    {
        return response()->json(Event::all());
    }

    public function store(Request $request)
    {
        $event = Event::create([
            'title' => $request->title,
            'description' => $request->description,
            'start' => $request->start,
            'end' => $request->end,
            'color' => $request->color,
        ]);

        return response()->json($event);
    }

    public function show($id)
    {
        return response()->json(Event::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $event->update([
            'title' => $request->title,
            'description' => $request->description,
            'start' => $request->start,
            'end' => $request->end,
            'color' => $request->color,
        ]);

        return response()->json($event);
    }

    public function destroy($id)
    {
        Event::destroy($id);

        return response()->json([
            'message' => 'Event deleted'
        ]);
    }
}
