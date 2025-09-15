<?php

// Config
$apiKey = getenv("OPENROUTER_API_KEY");
$baseUrl = "https://openrouter.ai/api/v1/chat/completions";

// Load files
$specDoc  = file_get_contents("spec.md");
$scadCode = file_get_contents("model.scad");

// Helper: read image and convert to base64
function imageToBase64($path) {
    $data = file_get_contents($path);
    return base64_encode($data);
}

// Helper: call OpenRouter LLM
function callLLM($messages, $model = "openai/gpt-4o-mini") {
    global $apiKey, $baseUrl;

    $payload = [
        "model" => $model,
        "messages" => $messages,
    ];

    $ch = curl_init($baseUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json",
        "HTTP-Referer: http://localhost",
        "X-Title: SCAD Agent",
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $result = curl_exec($ch);
    if ($result === false) {
        throw new Exception("cURL Error: " . curl_error($ch));
    }
    curl_close($ch);

    $data = json_decode($result, true);
    return $data["choices"][0]["message"]["content"] ?? "";
}

// Agent loop
$specFulfilled = false;
$maxIters = 5;

for ($i = 0; $i < $maxIters; $i++) {
    echo "\n--- Iteration ".($i+1)." ---\n";

    // Step 1: Render current SCAD code
    file_put_contents("model.scad", $scadCode);
    exec("openscad -o render.png model.scad"); 
    $renderBase64 = imageToBase64("render.png");

    // Step 2: Make a plan
    $dataURI = 'data:image/png;base64,' . $renderBase64;
    $plan = callLLM([
        ["role" => "system", "content" => "You are an expert SCAD engineer."],
        ["role" => "user", "content" => "Specification:\n$specDoc\n\nCurrent SCAD code:\n$scadCode\n\nRendered model (base64 PNG):\n$dataURI\n\nUse the image to make a concrete plan to modify the SCAD code to fulfill the specification. Provide the plan as JSON steps."]
    ]);
    echo "Plan:\n$plan\n";

    // Step 3: Generate new SCAD code
    $scadCode = callLLM([
        ["role" => "system", "content" => "You are a SCAD code generator. Follow these rules:\n1. ONLY output valid SCAD syntax\n2. NEVER add markdown formatting\n3. PRESERVE existing functionality\n4. IMPLEMENT changes from the plan"],
        ["role" => "user", "content" => "Specification:\n$specDoc\n\nCurrent SCAD code:\n$scadCode\n\nPlan:\n$plan\n\nGenerate ONLY valid SCAD code without any additional text:"]
    ]);
    
    // Basic syntax validation
    if (!str_starts_with(trim($scadCode), 'module') && !str_starts_with(trim($scadCode), 'function') && !str_contains($scadCode, '();')) {
        echo "⚠️ Generated code appears invalid. Reverting to previous version.\n";
        $scadCode = file_get_contents("model.scad");
    }

    // Step 4: Evaluate
    exec("openscad -o render.png model.scad"); 
    $renderBase64 = imageToBase64("render.png");
    $dataURI = 'data:image/png;base64,' . $renderBase64;
    $eval = callLLM([
        ["role" => "system", "content" => "You are a strict evaluator."],
        ["role" => "user", "content" => "Specification:\n$specDoc\n\nRendered model (base64 PNG):\n$dataURI\n\nEvaluate if the specification is fully satisfied. Answer only YES or NO."]
    ]);

    echo "Evaluation: $eval\n";
    $specFulfilled = stripos($eval, "yes") !== false;
    if ($specFulfilled) {
        echo "✅ Specification fulfilled!\n";
        break;
    }
}

if (!$specFulfilled) {
    echo "⚠️ Max iterations reached without fulfilling spec.\n";
}

