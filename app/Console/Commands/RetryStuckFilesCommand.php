<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FileScan;
use App\Jobs\ProcessFileScanJob;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RetryStuckFilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:retry-stuck {--limit=5 : Maximum number of stuck files to process} {--hours=1 : Number of hours after which a processing file is considered stuck}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for files stuck in processing state and retry them';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $limit = $this->option('limit');
        $hours = $this->option('hours');
        
        // Find scans that have been stuck in processing state
        $stuckTime = Carbon::now()->subHours($hours);
        
        $stuckScans = FileScan::where('status', 'processing')
            ->where('updated_at', '<', $stuckTime)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
        
        $count = $stuckScans->count();
        
        if ($count === 0) {
            $this->info('No stuck file scans found.');
            return 0;
        }
        
        $this->info("Found {$count} file scans stuck in processing state.");
        
        foreach ($stuckScans as $scan) {
            try {
                $this->info("Resetting stuck scan ID: {$scan->id}, File: {$scan->file_path}");
                
                // Reset status to queued
                $scan->status = 'queued';
                $scan->save();
                
                // Re-dispatch the job
                ProcessFileScanJob::dispatch($scan);
                
                Log::info("Reset stuck file scan. Scan ID: {$scan->id}, stuck for " . 
                    $scan->updated_at->diffForHumans() . ".");
            } catch (\Exception $e) {
                $this->error("Failed to reset stuck scan ID: {$scan->id}: " . $e->getMessage());
                Log::error("Failed to reset stuck scan ID {$scan->id}: " . $e->getMessage());
            }
        }
        
        $this->info('Finished processing stuck scans.');
        return 0;
    }
}
