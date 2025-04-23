<?php

namespace App\Http\Controllers;

use App\Models\FileScan;
use App\Models\FileSuggestion;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class OpenAITestController extends Controller
{
    protected $openAIService;
    protected $apiKey;
    protected $model;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->openAIService = new OpenAIService();
        $this->apiKey = config('services.openai.api_key');
        $this->model = config('services.openai.model', 'gpt-4');
    }
    
    /**
     * Display the main test interface
     */
    public function index()
    {
        // Get a list of files in the WordPress directory for testing
        $wordpressFiles = [];
        $wordpressDir = 'private/wordpress';
        
        if (Storage::exists($wordpressDir)) {
            $wordpressFiles = $this->getAllFiles($wordpressDir);
        }
        
        return view('openai-test.index', [
            'wordpressFiles' => $wordpressFiles,
            'apiKey' => $this->apiKey ? substr($this->apiKey, 0, 4) . '...' . substr($this->apiKey, -4) : 'Not configured',
            'model' => $this->model
        ]);
    }

    /**
     * Process all files in WordPress directory structure
     */
    public function processWordPressFiles(Request $request)
    {
        $wordpressDir = 'wordpress';
        $results = [
            'directories' => [],
            'processed_files' => [],
            'errors' => []
        ];
        
        try {

            // Check if the main directory exists
            if (!Storage::exists($wordpressDir)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'WordPress directory does not exist: ' . $wordpressDir
                ], 404);
            }
            
            // Get all directories including the root directory
            $directories = [$wordpressDir];
            $subdirectories = Storage::allDirectories($wordpressDir);
            $directories = array_merge($directories, $subdirectories);
            $results['directories'] = $directories;
            
            // Process each directory
            foreach ($directories as $directory) {
                Log::info("Processing directory: {$directory}");
                
                // Get all files in the directory and subdirectories (recursively)
                $files = Storage::allFiles($directory);

                // Process each file in this directory
                foreach ($files as $filePath) {
                    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                    
                    // Skip files with unsupported extensions
                    if (!in_array(strtolower($extension), ['php', 'js', 'css', 'html', 'twig', 'jsx', 'ts', 'tsx', 'scss', 'less'])) {
                        continue;
                    }
                    
                    try {

                        // Read file content
                        $fileContent = Storage::get($filePath);
                        $fileSize = strlen($fileContent);
                        
                        $fileScan = FileScan::create([
                            'site_url' => 'https://test.example.com',
                            'theme' => basename($directory),
                            'file_path' => $filePath,
                            'file_type' => strtolower($extension),
                            'file_size' => $fileSize,
                            'scan_date' => now(),
                            'status' => 'pending'
                        ]);

                        // Process file using OpenAIService with chunking
                        $fileContent = Storage::get($filePath);

                        // Define chunk size (adjust based on token limits)
                        $chunkSize = 2000;
                        $chunks = $this->chunkCode($fileContent, $chunkSize);
                        $chunkAnalyses = [];
                        
                        // Define our initial JSON structure for suggestions
                        $issuesJson = [
                            'id' => basename($filePath, '.' . $extension) . '-analysis',
                            'file' => basename($filePath),
                            'issues' => [],
                            'documentation' => [
                                'issue_details' => '',
                                'fix_explanation' => ''
                            ]
                        ];

                        // Process each chunk
                        foreach ($chunks as $index => $chunk) {
                            $totalChunks = count($chunks);
                            $currentChunkNumber = $index + 1;
                            $chunkPrompt = "Analyze this {$fileScan->file_type} code chunk ({$currentChunkNumber}/{$totalChunks}). Identify ONLY specific issues present in this chunk. Return valid JSON with an 'issues' array that includes:\n"
                                . "1. location: {line_number} or {start_line}-{end_line} with exact code snippet from this chunk\n"
                                . "2. issue: concise description of the actual problem in the code\n"
                                . "3. fix_suggestion: complete replacement code that resolves the issue\n" 
                                . "4. auto_fixable: 'yes' (direct replacement), 'semi' (needs review), or 'no' (manual fix required)\n"
                                . "5. apply_method: 'replace_lines' or 'modify_lines' with clear boundaries\n\n"
                                . "Important: Do NOT invent issues. Only flag actual problems. Do NOT assume methods are missing if they might be defined elsewhere in the project. If no issues found, return {\"issues\": []}.\n\n"
                                . "Here's the code to analyze:\n```{$fileScan->file_type}\n{$chunk}\n```";
                            
                            $chunkResponse = Http::withHeaders([
                                'Authorization' => 'Bearer ' . $this->apiKey,
                                'Content-Type' => 'application/json',
                            ])->post('https://api.openai.com/v1/chat/completions', [
                                'model' => $this->model,
                                'messages' => [
                                    [
                                        'role' => 'system',
                                        'content' => "You are a code review expert specializing in {$fileScan->file_type}. Provide clear and specific feedback in valid JSON format only. Each issue must include location, issue description, fix_suggestion, auto_fixable status, and apply_method."
                                    ],
                                    ['role' => 'user', 'content' => $chunkPrompt]
                                ],
                                'temperature' => 0.3,
                                'max_tokens' => 1000,
                            ]);
                            
                            if ($chunkResponse->successful()) {
                                $responseContent = $chunkResponse->json()['choices'][0]['message']['content'];
                                
                                // Extract JSON from response (in case it's wrapped in markdown code blocks)
                                if (preg_match('/```(?:json)?(.*?)```/s', $responseContent, $matches)) {
                                    $jsonContent = trim($matches[1]);
                                } else {
                                    $jsonContent = trim($responseContent);
                                }
                                
                                // Parse and validate JSON
                                try {
                                    $jsonData = json_decode($jsonContent, true);
                                    if (json_last_error() === JSON_ERROR_NONE && isset($jsonData['issues'])) {
                                        // Valid JSON with issues array
                                        $chunkAnalyses[] = $jsonData;
                                        
                                        // Add issues to our main JSON structure
                                        foreach ($jsonData['issues'] as $issue) {
                                            $issuesJson['issues'][] = $issue;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    Log::error("Error parsing JSON from chunk {$index}: " . $e->getMessage());
                                }
                            }
                        }

                        // Global review to consolidate all issues
                        if (!empty($chunkAnalyses)) {
                            $globalPrompt = "You've analyzed multiple chunks of a {$fileScan->file_type} file and identified various issues. "
                                . "Create a comprehensive summary for the documentation section of our analysis JSON. "
                                . "The summary should include:\n"
                                . "1. A general overview of issues found (issue_details)\n"
                                . "2. General explanation of the fix approach (fix_explanation)\n"
                                . "Please format your response as valid JSON with these two fields only.";
                            
                            $globalResponse = Http::withHeaders([
                                'Authorization' => 'Bearer ' . $this->apiKey,
                                'Content-Type' => 'application/json',
                            ])->post('https://api.openai.com/v1/chat/completions', [
                                'model' => $this->model,
                                'messages' => [
                                    [
                                        'role' => 'system',
                                        'content' => "You are a senior code reviewer providing holistic feedback on entire files."
                                    ],
                                    ['role' => 'user', 'content' => $globalPrompt]
                                ],
                                'temperature' => 0.3,
                                'max_tokens' => 1000,
                            ]); 
                            
                            if ($globalResponse->successful()) {
                                $globalContent = $globalResponse->json()['choices'][0]['message']['content'];
                                
                                // Extract JSON from response
                                if (preg_match('/```(?:json)?(.*?)```/s', $globalContent, $matches)) {
                                    $jsonContent = trim($matches[1]);
                                } else {
                                    $jsonContent = trim($globalContent);
                                }
                                
                                // Parse and validate JSON
                                try {
                                    $docData = json_decode($jsonContent, true);
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        // Add documentation to our main JSON structure
                                        if (isset($docData['issue_details'])) {
                                            $issuesJson['documentation']['issue_details'] = $docData['issue_details'];
                                        }
                                        if (isset($docData['fix_explanation'])) {
                                            $issuesJson['documentation']['fix_explanation'] = $docData['fix_explanation'];
                                        }
                                    }
                                } catch (\Exception $e) {
                                    Log::error("Error parsing documentation JSON: " . $e->getMessage());
                                }
                                
                                // Save the JSON suggestion
                                $filePathInfo = pathinfo($filePath);
                                $suggestionsDir = $filePathInfo['dirname'] . '/suggestions';
                              
                                if (!Storage::exists($suggestionsDir)) {
                                    Storage::makeDirectory($suggestionsDir);
                                }
                                
                                $suggestionFilePath = $suggestionsDir . '/' . $filePathInfo['filename'] . '_suggestions.json';
                                Storage::put($suggestionFilePath, json_encode($issuesJson, JSON_PRETTY_PRINT));
                               
                                // Create a FileSuggestion record
                                try {
                                    $result = FileSuggestion::create([
                                        'file_scan_id' => $fileScan->id,
                                        'file_path' => $filePath,
                                        'suggestion' => json_encode($issuesJson),
                                        'status' => 'processed',
                                        'ai_model' => $this->model,
                                        'metadata' => json_encode([
                                            'suggestion_file_path' => $suggestionFilePath,
                                            'issues_count' => count($issuesJson['issues'])
                                        ])
                                    ]);
                                    
                                    if (!$result) {
                                        Log::error("Failed to create FileSuggestion record for file: {$filePath}");
                                    }
                                } catch (\Exception $e) {
                                    Log::error("Exception creating FileSuggestion: " . $e->getMessage());
                                }
                        
                                $processResult = [
                                    'status' => 'success',
                                    'message' => 'File processed successfully',
                                    'issues_count' => count($issuesJson['issues'])
                                ];
                                
                                // Update file scan status
                                $fileScan->update(['status' => 'completed']);
                            } else {
                                $processResult = [
                                    'status' => 'error',
                                    'message' => 'Failed to generate global analysis: ' . $globalResponse->json()['error']['message'] ?? 'Unknown error'
                                ];
                                $fileScan->update(['status' => 'failed']);
                            }
                        } else {
                            $processResult = [
                                'status' => 'error',
                                'message' => 'Failed to analyze any chunks of the file'
                            ];
                            $fileScan->update(['status' => 'failed']);
                        }

                        $results['processed_files'][] = [
                            'path' => $filePath,
                            'status' => $processResult['status'],
                            'issues_count' => $processResult['issues_count'] ?? 0
                        ];
                        
                    } catch (Exception $e) {
                        Log::error("Error processing file {$filePath}: " . $e->getMessage());
                        $results['errors'][] = [
                            'path' => $filePath,
                            'error' => $e->getMessage()
                        ];
                    }
                }
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'All WordPress files processed',
                'directories_count' => count($results['directories']),
                'processed_files_count' => count($results['processed_files']),
                'errors_count' => count($results['errors']),
                'results' => $results
            ]);
            
        } catch (Exception $e) {
            Log::error("Error processing WordPress files: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'partial_results' => $results
            ], 500);
        }
    }

    
    protected function getAllFiles($directory)
    {
        $files = [];
        $allFiles = Storage::allFiles($directory);
        
        foreach ($allFiles as $file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            if (in_array(strtolower($extension), ['php', 'js', 'css', 'html', 'twig', 'jsx', 'ts', 'tsx', 'scss', 'less'])) {
                $files[] = $file;
            }
        }
        
        return $files;
    }
    
    /**
     * Test the OpenAI API directly with sample code
     */
    public function testOpenAIAPI(Request $request)
    {
        try {
            $validFileTypes = ['php', 'js', 'css', 'html', 'twig', 'jsx', 'ts', 'tsx', 'scss', 'less'];
            $fileType = $request->input('file_type', 'php');
            $fileType = in_array($fileType, $validFileTypes) ? $fileType : 'php';
            
            $sampleCode = $request->input('sample_code', '<?php echo "Hello, World!"; ?>');
            
            // Call OpenAI API directly
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
                        'content' => $this->preparePrompt($sampleCode, $fileType)
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 1000,
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                return response()->json([
                    'status' => 'success',
                    'message' => 'API call successful',
                    'data' => $data['choices'][0]['message']['content']
                ]);
            } else {
                $error = $response->json();
                $errorMessage = $error['error']['message'] ?? 'Unknown API error';
                Log::error('OpenAI API test error: ' . $errorMessage);
                return response()->json([
                    'status' => 'error',
                    'message' => $errorMessage
                ], 500);
            }
        } catch (Exception $e) {
            Log::error('OpenAI API test exception: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Test processing a specific file
     */
    public function testProcessFile(Request $request)
    {
        try {
            $filePath = $request->input('file_path');
            
            if (empty($filePath) || !Storage::exists($filePath)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'File does not exist: ' . $filePath
                ], 404);
            }
            
            // Create a FileScan record for testing
            $fileInfo = pathinfo($filePath);
            $extension = strtolower($fileInfo['extension'] ?? '');
            
            $fileScan = FileScan::create([
                'site_url' => 'https://test.example.com',
                'theme' => 'test-theme',
                'file_path' => $filePath,
                'file_type' => $extension,
                'scan_date' => now(),
                'status' => 'pending'
            ]);
            
            // Process file using the OpenAIService
            $result = $this->openAIService->processFile($fileScan);
            
            return response()->json([
                'status' => $result['status'],
                'message' => $result['message'] ?? '',
                'suggestions_count' => isset($result['suggestions']) ? count($result['suggestions']) : 0,
                'suggestions' => isset($result['suggestions']) ? $this->formatSuggestions($result['suggestions']) : []
            ]);
        } catch (Exception $e) {
            Log::error('File processing test error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Test the WordPress directory processing
     */
    public function testProcessDirectory(Request $request)
    {
        try {
            $directory = $request->input('directory', 'private/wordpress');
            
            if (!Storage::exists($directory)) {
                Storage::makeDirectory($directory);
                Log::info("Created directory for testing: {$directory}");
            }
            
            // Process files in directory
            $result = $this->openAIService->processWordPressDirectory($directory);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Directory processed',
                'processed' => $result['processed'],
                'failed' => $result['failed'],
                'skipped' => $result['skipped'],
                'suggestions_count' => count($result['suggestions']),
                'suggestions' => $this->formatSuggestions($result['suggestions'])
            ]);
        } catch (Exception $e) {
            Log::error('Directory processing test error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create and upload a test file for WordPress processing
     */
    public function createTestFile(Request $request)
    {
        try {
            $fileName = $request->input('file_name', 'test-file.php');
            $fileContent = $request->input('file_content', '<?php echo "This is a test file"; ?>');
            $directory = $request->input('directory', 'private/wordpress/test-site');
            
            // Create directory if it doesn't exist
            if (!Storage::exists($directory)) {
                Storage::makeDirectory($directory);
            }
            
            $filePath = $directory . '/' . $fileName;
            
            // Save the file
            Storage::put($filePath, $fileContent);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Test file created',
                'file_path' => $filePath
            ]);
        } catch (Exception $e) {
            Log::error('Test file creation error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Test token estimation
     */
    public function testTokenEstimation(Request $request)
    {
        $content = $request->input('content', '');
        $tokenCount = $this->estimateTokenCount($content);
        
        return response()->json([
            'status' => 'success',
            'token_count' => $tokenCount,
            'character_count' => strlen($content)
        ]);
    }
    
    /**
     * Get FileScan and FileSuggestion records for review
     */
    public function getProcessingHistory()
    {
        $fileScans = FileScan::with('fileSuggestions')
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();
        
        return response()->json([
            'status' => 'success',
            'file_scans' => $fileScans
        ]);
    }
    
    /**
     * Helper function to estimate token count (duplicated from OpenAIService)
     */
    protected function estimateTokenCount($content)
    {
        // A rough approximation: 1 token = ~4 characters
        return (int)ceil(strlen($content) / 4);
    }
    
    /**
     * Helper function to prepare prompt (duplicated from OpenAIService)
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
     * Split file content into chunks of specified size
     *
     * @param string $content The file content to chunk
     * @param int $chunkSize The approximate size of each chunk
     * @return array An array of content chunks
     */
    protected function chunkCode($content, $chunkSize)
    {
        // If content is small enough, return as a single chunk
        if (strlen($content) <= $chunkSize) {
            return [$content];
        }
        
        // Split by newlines
        $lines = explode("\n", $content);
        
        $chunks = [];
        $currentChunk = "";
        $currentSize = 0;
        
        foreach ($lines as $line) {
            $lineSize = strlen($line);
            
            // If adding this line would exceed chunk size, start a new chunk
            if ($currentSize + $lineSize > $chunkSize) {
                if (!empty($currentChunk)) {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = $line;
                $currentSize = $lineSize;
            } else {
                $currentChunk .= (!empty($currentChunk) ? "\n" : "") . $line;
                $currentSize += $lineSize;
            }
        }
        
        // Add the last chunk if not empty
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }
        
        return $chunks;
    }
    
    /**
     * Format suggestions for JSON response
     */
    protected function formatSuggestions($suggestions)
    {
        $formatted = [];
        
        foreach ($suggestions as $suggestion) {
            $formatted[] = [
                'id' => $suggestion->id,
                'file_path' => $suggestion->file_path,
                'status' => $suggestion->status,
                'ai_model' => $suggestion->ai_model,
                'suggestion' => $suggestion->suggestion,
                'created_at' => $suggestion->created_at->format('Y-m-d H:i:s')
            ];
        }
        
        return $formatted;
    }
}
