<?php
/** @var \Leantime\Core\UI\Template $tpl */
?>
<div class="pageheader">
    <div class="pageicon"><span class="fa fa-code-branch"></span></div>
    <div class="pagetitle">
        <h1>Gitea listener plugin settings</h1>
    </div>
</div>

<div class="maincontent">
    <div class="maincontentinner">

        <?php $configs = $tpl->get('giteaConfigs') ?? []; ?>

        <div style="margin-bottom: 20px;">
            <a class="btn" href="/plugins/myapps">Back</a>
        </div>

        <h3>Saved webhook configurations</h3>

        <?php if (count($configs) === 0): ?>
            <div class="well">No configurations saved yet.</div>
        <?php else: ?>
            <table class="table table-striped" id="gitea-config-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Repository URL</th>
                        <th>Branch filter</th>
                        <th>Access token (masked)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($configs as $c):
                        $id = htmlspecialchars($c['id'] ?? '');
                        $repo = htmlspecialchars($c['repository_url'] ?? '');
                        $bf = htmlspecialchars($c['branch_filter'] ?? '*');
                        $tokenMasked = htmlspecialchars(substr($c['repository_access_token'] ?? '', 0, 6));
                    ?>
                        <tr id="gitea-row-<?php echo $id; ?>">
                            <td><?php echo $id; ?></td>
                            <td><?php echo $repo; ?></td>
                            <td>
                                <input type="text" id="branch-input-<?php echo $id; ?>" class="form-control" value="<?php echo $bf; ?>" />
                            </td>
                            <td><code><?php echo $tokenMasked; ?>…</code></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-success gitea-update-btn" data-id="<?php echo $id; ?>">Update</button>
                                <button type="button" class="btn btn-sm btn-danger gitea-delete-btn" data-id="<?php echo $id; ?>">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <hr />

        <h3>Add webhook configuration</h3>

        <form method="post" action="/plugins/gitealistener/settings"  id="gitea-config-form">
            <div class="form-group">
                <label class="control-label" for="repo-url">Repository URL</label>
                <input type="url" name="repository_url" id="repo-url" class="form-control" placeholder="https://gitea.example.com/owner/repo" required />
            </div>

            <div class="form-group">
                <label class="control-label" for="access-token">Repository access token</label>
                <input type="text" name="repository_access_token" id="access-token" class="form-control" placeholder="Personal access token" required />
            </div>

            <div class="form-group">
                <label class="control-label" for="branch-filter">Branch filter</label>
                <input type="text" name="branch_filter" id="branch-filter" class="form-control" placeholder="e.g. master or * for all branches" value="*" />
            </div>

            <div class="form-group">
                <button type="button" id="test-connection" class="btn btn-secondary">Test connection</button>
                <span id="test-spinner" style="display:none; margin-left:8px;"><i class="fa fa-spinner fa-spin"></i></span>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>

        <div id="test-result" style="margin-top:12px;"></div>

    </div>
</div>

<script>
(function(){
    const btn = document.getElementById('test-connection');
    const spinner = document.getElementById('test-spinner');
    const resultDiv = document.getElementById('test-result');

    function show(message, success) {
        resultDiv.innerHTML = '';
        const el = document.createElement('div');
        el.className = success ? 'alert alert-success' : 'alert alert-danger';
        el.textContent = message;
        resultDiv.appendChild(el);
    }

    async function testConn() {
        const url = document.getElementById('repo-url').value.trim();
        const token = document.getElementById('access-token').value.trim();

        if (!url) { show('Repository URL is required', false); return; }
        if (!token) { show('Access token is required', false); return; }

        // disable and show spinner
        btn.disabled = true;
        spinner.style.display = 'inline-block';
        resultDiv.innerHTML = '';

        try {
            const body = new URLSearchParams();
            body.append('repository_url', url);
            body.append('repository_access_token', token);

            const resp = await fetch('/plugins/gitealistener/test', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });

            const json = await resp.json().catch(() => null);
            if (resp.ok && json && json.success) {
                show(json.message || 'Connection successful', true);
            } else if (json && json.message) {
                show(json.message, false);
            } else {
                show('Connection failed (HTTP ' + resp.status + ')', false);
            }
        } catch (err) {
            show('Request failed: ' + (err.message || err), false);
        } finally {
            btn.disabled = false;
            spinner.style.display = 'none';
        }
    }

    if (btn) btn.addEventListener('click', testConn);

    // Update and Delete handlers for rows
    function showRowMessage(rowId, message, success) {
        const row = document.getElementById('gitea-row-' + rowId);
        if (!row) return;
        let container = row.querySelector('.row-msg');
        if (!container) {
            container = document.createElement('div');
            container.className = 'row-msg';
            container.style.marginTop = '8px';
            row.cells[row.cells.length - 1].appendChild(container);
        }
        container.innerHTML = '';
        const el = document.createElement('div');
        el.className = success ? 'alert alert-success' : 'alert alert-danger';
        el.textContent = message;
        container.appendChild(el);
    }

    async function updateRow(id) {
        const input = document.getElementById('branch-input-' + id);
        if (!input) return;
        const bf = input.value.trim();
        if (bf === '') { showRowMessage(id, 'Branch filter cannot be empty', false); return; }

        const body = new URLSearchParams();
        body.append('id', id);
        body.append('branch_filter', bf);

        const updateBtn = document.querySelector('.gitea-update-btn[data-id="' + id + '"]');
        updateBtn.disabled = true;

        try {
            const resp = await fetch('/plugins/gitealistener/update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const json = await resp.json().catch(() => null);
            if (resp.ok && json && json.success) {
                showRowMessage(id, json.message || 'Updated', true);
            } else if (json && json.message) {
                showRowMessage(id, json.message, false);
            } else {
                showRowMessage(id, 'Update failed (HTTP ' + resp.status + ')', false);
            }
        } catch (err) {
            showRowMessage(id, 'Request failed: ' + (err.message || err), false);
        } finally {
            updateBtn.disabled = false;
        }
    }

    async function deleteRow(id) {
        if (!confirm('Delete configuration #' + id + '?')) return;

        const body = new URLSearchParams();
        body.append('id', id);

        const deleteBtn = document.querySelector('.gitea-delete-btn[data-id="' + id + '"]');
        deleteBtn.disabled = true;

        try {
            const resp = await fetch('/plugins/gitealistener/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const json = await resp.json().catch(() => null);
            if (resp.ok && json && json.success) {
                // remove row from table
                const row = document.getElementById('gitea-row-' + id);
                if (row) row.parentNode.removeChild(row);
            } else if (json && json.message) {
                showRowMessage(id, json.message, false);
                deleteBtn.disabled = false;
            } else {
                showRowMessage(id, 'Delete failed (HTTP ' + resp.status + ')', false);
                deleteBtn.disabled = false;
            }
        } catch (err) {
            showRowMessage(id, 'Request failed: ' + (err.message || err), false);
            deleteBtn.disabled = false;
        }
    }

    // wire up existing buttons
    document.querySelectorAll('.gitea-update-btn').forEach(b => {
        b.addEventListener('click', function(){ updateRow(this.getAttribute('data-id')); });
    });
    document.querySelectorAll('.gitea-delete-btn').forEach(b => {
        b.addEventListener('click', function(){ deleteRow(this.getAttribute('data-id')); });
    });

})();
</script>
