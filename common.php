<?php
// Database setup
function get_db()
{
    $db = new PDO('sqlite:autoscad.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

// Initialize database tables
function init_db()
{
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
function check_openscad()
{
    $output = shell_exec('which openscad');
    return !empty($output);
}

// Get API key
function get_api_key()
{
    return getenv('OPENROUTER_API_KEY');
}

// Render SCAD code to PNG from 6 directions with axis cross
function render_scad($scad_code)
{
    $temp_dir = sys_get_temp_dir();
    $scad_file = tempnam($temp_dir, 'autoscad_') . '.scad';
    
    // Add axis cross to the SCAD code with a unique module name
    $axis_cross_code = "
// AutoSCAD Axis cross (X=red, Y=green, Z=blue)
module autoscad_axis_cross(size=15) {
    // X-axis (red)
    color(\"red\") {
        translate([size/2, 0, 0]) 
            cube([size, 0.5, 0.5], center=true);
        translate([size, 0, 0]) 
            rotate([0, 0, 45]) 
            cube([1.5, 0.3, 0.3], center=true);
    }
    // Y-axis (green)
    color(\"green\") {
        translate([0, size/2, 0]) 
            cube([0.5, size, 0.5], center=true);
        translate([0, size, 0]) 
            rotate([0, 0, 45]) 
            cube([0.3, 1.5, 0.3], center=true);
    }
    // Z-axis (blue)
    color(\"blue\") {
        translate([0, 0, size/2]) 
            cube([0.5, 0.5, size], center=true);
        translate([0, 0, size]) 
            rotate([45, 0, 0]) 
            cube([0.3, 0.3, 1.5], center=true);
    }
}
";
    
    // Combine the axis cross with the user's code
    $combined_scad_code = $axis_cross_code . "\n" . $scad_code . "\nautoscad_axis_cross();";
    file_put_contents($scad_file, $combined_scad_code);

    // Define the 7 camera views using Euler angles in degrees
    // The camera looks towards the origin from the specified angles
    $views = [
        'default' => '--camera=0,0,0,55,0,25,100', // Default isometric view
        'front' => '--camera=0,0,10,0,0,0,50',
        'back' => '--camera=0,0,10,0,180,0,50',
        'left' => '--camera=0,0,10,0,90,0,50',
        'right' => '--camera=0,0,10,0,270,0,50',
        'top' => '--camera=0,0,10,90,0,0,50',
        'bottom' => '--camera=0,0,10,270,0,0,50'
    ];

    $images = [];

    foreach ($views as $view_name => $camera_params) {
        $png_file = tempnam($temp_dir, "autoscad_{$view_name}_") . '.png';

        // Run OpenSCAD to render with specific camera parameters, --viewall and --autocenter
        $command = "openscad -o " . escapeshellarg($png_file) . " " . $camera_params . " --viewall --autocenter --view axes " . escapeshellarg($scad_file);
        exec($command . " 2>&1", $output, $return_code);

        // Check if the file was created and has content
        if ($return_code !== 0 || !file_exists($png_file) || filesize($png_file) === 0) {
            // Clean up on error
            unlink($scad_file);
            foreach ($images as $temp_png_info) {
                if (file_exists($temp_png_info['file'])) {
                    unlink($temp_png_info['file']);
                }
            }
            $error_msg = "OpenSCAD rendering failed for $view_name view: " . implode("\n", $output);
            if (file_exists($png_file)) {
                if (filesize($png_file) === 0) {
                    $error_msg .= " (File exists but is empty)";
                } else {
                    // Check if it's a valid PNG file
                    $image_info = getimagesize($png_file);
                    if ($image_info === false) {
                        $error_msg .= " (File is not a valid image)";
                    }
                }
            }
            return ['error' => $error_msg];
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
function call_llm($messages, $images = [])
{
    $api_key = get_api_key();
    if (!$api_key) {
        return ['error' => 'OPENROUTER_API_KEY environment variable not set'];
    }

    // Prepare messages with multimodal content if images are provided
    $formatted_messages = [];
    foreach ($messages as $message) {
        // If there are images and this is a user message, we need to structure the content differently
        if ($message['role'] === 'user' && !empty($images)) {
            $content = [];

            // Add text content first
            if (isset($message['content'])) {
                $content[] = [
                    'type' => 'text',
                    'text' => $message['content']
                ];
            }

            // Add each image
            foreach ($images as $view_name => $image_data) {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:image/png;base64," . $image_data
                    ]
                ];
            }

            $formatted_messages[] = [
                'role' => $message['role'],
                'content' => $content
            ];
        } else {
            // Regular text message
            $formatted_messages[] = [
                'role' => $message['role'],
                'content' => $message['content']
            ];
        }
    }

    $data = [
        'model' => 'google/gemma-3-27b-it',
        'messages' => $formatted_messages,
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
