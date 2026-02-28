<?php
session_start();
include '../includes/db.php';
include '../includes/totp_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$msg_type = ''; // 'success', 'danger', 'info', 'warning'

// Check if 2fa_on_login column exists, if not create it (Simple migration fallback)
$check = $conn->query("SHOW COLUMNS FROM users LIKE '2fa_on_login'");
if ($check->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN `2fa_on_login` TINYINT(1) DEFAULT 0 AFTER `totp_secret`") or die($conn->error);
}

// Fetch current user details
$stmt = $conn->prepare("SELECT username, totp_secret, `2fa_on_login` FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Sync session with fresh DB data to ensure header matches
if ($user && isset($_SESSION['user'])) {
    $_SESSION['user']['totp_secret'] = $user['totp_secret'];
    $_SESSION['user']['2fa_on_login'] = $user['2fa_on_login'];
}

// Handle toggle 2FA on Login
if (isset($_POST['toggle_2fa_login'])) {
    $new_val = isset($_POST['2fa_on_login_toggle']) ? 1 : 0;
    $update_stmt = $conn->prepare("UPDATE users SET `2fa_on_login` = ? WHERE id = ?");
    $update_stmt->bind_param("ii", $new_val, $user_id);
    if ($update_stmt->execute()) {
        $message = "Login verification preference updated!";
        $msg_type = 'success';
        $user['2fa_on_login'] = $new_val;
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['2fa_on_login'] = $new_val;
        }
    }
}

// Handle cancel setup
if (isset($_GET['cancel'])) {
    unset($_SESSION['temp_secret']);
    header("Location: setup_2fa.php");
    exit();
}

// If form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['enable_2fa'])) {
        // Generate new secret
        $secret = TOTP::createSecret();
        // Temporarily store in session to verify first
        $_SESSION['temp_secret'] = $secret;
        $message = "Please scan the QR code and enter the verification code to confirm.";
        $msg_type = 'info';
    } elseif (isset($_POST['verify_2fa'])) {
        $code = trim($_POST['code']);
        $secret = $_SESSION['temp_secret'] ?? '';
        
        if ($secret && TOTP::verifyCode($secret, $code)) {
            // Save secret to DB
            $update_stmt = $conn->prepare("UPDATE users SET totp_secret = ? WHERE id = ?");
            $update_stmt->bind_param("si", $secret, $user_id);
            if ($update_stmt->execute()) {
                $message = "Two-Factor Authentication enabled successfully!";
                $msg_type = 'success';
                // Refresh user data and session
                $user['totp_secret'] = $secret;
                if (isset($_SESSION['user'])) {
                    $_SESSION['user']['totp_secret'] = $secret;
                }
                unset($_SESSION['temp_secret']);
            } else {
                $message = "Error saving secret: " . $conn->error;
                $msg_type = 'danger';
            }
        } else {
            $message = "Invalid verification code. Please try again.";
            $msg_type = 'danger';
        }
    } elseif (isset($_POST['disable_2fa'])) {
        $update_stmt = $conn->prepare("UPDATE users SET totp_secret = NULL WHERE id = ?");
        $update_stmt->bind_param("i", $user_id);
        if ($update_stmt->execute()) {
            $message = "Two-Factor Authentication disabled.";
            $msg_type = 'warning';
            $user['totp_secret'] = null;
            if (isset($_SESSION['user'])) {
                $_SESSION['user']['totp_secret'] = null;
            }
        }
    }
}

include '../templates/header.php';
?>

<div class="page-header">
    <h2><i class="bi bi-shield-lock"></i> Security Settings</h2>
    <p class="subtitle">Manage your account security and Two-Factor Authentication</p>
</div>

<div class="row">
    <div class="col-md-8 mx-auto">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-qr-code-scan me-2"></i>Two-Factor Authentication (2FA)</span>
                <?php if (!empty($user['totp_secret'])): ?>
                    <span class="badge bg-success rounded-pill px-3">Enabled</span>
                <?php else: ?>
                    <span class="badge bg-danger rounded-pill px-3">Disabled</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-4">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?= $msg_type == 'success' ? 'check-circle' : ($msg_type == 'danger' ? 'exclamation-circle' : 'info-circle') ?>-fill me-2"></i>
                        <?= htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (empty($user['totp_secret']) && !isset($_SESSION['temp_secret'])): ?>
                    <!-- State 1: 2FA Not Enabled -->
                    <div class="text-center py-4">
                        <div class="mb-4">
                            <span class="d-inline-flex align-items-center justify-content-center bg-danger-subtle rounded-circle" style="width: 80px; height: 80px;">
                                <i class="bi bi-shield-exclamation text-danger" style="font-size: 2.5rem;"></i>
                            </span>
                        </div>
                        <h4 class="mb-3">Secure your account</h4>
                        <p class="text-muted mb-4 mx-auto" style="max-width: 500px;">
                            Two-Factor Authentication adds an extra layer of security to your account by requiring a code from your phone in addition to your password.
                        </p>
                        <form method="POST">
                            <button type="submit" name="enable_2fa" class="btn btn-primary px-5" style="background-color: #449999; border-color: #449999; height: 48px; border-radius: 12px; font-weight: 700;">
                                <i class="bi bi-shield-plus me-2"></i>Enable 2FA
                            </button>
                        </form>
                    </div>
                
                <?php elseif (isset($_SESSION['temp_secret'])): ?>
                    <!-- State 2: Setup in Progress -->
                    <div class="row align-items-center">
                        <div class="col-md-6 text-center border-end-md">
                            <h5 class="mb-3">Step 1: Scan QR Code</h5>
                            <p class="small text-muted mb-3">Use Google Authenticator or Authy app to scan this code</p>
                            
                            <!-- QR Code -->
                            <?php 
                            $otpAuthUrl = TOTP::getQRCodeUrl($user['username'], $_SESSION['temp_secret'], 'SF10 System');
                            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($otpAuthUrl);
                            ?>
                            <div class="d-inline-block border p-2 rounded bg-white mb-3">
                                <img src="<?php echo $qrUrl; ?>" alt="QR Code" class="img-fluid" style="width: 180px; height: 180px;">
                            </div>
                            
                            <div class="alert alert-light border d-inline-block text-start">
                                <small class="d-block text-muted mb-1">Or enter secret key manually:</small>
                                <code class="user-select-all fw-bold text-dark"><?php echo $_SESSION['temp_secret']; ?></code>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mt-4 mt-md-0">
                            <div class="px-md-3">
                                <h5 class="mb-3">Step 2: Verify Code</h5>
                                <p class="small text-muted mb-3">Enter the 6-digit code generated by your authenticator app to verify setup.</p>
                                
                                <form method="POST">
                                    <div class="mb-4">
                                        <label class="form-label text-muted text-uppercase small fw-bold">Verification Code</label>
                                        <input type="text" name="code" class="form-control form-control-lg text-center font-monospace" placeholder="000000" required pattern="[0-9]{6}" autocomplete="off" maxlength="6" style="letter-spacing: 4px;">
                                    </div>
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="verify_2fa" class="btn btn-primary" style="background-color: #449999; border-color: #449999; height: 48px; border-radius: 12px; font-weight: 700;">
                                            Verify & Enable
                                        </button>
                                    </div>
                                    <div class="text-center mt-3">
                                        <a href="setup_2fa.php?cancel=1" class="text-decoration-none text-muted">Cancel Setup</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- State 3: 2FA Active -->
                    <div class="text-center py-4">
                        <div class="mb-4">
                            <span class="d-inline-flex align-items-center justify-content-center bg-success-subtle rounded-circle" style="width: 80px; height: 80px;">
                                <i class="bi bi-shield-lock-fill text-success" style="font-size: 2.5rem;"></i>
                            </span>
                        </div>
                        <h4 class="mb-2 text-success">2FA is Active</h4>
                        <p class="text-muted mb-4">Your account is securely protected with Two-Factor Authentication.</p>
                        
                        <div class="alert alert-warning border-warning d-inline-block text-start mb-4" style="max-width: 500px;">
                            <div class="d-flex">
                                <i class="bi bi-exclamation-triangle-fill me-3 fs-4"></i>
                                <div>
                                    <strong>Warning:</strong> Disabling 2FA will make your account less secure.
                                    Only disable if you are switching devices or experiencing issues.
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to disable 2FA? This will reduce your account security.');">
                                <button type="submit" name="disable_2fa" class="btn btn-danger">
                                    <i class="bi bi-shield-slash me-2"></i>Disable 2FA
                                </button>
                            </form>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-1"><i class="bi bi-box-arrow-in-right me-2"></i>Require 2FA on Login</h5>
                            <p class="text-muted small mb-0">When enabled, you will be asked for an authenticator code every time you log in.</p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <form method="POST">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input" type="checkbox" name="2fa_on_login_toggle" id="2faOnLoginToggle" style="width: 3.5em; height: 1.75em;" onchange="this.form.submit()" <?php echo ($user['2fa_on_login'] ? 'checked' : ''); ?>>
                                    <input type="hidden" name="toggle_2fa_login" value="1">
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        

    </div>
</div>

<style>
/* Custom responsive adjustments */
@media (min-width: 768px) {
    .border-end-md {
        border-right: 1px solid var(--border-color) !important;
    }
}
</style>

<?php include '../templates/footer.php'; ?>
