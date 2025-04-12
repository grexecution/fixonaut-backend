<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FileUploadController;

Route::get('/', function () {
    return view('welcome');
});

// API routes for WordPress file uploads
Route::prefix('api')->group(function () {
    Route::post('/files/upload', [FileUploadController::class, 'process']);
});
