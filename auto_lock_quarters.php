<?php
/**
 * Auto-Lock Quarters Cron Job
 * 
 * This script should be run periodically (e.g., every hour) via cron job or Windows Task Scheduler
 * to automatically lock quarters when their scheduled auto-lock time has passed.
 * 
 * Setup Instructions:
 * 
 * Linux/Mac (Cron Job):
 * Add to crontab: 0 * * * * php /path/to/auto_lock_quarters.php
 * (Runs every hour on the hour)
 * 
 * Windows (Task Scheduler):
 * Create a scheduled task to run: php.exe "c:\xampp\htdocs\sf10_system\auto_lock_quarters.php"
 * Set trigger: Daily, repeat every 1 hour
 */

// Prevent web access - only allow CLI or internal calls
if (php_sapi_name() !== 'cli' && !isset($_GET['run_auto_lock'])) {
    die('This script can only be run from command line or with ?run_auto_lock parameter');
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/logger.php';

// Set timezone to Manila
date_default_timezone_set('Asia/Manila');

echo "=== Quarter Auto-Lock Job Started ===\n";
echo "Time: " . date('Y-m-d H:i:s') . " (Manila Time)\n\n";

$locked_count = 0;
$error_count = 0;

try {
    // Get all auto-lock schedules where the time has passed and quarter is not yet locked
    $query = "SELECT qal.id, qal.school_attended_id, qal.quarter, qal.auto_lock_time,
                     sa.student_id, sa.grade_level, sa.section, sa.school_year,
                     CONCAT(s.last_name, ', ', s.first_name) as student_name
              FROM quarter_auto_locks qal
              JOIN schools_attended sa ON qal.school_attended_id = sa.id
              JOIN students s ON sa.student_id = s.id
              WHERE qal.auto_lock_time <= NOW()
              AND NOT EXISTS (
                  SELECT 1 FROM quarter_locks ql 
                  WHERE ql.school_attended_id = qal.school_attended_id 
                  AND ql.quarter = qal.quarter 
                  AND ql.locked = 1
              )
              ORDER BY qal.auto_lock_time ASC";
    
    $result = $conn->query($query);
    
    if ($result->num_rows === 0) {
        echo "No quarters need to be auto-locked at this time.\n";
    } else {
        echo "Found {$result->num_rows} quarter(s) to auto-lock:\n\n";
        
        while ($row = $result->fetch_assoc()) {
            $school_id = $row['school_attended_id'];
            $quarter = $row['quarter'];
            $student_name = $row['student_name'];
            $grade_level = $row['grade_level'];
            $section = $row['section'];
            $school_year = $row['school_year'];
            $scheduled_time = $row['auto_lock_time'];
            
            echo "Processing: Student '{$student_name}' - Grade {$grade_level} ({$section}) - SY {$school_year} - Q{$quarter}\n";
            echo "  Scheduled: {$scheduled_time}\n";
            
            // Check if lock record already exists
            $check = $conn->prepare("SELECT id, locked FROM quarter_locks WHERE school_attended_id = ? AND quarter = ?");
            $check->bind_param("ii", $school_id, $quarter);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            
            if ($existing) {
                if ($existing['locked'] == 1) {
                    echo "  Status: Already locked (skipping)\n\n";
                    continue;
                }
                
                // Update existing record to locked
                $stmt = $conn->prepare("UPDATE quarter_locks SET locked = 1, updated_at = NOW() WHERE school_attended_id = ? AND quarter = ?");
                $stmt->bind_param("ii", $school_id, $quarter);
            } else {
                // Insert new lock record
                $stmt = $conn->prepare("INSERT INTO quarter_locks (school_attended_id, quarter, locked) VALUES (?, ?, 1)");
                $stmt->bind_param("ii", $school_id, $quarter);
            }
            
            if ($stmt->execute()) {
                echo "  Status: ✓ Successfully locked\n";
                
                // Log the auto-lock action (use system user ID = 1 or 0 for automated actions)
                logActivity($conn, 1, 'AUTO_LOCK', 'quarter_locks', $school_id, 
                    "Auto-locked Quarter {$quarter} for {$student_name} - Grade {$grade_level} ({$section}) - SY {$school_year}");
                
                $locked_count++;
            } else {
                echo "  Status: ✗ Failed to lock - " . $stmt->error . "\n";
                $error_count++;
            }
            
            echo "\n";
        }
    }
    
    echo "\n=== Summary ===\n";
    echo "Quarters locked: {$locked_count}\n";
    echo "Errors: {$error_count}\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . " (Manila Time)\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Auto-lock quarters error: " . $e->getMessage());
}

$conn->close();
?>
