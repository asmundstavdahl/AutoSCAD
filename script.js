// Function to render image for given SCAD code
function renderImage(scadCode) {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=render&scad_code=${encodeURIComponent(scadCode)}`
    })
        .then(response => response.json())
        .then(data => {
            if (data.image) {
                document.getElementById('rendered-image').src = `data:image/png;base64,${data.image}`;
            }
        });
}

// Function to update UI with project data
function updateUI(data) {
    // Update project selector
    const selector = document.getElementById('project-selector');
    selector.innerHTML = '<option value="">Select Project</option>';
    data.projects.forEach(proj => {
        const option = document.createElement('option');
        option.value = proj.id;
        option.textContent = proj.name;
        if (data.current_project && proj.id == data.current_project.id) {
            option.selected = true;
        }
        selector.appendChild(option);
    });

    // Update project name input
    const nameInput = document.getElementById('project-name');
    if (data.current_project) {
        nameInput.value = data.current_project.name;
        nameInput.style.display = 'inline';
    } else {
        nameInput.style.display = 'none';
    }

    // Update iterations list
    const list = document.getElementById('iteration-list');
    list.innerHTML = '';
    data.iterations.forEach(iter => {
        const li = document.createElement('li');
        li.dataset.id = iter.id;
        li.textContent = iter.spec.substring(0, 50) + '...';
        if (data.current_iteration && iter.id == data.current_iteration.id) {
            li.classList.add('selected');
        }
        list.appendChild(li);
    });

    // Update form fields
    const specField = document.getElementById('spec');
    const scadField = document.getElementById('scad_code');
    specField.value = data.current_iteration ? data.current_iteration.spec : '';
    scadField.value = data.current_iteration ? data.current_iteration.scad_code : '';

    // Update rendered image
    if (data.current_iteration && data.current_iteration.scad_code) {
        renderImage(data.current_iteration.scad_code);
    } else {
        document.getElementById('rendered-image').src = '';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Function to render image for given SCAD code
    function renderImage(scadCode) {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=render&scad_code=${encodeURIComponent(scadCode)}`
        })
            .then(response => response.json())
            .then(data => {
                if (data.image) {
                    document.getElementById('rendered-image').src = `data:image/png;base64,${data.image}`;
                }
            });
    }

    // Function to update UI with project data
    function updateUI(data) {
        // Update project selector
        const selector = document.getElementById('project-selector');
        selector.innerHTML = '<option value="">Select Project</option>';
        data.projects.forEach(proj => {
            const option = document.createElement('option');
            option.value = proj.id;
            option.textContent = proj.name;
            if (data.current_project && proj.id == data.current_project.id) {
                option.selected = true;
            }
            selector.appendChild(option);
        });

        // Update project name input
        const nameInput = document.getElementById('project-name');
        if (data.current_project) {
            nameInput.value = data.current_project.name;
            nameInput.style.display = 'inline';
        } else {
            nameInput.style.display = 'none';
        }

        // Update iterations list
        const list = document.getElementById('iteration-list');
        list.innerHTML = '';
        data.iterations.forEach(iter => {
            const li = document.createElement('li');
            li.dataset.id = iter.id;
            li.textContent = iter.spec.substring(0, 50) + '...';
            if (data.current_iteration && iter.id == data.current_iteration.id) {
                li.classList.add('selected');
            }
            list.appendChild(li);
        });

        // Update form fields
        const specField = document.getElementById('spec');
        const scadField = document.getElementById('scad_code');
        specField.value = data.current_iteration ? data.current_iteration.spec : '';
        scadField.value = data.current_iteration ? data.current_iteration.scad_code : '';

        // Update rendered image
        if (data.current_iteration && data.current_iteration.scad_code) {
            renderImage(data.current_iteration.scad_code);
        } else {
            document.getElementById('rendered-image').src = '';
        }
    }

    // Project selector
    document.getElementById('project-selector').addEventListener('change', function () {
        const projectId = this.value;
        if (projectId) {
            fetch(`?ajax=1&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => updateUI(data));
        } else {
            updateUI({ projects: [], current_project: null, iterations: [], current_iteration: null });
        }
    });

    // New project button
    document.getElementById('new-project-btn').addEventListener('click', function () {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=create_project'
        })
            .then(response => response.json())
            .then(data => {
                if (data.id) {
                    fetch(`?ajax=1&project_id=${data.id}`)
                        .then(response => response.json())
                        .then(data => updateUI(data));
                }
            });
    });

    // Project name update
    document.getElementById('project-name').addEventListener('change', function () {
        const projectId = new URLSearchParams(window.location.search).get('project_id');
        const name = this.value;
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=update_project_name&project_id=${projectId}&name=${encodeURIComponent(name)}`
        });
    });

    // Iteration list clicks
    document.getElementById('iteration-list').addEventListener('click', function (e) {
        if (e.target.tagName === 'LI') {
            const iterationId = e.target.dataset.id;
            const projectId = new URLSearchParams(window.location.search).get('project_id');
            fetch(`?ajax=1&project_id=${projectId}&iteration_id=${iterationId}`)
                .then(response => response.json())
                .then(data => updateUI(data));
        }
    });

    // Form submit
    document.getElementById('scad-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('action', 'generate');

        const projectId = new URLSearchParams(window.location.search).get('project_id');
        const spec = formData.get('spec');
        const scadCode = formData.get('scad_code');

        if (!projectId) {
            alert('Please select a project first.');
            return;
        }

        if (!spec.trim()) {
            alert('Please enter a specification.');
            return;
        }

        // Start SSE
        const eventSource = new EventSource(`?sse=1&project_id=${projectId}&spec=${encodeURIComponent(spec)}&scad_code=${encodeURIComponent(scadCode)}`);
        let currentIterationId;
        let eventSourceFinished = false;

        eventSource.onmessage = function (event) {
            const data = event.data;
            if (event.type === 'iteration_start') {
                currentIterationId = data;
                // Add new iteration to list
                const li = document.createElement('li');
                li.dataset.id = data;
                li.textContent = spec.substring(0, 50) + '...';
                document.getElementById('iteration-list').prepend(li);
            } else if (event.type === 'render') {
                if (data !== 'error') {
                    document.getElementById('rendered-image').src = `data:image/png;base64,${data}`;
                }
            } else if (event.type === 'evaluate') {
                console.log('Evaluation:', data);
            } else if (event.type === 'plan') {
                console.log('Plan:', data);
            } else if (event.type === 'code_update') {
                document.getElementById('scad_code').value = data;
        } else if (event.type === 'done') {
            eventSource.onerror = function () {}; // Prevent error on close
            eventSource.close();
            if (currentIterationId) {
                // Update UI to select the new iteration
                fetch(`?ajax=1&project_id=${projectId}&iteration_id=${currentIterationId}`)
                    .then(response => response.json())
                    .then(data => updateUI(data));
            }
            } else if (event.type === 'error') {
                alert('Error: ' + data);
                eventSource.close();
            }
        };

    eventSource.onerror = function (e) {
        if (eventSource.readyState === EventSource.CLOSED) {
            return; // Ignore expected closure
        }
        console.error('SSE error', e);
        alert('Connection error occurred. This might be due to network issues, server problems, or the generation taking too long. Please try again or check your internet connection.');
        eventSource.close();
    };
    });
});