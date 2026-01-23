<?php
session_start();
include "includes/db.php";
include "includes/logger.php";

// Redirect if already logged in
if (isset($_SESSION['user'])) {
    header("Location: pages/dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password']; // No manual hashing here

    // ✅ Check user by username only (we'll verify password separately)
    $query = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();

    if ($user = $result->fetch_assoc()) {
        // ✅ Verify hashed password
        if (password_verify($password, $user['password'])) {
            $_SESSION['user'] = $user;
            
            // Log successful login
            logActivity($conn, $user['id'], 'LOGIN', 'users', $user['id'], 
                       "User logged in: {$user['full_name']} ({$user['role']})");
            
            header("Location: pages/dashboard.php");
            exit();
        } else {
            $error = "Invalid login credentials.";
        }
    } else {
        $error = "Invalid login credentials.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>SF10 System - Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <script>
    // Apply theme immediately before page renders to prevent flash
    (function() {
      const savedTheme = localStorage.getItem('theme');
      if (savedTheme === 'dark') {
        document.documentElement.classList.add('dark-theme');
      }
    })();
  </script>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      background: #f4f7fb;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      padding: 20px;
      margin: 0;
    }
    
    .login-container {
      width: 100%;
      max-width: 1000px;
      flex: 1;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    
    .login-footer {
      padding: 20px 0;
      width: 100%;
      text-align: center;
    }
    
    .login-footer small {
      color: #718096;
      opacity: 0.9;
    }
    
    .login-card {
      background: white;
      border-radius: 20px;
      box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
      overflow: hidden;
      width: 100%;
      display: flex;
    }
    
    /* Left Side: Form */
    .login-left {
      flex: 1;
      padding: 40px 50px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }
    
    .login-left h2 {
      font-weight: 700;
      font-size: clamp(20px, 5vh, 32px);
      color: #1a202c;
      margin-bottom: 5px;
      text-align: center;
    }
    
    .login-left p.welcome-text {
      color: #718096;
      font-size: clamp(12px, 2vh, 16px);
      margin-bottom: clamp(10px, 3vh, 40px);
      text-align: center;
    }

    .mobile-logo {
      display: none;
      text-align: center;
      margin-bottom: 20px;
    }
    
    .mobile-logo img {
      width: 100px;
      height: 100px;
      object-fit: contain;
      filter: drop-shadow(0 5px 15px rgba(0,0,0,0.1));
    }
    
    /* Right Side: Logo & Info */
    .login-right {
      flex: 1;
      background: linear-gradient(135deg, #449999 0%, #4FABA9 100%);
      padding: 40px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: white;
      text-align: center;
    }
    
    .login-right img {
      max-width: 220px;
      width: 100%;
      height: auto;
      object-fit: contain;
      margin-bottom: 30px;
      filter: drop-shadow(0 10px 20px rgba(0,0,0,0.15));
    }
    
    .login-right h3 {
      font-size: 28px;
      font-weight: 700;
      margin-bottom: 15px;
      letter-spacing: 1px;
    }
    
    .login-right p {
      font-size: 16px;
      opacity: 0.9;
      line-height: 1.6;
    }
    
    .login-body {
      width: 100%;
      max-width: 400px;
      margin: 0 auto;
    }
    
    .form-label {
      font-weight: 600;
      color: #4a5568;
      margin-bottom: 8px;
      font-size: 14px;
      display: block;
    }
    
    .form-control {
      height: 54px;
      border: 2px solid #edf2f7;
      border-radius: 12px;
      padding: 0 18px;
      font-size: 15px;
      transition: all 0.3s;
      width: 100%;
      background: #f8fafc;
    }
    
    .form-control:focus {
      outline: none;
    }
    
    .input-group {
      margin-bottom: 24px;
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
      box-shadow: 0 0 0 4px rgba(68, 153, 153, 0.1);
      background: white;
    }
    
    .input-group-text {
      background: transparent;
      border: none;
      padding: 0 18px;
      display: flex;
      align-items: center;
    }
    
    .input-group i {
      color: #a0aec0;
      font-size: 18px;
    }
    
    .input-group .form-control {
      border: none !important;
      border-radius: 0 !important;
      background: transparent !important;
      height: 50px; /* Adjust height since border is on container */
    }
    
    .password-toggle {
      position: absolute;
      right: 15px;
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
    
    .password-toggle:hover {
      color: #449999;
    }
    
    .btn-login {
      height: 54px;
      background: linear-gradient(135deg, #449999 0%, #4FABA9 100%);
      border: none;
      border-radius: 12px;
      color: white;
      font-weight: 700;
      font-size: 16px;
      transition: all 0.3s;
      width: 100%;
      margin-top: 10px;
      cursor: pointer;
      box-shadow: 0 4px 15px rgba(68, 153, 153, 0.3);
    }
    
    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(68, 153, 153, 0.4);
    }
    
    .alert {
      border-radius: 12px;
      font-size: 14px;
      padding: 15px;
      margin-bottom: 25px;
    }
    
    /* Loading Spinner Overlay */
    .loading-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(255, 255, 255, 0.9);
      backdrop-filter: blur(10px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    
    .loading-overlay.active {
      display: flex;
      opacity: 1;
    }
    
    .spinner {
      width: 60px;
      height: 60px;
      border: 5px solid rgba(68, 153, 153, 0.3);
      border-top: 5px solid #449999;
      border-radius: 50%;
      animation: spinRotate 1s linear infinite;
      margin-bottom: 15px;
    }
    
    .loading-text {
      color: #449999;
      font-weight: 600;
      font-size: 18px;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      animation: pulse 1.5s ease-in-out infinite;
    }

    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.6; }
    }
    
    @keyframes spinRotate {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    /* Mobile Responsive */
    @media (max-width: 992px) {
      .login-container {
        max-width: 500px;
      }
      .login-right {
        display: none;
      }
      .login-left {
        padding: 50px 30px;
      }
      .mobile-logo {
        display: block;
      }
    }
    
    @media (max-width: 576px) {
      body {
        padding: 0;
      }
      .login-container {
        max-width: 100%;
        height: auto;
        min-height: 100vh;
      }
      .login-card {
        border-radius: 0;
        min-height: 100vh;
        margin: 0;
      }
    }

    @media (max-height: 700px) {
      .login-left, .login-right {
        padding-top: 30px;
        padding-bottom: 30px;
      }
      .login-left p.welcome-text {
        margin-bottom: 20px;
      }
      .login-right img {
        width: 150px;
        height: 150px;
        margin-bottom: 15px;
      }
      .login-right h3 {
        font-size: 22px;
        margin-bottom: 10px;
      }
    }

    @media (max-height: 500px) {
      body {
        justify-content: center;
        padding: 2px;
        overflow: hidden;
      }
      .login-container {
        height: 100vh;
        max-height: 100vh;
        display: flex;
        align-items: center;
      }
      .login-card {
        box-shadow: none;
        border-radius: 10px;
      }
      .login-left, .login-right {
        padding: 10px 15px;
      }
      .login-left h2 {
        font-size: 18px;
        margin-bottom: 2px;
      }
      .login-left p.welcome-text {
        display: none;
      }
      .form-label {
        margin-bottom: 2px;
        font-size: 12px;
      }
      .input-group {
        border-width: 1px;
        margin-bottom: 8px;
      }
      .form-control {
        height: 38px !important;
        margin-bottom: 0;
        font-size: 14px;
      }
      .btn-login {
        height: 38px;
        margin-top: 2px;
        font-size: 14px;
      }
      .login-footer {
        display: none;
      }
      .mobile-logo {
        margin-bottom: 5px;
      }
      .mobile-logo img {
        width: 50px;
        height: 50px;
        margin-bottom: 2px;
      }
    }

    @media (max-height: 400px) {
      .mobile-logo img {
        width: 40px;
        height: 40px;
      }
      .theme-toggle {
        top: 10px;
        right: 10px;
        width: 35px;
        height: 35px;
        font-size: 18px;
      }
      .input-group-text {
        padding: 0 10px;
      }
    }
    
    /* Dark Mode Styles */
    html.dark-theme body {
      background: #1a202c;
    }
    
    html.dark-theme .login-card {
      background: #2d3748;
      box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
    }
    
    html.dark-theme .login-left h2 {
      color: #ffffff;
    }
    
    html.dark-theme .login-left p.welcome-text {
      color: #a0aec0;
    }
    
    html.dark-theme .form-label {
      color: #e2e8f0;
    }
    
    html.dark-theme .form-control {
      background: #1a202c;
      border-color: #4a5568;
      color: #ffffff;
    }

    html.dark-theme .form-control::placeholder {
      color: #718096;
      opacity: 0.7;
    }
    
    html.dark-theme .input-group {
      background: #1a202c;
      border-color: #4a5568;
    }

    html.dark-theme .input-group:focus-within {
      border-color: #4FABA9;
      background: #1a202c;
    }

    html.dark-theme .input-group-text {
      background: transparent;
      border: none;
    }

    html.dark-theme .input-group i,
    html.dark-theme .password-toggle {
      color: #718096;
    }
    
    html.dark-theme .form-control:focus {
      background: #1a202c;
    }
    
    html.dark-theme .loading-overlay {
      background: rgba(26, 32, 44, 0.9);
    }

    /* Theme Toggle Button */
    .theme-toggle {
      position: fixed;
      top: 20px;
      right: 20px;
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.95);
      border: none;
      color: #FFA500;
      font-size: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.3s ease;
      z-index: 1000;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
      position: fixed;
      overflow: hidden;
    }
    
    .theme-toggle:hover {
      background: rgba(255, 255, 255, 1);
      transform: scale(1.1);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    }
    
    .theme-toggle:active {
      transform: scale(0.95);
    }
    
    .theme-toggle i {
      transition: all 0.4s ease;
      position: absolute;
    }
    
    .theme-toggle .bi-moon-fill {
      /* No default animation */
    }
    
    .theme-toggle .bi-sun-fill {
      /* No default animation */
    }

    /* Only animate when this class is added */
    .theme-toggle.animate i {
      animation: fadeIn 0.4s ease;
    }
    
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: rotate(-180deg) scale(0.5);
      }
      to {
        opacity: 1;
        transform: rotate(0deg) scale(1);
      }
    }
    
    @keyframes fadeOut {
      from {
        opacity: 1;
        transform: rotate(0deg) scale(1);
      }
      to {
        opacity: 0;
        transform: rotate(180deg) scale(0.5);
      }
    }
    
    /* Dark theme - change color to gold */
    html.dark-theme .theme-toggle {
      color: #FFD700;
      background: #2d3748;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
    }

    html.dark-theme .theme-toggle:hover {
      background: #1a202c;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.5);
    }
    
    .theme-icon-dark {
      display: inline;
    }
    
    .theme-icon-light {
      display: none;
    }
    
    html.dark-theme .theme-icon-dark {
      display: none;
    }
    
    html.dark-theme .theme-icon-light {
      display: inline;
    }
    html.dark-theme .login-footer small {
      color: #a0aec0 !important;
    }
  </style>
</head>
<body>

<!-- Theme Toggle Button -->
<button class="theme-toggle" onclick="toggleTheme()" title="Toggle Dark Mode">
  <i class="bi bi-moon-fill theme-icon-dark"></i>
  <i class="bi bi-sun-fill theme-icon-light"></i>
</button>

<!-- Loading Spinner Overlay -->
<div class="loading-overlay" id="loadingOverlay">
  <div style="text-align: center; display: flex; flex-direction: column; align-items: center;">
    <div class="spinner"></div>
    <div class="loading-text">Signing In...</div>
  </div>
</div>

<div class="login-container">
  <div class="login-card">
    <!-- Left Side: Login Form -->
    <div class="login-left">
      <div class="mobile-logo">
        <img src="logo.png" alt="School Logo" onerror="this.onerror=null;this.style.display='none'">
      </div>
      <h2>SF10 SYSTEM</h2>
      <p class="welcome-text">Sign in to manage academic records</p>
      
      <div class="login-body">
        <?php if (isset($error)): ?>
          <div class='alert alert-danger'>
            <i class="bi bi-exclamation-circle"></i> <?= $error ?>
          </div>
        <?php endif; ?>
        
        <form method="POST">
          <div class="mb-3">
            <label class="form-label">Username</label>
            <div class="input-group">
              <span class="input-group-text">
                <i class="bi bi-person"></i>
              </span>
              <input type="text" name="username" class="form-control" placeholder="Enter your username" 
                     required autocomplete="off">
            </div>
          </div>
          
          <div class="mb-4">
            <label class="form-label">Password</label>
            <div class="input-group">
              <span class="input-group-text">
                <i class="bi bi-lock"></i>
              </span>
              <input type="password" name="password" id="passwordInput" class="form-control" placeholder="Enter your password" 
                     required autocomplete="off">
              <button type="button" class="password-toggle" onclick="togglePasswordVisibility()">
                <i class="bi bi-eye" id="toggleIcon"></i>
              </button>
            </div>
          </div>
          
          <button type="submit" class="btn btn-login">
            <i class="bi bi-box-arrow-in-right"></i> Sign In
          </button>
        </form>
      </div>
    </div>
    
    <!-- Right Side: Logo & School Info -->
    <div class="login-right">
      <img src="logo.png" alt="School Logo" onerror="this.onerror=null;this.style.display='none'">
      <h3>NEW MABUHAY ELEMENTARY SCHOOL</h3>
      <p>Providing quality education and maintaining accurate learner permanent records through the SF10 Management System.</p>
    </div>
  </div>
</div>

<div class="login-footer">
  <small>&copy; <?= date('Y') ?> SF10 System. All rights reserved.</small>
</div>

<script>
  // Password visibility toggle
  function togglePasswordVisibility() {
    const passwordInput = document.getElementById('passwordInput');
    const toggleIcon = document.getElementById('toggleIcon');
    
    if (passwordInput.type === 'password') {
      passwordInput.type = 'text';
      toggleIcon.classList.remove('bi-eye');
      toggleIcon.classList.add('bi-eye-slash');
    } else {
      passwordInput.type = 'password';
      toggleIcon.classList.remove('bi-eye-slash');
      toggleIcon.classList.add('bi-eye');
    }
  }
  
  // Theme toggle function
  function toggleTheme() {
    const html = document.documentElement;
    const toggleBtn = document.querySelector('.theme-toggle');
    const isDark = html.classList.contains('dark-theme');
    
    // Add animation class
    toggleBtn.classList.add('animate');
    
    if (isDark) {
      html.classList.remove('dark-theme');
      localStorage.setItem('theme', 'light');
    } else {
      html.classList.add('dark-theme');
      localStorage.setItem('theme', 'dark');
    }
    
    // Remove animation class after animation completes
    setTimeout(() => {
      toggleBtn.classList.remove('animate');
    }, 400);
  }
  
  // Show loading spinner on form submit
  document.querySelector('form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Clear all sidebar states on login
    const sidebarKeys = [];
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (key.startsWith('sidebar-')) {
        sidebarKeys.push(key);
      }
    }
    sidebarKeys.forEach(key => localStorage.removeItem(key));
    
    // Always show the loading spinner
    document.getElementById('loadingOverlay').classList.add('active');
    
    // Keep it visible for 1.8 seconds to be noticeable
    const form = this;
    
    setTimeout(function() {
      form.submit();
    }, 1800);
  });
</script>
</body>
</html>
