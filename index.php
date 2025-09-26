<?php

declare(strict_types=1);

require_once 'common.php';

init_database();

// Handle SSE
if (isset($_GET['sse']) && $_GET['sse'] === '1') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    // Helper to send SSE event with proper handling of multi-line data
    function send_sse_event(string $event, string $data = ''): void
    {
        echo "event: {$event}\n";
        foreach (preg_split('/\R/', $data) as $line) {
            echo "data: {$line}\n";
        }
        echo "\n";
        flush();
    }

    $project_id = (int) ($_GET['project_id'] ?? 0);
    $spec = sanitize_input($_GET['spec'] ?? '');
    $scad_code = $_GET['scad_code'] ?? null;
    if ($scad_code) {
        $scad_code = sanitize_input($scad_code);
    }

    if (!$project_id || !$spec) {
        send_sse_event('error', 'Invalid project or spec');
        exit;
    }

    $iteration_id = create_iteration($project_id, $spec, $scad_code);
    send_sse_event('iteration_start', (string) $iteration_id);

    $iterations = 0;
    while ($iterations < MAX_ITERATIONS) {
        $iterations++;

        // Get current SCAD
        $iteration = get_iteration($iteration_id);
        if (!$iteration)
            break;
        $current_scad = $iteration['scad_code'];

        // Render
        $image_b64 = render_scad($current_scad);
        send_sse_event('render', $image_b64 ?: 'error');

        // Evaluate
        $eval_prompt = "Spec: {$spec}\n\nSCAD Code:\n{$current_scad}\n\nIs the SCAD code fulfilling the specification? Answer only YES or NO.";
        $eval_response = call_llm($eval_prompt);
        $fulfilled = strtoupper(trim($eval_response ?? '')) === 'YES';
        send_sse_event('evaluate', $fulfilled ? 'yes' : 'no');

        if ($fulfilled)
            break;

        // Plan
        $plan_prompt = "Spec: {$spec}\n\nCurrent SCAD Code:\n{$current_scad}\n\nThe code does not fulfill the spec. Provide a concrete plan as JSON array of steps to modify the SCAD code. Respond with valid JSON only, no markdown.";
        $plan_response = call_llm($plan_prompt);
        // Try to decode and re-encode to ensure valid JSON
        $decoded = json_decode($plan_response, true);
        if ($decoded) {
            $plan_response = json_encode($decoded);
        }
        send_sse_event('plan', $plan_response);

        // Generate new code
        $gen_prompt = "Spec: {$spec}\n\nCurrent SCAD Code:\n{$current_scad}\n\nPlan: {$plan_response}\n\nWrite a new version of the SCAD code that fulfills the specification. Ensure it is valid SCAD code, no markdown.";
        $new_scad = call_llm($gen_prompt);
        if ($new_scad) {
            // Update iteration
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('UPDATE iterations SET scad_code = ? WHERE id = ?');
            $stmt->execute([$new_scad, $iteration_id]);
            send_sse_event('code_update', $new_scad);
        }
    }

    send_sse_event('done', 'completed');
    exit;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    if ($action === 'create_project') {
        $name = generate_project_name();
        $id = create_project($name);
        echo json_encode(['id' => $id, 'name' => $name]);
    } elseif ($action === 'update_project_name') {
        $project_id = (int) ($_POST['project_id'] ?? 0);
        $name = sanitize_input($_POST['name'] ?? '');
        if ($project_id && $name) {
            update_project_name($project_id, $name);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Invalid data']);
        }
    } elseif ($action === 'generate') {
        // Generation handled via SSE
        echo json_encode(['success' => true]);
    } elseif ($action === 'render') {
        $scad_code = $_POST['scad_code'] ?? '';
        if ($scad_code) {
            $image_b64 = render_scad($scad_code);
            echo json_encode(['image' => $image_b64]);
        } else {
            echo json_encode(['error' => 'No SCAD code provided']);
        }
    } else {
        echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Handle AJAX GET requests
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $project_id = (int) ($_GET['project_id'] ?? 0);
    $iteration_id = (int) ($_GET['iteration_id'] ?? 0);

    $projects = get_projects();
    $current_project = null;
    $iterations = [];
    $current_iteration = null;

    if ($project_id) {
        $current_project = get_project($project_id);
        $iterations = get_iterations($project_id);
        if ($iteration_id) {
            $current_iteration = get_iteration($iteration_id);
        } elseif ($iterations) {
            $current_iteration = $iterations[0]; // Latest
        }
    }

    echo json_encode([
        'projects' => $projects,
        'current_project' => $current_project,
        'iterations' => $iterations,
        'current_iteration' => $current_iteration,
    ]);
    exit;
}

// Handle GET: serve HTML
$project_id = (int) ($_GET['project_id'] ?? 0);
$iteration_id = (int) ($_GET['iteration_id'] ?? 0);

$projects = get_projects();
$current_project = null;
$iterations = [];
$current_iteration = null;

if ($project_id) {
    $current_project = get_project($project_id);
    $iterations = get_iterations($project_id);
    if ($iteration_id) {
        $current_iteration = get_iteration($iteration_id);
    } elseif ($iterations) {
        $current_iteration = $iterations[0]; // Latest
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>AutoSCAD</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <h1>AutoSCAD</h1>

    <div id="project-section">
        <select id="project-selector">
            <option value="">Select Project</option>
            <?php foreach ($projects as $proj): ?>
                <option value="<?= $proj['id'] ?>" <?= $proj['id'] == $project_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($proj['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button id="new-project-btn">New Project</button>
        <?php if ($current_project): ?>
            <input type="text" id="project-name" value="<?= htmlspecialchars($current_project['name']) ?>">
        <?php endif; ?>
    </div>

    <div id="main-content">
        <div id="iterations">
            <h2>Iterations</h2>
            <ul id="iteration-list">
                <?php foreach ($iterations as $iter): ?>
                    <li data-id="<?= $iter['id'] ?>"
                        class="<?= $iter['id'] == ($current_iteration['id'] ?? 0) ? 'selected' : '' ?>">
                        <?= htmlspecialchars(substr($iter['spec'], 0, 50)) ?>...
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div id="form-section">
            <form id="scad-form">
                <label for="spec">Specification:</label>
                <textarea id="spec" name="spec"
                    rows="5"><?= htmlspecialchars($current_iteration['spec'] ?? '') ?></textarea>

                <label for="scad_code">SCAD Code:</label>
                <textarea id="scad_code" name="scad_code"
                    rows="10"><?= htmlspecialchars($current_iteration['scad_code'] ?? '') ?></textarea>

                <button type="submit">Generate</button>
            </form>

            <div id="render-output">
                <?php if ($current_iteration && $current_iteration['scad_code']): ?>
                    <img id="rendered-image" src="data:image/png;base64,<?= render_scad($current_iteration['scad_code']) ?>"
                        alt="Rendered SCAD">
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
</body>

</html>