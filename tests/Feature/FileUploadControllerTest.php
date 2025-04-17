<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clean up storage directory before each test
        Storage::fake('local');
    }

    /**
     * Test initialization of chunk upload process
     */
    public function test_init_chunk_upload(): void
    {
        // Arrange: Prepare request data
        $fileIdentifier = 'test_' . time() . '_file';
        $requestData = [
            'file_path' => '/wp-content/themes/example/style.css',
            'file_type' => 'text/css',
            'file_size' => 150000,
            'total_chunks' => 3,
            'file_identifier' => $fileIdentifier,
            'site_url' => 'https://example.com',
            'theme' => 'example-theme',
        ];

        // Act: Make a POST request to the endpoint
        $response = $this->postJson('/api/file-upload/init-chunk', $requestData);

        // Assert: Check that the response is successful and has the expected structure
        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Chunked upload initialized',
                    'upload_id' => $fileIdentifier,
                ]);

        // Assert: Check that the cache entry was created with the expected metadata
        $cacheKey = 'chunk_upload_' . $fileIdentifier;
        $metadata = Cache::get($cacheKey);
        
        $this->assertNotNull($metadata, 'Cache entry should be created');
        $this->assertEquals($requestData['file_path'], $metadata['file_path']);
        $this->assertEquals($requestData['file_type'], $metadata['file_type']);
        $this->assertEquals($requestData['file_size'], $metadata['file_size']);
        $this->assertEquals($requestData['total_chunks'], $metadata['total_chunks']);
        $this->assertEquals(0, $metadata['received_chunks']);
        $this->assertEquals(count(array_fill(0, $requestData['total_chunks'], false)), count($metadata['chunk_status']));
        $this->assertEquals($requestData['site_url'], $metadata['site_url']);
        $this->assertEquals($requestData['theme'], $metadata['theme']);
        
        // Assert: Check that the temp directory was created
        $tempDirPath = 'temp/chunk_uploads/' . $fileIdentifier;
        Storage::assertExists($tempDirPath);
    }

    /**
     * Test validation fails with missing required fields
     */
    public function test_init_chunk_upload_validation_failure(): void
    {
        // Arrange: Prepare incomplete request data
        $requestData = [
            'file_path' => '/wp-content/themes/example/style.css',
            // Missing required fields
        ];

        // Act: Make a POST request to the endpoint
        $response = $this->postJson('/api/file-upload/init-chunk', $requestData);

        // Assert: Check that validation fails with 422 status
        $response->assertStatus(422)
                ->assertJsonValidationErrors(['file_type', 'file_size', 'total_chunks', 'file_identifier', 'site_url', 'theme']);
    }

    /**
     * Test error handling when directory creation fails
     */
    public function test_init_chunk_upload_error_handling(): void
    {
        // Mock the Storage facade to throw an exception when makeDirectory is called
        Storage::shouldReceive('exists')->andReturn(false);
        Storage::shouldReceive('makeDirectory')->once()->andThrow(new \Exception('Directory creation failed'));

        // Arrange: Prepare request data
        $fileIdentifier = 'test_' . time() . '_file';
        $requestData = [
            'file_path' => '/wp-content/themes/example/style.css',
            'file_type' => 'text/css',
            'file_size' => 150000,
            'total_chunks' => 3,
            'file_identifier' => $fileIdentifier,
            'site_url' => 'https://example.com',
            'theme' => 'example-theme',
        ];

        // Act: Make a POST request to the endpoint
        $response = $this->postJson('/api/file-upload/init-chunk', $requestData);

        // Assert: Check that the response has the expected error structure
        $response->assertStatus(500)
                ->assertJson([
                    'error' => 'Failed to initialize chunked upload'
                ]);
    }

    /**
     * Test full chunk upload workflow (init, process chunks, finalize)
     */
    public function test_full_chunk_upload_workflow(): void
    {
        // This is a more complex test that would simulate the full workflow
        // First initialize the upload
        $fileIdentifier = 'test_' . time() . '_file';
        $requestData = [
            'file_path' => '/wp-content/themes/example/style.css',
            'file_type' => 'text/css',
            'file_size' => 150000,
            'total_chunks' => 3,
            'file_identifier' => $fileIdentifier,
            'site_url' => 'https://example.com',
            'theme' => 'example-theme',
        ];

        // 1. Initialize the upload
        $initResponse = $this->postJson('/api/file-upload/init-chunk', $requestData);
        $initResponse->assertStatus(200);

        // 2. Upload each chunk
        $testContent = "This is test content for chunk ";
        
        for ($i = 0; $i < $requestData['total_chunks']; $i++) {
            $chunkContent = $testContent . $i;
            $chunkData = [
                'file_identifier' => $fileIdentifier,
                'chunk_index' => $i,
                'total_chunks' => $requestData['total_chunks'],
                'chunk_data' => base64_encode($chunkContent),
                'chunk_size' => strlen($chunkContent),
            ];

            $chunkResponse = $this->postJson('/api/file-upload/process-chunk', $chunkData);
            $chunkResponse->assertStatus(200)
                         ->assertJson([
                             'success' => true,
                             'chunk_index' => $i,
                         ]);
        }

        // 3. Finalize the upload
        $finalizeData = [
            'file_identifier' => $fileIdentifier,
            'total_chunks' => $requestData['total_chunks'],
            'uploaded_chunks' => $requestData['total_chunks'],
            'file_path' => $requestData['file_path'],
        ];

        $finalizeResponse = $this->postJson('/api/file-upload/finalize-chunk', $finalizeData);
        $finalizeResponse->assertStatus(200)
                        ->assertJson([
                            'success' => true,
                            'message' => 'File upload complete',
                        ]);

        // 4. Check that the file was stored in the expected location
        $siteFolderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', parse_url($requestData['site_url'], PHP_URL_HOST));
        
        // The path should start with 'wordpress/{siteFolderName}/' followed by a date-based name
        Storage::disk('local')->assertDirectoryExists('wordpress/' . $siteFolderName);
        
        // Also verify that the temporary directory was cleaned up
        Storage::disk('local')->assertDirectoryMissing('temp/chunk_uploads/' . $fileIdentifier);
    }

    /**
     * Test processing a single chunk
     */
    public function test_process_chunk(): void
    {
        // 1. First initialize the upload to create metadata
        $fileIdentifier = 'test_' . time() . '_chunk_test';
        $requestData = [
            'file_path' => '/wp-content/themes/example/script.js',
            'file_type' => 'application/javascript',
            'file_size' => 10000,
            'total_chunks' => 5,
            'file_identifier' => $fileIdentifier,
            'site_url' => 'https://example.com',
            'theme' => 'example-theme',
        ];

        $this->postJson('/api/file-upload/init-chunk', $requestData);

        // 2. Process a single chunk
        $chunkContent = "This is test content for a single chunk test";
        $chunkData = [
            'file_identifier' => $fileIdentifier,
            'chunk_index' => 2, // Testing with chunk index 2
            'total_chunks' => $requestData['total_chunks'],
            'chunk_data' => base64_encode($chunkContent),
            'chunk_size' => strlen($chunkContent),
        ];

        $response = $this->postJson('/api/file-upload/process-chunk', $chunkData);

        // 3. Assert the response structure
        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Chunk received successfully',
                    'chunk_index' => 2,
                    'received_chunks' => 1,
                    'remaining_chunks' => $requestData['total_chunks'] - 1,
                ]);

        // 4. Verify the metadata in the cache was updated
        $cacheKey = 'chunk_upload_' . $fileIdentifier;
        $metadata = Cache::get($cacheKey);
        
        $this->assertNotNull($metadata);
        $this->assertEquals(1, $metadata['received_chunks']);
        $this->assertTrue($metadata['chunk_status'][2]);
        $this->assertFalse($metadata['chunk_status'][0]);

        // 5. Check that the chunk file was created
        $tempDirPath = 'temp/chunk_uploads/' . $fileIdentifier;
        $chunkPath = $tempDirPath . '/chunk_2';
        Storage::assertExists($chunkPath);
    }

    /**
     * Test validation errors when processing a chunk
     */
    public function test_process_chunk_validation_failure(): void
    {
        // Send a request with missing required data
        $response = $this->postJson('/api/file-upload/process-chunk', [
            'file_identifier' => 'test_id',
            // Missing other required fields
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['chunk_index', 'total_chunks', 'chunk_data', 'chunk_size']);
    }

    /**
     * Test error handling when a chunk upload specifies an invalid upload ID
     */
    public function test_process_chunk_invalid_upload_id(): void
    {
        // Create a chunk with a non-existent file identifier
        $chunkData = [
            'file_identifier' => 'non_existent_upload_id',
            'chunk_index' => 0,
            'total_chunks' => 3,
            'chunk_data' => base64_encode('Test content'),
            'chunk_size' => 12,
        ];

        $response = $this->postJson('/api/file-upload/process-chunk', $chunkData);

        $response->assertStatus(404)
                ->assertJson([
                    'error' => 'Upload session not found or expired'
                ]);
    }

    /**
     * Test aborting a chunk upload
     */
    public function test_abort_chunk_upload(): void
    {
        // 1. First initialize the upload
        $fileIdentifier = 'test_' . time() . '_abort_test';
        $requestData = [
            'file_path' => '/wp-content/themes/example/style.css',
            'file_type' => 'text/css',
            'file_size' => 5000,
            'total_chunks' => 2,
            'file_identifier' => $fileIdentifier,
            'site_url' => 'https://example.com',
            'theme' => 'example-theme',
        ];

        $this->postJson('/api/file-upload/init-chunk', $requestData);

        // 2. Upload a chunk to create some files
        $chunkData = [
            'file_identifier' => $fileIdentifier,
            'chunk_index' => 0,
            'total_chunks' => $requestData['total_chunks'],
            'chunk_data' => base64_encode('Test content for chunk 0'),
            'chunk_size' => 22,
        ];

        $this->postJson('/api/file-upload/process-chunk', $chunkData);

        // 3. Now abort the upload
        $abortData = [
            'file_identifier' => $fileIdentifier,
            'reason' => 'Testing abort functionality',
        ];

        $response = $this->postJson('/api/file-upload/abort-chunk', $abortData);

        // 4. Assert the response
        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Upload aborted successfully',
                ]);

        // 5. Verify the cache entry was removed
        $cacheKey = 'chunk_upload_' . $fileIdentifier;
        $this->assertNull(Cache::get($cacheKey));

        // 6. Verify the temp directory was cleaned up
        $tempDirPath = 'temp/chunk_uploads/' . $fileIdentifier;
        Storage::assertDirectoryMissing($tempDirPath);
    }

    /**
     * Test finalizing a chunk upload with missing chunks
     */
    public function test_finalize_with_missing_chunks(): void
    {
        // 1. Initialize the upload
        $fileIdentifier = 'test_' . time() . '_missing_chunks';
        $requestData = [
            'file_path' => '/wp-content/themes/example/style.css',
            'file_type' => 'text/css',
            'file_size' => 10000,
            'total_chunks' => 3,
            'file_identifier' => $fileIdentifier,
            'site_url' => 'https://example.com',
            'theme' => 'example-theme',
        ];

        $this->postJson('/api/file-upload/init-chunk', $requestData);

        // 2. Upload only one chunk
        $chunkData = [
            'file_identifier' => $fileIdentifier,
            'chunk_index' => 0,
            'total_chunks' => $requestData['total_chunks'],
            'chunk_data' => base64_encode('Test content for a partial upload'),
            'chunk_size' => 32,
        ];

        $this->postJson('/api/file-upload/process-chunk', $chunkData);

        // 3. Try to finalize with missing chunks
        $finalizeData = [
            'file_identifier' => $fileIdentifier,
            'total_chunks' => $requestData['total_chunks'],
            'uploaded_chunks' => $requestData['total_chunks'], // Claiming all chunks are uploaded
            'file_path' => $requestData['file_path'],
        ];

        $response = $this->postJson('/api/file-upload/finalize-chunk', $finalizeData);

        // 4. Assert that it fails due to missing chunks
        $response->assertStatus(400)
                ->assertJson([
                    'error' => 'Not all chunks received'
                ]);
    }
}
