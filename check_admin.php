<?php
require_once 'includes/db.php';

echo "<h3>System Setup Check:</h3>";

// Check admin accounts
echo "<h4>1. Checking Admin Accounts:</h4>";
$result = $conn->query("SELECT id, username, full_name, role FROM users WHERE role = 'admin'");

if ($result->num_rows > 0) {
    echo "<p style='color: green;'>✓ Admin accounts found: " . $result->num_rows . "</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['username']}</td>";
        echo "<td>{$row['full_name']}</td>";
        echo "<td>{$row['role']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>✗ NO ADMIN ACCOUNTS FOUND!</p>";
    echo "<p>Creating default admin account...</p>";
    
    $password = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, created_at) VALUES (?, ?, ?, 'admin', NOW())");
    $username = 'admin';
    $fullname = 'System Administrator';
    $stmt->bind_param("sss", $username, $password, $fullname);
    
    if ($stmt->execute()) {
        echo "<p style='color: green;'>✓ Admin account created!</p>";
        echo "<p><strong>Username:</strong> admin</p>";
        echo "<p><strong>Password:</strong> admin123</p>";
    } else {
        echo "<p style='color: red;'>Failed to create admin account: " . $conn->error . "</p>";
    }
}

// Check school years
echo "<br><h4>2. Checking School Years:</h4>";
$sy_result = $conn->query("SELECT * FROM school_years ORDER BY year DESC");

if ($sy_result && $sy_result->num_rows > 0) {
    echo "<p style='color: green;'>✓ School years found: " . $sy_result->num_rows . "</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Year</th><th>Active</th><th>Status</th><th>Start Date</th><th>End Date</th></tr>";
    while ($row = $sy_result->fetch_assoc()) {
        $active_badge = $row['is_active'] ? '<span style="color: green;">✓ Active</span>' : '';
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['year']}</td>";
        echo "<td>{$active_badge}</td>";
        echo "<td>{$row['status']}</td>";
        echo "<td>{$row['start_date']}</td>";
        echo "<td>{$row['end_date']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>✗ NO SCHOOL YEARS FOUND!</p>";
    echo "<p>Creating default school year...</p>";
    
    // Create current school year (2025-2026)
    $currentYear = date("Y");
    $nextYear = $currentYear + 1;
    $schoolYear = "$currentYear-$nextYear";
    $startDate = "$currentYear-06-01";
    $endDate = "$nextYear-03-31";
    
    $stmt = $conn->prepare("INSERT INTO school_years (year, is_active, status, start_date, end_date, created_at) VALUES (?, 1, 'active', ?, ?, NOW())");
    $stmt->bind_param("sss", $schoolYear, $startDate, $endDate);
    
    if ($stmt->execute()) {
        echo "<p style='color: green;'>✓ School year created: {$schoolYear}</p>";
        echo "<p><strong>Start Date:</strong> {$startDate}</p>";
        echo "<p><strong>End Date:</strong> {$endDate}</p>";
    } else {
        echo "<p style='color: red;'>Failed to create school year: " . $conn->error . "</p>";
    }
}

echo "<br><hr><p style='font-size: 18px; color: green;'><strong>✓ Setup Complete!</strong></p>";
echo "<p><a href='login.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Go to Login</a></p>";
?>
