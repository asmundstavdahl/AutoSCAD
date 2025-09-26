<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
init_db();

$selected_project_id = isset($_GET['project']) ? (int)$_GET['project'] : null;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_project') {
        $name = trim($_POST['project_name'] ?? '');
        if ($name === '') {
            $name = 'AutoSCAD ' . (new \DateTime())->format('c');
        }
        $pid = create_project($name);
        header('Location: ?project=' . $pid);
        exit;
    }
    if ($_POST['action'] === 'new_iteration' && isset($_POST['project_id'])) {
        $project_id = (int)$_POST['project_id'];
        $spec = $_POST['spec'] ?? '';
        $scad = $_POST['scad_code'] ?? '';
        $iter_id = new_iteration($project_id, $spec, $scad);
        // Run generation loop (may be long; kept simple for this prototype)
        run_generation_loop($iter_id, MAX_ITERATIONS);
        header('Location: ?project=' . $project_id);
        exit;
    }
}

$projects = get_projects();
$latest = null;
if ($selected_project_id) {
    $iterations = get_iterations($selected_project_id);
    $latest = $iterations[0] ?? null;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>AutoSCAD (SPEC.md Implemented)</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 2rem; }
    .panel { border: 1px solid #ccc; padding: 1rem; border-radius: 6px; margin-bottom: 1rem; }
    label { display: block; margin-top: 0.5rem; }
    input, textarea, select { width: 100%; padding: 0.5rem; margin-top: 0.25rem; }
    button { padding: 0.5rem 1rem; margin-top: 0.5rem; }
    .row { display: flex; gap: 1rem; }
    .col { flex: 1; }
    img { max-width: 100%; border: 1px solid #ddd; }
  </style>
</head>
<body>
  <h1>AutoSCAD — SPEC.md Implemented (Prototype)</h1>
  <div class="panel">
    <h2>New Project</h2>
    <form method="POST" action="">
      <input type="hidden" name="action" value="create_project" />
      <label>Project Name</label>
      <input type="text" name="project_name" placeholder="New AutoSCAD Project" />
      <button type="submit">Create Project</button>
    </form>
  </div>
  <div class="panel">
    <h2>Projects</h2>
    <form method="GET" action="">
      <label>Select Project</label>
      <select name="project" onchange="this.form.submit()">
        <option value="">-- none --</option>
        <?php foreach ($projects as $p): $sel = (isset($selected_project_id) && $selected_project_id == $p['id']) ? 'selected' : ''; echo "<option value=\"{$p['id']}\" $sel>{$p['name']}</option>"; endforeach; ?>
      </select>
    </form>
    <?php if ($selected_project_id): ?>
      <p>Selected project: <?php echo htmlspecialchars(get_project($selected_project_id)['name'] ?? ''); ?></p>
    <?php endif; ?>
  </div>

  <?php if ($selected_project_id): ?>
  <div class="panel">
    <h2>New Iteration</h2>
    <form method="POST" action="">
      <input type="hidden" name="action" value="new_iteration" />
      <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>" />
      <label>Spec</label>
      <textarea name="spec" rows="5" placeholder="Enter model specification in natural language"></textarea>
      <label>SCAD Code (optional)</label>
      <textarea name="scad_code" rows="5" placeholder="Initial SCAD code (or leave empty to auto-generate)"></textarea>
      <button type="submit">Run Iteration</button>
    </form>
  </div>

  <div class="panel">
    <h2>Iterations</h2>
    <?php $its = get_iterations($selected_project_id); if (!empty($its)) {
      foreach ($its as $it) {
        $image = TMP_DIR . '/iteration_' . $it['id'] . '.png';
        echo '<div class="row" style="margin-bottom:1rem; align-items:stretch;">';
        echo '<div class="col" style="flex:0 0 60%;">';
        echo '<strong>Iteration ' . $it['id'] . '</strong><br/>'; 
        echo 'Created: ' . $it['created_at'] . '<br/>'; 
        echo '<pre style="white-space:pre-wrap;">' . htmlspecialchars($it['spec'] ?? '') . '</pre>';
        echo '</div>';
        echo '<div class="col" style="flex:0 0 40%;">';
        if (file_exists($image)) {
          echo '<img src="/tmp/iteration_' . $it['id'] . '.png" alt="render" />';
        } else {
          echo '<em>No render yet</em>';
        }
        echo '</div>';
        echo '</div>';
      }
    } else {
      echo '<p>No iterations yet.</p>';
    } ?>
  </div>
  <?php endif; ?>

</body>
</html>
