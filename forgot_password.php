<?php
session_start();
include 'includes/db.php';
include 'includes/totp_helper.php';

$step = 1;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['check_username'])) {
        $username = trim($_POST['username']);
        
        $stmt = $conn->prepare("SELECT id, totp_secret FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['totp_secret'])) {
                $_SESSION['reset_user_id'] = $row['id'];
                $_SESSION['reset_totp_secret'] = $row['totp_secret'];
                $step = 2;
            } else {
                $error = "This account does not have Two-Factor Authentication enabled. Please contact the administrator to reset your password.";
            }
        } else {
            // Generic message for security
            $error = "Username not found.";
        }
    } elseif (isset($_POST['verify_code'])) {
        $code = trim($_POST['code']);
        $secret = $_SESSION['reset_totp_secret'];
        
        if (TOTP::verifyCode($secret, $code)) {
            $step = 3;
            $_SESSION['reset_verified'] = true;
        } else {
            $error = "Invalid verification code.";
            $step = 2;
        }
    } elseif (isset($_POST['reset_password'])) {
        if (!isset($_SESSION['reset_verified']) || !$_SESSION['reset_verified']) {
            header("Location: forgot_password.php");
            exit();
        }

        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password === $confirm_password) {
            // Password strength check could go here
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $user_id = $_SESSION['reset_user_id'];
            
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $user_id);
            
            if ($stmt->execute()) {
                $success = "Password has been reset successfully. You can now <a href='login.php'>login</a>.";
                // Clear session
                unset($_SESSION['reset_user_id']);
                unset($_SESSION['reset_totp_secret']);
                unset($_SESSION['reset_verified']);
                $step = 4;
            } else {
                $error = "Error updating password: " . $conn->error;
                $step = 3;
            }
        } else {
            $error = "Passwords do not match.";
            $step = 3;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - SF10 System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .login-container { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .login-header { text-align: center; margin-bottom: 30px; }
        .login-header h2 { margin: 0; color: #333; }
        .form-group { margin-bottom: 20px; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; font-size: 16px; }
        .btn { width: 100%; padding: 12px; background-color: #007bff; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; transition: background 0.3s; }
        .btn:hover { background-color: #0056b3; }
        .alert { padding: 12px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .back-link { display: block; text-align: center; margin-top: 15px; color: #6c757d; text-decoration: none; }
        .back-link:hover { color: #333; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h2>Reset Password</h2>
            <p>Secure Account Recovery</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php else: ?>

            <?php if ($step === 1): ?>
                <form method="POST">
                    <div class="form-group">
                        <input type="text" name="username" class="form-control" placeholder="Enter your username" required>
                    </div>
                    <button type="submit" name="check_username" class="btn">Next</button>
                </form>
            <?php elseif ($step === 2): ?>
                <form method="POST">
                    <div class="alert alert-info" style="background-color: #e2e3e5; color: #383d41; border: 1px solid #d6d8db;">
                        Enter the 6-digit code from your Authenticator App.
                    </div>
                    <div class="form-group">
                        <input type="text" name="code" class="form-control" placeholder="000000" pattern="[0-9]{6}" required autofocus>
                    </div>
                    <button type="submit" name="verify_code" class="btn">Verify</button>
                </form>
            <?php elseif ($step === 3): ?>
                <form method="POST">
                    <div class="form-group">
                        <input type="password" name="new_password" class="form-control" placeholder="New Password" required minlength="6">
                    </div>
                    <div class="form-group">
                        <input type="password" name="confirm_password" class="form-control" placeholder="Confirm New Password" required minlength="6">
                    </div>
                    <button type="submit" name="reset_password" class="btn">Reset Password</button>
                </form>
            <?php endif; ?>

        <?php endif; ?>

        <?php if ($step !== 4): ?>
            <a href="login.php" class="back-link">Back to Login</a>
        <?php endif; ?>
    </div>
</body>
</html>
