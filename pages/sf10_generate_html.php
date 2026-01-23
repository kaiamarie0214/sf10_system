<style>
    body {
        background: #fff;
    }
    .sf10-container {
        margin: 0 auto;
        background: #fff;
        padding: 0;
        box-sizing: border-box;
        max-width: 100%;
        border: none;
    }
    .print-buttons {
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 1000;
        background: white;
        padding: 15px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
</style>

<div class="print-buttons">
    <button class="btn btn-primary mb-2" onclick="window.print()">
        Print to PDF
    </button>
    <a href="sf10_form.php" class="btn btn-secondary">
        Back
    </a>
</div>


<?php
$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : (isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0);
?>
<div class="sf10-container">
    <iframe src="../SF10_official_final.php?student_id=<?= $student_id ?>" 
            width="100%" 
            height="1600px" 
            id="sf10Frame"
            style="border: none; width:100%; height:1600px;">
    </iframe>
</div>
<!-- Only the iframe for the official template remains -->
