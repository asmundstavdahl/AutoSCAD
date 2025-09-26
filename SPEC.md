# SPEC.md

## What is AutoSCAD?
AutoSCAD is an AI agent for delegating the implementation of a 3D model in OpenSCAD. It uses a web interface to allow users to specify models in natural language, then iteratively generates and refines SCAD code using an LLM until the specification is met.

## Dependencies
- **OpenSCAD**: Required for rendering SCAD code to images. Must be installed and available in the system PATH.
- **OpenRouter API Key**: Required for accessing the LLM service. Set as environment variable `OPENROUTER_API_KEY`.
- **PHP**: With cURL extension for API calls and SQLite for database storage.
- **Web Server**: PHP built-in server or similar for serving the web interface.

## Configuration
- **LLM Model**: Uses `google/gemma-3-27b-it` via OpenRouter API.
- **Base URL**: `https://openrouter.ai/api/v1/chat/completions`
- **Max Iterations**: Limited to 3 to prevent infinite loops.
- **Input Limits**: No explicit limits.
- **File Paths**: Temporary files used during rendering are stored in the system's tempdir and deleted afterward.

## Data Storage
Model specifications, SCAD code, and rendered images for each project iteration are stored in an SQLite database (`autoscad.db`). This enables persistent tracking of project history, including all versions of the spec, code, and images.

### Database Schema
- **projects** table:
  - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
  - `name`: TEXT
  - `created_at`: DATETIME DEFAULT CURRENT_TIMESTAMP
- **iterations** table:
  - `id`: INTEGER PRIMARY KEY AUTOINCREMENT
  - `project_id`: INTEGER (foreign key to projects.id)
  - `spec`: TEXT
  - `scad_code`: TEXT
  - `created_at`: DATETIME DEFAULT CURRENT_TIMESTAMP

## Architecture
- **Backend**: PHP scripts (`index.php`, `common.php`) handle web requests, database interactions, LLM calls, and SCAD generation.
- **Frontend**: HTML/CSS/JavaScript web interface with AJAX for form submissions and real-time updates via Server-Sent Events (SSE).
- **Generation Loop**: Iterative process involving rendering, evaluation, planning, and code generation until spec is fulfilled or max iterations reached, with no full page reloads during iterations.
- **Session Management**: Prefer to not use session data – use URL GET parameters instead.

## Supported Usage Patterns

### Basic Workflow
1. User creates a new project or selects an existing one via the web interface.
2. If selecting an existing project, the interface displays all iterations, allowing navigation between versions of the spec, SCAD code, and rendered images. The latest iteration is pre-selected when the project is selected.
3. User enters or revises their model spec in the spec field (for new projects or iterations).
4. User leaves the SCAD field empty to auto-generate from spec, or provides initial code (or leaves the existing code from the selected iteration).
5. User submits, kicking off the agent, which creates a new iteration.
6. The current SCAD code is rendered using OpenSCAD, the resulting images supplied as context to the agent.
7. Spec and SCAD code is saved to the SQLite DB for the current iteration.
8. Agent evaluates the SCAD code and the rendered images against the spec, deciding if the spec is fulfilled (YES/NO response).
9. If the spec is fulfilled, go to step 12; otherwise, continue.
10. Agent makes a concrete plan (as JSON steps) to modify the SCAD code to fulfil the specification.
11. Agent writes a new version of the SCAD code, cleaning any markdown formatting and ensuring validity.
12. Repeat from step 6.
13. User is presented with the final SCAD code and the latest rendered images.
14. User may revise the spec or adjust the SCAD code and perform step 5 to iterate on the result.

### Web Interface
- **Project Management:** Users can create new projects or select existing ones from a dropdown selector. A "New Project" button creates a project with a name based on the current date and time in ISO format. Above the spec and SCAD text areas, a project name field allows editing the current project's name; changes update on "onchange" and refresh the project selector.
- **Iteration Navigation:** A list of the current project's iterations, sorted by most recent at the top, is displayed on the left side of the page. Iterations are selectable to switch to that iteration. When a new iteration is started, it is sent via Server-Sent Events (SSE) and appears in the iteration selector. When SCAD code is updated or images are rendered, they are sent via SSE and updated in the web page.
- **Real-time Updates:** Uses AJAX for form submissions and SSE to stream progress, including iteration starts, renders, evaluations, plans, and final results, with no full page reloads during iterations.
- **Input Validation:** Client-side and server-side checks for empty specs, length limits, and sanitization.

## Error Handling
- **Dependency Checks**: Verifies OpenSCAD is available and an API key is configured before starting generation.
- **Input Validation**: Ensures spec is not empty.
- **LLM Errors**: Handles API failures, no responses, or invalid JSON.
- **SCAD Errors**: Captures OpenSCAD rendering errors and feeds them back to the LLM for fixes.
- **Database Errors**: Uses PDO with error handling for database operations.
- **SSE Errors**: Graceful handling of connection issues with user feedback.

## Troubleshooting
- Check nginx error logs: `tail -n10 /var/log/nginx/error.log`
- Check PHP error logs: `tail -n10 /var/log/php8.3-fpm.log` (adjust version as needed)
- Verify OpenSCAD installation: `which openscad`
- Test LLM API: `curl -X POST "https://openrouter.ai/api/v1/chat/completions" -H "Authorization: Bearer $OPENROUTER_API_KEY" -d '{"model": "openai/gpt-4o-mini", "messages": [{"role": "user", "content": "Hello"}], "max_tokens": 10}'`
- If "OPENROUTER_API_KEY environment variable not set" error occurs, add the API key to PHP-FPM pool config in `/etc/php/8.3/fpm/pool.d/www.conf`:
  ```
  env[OPENROUTER_API_KEY] = your_api_key_here
  ```
  Then restart PHP-FPM: `systemctl restart php8.3-fpm`

## Limitations
- **Max Iterations**: Limited to 3 to prevent infinite loops and resource exhaustion, but this limit should be configurable by adjusting a constant in the PHP code.
- **No Recursive Modules**: SCAD code generation avoids recursive modules to prevent errors.
- **Single User**: Designed for single-user operation; no multi-user support.
- **Local Files**: Relies on local file system for temporary files; not suitable for cloud deployments without modifications.
- **LLM Dependency**: Requires stable internet connection and OpenRouter API access.
- **Image Rendering**: Only supports PNG output from OpenSCAD; no other formats.
- **No Build Steps**: Zero build steps required, but assumes dependencies are pre-installed.

## Security Considerations
- **Input Sanitization**: Only where technically valuable.
- **No Secrets in Code**: API key stored in environment variable, not hardcoded.
- **File Permissions**: Assumes appropriate permissions for reading/writing files.
- **No External Libraries**: Avoids third-party packages to minimize dependencies and security risks.
