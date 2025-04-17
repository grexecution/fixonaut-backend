<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class FileUploadController extends Controller
{
    /**
     * Process file upload from WordPress plugin
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function process(Request $request)
    {
        // Validate API key - commented out for now
        // if ($request->input('api-key') !== config('services.wordpress.api_key')) {
        //     return response()->json(['error' => 'Invalid API key'], 401);
        // }

        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'file_path' => 'required|string',
            'file_type' => 'required|string',
            'content' => 'required|string',
            'site_url' => 'required|string|url',
            'theme' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            // Decode the base64 content
            $decodedContent = base64_decode($request->input('content'));
            
            // Get file type directory
            $fileTypeDir = $this->getStorageDirectoryByFileType($request->input('file_type'));
            
            // Validate file content based on file type
            $validationResult = $this->validateFileByType($request->input('file_type'), $decodedContent);
            if ($validationResult !== true) {
                return response()->json(['error' => $validationResult], 400);
            }
            
            // Generate a unique name for the file
            $fileName = $this->generateUniqueFileName(
                $request->input('site_url'),
                $request->input('file_path'),
                $request->input('file_type')
            );
            
            // Store the file content in the appropriate directory
            $path = 'wordpress/' . $fileTypeDir . '/' . $this->getSiteFolderName($request->input('site_url')) . '/' . $fileName;
            
            // Ensure directory exists
            $directory = dirname(storage_path('app/' . $path));
            if (!file_exists($directory)) {
                mkdir($directory, 0777, true);
            }
            
            Storage::put($path, $decodedContent);
            
            // Record scan information in database
            $scan = $this->recordScan($request);
            
            return response()->json([
                'success' => true,
                'message' => 'File processed successfully',
                'scan_id' => $scan->id,
                'timestamp' => $scan->created_at,
                'file_type' => $request->input('file_type'),
                'storage_directory' => $fileTypeDir,
            ]);
        } catch (\Exception $e) {
            Log::error('File upload error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to process file'], 500);
        }
    }

    /**
     * Generate a unique file name for storage
     *
     * @param string $siteUrl
     * @param string $filePath
     * @param string $fileType
     * @return string
     */
    private function generateUniqueFileName($siteUrl, $filePath, $fileType)
    {
        // Extract original extension from file path if it exists
        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        // Clean the file path for use in the filename
        $sanitizedPath = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filePath);
        
        // Generate unique ID
        $uniqueId = substr(md5($siteUrl . $filePath . microtime(true) . rand(1000, 9999)), 0, 10);
        $timestamp = date('Ymd_His');
        
        // If we have both a sanitized path and an extension from the original path
        if (!empty($sanitizedPath) && !empty($fileExtension)) {
            // Remove any existing extension from sanitized path to avoid duplicates
            $sanitizedPath = preg_replace('/\.' . preg_quote($fileExtension, '/') . '$/', '', $sanitizedPath);
            
            // Return with proper extension
            return $timestamp . '_' . $uniqueId . '_' . $sanitizedPath . '.' . $fileExtension;
        }
        
        // Map image file types to proper extensions
        $imageExtensionMap = [
            'image' => 'png',   // Default image extension if no specific type
            'img' => 'png',     // Default to png for generic image
            'jpeg' => 'jpeg',
            'jpg' => 'jpg',
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            'svg' => 'svg'
        ];
        
        // Ensure we always have a proper extension based on file_type if no extension in path
        if (empty($fileExtension) && !empty($fileType)) {
            $normalizedFileType = strtolower($fileType);
            
            // If it's an image type, use the mapped extension
            if (array_key_exists($normalizedFileType, $imageExtensionMap)) {
                return $timestamp . '_' . $uniqueId . '_' . $sanitizedPath . '.' . $imageExtensionMap[$normalizedFileType];
            } else {
                return $timestamp . '_' . $uniqueId . '_' . $sanitizedPath . '.' . $fileType;
            }
        }
        
        // Default fallback (should rarely hit this)
        return $timestamp . '_' . $uniqueId . '_' . $sanitizedPath;
    }

    /**
     * Get a sanitized folder name from the site URL
     *
     * @param string $siteUrl
     * @return string
     */
    private function getSiteFolderName($siteUrl)
    {
        $host = parse_url($siteUrl, PHP_URL_HOST);
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $host);
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
    
    /**
     * Validate file based on type
     *
     * @param string $fileType
     * @param string $content
     * @return bool|string Returns true if valid, error message if invalid
     */
    private function validateFileByType($fileType, $content)
    {
        // Normalize file type to lowercase
        $fileType = strtolower($fileType);
        
        // Special validation for different file types
        switch ($fileType) {
            case 'image':
            case 'img':
            case 'jpeg':
            case 'jpg':
            case 'png':
            case 'gif':
            case 'webp':
                // Validate image content
                if (function_exists('imagecreatefromstring') && !@imagecreatefromstring($content)) {
                    return 'Invalid image content';
                }
                break;
                
            case 'php':
                // Basic PHP syntax validation
                // Note: This just checks if it has PHP tags, not full syntax validation
                if (strpos($content, '<?php') === false) {
                    return 'Invalid PHP file: missing PHP opening tag';
                }
                break;
                
            case 'css':
                // Basic CSS validation
                // Just check if it has some CSS syntax elements
                if (!preg_match('/{|}|;/', $content)) {
                    return 'Invalid CSS file: missing basic CSS syntax';
                }
                break;
                
            case 'html':
            case 'htm':
                // Basic HTML validation
                // Just check if it has some HTML syntax elements
                if (!preg_match('/<html|<body|<div|<p|<a|<img|<script|<style|<!DOCTYPE/i', $content)) {
                    return 'Invalid HTML file: missing basic HTML elements';
                }
                break;
        }
        
        return true;
    }

    /**
     * Record the scan in the database
     *
     * @param Request $request
     * @return \App\Models\FileScan
     */
    private function recordScan(Request $request)
    {
        // Create a record of this scan
        return \App\Models\FileScan::create([
            'site_url' => $request->input('site_url'),
            'theme' => $request->input('theme'),
            'file_path' => $request->input('file_path'),
            'file_type' => $request->input('file_type'),
            'scan_date' => now(),
        ]);
    }

    /**
     * Initialize a chunked file upload
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function initChunkUpload(Request $request)
    {
        // Add more detailed logging
        Log::debug('initChunkUpload method called with request data: ' . json_encode($request->all()));
        
        // Debug: Write to PHP error log at the start of the method
        error_log('initChunkUpload method started - ' . date('Y-m-d H:i:s'));

        // Validate API key - commented out for now
        // if ($request->input('api-key') !== config('services.wordpress.api_key')) {
        //     return response()->json(['error' => 'Invalid API key'], 401);
        // }

        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'file_path' => 'required|string',
            'file_type' => 'required|string',
            'file_size' => 'required|integer',
            'total_chunks' => 'required|integer',
            'file_identifier' => 'required|string',
            'site_url' => 'required|string|url',
            'theme' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            // Use Storage facade to ensure proper directory creation and permissions
            $basePath = 'temp';
            $chunkUploadsPath = 'temp/chunk_uploads';
            $uploadDirPath = 'temp/chunk_uploads/' . $request->input('file_identifier');
            
            // Ensure base temp directory exists (using Storage to handle this properly)
            if (!Storage::exists($basePath)) {
                Storage::makeDirectory($basePath);
                Log::info('Created base temp directory');
            }
            
            // Ensure chunk uploads directory exists
            if (!Storage::exists($chunkUploadsPath)) {
                Storage::makeDirectory($chunkUploadsPath);
                Log::info('Created chunk uploads directory');
            }
            
            // Create temp directory for this specific upload
            if (!Storage::exists($uploadDirPath)) {
                Storage::makeDirectory($uploadDirPath);
                Log::info('Created upload directory: ' . $uploadDirPath);
            }
            
            // Get the absolute path for the temporary directory
            $tempDir = storage_path('app/' . $uploadDirPath);

            // Store metadata in cache (1 day expiration)
            $cacheKey = 'chunk_upload_' . $request->input('file_identifier');
            $metadata = [
                'file_path' => $request->input('file_path'),
                'file_type' => $request->input('file_type'),
                'file_size' => $request->input('file_size'),
                'total_chunks' => $request->input('total_chunks'),
                'received_chunks' => 0,
                'chunk_status' => array_fill(0, $request->input('total_chunks'), false),
                'site_url' => $request->input('site_url'),
                'theme' => $request->input('theme'),
                'started_at' => now(),
                'temp_dir' => $tempDir,
            ];
            
            Cache::put($cacheKey, $metadata, now()->addDay());
            
            return response()->json([
                'success' => true,
                'message' => 'Chunked upload initialized',
                'upload_id' => $request->input('file_identifier'),
            ]);
            
        } catch (\Exception $e) {
            $errorMessage = 'Chunk upload initialization error: ' . $e->getMessage();
            
            // Enhanced error logging with context information
            Log::error($errorMessage, [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            // Also log to the PHP error log
            error_log('INIT ERROR: ' . $errorMessage . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            
            return response()->json(['error' => $errorMessage], 500);
        }
    }

    /**
     * Process a chunk from the client
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function processChunk(Request $request)
    {
        // Validate API key - commented out for now
        // if ($request->input('api-key') !== config('services.wordpress.api_key')) {
        //     return response()->json(['error' => 'Invalid API key'], 401);
        // }

        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'file_identifier' => 'required|string',
            'chunk_index' => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1',
            'chunk_data' => 'required|string',
            'chunk_size' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $fileIdentifier = $request->input('file_identifier');
            $chunkIndex = $request->input('chunk_index');
            $cacheKey = 'chunk_upload_' . $fileIdentifier;
            
            // Get metadata from cache
            $metadata = Cache::get($cacheKey);
            if (!$metadata) {
                return response()->json(['error' => 'Upload session not found or expired'], 404);
            }
            
            // Validate chunk index
            if ($chunkIndex >= $metadata['total_chunks']) {
                return response()->json(['error' => 'Invalid chunk index'], 400);
            }
            
            // Decode chunk data and save to temp file
            $chunkData = base64_decode($request->input('chunk_data'), true);
            if ($chunkData === false) {
                return response()->json(['error' => 'Invalid chunk data encoding'], 400);
            }
            
            // Save chunk to temporary file
            $chunkPath = $metadata['temp_dir'] . '/chunk_' . $chunkIndex;
            
            // Make sure the directory exists
            if (!file_exists(dirname($chunkPath))) {
                mkdir(dirname($chunkPath), 0777, true);
            }
            
            // Ensure we're writing binary data correctly, using binary mode
            file_put_contents($chunkPath, $chunkData, LOCK_EX);
            
            // Update metadata
            $metadata['chunk_status'][$chunkIndex] = true;
            $metadata['received_chunks'] += 1;
            Cache::put($cacheKey, $metadata, now()->addDay());
            
            return response()->json([
                'success' => true,
                'message' => 'Chunk received successfully',
                'chunk_index' => $chunkIndex,
                'received_chunks' => $metadata['received_chunks'],
                'remaining_chunks' => $metadata['total_chunks'] - $metadata['received_chunks'],
            ]);
            
        } catch (\Exception $e) {
            $errorMessage = 'Chunk processing error: ' . $e->getMessage();
            Log::error($errorMessage);
            error_log($errorMessage);
            // Return more detailed error info for debugging
            return response()->json([
                'error' => 'Failed to process chunk', 
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * Finalize the chunked upload and process the complete file
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function finalizeChunkUpload(Request $request)
    {
        // Validate API key - commented out for now
        // if ($request->input('api-key') !== config('services.wordpress.api_key')) {
        //     return response()->json(['error' => 'Invalid API key'], 401);
        // }

        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'file_identifier' => 'required|string',
            'total_chunks' => 'required|integer|min:1',
            'uploaded_chunks' => 'required|integer|min:1',
            'file_path' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $fileIdentifier = $request->input('file_identifier');
            $cacheKey = 'chunk_upload_' . $fileIdentifier;
            
            // Get metadata from cache
            $metadata = Cache::get($cacheKey);
            if (!$metadata) {
                return response()->json(['error' => 'Upload session not found or expired'], 404);
            }
            
            // Verify all chunks were received
            if ($metadata['received_chunks'] != $metadata['total_chunks']) {
                return response()->json([
                    'error' => 'Not all chunks received', 
                    'received' => $metadata['received_chunks'],
                    'expected' => $metadata['total_chunks']
                ], 400);
            }
            
            // Combine all chunks into a single file
            $combinedFilePath = $metadata['temp_dir'] . '/combined_file';
            $combinedFile = fopen($combinedFilePath, 'wb'); // Ensure binary write mode
            
            for ($i = 0; $i < $metadata['total_chunks']; $i++) {
                $chunkPath = $metadata['temp_dir'] . '/chunk_' . $i;
                if (!file_exists($chunkPath)) {
                    fclose($combinedFile);
                    return response()->json(['error' => 'Chunk ' . $i . ' missing'], 400);
                }
                
                // Read chunk in binary mode
                $chunkData = file_get_contents($chunkPath, false);
                if ($chunkData === false) {
                    fclose($combinedFile);
                    return response()->json(['error' => 'Failed to read chunk ' . $i], 500);
                }
                
                // Write chunk to combined file
                fwrite($combinedFile, $chunkData);
                
                // Clean up the chunk file
                unlink($chunkPath);
            }
            
            fclose($combinedFile);
            
            // Get file content for validation
            $fileContent = file_get_contents($combinedFilePath);
            
            // Validate file content based on file type
            $validationResult = $this->validateFileByType($metadata['file_type'], $fileContent);
            if ($validationResult !== true) {
                // Clean up on validation failure
                unlink($combinedFilePath);
                rmdir($metadata['temp_dir']);
                Cache::forget($cacheKey);
                
                return response()->json(['error' => $validationResult], 400);
            }
            
            // Get file type directory
            $fileTypeDir = $this->getStorageDirectoryByFileType($metadata['file_type']);
            
            // Generate a unique file name for storage
            $fileName = $this->generateUniqueFileName(
                $metadata['site_url'],
                $metadata['file_path'],
                $metadata['file_type']
            );
            
            // Store the final file in the proper location
            $path = 'wordpress/' . $fileTypeDir . '/' . $this->getSiteFolderName($metadata['site_url']) . '/' . $fileName;
            
            // Ensure directory exists
            $directory = dirname(storage_path('app/' . $path));
            if (!file_exists($directory)) {
                mkdir($directory, 0777, true);
            }
            
            Storage::put($path, $fileContent);
            
            // Create a request object to use with recordScan
            $scanRequest = new Request();
            $scanRequest->merge([
                'site_url' => $metadata['site_url'],
                'theme' => $metadata['theme'],
                'file_path' => $metadata['file_path'],
                'file_type' => $metadata['file_type'],
            ]);
            
            // Record the scan in the database
            $scan = $this->recordScan($scanRequest);
            
            // Clean up
            unlink($combinedFilePath);
            rmdir($metadata['temp_dir']);
            Cache::forget($cacheKey);
            
            return response()->json([
                'success' => true,
                'message' => 'File upload complete',
                'scan_id' => $scan->id,
                'timestamp' => $scan->created_at,
                'file_type' => $metadata['file_type'],
                'storage_directory' => $fileTypeDir,
            ]);
            
        } catch (\Exception $e) {
            $errorMessage = 'Finalize upload error: ' . $e->getMessage();
            Log::error($errorMessage);
            error_log($errorMessage);
            return response()->json(['error' => 'Failed to finalize upload: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Abort a chunked upload
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function abortChunkUpload(Request $request)
    {
        // Validate API key - commented out for now
        // if ($request->input('api-key') !== config('services.wordpress.api_key')) {
        //     return response()->json(['error' => 'Invalid API key'], 401);
        // }

        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'file_identifier' => 'required|string',
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $fileIdentifier = $request->input('file_identifier');
            $cacheKey = 'chunk_upload_' . $fileIdentifier;
            
            // Get metadata from cache
            $metadata = Cache::get($cacheKey);
            if (!$metadata) {
                return response()->json(['message' => 'Upload session not found or already cleaned up'], 200);
            }
            
            // Log the abort reason
            $reason = $request->input('reason', 'No reason provided');
            Log::info('Chunked upload aborted: ' . $reason . ' for file ' . ($metadata['file_path'] ?? 'unknown'));
            
            // Clean up temp directory if it exists
            if (isset($metadata['temp_dir']) && file_exists($metadata['temp_dir'])) {
                $this->cleanupTempDirectory($metadata['temp_dir']);
            }
            
            // Remove from cache
            Cache::forget($cacheKey);
            
            return response()->json([
                'success' => true,
                'message' => 'Upload aborted successfully',
            ]);
            
        } catch (\Exception $e) {
            $errorMessage = 'Abort upload error: ' . $e->getMessage();
            Log::error($errorMessage);
            error_log($errorMessage);
            return response()->json(['error' => 'Failed to abort upload'], 500);
        }
    }

    /**
     * Clean up a temporary directory and all its contents
     *
     * @param string $tempDir
     * @return void
     */
    private function cleanupTempDirectory($tempDir)
    {
        if (!file_exists($tempDir)) {
            return;
        }
        
        $files = glob($tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        rmdir($tempDir);
    }
}
