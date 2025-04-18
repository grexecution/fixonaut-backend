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
        // Process pending WordPress plugin files every hour
        $schedule->command('files:process-pending --limit=20')
                 ->hourly()
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/schedule-file-processing.log'));
        
        // Process WordPress directory files hourly
        $schedule->command('wordpress:process-directory')
                 ->hourly()
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/schedule-wordpress-directory.log'));
        
        // Check for and retry stuck files hourly
        $schedule->command('files:retry-stuck --hours=1 --limit=10')
                 ->hourly()
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/schedule-retry-stuck.log'));
        
        // Clean up old files weekly (Sunday at midnight)
        $schedule->command('files:cleanup --days=30 --limit=100')
                 ->weekly()
                 ->sundays()
                 ->at('00:00')
                 ->appendOutputTo(storage_path('logs/schedule-cleanup.log'));
                 
        // Retry failed jobs daily
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
