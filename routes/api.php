<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\Api\FileScanController;
use App\Http\Controllers\WordPressScanController; // Import the controller

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/



// Chunked file upload routes
Route::post('/files/upload/init', [FileUploadController::class, 'initChunkUpload']);
Route::post('/files/upload/chunk', [FileUploadController::class, 'processChunk']);
Route::post('/files/upload/finalize', [FileUploadController::class, 'finalizeChunkUpload']);
Route::post('/files/upload/abort', [FileUploadController::class, 'abortChunkUpload']);

// File scanning and suggestions routes
Route::post('/files/scan/process', [FileScanController::class, 'processForSuggestions']);
Route::post('/files/scan/batch', [FileScanController::class, 'processBatch']);
Route::get('/files/scan/status', [FileScanController::class, 'getStatus']);
Route::get('/files/scan/suggestions', [FileScanController::class, 'getSuggestions']);
Route::post('/files/scan/retry', [FileScanController::class, 'retrySuggestions']);

// Additional routes without /api prefix for compatibility with WordPress plugin
// Route::prefix('/')->group(function () {
//     Route::post('files/upload', [FileUploadController::class, 'process']);
//     Route::post('files/upload/init', [FileUploadController::class, 'initChunkUpload']);
//     Route::post('files/upload/chunk', [FileUploadController::class, 'processChunk']);
//     Route::post('files/upload/finalize', [FileUploadController::class, 'finalizeChunkUpload']);
//     Route::post('files/upload/abort', [FileUploadController::class, 'abortChunkUpload']);
// });

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Route to trigger the WordPress file scan process
Route::post('/scan-wordpress', [WordPressScanController::class, 'processWordPressFiles']);

// New route to get the latest scans with suggestions per site_url
Route::get('/latest-scans', [WordPressScanController::class, 'getLatestScansWithSuggestions']);

// New route for SEO analysis of WordPress post content
Route::post('/analyze-seo', [WordPressScanController::class, 'analyzePostForSEO']);

// New route for live chat suggestions
Route::post('/live-chat-suggestion', [WordPressScanController::class, 'liveChatSuggestion']);

