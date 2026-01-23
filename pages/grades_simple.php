<?php
include "../templates/header.php";
include "../includes/db.php";
include "../includes/logger.php";

$students = $conn->query("SELECT id, CONCAT(last_name, ', ', first_name) AS fullname FROM students ORDER BY last_name");
$subjects = $conn->query("SELECT id, subject_name FROM subjects ORDER BY id");

$school_history = [];
if (!empty($_GET['student_id'])) {
    $stmt = $conn->prepare("SELECT id, CONCAT(grade_level, ' - ', school_year, ' (', school_name, ')') AS label
                            FROM schools_attended WHERE student_id = ? ORDER BY school_year");
    $stmt->bind_param("i", $_GET['student_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $school_history[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $all_final_ratings = [];
    $ga_subject_id = $conn->query("SELECT id FROM subjects WHERE subject_name='General Average' LIMIT 1")->fetch_assoc()['id'];
    
    $grades_entered = 0;

    foreach ($_POST['grades'] as $subject_id => $data) {
        $final_rating = !empty($data['final_rating']) ? floatval($data['final_rating']) : null;
        $remarks = !empty($data['remarks']) ? $data['remarks'] : null;

        if ($final_rating && $subject_id != $ga_subject_id) {
            $all_final_ratings[] = $final_rating;
        }

        for ($q = 1; $q <= 4; $q++) {
            $grade = !empty($data['q' . $q]) ? floatval($data['q' . $q]) : null;
            
            if ($grade !== null) {
                $grades_entered++;
            }
            
            $stmt = $conn->prepare("INSERT INTO grades
                (student_id, school_attended_id, subject_id, quarter, grade, final_rating, remarks, is_general_average, teacher_id, school_year)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
                ON DUPLICATE KEY UPDATE
                grade = VALUES(grade), final_rating = VALUES(final_rating), remarks = VALUES(remarks)");
            $stmt->bind_param(
                "iiidsssis",
                $_POST['student_id'],
                $_POST['school_attended_id'],
                $subject_id,
                $q,
                $grade,
                $final_rating,
                $remarks,
                $_SESSION['user']['id'],
                $_POST['school_year']
            );
            $stmt->execute();
        }
    }
    
    // Log the grade entry activity
    if ($grades_entered > 0) {
        $student_info = $conn->query("SELECT CONCAT(first_name, ' ', last_name) as name FROM students WHERE id = {$_POST['student_id']}")->fetch_assoc();
        logActivity($conn, $_SESSION['user']['id'], 'GRADE_ENTRY', 'grades', $_POST['student_id'], 
                   "Entered $grades_entered grade(s) for student: {$student_info['name']} (SY: {$_POST['school_year']})");
    }

    $success = true;
}
?>

<div class="p-4">
  <h3>📝 Grades Entry (SF10)</h3>
  <?php if (isset($success)) echo "<div class='alert alert-success'>Grades saved successfully!</div>"; ?>

  <form method="GET" class="mb-3">
    <div class="row">
      <div class="col-md-6">
        <label class="form-label">Select Student</label>
        <select name="student_id" class="form-control" onchange="this.form.submit()">
          <option value="">-- Select --</option>
          <?php while($s = $students->fetch_assoc()): ?>
            <option value="<?= $s['id'] ?>" <?= isset($_GET['student_id']) && $_GET['student_id'] == $s['id'] ? 'selected' : '' ?>>
              <?= $s['fullname'] ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>
    </div>
  </form>

  <?php if (!empty($school_history)): ?>
  <form method="POST">
    <input type="hidden" name="student_id" value="<?= $_GET['student_id'] ?>">

    <div class="row mb-3">
      <div class="col-md-6">
        <label class="form-label">Select School Record</label>
        <select name="school_attended_id" class="form-control" required>
          <option value="">-- Select --</option>
          <?php foreach($school_history as $history): ?>
            <option value="<?= $history['id'] ?>"><?= $history['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">School Year</label>
        <input name="school_year" class="form-control" placeholder="e.g., 2024-2025" required>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered align-middle text-center">
        <thead class="table-primary">
          <tr>
            <th>Learning Areas</th>
            <th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th>
            <th>Final Rating</th><th>Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $subjects->data_seek(0);
          while($sub = $subjects->fetch_assoc()):
            if ($sub['subject_name'] == 'General Average') continue;
            $isMapeh = ($sub['subject_name'] == 'MAPEH');
          ?>
          <tr>
            <td class="text-start fw-bold"><?= $sub['subject_name'] ?></td>

            <?php if ($isMapeh): ?>
              <td id="mapehQ1">0.00</td>
              <td id="mapehQ2">0.00</td>
              <td id="mapehQ3">0.00</td>
              <td id="mapehQ4">0.00</td>
            <?php else: ?>
              <td><input type="number" step="0.01" min="0" max="100" inputmode="decimal" name="grades[<?= $sub['id'] ?>][q1]" class="form-control text-center grade-input" oninput="validateGrade(this);calculateFinalRating(<?= $sub['id'] ?>);calculateMAPEH();calculateGeneralAverage();"></td>
              <td><input type="number" step="0.01" min="0" max="100" inputmode="decimal" name="grades[<?= $sub['id'] ?>][q2]" class="form-control text-center grade-input" oninput="validateGrade(this);calculateFinalRating(<?= $sub['id'] ?>);calculateMAPEH();calculateGeneralAverage();"></td>
              <td><input type="number" step="0.01" min="0" max="100" inputmode="decimal" name="grades[<?= $sub['id'] ?>][q3]" class="form-control text-center grade-input" oninput="validateGrade(this);calculateFinalRating(<?= $sub['id'] ?>);calculateMAPEH();calculateGeneralAverage();"></td>
              <td><input type="number" step="0.01" min="0" max="100" inputmode="decimal" name="grades[<?= $sub['id'] ?>][q4]" class="form-control text-center grade-input" oninput="validateGrade(this);calculateFinalRating(<?= $sub['id'] ?>);calculateMAPEH();calculateGeneralAverage();"></td>
            <?php endif; ?>

            <td><input type="number" step="0.01" min="0" max="100" inputmode="decimal" name="grades[<?= $sub['id'] ?>][final_rating]" class="form-control text-center grade-input" readonly style="background:#e9ecef;"></td>
            <td>
              <select name="grades[<?= $sub['id'] ?>][remarks]" class="form-control text-center" <?= $isMapeh ? 'disabled' : '' ?>>
                <option value="">--</option>
                <option>Passed</option>
                <option>Failed</option>
              </select>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <div class="mt-3">
      <strong>General Average Per Quarter:</strong>
      <table class="table table-bordered text-center mt-2" style="width:300px;">
        <thead class="table-primary">
          <tr><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th>Final</th></tr>
        </thead>
        <tbody>
          <tr>
            <td id="gaQ1">0.00</td>
            <td id="gaQ2">0.00</td>
            <td id="gaQ3">0.00</td>
            <td id="gaQ4">0.00</td>
            <td id="generalAverage">0.00</td>
          </tr>
        </tbody>
      </table>
    </div>

    <button type="submit" class="btn btn-primary mt-2">Save Grades</button>
  </form>
  <?php endif; ?>
</div>

<script>
// Validate grade input (0-100 only)
function validateGrade(input) {
    let value = parseFloat(input.value);
    
    // Remove invalid characters and limit to 2 decimal places
    if (input.value !== '') {
        if (value < 0) {
            input.value = 0;
            input.style.borderColor = '#dc3545';
            setTimeout(() => input.style.borderColor = '', 1500);
        } else if (value > 100) {
            input.value = 100;
            input.style.borderColor = '#dc3545';
            setTimeout(() => input.style.borderColor = '', 1500);
        } else {
            input.style.borderColor = '';
        }
    }
}

function calculateFinalRating(subjectId) {
    let q1 = parseFloat(document.querySelector(`[name="grades[${subjectId}][q1]"]`)?.value) || 0;
    let q2 = parseFloat(document.querySelector(`[name="grades[${subjectId}][q2]"]`)?.value) || 0;
    let q3 = parseFloat(document.querySelector(`[name="grades[${subjectId}][q3]"]`)?.value) || 0;
    let q4 = parseFloat(document.querySelector(`[name="grades[${subjectId}][q4]"]`)?.value) || 0;

    // Count how many quarters have values
    let count = 0;
    let sum = 0;
    
    if (q1 > 0) { sum += q1; count++; }
    if (q2 > 0) { sum += q2; count++; }
    if (q3 > 0) { sum += q3; count++; }
    if (q4 > 0) { sum += q4; count++; }
    
    // Calculate average if at least one quarter has a grade
    if (count > 0) {
        let finalRating = (sum / count).toFixed(2);
        document.querySelector(`[name="grades[${subjectId}][final_rating]"]`).value = finalRating;
        document.querySelector(`[name="grades[${subjectId}][remarks]"]`).value = (finalRating >= 75 ? "Passed" : "Failed");
    } else {
        document.querySelector(`[name="grades[${subjectId}][final_rating]"]`).value = '';
        document.querySelector(`[name="grades[${subjectId}][remarks]"]`).value = '';
    }
}

function calculateMAPEH() {
    const ids = [9, 10, 11, 12]; // Music, Arts, PE, Health
    let quarterValues = [];

    for (let q = 1; q <= 4; q++) {
        let total = 0, count = 0;
        ids.forEach(id => {
            let val = parseFloat(document.querySelector(`[name="grades[${id}][q${q}]"]`)?.value) || 0;
            if (val > 0) { total += val; count++; }
        });

        let avg = (count > 0) ? (total / count).toFixed(2) : "0.00";
        document.getElementById(`mapehQ${q}`).innerText = avg;
        quarterValues[q-1] = parseFloat(avg);
    }

    if (quarterValues.filter(v => v > 0).length === 4) {
        let final = (quarterValues.reduce((a,b)=>a+b,0)/4).toFixed(2);
        let finalInput = document.querySelector(`[name="grades[8][final_rating]"]`);
        if(finalInput){
            finalInput.value = final;
            document.querySelector(`[name="grades[8][remarks]"]`).value = (final >= 75 ? "Passed" : "Failed");
        }
    }
}

function calculateGeneralAverage() {
    const skipIds = [9, 10, 11, 12]; // skip Music, Arts, PE, Health

    let quarterSums = [0, 0, 0, 0];
    let quarterCounts = [0, 0, 0, 0];
    let finalSum = 0, finalCount = 0;

    for (let q = 1; q <= 4; q++) {
        document.querySelectorAll(`input[name*="[q${q}]"]`).forEach(input => {
            let subjectId = parseInt(input.name.match(/\d+/)[0]);
            let val = parseFloat(input.value) || 0;
            if (val && !skipIds.includes(subjectId)) {
                quarterSums[q-1] += val;
                quarterCounts[q-1]++;
            }
        });
        // Include MAPEH calculated quarter too
        if(document.getElementById(`mapehQ${q}`)){
            let mapehVal = parseFloat(document.getElementById(`mapehQ${q}`).innerText) || 0;
            if(mapehVal > 0){
                quarterSums[q-1] += mapehVal;
                quarterCounts[q-1]++;
            }
        }
    }

    document.querySelectorAll('input[name*="[final_rating]"]').forEach(input => {
        let subjectId = parseInt(input.name.match(/\d+/)[0]);
        let val = parseFloat(input.value) || 0;
        if (val && !skipIds.includes(subjectId)) {
            finalSum += val;
            finalCount++;
        }
    });
    // Include MAPEH final too
    let mapehFinal = parseFloat(document.querySelector(`[name="grades[8][final_rating]"]`)?.value) || 0;
    if(mapehFinal > 0){
        finalSum += mapehFinal;
        finalCount++;
    }

    for(let i=0;i<4;i++){
        document.getElementById("gaQ"+(i+1)).textContent = quarterCounts[i] ? (quarterSums[i]/quarterCounts[i]).toFixed(2) : "0.00";
    }
    document.getElementById("generalAverage").textContent = finalCount ? (finalSum/finalCount).toFixed(2) : "0.00";
}
</script>
<?php include '../templates/footer.php'; ?>
</body>
</html>
