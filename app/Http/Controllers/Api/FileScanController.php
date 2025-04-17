<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\FileScan;
use App\Models\FileSuggestion;
use App\Services\OpenAIService;

class FileScanController extends Controller
{
    /**
     * OpenAI service
     */
    protected $openAIService;
    
    /**
     * Constructor
     */
    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }
    
    /**
     * Process a file for AI suggestions
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function processForSuggestions(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'scan_id' => 'required|exists:file_scans,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }
        
        try {
            // Get the file scan
            $fileScan = FileScan::findOrFail($request->input('scan_id'));
            
            // Check if already in process or processed
            if ($fileScan->status === 'processing') {
                return response()->json([
                    'status' => 'processing',
                    'message' => 'File scan is already being processed'
                ]);
            }
            
            if ($fileScan->status === 'processed') {
                return response()->json([
                    'status' => 'already_processed',
                    'message' => 'File scan has already been processed'
                ]);
            }
            
            // Update status to queued
            $fileScan->status = 'queued';
            $fileScan->save();
            
            // Dispatch the job to process the file
            \App\Jobs\ProcessFileScanJob::dispatch($fileScan);
            
            return response()->json([
                'status' => 'queued',
                'message' => 'File scan has been queued for processing',
                'scan_id' => $fileScan->id
            ]);
        } catch (\Exception $e) {
            Log::error('File scan processing error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to process file scan'], 500);
        }
    }
    
    /**
     * Get suggestions for a file scan
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getSuggestions(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'scan_id' => 'required|exists:file_scans,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }
        
        try {
            // Get the file scan
            $fileScan = FileScan::findOrFail($request->input('scan_id'));
            
            // Get the suggestions
            $suggestions = FileSuggestion::where('file_scan_id', $fileScan->id)->get();
            
            return response()->json([
                'status' => 'success',
                'scan' => $fileScan,
                'suggestions' => $suggestions
            ]);
        } catch (\Exception $e) {
            Log::error('Get suggestions error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get suggestions'], 500);
        }
    }
    
    /**
     * Process multiple files for AI suggestions
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function processBatch(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'scan_ids' => 'required|array',
            'scan_ids.*' => 'exists:file_scans,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }
        
        $results = [];
        $errors = [];
        
        foreach ($request->input('scan_ids') as $scanId) {
            try {
                $fileScan = FileScan::findOrFail($scanId);
                
                // Skip if already processed
                if ($fileScan->status === 'processed') {
                    $results[] = [
                        'scan_id' => $scanId,
                        'status' => 'already_processed'
                    ];
                    continue;
                }
                
                // If already processing or queued, skip
                if ($fileScan->status === 'processing' || $fileScan->status === 'queued') {
                    $results[] = [
                        'scan_id' => $scanId,
                        'status' => $fileScan->status
                    ];
                    continue;
                }
                
                // Mark as queued
                $fileScan->status = 'queued';
                $fileScan->save();
                
                // Dispatch job for processing
                \App\Jobs\ProcessFileScanJob::dispatch($fileScan);
                
                $results[] = [
                    'scan_id' => $scanId,
                    'status' => 'queued',
                    'message' => 'File scan has been queued for processing'
                ];
            } catch (\Exception $e) {
                Log::error("Error processing scan ID {$scanId}: " . $e->getMessage());
                $errors[] = [
                    'scan_id' => $scanId,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return response()->json([
            'status' => count($errors) === 0 ? 'success' : 'partial',
            'results' => $results,
            'errors' => $errors
        ]);
    }
    
    /**
     * Get status of file processing
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getStatus(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'scan_id' => 'required|exists:file_scans,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }
        
        try {
            // Get the file scan
            $fileScan = FileScan::findOrFail($request->input('scan_id'));
            
            // Get counts of suggestions by status
            $suggestionCounts = FileSuggestion::where('file_scan_id', $fileScan->id)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();
            
            return response()->json([
                'scan_id' => $fileScan->id,
                'file_path' => $fileScan->file_path,
                'status' => $fileScan->status,
                'processed_at' => $fileScan->processed_at,
                'suggestion_counts' => $suggestionCounts
            ]);
        } catch (\Exception $e) {
            Log::error('Get status error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get status'], 500);
        }
    }
    
    /**
     * Retry failed suggestions
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function retrySuggestions(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'scan_id' => 'required|exists:file_scans,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }
        
        try {
            // Get the file scan
            $fileScan = FileScan::findOrFail($request->input('scan_id'));
            
            // Get failed suggestions
            $failedSuggestions = FileSuggestion::where('file_scan_id', $fileScan->id)
                ->where('status', 'failed')
                ->get();
            
            if ($failedSuggestions->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No failed suggestions to retry'
                ]);
            }
            
            // Process each failed suggestion
            $retried = 0;
            foreach ($failedSuggestions as $suggestion) {
                // Skip if retry count is too high
                if ($suggestion->retry_count >= 3) {
                    continue;
                }
                
                // Get the file content
                $storagePath = $this->getStoragePath($fileScan);
                
                if (Storage::exists($storagePath)) {
                    // Mark as processing
                    $suggestion->status = 'processing';
                    $suggestion->incrementRetry();
                    
                    // Process through OpenAI (with a new instance for fresh retry logic)
                    $openAIService = new OpenAIService();
                    $result = $openAIService->processFile($fileScan);
                    
                    if ($result['status'] === 'success') {
                        $retried++;
                    }
                }
            }
            
            // Update the scan status if all suggestions now processed
            $remainingFailed = FileSuggestion::where('file_scan_id', $fileScan->id)
                ->where('status', 'failed')
                ->count();
            
            if ($remainingFailed === 0) {
                $fileScan->status = 'processed';
                $fileScan->processed_at = now();
                $fileScan->save();
            }
            
            return response()->json([
                'status' => 'success',
                'retried' => $retried,
                'remaining_failed' => $remainingFailed
            ]);
        } catch (\Exception $e) {
            Log::error('Retry suggestions error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retry suggestions'], 500);
        }
    }
    
    /**
     * Get the storage path for a file scan
     *
     * @param FileScan $fileScan
     * @return string
     */
    protected function getStoragePath(FileScan $fileScan)
    {
        // This is a simplified version; should match the logic in OpenAIService
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
        $siteName = preg_replace('(^https?://)', '', $siteUrl);
        $siteName = preg_replace('(^www\.)', '', $siteName);
        $siteName = rtrim($siteName, '/');
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
        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        $cleanPath = preg_replace('/[^a-zA-Z0-9]/', '-', $filePath);
        
        if (strlen($cleanPath) > 100) {
            $cleanPath = substr($cleanPath, 0, 100);
        }
        
        return $cleanPath . '.' . $fileExtension;
    }
}
