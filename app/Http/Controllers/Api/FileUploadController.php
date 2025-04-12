<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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
        // Validate API key
        if ($request->input('api-key') !== config('services.wordpress.api_key')) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

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
            
            // Generate a unique name for the file
            $fileName = $this->generateUniqueFileName(
                $request->input('site_url'),
                $request->input('file_path'),
                $request->input('file_type')
            );
            
            // Store the file content
            $path = 'wordpress/' . $this->getSiteFolderName($request->input('site_url')) . '/' . $fileName;
            Storage::put($path, $decodedContent);
            
            // Record scan information in database
            $scan = $this->recordScan($request);
            
            return response()->json([
                'success' => true,
                'message' => 'File processed successfully',
                'scan_id' => $scan->id,
                'timestamp' => $scan->created_at,
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
        $sanitizedPath = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filePath);
        return time() . '_' . $sanitizedPath;
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
            'status' => 'pending', // Initial status
        ]);
    }
    
    /**
     * Get the status of a file scan
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function getStatus($id)
    {
        // Validate API key if passed in query string
        if (request()->query('api-key') !== config('services.wordpress.api_key')) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }
        
        $scan = \App\Models\FileScan::find($id);
        
        if (!$scan) {
            return response()->json(['error' => 'Scan not found'], 404);
        }
        
        return response()->json([
            'scan_id' => $scan->id,
            'status' => $scan->status,
            'file_path' => $scan->file_path,
            'message' => $this->getMessageForStatus($scan->status),
            'processed_at' => $scan->processed_at,
            'issues_found' => $scan->issues_found,
        ]);
    }
    
    /**
     * Update the progress of a file scan
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function updateProgress(Request $request, $id)
    {
        // Validate API key
        if ($request->input('api-key') !== config('services.wordpress.api_key')) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }
        
        // Validate the request
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:pending,processing,completed,failed',
            'message' => 'nullable|string',
            'issues_found' => 'nullable|array',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }
        
        $scan = \App\Models\FileScan::find($id);
        
        if (!$scan) {
            return response()->json(['error' => 'Scan not found'], 404);
        }
        
        // Update scan with new status
        $scan->status = $request->input('status');
        
        // If scan is completed or failed, set processed_at timestamp
        if (in_array($request->input('status'), ['completed', 'failed'])) {
            $scan->processed_at = now();
        }
        
        // Update issues found if provided
        if ($request->has('issues_found')) {
            $scan->issues_found = $request->input('issues_found');
        }
        
        $scan->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Scan progress updated',
            'scan' => [
                'id' => $scan->id,
                'status' => $scan->status,
                'message' => $this->getMessageForStatus($scan->status),
                'processed_at' => $scan->processed_at,
            ],
        ]);
    }
    
    /**
     * Get a message based on the scan status
     *
     * @param string $status
     * @return string
     */
    private function getMessageForStatus($status)
    {
        switch ($status) {
            case 'pending':
                return 'File scan is pending and will be processed soon.';
            case 'processing':
                return 'File scan is currently being processed.';
            case 'completed':
                return 'File scan has been successfully completed.';
            case 'failed':
                return 'File scan encountered an error during processing.';
            default:
                return 'File scan status is unknown.';
        }
    }
}
