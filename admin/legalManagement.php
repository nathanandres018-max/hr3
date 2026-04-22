    <?php
    // filepath: c:\xampp\htdocs\Administrative\LegalManagement\LegalOfficer\index.php
    include_once("../connection.php");
    // Enhanced session validation
    
    session_start();
    
    if (
        !isset($_SESSION['username']) ||
        !isset($_SESSION['role']) ||
        empty($_SESSION['username']) ||
        empty($_SESSION['role']) ||
        $_SESSION['role'] !== 'HR3 Admin'
    ) {
        // Clear any existing session data
        session_unset();
        session_destroy();
    
        // Prevent caching
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
    
        // Redirect to login
        header("Location: ../login.php");
        exit();
    }
    
    // Optional: Add session timeout check
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
        session_unset();
        session_destroy();
        header("Location:../login.php");
        exit();
    }
    $_SESSION['last_activity'] = time();
    $fullname = $_SESSION['fullname'] ?? 'Administrator';
    $role = $_SESSION['role'] ?? 'admin';
    $employee_id = $_SESSION['employee_id'] ?? 'EMP001';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>Legal Management System</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
      <style>
        :root {
          --primary-gradient: linear-gradient(135deg, #a78bfa 0%, #6366f1 100%);
          --sidebar-bg: #18181b;
          --card-shadow: 0 2px 8px rgba(140, 140, 200, 0.07);
          --card-hover-shadow: 0 6px 24px rgba(108,71,255,0.13);
        }
        
        * { box-sizing: border-box; }
        body { 
          background: #f7f8fa; 
          font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
          margin: 0;
          padding: 0;
        }
        
        /* Sidebar Styles */
        .sidebar {
          width: 260px;
          min-height: 100vh;
          background: var(--sidebar-bg);
          color: #fff;
          position: fixed;
          left: 0; top: 0; bottom: 0;
          z-index: 100;
          padding: 2rem 1rem 1rem 1rem;
          display: flex;
          flex-direction: column;
          justify-content: space-between;
          transition: left 0.3s ease;
        }
        
        .sidebar .logo-container {
          margin-bottom: 2rem;
        }
        
        .sidebar .nav-link {
          color: #bfc7d1;
          border-radius: 8px;
          margin-bottom: 0.5rem;
          font-weight: 500;
          transition: all 0.2s;
          display: flex;
          align-items: center;
          gap: 0.7rem;
          font-size: 1.08rem;
          padding: 0.75rem 1rem;
        }
        
        .sidebar .nav-link.active,
        .sidebar .nav-link:hover {
          background: var(--primary-gradient);
          color: #fff;
          text-decoration: none;
        }
        
        .sidebar .logout-link {
          color: #f87171;
          font-weight: 600;
          display: flex;
          align-items: center;
          gap: 0.7rem;
          text-decoration: none;
          padding: 0.75rem 1rem;
          border-radius: 8px;
          transition: background 0.2s;
        }
        
        .sidebar .logout-link:hover {
          background: rgba(248, 113, 113, 0.1);
        }
        
        /* Main Content */
        .main-content {
          margin-left: 260px;
          padding: 2.5rem;
          min-height: 100vh;
        }
        
        /* Header */
        .page-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 2rem;
          flex-wrap: wrap;
          gap: 1rem;
        }
        
        .dashboard-title {
          font-size: 2.2rem;
          font-weight: 800;
          color: #18181b;
          margin-bottom: 0.2rem;
          letter-spacing: 0.5px;
        }
        
        .dashboard-desc {
          color: #6c757d;
          font-size: 1.13rem;
        }
        
        .header-actions {
          display: flex;
          gap: 0.75rem;
          align-items: center;
          flex-wrap: wrap;
        }
        
        /* Stats Cards */
        .stats-cards {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
          gap: 1.5rem;
          margin-bottom: 2.2rem;
        }
        
        .stats-card {
          background: #fff;
          border-radius: 18px;
          box-shadow: var(--card-shadow);
          padding: 1.5rem 1.2rem;
          text-align: center;
          display: flex;
          flex-direction: column;
          align-items: center;
          gap: 0.5rem;
          border: 1px solid #f0f0f0;
          transition: all 0.3s ease;
          cursor: pointer;
        }
        
        .stats-card:hover {
          box-shadow: var(--card-hover-shadow);
          transform: translateY(-4px) scale(1.03);
          border-color: #a78bfa;
        }
        
        .stats-card .icon {
          background: var(--primary-gradient);
          color: #fff;
          border-radius: 50%;
          width: 48px;
          height: 48px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 1.5rem;
          margin-bottom: 0.5rem;
          box-shadow: 0 2px 8px rgba(140,140,200,0.13);
        }
        
        .stats-card .label {
          font-size: 1.08rem;
          color: #6366f1;
          margin-bottom: 0.2rem;
          font-weight: 600;
          letter-spacing: 0.5px;
        }
        
        .stats-card .value {
          font-size: 2.1rem;
          font-weight: 800;
          color: #18181b;
          letter-spacing: -1px;
        }
        
        /* Filter Bar */
        .filter-bar {
          background: #fff;
          border-radius: 12px;
          box-shadow: var(--card-shadow);
          padding: 1.2rem 1rem;
          margin-bottom: 2rem;
          border: 1px solid #ececec;
          display: flex;
          flex-wrap: wrap;
          gap: 1rem;
          align-items: center;
        }
        
        .filter-bar .form-control,
        .filter-bar .form-select {
          border-radius: 8px;
          border: 1px solid #e5e7eb;
        }
        
        /* Request Cards */
        .request-card {
          background: #fff;
          border-radius: 14px;
          box-shadow: var(--card-shadow);
          overflow: hidden;
          margin-bottom: 1.5rem;
          transition: all 0.2s;
          padding: 1.5rem 1.2rem;
          border: 1px solid #ececec;
          cursor: pointer;
          position: relative;
        }
        
        .request-card:hover {
          box-shadow: var(--card-hover-shadow);
          border-color: #a78bfa;
        }
        
        .request-card .request-title {
          color: #18181b;
          font-size: 1.2rem;
          font-weight: 700;
          margin-bottom: 0.5rem;
        }
        
        .request-card .status-badge {
          position: absolute;
          top: 1.2rem;
          right: 1.2rem;
          font-size: 0.9rem;
          font-weight: 700;
          border-radius: 8px;
          padding: 0.3em 1em;
          background: #f3f4f6;
          color: #a78bfa;
          text-transform: capitalize;
        }
        
        .status-badge.pending,
        .status-badge.in-progress { background: #fef9c3; color: #eab308; }
        .status-badge.approved { background: #bbf7d0; color: #22c55e; }
        .status-badge.rejected { background: #fecaca; color: #ef4444; }
        .status-badge.completed { background: #dbeafe; color: #2563eb; }
        
        /* Modal Styles */
        .modal-content {
          border-radius: 18px;
          box-shadow: 0 6px 32px rgba(108,71,255,0.10);
          border: 1px solid #ececec;
        }
        
        .modal-header {
          border-bottom: 1px solid #f0f0f0;
          background: #f7f8fa;
          border-radius: 18px 18px 0 0;
        }
        
        .modal-title {
          font-weight: 700;
          color: #6366f1;
        }
        
        /* Notification Bell */
        .notif-wrapper {
          position: relative;
          display: inline-block;
        }
        
        .notif-bell {
          border-radius: 50%;
          padding: 0.6rem 0.7rem;
          border: 1px solid #e5e7eb;
          background: #fff;
          cursor: pointer;
          transition: all 0.2s;
        }
        
        .notif-bell:hover {
          background: #f9fafb;
          border-color: #a78bfa;
        }
        
        .notif-badge {
          position: absolute;
          top: -5px;
          right: -5px;
          font-size: 0.75rem;
          min-width: 20px;
          height: 20px;
          display: flex;
          align-items: center;
          justify-content: center;
        }
        
        .notif-dropdown {
          position: absolute;
          right: 0;
          top: 110%;
          min-width: 320px;
          max-width: 400px;
          z-index: 1000;
          display: none;
          box-shadow: 0 10px 40px rgba(0,0,0,0.15);
          border-radius: 12px;
          overflow: hidden;
        }
        
        .notif-dropdown.show {
          display: block;
        }
        
        /* Comments Section */
        .comments-section {
          max-height: 250px;
          overflow-y: auto;
          background: #f8fafc;
          border-radius: 8px;
          padding: 1rem;
          margin-bottom: 1rem;
        }
        
        .comment-item {
          margin-bottom: 1rem;
          padding-bottom: 0.75rem;
          border-bottom: 1px solid #e5e7eb;
        }
        
        .comment-item:last-child {
          border-bottom: none;
          margin-bottom: 0;
          padding-bottom: 0;
        }
        
        .comment-author {
          font-weight: 600;
          color: #6366f1;
        }
        
        .comment-date {
          font-size: 0.85rem;
          color: #888;
          margin-left: 0.5rem;
        }
        
        .comment-text {
          margin-top: 0.25rem;
          color: #374151;
        }
        
        /* Timeline */
        .status-timeline {
          list-style: none;
          padding-left: 0;
        }
        
        .status-timeline li {
          margin-bottom: 0.75rem;
          padding-left: 1.5rem;
          position: relative;
        }
        
        .status-timeline li::before {
          content: '';
          position: absolute;
          left: 0;
          top: 0.5rem;
          width: 8px;
          height: 8px;
          background: #6366f1;
          border-radius: 50%;
        }
        
        /* Document Preview */
        .document-preview {
          display: flex;
          align-items: center;
          gap: 0.75rem;
          padding: 0.75rem;
          background: #f8fafc;
          border-radius: 8px;
          border: 1px solid #e5e7eb;
          margin-top: 0.5rem;
        }
        
        .document-preview i {
          font-size: 2rem;
          color: #6366f1;
        }
        
        .document-info {
          flex: 1;
        }
        
        .document-name {
          font-weight: 600;
          color: #18181b;
          font-size: 0.95rem;
        }
        
        .document-size {
          font-size: 0.85rem;
          color: #6c757d;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
          .sidebar {
            left: -260px;
          }
          
          .sidebar.show {
            left: 0;
          }
          
          .main-content {
            margin-left: 0;
          }
          
          .mobile-menu-btn {
            display: block;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 99;
            background: var(--sidebar-bg);
            color: #fff;
            border: none;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
          }
        }
        
        .mobile-menu-btn {
          display: none;
        }
        
        /* Loading Spinner */
        .spinner-wrapper {
          display: flex;
          justify-content: center;
          align-items: center;
          padding: 3rem;
        }
        
        /* Empty State */
        .empty-state {
          text-align: center;
          padding: 3rem 1rem;
          color: #6c757d;
        }
        
        .empty-state i {
          font-size: 4rem;
          color: #d1d5db;
          margin-bottom: 1rem;
        }
      </style>
    </head>
    <body>
    
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
      <i class="bi bi-list"></i>
    </button>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
      <div>
        <div class="logo-container m-2">
          <img src="../../../asset/image.png" class="img-fluid" style="height:45px;" alt="Logo">
        </div>
        <nav class="nav flex-column">
          <a class="nav-link active" href="#"><i class="bi bi-folder2-open"></i> Requests</a>
          <a class="nav-link" href="document.php"><i class="bi bi-archive"></i> Documents</a>
        </nav>
      </div>
      <div>
        <hr class="bg-secondary">
        <a href="admin_dashboard.php" class="logout-link">
          <i class="bi bi-box-arrow-left"></i> Back to Dashboard
        </a>
      </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
      <!-- Page Header -->
      <div class="page-header">
        <div>
          <div class="dashboard-title">Legal Document Management</div>
          <div class="dashboard-desc">Manage and track legal requests efficiently</div>
        </div>
        <div class="header-actions">
          <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#legalRequestModal">
            <i class="bi bi-file-earmark-text"></i> New Request
          </button>
          <button class="btn btn-success" id="exportBtn">
            <i class="bi bi-file-earmark-excel"></i> Export
          </button>
          <button class="btn btn-primary" id="analyticsBtn">
            <i class="bi bi-bar-chart"></i> Analytics
          </button>
          
          <!-- Notification Bell -->
          <div class="notif-wrapper">
            <button class="notif-bell" id="notifBell">
              <i class="bi bi-bell" style="font-size:1.3rem;"></i>
              <span class="notif-badge badge bg-danger" id="notifBadge" style="display:none;">0</span>
            </button>
            <div class="card notif-dropdown" id="notifDropdown">
              <div class="card-header fw-bold py-2 px-3">
                <i class="bi bi-bell-fill me-2"></i>Notifications
              </div>
              <ul class="list-group list-group-flush" style="max-height:300px;overflow-y:auto;" id="notifList">
                <li class="list-group-item text-center text-muted">Loading...</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    
      <!-- Stats Cards -->
      <div class="stats-cards" id="statsCards">
        <div class="stats-card">
          <div class="icon"><i class="bi bi-file-earmark-text"></i></div>
          <div class="label">Total Requests</div>
          <div class="value">0</div>
        </div>
        <div class="stats-card">
          <div class="icon"><i class="bi bi-hourglass-split"></i></div>
          <div class="label">Pending</div>
          <div class="value text-warning">0</div>
        </div>
        <div class="stats-card">
          <div class="icon"><i class="bi bi-check-circle"></i></div>
          <div class="label">Approved</div>
          <div class="value text-success">0</div>
        </div>
        <div class="stats-card">
          <div class="icon"><i class="bi bi-x-circle"></i></div>
          <div class="label">Rejected</div>
          <div class="value text-danger">0</div>
        </div>
      </div>
    
      <!-- Filter Bar -->
      <div class="filter-bar">
        <input type="text" class="form-control" id="searchInput" placeholder="Search by title, ID, or requester..." style="max-width:250px;">
        <select class="form-select" id="departmentFilter" style="max-width:180px;">
          <option value="">All Departments</option>
          <option value="Administrative">Administrative</option>
          <option value="HR">HR</option>
          <option value="Finance">Finance</option>
        </select>
        <select class="form-select" id="typeFilter" style="max-width:180px;">
          <option value="">All Types</option>
          <option value="Contract Review">Contract Review</option>
          <option value="Documentation Validation">Documentation Validation</option>
          <option value="Legal Opinion">Legal Opinion</option>
          <option value="Template Request">Template Request</option>
          <option value="Signature Coordination">Signature Coordination</option>
          <option value="Policy Drafting">Policy Drafting</option>
          <option value="Compliance Check">Compliance Check</option>
          <option value="Risk Assessment">Risk Assessment</option>
          <option value="Others">Others</option>
        </select>
        <select class="form-select" id="statusFilter" style="max-width:150px;">
          <option value="">All Status</option>
          <option value="pending">Pending</option>
          <option value="in progress">In Progress</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="completed">Completed</option>
        </select>
        <input type="date" class="form-control" id="dateFrom" style="max-width:150px;">
        <span class="text-muted">to</span>
        <input type="date" class="form-control" id="dateTo" style="max-width:150px;">
        <button class="btn btn-primary" id="filterBtn">
          <i class="bi bi-funnel"></i> Filter
        </button>
        <button class="btn btn-outline-secondary" id="clearFiltersBtn">
          <i class="bi bi-x-circle"></i> Clear
        </button>
      </div>
    
      <!-- Requests List -->
      <div id="legalRequestsList">
        <div class="spinner-wrapper">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Legal Request Modal -->
    <div class="modal fade" id="legalRequestModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <form class="modal-content" id="legalRequestForm" enctype="multipart/form-data">
          <div class="modal-header">
            <h5 class="modal-title">
              <i class="bi bi-file-earmark-plus me-2"></i>Create Legal Request
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="user_id" value="<?php echo $employee_id; ?>">
            <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
            
            <div class="row">
              <div class="col-md-12 mb-3">
                <label class="form-label fw-semibold">Request Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" placeholder="Enter request title" required>
              </div>
              
              <div class="col-md-12 mb-3">
                <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                <textarea name="description" class="form-control" rows="4" placeholder="Provide detailed description" required></textarea>
              </div>
              
              <div class="col-md-6 mb-3">
                <label class="form-label fw-semibold">Request Type <span class="text-danger">*</span></label>
                <select name="request_type" class="form-select" required id="request_type_select">
                  <option value="">Select type...</option>
                  <option value="Contract Review">Contract Review</option>
                  <option value="Documentation Validation">Documentation Validation</option>
                  <option value="Legal Opinion">Legal Opinion</option>
                  <option value="Template Request">Template Request</option>
                  <option value="Signature Coordination">Signature Coordination</option>
                  <option value="Policy Drafting">Policy Drafting</option>
                  <option value="Compliance Check">Compliance Check</option>
                  <option value="Risk Assessment">Risk Assessment</option>
                  <option value="Others">Others</option>
                </select>
              </div>
              
              <div class="col-md-6 mb-3" id="otherRequestTypeDesc" style="display:none;">
                <label class="form-label fw-semibold">Specify Other Type</label>
                <input type="text" name="other_request_type" class="form-control" placeholder="Enter request type">
              </div>
              
              <div class="col-md-4 mb-3">
                <label class="form-label fw-semibold">Priority</label>
                <select name="priority" class="form-select">
                  <option value="Low">Low</option>
                  <option value="Medium" selected>Medium</option>
                  <option value="High">High</option>
                </select>
              </div>
              
              <div class="col-md-4 mb-3">
                <label class="form-label fw-semibold">Complexity</label>
                <select name="complexity_level" class="form-select">
                  <option value="Low" selected>Low</option>
                  <option value="Medium">Medium</option>
                  <option value="High">High</option>
                </select>
              </div>
              
              <div class="col-md-4 mb-3">
                <label class="form-label fw-semibold">Urgency</label>
                <select name="urgency" class="form-select">
                  <option value="Low" selected>Low</option>
                  <option value="Medium">Medium</option>
                  <option value="High">High</option>
                </select>
              </div>
              
              <div class="col-md-6 mb-3">
                <label class="form-label fw-semibold">Stakeholders</label>
                <input type="text" name="stakeholders" class="form-control" placeholder="e.g., HR, Finance, Legal">
              </div>
              
              <div class="col-md-6 mb-3">
                <label class="form-label fw-semibold">Deadline</label>
                <input type="date" name="deadline" class="form-control">
              </div>
              
              <div class="col-md-12 mb-3">
                <label class="form-label fw-semibold">Purpose</label>
                <textarea name="purpose" class="form-control" rows="2" placeholder="Explain the purpose of this request"></textarea>
              </div>
              
              <div class="col-md-12 mb-3">
                <label class="form-label fw-semibold">Attach Document</label>
                <input type="file" name="document" class="form-control" id="documentUpload" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                <small class="text-muted">Accepted formats: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG (Max: 10MB)</small>
                <div id="filePreview" class="mt-2" style="display:none;">
                  <div class="document-preview">
                    <i class="bi bi-file-earmark-text"></i>
                    <div class="document-info">
                      <div class="document-name" id="fileName"></div>
                      <div class="document-size" id="fileSize"></div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="removeFile">
                      <i class="bi bi-x"></i>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" id="submitRequestBtn">
              <i class="bi bi-send me-1"></i>Submit Request
            </button>
          </div>
        </form>
      </div>
    </div>
    
    <!-- Request Details Modal -->
    <div class="modal fade" id="requestDetailsModal" tabindex="-1">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h4 class="modal-title mb-1" id="detailsTitle">Request Title</h4>
              <div class="text-muted" id="detailsRequestId">Request ID: REQ-XXXX</div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="row mb-4">
              <div class="col-md-3 mb-3">
                <div class="fw-semibold text-muted small">DEPARTMENT</div>
                <div class="h6 mb-0" id="detailsDepartment">-</div>
              </div>
              <div class="col-md-3 mb-3">
                <div class="fw-semibold text-muted small">REQUEST TYPE</div>
                <div class="h6 mb-0" id="detailsRequestType">-</div>
              </div>
              <div class="col-md-2 mb-3">
                <div class="fw-semibold text-muted small">PRIORITY</div>
                <span class="badge bg-warning text-dark" id="detailsPriority">Medium</span>
              </div>
              <div class="col-md-2 mb-3">
                <div class="fw-semibold text-muted small">STATUS</div>
                <span class="badge status-badge" id="detailsStatus">Pending</span>
              </div>
              <div class="col-md-2 mb-3">
                <div class="fw-semibold text-muted small">DEADLINE</div>
                <div class="h6 mb-0" id="detailsDeadline">-</div>
              </div>
            </div>
    
            <div class="row">
              <div class="col-lg-8">
                <div class="mb-4">
                  <h6 class="fw-bold mb-2"><i class="bi bi-file-text me-2"></i>Description</h6>
                  <div class="p-3 bg-light rounded" id="detailsDescription">-</div>
                </div>
    
                <div class="mb-4">
                  <h6 class="fw-bold mb-2"><i class="bi bi-bullseye me-2"></i>Purpose</h6>
                  <div class="p-3 bg-light rounded" id="detailsPurpose">-</div>
                </div>
    
                <div class="mb-4">
                  <h6 class="fw-bold mb-2"><i class="bi bi-paperclip me-2"></i>Attached Documents</h6>
                  <div id="detailsAttachmentDiv"></div>
                  <div id="detailsNoAttachment" class="text-muted small">No documents attached</div>
                </div>
    
                <div class="mb-4">
                  <h6 class="fw-bold mb-2"><i class="bi bi-chat-dots me-2"></i>Comments</h6>
                  <div class="comments-section" id="commentsList">
                    <div class="text-muted text-center">No comments yet</div>
                  </div>
                  <div class="input-group">
                    <input type="text" class="form-control" id="commentInput" placeholder="Type your comment...">
                    <button class="btn btn-primary" id="postCommentBtn">
                      <i class="bi bi-send"></i> Post
                    </button>
                  </div>
                </div>
              </div>
    
              <div class="col-lg-4">
                <div class="mb-4">
                  <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-2"></i>Status Timeline</h6>
                  <ul class="status-timeline" id="detailsTimeline">
                    <li class="text-muted">No status history</li>
                  </ul>
                </div>
    
                <div class="mb-3">
                  <h6 class="fw-bold mb-2"><i class="bi bi-people me-2"></i>Additional Info</h6>
                  <div class="p-3 bg-light rounded small">
                    <div class="mb-2">
                      <strong>Requested by:</strong>
                      <div id="detailsRequestedBy">-</div>
                    </div>
                    <div class="mb-2">
                      <strong>Stakeholders:</strong>
                      <div id="detailsStakeholders">-</div>
                    </div>
                    <div class="mb-2">
                      <strong>Complexity:</strong>
                      <div id="detailsComplexity">-</div>
                    </div>
                    <div class="mb-2">
                      <strong>Urgency:</strong>
                      <div id="detailsUrgency">-</div>
                    </div>
                    <div>
                      <strong>Created:</strong>
                      <div id="detailsCreatedAt">-</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Global Variables
    let allRequests = [];
    let stats = { total: 0, pending: 0, approved: 0, rejected: 0, completed: 0 };
    let currentRequestId = null;
    const currentEmployeeId = '<?php echo $employee_id; ?>';
    const API_BASE = 'https://administrative.viahale.com/api_endpoint/legaldocument.php';
    
    // Check if we should use alternative submission method
    const USE_FORM_ACTION = false; // Set to true if API continues to fail
    
    // ==================== INITIALIZATION ====================
    document.addEventListener('DOMContentLoaded', function() {
      initializeApp();
      setupEventListeners();
      fetchLegalRequests();
      loadNotifications();
      
      // Auto-refresh every 60 seconds
      setInterval(fetchLegalRequests, 60000);
      setInterval(loadNotifications, 60000);
    });
    
    function initializeApp() {
      // Mobile menu toggle
      const mobileMenuBtn = document.getElementById('mobileMenuBtn');
      const sidebar = document.getElementById('sidebar');
      
      if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function() {
          sidebar.classList.toggle('show');
        });
      }
      
      // Close sidebar when clicking outside on mobile
      document.addEventListener('click', function(e) {
        if (window.innerWidth <= 992) {
          if (!sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
            sidebar.classList.remove('show');
          }
        }
      });
    }
    
    function setupEventListeners() {
      // Filter events
      document.getElementById('filterBtn').addEventListener('click', renderRequests);
      document.getElementById('clearFiltersBtn').addEventListener('click', clearFilters);
      document.getElementById('searchInput').addEventListener('input', debounce(renderRequests, 300));
      document.getElementById('departmentFilter').addEventListener('change', renderRequests);
      document.getElementById('typeFilter').addEventListener('change', renderRequests);
      document.getElementById('statusFilter').addEventListener('change', renderRequests);
      
      // Date filters
      document.getElementById('dateFrom').addEventListener('change', renderRequests);
      document.getElementById('dateTo').addEventListener('change', renderRequests);
      
      // Request type select
      const reqTypeSelect = document.getElementById('request_type_select');
      const otherTypeDiv = document.getElementById('otherRequestTypeDesc');
      reqTypeSelect.addEventListener('change', function() {
        otherTypeDiv.style.display = this.value === 'Others' ? 'block' : 'none';
      });
      
      // File upload preview
      const fileInput = document.getElementById('documentUpload');
      fileInput.addEventListener('change', handleFileSelect);
      
      document.getElementById('removeFile').addEventListener('click', function() {
        fileInput.value = '';
        document.getElementById('filePreview').style.display = 'none';
      });
      
      // Form submission
      document.getElementById('legalRequestForm').addEventListener('submit', handleFormSubmit);
      
      // Comment posting
      document.getElementById('postCommentBtn').addEventListener('click', postComment);
      document.getElementById('commentInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
          postComment();
        }
      });
      
      // Notification bell
      const notifBell = document.getElementById('notifBell');
      const notifDropdown = document.getElementById('notifDropdown');
      
      notifBell.addEventListener('click', function(e) {
        e.stopPropagation();
        notifDropdown.classList.toggle('show');
      });
      
      document.addEventListener('click', function(e) {
        if (!notifDropdown.contains(e.target) && !notifBell.contains(e.target)) {
          notifDropdown.classList.remove('show');
        }
      });
      
      // Request cards click delegation
      document.getElementById('legalRequestsList').addEventListener('click', function(e) {
        const card = e.target.closest('.request-card');
        if (card) {
          const requestId = card.getAttribute('data-request-id');
          showRequestDetails(requestId);
        }
      });
    }
    
    // ==================== FILE HANDLING ====================
    function handleFileSelect(e) {
      const file = e.target.files[0];
      if (!file) return;
      
      // Validate file size (10MB max)
      const maxSize = 10 * 1024 * 1024; // 10MB
      if (file.size > maxSize) {
        alert('File size exceeds 10MB. Please choose a smaller file.');
        e.target.value = '';
        return;
      }
      
      // Validate file type
      const allowedTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/jpg',
        'image/png'
      ];
      
      if (!allowedTypes.includes(file.type)) {
        alert('Invalid file type. Please upload PDF, DOC, DOCX, XLS, XLSX, JPG, or PNG files.');
        e.target.value = '';
        return;
      }
      
      // Show preview
      document.getElementById('fileName').textContent = file.name;
      document.getElementById('fileSize').textContent = formatFileSize(file.size);
      document.getElementById('filePreview').style.display = 'block';
    }
    
    function formatFileSize(bytes) {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }
    
    // ==================== FORM SUBMISSION ====================
    async function handleFormSubmit(e) {
      e.preventDefault();
      
      const submitBtn = document.getElementById('submitRequestBtn');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Submitting...';
      submitBtn.disabled = true;
      
      try {
        const formData = new FormData(e.target);
        formData.set('action', 'add');
        
        // Ensure defaults are set
        if (!formData.get('priority')) formData.set('priority', 'Medium');
        if (!formData.get('complexity_level')) formData.set('complexity_level', 'Low');
        if (!formData.get('urgency')) formData.set('urgency', 'Low');
        
        // Log formData for debugging
        console.log('Submitting form data:');
        for (let [key, value] of formData.entries()) {
          console.log(key, value);
        }
        
        const response = await fetch(API_BASE, {
          method: 'POST',
          body: formData
        });
        
        // Check if response is ok
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Get response text first
        const responseText = await response.text();
        console.log('API Response:', responseText);
        
        // Try to parse as JSON
        let data;
        try {
          data = JSON.parse(responseText);
        } catch (parseError) {
          console.error('JSON Parse Error:', parseError);
          console.error('Response was:', responseText.substring(0, 500));
          throw new Error('Server returned invalid response. Please check API endpoint.');
        }
        
        if (data.success || data.status === 'success') {
          showNotification('Success!', data.message || 'Legal request submitted successfully', 'success');
          e.target.reset();
          document.getElementById('filePreview').style.display = 'none';
          const modal = bootstrap.Modal.getInstance(document.getElementById('legalRequestModal'));
          if (modal) modal.hide();
          
          // Refresh requests after a short delay
          setTimeout(() => {
            fetchLegalRequests();
          }, 1000);
        } else {
          showNotification('Error', data.message || 'Failed to submit request', 'danger');
        }
      } catch (error) {
        console.error('Submission error:', error);
        
        // Show more specific error message
        let errorMessage = 'Failed to submit request. ';
        if (error.message.includes('JSON')) {
          errorMessage += 'API returned invalid response. Please contact administrator.';
        } else if (error.message.includes('HTTP')) {
          errorMessage += 'Server error. Please try again later.';
        } else {
          errorMessage += error.message || 'Please try again.';
        }
        
        showNotification('Error', errorMessage, 'danger');
      } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      }
    }
    
    // ==================== DATA FETCHING ====================
    async function fetchLegalRequests() {
      try {
        const response = await fetch(API_BASE);
        const data = await response.json();
        
        allRequests = Array.isArray(data) ? data : [];
        updateStats();
        renderRequests();
      } catch (error) {
        console.error('Error fetching requests:', error);
        document.getElementById('legalRequestsList').innerHTML = `
          <div class="empty-state">
            <i class="bi bi-exclamation-triangle"></i>
            <h5>Error Loading Requests</h5>
            <p>Unable to load requests. Please try again later.</p>
          </div>
        `;
      }
    }
    
    // ==================== STATS UPDATE ====================
    function updateStats() {
      stats = { total: 0, pending: 0, approved: 0, rejected: 0, completed: 0 };
      
      allRequests.forEach(req => {
        stats.total++;
        const status = (req.status || '').toLowerCase().trim();
        
        if (status === 'pending' || status === 'in progress') {
          stats.pending++;
        } else if (status === 'approved') {
          stats.approved++;
        } else if (status === 'rejected') {
          stats.rejected++;
        } else if (status === 'completed') {
          stats.completed++;
        }
      });
      
      document.getElementById('statsCards').innerHTML = `
        <div class="stats-card">
          <div class="icon"><i class="bi bi-file-earmark-text"></i></div>
          <div class="label">Total Requests</div>
          <div class="value">${stats.total}</div>
        </div>
        <div class="stats-card">
          <div class="icon"><i class="bi bi-hourglass-split"></i></div>
          <div class="label">Pending</div>
          <div class="value text-warning">${stats.pending}</div>
        </div>
        <div class="stats-card">
          <div class="icon"><i class="bi bi-check-circle"></i></div>
          <div class="label">Approved</div>
          <div class="value text-success">${stats.approved}</div>
        </div>
        <div class="stats-card">
          <div class="icon"><i class="bi bi-x-circle"></i></div>
          <div class="label">Rejected</div>
          <div class="value text-danger">${stats.rejected}</div>
        </div>
      `;
    }
    
    // ==================== RENDER REQUESTS ====================
    function renderRequests() {
      const list = document.getElementById('legalRequestsList');
      let filtered = [...allRequests];
      
      // Apply filters
      const search = document.getElementById('searchInput').value.trim().toLowerCase();
      const dept = document.getElementById('departmentFilter').value;
      const type = document.getElementById('typeFilter').value;
      const status = document.getElementById('statusFilter').value.toLowerCase();
      const dateFrom = document.getElementById('dateFrom').value;
      const dateTo = document.getElementById('dateTo').value;
      
      if (search) {
        filtered = filtered.filter(r =>
          (r.title && r.title.toLowerCase().includes(search)) ||
          (r.request_id && String(r.request_id).includes(search)) ||
          (r.requested_by && r.requested_by.toLowerCase().includes(search))
        );
      }
      
      if (dept) {
        filtered = filtered.filter(r => r.department === dept);
      }
      
      if (type) {
        filtered = filtered.filter(r => r.request_type === type);
      }
      
      if (status) {
        filtered = filtered.filter(r => 
          r.status && r.status.toLowerCase().trim() === status
        );
      }
      
      if (dateFrom) {
        filtered = filtered.filter(r => r.created_at && r.created_at >= dateFrom);
      }
      
      if (dateTo) {
        filtered = filtered.filter(r => r.created_at && r.created_at <= dateTo);
      }
      
      // Sort by created date (newest first)
      filtered.sort((a, b) => {
        const dateA = new Date(a.created_at || 0);
        const dateB = new Date(b.created_at || 0);
        return dateB - dateA;
      });
      
      if (filtered.length === 0) {
        list.innerHTML = `
          <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h5>No Requests Found</h5>
            <p>Try adjusting your filters or create a new request.</p>
          </div>
        `;
        return;
      }
      
      list.innerHTML = `
        <div class="mb-3">
          <h5 class="fw-bold">
            <i class="bi bi-folder2-open me-2"></i>
            Legal Requests 
            <span class="badge bg-primary ms-2">${filtered.length}</span>
          </h5>
        </div>
        ${filtered.map(req => createRequestCard(req)).join('')}
      `;
    }
    
    function createRequestCard(req) {
      const statusClass = (req.status || 'pending').toLowerCase().replace(' ', '-');
      const deadline = req.deadline ? new Date(req.deadline) : null;
      const isOverdue = deadline && deadline < new Date() && req.status !== 'completed';
      
      return `
        <div class="request-card" data-request-id="${req.request_id || ''}">
          <span class="status-badge ${statusClass}">${req.status || 'Pending'}</span>
          
          <div class="request-title">${escapeHtml(req.title || 'Untitled Request')}</div>
          
          <div class="mb-2 text-muted">${escapeHtml(truncateText(req.description || '', 150))}</div>
          
          <div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
            ${req.request_type ? `<span class="badge bg-light text-dark"><i class="bi bi-tag me-1"></i>${escapeHtml(req.request_type)}</span>` : ''}
            ${req.priority ? `<span class="badge bg-${getPriorityColor(req.priority)}">${req.priority}</span>` : ''}
            ${deadline ? `
              <span class="${isOverdue ? 'text-danger' : 'text-success'}">
                <i class="bi bi-clock me-1"></i>
                ${isOverdue ? 'Overdue: ' : 'Due: '}
                ${formatDate(deadline)}
              </span>
            ` : ''}
            ${req.document_path ? '<span class="badge bg-info"><i class="bi bi-paperclip me-1"></i>Has Attachment</span>' : ''}
          </div>
          
          <div class="text-muted small">
            <strong>From:</strong> ${escapeHtml(req.requested_by || 'Unknown')}
            ${req.department ? `<span class="mx-2">•</span><strong>Dept:</strong> ${escapeHtml(req.department)}` : ''}
            <span class="mx-2">•</span><strong>Submitted:</strong> ${req.created_at ? formatDate(new Date(req.created_at)) : 'N/A'}
          </div>
        </div>
      `;
    }
    
    // ==================== REQUEST DETAILS ====================
    async function showRequestDetails(requestId) {
      const requestData = allRequests.find(req => req.request_id == requestId);
      if (!requestData) return;
      
      currentRequestId = requestId;
      
      // Populate modal fields
      document.getElementById('detailsTitle').textContent = requestData.title || 'Untitled';
      document.getElementById('detailsRequestId').textContent = `Request ID: ${requestData.request_id || 'N/A'}`;
      document.getElementById('detailsDepartment').textContent = requestData.department || '-';
      document.getElementById('detailsRequestType').textContent = requestData.request_type || '-';
      document.getElementById('detailsPriority').textContent = requestData.priority || 'Medium';
      document.getElementById('detailsPriority').className = `badge bg-${getPriorityColor(requestData.priority)}`;
      document.getElementById('detailsStatus').textContent = requestData.status || 'Pending';
      document.getElementById('detailsStatus').className = 'badge status-badge ' + (requestData.status || 'pending').toLowerCase().replace(' ', '-');
      document.getElementById('detailsDeadline').textContent = requestData.deadline ? formatDate(new Date(requestData.deadline)) : '-';
      document.getElementById('detailsDescription').textContent = requestData.description || 'No description provided';
      document.getElementById('detailsPurpose').textContent = requestData.purpose || 'No purpose specified';
      document.getElementById('detailsRequestedBy').textContent = requestData.requested_by || '-';
      document.getElementById('detailsStakeholders').textContent = requestData.stakeholders || '-';
      document.getElementById('detailsComplexity').textContent = requestData.complexity_level || '-';
      document.getElementById('detailsUrgency').textContent = requestData.urgency || '-';
      document.getElementById('detailsCreatedAt').textContent = requestData.created_at ? formatDateTime(new Date(requestData.created_at)) : '-';
      
      // Handle attachments
      const attachmentDiv = document.getElementById('detailsAttachmentDiv');
      const noAttachment = document.getElementById('detailsNoAttachment');
      
      if (requestData.document_path && requestData.document_path.trim() !== '') {
        const fileName = requestData.document_path.split('/').pop();
        const fileExt = fileName.split('.').pop().toLowerCase();
        const iconClass = getFileIcon(fileExt);
        
        attachmentDiv.innerHTML = `
          <div class="document-preview">
            <i class="bi ${iconClass}"></i>
            <div class="document-info">
              <div class="document-name">${escapeHtml(fileName)}</div>
              <div class="document-size">Attached Document</div>
            </div>
            <a href="${requestData.document_path}" target="_blank" class="btn btn-sm btn-primary">
              <i class="bi bi-download"></i> Download
            </a>
          </div>
        `;
        attachmentDiv.style.display = 'block';
        noAttachment.style.display = 'none';
      } else {
        attachmentDiv.style.display = 'none';
        noAttachment.style.display = 'block';
      }
      
      // Render timeline
      renderTimeline(requestData.status_history);
      
      // Render comments
      renderComments(requestData.comments);
      
      // Show modal
      new bootstrap.Modal(document.getElementById('requestDetailsModal')).show();
    }
    
    function renderTimeline(history) {
      const timeline = document.getElementById('detailsTimeline');
      
      if (!Array.isArray(history) || history.length === 0) {
        timeline.innerHTML = '<li class="text-muted">No status history available</li>';
        return;
      }
      
      timeline.innerHTML = history.map(h => `
        <li>
          <span class="badge ${getStatusBadgeClass(h.status)}">${h.status}</span>
          <div class="small text-muted mt-1">
            ${h.by ? `by ${h.by}` : ''} 
            ${h.role ? `(${h.role})` : ''}
            ${h.date ? `<br>${formatDateTime(new Date(h.date))}` : ''}
          </div>
        </li>
      `).join('');
    }
    
    function renderComments(comments) {
      const commentsList = document.getElementById('commentsList');
      
      if (!comments || comments.length === 0) {
        commentsList.innerHTML = '<div class="text-muted text-center">No comments yet</div>';
        return;
      }
      
      commentsList.innerHTML = comments.map(c => `
        <div class="comment-item">
          <div>
            <span class="comment-author">${escapeHtml(c.author || 'Anonymous')}</span>
            <span class="badge bg-light text-dark ms-2">${escapeHtml(c.role || '')}</span>
            <span class="comment-date">${c.date ? formatDateTime(new Date(c.date)) : ''}</span>
          </div>
          <div class="comment-text">${escapeHtml(c.text || '')}</div>
        </div>
      `).join('');
    }
    
    async function postComment() {
      const commentText = document.getElementById('commentInput').value.trim();
      if (!commentText || !currentRequestId) return;
      
      try {
        const response = await fetch(API_BASE, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'post_comment',
            request_id: currentRequestId,
            comment: commentText,
            created_by: currentEmployeeId
          })
        });
        
        const data = await response.json();
        
        if (data.success) {
          document.getElementById('commentInput').value = '';
          // Refresh the request data
          fetchLegalRequests();
          // Re-render comments if API returns them
          if (data.comments) {
            renderComments(data.comments);
          }
          showNotification('Success', 'Comment posted', 'success');
        } else {
          showNotification('Error', data.message || 'Failed to post comment', 'danger');
        }
      } catch (error) {
        console.error('Error posting comment:', error);
        showNotification('Error', 'Network error. Please try again.', 'danger');
      }
    }
    
    // ==================== NOTIFICATIONS ====================
    async function loadNotifications() {
      try {
        const response = await fetch(`https://administrative.viahale.com/api_endpoint/notifications.php?user_id=${currentEmployeeId}`);
        const data = await response.json();
        
        const notifList = document.getElementById('notifList');
        const notifBadge = document.getElementById('notifBadge');
        
        let unread = 0;
        
        if (!data.notifications || data.notifications.length === 0) {
          notifList.innerHTML = '<li class="list-group-item text-muted text-center">No notifications</li>';
        } else {
          notifList.innerHTML = data.notifications.map(notif => {
            if (!notif.is_read) unread++;
            return `
              <li class="list-group-item${notif.is_read == 0 ? ' bg-light' : ''}">
                <div class="d-flex justify-content-between align-items-start">
                  <div class="flex-grow-1">
                    <div class="fw-semibold">${escapeHtml(notif.message)}</div>
                    <small class="text-muted">${notif.created_at}</small>
                  </div>
                </div>
              </li>
            `;
          }).join('');
        }
        
        notifBadge.style.display = unread > 0 ? 'inline-flex' : 'none';
        notifBadge.textContent = unread;
      } catch (error) {
        console.error('Error loading notifications:', error);
      }
    }
    
    // ==================== UTILITY FUNCTIONS ====================
    function clearFilters() {
      document.getElementById('searchInput').value = '';
      document.getElementById('departmentFilter').value = '';
      document.getElementById('typeFilter').value = '';
      document.getElementById('statusFilter').value = '';
      document.getElementById('dateFrom').value = '';
      document.getElementById('dateTo').value = '';
      renderRequests();
    }
    
    function getPriorityColor(priority) {
      const p = (priority || '').toLowerCase();
      if (p === 'high') return 'danger';
      if (p === 'medium') return 'warning';
      return 'secondary';
    }
    
    function getStatusBadgeClass(status) {
      const s = (status || '').toLowerCase();
      if (s.includes('approve') || s.includes('complete') || s.includes('finalized')) return 'bg-success';
      if (s.includes('pending') || s.includes('progress') || s.includes('review')) return 'bg-warning text-dark';
      if (s.includes('reject')) return 'bg-danger';
      return 'bg-secondary';
    }
    
    function getFileIcon(ext) {
      const icons = {
        'pdf': 'bi-file-pdf',
        'doc': 'bi-file-word',
        'docx': 'bi-file-word',
        'xls': 'bi-file-excel',
        'xlsx': 'bi-file-excel',
        'jpg': 'bi-file-image',
        'jpeg': 'bi-file-image',
        'png': 'bi-file-image'
      };
      return icons[ext] || 'bi-file-earmark-text';
    }
    
    function formatDate(date) {
      return date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
    }
    
    function formatDateTime(date) {
      return date.toLocaleString('en-US', { 
        month: 'short', 
        day: '2-digit', 
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    }
    
    function truncateText(text, maxLength) {
      if (text.length <= maxLength) return text;
      return text.substring(0, maxLength) + '...';
    }
    
    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
    
    function debounce(func, wait) {
      let timeout;
      return function executedFunction(...args) {
        const later = () => {
          clearTimeout(timeout);
          func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
      };
    }
    
    function showNotification(title, message, type = 'info') {
      // Simple Bootstrap toast notification
      const toastHTML = `
        <div class="toast align-items-center text-white bg-${type} border-0" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
          <div class="d-flex">
            <div class="toast-body">
              <strong>${title}</strong><br>${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
          </div>
        </div>
      `;
      
      const toastContainer = document.createElement('div');
      toastContainer.innerHTML = toastHTML;
      document.body.appendChild(toastContainer);
      
      const toastElement = toastContainer.querySelector('.toast');
      const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
      toast.show();
      
      toastElement.addEventListener('hidden.bs.toast', () => {
        toastContainer.remove();
      });
    }
    </script>
    </body>
    </html>