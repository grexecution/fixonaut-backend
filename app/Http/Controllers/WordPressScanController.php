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
use Illuminate\Http\JsonResponse;
use Carbon\Carbon; // Needed for timestamp comparison if using scan_date
use Illuminate\Support\Facades\DB; // Import DB facade for subquery

class WordPressScanController extends Controller
{

    /**
     * Main function to process all files in the WordPress directory structure.
     * Includes logic to skip files unchanged since the last successful scan.
     * WARNING: Can be long-running. Use Queues for production.
     */
    public function processWordPressFiles(Request $request): JsonResponse
    {
        // --- Configuration ---
        $wordpressDir = 'wordpress';
        $openaiApiKey = config('services.openai.api_key');
        $openaiModel = config('services.openai.model', 'gpt-4.1');
        $maxLinesPerChunk = 100;
        $siteUrl = $request->input('site_url', 'http://localhost');

        // Increase execution time for testing ONLY - REMOVE FOR PRODUCTION QUEUES
        // set_time_limit(300);

        Log::info("Starting WordPress file processing with change detection.");

        if (!$openaiApiKey) {
            Log::error("OpenAI API Key is not configured.");
            return response()->json(['status' => 'error', 'message' => 'OpenAI API Key is not configured.'], 500);
        }

        $results = [
            'target_directory' => storage_path('app/' . $wordpressDir),
            'directories_found' => [],
            'processed_files' => [], // Tracks files processed in *this* run
            'skipped_files' => [],   // Tracks files skipped in *this* run
            'errors' => []
        ];

        try {
            if (!Storage::exists($wordpressDir)) {
                 Log::error("WordPress directory does not exist: " . storage_path('app/' . $wordpressDir));
                return response()->json(['status' => 'error', 'message' => 'WordPress directory does not exist: ' . $wordpressDir], 404);
            }

            // Get directories 
            $results['directories_found'][] = $wordpressDir;
            $subdirectories = Storage::allDirectories($wordpressDir);
            $results['directories_found'] = array_merge($results['directories_found'], $subdirectories);

            // Get all files recursively
            $allFiles = Storage::allFiles($wordpressDir);
            Log::info("Found " . count($allFiles) . " total files/items in {$wordpressDir}. Filtering and checking for changes...");

            $processedCount = 0;
            $skippedCount = 0;
            foreach ($allFiles as $filePath) {
                // 1. Check Extension Support
                if (!$this->isSupportedExtension($filePath)) {
                    continue; // Skip unsupported extensions early
                }

                // 2. Check File Existence and Get Current Size
                if (!Storage::exists($filePath)) {
                    Log::warning("File listed but not found during processing (possible race condition?): {$filePath}");
                    $results['errors'][] = ['path' => $filePath, 'error' => 'File not found during size check.'];
                    continue;
                }
                $absolutePathFile = Storage::path($filePath);
                $currentFileSize = md5_file($absolutePathFile);

                // 3. Check for Previous Completed Scan and Compare Size
                $lastCompletedScan = FileScan::where('file_path', $filePath)
                                            // Optional: Add site_url or theme if paths aren't unique enough
                                            // ->where('site_url', $siteUrl)
                                            ->where('status', 'completed')
                                            ->latest('scan_date') // Get the most recent completed scan
                                            ->first();

                if ($lastCompletedScan && $lastCompletedScan->file_size === $currentFileSize) {
                    // File exists, was completed before, and size matches - SKIP
                    $skippedCount++;
                    Log::info("Skipping unchanged file ({$skippedCount}): {$filePath} (Size: {$currentFileSize} bytes)");
                    $results['skipped_files'][] = [
                        'path' => $filePath,
                        'reason' => 'Unchanged since last successful scan',
                        'last_scan_date' => $lastCompletedScan->scan_date->toDateTimeString(), // Assumes scan_date is Carbon instance
                        'size' => $currentFileSize
                    ];
                    continue; // Move to the next file
                }

                // 4. Proceed with Processing (File is new, changed, or previous scan failed/incomplete)
                $processedCount++;
                if ($lastCompletedScan) {
                     Log::info("Processing changed file ({$processedCount}): {$filePath} (Current Size: {$currentFileSize}, Previous Size: {$lastCompletedScan->file_size})");
                } else {
                     Log::info("Processing new or previously failed file ({$processedCount}): {$filePath} (Size: {$currentFileSize})");
                }

                $fileScan = null; // Initialize fileScan variable for this file's processing

                try {
                    $fileContent = Storage::get($filePath);
                    if (empty(trim($fileContent))) {
                        Log::info("Skipping empty file: {$filePath}");
                         $results['processed_files'][] = [ // Still mark as processed in this cycle, but skipped internally
                            'path' => $filePath,
                            'status' => 'skipped (empty)',
                            'issues_count' => 0
                        ];
                        // Create a basic 'completed' scan record for empty files so they are skipped next time if size is 0
                         FileScan::updateOrCreate(
                            ['file_path' => $filePath],
                            [
                                'site_url' => $siteUrl,
                                'theme' => basename(dirname($filePath)),
                                'file_type' => pathinfo($filePath, PATHINFO_EXTENSION),
                                'file_size' => 0,
                                'scan_date' => now(),
                                'status' => 'completed' 
                            ]
                        );
                        continue; 
                    }

                    $fileSize = $currentFileSize; 
                    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                    $themeDir = basename(dirname($filePath));

                    // Create or Update FileScan record - Mark as 'processing'
                    // Use updateOrCreate to handle potential previous failed/processing states
                    $fileScan = FileScan::updateOrCreate(
                         ['file_path' => $filePath],
                         [
                            'site_url' => $siteUrl,
                            'theme' => $themeDir,
                            'file_type' => strtolower($extension),
                            'file_size' => $fileSize, 
                            'scan_date' => now(),
                            'status' => 'processing' 
                        ]
                    );

           
                    $chunks = $this->chunkCode($fileContent, $maxLinesPerChunk);
                    $allChunkIssues = [];

                    foreach ($chunks as $index => $chunk) {
                        Log::debug("Analyzing chunk " . ($index + 1) . "/". count($chunks) . " for {$filePath} (Lines {$chunk['start_line']}-{$chunk['end_line']})");
                        //echo "Analyzing chunk " . ($index + 1) . "/". count($chunks) . " for {$filePath} (Lines {$chunk['start_line']}-{$chunk['end_line']})";
                        $chunkIssues = $this->analyzeChunk($chunk, $filePath, $extension, $openaiApiKey, $openaiModel);
                        if ($chunkIssues === false) {
                            // If analyzeChunk returns false, it's a critical API/connection error
                            throw new Exception("Critical error analyzing chunk " . ($index + 1) . ". See logs for details.");
                        }
                        $allChunkIssues = array_merge($allChunkIssues, $chunkIssues);
                    }

                   
                    $processResult = $this->consolidateAndSaveResults(
                        $fileScan, // Pass the existing record
                        $filePath,
                        $extension,
                        $allChunkIssues,
                        $openaiApiKey,
                        $openaiModel
                    );
    
                    $results['processed_files'][] = [
                        'path' => $filePath,
                        'status' => $processResult['status'], // 'success' or 'error' from consolidation
                        'issues_count' => $processResult['issues_count'] ?? 0,
                        'suggestion_path' => $processResult['suggestion_path'] ?? null
                    ];  


                    echo "<pre>";
                    print_r($results['processed_files']);
                    die();
                    

                } catch (Exception $e) {
                    Log::error("Error processing file {$filePath}: " . $e->getMessage() . " on line " . $e->getLine());
                    $results['errors'][] = [
                        'path' => $filePath,
                        'error' => $e->getMessage()
                    ];
                    // Ensure FileScan is marked as failed if an exception occurred during processing
                    if ($fileScan && $fileScan->status !== 'completed') {
                        try {
                            // Check if it exists before updating, as updateOrCreate might have failed
                            $scanToFail = FileScan::find($fileScan->id);
                            if ($scanToFail) {
                                $scanToFail->update(['status' => 'failed']);
                            }
                        } catch(Exception $dbEx) {
                             Log::error("Failed to update FileScan status to failed for ID {$fileScan->id}: " . $dbEx->getMessage());
                        }
                    }
                }


            } // End foreach file

            Log::info("WordPress file processing finished. Processed: {$processedCount}, Skipped (unchanged): {$skippedCount}.");
            return response()->json([
                'status' => 'success',
                'message' => 'File processing complete.',
                'directories_count' => count($results['directories_found']),
                'files_processed_this_cycle' => count($results['processed_files']),
                'files_skipped_this_cycle' => count($results['skipped_files']),
                'errors_this_cycle' => count($results['errors']),
                'results_summary' => $results // Include detailed results
            ]);

        } catch (Exception $e) {
            Log::error("General error during WordPress file processing: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => "An unexpected error occurred: " . $e->getMessage(),
                'partial_results' => $results
            ], 500);
        }
    }

    // --- Private Helper Methods (chunkCode, isSupportedExtension, analyzeChunk, consolidateAndSaveResults) ---
    // Keep the implementations of these private methods exactly as they were in the previous answer.
    // ... (Paste the code for the 4 private helper methods here) ...


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
        // $chunkPrompt = "Analyze this {$extension} code chunk from file '{$filePath}'. "
        //     . "This chunk represents lines {$startLine} to {$endLine} of the original file. "
        //     . "Identify ONLY specific issues present *within this chunk*. "
        //     . "Return valid JSON with an 'issues' array. Each issue object MUST include:\n"
        //     . "1. relative_line: {line_number} or {start_line}-{end_line} - Line number(s) RELATIVE TO THE START OF *THIS* CHUNK (starting from 1).\n"
        //     . "2. issue: Concise description of the actual problem.\n"
        //     . "3. severity: 'Critical', 'High', 'Medium', 'Low', or 'Info'.\n" // Added severity
        //     . "4. fix_suggestion: Complete replacement code for the problematic line(s), or detailed steps if not a direct replacement.\n"
        //     . "5. auto_fixable: 'yes' (direct replacement), 'semi' (needs review), or 'no' (manual fix required).\n"
        //     . "6. apply_method: 'replace_lines' or 'modify_lines'.\n\n"
        //     . "Important: ONLY flag actual problems within this specific chunk. Do NOT invent issues. If no issues found, return {\"issues\": []}.\n\n"
        //     . "Code chunk (Lines {$startLine}-{$endLine} absolute):\n```{$extension}\n{$chunkContent}\n```";

         // Contextual Information
         $currentUtcTime = gmdate('Y-m-d H:i:s'); 
         $currentUser = 'codestertech'; 

        
        $chunkPrompt = "Analyze the following code chunk provided by user '{$currentUser}' at {$currentUtcTime} UTC. "
                    . "The chunk originates from the file '{$filePath}' within a WordPress theme directory. This file likely contains a mix of PHP, HTML (including WordPress Gutenberg block comments like `<!-- wp:... -->`), possibly JavaScript, and CSS.\n\n"
                    . "This specific chunk represents lines {$startLine} to {$endLine} of the original file.\n\n"
                    . "**Your primary goal is to meticulously identify specific errors and violations *strictly within this chunk*. Pay critical attention to the following categories:**\n"
                    . "  1.  **PHP Errors (Highest Priority):**\n"
                    . "      *   Syntax Errors (Parse errors, unexpected tokens, missing semicolons/brackets, etc.).\n"
                    . "      *   Runtime Errors (Calls to undefined functions/methods, incorrect arguments to built-in functions, fatal errors like `echo ();`).\n"
                    . "      *   Logical Errors visible solely within the chunk.\n"
                    . "  2.  **Security Vulnerabilities:**\n"
                    . "      *   Improper Output Escaping: Identify any PHP variables echoed or printed within HTML context without appropriate WordPress escaping functions (e.g., `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`). Flag potential Cross-Site Scripting (XSS) risks.\n"
                    . "      *   Missing Nonces/Capability Checks *if relevant actions (like form processing hints) are visible within the chunk*.\n"
                    . "  3.  **WordPress Specific Issues:**\n"
                    . "      *   Use of deprecated WordPress functions, hooks, or parameters.\n"
                    . "      *   Violations of WordPress Coding Standards (naming conventions, spacing) visible in the PHP code.\n"
                    . "      *   Incorrect usage of WordPress APIs or functions.\n"
                    . "  4.  **HTML/CSS/JS Issues (within the chunk):**\n"
                    . "      *   Malformed HTML structure (unclosed tags, incorrect nesting visible within the chunk).\n"
                    . "      *   Invalid CSS syntax found in `<style>` blocks or inline `style` attributes.\n"
                    . "      *   Obvious JavaScript syntax errors or use of undefined variables/functions *if JS code is present in the chunk*.\n\n"
                    . "**Output Requirements:**\n"
                    . "Return your findings ONLY as a single, valid JSON object. This object must have a root key named 'issues', which is an array of issue objects. If no issues are found, return `{\"issues\": []}`.\n"
                    . "Each issue object within the 'issues' array MUST contain exactly these fields in this specific order:\n" // Added order requirement
                    . "  1.  `relative_line`: (integer or string \"start-end\") - The 1-based line number(s) where the issue *starts* or occurs, RELATIVE TO THE START OF THE PROVIDED CHUNK. The first line of the chunk is line 1. Use a string like `\"3-5\"` (e.g., `\"3-5\"`) for multi-line issues.\n"
                    . "  2.  `original_code_snippet`: (string) The exact, unmodified line(s) of code from the input chunk, identified by `relative_line`, that contain the reported issue. If `relative_line` is a range, include all lines in that range, preserving original indentation and newlines.\n"
                    . "  3.  `issue`: (string) A concise, specific description of the identified problem.\n"
                    . "  4.  `severity`: (string) Classify the severity: 'Critical' (e.g., PHP syntax/fatal errors, major security flaws), 'High' (e.g., undefined functions, potential XSS), 'Medium' (e.g., deprecated functions, moderate logic issues), 'Low' (e.g., coding standards), or 'Info' (e.g., minor suggestions).\n"
                    . "  5.  `fix_suggestion`: (string) **This field MUST NOT be empty.** Provide the complete, corrected code snippet intended to replace the `original_code_snippet`. If a direct code replacement is genuinely not feasible (e.g., requires complex external context or significant logic changes), provide clear, step-by-step instructions on how to manually fix the issue OR a clear explanation of *why* a code fix cannot be provided based *only* on the chunk's content. **Prioritize providing the corrected code snippet whenever possible.**\n"
                    . "  6.  `auto_fixable`: (string) Indicate fixability based on the `fix_suggestion`: 'yes' (if a direct code replacement snippet is provided and likely safe), 'semi' (if a code snippet is provided but requires human review/context), or 'no' (if `fix_suggestion` contains instructions/explanation instead of a direct code snippet, or if the fix requires manual implementation/complex logic change).\n"
                    . "  7.  `apply_method`: (string) Specify how the fix should be applied: 'replace_lines' (if `fix_suggestion` is a code snippet meant to replace the entire `original_code_snippet`) or 'modify_lines' (if `fix_suggestion` provides instructions or describes changes *within* the existing lines, often when `auto_fixable` is 'no' or 'semi').\n\n"
                    . "**Important Constraints:**\n"
                    . "*   Focus ONLY on the provided code chunk (`\$chunkContent`). Do not infer context or definitions from outside this chunk.\n"
                    . "*   Do NOT invent issues or make assumptions. Only report concrete problems visible in the code.\n"
                    . "*   Adhere strictly to the JSON output format and the specified fields/order for each issue.\n"
                    . "*   Ensure `relative_line` correctly reflects the 1-based index within the provided chunk.\n"
                    . "*   The `fix_suggestion` field MUST always contain content, either a code fix or an explanation/instructions.\n" // Added constraint reminder
                    . "\n"
                    . "**Code Chunk to Analyze (File: '{$filePath}', Lines {$startLine}-{$endLine} absolute):**\n```{$extension}\n{$chunkContent}\n```";

    // --- END PROMPT ---

 
            // echo '<pre style="color: black;">' . htmlspecialchars($chunkPrompt) . '</pre>';
            // die();


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
                        'content' => "You are a highly precise code review AI specializing in WordPress theme development (PHP, Gutenberg blocks, HTML, JS, CSS). Analyze the provided code chunk meticulously for errors (PHP syntax/runtime, security flaws like XSS via improper escaping, WP issues) and violations. Prioritize accuracy and adherence to WordPress best practices. Respond ONLY with the specified valid JSON structure containing an 'issues' array. Use relative line numbers." // Added severity to system prompt
                    ],
                    ['role' => 'user', 'content' => $chunkPrompt]
                ],
                'temperature' => 0.1, // Slightly adjusted temperature
                'max_tokens' => 2500, // Increased max_tokens slightly
                //'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->failed()) {
                $errorBody = $response->body(); // Get the response body
                Log::error("OpenAI API request failed for chunk {$startLine}-{$endLine} of {$filePath}. Status: " . $response->status() . ". Body: " . $errorBody);
                // Consider if this should be a critical failure or just skip the chunk
                return []; // Non-critical: return empty issues for this chunk
                // return false; // Critical: fail the entire file processing
            }

            $responseData = json_decode($response->body(), true);
            $responseContent = $responseData['choices'][0]['message']['content'] ?? null;
            
            if (!$responseContent) {
                Log::error("Failed to extract content from OpenAI API response for chunk {$startLine}-{$endLine} of {$filePath}");
                return []; // Non-critical: return empty issues for this chunk
            }


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
            // Decide if connection errors should be critical
            // return false; // Critical
             return []; // Non-critical for this chunk
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
                    return "- Line(s) " . ($issue['location'] ?? 'N/A') . " (" . ($issue['severity'] ?? 'N/A') . "): " . ($issue['issue'] ?? 'N/A');
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

             // Use Storage::put for consistency
            Storage::put($suggestionFilePath, json_encode($issuesJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            Log::info("Saved suggestion file: " . $suggestionFilePath);


            // Create or Update FileSuggestion record
            // Ensure fileScan object is valid before accessing id
             if (!$fileScan || !$fileScan->id) {
                throw new Exception("Invalid FileScan object before saving suggestion.");
            }

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

            // Update FileScan status to completed *after* everything else succeeded
            $fileScan->update(['status' => 'completed']);
            $finalStatus = 'success';

        } catch (Exception $e) {
            Log::error("Error during consolidation/saving for scan ID {$fileScan->id}, File: {$filePath}: " . $e->getMessage());
            // Ensure status is failed if exception occurs here
            if ($fileScan && $fileScan->status !== 'completed') {
                 try {
                    // Double check existence before update
                    $scanToFail = FileScan::find($fileScan->id);
                    if ($scanToFail) {
                        $scanToFail->update(['status' => 'failed']);
                    }
                 } catch(Exception $dbEx) {
                     Log::error("Nested error updating FileScan status to failed for ID {$fileScan->id}: " . $dbEx->getMessage());
                 }
            }
             // Don't re-throw here, let the outer loop handle reporting
             $finalStatus = 'error'; // Ensure status reflects error
        }

        return [
            'status' => $finalStatus,
            'message' => $finalStatus === 'success' ? 'File processed successfully' : 'Processing failed during consolidation/saving.',
            'issues_count' => $issuesCount,
            'suggestion_path' => $suggestionFilePath // Return relative path
        ];
    }

    /**
     * API endpoint to retrieve the latest completed FileScan for each file_path
     * associated with a given site_url, along with its FileSuggestion.
     */
    public function getLatestScansWithSuggestions(Request $request): JsonResponse
    {
        $request->validate([
            'site_url' => 'required|url' // Validate site_url input
        ]);

        $siteUrl = $request->input('site_url');

        try {
            $latestScanIdsSubquery = FileScan::selectRaw('MAX(id) as max_id')
                ->where('site_url', $siteUrl)
                // ->where('status', 'completed') // Optional: Only fetch completed scans
                ->groupBy('file_path');

            $latestScans = FileScan::select('file_scans.*') // Explicitly select FileScan columns
                ->with(['suggestion' => function($query) {
                    $query->select('id', 'file_scan_id', 'status', 'metadata', 'ai_model') // Exclude file_path from suggestion
                          ->addSelect('suggestion'); // Add other needed columns
                }])
                ->joinSub($latestScanIdsSubquery, 'latest_scans', function ($join) {
                    $join->on('file_scans.id', '=', 'latest_scans.max_id');
                })
                ->orderBy('file_scans.scan_date', 'desc') // Order the results
                ->get();

            return response()->json([
                'status' => 'success',
                'site_url' => $siteUrl,
                'data' => $latestScans
            ]);

        } catch (Exception $e) {
            Log::error("Error retrieving latest scans for site_url {$siteUrl}: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve latest scans.',
                'error' => $e->getMessage() // Provide error details in non-production environments
            ], 500);
        }
    }


} // End of Controller Class