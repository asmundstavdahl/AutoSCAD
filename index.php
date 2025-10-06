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
    $db = get_db();
    $name = $_POST['name'] ?? 'Project ' . date('Y-m-d H:i:s');
    
    $stmt = $db->prepare("INSERT INTO projects (name) VALUES (?)");
    $stmt->execute([$name]);
    
    $project_id = $db->lastInsertId();
    
    echo json_encode(['success' => true, 'project_id' => $project_id, 'name' => $name]);
}

function get_projects() {
    $db = get_db();
    $stmt = $db->query("SELECT * FROM projects ORDER BY created_at DESC");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($projects);
}

function update_project_name() {
    $db = get_db();
    $project_id = $_POST['project_id'];
    $name = $_POST['name'];
    
    $stmt = $db->prepare("UPDATE projects SET name = ? WHERE id = ?");
    $stmt->execute([$name, $project_id]);
    
    echo json_encode(['success' => true]);
}

function get_iterations() {
    $db = get_db();
    $project_id = $_GET['project_id'];
    
    $stmt = $db->prepare("SELECT * FROM iterations WHERE project_id = ? ORDER BY created_at DESC");
    $stmt->execute([$project_id]);
    $iterations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($iterations);
}

function generate() {
    // This would be a long-running process, so we'll use SSE to stream updates
    // For now, just return success and the actual processing will happen via SSE
    echo json_encode(['success' => true]);
}

function sse() {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    
    // Get parameters
    $project_id = $_GET['project_id'];
    $spec = $_POST['spec'] ?? '';
    $scad_code = $_POST['scad_code'] ?? '';
    
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
        
        // Prepare evaluation prompt
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an expert in OpenSCAD and 3D modeling. Evaluate if the provided SCAD code fulfills the specification.'
            of the implementation
        ];
        
        // TODO: Add more implementation for the generation loop
        // This is a complex part that needs careful implementation
        
        // For now, break after first iteration
        break;
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
        // JavaScript implementation will go here
        // This will handle AJAX requests, SSE connections, and UI updates
    </script>
</body>
</html>
    <?php
}
?>
