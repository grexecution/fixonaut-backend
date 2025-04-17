<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\FileUploadViewController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/check', function () {
    return view('chunk-uploader');
});

// Simple web routes for file uploads
Route::get('/uploads/chunked', [FileUploadViewController::class, 'showChunkedUploadForm'])->name('uploads.chunked-form');
Route::get('/uploads/standard', [FileUploadViewController::class, 'showStandardUploadForm'])->name('uploads.standard-form');

// API routes for WordPress file uploads
Route::prefix('api')->middleware('api')->group(function () {
    Route::post('/files/upload', [FileUploadController::class, 'process']);
    Route::post('/files/upload/init', [FileUploadController::class, 'initChunkUpload']);
    Route::post('/files/upload/chunk', [FileUploadController::class, 'processChunk']);
    Route::post('/files/upload/finalize', [FileUploadController::class, 'finalizeChunkUpload']);
    Route::post('/files/upload/abort', [FileUploadController::class, 'abortChunkUpload']);
});
