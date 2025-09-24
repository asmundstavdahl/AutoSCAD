<?php
session_start();
require_once 'common.php';

// Security: CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Load current spec
$spec_doc = file_exists("spec.md") ? file_get_contents("spec.md") : "";
$scad_code = file_exists("model.scad") ? file_get_contents("model.scad") : "";
$output = "";
$image_data_uri = "";
$final_scad = "";

if (isset($_GET['sse'])) {
    // SSE endpoint
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('Access-Control-Allow-Origin: *');
    while (ob_get_level()) ob_end_flush();
    ob_start();
    error_log("SSE started");

    if (!file_exists("current_spec.txt")) {
        error_log("No current_spec.txt found");
        echo "data: {\"error\": \"No spec found\"}\n\n";
        exit;
    }
    $spec_doc = file_get_contents("current_spec.txt");
    error_log("Loaded spec from current_spec.txt");
    file_put_contents("spec.md", $spec_doc);
    $scad_code = file_exists("model.scad") ? file_get_contents("model.scad") : "";
    $original_scad_code = $scad_code;
    $max_iterations = 3; // Prevent infinite loop
    $iteration = 0;
    $spec_fulfilled = false;

    function sendSSE($event, $data)
    {
        echo "event: $event\n";
        echo "data: " . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }

    // Run the generation
    run_scad_generation($spec_doc, $scad_code, 'sendSSE');
    // Clean up spec file
    if (file_exists("current_spec.txt")) {
        unlink("current_spec.txt");
        error_log("Cleaned up current_spec.txt");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $spec_doc = $_POST['spec'] ?? "";
    
    // Sanitize input
    $spec_doc = htmlspecialchars($spec_doc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    
    if (strlen($spec_doc) > 10000) {
        http_response_code(400);
        echo json_encode(['error' => 'Specification too long']);
        exit;
    }

    file_put_contents("spec.md", $spec_doc);
    file_put_contents("current_spec.txt", $spec_doc);
    error_log("Spec saved to current_spec.txt");
    
    // Return JSON instead of redirect
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AutoSCAD Web Interface</title>
    <link rel="stylesheet" href="style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .loading.active {
            display: flex;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>AutoSCAD</h1>
        <form id="specForm" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <label for="spec">Specification:<br>
                <small class="text-muted">Max 10000 characters</small>
            </label>
            <textarea 
                id="spec" 
                name="spec" 
                rows="10" 
                cols="50"
                maxlength="10000"
                required
            ><?php echo htmlspecialchars($spec_doc); ?></textarea><br>
            <button 
                type="submit" 
                id="generateBtn" 
                disabled
            >Generate SCAD</button>
        </form>
        <div class="loading">
            <div class="spinner">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
                <span class="loading-text">Generating...</span>
            </div>
        </div>
        <div class="results">
            <h2>Live Updates</h2>
            <div id="live-updates" class="live-updates"></div>
            <div id="final-results" style="display: none;">
                <?php if ($output): ?>
                    <h2>Output</h2>
                    <pre><?php echo htmlspecialchars($output); ?></pre>
                    <?php if ($image_data_uri): ?>
                        <h2>Rendered Model</h2>
                        <img src="<?php echo $image_data_uri; ?>" alt="Rendered SCAD model" class="rendered-image">
                    <?php endif; ?>
                    <?php if ($final_scad): ?>
                        <h2>Generated SCAD Code</h2>
                        <pre><?php echo htmlspecialchars($final_scad); ?></pre>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        const form = document.getElementById('specForm');
        const generateBtn = document.getElementById('generateBtn');
        const liveUpdates = document.getElementById('live-updates');
        const finalResults = document.getElementById('final-results');
        const loading = document.querySelector('.loading');

        function scrollToBottom() {
            liveUpdates.scrollTop = liveUpdates.scrollHeight;
        }

        // Enable button when spec is modified
        const specInput = document.getElementById('spec');
        specInput.addEventListener('input', function() {
            generateBtn.disabled = false;
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            generateBtn.disabled = true;
            loading.style.display = 'flex';
            liveUpdates.innerHTML = '';
            finalResults.style.display = 'none';

            const formData = new FormData(form);
            fetch('index.php', {
                method: 'POST',
                body: formData
            }).then(response => {
                loading.style.display = 'none';
                if (response.ok) {
                    const eventSource = new EventSource('index.php?sse=1');
                    eventSource.addEventListener('iteration', function(event) {
                        console.log('SSE iteration:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            liveUpdates.innerHTML += `<p><strong>Iteration ${data.iteration}:</strong> ${data.message}</p>`;
                            scrollToBottom();
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                            scrollToBottom();
                        }
                    });

                    eventSource.addEventListener('render', function(event) {
                        console.log('SSE render:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            if (data.image) {
                                liveUpdates.innerHTML += `<p>Rendered model:</p><img src="${data.image}" alt="Iteration render" style="max-width: 100%;">`;
                                scrollToBottom();
                            }
                            if (data.errors) {
                                liveUpdates.innerHTML += `<p>Errors: ${data.errors}</p>`;
                                scrollToBottom();
                            }
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                            scrollToBottom();
                        }
                    });

                    eventSource.addEventListener('eval', function(event) {
                        console.log('SSE eval:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            liveUpdates.innerHTML += `<p>Evaluation: ${data.result} (${data.explanation})</p>`;
                            scrollToBottom();
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                            scrollToBottom();
                        }
                    });

                    eventSource.addEventListener('plan', function(event) {
                        console.log('SSE plan:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            let html = '<p>Plan:</p>';
                            let plan = data.plan;
                            // If plan is a JSON string, parse it (strip markdown code blocks first)
                            if (typeof plan === 'string') {
                                // Extract JSON from markdown code block if present
                                const jsonMatch = plan.match(/```(?:json)?\s*([\s\S]*?)\s*```/);
                                if (jsonMatch) {
                                    plan = jsonMatch[1].trim();
                                }
                                try {
                                    plan = JSON.parse(plan);
                                } catch (e) {
                                    // If not JSON, treat as plain text
                                }
                            }
                            if (Array.isArray(plan)) {
                                plan.forEach(step => {
                                    html += `<details><summary>${step.step}</summary>`;
                                    if (step.description) html += `<p>${step.description}</p>`;
                                    if (step.code) html += `<pre>${step.code}</pre>`;
                                    html += `</details>`;
                                });
                            } else if (typeof plan === 'object') {
                                // Pretty-print object
                                html += `<pre>${JSON.stringify(plan, null, 2)}</pre>`;
                            } else {
                                // Plain text
                                html += `<p>${plan}</p>`;
                            }
                            liveUpdates.innerHTML += html;
                            scrollToBottom();
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                            scrollToBottom();
                        }
                    });

                    eventSource.addEventListener('scad', function(event) {
                        console.log('SSE scad:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            liveUpdates.innerHTML += `<p>Generated SCAD code:</p><pre>${data.code}</pre>`;
                            scrollToBottom();
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                            scrollToBottom();
                        }
                    });

                    eventSource.addEventListener('done', function(event) {
                        console.log('SSE done:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            liveUpdates.innerHTML += `<p><strong>${data.message}</strong></p>`;
                            scrollToBottom();
                            eventSource.close();
                            generateBtn.disabled = false;
                            finalResults.style.display = 'block';
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                            scrollToBottom();
                        }
                    });

                    eventSource.addEventListener('error', function(event) {
                        console.log('SSE error:', event);
                        if (event.readyState === EventSource.CLOSED) {
                            console.log('Connection closed normally');
                        } else {
                            liveUpdates.innerHTML += '<p>Error in connection.</p>';
                            scrollToBottom();
                            generateBtn.disabled = false;
                        }
                        eventSource.close();
                    });
                    eventSource.onerror = function() {
                        liveUpdates.innerHTML += '<p>Error in connection.</p>';
                        scrollToBottom();
                        eventSource.close();
                        generateBtn.disabled = false;
                    };
                } else {
                    liveUpdates.innerHTML += '<p>Failed to start generation.</p>';
                    scrollToBottom();
                    generateBtn.disabled = false;
                }
            }).catch(error => {
                loading.style.display = 'none';
                liveUpdates.innerHTML += '<p>Error: ' + error.message + '</p>';
                scrollToBottom();
                generateBtn.disabled = false;
            });
        });
    </script>
</body>
</html>
