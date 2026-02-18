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

// â”€â”€ EXPORT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'export'
    && $is_admin
    && !empty($_POST['csrf']) && $_POST['csrf'] === $_SESSION['csrf_token']) {

    set_time_limit(300);
    $filename = 'sf10_backup_' . date('Ymd_His') . '.sql';

    // Write to a temp file then stream it â€” avoids buffering the whole dump in RAM
    $tmpfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

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
        // Fall back to pure-PHP dump if mysqldump failed
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

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit();
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpfile));
    readfile($tmpfile);
    @unlink($tmpfile);
    exit();
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
                    $msg = 'Unable to read uploaded file.';
                    $msg_type = 'danger';
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
                        $msg      = 'Import failed: ' . htmlspecialchars($import_error);
                        $msg_type = 'danger';
                    } else {
                        $msg      = 'Import completed successfully (fallback mode).';
                        $msg_type = 'success';
                    }
                }
            } else {
                $msg      = 'Import completed successfully.';
                $msg_type = 'success';
            }

            // Update session with active school year after import
            if ($msg_type === 'success') {
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
    $_SESSION['flash_msg']  = "Database cleared. ({$cleared_count} tables truncated, {$teachers_deleted} teacher accounts removed. Admin accounts &amp; subjects preserved.)";
    $_SESSION['flash_type'] = 'success';
    header('Location: school_years.php');
    exit();
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

        <script>
        (function(){
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
