// Project selector
document.getElementById('project-selector').addEventListener('change', function() {
    const projectId = this.value;
    if (projectId) {
        window.location.href = `?project_id=${projectId}`;
    }
});

// New project button
document.getElementById('new-project-btn').addEventListener('click', function() {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=create_project'
    })
    .then(response => response.json())
    .then(data => {
        if (data.id) {
            window.location.href = `?project_id=${data.id}`;
        }
    });
});

// Project name update
document.getElementById('project-name').addEventListener('change', function() {
    const projectId = new URLSearchParams(window.location.search).get('project_id');
    const name = this.value;
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update_project_name&project_id=${projectId}&name=${encodeURIComponent(name)}`
    });
});

// Iteration list clicks
document.getElementById('iteration-list').addEventListener('click', function(e) {
    if (e.target.tagName === 'LI') {
        const iterationId = e.target.dataset.id;
        const projectId = new URLSearchParams(window.location.search).get('project_id');
        window.location.href = `?project_id=${projectId}&iteration_id=${iterationId}`;
    }
});

// Form submit
document.getElementById('scad-form').addEventListener('submit', function(e) {
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

    eventSource.onmessage = function(event) {
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
            eventSource.close();
            if (currentIterationId) {
                window.location.href = `?project_id=${projectId}&iteration_id=${currentIterationId}`;
            }
        } else if (event.type === 'error') {
            alert('Error: ' + data);
            eventSource.close();
        }
    };

    eventSource.onerror = function() {
        console.error('SSE error');
        alert('Connection error occurred. This might be due to network issues, server problems, or the generation taking too long. Please try again or check your internet connection.');
        eventSource.close();
    };
});