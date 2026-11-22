<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DownloadController;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/download', [DownloadController::class, 'index'])->name('download');
