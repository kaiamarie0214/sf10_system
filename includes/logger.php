<?php
// Activity logging helper function
function logActivity($conn, $user_id, $action, $table_name, $record_id, $details = null) {
    $stmt = $conn->prepare("INSERT INTO change_logs (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issis", $user_id, $action, $table_name, $record_id, $details);
    return $stmt->execute();
}
?>
