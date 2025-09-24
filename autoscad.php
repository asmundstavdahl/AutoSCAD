<?php

require_once 'common.php';

// Load files
$spec_doc = file_get_contents("spec.md");
if (!file_exists("model.scad")) {
    touch("model.scad");
}
$scad_code = file_get_contents("model.scad");
$original_scad_code = $scad_code;

// Archive current model.scad to /tmp with timestamp
$timestamp = date('Y-m-d_H-i-s');
if (file_exists("model.scad")) {
    copy("model.scad", "/tmp/model_$timestamp.scad");
}

// Callback for CLI output
function cli_output($event, $data) {
    switch ($event) {
        case 'iteration':
            echo "Iteration {$data['iteration']}: {$data['message']}\n";
            break;
        case 'render':
            echo "Rendering…\n";
            if ($data['errors']) echo "Errors: {$data['errors']}\n";
            break;
        case 'eval':
            echo "Evaluating… {$data['result']} ({$data['explanation']})\n";
            break;
        case 'plan':
            echo "Planning…\n";
            break;
        case 'scad':
            echo "Creating…\n";
            break;
        case 'done':
            echo "{$data['message']}\n";
            break;
    }
}

// Run the generation with max 1 iteration for CLI
$final_scad = run_scad_generation($spec_doc, $scad_code, 'cli_output', 1);

// Show diff
echo "Diff:\n";
$original_scad_codeFile = tempnam(sys_get_temp_dir(), "autoscad-prev-version");
file_put_contents($original_scad_codeFile, $original_scad_code);
exec("diff $original_scad_codeFile model.scad", $diff_lines);
unlink($original_scad_codeFile);
echo implode("\n", $diff_lines);
exit(1);
