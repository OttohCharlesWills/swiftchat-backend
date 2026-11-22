<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\ComplaintController;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/download', [DownloadController::class, 'index'])->name('download');

Route::post('/complaints', [ComplaintController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('complaints.store');
