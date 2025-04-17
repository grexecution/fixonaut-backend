<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FileScan;
use App\Models\FileSuggestion;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanupOldScansCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:cleanup-scans {days=30 : Number of days to keep scans}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old file scans and their suggestions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = $this->argument('days');
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("Cleaning up file scans older than {$days} days ({$cutoffDate})");
        
        try {
            // Find old scans
            $oldScans = FileScan::where('created_at', '<', $cutoffDate)->get();
            
            $this->info("Found {$oldScans->count()} scans to clean up");
            
            $suggestionCount = 0;
            
            // Delete each scan and its suggestions
            foreach ($oldScans as $scan) {
                // Count and delete suggestions
                $suggestionCount += FileSuggestion::where('file_scan_id', $scan->id)->count();
                FileSuggestion::where('file_scan_id', $scan->id)->delete();
                
                // Delete the scan
                $scan->delete();
            }
            
            $this->info("Cleaned up {$oldScans->count()} scans and {$suggestionCount} suggestions");
            Log::info("Cleaned up {$oldScans->count()} old scans and {$suggestionCount} suggestions");
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Error cleaning up scans: " . $e->getMessage());
            Log::error("Error cleaning up scans: " . $e->getMessage());
            return 1;
        }
    }
}
