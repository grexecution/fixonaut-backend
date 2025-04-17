<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run file processing twice daily (8 AM and 8 PM)
        $schedule->command('files:process-pending')
                 ->twiceDaily(8, 20)
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/schedule-file-processing.log'));
        
        // Clean up old scans weekly (Sunday at midnight)
        $schedule->command('files:cleanup-scans 30')
                 ->weekly()
                 ->sundays()
                 ->at('00:00')
                 ->appendOutputTo(storage_path('logs/schedule-cleanup.log'));
                 
        // Retry failed scans daily
        $schedule->command('queue:retry all')
                 ->daily()
                 ->at('01:00')
                 ->appendOutputTo(storage_path('logs/schedule-retry-queue.log'));
                 
        // Ensure queue worker is running
        $schedule->command('queue:work --stop-when-empty --max-time=300')
                 ->everyFifteenMinutes()
                 ->appendOutputTo(storage_path('logs/schedule-queue-worker.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
