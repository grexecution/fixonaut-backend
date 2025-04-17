<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FileScan;
use App\Jobs\ProcessFileScanJob;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessPendingScansCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:process-pending {limit=50 : Maximum number of files to process in one batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending file scans and queue them for AI suggestions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = $this->argument('limit');
        
        $this->info("Looking for pending file scans to process (limit: {$limit})");
        
        try {
            // Find pending scans that are not yet queued or processed
            $pendingScans = FileScan::where('status', 'pending')
                ->orWhereNull('status')
                ->limit($limit)
                ->get();
            
            $this->info("Found {$pendingScans->count()} pending scans to process");
            
            $queuedCount = 0;
            
            // Queue each scan for processing
            foreach ($pendingScans as $scan) {
                // Mark as queued
                $scan->status = 'queued';
                $scan->save();
                
                // Dispatch job for processing
                ProcessFileScanJob::dispatch($scan);
                
                $queuedCount++;
            }
            
            $this->info("Queued {$queuedCount} scans for processing");
            Log::info("Queued {$queuedCount} scans for processing via scheduled command");
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Error processing pending scans: " . $e->getMessage());
            Log::error("Error processing pending scans: " . $e->getMessage());
            return 1;
        }
    }
}
