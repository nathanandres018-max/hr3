<?php
session_start();
require_once("../includes/db.php");

// Enhanced session validation
if (
    !isset($_SESSION['username']) ||
    !isset($_SESSION['role']) ||
    empty($_SESSION['username']) ||
    empty($_SESSION['role']) ||
    $_SESSION['role'] !== 'Schedule Officer'
) {
    session_unset();
    session_destroy();
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("Location: ../login.php");
    exit();
}
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
    session_unset();
    session_destroy();
    header("Location:../login.php");
    exit();
}
$_SESSION['last_activity'] = time();

$fullname = $_SESSION['fullname'] ?? 'Schedule Officer';
$role = $_SESSION['role'] ?? 'schedule_officer';
$user_id = $_SESSION['employee_id'] ?? null;

$isAdmin = in_array($role, ['Schedule Officer', 'Admin']);
$departments = ['ALL', 'HR', 'LOGISTICS', 'CORE', 'FINANCIAL'];
$event_types = ['Holiday', 'Company Event', 'Schedule Notice', 'Deadline'];

$msg = "";

// Handle adding a new event (with schedule_logs integration)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $event_title = $_POST['event_title'];
    $event_type = $_POST['event_type'];
    $department = $_POST['department'];
    $event_date = $_POST['event_date'];
    $end_date = $_POST['end_date'] ?? null;
    $description = $_POST['description'];
    $created_by = $user_id;

    if ($event_title && $event_type && $department && $event_date && $created_by) {
        // Insert event
        $stmt = $pdo->prepare("INSERT INTO company_calendar (event_title, event_type, department, event_date, end_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$event_title, $event_type, $department, $event_date, $end_date, $description, $created_by]);

        // Log the creation for auditing
        $details = [
            'event_title' => $event_title,
            'event_type' => $event_type,
            'department' => $department,
            'event_date' => $event_date,
            'end_date' => $end_date,
            'description' => $description
        ];
        $stmt = $pdo->prepare("INSERT INTO schedule_logs (employee_id, action, action_time, status, performed_by, department, details) VALUES (?, ?, NOW(), ?, ?, ?, ?)");
        $stmt->execute([
            $created_by,
            'Created Calendar Event',
            'Success',
            $fullname,
            $department,
            json_encode($details)
        ]);
        $msg = "Event added successfully, and logged in schedule logs!";
        // Optionally reload/redirect to prevent resubmission
        header("Location: company_calendar2.php?success=1");
        exit;
    } else {
        $msg = "All required fields must be filled.";
    }
}

$stmt = $pdo->prepare("SELECT c.*, e.fullname AS creator_name 
    FROM company_calendar c 
    JOIN employees e ON c.created_by = e.id
    ORDER BY event_date ASC");
$stmt->execute();
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Company Calendar - ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        body { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; background: #fafbfc; color: #22223b; font-size: 16px; }
        .sidebar { background: #181818ff; color: #fff; min-height: 100vh; width: 220px; position: fixed; left: 0; top: 0; z-index: 1040; overflow-y: auto; padding: 1rem 0.3rem; }
        .sidebar::-webkit-scrollbar { display: none; }
        .sidebar a, .sidebar button { color: #bfc7d1; background: none; border: none; font-size: 0.95rem; padding: 0.45rem 0.7rem; border-radius: 8px; display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.1rem; transition: background 0.2s, color 0.2s; width: 100%; text-align: left; white-space: nowrap; }
        .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); color: #fff; }
        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
        .sidebar .nav-link ion-icon { font-size: 1.2rem; margin-right: 0.3rem; }
        .topbar { padding: 0.7rem 1.2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 0 !important; }
        .topbar .profile { display: flex; align-items: center; gap: 1.2rem; }
        .topbar .profile-img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; margin-right: 0.7rem; border: 2px solid #e0e7ff; }
        .topbar .profile-info { line-height: 1.1; }
        .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
        .topbar .profile-info small { color: #6c757d; font-size: 0.93rem; }
        .dashboard-title { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; font-size: 1.7rem; font-weight: 700; margin-bottom: 1.2rem; color: #22223b; }
        .breadcrumbs { color: #9A66ff; font-size: 0.98rem; text-align: right; }
        .main-content { margin-left: 220px; padding: 2rem 2rem 2rem 2rem; }
        .calendar-container { background: #fff; border-radius: 18px; box-shadow: 0 2px 8px rgba(140,140,200,0.07); padding: 1.5rem 1.2rem; border: 1px solid #f0f0f0; }
        #calendar { background: #fff; border-radius: 8px; padding: 0.5rem; }
        .fc-toolbar-title { font-size: 1.35rem; }
        .fc-event { cursor: pointer; }
        .add-event-form { background: #fff; border-radius: 18px; box-shadow: 0 2px 8px rgba(140,140,200,0.07); padding: 1.5rem 1.2rem; margin-bottom: 1.5rem; border: 1px solid #f0f0f0; }
        @media (max-width: 1200px) { .main-content { padding: 1rem 0.3rem; } .sidebar { width: 180px; } .main-content { margin-left: 180px; } }
        @media (max-width: 900px) { .sidebar { left: -220px; width: 180px; padding: 1rem 0.3rem; } .sidebar.show { left: 0; } .main-content { margin-left: 0; padding: 1rem 0.5rem 1rem 0.5rem; } }
        @media (max-width: 700px) { .main-content { padding: 0.7rem 0.2rem 0.7rem 0.2rem; } .sidebar { width: 100vw; left: -100vw; padding: 0.7rem 0.2rem; } .sidebar.show { left: 0; } }
        @media (max-width: 500px) { .main-content { padding: 0.1rem 0.01rem; } .sidebar { width: 100vw; left: -100vw; padding: 0.3rem 0.01rem; } .sidebar.show { left: 0; } }
        @media (min-width: 1400px) { .sidebar { width: 260px; padding: 2rem 1rem 2rem 1rem; } .main-content { margin-left: 260px; padding: 2rem 2rem 2rem 2rem; } }
    </style>
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    <!-- Sidebar -->
    <div class="sidenav col-auto p-0">
      <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
        <div>
          <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
            <img src="../assets/images/image.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase mb-2">Dashboard</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="schedule_officer_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Employee Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="add_employee.php"><ion-icon name="person-add-outline"></ion-icon>Add Employee</a>
              <a class="nav-link" href="employee_management.php"><ion-icon name="people-outline"></ion-icon>Employee List</a>
            </nav>
          </div>
          <div class="mb-4">
            <h6 class="text-uppercase px-2 mb-2">Shift & Schedule Management</h6>
            <nav class="nav flex-column">
              <a class="nav-link" href="shift_scheduling.php"><ion-icon name="calendar-outline"></ion-icon>Shift Scheduling</a>
              <a class="nav-link" href="edit_update_schedules.php"><ion-icon name="pencil-outline"></ion-icon>Edit/Update Schedules</a>
              <a class="nav-link" href="shift_swap_requests.php"><ion-icon name="swap-horizontal-outline"></ion-icon>Shift Swap Requests</a>
              <a class="nav-link" href="employee_availability.php"><ion-icon name="people-outline"></ion-icon>Employee Availability</a>
              <a class="nav-link" href="schedule_logs.php"><ion-icon name="reader-outline"></ion-icon>Schedule Logs</a>
              <a class="nav-link active" href="company_calendar.php"><ion-icon name="calendar-outline"></ion-icon>Company Calendar</a>
              <a class="nav-link" href="schedule_reports.php"><ion-icon name="document-text-outline"></ion-icon>Schedule Reports</a>
              <a class="nav-link" href="scheduling_rules_policies.php"><ion-icon name="settings-outline"></ion-icon>Scheduling Rules/Policies</a>
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
    <!-- Main Content -->
    <div class="main-content col">
      <div class="topbar">
        <span class="dashboard-title">Company Calendar</span>
        <div class="profile">
          <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
          <div class="profile-info">
            <strong><?= htmlspecialchars($fullname) ?></strong><br>
            <small><?= htmlspecialchars(ucfirst($role)) ?></small>
          </div>
        </div>
      </div>
      <div class="breadcrumbs text-end mb-2">Dashboard &gt; Shift & Schedule Management &gt; Company Calendar</div>
      <?php if ($msg): ?>
        <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>
      <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">Event added successfully, and logged in schedule logs!</div>
      <?php endif; ?>
      <div class="add-event-form mb-4">
        <h5 class="mb-3">Add Calendar Event</h5>
        <form method="post" class="row g-3 align-items-center">
          <input type="hidden" name="add_event" value="1">
          <div class="col-md-3">
            <input type="text" class="form-control" name="event_title" placeholder="Event Title" required>
          </div>
          <div class="col-md-2">
            <select class="form-select" name="event_type" required>
              <option value="">Type</option>
              <?php foreach($event_types as $et): ?>
                <option value="<?= htmlspecialchars($et) ?>"><?= htmlspecialchars($et) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <select class="form-select" name="department" required>
              <option value="">Department</option>
              <?php foreach($departments as $dept): ?>
                <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <input type="date" class="form-control" name="event_date" required>
          </div>
          <div class="col-md-2">
            <input type="date" class="form-control" name="end_date" placeholder="End Date (optional)">
          </div>
          <div class="col-md-8 mt-2">
            <input type="text" class="form-control" name="description" placeholder="Description">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-success">Add Event</button>
          </div>
        </form>
      </div>
      <div class="calendar-container mt-4">
        <div id="calendar"></div>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var calendarEl = document.getElementById('calendar');
  var calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    height: 'auto',
    eventClick: function(info) {
      // Show event details in a modal
      let event = info.event.extendedProps;
      let html = `<h5>${info.event.title}</h5>
      <div><strong>Type:</strong> ${event.event_type}</div>
      <div><strong>Date:</strong> ${info.event.startStr}${event.end_date ? ' - ' + event.end_date : ''}</div>
      <div><strong>Department:</strong> ${event.department || 'ALL'}</div>
      <div><strong>Description:</strong> ${event.description || ''}</div>
      <div><strong>Created By:</strong> ${event.creator_name}</div>`;
      let modal = new bootstrap.Modal(document.getElementById('eventModal'));
      document.getElementById('eventModalBody').innerHTML = html;
      modal.show();
    },
    events: [
      <?php foreach($events as $ev): ?>
      {
        id: <?= $ev['id'] ?>,
        title: "<?= htmlspecialchars($ev['event_title'], ENT_QUOTES) ?>",
        start: "<?= $ev['event_date'] ?>",
        end: <?= $ev['end_date'] ? '"'.$ev['end_date'].'"' : 'null' ?>,
        color: <?= json_encode(
          $ev['event_type']=='Holiday' ? '#f5222d' :
          ($ev['event_type']=='Company Event' ? '#1890ff' :
          ($ev['event_type']=='Deadline' ? '#faad14' : '#52c41a'))
        ) ?>,
        event_type: "<?= htmlspecialchars($ev['event_type']) ?>",
        department: "<?= htmlspecialchars($ev['department'] ?? 'ALL') ?>",
        description: <?= json_encode($ev['description']) ?>,
        creator_name: <?= json_encode($ev['creator_name']) ?>,
        end_date: <?= $ev['end_date'] ? json_encode($ev['end_date']) : 'null' ?>
      },
      <?php endforeach; ?>
    ]
  });
  calendar.render();
});
</script>

<!-- Event Details Modal -->
<div class="modal fade" id="eventModal" tabindex="-1" aria-labelledby="eventModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="eventModalLabel">Event Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="eventModalBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>