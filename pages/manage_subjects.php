<?php
session_start();
require_once "../includes/db.php";

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];

// AJAX endpoint for saving school subjects configuration
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_school_subjects') {
    header('Content-Type: application/json');
    
    // Check if table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'")->num_rows > 0;
    
    if (!$table_exists) {
        echo json_encode(['success' => false, 'message' => 'Please run database migration first']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $subjects = $input['subjects'] ?? [];
    $selected_year = $input['school_year'] ?? null;
    
    if (empty($subjects)) {
        echo json_encode(['success' => false, 'message' => 'No subjects provided']);
        exit;
    }
    
    if (!$selected_year) {
        echo json_encode(['success' => false, 'message' => 'School year is required']);
        exit;
    }
    
    // Include logger
    include_once "../includes/logger.php";
    
    $updated = 0;
    foreach ($subjects as $subject) {
        $grade_level = intval($subject['grade_level']);
        $subject_id = intval($subject['subject_id']);
        $subject_name = isset($subject['subject_name']) ? trim($subject['subject_name']) : '';

        if ($grade_level < 1 || $grade_level > 6) continue;

        $stmt = $conn->prepare("INSERT INTO subject_grade_groups 
                               (grade_level, subject_id, subject_name, school_year, display_order)
                               VALUES (?, ?, ?, ?, 0)
                               ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name)");
        $stmt->bind_param("iiss", $grade_level, $subject_id, $subject_name, $selected_year);
        
        if ($stmt->execute()) {
            $updated++;
        }
    }
    
    if ($updated > 0) {
        // Log the overall activity
        logActivity($conn, $_SESSION['user']['id'], 'UPDATE', 'subject_grade_groups', null, 
                    "Updated $updated school subject names for School Year $selected_year across Grade levels 1-6");
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Updated $updated subject(s) successfully"
    ]);
    exit;
}

// AJAX endpoint for fetching school subjects configuration
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_school_subjects') {
    header('Content-Type: application/json');
    $school_year = $_GET['school_year'] ?? '';
    
    if (!$school_year) {
        echo json_encode(['success' => false, 'message' => 'School year is required']);
        exit;
    }

    $all_subjects = $conn->query("SELECT id, subject_name FROM subjects WHERE subject_name != 'General Average' ORDER BY id");
    $subjects_by_grade = [];

    for ($grade = 1; $grade <= 6; $grade++) {
        $subjects_by_grade[$grade] = [];
        $all_subjects->data_seek(0);
        
        while ($subject = $all_subjects->fetch_assoc()) {
            $stmt = $conn->prepare("SELECT subject_name FROM subject_grade_groups 
                                   WHERE grade_level = ? AND subject_id = ? AND school_year = ?");
            $stmt->bind_param("iis", $grade, $subject['id'], $school_year);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $display_name = $subject['subject_name'];
            if ($res && $res->num_rows > 0) {
                $display_name = $res->fetch_assoc()['subject_name'];
            }
            
            $subjects_by_grade[$grade][] = [
                'subject_id' => $subject['id'],
                'original_name' => $subject['subject_name'],
                'subject_name' => $display_name
            ];
        }
    }
    
    echo json_encode(['success' => true, 'subjects' => $subjects_by_grade]);
    exit;
}

// Set current page for sidebar navigation
$_SERVER['PHP_SELF'] = 'manage_subjects.php';

include "../templates/header.php";

// Get available school years
$sy_res = $conn->query("SELECT year FROM school_years ORDER BY year DESC");
$all_years = [];
while ($row = $sy_res->fetch_assoc()) {
    $all_years[] = $row['year'];
}

// Get active school year from session or database
$active_year = $_SESSION['school_year'] ?? null;
if (!$active_year) {
    $active_sy_res = $conn->query("SELECT year FROM school_years WHERE is_active = 1 LIMIT 1");
    $active_year = ($active_sy_res && $active_sy_res->num_rows > 0) ? $active_sy_res->fetch_assoc()['year'] : ($all_years[0] ?? date('Y-Y'));
}
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-book"></i> Manage School Subject Format</h2>
        <p class="subtitle">Configure default subject names for each grade level</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <label class="fw-bold mb-0">School Year:</label>
        <select id="school-year-select" class="form-select" style="width: auto;" onchange="changeSchoolYear()">
            <?php foreach ($all_years as $sy): ?>
                <option value="<?= $sy ?>" <?= $sy === $active_year ? 'selected' : '' ?>><?= $sy ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    <strong>Global School Subjects:</strong>
    These are the default subject names used for regular (non-transfer) students.
    Configure subject names per grade level.
</div>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journal-check"></i> Subject Name Configuration</span>
        <span class="badge bg-secondary">Grades 1-6</span>
    </div>
    <div class="card-body">
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#grade-1" type="button">
                        Grade 1
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#grade-2" type="button">
                        Grade 2
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#grade-3" type="button">
                        Grade 3
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#grade-4" type="button">
                        Grade 4
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#grade-5" type="button">
                        Grade 5
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#grade-6" type="button">
                        Grade 6
                    </button>
                </li>
            </ul>

            <div class="tab-content" style="min-height: 400px;">
                <!-- Grade 1 Tab -->
                <div class="tab-pane fade show active" id="grade-1">
                    <h6 class="mb-3">Subject Names for Grade 1</h6>
                    <div id="subjects-grade-1-list">
                        <p class="text-muted">Loading...</p>
                    </div>
                </div>

                <!-- Grade 2 Tab -->
                <div class="tab-pane fade" id="grade-2">
                    <h6 class="mb-3">Subject Names for Grade 2</h6>
                    <div id="subjects-grade-2-list">
                        <p class="text-muted">Loading...</p>
                    </div>
                </div>

                <!-- Grade 3 Tab -->
                <div class="tab-pane fade" id="grade-3">
                    <h6 class="mb-3">Subject Names for Grade 3</h6>
                    <div id="subjects-grade-3-list">
                        <p class="text-muted">Loading...</p>
                    </div>
                </div>

                <!-- Grade 4 Tab -->
                <div class="tab-pane fade" id="grade-4">
                    <h6 class="mb-3">Subject Names for Grade 4</h6>
                    <div id="subjects-grade-4-list">
                        <p class="text-muted">Loading...</p>
                    </div>
                </div>

                <!-- Grade 5 Tab -->
                <div class="tab-pane fade" id="grade-5">
                    <h6 class="mb-3">Subject Names for Grade 5</h6>
                    <div id="subjects-grade-5-list">
                        <p class="text-muted">Loading...</p>
                    </div>
                </div>

                <!-- Grade 6 Tab -->
                <div class="tab-pane fade" id="grade-6">
                    <h6 class="mb-3">Subject Names for Grade 6</h6>
                    <div id="subjects-grade-6-list">
                        <p class="text-muted">Loading...</p>
                    </div>
                </div>
            </div>

        <hr class="my-4">

        <div class="d-flex gap-2 justify-content-end">
            <a href="grades.php" class="btn btn-secondary">
                <i class="bi bi-x-circle"></i> Cancel
            </a>
            <button type="button" class="btn btn-primary" onclick="saveSchoolSubjects()">
                <i class="bi bi-save"></i> Save Changes
            </button>
        </div>
    </div>
</div>

<!-- Success Message Modal -->
<div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true" data-bs-backdrop="true" style="margin-top: 80px; z-index: 1060;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="successModalLabel">
                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                    Success
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="successModalBody">
                <!-- Dynamic message will be set here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentSubjectsByGrade = {};

// Load subjects when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Initial load
    changeSchoolYear();

    // Tab persistence logic
    const activeTab = localStorage.getItem('activeSubjectTab');
    if (activeTab) {
        const tabTrigger = document.querySelector(`button[data-bs-target="${activeTab}"]`);
        if (tabTrigger) {
            const tab = new bootstrap.Tab(tabTrigger);
            tab.show();
        }
    }

    // Save active tab when changed
    const tabTriggers = document.querySelectorAll('button[data-bs-toggle="tab"]');
    tabTriggers.forEach(trigger => {
        trigger.addEventListener('shown.bs.tab', function (event) {
            localStorage.setItem('activeSubjectTab', event.target.getAttribute('data-bs-target'));
        });
    });
});

function changeSchoolYear() {
    const year = document.getElementById('school-year-select').value;
    
    // Show loading state
    for (let grade = 1; grade <= 6; grade++) {
        document.getElementById(`subjects-grade-${grade}-list`).innerHTML = '<p class="text-muted">Loading subjects for ' + year + '...</p>';
    }

    fetch(`?ajax=get_school_subjects&school_year=${encodeURIComponent(year)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentSubjectsByGrade = data.subjects;
                for (let grade = 1; grade <= 6; grade++) {
                    renderSchoolSubjects(grade);
                }
            } else {
                alert('Error loading subjects: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to load subjects');
        });
}

function renderSchoolSubjects(gradeLevel) {
    const container = document.getElementById(`subjects-grade-${gradeLevel}-list`);
    const subjects = currentSubjectsByGrade[gradeLevel] || [];
    
    if (subjects.length === 0) {
        container.innerHTML = '<p class="text-muted">No subjects configured for this grade level.</p>';
        return;
    }
    
    let html = '<div class="list-group">';
    subjects.forEach((subject, index) => {
        html += `
            <div class="list-group-item">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <label class="form-label mb-0"><strong>${escapeHtml(subject.original_name)}</strong></label>
                        <small class="text-muted d-block">Original subject name</small>
                    </div>
                    <div class="col-md-8">
                        <input type="text" 
                               class="form-control school-subject-input" 
                               data-grade-level="${gradeLevel}"
                               data-subject-id="${subject.subject_id}"
                               value="${escapeHtml(subject.subject_name)}"
                               placeholder=""
                               onkeydown="if(event.key==='Enter'){event.preventDefault();saveSchoolSubjects();}"  >
                    </div>
                </div>
            </div>`;
    });
    html += '</div>';
    
    container.innerHTML = html;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function saveSchoolSubjects() {
    const year = document.getElementById('school-year-select').value;
    const inputs = document.querySelectorAll('.school-subject-input');
    const updates = [];
    
    inputs.forEach(input => {
        updates.push({
            grade_level: parseInt(input.dataset.gradeLevel),
            subject_id: parseInt(input.dataset.subjectId),
            subject_name: input.value.trim()
        });
    });
    
    if (updates.length === 0) {
        alert('No subjects to save');
        return;
    }
    
    const saveBtn = document.querySelector('button[onclick="saveSchoolSubjects()"]');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

    fetch('?ajax=save_school_subjects', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ 
            subjects: updates,
            school_year: year
        })
    })
    .then(response => response.json())
    .then(data => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;

        if (data.success) {
            const modal = new bootstrap.Modal(document.getElementById('successModal'));
            document.getElementById('successModalBody').innerText = data.message;
            modal.show();
        } else {
            alert('Error saving subjects: ' + data.message);
        }
    })
    .catch(error => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
        console.error('Error:', error);
        alert('Failed to save subjects');
    });
}
</script>

<style>
.nav-tabs .nav-link {
    color: #6c757d;
}

.nav-tabs .nav-link.active {
    color: var(--primary-color);
    font-weight: 600;
}

.input-group-text {
    min-width: 45px;
    justify-content: center;
}
</style>

<?php include '../templates/footer.php'; ?>

