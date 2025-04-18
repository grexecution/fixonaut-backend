<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FileScan;
use App\Models\FileSuggestion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanupOldFilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:cleanup {--days=30 : Number of days after which processed files are eligible for cleanup} {--limit=50 : Maximum number of files to clean up per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old processed files to save storage space';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $days = $this->option('days');
        $limit = $this->option('limit');
        
        // Find old processed scans
        $cutoffDate = Carbon::now()->subDays($days);
        
        $oldScans = FileScan::where('status', 'processed')
            ->where('processed_at', '<', $cutoffDate)
            ->orderBy('processed_at')
            ->limit($limit)
            ->get();
        
        $count = $oldScans->count();
        
        if ($count === 0) {
            $this->info('No old file scans to clean up.');
            return 0;
        }
        
        $this->info("Found {$count} old file scans to clean up.");
        $cleanedCount = 0;
        
        foreach ($oldScans as $scan) {
            try {
                // Get the file storage path
                $fileTypeDir = $this->getStorageDirectoryByFileType($scan->file_type);
                $siteFolderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', parse_url($scan->site_url, PHP_URL_HOST));
                
                // Look for files with matching timestamp pattern (used in generateUniqueFileName)
                $pattern = 'wordpress/' . $fileTypeDir . '/' . $siteFolderName . '/*';
                $files = Storage::glob($pattern);
                
                // Find the exact file by looking at metadata or other identifying information
                $fileDeleted = false;
                foreach ($files as $file) {
                    // Check if this is the file we're looking for
                    // This is a simplified approach - in a real-world scenario, 
                    // you might want to store the exact storage path in the FileScan model
                    if (strpos(basename($file), pathinfo($scan->file_path, PATHINFO_BASENAME)) !== false) {
                        Storage::delete($file);
                        $fileDeleted = true;
                        break;
                    }
                }
                
                if ($fileDeleted) {
                    $this->info("Deleted file for scan ID: {$scan->id}, File: {$scan->file_path}");
                    $cleanedCount++;
                }
                
                // Keep the suggestion records but mark as archived
                FileSuggestion::where('file_scan_id', $scan->id)
                    ->update(['status' => 'archived']);
                
                // Mark the scan as archived
                $scan->status = 'archived';
                $scan->save();
                
                Log::info("Archived old file scan. Scan ID: {$scan->id}, Processed: " . 
                    $scan->processed_at->diffForHumans() . ".");
            } catch (\Exception $e) {
                $this->error("Failed to clean up scan ID: {$scan->id}: " . $e->getMessage());
                Log::error("Failed to clean up scan ID {$scan->id}: " . $e->getMessage());
            }
        }
        
        $this->info("Cleaned up {$cleanedCount} files.");
        return 0;
    }
    
    /**
     * Get storage directory based on file type
     *
     * @param string $fileType
     * @return string
     */
    private function getStorageDirectoryByFileType($fileType)
    {
        // Normalize file type to lowercase
        $fileType = strtolower($fileType);
        
        // Map file types to appropriate directories
        $directoryMap = [
            'image' => 'images',
            'img' => 'images',
            'jpeg' => 'images',
            'jpg' => 'images',
            'png' => 'images',
            'gif' => 'images',
            'webp' => 'images',
            'svg' => 'images',
            'css' => 'styles',
            'scss' => 'styles',
            'less' => 'styles',
            'php' => 'code',
            'js' => 'scripts',
            'html' => 'markup',
            'htm' => 'markup',
            'xml' => 'markup',
        ];
        
        // Return the appropriate directory or a default
        return isset($directoryMap[$fileType]) ? $directoryMap[$fileType] : 'other';
    }
}
