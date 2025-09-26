<?php

declare(strict_types=1);

// Database functions
function get_db_connection(): PDO {
    $db_path = __DIR__ . '/autoscad.db';
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function init_database(): void {
    $pdo = get_db_connection();
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS iterations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            spec TEXT NOT NULL,
            scad_code TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id)
        );
    ');
}

// Project functions
function create_project(string $name): int {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('INSERT INTO projects (name) VALUES (?)');
    $stmt->execute([$name]);
    return (int) $pdo->lastInsertId();
}

function get_projects(): array {
    $pdo = get_db_connection();
    $stmt = $pdo->query('SELECT id, name, created_at FROM projects ORDER BY created_at DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function update_project_name(int $project_id, string $name): void {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('UPDATE projects SET name = ? WHERE id = ?');
    $stmt->execute([$name, $project_id]);
}

function get_project(int $project_id): ?array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT id, name, created_at FROM projects WHERE id = ?');
    $stmt->execute([$project_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Iteration functions
function create_iteration(int $project_id, string $spec, ?string $scad_code): int {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('INSERT INTO iterations (project_id, spec, scad_code) VALUES (?, ?, ?)');
    $stmt->execute([$project_id, $spec, $scad_code]);
    return (int) $pdo->lastInsertId();
}

function get_iterations(int $project_id): array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT id, spec, scad_code, created_at FROM iterations WHERE project_id = ? ORDER BY created_at DESC');
    $stmt->execute([$project_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_iteration(int $iteration_id): ?array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT id, project_id, spec, scad_code, created_at FROM iterations WHERE id = ?');
    $stmt->execute([$iteration_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// LLM functions
function call_llm(string $prompt): ?string {
    $api_key = getenv('OPENROUTER_API_KEY');
    if (!$api_key) {
        throw new Exception('OPENROUTER_API_KEY environment variable not set');
    }

    $url = 'https://openrouter.ai/api/v1/chat/completions';
    $data = [
        'model' => 'openai/gpt-4o-mini',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 4096,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        return null;
    }

    $result = json_decode($response, true);
    return $result['choices'][0]['message']['content'] ?? null;
}

// SCAD rendering
function render_scad(string $scad_code): ?string {
    $temp_dir = sys_get_temp_dir();
    $scad_file = tempnam($temp_dir, 'autoscad_') . '.scad';
    $png_file = $scad_file . '.png';

    file_put_contents($scad_file, $scad_code);

    // Try with xvfb-run first, fallback to direct openscad
    $command = "xvfb-run -a openscad --imgsize=512,512 -o \"$png_file\" \"$scad_file\" 2>&1";
    exec($command, $output, $return_var);

    if ($return_var !== 0) {
        // Fallback to direct openscad
        $command = "openscad --imgsize=512,512 -o \"$png_file\" \"$scad_file\" 2>&1";
        exec($command, $output, $return_var);
    }

    unlink($scad_file);

    if ($return_var !== 0 || !file_exists($png_file)) {
        // Return a placeholder image if rendering fails
        $placeholder = base64_encode(file_get_contents(__DIR__ . '/placeholder.png') ?: '');
        if ($placeholder) {
            return $placeholder;
        }
        // If no placeholder, return a small transparent PNG
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    }

    $image_data = file_get_contents($png_file);
    unlink($png_file);

    return base64_encode($image_data);
}

// Utility functions
function sanitize_input(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generate_project_name(): string {
    return date('Y-m-d_H-i-s');
}

// Constants
const MAX_ITERATIONS = 3;

?>