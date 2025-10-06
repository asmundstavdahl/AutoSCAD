<?php
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

// Render SCAD code to PNG from 6 directions
function render_scad($scad_code) {
    $temp_dir = sys_get_temp_dir();
    $scad_file = tempnam($temp_dir, 'autoscad_') . '.scad';
    file_put_contents($scad_file, $scad_code);
    
    // Define the 6 camera views
    $views = [
        'front' => '--camera=0,0,0,55,0,25,500',
        'back' => '--camera=0,0,0,235,0,25,500',
        'left' => '--camera=0,0,0,145,0,25,500',
        'right' => '--camera=0,0,0,325,0,25,500',
        'top' => '--camera=0,0,0,0,90,500',
        'bottom' => '--camera=0,0,0,0,-90,500'
    ];
    
    $images = [];
    
    foreach ($views as $view_name => $camera_params) {
        $png_file = tempnam($temp_dir, "autoscad_{$view_name}_") . '.png';
        
        // Run OpenSCAD to render with specific camera parameters
        $command = "openscad -o " . escapeshellarg($png_file) . " " . $camera_params . " " . escapeshellarg($scad_file);
        exec($command . " 2>&1", $output, $return_code);
        
        if ($return_code !== 0) {
            // Clean up on error
            unlink($scad_file);
            foreach ($images as $temp_png_file) {
                if (file_exists($temp_png_file)) {
                    unlink($temp_png_file);
                }
            }
            return ['error' => "OpenSCAD rendering failed for $view_name view: " . implode("\n", $output)];
        }
        
        if (!file_exists($png_file)) {
            // Clean up on error
            unlink($scad_file);
            foreach ($images as $temp_png_file) {
                if (file_exists($temp_png_file)) {
                    unlink($temp_png_file);
                }
            }
            return ['error' => "Rendered image not found for $view_name view"];
        }
        
        $images[$view_name] = [
            'data' => base64_encode(file_get_contents($png_file)),
            'file' => $png_file
        ];
    }
    
    // Clean up
    unlink($scad_file);
    foreach ($images as $view_name => $image_info) {
        unlink($image_info['file']);
        $images[$view_name] = $image_info['data'];
    }
    
    return ['images' => $images];
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
