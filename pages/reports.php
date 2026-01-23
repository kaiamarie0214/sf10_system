<?php
require_once '../includes/db.php';
include '../templates/header.php';

// Admin only access
if (!$is_admin) {
    header("Location: dashboard.php");
    exit();
}

// Get statistics for reports
$total_students = $conn->query("SELECT COUNT(*) as count FROM students")->fetch_assoc()['count'];
$total_subjects = $conn->query("SELECT COUNT(*) as count FROM subjects")->fetch_assoc()['count'];
$total_grades = $conn->query("SELECT COUNT(*) as count FROM grades")->fetch_assoc()['count'];
?>

<div class="page-header">
    <h2><i class="bi bi-file-earmark-text"></i> Reports</h2>
    <p class="subtitle">Generate and view system reports</p>
</div>

<div class="row">
    <!-- Student Reports -->
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-people"></i> Student Reports
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">
                    <a href="#" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-pdf"></i> Student List (PDF)
                    </a>
                    <a href="#" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-excel"></i> Student Data (Excel)
                    </a>
                    <a href="#" class="list-group-item list-group-item-action">
                        <i class="bi bi-card-list"></i> Student Profiles
                    </a>
                </div>
                <div class="mt-3 text-center">
                    <small class="text-muted">Total Students: <strong><?= $total_students ?></strong></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Grade Reports -->
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <i class="bi bi-journal-text"></i> Grade Reports
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">
                    <a href="sf10_form.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-earmark-excel"></i> SF10 Form (Official DepEd Excel Template)
                    </a>
                    <a href="#" class="list-group-item list-group-item-action">
                        <i class="bi bi-graph-up"></i> Grade Summary
                    </a>
                    <a href="#" class="list-group-item list-group-item-action">
                        <i class="bi bi-award"></i> Honor Roll
                    </a>
                </div>
                <div class="mt-3 text-center">
                    <small class="text-muted">Total Grades: <strong><?= $total_grades ?></strong></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Academic Reports -->
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header bg-info text-white">
                <i class="bi bi-book"></i> Academic Reports
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">
                    <a href="#" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-pdf"></i> Subject List
                    </a>
                    <a href="#" class="list-group-item list-group-item-action">
                        <i class="bi bi-bar-chart"></i> Performance Analysis
                    </a>
                    <a href="#" class="list-group-item list-group-item-action">
                        <i class="bi bi-calendar-check"></i> Attendance Report
                    </a>
                </div>
                <div class="mt-3 text-center">
                    <small class="text-muted">Total Subjects: <strong><?= $total_subjects ?></strong></small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Report Generator -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-sliders"></i> Custom Report Generator
    </div>
    <div class="card-body">
        <form>
            <div class="row">
                <div class="col-md-3">
                    <label class="form-label">Report Type</label>
                    <select class="form-select">
                        <option>Student Report</option>
                        <option>Grade Report</option>
                        <option>Subject Report</option>
                        <option>Attendance Report</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Format</label>
                    <select class="form-select">
                        <option>PDF</option>
                        <option>Excel</option>
                        <option>CSV</option>
                    </select>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download"></i> Generate Report
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
