<?php
// filepath: admin/duplicate_check.php
// Small admin UI to run duplicate_scan.php and show pairs with similarity scores.
// Requires HR3 Admin session.

session_start();
if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'HR3 Admin') {
    header("Location: ../login.php");
    exit;
}
$fullname = $_SESSION['fullname'] ?? 'Administrator';
$role = $_SESSION['role'] ?? 'admin';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Duplicate Template Scan — Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <style>
    body { font-family: Inter, Roboto, Poppins, Arial, sans-serif; background:#fafbfc; color:#111827; }
    .container { margin-top:1.2rem; }
    .small-muted { font-size:0.9rem; color:#6b7280; }
  </style>
</head>
<body>
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h4 class="m-0">Duplicate Template Scan</h4>
        <div class="small-muted">Detect potentially duplicate face templates across enrolled employees</div>
      </div>
      <div>
        <strong><?= htmlspecialchars($fullname) ?></strong><br><small><?= htmlspecialchars(ucfirst($role)) ?></small>
      </div>
    </div>

    <div class="card p-3 mb-3">
      <div class="row g-2 align-items-end">
        <div class="col-auto">
          <label class="form-label small mb-1">Threshold</label>
          <input id="threshold" type="number" step="0.01" min="0" max="2" class="form-control form-control-sm" value="0.60">
        </div>
        <div class="col-auto">
          <label class="form-label small mb-1">Limit</label>
          <input id="limit" type="number" min="1" class="form-control form-control-sm" value="200">
        </div>
        <div class="col-auto">
          <label class="form-label small mb-1">Top (optional)</label>
          <input id="top" type="number" min="1" class="form-control form-control-sm" placeholder="Leave blank for threshold filter">
        </div>
        <div class="col-auto">
          <button id="btnRun" class="btn btn-primary btn-sm">Run Scan</button>
          <button id="btnExportCsv" class="btn btn-outline-secondary btn-sm">Export CSV</button>
        </div>
      </div>
      <div id="scanMsg" class="mt-2 small-muted"></div>
    </div>

    <div class="card p-3">
      <h6>Potential Duplicates</h6>
      <div class="table-responsive" style="max-height:56vh; overflow:auto;">
        <table class="table table-sm table-hover" id="resultsTable">
          <thead class="table-light"><tr><th>#</th><th>Employee A</th><th>Employee B</th><th>Distance</th></tr></thead>
          <tbody id="resultsTbody"><tr><td colspan="4" class="small-muted">Run a scan to see potential duplicates.</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

<script>
const btnRun = document.getElementById('btnRun');
const thresholdEl = document.getElementById('threshold');
const limitEl = document.getElementById('limit');
const topEl = document.getElementById('top');
const resultsTbody = document.getElementById('resultsTbody');
const scanMsg = document.getElementById('scanMsg');
const btnExportCsv = document.getElementById('btnExportCsv');

let lastResults = [];

btnRun.addEventListener('click', async () => {
  const threshold = parseFloat(thresholdEl.value);
  const limit = parseInt(limitEl.value,10) || 200;
  const top = topEl.value ? parseInt(topEl.value,10) : null;
  scanMsg.textContent = 'Running scan... please wait (may take a while for many employees).';
  resultsTbody.innerHTML = '<tr><td colspan="4" class="small-muted">Scanning…</td></tr>';
  try {
    const params = new URLSearchParams();
    if (!isNaN(threshold)) params.append('threshold', String(threshold));
    if (limit) params.append('limit', String(limit));
    if (top) params.append('top', String(top));
    const resp = await fetch('duplicate_scan.php?' + params.toString(), { credentials: 'same-origin' });
    if (!resp.ok) { const t = await resp.text(); scanMsg.textContent = 'Server error: ' + t; resultsTbody.innerHTML = ''; return; }
    const data = await resp.json();
    lastResults = data.pairs || [];
    renderResults(lastResults);
    scanMsg.textContent = 'Scan completed. Found ' + (lastResults.length) + ' pairs. Meta: ' + JSON.stringify(data.meta || {});
  } catch (err) {
    console.error(err);
    scanMsg.textContent = 'Network error: ' + err.message;
    resultsTbody.innerHTML = '<tr><td colspan="4" class="small-muted">Error running scan.</td></tr>';
  }
});

function renderResults(rows) {
  if (!rows || rows.length === 0) {
    resultsTbody.innerHTML = '<tr><td colspan="4" class="small-muted">No pairs found.</td></tr>';
    return;
  }
  resultsTbody.innerHTML = '';
  let i=0;
  for (const r of rows) {
    i++;
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${i}</td><td>${escapeHtml(r.name_a)} (${escapeHtml(r.emp_a)})</td><td>${escapeHtml(r.name_b)} (${escapeHtml(r.emp_b)})</td><td>${Number(r.dist).toFixed(4)}</td>`;
    resultsTbody.appendChild(tr);
  }
}

btnExportCsv.addEventListener('click', () => {
  if (!lastResults || lastResults.length === 0) {
    alert('No results to export');
    return;
  }
  const rows = [['Employee A','Name A','Employee B','Name B','Distance']];
  for (const r of lastResults) {
    rows.push([r.emp_a, r.name_a, r.emp_b, r.name_b, r.dist]);
  }
  const csv = rows.map(r => r.map(cell => `"${String(cell).replace(/"/g,'""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'duplicate_scan_' + new Date().toISOString().slice(0,19).replace(/[:T]/g,'-') + '.csv';
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
});

function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>