# SPEC.md

## What is AutoSCAD?
AutoSCAD is an AI agent for delegating the implementation of a 3D model in OpenSCAD.

## Data Storage
Model specifications, SCAD code, and rendered images for each project iteration are stored in an SQLite database. This enables persistent tracking of project history, including all versions of the spec, code, and images.

## Supported usage patterns

### Basic workflow
1. User creates a new project or selects an existing one via the web interface.
2. If selecting an existing project, the interface displays all iterations, allowing navigation between versions of the spec, SCAD code, and rendered images.
3. User enters or revises their model spec in the spec field (for new projects or iterations).
4. User leaves the SCAD field empty.
5. User submits, kicking off the agent.
6. The current SCAD code is rendered, the resulting images supplied as context to the agent.
6a. All data (spec, SCAD code, images) is saved to the SQLite DB for the current iteration.
7. Agent evaluates the SCAD code and the rendered images against the spec, deciding if the spec is fulfilled.
8. If the spec is fulfilled, go to step 12; otherwise, continue.
9. Agent makes a plan to modify the SCAD code to fulfil the specification.
10. Agent writes a new version of the SCAD code.
11. Repeat from step 6.
12. User is presented with the SCAD code and the latest rendered images of it.
13. User may revise the spec or adjust the SCAD code and perform step 5 to iterate on the result.

### Web Interface
- **Project Management:** Users can create new projects or select existing ones from a list.
- **Iteration Navigation:** For selected projects, users can browse and switch between all stored iterations, viewing the associated spec, SCAD code, and rendered images for each.
