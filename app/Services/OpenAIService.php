<?php

namespace App\Services;

use App\Models\FileScan;
use App\Models\FileSuggestion;
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
     * Constructor
     */
    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->model = config('services.openai.model', 'gpt-4');
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
            // Get the file path
            $storagePath = $this->getStoragePath($fileScan);
            
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
        // Create a suggestion record for tracking
        $suggestion = FileSuggestion::create([
            'file_scan_id' => $fileScan->id,
            'file_path' => $fileScan->file_path,
            'status' => 'processing',
            'ai_model' => $this->model,
            'metadata' => [
                'chunk_index' => $chunkIndex,
                'file_type' => $fileScan->file_type,
                'theme' => $fileScan->theme,
                'site_url' => $fileScan->site_url
            ]
        ]);
        
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
            
            return [$suggestion];
        } else {
            $suggestion->status = 'failed';
            $suggestion->error = $response['message'];
            $suggestion->save();
            
            return [];
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
        // Generate the storage path based on file type, site URL, and file path
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
}
