# SPEC.md

## What is AutoSCAD?
AutoSCAD is an AI agent for delegating the implementation of a 3D model in OpenSCAD.

## Supported usage patterns

### Basic workflow
1. User enters their model spec into the spec field.
2. User leaves the SCAD field empty.
3. User submits, kicking off the agent.
4. The current SCAD code is rendered, the resulting images supplied as context to the agent.
5. Agent evaluates the SCAD code and the rendered images against the spec, deciding if the soec os fulfilled.
6. If the spec is fulfilled, go to step 9; otherwise, continue.
7. Agent makes a plan for for to modify the SCAD code to fulfil the specifiction.
8. Agent writes a new version of the SCAD code.
9. Repeat from step 4.
10. User is presented with the SCAD code and the latest rendered images of it.
11. User may revise the spec or adjust the SCAD code and perform step 3. to iterate on the result.
