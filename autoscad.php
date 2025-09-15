<?php

// Config
$apiKey = getenv("OPENROUTER_API_KEY");
$baseUrl = "https://openrouter.ai/api/v1/chat/completions";
$llmName = "google/gemini-2.5-flash";

// Load files
$specDoc  = file_get_contents("spec.md");
if (!file_exists("model.scad")) {
    touch("model.scad");
}
$scadCode = file_get_contents("model.scad");
$originalScadCode = $scadCode;

// Helper: read image and convert to base64
function imageToBase64($path)
{
    $data = file_get_contents($path);
    return base64_encode($data);
}

// Helper: call OpenRouter LLM
function callLLM($messages)
{
    global $apiKey, $baseUrl, $llmName;

    $payload = [
        "model" => $llmName,
        "messages" => $messages,
    ];

    $ch = curl_init($baseUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json",
        "HTTP-Referer: https://github.com/asmundstavdahl/AutoSCAD",
        "X-Title: AutoSCAD",
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

// Step 1: Render
echo "Rendering…\n";
$errors = [];
exec("openscad -o render.png model.scad 2>&1", $errors, $returnCode);
$renderBase64 = imageToBase64("render.png");
unlink("render.png");
$imageDataURI = 'data:image/png;base64,' . $renderBase64;
$errorOutput = $returnCode !== 0 ? "OpenSCAD Errors:\n" . implode("\n", $errors) : "";

// Step 2: Evaluate
echo "Evaluating… ";
$eval = callLLM([
    ["role" => "system", "content" => "You are the Evaluator of AutoSCAD. Your job is to evaluate the provided SCAD code and its rendered model against the specification. Evaluate if the specification is fully satisfied. Answer only YES or NO."],
    ["role" => "user", "content" => "Specification:\n$specDoc\n\nRendered model (base64 PNG):\n$imageDataURI\n\nEvaluate if the specification is fully satisfied. Answer only YES or NO on the first line, followed by a concise explanation on the second line. Only respond with those two lines."],
]);

[$eval, $explanation] = explode("\n", $eval);
echo "Is specification fulfilled: $eval ($explanation)\n";
$specFulfilled = stripos($eval, "yes") !== false;
if ($specFulfilled) {
    exit(0);
}

// Step 3: Make a plan
echo "Planning…\n";
$plan = callLLM([
    ["role" => "system", "content" => "You are an expert SCAD engineer."],
    ["role" => "user", "content" => "Specification:\n$specDoc\n\nCurrent SCAD code:\n$scadCode\n\nRendered model (base64 PNG):\n$imageDataURI\n\n$errorOutput\n\nUse the image and error messages to make a concrete plan to modify the SCAD code. Provide the plan as JSON steps."]
]);

// Step 4: Generate new SCAD code
echo "Creating…\n";
$scadCode = callLLM([
    ["role" => "system", "content" => "You are a SCAD code generator. Follow these rules:\n1. ONLY output valid SCAD syntax\n2. NEVER add markdown formatting\n3. PRESERVE existing functionality\n4. IMPLEMENT changes from the plan\n5. FIX ALL these errors:\n$errorOutput\n6. NEVER create recursive modules\n7. ALWAYS use correct function arguments"],
    ["role" => "user", "content" => "Specification:\n$specDoc\n\nCurrent SCAD code:\n$scadCode\n\nPlan:\n$plan\n\nGenerate ONLY valid SCAD code that fixes these errors:"]
]);
file_put_contents("model.scad", $scadCode);

echo "Diff:          \n";
$originalScadCodeFile = tempnam(sys_get_temp_dir(), "autoscad-prev-version");
file_put_contents($originalScadCodeFile, $originalScadCode);
exec("diff $originalScadCodeFile model.scad", $diffLines);
unlink($originalScadCodeFile);
echo implode("\n", $diffLines);
exit(1);
