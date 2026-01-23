<?php
require_once '../includes/db.php';
include '../templates/header.php';

// Admin only access
if (!$is_admin) {
    header("Location: dashboard.php");
    exit();
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filter options
$filter_action = isset($_GET['action']) ? $_GET['action'] : 'all';
$filter_user = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query
$where_conditions = [];
$params = [];
$param_types = '';

if ($filter_action !== 'all') {
    $where_conditions[] = "cl.action = ?";
    $params[] = $filter_action;
    $param_types .= 's';
}

if ($filter_user > 0) {
    $where_conditions[] = "cl.user_id = ?";
    $params[] = $filter_user;
    $param_types .= 'i';
}

if ($date_from) {
    $where_conditions[] = "DATE(cl.timestamp) >= ?";
    $params[] = $date_from;
    $param_types .= 's';
}

if ($date_to) {
    $where_conditions[] = "DATE(cl.timestamp) <= ?";
    $params[] = $date_to;
    $param_types .= 's';
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_query = "SELECT COUNT(*) as total FROM change_logs cl $where_sql";
if (!empty($params)) {
    $stmt = $conn->prepare($count_query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $total_logs = $stmt->get_result()->fetch_assoc()['total'];
} else {
    $total_logs = $conn->query($count_query)->fetch_assoc()['total'];
}

$total_pages = ceil($total_logs / $per_page);

// Get logs with user information
$logs_query = "SELECT cl.*, u.username, u.full_name, u.role
               FROM change_logs cl
               LEFT JOIN users u ON cl.user_id = u.id
               $where_sql
               ORDER BY cl.timestamp DESC
               LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$param_types .= 'ii';

$stmt = $conn->prepare($logs_query);
if (!empty($where_conditions)) {
    $stmt->bind_param($param_types, ...$params);
} else {
    $stmt->bind_param('ii', $per_page, $offset);
}
$stmt->execute();
$logs = $stmt->get_result();

// Get all users for filter
$users = $conn->query("SELECT id, full_name FROM users ORDER BY full_name");

// Get action types
$actions = $conn->query("SELECT DISTINCT action FROM change_logs ORDER BY action");

function getActionIcon($action) {
    $icons = [
        'INSERT' => 'bi-plus-circle text-success',
        'UPDATE' => 'bi-pencil-square text-warning',
        'DELETE' => 'bi-trash text-danger',
        'LOGIN' => 'bi-box-arrow-in-right text-info',
        'LOGOUT' => 'bi-box-arrow-right text-secondary',
        'CREATE' => 'bi-file-plus text-success',
        'GRADE_ENTRY' => 'bi-journal-check text-primary',
    ];
    return $icons[$action] ?? 'bi-circle text-muted';
}

function timeAgo($datetime) {
    if (!$datetime) return 'N/A';
    
    // Set Manila timezone
    date_default_timezone_set('Asia/Manila');
    
    $timestamp = strtotime($datetime);
    $now = time();
    $diff = $now - $timestamp;
    
    if ($diff < 60) {
        return $diff . ' second' . ($diff != 1 ? 's' : '') . ' ago';
    }
    
    if ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins != 1 ? 's' : '') . ' ago';
    }
    
    if ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';
    }
    
    if ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days != 1 ? 's' : '') . ' ago';
    }
    
    return date('M d, Y g:i A', $timestamp);
}
?>

<style>
html, body {
    height: 100vh;
    margin: 0;
    padding: 0;
    overflow: hidden !important;
}
body {
    display: flex;
    flex-direction: column;
    height: 100vh;
}
.main-wrapper {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}
footer {
    flex-shrink: 0;
}

.activity-card {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
    margin-bottom: 0;
}

.activity-card .card-header {
    flex-shrink: 0;
    position: sticky;
    top: 0;
    z-index: 100;
    background: var(--card-bg);
    border-bottom: 1px solid var(--border-color);
}

.activity-card .card-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto !important;
    overflow-x: auto;
    padding: 0;
    -webkit-overflow-scrolling: touch;
}

.logs-card-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto !important;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* Desktop only - enable horizontal scroll for wide content */
@media (min-width: 769px) {
    .activity-card .card-body {
        overflow-x: auto;
    }
}

/* Mobile - no horizontal scroll, stack content */
@media (max-width: 768px) {
    .activity-card .card-body {
        overflow-x: hidden;
        padding: 10px;
    }
}

/* Hide scrollbars for body/html only */
html::-webkit-scrollbar, body::-webkit-scrollbar {
    display: none !important;
}
html, body {
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}

.log-row {
    border-bottom: 1px solid var(--border-color);
    padding: 10px 15px;
    transition: background-color 0.2s;
}

.log-row .row {
    margin: 0;
}

.log-row .col-auto,
.log-row [class*="col-"] {
    padding-left: 8px;
    padding-right: 8px;
}

/* Desktop - table-like layout with horizontal scroll */
@media (min-width: 769px) {
    .log-row {
        min-width: 1000px;
    }
}

/* Mobile - card layout */
@media (max-width: 768px) {
    .log-row {
        padding: 12px;
        margin-bottom: 10px;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
    }
    
    .log-row .row {
        flex-direction: column;
        gap: 8px;
    }
    
    .log-row [class*="col-"] {
        width: 100% !important;
        padding: 4px 0 !important;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .log-row [class*="col-"]::before {
        content: attr(data-label);
        font-weight: 600;
        min-width: 80px;
        font-size: 12px;
        color: var(--text-muted);
    }
}

.log-row:hover {
    background-color: rgba(0, 0, 0, 0.02);
}

.log-row:last-child {
    border-bottom: none;
}

body.dark-theme .log-row:hover {
    background-color: rgba(255, 255, 255, 0.05);
}

.pagination-container {
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
    z-index: 1000;
    background: var(--card-bg);
    border-top: 2px solid var(--border-color);
    padding: 12px 15px;
    box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
}

body.dark-theme .pagination-container {
    background: #242526;
    border-top-color: #3a3b3c;
    box-shadow: 0 -2px 8px rgba(0,0,0,0.3);
}

/* Mobile pagination adjustments */
@media (max-width: 768px) {
    .pagination-container {
        padding: 10px;
    }
    
    .pagination-container .d-flex {
        flex-direction: column;
        gap: 10px;
    }
    
    .pagination-container nav {
        width: 100%;
        overflow-x: auto;
    }
    
    .pagination-container .pagination {
        flex-wrap: nowrap;
        justify-content: center;
    }
    
    .pagination-container .page-item .page-link {
        padding: 6px 10px;
        font-size: 14px;
    }
    
    .pagination-container .text-muted {
        text-align: center;
        font-size: 13px;
    }
    
    /* Hide page jump on very small screens */
    .pagination-container form {
        display: none;
    }
}

/* Compact Filters */
.compact-filters .card-header {
    padding: 10px 15px;
    font-size: 14px;
}

.compact-filters .card-body {
    padding: 15px;
}

.compact-filters .form-label {
    font-size: 13px;
    margin-bottom: 4px;
}

.compact-filters .form-select,
.compact-filters .form-control {
    padding: 6px 10px;
    font-size: 13px;
}

/* Compact Statistics */
.compact-stats {
    padding: 8px 15px;
    margin-bottom: 12px;
    font-size: 14px;
}

.activity-card .card-header {
    padding: 10px 15px;
    font-size: 15px;
    font-weight: 600;
}

/* Mobile optimizations */
@media (max-width: 768px) {
    .page-header h2 {
        font-size: 1.5rem;
    }
    
    .page-header .subtitle {
        font-size: 0.85rem;
    }
    
    .compact-stats {
        padding: 8px 12px;
        font-size: 13px;
    }
    
    .d-flex.justify-content-between.align-items-start {
        flex-direction: column;
        gap: 10px;
    }
    
    .d-flex.justify-content-between.align-items-start .btn {
        width: 100%;
    }
}
</style>

<div class="d-flex justify-content-between align-items-start mb-2">
    <div class="page-header mb-0">
        <h2><i class="bi bi-clock-history"></i> Activity Logs</h2>
        <p class="subtitle mb-0">Complete system activity history with user information</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#filterModal">
        <i class="bi bi-funnel"></i> Filters
    </button>
</div>

<!-- Statistics -->
<div class="alert alert-info compact-stats mb-2">
    <i class="bi bi-info-circle"></i> 
    Showing <strong><?= number_format($logs->num_rows) ?></strong> of <strong><?= number_format($total_logs) ?></strong> total activity logs
</div>

<!-- Activity Logs Table -->
<div class="card activity-card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> Activity History
    </div>
    <div class="card-body p-0 logs-card-body">
        <?php if ($logs->num_rows > 0): ?>
            <?php while($log = $logs->fetch_assoc()): ?>
            <div class="log-row">
                <div class="row align-items-center">
                    <div class="col-auto" data-label="ID:">
                        <span class="badge bg-secondary">#<?= $log['id'] ?></span>
                    </div>
                    <div class="col-md-2" data-label="Date:">
                        <div>
                            <div><strong><?= date('M d, Y', strtotime($log['timestamp'])) ?></strong></div>
                            <small class="text-muted"><?= date('g:i:s A', strtotime($log['timestamp'])) ?></small><br>
                            <small class="text-info"><?= timeAgo($log['timestamp']) ?></small>
                        </div>
                    </div>
                    <div class="col-md-2" data-label="User:">
                        <div>
                            <div><strong><?= htmlspecialchars($log['full_name'] ?? 'System') ?></strong></div>
                            <small class="text-muted">@<?= htmlspecialchars($log['username'] ?? 'system') ?></small>
                        </div>
                    </div>
                    <div class="col-md-1" data-label="Role:">
                        <div>
                            <?php if ($log['role']): ?>
                            <span class="badge <?= $log['role'] === 'admin' ? 'bg-warning text-dark' : 'bg-info' ?>">
                                <?= strtoupper($log['role']) ?>
                            </span>
                            <?php else: ?>
                            <span class="badge bg-secondary">SYSTEM</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-2" data-label="Action:">
                        <div>
                            <i class="bi <?= getActionIcon($log['action']) ?>"></i>
                            <strong><?= htmlspecialchars($log['action']) ?></strong>
                        </div>
                    </div>
                    <div class="col-md-1" data-label="Table:">
                        <code><?= htmlspecialchars($log['table_name']) ?></code>
                    </div>
                    <div class="col-md-1" data-label="Record:">
                        <div>
                            <?php if ($log['record_id']): ?>
                            <span class="badge bg-light text-dark"><?= $log['record_id'] ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-2" data-label="Details:">
                        <small><?= htmlspecialchars($log['details'] ?? 'No additional details') ?></small>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.3;"></i>
                <p class="mt-2">No activity logs found</p>
                <small>Logs will appear here as users perform actions in the system</small>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination-container">
            <div class="d-flex justify-content-between align-items-center">
            <div class="text-muted">
                Page <?= $page ?> of <?= $total_pages ?>
            </div>
            
            <nav aria-label="Page navigation">
                <ul class="pagination mb-0">
                    <!-- First Page -->
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=1&action=<?= $filter_action ?>&user_id=<?= $filter_user ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" title="First Page">
                            <i class="bi bi-chevron-double-left"></i>
                        </a>
                    </li>
                    
                    <!-- Previous Page -->
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&action=<?= $filter_action ?>&user_id=<?= $filter_user ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">Previous</a>
                    </li>
                    
                    <!-- Page Numbers (limited to 5) -->
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $start_page + 4);
                    $start_page = max(1, $end_page - 4);
                    
                    for($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&action=<?= $filter_action ?>&user_id=<?= $filter_user ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    
                    <!-- Next Page -->
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&action=<?= $filter_action ?>&user_id=<?= $filter_user ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">Next</a>
                    </li>
                    
                    <!-- Last Page -->
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $total_pages ?>&action=<?= $filter_action ?>&user_id=<?= $filter_user ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" title="Last Page">
                            <i class="bi bi-chevron-double-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <!-- Custom Page Jump -->
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">Go to:</span>
                <form method="GET" class="d-flex gap-2" onsubmit="return validatePageJump()">
                    <input type="hidden" name="action" value="<?= $filter_action ?>">
                    <input type="hidden" name="user_id" value="<?= $filter_user ?>">
                    <input type="hidden" name="date_from" value="<?= $date_from ?>">
                    <input type="hidden" name="date_to" value="<?= $date_to ?>">
                    <input type="number" 
                           name="page" 
                           id="pageJump"
                           class="form-control form-control-sm" 
                           style="width: 70px;" 
                           min="1" 
                           max="<?= $total_pages ?>"
                           placeholder="<?= $page ?>"
                           title="Enter page number (1-<?= $total_pages ?>)">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-arrow-right"></i>
                    </button>
                </form>
            </div>
        </div>
        
        <script>
        function validatePageJump() {
            const input = document.getElementById('pageJump');
            const value = parseInt(input.value);
            const max = parseInt(input.max);
            
            if (!value || value < 1 || value > max) {
                alert('Please enter a valid page number between 1 and ' + max);
                return false;
            }
            return true;
        }
        </script>
        </div>
        <?php endif; ?>
</div>

<!-- Filter Modal -->
<div class="modal fade" id="filterModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="GET">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-funnel"></i> Filter Activity Logs</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Action Type</label>
              <select name="action" class="form-select">
                <option value="all" <?= $filter_action === 'all' ? 'selected' : '' ?>>All Actions</option>
                <?php 
                $actions->data_seek(0); // Reset pointer
                while($action = $actions->fetch_assoc()): 
                ?>
                <option value="<?= htmlspecialchars($action['action']) ?>" <?= $filter_action === $action['action'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($action['action']) ?>
                </option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">User</label>
              <select name="user_id" class="form-select">
                <option value="0">All Users</option>
                <?php 
                $users->data_seek(0); // Reset pointer
                while($u = $users->fetch_assoc()): 
                ?>
                <option value="<?= $u['id'] ?>" <?= $filter_user == $u['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['full_name']) ?>
                </option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Date From</label>
              <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Date To</label>
              <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="logs.php" class="btn btn-secondary">Clear Filters</a>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-search"></i> Apply Filters
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include '../templates/footer.php'; ?>
