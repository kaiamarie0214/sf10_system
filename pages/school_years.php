<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/logger.php";

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$isSetup = isset($_GET['setup']) && $_GET['setup'] == '1';
$success = '';
$error = '';

// Display session success message
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

// Flash message from backup clear
if (isset($_SESSION['flash_msg'])) {
    $success = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
    unset($_SESSION['flash_type']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        $id = $_POST['id'];
        $year = trim($_POST['year']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $status = $_POST['status'];
        
        // Validate dates
        if (strtotime($start_date) >= strtotime($end_date)) {
            $error = "End date must be after start date.";
        } else {
            // If setting as active, deactivate others
            if ($is_active) {
                $conn->query("UPDATE school_years SET is_active = 0");
            }
            
            $stmt = $conn->prepare("UPDATE school_years SET year = ?, is_active = ?, status = ?, start_date = ?, end_date = ? WHERE id = ?");
            $stmt->bind_param("sisssi", $year, $is_active, $status, $start_date, $end_date, $id);
            
            if ($stmt->execute()) {
                $success = "School year updated successfully!";
                logActivity($conn, $_SESSION['user_id'], 'UPDATE', 'school_years', $id, "Updated school year: $year");
            } else {
                $error = "Failed to update school year: " . $conn->error;
            }
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'];
        
        // Check if school year has any associated data
        $check = $conn->query("SELECT COUNT(*) as count FROM classes_per_year WHERE school_year_id = $id")->fetch_assoc();
        
        if ($check['count'] > 0) {
            $error = "Cannot delete school year with existing class data.";
        } else {
            $stmt = $conn->prepare("DELETE FROM school_years WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $success = "School year deleted successfully!";
                logActivity($conn, $_SESSION['user_id'], 'DELETE', 'school_years', $id, "Deleted school year");
                
                // Check if any school years remain
                $remaining_check = $conn->query("SELECT COUNT(*) as count FROM school_years")->fetch_assoc();
                
                if ($remaining_check['count'] == 0) {
                    // No school years left, clear session
                    $_SESSION['school_year_id'] = null;
                    $_SESSION['school_year'] = null;
                    $_SESSION['school_year_status'] = null;
                }
            } else {
                $error = "Failed to delete school year: " . $conn->error;
            }
        }
    }
}

// --- FILTERS & PAGINATION ---
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'year-desc';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$items_per_page = 20;

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = "";

if (!empty($search)) {
    $where_conditions[] = "(year LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $types .= "s";
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Build ORDER BY clause
$order_sql = "year DESC";
switch ($sort) {
    case 'year-asc': $order_sql = "year ASC"; break;
    case 'year-desc': $order_sql = "year DESC"; break;
    case 'status-active': $order_sql = "status ASC, year DESC"; break;
    case 'status-inactive': $order_sql = "status DESC, year DESC"; break;
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM school_years $where_sql";
$stmt_count = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_sy = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_sy / $items_per_page);
$page = max(1, min($total_pages, $page));
$offset = ($page - 1) * $items_per_page;

// Get school years with pagination
$sy_query = "SELECT * FROM school_years $where_sql ORDER BY $order_sql LIMIT ? OFFSET ?";
$stmt_sy = $conn->prepare($sy_query);
$final_types = $types . "ii";
$final_params = array_merge($params, [$items_per_page, $offset]);
$stmt_sy->bind_param($final_types, ...$final_params);
$stmt_sy->execute();
$school_years = $stmt_sy->get_result()->fetch_all(MYSQLI_ASSOC);

// Set current page for sidebar navigation
$_SERVER['PHP_SELF'] = 'school_years.php';

$page_title = $isSetup ? "School Year Setup Required" : "School Years Management";
include "../templates/header.php";
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-calendar3"></i> <?= $page_title ?></h2>
        <p class="subtitle">Manage academic school years and their settings</p>
    </div>
    <a href="add_school_year.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Add School Year
    </a>
</div>

<?php if ($isSetup): ?>
    <div class='alert alert-info alert-dismissible fade show' role='alert' id='infoAlert'>
        <i class="bi bi-info-circle"></i> 
        <strong>Welcome!</strong> Please create a school year to continue using the system.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class='alert alert-success alert-dismissible fade show' role='alert' id='successAlert'>
        <i class="bi bi-check-circle"></i> <?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class='alert alert-danger alert-dismissible fade show' role='alert' id='errorAlert'>
        <i class="bi bi-exclamation-circle"></i> <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<script>
// Auto-dismiss alerts with fade out
document.addEventListener('DOMContentLoaded', function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    if (successAlert) {
        setTimeout(() => {
            successAlert.style.transition = 'opacity 0.5s ease-out';
            successAlert.style.opacity = '0';
            setTimeout(() => successAlert.remove(), 500);
        }, 5000);
    }
    
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.style.transition = 'opacity 0.5s ease-out';
            errorAlert.style.opacity = '0';
            setTimeout(() => errorAlert.remove(), 500);
        }, 7000);
    }
});
</script>

<style>
  /* Flexbox layout for scrollable table */
  html, body {
    overflow: hidden !important;
    height: 100vh;
    margin: 0;
    padding: 0;
  }
  body {
    display: flex;
    flex-direction: column;
  }
  #mainContent {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    overflow: hidden;
    padding-bottom: 0 !important;
  }
  footer {
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
    z-index: 100;
  }
  #mainContent > * {
    flex-shrink: 0;
  }
  #mainContent .card:last-of-type {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
    margin-bottom: 0 !important;
  }
  #mainContent .card:last-of-type .card-body {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
    padding: 0 !important;
  }
  #mainContent .card:last-of-type .table-responsive {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto !important;
    overflow-x: auto;
    margin-bottom: 0;
    -webkit-overflow-scrolling: touch;
  }
  #schoolYearsTable {
    font-size: 13px;
    width: 100%;
    margin-bottom: 0;
  }
  #schoolYearsTable th, #schoolYearsTable td {
    padding: 8px;
  }
  #schoolYearsTable thead {
    position: sticky;
    top: 0;
    z-index: 10;
    background: var(--card-bg, #fff);
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
  
  /* Empty state */
  .empty-state {
    padding: 3rem 1rem;
  }
</style>

<!-- School Years List -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-list-ul"></i> School Years List
            <span class="badge bg-secondary ms-2" id="syCount"><?= number_format($total_sy) ?></span>
        </span>
        <div class="d-flex gap-2">
            <select id="sortSY" class="form-select form-select-sm" style="width: auto;">
                <option value="year-desc" <?= $sort === 'year-desc' ? 'selected' : '' ?>>Year (Newest First)</option>
                <option value="year-asc" <?= $sort === 'year-asc' ? 'selected' : '' ?>>Year (Oldest First)</option>
                <option value="status-active" <?= $sort === 'status-active' ? 'selected' : '' ?>>Active Status First</option>
                <option value="status-inactive" <?= $sort === 'status-inactive' ? 'selected' : '' ?>>Inactive Status First</option>
            </select>
            <div style="position: relative; width: 250px;">
                <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
                <input type="text" class="form-control form-control-sm" id="sySearch" placeholder="Search by year..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 35px; padding-right: 30px;">
                <button type="button" id="clearSYSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; <?= empty($search) ? 'display: none;' : 'display: block;' ?>">
                    <i class="bi bi-x-circle-fill" style="color: #6c757d;"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($school_years) && empty($search)): ?>
        <div class="text-center empty-state">
            <i class="bi bi-calendar-x" style="font-size: 3rem; color: #ccc;"></i>
            <p class="text-muted mt-3">No school years found. Create one to get started.</p>
        </div>
        <?php elseif (empty($school_years)): ?>
        <div class="text-center empty-state">
            <i class="bi bi-search" style="font-size: 3rem; color: #ccc;"></i>
            <p class="text-muted mt-3">No school years found matching your search.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="schoolYearsTable">
                <thead>
                    <tr>
                        <th>School Year</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Status</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($school_years as $sy): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sy['year']) ?></strong></td>
                            <td><?= date('M d, Y', strtotime($sy['start_date'])) ?></td>
                            <td><?= date('M d, Y', strtotime($sy['end_date'])) ?></td>
                            <td>
                                <?php if ($sy['status'] === 'active'): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sy['is_active'] == 1): ?>
                                    <span class="badge bg-primary">Current</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="dropdown" style="position: static;">
                                    <button class="btn btn-sm btn-secondary dropdown-toggle" type="button" id="actionsDropdown<?= $sy['id'] ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="actionsDropdown<?= $sy['id'] ?>">
                                        <li>
                                            <a class="dropdown-item" href="edit_school_year.php?id=<?= $sy['id'] ?>">
                                                <i class="bi bi-pencil text-warning me-2"></i>Edit School Year
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item text-danger" href="#" onclick="deleteSchoolYear(<?= $sy['id'] ?>, '<?= htmlspecialchars($sy['year']) ?>'); return false;">
                                                <i class="bi bi-trash me-2"></i>Delete School Year
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_sy > 0): ?>
        <div class="pagination-container">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted">
                    Page <?= $page ?> of <?= max(1, $total_pages) ?>
                </div>
                
                <nav aria-label="Page navigation">
                    <ul class="pagination mb-0">
                        <!-- First Page -->
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=1&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" title="First Page">
                                <i class="bi bi-chevron-double-left"></i>
                            </a>
                        </li>
                        
                        <!-- Previous Page -->
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= max(1, $page - 1) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>">Previous</a>
                        </li>
                        
                        <!-- Page Numbers -->
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min(max(1, $total_pages), $start_page + 4);
                        $start_page = max(1, $end_page - 4);
                        
                        for($i = $start_page; $i <= $end_page; $i++): 
                        ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <!-- Next Page -->
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= min($total_pages, $page + 1) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>">Next</a>
                        </li>
                        
                        <!-- Last Page -->
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= max(1, $total_pages) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" title="Last Page">
                                <i class="bi bi-chevron-double-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                
                <!-- Custom Page Jump -->
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small">Go to:</span>
                    <form method="GET" class="d-flex gap-2" onsubmit="return validatePageJump()">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                        <input type="number" 
                               name="page" 
                               id="pageJump"
                               class="form-control form-control-sm" 
                               style="width: 70px;" 
                               min="1" 
                               max="<?= max(1, $total_pages) ?>"
                               placeholder="<?= $page ?>"
                               title="Enter page number (1-<?= max(1, $total_pages) ?>)">
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
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true" style="margin-top: 80px;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel"><i class="bi bi-exclamation-triangle"></i> Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete school year <strong id="delete_school_year_name"></strong>?</p>
                <p class="text-danger"><i class="bi bi-info-circle"></i> This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete School Year</button>
            </div>
        </div>
    </div>
</div>

<script>
// Search and Sort functionality
(function() {
    const searchInput = document.getElementById('sySearch');
    const clearSearchBtn = document.getElementById('clearSYSearch');
    const sortSelect = document.getElementById('sortSY');
    
    function updateTable() {
        const q = searchInput ? searchInput.value.trim() : '';
        const s = sortSelect ? sortSelect.value : 'year-desc';
        window.location.href = `school_years.php?page=1&search=${encodeURIComponent(q)}&sort=${encodeURIComponent(s)}`;
    }

    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            if (clearSearchBtn) clearSearchBtn.style.display = this.value ? 'block' : 'none';
            
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(updateTable, 800);
        });
        
        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function() {
                searchInput.value = '';
                this.style.display = 'none';
                updateTable();
            });
        }
    }
    
    if (sortSelect) {
        sortSelect.addEventListener('change', updateTable);
    }
})();

// Delete school year function
let deleteSchoolYearId = null;

function deleteSchoolYear(id, year) {
    deleteSchoolYearId = id;
    document.getElementById('delete_school_year_name').textContent = year;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    deleteModal.show();
}

// Confirm delete
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (!deleteSchoolYearId) return;
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="${deleteSchoolYearId}">
    `;
    document.body.appendChild(form);
    form.submit();
});
</script>

<?php include "../templates/footer.php"; ?>
