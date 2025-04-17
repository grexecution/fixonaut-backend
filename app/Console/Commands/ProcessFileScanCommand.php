<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FileScan;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\Log;

class ProcessFileScanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'file:process-scan {scan_id : The ID of the file scan to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process a file scan for AI suggestions';

    /**
     * Execute the console command.
     */
    public function handle(OpenAIService $openAIService)
    {
        $scanId = $this->argument('scan_id');
        
        $this->info("Processing file scan ID: {$scanId}");
        
        try {
            // Get the file scan
            $fileScan = FileScan::findOrFail($scanId);
            
            // Check if scan is already processed
            if ($fileScan->status === 'processed') {
                $this->info("Scan already processed. Skipping.");
                return 0;
            }
            
            // Update status to processing
            $fileScan->status = 'processing';
            $fileScan->save();
            
            // Process the file through OpenAI
            $result = $openAIService->processFile($fileScan);
            
            // Update the scan status
            if ($result['status'] === 'success') {
                $fileScan->status = 'processed';
                $fileScan->processed_at = now();
                $fileScan->save();
                $this->info("File scan processed successfully.");
            } elseif ($result['status'] === 'unchanged') {
                $fileScan->status = 'processed';
                $fileScan->processed_at = now();
                $fileScan->save();
                $this->info("File unchanged since last scan. Using existing suggestions.");
            } elseif ($result['status'] === 'error') {
                $fileScan->status = 'failed';
                $fileScan->save();
                $this->error("Failed to process file scan: " . ($result['message'] ?? 'Unknown error'));
            }
            
            return 0;
        } catch (\Exception $e) {
            Log::error("Command error processing scan ID {$scanId}: " . $e->getMessage());
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }
}
