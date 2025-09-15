```
echo "a stack of 3 cubes, each rotated by 30° relative to the one below it. size of each cube = 5." > spec.md
# Use Ctrl-C to stop the loop when you're happy or have run out of hope
until php autoscad.php; do openscad model.scad; done
```
