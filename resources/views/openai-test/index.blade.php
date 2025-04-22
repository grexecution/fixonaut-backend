<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenAI API Test Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            padding-top: 2rem;
            padding-bottom: 2rem;
        }
        .tab-content {
            padding: 20px;
            border: 1px solid #dee2e6;
            border-top: 0;
            border-radius: 0 0 0.25rem 0.25rem;
        }
        .code-area {
            font-family: monospace;
            min-height: 200px;
        }
        .result-container {
            min-height: 200px;
            max-height: 600px;
            overflow: auto;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }
        .loader {
            display: none;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 2s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>OpenAI API Test Tool</h1>
        <p class="lead">Test the OpenAI integration for code analysis functionalities</p>
        
        <div class="alert alert-info">
            <strong>Configuration</strong><br>
            API Key: {{ $apiKey }}<br>
            Model: {{ $model }}
        </div>
        
        <ul class="nav nav-tabs" id="testTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="api-test-tab" data-bs-toggle="tab" data-bs-target="#api-test" type="button" role="tab" aria-controls="api-test" aria-selected="true">API Test</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="file-test-tab" data-bs-toggle="tab" data-bs-target="#file-test" type="button" role="tab" aria-controls="file-test" aria-selected="false">Process File</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="directory-test-tab" data-bs-toggle="tab" data-bs-target="#directory-test" type="button" role="tab" aria-controls="directory-test" aria-selected="false">Process Directory</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="create-file-tab" data-bs-toggle="tab" data-bs-target="#create-file" type="button" role="tab" aria-controls="create-file" aria-selected="false">Create Test File</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="token-test-tab" data-bs-toggle="tab" data-bs-target="#token-test" type="button" role="tab" aria-controls="token-test" aria-selected="false">Token Estimation</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab" aria-controls="history" aria-selected="false">Processing History</button>
            </li>
        </ul>
        
        <div class="tab-content" id="testTabsContent">
            <!-- API Test Tab -->
            <div class="tab-pane fade show active" id="api-test" role="tabpanel" aria-labelledby="api-test-tab">
                <h3>Test OpenAI API Directly</h3>
                <form id="api-test-form">
                    <div class="mb-3">
                        <label for="file-type" class="form-label">File Type</label>
                        <select class="form-select" id="file-type" name="file_type">
                            <option value="php">PHP</option>
                            <option value="js">JavaScript</option>
                            <option value="css">CSS</option>
                            <option value="html">HTML</option>
                            <option value="twig">Twig</option>
                            <option value="jsx">JSX</option>
                            <option value="ts">TypeScript</option>
                            <option value="tsx">TSX</option>
                            <option value="scss">SCSS</option>
                            <option value="less">LESS</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="sample-code" class="form-label">Sample Code</label>
                        <textarea class="form-control code-area" id="sample-code" name="sample_code" rows="10"><?php echo "Hello, World!"; ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Test API</button>
                </form>
                <div class="loader" id="api-test-loader"></div>
                <div class="mt-4">
                    <h4>Result:</h4>
                    <div class="result-container" id="api-test-result">No results yet</div>
                </div>
            </div>
            
            <!-- Process File Tab -->
            <div class="tab-pane fade" id="file-test" role="tabpanel" aria-labelledby="file-test-tab">
                <h3>Process Specific File</h3>
                <form id="file-test-form">
                    <div class="mb-3">
                        <label for="file-path" class="form-label">File Path</label>
                        <select class="form-select" id="file-path" name="file_path">
                            <option value="">Select a file...</option>
                            @foreach($wordpressFiles as $file)
                                <option value="{{ $file }}">{{ $file }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Process File</button>
                </form>
                <div class="loader" id="file-test-loader"></div>
                <div class="mt-4">
                    <h4>Result:</h4>
                    <div class="result-container" id="file-test-result">No results yet</div>
                </div>
            </div>
            
            <!-- Process Directory Tab -->
            <div class="tab-pane fade" id="directory-test" role="tabpanel" aria-labelledby="directory-test-tab">
                <h3>Process WordPress Directory</h3>
                <form id="directory-test-form">
                    <div class="mb-3">
                        <label for="directory" class="form-label">Directory Path</label>
                        <input type="text" class="form-control" id="directory" name="directory" value="private/wordpress">
                    </div>
                    <button type="submit" class="btn btn-primary">Process Directory</button>
                </form>
                <div class="loader" id="directory-test-loader"></div>
                <div class="mt-4">
                    <h4>Result:</h4>
                    <div class="result-container" id="directory-test-result">No results yet</div>
                </div>
            </div>
            
            <!-- Create Test File Tab -->
            <div class="tab-pane fade" id="create-file" role="tabpanel" aria-labelledby="create-file-tab">
                <h3>Create Test File</h3>
                <form id="create-file-form">
                    <div class="mb-3">
                        <label for="directory-path" class="form-label">Directory Path</label>
                        <input type="text" class="form-control" id="directory-path" name="directory" value="private/wordpress/test-site">
                    </div>
                    <div class="mb-3">
                        <label for="file-name" class="form-label">File Name</label>
                        <input type="text" class="form-control" id="file-name" name="file_name" value="test-file.php">
                    </div>
                    <div class="mb-3">
                        <label for="file-content" class="form-label">File Content</label>
                        <textarea class="form-control code-area" id="file-content" name="file_content" rows="10"><?php
/**
 * Test file for OpenAI analysis
 * 
 * This file contains code with potential issues for testing
 */

// Insecure example
$user_input = $_GET['user_input'] ?? '';
echo "User input: " . $user_input;

// Inefficient database query example
function get_all_users() {
    global $db;
    $result = $db->query("SELECT * FROM users");
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Function with unused parameter
function calculate_total($items, $tax_rate, $discount = 0) {
    $total = 0;
    foreach ($items as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    return $total;
}
?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Create File</button>
                </form>
                <div class="loader" id="create-file-loader"></div>
                <div class="mt-4">
                    <h4>Result:</h4>
                    <div class="result-container" id="create-file-result">No results yet</div>
                </div>
            </div>
            
            <!-- Token Estimation Tab -->
            <div class="tab-pane fade" id="token-test" role="tabpanel" aria-labelledby="token-test-tab">
                <h3>Token Estimation</h3>
                <form id="token-test-form">
                    <div class="mb-3">
                        <label for="token-content" class="form-label">Content</label>
                        <textarea class="form-control code-area" id="token-content" name="content" rows="10">Test content for token estimation. Type or paste your content here.</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Estimate Tokens</button>
                </form>
                <div class="loader" id="token-test-loader"></div>
                <div class="mt-4">
                    <h4>Result:</h4>
                    <div class="result-container" id="token-test-result">No results yet</div>
                </div>
            </div>
            
            <!-- Processing History Tab -->
            <div class="tab-pane fade" id="history" role="tabpanel" aria-labelledby="history-tab">
                <h3>Processing History</h3>
                <button id="load-history" class="btn btn-primary mb-3">Load Processing History</button>
                <div class="loader" id="history-loader"></div>
                <div class="result-container" id="history-result">Click "Load Processing History" to view recent processing records</div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // API Test Form
            document.getElementById('api-test-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const loader = document.getElementById('api-test-loader');
                const resultContainer = document.getElementById('api-test-result');
                
                loader.style.display = 'block';
                resultContainer.textContent = 'Processing...';
                
                fetch('/openai-test/api', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    loader.style.display = 'none';
                    if (data.status === 'success') {
                        resultContainer.innerHTML = '<pre>' + data.data + '</pre>';
                    } else {
                        resultContainer.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    loader.style.display = 'none';
                    resultContainer.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
                });
            });
            
            // File Test Form
            document.getElementById('file-test-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const loader = document.getElementById('file-test-loader');
                const resultContainer = document.getElementById('file-test-result');
                
                loader.style.display = 'block';
                resultContainer.textContent = 'Processing...';
                
                fetch('/openai-test/process-file', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    loader.style.display = 'none';
                    if (data.status === 'success' || data.status === 'unchanged') {
                        let html = `<div class="alert alert-success">${data.message}</div>`;
                        html += `<p>Processed ${data.suggestions_count} suggestions</p>`;
                        if (data.suggestions.length > 0) {
                            html += '<h5>Suggestions:</h5>';
                            data.suggestions.forEach(suggestion => {
                                html += `<div class="card mb-3">
                                    <div class="card-header">File: ${suggestion.file_path}</div>
                                    <div class="card-body">
                                        <h6>Status: ${suggestion.status}</h6>
                                        <h6>Model: ${suggestion.ai_model}</h6>
                                        <pre>${suggestion.suggestion}</pre>
                                    </div>
                                </div>`;
                            });
                        }
                        resultContainer.innerHTML = html;
                    } else {
                        resultContainer.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    loader.style.display = 'none';
                    resultContainer.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
                });
            });
            
            // Directory Test Form
            document.getElementById('directory-test-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const loader = document.getElementById('directory-test-loader');
                const resultContainer = document.getElementById('directory-test-result');
                
                loader.style.display = 'block';
                resultContainer.textContent = 'Processing directory... This may take some time.';
                
                fetch('/openai-test/process-directory', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    loader.style.display = 'none';
                    if (data.status === 'success') {
                        let html = `<div class="alert alert-success">${data.message}</div>`;
                        html += `<p>Files processed: ${data.processed}<br>
                                Files skipped: ${data.skipped}<br>
                                Files failed: ${data.failed}<br>
                                Total suggestions: ${data.suggestions_count}</p>`;
                        
                        if (data.suggestions.length > 0) {
                            html += '<h5>Suggestions:</h5>';
                            data.suggestions.forEach(suggestion => {
                                html += `<div class="card mb-3">
                                    <div class="card-header">File: ${suggestion.file_path}</div>
                                    <div class="card-body">
                                        <h6>Status: ${suggestion.status}</h6>
                                        <h6>Model: ${suggestion.ai_model}</h6>
                                        <pre>${suggestion.suggestion}</pre>
                                    </div>
                                </div>`;
                            });
                        }
                        resultContainer.innerHTML = html;
                    } else {
                        resultContainer.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    loader.style.display = 'none';
                    resultContainer.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
                });
            });
            
            // Create File Form
            document.getElementById('create-file-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const loader = document.getElementById('create-file-loader');
                const resultContainer = document.getElementById('create-file-result');
                
                loader.style.display = 'block';
                resultContainer.textContent = 'Creating file...';
                
                fetch('/openai-test/create-file', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    loader.style.display = 'none';
                    if (data.status === 'success') {
                        resultContainer.innerHTML = `<div class="alert alert-success">${data.message}</div>
                                                    <p>File created at: ${data.file_path}</p>
                                                    <button class="btn btn-sm btn-primary process-created-file" data-file="${data.file_path}">Process This File</button>`;
                        
                        // Update the file list in the Process File tab
                        const filePathSelect = document.getElementById('file-path');
                        const option = document.createElement('option');
                        option.value = data.file_path;
                        option.textContent = data.file_path;
                        filePathSelect.appendChild(option);
                        
                        // Add event listener to the "Process This File" button
                        document.querySelector('.process-created-file').addEventListener('click', function() {
                            const filePath = this.getAttribute('data-file');
                            document.getElementById('file-path').value = filePath;
                            document.getElementById('file-test-tab').click();
                            document.getElementById('file-test-form').dispatchEvent(new Event('submit'));
                        });
                    } else {
                        resultContainer.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    loader.style.display = 'none';
                    resultContainer.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
                });
            });
            
            // Token Estimation Form
            document.getElementById('token-test-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const loader = document.getElementById('token-test-loader');
                const resultContainer = document.getElementById('token-test-result');
                
                loader.style.display = 'block';
                resultContainer.textContent = 'Estimating...';
                
                fetch('/openai-test/token-estimation', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    loader.style.display = 'none';
                    if (data.status === 'success') {
                        resultContainer.innerHTML = `<p>Character count: ${data.character_count}</p>
                                                   <p>Estimated token count: ${data.token_count}</p>`;
                    } else {
                        resultContainer.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    loader.style.display = 'none';
                    resultContainer.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
                });
            });
            
            // Load History Button
            document.getElementById('load-history').addEventListener('click', function() {
                const loader = document.getElementById('history-loader');
                const resultContainer = document.getElementById('history-result');
                
                loader.style.display = 'block';
                resultContainer.textContent = 'Loading history...';
                
                fetch('/openai-test/history', {
                    method: 'GET',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    loader.style.display = 'none';
                    if (data.status === 'success') {
                        if (data.file_scans.length === 0) {
                            resultContainer.innerHTML = '<p>No processing history found.</p>';
                            return;
                        }
                        
                        let html = '<h5>Recent File Scans:</h5>';
                        
                        data.file_scans.forEach(scan => {
                            html += `<div class="card mb-3">
                                <div class="card-header">
                                    <strong>File:</strong> ${scan.file_path}
                                </div>
                                <div class="card-body">
                                    <p><strong>Site:</strong> ${scan.site_url}<br>
                                    <strong>Theme:</strong> ${scan.theme}<br>
                                    <strong>Type:</strong> ${scan.file_type}<br>
                                    <strong>Status:</strong> ${scan.status}<br>
                                    <strong>Date:</strong> ${new Date(scan.created_at).toLocaleString()}</p>
                                    
                                    ${scan.file_suggestions && scan.file_suggestions.length ? 
                                        `<h6>Suggestions (${scan.file_suggestions.length}):</h6>
                                        <div class="accordion" id="suggestions-${scan.id}">
                                            ${scan.file_suggestions.map((suggestion, index) => `
                                                <div class="accordion-item">
                                                    <h2 class="accordion-header">
                                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#suggestion-${scan.id}-${index}">
                                                            Suggestion #${index + 1} (${suggestion.status})
                                                        </button>
                                                    </h2>
                                                    <div id="suggestion-${scan.id}-${index}" class="accordion-collapse collapse">
                                                        <div class="accordion-body">
                                                            <pre>${suggestion.suggestion || 'No suggestion content'}</pre>
                                                        </div>
                                                    </div>
                                                </div>
                                            `).join('')}
                                        </div>` : 
                                        '<p>No suggestions available</p>'
                                    }
                                </div>
                            </div>`;
                        });
                        
                        resultContainer.innerHTML = html;
                    } else {
                        resultContainer.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                    }
                })
                .catch(error => {
                    loader.style.display = 'none';
                    resultContainer.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
                });
            });
        });
    </script>
</body>
</html>
