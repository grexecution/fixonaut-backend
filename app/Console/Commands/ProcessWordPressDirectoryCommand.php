<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\Log;

class ProcessWordPressDirectoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wordpress:process-directory {directory=wordpress : The directory containing WordPress files to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all WordPress files in the specified directory and create suggestions';

    /**
     * Execute the console command.
     */
    public function handle(OpenAIService $openAIService)
    {
        $directory = $this->argument('directory');
        
        $this->info("Starting to process WordPress files in: {$directory}");
        Log::info("Starting WordPress directory processing: {$directory}");
        
        try {
            // Process the directory recursively
            $results = $openAIService->processWordPressDirectory($directory);
            
            // Output results
            $this->info("WordPress directory processing completed:");
            $this->info("- Processed files: {$results['processed']}");
            $this->info("- Failed files: {$results['failed']}");
            $this->info("- Skipped files: {$results['skipped']}");
            $this->info("- Total suggestions created: " . count($results['suggestions']));
            
            Log::info("WordPress directory processing completed", [
                'processed' => $results['processed'],
                'failed' => $results['failed'],
                'skipped' => $results['skipped'],
                'total_suggestions' => count($results['suggestions'])
            ]);
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Error processing WordPress directory: " . $e->getMessage());
            Log::error("WordPress directory processing error: " . $e->getMessage(), [
                'directory' => $directory,
                'trace' => $e->getTraceAsString()
            ]);
            
            return 1;
        }
    }
}
