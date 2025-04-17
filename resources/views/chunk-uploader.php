<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fixonaut Chunk Uploader Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
        }
        h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 30px;
        }
        .container {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #34495e;
        }
        input[type="text"], input[type="file"], input[type="url"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        input[type="file"] {
            padding: 8px;
        }
        button {
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 12px 20px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        button:hover {
            background-color: #2980b9;
        }
        button:disabled {
            background-color: #95a5a6;
            cursor: not-allowed;
        }
        .progress-container {
            height: 25px;
            background-color: #ecf0f1;
            border-radius: 4px;
            margin-top: 10px;
            position: relative;
            display: none;
        }
        .progress-bar {
            height: 100%;
            background-color: #2ecc71;
            border-radius: 4px;
            width: 0%;
            transition: width 0.3s ease;
        }
        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #333;
            font-weight: bold;
        }
        #status {
            margin-top: 20px;
            padding: 10px;
            border-radius: 4px;
            display: none;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        #logContainer {
            margin-top: 20px;
            border: 1px solid #ddd;
            padding: 10px;
            height: 150px;
            overflow-y: auto;
            background-color: #f8f9fa;
            border-radius: 4px;
            font-family: monospace;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Fixonaut Chunk Uploader Test</h1>
        
        <div class="form-group">
            <label for="file">Select File to Upload:</label>
            <input type="file" id="file" name="file">
        </div>
        
        <div class="form-group">
            <label for="siteUrl">Site URL:</label>
            <input type="url" id="siteUrl" name="site_url" placeholder="https://example.com" value="https://example.com">
        </div>
        
        <div class="form-group">
            <label for="filePath">File Path:</label>
            <input type="text" id="filePath" name="file_path" placeholder="/wp-content/themes/example/style.css" value="/wp-content/themes/example/style.css">
        </div>
        
        <div class="form-group">
            <label for="theme">Theme Name:</label>
            <input type="text" id="theme" name="theme" placeholder="example-theme" value="example-theme">
        </div>
        
        <div class="form-group">
            <label for="chunkSize">Chunk Size (bytes):</label>
            <input type="number" id="chunkSize" name="chunk_size" value="1048576" min="1024">
            <small>(Default: 1MB = 1048576 bytes)</small>
        </div>
        
        <button id="uploadBtn">Start Upload</button>
        <button id="abortBtn" disabled>Abort Upload</button>
        
        <div class="progress-container" id="progressContainer">
            <div class="progress-bar" id="progressBar"></div>
            <div class="progress-text" id="progressText">0%</div>
        </div>
        
        <div id="status"></div>
        
        <h3>Upload Log:</h3>
        <div id="logContainer"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // DOM Elements
            const fileInput = document.getElementById('file');
            const siteUrlInput = document.getElementById('siteUrl');
            const filePathInput = document.getElementById('filePath');
            const themeInput = document.getElementById('theme');
            const chunkSizeInput = document.getElementById('chunkSize');
            const uploadBtn = document.getElementById('uploadBtn');
            const abortBtn = document.getElementById('abortBtn');
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const statusDiv = document.getElementById('status');
            const logContainer = document.getElementById('logContainer');
            
            // Variables for upload state
            let fileIdentifier = null;
            let isUploading = false;
            let abortUpload = false;
            let totalChunks = 0;
            let processedChunks = 0;
            // API endpoint base URL
            const apiBaseUrl = 'http://localhost:8000';
            
            // Add log entry
            function log(message, type = 'info') {
                const entry = document.createElement('div');
                entry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
                entry.className = type;
                logContainer.appendChild(entry);
                logContainer.scrollTop = logContainer.scrollHeight;
            }
            
            // Show status message
            function showStatus(message, type) {
                statusDiv.textContent = message;
                statusDiv.className = type;
                statusDiv.style.display = 'block';
            }
            
            // Reset UI
            function resetUI() {
                progressContainer.style.display = 'none';
                progressBar.style.width = '0%';
                progressText.textContent = '0%';
                uploadBtn.disabled = false;
                abortBtn.disabled = true;
                isUploading = false;
                fileIdentifier = null;
                totalChunks = 0;
                processedChunks = 0;
            }
            
            // Initialize upload
            async function initializeUpload(file) {
                try {
                    const fileType = file.type || 'application/octet-stream';
                    const fileSize = file.size;
                    
                    // Generate a unique file identifier
                    fileIdentifier = 'upload_' + Date.now() + '_' + Math.floor(Math.random() * 1000000);
                    
                    // Calculate number of chunks
                    const chunkSize = parseInt(chunkSizeInput.value, 10);
                    totalChunks = Math.ceil(fileSize / chunkSize);
                    
                    log(`Initializing upload: ${file.name} (${fileSize} bytes, ${totalChunks} chunks)`);
                    
                    const initData = {
                        file_path: filePathInput.value,
                        file_type: fileType,
                        file_size: fileSize,
                        total_chunks: totalChunks,
                        file_identifier: fileIdentifier,
                        site_url: siteUrlInput.value,
                        theme: themeInput.value
                    };
                    
                    // Use the full URL to the API endpoint
                    const apiBaseUrl = 'http://localhost:8000';
                    const response = await fetch(`${apiBaseUrl}/api/files/upload/init`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(initData)
                    });
                    
                    const result = await response.json();
                    
                    if (!response.ok || !result.success) {
                        throw new Error(result.error || 'Failed to initialize upload');
                    }
                    
                    log(`Upload initialized with ID: ${result.upload_id}`);
                    return true;
                } catch (error) {
                    log(`Initialization error: ${error.message}`, 'error');
                    showStatus(`Error initializing upload: ${error.message}`, 'error');
                    resetUI();
                    return false;
                }
            }                // Process chunks
            async function processChunks(file) {
                try {
                    const chunkSize = parseInt(chunkSizeInput.value, 10);
                    const totalSize = file.size;
                    processedChunks = 0;
                    
                    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                        if (abortUpload) {
                            log('Upload aborted by user');
                            return false;
                        }
                        
                        const start = chunkIndex * chunkSize;
                        const end = Math.min(start + chunkSize, totalSize);
                        const chunk = file.slice(start, end);
                        
                        // Read chunk as base64
                        const base64Chunk = await readFileAsBase64(chunk);
                        
                        // Prepare chunk data
                        const chunkData = {
                            file_identifier: fileIdentifier,
                            chunk_index: chunkIndex,
                            total_chunks: totalChunks,
                            chunk_data: base64Chunk,
                            chunk_size: chunk.size
                        };
                        
                        log(`Uploading chunk ${chunkIndex + 1}/${totalChunks} (${chunk.size} bytes)`);
                        
                        const response = await fetch(`${apiBaseUrl}/api/files/upload/chunk`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify(chunkData)
                        });
                        
                        // Check if response is OK before trying to parse JSON
                        if (!response.ok) {
                            throw new Error(`Server returned ${response.status}: ${response.statusText}`);
                        }
                        
                        // Check content type to make sure we're getting JSON back
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            throw new Error(`Expected JSON response but got ${contentType || 'unknown type'}`);
                        }
                        
                        const result = await response.json();
                        
                        if (!response.ok || !result.success) {
                            throw new Error(result.error || `Failed to upload chunk ${chunkIndex}`);
                        }
                        
                        processedChunks++;
                        updateProgress();
                    }
                    
                    return true;
                } catch (error) {
                    log(`Chunk processing error: ${error.message}`, 'error');
                    showStatus(`Error uploading chunks: ${error.message}`, 'error');
                    return false;
                }
            }
            
            // Finalize upload
            async function finalizeUpload() {
                try {
                    log('Finalizing upload...');
                    
                    const finalizeData = {
                        file_identifier: fileIdentifier,
                        total_chunks: totalChunks,
                        uploaded_chunks: processedChunks,
                        file_path: filePathInput.value
                    };
                    
                    const response = await fetch(`${apiBaseUrl}/api/files/upload/finalize`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify(finalizeData)
                    });
                    
                    // Check if response is OK before trying to parse JSON
                    if (!response.ok) {
                        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
                    }
                    
                    // Check content type to ensure we're getting JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error(`Expected JSON response but got ${contentType || 'unknown type'}`);
                    }
                    
                    const result = await response.json();
                    
                    if (!response.ok || !result.success) {
                        throw new Error(result.error || 'Failed to finalize upload');
                    }
                    
                    log(`Upload completed. Scan ID: ${result.scan_id}`);
                    showStatus('File upload completed successfully!', 'success');
                    return true;
                } catch (error) {
                    log(`Finalization error: ${error.message}`, 'error');
                    showStatus(`Error finalizing upload: ${error.message}`, 'error');
                    return false;
                }
            }
            
            // Abort upload
            async function abortUploadRequest() {
                if (!fileIdentifier) return;
                
                try {
                    log('Sending abort request...');
                    
                    const abortData = {
                        file_identifier: fileIdentifier,
                        reason: 'User requested abort'
                    };
                    
                    const response = await fetch(`${apiBaseUrl}/api/files/upload/abort`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify(abortData)
                    });
                    
                    const result = await response.json();
                    
                    if (!response.ok) {
                        throw new Error(result.error || 'Failed to abort upload');
                    }
                    
                    log('Upload aborted successfully');
                    showStatus('Upload aborted', 'info');
                    return true;
                } catch (error) {
                    log(`Abort error: ${error.message}`, 'error');
                    return false;
                } finally {
                    resetUI();
                }
            }
            
            // Helper to convert file chunk to base64
            function readFileAsBase64(blob) {
                return new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onload = () => {
                        // Remove data URL prefix (e.g., "data:application/octet-stream;base64,")
                        const base64String = reader.result.split(',')[1];
                        resolve(base64String);
                    };
                    reader.onerror = () => reject(new Error('Failed to read file'));
                    reader.readAsDataURL(blob);
                });
            }
            
            // Update progress bar
            function updateProgress() {
                const percent = Math.round((processedChunks / totalChunks) * 100);
                progressBar.style.width = `${percent}%`;
                progressText.textContent = `${percent}% (${processedChunks}/${totalChunks} chunks)`;
            }
            
            // Main upload process
            async function startUpload() {
                const file = fileInput.files[0];
                if (!file) {
                    showStatus('Please select a file to upload', 'error');
                    return;
                }
                
                if (!siteUrlInput.value || !filePathInput.value || !themeInput.value) {
                    showStatus('Please fill in all required fields', 'error');
                    return;
                }
                
                // Reset state
                abortUpload = false;
                isUploading = true;
                statusDiv.style.display = 'none';
                progressContainer.style.display = 'block';
                uploadBtn.disabled = true;
                abortBtn.disabled = false;
                
                // Initialize upload
                const initialized = await initializeUpload(file);
                if (!initialized) return;
                
                // Process chunks
                const processed = await processChunks(file);
                if (!processed) {
                    if (!abortUpload) {
                        await abortUploadRequest();
                    }
                    return;
                }
                
                // Finalize upload
                const finalized = await finalizeUpload();
                if (!finalized) {
                    await abortUploadRequest();
                }
                
                resetUI();
            }
            
            // Event Listeners
            uploadBtn.addEventListener('click', startUpload);
            
            abortBtn.addEventListener('click', function() {
                abortUpload = true;
                abortBtn.disabled = true;
                abortUploadRequest();
            });
            
            // Reset UI on file change
            fileInput.addEventListener('change', function() {
                statusDiv.style.display = 'none';
                logContainer.innerHTML = '';
                if (fileInput.files[0]) {
                    log(`File selected: ${fileInput.files[0].name} (${fileInput.files[0].size} bytes)`);
                }
            });
        });
    </script>
</body>
</html>
