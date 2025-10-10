# AGENTS.md

## Code Style Guidelines

- Variables and functions: snake_case (e.g., $scad_code, call_llm())
- Indentation: 4 spaces, no tabs
- Comments: Use // for single-line comments
- Imports: None required; use built-in PHP functions
- Formatting: One statement per line; brace on same line
- Types: Use strict typing where possible and PHPDoc using PHPStan features whenever needed to complete type specifications
- Naming: Descriptive, avoid abbreviations
- Error handling: Don't over do it
- Strings: In PHP, prefer double quoted strings. In JS, prefer single quoted strings.
- Preserve existing functionality when modifying code
- Avoid recursive modules in SCAD generation
- Ensure generated SCAD code is valid

## Absolute rules for this project

- avoid 3rd party packages
- prefer functions and built-in data types over classes
- if classes are deemed, use pure data classes only (no methods!)
- zero build steps is a design requirement
- keep functions pure
- use parameters over global variables and constants
