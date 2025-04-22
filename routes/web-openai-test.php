<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OpenAITestController;

// OpenAI Test Routes
Route::get('/openai-test', [OpenAITestController::class, 'index'])->name('openai-test.index');
Route::post('/openai-test/api', [OpenAITestController::class, 'testOpenAIAPI'])->name('openai-test.api');
Route::post('/openai-test/process-file', [OpenAITestController::class, 'testProcessFile'])->name('openai-test.process-file');
Route::post('/openai-test/process-directory', [OpenAITestController::class, 'testProcessDirectory'])->name('openai-test.process-directory');
Route::post('/openai-test/create-file', [OpenAITestController::class, 'createTestFile'])->name('openai-test.create-file');
Route::post('/openai-test/token-estimation', [OpenAITestController::class, 'testTokenEstimation'])->name('openai-test.token-estimation');
Route::get('/openai-test/history', [OpenAITestController::class, 'getProcessingHistory'])->name('openai-test.history');
