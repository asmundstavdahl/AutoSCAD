<?php
require_once 'common.php';

// Load current spec
$spec_doc = $_SESSION['spec'] ?? file_exists("spec.md") ? file_get_contents("spec.md") : "";
$scad_code = $_SESSION['scad'] ?? file_exists("model.scad") ? file_get_contents("model.scad") : "";
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

    if (!isset($_SESSION['current_spec'])) {
        error_log("No current_spec in session");
        echo "data: {\"error\": \"No spec found\"}\n\n";
        exit;
    }
    $spec_doc = $_SESSION['current_spec'];
    error_log("Loaded spec from session");
    file_put_contents("spec.md", $spec_doc);
    $scad_code = $_SESSION['scad'] ?? "";
    if (empty($scad_code) && file_exists("model.scad")) {
        $scad_code = file_get_contents("model.scad");
    }
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

    // Check dependencies
    $dep_errors = check_dependencies();
    if (!empty($dep_errors)) {
        sendSSE('dep_error', ['message' => 'Dependencies not met: ' . implode(', ', $dep_errors)]);
        exit;
    }

    // Run the generation
    run_scad_generation($spec_doc, $scad_code, 'sendSSE', 3, $iteration_id);
    // Clean up session
    unset($_SESSION['current_spec']);
    error_log("Cleaned up current_spec from session");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = $_POST['project_id'] ?? '';
    $iteration_id = $_POST['iteration_id'] ?? '';
    $new_project_name = $_POST['new_project_name'] ?? '';
    $projects = get_projects();
    $iterations = [];
    $selected_iteration = null;
    if ($project_id) {
        $iterations = get_iterations($project_id);
        if ($iteration_id) {
            $selected_iteration = get_iteration($iteration_id);
            if ($selected_iteration) {
                $spec_doc = $selected_iteration['spec'];
                $scad_code = $selected_iteration['scad'];
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'create_project') {
        $name = date('c'); // ISO 8601 format
        $project_id = create_project($name);
        $iterations = get_iterations($project_id);
        // Return JSON for create_project
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'project_id' => $project_id]);
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'select_project') {
        // Just return success to reload
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'select_iteration') {
        // Just return success to reload
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_project_name') {
        if (update_project_name($_POST['project_id'], $_POST['project_name'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Failed to update project name']);
            exit;
        }
    }

    // Handle generation (specForm submission)
    $spec_doc = $_POST['spec'] ?? "";
    $scad_code = $_POST['scad'] ?? "";

    // Sanitize input
    $spec_doc = htmlspecialchars($spec_doc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $scad_code = htmlspecialchars($scad_code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    if (empty($spec_doc)) {
        http_response_code(400);
        echo json_encode(['error' => 'Specification is empty']);
        exit;
    }

    if (strlen($spec_doc) > 10000) {
        http_response_code(400);
        echo json_encode(['error' => 'Specification too long']);
        exit;
    }

    if (strlen($scad_code) > 50000) { // Allow larger for SCAD
        http_response_code(400);
        echo json_encode(['error' => 'SCAD code too long']);
        exit;
    }

    if (file_put_contents("spec.md", $spec_doc) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save spec.md']);
        exit;
    }

    $_SESSION['current_spec'] = $spec_doc;
    $_SESSION['scad'] = $scad_code;

    if (!empty($scad_code) && file_put_contents("model.scad", $scad_code) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save model.scad']);
        exit;
    }
    error_log("Spec saved to current_spec.txt");
    
    // Save iteration if project selected
    $iteration_id = null;
    if ($project_id) {
        $image_path = 'render.png'; // Assuming image is saved as render.png
        $iteration_id = save_iteration($project_id, $spec_doc, $scad_code, $image_path);
        $iterations = get_iterations($project_id); // Refresh iterations
    }
    
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
        <h2>Projects</h2>
        <form id="createProjectForm" method="post" style="margin-bottom: 20px;">
            <input type="submit" name="action" value="create_project" value="New Project">
        </form>
        <form id="projectSelectForm" method="post">
            <input type="hidden" name="action" value="select_project">
            <label for="project_id">Select Project:</label>
            <select id="project_id" name="project_id" onchange="this.form.submit()">
                <option value="">-- Select Project --</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>" <?php if ($project_id == $project['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($project['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="sidebar">
        <div class="sidebar">
            <?php if ($project_id): ?>
                <h3>Iterations for Project <?php echo htmlspecialchars($projects[array_search($project_id, array_column($projects, 'id'))]['name']); ?></h3>
                <form id="iterationSelectForm" method="post">
                    <input type="hidden" name="action" value="select_iteration">
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <label for="iteration_id">Select Iteration:</label>
                    <select id="iteration_id" name="iteration_id" onchange="this.form.submit()">
                        <option value="">-- Select Iteration --</option>
                        <?php foreach ($iterations as $iteration): ?>
                            <option value="<?php echo $iteration['id']; ?>" <?php if ($iteration_id == $iteration['id']) echo 'selected'; ?>>
                                Iteration <?php echo $iteration['id']; ?> (<?php echo $iteration['created_at']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>
        <div class="main">
        </div>
        <div class="main">
        <form id="specForm" method="post">
            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
            <input type="hidden" name="iteration_id" value="<?php echo $iteration_id; ?>">
            <?php if ($project_id): ?>
                <label for="project_name">Project Name:</label>
                <input type="text" id="project_name" name="project_name" value="<?php echo htmlspecialchars($projects[array_search($project_id, array_column($projects, 'id'))]['name']); ?>" onchange="updateProjectName(this.value)">
                <br><br>
            <?php endif; ?>
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
            <label for="scad">SCAD Code:<br>
                <small class="text-muted">Leave empty to auto-generate from spec</small>
            </label>
            <textarea
                id="scad"
                name="scad"
                rows="10"
                cols="50"
            ><?php echo htmlspecialchars($scad_code); ?></textarea><br>
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
        <div class="main">
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

        // Handle create project form
        const createProjectForm = document.getElementById('createProjectForm');
        if (createProjectForm) {
            createProjectForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(createProjectForm);
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                }).then(response => {
                    if (response.ok) {
                        response.json().then(data => {
                            if (data.success) {
                                location.reload();
                            }
                        });
                    } else {
                        response.json().then(data => {
                            alert('Error: ' + (data.error || 'Unknown error'));
                        });
                    }
                }).catch(error => {
                    alert('Error: ' + error.message);
                });
            });
        }

        // Handle project select form
        const projectSelectForm = document.getElementById('projectSelectForm');
        if (projectSelectForm) {
            projectSelectForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(projectSelectForm);
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                }).then(response => {
                    if (response.ok) {
                        response.json().then(data => {
                            if (data.success) {
                                location.reload();
                            }
                        });
                    } else {
                        response.json().then(data => {
                            alert('Error: ' + (data.error || 'Unknown error'));
                        });
                    }
                }).catch(error => {
                    alert('Error: ' + error.message);
                });
            });
        }

        // Function to update project name
        function updateProjectName(name) {
            const formData = new FormData();
            formData.append('action', 'update_project_name');
            formData.append('project_id', <?php echo $project_id; ?>);
            formData.append('project_name', name);
            fetch('index.php', {
                method: 'POST',
                body: formData
            }).then(response => {
                if (response.ok) {
                    response.json().then(data => {
                        if (data.success) {
                            location.reload();
                        }
                    });
                } else {
                    response.json().then(data => {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    });
                }
            }).catch(error => {
                alert('Error: ' + error.message);
            });
        }

        function scrollToBottom() {
            liveUpdates.scrollTop = liveUpdates.scrollHeight;
        }

        // Enable button when spec or scad is modified
        const specInput = document.getElementById('spec');
        const scadInput = document.getElementById('scad');
        function enableButton() {
            generateBtn.disabled = false;
        }
        specInput.addEventListener('input', enableButton);
        scadInput.addEventListener('input', enableButton);

        // Enable button on page load if there's existing content
        window.addEventListener('load', function() {
            if (specInput.value.trim() || scadInput.value.trim()) {
                generateBtn.disabled = false;
            }
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
                    eventSource.addEventListener('message', function(event) {
                        console.log('SSE message:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            if (data.error) {
                                liveUpdates.innerHTML += `<p>Error: ${data.error}</p>`;
                                scrollToBottom();
                                eventSource.close();
                                generateBtn.disabled = false;
                            }
                        } catch (e) {
                            liveUpdates.innerHTML += `<p>Unexpected message: ${event.data}</p>`;
                            scrollToBottom();
                        }
                    });
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
                            document.getElementById('scad').value = data.code;
                            scrollToBottom();
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                            scrollToBottom();
                        }
                    });

                    eventSource.addEventListener('new_iteration', function(event) {
                        console.log('SSE new_iteration:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            if (data.iteration) {
                                const iterationSelect = document.getElementById('iteration_id');
                                const option = document.createElement('option');
                                option.value = data.iteration;
                                option.text = 'Iteration ' + data.iteration + ' (just now)';
                                iterationSelect.appendChild(option);
                                iterationSelect.value = data.iteration;
                            }
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
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

                    eventSource.addEventListener('dep_error', function(event) {
                        console.log('SSE dep error:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            liveUpdates.innerHTML += `<p>Error: ${data.message}</p>`;
                            scrollToBottom();
                            eventSource.close();
                            generateBtn.disabled = false;
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                            scrollToBottom();
                            eventSource.close();
                            generateBtn.disabled = false;
                        }
                    });

                    eventSource.addEventListener('connection_error', function(event) {
                        console.log('SSE connection error:', event);
                        liveUpdates.innerHTML += '<p>Error in connection.</p>';
                        scrollToBottom();
                        eventSource.close();
                        generateBtn.disabled = false;
                    });
                    eventSource.onerror = function() {
                        liveUpdates.innerHTML += '<p>Error in connection.</p>';
                        scrollToBottom();
                        eventSource.close();
                        generateBtn.disabled = false;
                    };
                } else {
                    response.json().then(data => {
                        liveUpdates.innerHTML += '<p>Error: ' + (data.error || 'Unknown error') + '</p>';
                        scrollToBottom();
                        generateBtn.disabled = false;
                    }).catch(() => {
                        liveUpdates.innerHTML += '<p>Failed to start generation.</p>';
                        scrollToBottom();
                        generateBtn.disabled = false;
                    });
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
