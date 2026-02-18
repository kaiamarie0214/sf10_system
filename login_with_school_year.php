<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/logger.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: pages/dashboard.php');
    exit;
}

// Handle login with school year selection
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $school_year_id = $_POST['school_year_id'] ?? null;
    
    // Validate inputs
    if (empty($username) || empty($password)) {
        echo json_encode([
            'success' => false,
            'message' => 'Username and password are required'
        ]);
        exit;
    }
    
    if (empty($school_year_id)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please select a school year'
        ]);
        exit;
    }
    
    // Check user credentials
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid username or password'
        ]);
        exit;
    }
    
    $user = $result->fetch_assoc();
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid username or password'
        ]);
        exit;
    }
    
    // Verify selected school year exists and is not archived
    $stmt = $conn->prepare("SELECT * FROM school_years WHERE id = ? AND status != 'archived'");
    $stmt->bind_param("i", $school_year_id);
    $stmt->execute();
    $school_year = $stmt->get_result()->fetch_assoc();
    
    if (!$school_year) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid school year selected'
        ]);
        exit;
    }
    
    // Set session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['school_year_id'] = $school_year['id'];
    $_SESSION['school_year'] = $school_year['year'];
    $_SESSION['school_year_status'] = $school_year['status'];
    
    // For teachers, load their assignments for this school year
    if ($user['role'] == 'teacher') {
        // Get subject assignments
        $stmt = $conn->prepare("
            SELECT 
                sta.id,
                sta.subject_id,
                s.subject_name,
                sta.grade_level,
                sta.section,
                CONCAT('Grade ', sta.grade_level, '-', sta.section) as class_display
            FROM subject_teacher_assignments sta
            JOIN subjects s ON sta.subject_id = s.id
            WHERE sta.teacher_id = ?
              AND sta.school_year_id = ?
            ORDER BY sta.grade_level, sta.section, s.subject_name
        ");
        $stmt->bind_param("ii", $user['id'], $school_year['id']);
        $stmt->execute();
        $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $_SESSION['subject_assignments'] = $assignments;
        
        // Get adviser assignment (if any)
        $stmt = $conn->prepare("
            SELECT 
                cpy.id,
                cpy.grade_level,
                cpy.section,
                cpy.current_count,
                cpy.capacity,
                CONCAT('Grade ', cpy.grade_level, '-', cpy.section) as class_display
            FROM classes_per_year cpy
            WHERE cpy.adviser_id = ?
              AND cpy.school_year_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $user['id'], $school_year['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $_SESSION['adviser_class'] = $result->fetch_assoc();
            $_SESSION['is_adviser'] = true;
        } else {
            $_SESSION['is_adviser'] = false;
        }
    }
    
    // Log the login
    logActivity($user['id'], 'Login', "User logged in to school year: {$school_year['year']}");
    
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'redirect' => 'pages/dashboard.php'
    ]);
    exit;
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
// If no default, use the first available
if (!$default_year && count($available_years) > 0) {
    $default_year = $available_years[0]['id'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SF10 System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .login-container {
            max-width: 450px;
            width: 100%;
        }
        .login-card {
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        .login-body {
            padding: 2rem;
        }
        .school-year-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .loading-overlay.show {
            display: flex;
        }
        .theme-toggle {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <button class="theme-toggle" onclick="toggleTheme()">
        <i class="bi bi-moon-fill" id="theme-icon"></i>
    </button>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-light" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <div class="login-container">
        <div class="card login-card">
            <div class="login-header">
                <h3><i class="bi bi-book"></i> SF10 System</h3>
                <p class="mb-0">Learner's Permanent Record</p>
            </div>
            <div class="login-body">
                <?php if (empty($available_years)): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>No School Year Available</strong>
                        <p class="mb-0 mt-2">Please contact the administrator to create a school year.</p>
                    </div>
                <?php else: ?>
                    <form id="loginForm" autocomplete="off">
                        <!-- School Year Selection -->
                        <div class="mb-3">
                            <label for="school_year_id" class="form-label">
                                <i class="bi bi-calendar3"></i> School Year
                            </label>
                            <select class="form-select form-select-lg" id="school_year_id" name="school_year_id" required>
                                <option value="">-- Select School Year --</option>
                                <?php foreach ($available_years as $year): ?>
                                    <option value="<?= $year['id'] ?>" 
                                            <?= $year['id'] == $default_year ? 'selected' : '' ?>
                                            data-status="<?= $year['status'] ?>">
                                        <?= htmlspecialchars($year['year']) ?>
                                        <?php if ($year['is_active'] == 1): ?>
                                            (Current)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select the school year you want to access</small>
                        </div>

                        <!-- Username -->
                        <div class="mb-3">
                            <label for="username" class="form-label">
                                <i class="bi bi-person-fill"></i> Username
                            </label>
                            <input type="text" 
                                   class="form-control form-control-lg" 
                                   id="username" 
                                   name="username" 
                                   placeholder="Enter username" 
                                   required 
                                   autocomplete="off">
                        </div>

                        <!-- Password -->
                        <div class="mb-3">
                            <label for="password" class="form-label">
                                <i class="bi bi-lock-fill"></i> Password
                            </label>
                            <div class="input-group">
                                <input type="password" 
                                       class="form-control form-control-lg" 
                                       id="password" 
                                       name="password" 
                                       placeholder="Enter password" 
                                       required>
                                <button class="btn btn-outline-secondary" 
                                        type="button" 
                                        onclick="togglePassword()">
                                    <i class="bi bi-eye" id="passwordIcon"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Login Button -->
                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-box-arrow-in-right"></i> Login
                            </button>
                        </div>

                        <!-- Alert Area -->
                        <div id="alertArea" class="mt-3"></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const passwordIcon = document.getElementById('passwordIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordIcon.classList.remove('bi-eye');
                passwordIcon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordIcon.classList.remove('bi-eye-slash');
                passwordIcon.classList.add('bi-eye');
            }
        }

        // Toggle theme
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            
            const icon = document.getElementById('theme-icon');
            if (newTheme === 'dark') {
                icon.classList.remove('bi-moon-fill');
                icon.classList.add('bi-sun-fill');
            } else {
                icon.classList.remove('bi-sun-fill');
                icon.classList.add('bi-moon-fill');
            }
        }

        // Load saved theme
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            
            const icon = document.getElementById('theme-icon');
            if (savedTheme === 'dark') {
                icon.classList.remove('bi-moon-fill');
                icon.classList.add('bi-sun-fill');
            }
        });

        // Handle login form submission
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const alertArea = document.getElementById('alertArea');
            const loadingOverlay = document.getElementById('loadingOverlay');
            
            // Show loading
            loadingOverlay.classList.add('show');
            alertArea.innerHTML = '';
            
            fetch('login_with_school_year.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                loadingOverlay.classList.remove('show');
                
                if (data.success) {
                    alertArea.innerHTML = `
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle"></i> ${data.message}
                        </div>
                    `;
                    
                    // Redirect after short delay
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 500);
                } else {
                    alertArea.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-circle"></i> ${data.message}
                        </div>
                    `;
                }
            })
            .catch(error => {
                loadingOverlay.classList.remove('show');
                alertArea.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-circle"></i> An error occurred. Please try again.
                    </div>
                `;
                console.error('Error:', error);
            });
        });

        // Highlight school year info when changed
        document.getElementById('school_year_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const status = selectedOption.getAttribute('data-status');
            
            // Visual feedback
            this.classList.remove('border-success', 'border-warning');
            if (status === 'active') {
                this.classList.add('border-success');
            } else if (status === 'upcoming') {
                this.classList.add('border-warning');
            }
        });
    </script>
</body>
</html>
