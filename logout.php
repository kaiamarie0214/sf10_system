<?php
session_start();
include "includes/db.php";
include "includes/logger.php";

// Log logout activity before destroying session
if (isset($_SESSION['user'])) {
    $user = $_SESSION['user'];
    
    // Clear remember token in DB
    $stmt = $conn->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    
    logActivity($conn, $user['id'], 'LOGOUT', 'users', $user['id'], 
               "User logged out: {$user['full_name']} ({$user['role']})");
}

// Clear remember_me cookie
if (isset($_COOKIE['remember_me'])) {
    setcookie('remember_me', '', time() - 3600, '/');
}

session_destroy();
?>
<!DOCTYPE html>
<html>
<head>
    <script>
        // Clear all sidebar dropdown states from localStorage on logout
        const keys = Object.keys(localStorage);
        keys.forEach(key => {
            if (key.startsWith('sidebar-')) {
                localStorage.removeItem(key);
            }
        });
        // Redirect to login
        window.location.href = 'login.php';
    </script>
</head>
<body></body>
</html>