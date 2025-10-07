<?php
require_once 'common.php';

// Initialize database
init_db();

// Handle different actions
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create_project':
        create_project();
        break;
    case 'get_projects':
        get_projects();
        break;
    case 'update_project_name':
        update_project_name();
        break;
    case 'get_iterations':
        get_iterations();
        break;
    case 'generate':
        generate();
        break;
    case 'sse':
        sse();
        break;
    default:
        show_interface();
        break;
}

function create_project() {
    header('Content-Type: application/json');
    $db = get_db();
    $name = $_POST['name'] ?? 'Project ' . date('Y-m-d H:i:s');
    
    $stmt = $db->prepare("INSERT INTO projects (name) VALUES (?)");
    $stmt->execute([$name]);
    
    $project_id = $db->lastInsertId();
    
    echo json_encode(['success' => true, 'project_id' => $project_id, 'name' => $name]);
}

function get_projects() {
    header('Content-Type: application/json');
    $db = get_db();
    $stmt = $db->query("SELECT * FROM projects ORDER BY created_at DESC");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($projects);
}

function update_project_name() {
    header('Content-Type: application/json');
    $db = get_db();
    $project_id = $_POST['project_id'];
    $name = $_POST['name'];
    
    $stmt = $db->prepare("UPDATE projects SET name = ? WHERE id = ?");
    $stmt->execute([$name, $project_id]);
    
    echo json_encode(['success' => true]);
}

function get_iterations() {
    header('Content-Type: application/json');
    $db = get_db();
    $project_id = $_GET['project_id'];
    
    $stmt = $db->prepare("SELECT * FROM iterations WHERE project_id = ? ORDER BY created_at DESC");
    $stmt->execute([$project_id]);
    $iterations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($iterations);
}

function generate() {
    header('Content-Type: application/json');
    // This would be a long-running process, so we'll use SSE to stream updates
    // For now, just return success and the actual processing will happen via SSE
    echo json_encode(['success' => true]);
}

function sse() {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    
    // Get parameters from GET since SSE uses GET requests
    $project_id = $_GET['project_id'];
    $spec = $_GET['spec'] ?? '';
    $scad_code = $_GET['scad_code'] ?? '';
    
    // Validate inputs
    if (empty($spec)) {
        send_sse_message(['error' => 'Spec cannot be empty']);
        exit;
    }
    
    // Check dependencies
    if (!check_openscad()) {
        send_sse_message(['error' => 'OpenSCAD is not installed or not in PATH']);
        exit;
    }
    
    if (!get_api_key()) {
        send_sse_message(['error' => 'OPENROUTER_API_KEY environment variable not set']);
        exit;
    }
    
    // Insert initial iteration
    $db = get_db();
    $stmt = $db->prepare("INSERT INTO iterations (project_id, spec, scad_code) VALUES (?, ?, ?)");
    $stmt->execute([$project_id, $spec, $scad_code]);
    $iteration_id = $db->lastInsertId();
    
    send_sse_message(['iteration_started' => true, 'iteration_id' => $iteration_id]);
    
    // Start generation loop
    $max_iterations = 3;
    for ($i = 0; $i < $max_iterations; $i++) {
        // Try to render the SCAD code, with up to 3 attempts to fix syntax errors
        $render_attempts = 0;
        $max_render_attempts = 3;
        $render_result = null;
        
        while ($render_attempts < $max_render_attempts) {
            $render_result = render_scad($scad_code);
            if (!isset($render_result['error'])) {
                break;
            }
            
            $render_attempts++;
            send_sse_message(['render_error' => $render_result['error'], 'attempt' => $render_attempts]);
            
            if ($render_attempts >= $max_render_attempts) {
                send_sse_message(['error' => 'Failed to fix SCAD code after ' . $max_render_attempts . ' attempts']);
                break 2; // Break out of both loops
            }
            
            // Read the OpenSCAD reference
            $openscad_reference = @file_get_contents('openscad_llms_comprehensive.txt');
            if ($openscad_reference === false) {
                $openscad_reference = "OpenSCAD reference file not found. Please use your knowledge of OpenSCAD to fix the code.";
            } else {
                // Truncate if too long to avoid token limits
                if (strlen($openscad_reference) > 8000) {
                    $openscad_reference = substr($openscad_reference, 0, 8000) . "\n\n[Reference truncated due to length]";
                }
            }
            
            // Ask LLM to fix the SCAD code based on the error
            $fix_messages = [
                [
                    'role' => 'system',
                    'content' => 'You are an expert in OpenSCAD programming. Use the following comprehensive OpenSCAD reference to fix the provided SCAD code based on the rendering error. Ensure the code is valid OpenSCAD syntax. Respond with only the fixed SCAD code.' . 
                                "\n\nOpenSCAD Reference:\n" . $openscad_reference
                ],
                [
                    'role' => 'user',
                    'content' => "SCAD Code:\n```openscad\n$scad_code\n```\n\nRendering Error:\n" . $render_result['error'] . "\n\nFix the SCAD code:"
                ]
            ];
            
            $fix_result = call_llm($fix_messages);
            if (isset($fix_result['error'])) {
                send_sse_message(['error' => 'Failed to fix SCAD code: ' . $fix_result['error']]);
                break 2;
            }
            
            // Update SCAD code with the fix
            $fixed_scad_code = trim($fix_result['content']);
            // Clean up markdown code blocks if present
            $fixed_scad_code = preg_replace('/^```(?:openscad)?\s*/', '', $fixed_scad_code);
            $fixed_scad_code = preg_replace('/\s*```$/', '', $fixed_scad_code);
            
            send_sse_message(['fixed_scad_code' => $fixed_scad_code]);
            
            // Update the iteration with fixed SCAD code
            $stmt = $db->prepare("UPDATE iterations SET scad_code = ? WHERE id = ?");
            $stmt->execute([$fixed_scad_code, $iteration_id]);
            
            $scad_code = $fixed_scad_code;
        }
        
        // If we couldn't render after max attempts, break
        if (isset($render_result['error'])) {
            break;
        }
        
        send_sse_message(['render_complete' => true, 'images' => $render_result['images']]);
        
        // Evaluate if the spec is fulfilled - include the rendered images
        $evaluation_messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert in OpenSCAD and 3D modeling. Evaluate if the provided SCAD code and the rendered images from 7 different views (default isometric, front, back, left, right, top, bottom) fulfill the specification. ' .
                            'Note: Each image includes an axis cross (X=red, Y=green, Z=blue) to help with orientation. ' .
                            'Look at each image carefully to understand the 3D model from all angles. ' .
                            'Respond with a JSON object: {"fulfilled": true/false, "reasoning": "brief explanation"}'
            ],
            [
                'role' => 'user',
                'content' => "Specification: $spec\n\nSCAD Code:\n```openscad\n$scad_code\n```\n\nBelow are 7 images showing the rendered 3D model from different views, including a default isometric view. Each view includes an axis cross (X=red, Y=green, Z=blue) for orientation reference. Please evaluate if the model fulfills the specification based on these images and the SCAD code."
            ]
        ];
        
        // Pass the rendered images to the LLM
        $evaluation_result = call_llm($evaluation_messages, $render_result['images']);
        if (isset($evaluation_result['error'])) {
            send_sse_message(['error' => 'Evaluation failed: ' . $evaluation_result['error']]);
            break;
        }
        
        // Parse the evaluation
        $evaluation_content = $evaluation_result['content'];
        send_sse_message(['evaluation' => $evaluation_content]);
        
        // Try to parse JSON
        $evaluation_json = json_decode($evaluation_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // If not valid JSON, try to extract JSON from the response
            preg_match('/\{.*\}/s', $evaluation_content, $matches);
            if (!empty($matches)) {
                $evaluation_json = json_decode($matches[0], true);
            }
        }
        
        // Check if spec is fulfilled
        if (isset($evaluation_json['fulfilled']) && $evaluation_json['fulfilled'] === true) {
            send_sse_message(['spec_fulfilled' => true]);
            break;
        }
        
        // If not fulfilled, generate a plan
        $plan_messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert in OpenSCAD and 3D modeling. Create a concrete plan to modify the SCAD code to better fulfill the specification. ' .
                            'Respond with a JSON object: {"steps": ["step 1", "step 2", ...]}'
            ],
            [
                'role' => 'user',
                'content' => "Specification: $spec\n\nCurrent SCAD Code:\n```openscad\n$scad_code\n```\n\nEvaluation: " . 
                            (isset($evaluation_json['reasoning']) ? $evaluation_json['reasoning'] : $evaluation_content) .
                            "\n\nCreate a plan to improve the SCAD code. Respond with JSON only."
            ]
        ];
        
        $plan_result = call_llm($plan_messages); // No images for planning
        if (isset($plan_result['error'])) {
            send_sse_message(['error' => 'Planning failed: ' . $plan_result['error']]);
            break;
        }
        
        $plan_content = $plan_result['content'];
        send_sse_message(['plan' => $plan_content]);
        
        // Read the OpenSCAD reference
        $openscad_reference = @file_get_contents('openscad_llms_comprehensive.txt');
        if ($openscad_reference === false) {
            $openscad_reference = "OpenSCAD reference file not found. Please use your knowledge of OpenSCAD to write the code.";
        } else {
            // Truncate if too long to avoid token limits
            if (strlen($openscad_reference) > 8000) {
                $openscad_reference = substr($openscad_reference, 0, 8000) . "\n\n[Reference truncated due to length]";
            }
        }
        
        // Generate new SCAD code
        $generate_messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert in OpenSCAD programmer. Use the following comprehensive OpenSCAD reference to write valid SCAD code based on the specification and plan. ' .
                            'Clean any markdown formatting and ensure the code is valid. Respond with only the SCAD code.' .
                            "\n\nOpenSCAD Reference:\n" . $openscad_reference
            ],
            [
                'role' => 'user',
                'content' => "Specification: $spec\n\nCurrent SCAD Code:\n```openscad\n$scad_code\n```\n\nPlan: $plan_content\n\nWrite the new SCAD code:"
            ]
        ];
        
        $generate_result = call_llm($generate_messages); // No images for code generation
        if (isset($generate_result['error'])) {
            send_sse_message(['error' => 'Code generation failed: ' . $generate_result['error']]);
            break;
        }
        
        // Update SCAD code for next iteration
        $new_scad_code = trim($generate_result['content']);
        // Clean up markdown code blocks if present
        $new_scad_code = preg_replace('/^```(?:openscad)?\s*/', '', $new_scad_code);
        $new_scad_code = preg_replace('/\s*```$/', '', $new_scad_code);
        
        send_sse_message(['new_scad_code' => $new_scad_code]);
        
        // Update the iteration with new SCAD code
        $stmt = $db->prepare("UPDATE iterations SET scad_code = ? WHERE id = ?");
        $stmt->execute([$new_scad_code, $iteration_id]);
        
        $scad_code = $new_scad_code;
    }
    
    send_sse_message(['generation_complete' => true]);
}

function send_sse_message($data) {
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

function show_interface() {
    ?>
<!DOCTYPE html>
<html>
<head>
    <title>AutoSCAD</title>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-color: #1e293b;
            --text-muted: #64748b;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
        }
        
        .container {
            display: flex;
            min-height: 100vh;
            flex-direction: row;
        }
        
        .sidebar {
            width: 300px;
            background: var(--card-bg);
            border-right: 1px solid var(--border-color);
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            box-sizing: border-box;
            overflow-y: auto;
        }
        
        .main {
            flex: 1;
            padding: 30px;
            box-sizing: border-box;
            overflow-x: hidden;
        }
        
        .card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            box-sizing: border-box;
        }
        
        .input-row {
            display: flex;
            gap: 20px;
        }
        
        .input-column {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0; /* Prevent flex items from overflowing */
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
                max-height: 40vh;
                overflow-y: auto;
            }
            
            .main {
                padding: 20px;
                width: 100%;
                overflow-x: hidden;
            }
            
            .input-row {
                flex-direction: column;
                gap: 0;
            }
            
            /* Ensure textareas don't overflow on mobile */
            textarea {
                max-width: 100%;
                box-sizing: border-box;
            }
        }
        
        h1 {
            color: var(--primary-color);
            margin: 0 0 20px 0;
            font-size: 28px;
            font-weight: 600;
        }
        
        h2 {
            font-size: 18px;
            margin: 0 0 15px 0;
            color: var(--text-color);
        }
        
        button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background-color 0.2s;
            width: 100%;
            margin-bottom: 10px;
        }
        
        button:hover {
            background-color: var(--primary-hover);
        }
        
        button:disabled {
            background-color: #94a3b8;
            cursor: not-allowed;
        }
        
        select, input, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 15px;
            box-sizing: border-box;
        }
        
        textarea {
            min-height: 120px;
            font-family: 'Courier New', monospace;
            resize: vertical;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .iteration-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 6px;
        }
        
        .iteration-item {
            padding: 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s;
        }
        
        .iteration-item:hover {
            background-color: #f1f5f9;
        }
        
        .iteration-item:last-child {
            border-bottom: none;
        }
        
        .iteration-item.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .status-messages-container {
            height: 120px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px;
            background-color: #f8fafc;
            font-family: monospace;
            font-size: 12px;
            line-height: 1.4;
        }
        
        .status-message {
            margin-bottom: 4px;
            padding: 4px;
            border-radius: 3px;
        }
        
        .status-info {
            color: #1e40af;
        }
        
        .status-success {
            color: #166534;
        }
        
        .status-error {
            color: #991b1b;
        }
        
        .preview-container {
            margin-top: 20px;
            text-align: center;
        }
        
        #rendered-image {
            max-width: 100%;
            max-height: 400px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .progress-bar {
            width: 100%;
            height: 4px;
            background-color: var(--border-color);
            border-radius: 2px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            height: 100%;
            background-color: var(--primary-color);
            width: 0%;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <h1>AutoSCAD</h1>
            <button id="new-project">➕ New Project</button>
            <select id="project-selector">
                <option value="">Select a project...</option>
            </select>
            
            <h2>Iterations</h2>
            <div id="iteration-list" class="iteration-list">
                <div class="iteration-item" style="text-align: center; color: var(--text-muted);">
                    No iterations yet
                </div>
            </div>
        </div>
        
        <div class="main">
            <div class="card">
                <label for="project-name">Project Name</label>
                <input type="text" id="project-name" placeholder="Enter project name...">
                
                <div class="input-row">
                    <div class="input-column">
                        <label for="spec">Specification</label>
                        <textarea id="spec" placeholder="Describe your 3D model in natural language..."></textarea>
                    </div>
                    <div class="input-column">
                        <label for="scad-code">SCAD Code</label>
                        <textarea id="scad-code" placeholder="OpenSCAD code will appear here..."></textarea>
                    </div>
                </div>
                
                <button id="generate">🚀 Generate & Refine</button>
                <div class="progress-bar" style="display: none;" id="progress-bar">
                    <div class="progress-fill" id="progress-fill"></div>
                </div>
                <div id="status-messages" class="status-messages-container"></div>
            </div>
            
            <div class="card">
                <h2>3D Preview (7 Views)</h2>
                <div class="preview-container">
                    <div id="no-preview" style="color: var(--text-muted);">
                        No preview available. Generate a model to see it here.
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentProjectId = null;
        let currentIterationId = null;
        let eventSource = null;
        
        // Load projects on page load
        window.onload = function() {
            loadProjects();
            document.getElementById('new-project').addEventListener('click', createNewProject);
            document.getElementById('project-selector').addEventListener('change', onProjectSelected);
            document.getElementById('project-name').addEventListener('change', updateProjectName);
            document.getElementById('generate').addEventListener('click', startGeneration);
        };
        
        function loadProjects() {
            fetch('?action=get_projects')
                .then(response => response.json())
                .then(projects => {
                    const selector = document.getElementById('project-selector');
                    selector.innerHTML = '<option value="">Select a project...</option>';
                    projects.forEach(project => {
                        const option = document.createElement('option');
                        option.value = project.id;
                        option.textContent = project.name;
                        selector.appendChild(option);
                    });
                    if (projects.length > 0 && !currentProjectId) {
                        currentProjectId = projects[0].id;
                        selector.value = currentProjectId;
                        loadProjectData(currentProjectId);
                    }
                });
        }
        
        function createNewProject() {
            fetch('?action=create_project', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'name=Project ' + new Date().toISOString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentProjectId = data.project_id;
                    loadProjects();
                    document.getElementById('spec').value = '';
                    document.getElementById('scad-code').value = '';
                    document.getElementById('project-name').value = data.name;
                    document.getElementById('iteration-list').innerHTML = '<div class="iteration-item" style="text-align: center; color: var(--text-muted);">No iterations yet</div>';
                    hidePreview();
                    clearStatusMessages();
                }
            });
        }
        
        function onProjectSelected(event) {
            currentProjectId = event.target.value;
            if (currentProjectId) {
                loadProjectData(currentProjectId);
            } else {
                document.getElementById('project-name').value = '';
                document.getElementById('spec').value = '';
                document.getElementById('scad-code').value = '';
                document.getElementById('iteration-list').innerHTML = '<div class="iteration-item" style="text-align: center; color: var(--text-muted);">No iterations yet</div>';
                hidePreview();
            }
        }
        
        function loadProjectData(projectId) {
            // Load project name
            document.getElementById('project-name').value = 
                document.getElementById('project-selector').selectedOptions[0].textContent;
            
            // Load iterations
            fetch('?action=get_iterations&project_id=' + projectId)
                .then(response => response.json())
                .then(iterations => {
                    const list = document.getElementById('iteration-list');
                    list.innerHTML = '';
                    if (iterations.length === 0) {
                        list.innerHTML = '<div class="iteration-item" style="text-align: center; color: var(--text-muted);">No iterations yet</div>';
                    } else {
                        iterations.forEach(iteration => {
                            const item = document.createElement('div');
                            item.className = 'iteration-item';
                            item.textContent = 'Iteration ' + iteration.id;
                            item.addEventListener('click', () => loadIteration(iteration));
                            list.appendChild(item);
                        });
                        loadIteration(iterations[0]);
                    }
                });
        }
        
        function loadIteration(iteration) {
            currentIterationId = iteration.id;
            document.getElementById('spec').value = iteration.spec;
            document.getElementById('scad-code').value = iteration.scad_code;
            
            // Highlight active iteration
            document.querySelectorAll('.iteration-item').forEach(item => {
                item.classList.remove('active');
            });
            event.target.classList.add('active');
        }
        
        function updateProjectName() {
            const newName = document.getElementById('project-name').value;
            if (!newName.trim()) return;
            
            fetch('?action=update_project_name', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'project_id=' + currentProjectId + '&name=' + encodeURIComponent(newName)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadProjects();
                }
            });
        }
        
        function startGeneration() {
            const spec = document.getElementById('spec').value;
            const scadCode = document.getElementById('scad-code').value;
            
            if (!spec.trim()) {
                alert('Please enter a specification');
                return;
            }
            
            if (!currentProjectId) {
                alert('Please select or create a project first');
                return;
            }
            
            if (eventSource) {
                eventSource.close();
            }
            
            // Show loading state
            document.getElementById('generate').disabled = true;
            document.getElementById('generate').textContent = 'Generating...';
            document.getElementById('progress-bar').style.display = 'block';
            document.getElementById('progress-fill').style.width = '0%';
            clearStatusMessages();
            
            // Pass spec and scad_code as GET parameters
            const sseUrl = '?action=sse&project_id=' + currentProjectId + 
                      '&spec=' + encodeURIComponent(spec) + 
                      '&scad_code=' + encodeURIComponent(scadCode);
            eventSource = new EventSource(sseUrl);
            
            eventSource.onmessage = function(event) {
                const data = JSON.parse(event.data);
                console.log(data);
                
                if (data.error) {
                    addStatusMessage('Error: ' + data.error, 'error');
                    document.getElementById('generate').disabled = false;
                    document.getElementById('generate').textContent = '🚀 Generate & Refine';
                    document.getElementById('progress-bar').style.display = 'none';
                    eventSource.close();
                } else if (data.iteration_started) {
                    currentIterationId = data.iteration_id;
                    addStatusMessage('Started new iteration ' + data.iteration_id, 'info');
                    document.getElementById('progress-fill').style.width = '25%';
                } else if (data.render_error) {
                    addStatusMessage(`Rendering error (attempt ${data.attempt}/3): ${data.render_error}`, 'error');
                    document.getElementById('progress-fill').style.width = '40%';
                } else if (data.fixed_scad_code) {
                    document.getElementById('scad-code').value = data.fixed_scad_code;
                    addStatusMessage('Fixed SCAD code based on rendering error', 'info');
                } else if (data.render_complete) {
                    addStatusMessage('Rendered 3D model from 7 angles', 'info');
                    const previewContainer = document.querySelector('.preview-container');
                    previewContainer.innerHTML = '';
                    
                    // Create a grid for the 7 images - 4 columns for better layout
                    const grid = document.createElement('div');
                    grid.style.display = 'grid';
                    grid.style.gridTemplateColumns = 'repeat(4, 1fr)';
                    grid.style.gap = '10px';
                    grid.style.marginTop = '20px';
                    
                    const viewNames = {
                        'default': 'Default (Isometric)',
                        'front': 'Front',
                        'back': 'Back', 
                        'left': 'Left',
                        'right': 'Right',
                        'top': 'Top',
                        'bottom': 'Bottom'
                    };
                    
                    // Ensure default view is first
                    const orderedViews = ['default', 'front', 'back', 'left', 'right', 'top', 'bottom'];
                    
                    for (const view of orderedViews) {
                        if (data.images[view]) {
                            const imgWrapper = document.createElement('div');
                            imgWrapper.style.textAlign = 'center';
                            
                            const label = document.createElement('div');
                            label.textContent = viewNames[view] || view;
                            label.style.marginBottom = '5px';
                            label.style.fontWeight = '500';
                            label.style.color = 'var(--text-color)';
                            label.style.fontSize = '14px';
                            
                            const img = document.createElement('img');
                            img.src = 'data:image/png;base64,' + data.images[view];
                            img.style.maxWidth = '100%';
                            img.style.border = '1px solid var(--border-color)';
                            img.style.borderRadius = '6px';
                            img.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                            
                            imgWrapper.appendChild(label);
                            imgWrapper.appendChild(img);
                            grid.appendChild(imgWrapper);
                        }
                    }
                    
                    previewContainer.appendChild(grid);
                    document.getElementById('no-preview').style.display = 'none';
                    document.getElementById('progress-fill').style.width = '50%';
                } else if (data.evaluation) {
                    addStatusMessage('Evaluating specification...', 'info');
                    document.getElementById('progress-fill').style.width = '75%';
                } else if (data.plan) {
                    addStatusMessage('Planning improvements...', 'info');
                } else if (data.new_scad_code) {
                    document.getElementById('scad-code').value = data.new_scad_code;
                    addStatusMessage('Generated new SCAD code', 'info');
                } else if (data.spec_fulfilled) {
                    addStatusMessage('Specification fulfilled!', 'success');
                    document.getElementById('progress-fill').style.width = '100%';
                } else if (data.generation_complete) {
                    addStatusMessage('Generation complete', 'success');
                    document.getElementById('generate').disabled = false;
                    document.getElementById('generate').textContent = '🚀 Generate & Refine';
                    document.getElementById('progress-bar').style.display = 'none';
                    eventSource.close();
                    loadProjectData(currentProjectId);
                }
            };
            
            // Send the generation request
            fetch('?action=generate', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'project_id=' + currentProjectId + '&spec=' + encodeURIComponent(spec) + 
                      '&scad_code=' + encodeURIComponent(scadCode)
            });
        }
        
        function addStatusMessage(message, type) {
            const statusDiv = document.getElementById('status-messages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `status-message status-${type}`;
            messageDiv.textContent = message;
            
            // Insert new message at the top
            if (statusDiv.firstChild) {
                statusDiv.insertBefore(messageDiv, statusDiv.firstChild);
            } else {
                statusDiv.appendChild(messageDiv);
            }
            
            // Scroll to top to show the newest message
            statusDiv.scrollTop = 0;
        }
        
        function clearStatusMessages() {
            document.getElementById('status-messages').innerHTML = '';
        }
        
        function hidePreview() {
            const previewContainer = document.querySelector('.preview-container');
            previewContainer.innerHTML = '<div id="no-preview" style="color: var(--text-muted);">No preview available. Generate a model to see it here.</div>';
        }
    </script>
</body>
</html>
    <?php
}
?>
