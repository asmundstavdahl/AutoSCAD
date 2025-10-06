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
        // Render current SCAD code
        $render_result = render_scad($scad_code);
        if (isset($render_result['error'])) {
            send_sse_message(['error' => $render_result['error']]);
            break;
        }
        
        send_sse_message(['render_complete' => true, 'image' => $render_result['image']]);
        
        // Evaluate if the spec is fulfilled
        $evaluation_messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert in OpenSCAD and 3D modeling. Evaluate if the provided SCAD code and rendered image fulfill the specification. ' .
                            'Respond with a JSON object: {"fulfilled": true/false, "reasoning": "brief explanation"}'
            ],
            [
                'role' => 'user',
                'content' => "Specification: $spec\n\nSCAD Code:\n```openscad\n$scad_code\n```\n\nIs the specification fulfilled? Answer with JSON only."
            ]
        ];
        
        $evaluation_result = call_llm($evaluation_messages);
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
        
        $plan_result = call_llm($plan_messages);
        if (isset($plan_result['error'])) {
            send_sse_message(['error' => 'Planning failed: ' . $plan_result['error']]);
            break;
        }
        
        $plan_content = $plan_result['content'];
        send_sse_message(['plan' => $plan_content]);
        
        // Generate new SCAD code
        $generate_messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert in OpenSCAD programmer. Write valid SCAD code based on the specification and plan. ' .
                            'Clean any markdown formatting and ensure the code is valid. Respond with only the SCAD code.'
            ],
            [
                'role' => 'user',
                'content' => "Specification: $spec\n\nCurrent SCAD Code:\n```openscad\n$scad_code\n```\n\nPlan: $plan_content\n\nWrite the new SCAD code:"
            ]
        ];
        
        $generate_result = call_llm($generate_messages);
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
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { display: flex; }
        .sidebar { width: 200px; margin-right: 20px; }
        .main { flex-grow: 1; }
        textarea { width: 100%; height: 200px; }
        .iteration-list { max-height: 400px; overflow-y: auto; }
        .iteration-item { padding: 5px; cursor: pointer; border: 1px solid #ccc; margin-bottom: 5px; }
        .iteration-item:hover { background-color: #f0f0f0; }
    </style>
</head>
<body>
    <h1>AutoSCAD</h1>
    <div class="container">
        <div class="sidebar">
            <button id="new-project">New Project</button>
            <select id="project-selector"></select>
            <div id="iteration-list" class="iteration-list"></div>
        </div>
        <div class="main">
            <div>
                <label>Project Name:</label>
                <input type="text" id="project-name" style="width: 100%;">
            </div>
            <div>
                <label>Specification:</label>
                <textarea id="spec"></textarea>
            </div>
            <div>
                <label>SCAD Code:</label>
                <textarea id="scad-code"></textarea>
            </div>
            <button id="generate">Generate</button>
            <div id="result">
                <img id="rendered-image" style="max-width: 100%;">
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
                    selector.innerHTML = '';
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
                    document.getElementById('iteration-list').innerHTML = '';
                }
            });
        }
        
        function onProjectSelected(event) {
            currentProjectId = event.target.value;
            loadProjectData(currentProjectId);
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
                    iterations.forEach(iteration => {
                        const item = document.createElement('div');
                        item.className = 'iteration-item';
                        item.textContent = 'Iteration ' + iteration.id;
                        item.addEventListener('click', () => loadIteration(iteration));
                        list.appendChild(item);
                    });
                    if (iterations.length > 0) {
                        loadIteration(iterations[0]);
                    }
                });
        }
        
        function loadIteration(iteration) {
            currentIterationId = iteration.id;
            document.getElementById('spec').value = iteration.spec;
            document.getElementById('scad-code').value = iteration.scad_code;
            // TODO: Load rendered image if available
        }
        
        function updateProjectName() {
            const newName = document.getElementById('project-name').value;
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
            
            if (!spec) {
                alert('Please enter a specification');
                return;
            }
            
            if (eventSource) {
                eventSource.close();
            }
            
            // Pass spec and scad_code as GET parameters
            const sseUrl = '?action=sse&project_id=' + currentProjectId + 
                      '&spec=' + encodeURIComponent(spec) + 
                      '&scad_code=' + encodeURIComponent(scadCode);
            eventSource = new EventSource(sseUrl);
            eventSource.onmessage = function(event) {
                const data = JSON.parse(event.data);
                console.log(data);
                
                if (data.error) {
                    alert('Error: ' + data.error);
                    eventSource.close();
                } else if (data.iteration_started) {
                    currentIterationId = data.iteration_id;
                } else if (data.render_complete) {
                    document.getElementById('rendered-image').src = 'data:image/png;base64,' + data.image;
                } else if (data.evaluation) {
                    console.log('Evaluation:', data.evaluation);
                } else if (data.plan) {
                    console.log('Plan:', data.plan);
                } else if (data.new_scad_code) {
                    document.getElementById('scad-code').value = data.new_scad_code;
                } else if (data.spec_fulfilled) {
                    alert('Specification fulfilled!');
                } else if (data.generation_complete) {
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
    </script>
</body>
</html>
    <?php
}
?>
