<?php

// Database connection and utility functions for AutoSCAD
// Follows AGENTS.md coding standards: snake_case, 4 spaces, pure functions

declare(strict_types=1);

// Database configuration
const DATABASE_FILE = 'autoscad.db';
const MAX_ITERATIONS = 3;
const LLM_MODEL = 'google/gemma-3-27b-it';
const OPENROUTER_BASE_URL = 'https://openrouter.ai/api/v1/chat/completions';

// Initialize database connection
function initialize_database(): PDO
{
    $pdo = new PDO('sqlite:' . DATABASE_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create tables if they don't exist
    $create_projects_table = "
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ";

    $create_iterations_table = "
        CREATE TABLE IF NOT EXISTS iterations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            spec TEXT NOT NULL,
            scad_code TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        )
    ";

    $pdo->exec($create_projects_table);
    $pdo->exec($create_iterations_table);

    return $pdo;
}

// Project management functions
function create_project(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare('INSERT INTO projects (name) VALUES (?)');
    $stmt->execute([$name]);
    return (int)$pdo->lastInsertId();
}

function get_projects(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM projects ORDER BY created_at DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_project(PDO $pdo, int $project_id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$project_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function update_project_name(PDO $pdo, int $project_id, string $name): bool
{
    $stmt = $pdo->prepare('UPDATE projects SET name = ? WHERE id = ?');
    return $stmt->execute([$name]);
}

// Iteration management functions
function create_iteration(PDO $pdo, int $project_id, string $spec, string $scad_code): int
{
    $stmt = $pdo->prepare('INSERT INTO iterations (project_id, spec, scad_code) VALUES (?, ?, ?)');
    $stmt->execute([$project_id, $spec, $scad_code]);
    return (int)$pdo->lastInsertId();
}

function get_iterations(PDO $pdo, int $project_id): array
{
    $stmt = $pdo->prepare('SELECT * FROM iterations WHERE project_id = ? ORDER BY created_at DESC');
    $stmt->execute([$project_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_iteration(PDO $pdo, int $iteration_id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM iterations WHERE id = ?');
    $stmt->execute([$iteration_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// OpenSCAD rendering functions
function render_scad_to_image(string $scad_code, string $output_file): bool
{
    $temp_file = sys_get_temp_dir() . '/autoscad_' . uniqid() . '.scad';

    // Write SCAD code to temporary file
    if (file_put_contents($temp_file, $scad_code) === false) {
        return false;
    }

    // Render using OpenSCAD
    $command = sprintf(
        'openscad -o %s --imgsize=800,600 --camera=0,0,0,0,0,0,200 %s 2>&1',
        escapeshellarg($output_file),
        escapeshellarg($temp_file)
    );

    exec($command, $output, $return_code);

    // Clean up temporary file
    unlink($temp_file);

    return $return_code === 0;
}

function get_rendered_images(int $project_id): array
{
    $images = [];
    $image_dir = sys_get_temp_dir() . '/autoscad_images_' . $project_id;

    if (is_dir($image_dir)) {
        $files = scandir($image_dir);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'png') {
                $images[] = $image_dir . '/' . $file;
            }
        }
    }

    return $images;
}

// LLM API functions
function call_llm_api(string $prompt, string $api_key): ?string
{
    $data = [
        'model' => LLM_MODEL,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'max_tokens' => 2000
    ];

    $ch = curl_init(OPENROUTER_BASE_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: AutoSCAD'
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return null;
    }

    $result = json_decode($response, true);
    return $result['choices'][0]['message']['content'] ?? null;
}

// Validation functions
function validate_spec(string $spec): array
{
    $errors = [];

    if (empty(trim($spec))) {
        $errors[] = 'Specification cannot be empty';
    }

    if (strlen($spec) > 10000) {
        $errors[] = 'Specification is too long (max 10000 characters)';
    }

    return $errors;
}

function validate_scad_code(string $scad_code): array
{
    $errors = [];

    if (empty(trim($scad_code))) {
        $errors[] = 'SCAD code cannot be empty';
    }

    // Check for recursive modules (basic check)
    if (preg_match('/module\s+\w+\s*\([^)]*\w+\s*\)\s*{[^}]*\w+\s*\([^)]*\w+\s*\)/', $scad_code)) {
        $errors[] = 'Recursive modules are not supported';
    }

    return $errors;
}

// Utility functions
function generate_project_name(): string
{
    return 'Project_' . date('Y-m-d_H-i-s');
}

function sanitize_filename(string $filename): string
{
    return preg_replace('/[^a-zA-Z0-9\-_.]/', '_', $filename);
}

function get_api_key(): ?string
{
    return getenv('OPENROUTER_API_KEY') ?: null;
}

function check_dependencies(): array
{
    $errors = [];

    // Check OpenSCAD
    if (empty(shell_exec('which openscad'))) {
        $errors[] = 'OpenSCAD is not installed or not in PATH';
    }

    // Check API key
    if (!get_api_key()) {
        $errors[] = 'OPENROUTER_API_KEY environment variable not set';
    }

    return $errors;
}
