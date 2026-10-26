<?php

namespace HorizonLens\Http;

use HorizonLens\ScheduleReader;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

final class ScheduleController
{
    public function __invoke(Request $request, ScheduleReader $reader)
    {
        $validated = $request->validate(['page' => 'nullable|integer|min:1|max:100000']);
        $page = (int) ($validated['page'] ?? 1);
        $available = true;
        try {
            $events = $reader->events();
            $rows = array_map($reader->describe(...), array_slice($events, ($page - 1) * 25, 25));
        } catch (Throwable) {
            $available = false;
            $events = $rows = [];
        }
        $tasks = new LengthAwarePaginator($rows, count($events), 25, $page, ['path' => $request->url()]);

        return view('horizon-lens::schedule', ['section' => 'schedule', 'tasks' => $tasks, 'available' => $available]);
    }
}
