<?php
session_start();
include "includes/db.php";
include "includes/logger.php";

// Log logout activity before destroying session
if (isset($_SESSION['user'])) {
    $user = $_SESSION['user'];
    logActivity($conn, $user['id'], 'LOGOUT', 'users', $user['id'], 
               "User logged out: {$user['full_name']} ({$user['role']})");
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