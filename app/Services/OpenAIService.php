<?php

namespace App\Services;

use App\Models\FileScan;
use App\Models\FileSuggestion;
use App\Models\FileUpload;
use App\Services\ChunkedFileProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class OpenAIService
{
    /**
     * Maximum number of retries for API calls
     */
    const MAX_RETRIES = 3;
    
    /**
     * Delay between retries in seconds
     */
    const RETRY_DELAY = 5;
    
    /**
     * Max tokens per chunk to send to OpenAI
     */
    const MAX_TOKENS_PER_CHUNK = 4000;
    
    /**
     * OpenAI API key
     */
    protected $apiKey;
    
    /**
     * OpenAI model to use
     */
    protected $model;
    
    /**
     * Chunked file processor service
     */
    protected $chunkedFileProcessor;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->model = config('services.openai.model', 'gpt-4');
        $this->chunkedFileProcessor = new ChunkedFileProcessor();
    }
    
    /**
     * Process a file through OpenAI for suggestions
     *
     * @param FileScan $fileScan
     * @return array
     */
    public function processFile(FileScan $fileScan)
    {
        try {
            // First check if this is a chunked file upload
            $chunkedFilePath = $this->chunkedFileProcessor->getFilePath($fileScan);

            echo "<pre>";
            $chunkedFilePath = $this->chunkedFileProcessor->getFilePath($fileScan); 
            print_r($chunkedFilePath);
            die();

            
            if ($chunkedFilePath) {
                // This is a chunked file, use the assembled path
                $storagePath = $chunkedFilePath;
            } else {
                // Check for WordPress private directory path
                if (strpos($fileScan->file_path, 'wordpress/') !== false) {
                    $storagePath = $fileScan->file_path;
                } else {
                    // Use the legacy path mechanism
                    $storagePath = $this->getStoragePath($fileScan);
                }
            }
            
            if (!Storage::exists($storagePath)) {
                throw new Exception("File does not exist: {$storagePath}");
            }
            
            // Read the file content
            $content = Storage::get($storagePath);
            
            // Check if file has been modified since last scan
            $lastModified = Storage::lastModified($storagePath);
            $previousSuggestions = FileSuggestion::where('file_scan_id', $fileScan->id)
                ->where('file_path', $fileScan->file_path)
                ->get();
            
            // If we have previous suggestions and the file hasn't been modified, return existing suggestions
            if ($previousSuggestions->count() > 0) {
                $hasChanged = $this->hasFileChanged($previousSuggestions, $lastModified);
                if (!$hasChanged) {
                    return [
                        'status' => 'unchanged',
                        'message' => 'File has not been modified since last scan',
                        'suggestions' => $previousSuggestions
                    ];
                }
            }
            
            // Split the file into chunks if it's large
            $chunks = $this->splitIntoChunks($content);
            
            $allSuggestions = [];
            
            // Process each chunk
            foreach ($chunks as $index => $chunk) {
                $chunkSuggestions = $this->processSingleChunk($fileScan, $chunk, $index);
                $allSuggestions = array_merge($allSuggestions, $chunkSuggestions);
            }
            
            return [
                'status' => 'success',
                'message' => 'File processed successfully',
                'suggestions' => $allSuggestions
            ];
        } catch (Exception $e) {
            Log::error('OpenAI processing error: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process a single chunk of content
     *
     * @param FileScan $fileScan
     * @param string $content
     * @param int $chunkIndex
     * @return array
     */
    protected function processSingleChunk(FileScan $fileScan, $content, $chunkIndex)
    {
        // Extract the relative path information
        $relativePath = $fileScan->file_path;
        
        // For WordPress files in private directory, properly extract subdirectory structure
        if (strpos($relativePath, 'private/wordpress/') !== false) {
            $pathParts = explode('/', $relativePath);
            // Remove 'private/wordpress' prefix for cleaner path handling
            if (count($pathParts) > 2) {
                array_shift($pathParts); // Remove 'private'
                array_shift($pathParts); // Remove 'wordpress'
                $relativePath = implode('/', $pathParts);
            }
        }
        
        $pathInfo = pathinfo($relativePath);
        $dirName = isset($pathInfo['dirname']) && $pathInfo['dirname'] != '.' ? $pathInfo['dirname'] : '';
        $fileName = $pathInfo['basename'] ?? '';
        
        // Create a suggestion record for tracking with improved directory info
        $suggestion = FileSuggestion::create([
            'file_scan_id' => $fileScan->id,
            'file_path' => $fileScan->file_path,
            'status' => 'processing',
            'ai_model' => $this->model,
            'metadata' => [
                'chunk_index' => $chunkIndex,
                'file_type' => $fileScan->file_type,
                'theme' => $fileScan->theme,
                'site_url' => $fileScan->site_url,
                'directory' => $dirName,
                'filename' => $fileName,
                'relative_path' => $relativePath
            ]
        ]);
        
        // Store the last modified timestamp
        $storagePath = $fileScan->file_path;
        if (Storage::exists($storagePath)) {
            $suggestion->last_modified_at = now()->timestamp(Storage::lastModified($storagePath));
        }
        
        // Get token count (approximate)
        $tokenCount = $this->estimateTokenCount($content);
        $suggestion->token_count = $tokenCount;
        $suggestion->save();
        
        // Call OpenAI API with retry logic
        $response = $this->callOpenAIWithRetry($content, $fileScan->file_type);
        
        if ($response['status'] === 'success') {
            $suggestion->suggestion = $response['data'];
            $suggestion->status = 'processed';
            $suggestion->save();
            
            // Create suggestion file in a matching directory structure if from WordPress directory
            if (strpos($fileScan->file_path, 'private/wordpress/') !== false) {
                $this->createSuggestionFile($suggestion);
            }
            
            return [$suggestion];
        } else {
            $suggestion->status = 'failed';
            $suggestion->error = $response['message'];
            $suggestion->save();
            
            return [];
        }
    }
    
    /**
     * Create a suggestion file that mirrors the original file structure
     *
     * @param FileSuggestion $suggestion The suggestion record
     * @return bool Success status
     */
    protected function createSuggestionFile(FileSuggestion $suggestion)
    {
        try {
            $metadata = $suggestion->metadata;
            $originalPath = $suggestion->file_path;
            
            // Skip if we don't have the necessary metadata
            if (!isset($metadata['relative_path'])) {
                return false;
            }
            
            // Create suggestion file path
            $suggestionDir = 'private/suggestions';
            
            // Extract site and file structure from original path
            $pathParts = explode('/', $originalPath);
            // Remove 'private/wordpress' prefix
            if (count($pathParts) > 2 && $pathParts[0] === 'private' && $pathParts[1] === 'wordpress') {
                array_shift($pathParts); // Remove 'private'
                array_shift($pathParts); // Remove 'wordpress'
                
                // Use original structure for suggestion directory
                $siteSuggestionPath = $suggestionDir . '/' . implode('/', $pathParts);
                $suggestionFilePath = dirname($siteSuggestionPath);
                
                // Create directory structure if it doesn't exist
                if (!Storage::exists($suggestionFilePath)) {
                    Storage::makeDirectory($suggestionFilePath, 0755, true);
                }
                
                // Add file extension to indicate it's a suggestion
                $baseSuggestionPath = $siteSuggestionPath . '.suggestion.txt';
                
                // Save the suggestion content
                Storage::put($baseSuggestionPath, $suggestion->suggestion);
                
                // Update the suggestion record with the path
                $suggestion->metadata = array_merge($suggestion->metadata, [
                    'suggestion_file_path' => $baseSuggestionPath
                ]);
                $suggestion->save();
                
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            Log::error('Error creating suggestion file: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Call OpenAI API with retry logic
     *
     * @param string $content
     * @param string $fileType
     * @return array
     */
    protected function callOpenAIWithRetry($content, $fileType)
    {
        $retries = 0;
        
        while ($retries < self::MAX_RETRIES) {
            try {
                return $this->callOpenAI($content, $fileType);
            } catch (Exception $e) {
                $retries++;
                $message = $e->getMessage();
                
                // If we've reached max retries, return error
                if ($retries >= self::MAX_RETRIES) {
                    return [
                        'status' => 'error',
                        'message' => "Failed after {$retries} retries: {$message}"
                    ];
                }
                
                // Check for rate limiting issues
                if (stripos($message, 'rate limit') !== false) {
                    // Exponential backoff
                    $delaySeconds = self::RETRY_DELAY * pow(2, $retries - 1);
                    Log::warning("Rate limited by OpenAI, retrying in {$delaySeconds} seconds");
                    sleep($delaySeconds);
                } else {
                    // Regular delay
                    Log::warning("OpenAI API error, retrying in " . self::RETRY_DELAY . " seconds: {$message}");
                    sleep(self::RETRY_DELAY);
                }
            }
        }
    }
    
    /**
     * Call OpenAI API
     *
     * @param string $content
     * @param string $fileType
     * @return array
     */
    protected function callOpenAI($content, $fileType)
    {
        // Prepare the prompt based on file type
        $prompt = $this->preparePrompt($content, $fileType);
        
        // Call OpenAI API
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a code review assistant. Analyze the provided code and suggest improvements for performance, security, and best practices."
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.3,
            'max_tokens' => 1000,
        ]);
        
        if ($response->successful()) {
            $data = $response->json();
            return [
                'status' => 'success',
                'data' => $data['choices'][0]['message']['content']
            ];
        } else {
            $error = $response->json();
            $errorMessage = $error['error']['message'] ?? 'Unknown API error';
            Log::error('OpenAI API error: ' . $errorMessage);
            throw new Exception($errorMessage);
        }
    }
    
    /**
     * Prepare the prompt based on file type
     *
     * @param string $content
     * @param string $fileType
     * @return string
     */
    protected function preparePrompt($content, $fileType)
    {
        $basePrompt = "Please review the following {$fileType} code and provide suggestions for improvement:\n\n```{$fileType}\n{$content}\n```\n\n";
        
        switch ($fileType) {
            case 'php':
                $basePrompt .= "Focus on PHP best practices, security vulnerabilities, performance optimizations, and code structure.";
                break;
            case 'js':
            case 'javascript':
                $basePrompt .= "Focus on JavaScript best practices, security issues, performance optimizations, and modern ECMAScript features.";
                break;
            case 'css':
                $basePrompt .= "Focus on CSS best practices, responsive design, performance, and maintainability.";
                break;
            default:
                $basePrompt .= "Focus on general code quality, security, performance, and best practices.";
        }
        
        return $basePrompt;
    }
    
    /**
     * Split file content into chunks
     *
     * @param string $content
     * @return array
     */
    protected function splitIntoChunks($content)
    {
        // If content is small enough, return as a single chunk
        if ($this->estimateTokenCount($content) <= self::MAX_TOKENS_PER_CHUNK) {
            return [$content];
        }
        
        // Split by newlines
        $lines = explode("\n", $content);
        
        $chunks = [];
        $currentChunk = "";
        $currentTokens = 0;
        
        foreach ($lines as $line) {
            $lineTokens = $this->estimateTokenCount($line);
            
            // If adding this line would exceed token limit, start a new chunk
            if ($currentTokens + $lineTokens > self::MAX_TOKENS_PER_CHUNK) {
                if (!empty($currentChunk)) {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = $line;
                $currentTokens = $lineTokens;
            } else {
                $currentChunk .= (!empty($currentChunk) ? "\n" : "") . $line;
                $currentTokens += $lineTokens;
            }
        }
        
        // Add the last chunk if not empty
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }
        
        return $chunks;
    }
    
    /**
     * Estimate token count for content (approximate)
     *
     * @param string $content
     * @return int
     */
    protected function estimateTokenCount($content)
    {
        // A rough approximation: 1 token = ~4 characters
        return (int)ceil(strlen($content) / 4);
    }
    
    /**
     * Get the storage path for a file scan
     *
     * @param FileScan $fileScan
     * @return string
     */
    protected function getStoragePath(FileScan $fileScan)
    {
        // Check if this file is associated with a chunked upload
        $fileUpload = \App\Models\FileUpload::where('file_path', $fileScan->file_path)
            ->where('status', 'completed')
            ->latest()
            ->first();
            
        if ($fileUpload) {
            // File was uploaded via chunks, return the combined file path
            return 'private/' . $fileUpload->final_path;
        }
        
        // For WordPress files, respect the original file path structure
        if (strpos($fileScan->file_path, 'wp-') === 0 || 
            strpos($fileScan->file_path, 'wp-content') !== false || 
            strpos($fileScan->file_path, 'wp-includes') !== false || 
            strpos($fileScan->file_path, 'wp-admin') !== false) {
            
            // Preserve WordPress directory structure
            $fileTypeDir = $this->getStorageDirectoryByFileType($fileScan->file_type);
            $siteFolderName = $this->getSiteFolderName($fileScan->site_url);
            
            // Create path that preserves WordPress structure
            $wpPath = str_replace('\\', '/', $fileScan->file_path);
            $fileName = basename($wpPath);
            $wpRelativePath = dirname($wpPath);
            $wpRelativePath = ($wpRelativePath === '.') ? '' : '/' . $wpRelativePath;
            
            return 'wordpress/' . $fileTypeDir . '/' . $siteFolderName . $wpRelativePath . '/' . $fileName;
        }
        
        // Original path fallback (for legacy files)
        $fileTypeDir = $this->getStorageDirectoryByFileType($fileScan->file_type);
        $siteFolderName = $this->getSiteFolderName($fileScan->site_url);
        $fileName = $this->generateUniqueFileName($fileScan->site_url, $fileScan->file_path, $fileScan->file_type);
        
        return 'wordpress/' . $fileTypeDir . '/' . $siteFolderName . '/' . $fileName;
    }
    
    /**
     * Get storage directory by file type
     *
     * @param string $fileType
     * @return string
     */
    protected function getStorageDirectoryByFileType($fileType)
    {
        switch (strtolower($fileType)) {
            case 'php':
                return 'php';
            case 'js':
            case 'javascript':
                return 'js';
            case 'css':
                return 'css';
            case 'html':
                return 'html';
            default:
                return 'other';
        }
    }
    
    /**
     * Generate a site folder name from URL
     *
     * @param string $siteUrl
     * @return string
     */
    protected function getSiteFolderName($siteUrl)
    {
        // Remove protocol and www
        $siteName = preg_replace('(^https?://)', '', $siteUrl);
        $siteName = preg_replace('(^www\.)', '', $siteName);
        
        // Remove trailing slash
        $siteName = rtrim($siteName, '/');
        
        // Replace special characters
        $siteName = preg_replace('/[^a-zA-Z0-9]/', '-', $siteName);
        
        return $siteName;
    }
    
    /**
     * Generate a unique file name
     *
     * @param string $siteUrl
     * @param string $filePath
     * @param string $fileType
     * @return string
     */
    protected function generateUniqueFileName($siteUrl, $filePath, $fileType)
    {
        // Extract original extension
        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        // Clean file path
        $cleanPath = preg_replace('/[^a-zA-Z0-9]/', '-', $filePath);
        
        // Truncate if too long
        if (strlen($cleanPath) > 100) {
            $cleanPath = substr($cleanPath, 0, 100);
        }
        
        return $cleanPath . '.' . $fileExtension;
    }
    
    /**
     * Check if a file has been modified since the last scan
     *
     * @param \Illuminate\Support\Collection $suggestions
     * @param int $lastModified
     * @return bool
     */
    protected function hasFileChanged($suggestions, $lastModified)
    {
        foreach ($suggestions as $suggestion) {
            if ($suggestion->last_modified_at) {
                // If the file's last modified timestamp is newer than what we recorded
                if ($lastModified > $suggestion->last_modified_at->timestamp) {
                    return true;
                }
            } else {
                // If we don't have a last_modified_at, assume it's changed
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Process all files in WordPress directory
     *
     * @param string $wordpressDir The base WordPress directory path
     * @return array Results of processing
     */
    public function processWordPressDirectory($wordpressDir = 'private/wordpress')
    {
        $results = [
            'processed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'suggestions' => []
        ];
        
        // Ensure the directory exists
        if (!Storage::exists($wordpressDir)) {
            Storage::makeDirectory($wordpressDir);
            Log::info("Created WordPress directory: {$wordpressDir}");
            return $results;
        }
        
        // Log the content of the WordPress directory
        $files = Storage::files($wordpressDir);
        $directories = Storage::directories($wordpressDir);
        
        Log::info("WordPress directory contents:", [
            'directory' => $wordpressDir,
            'files_count' => count($files),
            'directories_count' => count($directories),
            'files' => $files,
            'directories' => $directories
        ]);
        
        // If there are no files but there are subdirectories, ensure we process each subdirectory
        if (count($files) === 0 && count($directories) > 0) {
            Log::info("No files found in root directory, processing subdirectories");
            foreach ($directories as $subdir) {
                Log::info("Processing subdirectory: {$subdir}");
                $this->scanDirectory($subdir, $results);
            }
        } else {
            // Process files recursively starting from the base directory
            $this->scanDirectory($wordpressDir, $results);
        }
        
        return $results;
    }
    
    /**
     * Recursively scan directory and process files
     *
     * @param string $directory The directory to scan
     * @param array &$results Reference to results array to update
     * @return void
     */
    protected function scanDirectory($directory, &$results)
    {
        try {
            echo "Scanning directory: {$directory}\n";
            Log::info("Scanning directory: {$directory}");
            
            // Use direct disk access to ensure we're getting all files
            $fullPath = Storage::path($directory);
            
            if (!file_exists($fullPath) || !is_dir($fullPath)) {
                echo "Directory not found or not accessible: {$fullPath}\n";
                Log::warning("Directory not found or not accessible: {$fullPath}");
                
                // Try adding 'storage/app/' prefix if the path doesn't exist
                $altPath = storage_path('app/' . $directory);
                if (file_exists($altPath) && is_dir($altPath)) {
                    echo "Found directory at alternate path: {$altPath}\n";
                    Log::info("Using alternate path: {$altPath}");
                    $fullPath = $altPath;
                } else {
                    return;
                }
            }
            
            $files = [];
            $directories = [];
            
            // Get files and directories using direct filesystem access
            $dirContents = scandir($fullPath);
            
            foreach ($dirContents as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                
                $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
                $relativePath = $directory . '/' . $item;
                
                if (is_dir($itemPath)) {
                    $directories[] = $relativePath;
                } else if (is_file($itemPath)) {
                    $files[] = $relativePath;
                }
            }
            
            // Log what we found
            echo "Found " . count($files) . " files and " . count($directories) . " subdirectories in {$directory}\n";
            Log::info("Found files and directories", [
                'directory' => $directory,
                'files_count' => count($files),
                'directories_count' => count($directories),
                'files' => $files,
                'directories' => $directories
            ]);
            
            // Process all files in the current directory
            foreach ($files as $filePath) {
                try {
                    // Skip files that don't need analysis or system files
                    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                    $filename = basename($filePath);
                    
                    // Skip hidden files and .DS_Store
                    if (substr($filename, 0, 1) === '.' || $filename === '.DS_Store') {
                        $results['skipped']++;
                        echo "Skipping system file: {$filePath}\n";
                        continue;
                    }
                    
                    echo "Checking file: {$filePath} with extension: {$extension}\n";
                    
                    if (!$this->shouldProcessFile($extension)) {
                        $results['skipped']++;
                        echo "Skipping file {$filePath} - extension not processable\n";
                        continue;
                    }
                    
                    // Create a FileScan record for this file
                    $fileScan = $this->createFileScanForPath($filePath);
                    
                    // Process the file
                    echo "Processing file: {$filePath}";
                    $processResult = $this->processFile($fileScan);
                    
                    if ($processResult['status'] === 'success') {
                        $results['processed']++;
                        $results['suggestions'] = array_merge(
                            $results['suggestions'], 
                            $processResult['suggestions']
                        );
                        echo "Successfully processed file: {$filePath}\n";
                    } elseif ($processResult['status'] === 'unchanged') {
                        $results['skipped']++;
                        echo "Skipped unchanged file: {$filePath}\n";
                    } else {
                        $results['failed']++;
                        echo "Failed to process file: {$filePath} - " . ($processResult['message'] ?? 'Unknown error') . "\n";
                    }
                } catch (Exception $e) {
                    echo "Error processing file {$filePath}: " . $e->getMessage() . "\n";
                    Log::error("Error processing file {$filePath}: " . $e->getMessage());
                    $results['failed']++;
                }
            }
            
            // Process all subdirectories
            foreach ($directories as $subdir) {
                echo "Processing subdirectory: {$subdir}\n";
                $this->scanDirectory($subdir, $results);
            }
        } catch (Exception $e) {
            echo "Error scanning directory {$directory}: " . $e->getMessage() . "\n";
            Log::error("Error scanning directory {$directory}: " . $e->getMessage());
        }
    }
    
    /**
     * Determine if a file should be processed based on extension
     *
     * @param string $extension File extension
     * @return bool
     */
    protected function shouldProcessFile($extension)
    {
        $processableExtensions = [
            'php', 'js', 'css', 'html', 'twig', 'jsx', 'ts', 'tsx', 'scss', 'less'
        ];
        
        return in_array(strtolower($extension), $processableExtensions);
    }
    
    /**
     * Create a FileScan record for a file path
     *
     * @param string $filePath Storage path to the file
     * @return FileScan
     */
    protected function createFileScanForPath($filePath)
    {
        $fileInfo = pathinfo($filePath);
        $extension = strtolower($fileInfo['extension'] ?? '');
        
        // Extract site from path (assuming format: private/wordpress/site-domain-com/...)
        $pathParts = explode('/', $filePath);
        
        // Determine site folder - we need to be more flexible with directory structure
        $siteFolder = 'unknown-site';
        $wpIndex = array_search('wordpress', $pathParts);
        
        if ($wpIndex !== false && isset($pathParts[$wpIndex + 1])) {
            $siteFolder = $pathParts[$wpIndex + 1];
        } elseif (count($pathParts) >= 3) {
            // Fallback to original logic
            $siteFolder = $pathParts[2] ?? 'unknown-site';
        }
        
        $siteUrl = 'https://' . str_replace('-', '.', $siteFolder);
        
        Log::info("Creating FileScan for file: {$filePath}", [
            'extension' => $extension,
            'site_folder' => $siteFolder,
            'site_url' => $siteUrl
        ]);
        
        // Create FileScan record
        return FileScan::create([
            'site_url' => $siteUrl,
            'theme' => 'auto-detected',
            'file_path' => $filePath,
            'file_type' => $extension,
            'scan_date' => now(),
            'status' => 'pending'
        ]);
    }
}
