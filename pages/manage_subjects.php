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
    
    if (empty($subjects)) {
        echo json_encode(['success' => false, 'message' => 'No subjects provided']);
        exit;
    }
    
    $updated = 0;
    foreach ($subjects as $subject) {
        $grade_level = intval($subject['grade_level']);
        $subject_id = intval($subject['subject_id']);
        $subject_name = isset($subject['subject_name']) ? trim($subject['subject_name']) : '';

        if ($grade_level < 1 || $grade_level > 6) continue;

        $stmt = $conn->prepare("INSERT INTO subject_grade_groups 
                               (grade_level, subject_id, subject_name, display_order)
                               VALUES (?, ?, ?, 0)
                               ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name)");
        $stmt->bind_param("iis", $grade_level, $subject_id, $subject_name);
        
        if ($stmt->execute()) {
            $updated++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Updated $updated subject(s) successfully"
    ]);
    exit;
}

// Set current page for sidebar navigation
$_SERVER['PHP_SELF'] = 'manage_subjects.php';

include "../templates/header.php";
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-book"></i> Manage School Subject Format</h2>
        <p class="subtitle">Configure default subject names for each grade level</p>
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

<?php
// Check if table exists
$table_exists = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'")->num_rows > 0;

if (!$table_exists) {
    echo '<div class="alert alert-danger">Database table subject_grade_groups not found. Please run database migration.</div>';
    include '../templates/footer.php';
    exit;
}

// Get all subjects
$all_subjects = $conn->query("SELECT id, subject_name FROM subjects WHERE subject_name != 'General Average' ORDER BY id");

// Get subjects for each grade level (1-6)
$subjects_by_grade = [];

for ($grade = 1; $grade <= 6; $grade++) {
    $subjects_by_grade[$grade] = [];
    $all_subjects->data_seek(0); // Reset pointer
    
    while ($subject = $all_subjects->fetch_assoc()) {
        $grade_query = $conn->query("SELECT subject_name FROM subject_grade_groups 
                                     WHERE grade_level = $grade AND subject_id = {$subject['id']}");
        
        $display_name = $subject['subject_name'];
        if ($grade_query && $grade_query->num_rows > 0) {
            $grade_data = $grade_query->fetch_assoc();
            $display_name = $grade_data['subject_name'];
        }
        
        $subjects_by_grade[$grade][] = [
            'subject_id' => $subject['id'],
            'original_name' => $subject['subject_name'],
            'subject_name' => $display_name
        ];
    }
}
?>

<script>
const subjectsByGrade = <?= json_encode($subjects_by_grade) ?>;

// Load subjects when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Load subjects for all grades
    for (let grade = 1; grade <= 6; grade++) {
        loadSchoolSubjects(grade);
    }
});

function loadSchoolSubjects(gradeLevel) {
    const container = document.getElementById(`subjects-grade-${gradeLevel}-list`);
    const subjects = subjectsByGrade[gradeLevel] || [];
    
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
                               placeholder="Enter display name for this subject"
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
    
    fetch('?ajax=save_school_subjects', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ subjects: updates })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Broadcast to all tabs that subject names were updated
            const updatedGrades = [...new Set(updates.map(u => u.grade_level))];
            updatedGrades.forEach(gradeLevel => {
                localStorage.setItem('subjectNamesUpdated', JSON.stringify({
                    gradeLevel: gradeLevel,
                    timestamp: new Date().getTime()
                }));
                localStorage.removeItem('subjectNamesUpdated');
            });
            
            showSuccessMessage(data.message || 'School subjects updated successfully!');
            // Reload page after 1.5 seconds
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            alert('Error saving subjects: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to save school subjects');
    });
}

function showSuccessMessage(message) {
    document.getElementById('successModalBody').textContent = message;
    const modal = new bootstrap.Modal(document.getElementById('successModal'));
    modal.show();
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
