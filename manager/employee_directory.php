<?php
require_once("../includes/db.php");
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fullname'])) {
    $employee_id = isset($_POST['employee_id']) && $_POST['employee_id'] !== '' ? $_POST['employee_id'] : null;
    $full_name = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $role = trim($_POST['role']);
    $department = trim($_POST['department']);
    $status = trim($_POST['status']);

    // Use employee_id from form if provided, else generate next
    if (!$employee_id) {
        $stmt = $pdo->query("SELECT MAX(employee_id) AS max_id FROM employees");
        $row = $stmt->fetch();
        $employee_id = $row && $row['max_id'] ? (intval($row['max_id']) + 1) : 1;
    }

    $stmt = $pdo->prepare("INSERT INTO employees 
        (employee_id, full_name, username, email, role, department, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([
        $employee_id, $full_name, $username, $email, $role, $department, $status
    ])) {
        echo "<script>alert('Employee enrolled successfully!');window.location='employee_directory.php';</script>";
        exit;
    } else {
        echo "<script>alert('Failed to enroll employee.');</script>";
    }
}

$enrolledEmployees = [];
$stmt = $pdo->query("SELECT * FROM employees");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $enrolledEmployees[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Timesheet Review & Approval - HR Manager | ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        body { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; background: #fafbfc; color: #22223b; font-size: 16px; }
        .sidebar { background: #181818ff; color: #fff; min-height: 100vh; border: none; width: 220px; position: fixed; left: 0; top: 0; z-index: 1040; transition: left 0.3s; overflow-y: auto; padding: 1rem 0.3rem 1rem 0.3rem; scrollbar-width: none; height: 100vh; -ms-overflow-style: none; }
        .sidebar::-webkit-scrollbar { display: none; width: 0px; background: transparent; }
        .sidebar a, .sidebar button { color: #bfc7d1; background: none; border: none; font-size: 0.95rem; padding: 0.45rem 0.7rem; border-radius: 8px; display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.1rem; transition: background 0.2s, color 0.2s; width: 100%; text-align: left; white-space: nowrap; }
        .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; }
        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
        .sidebar .nav-link ion-icon { font-size: 1.2rem; margin-right: 0.3rem; }
        .topbar { padding: 0.7rem 1.2rem 0.7rem 1.2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 0 !important; }
        .topbar .profile { display: flex; align-items: center; gap: 1.2rem; }
        .topbar .profile-img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; margin-right: 0.7rem; border: 2px solid #e0e7ff; }
        .topbar .profile-info { line-height: 1.1; }
        .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
        .topbar .profile-info small { color: #6c757d; font-size: 0.93rem; }
        .dashboard-title { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; font-size: 1.7rem; font-weight: 700; margin-bottom: 1.2rem; color: #22223b; }
        .main-content { margin-left: 220px; padding: 2rem 2rem 2rem 2rem; }
        .card { border-radius: 18px; box-shadow: 0 2px 8px rgba(140, 140, 200, 0.07); border: 1px solid #f0f0f0; margin-bottom: 2rem; }
        .table { font-size: 0.98rem; color: #22223b; background: #fff; }
        .table th { color: #6c757d; font-weight: 600; border: none; background: transparent; }
        .table td { border: none; background: transparent; }
        .status-badge { padding: 3px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600; display: inline-block; }
        .status-badge.success, .status-badge.approved { background: #dbeafe; color: #2563eb; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        .status-badge.danger, .status-badge.rejected { background: #fee2e2; color: #b91c1c; }
        @media (max-width: 1200px) { .main-content { padding: 1rem 0.3rem 1rem 0.3rem; } .sidebar { width: 180px; padding: 1rem 0.3rem; } .main-content { margin-left: 180px; } }
        @media (max-width: 900px) { .sidebar { left: -220px; width: 180px; padding: 1rem 0.3rem; } .sidebar.show { left: 0; } .main-content { margin-left: 0; padding: 1rem 0.5rem 1rem 0.5rem; } }
        @media (max-width: 700px) { .dashboard-title { font-size: 1.1rem; } .main-content { padding: 0.7rem 0.2rem 0.7rem 0.2rem; } .sidebar { width: 100vw; left: -100vw; padding: 0.7rem 0.2rem; } .sidebar.show { left: 0; } }
        @media (max-width: 500px) { .main-content { padding: 0.1rem 0.01rem; } .sidebar { width: 100vw; left: -100vw; padding: 0.3rem 0.01rem; } .sidebar.show { left: 0; } }
        @media (min-width: 1400px) { .sidebar { width: 260px; padding: 2rem 1rem 2rem 1rem; } .main-content { margin-left: 260px; padding: 2rem 2rem 2rem 2rem; } }
        .modal-content {
            border-radius: 16px;
            box-shadow: 0 6px 32px rgba(70, 57, 130, 0.20);
            border: 1px solid #e0e7ff;
            background: #fff;
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif;
        }
        .modal-header {
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%);
            color: #fff;
            border-bottom: none;
            border-radius: 16px 16px 0 0;
            padding: 1.1rem 1.5rem;
            box-shadow: 0 2px 8px rgba(140, 140, 200, 0.09);
        }
        .modal-title { font-size: 1.23rem; font-weight: 700; letter-spacing: 0.01em; }
        .btn-close { color: #fff !important; filter: brightness(1.8) grayscale(0.25); opacity: 0.85; transition: opacity 0.15s; }
        .btn-close:hover { opacity: 1; }
        .modal-body { background: #fafbfc; padding: 1.7rem 1.5rem 1.5rem 1.5rem; border-radius: 0 0 16px 16px; font-size: 1.02rem; color: #22223b; min-height: 120px; }
        #modalLoading { font-size: 1.1rem; color: #4311a5; font-weight: 600; }
        @media (max-width: 600px) {
            .modal-content { border-radius: 8px; padding: 0.7rem; }
            .modal-header, .modal-body { padding: 0.7rem 1rem; }
            .modal-title { font-size: 1.08rem; }
        }
    </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    <div class="sidenav col-auto p-0">
      <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
        <div>
          <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
            <img src="../assets/images/image.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase mb-2">Dashboard</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="#"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
              <a class="nav-link active" href="#"><ion-icon name="person-outline"></ion-icon>Employee Directory</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Timesheet Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../manager/timesheet_review.php"><ion-icon name="checkmark-done-outline"></ion-icon>Timesheet Review & Approval</a>
              <a class="nav-link" href="../manager/timesheet_reports.php"><ion-icon name="document-text-outline"></ion-icon>Timesheet Reports</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Leave Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="../manager/leave_requests.php"><ion-icon name="calendar-outline"></ion-icon>Leave Requests</a>
              <a class="nav-link" href="../manager/leave_balance.php"><ion-icon name="calendar-outline"></ion-icon>Leave Balance</a>
              <a class="nav-link" href="../manager/leave_history.php"><ion-icon name="calendar-outline"></ion-icon>Leave History</a>
              <a class="nav-link" href="../manager/leave_types.php"><ion-icon name="settings-outline"></ion-icon>Types of leave</a>
              <a class="nav-link" href="../manager/leave_calendar.php"><ion-icon name="calendar-outline"></ion-icon>Leave Calendar</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Claims & Reimbursement</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="#"><ion-icon name="alert-circle-outline"></ion-icon>Escalated Claims</a>
              <a class="nav-link" href="#"><ion-icon name="document-text-outline"></ion-icon>Audit & Reports</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Shift & Schedule Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="#"><ion-icon name="calendar-outline"></ion-icon>View Schedules</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Policy & Reports</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="#"><ion-icon name="settings-outline"></ion-icon>Policy Management</a>
              <a class="nav-link" href="#"><ion-icon name="stats-chart-outline"></ion-icon>General Reports</a>
            </nav>
          </div>
        </div>
        <div class="p-3 border-top mb-2">
          <a class="nav-link text-danger" href="../logout.php">
            <ion-icon name="log-out-outline"></ion-icon>Logout
          </a>
        </div>
      </div>
    </div>
    <div class="main-content col">
      <div class="topbar">
        <span class="dashboard-title">Timesheet Review & Approval</span>
        <div class="profile">
          <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
        </div>
      </div>
      <div class="card mb-4">
        <div class="card-header bg-primary text-white">Employee Directory</div>
        <div class="card-body p-0">
          <div id="employeeTable"></div>
        </div>
      </div>
      <div class="card mb-4">
        <div class="card-header bg-success text-white">Enrolled Employees</div>
        <div class="card-body p-0">
          <div id="enrolledEmployeeTable"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Timesheet Details Modal (AJAX loaded) -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="detailsModalLabel">Timesheet Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="modalLoading" style="display:none;"><div class="text-center py-4">Loading...</div></div>
        <div id="modalTimesheetDetails"></div>
      </div>
    </div>
  </div>
</div>

<!-- Notes Modal for Approve/Reject -->
<div class="modal fade" id="notesModal" tabindex="-1" aria-labelledby="notesModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="notesModalLabel">Provide Notes for <span id="notesActionLabel"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-4">
        <input type="hidden" name="timesheet_id" id="modalTimesheetId">
        <input type="hidden" name="action" id="modalAction">
        <div class="mb-3">
            <label for="notes_modal" class="form-label"><span id="notesActionLabel2"></span> notes to be sent to employee:</label>
            <textarea class="form-control" id="notes_modal" name="notes_modal" rows="4" required placeholder="E.g. Please correct your time-in for 09-03 or Good job!"><?= isset($_POST['notes_modal']) ? htmlspecialchars($_POST['notes_modal']) : '' ?></textarea>
        </div>
      </div>
      <div class="modal-footer" style="border-top: none;">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="notesModalSubmitBtn">Submit</button>
      </div>
    </form>
  </div>
</div>

<!-- Enroll Employee Modal -->
<div class="modal fade" id="enrollModal" tabindex="-1" aria-labelledby="enrollModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="enrollForm" method="post">
      <div class="modal-header">
        <h5 class="modal-title" id="enrollModalLabel">Enroll New Employee</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="employee_id" id="enroll_employee_id">
        <div class="mb-2">
          <label class="form-label">Full Name</label>
          <input type="text" name="fullname" class="form-control" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Username</label>
          <input type="text" name="username" class="form-control" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Role</label>
          <input type="text" name="role" class="form-control" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Department</label>
          <input type="text" name="department" class="form-control" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Status</label>
          <select name="status" class="form-select" required>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Enroll</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// AJAX for view details
document.querySelectorAll('.view-details-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.dataset.timesheetId;
        document.getElementById('modalTimesheetDetails').innerHTML = '';
        document.getElementById('modalLoading').style.display = '';
        fetch('timesheet_modal_data.php?id=' + encodeURIComponent(id))
            .then(r => r.text())
            .then(html => {
                document.getElementById('modalTimesheetDetails').innerHTML = html;
                document.getElementById('modalLoading').style.display = 'none';
            });
    });
});

// Approve/Reject action with Notes Modal
function openNotesModal(action, timesheetId, employeeName) {
    var modal = new bootstrap.Modal(document.getElementById('notesModal'));
    document.getElementById('modalTimesheetId').value = timesheetId;
    document.getElementById('modalAction').value = action;
    document.getElementById('notes_modal').value = '';
    var actionText = action === 'approved' ? 'Approval' : 'Rejection';
    document.getElementById('notesActionLabel').innerText = actionText + (employeeName ? ' (' + employeeName + ')' : '');
    document.getElementById('notesActionLabel2').innerText = actionText;
    document.getElementById('notes_modal').placeholder = action === 'approved'
        ? 'E.g. Good job! Thank you for submitting accurately.'
        : 'E.g. Please correct your time-in for 09-03.';
    modal.show();
}

// Load employees for directory
function loadEmployees() {
  fetch('https://administrative.viahale.com/api_endpoint/account.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && Array.isArray(data.users)) {
        let html = '<table class="table table-bordered table-sm mb-0"><thead><tr><th>ID</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        data.users.forEach(user => {
          html += `<tr>
            <td>${user.employee_id}</td>
            <td>${user.full_name}</td>
            <td>${user.username}</td>
            <td>${user.email}</td>
            <td>${user.role}</td>
            <td>${user.department}</td>
            <td>${user.status}</td>
            <td>
              <button class="btn btn-sm btn-primary enroll-btn"
                data-employee='${JSON.stringify(user)}'
                data-bs-toggle="modal"
                data-bs-target="#enrollModal">
                Enroll
              </button>
            </td>
          </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('employeeTable').innerHTML = html;

        // Attach event listeners to all enroll buttons
        document.querySelectorAll('.enroll-btn').forEach(btn => {
          btn.addEventListener('click', function() {
            const emp = JSON.parse(this.getAttribute('data-employee'));
            document.getElementById('enroll_employee_id').value = emp.employee_id || '';
            document.querySelector('#enrollModal input[name="fullname"]').value = emp.full_name || emp.fullname || '';
            document.querySelector('#enrollModal input[name="username"]').value = emp.username || '';
            document.querySelector('#enrollModal input[name="email"]').value = emp.email || '';
            document.querySelector('#enrollModal input[name="contact_number"]').value = emp.contact_number || '';
            document.querySelector('#enrollModal input[name="department"]').value = emp.department || '';
            document.querySelector('#enrollModal input[name="date_joined"]').value = emp.date_joined || emp.date_hired || '';
            document.querySelector('#enrollModal select[name="status"]').value = emp.status || 'Active';
            document.querySelector('#enrollModal input[name="role"]').value = emp.role || '';
          });
        });
      } else {
        document.getElementById('employeeTable').innerHTML = '<div class="alert alert-warning">No users found.</div>';
      }
    })
    .catch(() => {
      document.getElementById('employeeTable').innerHTML = '<div class="alert alert-danger">Failed to load employees.</div>';
    });
}
document.addEventListener('DOMContentLoaded', loadEmployees);

// Render enrolled employees
function renderEnrolledEmployees() {
  let html = '<table class="table table-bordered table-sm mb-0"><thead><tr><th>ID</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th></tr></thead><tbody>';
  if (enrolledEmployees.length > 0) {
    enrolledEmployees.forEach(emp => {
      html += `<tr>
        <td>${emp.employee_id}</td>
        <td>${emp.full_name || emp.fullname}</td>
        <td>${emp.username}</td>
        <td>${emp.email}</td>
        <td>${emp.role}</td>
        <td>${emp.department}</td>
        <td>${emp.status}</td>
      </tr>`;
    });
  } else {
    html += '<tr><td colspan="7" class="text-center">No enrolled employees.</td></tr>';
  }
  html += '</tbody></table>';
  document.getElementById('enrolledEmployeeTable').innerHTML = html;
}
document.addEventListener('DOMContentLoaded', renderEnrolledEmployees);
</script>
</body>
</html>