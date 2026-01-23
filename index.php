<?php
/**
 * Main index file for SF10 System.
 * Redirects to dashboard if logged in, otherwise to login page.
 */
session_start();

if (isset($_SESSION['user'])) {
    header("Location: pages/dashboard.php");
} else {
    header("Location: login.php");
}
exit();
?>