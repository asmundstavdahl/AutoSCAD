<?php

// Config
$api_key = getenv("OPENROUTER_API_KEY");
$base_url = "https://openrouter.ai/api/v1/chat/completions";
$llm_name = "google/gemini-2.5-flash";

// Load files
$spec_doc  = file_get_contents("spec.md");
if (!file_exists("model.scad")) {
    touch("model.scad");
}
$scad_code = file_get_contents("model.scad");
$original_scad_code = $scad_code;

// Helper: read image and convert to base64
function image_to_base64($path)
{
    $data = file_get_contents($path);
    return base64_encode($data);
}

// Helper: call OpenRouter LLM
function call_llm($messages)
{
    global $api_key, $base_url, $llm_name;

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
$render_base64 = image_to_base64("render.png");
unlink("render.png");
$image_data_uri = 'data:image/png;base64,' . $render_base64;
$error_output = $returnCode !== 0 ? "OpenSCAD Errors:\n" . implode("\n", $errors) : "";

// Step 2: Evaluate
echo "Evaluating… ";
$eval = call_llm([
    ["role" => "system", "content" => "You are the Evaluator of AutoSCAD. Your job is to evaluate the provided SCAD code and its rendered model against the specification. Evaluate if the specification is fully satisfied. Answer only YES or NO."],
    ["role" => "user", "content" => "Specification:\n$spec_doc\n\nRendered model (base64 PNG):\n$image_data_uri\n\nEvaluate if the specification is fully satisfied. Answer only YES or NO on the first line, followed by a concise explanation on the second line. Only respond with those two lines."],
]);

[$eval, $explanation] = explode("\n", $eval);
echo "Is specification fulfilled: $eval ($explanation)\n";
$spec_fulfilled = stripos($eval, "yes") !== false;
if ($spec_fulfilled) {
    exit(0);
}

// Step 3: Make a plan
echo "Planning…\n";
$plan = call_llm([
    ["role" => "system", "content" => "You are an expert SCAD engineer."],
    ["role" => "user", "content" => "Specification:\n$spec_doc\n\nCurrent SCAD code:\n$scad_code\n\nRendered model (base64 PNG):\n$image_data_uri\n\n$error_output\n\nUse the image and error messages to make a concrete plan to modify the SCAD code. Provide the plan as JSON steps."]
]);

// Step 4: Generate new SCAD code
echo "Creating…\n";
$scad_code = call_llm([
    ["role" => "system", "content" => "You are a SCAD code generator. Follow these rules:\n1. ONLY output valid SCAD syntax\n2. NEVER add markdown formatting\n3. PRESERVE existing functionality\n4. IMPLEMENT changes from the plan\n5. FIX ALL these errors:\n$error_output\n6. NEVER create recursive modules\n7. ALWAYS use correct function arguments"],
    ["role" => "user", "content" => "Specification:\n$spec_doc\n\nCurrent SCAD code:\n$scad_code\n\nPlan:\n$plan\n\nGenerate ONLY valid SCAD code that fixes these errors:"]
]);
file_put_contents("model.scad", $scad_code);

echo "Diff:          \n";
$original_scad_codeFile = tempnam(sys_get_temp_dir(), "autoscad-prev-version");
file_put_contents($original_scad_codeFile, $original_scad_code);
exec("diff $original_scad_codeFile model.scad", $diff_lines);
unlink($original_scad_codeFile);
echo implode("\n", $diff_lines);
exit(1);
