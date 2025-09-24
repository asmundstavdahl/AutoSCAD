<?php
session_start();
// Config
$api_key = getenv("OPENROUTER_API_KEY");
$base_url = "https://openrouter.ai/api/v1/chat/completions";
$llm_name = "google/gemini-2.5-flash";
#$llm_name = "google/gemma-3-27b-it";

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

// Load current spec
$spec_doc = file_exists("spec.md") ? file_get_contents("spec.md") : "";
$scad_code = file_exists("model.scad") ? file_get_contents("model.scad") : "";
$output = "";
$image_data_uri = "";
$final_scad = "";

if (isset($_GET['sse'])) {
    // SSE endpoint
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('Access-Control-Allow-Origin: *');
    while (ob_get_level()) ob_end_flush();
    ob_start();
    error_log("SSE started");

    if (!file_exists("current_spec.txt")) {
        error_log("No current_spec.txt found");
        echo "data: {\"error\": \"No spec found\"}\n\n";
        exit;
    }
    $spec_doc = file_get_contents("current_spec.txt");
    error_log("Loaded spec from current_spec.txt");
    file_put_contents("spec.md", $spec_doc);
    $scad_code = file_exists("model.scad") ? file_get_contents("model.scad") : "";
    $original_scad_code = $scad_code;
    $max_iterations = 3; // Prevent infinite loop
    $iteration = 0;
    $spec_fulfilled = false;

    function sendSSE($event, $data)
    {
        echo "event: $event\n";
        echo "data: " . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }

    while (!$spec_fulfilled && $iteration < $max_iterations) {
        $iteration++;
        error_log("Starting iteration $iteration");
        sendSSE('iteration', ['iteration' => $iteration, 'message' => "Starting iteration $iteration"]);

        // Render
        error_log("Rendering model for iteration $iteration");
        $errors = [];
        exec("openscad -o render.png model.scad 2>&1", $errors, $returnCode);
        $render_base64 = image_to_base64("render.png");
        if (file_exists("render.png")) {
            unlink("render.png");
        }
        $image_data_uri = 'data:image/png;base64,' . $render_base64;
        $error_output = $returnCode !== 0 ? "OpenSCAD Errors:\n" . implode("\n", $errors) : "";
        error_log("Render completed for iteration $iteration, errors: " . ($error_output ? 'yes' : 'no'));
        sendSSE('render', ['image' => $image_data_uri, 'errors' => $error_output]);

        // Evaluate
        error_log("Evaluating model for iteration $iteration");
        $eval = call_llm([
            ["role" => "system", "content" => "You are the Evaluator of AutoSCAD. Your job is to evaluate the provided SCAD code and its rendered model against the specification. Evaluate if the specification is fully satisfied. Answer only YES or NO."],
            ["role" => "user", "content" => [
                ["type" => "text", "text" => "Specification:\n$spec_doc\n\nRendered model:"],
                ["type" => "image_url", "image_url" => ["url" => $image_data_uri]]
            ]],
        ]);
        [$eval_line, $explanation] = explode("\n", $eval);
        error_log("Evaluation for iteration $iteration: $eval_line ($explanation)");
        sendSSE('eval', ['result' => $eval_line, 'explanation' => $explanation]);
        $spec_fulfilled = stripos($eval_line, "yes") !== false;
        if ($spec_fulfilled) {
            error_log("Specification fulfilled after iteration $iteration");
            sendSSE('done', ['message' => 'Specification fulfilled after iteration ' . $iteration]);
            break;
        }

        // Plan
        error_log("Planning for iteration $iteration");
        $plan = call_llm([
            ["role" => "system", "content" => "You are an expert SCAD engineer."],
            ["role" => "user", "content" => [
                ["type" => "text", "text" => "Specification:\n$spec_doc\n\nCurrent SCAD code:\n$scad_code\n\n$error_output\n\nUse the image and error messages to make a concrete plan to modify the SCAD code. Provide the plan as JSON steps."],
                ["type" => "image_url", "image_url" => ["url" => $image_data_uri]]
            ]],
        ]);
        error_log("Plan generated for iteration $iteration");
        sendSSE('plan', ['plan' => $plan]);

        // Generate new SCAD
        error_log("Generating new SCAD for iteration $iteration");
        $scad_code = clean_scad_code(call_llm([
            ["role" => "system", "content" => "You are a SCAD code generator. Follow these rules:\n1. ONLY output valid SCAD syntax\n2. NEVER add markdown formatting\n3. PRESERVE existing functionality\n4. IMPLEMENT changes from the plan\n5. FIX ALL these errors:\n$error_output\n6. NEVER create recursive modules\n7. ALWAYS use correct function arguments"],
            ["role" => "user", "content" => "Specification:\n$spec_doc\n\nCurrent SCAD code:\n$scad_code\n\nPlan:\n$plan\n\nGenerate ONLY valid SCAD code that fixes these errors:"],
        ]));
        file_put_contents("model.scad", $scad_code);
        error_log("New SCAD generated and saved for iteration $iteration");
        sendSSE('scad', ['code' => $scad_code]);
    }

    if (!$spec_fulfilled) {
        // Send final SCAD and render even if max iterations reached
        sendSSE('scad', ['code' => $scad_code]);
        $errors = [];
        exec("openscad -o render.png model.scad 2>&1", $errors, $returnCode);
        $render_base64 = image_to_base64("render.png");
        if (file_exists("render.png")) {
            unlink("render.png");
        }
        $image_data_uri = 'data:image/png;base64,' . $render_base64;
        $error_output = $returnCode !== 0 ? "OpenSCAD Errors:\n" . implode("\n", $errors) : "";
        sendSSE('render', ['image' => $image_data_uri, 'errors' => $error_output]);
        sendSSE('done', ['message' => 'Max iterations reached']);
    }
    // Clean up spec file
    if (file_exists("current_spec.txt")) {
        unlink("current_spec.txt");
        error_log("Cleaned up current_spec.txt");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $spec_doc = $_POST['spec'] ?? "";
    file_put_contents("spec.md", $spec_doc);
    file_put_contents("current_spec.txt", $spec_doc);
    error_log("Spec saved to current_spec.txt");
    // Return JSON instead of redirect
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
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
        <form id="specForm" method="post">
            <label for="spec">Specification:</label><br>
            <textarea id="spec" name="spec" rows="10" cols="50"><?php echo htmlspecialchars($spec_doc); ?></textarea><br>
            <button type="submit" id="generateBtn">Generate SCAD</button>
        </form>
        <div class="results">
            <h2>Live Updates</h2>
            <div id="live-updates"></div>
            <div id="final-results" style="display: none;">
                <?php if ($output): ?>
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
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        const form = document.getElementById('specForm');
        const generateBtn = document.getElementById('generateBtn');
        const liveUpdates = document.getElementById('live-updates');
        const finalResults = document.getElementById('final-results');

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            generateBtn.disabled = true;
            liveUpdates.innerHTML = '';
            finalResults.style.display = 'none';

            const formData = new FormData(form);
            fetch('index.php', {
                method: 'POST',
                body: formData
            }).then(response => {
                if (response.ok) {
                    const eventSource = new EventSource('index.php?sse=1');
                    eventSource.addEventListener('iteration', function(event) {
                        console.log('SSE iteration:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            liveUpdates.innerHTML += `<p><strong>Iteration ${data.iteration}:</strong> ${data.message}</p>`;
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                        }
                    });

                    eventSource.addEventListener('render', function(event) {
                        console.log('SSE render:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            if (data.image) {
                                liveUpdates.innerHTML += `<p>Rendered model:</p><img src="${data.image}" alt="Iteration render" style="max-width: 100%;">`;
                            }
                            if (data.errors) {
                                liveUpdates.innerHTML += `<p>Errors: ${data.errors}</p>`;
                            }
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                        }
                    });

                    eventSource.addEventListener('eval', function(event) {
                        console.log('SSE eval:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            liveUpdates.innerHTML += `<p>Evaluation: ${data.result} (${data.explanation})</p>`;
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                        }
                    });

                    eventSource.addEventListener('plan', function(event) {
                        console.log('SSE plan:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            let html = '<p>Plan:</p>';
                            let plan = data.plan;
                            // If plan is a JSON string, parse it (strip markdown code blocks first)
                            if (typeof plan === 'string') {
                                // Extract JSON from markdown code block if present
                                const jsonMatch = plan.match(/```(?:json)?\s*([\s\S]*?)\s*```/);
                                if (jsonMatch) {
                                    plan = jsonMatch[1].trim();
                                }
                                try {
                                    plan = JSON.parse(plan);
                                } catch (e) {
                                    // If not JSON, treat as plain text
                                }
                            }
                            if (Array.isArray(plan)) {
                                plan.forEach(step => {
                                    html += `<details><summary>${step.step}</summary>`;
                                    if (step.description) html += `<p>${step.description}</p>`;
                                    if (step.code) html += `<pre>${step.code}</pre>`;
                                    html += `</details>`;
                                });
                            } else if (typeof plan === 'object') {
                                // Pretty-print object
                                html += `<pre>${JSON.stringify(plan, null, 2)}</pre>`;
                            } else {
                                // Plain text
                                html += `<p>${plan}</p>`;
                            }
                            liveUpdates.innerHTML += html;
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                        }
                    });

                    eventSource.addEventListener('scad', function(event) {
                        console.log('SSE scad:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            liveUpdates.innerHTML += `<p>Generated SCAD code:</p><pre>${data.code}</pre>`;
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                        }
                    });

                    eventSource.addEventListener('done', function(event) {
                        console.log('SSE done:', event.data);
                        try {
                            const data = JSON.parse(event.data);
                            liveUpdates.innerHTML += `<p><strong>${data.message}</strong></p>`;
                            eventSource.close();
                            generateBtn.disabled = false;
                            finalResults.style.display = 'block';
                        } catch (e) {
                            console.error('JSON parse error:', e, event.data);
                            liveUpdates.innerHTML += `<p>Parse error: ${event.data}</p>`;
                        }
                    });

                    eventSource.addEventListener('error', function(event) {
                        console.log('SSE error:', event);
                        // Only show error if not a normal close
                        if (event.readyState === EventSource.CLOSED) {
                            console.log('Connection closed normally');
                        } else {
                            liveUpdates.innerHTML += '<p>Error in connection.</p>';
                            generateBtn.disabled = false;
                        }
                        eventSource.close();
                    });
                    eventSource.onerror = function() {
                        liveUpdates.innerHTML += '<p>Error in connection.</p>';
                        eventSource.close();
                        generateBtn.disabled = false;
                    };
                } else {
                    liveUpdates.innerHTML += '<p>Failed to start generation.</p>';
                    generateBtn.disabled = false;
                }
            }).catch(error => {
                liveUpdates.innerHTML += '<p>Error: ' + error.message + '</p>';
                generateBtn.disabled = false;
            });
        });
    </script>
</body>

</html>
