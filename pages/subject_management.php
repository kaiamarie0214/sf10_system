<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/logger.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'];

// Handle subject activation/deactivation for specific grades
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'toggle_subject') {
        $subject_id = intval($_POST['subject_id']);
        $grade_level = intval($_POST['grade_level']);
        $is_active = intval($_POST['is_active']);
        
        // Create subject_grade_config table if it doesn't exist
        $conn->query("CREATE TABLE IF NOT EXISTS `subject_grade_config` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `subject_id` INT NOT NULL,
            `grade_level` INT NOT NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            UNIQUE KEY `subject_grade` (`subject_id`, `grade_level`),
            FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
        )");
        
        $stmt = $conn->prepare("INSERT INTO subject_grade_config (subject_id, grade_level, is_active) 
                                VALUES (?, ?, ?) 
                                ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)");
        $stmt->bind_param("iii", $subject_id, $grade_level, $is_active);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        exit;
    }
}

// Get all subjects
$subjects = $conn->query("SELECT * FROM subjects ORDER BY display_order, subject_name");

// Get subject configuration by grade
$subject_config = [];
$config_result = $conn->query("SELECT subject_id, grade_level, is_active FROM subject_grade_config");
if ($config_result) {
    while ($row = $config_result->fetch_assoc()) {
        $subject_config[$row['subject_id']][$row['grade_level']] = $row['is_active'];
    }
}

include '../templates/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <h2><i class="bi bi-book"></i> K-12 Subject Management</h2>
        <p class="subtitle">Configure subjects for each grade level</p>
    </div>

    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> 
        <strong>Philippine K-12 Curriculum:</strong> 
        Subjects are pre-configured based on DepEd standards. Enable/disable subjects per grade level as needed for your school.
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Grade 1</th>
                            <th>Grade 2</th>
                            <th>Grade 3</th>
                            <th>Grade 4</th>
                            <th>Grade 5</th>
                            <th>Grade 6</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($subject = $subjects->fetch_assoc()): ?>
                            <?php if ($subject['subject_name'] === 'General Average') continue; ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($subject['subject_name']) ?></strong>
                                    <?php if ($subject['is_mapeh_component']): ?>
                                        <span class="badge bg-secondary">MAPEH Component</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($subject['subject_code'] ?? '') ?></td>
                                <td>
                                    <?php if ($subject['is_core']): ?>
                                        <span class="badge bg-primary">Core</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Optional</span>
                                    <?php endif; ?>
                                </td>
                                <?php for ($grade = 1; $grade <= 6; $grade++): ?>
                                    <td class="text-center">
                                        <?php 
                                        $in_range = ($grade >= $subject['min_grade'] && $grade <= $subject['max_grade']);
                                        $is_active = isset($subject_config[$subject['id']][$grade]) 
                                            ? $subject_config[$subject['id']][$grade] 
                                            : $in_range;
                                        
                                        if ($in_range):
                                        ?>
                                            <div class="form-check form-switch d-inline-block">
                                                <input class="form-check-input subject-toggle" 
                                                       type="checkbox" 
                                                       <?= $is_active ? 'checked' : '' ?>
                                                       data-subject-id="<?= $subject['id'] ?>"
                                                       data-grade="<?= $grade ?>"
                                                       onchange="toggleSubject(this)">
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header">
            <h5>Subject Guidelines</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Grades 1-3 Subjects:</h6>
                    <ul>
                        <li>Mother Tongue-Based MLE</li>
                        <li>Filipino</li>
                        <li>English</li>
                        <li>Mathematics</li>
                        <li>Science</li>
                        <li>Araling Panlipunan</li>
                        <li>MAPEH (or components)</li>
                        <li>Edukasyon sa Pagpapakatao</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6>Grades 4-6 Subjects:</h6>
                    <ul>
                        <li>Filipino</li>
                        <li>English</li>
                        <li>Mathematics</li>
                        <li>Science</li>
                        <li>Araling Panlipunan</li>
                        <li>EPP/TLE</li>
                        <li>MAPEH (or components)</li>
                        <li>Edukasyon sa Pagpapakatao</li>
                    </ul>
                </div>
            </div>
            <hr>
            <p class="mb-0">
                <strong>Note:</strong> MAPEH can be recorded as a single subject or broken down into Music, Arts, Physical Education, and Health components. 
                Configure based on your school's grading practice.
            </p>
        </div>
    </div>
</div>

<script>
function toggleSubject(checkbox) {
    const subjectId = checkbox.dataset.subjectId;
    const grade = checkbox.dataset.grade;
    const isActive = checkbox.checked ? 1 : 0;
    
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=toggle_subject&subject_id=${subjectId}&grade_level=${grade}&is_active=${isActive}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success feedback
            checkbox.parentElement.style.animation = 'pulse 0.3s';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        checkbox.checked = !checkbox.checked; // Revert on error
    });
}
</script>

<style>
@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.subject-toggle {
    cursor: pointer;
}

.table th {
    background-color: var(--primary-teal);
    color: white;
}
</style>

<?php include '../templates/footer.php'; ?>
