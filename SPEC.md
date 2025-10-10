# SPEC.md

## What is AutoSCAD?

AutoSCAD is an AI‑agent that delegates implementation and refinement of a 3D model in OpenSCAD.
A web interface lets users describe a model in natural language; the system then iteratively generates, renders, evaluates, and refines OpenSCAD code via a Large Language Model (LLM) until the specification is satisfied or an iteration cap is reached.

## Dependencies

- **OpenSCAD** – required for rendering SCAD code to PNG images (must be in system `PATH`).
- **OpenRouter API key** – required for accessing the LLM service (`OPENROUTER_API_KEY` env var).
- **PHP** – with cURL (HTTP) and PDO‑SQLite.
- **Web server** – PHP built‑in server (`php -S`) or any server that can serve these PHP files.
- **(Optional) Xvfb** – `xvfb-run` enables headless rendering on systems without a display.

## Configuration

- **LLM model** – default: `google/gemma-3-27b-it`.
  Override with environment variable: `OPENROUTER_MODEL`.
- **Base URL** – `https://openrouter.ai/api/v1/chat/completions`.
- **Maximum iterations** – default: `3`.
  Override with environment variable: `AUTOSCAD_MAX_ITERATIONS`.
- **Input limits** – no backend length constraints; frontend only checks that the specification is non‑empty.
- **Temporary files** – created under the system temp directory and deleted after each render.
- **Headless rendering** – if `xvfb-run` is available it wraps each OpenSCAD call automatically.

## Data Storage

SQLite database file: `autoscad.db`.

### Tables

- **projects**
  - `id` – INTEGER PRIMARY KEY AUTOINCREMENT
  - `name` – TEXT
  - `created_at` – DATETIME DEFAULT CURRENT_TIMESTAMP

- **iterations**
  - `id` – INTEGER PRIMARY KEY AUTOINCREMENT
  - `project_id` – INTEGER (FK → projects.id)
  - `spec` – TEXT
  - `scad_code` – TEXT
  - `created_at` – DATETIME DEFAULT CURRENT_TIMESTAMP

Each refinement loop appends or updates a single iteration row (initial insert, then code updates).

## Architecture

- **Backend** – pure PHP function style (`index.php`, `common.php`). No frameworks or third‑party packages.
- **Frontend** – Vanilla HTML/CSS/JS. Server‑Sent Events (SSE) stream incremental status updates.
- **Rendering** – 7 viewpoints (default isometric + front, back, left, right, top, bottom). OpenSCAD’s axis display (`--view axes`) supplies a color‑coded axis cross (X=red, Y=green, Z=blue).
- **LLM integration** – Multi‑turn orchestration: initial code (optionally user‑supplied), rendering, evaluation, planning, regeneration.

## Generation Loop (High Level)

For each user‑triggered “Generate & Refine” action:

1. Insert a new `iterations` row (initial `spec`, initial or empty `scad_code`).
2. Attempt to render the SCAD code from 7 viewpoints.
   - Up to 3 automatic fix attempts if OpenSCAD reports syntax / compile errors.
   - Fix attempts use an OpenSCAD reference context.
3. If rendering succeeds:
   - Send the specification, current SCAD code, and rendered images to the LLM.
   - The LLM returns a **plain text evaluation** (not JSON) that must clearly indicate whether the spec is fulfilled.
4. If the model is NOT fulfilled:
   - Ask LLM for a **plain text improvement plan** (ordered steps or rationale).
   - Ask LLM for revised SCAD code (markdown fences stripped if present).
   - Update the existing iteration row with the new SCAD code.
   - Continue to next iteration until fulfilled or iteration cap reached.
5. Stream status, progress, intermediate code, and final outcome to the client via SSE.

## Evaluation & Planning (Plain Text Convention)

- The evaluation response is plain text. It should contain:
  - A clear fulfillment statement (e.g., “Fulfilled” / “Not fulfilled”).
  - Brief reasoning.
- The plan response is plain text, typically a concise list of steps (bulleted or numbered) or a short rationale.
- No JSON is required or expected for either evaluation or planning phases.

## UI Features

- **Sidebar** – project selector, “New Project” button, iteration list.
- **Iteration list** – shows iterations for the selected project, labeled using their database IDs (may appear non‑sequential if other projects exist).
- **Editable project name** – inline rename persists immediately.
- **Specification editor** – free‑form natural language.
- **SCAD code editor** – starts blank unless an iteration is loaded; updated after fixes or new generations.
- **Status stream** – color‑coded messages (info/success/error) with newest first.
- **Progress bar** – coarse phase progress (rendering, evaluation, planning, completion).
- **Preview grid** – 7 labeled PNG renders (Default, Front, Back, Left, Right, Top, Bottom), responsive 4‑column layout.

## Error Handling

- **Dependency checks** – fail fast if `openscad` missing or `OPENROUTER_API_KEY` unset.
- **Input validation** – reject empty specification.
- **Rendering retries** – up to 3 fix attempts; abort loop if still failing.
- **LLM errors** – surfaced plainly to the stream (HTTP errors, parse issues).
- **Database exceptions** – PDO configured with exceptions; any fatal error aborts the current request.
- **SSE robustness** – client auto‑closes on fatal error messages; server stops streaming afterward.

## Limitations

- **Iteration cap** – default 3 (tune with `AUTOSCAD_MAX_ITERATIONS`).
- **Single user assumption** – no auth or multi‑tenant separation.
- **Local filesystem** – not designed for distributed execution.
- **LLM dependence** – offline operation not supported.
- **Image format** – only PNG exports.
- **No recursive OpenSCAD modules encouraged** – prompts and reference discourage recursion; no static enforcement yet.
- **Plain text protocol** – evaluation & plan not structured as JSON, so downstream automated reasoning is minimal.

## Security Considerations

- **Secrets** – API key only via environment.
- **No arbitrary upload** – user text + generated SCAD only.
- **Shell invocation** – constrained to `openscad` (+ optional `xvfb-run`). User’s SCAD code is passed directly to OpenSCAD; risk is limited to OpenSCAD’s own execution model (geometric DSL, not general code).
- **No third‑party PHP packages** – reduced supply chain risk.

## Style & Project Constraints (Summary)

(See `AGENTS.md` for authoritative rules.)
- Pure functions where practical; side effects isolated (DB, rendering, HTTP).
- No third‑party dependencies.
- Prefer functions over classes; no method logic in data containers.
- Zero build steps (run directly with PHP + OpenSCAD installed).
- Consistent snake_case naming moving forward (backend and frontend migration in progress).

## Extensibility Notes

Potential future improvements:
- Structured (optional) machine‑readable evaluation via a toggle.
- Per‑project sequential iteration numbering (derived, not stored).
- Optional lint/pass for recursive module detection.
- Enhanced timeout and retry logic for LLM calls.

---

_This SPEC reflects the updated behavior after migrating to plain text evaluation & planning and confirming the default model `google/gemma-3-27b-it` with environment overrides._
