<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test', function () {
    return response()->json([
        'time' => now(),
        'memory' => memory_get_usage(true),
    ]);
});
