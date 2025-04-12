<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FileUploadController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// WordPress file upload routes
Route::post('/files/upload', [FileUploadController::class, 'process']);
Route::get('/files/status/{id}', [FileUploadController::class, 'getStatus']);
Route::post('/files/update-progress/{id}', [FileUploadController::class, 'updateProgress']);
