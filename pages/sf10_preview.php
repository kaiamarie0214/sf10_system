<?php
require_once '../includes/db.php';
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

$_SERVER['PHP_SELF'] = 'sf10_form.php';

// Function to get subject name for a specific student
function getSubjectNameForStudent($conn, $subject_id, $student_id, $school_attended_id) {
    // First, determine if this is a transfer student
    $is_transfer = false;
    $grade_level = null;
    
    $school_info = $conn->query("SELECT grade_level, adviser_name FROM schools_attended WHERE id = $school_attended_id");
    if ($school_info && $school_info->num_rows > 0) {
        $school_data = $school_info->fetch_assoc();
        $grade_level = $school_data['grade_level'];
        
        // Auto-detect transfer status based on adviser existence in users table
        if (!empty($school_data['adviser_name'])) {
            $adviser_check = $conn->query("SELECT id FROM users WHERE full_name = '" . $conn->real_escape_string($school_data['adviser_name']) . "'")->num_rows;
            $is_transfer = ($adviser_check == 0); // Transfer if adviser not in system
        } else {
            $is_transfer = true; // No adviser = transfer student
        }
    }
    
    // First check if there's a custom subject name for this transfer student
    $table_check = $conn->query("SHOW TABLES LIKE 'student_custom_subjects'");
    if ($table_check && $table_check->num_rows > 0) {
        $custom_query = $conn->query("SELECT custom_subject_name 
                                      FROM student_custom_subjects 
                                      WHERE student_id = $student_id 
                                      AND school_attended_id = $school_attended_id 
                                      AND subject_id = $subject_id");
        if ($custom_query && $custom_query->num_rows > 0) {
            $custom_result = $custom_query->fetch_assoc();
            return $custom_result['custom_subject_name'];
        }
    }
    
    // IMPORTANT: Only use grade-level config for regular students (non-transfer)
    // Transfer students should NOT be affected by global subject format changes
    if (!$is_transfer && $grade_level) {
        $table_check = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'");
        
        if ($table_check && $table_check->num_rows > 0) {
            $group_query = $conn->query("SELECT subject_name 
                                         FROM subject_grade_groups 
                                         WHERE grade_level = $grade_level 
                                         AND subject_id = $subject_id");
            if ($group_query && $group_query->num_rows > 0) {
                $group_result = $group_query->fetch_assoc();
                return $group_result['subject_name'];
            }
        }
    }
    
    // Fall back to default subject name
    $default_query = $conn->query("SELECT subject_name FROM subjects WHERE id = $subject_id");
    if ($default_query && $default_query->num_rows > 0) {
        $default_result = $default_query->fetch_assoc();
        return $default_result['subject_name'];
    }
    
    return 'Unknown Subject';
}

// Function to get adviser full name from system if available
function getAdviserFullName($conn, $school_row) {
    if (!$school_row) return '';
    $adviser_name = $school_row['adviser_name'] ?? '';
    
    // 1. Try matching existing adviser_name to a user in the system to get their latest full name
    if (!empty($adviser_name)) {
        $stmt = $conn->prepare("SELECT full_name FROM users WHERE LOWER(full_name) = LOWER(?) LIMIT 1");
        $stmt->bind_param("s", $adviser_name);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            return $row['full_name'];
        }
        $stmt->close();
    }
    
    // 2. If no name or no match, look up by assignment (for internal records with missing adviser_name)
    $grade_label = $school_row['grade_level'] ?? '';
    $section = $school_row['section'] ?? '';
    $school_year_str = $school_row['school_year'] ?? '';
    
    if ($grade_label && $section && $school_year_str) {
        // Extract numeric grade level
        preg_match('/(\d+)/', $grade_label, $m);
        $gl_num = isset($m[1]) ? intval($m[1]) : null;
        
        if ($gl_num) {
            $stmt = $conn->prepare("SELECT u.full_name 
                                   FROM teacher_assignments ta
                                   JOIN users u ON ta.teacher_id = u.id
                                   JOIN school_years sy ON ta.school_year_id = sy.id
                                   WHERE ta.grade_level = ? 
                                   AND ta.section = ? 
                                   AND ta.assignment_type = 'adviser'
                                   AND sy.year = ?
                                   LIMIT 1");
            $stmt->bind_param("iss", $gl_num, $section, $school_year_str);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return $row['full_name'];
            }
            $stmt->close();
        }
    }
    
    return $adviser_name;
}

// Function to get school details with fallback to adviser's info
function getSchoolInfo($conn, $school_row) {
    $info = [
        'school_name' => $school_row['school_name'] ?? '',
        'school_id' => $school_row['school_id'] ?? '',
        'district' => $school_row['district'] ?? '',
        'division' => $school_row['division'] ?? '',
        'region' => $school_row['region'] ?? ''
    ];

    // If any critical field is missing, try to fetch from the assigned adviser in the system
    if (empty($info['school_name']) || empty($info['school_id']) || empty($info['district'])) {
        $adviser_name = $school_row['adviser_name'] ?? '';
        $user_match = null;

        // 1. Try matching by name first
        if (!empty($adviser_name)) {
            $stmt = $conn->prepare("SELECT school_name, school_id, district, division, region FROM users WHERE LOWER(full_name) = LOWER(?) LIMIT 1");
            $stmt->bind_param("s", $adviser_name);
            $stmt->execute();
            $user_match = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        // 2. If no match by name, try matching by assignment (for internal records)
        if (!$user_match) {
            $grade_label = $school_row['grade_level'] ?? '';
            $section = $school_row['section'] ?? '';
            $school_year_str = $school_row['school_year'] ?? '';
            
            if ($grade_label && $section && $school_year_str) {
                preg_match('/(\d+)/', $grade_label, $m);
                $gl_num = isset($m[1]) ? intval($m[1]) : null;

                if ($gl_num) {
                    $stmt = $conn->prepare("SELECT u.school_name, u.school_id, u.district, u.division, u.region 
                                           FROM teacher_assignments ta
                                           JOIN users u ON ta.teacher_id = u.id
                                           JOIN school_years sy ON ta.school_year_id = sy.id
                                           WHERE ta.grade_level = ? 
                                           AND ta.section = ? 
                                           AND ta.assignment_type = 'adviser'
                                           AND sy.year = ?
                                           LIMIT 1");
                    $stmt->bind_param("iss", $gl_num, $section, $school_year_str);
                    $stmt->execute();
                    $user_match = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }
            }
        }

        // 3. Last fallback: try any admin user's school info as a global default
        if (!$user_match) {
            $admin_query = $conn->query("SELECT school_name, school_id, district, division, region FROM users WHERE role = 'admin' AND school_name IS NOT NULL AND school_name != '' LIMIT 1");
            if ($admin_query && $admin_query->num_rows > 0) {
                $user_match = $admin_query->fetch_assoc();
            }
        }

        if ($user_match) {
            if (empty($info['school_name'])) $info['school_name'] = $user_match['school_name'] ?? '';
            if (empty($info['school_id'])) $info['school_id'] = $user_match['school_id'] ?? '';
            if (empty($info['district'])) $info['district'] = $user_match['district'] ?? '';
            if (empty($info['division'])) $info['division'] = $user_match['division'] ?? '';
            if (empty($info['region'])) $info['region'] = $user_match['region'] ?? '';
        }
    }

    return $info;
}

if (!isset($_GET['student_id'])) {
    header("Location: sf10_form.php?error=" . urlencode("No student selected"));
    exit();
}

$student_id = (int)$_GET['student_id'];

// Get student information
$student_query = "SELECT * FROM students WHERE id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    header("Location: sf10_form.php?error=" . urlencode("Student not found"));
    exit();
}

// Get all school records for this student
$schools_query = "SELECT * FROM schools_attended 
                  WHERE student_id = ?
                  ORDER BY grade_level ASC, school_year ASC";
$stmt = $conn->prepare($schools_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$schools_result = $stmt->get_result();

$school_records = [];
while ($school = $schools_result->fetch_assoc()) {
    $school_records[] = $school;
}

// Get all grades for this student
$all_grades = [];
$all_remedial = [];
foreach ($school_records as $school) {
    // Get the most recent grade for each subject/quarter combination using MAX(id)
    $grades_query = "SELECT g.*, s.subject_name, s.id as subject_id
                     FROM grades g
                     INNER JOIN (
                         SELECT subject_id, quarter, MAX(id) as max_id
                         FROM grades
                         WHERE student_id = ? 
                         AND school_attended_id = ?
                         GROUP BY subject_id, quarter
                     ) AS latest ON g.id = latest.max_id
                     JOIN subjects s ON g.subject_id = s.id
                     WHERE s.subject_name != 'General Average'
                     ORDER BY s.id, g.quarter";
    $stmt = $conn->prepare($grades_query);
    $stmt->bind_param("ii", $student_id, $school['id']);
    $stmt->execute();
    $grades_result = $stmt->get_result();
    
    $key = $school['grade_level'] . '_' . $school['school_year'];
    $all_grades[$key] = [
        'school' => $school,
        'grades' => [],
        'general_average' => ['q1' => '', 'q2' => '', 'q3' => '', 'q4' => '', 'final_rating' => '', 'remarks' => '']
    ];
    
    while ($grade = $grades_result->fetch_assoc()) {
        $sid = $grade['subject_id'];
        $q = $grade['quarter'];
        
        if (!isset($all_grades[$key]['grades'][$sid])) {
            $all_grades[$key]['grades'][$sid] = [
                'subject_name' => $grade['subject_name'],
                'q1' => '', 'q2' => '', 'q3' => '', 'q4' => '',
                'final_rating' => '', 'remarks' => ''
            ];
        }
        
        // Store the grade for this quarter
        $all_grades[$key]['grades'][$sid]['q' . $q] = $grade['grade'];
        
        // Always update final_rating and remarks from the last row processed
        $all_grades[$key]['grades'][$sid]['final_rating'] = $grade['final_rating'] ?? '';
        $all_grades[$key]['grades'][$sid]['remarks'] = $grade['remarks'] ?? '';
    }
    
    // Get General Average
    $gen_avg_query = "SELECT g.*
                      FROM grades g
                      INNER JOIN (
                          SELECT quarter, MAX(id) as max_id
                          FROM grades
                          WHERE student_id = ? 
                          AND school_attended_id = ?
                          AND subject_id = (SELECT id FROM subjects WHERE subject_name = 'General Average' LIMIT 1)
                          GROUP BY quarter
                      ) AS latest ON g.id = latest.max_id
                      ORDER BY g.quarter";
    $stmt = $conn->prepare($gen_avg_query);
    $stmt->bind_param("ii", $student_id, $school['id']);
    $stmt->execute();
    $gen_avg_result = $stmt->get_result();
    
    while ($avg = $gen_avg_result->fetch_assoc()) {
        $q = $avg['quarter'];
        $all_grades[$key]['general_average']['q' . $q] = $avg['grade'];
        $all_grades[$key]['general_average']['final_rating'] = $avg['final_rating'] ?? '';
        $all_grades[$key]['general_average']['remarks'] = $avg['remarks'] ?? '';
    }
}

require_once '../templates/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start mb-3">
    <div>
        <h2><i class="bi bi-eye"></i> SF10 Preview</h2>
        <p class="subtitle">Review Student Academic Record Before Download</p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.open('../SF10_official_final.php?student_id=<?= $student_id ?>', '_blank')" class="btn btn-info" style="padding: .45rem .75rem;">
            <i class="bi bi-file-earmark-text"></i> View Front Sheet
        </button>
        <button onclick="window.open('../SF10_official_final_back.php?student_id=<?= $student_id ?>', '_blank')" class="btn btn-info" style="padding: .45rem .75rem;">
            <i class="bi bi-file-earmark-text"></i> View Back Sheet
        </button>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-person-badge"></i> Student Information
        </span>
        <a href="edit_student.php?id=<?= $student_id ?>" class="btn btn-sm btn-warning">
            <i class="bi bi-pencil"></i> Edit Student Info
        </a>
    </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong>Last Name:</strong><br>
                    <?= htmlspecialchars($student['last_name']) ?>
                </div>
                <div class="col-md-3">
                    <strong>First Name:</strong><br>
                    <?= htmlspecialchars($student['first_name']) ?>
                </div>
                <div class="col-md-3">
                    <strong>Middle Name:</strong><br>
                    <?= htmlspecialchars($student['middle_name'] ?? 'N/A') ?>
                </div>
                <div class="col-md-3">
                    <strong>LRN:</strong><br>
                    <?= htmlspecialchars($student['lrn']) ?>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-3">
                    <strong>Birthdate:</strong><br>
                    <?= date('F d, Y', strtotime($student['birthdate'])) ?>
                </div>
                <div class="col-md-3">
                    <strong>Sex:</strong><br>
                    <?= htmlspecialchars($student['gender']) ?>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($all_grades as $key => $data): 
        $school = $data['school'];
        $grades = $data['grades'];
    ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-book"></i> Grade <?= $school['grade_level'] ?> - School Year <?= htmlspecialchars($school['school_year']) ?>
            </span>
            <div class="d-flex gap-2">
                <a href="grade_progression.php?student_id=<?= $student_id ?>&expand_grade=<?= $school['grade_level'] ?>" class="btn btn-sm btn-warning">
                    <i class="bi bi-pencil"></i> Edit Information
                </a>
                <a href="enter_grades.php?student_id=<?= $student_id ?>&school_attended_id=<?= $school['id'] ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-pencil-square"></i> Edit Grades
                </a>
            </div>
        </div>
        <div class="card-body">
            <?php $school_info = getSchoolInfo($conn, $school); ?>
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>School:</strong> <?= htmlspecialchars($school_info['school_name'] ?: 'N/A') ?><br>
                    <strong>School ID:</strong> <?= htmlspecialchars($school_info['school_id'] ?: 'N/A') ?><br>
                    <strong>Section:</strong> <?= htmlspecialchars($school['section'] ?? 'N/A') ?>
                </div>
                <div class="col-md-6">
                    <strong>District:</strong> <?= htmlspecialchars($school_info['district'] ?: 'N/A') ?><br>
                    <strong>Division:</strong> <?= htmlspecialchars($school_info['division'] ?: 'N/A') ?><br>
                    <strong>Adviser:</strong> <?= htmlspecialchars(getAdviserFullName($conn, $school) ?: 'N/A') ?>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Q1</th>
                            <th>Q2</th>
                            <th>Q3</th>
                            <th>Q4</th>
                            <th>Final Rating</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Get all subjects from database
                        $subjects_query = $conn->query("SELECT id, subject_name FROM subjects WHERE subject_name != 'General Average' ORDER BY id");
                        
                        while ($subject = $subjects_query->fetch_assoc()):
                            $sid = $subject['id'];
                            
                            // Get the proper subject name for this student/school
                            $display_name = getSubjectNameForStudent($conn, $sid, $student_id, $school['id']);
                            
                            // Get grades for this subject (may not exist)
                            $g = $grades[$sid] ?? null;
                        ?>
                        <?php
                            $all_quarters_filled = $g &&
                                isset($g['q1'], $g['q2'], $g['q3'], $g['q4']) &&
                                $g['q1'] !== '' && $g['q1'] !== null &&
                                $g['q2'] !== '' && $g['q2'] !== null &&
                                $g['q3'] !== '' && $g['q3'] !== null &&
                                $g['q4'] !== '' && $g['q4'] !== null;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($display_name) ?></td>
                            <td><?= $g && isset($g['q1']) && $g['q1'] !== '' && $g['q1'] !== null ? round($g['q1']) : '-' ?></td>
                            <td><?= $g && isset($g['q2']) && $g['q2'] !== '' && $g['q2'] !== null ? round($g['q2']) : '-' ?></td>
                            <td><?= $g && isset($g['q3']) && $g['q3'] !== '' && $g['q3'] !== null ? round($g['q3']) : '-' ?></td>
                            <td><?= $g && isset($g['q4']) && $g['q4'] !== '' && $g['q4'] !== null ? round($g['q4']) : '-' ?></td>
                            <td><strong><?= $all_quarters_filled && isset($g['final_rating']) && $g['final_rating'] !== '' && $g['final_rating'] !== null ? round($g['final_rating']) : '-' ?></strong></td>
                            <td><?= $all_quarters_filled && isset($g['remarks']) && $g['remarks'] !== '' && $g['remarks'] !== null ? htmlspecialchars($g['remarks']) : '-' ?></td>
                        </tr>
                        <?php endwhile; ?>
                        <!-- General Average Row -->
                        <tr class="table-warning">
                            <td><strong>General Average</strong></td>
                            <?php
                            // Compute General Average from available subject finals while treating MAPEH as a single subject
                            $gen_avg = $all_grades[$key]['general_average'] ?? null;

                            // Build map of subject_id -> display name for grades present
                            $subject_names_map = [];
                            foreach ($grades as $sid => $g) {
                                $subject_names_map[intval($sid)] = strtolower(trim(getSubjectNameForStudent($conn, intval($sid), $student_id, $school['id'])));
                            }

                            $mapeh_agg_id = null;
                            $mapeh_components = [];
                            $component_keys = ['music','arts','art','physical education','physical','pe','health'];
                            foreach ($subject_names_map as $sid => $sname) {
                                if ($sname === 'mapeh') {
                                    $mapeh_agg_id = $sid;
                                } else {
                                    foreach ($component_keys as $ck) {
                                        if (stripos($sname, $ck) !== false) {
                                            $mapeh_components[] = $sid;
                                            break;
                                        }
                                    }
                                }
                            }

                            // Compute GA finals to include
                            $ga_values = [];

                            // Helper: check all 4 quarters filled for a grade entry
                            $allQFilled = function($g) {
                                return $g && isset($g['q1'],$g['q2'],$g['q3'],$g['q4'])
                                    && $g['q1'] !== '' && $g['q1'] !== null
                                    && $g['q2'] !== '' && $g['q2'] !== null
                                    && $g['q3'] !== '' && $g['q3'] !== null
                                    && $g['q4'] !== '' && $g['q4'] !== null;
                            };

                            // Prefer aggregate MAPEH final if present and all 4 quarters filled
                            if ($mapeh_agg_id !== null && isset($grades[$mapeh_agg_id]) && $allQFilled($grades[$mapeh_agg_id]) && (!empty($grades[$mapeh_agg_id]['final_rating']) || $grades[$mapeh_agg_id]['final_rating'] === '0')) {
                                $ga_values[] = round(floatval($grades[$mapeh_agg_id]['final_rating']));
                            } elseif (!empty($mapeh_components)) {
                                // Only include MAPEH in GA when ALL 4 components each have all 4 quarters filled
                                $mfinals = [];
                                foreach ($mapeh_components as $cid) {
                                    if (isset($grades[$cid]) && $allQFilled($grades[$cid]) && (!empty($grades[$cid]['final_rating']) || $grades[$cid]['final_rating'] === '0')) {
                                        $mfinals[] = round(floatval($grades[$cid]['final_rating']));
                                    }
                                }
                                // Only add MAPEH to GA if all 4 components contributed
                                if (count($mfinals) === count($mapeh_components) && count($mfinals) > 0) {
                                    $ga_values[] = round(array_sum($mfinals) / count($mfinals));
                                }
                            }

                            // Include other subjects' finals (exclude components and General Average)
                            foreach ($grades as $sid => $g) {
                                $sid = intval($sid);
                                // skip General Average subject if somehow included
                                if (isset($g['subject_name']) && strtolower(trim($g['subject_name'])) === 'general average') continue;
                                if ($mapeh_agg_id !== null && $sid === $mapeh_agg_id) continue; // already included
                                if (!empty($mapeh_components) && in_array($sid, $mapeh_components)) continue; // handled via combined value
                                // Only include subject if all 4 quarters are filled
                                if ($allQFilled($g) && (!empty($g['final_rating']) || (isset($g['final_rating']) && $g['final_rating'] === '0'))) {
                                    $ga_values[] = round(floatval($g['final_rating']));
                                }
                            }

                            $computed_ga = null;
                            if (!empty($ga_values)) {
                                $computed_ga = round(array_sum($ga_values) / count($ga_values));
                            }

                            // Decide what to print: prefer computed GA (ensures components are handled correctly), otherwise fallback to stored gen_avg
                            $printed = '-';
                            if ($computed_ga !== null) {
                                $printed = $computed_ga . '%';
                            } elseif ($gen_avg) {
                                if (isset($gen_avg['final_rating']) && $gen_avg['final_rating'] !== '' && $gen_avg['final_rating'] !== null) {
                                    $printed = round($gen_avg['final_rating']) . '%';
                                } else {
                                    $vals = [];
                                    for ($i=1;$i<=4;$i++) {
                                        $k = 'q'.$i;
                                        if (isset($gen_avg[$k]) && $gen_avg[$k] !== '' && $gen_avg[$k] !== null) {
                                            $vals[] = $gen_avg[$k];
                                        }
                                    }
                                    if (!empty($vals)) {
                                        $avg = array_sum($vals) / count($vals);
                                        $printed = round($avg) . '%';
                                    }
                                }
                            }
                            ?>
                            <td><strong>-</strong></td>
                            <td><strong>-</strong></td>
                            <td><strong>-</strong></td>
                            <td><strong>-</strong></td>
                            <td><strong><?php echo $printed; ?></strong></td>
                            <td><?= $gen_avg && isset($gen_avg['remarks']) && $gen_avg['remarks'] !== '' && $gen_avg['remarks'] !== null ? htmlspecialchars($gen_avg['remarks']) : '-' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($all_grades)): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> No grade records found for this student.
    </div>
    <?php endif; ?>

<div class="mt-2">
    <a href="sf10_form.php" class="btn btn-info w-100">
        <i class="bi bi-arrow-left"></i> Back to Selection
    </a>
</div>

<?php require_once '../templates/footer.php'; ?>
