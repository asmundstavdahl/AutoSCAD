<?php
// Config
$api_key = getenv("OPENROUTER_API_KEY");
$base_url = "https://openrouter.ai/api/v1/chat/completions";
$llm_name = "google/gemini-2.5-flash";

// Helper: read image and convert to base64
function image_to_base64($path) {
    if (file_exists($path)) {
        $data = file_get_contents($path);
        return base64_encode($data);
    }
    return "";
}

// Helper: call OpenRouter LLM
function call_llm($messages) {
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

// Load current spec
$spec_doc = file_exists("spec.md") ? file_get_contents("spec.md") : "";
$scad_code = file_exists("model.scad") ? file_get_contents("model.scad") : "";
$output = "";
$image_data_uri = "";
$final_scad = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $spec_doc = $_POST['spec'] ?? "";
    file_put_contents("spec.md", $spec_doc);
    $scad_code = $scad_code;
    $original_scad_code = $scad_code;
    $max_iterations = 10; // Prevent infinite loop
    $iteration = 0;
    $spec_fulfilled = false;

    while (!$spec_fulfilled && $iteration < $max_iterations) {
        $iteration++;
        $output .= "Iteration $iteration:\n";

        // Render
        $errors = [];
        exec("openscad -o render.png model.scad 2>&1", $errors, $returnCode);
        $render_base64 = image_to_base64("render.png");
        if (file_exists("render.png")) {
            unlink("render.png");
        }
        $image_data_uri = 'data:image/png;base64,' . $render_base64;
        $error_output = $returnCode !== 0 ? "OpenSCAD Errors:\n" . implode("\n", $errors) : "";
        $output .= "Rendered.\n";

        // Evaluate
        $eval = call_llm([
            ["role" => "system", "content" => "You are the Evaluator of AutoSCAD. Your job is to evaluate the provided SCAD code and its rendered model against the specification. Evaluate if the specification is fully satisfied. Answer only YES or NO."],
            ["role" => "user", "content" => "Specification:\n$spec_doc\n\nRendered model (base64 PNG):\n$image_data_uri\n\nEvaluate if the specification is fully satisfied. Answer only YES or NO on the first line, followed by a concise explanation on the second line. Only respond with those two lines."],
        ]);
        [$eval_line, $explanation] = explode("\n", $eval);
        $output .= "Evaluation: $eval_line ($explanation)\n";
        $spec_fulfilled = stripos($eval_line, "yes") !== false;
        if ($spec_fulfilled) {
            break;
        }

        // Plan
        $plan = call_llm([
            ["role" => "system", "content" => "You are an expert SCAD engineer."],
            ["role" => "user", "content" => "Specification:\n$spec_doc\n\nCurrent SCAD code:\n$scad_code\n\nRendered model (base64 PNG):\n$image_data_uri\n\n$error_output\n\nUse the image and error messages to make a concrete plan to modify the SCAD code. Provide the plan as JSON steps."],
        ]);
        $output .= "Plan: $plan\n";

        // Generate new SCAD
        $scad_code = call_llm([
            ["role" => "system", "content" => "You are a SCAD code generator. Follow these rules:\n1. ONLY output valid SCAD syntax\n2. NEVER add markdown formatting\n3. PRESERVE existing functionality\n4. IMPLEMENT changes from the plan\n5. FIX ALL these errors:\n$error_output\n6. NEVER create recursive modules\n7. ALWAYS use correct function arguments"],
            ["role" => "user", "content" => "Specification:\n$spec_doc\n\nCurrent SCAD code:\n$scad_code\n\nPlan:\n$plan\n\nGenerate ONLY valid SCAD code that fixes these errors:"],
        ]);
        file_put_contents("model.scad", $scad_code);
        $output .= "Generated new SCAD.\n";
    }

    $final_scad = $scad_code;
    if ($iteration >= $max_iterations) {
        $output .= "Max iterations reached.\n";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AutoSCAD Web Interface</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h1>AutoSCAD</h1>
        <form method="post">
            <label for="spec">Specification:</label><br>
            <textarea id="spec" name="spec" rows="10" cols="50"><?php echo htmlspecialchars($spec_doc); ?></textarea><br>
            <button type="submit">Generate SCAD</button>
        </form>
        <?php if ($output): ?>
            <div class="results">
                <h2>Output</h2>
                <pre><?php echo htmlspecialchars($output); ?></pre>
                <?php if ($image_data_uri): ?>
                    <h2>Rendered Model</h2>
                    <img src="<?php echo $image_data_uri; ?>" alt="Rendered SCAD model">
                <?php endif; ?>
                <?php if ($final_scad): ?>
                    <h2>Generated SCAD Code</h2>
                    <pre><?php echo htmlspecialchars($final_scad); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>