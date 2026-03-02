<?php
session_start();
include "../includes/db.php";

// Check if user is logged in and is admin
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];
if ($user['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// AJAX ENDPOINTS - Handle these FIRST before any other logic
// AJAX endpoint to get subjects for a specific grade level
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_grade_subjects') {
    header('Content-Type: application/json');
    $grade = (int)$_GET['grade'];
    
    if ($grade < 1 || $grade > 6) {
        echo json_encode(['subjects' => []]);
        exit;
    }
    
    try {
        // Get all subjects
        $all_subjects = $conn->query("SELECT id, subject_name FROM subjects WHERE subject_name != 'General Average' ORDER BY id");
        
        if (!$all_subjects) {
            echo json_encode(['subjects' => [], 'error' => 'Failed to fetch subjects: ' . $conn->error]);
            exit;
        }
        
        $subjects_list = [];
        
        while ($subject = $all_subjects->fetch_assoc()) {
            // Check if there's a customized name for this grade level
            $grade_query = $conn->query("SELECT subject_name FROM subject_grade_groups 
                                         WHERE grade_level = $grade AND subject_id = {$subject['id']}");
            
            $display_name = $subject['subject_name'];
            if ($grade_query && $grade_query->num_rows > 0) {
                $grade_data = $grade_query->fetch_assoc();
                $display_name = $grade_data['subject_name'];
            }
            
            $subjects_list[] = [
                'id' => $subject['id'],
                'name' => $display_name
            ];
        }
        
        echo json_encode(['subjects' => $subjects_list]);
    } catch (Exception $e) {
        echo json_encode(['subjects' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX endpoint to check if section already has an adviser
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_adviser') {
    header('Content-Type: application/json');
    $grade = (int)$_GET['grade'];
    $section = $_GET['section'];
    $exclude_user = isset($_GET['exclude_user']) ? (int)$_GET['exclude_user'] : 0;
    $school_year_id = $_SESSION['school_year_id'];
    
    $result = ['has_adviser' => false, 'adviser_name' => null];
    
    $check_query = $conn->prepare("SELECT u.full_name 
                                   FROM teacher_assignments ta
                                   JOIN users u ON ta.teacher_id = u.id
                                   WHERE ta.grade_level = ? 
                                   AND ta.section = ? 
                                   AND ta.assignment_type = 'adviser'
                                   AND ta.teacher_id != ?
                                   AND ta.school_year_id = ?");
    $check_query->bind_param("isii", $grade, $section, $exclude_user, $school_year_id);
    $check_query->execute();
    $check_result = $check_query->get_result();
    
    if ($adviser = $check_result->fetch_assoc()) {
        $result['has_adviser'] = true;
        $result['adviser_name'] = $adviser['full_name'];
    }
    
    echo json_encode($result);
    exit;
}

// Set current page for sidebar navigation
$_SERVER['PHP_SELF'] = 'users.php';

$success = "";
$error = "";
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch user data
if ($user_id > 0) {
    $user_query = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $user_query->bind_param("i", $user_id);
    $user_query->execute();
    $user_result = $user_query->get_result();
    $edit_user = $user_result->fetch_assoc();
    
    if (!$edit_user) {
        $_SESSION['error_message'] = "User not found.";
        header("Location: users.php");
        exit();
    }
} else {
    $_SESSION['error_message'] = "Invalid user ID.";
    header("Location: users.php");
    exit();
}

// Get classes (grade levels and sections) for dropdowns
$classes_query = "SELECT DISTINCT grade_level, section, school_year, status 
                  FROM classes 
                  WHERE status = 'Active' 
                  ORDER BY grade_level, section";
$classes_result = $conn->query($classes_query);
$classes_data = [];
while ($class = $classes_result->fetch_assoc()) {
    $classes_data[] = $class;
}

// Handle Edit User
if (isset($_POST['edit_user'])) {
    try {
        // Get school information (nullable)
        $school_name = isset($_POST['school_name']) && $_POST['school_name'] !== '' ? $_POST['school_name'] : null;
        $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? $_POST['school_id'] : null;
        $district = isset($_POST['district']) && $_POST['district'] !== '' ? $_POST['district'] : null;
        $division = isset($_POST['division']) && $_POST['division'] !== '' ? $_POST['division'] : null;
        $region = isset($_POST['region']) && $_POST['region'] !== '' ? $_POST['region'] : null;
        
        // Update basic user info with school fields
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET username=?, password=?, full_name=?, role=?, school_name=?, school_id=?, district=?, division=?, region=? WHERE id=?");
            $stmt->bind_param("sssssssssi", $_POST['username'], $password, $_POST['full_name'], $_POST['role'], 
                              $school_name, $school_id, $district, $division, $region, $_POST['id']);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=?, full_name=?, role=?, school_name=?, school_id=?, district=?, division=?, region=? WHERE id=?");
            $stmt->bind_param("ssssssssi", $_POST['username'], $_POST['full_name'], $_POST['role'], 
                              $school_name, $school_id, $district, $division, $region, $_POST['id']);
        }
        
        if ($stmt->execute()) {
            $uid = $_POST['id'];
            
            // If teacher, update assignments
            if ($_POST['role'] === 'teacher') {
                $school_year_id = $_SESSION['school_year_id'];
                $school_year = $_SESSION['school_year'];
                
                // Get current adviser assignment from database before making changes
                $current_adviser_query = $conn->prepare("SELECT grade_level, section FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'adviser' AND school_year_id = ?");
                $current_adviser_query->bind_param("ii", $uid, $school_year_id);
                $current_adviser_query->execute();
                $current_adviser_result = $current_adviser_query->get_result();
                $current_adviser = $current_adviser_result->fetch_assoc();
                
                // Handle adviser assignment
                $new_assignment = isset($_POST['adviser_class']) && isset($_POST['is_adviser']) ? trim($_POST['adviser_class']) : '';
                
                // Delete existing adviser assignments for this teacher in current school year
                $stmt_del_adv = $conn->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'adviser' AND school_year_id = ?");
                $stmt_del_adv->bind_param("ii", $uid, $school_year_id);
                $stmt_del_adv->execute();
                
                // Add new adviser assignment if selected and checked
                if (!empty($new_assignment) && isset($_POST['is_adviser'])) {
                    list($grade, $section) = explode('|', $new_assignment);
                    $grade = (int)$grade;
                    
                    // First, remove any existing adviser from this section in current school year
                    $stmt_del = $conn->prepare("DELETE FROM teacher_assignments WHERE grade_level = ? AND section = ? AND assignment_type = 'adviser' AND school_year_id = ?");
                    $stmt_del->bind_param("isi", $grade, $section, $school_year_id);
                    $stmt_del->execute();
                    
                    // Now assign the new adviser
                    $stmt_adv = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, assignment_type, grade_level, section, school_year_id, school_year) VALUES (?, 'adviser', ?, ?, ?, ?)");
                    $stmt_adv->bind_param("iisis", $uid, $grade, $section, $school_year_id, $school_year);
                    $stmt_adv->execute();
                }
                
                // Handle subject assignments
                // Delete existing subject assignments for this teacher in current school year
                $stmt_del_subj = $conn->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'subject' AND school_year_id = ?");
                $stmt_del_subj->bind_param("ii", $uid, $school_year_id);
                $stmt_del_subj->execute();
                
                // Add new subject assignments if provided
                if (isset($_POST['subject_assignments']) && is_array($_POST['subject_assignments'])) {
                    foreach ($_POST['subject_assignments'] as $assignment) {
                        if (!empty($assignment['subject_id']) && !empty($assignment['grade']) && !empty($assignment['sections'])) {
                            $subject_id = (int)$assignment['subject_id'];
                            $grade = (int)$assignment['grade'];
                            
                            // Insert for each selected section
                            foreach ($assignment['sections'] as $section) {
                                $stmt_subj = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, assignment_type, subject_id, grade_level, section, school_year_id, school_year) 
                                                            VALUES (?, 'subject', ?, ?, ?, ?, ?)");
                                $stmt_subj->bind_param("iiisis", $uid, $subject_id, $grade, $section, $school_year_id, $school_year);
                                $stmt_subj->execute();
                            }
                        }
                    }
                }
            } else {
                // If changed to admin, remove all teacher assignments for current school year
                $school_year_id = $_SESSION['school_year_id'];
                $stmt_del_all = $conn->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ? AND school_year_id = ?");
                $stmt_del_all->bind_param("ii", $uid, $school_year_id);
                $stmt_del_all->execute();
            }
            
            $success = "User updated successfully!";
            
            // Re-fetch user data to show updated values in form
            $user_query = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $user_query->bind_param("i", $uid);
            $user_query->execute();
            $edit_user = $user_query->get_result()->fetch_assoc();
        } else {
            $error = "Error updating user: " . $stmt->error;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get current adviser assignment for the user
$adviser_assignment = null;
$subject_assignments = [];
if ($edit_user['role'] === 'teacher') {
    $school_year_id = $_SESSION['school_year_id'];
    
    $adviser_query = $conn->prepare("SELECT grade_level, section FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'adviser' AND school_year_id = ?");
    $adviser_query->bind_param("ii", $user_id, $school_year_id);
    $adviser_query->execute();
    $adviser_result = $adviser_query->get_result();
    $adviser_assignment = $adviser_result->fetch_assoc();
    
    // Get subject assignments
    $subject_query = $conn->prepare("SELECT subject_id, grade_level, section FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'subject' AND school_year_id = ?");
    $subject_query->bind_param("ii", $user_id, $school_year_id);
    $subject_query->execute();
    $subject_result = $subject_query->get_result();
    while ($row = $subject_result->fetch_assoc()) {
        $key = $row['subject_id'] . '_' . $row['grade_level'];
        if (!isset($subject_assignments[$key])) {
            $subject_assignments[$key] = [
                'subject_id' => $row['subject_id'],
                'grade_level' => $row['grade_level'],
                'sections' => []
            ];
        }
        $subject_assignments[$key]['sections'][] = $row['section'];
    }
}

include "../templates/header.php";
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-pencil-square"></i> Edit User</h2>
        <p class="subtitle">Update user information and assignments</p>
    </div>
    <a href="users.php" class="btn btn-info">
        <i class="bi bi-arrow-left"></i> Back to Users
    </a>
</div>

<?php if (!empty($success)): ?>
    <div class='alert alert-success alert-dismissible fade show' role='alert' id='successAlert'>
        <i class="bi bi-check-circle"></i> <?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class='alert alert-danger alert-dismissible fade show' role='alert' id='errorAlert'>
        <i class="bi bi-exclamation-circle"></i> <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<script>
// Auto-dismiss alerts with fade out
document.addEventListener('DOMContentLoaded', function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    if (successAlert) {
        setTimeout(() => {
            successAlert.style.transition = 'opacity 0.5s ease-out';
            successAlert.style.opacity = '0';
            setTimeout(() => successAlert.remove(), 500);
        }, 5000);
    }
    
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.style.transition = 'opacity 0.5s ease-out';
            errorAlert.style.opacity = '0';
            setTimeout(() => errorAlert.remove(), 500);
        }, 7000);
    }
});
</script>

<!-- Edit User Form -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-person-badge"></i> User Information
    </div>
    <div class="card-body">
        <form method="POST" id="editUserForm">
            <input type="hidden" name="id" value="<?= $edit_user['id'] ?>">
            
            <!-- Basic Info -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" autocomplete="off" 
                           value="<?= htmlspecialchars($edit_user['full_name']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" autocomplete="off" 
                           value="<?= htmlspecialchars($edit_user['username']) ?>" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">New Password (leave blank to keep current)</label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password" 
                           placeholder="Enter new password or leave blank">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Role <span class="text-danger">*</span></label>
                    <select name="role" class="form-control" id="roleSelect" onchange="toggleTeacherFields()" required>
                        <option value="admin" <?= $edit_user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="teacher" <?= $edit_user['role'] === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                    </select>
                </div>
            </div>
            
            <!-- School Information -->
            <hr>
            <h6 class="mb-3"><i class="bi bi-building"></i> School Information</h6>
            <div class="row mb-3">
                <div class="col-md-8">
                    <label class="form-label">School Name</label>
                    <input type="text" name="school_name" class="form-control" autocomplete="off" 
                           value="<?= htmlspecialchars($edit_user['school_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">School ID</label>
                    <input type="text" name="school_id" class="form-control" autocomplete="off" 
                           value="<?= htmlspecialchars($edit_user['school_id'] ?? '') ?>">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">District</label>
                    <input type="text" name="district" class="form-control" autocomplete="off" 
                           value="<?= htmlspecialchars($edit_user['district'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Division</label>
                    <input type="text" name="division" class="form-control" autocomplete="off" 
                           value="<?= htmlspecialchars($edit_user['division'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Region</label>
                    <input type="text" name="region" class="form-control" autocomplete="off" 
                           value="<?= htmlspecialchars($edit_user['region'] ?? '') ?>">
                </div>
            </div>
            
            <!-- Teacher Assignment Section -->
            <div class="teacher-fields <?= $edit_user['role'] !== 'teacher' ? 'd-none' : '' ?>">
                <hr>
                <h6 class="mb-3"><i class="bi bi-person-badge"></i> Teacher Assignments</h6>
                
                <!-- Adviser Assignment -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_adviser" value="1" id="isAdviserCheck" 
                                   <?= $adviser_assignment ? 'checked' : '' ?> onchange="toggleAdviserFields()">
                            <label class="form-check-label" for="isAdviserCheck">
                                <strong>This teacher is a Class Adviser</strong>
                            </label>
                        </div>
                        <div class="adviser-fields <?= !$adviser_assignment ? 'd-none' : '' ?>">
                            <div class="mb-3">
                                <label class="form-label">Select Class to Advise <span class="text-danger">*</span></label>
                                <select name="adviser_class" id="adviserClassSelect" class="form-control" onchange="checkExistingAdviser()">
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($classes_data as $class): ?>
                                        <?php 
                                        $class_value = $class['grade_level'] . '|' . $class['section'];
                                        $is_selected = '';
                                        if ($adviser_assignment && 
                                            $adviser_assignment['grade_level'] == $class['grade_level'] && 
                                            $adviser_assignment['section'] == $class['section']) {
                                            $is_selected = 'selected';
                                        }
                                        ?>
                                        <option value="<?= $class_value ?>" <?= $is_selected ?>>
                                            Grade <?= $class['grade_level'] ?> - <?= htmlspecialchars($class['section']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Select from existing classes in the system</small>
                                <div id="adviserWarning" class="alert alert-warning mt-2" style="display: none; border-left: 4px solid #ff6b6b; background-color: #fff3cd; padding: 10px;">
                                    <i class="bi bi-exclamation-triangle-fill text-danger"></i> 
                                    <strong id="adviserWarningText"></strong>
                                    <br>
                                    <small>Saving changes will reassign this section. The previous teacher will be unassigned.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Subject Assignments -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="hasSubjectsCheck" 
                                   <?= !empty($subject_assignments) ? 'checked' : '' ?> onchange="toggleSubjectFields()">
                            <label class="form-check-label" for="hasSubjectsCheck">
                                <strong>This teacher has Subject Assignments</strong>
                            </label>
                        </div>
                        <div class="subject-fields <?= empty($subject_assignments) ? 'd-none' : '' ?>">
                            <div class="d-flex justify-content-between align-items-center mb-3 pb-2">
                                <strong><i class="bi bi-book"></i> Subject Assignments</strong>
                                <button type="button" class="btn btn-sm btn-success" onclick="addSubjectRow()">
                                    <i class="bi bi-plus-circle"></i> Add Subject
                                </button>
                            </div>
                            <div id="subjectAssignmentsContainer">
                                <?php if (empty($subject_assignments)): ?>
                                <p class="text-muted text-center mb-0">
                                    <i class="bi bi-info-circle"></i> Click "Add Subject" to assign this teacher to subjects
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="users.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
                <button type="submit" name="edit_user" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let subjectRowCounter = 0;

// Get classes data (grade levels and sections)
const classesData = <?= json_encode($classes_data) ?>;
const currentUserId = <?= $user_id ?>;

// Cache for subjects by grade level
const subjectsByGrade = {};

// Existing subject assignments from database
const existingSubjectAssignments = <?= json_encode(array_values($subject_assignments)) ?>;

function toggleTeacherFields() {
    const role = document.getElementById('roleSelect').value;
    document.querySelector('.teacher-fields').classList.toggle('d-none', role !== 'teacher');
}

function toggleAdviserFields() {
    const isAdviser = document.getElementById('isAdviserCheck').checked;
    document.querySelector('.adviser-fields').classList.toggle('d-none', !isAdviser);
}

function toggleSubjectFields() {
    const hasSubjects = document.getElementById('hasSubjectsCheck').checked;
    document.querySelector('.subject-fields').classList.toggle('d-none', !hasSubjects);
    
    // Clear subject assignments if unchecked
    if (!hasSubjects) {
        const container = document.getElementById('subjectAssignmentsContainer');
        container.innerHTML = '<p class="text-muted text-center mb-0"><i class="bi bi-info-circle"></i> Click "Add Subject" to assign this teacher to subjects</p>';
    }
}

function addSubjectRow(existingData = null) {
    subjectRowCounter++;
    const container = document.getElementById('subjectAssignmentsContainer');
    
    // Remove "no subjects" message if present
    const emptyMsg = container.querySelector('p.text-muted');
    if (emptyMsg) emptyMsg.remove();
    
    const row = document.createElement('div');
    row.className = 'subject-row border rounded p-3 mb-3 bg-light';
    row.id = 'subjectRow' + subjectRowCounter;
    
    let gradeOptions = '<option value="">-- Select Grade --</option>';
    let uniqueGrades = [...new Set(classesData.map(c => c.grade_level))].sort((a, b) => a - b);
    uniqueGrades.forEach(grade => {
        const selected = existingData && existingData.grade_level == grade ? 'selected' : '';
        gradeOptions += `<option value="${grade}" ${selected}>Grade ${grade}</option>`;
    });
    
    row.innerHTML = `
        <div class="row mb-2">
            <div class="col-md-4">
                <label class="form-label"><strong>Grade Level</strong></label>
                <select name="subject_assignments[${subjectRowCounter}][grade]" 
                        id="gradeSelect${subjectRowCounter}"
                        class="form-control" 
                        onchange="loadSubjectsForRow(${subjectRowCounter})" 
                        required>
                    ${gradeOptions}
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><strong>Subject</strong></label>
                <select name="subject_assignments[${subjectRowCounter}][subject_id]" 
                        id="subjectSelect${subjectRowCounter}"
                        class="form-control" 
                        ${existingData ? '' : 'disabled'}
                        required>
                    <option value="">-- Select Grade First --</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeSubjectRow(${subjectRowCounter})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <label class="form-label"><strong>Sections</strong></label>
                <div id="sectionsContainer${subjectRowCounter}" class="border rounded p-3 bg-white" style="min-height: 60px;">
                    <p class="text-muted mb-0"><i class="bi bi-arrow-up"></i> Select a grade level first</p>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(row);
    
    // Load subjects if existing data
    if (existingData) {
        loadSubjectsForRow(subjectRowCounter, existingData);
    }
}

function loadSubjectsForRow(rowId, existingData = null) {
    const gradeSelect = document.getElementById('gradeSelect' + rowId);
    const subjectSelect = document.getElementById('subjectSelect' + rowId);
    const selectedGrade = gradeSelect.value;
    
    if (!selectedGrade) {
        subjectSelect.innerHTML = '<option value="">-- Select Grade First --</option>';
        subjectSelect.disabled = true;
        return;
    }
    
    // Check cache first
    if (subjectsByGrade[selectedGrade]) {
        populateSubjectDropdown(rowId, subjectsByGrade[selectedGrade], existingData);
        updateSections(rowId, existingData);
        return;
    }
    
    // Fetch subjects for this grade level
    subjectSelect.innerHTML = '<option value="">Loading...</option>';
    subjectSelect.disabled = true;
    
    fetch(`edit_user.php?ajax=get_grade_subjects&grade=${selectedGrade}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error('Server error:', data.error);
                subjectSelect.innerHTML = '<option value="">Error: ' + data.error + '</option>';
                return;
            }
            subjectsByGrade[selectedGrade] = data.subjects;
            populateSubjectDropdown(rowId, data.subjects, existingData);
            updateSections(rowId, existingData);
        })
        .catch(error => {
            console.error('Error loading subjects:', error);
            subjectSelect.innerHTML = '<option value="">Network error loading subjects</option>';
        });
}

function populateSubjectDropdown(rowId, subjects, existingData = null) {
    const subjectSelect = document.getElementById('subjectSelect' + rowId);
    let options = '<option value="">-- Select Subject --</option>';
    
    subjects.forEach(subject => {
        const selected = existingData && existingData.subject_id == subject.id ? 'selected' : '';
        options += `<option value="${subject.id}" ${selected}>${subject.name}</option>`;
    });
    
    subjectSelect.innerHTML = options;
    subjectSelect.disabled = false;
}

function updateSections(rowId, existingData = null) {
    const gradeSelect = document.getElementById('gradeSelect' + rowId);
    const sectionsContainer = document.getElementById('sectionsContainer' + rowId);
    const selectedGrade = gradeSelect.value;
    
    if (!selectedGrade) {
        sectionsContainer.innerHTML = '<p class="text-muted mb-0"><i class="bi bi-arrow-up"></i> Select a grade level first</p>';
        return;
    }
    
    const gradeSections = classesData.filter(c => c.grade_level == selectedGrade);
    
    if (gradeSections.length === 0) {
        sectionsContainer.innerHTML = '<p class="text-warning mb-0"><i class="bi bi-exclamation-triangle"></i> No sections found for this grade.</p>';
        return;
    }
    
    let checkboxesHtml = '<div class="d-flex flex-wrap gap-3">';
    gradeSections.forEach(cls => {
        const sectionId = `sec${rowId}_${cls.section.replace(/\s+/g, '_')}`;
        const checked = existingData && existingData.sections.includes(cls.section) ? 'checked' : '';
        checkboxesHtml += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" 
                       name="subject_assignments[${rowId}][sections][]" 
                       value="${cls.section}" 
                       id="${sectionId}"
                       ${checked}>
                <label class="form-check-label" for="${sectionId}">${cls.section}</label>
            </div>
        `;
    });
    checkboxesHtml += '</div>';
    
    sectionsContainer.innerHTML = checkboxesHtml;
}

function removeSubjectRow(id) {
    const row = document.getElementById('subjectRow' + id);
    row.remove();
    
    // Show empty message if no rows left
    const container = document.getElementById('subjectAssignmentsContainer');
    if (container.children.length === 0) {
        container.innerHTML = '<p class="text-muted text-center mb-0"><i class="bi bi-info-circle"></i> Click "Add Subject" to assign this teacher to subjects</p>';
    }
}

// Check if selected section already has an adviser
function checkExistingAdviser() {
    const dropdown = document.getElementById('adviserClassSelect');
    const warningDiv = document.getElementById('adviserWarning');
    const warningText = document.getElementById('adviserWarningText');
    const selectedValue = dropdown.value;
    
    if (!selectedValue) {
        // No selection - hide warning
        warningDiv.style.display = 'none';
        dropdown.classList.remove('border-danger');
        dropdown.style.boxShadow = '';
        return;
    }
    
    const [grade, section] = selectedValue.split('|');
    
    fetch(`edit_user.php?ajax=check_adviser&grade=${grade}&section=${encodeURIComponent(section)}&exclude_user=${currentUserId}`)
        .then(response => response.json())
        .then(data => {
            if (data.has_adviser) {
                // Show warning with red border
                dropdown.classList.add('border-danger');
                warningText.textContent = `Grade ${grade} - ${section} is currently assigned to "${data.adviser_name}".`;
                warningDiv.style.display = 'block';
                
                // Add glow effect
                dropdown.style.boxShadow = '0 0 10px rgba(255, 107, 107, 0.5)';
            } else {
                // No conflict - hide warning
                dropdown.classList.remove('border-danger');
                dropdown.style.boxShadow = '';
                warningDiv.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error checking adviser:', error);
        });
}

// Load existing subject assignments on page load
document.addEventListener('DOMContentLoaded', function() {
    // Submit form on Enter key globally (unless in a textarea)
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const activeElement = document.activeElement;
            // Don't submit if we're in a textarea or if a modal is open (modals usually handle their own Enter)
            if (activeElement.tagName === 'TEXTAREA' || document.querySelector('.modal.show')) {
                return;
            }
            
            e.preventDefault();
            const form = document.getElementById('editUserForm');
            if (form) {
                // Trigger the submit button instead of calling submit() directly
                // This ensures the button's name/value are included in $_POST
                const submitBtn = form.querySelector('button[type="submit"][name="edit_user"]');
                if (submitBtn) submitBtn.click();
            }
        }
    });

    if (existingSubjectAssignments && existingSubjectAssignments.length > 0) {
        existingSubjectAssignments.forEach(assignment => {
            addSubjectRow(assignment);
        });
    }
});
</script>

<?php include '../templates/footer.php'; ?>
