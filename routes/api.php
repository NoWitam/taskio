<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::match('POST', '/disk/file', function (Request $request) {
    $file = $request->file('file');
    if (!$file) {
        return response()->json(['message' => 'Brak pliku (pole: file).'], 422);
    }

    $uuid = (string) str()->uuid();
    $ext = (string) $file->getClientOriginalExtension();
    $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $suffix = $safeExt ? ('.' . strtolower($safeExt)) : '';

    // Przechowujemy plik prywatnie. W tej fazie nie robimy czyszczenia orphanów.
    $path = Storage::disk('local')->putFileAs('task_attachments/files', $file, $uuid . $suffix);

    $meta = [
        'uuid' => $uuid,
        'name' => $file->getClientOriginalName(),
        'size' => $file->getSize(),
        'mime' => $file->getClientMimeType(),
        'path' => $path,
    ];

    Storage::disk('local')->put('task_attachments/meta/' . $uuid . '.json', json_encode($meta));

    return response()->json([
        'uuid' => $meta['uuid'],
        'name' => $meta['name'],
        'size' => $meta['size'],
        'mime' => $meta['mime'],
    ]);
});

Route::get('/tasks', function (Request $request) {
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

Route::post('/tasks', function (Request $request) {
    $title = trim((string) $request->input('title', ''));
    if ($title === '') {
        return response()->json(['message' => 'Tytuł jest wymagany.'], 422);
    }

    $priority = $request->input('priority', 'medium');
    $status = $request->input('status', 'to_do');
    $description = $request->input('description');
    $userId = $request->input('user_id');
    $labels = $request->input('labels', []);

    $dueYmd = $request->input('due_date');
    $dueDate = null;
    $date = null;
    if (is_string($dueYmd) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dueYmd, $m)) {
        $dueDate = $dueYmd;
        $date = $m[3].'.'.$m[2].'.'.$m[1];
    }

    $attachments = [];

    // Nowy kontrakt: attachments[] = lista UUID z PUT /disk/file
    $uuids = $request->input('attachments', []);
    if (is_string($uuids) && $uuids !== '') {
        $uuids = [$uuids];
    }

    if (is_array($uuids) && count($uuids)) {
        foreach ($uuids as $uuid) {
            $uuid = trim((string) $uuid);
            if ($uuid === '') continue;

            $metaPath = 'task_attachments/meta/' . $uuid . '.json';
            if (Storage::disk('local')->exists($metaPath)) {
                $raw = Storage::disk('local')->get($metaPath);
                $meta = json_decode($raw, true);
                if (is_array($meta)) {
                    $attachments[] = [
                        'uuid' => (string) ($meta['uuid'] ?? $uuid),
                        'name' => (string) ($meta['name'] ?? ''),
                        'size' => (int) ($meta['size'] ?? 0),
                        'mime' => (string) ($meta['mime'] ?? ''),
                    ];
                    continue;
                }
            }

            $attachments[] = [
                'uuid' => $uuid,
                'name' => '',
                'size' => 0,
                'mime' => '',
            ];
        }
    } else {
        // Fallback (stary kontrakt): attachments jako pliki
        $files = $request->file('attachments', []);
        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if (!$file) continue;
            $path = Storage::disk('public')->putFile('task_attachments', $file);
            $attachments[] = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
                'url' => asset('storage/' . $path),
            ];
        }
    }

    $task = [
        'id' => (string) str()->uuid(),
        'title' => $title,
        'description' => $description,
        'status' => $status,
        'priority' => $priority,
        'due_date' => $dueDate,
        'date' => $date,
        'comments' => 0,
        'user' => [
            'id' => $userId,
            'name' => $userId ? ('Użytkownik #' . $userId) : fake()->name(),
            'src' => null,
        ],
        'labels' => array_values(array_map(function ($id) {
            return [
                'id' => $id,
                'text' => 'Etykieta #' . $id,
                'color' => null,
            ];
        }, is_array($labels) ? $labels : (strlen((string)$labels) ? explode(',', (string) $labels) : []))),
        'attachments' => $attachments,
        'task_form' => [
            'mode' => $request->input('task_form_mode', 'none'),
            'template_id' => $request->input('task_form_template_id'),
        ],
    ];

    return response()->json($task);
});

Route::get('/labels', function (Request $request) {
    $ids = $request->input('ids', null);
    if (is_string($ids) && $ids !== '') {
        $ids = [$ids];
    }
    if (!is_array($ids)) {
        $ids = null;
    }

    $data = [
        [
            'id' => 'sadasd',
            'text' => 'Siema eniu'
        ],
        [
            'id' => 'sadxsd',
            'text' => 'Eluwina',
            'color' => '#f7f06a',
            'icon' => null,
        ],
        [
            'id' => 'sadasx',
            'text' => 'Ojciec',
            'color' => '#b82e7e',
            'icon' => null,
        ],
        [
            'id' => 'sadasde',
            'text' => 'Allah',
            'color' => '#b82e7e',
            'icon' => null,
        ],
        [
            'id' => 'sadasdxe',
            'text' => 'XDDD',
            'color' => '#b82e7e',
            'icon' => null,
        ],
    ];


    $combined = [...$data, ...$data, ...$data];

    // Prefetch mode: /labels?ids[]=a&ids[]=b
    if (is_array($ids)) {
        $byId = [];
        foreach ($combined as $l) {
            $byId[(string) ($l['id'] ?? '')] = $l;
        }

        $out = [];
        foreach ($ids as $id) {
            $idStr = (string) $id;
            if ($idStr === '') continue;

            if (isset($byId[$idStr])) {
                $out[] = $byId[$idStr];
            } else {
                $out[] = [
                    'id' => $idStr,
                    'text' => 'Etykieta #' . $idStr,
                    'color' => null,
                    'icon' => null,
                ];
            }
        }

        return response()->json([
            'meta' => ['next_cursor' => null, 'total' => count($out)],
            'data' => $out,
        ]);
    }

    if($request->has('q')) {
        $combined = [];
    }

    return response()->json([
        'meta' => ['next_cursor' => 'sadasdasd', 'total' => $request->status == 'in_test' ? 0 : 18],
        'data' => $combined
    ]);
});

Route::post('/labels', function (Request $request) {
    $text = trim((string) $request->input('text', ''));
    $color = $request->input('color');
    $icon = $request->input('icon');

    if ($text === '') {
        return response()->json(['message' => 'Nazwa etykiety jest wymagana'], 422);
    }

    return response()->json([
        'id' => (string) str()->uuid(),
        'text' => $text,
        'color' => $color ?: null,
        'icon' => $icon ?: null,
    ]);
});