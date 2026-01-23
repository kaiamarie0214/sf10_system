<?php 
include '../includes/db.php';
include '../templates/header.php';

$user = $_SESSION['user'];
$is_teacher = ($user['role'] === 'teacher');
$is_admin = ($user['role'] === 'admin');

// Get teacher's class info if teacher
$teacher_class = null;
$current_school_year = date('Y') . '-' . (date('Y') + 1);

if ($is_teacher) {
    $adviser_query = "SELECT grade_level, section FROM teacher_assignments 
                      WHERE teacher_id = ? AND assignment_type = 'adviser' LIMIT 1";
    $stmt = $conn->prepare($adviser_query);
    $stmt->bind_param("i", $user['id']);
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
                                                                            JOIN (SELECT student_id, MAX(id) AS max_id FROM schools_attended GROUP BY student_id) l
                                                                                ON sa.student_id = l.student_id AND sa.id = l.max_id
                                                                            WHERE sa.grade_level = ? AND sa.section = ? AND sa.school_year = ?
                                                                              AND (sa.active = 1 OR sa.active IS NULL)");
        $total_students_stmt->bind_param("iss", $grade_level, $section, $current_school_year);
        $total_students_stmt->execute();
        $total_students = $total_students_stmt->get_result()->fetch_assoc()['count'];
    
    // Get subjects assigned to this teacher
    $total_subjects = $conn->prepare("SELECT COUNT(DISTINCT subject_id) as count FROM teacher_assignments 
                                      WHERE teacher_id = ? AND assignment_type = 'subject'");
    $total_subjects->bind_param("i", $user['id']);
    $total_subjects->execute();
    $total_subjects = $total_subjects->get_result()->fetch_assoc()['count'];
    
    // Get total grades entered by this teacher
    $total_records = $conn->prepare("SELECT COUNT(*) as count FROM grades g
                                     JOIN schools_attended sa ON g.school_attended_id = sa.id
                                     WHERE sa.grade_level = ? AND sa.section = ? AND sa.school_year = ?
                                     AND g.grade IS NOT NULL");
    $total_records->bind_param("iss", $grade_level, $section, $current_school_year);
    $total_records->execute();
    $total_records = $total_records->get_result()->fetch_assoc()['count'];
    
    $total_classes = 1; // Teacher has one class
} else {
    // Admin stats - global
    $total_students = $conn->query("SELECT COUNT(*) as count FROM students")->fetch_assoc()['count'];
    $total_teachers = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'teacher'")->fetch_assoc()['count'];
    $total_classes = $conn->query("SELECT COUNT(DISTINCT CONCAT(grade_level, section)) as count FROM schools_attended WHERE grade_level IS NOT NULL")->fetch_assoc()['count'];
    $total_subjects = $conn->query("SELECT COUNT(*) as count FROM subjects")->fetch_assoc()['count'];
    $total_records = $conn->query("SELECT COUNT(*) as count FROM grades")->fetch_assoc()['count'];
    // SF10 records are stored in schools_attended
    $total_sf10 = $conn->query("SELECT COUNT(*) as count FROM schools_attended")->fetch_assoc()['count'];
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
                           WHERE ta.teacher_id = ? AND ta.assignment_type = 'subject'
                           GROUP BY s.id, s.subject_name
                           ORDER BY s.subject_name";
    $stmt = $conn->prepare($subject_stats_query);
    $stmt->bind_param("issi", $grade_level, $section, $current_school_year, $user['id']);
    $stmt->execute();
    $subject_stats = $stmt->get_result();
} else {
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
                           WHERE sa.grade_level = ? AND sa.section = ? AND sa.school_year = ? AND (sa.active = 1 OR sa.active IS NULL)
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
} else {
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
    <?php if (!$is_teacher): ?>
    <div class="col-md-3">
        <div class="stats-card" onclick="window.location.href='records.php'" style="cursor: pointer;">
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
        <div class="stats-card" onclick="window.location.href='my_class.php'" style="cursor: pointer;">
            <div class="icon">
                <i class="bi bi-bookmarks-fill"></i>
            </div>
            <div class="label">My Class</div>
            <div class="value"><?= htmlspecialchars($teacher_class['grade_level'] . ' - ' . $teacher_class['section']) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stats-card" onclick="window.location.href='grades.php'" style="cursor: pointer;">
            <div class="icon">
                <i class="bi bi-file-text-fill"></i>
            </div>
            <div class="label">Grades Entered</div>
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
                <?php else: ?>
                <span><i class="bi bi-bar-chart-fill"></i> Student Distribution by Grade Level</span>
                <span class="badge bg-primary">Total: <?= number_format($total_students) ?> Students</span>
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
                        $stmt = $conn->prepare("SELECT LOWER(TRIM(s.gender)) AS gender, COUNT(DISTINCT sa.student_id) AS cnt FROM schools_attended sa JOIN (SELECT student_id, MAX(id) AS max_id FROM schools_attended GROUP BY student_id) l ON sa.student_id = l.student_id AND sa.id = l.max_id JOIN students s ON sa.student_id = s.id WHERE sa.grade_level = ? AND sa.section = ? AND sa.school_year = ? AND (sa.active = 1 OR sa.active IS NULL) GROUP BY s.gender");
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
                        // Ensure totals reflect all students (including unknown genders)
                        $total = array_sum($gender_counts);
                        if ($total === 0) $total = 1;
                        $gender_percents = array_map(function($c) use ($total){ return round(($c / $total) * 100); }, $gender_counts);
                        $js_labels = json_encode($gender_labels);
                        $js_counts = json_encode($gender_counts);
                        $js_percents = json_encode($gender_percents);
                        $js_total = json_encode(array_sum($gender_counts));
                            // Debug: expose computed gender arrays as HTML comment for inspection
                            echo "<!-- GENDER_DEBUG labels=" . htmlspecialchars($js_labels) . " counts=" . htmlspecialchars($js_counts) . " total=" . htmlspecialchars($js_total) . " -->\n";
                    ?>
                    <div class="chart-row" style="display:flex; gap:12px; align-items:center; padding: 6px 20px 6px 20px;">
                        <div style="flex: 0 0 260px; display:flex; align-items:center; justify-content:center;">
                            <canvas id="gradeDistributionChart" class="dashboard-pie"></canvas>
                        </div>
                        <div id="gradeDistributionLegend" style="flex:1; display:flex; flex-direction:column; gap:8px;">
                            <!-- Legend items will be populated by JS -->
                        </div>
                    </div>
                    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
                    <script>
                    (function(){
                        const labels = <?= $js_labels ?>;
                        const dataPoints = <?= $js_counts ?>;
                        const percents = <?= $js_percents ?>;
                        const totalStudents = <?= $js_total ?>;
                        const colors = ['#3498db', '#ff69b4', '#6c757d'];

                        // If there are zero students, show a single grey slice so the donut is visible
                        let labelsArr = Array.isArray(labels) ? labels.slice() : labels;
                        let dataArr = Array.isArray(dataPoints) ? dataPoints.slice() : dataPoints;
                        let percentsArr = Array.isArray(percents) ? percents.slice() : percents;
                        let colorsArr = colors.slice();
                        let displayTotal = totalStudents;
                        const sumVals = (Array.isArray(dataArr) ? dataArr : []).reduce((a,b)=>a+b,0) || 0;
                        if (displayTotal === 0 || sumVals === 0) {
                            labelsArr = ['No students'];
                            dataArr = [1];
                            percentsArr = [0];
                            colorsArr = ['#6c757d'];
                            // keep displayTotal as 0 to show center text correctly
                        }
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
                                ctx.fillText(String(totalStudents), left + width/2, top + height/2 - 6);
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
                            const title = (tooltip.title && tooltip.title[0]) ? tooltip.title[0] : '';
                            const rawValue = dp ? (dp.parsed || dp.raw || 0) : 0;
                            const total = chart.data.datasets[0].data.reduce((a,b)=>a+b,0) || 1;
                            const pct = Math.round((rawValue / total) * 100);
                            const color = (dp && chart.data.datasets[0].backgroundColor && chart.data.datasets[0].backgroundColor[dp.dataIndex]) || '#999';
                            const labelText = title || (dp && dp.label) || '';
                            const countText = rawValue + ' ' + (rawValue === 1 ? 'student' : 'students');
                            let innerHtml = ''; if (labelText) innerHtml += '<div style="font-weight:700;margin-bottom:6px;">' + labelText + '</div>';
                            innerHtml += '<div style="display:flex; align-items:center; gap:8px;">'
                                      + '<div style="width:12px; height:12px; background:' + color + '; border-radius:2px; box-shadow: 0 1px 0 rgba(0,0,0,0.2);"></div>'
                                      + '<div style="font-weight:600">' + countText + ' (' + pct + '%)</div>'
                                      + '</div>';
                            tooltipEl.innerHTML = innerHtml; tooltipEl.style.display = 'block';
                            const canvasRect = chart.canvas.getBoundingClientRect(); const elRect = tooltipEl.getBoundingClientRect();
                            let left = window.pageXOffset + canvasRect.left + (tooltip.caretX || canvasRect.width/2) + 12;
                            const minLeft = window.pageXOffset + 8; const maxLeft = window.pageXOffset + document.documentElement.clientWidth - elRect.width - 8;
                            if (left > maxLeft) left = maxLeft; if (left < minLeft) left = minLeft;
                            let top = window.pageYOffset + canvasRect.top + (tooltip.caretY || canvasRect.height/2) - 8;
                            const minTop = window.pageYOffset + 8; const maxTop = window.pageYOffset + document.documentElement.clientHeight - elRect.height - 8;
                            if (top > maxTop) top = maxTop; if (top < minTop) top = minTop;
                            tooltipEl.style.left = left + 'px'; tooltipEl.style.top = top + 'px';
                        }
                        Chart.register(centerTextPlugin);
                        const chart = new Chart(ctx, { type: 'doughnut', data: { labels: labelsArr, datasets: [{ data: dataArr, backgroundColor: colorsArr, hoverOffset: 6, borderWidth: 2, borderColor: '#111827' }] }, options: { responsive: true, cutout: '64%', maintainAspectRatio: false, layout: { padding: { top: 6, right: 6, bottom: 6, left: 6 } }, plugins: { legend: { display: false }, tooltip: { enabled: false, external: externalTooltip } } } });
                        // Ensure center text updates when theme toggles: track charts and observe body.class changes
                        window.__chartInstances = window.__chartInstances || [];
                        window.__chartInstances.push(chart);
                        if (!window.__chartThemeObserver) {
                            window.__chartThemeObserver = new MutationObserver(function(muts){
                                muts.forEach(function(m){
                                    if (m.attributeName === 'class') {
                                        (window.__chartInstances || []).forEach(function(c){ try{ c.update(); }catch(e){} });
                                    }
                                });
                            });
                            window.__chartThemeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
                        }
                        const legendContainer = document.getElementById('gradeDistributionLegend');
                        const containerHtml = labelsArr.map((label, idx) => {
                            const color = colorsArr[idx]; const val = dataArr[idx] || 0; const pct = percentsArr[idx] || 0;
                            return `
                                <div style="display:flex; align-items:center; justify-content:space-between;">
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div style="width:16px; height:16px; background:${color}; border-radius:4px; box-shadow: 0 1px 0 rgba(0,0,0,0.2);"></div>
                                        <div style="font-weight:700; color: var(--legend-text-color);">${label}</div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-weight:700; color:var(--legend-text-color);">${pct}%</div>
                                        <div style="font-size:12px; color:var(--legend-muted-color);">${val} students</div>
                                    </div>
                                </div>
                            `;
                        }).join('');
                        legendContainer.innerHTML = containerHtml;
                    })();
                    </script>
                <?php else: ?>
                    <?php if ($total_students > 0): ?>
                    <!-- Grade Distribution Donut Chart (Grades 1-6) -->
                    <div class="chart-row" style="display:flex; gap:12px; align-items:center; padding: 6px 20px 6px 20px;">
                        <div style="flex: 0 0 260px; display:flex; align-items:center; justify-content:center;">
                            <canvas id="gradeDistributionChart" class="dashboard-pie"></canvas>
                        </div>
                        <div id="gradeDistributionLegend" style="flex:1; display:flex; flex-direction:column; gap:8px;">
                            <!-- Legend items will be populated by JS -->
                        </div>
                    </div>
                    <?php
                        $grade_labels = [];
                        $grade_counts = [];
                        $total_students = 0;
                        for ($i = 1; $i <= 6; $i++) {
                            $grade_labels[] = "Grade $i";
                            $count = (int)($grade_stats[$i]['count'] ?? 0);
                            $grade_counts[] = $count;
                            $total_students += $count;
                        }
                        if ($total_students === 0) $total_students = 1;
                        $grade_percents = array_map(function($c) use ($total_students){ return round(($c / $total_students) * 100); }, $grade_counts);

                        $js_labels = json_encode($grade_labels);
                        $js_counts = json_encode($grade_counts);
                        $js_percents = json_encode($grade_percents);
                        $real_total = array_sum($grade_counts);
                        $js_total = json_encode($real_total);
                    ?>
                    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
                    <script>
                    (function(){
                        const labels = <?= $js_labels ?>;
                        const dataPoints = <?= $js_counts ?>;
                        const percents = <?= $js_percents ?>;
                        const totalStudents = <?= $js_total ?>;

                        const colors = ['#3498db', '#2ecc71', '#f1c40f', '#9b59b6', '#ff69b4', '#ff4d4d'];

                        const ctx = document.getElementById('gradeDistributionChart').getContext('2d');

                        // Center text plugin: shows total number of students
                        const centerTextPlugin = {
                            id: 'centerTextPlugin',
                            afterDraw(chart) {
                                const {ctx, chartArea: {left, right, top, bottom, width, height}} = chart;
                                ctx.save();
                                // Choose text color based on theme (dark-theme class on body)
                                let centerTextColor = '#ffffff';
                                try {
                                    if (!document.body.classList.contains('dark-theme')) {
                                        centerTextColor = '#111827';
                                    }
                                } catch(e) {}
                                ctx.fillStyle = centerTextColor;
                                ctx.font = '700 22px Arial';
                                ctx.textAlign = 'center';
                                ctx.textBaseline = 'middle';
                                ctx.fillText(String(totalStudents), left + width/2, top + height/2 - 6);
                                ctx.font = '600 12px Arial';
                                ctx.fillText('students', left + width/2, top + height/2 + 16);
                                ctx.restore();
                            }
                        };

                        // External DOM tooltip so it can overlay all page elements
                        function getOrCreateTooltip(chart) {
                            let el = document.getElementById('chartjs-tooltip');
                            if (!el) {
                                el = document.createElement('div');
                                el.id = 'chartjs-tooltip';
                                el.style.position = 'absolute';
                                el.style.zIndex = 99999;
                                el.style.pointerEvents = 'none';
                                el.style.background = '#111827';
                                el.style.color = '#fff';
                                el.style.padding = '8px 10px';
                                el.style.borderRadius = '6px';
                                el.style.boxShadow = '0 6px 18px rgba(0,0,0,0.4)';
                                el.style.font = '600 12px Arial';
                                document.body.appendChild(el);
                            }
                            return el;
                        }

                        function externalTooltip(context) {
                            const {chart, tooltip} = context;
                            const tooltipEl = getOrCreateTooltip(chart);
                            if (tooltip.opacity === 0) {
                                tooltipEl.style.display = 'none';
                                return;
                            }

                            // Prepare content: include title label, color swatch, value and percent
                            const dp = (tooltip.dataPoints && tooltip.dataPoints[0]) || null;
                            const title = (tooltip.title && tooltip.title[0]) ? tooltip.title[0] : '';
                            const rawValue = dp ? (dp.parsed || dp.raw || 0) : 0;
                            const total = chart.data.datasets[0].data.reduce((a,b)=>a+b,0) || 1;
                            const pct = Math.round((rawValue / total) * 100);
                            const color = (dp && chart.data.datasets[0].backgroundColor && chart.data.datasets[0].backgroundColor[dp.dataIndex]) || '#999';

                            const labelText = title || (dp && dp.label) || '';
                            const countText = rawValue + ' ' + (rawValue === 1 ? 'student' : 'students');

                            let innerHtml = '';
                            if (labelText) {
                                innerHtml += '<div style="font-weight:700;margin-bottom:6px;">' + labelText + '</div>';
                            }
                            innerHtml += '<div style="display:flex; align-items:center; gap:8px;">'
                                      + '<div style="width:12px; height:12px; background:' + color + '; border-radius:2px; box-shadow: 0 1px 0 rgba(0,0,0,0.2);"></div>'
                                      + '<div style="font-weight:600">' + countText + ' (' + pct + '%)</div>'
                                      + '</div>';

                            tooltipEl.innerHTML = innerHtml;
                            // Show then measure to avoid overflow off-screen (especially on mobile)
                            tooltipEl.style.display = 'block';
                            const canvasRect = chart.canvas.getBoundingClientRect();
                            const elRect = tooltipEl.getBoundingClientRect();

                            // Preferred position near the caret
                            let left = window.pageXOffset + canvasRect.left + (tooltip.caretX || canvasRect.width/2) + 12;
                            const minLeft = window.pageXOffset + 8;
                            const maxLeft = window.pageXOffset + document.documentElement.clientWidth - elRect.width - 8;
                            if (left > maxLeft) left = maxLeft;
                            if (left < minLeft) left = minLeft;

                            let top = window.pageYOffset + canvasRect.top + (tooltip.caretY || canvasRect.height/2) - 8;
                            const minTop = window.pageYOffset + 8;
                            const maxTop = window.pageYOffset + document.documentElement.clientHeight - elRect.height - 8;
                            if (top > maxTop) top = maxTop;
                            if (top < minTop) top = minTop;

                            tooltipEl.style.left = left + 'px';
                            tooltipEl.style.top = top + 'px';
                        }

                        Chart.register(centerTextPlugin);

                        const chart = new Chart(ctx, {
                            type: 'doughnut',
                            data: {
                                labels: labels,
                                datasets: [{
                                    data: dataPoints,
                                    backgroundColor: colors,
                                    hoverOffset: 6,
                                    borderWidth: 2,
                                    borderColor: '#111827'
                                }]
                            },
                            options: {
                                responsive: true,
                                cutout: '64%',
                                maintainAspectRatio: false,
                                layout: { padding: { top: 6, right: 6, bottom: 6, left: 6 } },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        enabled: false,
                                        external: externalTooltip
                                    }
                                }
                            }
                        });
                        // Ensure center text updates when theme toggles: track charts and observe body.class changes
                        window.__chartInstances = window.__chartInstances || [];
                        window.__chartInstances.push(chart);
                        if (!window.__chartThemeObserver) {
                            window.__chartThemeObserver = new MutationObserver(function(muts){
                                muts.forEach(function(m){
                                    if (m.attributeName === 'class') {
                                        (window.__chartInstances || []).forEach(function(c){ try{ c.update(); }catch(e){} });
                                    }
                                });
                            });
                            window.__chartThemeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
                        }

                        // Build custom legend for grades 1-6
                        const legendContainer = document.getElementById('gradeDistributionLegend');
                        const containerHtml = labels.map((label, idx) => {
                            const color = colors[idx];
                            const val = dataPoints[idx] || 0;
                            const pct = percents[idx] || 0;
                            return `
                                <div style="display:flex; align-items:center; justify-content:space-between;">
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div style="width:16px; height:16px; background:${color}; border-radius:4px; box-shadow: 0 1px 0 rgba(0,0,0,0.2);"></div>
                                        <div style="font-weight:700; color: var(--legend-text-color);">${label}</div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-weight:700; color:var(--legend-text-color);">${pct}%</div>
                                        <div style="font-size:12px; color:var(--legend-muted-color);">${val} students</div>
                                    </div>
                                </div>
                            `;
                        }).join('');
                        legendContainer.innerHTML = containerHtml;
                    })();
                    </script>
                    
                    <!-- Summary Stats -->
                    <div class="row g-2 mt-2">
                        <?php 
                        $total_count = array_sum(array_column($grade_stats, 'count'));
                        $avg_per_grade = $total_count > 0 ? round($total_count / 6, 1) : 0;
                        $highest_grade = array_search(max(array_column($grade_stats, 'count')), array_column($grade_stats, 'count')) + 1;
                        $lowest_grade = array_search(min(array_column($grade_stats, 'count')), array_column($grade_stats, 'count')) + 1;
                        ?>
                        <div class="col-md-4">
                            <div class="summary-stat-card text-center p-3" style="background: rgba(0,0,0,0.15); border-radius: 12px; border-top: 3px solid #3498db;">
                                <i class="bi bi-calculator" style="font-size: 24px; color: #3498db;"></i>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.6); margin-top: 8px; text-transform: uppercase; letter-spacing: 1px;">Average per Grade</div>
                                <div style="font-size: 24px; font-weight: bold; color: #3498db; margin-top: 5px;"><?= number_format($avg_per_grade, 1) ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-stat-card text-center p-3" style="background: rgba(0,0,0,0.15); border-radius: 12px; border-top: 3px solid #2ecc71;">
                                <i class="bi bi-arrow-up-circle-fill" style="font-size: 24px; color: #2ecc71;"></i>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.6); margin-top: 8px; text-transform: uppercase; letter-spacing: 1px;">Highest Enrollment</div>
                                <div style="font-size: 24px; font-weight: bold; color: #2ecc71; margin-top: 5px;">Grade <?= $highest_grade ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-stat-card text-center p-3" style="background: rgba(0,0,0,0.15); border-radius: 12px; border-top: 3px solid #e74c3c;">
                                <i class="bi bi-arrow-down-circle-fill" style="font-size: 24px; color: #e74c3c;"></i>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.6); margin-top: 8px; text-transform: uppercase; letter-spacing: 1px;">Lowest Enrollment</div>
                                <div style="font-size: 24px; font-weight: bold; color: #e74c3c; margin-top: 5px;">Grade <?= $lowest_grade ?></div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.3;"></i>
                        <p class="mt-2">No students enrolled yet</p>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Activity -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header" <?php if (!$is_teacher): ?> onclick="window.location.href='logs.php'" style="cursor: pointer;"<?php endif; ?>>
                <i class="bi bi-clock-history"></i> Recent Activity
            </div>
            <div class="card-body">
                <?php 
                $has_activity = false;
                if ($recent_students): 
                    $has_activity = true;
                ?>
                <div class="activity-item" <?php if (!$is_teacher): ?> onclick="window.location.href='logs.php'" style="cursor: pointer;"<?php endif; ?>>
                    <div class="d-flex align-items-start mb-3">
                        <div class="flex-shrink-0">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center;">
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
                <div class="activity-item" <?php if (!$is_teacher): ?> onclick="window.location.href='logs.php'" style="cursor: pointer;"<?php endif; ?>>
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
                <div class="activity-item" <?php if (!$is_teacher): ?> onclick="window.location.href='logs.php'" style="cursor: pointer;"<?php endif; ?>>
                    <div class="d-flex align-items-start mb-3">
                        <div class="flex-shrink-0">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); display: flex; align-items: center; justify-content: center;">
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
                <div class="activity-item" <?php if (!$is_teacher): ?> onclick="window.location.href='logs.php'" style="cursor: pointer;"<?php endif; ?>>
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
/* Vertical Bar Chart */
.chart-container {
    background: rgba(0,0,0,0.1);
    border-radius: 12px;
}

.bar-wrapper:hover .bar {
    opacity: 0.9;
    transform: scaleY(1.02);
}

.bar {
    transition: all 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}

body:not(.dark-theme) .chart-container {
    background: #f8f9fa;
}

body:not(.dark-theme) .bar-wrapper div {
    color: #2c3e50 !important;
}

body:not(.dark-theme) .bar {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
}

/* Summary Stat Cards */
.summary-stat-card {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.summary-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.3);
}

body:not(.dark-theme) .summary-stat-card {
    background: #f8f9fa !important;
    border: 1px solid #e0e0e0;
}

body:not(.dark-theme) .summary-stat-card div {
    color: #2c3e50 !important;
}

body.dark-theme .summary-stat-card {
    background: rgba(0,0,0,0.15) !important;
}

/* Dashboard card backgrounds */
body:not(.dark-theme) .grade-card,
body:not(.dark-theme) .stat-card {
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

body.dark-theme .grade-card,
body.dark-theme .stat-card {
    background: rgba(255, 255, 255, 0.05);
}

/* Progress bar backgrounds */
body:not(.dark-theme) {
    --progress-bg: rgba(0, 0, 0, 0.08);
}

body.dark-theme {
    --progress-bg: rgba(255, 255, 255, 0.15);
}

/* Chart text colors */
.chart-label {
    color: var(--text-color);
}

.chart-value {
    color: var(--text-color);
}

.chart-axis-label {
    color: var(--text-muted);
}
</style>

<style>
/* Ensure both chart canvases render the same fixed size and are centered */
</style>

<style>
/* Responsive dashboard pie sizes */
.dashboard-pie { display:block; margin:0 auto; width:220px; height:220px; }

@media (max-width: 992px) {
    .dashboard-pie { width:180px; height:180px; }
}

@media (max-width: 576px) {
    .chart-row { flex-direction: column; gap: 12px; }
    .dashboard-pie { width:140px; height:140px; }
    #gradeDistributionLegend { width: 100%; display:flex; flex-direction:column; align-items:center; }
}
</style>
</style>

<style>
/* Legend color variables for light/dark themes */
body.dark-theme {
    --legend-text-color: #ffffff;
    --legend-muted-color: rgba(255,255,255,0.6);
}
body:not(.dark-theme) {
    --legend-text-color: #111827;
    --legend-muted-color: rgba(0,0,0,0.6);
}
</style>

<style>
/* Allow chart slices to expand outside their immediate container to avoid clipping on hover */
.chart-row, .chart-row > div {
    overflow: visible !important;
}
.chart-row canvas {
    display: block;
}
</style>

<?php include '../templates/footer.php'; ?>