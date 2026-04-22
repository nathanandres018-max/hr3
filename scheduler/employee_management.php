<?php
session_start();
require_once("../includes/db.php");

$fullname = $_SESSION['fullname'] ?? 'Schedule Officer';
$display_role = $_SESSION['role'] ?? 'schedule_officer';

// Handle AJAX POST request for editing employees
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_employee_modal'])) {
    header('Content-Type: application/json');
    
    try {
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid employee ID']);
            exit;
        }

        $name = trim($_POST['fullname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($name) || empty($username) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Name, Username, and Email are required']);
            exit;
        }
        
        $allowed_roles = ['employee', 'hr_manager', 'benefits_officer', 'admin', 'schedule_officer'];
        $role_emp = strtolower(trim($_POST['role'] ?? 'employee'));
        if (!in_array($role_emp, $allowed_roles)) {
            $role_emp = 'employee';
        }
        
        $job_title = trim($_POST['job_title'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        $birthday = trim($_POST['birthday'] ?? '');
        $birthday = (!empty($birthday) && $birthday !== '0000-00-00') ? $birthday : null;
        
        $date_joined = trim($_POST['date_joined'] ?? '');
        $date_joined = (!empty($date_joined) && $date_joined !== '0000-00-00') ? $date_joined : null;
        
        $valid_genders = ['Male', 'Female', 'Other', ''];
        $gender = trim($_POST['gender'] ?? '');
        $gender = (!empty($gender) && in_array($gender, $valid_genders)) ? $gender : null;
        
        $status = trim($_POST['status'] ?? 'Active');
        if (empty($status)) {
            $status = 'Active';
        }

        // Get current profile photo
        $stmt = $pdo->prepare("SELECT profile_photo FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $profile_photo_path = $current['profile_photo'] ?? null;

        // Handle profile photo upload
        if (!empty($_FILES['profile_photo']['name'])) {
            $target_dir = "../assets/images/profiles/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            
            $file_ext = strtolower(pathinfo($_FILES["profile_photo"]["name"], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_ext, $allowed_extensions)) {
                $new_file_name = uniqid("profile_") . "." . $file_ext;
                $upload_path = $target_dir . $new_file_name;
                
                if (move_uploaded_file($_FILES["profile_photo"]["tmp_name"], $upload_path)) {
                    $profile_photo_path = $upload_path;
                }
            }
        }

        // Build update query
        $update_fields = [
            "fullname = ?",
            "username = ?",
            "email = ?",
            "role = ?",
            "job_title = ?",
            "contact_number = ?",
            "birthday = ?",
            "department = ?",
            "gender = ?",
            "date_joined = ?",
            "status = ?",
            "address = ?"
        ];

        $params = [
            $name,
            $username,
            $email,
            $role_emp,
            $job_title,
            $contact_number,
            $birthday,
            $department,
            $gender,
            $date_joined,
            $status,
            $address
        ];

        // Add profile photo if updated
        if ($profile_photo_path !== null) {
            $update_fields[] = "profile_photo = ?";
            $params[] = $profile_photo_path;
        }

        // Add password if provided
        if (!empty($_POST['password'])) {
            $update_fields[] = "password = ?";
            $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }

        $params[] = $id;

        $sql = "UPDATE employees SET " . implode(", ", $update_fields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);

        if ($result) {
            echo json_encode([
                'success' => true, 
                'message' => 'Employee updated successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Failed to update employee. Please try again.'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit;
}

function getProfileImage($emp) {
    if (!empty($emp['profile_photo']) && file_exists($emp['profile_photo'])) {
        return $emp['profile_photo'];
    }
    return "../assets/images/default-profile.png";
}

$search = trim($_GET['search'] ?? '');
$params = [];
$where = '';
if ($search) {
    $where = 'WHERE fullname LIKE ? OR username LIKE ? OR email LIKE ?';
    $params = ["%$search%", "%$search%", "%$search%"];
}

$stmt = $pdo->prepare("SELECT id, fullname, username, email, role, job_title, contact_number, birthday, department, gender, date_joined, status, address, profile_photo FROM employees $where ORDER BY id DESC");
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_employees,
    SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive_count
    FROM employees";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Management - ViaHale TNVS HR3</title>
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
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .dashboard-title ion-icon { font-size: 2.2rem; color: #9A66ff; }

        .main-content { 
            flex: 1; 
            overflow-y: auto; 
            padding: 2rem 2rem;
        }

        .breadcrumbs { color: #9A66ff; font-size: 0.93rem; margin-bottom: 2rem; }

        .stats-container { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
            gap: 1.5rem; 
            margin-bottom: 2rem;
        }

        .stat-card { 
            background: linear-gradient(135deg, #fff 0%, #f8f9ff 100%); 
            border-radius: 15px; 
            padding: 1.5rem; 
            box-shadow: 0 4px 15px rgba(140,140,200,0.08); 
            border: 1px solid #f0f0f0; 
            display: flex; 
            align-items: center; 
            gap: 1.5rem;
        }

        .stat-card.total { border-left: 5px solid #3b82f6; }
        .stat-card.active { border-left: 5px solid #22c55e; }
        .stat-card.inactive { border-left: 5px solid #ef4444; }

        .stat-icon { 
            width: 60px; 
            height: 60px; 
            border-radius: 12px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 1.8rem; 
            flex-shrink: 0;
        }

        .stat-card.total .stat-icon { background: #dbeafe; color: #3b82f6; }
        .stat-card.active .stat-icon { background: #dcfce7; color: #22c55e; }
        .stat-card.inactive .stat-icon { background: #fee2e2; color: #ef4444; }

        .stat-text h3 { font-size: 2rem; font-weight: 800; margin: 0; color: #22223b; }
        .stat-text p { font-size: 0.9rem; color: #6c757d; margin: 0; }

        .search-section { 
            background: #f8f9ff; 
            border: 1px solid #e0e7ff; 
            border-radius: 12px; 
            padding: 1.5rem; 
            margin-bottom: 2rem;
        }

        .search-section form { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }

        .search-box { 
            border-radius: 8px; 
            font-size: 1rem; 
            padding: 0.8rem 1.2rem; 
            border: 1px solid #e0e7ff; 
            min-width: 260px;
            background: #fff;
            flex: 1;
        }

        .search-box:focus { border-color: #9A66ff; box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15); outline: none; }

        .btn-primary { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            border: none; 
            border-radius: 8px; 
            padding: 0.65rem 1.5rem; 
            font-weight: 600;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary:hover { 
            background: linear-gradient(90deg, #8654e0 0%, #360090 100%);
            transform: translateY(-2px);
            color: white;
        }

        .btn-link {
            color: #9A66ff !important;
            text-decoration: none;
            font-weight: 600;
        }

        .btn-link:hover { color: #4311a5 !important; }

        .card { 
            border-radius: 18px; 
            box-shadow: 0 4px 15px rgba(140,140,200,0.08); 
            border: 1px solid #f0f0f0;
            margin-bottom: 2rem;
        }

        .card-header { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            color: white; 
            border-radius: 18px 18px 0 0; 
            padding: 1.5rem; 
            border: none; 
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 1.15rem;
        }

        .card-body { padding: 0; }

        .table-responsive { background: #fff; border-radius: 0 0 18px 18px; overflow: hidden; }

        .table { font-size: 0.98rem; color: #22223b; margin-bottom: 0; }

        .table th { 
            color: #6c757d; 
            font-weight: 700; 
            border: none; 
            background: #f9f9fc; 
            padding: 1.2rem 1rem;
            font-size: 0.92rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table td { 
            border-bottom: 1px solid #e8e8f0; 
            padding: 1.2rem 1rem; 
            vertical-align: middle;
        }

        .table tbody tr { transition: all 0.2s ease; }
        .table tbody tr:hover { background: #f8f8fb; }
        .table tbody tr:last-child td { border-bottom: none; }

        .profile-thumb { 
            width: 40px; 
            height: 40px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 2px solid #9A66ff; 
            box-shadow: 0 1px 8px rgba(120,120,120,0.07);
        }

        .btn-sm { 
            padding: 0.5rem 1rem; 
            font-size: 0.85rem; 
            border-radius: 6px;
            border: none;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-outline-primary { 
            border: 1.5px solid #9A66ff; 
            color: #9A66ff;
            background: transparent;
            min-width: 90px;
        }

        .btn-outline-primary:hover { 
            background: #9A66ff; 
            color: white;
            transform: translateY(-2px);
        }

        .modal-dialog { max-width: 800px; }

        .modal-content { 
            border-radius: 18px; 
            border: 1px solid #e0e7ff; 
            box-shadow: 0 10px 40px rgba(70, 57, 130, 0.15);
        }

        .modal-header { 
            background: linear-gradient(90deg, #9A66ff 0%, #4311a5 100%); 
            color: #fff; 
            border-bottom: none; 
            border-radius: 18px 18px 0 0; 
            padding: 1.5rem;
        }

        .modal-header h5 { 
            font-family: 'QuickSand', 'Poppins', Arial, sans-serif; 
            font-size: 1.23rem; 
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-body { 
            padding: 2rem; 
            background: #fafbfc;
        }

        .modal-body label { 
            font-weight: 600; 
            margin-bottom: 0.5rem; 
            color: #22223b;
            font-size: 0.95rem;
        }

        .modal-body input, 
        .modal-body select { 
            border-radius: 8px; 
            border: 1px solid #e0e7ff; 
            padding: 0.7rem 1rem;
            background: #fff;
        }

        .modal-body input:focus, 
        .modal-body select:focus { 
            border-color: #9A66ff; 
            box-shadow: 0 0 0 0.2rem rgba(154, 102, 255, 0.15);
            outline: none;
        }

        .form-img-preview { 
            width: 90px; 
            height: 90px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 3px solid #9A66ff; 
            margin: 0 auto; 
            display: block;
        }

        .modal-footer { 
            border-top: 1px solid #e0e7ff; 
            padding: 1.2rem 2rem; 
            background: #fafbfc;
            border-radius: 0 0 18px 18px;
        }

        .btn-save { 
            background: linear-gradient(90deg, #10b981 0%, #059669 100%); 
            color: white; 
            border: none; 
            font-weight: 600;
            min-width: 130px;
        }

        .btn-save:hover { 
            background: linear-gradient(90deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
        }

        .btn-cancel { 
            background: #ef4444; 
            color: white; 
            border: none; 
            font-weight: 600;
        }

        .btn-cancel:hover { background: #dc2626; }

        .btn-close { filter: brightness(1.8); }
        
        .modal-success { 
            display: none; 
            position: fixed; 
            z-index: 99999; 
            background: rgba(0,0,0,0.4); 
            top: 0; 
            left: 0; 
            width: 100vw; 
            height: 100vh; 
            align-items: center; 
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .modal-success.show { display: flex; }

        .modal-success .box { 
            background: #fff; 
            border-radius: 18px; 
            padding: 2.5rem 3.5rem; 
            box-shadow: 0 10px 40px rgba(70, 57, 130, 0.2); 
            text-align: center;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-success .box ion-icon { 
            font-size: 3.5rem; 
            color: #10b981; 
            margin-bottom: 0.7rem;
            display: block;
        }

        .modal-success .box .title { 
            font-size: 1.4rem; 
            font-weight: 700; 
            margin-bottom: 1rem;
            color: #22223b;
        }
        
        .alert { 
            border-radius: 12px; 
            border: none; 
            border-left: 4px solid; 
            padding: 1rem; 
            margin-bottom: 1.5rem;
        }

        .alert-danger { 
            background: #fee2e2; 
            color: #991b1b; 
            border-left-color: #ef4444;
        }

        .alert-info { 
            background: #dbeafe; 
            color: #0c4a6e; 
            border-left-color: #0284c7;
        }

        .d-none { display: none !important; }

        .spinner-border { color: #9A66ff; }
        .text-danger { color: #dc3545; }
        .text-muted { color: #6c757d; }

        .form-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
            gap: 1.5rem;
        }

        .form-group { margin-bottom: 0; }

        .badge { 
            padding: 0.5rem 0.85rem; 
            border-radius: 20px; 
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .badge ion-icon { font-size: 0.95rem; }

        @media (max-width: 1200px) { 
            .sidebar { width: 180px; } 
            .content-wrapper { margin-left: 180px; } 
            .main-content { padding: 1.5rem 1rem; } 
            .stats-container { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 900px) { 
            .sidebar { left: -220px; width: 220px; } 
            .sidebar.show { left: 0; } 
            .content-wrapper { margin-left: 0; } 
            .main-content { padding: 1rem; } 
            .topbar { padding: 1rem 1.5rem; } 
            .search-section form { flex-direction: column; }
            .search-section form > * { width: 100%; }
            .form-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 700px) { 
            .dashboard-title { font-size: 1.4rem; } 
            .main-content { padding: 1rem 0.8rem; } 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; }
            .stats-container { grid-template-columns: 1fr; }
            .search-box { font-size: 0.9rem; padding: 0.4rem 0.8rem; min-width: 100%; }
            .modal-body { padding: 1.2rem; }
            .modal-footer { padding: 1rem 1.2rem; }
            .topbar { flex-direction: column; gap: 1rem; justify-content: flex-start; }
            .modal-success .box { padding: 1.5rem 2rem; }
        }

        @media (max-width: 500px) { 
            .sidebar { width: 100%; left: -100%; } 
            .sidebar.show { left: 0; } 
            .main-content { padding: 0.8rem 0.5rem; }
            .table { font-size: 0.85rem; }
            .table th, .table td { padding: 0.8rem 0.5rem; }
            .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.75rem; }
        }

        @media (min-width: 1400px) { 
            .sidebar { width: 260px; padding: 2rem 1rem; } 
            .content-wrapper { margin-left: 260px; } 
            .main-content { padding: 2.5rem 2.5rem; } 
            .stats-container { grid-template-columns: repeat(3, 1fr); }
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
                    <a class="nav-link" href="../scheduler/add_employee.php"><ion-icon name="person-add-outline"></ion-icon>Add Employee</a>
                    <a class="nav-link active" href="../scheduler/employee_management.php"><ion-icon name="people-outline"></ion-icon>Employee List</a>
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
            <a class="nav-link text-danger" href="../logout.php">
                <ion-icon name="log-out-outline"></ion-icon>Logout
            </a>
        </div>
    </div>

    <!-- Main Content Wrapper -->
    <div class="content-wrapper">
        <!-- Top Bar -->
        <div class="topbar">
            <span class="dashboard-title">
                <ion-icon name="people-outline"></ion-icon> Employee Management
            </span>
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
            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card total">
                    <div class="stat-icon">
                        <ion-icon name="people-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['total_employees'] ?? 0 ?></h3>
                        <p>Total Employees</p>
                    </div>
                </div>
                <div class="stat-card active">
                    <div class="stat-icon">
                        <ion-icon name="checkmark-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['active_count'] ?? 0 ?></h3>
                        <p>Active</p>
                    </div>
                </div>
                <div class="stat-card inactive">
                    <div class="stat-icon">
                        <ion-icon name="close-circle-outline"></ion-icon>
                    </div>
                    <div class="stat-text">
                        <h3><?= $stats['inactive_count'] ?? 0 ?></h3>
                        <p>Inactive</p>
                    </div>
                </div>
            </div>

            <!-- Search Section -->
            <div class="search-section">
                <form method="get" action="">
                    <label style="font-weight: 600; margin: 0; white-space: nowrap; display: flex; align-items: center; gap: 0.5rem;">
                        <ion-icon name="search-outline"></ion-icon> Search:
                    </label>
                    <input type="text" class="search-box" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, username, or email">
                    <button class="btn btn-primary" type="submit">
                        <ion-icon name="search-outline"></ion-icon> Search
                    </button>
                    <?php if ($search): ?>
                        <a href="employee_management.php" class="btn btn-link">
                            <ion-icon name="close-outline"></ion-icon> Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Employees Table -->
            <div class="card">
                <div class="card-header">
                    <ion-icon name="list-outline"></ion-icon> Employee Directory
                </div>
                <div class="card-body">
                    <?php if (!empty($employees)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">ID</th>
                                        <th>Name</th>
                                        <th>Job Title</th>
                                        <th>Role</th>
                                        <th>Email</th>
                                        <th>Contact</th>
                                        <th style="width: 110px;">Status</th>
                                        <th style="width: 120px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($employees as $emp): ?>
                                        <?php
                                            $profileSrc = htmlspecialchars(getProfileImage($emp));
                                            $role_map = [
                                                'employee' => 'Employee',
                                                'hr_manager' => 'HR Manager',
                                                'benefits_officer' => 'Benefits Officer',
                                                'admin' => 'Admin',
                                                'schedule_officer' => 'Schedule Officer'
                                            ];
                                        ?>
                                        <tr id="employeeRow<?= $emp['id'] ?>" class="<?= $emp['status'] == 'Inactive' ? 'table-secondary' : '' ?>">
                                            <td><strong>Emp<?= $emp['id'] ?></strong></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <img src="<?= $profileSrc ?>" class="profile-thumb" alt="Profile">
                                                    <div>
                                                        <div class="fw-bold"><?= htmlspecialchars($emp['fullname']) ?></div>
                                                        <div class="text-muted small">@<?= htmlspecialchars($emp['username']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($emp['job_title'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($role_map[$emp['role']] ?? ucfirst($emp['role'])) ?></td>
                                            <td><?= htmlspecialchars($emp['email']) ?></td>
                                            <td><?= htmlspecialchars($emp['contact_number'] ?? '-') ?></td>
                                            <td>
                                                <?php if ($emp['status'] == 'Active'): ?>
                                                    <span class="badge bg-success"><ion-icon name="checkmark-circle-outline"></ion-icon> Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><ion-icon name="close-circle-outline"></ion-icon> Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($emp), ENT_QUOTES, 'UTF-8') ?>, '<?= $profileSrc ?>', event)">
                                                    <ion-icon name="create-outline"></ion-icon> Edit
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="padding: 3rem 1rem; text-align: center;">
                            <ion-icon name="document-outline" style="font-size: 3rem; color: #d1d5db; display: block; margin-bottom: 0.5rem;"></ion-icon>
                            <p style="color: #6c757d; margin: 0; font-weight: 500;">No employees found. Try a different search.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="modal-success" id="successModal">
    <div class="box">
        <ion-icon name="checkmark-circle-outline"></ion-icon>
        <div class="title">Employee Updated Successfully!</div>
        <p style="color: #6c757d; margin: 0.5rem 0 0 0;">The employee information has been updated.</p>
        <button onclick="closeSuccessModal()" class="btn btn-primary mt-3" style="min-width: 120px;">OK</button>
    </div>
</div>

<!-- Edit Employee Modal -->
<div class="modal fade" id="editEmployeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <form class="modal-content" id="editEmployeeForm" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title">
                    <ion-icon name="create-outline"></ion-icon> Edit Employee Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="edit_employee_modal" value="1">
                <input type="hidden" name="id" id="edit_id" value="">
                
                <!-- Profile Photo Section -->
                <div class="row mb-3">
                    <div class="col-md-4 text-center">
                        <img src="../assets/images/default-profile.png" class="form-img-preview" id="editProfilePreview" alt="Profile">
                        <input type="file" class="form-control form-control-sm mt-2" id="edit_profile_photo" name="profile_photo" accept="image/*" onchange="previewEditProfileImage(event)">
                        <small class="text-muted d-block mt-1">JPG, PNG, GIF (Max 5MB)</small>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group mb-3">
                            <label class="form-label">Employee ID</label>
                            <input type="text" class="form-control" id="edit_empid" readonly style="background-color: #f5f5f5; cursor: not-allowed;">
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="fullname" id="edit_fullname" required>
                        </div>
                    </div>
                </div>

                <hr style="border-color: #e0e7ff; margin: 1.5rem 0;">

                <!-- Main Information -->
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="username" id="edit_username" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" id="edit_email" required>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Job Title</label>
                        <input type="text" class="form-control" name="job_title" id="edit_job_title" placeholder="e.g., Manager">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Number</label>
                        <input type="text" class="form-control" name="contact_number" id="edit_contact_number" placeholder="e.g., 09123456789">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Birthday</label>
                        <input type="date" class="form-control" name="birthday" id="edit_birthday">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gender</label>
                        <select class="form-control" name="gender" id="edit_gender">
                            <option value="">-- Select Gender --</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" class="form-control" name="department" id="edit_department" placeholder="e.g., HR">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date Joined</label>
                        <input type="date" class="form-control" name="date_joined" id="edit_date_joined">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select class="form-control" name="role" id="edit_role">
                            <option value="employee">Employee</option>
                            <option value="hr_manager">HR Manager</option>
                            <option value="benefits_officer">Benefits Officer</option>
                            <option value="admin">Admin</option>
                            <option value="schedule_officer">Schedule Officer</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-control" name="status" id="edit_status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Address</label>
                    <input type="text" class="form-control" name="address" id="edit_address" placeholder="Employee address">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" class="form-control" name="password" id="edit_password" placeholder="Leave blank to keep current password">
                    <small class="text-muted">Optional - Leave blank to keep current</small>
                </div>

                <!-- Error & Loading Messages -->
                <div id="edit_error_message" class="alert alert-danger d-none" role="alert"></div>
                <div id="edit_loading" class="alert alert-info d-none" role="alert">
                    <span class="spinner-border spinner-border-sm me-2"></span>
                    <span>Saving changes...</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-save" id="submitBtn">
                    <ion-icon name="checkmark-outline"></ion-icon>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openEditModal(empData, profileSrc, event) {
    event.preventDefault();
    
    // Populate form fields
    document.getElementById('edit_id').value = empData.id || '';
    document.getElementById('edit_empid').value = 'Emp' + (empData.id || '');
    document.getElementById('edit_fullname').value = empData.fullname || '';
    document.getElementById('edit_job_title').value = empData.job_title || '';
    document.getElementById('edit_email').value = empData.email || '';
    document.getElementById('edit_contact_number').value = empData.contact_number || '';
    document.getElementById('edit_birthday').value = (empData.birthday && empData.birthday !== '0000-00-00') ? empData.birthday : '';
    document.getElementById('edit_department').value = empData.department || '';
    
    const genderValue = (empData.gender && empData.gender.trim() !== '') ? empData.gender : '';
    document.getElementById('edit_gender').value = genderValue;
    
    document.getElementById('edit_date_joined').value = (empData.date_joined && empData.date_joined !== '0000-00-00') ? empData.date_joined : '';
    document.getElementById('edit_status').value = empData.status || 'Active';
    document.getElementById('edit_role').value = empData.role || 'employee';
    document.getElementById('edit_username').value = empData.username || '';
    document.getElementById('edit_address').value = empData.address || '';
    document.getElementById('edit_password').value = '';

    // Set profile preview
    document.getElementById('editProfilePreview').src = profileSrc || '../assets/images/default-profile.png';

    // Clear error messages
    document.getElementById('edit_error_message').classList.add('d-none');
    document.getElementById('edit_loading').classList.add('d-none');
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editEmployeeModal'), {
        backdrop: 'static',
        keyboard: false
    });
    modal.show();
}

function previewEditProfileImage(e) {
    const input = e.target;
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(evt) {
            document.getElementById('editProfilePreview').src = evt.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

document.getElementById('editEmployeeForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const form = this;
    const formData = new FormData(form);
    const errorDiv = document.getElementById('edit_error_message');
    const loadingDiv = document.getElementById('edit_loading');
    const submitBtn = document.getElementById('submitBtn');

    const fullname = document.getElementById('edit_fullname').value.trim();
    const username = document.getElementById('edit_username').value.trim();
    const email = document.getElementById('edit_email').value.trim();

    if (!fullname || !username || !email) {
        errorDiv.textContent = 'Name, Username, and Email are required fields';
        errorDiv.classList.remove('d-none');
        return;
    }

    loadingDiv.classList.remove('d-none');
    errorDiv.classList.add('d-none');
    submitBtn.disabled = true;

    fetch('employee_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        loadingDiv.classList.add('d-none');
        
        if (data.success) {
            const modal = bootstrap.Modal.getInstance(document.getElementById('editEmployeeModal'));
            if (modal) {
                modal.hide();
            }
            
            showSuccessModal();
            
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            errorDiv.textContent = data.message || 'Failed to update employee';
            errorDiv.classList.remove('d-none');
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        loadingDiv.classList.add('d-none');
        errorDiv.textContent = 'An error occurred: ' + error.message;
        errorDiv.classList.remove('d-none');
        submitBtn.disabled = false;
    });
});

function showSuccessModal() {
    document.getElementById("successModal").classList.add('show');
}

function closeSuccessModal() {
    document.getElementById("successModal").classList.remove('show');
}
</script>

</body>
</html>