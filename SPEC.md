# SPEC.md

## What is AutoSCAD?

AutoSCAD is an AI‑agent that delegates the implementation of a 3D model in OpenSCAD.  
A web interface lets users describe a model in natural language; the system then iteratively generates, renders, evaluates, and refines OpenSCAD code via a Large Language Model (LLM) until the specification is satisfied.

## Dependencies

- **OpenSCAD** – required for rendering SCAD code to PNG images. Must be installed and available in the system `PATH`.
- **OpenRouter API key** – required for accessing the LLM service. Set as the environment variable `OPENROUTER_API_KEY`.
- **PHP** – with the cURL extension for API calls and PDO‑SQLite for database storage.
- **Web server** – PHP built‑in server (`php -S`) or any compatible server that can serve the PHP files.

## Configuration

- **LLM model** – `google/gemini-2.5-pro` via the OpenRouter API (updated from the previous `google/gemma-3-27b-it`).
- **Base URL** – `https://openrouter.ai/api/v1/chat/completions`.
- **Maximum iterations** – default `3` (configurable by changing `$max_iterations` in `index.php`).
- **Input limits** – none enforced by the backend; the UI validates that the specification is not empty.
- **File handling** – temporary files are created in the system temporary directory and deleted after each render.
- **Xvfb support** – if `xvfb-run` is available, rendering is performed inside a virtual X server to work on headless systems.

## Data Storage

All specifications, SCAD code, and rendered images are persisted in a SQLite database (`autoscad.db`). This enables full history tracking per project.

### Database schema

- **projects** table

  - `id` – INTEGER PRIMARY KEY AUTOINCREMENT
  - `name` – TEXT (editable from the UI)
  - `created_at` – DATETIME DEFAULT CURRENT_TIMESTAMP

- **iterations** table
  - `id` – INTEGER PRIMARY KEY AUTOINCREMENT
  - `project_id` – INTEGER (foreign key to `projects.id`)
  - `spec` – TEXT
  - `scad_code` – TEXT
  - `created_at` – DATETIME DEFAULT CURRENT_TIMESTAMP

## Architecture

- **Backend** – pure PHP functions (`index.php`, `common.php`) handle routing, database CRUD, LLM calls, and SCAD rendering. No classes or third‑party packages are used.
- **Frontend** – HTML/CSS/JavaScript with AJAX for form submission and Server‑Sent Events (SSE) for real‑time streaming of generation progress.
- **Generation loop** – runs inside the SSE endpoint (`action=sse`). It:
  1. Inserts a new iteration record.
  2. Renders the current SCAD code from **seven** viewpoints (default isometric + front, back, left, right, top, bottom). An axis‑cross (X = red, Y = green, Z = blue) is automatically added to every render.
  3. Sends the images to the LLM for evaluation.
  4. Parses the LLM’s JSON response (`{"fulfilled": true/false, "reasoning": "…"}`).
  5. If not fulfilled, asks the LLM for a concrete plan, then for new SCAD code, cleans any markdown, and repeats until the spec is fulfilled or the iteration limit is reached.
- **UI enhancements** – the interface now includes:
  - A **progress bar** and colour‑coded status messages (info, success, error).
  - A **sidebar** with a “New Project” button, project selector, and a clickable list of iterations.
  - An editable **Project Name** field that updates the database on change.
  - A **preview grid** that displays the seven rendered images in a responsive 4‑column layout.
  - Real‑time updates via SSE without full page reloads.

## Supported Usage Patterns

### Basic workflow

1. **Create or select a project** using the sidebar controls. New projects receive a timestamp‑based default name.
2. **Select an iteration** (if any) to load its specification and SCAD code into the text areas.
3. **Edit the specification** (or keep the existing one). The SCAD field may be left empty to let the agent generate code from scratch.
4. Click **Generate & Refine**. The client opens an SSE connection that streams:
   - Iteration start notification.
   - Rendering attempts and any automatic fixes.
   - Evaluation results.
   - Planning steps.
   - Newly generated SCAD code.
   - Progress‑bar updates and status messages.
5. The backend renders the model from the seven viewpoints, adds the axis cross, and sends the base‑64 PNGs back to the client.
6. The LLM evaluates the rendered images against the specification and replies with a JSON verdict.
7. If the spec is not fulfilled, the LLM provides a plan and new SCAD code, which the backend stores and feeds into the next loop iteration.
8. When the spec is fulfilled (or the iteration limit is hit), the final SCAD code and images are displayed. The user can start a new iteration by editing the spec or SCAD code.

### UI specifics

- **Project Management** – “New Project” creates a fresh entry; the project selector lists all projects ordered by creation date. Changing the project name updates the DB instantly.
- **Iteration Navigation** – the sidebar shows a scrollable list of iterations (`Iteration 1`, `Iteration 2`, …). Clicking an item loads its data and highlights the active iteration.
- **Status area** – a scrollable box shows timestamped messages with colour coding (`info`, `success`, `error`).
- **Progress bar** – visual feedback of the generation pipeline (0 % → 100 %).
- **Preview** – rendered images are shown in a responsive grid; each image is labelled (Default, Front, Back, Left, Right, Top, Bottom).

## Error Handling

- **Dependency checks** – verifies that `openscad` is in `PATH` and that `OPENROUTER_API_KEY` is set before starting generation.
- **Input validation** – the spec must not be empty; the UI prevents submission otherwise.
- **Rendering errors** – up to three automatic fix attempts are made. Errors are streamed to the client and abort the loop if unrecoverable.
- **LLM errors** – API failures, missing or malformed JSON, and other issues are captured and reported via SSE.
- **Database errors** – PDO is configured with `ERRMODE_EXCEPTION`; any exception aborts the current request and is sent to the client.
- **SSE connection issues** – the client closes the EventSource on error; the server cleans up temporary files.

## Limitations

- **Iteration cap** – fixed at three by default; can be changed by editing `$max_iterations` in `index.php`.
- **No recursive modules** – the generator avoids recursive OpenSCAD modules to prevent runtime failures.
- **Single‑user design** – the application assumes one active user; there is no authentication or multi‑user isolation.
- **Local file system** – temporary files are stored locally; the system is not ready for distributed cloud deployments without modification.
- **LLM dependency** – requires a stable internet connection and a valid OpenRouter API key.
- **Image format** – only PNG output is supported.
- **Zero build steps** – the project is intended to run out‑of‑the‑box with the listed dependencies; no additional build or compilation steps are required.

## Security Considerations

- **Input sanitisation** – performed only where technically necessary (e.g., spec emptiness). All other inputs are stored as‑is and rendered by OpenSCAD.
- **Secrets management** – the API key is read from an environment variable; it is never hard‑coded.
- **File permissions** – the PHP process must have read/write access to the temporary directory and the SQLite database.
- **No external libraries** – the code relies solely on built‑in PHP functions to minimise attack surface.

---

_This SPEC reflects the current state of the codebase as of commit 527d9c8._
