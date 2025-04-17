<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\FileScan;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\Log;

class ProcessFileScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * The file scan instance.
     *
     * @var \App\Models\FileScan
     */
    protected $fileScan;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\FileScan  $fileScan
     * @return void
     */
    public function __construct(FileScan $fileScan)
    {
        $this->fileScan = $fileScan;
    }

    /**
     * Execute the job.
     *
     * @param  \App\Services\OpenAIService  $openAIService
     * @return void
     */
    public function handle(OpenAIService $openAIService)
    {
        try {
            // Check if scan is already processed
            if ($this->fileScan->status === 'processed') {
                Log::info("Scan already processed. Skipping. Scan ID: {$this->fileScan->id}");
                return;
            }
            
            // Update status to processing
            $this->fileScan->status = 'processing';
            $this->fileScan->save();
            
            // Process the file through OpenAI
            $result = $openAIService->processFile($this->fileScan);
            
            // Update the scan status
            if ($result['status'] === 'success') {
                $this->fileScan->status = 'processed';
                $this->fileScan->processed_at = now();
                $this->fileScan->save();
                Log::info("File scan processed successfully. Scan ID: {$this->fileScan->id}");
            } elseif ($result['status'] === 'unchanged') {
                $this->fileScan->status = 'processed';
                $this->fileScan->processed_at = now();
                $this->fileScan->save();
                Log::info("File unchanged since last scan. Using existing suggestions. Scan ID: {$this->fileScan->id}");
            } elseif ($result['status'] === 'error') {
                $this->fileScan->status = 'failed';
                $this->fileScan->save();
                Log::error("Failed to process file scan: " . ($result['message'] ?? 'Unknown error') . ". Scan ID: {$this->fileScan->id}");
            }
        } catch (\Exception $e) {
            Log::error("Job error processing scan ID {$this->fileScan->id}: " . $e->getMessage());
            $this->fileScan->status = 'failed';
            $this->fileScan->save();
            
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error("Job failed for scan ID {$this->fileScan->id}: " . $exception->getMessage());
        
        // Update scan status to failed
        $this->fileScan->status = 'failed';
        $this->fileScan->save();
    }
}
