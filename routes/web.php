<?php

use App\Http\Controllers\ShowcaseController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ShowcaseController::class, 'index'])->name('home');
Route::get('/api/movies', [ShowcaseController::class, 'movies'])->name('api.movies');
Route::get('/api/seed', [ShowcaseController::class, 'seed'])->name('api.seed');
