<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FileScan;
use App\Jobs\ProcessFileScanJob;
use Illuminate\Support\Facades\Log;

class ProcessPendingFilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:process-pending {--limit=10 : Maximum number of files to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending file scans from the WordPress plugin';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $limit = $this->option('limit');
        
        // Get pending file scans
        $pendingScans = FileScan::where('status', 'pending')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
        
        $count = $pendingScans->count();
        
        if ($count === 0) {
            $this->info('No pending file scans to process.');
            return 0;
        }
        
        $this->info("Processing {$count} pending file scans...");
        
        foreach ($pendingScans as $scan) {
            try {
                // Update status to queued
                $scan->status = 'queued';
                $scan->save();
                
                // Dispatch job to process file
                ProcessFileScanJob::dispatch($scan);
                
                $this->info("Queued scan ID: {$scan->id}, File: {$scan->file_path}");
                Log::info("Queued file scan for processing via scheduler. Scan ID: {$scan->id}");
            } catch (\Exception $e) {
                $this->error("Failed to queue scan ID: {$scan->id}: " . $e->getMessage());
                Log::error("Scheduler failed to queue scan ID {$scan->id}: " . $e->getMessage());
                
                // Mark as failed if we couldn't even queue it
                $scan->status = 'failed';
                $scan->save();
            }
        }
        
        $this->info('File processing complete.');
        return 0;
    }
}
