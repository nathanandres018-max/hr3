<?php
session_start();
require_once("../includes/db.php");

$fullname = $_SESSION['fullname'] ?? 'Schedule Officer';
$display_role = $_SESSION['role'] ?? 'schedule_officer';

$msg = "";
$msg_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = trim($_POST['employee_id'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role_emp = trim($_POST['role'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $status = trim($_POST['status'] ?? '');

    if ($employee_id && $full_name && $username && $role_emp && $department && $email && $status) {
        try {
            $check_stmt = $pdo->prepare("SELECT id FROM employees WHERE employee_id = ? OR email = ?");
            $check_stmt->execute([$employee_id, $email]);
            
            if ($check_stmt->rowCount() > 0) {
                $msg = "Employee with this ID or email already exists in the system.";
                $msg_type = "warning";
            } else {
                $stmt = $pdo->prepare("INSERT INTO employees (employee_id, fullname, username, email, role, department, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $success = $stmt->execute([$employee_id, $full_name, $username, $email, $role_emp, $department, $status]);
                
                if ($success) {
                    $msg = "Employee added successfully to the system!";
                    $msg_type = "success";
                    $_POST = [];
                } else {
                    $msg = "Failed to add employee. Please try again.";
                    $msg_type = "danger";
                }
            }
        } catch (PDOException $e) {
            $msg = "Database error: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "All fields are required.";
        $msg_type = "warning";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Employee - Schedule Officer | ViaHale TNVS HR3</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        * { transition: all 0.3s ease; }
        html, body { height: 100%; }
        body { font-family: 'QuickSand', 'Poppins', Arial, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #fafbfc 100%); color: #22223b; font-size: 16px; margin: 0; padding: 0; }
        
        .wrapper { display: flex; min-height: 100vh; }
        
        .sidebar { 
            background: linear-gradient(180deg, #181818ff 0%, #1a1a2e 100%); 
            color: #fff; 
            width: 220px; 
            position: fixed; 
            left: 0; 
            top: 0; 
            height: 100vh; 
            z-index: 1040; 
            overflow-y: auto; 
            padding: 1rem 0.3rem; 
            box-shadow: 2px 0 15px rgba(0,0,0,0.1);
        }

        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: #9A66ff; border-radius: 3px; }

        .sidebar a, .sidebar button { 
            color: #bfc7d1; 
            background: none; 
            border: none; 
            font-size: 0.95rem; 
            padding: 0.45rem 0.7rem; 
            border-radius: 8px; 
            display: flex; 
            align-items: center; 
            gap: 0.7rem; 
            margin-bottom: 0.1rem; 
            width: 100%; 
            text-align: left; 
            white-space: nowrap; 
            cursor: pointer;
        }

        .sidebar a.active, .sidebar a:hover, .sidebar button.active, .sidebar button:hover { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            color: #fff; 
            padding-left: 1rem;
            box-shadow: 0 2px 8px rgba(154, 102, 255, 0.3);
        }

        .sidebar hr { border-top: 1px solid #232a43; margin: 0.7rem 0; }
        .sidebar h6 { font-size: 0.75rem; font-weight: 700; letter-spacing: 1px; color: #9A66ff; }
        .sidebar .nav-link ion-icon { font-size: 1.2rem; }

        .content-wrapper { flex: 1; margin-left: 220px; display: flex; flex-direction: column; }
        
        .topbar { 
            padding: 1.5rem 2rem; 
            background: #fff; 
            border-bottom: 2px solid #f0f0f0; 
            box-shadow: 0 2px 8px rgba(140,140,200,0.05); 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            gap: 2rem;
        }

        .topbar .profile { display: flex; align-items: center; gap: 1.2rem; }
        .topbar .profile-img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 3px solid #9A66ff; }
        .topbar .profile-info { line-height: 1.1; }
        .topbar .profile-info strong { font-size: 1.08rem; font-weight: 600; color: #22223b; }
        .topbar .profile-info small { color: #9A66ff; font-size: 0.93rem; font-weight: 500; }

        .dashboard-title { 
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif; 
            font-size: 2rem; 
            font-weight: 800; 
            margin: 0; 
            color: #22223b; 
            letter-spacing: -0.5px;
        }

        .main-content { 
            flex: 1; 
            overflow-y: auto; 
            padding: 2rem 2rem;
        }

        .breadcrumbs { color: #9A66ff; font-size: 0.93rem; margin-bottom: 2rem; }

        .dashboard-col { 
            background: #fff; 
            border-radius: 18px; 
            box-shadow: 0 4px 15px rgba(140,140,200,0.08); 
            padding: 2rem; 
            margin-bottom: 2rem; 
            border: 1px solid #f0f0f0;
        }

        .form-group { margin-bottom: 1.5rem; }

        label { 
            font-weight: 600; 
            margin-bottom: 0.5rem; 
            color: #22223b; 
            display: block;
        }

        .form-control, .form-select { 
            border-radius: 8px; 
            border: 1px solid #e0e7ff; 
            padding: 0.7rem 1rem; 
            font-size: 0.95rem;
        }

        .form-control:focus, .form-select:focus { 
            border-color: #9A66ff; 
            box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15);
        }

        .btn-primary { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            border: none; 
            border-radius: 8px; 
            padding: 0.8rem 2rem; 
            font-weight: 600;
        }

        .btn-primary:hover { 
            background: linear-gradient(90deg, #8654e0 0%, #360090 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(154, 102, 255, 0.3);
            color: white;
        }

        .btn-back { 
            background: #e0e7ff; 
            color: #9A66ff; 
            border: none; 
            border-radius: 8px; 
            padding: 0.8rem 2rem; 
            font-weight: 600; 
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-back:hover { 
            background: #9A66ff; 
            color: white;
        }

        .alert { 
            border-radius: 12px; 
            border: none; 
            border-left: 4px solid; 
            padding: 1.2rem; 
            margin-bottom: 1.5rem; 
            display: flex; 
            align-items: center; 
            gap: 0.8rem;
        }

        .alert ion-icon { font-size: 1.3rem; flex-shrink: 0; }

        .alert-success { 
            background: #d1fae5; 
            color: #065f46; 
            border-left-color: #10b981;
        }

        .alert-danger { 
            background: #fee2e2; 
            color: #991b1b; 
            border-left-color: #ef4444;
        }

        .alert-warning { 
            background: #fef3c7; 
            color: #92400e; 
            border-left-color: #f59e0b;
        }

        .employee-section { 
            background: linear-gradient(135deg, #f0e6ff 0%, #e8deff 100%); 
            border-left: 5px solid #9A66ff; 
            padding: 1.5rem; 
            border-radius: 12px; 
            margin-bottom: 2rem;
        }

        .employee-section h6 { 
            color: #7c3aed; 
            font-weight: 700; 
            margin-bottom: 0.8rem; 
            display: flex; 
            align-items: center; 
            gap: 0.5rem;
            font-size: 1rem;
        }

        .employee-section h6 ion-icon { font-size: 1.2rem; }

        .employee-section p { margin: 0; }

        .spinner { 
            width: 1rem; 
            height: 1rem; 
            border: 2px solid #f3f3f3; 
            border-top: 2px solid #9A66ff; 
            border-radius: 50%; 
            animation: spin 1s linear infinite;
        }

        @keyframes spin { 
            0% { transform: rotate(0deg); } 
            100% { transform: rotate(360deg); } 
        }

        .form-actions { 
            display: flex; 
            gap: 1rem; 
            margin-top: 2rem; 
            flex-wrap: wrap;
        }

        .form-actions .btn { flex: 1; min-width: 150px; }

        .sync-button { 
            background: #9A66ff; 
            color: white; 
            border: none; 
            border-radius: 8px; 
            padding: 0.65rem 1.5rem; 
            font-weight: 600; 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            gap: 0.5rem; 
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .sync-button:hover:not(:disabled) { 
            background: #8654e0; 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(154, 102, 255, 0.3);
        }

        .sync-button:disabled { 
            background: #d1d5db; 
            cursor: not-allowed; 
            transform: none;
        }

        .form-row { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
            gap: 1.5rem; 
            margin-bottom: 1.5rem;
        }

        .form-group-inline { margin-bottom: 0; }

        .section-header { 
            font-size: 1.2rem; 
            font-weight: 700; 
            color: #22223b; 
            margin-bottom: 1.5rem; 
            padding-bottom: 1rem; 
            border-bottom: 2px solid #e0e7ff; 
            display: flex; 
            align-items: center; 
            gap: 0.7rem;
        }

        .section-header ion-icon { 
            color: #9A66ff; 
            font-size: 1.4rem;
        }

        .text-danger { color: #dc3545; }

        @media (max-width: 1200px) { 
            .sidebar { width: 180px; } 
            .content-wrapper { margin-left: 180px; } 
            .main-content { padding: 1.5rem 1rem; } 
            .dashboard-col { padding: 1.5rem; }
        }

        @media (max-width: 900px) { 
            .sidebar { left: -220px; width: 220px; } 
            .sidebar.show { left: 0; } 
            .content-wrapper { margin-left: 0; } 
            .main-content { padding: 1rem; } 
            .topbar { padding: 1rem 1.5rem; }
            .dashboard-col { padding: 1rem; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; }
        }

        @media (max-width: 700px) { 
            .dashboard-title { font-size: 1.4rem; } 
            .main-content { padding: 1rem 0.8rem; } 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; }
            .dashboard-col { padding: 1rem 0.8rem; }
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.8rem 0.5rem; } 
            .dashboard-col { padding: 0.8rem; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .form-row { grid-template-columns: 1fr; }
        }

        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2.5rem 2.5rem; } 
            .form-row { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column justify-content-between shadow-sm border-end">
        <div>
            <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
                <img src="../assets/images/image.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
            </div>
            <div class="mb-4">
                <nav class="nav flex-column">
                    <a class="nav-link" href="../scheduler/schedule_officer_dashboard.php"><ion-icon name="home-outline"></ion-icon>Dashboard</a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Employee Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link active" href="../scheduler/add_employee.php"><ion-icon name="person-add-outline"></ion-icon>Add Employee</a>
                    <a class="nav-link" href="../scheduler/employee_management.php"><ion-icon name="people-outline"></ion-icon>Employee List</a>
                </nav>
            </div>
            <div class="mb-4">
                <h6 class="text-uppercase px-2 mb-2">Shift & Schedule Management</h6>
                <nav class="nav flex-column">
                    <a class="nav-link" href="../scheduler/shift_scheduling.php"><ion-icon name="calendar-outline"></ion-icon>Shift Scheduling</a>
                    <a class="nav-link" href="../scheduler/edit_update_schedules.php"><ion-icon name="pencil-outline"></ion-icon>Edit/Update Schedules</a>
                    <a class="nav-link" href="../scheduler/schedule_logs.php"><ion-icon name="reader-outline"></ion-icon>Schedule Logs</a>
                    <a class="nav-link" href="../scheduler/schedule_reports.php"><ion-icon name="document-text-outline"></ion-icon>Schedule Reports</a>
                </nav>
            </div>
        </div>
        <div class="p-3 border-top mb-2">
            <a class="nav-link text-danger" href="../logout.php"><ion-icon name="log-out-outline"></ion-icon>Logout</a>
        </div>
    </div>

    <!-- Main Content Wrapper -->
    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <span class="dashboard-title">Add Employee</span>
            <div class="profile">
                <img src="../assets/images/default-profile.png" class="profile-img" alt="Profile">
                <div class="profile-info">
                    <strong><?= htmlspecialchars($fullname) ?></strong><br>
                    <small>Schedule Officer</small>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="dashboard-col">
                <!-- Messages -->
                <?php if (!empty($msg)): ?>
                    <div class="alert alert-<?= $msg_type ?>">
                        <ion-icon name="<?= $msg_type === 'success' ? 'checkmark-circle-outline' : ($msg_type === 'danger' ? 'alert-circle-outline' : 'alert-outline') ?>"></ion-icon>
                        <div><?= htmlspecialchars($msg) ?></div>
                    </div>
                <?php endif; ?>

                <!-- Employee Selection from API -->
                <div class="employee-section">
                    <h6>
                        <ion-icon name="cloud-download-outline"></ion-icon>
                        Sync Employee from HR System
                    </h6>
                    <p style="font-size: 0.9rem; color: #7c3aed; margin-bottom: 1rem;">
                        Select an employee from the HR database to add them to the system. Click "Load" to fetch all available employees.
                    </p>
                    <div class="form-group">
                        <label for="employee_select" class="form-label">Available Employees</label>
                        <div style="display: flex; gap: 0.5rem; align-items: stretch;">
                            <select class="form-select" id="employee_select" style="flex: 1;" required>
                                <option value="">-- Click "Load" to sync from HR System --</option>
                            </select>
                            <button type="button" class="sync-button" id="syncButton">
                                <ion-icon name="refresh-outline"></ion-icon>
                                Load
                            </button>
                        </div>
                        <small class="text-muted" style="display: block; margin-top: 0.5rem;">
                            <ion-icon name="information-circle-outline" style="font-size: 0.9rem;"></ion-icon>
                            This will fetch all employees from the HR2 system
                        </small>
                    </div>
                </div>

                <!-- Employee Form -->
                <form method="post" id="addEmployeeForm">
                    <div class="section-header">
                        <ion-icon name="document-text-outline"></ion-icon>
                        Employee Information
                    </div>

                    <div class="form-row">
                        <div class="form-group form-group-inline">
                            <label for="employee_id" class="form-label">Employee ID</label>
                            <input type="text" class="form-control" id="employee_id" name="employee_id" readonly style="background-color: #f5f5f5;">
                            <small class="text-muted">Auto-filled from selected employee</small>
                        </div>

                        <div class="form-group form-group-inline">
                            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-group-inline">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>

                        <div class="form-group form-group-inline">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-group-inline">
                            <label for="department" class="form-label">Department <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="department" name="department" required>
                        </div>

                        <div class="form-group form-group-inline">
                            <label for="role" class="form-label">Role/Position <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="role" name="role" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="">-- Select Status --</option>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="On Leave">On Leave</option>
                            <option value="Terminated">Terminated</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <ion-icon name="checkmark-outline"></ion-icon> Add Employee
                        </button>
                        <a href="employee_management.php" class="btn btn-back">
                            <ion-icon name="arrow-back-outline"></ion-icon> Back to List
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let employeesFromApi = [];

document.addEventListener("DOMContentLoaded", () => {
    const syncButton = document.getElementById("syncButton");
    const select = document.getElementById("employee_select");

    syncButton.addEventListener("click", loadEmployees);

    function loadEmployees() {
        const apiUrl = "https://hr2.viahale.com/api/employees/list";
        const bearerToken = "secret_token_12345";
        
        syncButton.disabled = true;
        syncButton.innerHTML = '<div class="spinner"></div>';
        select.innerHTML = '<option value="">Loading employees...</option>';
        
        console.log("Fetching employees from: " + apiUrl);

        fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + bearerToken
            }
        })
        .then(response => {
            console.log("Response status:", response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log("API Response:", data);
            
            select.innerHTML = '<option value="">-- Select Employee --</option>';
            
            let employees = [];
            
            if (data.success && Array.isArray(data.data)) {
                employees = data.data;
            } else if (data.success && Array.isArray(data.employees)) {
                employees = data.employees;
            } else if (Array.isArray(data)) {
                employees = data;
            } else if (data.data && Array.isArray(data.data)) {
                employees = data.data;
            } else if (data.employees && Array.isArray(data.employees)) {
                employees = data.employees;
            }

            if (employees.length === 0) {
                select.innerHTML = '<option value="">No employees found in HR system</option>';
                console.warn("No employees found in response");
                syncButton.disabled = false;
                syncButton.innerHTML = '<ion-icon name="refresh-outline"></ion-icon> Load';
                return;
            }

            employeesFromApi = employees;
            
            employees.forEach(emp => {
                const option = document.createElement("option");
                option.value = JSON.stringify(emp);
                
                const empId = emp.employee_id || emp.id || emp.User_ID || 'N/A';
                const empName = emp.first_name && emp.last_name 
                    ? `${emp.first_name} ${emp.last_name}` 
                    : (emp.fullname || emp.name || emp.full_name || 'Unknown');
                const empDept = emp.department || emp.dept || emp.Department || 'N/A';
                
                option.textContent = `${empId} - ${empName} (${empDept})`;
                select.appendChild(option);
            });

            syncButton.disabled = false;
            syncButton.innerHTML = '<ion-icon name="refresh-outline"></ion-icon> Load';
            console.log(`Loaded ${employees.length} employees successfully`);
        })
        .catch(error => {
            console.error("Error fetching employees:", error);
            select.innerHTML = `<option value="">Error: ${error.message}</option>`;
            syncButton.disabled = false;
            syncButton.innerHTML = '<ion-icon name="refresh-outline"></ion-icon> Load';
        });
    }

    select.addEventListener("change", function() {
        if (!this.value) {
            clearForm();
            return;
        }

        try {
            const emp = JSON.parse(this.value);
            
            document.getElementById("employee_id").value = emp.employee_id || emp.id || emp.User_ID || '';
            document.getElementById("full_name").value = (emp.first_name && emp.last_name) 
                ? `${emp.first_name} ${emp.last_name}` 
                : (emp.fullname || emp.name || emp.full_name || '');
            document.getElementById("username").value = emp.username || emp.User_ID || '';
            document.getElementById("email").value = emp.email || '';
            document.getElementById("department").value = emp.department || emp.dept || emp.Department || '';
            document.getElementById("role").value = emp.job_title || emp.role || emp.position || emp.job_role || '';
            document.getElementById("status").value = emp.status || emp.active || 'Active';
            
            console.log("Employee selected:", emp);
        } catch (e) {
            console.error("Error parsing employee data:", e);
            clearForm();
        }
    });

    function clearForm() {
        document.getElementById("employee_id").value = '';
        document.getElementById("full_name").value = '';
        document.getElementById("username").value = '';
        document.getElementById("email").value = '';
        document.getElementById("department").value = '';
        document.getElementById("role").value = '';
        document.getElementById("status").value = '';
    }
});
</script>

</body>
</html>