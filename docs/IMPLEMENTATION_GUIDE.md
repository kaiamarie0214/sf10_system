# PHP Implementation Guide for New School Year System

## Overview
This guide provides PHP code examples for implementing the new school year management system in your application.

---

## 1. Session Management Updates

### includes/session_helper.php (NEW FILE)
```php
<?php
/**
 * Initialize school year context in session
 */
function initializeSchoolYearContext($conn, $user_id, $role) {
    // Get active school year
    $stmt = $conn->prepare("SELECT * FROM school_years WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $active_year = $stmt->get_result()->fetch_assoc();
    
    if (!$active_year) {
        return ['success' => false, 'message' => 'No active school year found. Please contact admin.'];
    }
    
    $_SESSION['school_year_id'] = $active_year['id'];
    $_SESSION['school_year'] = $active_year['year'];
    $_SESSION['school_year_status'] = $active_year['status'];
    
    // For teachers, load their permissions and assignments
    if ($role == 'teacher') {
        loadTeacherAssignments($conn, $user_id, $active_year['id']);
    }
    
    return ['success' => true];
}

/**
 * Load teacher's assignments and permissions
 */
function loadTeacherAssignments($conn, $teacher_id, $school_year_id) {
    // Get subject assignments (what they can teach/grade)
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
    $stmt->bind_param("ii", $teacher_id, $school_year_id);
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
    $stmt->bind_param("ii", $teacher_id, $school_year_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $_SESSION['adviser_class'] = $result->fetch_assoc();
        $_SESSION['is_adviser'] = true;
    } else {
        $_SESSION['is_adviser'] = false;
    }
}

/**
 * Check if teacher can grade a specific subject for a class
 */
function canTeacherGradeSubject($conn, $teacher_id, $subject_id, $grade_level, $section, $school_year_id) {
    $stmt = $conn->prepare("
        SELECT fn_can_teacher_grade_subject(?, ?, ?, ?, ?) as can_grade
    ");
    $stmt->bind_param("iiisi", $teacher_id, $subject_id, $grade_level, $section, $school_year_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result['can_grade'] == 1;
}

/**
 * Get teacher's grading permissions
 */
function getTeacherGradingPermissions($conn, $teacher_id, $school_year_id) {
    $stmt = $conn->prepare("
        SELECT * FROM v_teacher_grading_permissions
        WHERE teacher_id = ?
          AND school_year_id = ?
    ");
    $stmt->bind_param("ii", $teacher_id, $school_year_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
```

---

## 2. Update login.php

Add this after successful login:

```php
// In login.php, after password verification

// ... existing login code ...

if (password_verify($password, $user['password'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    
    // NEW: Initialize school year context
    require_once 'includes/session_helper.php';
    $result = initializeSchoolYearContext($conn, $user['id'], $user['role']);
    
    if (!$result['success']) {
        echo json_encode([
            'success' => false,
            'message' => $result['message']
        ]);
        exit;
    }
    
    // Log activity
    logActivity($user['id'], 'Login', 'User logged in successfully');
    
    echo json_encode([
        'success' => true,
        'redirect' => 'pages/dashboard.php'
    ]);
}
```

---

## 3. Admin: School Year Management Page

### pages/school_year_management.php (NEW FILE)

```php
<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/logger.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../login.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action == 'create_school_year') {
        $year = $_POST['year'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $user_id = $_SESSION['user_id'];
        
        // Validate
        if (empty($year) || empty($start_date) || empty($end_date)) {
            echo json_encode(['success' => false, 'message' => 'All fields required']);
            exit;
        }
        
        try {
            // Call stored procedure
            $stmt = $conn->prepare("CALL sp_create_new_school_year(?, ?, ?, ?)");
            $stmt->bind_param("sssi", $year, $start_date, $end_date, $user_id);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result()->fetch_assoc();
                echo json_encode([
                    'success' => true,
                    'message' => 'School year created successfully',
                    'data' => $result
                ]);
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($action == 'activate_school_year') {
        $school_year_id = $_POST['school_year_id'];
        
        try {
            // Deactivate all
            $conn->query("UPDATE school_years SET is_active = 0, status = 'archived'");
            
            // Activate selected
            $stmt = $conn->prepare("UPDATE school_years SET is_active = 1, status = 'active' WHERE id = ?");
            $stmt->bind_param("i", $school_year_id);
            $stmt->execute();
            
            logActivity($_SESSION['user_id'], 'ACTIVATE_SCHOOL_YEAR', "Activated school year ID: $school_year_id");
            
            echo json_encode(['success' => true, 'message' => 'School year activated']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action == 'archive_school_year') {
        $school_year_id = $_POST['school_year_id'];
        
        try {
            $stmt = $conn->prepare("UPDATE school_years SET status = 'archived', is_active = 0 WHERE id = ?");
            $stmt->bind_param("i", $school_year_id);
            $stmt->execute();
            
            logActivity($_SESSION['user_id'], 'ARCHIVE_SCHOOL_YEAR', "Archived school year ID: $school_year_id");
            
            echo json_encode(['success' => true, 'message' => 'School year archived']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Get all school years
$school_years = $conn->query("
    SELECT 
        sy.*,
        u.full_name as created_by_name,
        (SELECT COUNT(*) FROM school_year_enrollments WHERE school_year_id = sy.id) as enrollment_count,
        (SELECT COUNT(*) FROM classes_per_year WHERE school_year_id = sy.id) as class_count
    FROM school_years sy
    LEFT JOIN users u ON sy.created_by = u.id
    ORDER BY sy.year DESC
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>School Year Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../templates/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../templates/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">School Year Management</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSchoolYearModal">
                        <i class="bi bi-plus-circle"></i> Create New School Year
                    </button>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>School Year</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th>Classes</th>
                                <th>Students</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($school_years as $sy): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($sy['year']) ?></strong>
                                    <?php if ($sy['is_active']): ?>
                                        <span class="badge bg-success ms-2">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('M d, Y', strtotime($sy['start_date'])) ?></td>
                                <td><?= date('M d, Y', strtotime($sy['end_date'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= $sy['status'] == 'active' ? 'success' : ($sy['status'] == 'upcoming' ? 'info' : 'secondary') ?>">
                                        <?= ucfirst($sy['status']) ?>
                                    </span>
                                </td>
                                <td><?= $sy['class_count'] ?></td>
                                <td><?= $sy['enrollment_count'] ?></td>
                                <td><?= htmlspecialchars($sy['created_by_name'] ?? 'System') ?></td>
                                <td>
                                    <?php if (!$sy['is_active']): ?>
                                        <button class="btn btn-sm btn-success" onclick="activateSchoolYear(<?= $sy['id'] ?>)">
                                            Activate
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($sy['status'] != 'archived'): ?>
                                        <button class="btn btn-sm btn-secondary" onclick="archiveSchoolYear(<?= $sy['id'] ?>)">
                                            Archive
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Create School Year Modal -->
    <div class="modal fade" id="createSchoolYearModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New School Year</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createSchoolYearForm">
                        <div class="mb-3">
                            <label class="form-label">School Year</label>
                            <input type="text" class="form-control" name="year" placeholder="e.g., 2026-2027" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createSchoolYear()">Create</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function createSchoolYear() {
            const formData = new FormData(document.getElementById('createSchoolYearForm'));
            formData.append('action', 'create_school_year');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        function activateSchoolYear(id) {
            if (!confirm('Activate this school year? This will deactivate all other school years.')) return;
            
            const formData = new FormData();
            formData.append('action', 'activate_school_year');
            formData.append('school_year_id', id);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.success) location.reload();
            });
        }
        
        function archiveSchoolYear(id) {
            if (!confirm('Archive this school year?')) return;
            
            const formData = new FormData();
            formData.append('action', 'archive_school_year');
            formData.append('school_year_id', id);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.success) location.reload();
            });
        }
    </script>
</body>
</html>
```

---

## 4. Admin: Subject Teacher Assignment Page

### pages/subject_teacher_assignments.php (NEW FILE)

```php
<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/logger.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: ../login.php');
    exit;
}

$school_year_id = $_SESSION['school_year_id'];

// Handle AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action == 'assign_teacher') {
        $teacher_id = $_POST['teacher_id'];
        $subject_id = $_POST['subject_id'];
        $grade_level = $_POST['grade_level'];
        $section = $_POST['section'];
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO subject_teacher_assignments 
                (school_year_id, teacher_id, subject_id, grade_level, section, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiiisi", 
                $school_year_id, $teacher_id, $subject_id, $grade_level, $section, $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                logActivity($_SESSION['user_id'], 'ASSIGN_SUBJECT_TEACHER', 
                    "Assigned teacher $teacher_id to subject $subject_id for Grade $grade_level-$section");
                echo json_encode(['success' => true, 'message' => 'Teacher assigned successfully']);
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($action == 'remove_assignment') {
        $assignment_id = $_POST['assignment_id'];
        
        try {
            $stmt = $conn->prepare("DELETE FROM subject_teacher_assignments WHERE id = ?");
            $stmt->bind_param("i", $assignment_id);
            $stmt->execute();
            
            logActivity($_SESSION['user_id'], 'REMOVE_SUBJECT_ASSIGNMENT', "Removed assignment ID: $assignment_id");
            echo json_encode(['success' => true, 'message' => 'Assignment removed']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Get data for dropdowns
$teachers = $conn->query("SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$subjects = $conn->query("SELECT id, subject_name FROM subjects WHERE is_global = 1 ORDER BY subject_name")->fetch_all(MYSQLI_ASSOC);
$classes = $conn->query("
    SELECT DISTINCT grade_level, section 
    FROM classes_per_year 
    WHERE school_year_id = $school_year_id
    ORDER BY grade_level, section
")->fetch_all(MYSQLI_ASSOC);

// Get current assignments
$assignments = $conn->query("
    SELECT 
        sta.id,
        sta.grade_level,
        sta.section,
        u.full_name as teacher_name,
        s.subject_name,
        sta.created_at
    FROM subject_teacher_assignments sta
    JOIN users u ON sta.teacher_id = u.id
    JOIN subjects s ON sta.subject_id = s.id
    WHERE sta.school_year_id = $school_year_id
    ORDER BY sta.grade_level, sta.section, s.subject_name
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Subject Teacher Assignments</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../templates/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="col-md-12 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Subject Teacher Assignments (<?= $_SESSION['school_year'] ?>)</h1>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignModal">
                        <i class="bi bi-plus-circle"></i> Assign Teacher
                    </button>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Grade & Section</th>
                                <th>Subject</th>
                                <th>Teacher</th>
                                <th>Assigned Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $a): ?>
                            <tr>
                                <td>Grade <?= $a['grade_level'] ?>-<?= $a['section'] ?></td>
                                <td><?= htmlspecialchars($a['subject_name']) ?></td>
                                <td><?= htmlspecialchars($a['teacher_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($a['created_at'])) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-danger" onclick="removeAssignment(<?= $a['id'] ?>)">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Assign Modal -->
    <div class="modal fade" id="assignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Subject Teacher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="assignForm">
                        <div class="mb-3">
                            <label>Teacher</label>
                            <select class="form-select" name="teacher_id" required>
                                <option value="">Select teacher...</option>
                                <?php foreach ($teachers as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Subject</label>
                            <select class="form-select" name="subject_id" required>
                                <option value="">Select subject...</option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['subject_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Class</label>
                            <select class="form-select" name="class_select" id="classSelect" required>
                                <option value="">Select class...</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['grade_level'] ?>|<?= $c['section'] ?>">
                                        Grade <?= $c['grade_level'] ?>-<?= $c['section'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="grade_level" id="gradeLevel">
                        <input type="hidden" name="section" id="section">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="assignTeacher()">Assign</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('classSelect').addEventListener('change', function() {
            const [grade, section] = this.value.split('|');
            document.getElementById('gradeLevel').value = grade;
            document.getElementById('section').value = section;
        });
        
        function assignTeacher() {
            const formData = new FormData(document.getElementById('assignForm'));
            formData.append('action', 'assign_teacher');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.success) location.reload();
            });
        }
        
        function removeAssignment(id) {
            if (!confirm('Remove this assignment?')) return;
            
            const formData = new FormData();
            formData.append('action', 'remove_assignment');
            formData.append('assignment_id', id);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.success) location.reload();
            });
        }
    </script>
</body>
</html>
```

---

## 5. Update pages/grades.php

Add permission checking before allowing grade entry:

```php
// In grades.php, before processing grade submission

// Get student's enrollment info
$stmt = $conn->prepare("
    SELECT grade_level, section 
    FROM school_year_enrollments 
    WHERE student_id = ? AND school_year_id = ?
");
$stmt->bind_param("ii", $student_id, $_SESSION['school_year_id']);
$stmt->execute();
$enrollment = $stmt->get_result()->fetch_assoc();

if (!$enrollment) {
    echo json_encode(['success' => false, 'message' => 'Student not enrolled in current school year']);
    exit;
}

// Check if teacher can grade this subject
require_once '../includes/session_helper.php';
if (!canTeacherGradeSubject($conn, $_SESSION['user_id'], $subject_id, 
    $enrollment['grade_level'], $enrollment['section'], $_SESSION['school_year_id'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'You are not authorized to enter grades for this subject'
    ]);
    exit;
}

// Proceed with grade entry...
$stmt = $conn->prepare("
    INSERT INTO grades 
    (school_year_id, student_id, subject_id, quarter, grade, teacher_id, school_year, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE grade = ?, teacher_id = ?
");
```

---

## 6. Teacher Dashboard Updates

### pages/teacher_dashboard.php (Update existing or create)

```php
<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'teacher') {
    header('Location: ../login.php');
    exit;
}

$teacher_id = $_SESSION['user_id'];
$school_year_id = $_SESSION['school_year_id'];

// Get subject assignments
$subject_assignments = $_SESSION['subject_assignments'] ?? [];

// Get adviser class if any
$adviser_class = $_SESSION['adviser_class'] ?? null;

// Get class roster if adviser
$class_roster = [];
if ($adviser_class) {
    $stmt = $conn->prepare("
        SELECT * FROM v_class_roster
        WHERE school_year_id = ?
          AND grade_level = ?
          AND section = ?
        ORDER BY student_name
    ");
    $stmt->bind_param("iis", $school_year_id, $adviser_class['grade_level'], $adviser_class['section']);
    $stmt->execute();
    $class_roster = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Teacher Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../templates/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="col-md-12 px-md-4">
                <h1 class="mt-4">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h1>
                <p class="text-muted">School Year: <?= $_SESSION['school_year'] ?></p>
                
                <!-- Subject Assignments -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5>My Subject Assignments</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($subject_assignments)): ?>
                            <p class="text-muted">No subject assignments yet. Contact admin.</p>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Class</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subject_assignments as $a): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($a['subject_name']) ?></td>
                                        <td><?= htmlspecialchars($a['class_display']) ?></td>
                                        <td>
                                            <a href="grades.php?subject=<?= $a['subject_id'] ?>&grade=<?= $a['grade_level'] ?>&section=<?= $a['section'] ?>" 
                                               class="btn btn-sm btn-primary">
                                                Enter Grades
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Class Roster (if adviser) -->
                <?php if ($adviser_class): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h5>My Class Roster - <?= htmlspecialchars($adviser_class['class_display']) ?></h5>
                        <small>Students: <?= $adviser_class['current_count'] ?> / <?= $adviser_class['capacity'] ?></small>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>LRN</th>
                                    <th>Name</th>
                                    <th>Gender</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($class_roster as $student): ?>
                                <tr>
                                    <td><?= htmlspecialchars($student['lrn']) ?></td>
                                    <td><?= htmlspecialchars($student['student_name']) ?></td>
                                    <td><?= htmlspecialchars($student['gender']) ?></td>
                                    <td>
                                        <span class="badge bg-success">
                                            <?= ucfirst($student['enrollment_status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>
```

---

## 7. Update Sidebar Navigation

Add links to new pages in templates/sidebar.php:

```php
<?php if ($_SESSION['role'] == 'admin'): ?>
    <!-- School Year Management -->
    <li class="nav-item">
        <a class="nav-link" href="school_year_management.php">
            <i class="bi bi-calendar3"></i> School Years
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="subject_teacher_assignments.php">
            <i class="bi bi-person-check"></i> Teacher Assignments
        </a>
    </li>
<?php endif; ?>
```

---

## 8. Helper Functions

### includes/grade_helpers.php (NEW FILE)

```php
<?php
/**
 * Get grades for a student in current school year
 */
function getStudentGrades($conn, $student_id, $school_year_id) {
    $stmt = $conn->prepare("
        SELECT 
            s.subject_name,
            g.quarter,
            g.grade,
            g.final_rating,
            u.full_name as teacher_name
        FROM grades g
        JOIN subjects s ON g.subject_id = s.id
        JOIN users u ON g.teacher_id = u.id
        WHERE g.student_id = ?
          AND g.school_year_id = ?
        ORDER BY s.subject_name, g.quarter
    ");
    $stmt->bind_param("ii", $student_id, $school_year_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get students in a class for grading
 */
function getClassStudents($conn, $school_year_id, $grade_level, $section) {
    $stmt = $conn->prepare("
        SELECT * FROM v_class_roster
        WHERE school_year_id = ?
          AND grade_level = ?
          AND section = ?
          AND enrollment_status = 'enrolled'
        ORDER BY student_name
    ");
    $stmt->bind_param("iis", $school_year_id, $grade_level, $section);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
```

---

## Summary of Key Changes

1. **Session now includes:**
   - `school_year_id`
   - `school_year` (year string)
   - `subject_assignments` (for teachers)
   - `adviser_class` (for advisers)

2. **New pages:**
   - `school_year_management.php` - Admin creates/manages school years
   - `subject_teacher_assignments.php` - Admin assigns teachers to subjects

3. **Grade entry validation:**
   - Checks if teacher assigned to subject
   - Uses `fn_can_teacher_grade_subject()` function
   - Automatic validation via trigger

4. **Teacher dashboard shows:**
   - Subject assignments with quick grade entry links
   - Class roster (if adviser)

---

## Testing Checklist

- [ ] Admin can create new school year
- [ ] Admin can activate/archive school years
- [ ] Admin can assign subject teachers
- [ ] Teacher sees only assigned subjects
- [ ] Teacher can grade only assigned subjects
- [ ] Permission denied if teacher tries unauthorized grading
- [ ] Adviser sees class roster
- [ ] Data isolated per school year

---

## Next Steps

1. Run database migration scripts
2. Deploy new PHP files
3. Test with sample data
4. Train admin and teachers
5. Go live!
