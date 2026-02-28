<?php
include '../includes/db.php';

// Ensure session is started and user is authenticated before processing
if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit();
}
$user = $_SESSION['user'];
$is_admin = ($user['role'] === 'admin');

// CSRF token
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$msg      = '';
$msg_type = 'info'; // 'success' | 'danger' | 'info'

// â”€â”€ DB credentials (reuse from db.php globals) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$db_host = 'localhost';
$db_user = 'root';
$db_pass = 'kaia0214';
$db_name = 'sf10_system';

$mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
$mysqlcli  = 'C:\\xampp\\mysql\\bin\\mysql.exe';

// â”€â”€ Helper: build a safe CLI password argument â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function db_pass_arg($pass) {
    if ($pass === '') return '';
    // escape double-quotes inside the password for cmd.exe
    return '-p' . escapeshellarg($pass);
}

// â”€â”€ Helper: Perform Export (shared by Export action and Auto-backup-before-clear) â”€â”€â”€â”€â”€â”€â”€
function perform_db_export($conn, $db_host, $db_user, $db_pass, $db_name, $mysqldump, $filename, $save_to_disk = false) {
    set_time_limit(300);
    
    // Path for temp file
    if ($save_to_disk) {
        $backup_dir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        $tmpfile = $backup_dir . DIRECTORY_SEPARATOR . $filename;
    } else {
        $tmpfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
    }
    
    $cmd = sprintf(
        '"%s" -h %s -u %s %s --single-transaction --routines --triggers --add-drop-table %s > "%s" 2>&1',
        $mysqldump,
        escapeshellarg($db_host),
        escapeshellarg($db_user),
        db_pass_arg($db_pass),
        escapeshellarg($db_name),
        $tmpfile
    );
    exec($cmd, $out_lines, $ret);

    if ($ret !== 0 || !file_exists($tmpfile) || filesize($tmpfile) === 0) {
        // Fall back to pure-PHP dump
        $sql  = "-- SF10 System Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        $tables_res = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($trow = $tables_res->fetch_row()) {
            $tbl = $trow[0];
            $sql .= "DROP TABLE IF EXISTS `{$tbl}`;\n";
            $cr  = $conn->query("SHOW CREATE TABLE `{$tbl}`")->fetch_assoc();
            $sql .= $cr['Create Table'] . ";\n\n";

            $rr = $conn->query("SELECT * FROM `{$tbl}`");
            if ($rr && $rr->num_rows > 0) {
                $fi   = $rr->fetch_fields();
                $cols = implode(', ', array_map(fn($f) => '`'.$f->name.'`', $fi));
                while ($r = $rr->fetch_assoc()) {
                    $vals = array_map(fn($v) => is_null($v) ? 'NULL' : "'".$conn->real_escape_string($v)."'", $r);
                    $sql .= "INSERT INTO `{$tbl}` ({$cols}) VALUES (" . implode(', ', $vals) . ");\n";
                }
                $sql .= "\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        if ($save_to_disk) {
            file_put_contents($tmpfile, $sql);
            return $tmpfile;
        } else {
            return ['type' => 'raw', 'content' => $sql];
        }
    }
    
    return $tmpfile;
}

// â”€â”€ Helper: Perform Import (shared by Import action and Restore-from-auto-backup) â”€â”€â”€â”€â”€â”€â”€
function perform_db_import($conn, $db_host, $db_user, $db_pass, $db_name, $mysqlcli, $tmpfile) {
    set_time_limit(300);
    
    // Try mysql CLI first (most reliable for large files)
    $cmd = sprintf(
        '"%s" -h %s -u %s %s %s < "%s" 2>&1',
        $mysqlcli,
        escapeshellarg($db_host),
        escapeshellarg($db_user),
        db_pass_arg($db_pass),
        escapeshellarg($db_name),
        $tmpfile
    );
    exec($cmd, $out_lines, $ret);

    if ($ret !== 0) {
        // Fall back to PHP multi_query for small files
        $content = file_get_contents($tmpfile);
        if ($content === false) {
            return ['success' => false, 'message' => 'Unable to read backup file.'];
        } else {
            $import_error = null;
            // Split on statement boundaries, skip comments and empty lines
            $statements = array_filter(
                array_map('trim', explode(";\n", $content)),
                fn($s) => $s !== '' && !preg_match('/^\s*--/', $s)
            );
            foreach ($statements as $stmt) {
                if (trim($stmt) === '') continue;
                if (!$conn->query($stmt)) {
                    $import_error = $conn->error . ' in: ' . substr($stmt, 0, 100);
                    break;
                }
            }
            if ($import_error) {
                return ['success' => false, 'message' => 'Import failed: ' . $import_error];
            } else {
                return ['success' => true, 'message' => 'Import completed successfully (fallback mode).'];
            }
        }
    } else {
        return ['success' => true, 'message' => 'Import completed successfully.'];
    }
}

// â”€â”€ EXPORT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'export'
    && $is_admin
    && !empty($_POST['csrf']) && $_POST['csrf'] === $_SESSION['csrf_token']) {

    $filename = 'sf10_backup_' . date('Ymd_His') . '.sql';
    $result = perform_db_export($conn, $db_host, $db_user, $db_pass, $db_name, $mysqldump, $filename);

    if (is_array($result) && $result['type'] === 'raw') {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($result['content']));
        echo $result['content'];
        exit();
    } elseif (is_string($result) && file_exists($result)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($result));
    readfile($result);
    @unlink($result);
    exit();
  }
}

// â”€â”€ AUTO-BACKUP MANAGEMENT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$auto_backups_dir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'backups';
$show_backups = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'view_auto_backups' && $is_admin) {
    $password = $_POST['admin_password_view'] ?? '';
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    if ($res && password_verify($password, $res['password'])) {
        $show_backups = true;
    } else {
        $msg = 'Invalid password to view backups.';
        $msg_type = 'danger';
    }
}

// Handle Import (Restore) from specific auto-backup
if (isset($_POST['action']) && $_POST['action'] === 'import_auto' && $is_admin && !empty($_POST['csrf']) && $_POST['csrf'] === $_SESSION['csrf_token']) {
    $file = basename($_POST['filename']);
    $path = $auto_backups_dir . DIRECTORY_SEPARATOR . $file;
    
    if (file_exists($path) && strpos($file, 'auto_backup') === 0) {
        $import_result = perform_db_import($conn, $db_host, $db_user, $db_pass, $db_name, $mysqlcli, $path);
        
        $msg = "Auto-Backup Restore: " . $import_result['message'];
        $msg_type = $import_result['success'] ? 'success' : 'danger';

        // Update session with active school year after import
        if ($import_result['success']) {
            $sy = $conn->query("SELECT id, year FROM school_years WHERE is_active = 1 ORDER BY year DESC LIMIT 1");
            if ($sy && $sy->num_rows > 0) {
                $r = $sy->fetch_assoc();
                $_SESSION['school_year_id']     = $r['id'];
                $_SESSION['school_year']         = $r['year'];
                $_SESSION['school_year_status']  = 'active';
            }
        }
    } else {
        $msg = 'Backup file not found.';
        $msg_type = 'danger';
    }
}

// â”€â”€ IMPORT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'import'
    && $is_admin
    && !empty($_POST['csrf']) && $_POST['csrf'] === $_SESSION['csrf_token']) {

    set_time_limit(300);

    if (!isset($_FILES['sqlfile']) || $_FILES['sqlfile']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
        ];
        $err_code = $_FILES['sqlfile']['error'] ?? UPLOAD_ERR_NO_FILE;
        $msg      = $upload_errors[$err_code] ?? 'File upload failed (error ' . $err_code . ').';
        $msg_type = 'danger';
    } else {
        $fname = $_FILES['sqlfile']['name'];
        if (strtolower(pathinfo($fname, PATHINFO_EXTENSION)) !== 'sql') {
            $msg      = 'Please upload a valid .sql file.';
            $msg_type = 'danger';
        } else {
            $tmpfile = $_FILES['sqlfile']['tmp_name'];
            $import_result = perform_db_import($conn, $db_host, $db_user, $db_pass, $db_name, $mysqlcli, $tmpfile);
            
            $msg = $import_result['message'];
            $msg_type = $import_result['success'] ? 'success' : 'danger';

            // Update session with active school year after import
            if ($import_result['success']) {
                $sy = $conn->query("SELECT id, year FROM school_years WHERE is_active = 1 ORDER BY year DESC LIMIT 1");
                if ($sy && $sy->num_rows > 0) {
                    $r = $sy->fetch_assoc();
                    $_SESSION['school_year_id']     = $r['id'];
                    $_SESSION['school_year']         = $r['year'];
                    $_SESSION['school_year_status']  = 'active';
                }
            }
        }
    }
}

// â”€â”€ CLEAR â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'clear'
    && $is_admin
    && !empty($_POST['csrf']) && $_POST['csrf'] === $_SESSION['csrf_token']) {

    $password = $_POST['admin_password'] ?? '';
    
    // Verify password first
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    if (!$res || !password_verify($password, $res['password'])) {
        $msg = 'Invalid password. Clear operation aborted.';
        $msg_type = 'danger';
    } else {
        // --- CHECK IF DATA EXISTS BEFORE AUTO-BACKUP ---
        $student_count = $conn->query("SELECT COUNT(*) FROM students")->fetch_row()[0];
        $sy_count = $conn->query("SELECT COUNT(*) FROM school_years")->fetch_row()[0];
        $teacher_count = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'teacher'")->fetch_row()[0];
        
        $has_data = ($student_count > 0 || $sy_count > 0 || $teacher_count > 0);
        $backup_status = "";
        $admin_name = $_SESSION['user']['full_name'] ?? 'Unknown Admin';
        // Sanitize name for filename (remove special chars)
        $safe_admin_name = preg_replace('/[^a-zA-Z0-9]/', '_', $admin_name);

        if ($has_data) {
            // --- AUTO-BACKUP BEFORE CLEAR ---
            $backup_filename = 'auto_backup_before_clear_' . $safe_admin_name . '_' . date('Ymd_His') . '.sql';
            $backup_result = perform_db_export($conn, $db_host, $db_user, $db_pass, $db_name, $mysqldump, $backup_filename, true);
            
            if (is_string($backup_result) && file_exists($backup_result)) {
                $backup_status = " (Auto-backup created by $admin_name: $backup_filename)";
                
                // --- LIMIT TO 10 AUTO-BACKUPS ---
                 $all_auto_backups = glob($auto_backups_dir . DIRECTORY_SEPARATOR . 'auto_backup_before_clear_*.sql');
                 if (count($all_auto_backups) > 10) {
                     // Sort by modification time ascending (oldest first)
                     usort($all_auto_backups, fn($a, $b) => filemtime($a) - filemtime($b));
                     // Number of files to delete
                     $files_to_delete = count($all_auto_backups) - 10;
                     for ($i = 0; $i < $files_to_delete; $i++) {
                         @unlink($all_auto_backups[$i]);
                     }
                 }
            } else {
                $backup_status = " (Warning: Auto-backup failed, but proceeding with clear.)";
            }
        } else {
            $backup_status = " (System already empty, auto-backup skipped.)";
        }

        set_time_limit(300);
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        $tables_res      = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $cleared_count   = 0;
        $teachers_deleted = 0;

        while ($row = $tables_res->fetch_row()) {
            $table = $row[0];
            if ($table === 'users') {
                $conn->query("DELETE FROM `users` WHERE `role` = 'teacher'");
                $teachers_deleted = $conn->affected_rows;
            } elseif ($table !== 'subjects') {
                $conn->query("TRUNCATE TABLE `{$table}`");
                $cleared_count++;
            }
        }
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");

        $_SESSION['school_year_id']    = null;
        $_SESSION['school_year']        = null;
        $_SESSION['school_year_status'] = null;

        // Redirect to school years so admin sets up a new school year immediately
        $_SESSION['flash_msg']  = "Database cleared. ({$cleared_count} tables truncated, {$teachers_deleted} teacher accounts removed. Admin accounts &amp; subjects preserved.)$backup_status";
        $_SESSION['flash_type'] = 'success';
        header('Location: school_years.php');
        exit();
    }
}

// Now safe to include header (no more header() calls)
include '../templates/header.php';
?>

<div class="page-header">
    <h2><i class="bi bi-cloud-arrow-down-fill"></i> Backup / Restore Database</h2>
    <p class="subtitle">Export a SQL dump or upload a .sql file to restore (admin only)</p>
</div>

<div class="card mb-4">
    <div class="card-body">
        <?php if ($msg): ?>
            <div class="alert alert-<?= htmlspecialchars($msg_type) ?>"><?= $msg ?></div>
        <?php endif; ?>

        <div class="mb-4">
            <form id="exportForm" method="post" data-no-loading>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="export">
                <button type="submit" class="btn btn-primary" id="exportBtn"><i class="bi bi-download"></i> Export SQL Dump</button>
            </form>
        </div>

        <hr>

        <div>
            <form id="importForm" method="post" enctype="multipart/form-data" data-no-loading>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="import">
                <div class="mb-3">
                    <label for="sqlfile" class="form-label">Upload .sql file to import</label>
                    <input type="file" name="sqlfile" id="sqlfile" accept=".sql" class="form-control" required>
                </div>
                <div class="mb-3">
                    <button type="button" id="importBtn" class="btn btn-danger"><i class="bi bi-upload"></i> Import SQL</button>
                </div>

                <!-- Confirm Import Modal -->
                <div class="modal fade" id="confirmImportModal" tabindex="-1" aria-labelledby="confirmImportModalLabel" aria-hidden="true" style="margin-top: 80px;">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title" id="confirmImportModalLabel"><i class="bi bi-exclamation-triangle"></i> Confirm Import</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Restoring will overwrite existing data. This action is irreversible.</p>
                                <p><strong>Selected file:</strong> <span id="selectedSqlFile">(none)</span></p>
                                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill"></i> Make sure you have a backup before proceeding.</div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" id="confirmImportBtn" class="btn btn-danger">Yes, Import</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <hr>

        <div>
            <form id="clearForm" method="post" data-no-loading>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="clear">
                <div class="mb-3">
                    <label class="form-label text-danger fw-bold">Clear Database</label>
                    <p class="text-muted small">This will truncate all tables EXCEPT <code>subjects</code>, and remove all <code>teacher</code> accounts from the <code>users</code> table (keeping <code>admin</code> accounts). This action is irreversible.</p>
                    <button type="button" id="clearBtn" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Clear Database</button>
                </div>

                <!-- Confirm Clear Modal -->
                <div class="modal fade" id="confirmClearModal" tabindex="-1" aria-labelledby="confirmClearModalLabel" aria-hidden="true" style="margin-top: 80px;">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title" id="confirmClearModalLabel"><i class="bi bi-exclamation-triangle"></i> Confirm Clear Operation</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Are you sure you want to clear all data? This will truncate all tables except <code>subjects</code> and remove all teacher accounts (admin accounts will be kept).</p>
                                <div class="alert alert-danger"><i class="bi bi-exclamation-octagon-fill"></i> THIS ACTION CANNOT BE UNDONE.</div>
                                
                                <div class="mt-3">
                                    <label for="admin_password" class="form-label fw-bold">Enter Admin Password to Confirm:</label>
                                    <input type="password" name="admin_password" id="admin_password" class="form-control" required placeholder="Your login password">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" id="confirmClearBtn" class="btn btn-danger">Yes, Clear Everything</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <hr>

        <!-- Auto-Backups Management Section -->
        <div class="mt-4">
            <h5 class="fw-bold"><i class="bi bi-clock-history me-2"></i> Auto-Backups History</h5>
            <p class="text-muted small">Access safety backups created automatically before clear operations.</p>
            
            <?php if (!$show_backups): ?>
                <form method="POST" class="d-flex align-items-end gap-2" data-no-loading>
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="view_auto_backups">
                    <div style="flex: 1; max-width: 300px;">
                        <label class="form-label small fw-bold">Enter Password to View History</label>
                        <input type="password" name="admin_password_view" class="form-control form-control-sm" required placeholder="Admin Password">
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> View History</button>
                </form>
            <?php else: ?>
                <div class="table-responsive mt-3 border rounded shadow-sm" style="max-height: 300px; overflow-y: auto;">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3">Filename</th>
                                <th>Date Created</th>
                                <th>Size</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $files = glob($auto_backups_dir . DIRECTORY_SEPARATOR . 'auto_backup_*.sql');
                            // Sort files by date descending
                            usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
                            
                            if (empty($files)): 
                            ?>
                                <tr>
                                    <td colspan="4" class="text-center py-3 text-muted">No auto-backups found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($files as $file_path): 
                                    $file_name = basename($file_path);
                                    $file_size = round(filesize($file_path) / 1024, 2) . ' KB';
                                    $file_date = date('M d, Y - h:i A', filemtime($file_path));
                                ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-primary small"><i class="bi bi-file-earmark-code me-1"></i><?= $file_name ?></td>
                                        <td class="small"><?= $file_date ?></td>
                                        <td class="small"><?= $file_size ?></td>
                                        <td class="text-end pe-3">
                                            <button type="button" class="btn btn-xs btn-outline-danger" 
                                                    onclick="confirmAutoImport('<?= htmlspecialchars($file_name) ?>')" 
                                                    title="Import/Restore">
                                                <i class="bi bi-cloud-arrow-up"></i> Import
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Hidden form for auto-import -->
                <form id="autoImportForm" method="POST" data-no-loading>
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="import_auto">
                    <input type="hidden" name="filename" id="autoImportFilename">
                </form>

                <!-- Confirm Auto-Import Modal -->
                <div class="modal fade" id="confirmAutoImportModal" tabindex="-1" aria-hidden="true" style="margin-top: 80px;">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Restore from Auto-Backup</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>You are about to restore the database from this auto-backup file:</p>
                                <p class="fw-bold text-primary" id="autoImportDisplayFilename"></p>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle-fill"></i> This will <strong>overwrite</strong> all current data. This action cannot be undone.
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" id="executeAutoImportBtn" class="btn btn-danger">Yes, Restore Data</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-2 text-end">
                    <a href="backup.php" class="btn btn-xs btn-link text-muted"><i class="bi bi-shield-lock"></i> Hide History</a>
                </div>
            <?php endif; ?>
        </div>

        <script>
        // Global for modal
        function confirmAutoImport(filename) {
            document.getElementById('autoImportFilename').value = filename;
            document.getElementById('autoImportDisplayFilename').textContent = filename;
            new bootstrap.Modal(document.getElementById('confirmAutoImportModal')).show();
        }

        (function(){
            // Execute Auto Import
            var executeAutoImportBtn = document.getElementById('executeAutoImportBtn');
            if (executeAutoImportBtn) {
                executeAutoImportBtn.addEventListener('click', function() {
                    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Restoring...';
                    this.disabled = true;
                    document.getElementById('autoImportForm').submit();
                });
            }
            var exportForm = document.getElementById('exportForm');
            var exportBtn = document.getElementById('exportBtn');
            if (exportForm && exportBtn) {
                exportForm.addEventListener('submit', function() {
                    var originalHtml = exportBtn.innerHTML;
                    exportBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Preparing...';
                    exportBtn.disabled = true;
                    setTimeout(function() {
                        exportBtn.innerHTML = originalHtml;
                        exportBtn.disabled = false;
                    }, 3000);
                });
            }

            var importForm = document.getElementById('importForm');
            var importBtn = document.getElementById('importBtn');
            var confirmModalEl = document.getElementById('confirmImportModal');
            var confirmModal = null;
            var selectedSqlFileSpan = document.getElementById('selectedSqlFile');
            var sqlFileInput = document.getElementById('sqlfile');
            var confirmImportBtn = document.getElementById('confirmImportBtn');

            function ensureModal() {
                if (!confirmModal && window.bootstrap && confirmModalEl) {
                    confirmModal = new bootstrap.Modal(confirmModalEl);
                }
                return confirmModal;
            }

            if (importBtn) {
                importBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var fname = sqlFileInput && sqlFileInput.files && sqlFileInput.files.length ? sqlFileInput.files[0].name : '(no file selected)';
                    if (selectedSqlFileSpan) selectedSqlFileSpan.textContent = fname;
                    var m = ensureModal();
                    if (m) m.show();
                    else alert('Modal not ready yet. Please try again in a moment.');
                });
            }

            if (confirmImportBtn) {
                confirmImportBtn.addEventListener('click', function() {
                    var m = bootstrap.Modal.getInstance(confirmModalEl);
                    if (m) m.hide();
                    if (importBtn) {
                        importBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...';
                        importBtn.disabled = true;
                    }
                    importForm.submit();
                });
            }

            var clearForm = document.getElementById('clearForm');
            var clearBtn = document.getElementById('clearBtn');
            var confirmClearModalEl = document.getElementById('confirmClearModal');
            var confirmClearBtn = document.getElementById('confirmClearBtn');

            if (clearBtn && confirmClearModalEl) {
                clearBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    new bootstrap.Modal(confirmClearModalEl).show();
                });
            }

            if (confirmClearBtn) {
                confirmClearBtn.addEventListener('click', function() {
                    var m = bootstrap.Modal.getInstance(confirmClearModalEl);
                    if (m) m.hide();
                    clearBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Clearing...';
                    clearBtn.disabled = true;
                    clearForm.submit();
                });
            }
        })();
        </script>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
