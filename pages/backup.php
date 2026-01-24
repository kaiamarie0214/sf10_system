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
$msg = '';

function generate_sql_dump($conn) {
    $out = "-- SF10 System Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables_res = $conn->query('SHOW TABLES');
    $tables = [];
    while ($row = $tables_res->fetch_row()) $tables[] = $row[0];

    foreach ($tables as $table) {
        $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $create_res = $conn->query("SHOW CREATE TABLE `{$table}`");
        $create_row = $create_res->fetch_assoc();
        $out .= $create_row['Create Table'] . ";\n\n";

        $rows_res = $conn->query("SELECT * FROM `{$table}`");
        if ($rows_res && $rows_res->num_rows > 0) {
            $cols = [];
            $fields_info = $rows_res->fetch_fields();
            foreach ($fields_info as $f) $cols[] = '`' . $f->name . '`';
            $cols_list = implode(', ', $cols);
            while ($r = $rows_res->fetch_assoc()) {
                $vals = [];
                foreach ($r as $v) {
                    if (is_null($v)) $vals[] = 'NULL';
                    else $vals[] = "'" . $conn->real_escape_string($v) . "'";
                }
                $out .= "INSERT INTO `{$table}` ({$cols_list}) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $out .= "\n";
        }
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_admin) {
        $msg = 'Access denied.';
    } elseif (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
        $msg = 'Invalid request.';
    } else {
        if (isset($_POST['action']) && $_POST['action'] === 'export') {
            set_time_limit(0); // Unlimited time for large exports
            $sql = generate_sql_dump($conn);
            // send download headers before including any HTML/template
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="sf10_backup_' . date('Ymd_His') . '.sql"');
            header('Content-Length: ' . strlen($sql));
            echo $sql;
            exit();
        }

        if (isset($_POST['action']) && $_POST['action'] === 'import') {
            set_time_limit(0); // Unlimited time for large imports
            if (!isset($_FILES['sqlfile']) || $_FILES['sqlfile']['error'] !== UPLOAD_ERR_OK) {
                $msg = 'File upload failed.';
            } else {
                $fname = $_FILES['sqlfile']['name'];
                if (strtolower(pathinfo($fname, PATHINFO_EXTENSION)) !== 'sql') {
                    $msg = 'Please upload a .sql file.';
                } else {
                    $content = file_get_contents($_FILES['sqlfile']['tmp_name']);
                    if ($content === false) { $msg = 'Unable to read uploaded file.'; }
                    else {
                        // run import in chunks to avoid exceeding max_allowed_packet
                        $max_row = $conn->query("SHOW VARIABLES LIKE 'max_allowed_packet'")->fetch_assoc();
                        $max_allowed = isset($max_row['Value']) ? (int)$max_row['Value'] : 1048576;
                        // keep a safety margin
                        $chunk_limit = max(10240, $max_allowed - 1024);
                        $buffer = $content;
                        $import_error = null;
                        while ($buffer !== '') {
                            $len = strlen($buffer);
                            $take = ($len > $chunk_limit) ? $chunk_limit : $len;
                            $chunk = substr($buffer, 0, $take);
                            // ensure we cut at a statement boundary (semicolon). If no semicolon in chunk, extend to next semicolon.
                            $lastSemi = strrpos($chunk, ';');
                            if ($lastSemi === false) {
                                $nextSemi = strpos($buffer, ';');
                                if ($nextSemi !== false) {
                                    $pos = $nextSemi + 1;
                                } else {
                                    // no semicolon at all; send the whole buffer
                                    $pos = $len;
                                }
                            } else {
                                $pos = $lastSemi + 1;
                            }
                            $toSend = substr($buffer, 0, $pos);
                            $buffer = substr($buffer, $pos);

                            // Trim and skip empty chunks (avoid "Query was empty" errors)
                            $toSendTrim = trim($toSend);
                            if ($toSendTrim === '') {
                                // nothing to send, continue with remaining buffer
                                continue;
                            }

                            if (!$conn->multi_query($toSendTrim)) {
                                $import_error = $conn->error;
                                break;
                            }
                            // consume results
                            do { if ($res = $conn->store_result()) { $res->free(); } } while ($conn->more_results() && $conn->next_result());
                        }
                        if ($import_error) {
                            $msg = 'Import failed: ' . htmlspecialchars($import_error);
                        } else {
                            $msg = 'Import completed successfully.';
                        }
                    }
                }
            }
        }

        if (isset($_POST['action']) && $_POST['action'] === 'clear') {
            set_time_limit(0); // Unlimited time for clear operation
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            $tables_res = $conn->query("SHOW TABLES");
            $cleared_count = 0;
            $teachers_deleted = 0;
            
            while ($row = $tables_res->fetch_row()) {
                $table = $row[0];
                if ($table === 'users') {
                    // Delete only teachers from users table
                    $conn->query("DELETE FROM `users` WHERE `role` = 'teacher'");
                    $teachers_deleted = $conn->affected_rows;
                } elseif ($table !== 'subjects') {
                    // Truncate all other tables except subjects
                    $conn->query("TRUNCATE TABLE `{$table}`");
                    $cleared_count++;
                }
            }
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            $msg = "Database cleared successfully. ($cleared_count tables truncated, $teachers_deleted teacher accounts removed, admin accounts and subjects preserved)";
        }
    }
}

// Now safe to include header and render page (no more header() calls expected)
include '../templates/header.php';

?>
<div class="page-header">
    <h2><i class="bi bi-cloud-arrow-down-fill"></i> Backup / Restore Database</h2>
    <p class="subtitle">Export a SQL dump or upload a .sql file to restore (admin only)</p>
</div>

<div class="card mb-4">
    <div class="card-body">
        <?php if ($msg): ?>
            <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
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
                    // Provide some feedback without blocking the whole UI
                    var originalHtml = exportBtn.innerHTML;
                    exportBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Preparing...';
                    exportBtn.disabled = true;
                    
                    // Re-enable after a few seconds since we can't easily detect download completion
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

            if (importForm) {
                // When import button clicked, show confirmation modal
                if (importBtn) {
                    importBtn.addEventListener('click', function(e){
                        e.preventDefault();
                        var fname = sqlFileInput && sqlFileInput.files && sqlFileInput.files.length ? sqlFileInput.files[0].name : '(no file selected)';
                        if (selectedSqlFileSpan) selectedSqlFileSpan.textContent = fname;
                        var m = ensureModal();
                        if (m) m.show();
                        else alert('Modal not ready yet. Please try again in a moment.');
                    });
                }

                // When confirm in modal clicked, submit form
                if (confirmImportBtn) {
                    confirmImportBtn.addEventListener('click', function(){
                        // Hide modal first
                        var m = bootstrap.Modal.getInstance(confirmModalEl);
                        if (m) m.hide();
                        
                        // Update main button state
                        if (importBtn) {
                            importBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...';
                            importBtn.disabled = true;
                        }
                        
                        importForm.submit();
                    });
                }
            }

            var clearForm = document.getElementById('clearForm');
            var clearBtn = document.getElementById('clearBtn');
            var confirmClearModalEl = document.getElementById('confirmClearModal');
            var confirmClearBtn = document.getElementById('confirmClearBtn');

            if (clearForm && clearBtn && confirmClearModalEl) {
                clearBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var m = new bootstrap.Modal(confirmClearModalEl);
                    m.show();
                });

                if (confirmClearBtn) {
                    confirmClearBtn.addEventListener('click', function() {
                        var m = bootstrap.Modal.getInstance(confirmClearModalEl);
                        if (m) m.hide();
                        
                        clearBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Clearing...';
                        clearBtn.disabled = true;
                        
                        clearForm.submit();
                    });
                }
            }
        })();
        </script>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
