<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/tasks', function (Request $request) {

    $data = [
        [
            'title' => "Design new landing page wireframes",
            'date' => "06.01.2026",
            'priority' => 'high',
            'comments' => 13,
            'user' => ['name' => 'Hubert Golewski', 'src' => null],
            'labels' => [
                ['text' => 'Design', 'color' => null]
            ]
        ],
        [
            'title' => "Design new landing page wireframes",
            'date' => "06.01.2026",
            'priority' => 'medium',
            'comments' => 0,
            'user' => ['name' => 'Hubert Golewski', 'src' => null],
            'labels' => [
                ['text' => 'Design', 'color' => null]
            ]
        ],
        [
            'title' => "Design new landing page wireframes",
            'date' => "06.01.2026",
            'priority' => 'low',
            'comments' => 2,
            'user' => ['name' => 'Hubert Golewski', 'src' => null],
            'labels' => [
                ['text' => 'Design', 'color' => null],
                ['text' => 'Design', 'color' => '#b23e7c']
            ]
        ],
    ];


    if($request->status == 'in_test' OR ($request->status == 'to_do' AND $request->has('cursor'))) {
        $combined = [];
    } else {
        $combined = [...$data, ...$data];
    }

    return response()->json([
        'meta' => ['next_cursor' => empty($combined) ? null : 'sadasdasd', 'total' => $request->status == 'in_test' ? 0 : 18],
        'data' => $combined
    ]);
});