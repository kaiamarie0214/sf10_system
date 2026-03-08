<?php
session_start();
include_once "../includes/db.php";

// Role check: Only teachers should access this page
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

$current_user_id = $_SESSION['user']['id'];

// Get current school year from session or active
$active_school_year = null;
$active_school_year_id = null;

if (isset($_SESSION['school_year']) && isset($_SESSION['school_year_id'])) {
    $active_school_year = $_SESSION['school_year'];
    $active_school_year_id = $_SESSION['school_year_id'];
} else {
    $active_sy_query = "SELECT id, year FROM school_years WHERE is_active = 1 LIMIT 1";
    $active_sy_result = $conn->query($active_sy_query);
    if ($active_sy_result && $active_sy_result->num_rows > 0) {
        $sy_row = $active_sy_result->fetch_assoc();
        $active_school_year = $sy_row['year'];
        $active_school_year_id = $sy_row['id'];
    }
}

// Get teacher's subject assignments grouped by class - FILTERED BY SCHOOL YEAR
$assignments_query = "SELECT DISTINCT ta.grade_level, ta.section, ta.subject_id,
                     COALESCE(NULLIF(sgg.subject_name, ''), s.subject_name) AS subject_name
                     FROM teacher_assignments ta
                     LEFT JOIN subjects s ON ta.subject_id = s.id
                     LEFT JOIN subject_grade_groups sgg ON sgg.subject_id = ta.subject_id 
                          AND sgg.grade_level = ta.grade_level
                          AND (sgg.school_year = ta.school_year OR sgg.school_year IS NULL)
                     WHERE ta.teacher_id = ?
                     AND ta.assignment_type = 'subject'
                     AND ta.school_year = ?
                     ORDER BY ta.grade_level, ta.section, subject_name";

$stmt = $conn->prepare($assignments_query);
$stmt->bind_param("is", $current_user_id, $active_school_year);
$stmt->execute();
$assignments_result = $stmt->get_result();

// Group assignments by grade_level and section
$assignments_by_class = [];
while ($row = $assignments_result->fetch_assoc()) {
    $class_key = $row['grade_level'] . '-' . $row['section'];
    if (!isset($assignments_by_class[$class_key])) {
        $assignments_by_class[$class_key] = [
            'grade_level' => $row['grade_level'],
            'section' => $row['section'],
            'subjects' => []
        ];
    }
    $assignments_by_class[$class_key]['subjects'][] = [
        'subject_id' => $row['subject_id'],
        'subject_name' => $row['subject_name']
    ];
}

include_once "../templates/header.php";
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-pencil-square"></i> Input Grades</h2>
        <p class="subtitle">Select a class to input grades (School Year: <?php echo htmlspecialchars($active_school_year ?? 'Not Set'); ?>)</p>
    </div>
</div>

<?php if (empty($assignments_by_class)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> You have no subject assignments. Please contact your administrator to assign subjects to you.
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Grade Level</th>
                                        <th>Section</th>
                                        <th>Subjects</th>
                                        <th>Students</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments_by_class as $class_key => $class_data): 
                                        // Count students in this class
                                        $count_query = "SELECT COUNT(DISTINCT s.id) as student_count
                                                       FROM students s
                                                       INNER JOIN schools_attended sa ON s.id = sa.student_id
                                                       WHERE sa.grade_level = ?
                                                       AND LOWER(sa.section) = LOWER(?)
                                                       AND sa.school_year = ?";
                                        
                                        $count_stmt = $conn->prepare($count_query);
                                        $count_stmt->bind_param("iss", 
                                            $class_data['grade_level'], 
                                            $class_data['section'], 
                                            $active_school_year
                                        );
                                        $count_stmt->execute();
                                        $count_result = $count_stmt->get_result();
                                        $student_count = $count_result->fetch_assoc()['student_count'];
                                        
                                        $subject_names = array_column($class_data['subjects'], 'subject_name');
                                    ?>
                                        <tr>
                                            <td><strong>Grade <?php echo $class_data['grade_level']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($class_data['section']); ?></td>
                                            <td><span class="badge bg-info text-dark"><?php echo implode('</span> <span class="badge bg-info text-dark">', $subject_names); ?></span></td>
                                            <td><i class="bi bi-people-fill"></i> <?php echo $student_count; ?> student<?php echo $student_count != 1 ? 's' : ''; ?></td>
                                            <td>
                                                <a href="input_grades_form.php?grade_level=<?php echo $class_data['grade_level']; ?>&section=<?php echo urlencode($class_data['section']); ?>" 
                                                   class="btn btn-sm btn-info">
                                                    <i class="bi bi-pencil-square"></i> Enter Grades
                                                </a>
                                            </td>
                                        </tr>   
                                    <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
<?php endif; ?>

<?php include_once "../templates/footer.php"; ?>
