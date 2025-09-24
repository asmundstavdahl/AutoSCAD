<?php

// Config
$api_key = getenv("OPENROUTER_API_KEY");
$base_url = "https://openrouter.ai/api/v1/chat/completions";
$llm_name = "google/gemma-3-27b-it";

// Helper: read image and convert to base64
function image_to_base64($path)
{
    if (file_exists($path)) {
        $data = file_get_contents($path);
        return base64_encode($data);
    }
    return "";
}

// Helper: clean SCAD code from markdown blocks
function clean_scad_code($code)
{
    $code = trim($code);
    if (preg_match('/^```scad\s*\n(.*)\n```$/s', $code, $matches)) {
        return $matches[1];
    }
    return $code;
}

// Helper: call OpenRouter LLM
function call_llm($messages)
{
    global $api_key, $base_url, $llm_name;
    if (!$api_key) {
        return "API key not set.";
    }
    $payload = [
        "model" => $llm_name,
        "messages" => $messages,
    ];
    $ch = curl_init($base_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $api_key",
        "Content-Type: application/json",
        "HTTP-Referer: https://github.com/asmundstavdahl/AutoSCAD",
        "X-Title: AutoSCAD",
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $result = curl_exec($ch);
    if ($result === false) {
        return "cURL Error: " . curl_error($ch);
    }
    curl_close($ch);
    $data = json_decode($result, true);
    return $data["choices"][0]["message"]["content"] ?? "No response.";
}

// Function to run the SCAD generation loop
function run_scad_generation($spec_doc, $initial_scad_code, $output_callback = null, $max_iterations = 3)
{
    $scad_code = $initial_scad_code;
    $original_scad_code = $scad_code;
    $iteration = 0;
    $spec_fulfilled = false;

    while (!$spec_fulfilled && $iteration < $max_iterations) {
        $iteration++;
        if ($output_callback) $output_callback('iteration', ['iteration' => $iteration, 'message' => "Starting iteration $iteration"]);

        // Render
        $errors = [];
        exec("openscad -o render.png model.scad 2>&1", $errors, $returnCode);
        $render_base64 = image_to_base64("render.png");
        if (file_exists("render.png")) {
            unlink("render.png");
        }
        $image_data_uri = 'data:image/png;base64,' . $render_base64;
        $error_output = $returnCode !== 0 ? "OpenSCAD Errors:\n" . implode("\n", $errors) : "";
        if ($output_callback) $output_callback('render', ['image' => $image_data_uri, 'errors' => $error_output]);

        // Evaluate
        $eval = call_llm([
            ["role" => "system", "content" => "You are the Evaluator of AutoSCAD. Your job is to evaluate the provided SCAD code and its rendered model against the specification. Evaluate if the specification is fully satisfied. Answer only YES or NO."],
            ["role" => "user", "content" => [
                ["type" => "text", "text" => "Specification:\n$spec_doc\n\nRendered model:"],
                ["type" => "image_url", "image_url" => ["url" => $image_data_uri]]
            ]],
        ]);
        $eval_parts = explode("\n", $eval);
        $eval_line = $eval_parts[0] ?? $eval;
        $explanation = $eval_parts[1] ?? "";
        if ($output_callback) $output_callback('eval', ['result' => $eval_line, 'explanation' => $explanation]);
        $spec_fulfilled = stripos($eval_line, "yes") !== false;
        if ($spec_fulfilled) {
            if ($output_callback) $output_callback('done', ['message' => 'Specification fulfilled after iteration ' . $iteration]);
            break;
        }

        // Plan
        $plan = call_llm([
            ["role" => "system", "content" => "You are an expert SCAD engineer."],
            ["role" => "user", "content" => [
                ["type" => "text", "text" => "Specification:\n$spec_doc\n\nCurrent SCAD code:\n$scad_code\n\n$error_output\n\nUse the image and error messages to make a concrete plan to modify the SCAD code. Provide the plan as JSON steps."],
                ["type" => "image_url", "image_url" => ["url" => $image_data_uri]]
            ]],
        ]);
        if ($output_callback) $output_callback('plan', ['plan' => $plan]);

        // Generate new SCAD
        $scad_code = clean_scad_code(call_llm([
            ["role" => "system", "content" => "You are a SCAD code generator. Follow these rules:\n1. ONLY output valid SCAD syntax\n2. NEVER add markdown formatting\n3. PRESERVE existing functionality\n4. IMPLEMENT changes from the plan\n5. FIX ALL these errors:\n$error_output\n6. NEVER create recursive modules\n7. ALWAYS use correct function arguments"],
            ["role" => "user", "content" => "Specification:\n$spec_doc\n\nCurrent SCAD code:\n$scad_code\n\nPlan:\n$plan\n\nGenerate ONLY valid SCAD code that fixes these errors:"],
        ]));
        file_put_contents("model.scad", $scad_code);
        if ($output_callback) $output_callback('scad', ['code' => $scad_code]);
    }

    if (!$spec_fulfilled) {
        if ($output_callback) $output_callback('scad', ['code' => $scad_code]);
        $errors = [];
        exec("openscad -o render.png model.scad 2>&1", $errors, $returnCode);
        $render_base64 = image_to_base64("render.png");
        if (file_exists("render.png")) {
            unlink("render.png");
        }
        $image_data_uri = 'data:image/png;base64,' . $render_base64;
        $error_output = $returnCode !== 0 ? "OpenSCAD Errors:\n" . implode("\n", $errors) : "";
        if ($output_callback) $output_callback('render', ['image' => $image_data_uri, 'errors' => $error_output]);
        if ($output_callback) $output_callback('done', ['message' => 'Max iterations reached']);
    }

    return $scad_code;
}

?>