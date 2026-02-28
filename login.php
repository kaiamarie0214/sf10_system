<?php
session_start();
include "includes/db.php";
include "includes/logger.php";

// Redirect if already logged in
if (isset($_SESSION['user'])) {
    header("Location: pages/dashboard.php");
    exit();
}

// ── REMEMBER ME check ──────────────────────────────────────────
if (!isset($_SESSION['user']) && isset($_COOKIE['remember_me'])) {
    $token = $_COOKIE['remember_me'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE remember_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        // Automatically log in the user
        // We need a school year for full session setup. 
        // We'll pick the current active one or the most recent one.
        $active_yr = $conn->query("SELECT * FROM school_years WHERE is_active = 1 LIMIT 1")->fetch_assoc();
        if (!$active_yr) {
            $active_yr = $conn->query("SELECT * FROM school_years WHERE status != 'archived' ORDER BY year DESC LIMIT 1")->fetch_assoc();
        }

        if ($active_yr || ($user['role'] == 'admin')) {
            $_SESSION['user']              = $user;
            $_SESSION['user_id']           = $user['id'];
            $_SESSION['username']          = $user['username'];
            $_SESSION['role']              = $user['role'];
            $_SESSION['full_name']         = $user['full_name'];
            
            if ($active_yr) {
                $_SESSION['school_year_id']    = $active_yr['id'];
                $_SESSION['school_year']       = $active_yr['year'];
                $_SESSION['school_year_status']= $active_yr['status'];
            } else {
                $_SESSION['school_year_id']    = null;
                $_SESSION['school_year']       = null;
                $_SESSION['school_year_status']= null;
            }

            // Setup teacher assignments if applicable
            if ($user['role'] == 'teacher' && $active_yr) {
                $stmt = $conn->prepare("
                    SELECT sta.id, sta.subject_id, s.subject_name, sta.grade_level, sta.section,
                           CONCAT('Grade ', sta.grade_level, '-', sta.section) as class_display
                    FROM subject_teacher_assignments sta
                    JOIN subjects s ON sta.subject_id = s.id
                    WHERE sta.teacher_id = ? AND sta.school_year_id = ?
                    ORDER BY sta.grade_level, sta.section, s.subject_name
                ");
                $stmt->bind_param("ii", $user['id'], $active_yr['id']);
                $stmt->execute();
                $_SESSION['subject_assignments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                $stmt = $conn->prepare("
                    SELECT cpy.id, cpy.grade_level, cpy.section, cpy.current_count, cpy.capacity,
                           CONCAT('Grade ', cpy.grade_level, '-', cpy.section) as class_display
                    FROM classes_per_year cpy
                    WHERE cpy.adviser_id = ? AND cpy.school_year_id = ? LIMIT 1
                ");
                $stmt->bind_param("ii", $user['id'], $active_yr['id']);
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
                "User logged in via Remember Me: {$user['full_name']} ({$user['role']})");
            header("Location: pages/dashboard.php");
            exit();
        }
    }
}

// Get available school years (not archived)
$available_years = $conn->query("
    SELECT id, year, is_active, status, start_date, end_date
    FROM school_years 
    WHERE status != 'archived'
    ORDER BY year DESC
")->fetch_all(MYSQLI_ASSOC);

// Get default school year
$default_year = null;
foreach ($available_years as $year) {
    if ($year['is_active'] == 1) {
        $default_year = $year['id'];
        break;
    }
}
if (!$default_year && count($available_years) > 0) {
    $default_year = $available_years[0]['id'];
}

// ── LOGIN handler ──────────────────────────────────────────────
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username       = $_POST['username'];
    $password       = $_POST['password'];
    $school_year_id = $_POST['school_year_id'] ?? null;
    $remember_me    = isset($_POST['remember_me']);

    // Ensure remember_token column exists
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS remember_token VARCHAR(255) DEFAULT NULL");

    $query = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            // Check if 2FA is required for this user
            if (!empty($user['totp_secret']) && $user['2fa_on_login'] == 1) {
                // Store user info temporarily and redirect to 2FA verification
                $_SESSION['temp_2fa_user'] = $user;
                $_SESSION['temp_2fa_school_year_id'] = $school_year_id;
                $_SESSION['temp_2fa_remember_me'] = $remember_me;
                header("Location: verify_login_2fa.php");
                exit();
            }

            if ($user['role'] == 'admin' && empty($available_years)) {
                $_SESSION['user']              = $user;
                $_SESSION['user_id']           = $user['id'];
                $_SESSION['username']          = $user['username'];
                $_SESSION['role']              = $user['role'];
                $_SESSION['full_name']         = $user['full_name'];
                $_SESSION['school_year_id']    = null;
                $_SESSION['school_year']       = null;
                $_SESSION['school_year_status']= null;
                logActivity($conn, $user['id'], 'LOGIN', 'users', $user['id'],
                    "Admin logged in without school year: {$user['full_name']}");
                header("Location: pages/add_school_year.php?setup=1");
                exit();
            }

            if (empty($school_year_id)) {
                $login_error = "Please select a school year.";
            } else {
                $stmt = $conn->prepare("SELECT * FROM school_years WHERE id = ? AND status != 'archived'");
                $stmt->bind_param("i", $school_year_id);
                $stmt->execute();
                $school_year = $stmt->get_result()->fetch_assoc();

                if (!$school_year) {
                    $login_error = "Invalid school year selected.";
                } else {
                    $_SESSION['user']              = $user;
                    $_SESSION['user_id']           = $user['id'];
                    $_SESSION['username']          = $user['username'];
                    $_SESSION['role']              = $user['role'];
                    $_SESSION['full_name']         = $user['full_name'];
                    $_SESSION['school_year_id']    = $school_year['id'];
                    $_SESSION['school_year']       = $school_year['year'];
                    $_SESSION['school_year_status']= $school_year['status'];

                    // Handle Remember Me
                    if ($remember_me) {
                        $token = bin2hex(random_bytes(32));
                        $stmt_token = $conn->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                        $stmt_token->bind_param("si", $token, $user['id']);
                        $stmt_token->execute();
                        setcookie('remember_me', $token, time() + (86400 * 30), "/"); // 30 days
                    }

                    if ($user['role'] == 'teacher') {
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
                        "User logged in: {$user['full_name']} ({$user['role']}) - School Year: {$school_year['year']}");
                    header("Location: pages/dashboard.php");
                    exit();
                }
            }
        } else {
            $login_error = "Invalid login credentials.";
        }
    } else {
        $login_error = "Invalid login credentials.";
    }
}

// ── REGISTER handler ───────────────────────────────────────────
$reg_error   = '';
$reg_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $full_name = trim($_POST['full_name']   ?? '');
    $username  = trim($_POST['reg_username']?? '');
    $password  = $_POST['reg_password']     ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    if (empty($full_name) || empty($username) || empty($password) || empty($confirm)) {
        $reg_error = "All fields are required.";
    } elseif ($password !== $confirm) {
        $reg_error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $reg_error = "Password must be at least 6 characters.";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $reg_error = "Username already taken. Please choose another.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $role   = 'teacher';
            $stmt   = $conn->prepare("INSERT INTO users (username, password, full_name, role, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssss", $username, $hashed, $full_name, $role);
            if ($stmt->execute()) {
                $reg_success = "Registration successful! Your account is pending approval. Please wait for an admin to assign your class before you can log in.";
            } else {
                $reg_error = "Registration failed. Please try again.";
            }
        }
    }
}

// If register had error/success keep panel on register side
$start_on_register = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>SF10 System - Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <script>
    (function() {
      if (localStorage.getItem('theme') === 'dark') {
        document.documentElement.classList.add('dark-theme');
      }
    })();
  </script>
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      background: #f4f7fb;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      padding: 20px;
    }

    /* ── Outer wrapper ── */
    .auth-container {
      width: 100%;
      max-width: 1000px;
      flex: 1;
      display: flex;
      justify-content: center;
      align-items: center;
    }

    /* ── Card: two equal halves side by side ── */
    .auth-card {
      position: relative;
      border-radius: 20px;
      box-shadow: 0 15px 50px rgba(0,0,0,0.12);
      overflow: hidden;
      width: 100%;
      display: flex;
      min-height: 560px;
      /* white bg only under the form halves, not the overlay */
      background: white;
    }

    /*
     * Each form panel occupies exactly one half of the card.
     * They are in normal document flow (not absolute), so the
     * card height is driven by their content.
     * The teal overlay slides ON TOP of them via position:absolute.
     */
    .panel-login,
    .panel-register {
      width: 50%;
      flex-shrink: 0;
      padding: 44px 44px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      /* z-index below the overlay */
      position: relative;
      z-index: 1;
    }

    /* ── Text inside form panels ── */
    .panel-login h2,
    .panel-register h2 {
      font-weight: 700;
      font-size: clamp(18px, 4vh, 28px);
      color: #1a202c;
      margin-bottom: 4px;
      text-align: center;
    }

    .panel-login p.sub,
    .panel-register p.sub {
      color: #718096;
      font-size: clamp(12px, 1.8vh, 14px);
      margin-bottom: clamp(10px, 2.5vh, 24px);
      text-align: center;
    }

    .panel-body {
      width: 100%;
      max-width: 340px;
      margin: 0 auto;
    }

    /*
     * Transition: when switching panels, fade & slide the
     * CONTENT (not the position of the half itself).
     * The overlay moving physically hides the outgoing side.
     */
    .panel-login,
    .panel-register {
      transition: opacity 0.35s ease 0.15s;
    }

    /* While overlay is over login side, dim it slightly */
    .auth-card.show-register .panel-login   { opacity: 0.15; pointer-events: none; }
    .auth-card:not(.show-register) .panel-register { opacity: 0.15; pointer-events: none; }

    /* ── Sliding teal overlay ── */
    .panel-overlay {
      position: absolute;
      top: 0; bottom: 0;
      width: 50%;
      background: linear-gradient(135deg, #449999 0%, #4FABA9 100%);
      z-index: 20;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      text-align: center;
      /* default: right half */
      left: 50%;
      transition: left 0.65s cubic-bezier(0.77, 0, 0.18, 1);
    }

    .auth-card.show-register .panel-overlay {
      left: 0%;
    }

    /* ── Content inside the overlay ── */
    .overlay-login-hint,
    .overlay-register-hint {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 40px 30px;
      transition: opacity 0.3s ease, transform 0.3s ease;
    }

    /* Default: show login-hint (overlay on right = register CTA) */
    .overlay-login-hint   { opacity: 1;  transform: translateX(0);   transition-delay: 0.25s; }
    .overlay-register-hint{ opacity: 0;  transform: translateX(20px); pointer-events: none; }

    .auth-card.show-register .overlay-login-hint    { opacity: 0;  transform: translateX(-20px); pointer-events: none; transition-delay: 0s; }
    .auth-card.show-register .overlay-register-hint { opacity: 1;  transform: translateX(0);     pointer-events: all;  transition-delay: 0.25s; }

    .panel-overlay img {
      max-width: 170px;
      width: 55%;
      margin-bottom: 22px;
      filter: drop-shadow(0 10px 20px rgba(0,0,0,0.15));
    }

    .panel-overlay h3 {
      font-size: clamp(15px, 2.2vw, 22px);
      font-weight: 700;
      margin-bottom: 12px;
      letter-spacing: 0.5px;
    }

    .panel-overlay p {
      font-size: clamp(12px, 1.3vw, 14px);
      opacity: 0.9;
      line-height: 1.6;
    }

    .overlay-cta {
      margin-top: 26px;
      height: 48px;
      padding: 0 35px;
      border: none;
      border-radius: 12px;
      color: #449999;
      background: white;
      font-weight: 700;
      font-size: 15px;
      cursor: pointer;
      transition: all 0.3s;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .overlay-cta:hover {
      transform: translateY(-2px);
      background: #f8fafc;
      box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }

    /* ── Form controls ── */
    .form-label {
      font-weight: 600;
      color: #4a5568;
      margin-bottom: 6px;
      font-size: 13px;
      display: block;
    }

    .form-control {
      height: 48px;
      border: 2px solid #edf2f7;
      border-radius: 12px;
      padding: 0 16px;
      font-size: 14px;
      transition: all 0.3s;
      width: 100%;
      background: #f8fafc;
    }
    .form-control:focus { outline: none; }

    .input-group {
      margin-bottom: 15px;
      position: relative;
      display: flex;
      border: 2px solid #edf2f7;
      border-radius: 12px;
      overflow: hidden;
      background: #f8fafc;
      transition: all 0.3s;
    }
    .input-group:focus-within {
      border-color: #449999;
      box-shadow: 0 0 0 4px rgba(68,153,153,0.1);
      background: white;
    }
    .input-group-text {
      background: transparent;
      border: none;
      padding: 0 14px;
      display: flex;
      align-items: center;
    }
    .input-group i { color: #a0aec0; font-size: 17px; }
    .input-group .form-control {
      border: none !important;
      border-radius: 0 !important;
      background: transparent !important;
      height: 44px;
    }

    .password-toggle {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: #a0aec0;
      cursor: pointer;
      padding: 5px;
      display: flex;
      align-items: center;
      z-index: 10;
    }
    .password-toggle:hover { color: #449999; }

    .btn-auth {
      height: 48px;
      background: linear-gradient(135deg, #449999 0%, #4FABA9 100%);
      border: none;
      border-radius: 12px;
      color: white;
      font-weight: 700;
      font-size: 15px;
      transition: all 0.3s;
      width: 100%;
      margin-top: 6px;
      cursor: pointer;
      box-shadow: 0 4px 15px rgba(68,153,153,0.3);
    }
    .btn-auth:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(68,153,153,0.4);
    }

    .alert {
      border-radius: 12px;
      font-size: 13px;
      padding: 12px 14px;
      margin-bottom: 15px;
    }

    /* ── Custom checkbox color ── */
    .form-check-input:checked {
      background-color: #449999;
      border-color: #449999;
    }
    .form-check-input:focus {
      border-color: #449999;
      box-shadow: 0 0 0 0.25rem rgba(68, 153, 153, 0.25);
    }

    /* mobile logo */
    .mobile-logo { display: none; text-align: center; margin-bottom: 14px; }
    .mobile-logo img { width: 75px; height: 75px; object-fit: contain; }

    /* ── Loading overlay ── */
    .loading-overlay {
      position: fixed; inset: 0;
      background: rgba(255,255,255,0.9);
      backdrop-filter: blur(10px);
      display: none; align-items: center; justify-content: center;
      z-index: 9999;
      opacity: 0; transition: opacity 0.3s ease;
    }
    .loading-overlay.active { display: flex; opacity: 1; }
    .spinner {
      width: 60px; height: 60px;
      border: 5px solid rgba(68,153,153,0.3);
      border-top: 5px solid #449999;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin-bottom: 15px;
    }
    .loading-text {
      color: #449999; font-weight: 600; font-size: 18px;
      animation: pulse 1.5s ease-in-out infinite;
    }
    @keyframes spin  { to { transform: rotate(360deg); } }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.6} }

    /* ── Theme toggle ── */
    .theme-toggle {
      position: fixed; top: 20px; right: 20px;
      width: 50px; height: 50px; border-radius: 50%;
      background: rgba(255,255,255,0.95);
      border: none; color: #FFA500; font-size: 24px;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; transition: all 0.3s ease; z-index: 2000;
      box-shadow: 0 2px 10px rgba(0,0,0,0.2); overflow: hidden;
    }
    .theme-toggle:hover  { transform: scale(1.1); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
    .theme-toggle:active { transform: scale(0.95); }
    .theme-toggle i      { position: absolute; transition: all 0.4s ease; }
    .theme-toggle.animate i { animation: fadeIn 0.4s ease; }
    @keyframes fadeIn {
      from { opacity:0; transform: rotate(-180deg) scale(0.5); }
      to   { opacity:1; transform: rotate(0deg) scale(1); }
    }
    .theme-icon-dark  { display: inline; }
    .theme-icon-light { display: none; }

    /* footer */
    .auth-footer { padding: 18px 0; width: 100%; text-align: center; }
    .auth-footer small { color: #718096; opacity: 0.9; }

    /* ── Responsive: stack vertically on narrow screens ── */
    @media (max-width: 900px) {
      .auth-container  { max-width: 480px; }
      .panel-overlay   { display: none; }
      .auth-card       { flex-direction: column; min-height: auto; }
      .panel-login,
      .panel-register  {
        width: 100%; padding: 36px 28px;
        opacity: 1 !important; pointer-events: all !important;
      }
      /* show only the active panel on mobile */
      .auth-card:not(.show-register) .panel-register { display: none; }
      .auth-card.show-register .panel-login          { display: none; }
      .mobile-logo { display: block; }
    }

    @media (max-width: 576px) {
      body { padding: 0; }
      .auth-container { max-width: 100%; min-height: 100vh; }
      .auth-card { border-radius: 0; min-height: 100vh; }
    }

    @media (max-height: 640px) {
      .panel-login, .panel-register { padding-top: 24px; padding-bottom: 24px; }
      .input-group  { margin-bottom: 10px; }
      .panel-login p.sub, .panel-register p.sub { margin-bottom: 12px; }
    }

    /* ── Dark mode ── */
    html.dark-theme body                       { background: #1a202c; }
    html.dark-theme .auth-card                 { background: #2d3748; box-shadow: 0 25px 50px rgba(0,0,0,0.4); }
    html.dark-theme .panel-login h2,
    html.dark-theme .panel-register h2         { color: #ffffff; }
    html.dark-theme .panel-login p.sub,
    html.dark-theme .panel-register p.sub      { color: #a0aec0; }
    html.dark-theme .form-label                { color: #e2e8f0; }
    html.dark-theme .form-control              { background: #1a202c; border-color: #4a5568; color: #ffffff; }
    html.dark-theme .form-control::placeholder { color: #718096; opacity: 0.7; }
    html.dark-theme .input-group               { background: #1a202c; border-color: #4a5568; }
    html.dark-theme .input-group:focus-within  { border-color: #4FABA9; background: #1a202c; }
    html.dark-theme .input-group-text          { background: transparent; border: none; }
    html.dark-theme .input-group i,
    html.dark-theme .password-toggle           { color: #718096; }
    html.dark-theme .form-control:focus        { background: #1a202c; }
    html.dark-theme select.form-control        { background-color: #1a202c; color: #ffffff; color-scheme: dark; }
    html.dark-theme select.form-control option { background-color: #1a202c; color: #ffffff; }
    html.dark-theme .loading-overlay           { background: rgba(26,32,44,0.9); }
    html.dark-theme .auth-footer small         { color: #a0aec0 !important; }
    html.dark-theme .text-muted                { color: #a0aec0 !important; }
    html.dark-theme .panel-login span,
    html.dark-theme .panel-register span       { color: #a0aec0 !important; }
    html.dark-theme .form-check-label          { color: #a0aec0 !important; }
    html.dark-theme .form-check-input          { background-color: #1a202c; border-color: #4a5568; }
    html.dark-theme .form-check-input:checked  { background-color: #449999; border-color: #449999; }
    html.dark-theme .theme-toggle              { color: #FFD700; background: #2d3748; box-shadow: 0 4px 15px rgba(0,0,0,0.4); }
    html.dark-theme .theme-toggle:hover        { background: #1a202c; }
    html.dark-theme .theme-icon-dark           { display: none; }
    html.dark-theme .theme-icon-light          { display: inline; }
  </style>
</head>
<body>

<!-- Theme toggle -->
<button class="theme-toggle" onclick="toggleTheme()" title="Toggle Dark Mode">
  <i class="bi bi-moon-fill theme-icon-dark"></i>
  <i class="bi bi-sun-fill theme-icon-light"></i>
</button>

<!-- Loading overlay -->
<div class="loading-overlay" id="loadingOverlay">
  <div style="text-align:center;display:flex;flex-direction:column;align-items:center;">
    <div class="spinner"></div>
    <div class="loading-text" id="loadingText">Logging In...</div>
  </div>
</div>

<div class="auth-container">
  <div class="auth-card <?= $start_on_register ? 'show-register' : '' ?>" id="authCard">

    <!-- ══ LOGIN FORM (left) ══════════════════════════════════ -->
    <div class="panel-login">
      <div class="mobile-logo">
        <img src="logo.png" alt="School Logo" onerror="this.onerror=null;this.style.display='none'">
      </div>
      <h2>SF10 SYSTEM</h2>
      <p class="sub">Sign in to manage academic records</p>

      <div class="panel-body">
        <?php if ($login_error): ?>
          <div class="alert alert-danger">
            <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($login_error) ?>
          </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
          <input type="hidden" name="action" value="login">

          <?php if (!empty($available_years)): ?>
          <div class="mb-1">
            <label class="form-label"><i class="bi bi-calendar3"></i> School Year</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-calendar-check"></i></span>
              <select name="school_year_id" class="form-control" required>
                <option value="">-- Select School Year --</option>
                <?php foreach ($available_years as $yr): ?>
                  <option value="<?= $yr['id'] ?>" <?= $yr['id'] == $default_year ? 'selected' : '' ?>>
                    <?= htmlspecialchars($yr['year']) ?><?= $yr['is_active'] == 1 ? ' (Current)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php else: ?>
          <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i> No school year available. Admin can login to create one.
          </div>
          <?php endif; ?>

          <div class="mb-1">
            <label class="form-label">Username</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person"></i></span>
              <input type="text" name="username" class="form-control" placeholder="Enter your username"
                     required autocomplete="off">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" name="password" id="loginPassword" class="form-control"
                     placeholder="Enter your password" required autocomplete="off">
              <button type="button" class="password-toggle" onclick="togglePwd('loginPassword','loginEye')">
                <i class="bi bi-eye" id="loginEye"></i>
              </button>
            </div>
          </div>

          <div class="mb-3 d-flex align-items-center justify-content-between">
            <div class="form-check mb-0">
              <input type="checkbox" name="remember_me" id="rememberMe" class="form-check-input">
              <label class="form-check-label text-muted" for="rememberMe" style="cursor: pointer; font-size: 0.85rem;">Remember login</label>
            </div>
            <a href="forgot_password.php" class="text-decoration-none" style="font-size: 0.85rem; color: #449999;">Forgot Password?</a>
          </div>

          <button type="submit" class="btn btn-auth" form="loginForm">
            <i class="bi bi-box-arrow-in-right"></i> Login
          </button>
        </form>

        <!-- Mobile-only register link -->
        <div class="text-center mt-3 d-lg-none">
          <span style="color:#718096;font-size:14px;">Don't have an account?</span>
          <button onclick="showRegister()" class="ms-1 fw-semibold btn btn-link p-0"
                  style="color:#449999;font-size:14px;text-decoration:none;vertical-align:baseline;">
            <i class="bi bi-person-plus"></i> Register as Teacher
          </button>
        </div>
      </div>
    </div>

    <!-- ══ REGISTER FORM (right, hidden behind overlay initially) ══ -->
    <div class="panel-register">
      <div class="mobile-logo">
        <img src="logo.png" alt="School Logo" onerror="this.onerror=null;this.style.display='none'">
      </div>
      <h2>SF10 SYSTEM</h2>
      <p class="sub">Create your teacher account</p>

      <div class="panel-body">
        <?php if ($reg_success): ?>
          <div class="alert alert-success">
            <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($reg_success) ?>
          </div>
          <div class="text-center mt-2">
            <button onclick="showLogin()" class="btn btn-link fw-semibold p-0"
                    style="color:#449999;font-size:14px;text-decoration:none;">
              <i class="bi bi-box-arrow-in-right"></i> Back to Login
            </button>
          </div>

        <?php else: ?>
          <?php if ($reg_error): ?>
            <div class="alert alert-danger">
              <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($reg_error) ?>
            </div>
          <?php endif; ?>

          <form method="POST" id="registerForm">
            <input type="hidden" name="action" value="register">

            <div class="mb-1">
              <label class="form-label">Full Name</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                <input type="text" name="full_name" class="form-control"
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                       placeholder="e.g. Juan Dela Cruz" autocomplete="off" required>
              </div>
            </div>

            <div class="mb-1">
              <label class="form-label">Username</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person"></i></span>
                <input type="text" name="reg_username" class="form-control"
                       value="<?= htmlspecialchars($_POST['reg_username'] ?? '') ?>"
                       placeholder="Choose a username" autocomplete="off" required>
              </div>
            </div>

            <div class="mb-1">
              <label class="form-label">Password</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" name="reg_password" id="regPassword" class="form-control"
                       placeholder="Min. 6 characters" autocomplete="new-password" required>
                <button type="button" class="password-toggle" onclick="togglePwd('regPassword','regEye1')">
                  <i class="bi bi-eye" id="regEye1"></i>
                </button>
              </div>
            </div>

            <div class="mb-1">
              <label class="form-label">Confirm Password</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                <input type="password" name="confirm_password" id="regConfirm" class="form-control"
                       placeholder="Re-enter your password" autocomplete="new-password" required>
                <button type="button" class="password-toggle" onclick="togglePwd('regConfirm','regEye2')">
                  <i class="bi bi-eye" id="regEye2"></i>
                </button>
              </div>
            </div>

            <button type="submit" class="btn btn-auth">
              <i class="bi bi-person-check-fill"></i> Register
            </button>
          </form>

          <!-- Mobile-only login link -->
          <div class="text-center mt-3 d-lg-none">
            <span style="color:#718096;font-size:14px;">Already have an account?</span>
            <button onclick="showLogin()" class="ms-1 fw-semibold btn btn-link p-0"
                    style="color:#449999;font-size:14px;text-decoration:none;vertical-align:baseline;">
              <i class="bi bi-box-arrow-in-right"></i> Login
            </button>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ SLIDING TEAL OVERLAY ══════════════════════════════ -->
    <div class="panel-overlay">
      <!-- Shown when LOGIN is active (overlay on right) -->
      <div class="overlay-login-hint">
        <img src="logo.png" alt="School Logo" onerror="this.onerror=null;this.style.display='none'">
        <h3>NEW MABUHAY ELEMENTARY SCHOOL</h3>
        <p>Providing quality education and maintaining accurate learner permanent records through the SF10 Management System.</p>
        <button class="overlay-cta" onclick="showRegister()">
          <i class="bi bi-person-plus me-1"></i> Register as Teacher
        </button>
      </div>

      <!-- Shown when REGISTER is active (overlay on left) -->
      <div class="overlay-register-hint">
        <img src="logo.png" alt="School Logo" onerror="this.onerror=null;this.style.display='none'">
        <h3>WELCOME BACK!</h3>
        <p>Already have an account? Sign in to access your classes and manage student records.</p>
        <button class="overlay-cta" onclick="showLogin()">
          <i class="bi bi-box-arrow-in-right me-1"></i> Login
        </button>
      </div>
    </div>

  </div><!-- /.auth-card -->
</div><!-- /.auth-container -->

<div class="auth-footer">
  <small>&copy; <?= date('Y') ?> SF10 System | v1.6.0. All rights reserved.</small>
</div>

<script>
  const card = document.getElementById('authCard');

  function showRegister() {
    card.classList.add('show-register');
  }

  function showLogin() {
    card.classList.remove('show-register');
  }

  function togglePwd(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') {
      inp.type = 'text';
      icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
      inp.type = 'password';
      icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
  }

  function toggleTheme() {
    const html = document.documentElement;
    const btn  = document.querySelector('.theme-toggle');
    btn.classList.add('animate');
    if (html.classList.contains('dark-theme')) {
      html.classList.remove('dark-theme');
      localStorage.setItem('theme', 'light');
    } else {
      html.classList.add('dark-theme');
      localStorage.setItem('theme', 'dark');
    }
    setTimeout(() => btn.classList.remove('animate'), 400);
  }

  // Login form submit → spinner
  document.getElementById('loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    // clear sidebar state
    Object.keys(localStorage).filter(k => k.startsWith('sidebar-')).forEach(k => localStorage.removeItem(k));
    document.getElementById('loadingText').textContent = 'Logging In...';
    document.getElementById('loadingOverlay').classList.add('active');
    const f = this;
    setTimeout(() => f.submit(), 1800);
  });

  // Register form submit → spinner
  const regForm = document.getElementById('registerForm');
  if (regForm) {
    regForm.addEventListener('submit', function (e) {
      e.preventDefault();
      document.getElementById('loadingText').textContent = 'Registering...';
      document.getElementById('loadingOverlay').classList.add('active');
      const f = this;
      setTimeout(() => f.submit(), 1800);
    });
  }
</script>
</body>
</html>
