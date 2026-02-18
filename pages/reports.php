<?php
require_once '../includes/db.php';
include '../templates/header.php';

$school_year_id = $_SESSION['school_year_id'] ?? null;
$school_year    = $_SESSION['school_year']    ?? null;
$teacher_id     = $is_admin ? null : ($user['id'] ?? null);

// ── Teacher: resolve assigned class(es) from teacher_assignments ─────────────
$teacher_classes = []; // [ ['grade_level'=>X,'section'=>'Y'], ... ]
if (!$is_admin && $teacher_id && $school_year) {
    $tc_stmt = $conn->prepare("
        SELECT DISTINCT grade_level, section
        FROM teacher_assignments
        WHERE teacher_id = ? AND school_year = ?
    ");
    $tc_stmt->bind_param('is', $teacher_id, $school_year);
    $tc_stmt->execute();
    $tc_res = $tc_stmt->get_result();
    while ($tc = $tc_res->fetch_assoc()) {
        $teacher_classes[] = $tc;
    }
}

// Filter inputs (admin only; teacher's filter is locked to their class)
$filter_grade   = $is_admin ? (isset($_GET['grade_level']) ? intval($_GET['grade_level']) : 0) : 0;
$filter_section = $is_admin ? (isset($_GET['section'])     ? trim($_GET['section'])        : '') : '';

// ── Build class scope filter (used by both annual + quarterly queries) ────────
$class_filter = '';
if ($school_year) {
    if ($is_admin) {
        $grade_filter   = $filter_grade   ? "AND sa.grade_level = " . intval($filter_grade) : '';
        $section_filter = $filter_section ? "AND LOWER(sa.section) = LOWER('" . $conn->real_escape_string($filter_section) . "')" : '';
        $class_filter   = "$grade_filter $section_filter";
    } else {
        if (empty($teacher_classes)) {
            $class_filter = "AND 1=0";
        } else {
            $pairs = array_map(function($c) use ($conn) {
                return "(sa.grade_level = " . intval($c['grade_level']) .
                       " AND LOWER(sa.section) = LOWER('" . $conn->real_escape_string($c['section']) . "'))";
            }, $teacher_classes);
            $class_filter = "AND (" . implode(" OR ", $pairs) . ")";
        }
    }
}

// ── Annual Honor Roll Query ───────────────────────────────────────────────────
// Final grade per subject = AVG of Q1-Q4 quarterly grades
// General average = AVG of all subject finals
// Eligible: GA >= 90 AND no subject final grade < 85
$honor_students = [];

if ($school_year) {

    $sql = "
        SELECT
            s.id as student_id,
            CONCAT(s.last_name, ', ', s.first_name,
                IF(s.middle_name IS NOT NULL AND s.middle_name != '',
                   CONCAT(' ', s.middle_name), '')) as full_name,
            sa.grade_level,
            sa.section,
            subj.subject_name,
            subj.id as subject_id,
            ROUND(AVG(g.grade), 0) as subject_final
        FROM grades g
        JOIN students s  ON g.student_id       = s.id
        JOIN schools_attended sa ON g.school_attended_id = sa.id
        JOIN subjects subj       ON g.subject_id         = subj.id
        WHERE g.school_year = ?
          AND g.grade IS NOT NULL
          AND sa.active = 1
          $class_filter
        GROUP BY s.id, subj.id, sa.grade_level, sa.section
        ORDER BY sa.grade_level, sa.section, s.last_name, s.first_name
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $school_year);
    $stmt->execute();
    $res = $stmt->get_result();

    // Organise by student
    $by_student = [];
    while ($row = $res->fetch_assoc()) {
        $sid = $row['student_id'];
        if (!isset($by_student[$sid])) {
            $by_student[$sid] = [
                'name'        => $row['full_name'],
                'grade_level' => $row['grade_level'],
                'section'     => $row['section'],
                'subjects'    => [],
            ];
        }
        $by_student[$sid]['subjects'][] = [
            'name'  => $row['subject_name'],
            'final' => floatval($row['subject_final']),
        ];
    }

    // Compute GA, apply DepEd eligibility rules
    foreach ($by_student as $sid => $data) {
        if (empty($data['subjects'])) continue;
        $finals    = array_column($data['subjects'], 'final');
        $ga        = round(array_sum($finals) / count($finals), 2);
        $min_grade = min($finals);

        if ($ga >= 90 && $min_grade >= 85) {
            if ($ga >= 98)     $award = 'With Highest Honors';
            elseif ($ga >= 95) $award = 'With High Honors';
            else               $award = 'With Honors';

            $honor_students[] = [
                'name'        => $data['name'],
                'grade_level' => $data['grade_level'],
                'section'     => $data['section'],
                'ga'          => $ga,
                'min_grade'   => $min_grade,
                'award'       => $award,
                'subjects'    => $data['subjects'],
            ];
        }
    }

    // Sort: grade level → section → GA desc
    usort($honor_students, function($a, $b) {
        if ($a['grade_level'] != $b['grade_level']) return $a['grade_level'] - $b['grade_level'];
        if ($a['section'] != $b['section']) return strcmp($a['section'], $b['section']);
        return $b['ga'] <=> $a['ga'];
    });
}

// ── Quarterly Honor Roll Query ──────────────────────────────────────────────
// For each quarter: student GA = AVG(grade) across subjects for that quarter
// Eligible: GA >= 90 AND no subject grade < 85
$quarterly_honors = [1 => [], 2 => [], 3 => [], 4 => []]; // keyed by quarter number

if ($school_year) {
    for ($q = 1; $q <= 4; $q++) {
        $q_sql = "
            SELECT
                s.id as student_id,
                CONCAT(s.last_name, ', ', s.first_name,
                    IF(s.middle_name IS NOT NULL AND s.middle_name != '',
                       CONCAT(' ', s.middle_name), '')) as full_name,
                sa.grade_level,
                sa.section,
                subj.subject_name,
                g.grade as q_grade
            FROM grades g
            JOIN students s  ON g.student_id       = s.id
            JOIN schools_attended sa ON g.school_attended_id = sa.id
            JOIN subjects subj       ON g.subject_id         = subj.id
            WHERE g.school_year = ?
              AND g.quarter = ?
              AND g.grade IS NOT NULL
              AND sa.active = 1
              $class_filter
            ORDER BY sa.grade_level, sa.section, s.last_name, s.first_name
        ";
        $q_stmt = $conn->prepare($q_sql);
        $q_stmt->bind_param('si', $school_year, $q);
        $q_stmt->execute();
        $q_res = $q_stmt->get_result();

        $q_by_student = [];
        while ($row = $q_res->fetch_assoc()) {
            $sid = $row['student_id'];
            if (!isset($q_by_student[$sid])) {
                $q_by_student[$sid] = [
                    'name'        => $row['full_name'],
                    'grade_level' => $row['grade_level'],
                    'section'     => $row['section'],
                    'grades'      => [],
                ];
            }
            $q_by_student[$sid]['grades'][] = [
                'subject' => $row['subject_name'],
                'grade'   => floatval($row['q_grade']),
            ];
        }

        foreach ($q_by_student as $sid => $data) {
            if (empty($data['grades'])) continue;
            $vals    = array_column($data['grades'], 'grade');
            $ga      = round(array_sum($vals) / count($vals), 2);
            $min_g   = min($vals);
            if ($ga >= 90 && $min_g >= 85) {
                if ($ga >= 98)     $award = 'With Highest Honors';
                elseif ($ga >= 95) $award = 'With High Honors';
                else               $award = 'With Honors';
                $quarterly_honors[$q][] = [
                    'name'        => $data['name'],
                    'grade_level' => $data['grade_level'],
                    'section'     => $data['section'],
                    'ga'          => $ga,
                    'min_grade'   => $min_g,
                    'award'       => $award,
                ];
            }
        }

        usort($quarterly_honors[$q], function($a, $b) {
            if ($a['grade_level'] != $b['grade_level']) return $a['grade_level'] - $b['grade_level'];
            if ($a['section']     != $b['section'])     return strcmp($a['section'], $b['section']);
            return $b['ga'] <=> $a['ga'];
        });
    }
}

// Active tab: final | q1 | q2 | q3 | q4
$active_tab = isset($_GET['tab']) && in_array($_GET['tab'], ['final','q1','q2','q3','q4']) ? $_GET['tab'] : 'final';

// Filter dropdowns — admin sees all active classes; teacher sees only their own
if ($is_admin) {
    $gl_res  = $conn->query("SELECT DISTINCT sa.grade_level FROM schools_attended sa WHERE sa.active=1 ORDER BY sa.grade_level");
    // Build grade → sections map for dynamic section dropdown
    $gl_sections_map = []; // [ grade_level => ['sectionA', 'sectionB', ...] ]
    $gs_res = $conn->query("SELECT DISTINCT sa.grade_level, sa.section FROM schools_attended sa WHERE sa.active=1 ORDER BY sa.grade_level, sa.section");
    while ($gs = $gs_res->fetch_assoc()) {
        $gl_sections_map[(int)$gs['grade_level']][] = $gs['section'];
    }
} else {
    $gl_res          = false;
    $gl_sections_map = [];
}

// Stats (admin only)
$total_students = $is_admin ? $conn->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'] : 0;
$total_subjects = $is_admin ? $conn->query("SELECT COUNT(*) as c FROM subjects")->fetch_assoc()['c'] : 0;
$total_grades   = $is_admin ? $conn->query("SELECT COUNT(*) as c FROM grades")->fetch_assoc()['c']   : 0;

// Helper: group honor array by grade+section
function groupHonorStudents(array $students): array {
    $grouped = [];
    foreach ($students as $h) {
        $key = 'Grade ' . $h['grade_level'] . ' — ' . ucfirst($h['section']);
        $grouped[$key][] = $h;
    }
    return $grouped;
}

// Helper: render a full honor table from a grouped array
function renderHonorTable(array $grouped, bool $is_admin, string $empty_msg): void { ?>
    <?php if (empty($grouped)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-award" style="font-size:48px;opacity:.25"></i>
            <p class="mt-3 mb-0"><?= htmlspecialchars($empty_msg) ?></p>
            <small>Students need GA &ge; 90 with no subject grade below 85.</small>
        </div>
    <?php else: ?>
        <?php
        $overall_rank = 0;
        foreach ($grouped as $group_label => $group_students): ?>
        <div class="section-divider">
            <i class="bi bi-people-fill text-primary"></i>
            <?= htmlspecialchars($group_label) ?>
            <span class="badge bg-light text-dark border" style="font-weight:500"><?= count($group_students) ?></span>
        </div>
        <div class="table-responsive mb-2">
            <table class="table table-hover align-middle mb-0" style="font-size:14px">
                <thead style="background:var(--bg-light,#f8f9fa);font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted,#6c757d)">
                    <tr>
                        <th style="width:44px">#</th>
                        <th>Student Name</th>
                        <?php if ($is_admin): ?>
                        <th class="text-center" style="width:80px">Grade</th>
                        <th class="text-center" style="width:100px">Section</th>
                        <?php endif; ?>
                        <th class="text-center" style="width:130px">General Average</th>
                        <th class="text-center" style="width:130px">Lowest Grade</th>
                        <th style="width:180px">Award</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($group_students as $h):
                        $overall_rank++;
                        if ($h['award'] === 'With Highest Honors')
                            $pill = '<span class="award-pill highest">&#11088; With Highest Honors</span>';
                        elseif ($h['award'] === 'With High Honors')
                            $pill = '<span class="award-pill high">&#129352; With High Honors</span>';
                        else
                            $pill = '<span class="award-pill honors">&#129353; With Honors</span>';
                    ?>
                    <tr>
                        <td><span class="honor-rank"><?= $overall_rank ?></span></td>
                        <td><span class="fw-semibold"><?= htmlspecialchars($h['name']) ?></span></td>
                        <?php if ($is_admin): ?>
                        <td class="text-center text-muted"><?= $h['grade_level'] ?></td>
                        <td class="text-center text-muted"><?= htmlspecialchars(ucfirst($h['section'])) ?></td>
                        <?php endif; ?>
                        <td class="text-center">
                            <span class="ga-badge <?= $h['ga'] >= 98 ? 'text-warning' : ($h['ga'] >= 95 ? 'text-primary' : 'text-success') ?>">
                                <?= number_format($h['ga'], 2) ?>
                            </span>
                        </td>
                        <td class="text-center text-muted"><?= number_format($h['min_grade'], 0) ?></td>
                        <td><?= $pill ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    <?php endif;
}
?>

<style>
/* ── Reports page ─────────────────────────────────────────────── */
.honor-stat {
    display: flex;
    align-items: center;
    gap: 14px;
    background: var(--bg-light, #f8f9fa);
    border: 1px solid var(--border-color, #e9ecef);
    border-radius: 10px;
    padding: 14px 20px;
}
.honor-stat .hs-icon {
    font-size: 28px;
    line-height: 1;
}
.honor-stat .hs-val {
    font-size: 26px;
    font-weight: 700;
    line-height: 1;
    color: var(--text-dark, #1a1d23);
}
.honor-stat .hs-lbl {
    font-size: 12px;
    color: var(--text-muted, #6c757d);
    margin-top: 2px;
}

.section-divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 22px 0 10px;
    font-weight: 600;
    font-size: 13px;
    color: var(--text-muted, #6c757d);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.section-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border-color, #e9ecef);
}

.honor-rank {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--bg-light, #f0f2f5);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted, #6c757d);
    flex-shrink: 0;
}

.award-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}
.award-pill.highest { background: #fff8e1; color: #b8860b; border: 1px solid #f0c040; }
.award-pill.high    { background: #e8f0fe; color: #1a56db; border: 1px solid #a4c2f4; }
.award-pill.honors  { background: #e6f4ea; color: #1e7e34; border: 1px solid #a8d5b5; }

.ga-badge {
    font-size: 16px;
    font-weight: 700;
}

.filter-bar {
    background: var(--bg-light, #f8f9fa);
    border: 1px solid var(--border-color, #e9ecef);
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 20px;
}

.legend-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid;
}

/* Dark theme support */
body.dark-theme .honor-stat {
    background: #2a2d35;
    border-color: #3a3d45;
}
body.dark-theme .honor-stat .hs-val { color: #e9ecef; }
body.dark-theme .section-divider::after { background: #3a3d45; }
body.dark-theme .honor-rank { background: #3a3d45; color: #adb5bd; }
body.dark-theme .filter-bar { background: #2a2d35; border-color: #3a3d45; }
body.dark-theme .award-pill.highest { background: #3d3200; color: #ffc107; border-color: #6b5500; }
body.dark-theme .award-pill.high    { background: #0d2240; color: #74a9f5; border-color: #1a4a8a; }
body.dark-theme .award-pill.honors  { background: #0d2d15; color: #5cb85c; border-color: #1a5c2a; }
body.dark-theme .nav-tabs .nav-link { color: #adb5bd; border-color: transparent; }
body.dark-theme .nav-tabs .nav-link:hover { color: #e9ecef; border-color: #3a3d45 #3a3d45 transparent; }
body.dark-theme .nav-tabs .nav-link.active { color: #e9ecef; background: #2a2d35; border-color: #3a3d45 #3a3d45 #2a2d35; }
body.dark-theme .nav-tabs { border-bottom-color: #3a3d45; }

@media print {
    .topbar, .sidebar, .page-header, .filter-bar, .no-print,
    .btn, form { display: none !important; }
    .main-wrapper { margin-left: 0 !important; padding: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    .honor-stat { border: 1px solid #ddd !important; background: #f9f9f9 !important; }
    #honorTable { font-size: 12px; }
    .award-pill { border: 1px solid #999 !important; }
}
</style>

<?php
// ── Pre-compute summary counts ────────────────────────────────────────────────
$cnt_highest = count(array_filter($honor_students, fn($h) => $h['award'] === 'With Highest Honors'));
$cnt_high    = count(array_filter($honor_students, fn($h) => $h['award'] === 'With High Honors'));
$cnt_honors  = count(array_filter($honor_students, fn($h) => $h['award'] === 'With Honors'));
$cnt_total   = count($honor_students);

// Group by grade level + section for the table
$grouped = groupHonorStudents($honor_students);

// Pre-group quarterly
$q_grouped = [];
for ($q = 1; $q <= 4; $q++) {
    $q_grouped[$q] = groupHonorStudents($quarterly_honors[$q]);
}
?>

<!-- Page Header -->
<div class="page-header d-flex align-items-start justify-content-between flex-wrap gap-2">
    <div>
        <h2><i class="bi bi-award-fill text-warning me-2"></i>Honor Roll Report</h2>
        <p class="subtitle">
            DepEd Order No. 36, s. 2016 &nbsp;·&nbsp;
            SY <?= $school_year ? htmlspecialchars($school_year) : '—' ?>
            <?php if (!$is_admin && !empty($teacher_classes)): ?>
                &nbsp;·&nbsp;
                <?php foreach ($teacher_classes as $tc): ?>
                    <span class="badge bg-info text-dark">Grade <?= $tc['grade_level'] ?> – <?= htmlspecialchars(ucfirst($tc['section'])) ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="no-print d-flex gap-2">
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
</div>

<?php if (!$school_year): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>No active school year. Please select a school year first.</div>
<?php elseif (!$is_admin && empty($teacher_classes)): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle-fill me-2"></i>You have no class assignment for SY <?= htmlspecialchars($school_year) ?>.</div>
<?php else: ?>

<?php
// Stats for the active tab
if ($active_tab === 'final') {
    $stat_list   = $honor_students;
    $stat_label  = 'Final Honor Roll';
} else {
    $q_num_stat  = intval(substr($active_tab, 1));
    $stat_list   = $quarterly_honors[$q_num_stat];
    $stat_label  = 'Quarter ' . $q_num_stat . ' Honor Roll';
}
$stat_total   = count($stat_list);
$stat_highest = count(array_filter($stat_list, fn($h) => $h['award'] === 'With Highest Honors'));
$stat_high    = count(array_filter($stat_list, fn($h) => $h['award'] === 'With High Honors'));
$stat_honors  = count(array_filter($stat_list, fn($h) => $h['award'] === 'With Honors'));
?>
<!-- Summary Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="honor-stat">
            <span class="hs-icon">&#127942;</span>
            <div>
                <div class="hs-val"><?= $stat_total ?></div>
                <div class="hs-lbl">Total &mdash; <?= $stat_label ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="honor-stat">
            <span class="hs-icon">&#11088;</span>
            <div>
                <div class="hs-val" style="color:#b8860b"><?= $stat_highest ?></div>
                <div class="hs-lbl">With Highest Honors</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="honor-stat">
            <span class="hs-icon">&#129352;</span>
            <div>
                <div class="hs-val" style="color:#1a56db"><?= $stat_high ?></div>
                <div class="hs-lbl">With High Honors</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="honor-stat">
            <span class="hs-icon">&#129353;</span>
            <div>
                <div class="hs-val" style="color:#1e7e34"><?= $stat_honors ?></div>
                <div class="hs-lbl">With Honors</div>
            </div>
        </div>
    </div>
</div>

<?php
// Build filter query string (preserves grade_level & section across tab switches)
$filter_qs = '';
if ($is_admin) {
    $qs_parts = [];
    if ($filter_grade)   $qs_parts[] = 'grade_level=' . $filter_grade;
    if ($filter_section) $qs_parts[] = 'section=' . urlencode($filter_section);
    $filter_qs = $qs_parts ? '&' . implode('&', $qs_parts) : '';
}

$tab_labels = [
    'final' => ['label' => 'Final',      'icon' => 'bi-trophy-fill',    'count' => $cnt_total],
    'q1'    => ['label' => 'Quarter 1',  'icon' => 'bi-1-circle-fill',  'count' => count($quarterly_honors[1])],
    'q2'    => ['label' => 'Quarter 2',  'icon' => 'bi-2-circle-fill',  'count' => count($quarterly_honors[2])],
    'q3'    => ['label' => 'Quarter 3',  'icon' => 'bi-3-circle-fill',  'count' => count($quarterly_honors[3])],
    'q4'    => ['label' => 'Quarter 4',  'icon' => 'bi-4-circle-fill',  'count' => count($quarterly_honors[4])],
];
?>

<div class="card">
    <!-- Card header: title + legend -->
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-list-ol me-2"></i>Honorees List</span>
        <div class="d-flex gap-2 flex-wrap no-print">
            <span class="legend-pill" style="background:#fff8e1;color:#b8860b;border-color:#f0c040">&#11088; Highest Honors <span class="text-muted fw-normal">98–100</span></span>
            <span class="legend-pill" style="background:#e8f0fe;color:#1a56db;border-color:#a4c2f4">&#129352; High Honors <span class="text-muted fw-normal">95–97</span></span>
            <span class="legend-pill" style="background:#e6f4ea;color:#1e7e34;border-color:#a8d5b5">&#129353; Honors <span class="text-muted fw-normal">90–94</span></span>
        </div>
    </div>

    <!-- Admin Filter Bar -->
    <?php if ($is_admin): ?>
    <div class="px-4 pt-3 no-print">
        <form method="get" class="filter-bar d-flex align-items-center gap-2 flex-wrap" id="honorFilterForm">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            <i class="bi bi-funnel text-muted"></i>
            <label class="text-muted small me-1 mb-0">Filter:</label>
            <select name="grade_level" id="filterGrade" class="form-select form-select-sm" style="width:auto">
                <option value="">All Grades</option>
                <?php if ($gl_res) while ($gl = $gl_res->fetch_assoc()): ?>
                    <option value="<?= $gl['grade_level'] ?>" <?= $filter_grade == $gl['grade_level'] ? 'selected' : '' ?>>
                        Grade <?= $gl['grade_level'] ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <select name="section" id="filterSection" class="form-select form-select-sm" style="width:auto">
                <option value="">All Sections</option>
                <?php
                // Pre-render all options; JS will hide/show based on grade
                foreach ($gl_sections_map as $gl_val => $sections):
                    foreach ($sections as $sec_name):
                        $sel = ($filter_section === $sec_name) ? 'selected' : '';
                ?>
                    <option value="<?= htmlspecialchars($sec_name) ?>"
                            data-grade="<?= $gl_val ?>"
                            <?= $sel ?>>
                        <?= htmlspecialchars(ucfirst($sec_name)) ?>
                    </option>
                <?php endforeach; endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary px-3">Apply</button>
            <?php if ($filter_grade || $filter_section): ?>
                <a href="reports.php?tab=<?= $active_tab ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <script>
    (function(){
        var gradeSelect   = document.getElementById('filterGrade');
        var sectionSelect = document.getElementById('filterSection');
        var allOptions    = Array.from(sectionSelect.querySelectorAll('option[data-grade]'));

        function filterSections() {
            var selectedGrade = gradeSelect.value;
            var currentSection = sectionSelect.value;
            var firstVisible = null;

            allOptions.forEach(function(opt) {
                var show = !selectedGrade || opt.dataset.grade === selectedGrade;
                opt.hidden   = !show;
                opt.disabled = !show;
                if (show && !firstVisible) firstVisible = opt.value;
            });

            // If the currently selected section is now hidden, reset to All
            var currentOpt = sectionSelect.querySelector('option[value="' + CSS.escape(currentSection) + '"][data-grade]');
            if (currentOpt && currentOpt.hidden) {
                sectionSelect.value = '';
            }
        }

        gradeSelect.addEventListener('change', filterSections);
        // Run on page load so pre-selected grade filters sections immediately
        filterSections();
    })();
    </script>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="px-4 pt-3">
        <ul class="nav nav-tabs" style="border-bottom:2px solid var(--border-color,#dee2e6)">
            <?php foreach ($tab_labels as $tab_key => $tab_info): ?>
            <li class="nav-item">
                <a class="nav-link d-flex align-items-center gap-2 <?= $active_tab === $tab_key ? 'active' : '' ?>"
                   href="reports.php?tab=<?= $tab_key . $filter_qs ?>">
                    <i class="bi <?= $tab_info['icon'] ?>"></i>
                    <?= $tab_info['label'] ?>
                    <span class="badge rounded-pill <?= $active_tab === $tab_key ? 'bg-primary' : 'bg-secondary' ?> ms-1" style="font-size:10px">
                        <?= $tab_info['count'] ?>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="card-body">
        <?php if ($active_tab === 'final'): ?>
            <?php renderHonorTable($grouped, $is_admin, 'No honor students found' . (($filter_grade || $filter_section) ? ' for the selected filter' : ' for this school year') . '.'); ?>
        <?php else:
            $q_num = intval(substr($active_tab, 1)); // q1→1, q2→2 …
            $q_label = 'Quarter ' . $q_num;
            renderHonorTable($q_grouped[$q_num], $is_admin, 'No honor students found for ' . $q_label . (($filter_grade || $filter_section) ? ' with the selected filter' : '') . '.');
        endif; ?>
    </div>
</div><!-- /card -->

<!-- Admin: Quick Links -->
<?php if ($is_admin): ?>
<div class="card no-print">
    <div class="card-header"><i class="bi bi-grid me-2"></i>Other Reports</div>
    <div class="card-body p-0">
        <a href="sf10_form.php" class="d-flex align-items-center gap-3 px-4 py-3 text-decoration-none border-bottom" style="color:inherit;transition:background .15s" onmouseover="this.style.background='var(--bg-light,#f8f9fa)'" onmouseout="this.style.background=''">
            <i class="bi bi-file-earmark-excel text-success fs-5"></i>
            <div>
                <div class="fw-semibold" style="font-size:14px">SF10 Form</div>
                <div class="text-muted" style="font-size:12px">Generate official DepEd SF10 Excel template</div>
            </div>
            <i class="bi bi-chevron-right text-muted ms-auto"></i>
        </a>
        <div class="d-flex gap-4 px-4 py-3 small text-muted">
            <span><i class="bi bi-people me-1"></i>Students: <strong class="text-dark"><?= $total_students ?></strong></span>
            <span><i class="bi bi-book me-1"></i>Subjects: <strong class="text-dark"><?= $total_subjects ?></strong></span>
            <span><i class="bi bi-pencil me-1"></i>Grade Records: <strong class="text-dark"><?= $total_grades ?></strong></span>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; // end school_year + teacher check ?>

<?php include '../templates/footer.php'; ?>
