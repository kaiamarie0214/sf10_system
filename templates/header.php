<?php
if (!isset($_SESSION)) { session_start(); }
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}
$user = $_SESSION['user'];
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$is_admin = ($user['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script>
    // Early theme guard: set background and theme-color immediately to avoid white flash
    (function(){
      try {
        var t = localStorage.getItem('theme');
        var bg = (t === 'dark') ? '#1a1d23' : '#f4f7fb';
        document.documentElement.style.backgroundColor = bg;
        // ensure meta theme-color exists and is set for mobile browsers
        var meta = document.querySelector('meta[name="theme-color"]');
        if (!meta) {
          meta = document.createElement('meta'); meta.name = 'theme-color'; document.head.appendChild(meta);
        }
        meta.content = bg;
        if (t === 'dark') document.documentElement.classList.add('dark-theme-loading');
        window.pageJustLoaded = true;
      } catch (e) { /* ignore */ }
    })();
  </script>
  <title>SF10 System</title>
  <!-- Critical inline background to avoid white flash between page loads -->
  <style>
    html, body { background-color: #f4f7fb; }
    .dark-theme-loading, .dark-theme-loading body { background-color: #1a1d23 !important; }
  </style>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <?php $style_path = __DIR__ . '/../assets/css/style.css'; ?>
  <link href="../assets/css/style.css?v=<?= file_exists($style_path) ? filemtime($style_path) : time() ?>" rel="stylesheet">
  <script>
    // Apply theme immediately before page renders to prevent flash
    (function() {
      const savedTheme = localStorage.getItem('theme');
      if (savedTheme === 'dark') {
        document.documentElement.classList.add('dark-theme-loading');
      }
      
      // Set flag to prevent transitions on initial load
      window.pageJustLoaded = true;
      
      // Apply dropdown states immediately before page renders
      document.addEventListener('DOMContentLoaded', function() {
        // Apply states instantly without any delay
        document.querySelectorAll('.nav-group').forEach(function(group) {
          const groupTitle = group.querySelector('.nav-group-title');
          const groupItems = group.querySelector('.nav-group-items');
          if (!groupTitle || !groupItems) return;
          
          const groupId = groupTitle.textContent.trim();
          const isOpen = localStorage.getItem('sidebar-' + groupId);
          
          // Default to collapsed if no saved state (first time)
          if (isOpen === 'open') {
            groupItems.classList.remove('collapsed');
            groupTitle.classList.remove('collapsed');
          } else {
            groupItems.classList.add('collapsed');
            groupTitle.classList.add('collapsed');
          }
        });
        
        // Re-enable transitions after a brief moment
        setTimeout(function() {
          window.pageJustLoaded = false;
          document.body.classList.add('transitions-enabled');
        }, 200);
      });
    })();
  </script>
  <style>
    /* Prevent white flash on page load */
    .dark-theme-loading {
      background-color: #1a1d23 !important;
    }
    .dark-theme-loading body {
      background-color: #1a1d23 !important;
    }
    /* Disable transitions by default (on page load) */
    .sidebar .nav-group-items {
      transition: none !important;
    }
    .sidebar .nav-group-title .toggle-icon {
      transition: none !important;
    }
    /* Enable transitions only after flag is set */
    body.transitions-enabled .sidebar .nav-group-items {
      transition: max-height 0.3s ease !important;
    }
    body.transitions-enabled .sidebar .nav-group-title .toggle-icon {
      transition: transform 0.3s ease !important;
    }
    
    /* Circular Theme Toggle Button */
    .theme-toggle-btn {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      border: none;
      background: rgba(255, 255, 255, 0.95);
      color: #FFA500;
      font-size: 18px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.3s ease;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
      position: relative;
      overflow: hidden;
    }
    
    .theme-toggle-btn:hover {
      transform: scale(1.1);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
    }
    
    .theme-toggle-btn:active {
      transform: scale(0.95);
    }
    
    .theme-toggle-btn i {
      position: absolute;
      /* Remove global transition to prevent accidental animations on page load */
    }
    
    /* Only animate/transition when explicitly toggling */
    .theme-toggle-btn.is-toggling i {
      transition: all 0.4s ease;
    }
    
    .theme-toggle-btn.is-toggling .bi-moon-fill {
      animation: fadeIn 0.4s ease;
    }
    
    .theme-toggle-btn.is-toggling .bi-sun-fill {
      animation: fadeIn 0.4s ease;
    }
    
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: rotate(-180deg) scale(0.5);
      }
      to {
        opacity: 1;
        transform: rotate(0deg) scale(1);
      }
    }
    
    @keyframes fadeOut {
      from {
        opacity: 1;
        transform: rotate(0deg) scale(1);
      }
      to {
        opacity: 0;
        transform: rotate(180deg) scale(0.5);
      }
    }
    
    /* Dark theme adjustments */
    .dark-theme .theme-toggle-btn {
      background: rgba(52, 58, 64, 0.95);
      color: #FFD700;
    }
    
    /* School Year Dropdown Styles */
    .dropdown-menu {
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      border: 1px solid rgba(0, 0, 0, 0.1);
      padding: 8px 0;
    }
    
    .dropdown-item {
      padding: 8px 16px;
      transition: all 0.2s ease;
    }
    
    .dropdown-item:hover {
      background-color: rgba(13, 110, 253, 0.1);
    }
    
    .dropdown-item.active {
      background-color: rgba(13, 110, 253, 0.15);
      color: #0d6efd;
      font-weight: 500;
    }
    
    .dark-theme .dropdown-menu {
      background-color: #2d3238;
      border-color: rgba(255, 255, 255, 0.1);
    }
    
    .dark-theme .dropdown-item {
      color: #e9ecef;
    }
    
    .dark-theme .dropdown-item:hover {
      background-color: rgba(255, 255, 255, 0.1);
      color: #fff;
    }
    
    .dark-theme .dropdown-item.active {
      background-color: rgba(13, 110, 253, 0.25);
      color: #6ea8fe;
    }
    
    .dark-theme .dropdown-divider {
      border-color: rgba(255, 255, 255, 0.1);
    }
  </style>
</head>
<body>
<script>
  // Move dark theme class to body immediately
  if (document.documentElement.classList.contains('dark-theme-loading')) {
    document.body.classList.add('dark-theme');
    document.documentElement.classList.remove('dark-theme-loading');
  }
</script>

<!-- Logout Loading Spinner (Full Screen) -->
<div class="logout-loading-overlay" id="logoutLoadingOverlay">
  <div class="spinner-container">
    <div class="spinner-logout"></div>
    <p class="loading-text-logout">Logging out...</p>
  </div>
</div>

<!-- Submit Loading Spinner (Full Screen) -->
<div class="logout-loading-overlay" id="submitLoadingOverlay">
  <div class="spinner-container">
    <div class="spinner-logout"></div>
    <p class="loading-text-logout">Saving...</p>
  </div>
</div>

<!-- Top Navigation Bar -->
<div class="topbar">
  <button class="mobile-menu-toggle" id="mobileMenuToggle" onclick="toggleMobileMenu()">
    <i class="bi bi-list"></i>
  </button>
  <div class="brand">
    <a href="dashboard.php" style="text-decoration: none; color: white; display: flex; align-items: center; gap: 10px;">
      <img src="../logo.png" alt="School Logo" style="height: 38px; width: auto; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));">
      <span style="font-weight: 700; letter-spacing: 0.5px;">SF10 System</span>
    </a>
    <span class="role-badge <?= $is_admin ? 'role-admin' : 'role-teacher' ?>" style="margin-left: 5px;">
      <?= $is_admin ? 'Admin' : 'Teacher' ?>
    </span>
    <?php if (isset($_SESSION['school_year'])): ?>
      <?php if ($is_admin): ?>
        <!-- Admin: School Year Dropdown -->
        <div class="dropdown">
          <button class="btn btn-light btn-sm dropdown-toggle" type="button" id="schoolYearDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="background: rgba(255,255,255,0.95); color: #333; border: none; padding: 6px 15px; font-weight: 500; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
            <i class="bi bi-calendar-event" style="font-size: 14px;"></i>
            <span>SY <?= htmlspecialchars($_SESSION['school_year']) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="schoolYearDropdown">
            <?php
            $sy_query = $conn->query("SELECT id, year, status FROM school_years ORDER BY year DESC");
            while ($sy = $sy_query->fetch_assoc()):
              $is_current = ($_SESSION['school_year_id'] == $sy['id']);
            ?>
            <li>
              <a class="dropdown-item <?= $is_current ? 'active' : '' ?>" 
                 href="#" 
                 onclick="switchSchoolYear(<?= $sy['id'] ?>, '<?= htmlspecialchars($sy['year']) ?>'); return false;">
                <i class="bi bi-calendar-check<?= $is_current ? '-fill' : '' ?> me-2"></i>
                <?= htmlspecialchars($sy['year']) ?>
                <?php if ($is_current): ?>
                  <i class="bi bi-check-circle-fill text-success float-end"></i>
                <?php endif; ?>
              </a>
            </li>
            <?php endwhile; ?>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item" href="school_years.php">
                <i class="bi bi-gear text-primary me-2"></i> Manage School Years
              </a>
            </li>
          </ul>
        </div>
      <?php else: ?>
        <!-- Teacher: School Year Display (matches admin dropdown style, non-interactive) -->
        <div class="dropdown">
          <button class="btn btn-light btn-sm dropdown-toggle" type="button" id="schoolYearDropdownTeacher" data-bs-toggle="dropdown" aria-expanded="false" style="background: rgba(255,255,255,0.95); color: #333; border: none; padding: 6px 15px; font-weight: 500; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
            <i class="bi bi-calendar-event" style="font-size: 14px;"></i>
            <span>SY <?= htmlspecialchars($_SESSION['school_year']) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="schoolYearDropdownTeacher">
            <li>
              <span class="dropdown-item active" style="pointer-events:none;">
                <i class="bi bi-calendar-check-fill me-2"></i>
                <?= htmlspecialchars($_SESSION['school_year']) ?>
                <i class="bi bi-check-circle-fill text-success float-end"></i>
              </span>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <span class="dropdown-item text-muted" style="font-size:12px; pointer-events:none;">
                <i class="bi bi-info-circle me-2"></i> Contact admin to switch school year
              </span>
            </li>
          </ul>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div style="margin-left: auto; display: flex; align-items: center; gap: 15px;">
    <span style="color: white; font-size: 13px;" class="d-none d-sm-inline">
      <i class="bi bi-person-circle"></i> <?= htmlspecialchars($user['full_name']) ?>
    </span>
    <!-- Theme Toggle Button (Circular) -->
    <button class="theme-toggle-btn" onclick="toggleTheme(); return false;" title="Toggle Theme">
      <i class="bi bi-moon-fill theme-icon-dark"></i>
      <i class="bi bi-sun-fill theme-icon-light" style="display: none;"></i>
    </button>
    <script>
      // Update icons immediately to prevent flicker/animation on load
      (function() {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
          const darkIcon = document.querySelector('.theme-icon-dark');
          const lightIcon = document.querySelector('.theme-icon-light');
          if (darkIcon && lightIcon) {
            darkIcon.style.display = 'none';
            lightIcon.style.display = 'inline';
          }
        }
      })();
    </script>
    <script>
      // Switch school year function for admin
      function switchSchoolYear(schoolYearId, schoolYearName) {
        if (confirm('Switch to school year ' + schoolYearName + '?\\n\\nThis will reload the page with the new school year data.')) {
          // Show loading indicator
          const blocker = document.getElementById('pjaxBlocker');
          if (blocker) blocker.style.display = 'block';
          
          // Make AJAX request to switch school year
          // Detect if we're already in /pages/ directory
          const currentPath = window.location.pathname;
          const switchUrl = currentPath.includes('/pages/') ? 'switch_school_year.php' : '../pages/switch_school_year.php';
          
          fetch(switchUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'school_year_id=' + schoolYearId
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              // Reload the current page to reflect new school year
              window.location.reload();
            } else {
              alert('Error switching school year: ' + (data.message || 'Unknown error'));
              if (blocker) blocker.style.display = 'none';
            }
          })
          .catch(error => {
            alert('Error switching school year. Please try again.');
            console.error('Error:', error);
            if (blocker) blocker.style.display = 'none';
          });
        }
      }
    </script>
    <!-- Logout Button -->
    <a href="../logout.php" class="btn btn-light btn-sm" id="logoutBtn" style="background: rgba(255,255,255,0.95); color: #dc3545; border: none; padding: 6px 15px; font-weight: 500;">
      <i class="bi bi-box-arrow-right"></i> <span class="d-none d-sm-inline">Logout</span>
    </a>
  </div>
</div>

<!-- Mobile Overlay -->
<div class="mobile-overlay" id="mobileOverlay" onclick="toggleMobileMenu()"></div>

<?php
// Check if admin needs setup (no school year)
$needs_setup = $is_admin && empty($_SESSION['school_year_id']);
?>

<!-- Sidebar Navigation -->
<div class="sidebar">
  <div class="nav-section">
    <div style="color: var(--text-muted); font-size: 11px; font-weight: 600; padding: 12px 0 8px; text-transform: uppercase; letter-spacing: 0.5px; text-align: center;">
      NAVIGATION
    </div>
  </div>

  <?php if (!$needs_setup): ?>
  <div class="nav-section">
    <a href="dashboard.php" class="nav-link <?= $current_page == 'dashboard' ? 'active' : '' ?>">
      <i class="bi bi-speedometer2"></i> Dashboard
    </a>
  </div>

  <!-- Records Section (All Users) -->
  <div class="nav-section">
    <a href="students.php" class="nav-link <?= in_array($current_page, ['students', 'records', 'grade_progression', 'view_student', 'edit_student']) ? 'active' : '' ?>">
      <i class="bi bi-person-lines-fill"></i> Students
    </a>
  </div>

  <!-- My Class Section (Teacher Only) -->
  <?php if (!$is_admin): ?>
  <div class="nav-section">
    <a href="my_class.php" class="nav-link <?= in_array($current_page, ['my_class', 'add_student_to_class']) ? 'active' : '' ?>">
      <i class="bi bi-person-workspace"></i> My Class
    </a>
  </div>
  <?php endif; ?>

  <!-- Classes Section (Admin Only) -->
  <?php if ($is_admin): ?>
  <div class="nav-section">
    <a href="classes.php" class="nav-link <?= in_array($current_page, ['classes', 'add_class', 'edit_class']) ? 'active' : '' ?>">
      <i class="bi bi-grid-3x3-gap"></i> Classes
    </a>
  </div>
  <?php endif; ?>

  <!-- Grades Section -->
  <div class="nav-section">
    <?php if ($is_admin): ?>
    <a href="grade_entry.php" class="nav-link <?= in_array($current_page, ['grade_entry', 'enter_grades']) ? 'active' : '' ?>">
      <i class="bi bi-pencil-square"></i> Grade Entry
    </a>
    <?php else: ?>
    <a href="input_grades.php" class="nav-link <?= in_array($current_page, ['input_grades', 'input_grades_form', 'enter_grades']) ? 'active' : '' ?>">
      <i class="bi bi-pencil-square"></i> Grade Entry
    </a>
    <?php endif; ?>
  </div>

  <!-- Manage School Subjects (Admin Only) -->
  <?php if ($is_admin): ?>
  <div class="nav-section">
    <a href="manage_subjects.php" class="nav-link <?= $current_page == 'manage_subjects' ? 'active' : '' ?>">
      <i class="bi bi-book"></i> School Subjects
    </a>
  </div>
  <?php endif; ?>

  <!-- Manage Quarter Locks (Admin Only) -->
  <?php if ($is_admin): ?>
  <div class="nav-section">
    <a href="manage_quarter_locks.php" class="nav-link <?= $current_page == 'manage_quarter_locks' ? 'active' : '' ?>">
      <i class="bi bi-lock-fill"></i> Quarter Locks
    </a>
  </div>
  <?php endif; ?>

  <!-- SF10 Generate Section (Admin Only) -->
  <?php if ($is_admin): ?>
  <div class="nav-section">
    <a href="sf10_form.php" class="nav-link <?= $current_page == 'sf10_form' ? 'active' : '' ?>">
      <i class="bi bi-file-earmark-pdf"></i> Generate SF10
    </a>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- User Management Section (Admin Only) - Always visible for admin -->
  <?php if ($is_admin): ?>
  <div class="nav-section">
    <a href="users.php" class="nav-link <?= $current_page == 'users' ? 'active' : '' ?>">
      <i class="bi bi-person-gear"></i> Users
    </a>
  </div>
  <?php endif; ?>

  <!-- School Year Management (Admin Only) - Always visible for admin -->
  <?php if ($is_admin): ?>
  <div class="nav-section">
    <a href="school_years.php" class="nav-link <?= $current_page == 'school_years' ? 'active' : '' ?>">
      <i class="bi bi-calendar3"></i> School Years
    </a>
  </div>
  <?php endif; ?>

  <?php if (!$needs_setup): ?>
  <!-- Activity Logs Section (Admin Only) -->
  <?php if ($is_admin): ?>
  <div class="nav-section">
    <a href="logs.php" class="nav-link <?= $current_page == 'logs' ? 'active' : '' ?>">
      <i class="bi bi-clock-history"></i> Activity Logs
    </a>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Reports (Admin + Teacher) -->
  <div class="nav-section">
    <a href="reports.php" class="nav-link <?= $current_page == 'reports' ? 'active' : '' ?>">
      <i class="bi bi-award-fill"></i> Reports
    </a>
  </div>

  <!-- Backup / Import-Export (Admin Only) -->
  <?php if ($is_admin): ?>
  <div class="nav-section">
    <a href="backup.php" class="nav-link <?= $current_page == 'backup' ? 'active' : '' ?>">
      <i class="bi bi-cloud-arrow-down-fill"></i> Backup / Restore
    </a>
  </div>
  <?php endif; ?>

  <!-- Settings / 2FA -->
  <div class="nav-section">
    <a href="setup_2fa.php" class="nav-link <?= $current_page == 'setup_2fa' ? 'active' : '' ?>">
      <i class="bi bi-shield-lock"></i> Security / 2FA
    </a>
  </div>

</div>

<!-- Form Submission Loading (Main Content Only) -->
<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner-container">
    <div class="spinner"></div>
    <p class="loading-text" id="loadingText">Processing...</p>
  </div>
</div>


<!-- Main Content Wrapper -->
<div class="main-wrapper" id="mainContent">

<script>
// PJAX with blocker overlay + debounce to prevent ghost touches on fast clicks
(function(){
  const supportsFetch = !!window.fetch && !!window.history && !!window.DOMParser;
  if (!supportsFetch) return;

  // create blocker overlay (initially hidden) with spinner to prevent ghost clicks
  const blocker = document.createElement('div');
  blocker.id = 'pjaxBlocker';
  blocker.style.position = 'fixed';
  blocker.style.left = '0'; blocker.style.top = '0';
  blocker.style.width = '100%'; blocker.style.height = '100%';
  blocker.style.zIndex = '99999';
  blocker.style.background = 'rgba(0,0,0,0.15)';
  blocker.style.display = 'none';
  blocker.style.pointerEvents = 'auto';
  blocker.style.backdropFilter = 'blur(2px)';
  blocker.innerHTML = '<div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);">
    <div style="width:40px;height:40px;border:4px solid rgba(255,255,255,0.6);border-top-color:#fff;border-radius:50%;animation:pjaxSpin 0.8s linear infinite"></div>
</div>';
  const spinStyle = document.createElement('style');
  spinStyle.textContent = '@keyframes pjaxSpin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
  document.head.appendChild(spinStyle);
  document.body.appendChild(blocker);

  let isLoading = false;
  const pjaxCache = new Map(); // simple in-memory cache: url -> { html, title, text }
  const inFlight = new Map(); // url -> Promise for in-flight fetch

  function executeScripts(container) {
    const scripts = Array.from(container.querySelectorAll('script'));
    scripts.forEach(old => {
      const script = document.createElement('script');
      if (old.src) { script.src = old.src; script.async = false; }
      else { script.textContent = old.textContent; }
      old.parentNode.replaceChild(script, old);
    });
  }

  async function loadUrl(url, push=true) {
    // If we're already loading a navigation, ignore new requests
    if (isLoading) return;

    const main = document.getElementById('mainContent');

    // If cached, render immediately to avoid network delay
    if (pjaxCache.has(url)) {
      const cached = pjaxCache.get(url);
      if (main) {
        main.innerHTML = cached.html;
        if (cached.title) document.title = cached.title;
        executeScripts(main);
        document.querySelectorAll('.sidebar .nav-link').forEach(a => a.classList.remove('active'));
        const path = new URL(url, location.origin).pathname.split('/').pop();
        const matching = document.querySelector('.sidebar .nav-link[href="' + path + '"]');
        if (matching) matching.classList.add('active');
        window.scrollTo(0,0);
      }
      if (push) history.pushState({pjax: true, url: url}, '', url);

      // Background refresh: fetch latest and update cache/content if changed
      (async () => {
        try {
          const res = await fetch(url, {credentials: 'same-origin'});
          if (!res.ok) return;
          const text = await res.text();
          if (text !== cached.text) {
            const parser = new DOMParser();
            const doc = parser.parseFromString(text, 'text/html');
            const newMain = doc.getElementById('mainContent');
            const newTitle = doc.querySelector('title');
            pjaxCache.set(url, { html: newMain ? newMain.innerHTML : text, title: newTitle ? newTitle.textContent : document.title, text });
            if (main) {
              main.innerHTML = newMain ? newMain.innerHTML : text;
              if (newTitle) document.title = newTitle.textContent;
              executeScripts(main);
            }
          }
        } catch (e) { /* ignore background refresh errors */ }
      })();

      return;
    }

    // Not cached: perform full fetch and show blocker
    isLoading = true;
    blocker.style.display = 'block';
    try {
      document.documentElement.classList.add('loading');
      if (main) main.classList.add('loading');
      const res = await fetch(url, {credentials: 'same-origin'});
      if (!res.ok) throw new Error('Network response not ok');
      const text = await res.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(text, 'text/html');
      const newMain = doc.getElementById('mainContent');
      if (!newMain) { window.location.href = url; return; }
      main.innerHTML = newMain.innerHTML;
      const newTitle = doc.querySelector('title'); if (newTitle) document.title = newTitle.textContent;
      executeScripts(main);
      document.querySelectorAll('.sidebar .nav-link').forEach(a => a.classList.remove('active'));
      const path = new URL(url, location.origin).pathname.split('/').pop();
      const matching = document.querySelector('.sidebar .nav-link[href="' + path + '"]');
      if (matching) matching.classList.add('active');
      window.scrollTo(0,0);

      // Cache the fetched result for future instant render
      pjaxCache.set(url, { html: newMain.innerHTML, title: newTitle ? newTitle.textContent : document.title, text });

      if (push) history.pushState({pjax: true, url: url}, '', url);
    } catch (e) {
      console.error('PJAX load failed, falling back', e);
      window.location.href = url;
    } finally {
      isLoading = false;
      blocker.style.display = 'none';
      document.documentElement.classList.remove('loading');
      if (main) main.classList.remove('loading');
    }
  }

  // Prefetch on hover/touchstart to reduce delay
  document.addEventListener('pointerenter', function(e){
    const a = e.target.closest && e.target.closest('.sidebar a.nav-link');
    if (!a) return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('http') || a.target === '_blank' || a.hasAttribute('data-no-pjax')) return;
    if (pjaxCache.has(href) || inFlight.has(href)) return;
    const p = fetch(href, {credentials: 'same-origin'}).then(r => r.ok ? r.text() : null).then(text => {
      if (!text) return null;
      try {
        const parser = new DOMParser(); const doc = parser.parseFromString(text, 'text/html');
        const newMain = doc.getElementById('mainContent');
        const title = doc.querySelector('title');
        const html = newMain ? newMain.innerHTML : text;
        pjaxCache.set(href, { html, title: title ? title.textContent : document.title, text });
        return text;
      } catch (err) { return null; }
    }).catch(()=>null).finally(()=> inFlight.delete(href));
    inFlight.set(href, p);
  }, {passive:true});

  // Intercept clicks on nav-link inside sidebar using delegation
  document.addEventListener('click', function(e){
    const a = e.target.closest && e.target.closest('.sidebar a.nav-link');
    if (!a) return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('http') || a.target === '_blank' || a.hasAttribute('data-no-pjax')) return;
    e.preventDefault();
    e.stopPropagation();
    // If cached, render instantly
    if (pjaxCache.has(href)) { loadUrl(href, true); return; }
    // If in-flight, wait for it and then render
    if (inFlight.has(href)) {
      inFlight.get(href).then(() => loadUrl(href, true));
      return;
    }
    loadUrl(href, true);
  }, true);

  window.addEventListener('popstate', function(e){ if (e.state && e.state.pjax) { loadUrl(location.pathname + location.search, false); } });
})();
</script>

