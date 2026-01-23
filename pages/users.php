<?php
session_start();
include "../includes/db.php";

// AJAX endpoint to get teacher assignments - MUST BE BEFORE ANY HTML OUTPUT
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_assignments' && isset($_GET['user_id'])) {
    header('Content-Type: application/json');
    $user_id = (int)$_GET['user_id'];
    
    $result = ['adviser' => null, 'subjects' => []];
    
    // Get adviser assignment
    $adviser_query = $conn->prepare("SELECT grade_level, section FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'adviser'");
    $adviser_query->bind_param("i", $user_id);
    $adviser_query->execute();
    $adviser_result = $adviser_query->get_result();
    if ($adviser = $adviser_result->fetch_assoc()) {
        $result['adviser'] = $adviser;
    }
    
    // Get subject assignments (grouped by subject and grade)
    $subjects_query = $conn->prepare("SELECT subject_id, grade_level, GROUP_CONCAT(section) as sections 
                                      FROM teacher_assignments 
                                      WHERE teacher_id = ? AND assignment_type = 'subject' 
                                      GROUP BY subject_id, grade_level");
    $subjects_query->bind_param("i", $user_id);
    $subjects_query->execute();
    $subjects_result = $subjects_query->get_result();
    while ($subj = $subjects_result->fetch_assoc()) {
        $result['subjects'][] = [
            'subject_id' => $subj['subject_id'],
            'grade_level' => $subj['grade_level'],
            'sections' => explode(',', $subj['sections'])
        ];
    }
    
    echo json_encode($result);
    exit;
}

// AJAX endpoint to check if section already has an adviser
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_adviser') {
    header('Content-Type: application/json');
    $grade = (int)$_GET['grade'];
    $section = $_GET['section'];
    $exclude_user = isset($_GET['exclude_user']) ? (int)$_GET['exclude_user'] : 0;
    
    $result = ['has_adviser' => false, 'adviser_name' => null];
    
    // Check if section has an adviser (excluding current user)
    $check_query = $conn->prepare("SELECT u.full_name 
                                   FROM teacher_assignments ta
                                   JOIN users u ON ta.teacher_id = u.id
                                   WHERE ta.grade_level = ? 
                                   AND ta.section = ? 
                                   AND ta.assignment_type = 'adviser'
                                   AND ta.teacher_id != ?");
    $check_query->bind_param("isi", $grade, $section, $exclude_user);
    $check_query->execute();
    $check_result = $check_query->get_result();
    
    if ($adviser = $check_result->fetch_assoc()) {
        $result['has_adviser'] = true;
        $result['adviser_name'] = $adviser['full_name'];
    }
    
    echo json_encode($result);
    exit;
}

// Now include header and check permissions for regular page load
include "../templates/header.php";

$user = $_SESSION['user'];
if ($user['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$success = "";
$error = "";

// Handle Add User
if (isset($_POST['add_user'])) {
    try {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        // Get school information (nullable)
        $school_name = !empty($_POST['school_name']) ? $_POST['school_name'] : null;
        $school_id = !empty($_POST['school_id']) ? $_POST['school_id'] : null;
        $district = !empty($_POST['district']) ? $_POST['district'] : null;
        $division = !empty($_POST['division']) ? $_POST['division'] : null;
        $region = !empty($_POST['region']) ? $_POST['region'] : null;
        
        // Basic user creation with school info
        $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, school_name, school_id, district, division, region, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssssssss", $_POST['username'], $password, $_POST['full_name'], $_POST['role'], 
                          $school_name, $school_id, $district, $division, $region);
        
        if ($stmt->execute()) {
            $new_user_id = $conn->insert_id;
            
            // If teacher, handle assignments
            if ($_POST['role'] === 'teacher') {
                
                // Add adviser assignment if checked
                if (isset($_POST['is_adviser']) && $_POST['is_adviser'] == '1' && !empty($_POST['adviser_class'])) {
                    // adviser_class format: "grade_level|section"
                    list($grade, $section) = explode('|', $_POST['adviser_class']);
                    $grade = (int)$grade;
                    
                    $stmt_adv = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, assignment_type, grade_level, section) 
                                                VALUES (?, 'adviser', ?, ?)");
                    $stmt_adv->bind_param("iis", $new_user_id, $grade, $section);
                    $stmt_adv->execute();
                }
            }
            
            $success = "User added successfully!";
        } else {
            $error = "Error adding user: " . $stmt->error;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle Edit User
if (isset($_POST['edit_user'])) {
    try {
        // Get school information (nullable) - ensure they're captured from POST
        $school_name = isset($_POST['school_name']) && $_POST['school_name'] !== '' ? $_POST['school_name'] : null;
        $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? $_POST['school_id'] : null;
        $district = isset($_POST['district']) && $_POST['district'] !== '' ? $_POST['district'] : null;
        $division = isset($_POST['division']) && $_POST['division'] !== '' ? $_POST['division'] : null;
        $region = isset($_POST['region']) && $_POST['region'] !== '' ? $_POST['region'] : null;
        
        // Update basic user info with school fields
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET username=?, password=?, full_name=?, role=?, school_name=?, school_id=?, district=?, division=?, region=? WHERE id=?");
            $stmt->bind_param("sssssssssi", $_POST['username'], $password, $_POST['full_name'], $_POST['role'], 
                              $school_name, $school_id, $district, $division, $region, $_POST['id']);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=?, full_name=?, role=?, school_name=?, school_id=?, district=?, division=?, region=? WHERE id=?");
            $stmt->bind_param("ssssssssi", $_POST['username'], $_POST['full_name'], $_POST['role'], 
                              $school_name, $school_id, $district, $division, $region, $_POST['id']);
        }
        
        if ($stmt->execute()) {
            $user_id = $_POST['id'];
            
            // If teacher, update assignment
            if ($_POST['role'] === 'teacher') {
                // Get current adviser assignment from database before making changes
                $current_adviser_query = $conn->prepare("SELECT grade_level, section FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'adviser'");
                $current_adviser_query->bind_param("i", $user_id);
                $current_adviser_query->execute();
                $current_adviser_result = $current_adviser_query->get_result();
                $current_adviser = $current_adviser_result->fetch_assoc();
                
                // Determine what assignment to use
                $new_assignment = isset($_POST['adviser_class_edit']) ? trim($_POST['adviser_class_edit']) : '';
                
                // If form field is empty but user has an existing assignment, preserve it
                // This prevents accidental deletion when just editing school info
                if (empty($new_assignment) && $current_adviser) {
                    // Keep existing assignment - do nothing
                    // User must explicitly select "Not Assigned" to remove assignment
                } else {
                    // Delete existing adviser assignments for this teacher
                    $conn->query("DELETE FROM teacher_assignments WHERE teacher_id = $user_id AND assignment_type = 'adviser'");
                    
                    // Add new adviser assignment if selected
                    if (!empty($new_assignment)) {
                        list($grade, $section) = explode('|', $new_assignment);
                        $grade = (int)$grade;
                        
                        // First, remove any existing adviser from this section (enforce one adviser per section)
                        $stmt_del = $conn->prepare("DELETE FROM teacher_assignments WHERE grade_level = ? AND section = ? AND assignment_type = 'adviser'");
                        $stmt_del->bind_param("is", $grade, $section);
                        $stmt_del->execute();
                        
                        // Now assign the new adviser
                        $stmt_adv = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, assignment_type, grade_level, section) VALUES (?, 'adviser', ?, ?)");
                        $stmt_adv->bind_param("iis", $user_id, $grade, $section);
                        $stmt_adv->execute();
                    }
                }
            } else {
                // If changed to admin, remove all teacher assignments
                $conn->query("DELETE FROM teacher_assignments WHERE teacher_id = $user_id");
            }
            
            $success = "User updated successfully!";
            
            // Add script to reset the currentEditUserData so next open loads fresh data
            echo "<script>if(typeof currentEditUserData !== 'undefined') currentEditUserData = null;</script>";
        } else {
            $error = "Error updating user: " . $stmt->error;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle Delete User
if (isset($_POST['delete_user'])) {
    try {
        // Teacher assignments will be deleted automatically by CASCADE
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $_POST['id']);
        
        if ($stmt->execute()) {
            $success = "User deleted successfully!";
        } else {
            $error = "Error deleting user: " . $stmt->error;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch Users with their assignments
$users_query = "SELECT u.*, 
                GROUP_CONCAT(DISTINCT CASE WHEN ta.assignment_type = 'adviser' 
                    THEN CONCAT('Grade ', ta.grade_level, ' - ', ta.section) END) as adviser_info,
                GROUP_CONCAT(DISTINCT CASE WHEN ta.assignment_type = 'subject' 
                    THEN CONCAT(s.subject_name, ' (G', ta.grade_level, '-', ta.section, ')') END SEPARATOR ', ') as subject_info
                FROM users u
                LEFT JOIN teacher_assignments ta ON u.id = ta.teacher_id
                LEFT JOIN subjects s ON ta.subject_id = s.id
                GROUP BY u.id
                ORDER BY u.created_at DESC";
$users = $conn->query($users_query);

// Get subjects for dropdown
$subjects = $conn->query("SELECT id, subject_name FROM subjects ORDER BY subject_name");

// Get classes (grade levels and sections) for dropdowns
$classes_query = "SELECT DISTINCT grade_level, section, school_year, status 
                  FROM classes 
                  WHERE status = 'Active' 
                  ORDER BY grade_level, section";
$classes_result = $conn->query($classes_query);
$classes_data = [];
while ($class = $classes_result->fetch_assoc()) {
    $classes_data[] = $class;
}
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-people"></i> Manage Users</h2>
        <p class="subtitle">Manage system users and their permissions</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-plus-circle"></i> Add New User
    </button>
</div>

<?php if (!empty($success)): ?>
    <div class='alert alert-success alert-dismissible fade show' role='alert' id='successAlert'>
        <?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class='alert alert-danger alert-dismissible fade show' role='alert' id='errorAlert'>
        <?= $error ?>
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
        }, 5000); // 5 seconds for success
    }
    
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.style.transition = 'opacity 0.5s ease-out';
            errorAlert.style.opacity = '0';
            setTimeout(() => errorAlert.remove(), 500);
        }, 7000); // 7 seconds for errors
    }
});
</script>

<!-- Users Table -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      <i class="bi bi-people"></i> All Users
      <span class="badge bg-primary ms-2" id="userCount">0</span>
    </span>
    <div class="d-flex gap-2">
      <select id="sortUsers" class="form-select form-select-sm" style="width: auto;">
        <option value="name-asc">Name (A-Z)</option>
        <option value="name-desc">Name (Z-A)</option>
        <option value="role-admin">Admin First</option>
        <option value="role-teacher">Teacher First</option>
      </select>
      <div style="position: relative; width: 250px;">
        <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
        <input type="text" class="form-control form-control-sm" id="userSearch" placeholder="Search by name or username..." style="padding-left: 35px; padding-right: 30px;">
        <button type="button" id="clearUserSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; display: none;">
          <i class="bi bi-x-circle-fill" style="color: #6c757d;"></i>
        </button>
      </div>
    </div>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover" id="usersTable">
        <thead>
          <tr>
            <th>Full Name</th>
            <th>Username</th>
            <th>Role</th>
            <th>Adviser For</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while($u = $users->fetch_assoc()): ?>
          <tr class="user-row"
              data-name="<?= htmlspecialchars($u['full_name']) ?>"
              data-username="<?= htmlspecialchars($u['username']) ?>"
              data-role="<?= htmlspecialchars($u['role']) ?>">
            <td>
              <i class="bi bi-person-circle"></i> <?= htmlspecialchars(strtoupper($u['full_name'])) ?>
            </td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td>
              <?php if ($u['role'] === 'admin'): ?>
                <span class="badge bg-danger">Admin</span>
              <?php else: ?>
                <span class="badge bg-primary">Teacher</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($u['adviser_info']): ?>
                <span class="badge bg-warning text-dark"><?= htmlspecialchars($u['adviser_info']) ?></span>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="dropdown" style="position: static;">
                <button class="btn btn-sm btn-secondary dropdown-toggle" type="button" id="actionsDropdown<?= $u['id'] ?>" data-bs-toggle="dropdown" aria-expanded="false">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="actionsDropdown<?= $u['id'] ?>" style="z-index: 1050;">
                  <li><a class="dropdown-item" href="javascript:void(0)" onclick='editUser(<?= json_encode($u) ?>)'>
                    <i class="bi bi-pencil text-warning me-2"></i>Edit User
                  </a></li>
                  <li><hr class="dropdown-divider"></li>
                  <?php if ($u['role'] === 'admin'): ?>
                    <li title="Cannot delete admin. Change role to teacher first.">
                      <a class="dropdown-item text-muted disabled" href="javascript:void(0)" style="cursor: not-allowed; pointer-events: auto;">
                        <i class="bi bi-trash me-2"></i>Delete User (Disabled)
                      </a>
                    </li>
                  <?php else: ?>
                    <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick='deleteUserConfirm(<?= $u["id"] ?>, "<?= htmlspecialchars($u["full_name"]) ?>")'>
                      <i class="bi bi-trash me-2"></i>Delete User
                    </a></li>
                  <?php endif; ?>
                </ul>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// Search and Sort functionality
(function() {
    const searchInput = document.getElementById('userSearch');
    const clearSearchBtn = document.getElementById('clearUserSearch');
    const sortSelect = document.getElementById('sortUsers');
    const tableBody = document.querySelector('#usersTable tbody');
    const userRows = Array.from(tableBody.querySelectorAll('.user-row'));
    
    function updateUserCount() {
      const visible = userRows.filter(r => window.getComputedStyle(r).display !== 'none').length;
      const badge = document.getElementById('userCount');
      if (badge) badge.textContent = visible;
    }

    if (searchInput && clearSearchBtn) {
        // Show/hide clear button based on input value
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            clearSearchBtn.style.display = this.value ? 'block' : 'none';
            
            userRows.forEach(row => {
                const name = row.getAttribute('data-name').toLowerCase();
                const username = row.getAttribute('data-username').toLowerCase();
                
                if (name.includes(searchTerm) || username.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            updateUserCount();
        });
        
        // Clear search input when X button is clicked
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            clearSearchBtn.style.display = 'none';
            userRows.forEach(row => row.style.display = '');
          updateUserCount();
        });
    }
    
    // Sort functionality
    if (sortSelect) {
        sortSelect.addEventListener('change', function() {
            const sortType = this.value;
            let sortedRows = [...userRows];
            
            sortedRows.sort((a, b) => {
                if (sortType === 'name-asc') {
                    return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                } else if (sortType === 'name-desc') {
                    return b.getAttribute('data-name').localeCompare(a.getAttribute('data-name'));
                } else if (sortType === 'role-admin') {
                    const aRole = a.getAttribute('data-role');
                    const bRole = b.getAttribute('data-role');
                    if (aRole === 'admin' && bRole !== 'admin') return -1;
                    if (aRole !== 'admin' && bRole === 'admin') return 1;
                    return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                } else if (sortType === 'role-teacher') {
                    const aRole = a.getAttribute('data-role');
                    const bRole = b.getAttribute('data-role');
                    if (aRole === 'teacher' && bRole !== 'teacher') return -1;
                    if (aRole !== 'teacher' && bRole === 'teacher') return 1;
                    return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                }
            });
            
            // Re-append rows in sorted order
            sortedRows.forEach(row => tableBody.appendChild(row));
            updateUserCount();
        });
    }
        // initial count
        updateUserCount();
})();
</script>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="max-height: 90vh; display: flex; flex-direction: column;">
      <form method="POST" id="addUserForm">
        <div class="modal-header">
          <h5 class="modal-title">Add New User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="overflow-y: auto; max-height: calc(90vh - 120px);">
          <!-- Basic Info -->
          <div class="mb-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="full_name" class="form-control" autocomplete="off" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" class="form-control" autocomplete="off" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Password <span class="text-danger">*</span></label>
            <input type="password" name="password" class="form-control" autocomplete="new-password" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Role <span class="text-danger">*</span></label>
            <select name="role" class="form-control" id="roleSelect" onchange="toggleTeacherFields()" required>
              <option value="">-- Select Role --</option>
              <option value="admin">Admin</option>
              <option value="teacher">Teacher</option>
            </select>
          </div>
          
          <!-- School Information -->
          <hr>
          <h6 class="mb-3"><i class="bi bi-building"></i> School Information</h6>
          <div class="row">
            <div class="col-md-8 mb-3">
              <label class="form-label">School Name</label>
              <input type="text" name="school_name" class="form-control" autocomplete="off">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">School ID</label>
              <input type="text" name="school_id" class="form-control" autocomplete="off">
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">District</label>
              <input type="text" name="district" class="form-control" autocomplete="off">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Division</label>
              <input type="text" name="division" class="form-control" autocomplete="off">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Region</label>
              <input type="text" name="region" class="form-control" autocomplete="off">
            </div>
          </div>
          
          <!-- Teacher Assignments Section -->
          <div class="teacher-fields d-none">
            <hr>
            <h6 class="mb-3"><i class="bi bi-person-badge"></i> Teacher Assignments</h6>
            
            <!-- Adviser Assignment -->
            <div class="card mb-3">
              <div class="card-body">
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" name="is_adviser" value="1" id="isAdviserCheck" onchange="toggleAdviserFields()">
                  <label class="form-check-label" for="isAdviserCheck">
                    <strong>This teacher is a Class Adviser</strong>
                  </label>
                </div>
                <div class="adviser-fields d-none">
                  <div class="mb-3">
                    <label class="form-label">Select Class to Advise <span class="text-danger">*</span></label>
                    <select name="adviser_class" id="adviserClassSelect" class="form-control" onchange="checkExistingAdviserAdd()">
                      <option value="">-- Select Class --</option>
                      <?php foreach ($classes_data as $class): ?>
                        <option value="<?= $class['grade_level'] ?>|<?= htmlspecialchars($class['section']) ?>">
                          Grade <?= $class['grade_level'] ?> - <?= htmlspecialchars($class['section']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Select from existing classes in the system</small>
                    <div id="adviserWarningAdd" class="alert alert-warning mt-2" style="display: none; border-left: 4px solid #ff6b6b; background-color: #fff3cd; padding: 10px;">
                      <i class="bi bi-exclamation-triangle-fill text-danger"></i> 
                      <strong id="adviserWarningTextAdd"></strong>
                      <br>
                      <small>This class already has an adviser. Adding this user will reassign the class and remove the previous adviser.</small>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-lg" style="margin-top: 80px;">
    <div class="modal-content">
      <form method="POST" style="display: flex; flex-direction: column; max-height: 85vh;">
        <div class="modal-header">
          <h5 class="modal-title">Edit User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="overflow-y: auto; flex: 1;">
          <input type="hidden" name="id" id="editId">
          
          <!-- Basic Info -->
          <div class="mb-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="full_name" id="editFullName" class="form-control" autocomplete="off" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" id="editUsername" class="form-control" autocomplete="off" required>
          </div>
          <div class="mb-3">
            <label class="form-label">New Password (leave blank to keep current)</label>
            <input type="password" name="password" class="form-control" autocomplete="new-password" placeholder="Enter new password or leave blank">
          </div>
          <div class="mb-3">
            <label class="form-label">Role <span class="text-danger">*</span></label>
            <select name="role" id="editRole" class="form-control" required onchange="toggleTeacherFieldsEdit()">
              <option value="admin">Admin</option>
              <option value="teacher">Teacher</option>
            </select>
          </div>
          
          <!-- School Information -->
          <hr>
          <h6 class="mb-3"><i class="bi bi-building"></i> School Information</h6>
          <div class="row">
            <div class="col-md-8 mb-3">
              <label class="form-label">School Name</label>
              <input type="text" name="school_name" id="editSchoolName" class="form-control" autocomplete="off">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">School ID</label>
              <input type="text" name="school_id" id="editSchoolId" class="form-control" autocomplete="off">
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">District</label>
              <input type="text" name="district" id="editDistrict" class="form-control" autocomplete="off">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Division</label>
              <input type="text" name="division" id="editDivision" class="form-control" autocomplete="off">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Region</label>
              <input type="text" name="region" id="editRegion" class="form-control" autocomplete="off">
            </div>
          </div>
          
          <!-- Teacher Assignment (Simple) -->
          <div class="teacher-fields-edit d-none">
            <hr>
            <h6 class="mb-3"><i class="bi bi-person-badge"></i> Teacher Assignment</h6>
            <div class="mb-3">
              <label class="form-label">Assigned as Adviser for Class</label>
              <select name="adviser_class_edit" id="adviserClassSelectEdit" class="form-control" onchange="checkExistingAdviser()">
                <option value="">-- Not Assigned / None --</option>
                <?php foreach ($classes_data as $class): ?>
                  <option value="<?= $class['grade_level'] ?>|<?= htmlspecialchars($class['section']) ?>">
                    Grade <?= $class['grade_level'] ?> - <?= htmlspecialchars($class['section']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">Select a class to assign this teacher as adviser, or leave as "None"</small>
              <div id="adviserWarning" class="alert alert-warning mt-2" style="display: none; border-left: 4px solid #ff6b6b; background-color: #fff3cd; padding: 10px;">
                <i class="bi bi-exclamation-triangle-fill text-danger"></i> 
                <strong id="adviserWarningText"></strong>
                <br>
                <small>Click "Save Changes" to reassign this section. The previous teacher will be unassigned.</small>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="edit_user" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Confirmation Form (Hidden) -->
<form method="POST" id="deleteUserForm" style="display: none;">
  <input type="hidden" name="id" id="deleteUserId">
  <input type="hidden" name="delete_user" value="1">
</form>

<!-- Delete User Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" style="margin-top: 80px;">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Confirm Delete</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete user <strong id="delete_user_name"></strong>?</p>
        <p class="text-danger"><i class="bi bi-info-circle"></i> This action cannot be undone. All associated records will be deleted.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteUserBtn">Delete User</button>
      </div>
    </div>
  </div>
</div>

<script>
let subjectRowCounter = 0;
let subjectRowCounterEdit = 0;
let currentEditUserData = null; // Store current user being edited

// Get subjects data for dropdowns
const subjectsData = <?= json_encode($subjects->fetch_all(MYSQLI_ASSOC)) ?>;

// Get classes data (grade levels and sections)
const classesData = <?= json_encode($classes_data) ?>;

// Reset currentEditUserData when modal closes to force fresh load on next open
document.addEventListener('DOMContentLoaded', function() {
    const editModal = document.getElementById('editModal');
    if (editModal) {
        editModal.addEventListener('hidden.bs.modal', function () {
            // Reset to force fresh data load next time
            currentEditUserData = null;
        });
    }
});

function toggleTeacherFields() {
    const role = document.getElementById('roleSelect').value;
    document.querySelector('.teacher-fields').classList.toggle('d-none', role !== 'teacher');
}

function toggleAdviserFields() {
    const isAdviser = document.getElementById('isAdviserCheck').checked;
    document.querySelector('.adviser-fields').classList.toggle('d-none', !isAdviser);
}

// Edit modal functions
function toggleTeacherFieldsEdit() {
    const role = document.getElementById('editRole').value;
    document.querySelector('.teacher-fields-edit').classList.toggle('d-none', role !== 'teacher');
}

function toggleAdviserFieldsEdit() {
    const isAdviser = document.getElementById('isAdviserCheckEdit').checked;
    document.querySelector('.adviser-fields-edit').classList.toggle('d-none', !isAdviser);
}

// Check if selected section already has an adviser
function checkExistingAdviser() {
    const dropdown = document.getElementById('adviserClassSelectEdit');
    const warningDiv = document.getElementById('adviserWarning');
    const warningText = document.getElementById('adviserWarningText');
    const selectedValue = dropdown.value;
    
    if (!selectedValue) {
        // No selection - hide warning
        warningDiv.style.display = 'none';
        dropdown.classList.remove('border-danger');
        return;
    }
    
    const [grade, section] = selectedValue.split('|');
    const currentUserId = document.getElementById('editId').value;
    
    fetch(`users.php?ajax=check_adviser&grade=${grade}&section=${encodeURIComponent(section)}&exclude_user=${currentUserId}`)
        .then(response => response.json())
        .then(data => {
            if (data.has_adviser) {
                // Show warning with red border
                dropdown.classList.add('border-danger');
                warningText.textContent = `Grade ${grade} - ${section} is currently assigned to "${data.adviser_name}".`;
                warningDiv.style.display = 'block';
                
                // Add glow effect
                dropdown.style.boxShadow = '0 0 10px rgba(255, 107, 107, 0.5)';
            } else {
                // No conflict - hide warning
                dropdown.classList.remove('border-danger');
                dropdown.style.boxShadow = '';
                warningDiv.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error checking adviser:', error);
        });
}

// Check if selected section already has an adviser (for Add modal)
function checkExistingAdviserAdd() {
    const dropdown = document.getElementById('adviserClassSelect');
    const warningDiv = document.getElementById('adviserWarningAdd');
    const warningText = document.getElementById('adviserWarningTextAdd');
    const selectedValue = dropdown.value;
    
    if (!selectedValue) {
        // No selection - hide warning
        warningDiv.style.display = 'none';
        dropdown.classList.remove('border-danger');
        return;
    }
    
    const [grade, section] = selectedValue.split('|');
    
    fetch(`users.php?ajax=check_adviser&grade=${grade}&section=${encodeURIComponent(section)}&exclude_user=0`)
        .then(response => response.json())
        .then(data => {
            if (data.has_adviser) {
                // Show warning with red border
                dropdown.classList.add('border-danger');
                warningText.textContent = `Grade ${grade} - ${section} is currently assigned to "${data.adviser_name}".`;
                warningDiv.style.display = 'block';
                
                // Add glow effect
                dropdown.style.boxShadow = '0 0 10px rgba(255, 107, 107, 0.5)';
            } else {
                // No conflict - hide warning
                dropdown.classList.remove('border-danger');
                dropdown.style.boxShadow = '';
                warningDiv.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error checking adviser:', error);
        });
}

function addSubjectRowEdit() {
    subjectRowCounterEdit++;
    const container = document.getElementById('subjectAssignmentsContainerEdit');
    
    const row = document.createElement('div');
    row.className = 'subject-row border rounded p-3 mb-3 bg-light';
    row.id = 'subjectRowEdit' + subjectRowCounterEdit;
    
    let subjectOptions = '<option value="">-- Select Subject --</option>';
    subjectsData.forEach(subject => {
        subjectOptions += `<option value="${subject.id}">${subject.subject_name}</option>`;
    });
    
    let gradeOptions = '<option value="">-- Select Grade --</option>';
    let uniqueGrades = [...new Set(classesData.map(c => c.grade_level))].sort((a, b) => a - b);
    uniqueGrades.forEach(grade => {
        gradeOptions += `<option value="${grade}">Grade ${grade}</option>`;
    });
    
    row.innerHTML = `
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="form-label"><strong>Subject</strong></label>
                <select name="subject_assignments_edit[${subjectRowCounterEdit}][subject_id]" class="form-control" required>
                    ${subjectOptions}
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><strong>Grade Level</strong></label>
                <select name="subject_assignments_edit[${subjectRowCounterEdit}][grade]" 
                        id="gradeSelectEdit${subjectRowCounterEdit}"
                        class="form-control" 
                        onchange="updateSectionsEdit(${subjectRowCounterEdit})" 
                        required>
                    ${gradeOptions}
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeSubjectRowEdit(${subjectRowCounterEdit})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <label class="form-label"><strong>Sections</strong></label>
                <div id="sectionsContainerEdit${subjectRowCounterEdit}" class="border rounded p-3 bg-white" style="min-height: 60px;">
                    <p class="text-muted mb-0"><i class="bi bi-arrow-up"></i> Select a grade level first</p>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(row);
}

function updateSectionsEdit(rowId) {
    const gradeSelect = document.getElementById('gradeSelectEdit' + rowId);
    const sectionsContainer = document.getElementById('sectionsContainerEdit' + rowId);
    const selectedGrade = gradeSelect.value;
    
    if (!selectedGrade) {
        sectionsContainer.innerHTML = '<p class="text-muted mb-0"><i class="bi bi-arrow-up"></i> Select a grade level first</p>';
        return;
    }
    
    const gradeSections = classesData.filter(c => c.grade_level == selectedGrade);
    
    if (gradeSections.length === 0) {
        sectionsContainer.innerHTML = '<p class="text-warning mb-0"><i class="bi bi-exclamation-triangle"></i> No sections found for this grade.</p>';
        return;
    }
    
    let checkboxesHtml = '<div class="d-flex flex-wrap gap-3">';
    gradeSections.forEach(cls => {
        const sectionId = `secEdit${rowId}_${cls.section.replace(/\s+/g, '_')}`;
        checkboxesHtml += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" 
                       name="subject_assignments_edit[${rowId}][sections][]" 
                       value="${cls.section}" 
                       id="${sectionId}">
                <label class="form-check-label" for="${sectionId}">${cls.section}</label>
            </div>
        `;
    });
    checkboxesHtml += '</div>';
    
    sectionsContainer.innerHTML = checkboxesHtml;
}

function removeSubjectRowEdit(id) {
    document.getElementById('subjectRowEdit' + id).remove();
}

function editUser(user) {
    // Check if same user (to preserve manual changes)
    const isSameUser = currentEditUserData && currentEditUserData.id === user.id;
    
    // Store user data
    currentEditUserData = user;
    
    // Update basic info from database
    document.getElementById('editId').value = user.id;
    document.getElementById('deleteUserId').value = user.id;
    document.getElementById('editFullName').value = user.full_name;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editRole').value = user.role;
    document.getElementById('editSchoolName').value = user.school_name || '';
    document.getElementById('editSchoolId').value = user.school_id || '';
    document.getElementById('editDistrict').value = user.district || '';
    document.getElementById('editDivision').value = user.division || '';
    document.getElementById('editRegion').value = user.region || '';
    
    // Clear password field (security)
    const passwordField = document.querySelector('#editModal input[name="password"]');
    if (passwordField) passwordField.value = '';
    
    // Show/hide teacher fields
    toggleTeacherFieldsEdit();
    
    // Show modal first
    const modal = new bootstrap.Modal(document.getElementById('editModal'));
    modal.show();
    
    // Load teacher assignment from database (always refresh to show saved values)
    if (user.role === 'teacher') {
        fetch(`users.php?ajax=get_assignments&user_id=${user.id}`)
            .then(response => response.json())
            .then(data => {
                console.log('Full AJAX response:', data);
                const dropdown = document.getElementById('adviserClassSelectEdit');
                
                // Debug: Show all available options
                console.log('Available dropdown options:');
                for (let i = 0; i < dropdown.options.length; i++) {
                    console.log(`  [${i}] value="${dropdown.options[i].value}" text="${dropdown.options[i].text}"`);
                }
                
                if (data.adviser) {
                    const value = data.adviser.grade_level + '|' + data.adviser.section;
                    console.log('Trying to set dropdown to:', value);
                    console.log('Grade level type:', typeof data.adviser.grade_level);
                    console.log('Section type:', typeof data.adviser.section);
                    
                    // Use setTimeout to ensure DOM is ready
                    setTimeout(() => {
                        dropdown.value = value;
                        console.log('After setting - dropdown.value is now:', dropdown.value);
                        console.log('Selected option text:', dropdown.options[dropdown.selectedIndex]?.text);
                    }, 100);
                } else {
                    console.log('No adviser assignment found in database');
                    dropdown.value = '';
                }
            })
            .catch(error => {
                console.error('Error loading assignment:', error);
                document.getElementById('adviserClassSelectEdit').value = '';
            });
    } else {
        // Not a teacher - clear dropdown
        document.getElementById('adviserClassSelectEdit').value = '';
    }
}

// Delete user from table action button
function deleteUserConfirm(userId, userName) {
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('delete_user_name').textContent = userName;
    new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
}

// Confirm delete user
document.getElementById('confirmDeleteUserBtn').addEventListener('click', function() {
    document.getElementById('deleteUserForm').submit();
});
</script>
<?php include '../templates/footer.php'; ?>
</body>
</html>
</body>
</html>

<style>
/* Make users search/sort controls stack on small screens like records.php */
@media (max-width: 576px) {
  .card-header.d-flex { flex-direction: column; align-items: flex-start; gap: 10px; }
  .card-header.d-flex > .d-flex { width: 100%; display: flex; flex-direction: column; gap: 8px; }
  .card-header .d-flex select.form-select { width: 100% !important; }
  .card-header .d-flex > div { width: 100% !important; }
  .card-header .d-flex input.form-control { width: 100% !important; }
  /* Make the left header group use flex so title and badge stay inline and badge doesn't stretch */
  .card-header > span { display: flex; align-items: center; gap: 8px; width: 100%; }
  .card-header > span .badge { display: inline-block; width: auto; }
}
</style>
