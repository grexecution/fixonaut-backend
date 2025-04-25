<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\FileUploadViewController;
use App\Http\Controllers\OpenAITestController;
use App\Http\Controllers\WordPressScanController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/check', function () {
    return view('chunk-uploader');
});



Route::get('/scan-wordpress-files', [WordPressScanController::class, 'processWordPressFiles']);

// Simple web routes for file uploads
Route::get('/uploads/chunked', [FileUploadViewController::class, 'showChunkedUploadForm'])->name('uploads.chunked-form');
Route::get('/uploads/standard', [FileUploadViewController::class, 'showStandardUploadForm'])->name('uploads.standard-form');

// OpenAI Testing Routes
Route::get('/openai-test', [OpenAITestController::class, 'index'])->name('openai-test.index');
Route::post('/openai-test/api', [OpenAITestController::class, 'testOpenAIAPI'])->name('openai-test.api');
Route::post('/openai-test/process-file', [OpenAITestController::class, 'testProcessFile'])->name('openai-test.process-file');
Route::post('/openai-test/process-directory', [OpenAITestController::class, 'testProcessDirectory'])->name('openai-test.process-directory');
Route::post('/openai-test/create-file', [OpenAITestController::class, 'createTestFile'])->name('openai-test.create-file');
Route::post('/openai-test/token-estimation', [OpenAITestController::class, 'testTokenEstimation'])->name('openai-test.token-estimation');
Route::get('/openai-test/history', [OpenAITestController::class, 'getProcessingHistory'])->name('openai-test.history');

Route::get('/openai-test/testInt', [OpenAITestController::class, 'processWordPressFiles']);

// API routes for WordPress file uploads
Route::prefix('api')->middleware('api')->group(function () {
    Route::post('/files/upload', [FileUploadController::class, 'process']);
    Route::post('/files/upload/init', [FileUploadController::class, 'initChunkUpload']);
    Route::post('/files/upload/chunk', [FileUploadController::class, 'processChunk']);
    Route::post('/files/upload/finalize', [FileUploadController::class, 'finalizeChunkUpload']);
    Route::post('/files/upload/abort', [FileUploadController::class, 'abortChunkUpload']);
});
