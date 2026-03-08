  </div><!-- End main-wrapper -->

  <footer class="text-center py-3 small text-muted">
    <span>&copy; <?= date("Y") ?> SF10 Learner Record Management System | v1.7.3</span>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // ==================== LOADING SPINNER (FORM SUBMISSIONS) ====================
    const loadingOverlay = document.getElementById('loadingOverlay');
    const mainContent = document.getElementById('mainContent');
    let loadingStartTime = null;
    const MINIMUM_LOADING_TIME = 1800; // 1.8 seconds in milliseconds

    // Function to show loading (for forms)
    function showLoading() {
      loadingStartTime = Date.now();
      loadingOverlay.classList.add('active');
      mainContent.classList.add('loading');
    }

    // Function to hide loading with minimum display time
    function hideLoading() {
      if (loadingStartTime) {
        const elapsedTime = Date.now() - loadingStartTime;
        const remainingTime = Math.max(0, MINIMUM_LOADING_TIME - elapsedTime);
        
        setTimeout(() => {
          loadingOverlay.classList.remove('active');
          mainContent.classList.remove('loading');
          loadingStartTime = null;
        }, remainingTime);
      } else {
        loadingOverlay.classList.remove('active');
        mainContent.classList.remove('loading');
      }
    }

    // Show loading on form submissions ONLY (not on navigation)
    document.querySelectorAll('form').forEach(form => {
      form.addEventListener('submit', function(e) {
        // Skip if form has data-no-loading attribute
        if (this.hasAttribute('data-no-loading')) {
          return;
        }
        // Show loading spinner
        showLoading();
      });
    });

    // ==================== LOGOUT LOADING SPINNER (FULL SCREEN) ====================
    const logoutLoadingOverlay = document.getElementById('logoutLoadingOverlay');
    
    // Show logout loading on logout button click
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function(e) {
        e.preventDefault();
        // Show full screen logout loading
        logoutLoadingOverlay.classList.add('active');
        // Navigate after showing spinner for 1.8 seconds
        setTimeout(() => {
          window.location.href = this.href;
        }, 1800);
      });
    }

    // ==================== SUBMIT LOADING SPINNER (FULL SCREEN) ====================
    const submitLoadingOverlay = document.getElementById('submitLoadingOverlay');
    
    // Show submit loading on all form submissions
    document.addEventListener('submit', function(e) {
      const form = e.target;
      
      // Skip if form has data-no-loading attribute or is already submitting
      if (form.hasAttribute('data-no-loading') || form.classList.contains('submitting')) {
        return;
      }
      
      // Mark form as submitting to prevent double submission
      form.classList.add('submitting');
      
      // Show loading spinner
      submitLoadingOverlay.classList.add('active');
      
      // Allow form to submit naturally
    });

    // ==================== SIDEBAR DROPDOWN TOGGLE ====================
    function toggleGroup(element) {
      const items = element.nextElementSibling;
      const groupId = element.textContent.trim();
      const isCurrentlyCollapsed = items.classList.contains('collapsed');
      
      // Toggle the clicked group
      if (isCurrentlyCollapsed) {
        items.classList.remove('collapsed');
        element.classList.remove('collapsed');
        localStorage.setItem('sidebar-' + groupId, 'open');
      } else {
        items.classList.add('collapsed');
        element.classList.add('collapsed');
        localStorage.setItem('sidebar-' + groupId, 'closed');
      }
    }

    // ==================== MOBILE MENU TOGGLE ====================
    function toggleMobileMenu() {
      const sidebar = document.querySelector('.sidebar');
      const overlay = document.getElementById('mobileOverlay');
      
      sidebar.classList.toggle('show');
      overlay.classList.toggle('show');
      
      // Prevent body scroll when menu is open
      if (sidebar.classList.contains('show')) {
        document.body.style.overflow = 'hidden';
      } else {
        document.body.style.overflow = '';
      }
    }
    
    // Close mobile menu when clicking on a nav link
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
      link.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
          toggleMobileMenu();
        }
      });
    });

    // ==================== THEME TOGGLE ====================
    function toggleTheme() {
      const body = document.body;
      const btn = document.querySelector('.theme-toggle-btn');
      const isDark = body.classList.contains('dark-theme');
      
      // Add toggling class to trigger animation
      if (btn) {
        btn.classList.add('is-toggling');
        // Remove it after animation finishes (400ms)
        setTimeout(() => btn.classList.remove('is-toggling'), 450);
      }
      
      if (isDark) {
        body.classList.remove('dark-theme');
        localStorage.setItem('theme', 'light');
        updateThemeUI(false);
      } else {
        body.classList.add('dark-theme');
        localStorage.setItem('theme', 'dark');
        updateThemeUI(true);
      }
    }

    function updateThemeUI(isDark) {
      const darkIcon = document.querySelector('.theme-icon-dark');
      const lightIcon = document.querySelector('.theme-icon-light');
      const themeText = document.querySelector('.theme-text');
      
      if (isDark) {
        darkIcon.style.display = 'none';
        lightIcon.style.display = 'inline';
        themeText.textContent = 'Light Theme';
      } else {
        darkIcon.style.display = 'inline';
        lightIcon.style.display = 'none';
        themeText.textContent = 'Dark Theme';
      }
    }

    // Load theme on page load
    document.addEventListener('DOMContentLoaded', function() {
      const savedTheme = localStorage.getItem('theme');
      if (savedTheme === 'dark') {
        document.body.classList.add('dark-theme');
        // updateThemeUI(true); // Already handled in header.php for faster rendering
      }
    });
  </script>
</body>
</html>
