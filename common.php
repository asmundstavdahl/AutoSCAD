<?php
header('Content-Type: application/json');

// Database setup
function get_db() {
    $db = new PDO('sqlite:autoscad.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

// Initialize database tables
function init_db() {
    $db = get_db();
    
    // Create projects table
    $db->exec("CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create iterations table
    $db->exec("CREATE TABLE IF NOT EXISTS iterations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        spec TEXT NOT NULL,
        scad_code TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(project_id) REFERENCES projects(id)
    )");
}

// Check if OpenSCAD is available
function check_openscad() {
    $output = shell_exec('which openscad');
    return !empty($output);
}

// Get API key
function get_api_key() {
    return getenv('OPENROUTER_API_KEY');
}

// Render SCAD code to PNG
function render_scad($scad_code) {
    $temp_dir = sys_get_temp_dir();
    $scad_file = tempnam($temp_dir, 'autoscad_') . '.scad';
    $png_file = tempnam($temp_dir, 'autoscad_') . '.png';
    
    file_put_contents($scad_file, $scad_code);
    
    // Run OpenSCAD to render
    $command = "openscad -o " . escapeshellarg($png_file) . " " . escapeshellarg($scad_file);
    exec($command . " 2>&1", $output, $return_code);
    
    if ($return_code !== 0) {
        unlink($scad_file);
        return ['error' => 'OpenSCAD rendering failed: ' . implode("\n", $output)];
    }
    
    if (!file_exists($png_file)) {
        unlink($scad_file);
        return ['error' => 'Rendered image not found'];
    }
    
    $image_data = base64_encode(file_get_contents($png_file));
    
    // Clean up
    unlink($scad_file);
    unlink($png_file);
    
    return ['image' => $image_data];
}

// Call LLM via OpenRouter
function call_llm($messages) {
    $api_key = get_api_key();
    if (!$api_key) {
        return ['error' => 'OPENROUTER_API_KEY environment variable not set'];
    }
    
    $data = [
        'model' => 'google/gemma-3-27b-it',
        'messages' => $messages,
        'max_tokens' => 4000
    ];
    
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ]
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        return ['error' => 'LLM API request failed with status ' . $http_code . ': ' . $response];
    }
    
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return ['content' => $result['choices'][0]['message']['content']];
    } else {
        return ['error' => 'Invalid response from LLM API'];
    }
}
?>
