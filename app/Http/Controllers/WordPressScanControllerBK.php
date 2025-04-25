<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\FileScan;       // Make sure this model exists
use App\Models\FileSuggestion; // Make sure this model exists
use Exception;
use JsonException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse; // Added for type hinting

class WordPressScanControllerBK extends Controller
{

    /**
     * Main function to process all files in the WordPress directory structure.
     * Suitable for direct invocation (e.g., via a route for testing).
     * WARNING: Can be long-running and resource-intensive. Use Queues for production.
     */
    public function processWordPressFiles(Request $request): JsonResponse // Added return type hint
    {
        // --- Configuration ---
        $wordpressDir = 'wordpress'; // Relative to Storage disk root (e.g., storage/app/wordpress)
        $openaiApiKey = config('services.openai.api_key');
        $openaiModel = config('services.openai.model', 'gpt-4-turbo');  
        $maxLinesPerChunk = 100; // Adjust based on token usage and context needs
        $siteUrl = $request->input('site_url', 'http://localhost'); // Get site_url or default

        // Increase execution time for testing ONLY - REMOVE FOR PRODUCTION QUEUES
        // set_time_limit(300); // Set to 5 minutes for testing, 0 for unlimited (use cautiously)

        Log::info("Starting WordPress file processing via direct function call.");

        if (!$openaiApiKey) {
            Log::error("OpenAI API Key is not configured in config/services.php or .env.");
            return response()->json(['status' => 'error', 'message' => 'OpenAI API Key is not configured.'], 500);
        }

        $results = [
            'target_directory' => storage_path('app/' . $wordpressDir), // Show absolute path being checked
            'directories_found' => [],
            'processed_files' => [],
            'errors' => []
        ];

        try {
            // Use absolute path for existence check with Storage facade
            if (!Storage::exists($wordpressDir)) {
                 Log::error("WordPress directory does not exist: " . storage_path('app/' . $wordpressDir));
                return response()->json(['status' => 'error', 'message' => 'WordPress directory does not exist: ' . $wordpressDir], 404);
            }

            // Get directories (optional for results, processing uses allFiles)
            $results['directories_found'][] = $wordpressDir;
            $subdirectories = Storage::allDirectories($wordpressDir);
            $results['directories_found'] = array_merge($results['directories_found'], $subdirectories);

            // Get all files recursively
            $allFiles = Storage::allFiles($wordpressDir);
            Log::info("Found " . count($allFiles) . " total files/items in {$wordpressDir}. Filtering supported extensions...");
        

            $processedCount = 0;
            foreach ($allFiles as $filePath) {
                if (!$this->isSupportedExtension($filePath)) {
                    continue;
                }

                $processedCount++;
                Log::info("Processing file ({$processedCount}): {$filePath}");
                $fileScan = null; // Initialize fileScan variable

                try {
                    $fileContent = Storage::get($filePath);
                    // Skip empty files
                    if (empty(trim($fileContent))) {
                        Log::info("Skipping empty file: {$filePath}");
                        $results['processed_files'][] = [
                            'path' => $filePath,
                            'status' => 'skipped (empty)',
                            'issues_count' => 0
                        ];
                        continue;
                    }

                    $fileSize = strlen($fileContent); // Use strlen for byte size
                    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                    $themeDir = basename(dirname($filePath)); // Example: immediate parent

                    // Create FileScan record
                    $fileScan = FileScan::create([
                        'site_url' => $siteUrl,
                        'theme' => $themeDir,
                        'file_path' => $filePath,
                        'file_type' => strtolower($extension),
                        'file_size' => $fileSize,
                        'scan_date' => now(),
                        'status' => 'processing'
                    ]);

                    // Chunk the code
                    $chunks = $this->chunkCode($fileContent, $maxLinesPerChunk);
                    $allChunkIssues = []; // Store validated issues from all chunks

                    // Analyze each chunk
                    foreach ($chunks as $index => $chunk) {
                        Log::debug("Analyzing chunk " . ($index + 1) . "/". count($chunks) . " for {$filePath} (Lines {$chunk['start_line']}-{$chunk['end_line']})");
                        $chunkIssues = $this->analyzeChunk($chunk, $filePath, $extension, $openaiApiKey, $openaiModel);
                        if ($chunkIssues === false) {
                            throw new Exception("Critical error analyzing chunk " . ($index + 1) . " for file {$filePath}");
                        }
                        $allChunkIssues = array_merge($allChunkIssues, $chunkIssues);
                    }

                    // Consolidate results and save suggestions
                    $processResult = $this->consolidateAndSaveResults(
                        $fileScan,
                        $filePath,
                        $extension,
                        $allChunkIssues,
                        $openaiApiKey,
                        $openaiModel
                    );

                    $results['processed_files'][] = [
                        'path' => $filePath,
                        'status' => $processResult['status'],
                        'issues_count' => $processResult['issues_count'] ?? 0,
                        'suggestion_path' => $processResult['suggestion_path'] ?? null
                    ];

                } catch (Exception $e) {
                    Log::error("Error processing file {$filePath}: " . $e->getMessage() . " on line " . $e->getLine()); // Log line number
                    $results['errors'][] = [
                        'path' => $filePath,
                        'error' => $e->getMessage()
                    ];
                    if ($fileScan && $fileScan->status !== 'completed') {
                        try {
                            $fileScan->update(['status' => 'failed']);
                        } catch(Exception $dbEx) {
                             Log::error("Failed to update FileScan status to failed for ID {$fileScan->id}: " . $dbEx->getMessage());
                        }
                    }
                }

                echo "<pre>";
                print_r([
                    'directories_count' => count($results['directories_found']),
                'files_processed' => count($results['processed_files']),
                'errors_count' => count($results['errors']),
                'results_summary' => $results 
                ]);
                die();

            } // End foreach file

            Log::info("WordPress file processing finished.");
            return response()->json([
                'status' => 'success',
                'message' => 'File processing complete.',
                'directories_count' => count($results['directories_found']),
                'files_processed' => count($results['processed_files']),
                'errors_count' => count($results['errors']),
                'results_summary' => $results // Include the detailed results
            ]);

        } catch (Exception $e) {
            Log::error("General error during WordPress file processing: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => "An unexpected error occurred: " . $e->getMessage(),
                'partial_results' => $results // Include any partial results gathered before the error
            ], 500);
        }
    }

    // --- Private Helper Methods ---

    /**
     * Check if the file extension is supported for scanning.
     */
    private function isSupportedExtension(string $filePath): bool
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        // Added more web-related extensions
        $supportedExtensions = ['php', 'js', 'css', 'html', 'htm', 'vue', 'jsx', 'ts', 'tsx', 'json', 'xml', 'yaml', 'yml', 'md', 'twig', 'blade.php', 'scss', 'less', 'sass', 'sql'];
        // Ensure blade.php is checked correctly
        if (str_ends_with(strtolower($filePath), '.blade.php')) {
            return true;
        }
        return in_array(strtolower($extension), $supportedExtensions, true);
    }

    /**
     * Helper function to chunk code and track line numbers.
     */
    private function chunkCode(string $code, int $maxLinesPerChunk = 100): array
    {
        // Normalize line endings before splitting
        $normalizedCode = str_replace(["\r\n", "\r"], "\n", $code);
        $lines = explode("\n", $normalizedCode);
        $totalLines = count($lines);
        $chunks = [];
        $currentAbsoluteLine = 1; // Start line numbering at 1

        for ($i = 0; $i < $totalLines; $i += $maxLinesPerChunk) {
            $chunkStartLine = $currentAbsoluteLine;
            // Calculate end line index for slicing (0-based)
            $endLineIndex = min($i + $maxLinesPerChunk - 1, $totalLines - 1);
            // Calculate absolute end line number (1-based)
            $chunkEndLine = $chunkStartLine + ($endLineIndex - $i);

            // Slice lines for the current chunk
            $chunkLines = array_slice($lines, $i, $maxLinesPerChunk);
            $chunkContent = implode("\n", $chunkLines);
            $lineCountInChunk = count($chunkLines);

            // Only add non-empty chunks
            if (!empty(trim($chunkContent))) {
                $chunks[] = [
                    'content' => $chunkContent,
                    'start_line' => $chunkStartLine, // Absolute start line (1-based)
                    'end_line' => $chunkEndLine,     // Absolute end line (1-based)
                    'line_count' => $lineCountInChunk // Number of lines in this specific chunk
                ];
            }
            // Update the starting line for the next chunk
            $currentAbsoluteLine = $chunkEndLine + 1;
        }
        return $chunks;
    }


    /**
     * Analyzes a single code chunk using OpenAI API.
     * Returns an array of validated issues found in the chunk, or FALSE on critical failure.
     */
    private function analyzeChunk(array $chunk, string $filePath, string $extension, string $apiKey, string $model): array | false
    {
        $chunkContent = $chunk['content'];
        $startLine = $chunk['start_line']; // Absolute start line of chunk
        $endLine = $chunk['end_line'];     // Absolute end line of chunk
        $chunkLineCount = $chunk['line_count']; // Lines in *this* chunk
        $validatedIssues = [];

        // Prompt asking for RELATIVE line numbers within the chunk
        $chunkPrompt = "Analyze this {$extension} code chunk from file '{$filePath}'. "
            . "This chunk represents lines {$startLine} to {$endLine} of the original file. "
            . "Identify ONLY specific issues present *within this chunk*. "
            . "Return valid JSON with an 'issues' array. Each issue object MUST include:\n"
            . "1. relative_line: {line_number} or {start_line}-{end_line} - Line number(s) RELATIVE TO THE START OF *THIS* CHUNK (starting from 1).\n"
            . "2. issue: Concise description of the actual problem.\n"
            . "3. severity: 'Critical', 'High', 'Medium', 'Low', or 'Info'.\n" // Added severity
            . "4. fix_suggestion: Complete replacement code for the problematic line(s), or detailed steps if not a direct replacement.\n"
            . "5. auto_fixable: 'yes' (direct replacement), 'semi' (needs review), or 'no' (manual fix required).\n"
            . "6. apply_method: 'replace_lines' or 'modify_lines'.\n\n"
            . "Important: ONLY flag actual problems within this specific chunk. Do NOT invent issues. If no issues found, return {\"issues\": []}.\n\n"
            . "Code chunk (Lines {$startLine}-{$endLine} absolute):\n```{$extension}\n{$chunkContent}\n```";


        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(180) // Increased timeout further for complex analysis
              ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You are a meticulous code review expert for {$extension} files, focusing on security, performance, and WordPress best practices. Respond ONLY with valid JSON. Each issue must include relative_line, issue, severity, fix_suggestion, auto_fixable, and apply_method." // Added severity to system prompt
                    ],
                    ['role' => 'user', 'content' => $chunkPrompt]
                ],
                'temperature' => 0.15, // Slightly adjusted temperature
                'max_tokens' => 2000, // Increased max_tokens slightly
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->failed()) {
                Log::error("OpenAI API request failed for chunk {$startLine}-{$endLine} of {$filePath}. Status: " . $response->status() . ". Body: " . $response->body());
                // Consider if this should be a critical failure or just skip the chunk
                return []; // Non-critical: return empty issues for this chunk
                // return false; // Critical: fail the entire file processing
            }

            $responseContent = $response->body();

            // Attempt to decode JSON
            try {
                $jsonData = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                Log::warning("Direct JSON decoding failed for chunk {$startLine}-{$endLine} of {$filePath}, attempting markdown extraction. Error: " . $e->getMessage());
                if (preg_match('/```(?:json)?(.*?)```/s', $responseContent, $matches)) {
                    $jsonContent = trim($matches[1]);
                    try {
                        $jsonData = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $eInner) {
                         Log::error("Failed to parse extracted JSON for chunk {$startLine}-{$endLine} of {$filePath}. Error: " . $eInner->getMessage());
                         return []; // Non-critical
                    }
                } else {
                    Log::error("Failed to parse JSON and no markdown block found for chunk {$startLine}-{$endLine} of {$filePath}. Response: " . $responseContent);
                    return []; // Non-critical
                }
            }

            // Validate JSON structure and issues
            if (isset($jsonData['issues']) && is_array($jsonData['issues'])) {
                foreach ($jsonData['issues'] as $issue) {
                    // Check for all required fields including severity
                    if (isset($issue['relative_line']) && isset($issue['issue']) && isset($issue['severity']) && isset($issue['fix_suggestion']) && isset($issue['auto_fixable']) && isset($issue['apply_method'])) {
                        // Calculate Absolute Line Number(s)
                        $absoluteLocation = null;
                        $relativeLocation = $issue['relative_line'];
                        $isValidLocation = false;

                        if (is_numeric($relativeLocation)) {
                            $relativeLineNum = (int)$relativeLocation;
                            // Check bounds: relative line must be >= 1 and <= number of lines in this chunk
                            if ($relativeLineNum >= 1 && $relativeLineNum <= $chunkLineCount) {
                                $absoluteLocation = $startLine + $relativeLineNum - 1;
                                $isValidLocation = true;
                            } else {
                                Log::warning("Relative line {$relativeLineNum} is outside the line count ({$chunkLineCount}) for chunk {$startLine}-{$endLine} in {$filePath}. Issue ignored.");
                            }
                        } elseif (preg_match('/^(\d+)-(\d+)$/', (string)$relativeLocation, $matches)) {
                            $relativeStart = (int)$matches[1];
                            $relativeEnd = (int)$matches[2];
                            // Check bounds: start >= 1, end >= start, end <= line count
                            if ($relativeStart >= 1 && $relativeEnd >= $relativeStart && $relativeEnd <= $chunkLineCount) {
                                $absoluteStart = $startLine + $relativeStart - 1;
                                $absoluteEnd = $startLine + $relativeEnd - 1;
                                $absoluteLocation = "{$absoluteStart}-{$absoluteEnd}";
                                $isValidLocation = true;
                            } else {
                                 Log::warning("Relative range {$relativeLocation} is outside the line count ({$chunkLineCount}) for chunk {$startLine}-{$endLine} in {$filePath}. Issue ignored.");
                            }
                        } else {
                             Log::warning("Invalid relative_line format '{$relativeLocation}' for chunk {$startLine}-{$endLine} in {$filePath}. Issue ignored.");
                        }

                        if ($isValidLocation) {
                            $issue['location'] = $absoluteLocation; // Use 'location' for the final absolute value
                            unset($issue['relative_line']); // Remove the relative one
                            $validatedIssues[] = $issue; // Add the validated issue with absolute location
                        }
                    } else {
                        Log::warning("Issue missing required fields (incl. severity) in chunk {$startLine}-{$endLine} of {$filePath}: " . json_encode($issue));
                    }
                }
            } elseif (isset($jsonData['issues']) && empty($jsonData['issues'])) {
                 Log::debug("No issues reported by AI for chunk {$startLine}-{$endLine} of {$filePath}.");
            }
            else {
                 Log::warning("Invalid JSON structure (missing 'issues' array or not an array) received for chunk {$startLine}-{$endLine} of {$filePath}. Response: " . $responseContent);
            }

        } catch (ConnectionException $e) {
            Log::error("HTTP Connection error analyzing chunk {$startLine}-{$endLine} of {$filePath}: " . $e->getMessage());
            return false; // Indicate critical failure
        } catch (Exception $e) {
            Log::error("General error analyzing chunk {$startLine}-{$endLine} of {$filePath}: " . $e->getMessage());
            return []; // Non-critical, return empty issues for this chunk
        }

        return $validatedIssues;
    }


    /**
     * Consolidates issues, generates summary, saves suggestions, and updates FileScan.
     */
    private function consolidateAndSaveResults(FileScan $fileScan, string $filePath, string $extension, array $allChunkIssues, string $apiKey, string $model): array
    {
        $suggestionFilePath = null; // Initialize
        $finalStatus = 'failed'; // Default status
        $issuesCount = count($allChunkIssues);

        try {
            $issuesJson = [
                'id' => basename($filePath, '.' . $extension) . '-analysis-' . time(), // Add timestamp for uniqueness
                'file' => basename($filePath),
                'full_path' => $filePath, // Include full path for clarity
                'issues' => $allChunkIssues,
                'documentation' => [
                    'issue_details' => 'Analysis complete. Review individual issues.',
                    'fix_explanation' => 'Apply fixes based on individual issue suggestions and severity.'
                ]
            ];

            // Optional: Generate a global summary if issues were found
            if ($issuesCount > 0) {
                 // Prepare a summary of issues for the global prompt
                $issueSummaryForPrompt = array_map(function($issue) {
                    return "- Line(s) " . $issue['location'] . " (" . ($issue['severity'] ?? 'N/A') . "): " . $issue['issue'];
                }, $allChunkIssues);
                $issueSummaryText = "Issues found:\n" . implode("\n", array_slice($issueSummaryForPrompt, 0, 10)); // Limit summary length further


                $globalPrompt = "The following issues were identified in '{$filePath}':\n"
                    . $issueSummaryText . (count($issueSummaryForPrompt) > 10 ? "\n(... and more)" : "") . "\n\n"
                    . "Based *only* on this list, create a brief summary JSON for documentation with two fields:\n"
                    . "1. issue_details: Short paragraph summarizing the types of issues (e.g., 'Minor coding standard violations and a potential XSS risk were found.').\n"
                    . "2. fix_explanation: Short paragraph on the general fix approach (e.g., 'Fixes involve applying escaping and adhering to standards.').\n"
                    . "Respond ONLY with the valid JSON object.";

                try {
                    $globalResponse = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ])->timeout(90) // Shorter timeout for summary
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $model, // Can potentially use a faster/cheaper model for summary
                        'messages' => [
                            ['role' => 'system', 'content' => "You summarize code review findings into documentation fields. Respond ONLY with valid JSON containing 'issue_details' and 'fix_explanation'."],
                            ['role' => 'user', 'content' => $globalPrompt]
                        ],
                        'temperature' => 0.3,
                        'max_tokens' => 300,
                        'response_format' => ['type' => 'json_object'],
                    ]);

                    if ($globalResponse->successful()) {
                        $globalContent = $globalResponse->body();
                        try {
                            $docData = json_decode($globalContent, true, 512, JSON_THROW_ON_ERROR);
                            if (isset($docData['issue_details'])) {
                                $issuesJson['documentation']['issue_details'] = $docData['issue_details'];
                            }
                            if (isset($docData['fix_explanation'])) {
                                $issuesJson['documentation']['fix_explanation'] = $docData['fix_explanation'];
                            }
                        } catch (JsonException $e) {
                            Log::error("Error parsing documentation JSON for {$filePath}: " . $e->getMessage() . ". Response: " . $globalContent);
                        }
                    } else {
                        Log::error("Failed to generate global analysis for {$filePath}: Status " . $globalResponse->status() . ". Body: " . $globalResponse->body());
                    }
                } catch (ConnectionException $e) {
                    Log::error("HTTP Connection error generating global analysis for {$filePath}: " . $e->getMessage());
                } catch (Exception $e) {
                    Log::error("General error generating global analysis for {$filePath}: " . $e->getMessage());
                }
            } else {
                $issuesJson['documentation']['issue_details'] = 'No specific code issues requiring fixes were automatically detected.';
                $issuesJson['documentation']['fix_explanation'] = 'No fixes suggested.';
            }

            // Save the JSON suggestion file
            $filePathInfo = pathinfo($filePath);
            // Ensure suggestions directory is relative to the storage path used
            $suggestionsDir = dirname($filePath) . '/suggestions'; // Relative path

            if (!Storage::exists($suggestionsDir)) {
                Storage::makeDirectory($suggestionsDir);
                Log::info("Created suggestions directory: " . storage_path('app/' . $suggestionsDir));
            }

            // Use original filename + _suggestions.json
            $suggestionFilename = $filePathInfo['filename'] . '_suggestions.json';
            $suggestionFilePath = $suggestionsDir . '/' . $suggestionFilename;

            Storage::put($suggestionFilePath, json_encode($issuesJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            Log::info("Saved suggestion file: " . $suggestionFilePath);


            // Create or Update FileSuggestion record
            FileSuggestion::updateOrCreate(
                ['file_scan_id' => $fileScan->id],
                [
                    'file_path' => $filePath, // Store relative path from storage root
                    'suggestion' => json_encode($issuesJson),
                    'status' => 'processed',
                    'ai_model' => $model,
                    'metadata' => json_encode([
                        'suggestion_file_path' => $suggestionFilePath, // Store relative path
                        'issues_count' => $issuesCount
                    ])
                ]
            );

            $fileScan->update(['status' => 'completed']);
            $finalStatus = 'success';

        } catch (Exception $e) {
            Log::error("Error during consolidation/saving for scan ID {$fileScan->id}, File: {$filePath}: " . $e->getMessage());
            if ($fileScan && $fileScan->status !== 'completed') {
                 try {
                     $fileScan->update(['status' => 'failed']);
                 } catch(Exception $dbEx) {
                     Log::error("Nested error updating FileScan status to failed for ID {$fileScan->id}: " . $dbEx->getMessage());
                 }
            }
             // Re-throw exception to be caught by the main file processing loop
             throw $e;
        }

        return [
            'status' => $finalStatus,
            'message' => $finalStatus === 'success' ? 'File processed successfully' : 'Processing failed during consolidation/saving.',
            'issues_count' => $issuesCount,
            'suggestion_path' => $suggestionFilePath // Return relative path
        ];
    }
}