<?php
declare(strict_types=1);

// AutoSCAD backend helpers per SPEC.md

define('DB_PATH', __DIR__ . '/autoscad.db');
define('TMP_DIR', __DIR__ . '/tmp');
define('MAX_ITERATIONS', 3);

// Ensure temporary directory exists
function ensure_dirs(): void
{
    if (!is_dir(TMP_DIR)) {
        mkdir(TMP_DIR, 0777, true);
    }
}

// Database connection
function db_connect(): PDO
{
    $dsn = 'sqlite:' . DB_PATH;
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// Initialize database schema if missing
function init_db(): void
{
    ensure_dirs();
    $pdo = db_connect();
    // projects table
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )"
    );
    // iterations table
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS iterations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER,
            spec TEXT,
            scad_code TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(project_id) REFERENCES projects(id)
        )"
    );
}

// Project helpers
function create_project(string $name): int
{
    $pdo = db_connect();
    init_db();
    $stmt = $pdo->prepare('INSERT INTO projects (name) VALUES (?)');
    $stmt->execute([$name]);
    return (int) $pdo->lastInsertId();
}

function get_projects(): array
{
    $pdo = db_connect();
    init_db();
    $stmt = $pdo->query('SELECT id, name, created_at FROM projects ORDER BY created_at DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_project(int $project_id): ?array
{
    $pdo = db_connect();
    init_db();
    $stmt = $pdo->prepare('SELECT id, name, created_at FROM projects WHERE id = ?');
    $stmt->execute([$project_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Iteration helpers
function new_iteration(int $project_id, string $spec, string $scad_code): int
{
    $pdo = db_connect();
    init_db();
    $stmt = $pdo->prepare('INSERT INTO iterations (project_id, spec, scad_code) VALUES (?, ?, ?)');
    $stmt->execute([$project_id, $spec, $scad_code]);
    return (int) $pdo->lastInsertId();
}

function get_iterations(int $project_id): array
{
    $pdo = db_connect();
    init_db();
    $stmt = $pdo->prepare('SELECT id, spec, scad_code, created_at FROM iterations WHERE project_id = ? ORDER BY created_at DESC');
    $stmt->execute([$project_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_iteration(int $iteration_id): ?array
{
    $pdo = db_connect();
    init_db();
    $stmt = $pdo->prepare('SELECT id, project_id, spec, scad_code, created_at FROM iterations WHERE id = ?');
    $stmt->execute([$iteration_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function update_iteration_scad(int $iteration_id, string $scad_code): void
{
    $pdo = db_connect();
    init_db();
    $stmt = $pdo->prepare('UPDATE iterations SET scad_code = ? WHERE id = ?');
    $stmt->execute([$scad_code, $iteration_id]);
}

// SCAD rendering (best-effort, uses OpenSCAD if available, otherwise creates placeholder PNG)
function render_scad_to_image(string $scad_code, int $iteration_id): string
{
    ensure_dirs();
    $scad_path = TMP_DIR . '/iteration_' . $iteration_id . '.scad';
    $png_path = TMP_DIR . '/iteration_' . $iteration_id . '.png';

    // write SCAD
    file_put_contents($scad_path, $scad_code);

    // try openscad
    $openscad = trim(shell_exec('command -v openscad 2>/dev/null') ?: '');
    if ($openscad !== '') {
        $cmd = escapeshellcmd($openscad) . ' -o ' . escapeshellarg($png_path) . ' ' . escapeshellarg($scad_path);
        $exit = null;
        $output = [];
        exec($cmd, $output, $exit);
        if ($exit === 0 && file_exists($png_path)) {
            return $png_path;
        }
    }

    // fallback: create a tiny placeholder image
    $im = imagecreatetruecolor(200, 200);
    $bg = imagecolorallocate($im, 240, 240, 240);
    $fg = imagecolorallocate($im, 0, 0, 0);
    imagefill($im, 0, 0, $bg);
    $text = 'OpenSCAD placeholder';
    imagestring($im, 3, 10, 90, $text, $fg);
    imagepng($im, $png_path);
    imagedestroy($im);
    return $png_path;
}

// LLM integration (optional)
function llm_chat(array $messages, string $model = 'google/gemma-3-27b-it')
{
    $apiKey = getenv('OPENROUTER_API_KEY');
    if (!$apiKey) {
        return ['error' => 'OPENROUTER_API_KEY not set'];
    }
    $url = 'https://openrouter.ai/api/v1/chat/completions';
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 800,
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) {
        return ['error' => 'LLM API error: ' . $http . ' ' . ($res ?? '')];
    }
    $data = json_decode($res, true);
    if (!$data) {
        return ['error' => 'LLM response not valid JSON'];
    }
    // try to extract content
    if (!empty($data['choices'][0]['message']['content'])) {
        $content = $data['choices'][0]['message']['content'];
        // try to parse JSON inside content
        $json_start = strpos($content, '{');
        if ($json_start !== false) {
            $maybe = substr($content, $json_start);
            $decoded = json_decode($maybe, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
        return ['text' => $content];
    }
    return ['error' => 'LLM response parsing failed'];
}

// Plan executor (very naive): apply simple plan steps to SCAD code
function apply_plan_to_scad(array $plan, string $scad_code): string
{
    foreach ($plan as $step) {
        if (!is_array($step))
            continue;
        $text = isset($step['text']) ? $step['text'] : (isset($step[0]) ? $step[0] : '');
        if (stripos($text, 'add cube') !== false || stripos($text, 'cube(') !== false) {
            // try extract dims
            if (preg_match('/cube\\s*\\(\\s*\\[?([0-9\\.]+)\\s*,\\s*([0-9\\.]+)\\s*,\\s*([0-9\\.]+)\\]?/', $text, $m)) {
                $w = $m[1];
                $h = $m[2];
                $d = $m[3];
                $scad_code .= "\ncube([$w, $h, $d]);";
            } else {
                $scad_code .= "\ncube([10,10,10]);";
            }
        } elseif (stripos($text, 'add cylinder') !== false || stripos($text, 'cylinder') !== false) {
            if (preg_match('/cylinder\\(.*r\\s*=\\s*([0-9\\.]+).*h\\s*=\\s*([0-9\\.]+)/', $text, $m)) {
                $r = $m[1];
                $h = $m[2];
            } else {
                $r = 5;
                $h = 10;
            }
            $scad_code .= "\ncylinder(h=$h, r=$r);";
        } elseif (stripos($text, 'add sphere') !== false || stripos($text, 'sphere') !== false) {
            if (preg_match('/sphere\\(.*r\\s*=\\s*([0-9\\.]+)/', $text, $m)) {
                $r = $m[1];
            } else {
                $r = 5;
            }
            $scad_code .= "\nsphere(r=$r);";
        } else {
            // attach as comment for traceability
            $scad_code .= "\n// plan: $text";
        }
    }
    return $scad_code;
}

// Generate loop orchestrator
function run_generation_loop(int $iteration_id, int $max_iterations = MAX_ITERATIONS): void
{
    $iter = get_iteration($iteration_id);
    if (!$iter)
        return;
    $spec = $iter['spec'] ?? '';
    $scad_code = $iter['scad_code'] ?? '';

    for ($i = 0; $i < $max_iterations; $i++) {
        // render current SCAD
        $image_path = render_scad_to_image($scad_code, $iteration_id);
        // build prompt for LLM
        $messages = [];
        $messages[] = ['role' => 'system', 'content' => 'You are AutoSCAD, an AI assistant that iteratively refines OpenSCAD code to fulfill a given natural language specification.'];
        $messages[] = ['role' => 'user', 'content' => "Spec:\n$spec\n\nCurrent SCAD:\n$scad_code\n\nRendered image: $image_path\n\nProvide a JSON object with keys: fulfill (YES/NO), plan (array of steps, each step as {text: '...'}), updated_scad (optional string with revised SCAD code)."];

        $llm = llm_chat($messages);
        if (isset($llm['error'])) {
            // LLM failure; stop loop and expose error in iteration record
            break;
        }
        // if LLM provided structured data, try to use it
        if (!empty($llm['fulfill']) && (strtoupper((string) $llm['fulfill']) === 'YES' || strtoupper((string) $llm['fulfill']) === 'Y')) {
            // final fulfill, write updated code if provided
            if (!empty($llm['updated_scad'])) {
                $scad_code = $llm['updated_scad'];
                update_iteration_scad($iteration_id, $scad_code);
            }
            break;
        }
        // attempt to parse plan and updated_scad
        $updated = $llm['updated_scad'] ?? null;
        $plan = $llm['plan'] ?? [];
        if (is_string($updated) && $updated !== '') {
            $scad_code = $updated;
            update_iteration_scad($iteration_id, $scad_code);
        } elseif (is_array($plan) && !empty($plan)) {
            $scad_code = apply_plan_to_scad($plan, $scad_code);
            update_iteration_scad($iteration_id, $scad_code);
        }
        // continue to next iteration if not fulfilled
    }
}
?>