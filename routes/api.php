<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/tasksassd', function (Request $request) {
    $status = $request->status ?? 'to_do';

    $preset = (string) $request->input('date_preset', '');
    $dateFrom = $request->input('date_from');
    $dateTo = $request->input('date_to');

    // If preset is used, compute date range in ISO (YYYY-MM-DD)
    if ($preset) {
        $now = now();

        if ($preset === 'today') {
            $dateFrom = $now->toDateString();
            $dateTo = $now->toDateString();
        } elseif ($preset === 'this_week') {
            $dateFrom = $now->copy()->startOfWeek()->toDateString();
            $dateTo = $now->copy()->endOfWeek()->toDateString();
        } elseif ($preset === 'last_week') {
            $dateFrom = $now->copy()->subWeek()->startOfWeek()->toDateString();
            $dateTo = $now->copy()->subWeek()->endOfWeek()->toDateString();
        } elseif ($preset === 'this_month') {
            $dateFrom = $now->copy()->startOfMonth()->toDateString();
            $dateTo = $now->copy()->endOfMonth()->toDateString();
        }
    }

    $hasDateFilter = !empty($preset) || !empty($dateFrom) || !empty($dateTo);
    $hideWithoutDeadline = filter_var($request->input('hide_without_deadline', false), FILTER_VALIDATE_BOOLEAN);

    $data = [
        [
            'id' => (string) str()->uuid(),
            'title' => "Design new landing page wireframes",
            'description' => null,
            'status' => $status,
            'due_date' => '2026-01-06',
            'date' => "06.01.2026",
            'priority' => 'high',
            'comments' => 13,
            'user' => ['id' => (string) str()->uuid(), 'name' => 'Hubert Golewski', 'src' => null],
            'labels' => [
                ['id' => (string) str()->uuid(), 'text' => 'Design', 'color' => null]
            ]
        ],
        [
            'id' => (string) str()->uuid(),
            'title' => "Design new landing page wireframes",
            'description' => null,
            'status' => $status,
            // Example task without a deadline
            'due_date' => null,
            'date' => null,
            'priority' => 'medium',
            'comments' => 0,
            'user' => ['id' => (string) str()->uuid(), 'name' => 'Hubert Golewski', 'src' => null],
            'labels' => [
                ['id' => (string) str()->uuid(), 'text' => 'Design', 'color' => null]
            ]
        ],
        [
            'id' => (string) str()->uuid(),
            'title' => "Design new landing page wireframes",
            'description' => null,
            'status' => $status,
            'due_date' => '2026-01-06',
            'date' => "06.01.2026",
            'priority' => 'low',
            'comments' => 2,
            'user' => ['id' => (string) str()->uuid(), 'name' => 'Hubert Golewski', 'src' => null],
            'labels' => [
                ['id' => (string) str()->uuid(), 'text' => 'Design', 'color' => null],
                ['id' => (string) str()->uuid(), 'text' => 'Design', 'color' => '#b23e7c']
            ]
        ],
    ];


    if($request->status == 'in_test' OR ($request->status == 'to_do' AND $request->has('cursor'))) {
        $combined = [];
    } else {
        $combined = [...$data, ...$data];
    }

    if ($hasDateFilter || $hideWithoutDeadline) {
        $from = is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;
        $to = is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;

        $combined = array_values(array_filter($combined, function ($task) use ($from, $to, $hasDateFilter, $hideWithoutDeadline) {
            $due = $task['due_date'] ?? null;

            // If we're hiding tasks without deadline, always exclude them.
            if ($hideWithoutDeadline && !$due) return false;

            // If there is an active date range/preset:
            // - tasks without deadline are INCLUDED unless hideWithoutDeadline is enabled
            // - tasks with deadline must match the range
            if ($hasDateFilter) {
                if (!$due) return true;
                if ($from && $due < $from) return false;
                if ($to && $due > $to) return false;
                return true;
            }

            // No date filter: only apply hideWithoutDeadline (handled above)
            return true;
        }));
    }

    $total = $request->status == 'in_test' ? 0 : count($combined);

    return response()->json([
        'meta' => [
            'next_cursor' => empty($combined) ? null : 'sadasdasd', 
            'total' => $request->has('cursor') ? null : $total
        ],
        'data' => $combined
    ]);
});