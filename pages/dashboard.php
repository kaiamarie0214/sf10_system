<?php 
include '../includes/db.php';
include '../templates/header.php';

$user = $_SESSION['user'];
$is_teacher = ($user['role'] === 'teacher');
$is_admin = ($user['role'] === 'admin');

// Get teacher's class info if teacher
$teacher_class = null;
$current_school_year = $_SESSION['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

if ($is_teacher) {
    $school_year_id = $_SESSION['school_year_id'];
    $adviser_query = "SELECT grade_level, section FROM teacher_assignments 
                      WHERE teacher_id = ? AND assignment_type = 'adviser' AND school_year_id = ? LIMIT 1";
    $stmt = $conn->prepare($adviser_query);
    $stmt->bind_param("ii", $user['id'], $school_year_id);
    $stmt->execute();
    $teacher_class = $stmt->get_result()->fetch_assoc();
}

// Get statistics based on role
if ($is_teacher && $teacher_class) {
    // Teacher stats - only their class
    $grade_level = $teacher_class['grade_level'];
    $section = $teacher_class['section'];
    
    $total_students_stmt = $conn->prepare("SELECT COUNT(DISTINCT sa.student_id) as count
                                            FROM schools_attended sa
                                            WHERE sa.grade_level = ? AND LOWER(sa.section) = LOWER(?) AND sa.school_year = ?
                                              AND sa.active = 1");
    $total_students_stmt->bind_param("iss", $grade_level, $section, $current_school_year);
    $total_students_stmt->execute();
    $total_students = $total_students_stmt->get_result()->fetch_assoc()['count'];
    
    // Get subjects assigned to this teacher
    $total_subjects = $conn->prepare("SELECT COUNT(DISTINCT subject_id) as count FROM teacher_assignments 
                                      WHERE teacher_id = ? AND assignment_type = 'subject' AND school_year_id = ?");
    $total_subjects->bind_param("ii", $user['id'], $school_year_id);
    $total_subjects->execute();
    $total_subjects = $total_subjects->get_result()->fetch_assoc()['count'];
    
    // Get total students who have at least one grade entered ("grades entered" = per student)
    $total_records = $conn->prepare("SELECT COUNT(DISTINCT g.student_id) as count FROM grades g
                                     JOIN schools_attended sa ON g.school_attended_id = sa.id
                                     WHERE sa.grade_level = ? AND sa.section = ? AND sa.school_year = ?
                                     AND g.grade IS NOT NULL");
    $total_records->bind_param("iss", $grade_level, $section, $current_school_year);
    $total_records->execute();
    $total_records = $total_records->get_result()->fetch_assoc()['count'];
    
    $total_classes = 1; // Teacher has one class
} elseif ($is_admin) {
    // Admin stats - global
    $total_students = $conn->query("SELECT COUNT(*) as count FROM students")->fetch_assoc()['count'];
    $total_teachers = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'teacher'")->fetch_assoc()['count'];
    $total_classes = $conn->query("SELECT COUNT(DISTINCT CONCAT(grade_level, section)) as count FROM schools_attended WHERE grade_level IS NOT NULL")->fetch_assoc()['count'];
    $total_subjects = $conn->query("SELECT COUNT(*) as count FROM subjects")->fetch_assoc()['count'];
    $total_records = $conn->query("SELECT COUNT(*) as count FROM grades")->fetch_assoc()['count'];
    // SF10 records are stored in schools_attended
    $total_sf10 = $conn->query("SELECT COUNT(*) as count FROM schools_attended")->fetch_assoc()['count'];
} else {
    // Unassigned teacher
    $total_students = 0;
    $total_teachers = 0;
    $total_classes = 0;
    $total_subjects = 0;
    $total_records = 0;
    $total_sf10 = 0;
}

// Get students by grade level
$grade_stats = [];
if ($is_teacher && $teacher_class) {
    // For teachers, show subject performance instead
    $subject_stats_query = "SELECT s.subject_name, COUNT(DISTINCT g.student_id) as student_count,
                           AVG(g.grade) as avg_grade
                           FROM teacher_assignments ta
                           JOIN subjects s ON ta.subject_id = s.id
                           LEFT JOIN grades g ON g.subject_id = s.id 
                           LEFT JOIN schools_attended sa ON g.school_attended_id = sa.id 
                               AND sa.grade_level = ? AND sa.section = ? AND sa.school_year = ?
                           WHERE ta.teacher_id = ? AND ta.assignment_type = 'subject' AND ta.school_year_id = ?
                           GROUP BY s.id, s.subject_name
                           ORDER BY s.subject_name";
    $stmt = $conn->prepare($subject_stats_query);
    $stmt->bind_param("issii", $grade_level, $section, $current_school_year, $user['id'], $school_year_id);
    $stmt->execute();
    $subject_stats = $stmt->get_result();
} elseif ($is_admin) {
    // For admin, show grade level distribution
    for ($i = 1; $i <= 6; $i++) {
        $result = $conn->query("SELECT COUNT(DISTINCT sa.student_id) as count 
                               FROM schools_attended sa 
                               WHERE sa.grade_level = $i 
                               AND sa.created_at = (
                                   SELECT MAX(created_at) 
                                   FROM schools_attended 
                                   WHERE student_id = sa.student_id
                               )");
        $count = $result->fetch_assoc()['count'];
        $percentage = $total_students > 0 ? round(($count / $total_students) * 100, 1) : 0;
        $grade_stats[$i] = ['count' => $count, 'percentage' => $percentage];
    }
}


// Get recent activities based on role
if ($is_teacher && $teacher_class) {
    $grade_level = $teacher_class['grade_level'];
    $section = $teacher_class['section'];
    
    // Recent student in teacher's class
    $stmt = $conn->prepare("SELECT CONCAT(st.first_name, ' ', st.last_name) as name, sa.created_at 
                           FROM schools_attended sa
                           JOIN students st ON sa.student_id = st.id
                           WHERE sa.grade_level = ? AND LOWER(sa.section) = LOWER(?) AND sa.school_year = ? AND sa.active = 1
                           ORDER BY sa.created_at DESC LIMIT 1");
    $stmt->bind_param("iss", $grade_level, $section, $current_school_year);
    $stmt->execute();
    $recent_students = $stmt->get_result()->fetch_assoc();
    
    // Recent grades by this teacher
    $stmt = $conn->prepare("SELECT s.subject_name, sa.grade_level, g.created_at, st.first_name, st.last_name, g.quarter
                           FROM grades g 
                           JOIN subjects s ON g.subject_id = s.id 
                           JOIN schools_attended sa ON g.school_attended_id = sa.id
                           JOIN students st ON g.student_id = st.id
                           WHERE sa.grade_level = ? AND sa.section = ? AND sa.school_year = ?
                           AND g.grade IS NOT NULL
                           ORDER BY g.created_at DESC LIMIT 1");
    $stmt->bind_param("iss", $grade_level, $section, $current_school_year);
    $stmt->execute();
    $recent_grades = $stmt->get_result()->fetch_assoc();
    
    $recent_sf10 = null;
    $recent_class = null;
} elseif ($is_admin) {
    // Admin recent activities
    $recent_students = $conn->query("SELECT CONCAT(first_name, ' ', last_name) as name, created_at FROM students ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
    
    $recent_grades = $conn->query("SELECT s.subject_name, sa.grade_level, g.created_at, st.first_name, st.last_name, g.quarter
                                   FROM grades g 
                                   JOIN subjects s ON g.subject_id = s.id 
                                   JOIN schools_attended sa ON g.school_attended_id = sa.id
                                   JOIN students st ON g.student_id = st.id
                                   WHERE g.grade IS NOT NULL
                                   ORDER BY g.created_at DESC LIMIT 1")->fetch_assoc();
    
    $recent_sf10 = $conn->query("SELECT CONCAT(st.first_name, ' ', st.last_name) as name, sa.school_year, sa.created_at
                                 FROM schools_attended sa
                                 JOIN students st ON sa.student_id = st.id
                                 ORDER BY sa.created_at DESC LIMIT 1")->fetch_assoc();
    $recent_class = $conn->query("SELECT CONCAT('Grade ', grade_level, ' - ', section) as class_name, created_at
                                  FROM schools_attended 
                                  WHERE grade_level IS NOT NULL 
                                  ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
} else {
    // Unassigned teacher - no recent activities
    $recent_students = null;
    $recent_grades = null;
    $recent_sf10 = null;
    $recent_class = null;
}


function timeAgo($datetime) {
    if (!$datetime) return 'N/A';
    
    date_default_timezone_set('Asia/Manila'); // Set timezone explicitly
    $timestamp = strtotime($datetime);
    $now = time();
    $diff = $now - $timestamp;
    
    // If negative, the datetime is in the future
    if ($diff < 0) {
        return date('M d, Y - g:i A', $timestamp);
    }
    
    // Calculate relative time
    $relative = '';
    if ($diff < 60) {
        $secs = $diff;
        $relative = $secs . ' second' . ($secs == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        $relative = $mins . ' minute' . ($mins == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        $relative = $hours . ' hour' . ($hours == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        $relative = $days . ' day' . ($days == 1 ? '' : 's') . ' ago';
    } else {
        $relative = date('M d, Y', $timestamp);
    }
    
    // Return exact time with relative time
    return date('M d, Y - g:i A', $timestamp) . ' (' . $relative . ')';
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/**
 * Shared initialization function for dashboard doughnut charts
 */
function initChart(labels, dataPoints, percents, totalStudents, colors) {
    const labelsArr = labels.length > 0 ? labels : ['No data'];
    const dataArr = dataPoints.length > 0 && totalStudents > 0 ? dataPoints : [1];
    const colorsArr = totalStudents > 0 ? colors : ['#6c757d'];
    const displayTotal = totalStudents || 0;

    const ctx = document.getElementById('gradeDistributionChart').getContext('2d');
    
    const centerTextPlugin = {
        id: 'centerTextPlugin',
        afterDraw(chart) {
            const {ctx, chartArea: {left, right, top, bottom, width, height}} = chart;
            ctx.save();
            let centerTextColor = '#ffffff';
            try { if (!document.body.classList.contains('dark-theme')) centerTextColor = '#111827'; } catch(e){}
            ctx.fillStyle = centerTextColor;
            ctx.font = '700 22px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(String(displayTotal), left + width/2, top + height/2 - 6);
            ctx.font = '600 12px Arial';
            ctx.fillText('students', left + width/2, top + height/2 + 16);
            ctx.restore();
        }
    };

    function getOrCreateTooltip(chart) {
        let el = document.getElementById('chartjs-tooltip');
        if (!el) {
            el = document.createElement('div'); el.id = 'chartjs-tooltip';
            el.style.position = 'absolute'; el.style.zIndex = 99999; el.style.pointerEvents = 'none';
            el.style.background = '#111827'; el.style.color = '#fff'; el.style.padding = '8px 10px';
            el.style.borderRadius = '6px'; el.style.boxShadow = '0 6px 18px rgba(0,0,0,0.4)'; el.style.font = '600 12px Arial';
            document.body.appendChild(el);
        }
        return el;
    }

    function externalTooltip(context) {
        const {chart, tooltip} = context;
        const tooltipEl = getOrCreateTooltip(chart);
        if (tooltip.opacity === 0) { tooltipEl.style.display = 'none'; return; }
        const dp = (tooltip.dataPoints && tooltip.dataPoints[0]) || null;
        const rawValue = dp ? (dp.parsed || dp.raw || 0) : 0;
        const total = chart.data.datasets[0].data.reduce((a,b)=>a+b,0) || 1;
        const pct = Math.round((rawValue / total) * 100);
        const color = (dp && chart.data.datasets[0].backgroundColor && chart.data.datasets[0].backgroundColor[dp.dataIndex]) || '#999';
        const labelText = (dp && dp.label) || '';
        let innerHtml = `<div style="font-weight:700;margin-bottom:6px;">${labelText}</div>`;
        innerHtml += `<div style="display:flex; align-items:center; gap:8px;"><div style="width:12px; height:12px; background:${color}; border-radius:2px;"></div><div>${rawValue} students (${pct}%)</div></div>`;
        tooltipEl.innerHTML = innerHtml; tooltipEl.style.display = 'block';
        const canvasRect = chart.canvas.getBoundingClientRect();
        tooltipEl.style.left = window.pageXOffset + canvasRect.left + tooltip.caretX + 12 + 'px';
        tooltipEl.style.top = window.pageYOffset + canvasRect.top + tooltip.caretY - 8 + 'px';
    }

    Chart.register(centerTextPlugin);
    const chart = new Chart(ctx, {
        type: 'doughnut',
        data: { labels: labelsArr, datasets: [{ data: dataArr, backgroundColor: colorsArr, hoverOffset: 6, borderWidth: 2, borderColor: '#111827' }] },
        options: { responsive: true, cutout: '64%', maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { enabled: false, external: externalTooltip } } }
    });

    // Theme Observer: Automatically update chart when dark mode is toggled
    window.__chartInstances = window.__chartInstances || [];
    window.__chartInstances.push(chart);
    if (!window.__themeObserver) {
        window.__themeObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    window.__chartInstances.forEach(c => c.update());
                }
            });
        });
        window.__themeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }

    const legendContainer = document.getElementById('gradeDistributionLegend');
    if (legendContainer && totalStudents > 0) {
        legendContainer.innerHTML = labels.map((label, idx) => `
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:16px; height:16px; background:${colors[idx]}; border-radius:4px;"></div>
                    <div style="font-weight:700; color: var(--legend-text-color);">${label}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-weight:700; color:var(--legend-text-color);">${percents[idx]}%</div>
                    <div style="font-size:12px; color:var(--legend-muted-color);">${dataPoints[idx]} students</div>
                </div>
            </div>
        `).join('');
    }
}
</script>

<div class="page-header">
    <?php if ($is_teacher && $teacher_class): ?>
    <h2><i class="bi bi-speedometer2"></i> My Class Dashboard</h2>
    <p class="subtitle">Grade <?= $teacher_class['grade_level'] ?> - <?= htmlspecialchars($teacher_class['section']) ?> | SY <?= $current_school_year ?></p>
    <?php else: ?>
    <h2><i class="bi bi-speedometer2"></i> General Settings</h2>
    <p class="subtitle">School Academic Records Management System</p>
    <?php endif; ?>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <?php if ($is_admin): ?>
    <div class="col-md-3">
        <div class="stats-card" onclick="window.location.href='students.php'" style="cursor: pointer;">
            <div class="icon">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="label">Total Students</div>
            <div class="value"><?= number_format($total_students) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card" onclick="window.location.href='users.php'" style="cursor: pointer;">
            <div class="icon">
                <i class="bi bi-person-badge-fill"></i>
            </div>
            <div class="label">Total Teachers</div>
            <div class="value"><?= number_format($total_teachers) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card" onclick="window.location.href='classes.php'" style="cursor: pointer;">
            <div class="icon">
                <i class="bi bi-bookmarks-fill"></i>
            </div>
            <div class="label">Active Classes</div>
            <div class="value"><?= number_format($total_classes) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card" onclick="window.location.href='sf10_form.php'" style="cursor: pointer;">
            <div class="icon">
                <i class="bi bi-file-text-fill"></i>
            </div>
            <div class="label">SF10 Records</div>
            <div class="value"><?= number_format($total_sf10 ?? 0) ?></div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-md-4">
        <div class="stats-card" onclick="window.location.href='my_class.php'" style="cursor: pointer;">
            <div class="icon">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="label">My Students</div>
            <div class="value"><?= number_format($total_students) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stats-card" <?= $teacher_class ? "onclick=\"window.location.href='my_class.php'\" style=\"cursor:pointer;\"" : '' ?>>
            <div class="icon">
                <i class="bi bi-bookmarks-fill"></i>
            </div>
            <div class="label">My Class</div>
            <?php if ($teacher_class): ?>
                <div class="value"><?= htmlspecialchars('Grade ' . $teacher_class['grade_level'] . ' - ' . $teacher_class['section']) ?></div>
            <?php else: ?>
                <div class="value" style="font-size:14px; color:#a0aec0;">No class assigned yet</div>
                <div style="font-size:12px; color:#718096; margin-top:4px;">Please wait for admin to assign your class</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stats-card" onclick="window.location.href='grades.php'" style="cursor: pointer;">
            <div class="icon">
                <i class="bi bi-file-text-fill"></i>
            </div>
            <div class="label">Students Graded</div>
            <div class="value"><?= number_format($total_records) ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Students by Grade Level Chart and Recent Activity -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <?php if ($is_teacher && $teacher_class): ?>
                <span><i class="bi bi-bar-chart-fill"></i> Class Distribution by Gender</span>
                <span class="badge bg-primary">By Gender</span>
                <?php elseif ($is_admin): ?>
                <span><i class="bi bi-bar-chart-fill"></i> Student Distribution by Grade Level</span>
                <span class="badge bg-primary">Total: <?= number_format($total_students) ?> Students</span>
                <?php else: ?>
                <span><i class="bi bi-bar-chart-fill"></i> System Overview</span>
                <span class="badge bg-secondary">Unassigned</span>
                <?php endif; ?>
            </div>
            <div class="card-body" style="padding: 12px 25px;">
                <?php if ($is_teacher && $teacher_class): ?>
                    <!-- Teacher: Class Gender Distribution (Male/Female) -->
                    <?php
                        $gender_labels = ['Male','Female','Unknown'];
                        $gender_counts = [0,0,0];
                        $grade_level = $teacher_class['grade_level'];
                        $section = $teacher_class['section'];
                        $stmt = $conn->prepare("SELECT LOWER(TRIM(s.gender)) AS gender, COUNT(DISTINCT sa.student_id) AS cnt FROM schools_attended sa JOIN students s ON sa.student_id = s.id WHERE sa.grade_level = ? AND LOWER(sa.section) = LOWER(?) AND sa.school_year = ? AND sa.active = 1 GROUP BY s.gender");
                        $stmt->bind_param("iss", $grade_level, $section, $current_school_year);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        while ($r = $res->fetch_assoc()) {
                            $g = strtolower(trim($r['gender'] ?? ''));
                            $cnt = intval($r['cnt']);
                            if ($g === 'male' || $g === 'm') $gender_counts[0] = $cnt;
                            elseif ($g === 'female' || $g === 'f') $gender_counts[1] = $cnt;
                            else $gender_counts[2] += $cnt;
                        }
                        $total = array_sum($gender_counts);
                        $display_total = $total;
                        if ($total === 0) $total = 1;
                        $gender_percents = array_map(function($c) use ($total){ return round(($c / $total) * 100); }, $gender_counts);
                    ?>
                    <div class="chart-row" style="display:flex; gap:12px; align-items:center; padding: 6px 20px 6px 20px;">
                        <div style="flex: 0 0 260px; display:flex; align-items:center; justify-content:center;">
                            <canvas id="gradeDistributionChart" class="dashboard-pie"></canvas>
                        </div>
                        <div id="gradeDistributionLegend" style="flex:1; display:flex; flex-direction:column; gap:8px;"></div>
                    </div>
                    <script>
                    (function(){
                        const labels = <?= json_encode($gender_labels) ?>;
                        const dataPoints = <?= json_encode($gender_counts) ?>;
                        const percents = <?= json_encode($gender_percents) ?>;
                        const totalStudents = <?= json_encode($display_total) ?>;
                        const colors = ['#3498db', '#ff69b4', '#6c757d'];
                        
                        initChart(labels, dataPoints, percents, totalStudents, colors);
                    })();
                    </script>
                <?php elseif ($is_admin): ?>
                    <!-- Admin: Grade Level Distribution (1-6) -->
                    <div class="chart-row" style="display:flex; gap:12px; align-items:center; padding: 6px 20px 6px 20px;">
                        <div style="flex: 0 0 260px; display:flex; align-items:center; justify-content:center;">
                            <canvas id="gradeDistributionChart" class="dashboard-pie"></canvas>
                        </div>
                        <div id="gradeDistributionLegend" style="flex:1; display:flex; flex-direction:column; gap:8px;"></div>
                    </div>
                    <?php
                        $grade_labels = []; $grade_counts = [];
                        for ($i = 1; $i <= 6; $i++) {
                            $grade_labels[] = "Grade $i";
                            $grade_counts[] = (int)($grade_stats[$i]['count'] ?? 0);
                        }
                        $total = array_sum($grade_counts); 
                        $display_total = $total;
                        if ($total === 0) $total = 1;
                        $grade_percents = array_map(function($c) use ($total){ return round(($c / $total) * 100); }, $grade_counts);
                    ?>
                    <script>
                    (function(){
                        const labels = <?= json_encode($grade_labels) ?>;
                        const dataPoints = <?= json_encode($grade_counts) ?>;
                        const percents = <?= json_encode($grade_percents) ?>;
                        const totalStudents = <?= json_encode($display_total) ?>;
                        const colors = ['#3498db', '#2ecc71', '#f1c40f', '#9b59b6', '#ff69b4', '#ff4d4d'];
                        
                        initChart(labels, dataPoints, percents, totalStudents, colors);
                    })();
                    </script>
                    
                    <!-- Summary Stats -->
                    <div class="row g-2 mt-2">
                        <?php 
                        $total_count = array_sum(array_column($grade_stats, 'count'));
                        $avg_per_grade = $total_count > 0 ? round($total_count / 6, 1) : 0;
                        $highest_grade = 1; $max_val = -1;
                        foreach($grade_stats as $lvl => $st) { if($st['count'] > $max_val) { $max_val = $st['count']; $highest_grade = $lvl; } }
                        $lowest_grade = 1; $min_val = 999999;
                        foreach($grade_stats as $lvl => $st) { if($st['count'] < $min_val) { $min_val = $st['count']; $lowest_grade = $lvl; } }
                        ?>
                        <div class="col-md-4">
                            <div class="summary-stat-card text-center p-3 stat-avg">
                                <i class="bi bi-calculator"></i>
                                <div class="stat-label">Average per Grade</div>
                                <div class="stat-value"><?= number_format($avg_per_grade, 1) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-stat-card text-center p-3 stat-highest">
                                <i class="bi bi-arrow-up-circle-fill"></i>
                                <div class="stat-label">Highest Enrollment</div>
                                <div class="stat-value">Grade <?= $highest_grade ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-stat-card text-center p-3 stat-lowest">
                                <i class="bi bi-arrow-down-circle-fill"></i>
                                <div class="stat-label">Lowest Enrollment</div>
                                <div class="stat-value">Grade <?= $lowest_grade ?></div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-person-badge text-muted" style="font-size: 48px;"></i>
                        <h5 class="mt-3 text-muted">Account Not Yet Assigned</h5>
                        <p class="text-secondary small">Your teacher account is registered but has not been assigned to a class or subject yet.<br>Please contact the system administrator for your assignments.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Activity -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header" <?php if ($is_admin): ?> onclick="window.location.href='logs.php'" style="cursor: pointer;"<?php endif; ?>>
                <i class="bi bi-clock-history"></i> Recent Activity
            </div>
            <div class="card-body">
                <?php 
                $has_activity = false;
                if ($recent_students): 
                    $has_activity = true;
                ?>
                <div class="activity-item" <?php if ($is_admin): ?> onclick="window.location.href='logs.php'" style="cursor: pointer;"<?php endif; ?>>
                    <div class="d-flex align-items-start mb-3">
                        <div class="flex-shrink-0">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-teal) 0%, var(--primary-teal-light) 100%); display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-person-plus-fill text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div style="font-weight: 600; font-size: 14px; color: var(--text-color);">New Student Enrolled</div>
                            <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars($recent_students['name']) ?></div>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                <i class="bi bi-clock"></i> <?= timeAgo($recent_students['created_at']) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($recent_grades): 
                    $has_activity = true;
                ?>
                <div class="activity-item" <?php if ($is_admin): ?> onclick="window.location.href='logs.php'" style="cursor: pointer;"<?php endif; ?>>
                    <div class="d-flex align-items-start mb-3">
                        <div class="flex-shrink-0">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-pencil-fill text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div style="font-weight: 600; font-size: 14px; color: var(--text-color);">Grade Entry</div>
                            <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                                <?= htmlspecialchars($recent_grades['subject_name']) ?> - Q<?= $recent_grades['quarter'] ?> (Grade <?= $recent_grades['grade_level'] ?>)
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                <i class="bi bi-clock"></i> <?= timeAgo($recent_grades['created_at']) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($recent_sf10): 
                    $has_activity = true;
                ?>
                <div class="activity-item" <?php if ($is_admin): ?> onclick="window.location.href='logs.php'" style="cursor: pointer;"<?php endif; ?>>
                    <div class="d-flex align-items-start mb-3">
                        <div class="flex-shrink-0">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-teal) 0%, var(--primary-teal-light) 100%); display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-file-earmark-text-fill text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div style="font-weight: 600; font-size: 14px; color: var(--text-color);">SF10 Record Created</div>
                            <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                                <?= htmlspecialchars($recent_sf10['name']) ?> - SY <?= htmlspecialchars($recent_sf10['school_year']) ?>
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                <i class="bi bi-clock"></i> <?= timeAgo($recent_sf10['created_at']) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($recent_class): 
                    $has_activity = true;
                ?>
                <div class="activity-item" <?php if ($is_admin): ?> onclick="window.location.href='logs.php'" style="cursor: pointer;"<?php endif; ?>>
                    <div class="d-flex align-items-start">
                        <div class="flex-shrink-0">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-bookmark-plus-fill text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div style="font-weight: 600; font-size: 14px; color: var(--text-color);">Class Created</div>
                            <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars($recent_class['class_name']) ?></div>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                                <i class="bi bi-clock"></i> <?= timeAgo($recent_class['created_at']) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!$has_activity): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.3;"></i>
                    <p class="mt-2">No recent activity</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.stats-card {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 12px;
    padding: 20px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.stats-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
.stats-card .icon { font-size: 24px; color: var(--primary-teal); margin-bottom: 10px; }
.stats-card .label { font-size: 14px; color: var(--text-muted); }
.stats-card .value { font-size: 24px; font-weight: 700; color: var(--text-color); }

body:not(.dark-theme) .stats-card { background: #fff; border-color: #e0e0e0; }

/* Summary Stat Cards */
.summary-stat-card {
    border-radius: 12px;
    transition: all 0.3s ease;
    border: 1px solid transparent;
}

.summary-stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.15);
}

.summary-stat-card i {
    font-size: 24px;
    margin-bottom: 8px;
    display: block;
}

.summary-stat-card .stat-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 4px;
}

.summary-stat-card .stat-value {
    font-size: 24px;
    font-weight: 700;
}

/* Light Mode Summary Stat Colors */
body:not(.dark-theme) .summary-stat-card {
    background: #ffffff;
    border-color: #e9ecef;
}

body:not(.dark-theme) .stat-avg { border-top: 3px solid var(--primary-teal); }
body:not(.dark-theme) .stat-avg i, body:not(.dark-theme) .stat-avg .stat-value { color: var(--primary-teal); }
body:not(.dark-theme) .stat-avg .stat-label { color: #6c757d; }

body:not(.dark-theme) .stat-highest { border-top: 3px solid #2ecc71; }
body:not(.dark-theme) .stat-highest i, body:not(.dark-theme) .stat-highest .stat-value { color: #2ecc71; }
body:not(.dark-theme) .stat-highest .stat-label { color: #6c757d; }

body:not(.dark-theme) .stat-lowest { border-top: 3px solid #e74c3c; }
body:not(.dark-theme) .stat-lowest i, body:not(.dark-theme) .stat-lowest .stat-value { color: #e74c3c; }
body:not(.dark-theme) .stat-lowest .stat-label { color: #6c757d; }

/* Dark Mode Summary Stat Colors */
body.dark-theme .summary-stat-card {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.1);
}

body.dark-theme .stat-avg { border-top: 3px solid var(--primary-teal); }
body.dark-theme .stat-avg i, body.dark-theme .stat-avg .stat-value { color: var(--primary-teal); }
body.dark-theme .stat-avg .stat-label { color: rgba(255, 255, 255, 0.6); }

body.dark-theme .stat-highest { border-top: 3px solid #2ecc71; }
body.dark-theme .stat-highest i, body.dark-theme .stat-highest .stat-value { color: #2ecc71; }
body.dark-theme .stat-highest .stat-label { color: rgba(255, 255, 255, 0.6); }

body.dark-theme .stat-lowest { border-top: 3px solid #e74c3c; }
body.dark-theme .stat-lowest i, body.dark-theme .stat-lowest .stat-value { color: #e74c3c; }
body.dark-theme .stat-lowest .stat-label { color: rgba(255, 255, 255, 0.6); }

.dashboard-pie { width:220px; height:220px; }
@media (max-width: 576px) { .chart-row { flex-direction: column; } .dashboard-pie { width:160px; height:160px; } }

body.dark-theme { --legend-text-color: #ffffff; --legend-muted-color: rgba(255,255,255,0.6); }
body:not(.dark-theme) { --legend-text-color: #111827; --legend-muted-color: rgba(0,0,0,0.6); }
</style>

<?php include '../templates/footer.php'; ?>
