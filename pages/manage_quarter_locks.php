<?php
session_start();
require_once "../includes/db.php";
date_default_timezone_set('Asia/Manila');

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];

// Get school year from session (the one user selected)
$active_school_year = $_SESSION['school_year'] ?? null;
$active_school_year_id = $_SESSION['school_year_id'] ?? null;

// If no school year in session, get the active one
if (!$active_school_year) {
    $active_sy_query = "SELECT id, year FROM school_years WHERE is_active = 1 LIMIT 1";
    $active_sy_result = $conn->query($active_sy_query);
    
    if ($active_sy_result && $active_sy_result->num_rows > 0) {
        $sy_row = $active_sy_result->fetch_assoc();
        $active_school_year = $sy_row['year'];
        $active_school_year_id = $sy_row['id'];
    }
}

// Fetch current quarter lock states from database for active school year
$locks = ['q1' => false, 'q2' => false, 'q3' => false, 'q4' => false];
$auto_locks = ['q1' => '', 'q2' => '', 'q3' => '', 'q4' => ''];
$auto_unlocks = ['q1' => '', 'q2' => '', 'q3' => '', 'q4' => ''];

// Get quarter locks for this school year
$result = $conn->query("SELECT quarter, locked FROM quarter_locks 
                        WHERE school_attended_id IS NULL 
                        AND (school_year = '$active_school_year' OR school_year IS NULL)
                        ORDER BY school_year DESC");
if ($result) {
    $processed = [];
    while ($row = $result->fetch_assoc()) {
        $q = 'q' . $row['quarter'];
        if (!isset($processed[$q])) {
            $locks[$q] = (bool)$row['locked'];
            $processed[$q] = true;
        }
    }
}

// Get auto-lock times for this school year
$auto_lock_result = $conn->query("SELECT quarter, auto_lock_time FROM quarter_auto_locks 
                                  WHERE school_attended_id IS NULL
                                  AND (school_year = '$active_school_year' OR school_year IS NULL)
                                  ORDER BY school_year DESC");
if ($auto_lock_result) {
    $processed = [];
    while ($row = $auto_lock_result->fetch_assoc()) {
        $q = 'q' . $row['quarter'];
        if (!isset($processed[$q]) && $row['auto_lock_time']) {
            $auto_locks[$q] = date('Y-m-d\TH:i', strtotime($row['auto_lock_time']));
            $processed[$q] = true;
        }
    }
}

// Get auto-unlock times for this school year
$auto_unlock_result = $conn->query("SELECT quarter, auto_unlock_time FROM quarter_auto_unlocks 
                                    WHERE school_attended_id IS NULL
                                    AND (school_year = '$active_school_year' OR school_year IS NULL)
                                    ORDER BY school_year DESC");
if ($auto_unlock_result) {
    $processed = [];
    while ($row = $auto_unlock_result->fetch_assoc()) {
        $q = 'q' . $row['quarter'];
        if (!isset($processed[$q]) && $row['auto_unlock_time']) {
            $auto_unlocks[$q] = date('Y-m-d\TH:i', strtotime($row['auto_unlock_time']));
            $processed[$q] = true;
        }
    }
}

// Set current page for sidebar navigation and school year switching
$_SERVER['PHP_SELF'] = 'manage_quarter_locks.php';

include "../templates/header.php";
?>

<style>
.form-check-input:checked {
    background-color: #dc3545;
    border-color: #dc3545;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
</style>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-lock-fill"></i> Manage Quarter Locks</h2>
        <p class="subtitle">Control grade editing permissions for SY <?php echo htmlspecialchars($active_school_year); ?></p>
    </div>
</div>

<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    <strong>School Year Specific Quarter Lock:</strong>
    When you lock a quarter, it will be locked for <strong>all students in school year <?php echo htmlspecialchars($active_school_year); ?></strong>.
    Other school years remain unaffected.
</div>

<div class="row">
    <?php for ($q = 1; $q <= 4; $q++): ?>
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-<?= $q ?>-circle"></i> Quarter <?= $q ?></h5>
                <div class="form-check form-switch">
                    <input class="form-check-input" 
                           type="checkbox" 
                           id="lock-q<?= $q ?>" 
                           <?= $locks['q'.$q] ? 'checked' : '' ?>
                           onchange="toggleQuarterLock(<?= $q ?>)">
                    <label class="form-check-label" for="lock-q<?= $q ?>">
                        <span id="lock-q<?= $q ?>-label" class="<?= $locks['q'.$q] ? 'text-danger fw-bold' : 'text-success' ?>">
                            <?= $locks['q'.$q] ? 'Locked' : 'Unlocked' ?>
                        </span>
                    </label>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-12 mb-3">
                        <label class="form-label"><i class="bi bi-lock-fill"></i> Auto-Lock Time</label>
                        <div class="input-group">
                            <input type="datetime-local" 
                                   id="autoLockQ<?= $q ?>" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($auto_locks['q'.$q]) ?>"
                                   onchange="updateClearButton(<?= $q ?>, 'lock')">
                            <button class="btn btn-primary btn-sm" onclick="setAutoLockTime(<?= $q ?>)">Save</button>
                            <button class="btn <?= $auto_locks['q'.$q] ? 'btn-danger' : 'btn-outline-secondary' ?> btn-sm" 
                                    id="clearLockQ<?= $q ?>" 
                                    onclick="clearAutoLock(<?= $q ?>)">Clear</button>
                        </div>
                        <small class="text-muted">Quarter locks automatically at this time</small>
                    </div>
                    
                    <div class="col-12">
                        <label class="form-label"><i class="bi bi-unlock-fill"></i> Auto-Unlock Time</label>
                        <div class="input-group">
                            <input type="datetime-local" 
                                   id="autoUnlockQ<?= $q ?>" 
                                   class="form-control" 
                                   value="<?= htmlspecialchars($auto_unlocks['q'.$q]) ?>"
                                   onchange="updateClearButton(<?= $q ?>, 'unlock')">
                            <button class="btn btn-primary btn-sm" onclick="setAutoUnlockTime(<?= $q ?>)">Save</button>
                            <button class="btn <?= $auto_unlocks['q'.$q] ? 'btn-danger' : 'btn-outline-secondary' ?> btn-sm" 
                                    id="clearUnlockQ<?= $q ?>" 
                                    onclick="clearAutoUnlock(<?= $q ?>)">Clear</button>
                        </div>
                        <small class="text-muted">Quarter unlocks automatically at this time</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endfor; ?>
</div>

<!-- Success/Warning Modal -->
<div class="modal fade" id="messageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="messageModalTitle">Notice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="messageModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalTitle">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirmModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmYes">Yes</button>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>

<script>
function showWarning(message, title = 'Notice') {
    document.getElementById('messageModalTitle').textContent = title;
    document.getElementById('messageModalBody').textContent = message;
    const modal = new bootstrap.Modal(document.getElementById('messageModal'));
    modal.show();
}

function showConfirm(message, title = 'Confirm Action') {
    return new Promise((resolve) => {
        document.getElementById('confirmModalTitle').textContent = title;
        document.getElementById('confirmModalBody').textContent = message;
        const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
        const yesBtn = document.getElementById('confirmYes');
        
        yesBtn.onclick = () => {
            modal.hide();
            resolve(true);
        };
        
        document.getElementById('confirmModal').addEventListener('hidden.bs.modal', function () {
            resolve(false);
        }, { once: true });
        
        modal.show();
    });
}

function updateClearButton(quarter, type) {
    const input = document.getElementById(`auto${type === 'lock' ? 'Lock' : 'Unlock'}Q${quarter}`);
    const button = document.getElementById(`clear${type === 'lock' ? 'Lock' : 'Unlock'}Q${quarter}`);
    
    if (button && input) {
        if (input.value) {
            button.className = 'btn btn-danger btn-sm';
        } else {
            button.className = 'btn btn-outline-secondary btn-sm';
        }
    }
}

async function toggleQuarterLock(quarter) {
    const checkbox = document.getElementById(`lock-q${quarter}`);
    const label = document.getElementById(`lock-q${quarter}-label`);
    const locked = checkbox.checked ? 1 : 0;
    
    const action = locked ? 'lock' : 'unlock';
    const confirmed = await showConfirm(`Are you sure you want to ${action} Quarter ${quarter}?`, 'Confirm ' + (locked ? 'Lock' : 'Unlock'));
    
    if (!confirmed) {
        checkbox.checked = !checkbox.checked;
        return;
    }
    
    fetch('grades.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=toggle_quarter_lock&quarter=${quarter}&locked=${locked}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (label) {
                label.textContent = checkbox.checked ? 'Locked' : 'Unlocked';
                label.className = checkbox.checked ? 'text-danger fw-bold' : 'text-success';
            }
            showWarning(`Quarter ${quarter} ${checkbox.checked ? 'locked' : 'unlocked'} successfully`, 'Success');
        } else {
            showWarning('Failed to update quarter lock: ' + (data.message || 'Unknown error'), 'Error');
            checkbox.checked = !checkbox.checked;
        }
    })
    .catch(error => {
        console.error('Error toggling quarter lock:', error);
        showWarning('Failed to update quarter lock', 'Error');
        checkbox.checked = !checkbox.checked;
    });
}

function setAutoLockTime(quarter) {
    const timeInput = document.getElementById(`autoLockQ${quarter}`);
    const lockTime = timeInput.value;
    
    if (!lockTime) {
        showWarning('Please select a date and time for auto-lock');
        return;
    }
    
    fetch('grades.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=set_auto_lock_time&quarter=${quarter}&lock_time=${lockTime}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateClearButton(quarter, 'lock');
            showWarning(`Auto-lock scheduled for Quarter ${quarter} at ${lockTime}`, 'Success');
        } else {
            showWarning('Failed to set auto-lock time: ' + (data.message || 'Unknown error'), 'Error');
        }
    })
    .catch(error => {
        console.error('Error setting auto-lock time:', error);
        showWarning('Failed to set auto-lock time', 'Error');
    });
}

function setAutoUnlockTime(quarter) {
    const timeInput = document.getElementById(`autoUnlockQ${quarter}`);
    const unlockTime = timeInput.value;
    
    if (!unlockTime) {
        showWarning('Please select a date and time for auto-unlock');
        return;
    }
    
    fetch('grades.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=set_auto_unlock_time&quarter=${quarter}&unlock_time=${unlockTime}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateClearButton(quarter, 'unlock');
            showWarning(`Auto-unlock scheduled for Quarter ${quarter} at ${unlockTime}`, 'Success');
        } else {
            showWarning('Failed to set auto-unlock time: ' + (data.message || 'Unknown error'), 'Error');
        }
    })
    .catch(error => {
        console.error('Error setting auto-unlock time:', error);
        showWarning('Failed to set auto-unlock time', 'Error');
    });
}

async function clearAutoLock(quarter) {
    const timeInput = document.getElementById(`autoLockQ${quarter}`);
    
    if (!timeInput.value) {
        showWarning('No auto-lock schedule set for this quarter', 'Notice');
        return;
    }
    
    const confirmed = await showConfirm(`Are you sure you want to clear the auto-lock schedule for Quarter ${quarter}?`, 'Confirm Clear Schedule');
    
    if (!confirmed) {
        return;
    }
    
    fetch('grades.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=clear_auto_lock&quarter=${quarter}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById(`autoLockQ${quarter}`).value = '';
            updateClearButton(quarter, 'lock');
            showWarning(`Auto-lock schedule cleared for Quarter ${quarter}`, 'Success');
        } else {
            showWarning('Failed to clear auto-lock schedule: ' + (data.message || 'Unknown error'), 'Error');
        }
    })
    .catch(error => {
        console.error('Error clearing auto-lock:', error);
        showWarning('Failed to clear auto-lock schedule', 'Error');
    });
}

async function clearAutoUnlock(quarter) {
    const timeInput = document.getElementById(`autoUnlockQ${quarter}`);
    
    if (!timeInput.value) {
        showWarning('No auto-unlock schedule set for this quarter', 'Notice');
        return;
    }
    
    const confirmed = await showConfirm(`Are you sure you want to clear the auto-unlock schedule for Quarter ${quarter}?`, 'Confirm Clear Schedule');
    
    if (!confirmed) {
        return;
    }
    
    fetch('grades.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=clear_auto_unlock&quarter=${quarter}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById(`autoUnlockQ${quarter}`).value = '';
            updateClearButton(quarter, 'unlock');
            showWarning(`Auto-unlock schedule cleared for Quarter ${quarter}`, 'Success');
        } else {
            showWarning('Failed to clear auto-unlock schedule: ' + (data.message || 'Unknown error'), 'Error');
        }
    })
    .catch(error => {
        console.error('Error clearing auto-unlock:', error);
        showWarning('Failed to clear auto-unlock schedule', 'Error');
    });
}
</script>
