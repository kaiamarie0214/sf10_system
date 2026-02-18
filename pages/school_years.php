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

// Get all school years
$school_years = $conn->query("SELECT * FROM school_years ORDER BY year DESC")->fetch_all(MYSQLI_ASSOC);

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
  
  /* Empty state */
  .empty-state {
    padding: 3rem 1rem;
  }
</style>

<!-- School Years List -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list-ul"></i> School Years List
    </div>
    <div class="card-body">
        <?php if (empty($school_years)): ?>
        <div class="text-center empty-state">
            <i class="bi bi-calendar-x" style="font-size: 3rem; color: #ccc;"></i>
            <p class="text-muted mt-3">No school years found. Create one to get started.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover" id="schoolYearsTable">
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
