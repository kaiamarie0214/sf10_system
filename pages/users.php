<?php
session_start();
include "../includes/db.php";

// AJAX endpoint to get teacher assignments - MUST BE BEFORE ANY HTML OUTPUT
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_assignments' && isset($_GET['user_id'])) {
    header('Content-Type: application/json');
    $user_id = (int)$_GET['user_id'];
    
    $result = ['adviser' => null, 'subjects' => []];
    $school_year_id = $_SESSION['school_year_id'];
    
    // Get adviser assignment
    $adviser_query = $conn->prepare("SELECT grade_level, section FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'adviser' AND school_year_id = ?");
    $adviser_query->bind_param("ii", $user_id, $school_year_id);
    $adviser_query->execute();
    $adviser_result = $adviser_query->get_result();
    if ($adviser = $adviser_result->fetch_assoc()) {
        $result['adviser'] = $adviser;
    }
    
    // Get subject assignments (grouped by subject and grade)
    $subjects_query = $conn->prepare("SELECT subject_id, grade_level, GROUP_CONCAT(section) as sections 
                                      FROM teacher_assignments 
                                      WHERE teacher_id = ? AND assignment_type = 'subject' AND school_year_id = ?
                                      GROUP BY subject_id, grade_level");
    $subjects_query->bind_param("ii", $user_id, $school_year_id);
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

// AJAX endpoint to get subject details by assignment
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_subject_details') {
    header('Content-Type: application/json');
    $user_id = (int)$_GET['user_id'];
    
    $subjects_with_names = [];
    
    // Get subject assignments with customized names
    $school_year_id = $_SESSION['school_year_id'];
    $query = $conn->prepare("
        SELECT 
            ta.subject_id,
            ta.grade_level,
            GROUP_CONCAT(ta.section) as sections,
            COALESCE(sgg.subject_name, s.subject_name) as subject_name
        FROM teacher_assignments ta
        JOIN subjects s ON ta.subject_id = s.id
        LEFT JOIN subject_grade_groups sgg ON s.id = sgg.subject_id AND ta.grade_level = sgg.grade_level
        WHERE ta.teacher_id = ? AND ta.assignment_type = 'subject' AND ta.school_year_id = ?
        GROUP BY ta.subject_id, ta.grade_level, sgg.subject_name, s.subject_name
        ORDER BY ta.grade_level, s.subject_name
    ");
    $query->bind_param("ii", $user_id, $school_year_id);
    $query->execute();
    $result = $query->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $subjects_with_names[] = [
            'subject_id' => $row['subject_id'],
            'subject_name' => $row['subject_name'],
            'grade_level' => $row['grade_level'],
            'sections' => explode(',', $row['sections'])
        ];
    }
    
    echo json_encode($subjects_with_names);
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

// Check for session success message
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Handle Add User - REMOVED (now in add_user.php)
if (false && isset($_POST['add_user'])) {
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
                    $school_year_id = $_SESSION['school_year_id'];
                    
                    $stmt_adv = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, assignment_type, grade_level, section, school_year_id) 
                                                VALUES (?, 'adviser', ?, ?, ?)");
                    $stmt_adv->bind_param("iisi", $new_user_id, $grade, $section, $school_year_id);
                    $stmt_adv->execute();
                }
                
                // Add subject assignments if provided
                if (isset($_POST['subject_assignments']) && is_array($_POST['subject_assignments'])) {
                    $school_year_id = $_SESSION['school_year_id'];
                    foreach ($_POST['subject_assignments'] as $assignment) {
                        if (!empty($assignment['subject_id']) && !empty($assignment['grade']) && !empty($assignment['sections'])) {
                            $subject_id = (int)$assignment['subject_id'];
                            $grade = (int)$assignment['grade'];
                            
                            // Insert for each selected section
                            foreach ($assignment['sections'] as $section) {
                                $stmt_subj = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, assignment_type, subject_id, grade_level, section, school_year_id) 
                                                            VALUES (?, 'subject', ?, ?, ?, ?)");
                                $stmt_subj->bind_param("iiisi", $new_user_id, $subject_id, $grade, $section, $school_year_id);
                                $stmt_subj->execute();
                            }
                        }
                    }
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
                $school_year_id = $_SESSION['school_year_id'];
                
                // Get current adviser assignment from database before making changes
                $current_adviser_query = $conn->prepare("SELECT grade_level, section FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'adviser' AND school_year_id = ?");
                $current_adviser_query->bind_param("ii", $user_id, $school_year_id);
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
                    // Delete existing adviser assignments for this teacher in current school year
                    $stmt_del_adv = $conn->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'adviser' AND school_year_id = ?");
                    $stmt_del_adv->bind_param("ii", $user_id, $school_year_id);
                    $stmt_del_adv->execute();
                    
                    // Add new adviser assignment if selected
                    if (!empty($new_assignment)) {
                        list($grade, $section) = explode('|', $new_assignment);
                        $grade = (int)$grade;
                        
                        // First, remove any existing adviser from this section (enforce one adviser per section) in current school year
                        $stmt_del = $conn->prepare("DELETE FROM teacher_assignments WHERE grade_level = ? AND section = ? AND assignment_type = 'adviser' AND school_year_id = ?");
                        $stmt_del->bind_param("isi", $grade, $section, $school_year_id);
                        $stmt_del->execute();
                        
                        // Now assign the new adviser
                        $stmt_adv = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, assignment_type, grade_level, section, school_year_id) VALUES (?, 'adviser', ?, ?, ?)");
                        $stmt_adv->bind_param("iisi", $user_id, $grade, $section, $school_year_id);
                        $stmt_adv->execute();
                    }
                }
            } else {
                // If changed to admin, remove all teacher assignments for current school year
                $school_year_id = $_SESSION['school_year_id'];
                $stmt_del_all = $conn->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ? AND school_year_id = ?");
                $stmt_del_all->bind_param("ii", $user_id, $school_year_id);
                $stmt_del_all->execute();
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
$current_school_year_id = $_SESSION['school_year_id'] ?? null;
if ($current_school_year_id) {
    $sy_filter = "AND ta.school_year_id = " . intval($current_school_year_id);
} else {
    $sy_filter = "AND 1=0"; // no school year active — show no assignments
}

// --- FILTERS & PAGINATION ---
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'name-asc';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$items_per_page = 20;

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = "";

if (!empty($search)) {
    $where_conditions[] = "(u.full_name LIKE ? OR u.username LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Build ORDER BY clause
$order_sql = "u.full_name ASC";
switch ($sort) {
    case 'name-asc': $order_sql = "u.full_name ASC"; break;
    case 'name-desc': $order_sql = "u.full_name DESC"; break;
    case 'role-admin': $order_sql = "u.role ASC, u.full_name ASC"; break;
    case 'role-teacher': $order_sql = "u.role DESC, u.full_name ASC"; break;
}

// Get total count for pagination
$count_query = "SELECT COUNT(DISTINCT u.id) as total FROM users u $where_sql";
$stmt_count = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_users = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_users / $items_per_page);
$page = max(1, min($total_pages, $page));
$offset = ($page - 1) * $items_per_page;

$users_query = "SELECT u.*, 
                GROUP_CONCAT(DISTINCT CASE WHEN ta.assignment_type = 'adviser' 
                    THEN CONCAT('Grade ', ta.grade_level, ' - ', ta.section) END) as adviser_info,
                GROUP_CONCAT(DISTINCT CASE WHEN ta.assignment_type = 'subject' 
                    THEN CONCAT(s.subject_name, ' (G', ta.grade_level, '-', ta.section, ')') END SEPARATOR ', ') as subject_info
                FROM users u
                LEFT JOIN teacher_assignments ta ON u.id = ta.teacher_id $sy_filter
                LEFT JOIN subjects s ON ta.subject_id = s.id
                $where_sql
                GROUP BY u.id
                ORDER BY $order_sql
                LIMIT ? OFFSET ?";

$stmt_users = $conn->prepare($users_query);
$final_types = $types . "ii";
$final_params = array_merge($params, [$items_per_page, $offset]);
$stmt_users->bind_param($final_types, ...$final_params);
$stmt_users->execute();
$users = $stmt_users->get_result();

// Get subjects for dropdown
$subjects = $conn->query("SELECT id, subject_name FROM subjects ORDER BY subject_name");

// Get classes (grade levels and sections) for dropdowns
$classes_query = "SELECT DISTINCT grade_level, section, school_year, status 
                  FROM classes 
                  WHERE status = 'Active' 
                  ORDER BY grade_level, section";
$classes_result = $conn->query($classes_query);
$classes_data = [];
if ($classes_result) {
    while ($class = $classes_result->fetch_assoc()) {
        $classes_data[] = $class;
    }
}
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-people"></i> Manage Users</h2>
        <p class="subtitle">Manage system users and their permissions</p>
    </div>
    <a href="add_user.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Add New User
    </a>
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

<style>
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
  #usersTableCard {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
    margin-bottom: 0 !important;
  }
  #usersTableCard .card-body {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
    padding-bottom: 1rem !important;
  }
  #usersTableCard .table-responsive {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto !important;
    overflow-x: hidden;
    margin-bottom: 0;
  }
  #usersTable {
    font-size: 13px;
    width: 100%;
    margin-bottom: 0;
  }
  #usersTable th, #usersTable td {
    padding: 6px 8px;
  }
  #usersTable thead {
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
  
  /* Mobile responsive - enable horizontal scroll */
  @media (max-width: 768px) {
    #usersTableCard .table-responsive {
      overflow-x: auto !important;
      -webkit-overflow-scrolling: touch;
    }
    #usersTable {
      min-width: 700px;
    }
  }
</style>

<!-- Users Table -->
<div class="card" id="usersTableCard">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      <i class="bi bi-people"></i> All Users
      <span class="badge bg-primary ms-2" id="userCount"><?= number_format($total_users) ?></span>
    </span>
    <div class="d-flex gap-2">
      <select id="sortUsers" class="form-select form-select-sm" style="width: auto;">
        <option value="name-asc" <?= $sort === 'name-asc' ? 'selected' : '' ?>>Name (A-Z)</option>
        <option value="name-desc" <?= $sort === 'name-desc' ? 'selected' : '' ?>>Name (Z-A)</option>
        <option value="role-admin" <?= $sort === 'role-admin' ? 'selected' : '' ?>>Admin First</option>
        <option value="role-teacher" <?= $sort === 'role-teacher' ? 'selected' : '' ?>>Teacher First</option>
      </select>
      <div style="position: relative; width: 250px;">
        <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
        <input type="text" class="form-control form-control-sm" id="userSearch" placeholder="Search by name or username..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 35px; padding-right: 30px;">
        <button type="button" id="clearUserSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; <?= empty($search) ? 'display: none;' : 'display: block;' ?>">
          <i class="bi bi-x-circle-fill" style="color: #6c757d;"></i>
        </button>
      </div>
    </div>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="usersTable">
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
          <?php if ($users->num_rows > 0): ?>
            <?php while($u = $users->fetch_assoc()): ?>
            <tr class="user-row">
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
                    <li><a class="dropdown-item" href="view_user.php?id=<?= $u['id'] ?>">
                      <i class="bi bi-eye text-info me-2"></i>View Details
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="edit_user.php?id=<?= $u['id'] ?>">
                      <i class="bi bi-pencil text-warning me-2"></i>Edit User
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <?php if ($u['role'] === 'admin'): ?>
                      <li title="Cannot delete admin. Change role to teacher first.">
                        <a class="dropdown-item text-muted disabled" href="javascript:void(0)" style="cursor: not-allowed; pointer-events: auto;">
                          <i class="bi bi-trash me-2"></i>(Disabled)
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
          <?php else: ?>
            <tr>
              <td colspan="5" class="text-center py-4 text-muted">
                <i class="bi bi-search" style="font-size: 2rem; opacity: 0.3;"></i>
                <p class="mt-2 mb-0">No users found matching your criteria</p>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($total_users > 0): ?>
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

<script>
// Search and Sort functionality
(function() {
    const searchInput = document.getElementById('userSearch');
    const clearSearchBtn = document.getElementById('clearUserSearch');
    const sortSelect = document.getElementById('sortUsers');
    
    function updateTable() {
        const q = searchInput ? searchInput.value.trim() : '';
        const s = sortSelect ? sortSelect.value : 'name-asc';
        window.location.href = `users.php?page=1&search=${encodeURIComponent(q)}&sort=${encodeURIComponent(s)}`;
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
</script>

<!-- Delete Confirmation Form (Hidden) -->
<form method="POST" id="deleteUserForm" style="display: none;">
  <input type="hidden" name="id" id="deleteUserId">
  <input type="hidden" name="delete_user" value="1">
</form>

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
