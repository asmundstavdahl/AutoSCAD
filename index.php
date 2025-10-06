<?php

// Main web interface for AutoSCAD
// Handles web requests, AJAX, and Server-Sent Events

declare(strict_types=1);

require_once 'common.php';

// Initialize database
$pdo = initialize_database();

// Handle different request types
$request_method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'index';

try {
    switch ($action) {
        case 'index':
            // Serve the HTML interface
            serve_html_interface();
            break;
        case 'create_project':
        case 'get_projects':
        case 'update_project':
        case 'get_iterations':
        case 'generate_scad':
            // API endpoints return JSON
            header('Content-Type: application/json');
            handle_api_request($pdo, $action);
            break;
        case 'sse':
            handle_sse($pdo);
            break;
        default:
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// Handler functions
function handle_create_project(PDO $pdo): void
{
    $name = $_POST['name'] ?? generate_project_name();

    if (empty(trim($name))) {
        throw new Exception('Project name cannot be empty');
    }

    $project_id = create_project($pdo, $name);

    echo json_encode([
        'success' => true,
        'project_id' => $project_id,
        'name' => $name
    ]);
}

function handle_get_projects(PDO $pdo): void
{
    $projects = get_projects($pdo);
    echo json_encode(['projects' => $projects]);
}

function handle_update_project(PDO $pdo): void
{
    $project_id = (int)($_POST['project_id'] ?? 0);
    $name = $_POST['name'] ?? '';

    if (!$project_id) {
        throw new Exception('Project ID is required');
    }

    if (empty(trim($name))) {
        throw new Exception('Project name cannot be empty');
    }

    $success = update_project_name($pdo, $project_id, $name);

    echo json_encode([
        'success' => $success,
        'project_id' => $project_id,
        'name' => $name
    ]);
}

function handle_get_iterations(PDO $pdo): void
{
    $project_id = (int)($_GET['project_id'] ?? 0);

    if (!$project_id) {
        throw new Exception('Project ID is required');
    }

    $iterations = get_iterations($pdo, $project_id);

    echo json_encode([
        'iterations' => $iterations,
        'project_id' => $project_id
    ]);
}

function handle_generate_scad(PDO $pdo): void
{
    $project_id = (int)($_POST['project_id'] ?? 0);
    $spec = $_POST['spec'] ?? '';
    $scad_code = $_POST['scad_code'] ?? '';

    if (!$project_id) {
        throw new Exception('Project ID is required');
    }

    // Validate inputs
    $spec_errors = validate_spec($spec);
    if (!empty($spec_errors)) {
        throw new Exception('Spec validation failed: ' . implode(', ', $spec_errors));
    }

    // Check if this is the first iteration of the project
    $iterations = get_iterations($pdo, $project_id);
    $is_first_iteration = empty($iterations);

    // For first iteration, allow empty SCAD code (auto-generate from spec only)
    // For subsequent iterations, require SCAD code
    if (!$is_first_iteration && empty(trim($scad_code))) {
        throw new Exception('SCAD code is required for existing projects. For new projects, leave the SCAD field empty to auto-generate.');
    }

    // Validate SCAD code only if it's provided
    if (!empty(trim($scad_code))) {
        $scad_errors = validate_scad_code($scad_code);
        if (!empty($scad_errors)) {
            throw new Exception('SCAD code validation failed: ' . implode(', ', $scad_errors));
        }
    }

    // Check dependencies
    $dependency_errors = check_dependencies();
    if (!empty($dependency_errors)) {
        throw new Exception('Dependencies not met: ' . implode(', ', $dependency_errors));
    }

    $api_key = get_api_key();
    if (!$api_key) {
        throw new Exception('API key not available');
    }

    // Create initial iteration
    $iteration_id = create_iteration($pdo, $project_id, $spec, $scad_code);

    echo json_encode([
        'success' => true,
        'iteration_id' => $iteration_id,
        'message' => 'Generation started'
    ]);
}

function handle_sse(PDO $pdo): void
{
    // Set headers for Server-Sent Events
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Cache-Control');

    // Get parameters
    $project_id = (int)($_GET['project_id'] ?? 0);
    $iteration_id = (int)($_GET['iteration_id'] ?? 0);

    if (!$project_id || !$iteration_id) {
        echo "data: " . json_encode(['error' => 'Project ID and Iteration ID required']) . "\n\n";
        ob_flush();
        flush();
        return;
    }

    $api_key = get_api_key();
    if (!$api_key) {
        echo "data: " . json_encode(['error' => 'API key not available']) . "\n\n";
        ob_flush();
        flush();
        return;
    }

    // Send initial status
    echo "data: " . json_encode(['status' => 'started', 'iteration_id' => $iteration_id]) . "\n\n";
    ob_flush();
    flush();

    // Get current iteration
    $iteration = get_iteration($pdo, $iteration_id);
    if (!$iteration) {
        echo "data: " . json_encode(['error' => 'Iteration not found']) . "\n\n";
        ob_flush();
        flush();
        return;
    }

    // Start generation loop
    $current_code = $iteration['scad_code'];
    $current_spec = $iteration['spec'];

    for ($i = 0; $i < MAX_ITERATIONS; $i++) {
        // Send iteration status
        echo "data: " . json_encode([
            'status' => 'iteration',
            'iteration' => $i + 1,
            'max_iterations' => MAX_ITERATIONS
        ]) . "\n\n";
        ob_flush();
        flush();

        // Render current SCAD code
        $image_dir = sys_get_temp_dir() . '/autoscad_images_' . $project_id;
        if (!is_dir($image_dir)) {
            mkdir($image_dir, 0755, true);
        }

        $image_file = $image_dir . '/iteration_' . $iteration_id . '_v' . ($i + 1) . '.png';
        $render_success = render_scad_to_image($current_code, $image_file);

        if (!$render_success) {
            echo "data: " . json_encode(['error' => 'Failed to render SCAD code']) . "\n\n";
            ob_flush();
            flush();
            break;
        }

        // Send render status
        echo "data: " . json_encode([
            'status' => 'rendered',
            'image_file' => basename($image_file)
        ]) . "\n\n";
        ob_flush();
        flush();

        // Evaluate current result
        $evaluation_prompt = sprintf(
            "Evaluate this OpenSCAD code against the specification.\n\n" .
            "Specification: %s\n\n" .
            "Current SCAD code:\n%s\n\n" .
            "Does this SCAD code fulfill the specification? Answer YES or NO only, no explanation.",
            $current_spec,
            $current_code
        );

        $evaluation = call_llm_api($evaluation_prompt, $api_key);

        if (!$evaluation) {
            echo "data: " . json_encode(['error' => 'Failed to evaluate code']) . "\n\n";
            ob_flush();
            flush();
            break;
        }

        $evaluation = trim(strtoupper($evaluation));

        if ($evaluation === 'YES') {
            echo "data: " . json_encode([
                'status' => 'complete',
                'message' => 'Specification fulfilled'
            ]) . "\n\n";
            ob_flush();
            flush();
            break;
        }

        // Send evaluation status
        echo "data: " . json_encode([
            'status' => 'evaluating',
            'result' => $evaluation
        ]) . "\n\n";
        ob_flush();
        flush();

        // Plan improvements
        $planning_prompt = sprintf(
            "The current OpenSCAD code does not fulfill the specification. " .
            "Create a concrete plan as JSON steps to modify the code.\n\n" .
            "Specification: %s\n\n" .
            "Current SCAD code:\n%s\n\n" .
            "Provide a JSON array of improvement steps, each with 'action' and 'description' fields.",
            $current_spec,
            $current_code
        );

        $plan_response = call_llm_api($planning_prompt, $api_key);

        if (!$plan_response) {
            echo "data: " . json_encode(['error' => 'Failed to plan improvements']) . "\n\n";
            ob_flush();
            flush();
            break;
        }

        $plan = json_decode($plan_response, true);

        if (!$plan || !is_array($plan)) {
            echo "data: " . json_encode(['error' => 'Invalid plan format']) . "\n\n";
            ob_flush();
            flush();
            break;
        }

        // Send plan status
        echo "data: " . json_encode([
            'status' => 'planning',
            'plan' => $plan
        ]) . "\n\n";
        ob_flush();
        flush();

        // Generate new code
        $generation_prompt = sprintf(
            "Improve the OpenSCAD code to better fulfill the specification.\n\n" .
            "Specification: %s\n\n" .
            "Current SCAD code:\n%s\n\n" .
            "Improvement plan: %s\n\n" .
            "Generate valid OpenSCAD code that addresses the plan. " .
            "Ensure the code is clean and contains no markdown formatting.",
            $current_spec,
            $current_code,
            json_encode($plan)
        );

        $new_code = call_llm_api($generation_prompt, $api_key);

        if (!$new_code) {
            echo "data: " . json_encode(['error' => 'Failed to generate new code']) . "\n\n";
            ob_flush();
            flush();
            break;
        }

        // Clean the generated code
        $new_code = trim($new_code);
        $new_code = preg_replace('/^```(?:openscad)?\n?/i', '', $new_code);
        $new_code = preg_replace('/\n?```$/', '', $new_code);

        // Validate new code
        $new_code_errors = validate_scad_code($new_code);
        if (!empty($new_code_errors)) {
            echo "data: " . json_encode([
                'error' => 'Generated code validation failed: ' . implode(', ', $new_code_errors)
            ]) . "\n\n";
            ob_flush();
            flush();
            break;
        }

        // Update current code
        $current_code = $new_code;

        // Create new iteration
        $new_iteration_id = create_iteration($pdo, $project_id, $current_spec, $current_code);

        // Send new code status
        echo "data: " . json_encode([
            'status' => 'new_code',
            'new_iteration_id' => $new_iteration_id,
            'scad_code' => $new_code
        ]) . "\n\n";
        ob_flush();
        flush();

        // Update iteration_id for next loop
        $iteration_id = $new_iteration_id;
    }

    // Send final status
    if ($i >= MAX_ITERATIONS) {
        echo "data: " . json_encode([
            'status' => 'max_iterations_reached',
            'message' => 'Maximum iterations reached'
        ]) . "\n\n";
    }

    ob_flush();
    flush();
}

// Serve the HTML interface
function serve_html_interface(): void
{
    // Read and output the HTML file
    if (file_exists('index.html')) {
        readfile('index.html');
    } else {
        http_response_code(404);
        echo 'HTML interface file not found';
    }
}

// Handle API requests
function handle_api_request(PDO $pdo, string $action): void
{
    switch ($action) {
        case 'create_project':
            handle_create_project($pdo);
            break;
        case 'get_projects':
            handle_get_projects($pdo);
            break;
        case 'update_project':
            handle_update_project($pdo);
            break;
        case 'get_iterations':
            handle_get_iterations($pdo);
            break;
        case 'generate_scad':
            handle_generate_scad($pdo);
            break;
    }
}
