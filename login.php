<?php
include('connection.php');
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$max_attempts = 5;
$lockout_time = 15 * 60;

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = 0;
}

$error = '';
$disable_login = false;

if ($_SESSION['login_attempts'] >= $max_attempts) {
    $remaining = $lockout_time - (time() - $_SESSION['last_attempt_time']);
    if ($remaining > 0) {
        $error = "Too many failed login attempts. Please try again after " . ceil($remaining / 60) . " minute(s).";
        $disable_login = true;
    } else {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = 0;
    }
}

// Step 1: Email/Password Login via API
if (isset($_POST["submit"]) && !$disable_login && empty($_SESSION['mfa_pending'])) {
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    // Call the Account API
    $api_url = "https://administrative.viahale.com/api_endpoint/account.php?email=" . urlencode($email);
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $api_response = curl_exec($ch);
    curl_close($ch);
    $api_data = json_decode($api_response, true);

    $data = null;
    if ($api_data && $api_data['success'] && !empty($api_data['users'])) {
        foreach ($api_data['users'] as $user) {
            if ($user['email'] === $email) {
                $data = $user;
                break;
            }
        }
    }

    if ($data && password_verify($password, trim($data['password']))) {
        // Generate OTP locally
        $otp = rand(100000, 999999);
        $expires_at = date('Y-m-d H:i:s', time() + 300); // 5 min expiry

        // Store OTP in DB
        $stmt = $conn->prepare("INSERT INTO user_otp (email, otp_code, expires_at, used) VALUES (?, ?, ?, 0)");
        $stmt->bind_param("sss", $email, $otp, $expires_at);
        $stmt->execute();
        $stmt->close();

        // Send OTP via email (using PHPMailer)
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'glenbrazillhonrado@gmail.com'; // Change to your email
            $mail->Password = 'tfek jkqy ysdr pefd';    // Gmail App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('glenbrazillhonrado@gmail.com', 'ViaHale');
            $mail->addAddress($email, $data['full_name'] ?? $email);
            $mail->isHTML(true);
            $mail->Subject = 'Your ViaHale Login OTP';
            $mail->Body = "<h2>Your OTP Code</h2><p style='font-size:2rem;font-weight:bold;'>$otp</p><p>This code will expire in 5 minutes.</p>";

            $mail->send();
            $_SESSION['mfa_pending'] = true;
            $_SESSION['mfa_user'] = $data;
            $_SESSION['email'] = $data['email'];
            $_SESSION['role'] = trim($data['role']);
            $error = "An OTP has been sent to your email. Please enter it below.";
        } catch (Exception $e) {
            $error = "Failed to send OTP email. Please contact support.";
        }
    } else {
        $_SESSION['login_attempts'] += 1;
        $_SESSION['last_attempt_time'] = time();
        if ($_SESSION['login_attempts'] >= $max_attempts) {
            $error = "Too many failed login attempts. Please try again after 15 minutes.";
            $disable_login = true;
        } else {
            $error = "Invalid email or password. Attempt " . $_SESSION['login_attempts'] . " of $max_attempts.";
        }
    }
}

// Step 2: OTP Verification
if (isset($_POST['verify_otp']) && isset($_SESSION['mfa_pending']) && $_SESSION['mfa_pending']) {
    $input_otp = trim($_POST['otp']);
    $email = $_SESSION['mfa_user']['email'];

    // Check OTP in database
    $checkOtp = $conn->prepare("SELECT id, expires_at, used FROM user_otp WHERE email = ? AND otp_code = ? ORDER BY id DESC LIMIT 1");
    $checkOtp->bind_param("ss", $email, $input_otp);
    $checkOtp->execute();
    $result = $checkOtp->get_result();
    $otpRow = $result->fetch_assoc();
    $checkOtp->close();

    if (!$otpRow) {
        $error = "Invalid OTP. Please try again.";
    } elseif ($otpRow['used']) {
        $error = "OTP already used. Please login again.";
        unset($_SESSION['mfa_pending'], $_SESSION['mfa_user'], $_SESSION['mfa_otp'], $_SESSION['mfa_otp_time']);
    } elseif (strtotime($otpRow['expires_at']) < time()) {
        $error = "OTP expired. Please login again.";
        unset($_SESSION['mfa_pending'], $_SESSION['mfa_user'], $_SESSION['mfa_otp'], $_SESSION['mfa_otp_time']);
    } else {
        // Delete OTP after successful use (one-time)
        $deleteOtp = $conn->prepare("DELETE FROM user_otp WHERE id = ?");
        $deleteOtp->bind_param("i", $otpRow['id']);
        $deleteOtp->execute();
        $deleteOtp->close();

        // Successful MFA
        $data = $_SESSION['mfa_user'];
        unset($_SESSION['mfa_pending'], $_SESSION['mfa_user'], $_SESSION['mfa_otp'], $_SESSION['mfa_otp_time']);
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = 0;
        session_regenerate_id(true);

        $_SESSION['username'] = $data['username'];
        $_SESSION['role'] = trim($data['role']);
        $_SESSION['user_id'] = $data['employee_id'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();

        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', 1);
        ini_set('session.use_only_cookies', 1);

        // Redirect based on role
        switch ($_SESSION['role']) {
            case 'HR Manager':
                header("Location: manager/manager_dashboard.php");
                exit;
            case 'HR3 Admin':
                header("Location: admin/admin_dashboard.php");
                exit;
            case 'Schedule Officer':
                header("Location: scheduler/schedule_officer_dashboard.php");
                exit;
            case 'Benefits Officer':
                header("Location: benefits/benefits_officer_dashboard.php");
                exit;
            case 'Regular':
                header("Location: employee/employee_dashboard.php");
                exit;
            default:
                header("Location: login_api.php");
                exit;
        }
    }
}

// Back to login from OTP form
if (isset($_POST['back_to_login'])) {
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_user'], $_SESSION['email'], $_SESSION['role']);
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Log in | ViaHale</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <style>
    :root{
      --vh-purple:#6c2bd9;
      --vh-purple-2:#5b21b6;
      --vh-purple-3:#7c3aed;
    }
    body{
      font-family:Poppins,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;
      min-height:100vh;
      margin:0;
      display:flex;
      flex-direction:column;
      background:
        radial-gradient(40rem 40rem at -10% -10%, #ede9fe 0%, transparent 60%),
        radial-gradient(50rem 50rem at 110% 0%, #f5f3ff 0%, transparent 55%),
        #ffffff;
    }
    .brandbar{ padding:18px 20px; }
    .brand{ font-weight:700; font-size:1.15rem; color:var(--vh-purple-2); letter-spacing:.2px; user-select:none; }
    .wrap{ flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; padding:2rem 1rem; }
    .title-xl{ font-weight:600; font-size:clamp(1.4rem,1rem + 2vw,2rem); text-align:center; margin-bottom:.25rem; color:var(--vh-purple-2);}
    .subtitle{ text-align:center; color:#6b7280; margin-bottom:1.5rem; font-size:.95rem;}
    .login-card{ width:min(460px,92vw); background:linear-gradient(180deg,var(--vh-purple-3),var(--vh-purple-2) 55%,var(--vh-purple) 100%); color:#fff; border-radius:1rem; padding:1.5rem; box-shadow:0 20px 40px rgba(92,44,182,.25), 0 4px 12px rgba(92,44,182,.15);}
    .form-label{ font-weight:500; color:#e9e9ff; font-size:.85rem;}
    .form-control{ background-color:rgba(255,255,255,.16); color:#fff; border:1px solid rgba(255,255,255,.25); padding:.75rem 1rem; border-radius:.65rem; font-size:.9rem; }
    .form-control::placeholder{ color:#e6e6ff; opacity:.6;}
    .form-control:focus{ background-color:rgba(255, 255, 255, 0.22); border-color:#fff; box-shadow:0 0 0 .15rem rgba(255,255,255,.15); color:#fff;}
    .input-icon{ position:relative; }
    .input-icon button{ position:absolute; right:.6rem; top:72%; transform:translateY(-50%); border:0; background:transparent; color:#fff; opacity:.85;}
    .btn-login{ width:100%; padding:.8rem 1rem; border-radius:.65rem; border:0; background:#fff; color:var(--vh-purple-2); font-weight:600; font-size:.95rem; margin-top:.5rem; transition:transform .05s ease, box-shadow .2s ease;}
    .btn-login:hover{ transform:translateY(-1px); box-shadow:0 8px 16px rgba(255, 255, 255, 0.1);}
    .footer-bar{ background:var(--vh-purple-2); color:#fff; font-size:.8rem; padding:1.35rem 1rem; display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap;}
    .footer-bar a{ color:#fff; text-decoration:none;}
    .footer-bar a:hover{ text-decoration:underline;}
    @media (max-width:480px){ .footer-bar{ justify-content:center; text-align:center; }}
    .alert-danger{ background-color:rgba(255, 0, 0, 0.15); border:none; color:#fff; font-size:.85rem; border-radius:.5rem; }
  </style>
</head>
<body>
  <header class="brandbar">
    <div class="brand">ViaHale</div>
  </header>

  <div class="wrap">
    <h1 class="title-xl">Welcome back!</h1>
    <p class="subtitle">Please enter your credentials to access the dashboard.</p>

    <div class="login-card">
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2 px-3 mb-3"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if (!empty($_SESSION['mfa_pending'])): ?>
        <!-- OTP Verification Form -->
        <form method="post" action="">
          <div class="mb-3">
            <label class="form-label">Enter OTP (Check your email)</label>
            <input type="text" name="otp" class="form-control" placeholder="6-digit code" required maxlength="6" pattern="\d{6}">
          </div>
          <div class="d-flex flex-column gap-2">
            <button class="btn-login" type="submit" name="verify_otp">Verify OTP</button>
            <button class="btn btn-secondary w-100" type="submit" name="back_to_login">← Back</button>
          </div>
        </form>
      <?php else: ?>
        <!-- Email/Password Login Form -->
        <form method="post" action="">
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" placeholder="Enter your email" required autocomplete="email">
          </div>
          <div class="mb-3 input-icon">
            <label class="form-label">Password</label>
            <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
            <button type="button" id="togglePass" aria-label="Show/Hide password">
              <ion-icon name="eye-outline" id="eyeOpen"></ion-icon>
            </button>
          </div>
          <button class="btn-login" type="submit" name="submit" <?php if (!empty($disable_login)) echo 'disabled'; ?>>LOGIN <span class="ms-1">►</span></button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="footer-bar">
    <div>BCP Capstone &nbsp; | &nbsp; <a href="#">Privacy Policy</a></div>
    <div><a href="#">Need Help?</a></div>
  </div>

  <script>
    const btn = document.getElementById('togglePass');
    const input = document.getElementById('password');
    const icon = document.getElementById('eyeOpen');
    btn?.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      icon.setAttribute('name', show ? 'eye-off-outline' : 'eye-outline');
    });
  </script>
</body>
</html>