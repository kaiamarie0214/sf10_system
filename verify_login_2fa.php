<?php
session_start();
include "includes/db.php";
include "includes/logger.php";
include "includes/totp_helper.php";

// If no temp user in session, redirect to login
if (!isset($_SESSION['temp_2fa_user'])) {
    header("Location: login.php");
    exit();
}

$user = $_SESSION['temp_2fa_user'];
$school_year_id = $_SESSION['temp_2fa_school_year_id'];
$remember_me = $_SESSION['temp_2fa_remember_me'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    $code = trim($_POST['code']);
    
    if (TOTP::verifyCode($user['totp_secret'], $code)) {
        // Code is valid, finalize login
        
        // Fetch school year details
        $school_year = null;
        if ($school_year_id) {
            $stmt = $conn->prepare("SELECT * FROM school_years WHERE id = ?");
            $stmt->bind_param("i", $school_year_id);
            $stmt->execute();
            $school_year = $stmt->get_result()->fetch_assoc();
        }

        // Setup session
        $_SESSION['user']              = $user;
        $_SESSION['user_id']           = $user['id'];
        $_SESSION['username']          = $user['username'];
        $_SESSION['role']              = $user['role'];
        $_SESSION['full_name']         = $user['full_name'];
        
        if ($school_year) {
            $_SESSION['school_year_id']    = $school_year['id'];
            $_SESSION['school_year']       = $school_year['year'];
            $_SESSION['school_year_status']= $school_year['status'];
        } else {
            $_SESSION['school_year_id']    = null;
            $_SESSION['school_year']       = null;
            $_SESSION['school_year_status']= null;
        }

        // Handle Remember Me
        if ($remember_me) {
            $token = bin2hex(random_bytes(32));
            $stmt_token = $conn->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
            $stmt_token->bind_param("si", $token, $user['id']);
            $stmt_token->execute();
            setcookie('remember_me', $token, time() + (86400 * 30), "/"); // 30 days
        }

        // Setup teacher assignments if applicable
        if ($user['role'] == 'teacher' && $school_year) {
            $stmt = $conn->prepare("
                SELECT sta.id, sta.subject_id, s.subject_name, sta.grade_level, sta.section,
                       CONCAT('Grade ', sta.grade_level, '-', sta.section) as class_display
                FROM subject_teacher_assignments sta
                JOIN subjects s ON sta.subject_id = s.id
                WHERE sta.teacher_id = ? AND sta.school_year_id = ?
                ORDER BY sta.grade_level, sta.section, s.subject_name
            ");
            $stmt->bind_param("ii", $user['id'], $school_year['id']);
            $stmt->execute();
            $_SESSION['subject_assignments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $stmt = $conn->prepare("
                SELECT cpy.id, cpy.grade_level, cpy.section, cpy.current_count, cpy.capacity,
                       CONCAT('Grade ', cpy.grade_level, '-', cpy.section) as class_display
                FROM classes_per_year cpy
                WHERE cpy.adviser_id = ? AND cpy.school_year_id = ? LIMIT 1
            ");
            $stmt->bind_param("ii", $user['id'], $school_year['id']);
            $stmt->execute();
            $res_adv = $stmt->get_result();
            if ($res_adv->num_rows > 0) {
                $_SESSION['adviser_class'] = $res_adv->fetch_assoc();
                $_SESSION['is_adviser']    = true;
            } else {
                $_SESSION['is_adviser'] = false;
            }
        }

        logActivity($conn, $user['id'], 'LOGIN', 'users', $user['id'],
            "User logged in with 2FA: {$user['full_name']} ({$user['role']})");
        
        // Clear temp data
        unset($_SESSION['temp_2fa_user']);
        unset($_SESSION['temp_2fa_school_year_id']);
        unset($_SESSION['temp_2fa_remember_me']);

        header("Location: pages/dashboard.php");
        exit();
    } else {
        $error = "Invalid verification code. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Login - SF10 System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #f4f7fb;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .verify-card {
            width: 100%;
            max-width: 400px;
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.1);
            text-align: center;
        }
        .icon-circle {
            width: 80px;
            height: 80px;
            background: #e6fffa;
            color: #449999;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin-bottom: 24px;
        }
        .btn-verify {
            height: 48px;
            background: linear-gradient(135deg, #449999 0%, #4FABA9 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 700;
            width: 100%;
            margin-top: 20px;
        }
        .code-input {
            height: 60px;
            font-size: 24px;
            text-align: center;
            letter-spacing: 8px;
            font-weight: 700;
            border-radius: 12px;
            border: 2px solid #edf2f7;
            background: #f8fafc;
        }
        .code-input:focus {
            border-color: #449999;
            box-shadow: 0 0 0 4px rgba(68,153,153,0.1);
        }
    </style>
</head>
<body>
    <div class="verify-card">
        <div class="icon-circle">
            <i class="bi bi-shield-lock-fill"></i>
        </div>
        <h3 class="mb-2">Two-Factor Auth</h3>
        <p class="text-muted mb-4">Enter the 6-digit code from your authenticator app to complete login.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger mb-4">
                <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <input type="text" name="code" class="form-control code-input" placeholder="000000" required maxlength="6" pattern="[0-9]{6}" autocomplete="off" autofocus>
            </div>
            <button type="submit" name="verify_code" class="btn btn-verify">
                Verify & Login
            </button>
            <div class="mt-4">
                <a href="login.php" class="text-decoration-none text-muted small">
                    <i class="bi bi-arrow-left me-1"></i>Back to Login
                </a>
            </div>
        </form>
    </div>
</body>
</html>