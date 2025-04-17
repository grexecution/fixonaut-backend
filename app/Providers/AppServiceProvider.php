<?php

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set up custom error handling to ensure errors are logged
        $this->setupErrorLogging();
    }

    /**
     * Set up custom error logging to ensure errors are captured
     */
    private function setupErrorLogging(): void
    {
        // Register a custom error handler that logs all errors
        set_error_handler(function ($level, $message, $file = '', $line = 0) {
            Log::error("PHP Error: {$message} in {$file} on line {$line}");
            return false; // Let PHP's internal error handler continue
        }, E_ALL);

        // Register a custom exception handler
        set_exception_handler(function (\Throwable $e) {
            Log::error("Uncaught Exception: " . $e->getMessage(), [
                'exception' => $e,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        });

        // Log a test message to verify logging is working
        Log::info('Application started - Logging system initialized');
    }
}
