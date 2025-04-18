<?php

namespace App\Services;

use App\Models\FileScan;
use App\Models\FileSuggestion;
use App\Models\FileUpload;
use App\Models\FileUploadChunk;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class ChunkedFileProcessor
{
    /**
     * Assemble file chunks into a complete file
     *
     * @param FileUpload $fileUpload
     * @return bool
     */
    public function assembleChunks(FileUpload $fileUpload)
    {
        try {
            // If the final file already exists, skip assembly
            if (Storage::exists('private/' . $fileUpload->final_path)) {
                return true;
            }
            
            // Get all chunks for this upload
            $chunks = FileUploadChunk::where('upload_id', $fileUpload->id)
                ->where('is_received', true)
                ->orderBy('chunk_index')
                ->get();
                
            // Check if we have all chunks
            if ($chunks->count() == 0 || $chunks->count() != $fileUpload->total_chunks) {
                Log::warning("Cannot assemble file, missing chunks. Expected {$fileUpload->total_chunks}, got {$chunks->count()}");
                return false;
            }
            
            // Create directory structure if needed
            $finalDir = dirname($fileUpload->final_path);
            if (!Storage::exists('private/' . $finalDir)) {
                Storage::makeDirectory('private/' . $finalDir);
            }
            
            // Create/open target file
            $targetPath = 'private/' . $fileUpload->final_path;
            $targetFile = fopen(Storage::path($targetPath), 'wb');
            
            if (!$targetFile) {
                Log::error("Failed to open target file for writing: {$targetPath}");
                return false;
            }
            
            // Append each chunk to the target file
            foreach ($chunks as $chunk) {
                $chunkPath = $chunk->chunk_path;
                
                if (!Storage::exists('private/' . $chunkPath)) {
                    Log::error("Chunk file missing: {$chunkPath}");
                    fclose($targetFile);
                    return false;
                }
                
                $chunkContent = Storage::get('private/' . $chunkPath);
                fwrite($targetFile, $chunkContent);
            }
            
            fclose($targetFile);
            
            // Update file upload status if not already completed
            if ($fileUpload->status !== 'completed') {
                $fileUpload->status = 'completed';
                $fileUpload->save();
            }
            
            Log::info("Successfully assembled file from {$chunks->count()} chunks: {$fileUpload->final_path}");
            return true;
        } catch (\Exception $e) {
            Log::error("Error assembling chunks: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the file path for a file scan
     *
     * @param FileScan $fileScan
     * @return string|null
     */
    public function getFilePath(FileScan $fileScan)
    {
        // Find the latest completed file upload for this file path
        $fileUpload = FileUpload::where('file_path', $fileScan->file_path)
            ->where('status', 'completed')
            ->latest()
            ->first();
            
        if (!$fileUpload) {
            return null;
        }
        
        // Ensure the chunks are assembled
        $assembled = $this->assembleChunks($fileUpload);
        
        if (!$assembled) {
            return null;
        }
        
        return 'private/' . $fileUpload->final_path;
    }
}
