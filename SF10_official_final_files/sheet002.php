<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}
// --- SF10 dynamic data fetcher ---
require_once dirname(__DIR__, 1) . '/includes/db.php';

$student = null;
// Sheet002 covers Grade 5-6 slots (left+right columns per row)
// With mid-year transfers, a single grade can occupy TWO slots (originating + receiving)
$grade1_school = null;
$grade2_school = null;
$grade3_school = null;
$grade4_school = null;
$grade5_school = null;
$grade6_school = null;
$grade7_school = null;
$grade8_school = null;
if (isset($_GET['student_id'])) {
   $student_id = intval($_GET['student_id']);
   $stmt = $conn->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
   $stmt->bind_param('i', $student_id);
   $stmt->execute();
   $result = $stmt->get_result();
   $student = $result->fetch_assoc();

   // Fetch ALL school records for a grade level (supports mid-year transfer: multiple records per grade)
   function fetch_all_grade_schools($conn, $student_id, $grade_label, $grade_num) {
      $stmt = $conn->prepare('SELECT * FROM schools_attended WHERE student_id = ? AND (grade_level = ? OR grade_level = ?) ORDER BY display_order ASC, school_year ASC, id ASC');
      $stmt->bind_param('iss', $student_id, $grade_label, $grade_num);
      $stmt->execute();
      $result = $stmt->get_result();
      return $result->fetch_all(MYSQLI_ASSOC);
   }

   // Keep backward-compatible single fetch (returns first record)
   function fetch_grade_school($conn, $student_id, $grade_label, $grade_num) {
      $all = fetch_all_grade_schools($conn, $student_id, $grade_label, $grade_num);
      return $all[0] ?? null;
   }

   // Fetch all records per grade for Grades 1-6
   $grade1_schools_all = fetch_all_grade_schools($conn, $student_id, 'Grade 1', '1');
   $grade2_schools_all = fetch_all_grade_schools($conn, $student_id, 'Grade 2', '2');
   $grade3_schools_all = fetch_all_grade_schools($conn, $student_id, 'Grade 3', '3');
   $grade4_schools_all = fetch_all_grade_schools($conn, $student_id, 'Grade 4', '4');
   $grade5_schools_all = fetch_all_grade_schools($conn, $student_id, 'Grade 5', '5');
   $grade6_schools_all = fetch_all_grade_schools($conn, $student_id, 'Grade 6', '6');
   $grade7_schools_all = fetch_all_grade_schools($conn, $student_id, 'Grade 7', '7');
   $grade8_schools_all = fetch_all_grade_schools($conn, $student_id, 'Grade 8', '8');

   // Single-record backward-compat (first record per grade)
   $grade1_school = $grade1_schools_all[0] ?? null;
   $grade2_school = $grade2_schools_all[0] ?? null;
   $grade3_school = $grade3_schools_all[0] ?? null;
   $grade4_school = $grade4_schools_all[0] ?? null;
   $grade5_school = $grade5_schools_all[0] ?? null;
   $grade6_school = $grade6_schools_all[0] ?? null;
   $grade7_school = $grade7_schools_all[0] ?? null;
   $grade8_school = $grade8_schools_all[0] ?? null;

// Function to get subject name for a specific student, matching preview logic
function getSubjectNameForStudent($conn, $subject_id, $student_id, $school_attended_id) {
    // First, determine if this is a transfer student
    $is_transfer = false;
    $grade_level_raw = null;
    $school_year = null;
    
    $school_info = $conn->query("SELECT grade_level, school_year, is_transfer FROM schools_attended WHERE id = $school_attended_id");
    if ($school_info && $school_info->num_rows > 0) {
        $school_data = $school_info->fetch_assoc();
        $grade_level_raw = $school_data['grade_level'];
        $school_year = $school_data['school_year'];
        $is_transfer = intval($school_data['is_transfer'] ?? 0) === 1;
    }

    // Extract numeric grade level for lookup (e.g., "Grade 1" -> 1, "IV" -> 4)
    $grade_level_num = null;
    if ($grade_level_raw) {
        if (preg_match('/(\d+)/', $grade_level_raw, $m)) {
            $grade_level_num = intval($m[1]);
        } else {
            // Roman numeral fallback for "IV", "V", "VI"
            $roman_map = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6];
            $clean_grade = strtoupper(trim($grade_level_raw));
            if (isset($roman_map[$clean_grade])) {
                $grade_level_num = $roman_map[$clean_grade];
            }
        }
    }
    
    // First check if there's a custom subject name for this transfer student
    $table_check = $conn->query("SHOW TABLES LIKE 'student_custom_subjects'");
    if ($table_check && $table_check->num_rows > 0) {
        $custom_query = $conn->query("SELECT custom_subject_name 
                                      FROM student_custom_subjects 
                                      WHERE student_id = $student_id 
                                      AND school_attended_id = $school_attended_id 
                                      AND subject_id = $subject_id");
        if ($custom_query && $custom_query->num_rows > 0) {
            $custom_result = $custom_query->fetch_assoc();
            // Return the custom name (even if empty) to respect user override
            return $custom_result['custom_subject_name'];
        }
    }
    
    // IMPORTANT: Only use grade-level config for regular students (non-transfer)
    if (!$is_transfer && $grade_level_num) {
        $table_check = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'");
        
        if ($table_check && $table_check->num_rows > 0) {
            $group_query = $conn->prepare("SELECT subject_name FROM subject_grade_groups 
                                          WHERE grade_level = ? AND subject_id = ? 
                                          AND (school_year = ? OR school_year IS NULL)");
            $group_query->bind_param("iis", $grade_level_num, $subject_id, $school_year);
            $group_query->execute();
            $group_res = $group_query->get_result();
            
            if ($group_res && $group_res->num_rows > 0) {
                $group_result = $group_res->fetch_assoc();
                // Return the alias name (even if empty) to respect grade-level configuration
                return $group_result['subject_name'];
            }
        }
    }
    
    // Fall back to default subject name
    $default_query = $conn->query("SELECT subject_name FROM subjects WHERE id = $subject_id");
    if ($default_query && $default_query->num_rows > 0) {
        $default_result = $default_query->fetch_assoc();
        return $default_result['subject_name'];
    }
    
    return 'Unknown Subject';
}

// Function to get adviser full name from system if available
function getAdviserFullName($conn, $school_row) {
    if (!$school_row) return '';
    $adviser_name = $school_row['adviser_name'] ?? '';
    
    // 1. Try matching existing adviser_name to a user in the system to get their latest full name
    if (!empty($adviser_name)) {
        $stmt = $conn->prepare("SELECT full_name FROM users WHERE LOWER(full_name) = LOWER(?) LIMIT 1");
        $stmt->bind_param("s", $adviser_name);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            return $row['full_name'];
        }
        $stmt->close();
    }
    
    // 2. If no name or no match, look up by assignment (for internal records with missing adviser_name)
    $grade_label = $school_row['grade_level'] ?? '';
    $section = $school_row['section'] ?? '';
    $school_year_str = $school_row['school_year'] ?? '';
    
    if ($grade_label && $section && $school_year_str) {
        // Extract numeric grade level
        preg_match('/(\d+)/', $grade_label, $m);
        $gl_num = isset($m[1]) ? intval($m[1]) : null;
        
        if ($gl_num) {
            $stmt = $conn->prepare("SELECT u.full_name 
                                   FROM teacher_assignments ta
                                   JOIN users u ON ta.teacher_id = u.id
                                   JOIN school_years sy ON ta.school_year_id = sy.id
                                   WHERE ta.grade_level = ? 
                                   AND ta.section = ? 
                                   AND ta.assignment_type = 'adviser'
                                   AND sy.year = ?
                                   LIMIT 1");
            $stmt->bind_param("iss", $gl_num, $section, $school_year_str);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return $row['full_name'];
            }
            $stmt->close();
        }
    }
    
    return $adviser_name;
}

// Function to get school details with fallback to adviser's info
function getSchoolInfo($conn, $school_row) {
    // If no school record exists for this grade level, return empty fields immediately
    if (!$school_row) {
        return [
            'school_name' => '',
            'school_id' => '',
            'district' => '',
            'division' => '',
            'region' => ''
        ];
    }

    $info = [
        'school_name' => ($school_row && isset($school_row['school_name'])) ? $school_row['school_name'] : '',
        'school_id' => ($school_row && isset($school_row['school_id'])) ? $school_row['school_id'] : '',
        'district' => ($school_row && isset($school_row['district'])) ? $school_row['district'] : '',
        'division' => ($school_row && isset($school_row['division'])) ? $school_row['division'] : '',
        'region' => ($school_row && isset($school_row['region'])) ? $school_row['region'] : ''
    ];

    // If any critical field is missing, try to fetch from the assigned adviser in the system
    if (empty($info['school_name']) || empty($info['school_id']) || empty($info['district'])) {
        $adviser_name = ($school_row && isset($school_row['adviser_name'])) ? $school_row['adviser_name'] : '';
        $user_match = null;

        // 1. Try matching by name first
        if (!empty($adviser_name)) {
            $stmt = $conn->prepare("SELECT school_name, school_id, district, division, region FROM users WHERE LOWER(full_name) = LOWER(?) LIMIT 1");
            $stmt->bind_param("s", $adviser_name);
            $stmt->execute();
            $user_match = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        // 2. If no match by name, try matching by assignment (for internal records)
        if (!$user_match) {
            $grade_label = ($school_row && isset($school_row['grade_level'])) ? $school_row['grade_level'] : '';
            $section = ($school_row && isset($school_row['section'])) ? $school_row['section'] : '';
            $school_year_str = ($school_row && isset($school_row['school_year'])) ? $school_row['school_year'] : '';
            
            if ($grade_label && $section && $school_year_str) {
                preg_match('/(\d+)/', $grade_label, $m);
                $gl_num = isset($m[1]) ? intval($m[1]) : null;

                if ($gl_num) {
                    $stmt = $conn->prepare("SELECT u.school_name, u.school_id, u.district, u.division, u.region 
                                           FROM teacher_assignments ta
                                           JOIN users u ON ta.teacher_id = u.id
                                           JOIN school_years sy ON ta.school_year_id = sy.id
                                           WHERE ta.grade_level = ? 
                                           AND ta.section = ? 
                                           AND ta.assignment_type = 'adviser'
                                           AND sy.year = ?
                                           LIMIT 1");
                    $stmt->bind_param("iss", $gl_num, $section, $school_year_str);
                    $stmt->execute();
                    $user_match = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }
            }
        }

        // 3. Last fallback: try any admin user's school info as a global default
        if (!$user_match) {
            $admin_query = $conn->query("SELECT school_name, school_id, district, division, region FROM users WHERE role = 'admin' AND school_name IS NOT NULL AND school_name != '' LIMIT 1");
            if ($admin_query && $admin_query->num_rows > 0) {
                $user_match = $admin_query->fetch_assoc();
            }
        }

        if ($user_match) {
            if (empty($info['school_name'])) $info['school_name'] = $user_match['school_name'] ?? '';
            if (empty($info['school_id'])) $info['school_id'] = $user_match['school_id'] ?? '';
            if (empty($info['district'])) $info['district'] = $user_match['district'] ?? '';
            if (empty($info['division'])) $info['division'] = $user_match['division'] ?? '';
            if (empty($info['region'])) $info['region'] = $user_match['region'] ?? '';
        }
    }

    return $info;
}
}
?>

<?php
// =============================================================================
// Build a robust per-school (grade-level) latest-grade map for this student.
// Supports mid-year transfers: a student may have TWO schools_attended records
// for the SAME grade level (originating + receiving school). Both records are
// stored in $grades_by_grade[$grade_num] as an array indexed 0,1,...
// Sheet002 covers Grades 5-6 (and uses slots 4-7 in the global ordering).
// =============================================================================
$grades_by_grade = []; // [ grade_num => [ [school=>, grades=>], [school=>, grades=>], ... ] ]
if (isset($student_id)) {
   $stmt_sch = $conn->prepare('SELECT * FROM schools_attended WHERE student_id = ? ORDER BY grade_level ASC, display_order ASC, school_year ASC, id ASC');
   $stmt_sch->bind_param('i', $student_id);
   $stmt_sch->execute();
   $schools_res = $stmt_sch->get_result();

   $latest_query = "SELECT g.*
                       FROM grades g
                       INNER JOIN (
                           SELECT subject_id, quarter, MAX(id) as max_id
                           FROM grades
                           WHERE student_id = ?
                           AND school_attended_id = ?
                           GROUP BY subject_id, quarter
                       ) AS latest ON g.id = latest.max_id
                       ORDER BY g.subject_id, g.quarter";

   while ($school_row = $schools_res->fetch_assoc()) {
      // Extract numeric grade (e.g., 'Grade 1' -> 1, '1' -> 1)
      $grade_label = $school_row['grade_level'];
      $grade_num = 0;
      if (preg_match('/(\d+)/', $grade_label, $m)) {
         $grade_num = intval($m[1]);
      } else {
         // Fallback for non-numeric grade levels if any
         $grade_num = (int)$grade_label;
      }
      
      // If still 0, use a high index to put it at the end
      if ($grade_num <= 0) $grade_num = 99;

      if (!isset($grades_by_grade[$grade_num])) {
         $grades_by_grade[$grade_num] = [];
      }

      $slot_entry = ['school' => $school_row, 'grades' => []];

      $stmt_g = $conn->prepare($latest_query);
      $stmt_g->bind_param('ii', $student_id, $school_row['id']);
      $stmt_g->execute();
      $res_g = $stmt_g->get_result();
      while ($r = $res_g->fetch_assoc()) {
         $sid = (int)$r['subject_id'];
         $q   = (int)$r['quarter'];
         if (!isset($slot_entry['grades'][$sid])) {
            $slot_entry['grades'][$sid] = ['q1' => '', 'q2' => '', 'q3' => '', 'q4' => '', 'final_rating' => '', 'remarks' => ''];
         }
         $slot_entry['grades'][$sid]['q' . $q] = $r['grade'];
         if (isset($r['final_rating']) && $r['final_rating'] !== '') {
            $slot_entry['grades'][$sid]['final_rating'] = $r['final_rating'];
         }
         if (isset($r['remarks']) && $r['remarks'] !== '') {
            $slot_entry['grades'][$sid]['remarks'] = $r['remarks'];
         }
      }
      $stmt_g->close();

      $grades_by_grade[$grade_num][] = $slot_entry;
   }
   $stmt_sch->close();

   // -------------------------------------------------------------------------
   // Build $sf10_slots for sheet002 (Grades 5-6).
   // Sheet002 displays grades 5 and 6 in left/right columns just like sheet001.
   // With transfers, a single grade produces 2 consecutive slots.
   // $grade5_school/$grade6_school are re-assigned from slots 4-5 (0-indexed).
   // -------------------------------------------------------------------------
   $sf10_slots = []; // flat ordered list for all grades 1..8
   for ($gn = 1; $gn <= 8; $gn++) {
      if (!empty($grades_by_grade[$gn])) {
         foreach ($grades_by_grade[$gn] as $slot) {
            $sf10_slots[] = $slot;
         }
      } else {
         $sf10_slots[] = ['school' => null, 'grades' => []];
      }
   }

   // Sheet002 uses slots 4 and 5 (Grade 5 left, Grade 6 right by default)
   // With a transfer in Grade 5, slot 4 = Grade 5 orig, slot 5 = Grade 5 receiving
   $grade5_school = $sf10_slots[4]['school'] ?? null;
   $grade6_school = $sf10_slots[5]['school'] ?? null;

   // Re-assign grades arrays from slots (all 8 for cross-sheet compatibility)
   for ($i = 0; $i < 8; $i++) {
      ${"grades_grade" . ($i + 1)} = $sf10_slots[$i]['grades'] ?? [];
   }

   // Convenience for direct grade-number access (Grades 1-8)
   // Already done above via slot assignments

   // -------------------------------------------------------------------------
   // Post-process: clear final_rating and remarks for any subject that does
   // not have all 4 quarters filled — final rating is only valid when complete.
   // -------------------------------------------------------------------------
   for ($i = 1; $i <= 8; $i++) {
      $varName = "grades_grade" . $i;
      foreach (${$varName} as $sid => &$gdata) {
         $allFour = $gdata['q1'] !== '' && $gdata['q1'] !== null
                 && $gdata['q2'] !== '' && $gdata['q2'] !== null
                 && $gdata['q3'] !== '' && $gdata['q3'] !== null
                 && $gdata['q4'] !== '' && $gdata['q4'] !== null;
         if (!$allFour) {
            $gdata['final_rating'] = '';
            $gdata['remarks']      = '';
         }
      }
      unset($gdata);
   }

   // -------------------------------------------------------------------------
   // Remedial classes fetcher — keyed by actual grade number to prevent
   // bleeding between grades that share the same school_year
   // -------------------------------------------------------------------------
   $remedial_by_grade = [];
   // Check which columns are available for precision scoping
   $col_check = $conn->query("SHOW COLUMNS FROM remedial_classes LIKE 'school_attended_id'");
   $has_school_attended_col = ($col_check && $col_check->num_rows > 0);
   $col_check_gl = $conn->query("SHOW COLUMNS FROM remedial_classes LIKE 'grade_level'");
   $has_grade_level_col = ($col_check_gl && $col_check_gl->num_rows > 0);

   foreach ($sf10_slots as $slot_idx => $slot_info) {
      if (!$slot_info['school']) { continue; }
      $school_row = $slot_info['school'];
      // Extract the numeric grade number from grade_level (e.g. "Grade 5" -> 5, "5" -> 5)
      $gl_label = $school_row['grade_level'] ?? '';
      preg_match('/(\d+)/', $gl_label, $m);
      $grade_num = isset($m[1]) ? intval($m[1]) : null;
      if (!$grade_num) continue;
      // Only fetch once per grade number (first school record wins; transfer records are skipped)
      if (isset($remedial_by_grade[$grade_num])) continue;

      $sy = $school_row['school_year'] ?? null;
      $remedial_by_grade[$grade_num] = [];
      if ($sy) {
         if ($has_school_attended_col) {
            $stmt_r = $conn->prepare("SELECT * FROM remedial_classes WHERE student_id = ? AND school_attended_id = ? ORDER BY id ASC");
            $stmt_r->bind_param('ii', $student_id, $school_row['id']);
         } elseif ($has_grade_level_col) {
            // Filter by both school_year AND grade_level to isolate this grade's remedial
            $stmt_r = $conn->prepare("SELECT * FROM remedial_classes WHERE student_id = ? AND school_year = ? AND grade_level = ? ORDER BY id ASC");
            $stmt_r->bind_param('iss', $student_id, $sy, $gl_label);
         } else {
            // Legacy fallback: school_year only — may still bleed if grades share same year
            $stmt_r = $conn->prepare("SELECT * FROM remedial_classes WHERE student_id = ? AND school_year = ? ORDER BY id ASC");
            $stmt_r->bind_param('is', $student_id, $sy);
         }
         $stmt_r->execute();
         $res_r = $stmt_r->get_result();
         if ($res_r) {
            $remedial_by_grade[$grade_num] = $res_r->fetch_all(MYSQLI_ASSOC);
         }
         $stmt_r->close();
      }
   }

   // For transfer students: if the originating school slot had no remedial data,
   // try fetching from the receiving school slot for the same grade
   if ($has_school_attended_col) {
      foreach ($sf10_slots as $slot_idx => $slot_info) {
         if (!$slot_info['school']) continue;
         $school_row = $slot_info['school'];
         $gl_label = $school_row['grade_level'] ?? '';
         preg_match('/(\d+)/', $gl_label, $m);
         $grade_num = isset($m[1]) ? intval($m[1]) : null;
         if (!$grade_num) continue;
         if (!empty($remedial_by_grade[$grade_num])) continue; // already has data
         $sy = $school_row['school_year'] ?? null;
         if (!$sy) continue;
         $stmt_r = $conn->prepare("SELECT * FROM remedial_classes WHERE student_id = ? AND school_attended_id = ? ORDER BY id ASC");
         $stmt_r->bind_param('ii', $student_id, $school_row['id']);
         $stmt_r->execute();
         $res_r = $stmt_r->get_result();
         if ($res_r) {
            $fetched = $res_r->fetch_all(MYSQLI_ASSOC);
            if (!empty($fetched)) $remedial_by_grade[$grade_num] = $fetched;
         }
         $stmt_r->close();
      }
   }

   // Expose convenience variables remedial_grade1..8 keyed by actual grade number
   for ($i = 1; $i <= 8; $i++) {
      ${"remedial_grade" . $i} = $remedial_by_grade[$i] ?? [];
   }
}

   // Helper to format remarks on printable sheet: uppercase PASSED/FAILED, escape otherwise
   function format_remark_sheet($text) {
      $text = trim((string)$text);
      if ($text === '') {
         return '-';
      }
      $upper = strtoupper($text);
      if ($upper === 'PASSED' || $upper === 'FAILED') {
         return htmlspecialchars($upper, ENT_QUOTES, 'UTF-8');
      }
      return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
   }

   // Convert grade label to Roman numeral if it contains a numeric grade
   function grade_label_to_roman($label) {
      if (!$label) return '';
      // Extract first number found
      if (preg_match('/(\d+)/', $label, $m)) {
         $num = intval($m[1]);
         $map = [1000=>'M',900=>'CM',500=>'D',400=>'CD',100=>'C',90=>'XC',50=>'L',40=>'XL',10=>'X',9=>'IX',5=>'V',4=>'IV',1=>'I'];
         $res = '';
         foreach ($map as $val => $rom) {
            while ($num >= $val) { $res .= $rom; $num -= $val; }
         }
         return $res;
      }
      // If label already contains words like 'Grade I', try to detect roman inside
      if (preg_match('/\b(I|II|III|IV|V|VI|VII|VIII|IX|X)\b/i', $label, $m2)) {
         return strtoupper($m2[1]);
      }
      return strtoupper(htmlspecialchars($label));
   }
?>

<html xmlns:v="urn:schemas-microsoft-com:vml"
xmlns:o="urn:schemas-microsoft-com:office:office"
xmlns:x="urn:schemas-microsoft-com:office:excel"
xmlns="http://www.w3.org/TR/REC-html40">

<head>
<meta http-equiv=Content-Type content="text/html; charset=windows-1252">
<meta name=ProgId content=Excel.Sheet>
<meta name=Generator content="Microsoft Excel 15">
<link id=Main-File rel=Main-File href="../SF10_official_final.php">
<link rel=File-List href=filelist.xml>
<title>School Form 10 ES Learners Permanent Record Final</title>
<link rel=Stylesheet href=stylesheet.css>
<style>
<!--table
	{mso-displayed-decimal-separator:"\.";
	mso-displayed-thousand-separator:"\,";}
@page
	{margin:0in;
	mso-header-margin:0in;
	mso-footer-margin:0in;
	mso-horizontal-page-align:center;
    size: 8.5in 13in; /* Long Bond Paper / Legal Size (PH) */}

@media print {
    @page {
        size: 8.5in 13in;
        margin: 0 !important;
    }
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        width: 8.5in !important;
        height: 13in !important;
        overflow: hidden !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    body {
        background-color: white !important;
        display: block !important;
    }
    .form-container {
        box-shadow: none !important;
        width: 1149px !important;
        margin: 0 auto !important;
        padding: 0 !important;
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        /* Forced scaling to fit edge-to-edge on 8.5x13 paper */
        zoom: 0.64;
    }
    .no-print {
        display: none !important;
        height: 0 !important;
        visibility: hidden !important;
    }
    table {
        page-break-inside: avoid !important;
        page-break-after: avoid !important;
        border-collapse: collapse !important;
        margin: 0 auto !important;
    }
    tr {
        page-break-inside: avoid !important;
        page-break-after: auto !important;
    }
    /* Suppress browser headers/footers */
    header, footer { display: none !important; }
}

body {
    display: flex;
    flex-direction: column;
    align-items: center;
    background-color: #f8f9fa;
    margin: 0;
    padding: 20px;
}
.form-container {
    background-color: white;
    padding: 0;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
}
-->
</style>
<![if !supportTabStrip]><script language="JavaScript">
<!--
function fnUpdateTabs()
 {
  if (parent.window.g_iIEVer>=4) {
   if (parent.document.readyState=="complete"
    && parent.frames['frTabs'].document.readyState=="complete")
   parent.fnSetActiveSheet(1);
  else
   window.setTimeout("fnUpdateTabs();",150);
 }
}

if (window.name!="frSheet")
 window.location.replace("../SF10_official_final.php");
else
 fnUpdateTabs();
//-->
</script>
<![endif]>
</head>

<body link="#0563C1" vlink="#954F72">

<!-- Back to preview card (hidden when printing) -->
<div class="no-print" style="width: 100%; max-width: 1149px; margin-bottom: 15px;">
   <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
      <div style="display:inline-block; background:#6c757d; color:#fff; border-radius:6px; padding:8px 12px; cursor: pointer;" onclick="if(window.parent && window.parent.closeTab){window.parent.closeTab();}else if(window.top && window.top.closeTab){window.top.closeTab();}else{window.location.href='../pages/sf10_preview.php?student_id=<?= isset($student_id) ? intval($student_id) : '' ?>';}">
         <span style="color:inherit; text-decoration:none; font-weight:600;">
            &larr; Back to Preview
         </span>
      </div>
      <div style="display:inline-block; background:#198754; color:#fff; border-radius:6px; padding:8px 12px; cursor: pointer;" onclick="window.print()">
         <span style="font-weight:600;">
            <i class="bi bi-printer"></i> Print Form (Legal Size)
         </span>
      </div>
   </div>
   <div style="background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 10px 15px; border-radius: 6px; font-size: 14px; display: flex; align-items: center; gap: 10px;">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><strong>Reminder:</strong> For a perfect fit, set <strong>Paper Size</strong> to <strong>Legal Size (8.5" x 14")</strong> and <strong>Margins</strong> to <strong>None</strong> in the print settings.</span>
   </div>
</div>

<div class="form-container">
<table border=0 cellpadding=0 cellspacing=0 width=1149 style='border-collapse:
 collapse;table-layout:fixed;width:869pt'>
 <col class=xl66 width=7 style='mso-width-source:userset;mso-width-alt:256;
 width:5pt'>
 <col class=xl66 width=41 style='mso-width-source:userset;mso-width-alt:1499;
 width:31pt'>
 <col class=xl66 width=19 style='mso-width-source:userset;mso-width-alt:694;
 width:14pt'>
 <col class=xl66 width=40 style='mso-width-source:userset;mso-width-alt:1462;
 width:30pt'>
 <col class=xl66 width=37 style='mso-width-source:userset;mso-width-alt:1353;
 width:28pt'>
 <col class=xl66 width=23 style='mso-width-source:userset;mso-width-alt:841;
 width:17pt'>
 <col class=xl66 width=21 style='mso-width-source:userset;mso-width-alt:768;
 width:16pt'>
 <col class=xl66 width=41 style='mso-width-source:userset;mso-width-alt:1499;
 width:31pt'>
 <col class=xl66 width=12 style='mso-width-source:userset;mso-width-alt:438;
 width:9pt'>
 <col class=xl66 width=34 style='mso-width-source:userset;mso-width-alt:1243;
 width:26pt'>
 <col class=xl66 width=33 style='mso-width-source:userset;mso-width-alt:1206;
 width:25pt'>
 <col class=xl66 width=23 style='mso-width-source:userset;mso-width-alt:841;
 width:17pt'>
 <col class=xl66 width=16 style='mso-width-source:userset;mso-width-alt:585;
 width:12pt'>
 <col class=xl66 width=33 style='mso-width-source:userset;mso-width-alt:1206;
 width:25pt'>
 <col class=xl66 width=35 style='mso-width-source:userset;mso-width-alt:1280;
 width:26pt'>
 <col class=xl66 width=17 span=2 style='mso-width-source:userset;mso-width-alt:
 621;width:13pt'>
 <col class=xl66 width=28 style='mso-width-source:userset;mso-width-alt:1024;
 width:21pt'>
 <col class=xl66 width=33 style='mso-width-source:userset;mso-width-alt:1206;
 width:25pt'>
 <col class=xl66 width=56 style='mso-width-source:userset;mso-width-alt:2048;
 width:42pt'>
 <col class=xl66 width=13 style='mso-width-source:userset;mso-width-alt:475;
 width:10pt'>
 <col class=xl66 width=40 style='mso-width-source:userset;mso-width-alt:1462;
 width:30pt'>
 <col class=xl66 width=19 span=2 style='mso-width-source:userset;mso-width-alt:
 694;width:14pt'>
 <col class=xl66 width=58 style='mso-width-source:userset;mso-width-alt:2121;
 width:44pt'>
 <col class=xl66 width=21 style='mso-width-source:userset;mso-width-alt:768;
 width:16pt'>
 <col class=xl66 width=8 style='mso-width-source:userset;mso-width-alt:292;
 width:6pt'>
 <col class=xl66 width=14 style='mso-width-source:userset;mso-width-alt:512;
 width:11pt'>
 <col class=xl66 width=31 style='mso-width-source:userset;mso-width-alt:1133;
 width:23pt'>
 <col class=xl66 width=10 style='mso-width-source:userset;mso-width-alt:365;
 width:8pt'>
 <col class=xl66 width=22 style='mso-width-source:userset;mso-width-alt:804;
 width:17pt'>
 <col class=xl66 width=10 style='mso-width-source:userset;mso-width-alt:365;
 width:8pt'>
 <col class=xl66 width=2 style='mso-width-source:userset;mso-width-alt:73;
 width:2pt'>
 <col class=xl66 width=5 style='mso-width-source:userset;mso-width-alt:182;
 width:4pt'>
 <col class=xl66 width=6 style='mso-width-source:userset;mso-width-alt:219;
 width:5pt'>
 <col class=xl66 width=23 style='mso-width-source:userset;mso-width-alt:841;
 width:17pt'>
 <col class=xl66 width=6 style='mso-width-source:userset;mso-width-alt:219;
 width:5pt'>
 <col class=xl66 width=12 style='mso-width-source:userset;mso-width-alt:438;
 width:9pt'>
 <col class=xl66 width=26 style='mso-width-source:userset;mso-width-alt:950;
 width:20pt'>
 <col class=xl66 width=16 style='mso-width-source:userset;mso-width-alt:585;
 width:12pt'>
 <col class=xl66 width=12 span=3 style='mso-width-source:userset;mso-width-alt:
 438;width:9pt'>
 <col class=xl66 width=24 style='mso-width-source:userset;mso-width-alt:877;
 width:18pt'>
 <col class=xl66 width=14 style='mso-width-source:userset;mso-width-alt:512;
 width:11pt'>
 <col class=xl66 width=17 style='mso-width-source:userset;mso-width-alt:621;
 width:13pt'>
 <col class=xl66 width=24 style='mso-width-source:userset;mso-width-alt:877;
 width:18pt'>
 <col class=xl66 width=16 style='mso-width-source:userset;mso-width-alt:585;
 width:12pt'>
 <col class=xl66 width=33 style='mso-width-source:userset;mso-width-alt:1206;
 width:25pt'>
 <col class=xl66 width=52 style='mso-width-source:userset;mso-width-alt:1901;
 width:39pt'>
 <col class=xl66 width=6 style='mso-width-source:userset;mso-width-alt:219;
 width:5pt'>
 <col class=xl66 width=0 style='display:none'>
 <col class=xl66 width=0 style='display:none;mso-width-source:userset;
 mso-width-alt:2340'>
 <col width=0 span=2 style='display:none'>
 <tr height=21 style='mso-height-source:userset;height:15.75pt'>
  <td height=21 class=xl66 width=7 style='height:15.75pt;width:5pt'><a
  name="Print_Area"></a></td>
  <td class=xl67 width=41 style='width:31pt'>SF10-ES</span></td>
  <td class=xl66 width=19 style='width:14pt'></td>
  <td class=xl66 width=40 style='width:30pt'></td>
  <td class=xl67 width=37 style='width:28pt'></td>
  <td class=xl67 width=23 style='width:17pt'></td>
  <td class=xl67 width=21 style='width:16pt'></td>
  <td class=xl67 width=41 style='width:31pt'></td>
  <td class=xl67 width=12 style='width:9pt'></td>
  <td class=xl67 width=34 style='width:26pt'></td>
  <td class=xl67 width=33 style='width:25pt'></td>
  <td class=xl67 width=23 style='width:17pt'></td>
  <td class=xl69 width=16 style='width:12pt'></td>
  <td class=xl67 width=33 style='width:25pt'></td>
  <td class=xl67 width=35 style='width:26pt'></td>
  <td class=xl67 width=17 style='width:13pt'></td>
  <td class=xl67 width=17 style='width:13pt'></td>
  <td class=xl69 width=28 style='width:21pt'></td>
  <td class=xl66 width=33 style='width:25pt'></td>
  <td class=xl69 width=56 style='width:42pt'></td>
  <td class=xl66 width=13 style='width:10pt'></td>
  <td class=xl75 width=40 style='width:30pt'></td>
  <td class=xl75 width=19 style='width:14pt'></td>
  <td class=xl75 width=19 style='width:14pt'></td>
  <td class=xl75 width=58 style='width:44pt'></td>
  <td class=xl75 width=21 style='width:16pt'></td>
  <td class=xl75 width=8 style='width:6pt'></td>
  <td class=xl75 width=14 style='width:11pt'></td>
  <td class=xl66 width=31 style='width:23pt'></td>
  <td class=xl66 width=10 style='width:8pt'></td>
  <td class=xl75 width=22 style='width:17pt'></td>
  <td class=xl75 width=10 style='width:8pt'></td>
  <td class=xl86 width=2 style='width:2pt'></td>
  <td class=xl86 width=5 style='width:4pt'></td>
  <td class=xl86 width=6 style='width:5pt'></td>
  <td class=xl69 width=23 style='width:17pt'></td>
  <td class=xl69 width=6 style='width:5pt'></td>
  <td class=xl69 width=12 style='width:9pt'></td>
  <td class=xl69 width=26 style='width:20pt'></td>
  <td class=xl69 width=16 style='width:12pt'></td>
  <td class=xl69 width=12 style='width:9pt'></td>
  <td class=xl69 width=12 style='width:9pt'></td>
  <td class=xl69 width=12 style='width:9pt'></td>
  <td class=xl69 colspan=6 width=128 style='mso-ignore:colspan;width:97pt'>Page
  2 of ________</td>
  <td class=xl69 width=52 style='width:39pt'></td>
  <td class=xl66 width=6 style='width:5pt'></td>
  <td class=xl66 width=0></td>
  <td class=xl66 width=0></td>
  <td width=0></td>
  <td width=0></td>
 </tr>
 <tr height=20 style='mso-height-source:userset;height:15.0pt'>
  <td height=20 class=xl66 style='height:15.0pt'></td>
  <td colspan=49 class=xl232 style='border-right:.5pt solid black'>SCHOLASTIC
  RECORD</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
<?php
// Compute/locate General Average rows for Grades 5-8 (prefer stored GA rows)
$ga_skip_ids = [9,10,11,12];
$ga5_final = null; $ga6_final = null; $ga7_final = null; $ga8_final = null;
$ga5_row = null; $ga6_row = null; $ga7_row = null; $ga8_row = null;
// Helper: returns true only when all 4 quarters are non-empty for a grade entry
$allQFilled = function($g) {
   return isset($g['q1'],$g['q2'],$g['q3'],$g['q4'])
       && $g['q1'] !== '' && $g['q1'] !== null
       && $g['q2'] !== '' && $g['q2'] !== null
       && $g['q3'] !== '' && $g['q3'] !== null
       && $g['q4'] !== '' && $g['q4'] !== null;
};

if (!empty($grades_grade5) && is_array($grades_grade5)) {
   $ga5_finals = [];
   foreach ($grades_grade5 as $sid => $g) {
      if (in_array(intval($sid), $ga_skip_ids)) continue;
      if (!empty($g['is_general_average']) || (isset($g['subject_name']) && strtolower(trim($g['subject_name'])) === 'general average')) {
         $ga5_row = $g;
      }
      if ($allQFilled($g) && (!empty($g['final_rating']) || $g['final_rating'] === '0')) $ga5_finals[] = round(floatval($g['final_rating']));
   }
   $ga5_final = (!empty($ga5_finals)) ? round(array_sum($ga5_finals)/count($ga5_finals)) : null;
}
if (!empty($grades_grade6) && is_array($grades_grade6)) {
   $ga6_finals = [];
   foreach ($grades_grade6 as $sid => $g) {
      if (in_array(intval($sid), $ga_skip_ids)) continue;
      if (!empty($g['is_general_average']) || (isset($g['subject_name']) && strtolower(trim($g['subject_name'])) === 'general average')) {
         $ga6_row = $g;
      }
      if ($allQFilled($g) && (!empty($g['final_rating']) || $g['final_rating'] === '0')) $ga6_finals[] = round(floatval($g['final_rating']));
   }
   $ga6_final = (!empty($ga6_finals)) ? round(array_sum($ga6_finals)/count($ga6_finals)) : null;
}
if (!empty($grades_grade7) && is_array($grades_grade7)) {
   $ga7_finals = [];
   foreach ($grades_grade7 as $sid => $g) {
      if (in_array(intval($sid), $ga_skip_ids)) continue;
      if (!empty($g['is_general_average']) || (isset($g['subject_name']) && strtolower(trim($g['subject_name'])) === 'general average')) {
         $ga7_row = $g;
      }
      if ($allQFilled($g) && (!empty($g['final_rating']) || $g['final_rating'] === '0')) $ga7_finals[] = round(floatval($g['final_rating']));
   }
   $ga7_final = (!empty($ga7_finals)) ? round(array_sum($ga7_finals)/count($ga7_finals)) : null;
}
if (!empty($grades_grade8) && is_array($grades_grade8)) {
   $ga8_finals = [];
   foreach ($grades_grade8 as $sid => $g) {
      if (in_array(intval($sid), $ga_skip_ids)) continue;
      if (!empty($g['is_general_average']) || (isset($g['subject_name']) && strtolower(trim($g['subject_name'])) === 'general average')) {
         $ga8_row = $g;
      }
      if ($allQFilled($g) && (!empty($g['final_rating']) || $g['final_rating'] === '0')) $ga8_finals[] = round(floatval($g['final_rating']));
   }
   $ga8_final = (!empty($ga8_finals)) ? round(array_sum($ga8_finals)/count($ga8_finals)) : null;
}
?>
 <tr height=4 style='mso-height-source:userset;height:3.0pt'>
  <td height=4 class=xl66 style='height:3.0pt'></td>
  <td class=xl66></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl66></td>
  <td class=xl88></td>
  <td class=xl88></td>
  <td class=xl88></td>
  <td class=xl88></td>
  <td class=xl88></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl117></td>
  <td class=xl117></td>
  <td colspan=13 class=xl117></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <?php $grade5_info = getSchoolInfo($conn, $grade5_school); ?>
  <?php $grade6_info = getSchoolInfo($conn, $grade6_school); ?>
  <td colspan=2 class=xl235>School:</td>
  <td colspan=11 class=xl154>
  <?php
            if (!empty($grade5_info['school_name'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade5_info['school_name'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
         ?>
  </td>
  <td colspan=4 class=xl201>School ID:</td>
  <td colspan=2 class=xl154 style='border-right:1.0pt solid black'>
  <?php
         if (!empty($grade5_info['school_id'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade5_info['school_id'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td class=xl89 colspan=2 style='mso-ignore:colspan'>School:</td>
  <td colspan=20 class=xl154>
  <?php
            if (!empty($grade6_info['school_name'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade6_info['school_name'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
         ?>
  </td>
  <td colspan=5 class=xl155>School ID:</td>
  <td colspan=2 class=xl154 style='border-right:1.0pt solid black'>
  <?php
         if (!empty($grade6_info['school_id'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade6_info['school_id'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
  <td colspan=3 class=xl123>
  <?php
            if (!empty($grade5_info['district'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade5_info['district'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
      ?>
  </td>
  <td class=xl66 colspan=2 style='mso-ignore:colspan'>Division:</td>
  <td colspan=9 class=xl94>
 <?php
         if (!empty($grade5_info['division'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade5_info['division'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=2 class=xl76>Region:</td>
  <td class=xl92>
  <?php
         if (!empty($grade5_info['region'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade5_info['region'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
  <td colspan=3 class=xl123>
  <?php
            if (!empty($grade6_info['district'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade6_info['district'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
      ?>
  </td>
  <td class=xl66 colspan=3 style='mso-ignore:colspan'>Division:</td>
  <td colspan=17 class=xl94>
  <?php
         if (!empty($grade6_info['division'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade6_info['division'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=3 class=xl76>Region:</td>
  <td class=xl93 style='border-top:none'>
  <?php
         if (!empty($grade6_info['region'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade6_info['region'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl91 colspan=4 style='mso-ignore:colspan'>Classified as Grade:</td>
  <td class=xl94>
  <?php
         if (isset($grade5_school) && !empty($grade5_school['grade_level'])) {
            $roman5 = grade_label_to_roman($grade5_school['grade_level']);
            echo '<strong>' . htmlspecialchars($roman5) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl95 colspan=2 style='mso-ignore:colspan'>Section:</td>
  <td class=xl66></td>
  <td colspan=5 class=xl94>
  <?php
         if (isset($grade5_school) && !empty($grade5_school['section'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade5_school['section'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=4 class=xl75>School Year:</td>
  <td colspan=2 class=xl94 style='border-right:1.0pt solid black'>
  <?php
         if (isset($grade5_school) && !empty($grade5_school['school_year'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade5_school['school_year'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td colspan=4 class=xl96>Classified as Grade:</td>
  <td colspan=2 class=xl94>
  <?php
         if (isset($grade6_school) && !empty($grade6_school['grade_level'])) {
            $roman6 = grade_label_to_roman($grade6_school['grade_level']);
            echo '<strong>' . htmlspecialchars($roman6) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=3 class=xl76>Section:</td>
  <td colspan=10 class=xl94>
  <?php
         if (isset($grade6_school) && !empty($grade6_school['section'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade6_school['section'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=6 class=xl76>School Year:</td>
  <td colspan=4 class=xl94 style='border-right:1.0pt solid black'>
  <?php
         if (isset($grade6_school) && !empty($grade6_school['school_year'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade6_school['school_year'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=26 style='mso-height-source:userset;height:19.5pt'>
  <td height=26 class=xl66 style='height:19.5pt'></td>
  <td colspan=6 class=xl96>Name of Adviser/Teacher:</td>
  <td colspan=7 class=xl94>
  <?php
         $adv_full5 = getAdviserFullName($conn, $grade5_school);
         if (!empty($adv_full5)) {
            echo '<strong>' . strtoupper(htmlspecialchars($adv_full5)) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=3 class=xl76>Signature:</td>
  <td colspan=3 class=xl94 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl96 colspan=5 style='mso-ignore:colspan'>Name of Adviser/Teacher:</td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td colspan=13 class=xl94>
  <?php
         $adv_full6 = getAdviserFullName($conn, $grade6_school);
         if (!empty($adv_full6)) {
            echo '<strong>' . strtoupper(htmlspecialchars($adv_full6)) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=5 class=xl76>Signature:</td>
  <td colspan=4 class=xl123 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=4 style='mso-height-source:userset;height:3.0pt'>
  <td height=4 class=xl66 style='height:3.0pt'></td>
  <td class=xl97>&nbsp;</td>
  <td class=xl98>&nbsp;</td>
  <td class=xl98>&nbsp;</td>
  <td class=xl98>&nbsp;</td>
  <td class=xl98>&nbsp;</td>
  <td class=xl98>&nbsp;</td>
  <td class=xl98>&nbsp;</td>
  <td class=xl98>&nbsp;</td>
  <td class=xl98>&nbsp;</td>
  <td class=xl99>&nbsp;</td>
  <td class=xl99>&nbsp;</td>
  <td class=xl99>&nbsp;</td>
  <td class=xl99>&nbsp;</td>
  <td class=xl99>&nbsp;</td>
  <td class=xl99>&nbsp;</td>
  <td class=xl99>&nbsp;</td>
  <td class=xl99>&nbsp;</td>
  <td class=xl99>&nbsp;</td>
  <td class=xl100>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl101>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl103>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=20 style='mso-height-source:userset;height:15.0pt'>
  <td height=20 class=xl66 style='height:15.0pt'></td>
  <td colspan=9 rowspan=2 class=xl216 width=268 style='border-right:.5pt solid black;
  border-bottom:.5pt solid black;width:202pt'>LEARNING AREAS</td>
  <td colspan=5 class=xl136 width=140 style='border-right:.5pt solid black;
  border-left:none;width:105pt'>Quarterly Rating</td>
  <td colspan=3 rowspan=2 class=xl220 width=62 style='border-right:.5pt solid black;
  border-bottom:.5pt solid black;width:47pt'>Final Rating</td>
  <td colspan=2 rowspan=2 class=xl220 width=89 style='border-right:1.0pt solid black;
  border-bottom:.5pt solid black;width:67pt'>Remarks</td>
  <td class=xl66></td>
  <td colspan=14 rowspan=2 class=xl216 width=265 style='border-right:.5pt solid black;
  border-bottom:.5pt solid black;width:202pt'>Learning Areas</td>
  <td colspan=10 class=xl214 width=157 style='border-left:none;width:119pt'>Quarterly
  Rating</td>
  <td colspan=3 rowspan=2 class=xl133 width=57 style='border-right:.5pt solid black;
  border-bottom:.5pt solid black;width:43pt'>Final Rating</td>
  <td colspan=2 rowspan=2 class=xl133 width=85 style='border-right:1.0pt solid black;
  border-bottom:.5pt solid black;width:64pt'>Remarks</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=17 style='mso-height-source:userset;height:12.75pt'>
  <td height=17 class=xl66 style='height:12.75pt'></td>
  <td class=xl104 style='border-top:none;border-left:none'>1</td>
  <td colspan=2 class=xl162 style='border-right:.5pt solid black;border-left:
  none'>2</td>
  <td class=xl104 style='border-top:none;border-left:none'>3</td>
  <td class=xl104 style='border-top:none;border-left:none'>4</td>
  <td class=xl66></td>
  <td colspan=3 class=xl162 style='border-right:.5pt solid black;border-left:
  none'>1</td>
  <td colspan=2 class=xl162 style='border-right:.5pt solid black;border-left:
  none'>2</td>
  <td colspan=3 class=xl162 style='border-right:.5pt solid black;border-left:
  none'>3</td>
  <td colspan=2 class=xl162 style='border-right:.5pt solid black;border-left:
  none'>4</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 1; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'Mother Tongue';
        }
    ?>
  </td>
   <?php $row5 = $grades_grade5[1] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>  
<td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 1; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'Mother Tongue';
        }
    ?>
  </td>
  <?php $row6 = $grades_grade6[1] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 2; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'Filipino';
        }
    ?>
  </td>
  <?php $row5 = $grades_grade5[2] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 2; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'Filipino';
        }
    ?>
  </td>
  <?php $row6 = $grades_grade6[2] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 3; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'English';
        }
  ?>
  </td>
 <?php $row5 = $grades_grade5[3] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 3; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'English';
        }
  ?>
  </td>
  <?php $row6 = $grades_grade6[3] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 4; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'Mathematics';
        }
    ?>
  </td>
  <?php $row5 = $grades_grade5[4] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 4; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'Mathematics';
        }
    ?>
  </td>
  <?php $row6 = $grades_grade6[4] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 5; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'Science';
        }
    ?>
  </td>
  <?php $row5 = $grades_grade5[5] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 5; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'Science';
        }
    ?>
  </td>
  <?php $row6 = $grades_grade6[5] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 6; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'Araling Panlipunan';
        }
    ?></td>
  <?php $row5 = $grades_grade5[6] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 6; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'Araling Panlipunan';
        }
    ?></td>
  <?php $row6 = $grades_grade6[6] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 7; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'EPP / TLE';
        }
    ?>
  </td>
 <?php $row5 = $grades_grade5[7] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 7; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'EPP / TLE';
        }
    ?>
  </td>
  <?php $row6 = $grades_grade6[7] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 8; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'MAPEH';
        }
    ?>
  </td>
  <?php $row5 = $grades_grade5[8] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 8; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'MAPEH';
        }
    ?>
  </td>
  <?php $row6 = $grades_grade6[8] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 9; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'Music';
        }
    ?>
  </td>
  <?php $row5 = $grades_grade5[9] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 9; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'Music';
        }
    ?>
  </td>
  <?php $row6 = $grades_grade6[9] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 10; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'Arts';
        }
    ?>
  </td>
  <?php $row5 = $grades_grade5[10] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 10; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'Arts';
        }
    ?>
  </td>
  <?php $row6 = $grades_grade6[10] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 11; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'Physical Education';
        }
    ?></td>
  <?php $row5 = $grades_grade5[11] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 11; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'Physical Education';
        }
    ?></td>
  <?php $row6 = $grades_grade6[11] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 12; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'Health';
        }
    ?></td>
 <?php $row5 = $grades_grade5[12] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl271 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 12; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'Health';
        }
    ?></td>
  <?php $row6 = $grades_grade6[12] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 13; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo 'Eduk. sa Pagpapakatao';
        }
    ?></td>
  <?php $row5 = $grades_grade5[13] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>  
<td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 13; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo 'Eduk. sa Pagpapakatao';
        }
    ?></td>
  <?php $row6 = $grades_grade6[13] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
 <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl173 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade5_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 14; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade5_school['id']));
        } else {
           echo '*Arabic Language';
        }
    ?></td>
  <?php $row5 = $grades_grade5[14] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>  <td class=xl66></td>
  <td colspan=14 class=xl173 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 14; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo '*Arabic Language';
        }
    ?></td>
  <?php $row6 = $grades_grade6[14] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl173 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade5_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 15; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo '*Islamic Values Education';
        }
    ?></td>
  <?php $row5 = $grades_grade5[15] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q1']) && $row5['q1'] !== '') ? '<strong>' . round($row5['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q2']) && $row5['q2'] !== '') ? '<strong>' . round($row5['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q3']) && $row5['q3'] !== '') ? '<strong>' . round($row5['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['q4']) && $row5['q4'] !== '') ? '<strong>' . round($row5['q4']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['final_rating']) && $row5['final_rating'] !== '') ? '<strong>' . round($row5['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row5 && isset($row5['remarks']) && $row5['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row5['remarks']) . '</span>' : '&nbsp;'; ?></td>  <td class=xl66></td>
  <td colspan=14 class=xl173 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade6_school) && $grade6_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 15; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade6_school['id']));
        } else {
           echo '*Islamic Values Education';
        }
    ?></td>
  <?php $row6 = $grades_grade6[15] ?? null; ?>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q1']) && $row6['q1'] !== '') ? '<strong>' . round($row6['q1']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt'><?php echo ($row6 && isset($row6['q2']) && $row6['q2'] !== '') ? '<strong>' . round($row6['q2']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q3']) && $row6['q3'] !== '') ? '<strong>' . round($row6['q3']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['q4']) && $row6['q4'] !== '') ? '<strong>' . round($row6['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row6 && isset($row6['final_rating']) && $row6['final_rating'] !== '') ? '<strong>' . round($row6['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row6 && isset($row6['remarks']) && $row6['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row6['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl194 style='border-right:.5pt solid black'>General
  Average</td>
  <td class=xl109 style='border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl186 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl109 style='border-left:none'>&nbsp;</td>
  <td class=xl109 style='border-left:none'>&nbsp;</td>
   <td colspan=3 class=xl139 style='border-right:.5pt solid black;border-left:none'><?php echo ($ga5_row && isset($ga5_row['final_rating']) && $ga5_row['final_rating'] !== '') ? '<strong>' . round($ga5_row['final_rating']) . '%</strong>' : (($ga5_final !== null) ? '<strong>' . $ga5_final . '%</strong>' : '&nbsp;'); ?></td>
  <td colspan=2 class=xl205 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl194 style='border-right:.5pt solid black'>General
  Average</td>
  <td colspan=3 class=xl139 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl139 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl139 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl139 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl139 style='border-right:.5pt solid black;border-left:
  none'><?php echo ($ga6_row && isset($ga6_row['final_rating']) && $ga6_row['final_rating'] !== '') ? '<strong>' . round($ga6_row['final_rating']) . '%</strong>' : (($ga6_final !== null) ? '<strong>' . $ga6_final . '%</strong>' : '&nbsp;'); ?></td>
  <td colspan=2 class=xl207 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=4 style='mso-height-source:userset;height:3.0pt'>
  <td height=4 class=xl66 style='height:3.0pt'></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl110></td>
  <td class=xl110></td>
  <td class=xl66></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=5 class=xl145 style='border-right:.5pt solid black'>Remedial
  Classes</td>
  <td colspan="4" class="xl142" style="border-left:
   none">Conducted from:</td>
   <td colspan="4" class="xl142" style="border-left:none; vertical-align:middle; text-align:center">
   <?php
      $r5_head = $remedial_grade5[0] ?? null;
      if ($r5_head && !empty($r5_head['conducted_from'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r5_head['conducted_from'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
   <td colspan="1" class=xl142 style='border-left:
   none'>&nbsp;&nbsp;to:</td>
   <td colspan="5" class="xl142" style="border-right:1.0pt solid black; border-left:none; vertical-align:middle; text-align:center">
   <?php
      if ($r5_head && !empty($r5_head['conducted_to'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r5_head['conducted_to'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>                  
  <td class=xl66></td>
  <td colspan=5 class=xl145 style='border-right:.5pt solid black'>Remedial
  Classes</td>
   <td colspan=9 class=xl142 style='border-left:
   none'>Conducted from:</td>
   <td colspan=8 class=xl142 style='border-left:none; vertical-align:middle; text-align:center'>
   <?php
      $r6_head = $remedial_grade6[0] ?? null;
      if ($r6_head && !empty($r6_head['conducted_from'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r6_head['conducted_from'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
   <td colspan=2 class=xl142 style='border-left:
   none'>&nbsp;&nbsp;to:</td>
   <td colspan=5 class=xl142 style='border-right:1.0pt solid black; border-left:none; vertical-align:middle; text-align:center'>
   <?php
      if ($r6_head && !empty($r6_head['conducted_to'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r6_head['conducted_to'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=42 style='mso-height-source:userset;height:31.5pt'>
  <td height=42 class=xl66 style='height:31.5pt'></td>
  <td colspan=5 class=xl148 style='border-right:.5pt solid black'>Learning
  Areas</td>
  <td colspan=4 class=xl202 style='border-right:.5pt solid black;border-left:
  none'>Final Rating</td>
  <td colspan=4 class=xl151 width=105 style='border-right:.5pt solid black;
  border-left:none;width:79pt'>Remedial Class Mark</td>
  <td colspan=4 class=xl151 width=97 style='border-right:.5pt solid black;
  border-left:none;width:73pt'>Recomputed Final Grade</td>
  <td colspan=2 class=xl199 style='border-right:1.0pt solid black;border-left:
  none'>Remarks</td>
  <td class=xl66></td>
  <td colspan=5 class=xl148 style='border-right:.5pt solid black'>Learning
  Areas</td>
  <td colspan=9 class=xl151 width=108 style='border-left:none;width:84pt'>Final
  Rating</td>
  <td colspan="7" class="xl151" width="107" style="border-right:.5pt solid black;width:81pt">Remedial Class Mark</td>
  <td colspan=6 class=xl152 width=107 style='border-right:.5pt solid black;
  width:81pt'>Recomputed Final Grade</td>
  <td colspan=2 class=xl199 style='border-right:1.0pt solid black;border-left:
  none'>Remarks</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
   <?php $row = $remedial_grade5[0] ?? null; ?>
   <td colspan=5 class=xl203 style='border-right:.5pt solid black'><?php echo ($row && isset($row['learning_area']) && $row['learning_area'] !== '') ? htmlspecialchars($row['learning_area']) : '&nbsp;'; ?></td>
   <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left: none'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row['final_rating']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=4 class=xl132 style='border-left:none'><?php echo ($row && isset($row['remedial_class_mark']) && $row['remedial_class_mark'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left: none'><?php echo ($row && isset($row['recomputed_final_grade']) && $row['recomputed_final_grade'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl181 style='border-right:1.0pt solid black;border-left: none'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.1em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
   <?php $row2 = $remedial_grade6[0] ?? null; ?>
   <td class=xl66></td>
   <td colspan=5 class=xl131><?php echo ($row2 && isset($row2['learning_area']) && $row2['learning_area'] !== '') ? htmlspecialchars($row2['learning_area']) : '&nbsp;'; ?></td>
   <td colspan=9 class=xl132 style='border-left:none'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row2['final_rating']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=7 class=xl132 style='border-left:none'><?php echo ($row2 && isset($row2['remedial_class_mark']) && $row2['remedial_class_mark'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row2['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=6 class=xl132 style='border-left:none'><?php echo ($row2 && isset($row2['recomputed_final_grade']) && $row2['recomputed_final_grade'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row2['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl132 style='border-right:1.0pt solid black;border-left: none'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <?php $row = $remedial_grade5[1] ?? null; ?>
    <td colspan=5 class=xl203 style='border-right:.5pt solid black'><?php echo ($row && isset($row['learning_area']) && $row['learning_area'] !== '') ? htmlspecialchars($row['learning_area']) : '&nbsp;'; ?></td>
    <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left: none'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row['final_rating']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=4 class=xl132 style='border-left:none'><?php echo ($row && isset($row['remedial_class_mark']) && $row['remedial_class_mark'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left: none'><?php echo ($row && isset($row['recomputed_final_grade']) && $row['recomputed_final_grade'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=2 class=xl181 style='border-right:1.0pt solid black;border-left: none'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.1em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
    <?php $row2 = $remedial_grade6[1] ?? null; ?>
    <td class=xl66></td>
    <td colspan=5 class=xl131><?php echo ($row2 && isset($row2['learning_area']) && $row2['learning_area'] !== '') ? htmlspecialchars($row2['learning_area']) : '&nbsp;'; ?></td>
    <td colspan=9 class=xl132 style='border-left:none'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row2['final_rating']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=7 class=xl132 style='border-left:none'><?php echo ($row2 && isset($row2['remedial_class_mark']) && $row2['remedial_class_mark'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row2['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=6 class=xl132 style='border-left:none'><?php echo ($row2 && isset($row2['recomputed_final_grade']) && $row2['recomputed_final_grade'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row2['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=2 class=xl132 style='border-right:1.0pt solid black;border-left: none'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=16 style='mso-height-source:userset;height:7.5pt'>
  <td height=16 class=xl66 style='height:7.5pt'></td>
  <td colspan=10 class=xl111></td>
  <td class=xl111></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td colspan=15 class=xl111></td>
  <td class=xl111></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <?php $grade7_info = getSchoolInfo($conn, $grade7_school); ?>
  <?php $grade8_info = getSchoolInfo($conn, $grade8_school); ?>
  <td colspan=2 class=xl235>School:</td>
  <td colspan=11 class=xl154>
  <?php
            if (!empty($grade7_info['school_name'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade7_info['school_name'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
         ?>
  </td>
  <td colspan=4 class=xl201>School ID:</td>
  <td colspan=2 class=xl154 style='border-right:1.0pt solid black'>
  <?php
            if (!empty($grade7_info['school_id'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade7_info['school_id'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td class=xl89 colspan=2 style='mso-ignore:colspan'>School:</td>
  <td colspan=20 class=xl154>
  <?php
            if (!empty($grade8_info['school_name'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade8_info['school_name'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
         ?>
  </td>
  <td colspan=5 class=xl155>School ID:</td>
  <td colspan=2 class=xl154 style='border-right:1.0pt solid black'>
  <?php
         if (!empty($grade8_info['school_id'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade8_info['school_id'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
  <td colspan=3 class=xl123>
  <?php
            if (!empty($grade7_info['district'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade7_info['district'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
         ?>
  </td>
  <td class=xl66 colspan=2 style='mso-ignore:colspan'>Division:</td>
  <td colspan=9 class=xl94>
  <?php
         if (!empty($grade7_info['division'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade7_info['division'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=2 class=xl76>Region:</td>
  <td class=xl92>
  <?php
         if (!empty($grade7_info['region'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade7_info['region'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
  <td colspan=3 class=xl123>
  <?php
         if (!empty($grade8_info['district'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade8_info['district'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
         ?>
  </td>
  <td class=xl66 colspan=3 style='mso-ignore:colspan'>Division:</td>
  <td colspan=17 class=xl94>
  <?php
         if (!empty($grade8_info['division'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade8_info['division'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=3 class=xl76>Region:</td>
  <td class=xl93 style='border-top:none'>
  <?php
         if (!empty($grade8_info['region'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade8_info['region'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl91 colspan=4 style='mso-ignore:colspan'>Classified as Grade:</td>
  <td class=xl94>
  <?php
         if (isset($grade7_school) && !empty($grade7_school['grade_level'])) {
            $roman7 = grade_label_to_roman($grade7_school['grade_level']);
            echo '<strong>' . htmlspecialchars($roman7) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl95 colspan=2 style='mso-ignore:colspan'>Section:</td>
  <td class=xl66></td>
  <td colspan=5 class=xl94>
  <?php
         if (isset($grade7_school) && !empty($grade7_school['section'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade7_school['section'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=4 class=xl75>School Year:</td>
  <td colspan=2 class=xl94 style='border-right:1.0pt solid black'>
  <?php
         if (isset($grade7_school) && !empty($grade7_school['school_year'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade7_school['school_year'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td colspan=4 class=xl96>Classified as Grade:</td>
  <td colspan=2 class=xl94>
  <?php
         if (isset($grade8_school) && !empty($grade8_school['grade_level'])) {
            $roman8 = grade_label_to_roman($grade8_school['grade_level']);
            echo '<strong>' . htmlspecialchars($roman8) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=3 class=xl76>Section:</td>
  <td colspan=10 class=xl94>
  <?php
         if (isset($grade8_school) && !empty($grade8_school['section'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade8_school['section'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=6 class=xl76>School Year:</td>
  <td colspan=4 class=xl94 style='border-right:1.0pt solid black'>
  <?php
         if (isset($grade8_school) && !empty($grade8_school['school_year'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade8_school['school_year'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td colspan=6 class=xl96>Name of Adviser/Teacher:</td>
  <td colspan=7 class=xl94>
  <?php
         $adv_full7 = getAdviserFullName($conn, $grade7_school);
         if (!empty($adv_full7)) {
            echo '<strong>' . strtoupper(htmlspecialchars($adv_full7)) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=3 class=xl76>Signature:</td>
  <td colspan=3 class=xl94 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl96 colspan=5 style='mso-ignore:colspan'>Name of Adviser/Teacher:</td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td colspan=13 class=xl94>
  <?php
         $adv_full8 = getAdviserFullName($conn, $grade8_school);
         if (!empty($adv_full8)) {
            echo '<strong>' . strtoupper(htmlspecialchars($adv_full8)) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
  </td>
  <td colspan=5 class=xl76>Signature:</td>
  <td colspan=4 class=xl123 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=5 style='mso-height-source:userset;height:3.75pt'>
  <td height=5 class=xl66 style='height:3.75pt'></td>
  <td class=xl101>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl103>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl101>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl102>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl70>&nbsp;</td>
  <td class=xl103>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=5 style='mso-height-source:userset;height:3.75pt'>
  <td height=5 class=xl66 style='height:3.75pt'></td>
  <td class=xl112>&nbsp;</td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl113>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl112>&nbsp;</td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl113>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=24 style='mso-height-source:userset;height:18.0pt'>
  <td height=24 class=xl66 style='height:18.0pt'></td>
  <td colspan=9 rowspan=2 class=xl216 width=268 style='border-right:.5pt solid black;
  border-bottom:.5pt solid black;width:202pt'>LEARNING AREAS</td>
  <td colspan=5 class=xl237 width=140 style='border-right:.5pt solid black;
  border-left:none;width:105pt'>Quarterly Rating</td>
  <td colspan=3 rowspan=2 class=xl133 width=62 style='border-right:.5pt solid black;
  border-bottom:.5pt solid black;width:47pt'>Final Rating</td>
  <td colspan=2 rowspan=2 class=xl133 width=89 style='border-right:1.0pt solid black;
  border-bottom:.5pt solid black;width:67pt'>Remarks</td>
  <td class=xl66></td>
  <td colspan=14 rowspan=2 class=xl216 width=265 style='border-right:.5pt solid black;
  border-bottom:.5pt solid black;width:202pt'>Learning Areas</td>
  <td colspan=10 class=xl214 width=157 style='border-left:none;width:119pt'>Quarterly
  Rating</td>
  <td colspan=3 rowspan=2 class=xl133 width=57 style='border-right:.5pt solid black;
  border-bottom:.5pt solid black;width:43pt'>Final Rating</td>
  <td colspan=2 rowspan=2 class=xl133 width=85 style='border-right:1.0pt solid black;
  border-bottom:.5pt solid black;width:64pt'>Remarks</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=24 style='mso-height-source:userset;height:18.0pt'>
  <td height=24 class=xl66 style='height:18.0pt'></td>
  <td class=xl104 style='border-top:none;border-left:none'>1</td>
  <td colspan=2 class=xl162 style='border-right:.5pt solid black;border-left:
  none'>2</td>
  <td class=xl104 style='border-top:none;border-left:none'>3</td>
  <td class=xl104 style='border-top:none;border-left:none'>4</td>
  <td class=xl66></td>
  <td colspan=3 class=xl162 style='border-right:.5pt solid black;border-left:
  none'>1</td>
  <td colspan=2 class=xl162 style='border-right:.5pt solid black;border-left:
  none'>2</td>
  <td colspan=3 class=xl162 style='border-right:.5pt solid black;border-left:
  none'>3</td>
  <td colspan=2 class=xl162 style='border-right:.5pt solid black;border-left:
  none'>4</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 1; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'Mother Tongue';
        }
    ?>
  </td>
   <?php $row7 = $grades_grade7[1] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 1; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'Mother Tongue';
        }
    ?>
  </td>
  <?php $row8 = $grades_grade8[1] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 2; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'Filipino';
        }
    ?>
  </td>
  <?php $row7 = $grades_grade7[2] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 2; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'Filipino';
        }
    ?>
  </td>
  <?php $row8 = $grades_grade8[2] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 3; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'English';
        }
  ?>
  </td>
  <?php $row7 = $grades_grade7[3] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 3; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'English';
        }
  ?>
  </td>
  <?php $row8 = $grades_grade8[3] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 4; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'Mathematics';
        }
    ?>
  </td>
  <?php $row7 = $grades_grade7[4] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>
 <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 4; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'Mathematics';
        }
    ?>
  </td>
  <?php $row8 = $grades_grade8[4] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 5; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'Science';
        }
    ?>
  </td>
  <?php $row7 = $grades_grade7[5] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 5; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'Science';
        }
    ?>
  </td>
  <?php $row8 = $grades_grade8[5] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 6; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'Araling Panlipunan';
        }
    ?></td>
  <?php $row7 = $grades_grade7[6] ?? null; ?>
<td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
<td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
<td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 6; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'Araling Panlipunan';
        }
    ?></td>
  <?php $row8 = $grades_grade8[6] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 7; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'EPP / TLE';
        }
    ?>
  </td>
  <?php $row7 = $grades_grade7[7] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 7; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'EPP / TLE';
        }
    ?>
  </td>
  <?php $row8 = $grades_grade8[7] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 8; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'MAPEH';
        }
    ?>
  </td>
  <?php $row7 = $grades_grade7[8] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 8; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'MAPEH';
        }
    ?>
  </td>
  <?php $row8 = $grades_grade8[8] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 9; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'Music';
        }
    ?>
  </td>
  <?php $row7 = $grades_grade7[9] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 9; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'Music';
        }
    ?>
  </td>
  <?php $row8 = $grades_grade8[9] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 10; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'Arts';
        }
    ?>
  </td>
  <?php $row7 = $grades_grade7[10] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 10; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'Arts';
        }
    ?>
  </td>
  <?php $row8 = $grades_grade8[10] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 11; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'Physical Education';
        }
    ?></td>
  <?php $row7 = $grades_grade7[11] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 11; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'Physical Education';
        }
    ?></td>
  <?php $row8 = $grades_grade8[11] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 12; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'Health';
        }
    ?></td>
  <?php $row7 = $grades_grade7[12] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 12; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'Health';
        }
    ?></td>
  <?php $row8 = $grades_grade8[12] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 13; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo 'Eduk. sa Pagpapakatao';
        }
    ?></td>
  <?php $row7 = $grades_grade7[13] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 13; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo 'Eduk. sa Pagpapakatao';
        }
    ?></td>
  <?php $row8 = $grades_grade8[13] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl173 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 14; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo '*Arabic Language';
        }
    ?></td>
  <?php $row7 = $grades_grade7[14] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>  <td class=xl66></td>
  <td colspan=14 class=xl173 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 14; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo '*Arabic Language';
        }
    ?></td>
  <?php $row8 = $grades_grade8[14] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl173 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade7_school) && $grade7_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 15; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade7_school['id']));
        } else {
           echo '*Islamic Values Education';
        }
    ?></td>
  <?php $row7 = $grades_grade7[15] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q1']) && $row7['q1'] !== '') ? '<strong>' . round($row7['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q2']) && $row7['q2'] !== '') ? '<strong>' . round($row7['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q3']) && $row7['q3'] !== '') ? '<strong>' . round($row7['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['q4']) && $row7['q4'] !== '') ? '<strong>' . round($row7['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['final_rating']) && $row7['final_rating'] !== '') ? '<strong>' . round($row7['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;vertical-align:middle;text-align:center'><?php echo ($row7 && isset($row7['remarks']) && $row7['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row7['remarks']) . '</span>' : '&nbsp;'; ?></td>  <td class=xl66></td>
  <td colspan=14 class=xl173 style='border-right:.5pt solid black'><?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade8_school) && $grade8_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 15; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade8_school['id']));
        } else {
           echo '*Islamic Values Education';
        }
    ?></td>
  <?php $row8 = $grades_grade8[15] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q1']) && $row8['q1'] !== '') ? '<strong>' . round($row8['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q2']) && $row8['q2'] !== '') ? '<strong>' . round($row8['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q3']) && $row8['q3'] !== '') ? '<strong>' . round($row8['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['q4']) && $row8['q4'] !== '') ? '<strong>' . round($row8['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none'><?php echo ($row8 && isset($row8['final_rating']) && $row8['final_rating'] !== '') ? '<strong>' . round($row8['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none'><?php echo ($row8 && isset($row8['remarks']) && $row8['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row8['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=25 style='mso-height-source:userset;height:18.75pt'>
  <td height=25 class=xl66 style='height:18.75pt'></td>
  <td colspan=9 class=xl194 style='border-right:.5pt solid black'>General
  Average</td>
  <td class=xl109 style='border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl186 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl109 style='border-left:none'>&nbsp;</td>
  <td class=xl109 style='border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl139 style='border-right:.5pt solid black;border-left:
  none'><?php echo ($ga7_row && isset($ga7_row['final_rating']) && $ga7_row['final_rating'] !== '') ? '<strong>' . round($ga7_row['final_rating']) . '%</strong>' : (($ga7_final !== null) ? '<strong>' . $ga7_final . '%</strong>' : '&nbsp;'); ?></td>
  <td colspan=2 class=xl205 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl194 style='border-right:.5pt solid black'>General
  Average</td>
  <td colspan=3 class=xl139 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl139 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl139 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl139 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl139 style='border-right:.5pt solid black;border-left:
  none'><?php echo ($ga8_row && isset($ga8_row['final_rating']) && $ga8_row['final_rating'] !== '') ? '<strong>' . round($ga8_row['final_rating']) . '%</strong>' : (($ga8_final !== null) ? '<strong>' . $ga8_final . '%</strong>' : '&nbsp;'); ?></td>
  <td colspan=2 class=xl207 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=10 style='mso-height-source:userset;height:3pt'>
  <td height=10 class=xl66 style='height:3pt'></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=21 style='mso-height-source:userset;height:15.75pt'>
  <td height=21 class=xl66 style='height:15.75pt'></td>
  <td colspan=5 class=xl145 style='border-right:.5pt solid black'>Remedial
  Classes</td>
  <td colspan="4" class="xl142" style="border-left:
   none">Conducted from:</td>
   <td colspan="4" class="xl142" style="border-left:none; vertical-align:middle; text-align:center">
   <?php
      $r7_head = $remedial_grade7[0] ?? null;
      if ($r7_head && !empty($r7_head['conducted_from'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r7_head['conducted_from'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
   <td colspan="1" class=xl142 style='border-left:
   none'>&nbsp;&nbsp;to:</td>
   <td colspan="5" class="xl142" style="border-right:1.0pt solid black; border-left:none; vertical-align:middle; text-align:center">
   <?php
      if ($r7_head && !empty($r7_head['conducted_to'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r7_head['conducted_to'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>                  
  <td class=xl66></td>
  <td colspan=5 class=xl145 style='border-right:.5pt solid black'>Remedial
  Classes</td>
   <td colspan=9 class=xl142 style='border-left:
   none'>Conducted from:</td>
   <td colspan=8 class=xl142 style='border-left:none; vertical-align:middle; text-align:center'>
   <?php
      $r8_head = $remedial_grade8[0] ?? null;
      if ($r8_head && !empty($r8_head['conducted_from'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r8_head['conducted_from'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
   <td colspan=2 class=xl142 style='border-left:
   none'>&nbsp;&nbsp;to:</td>
   <td colspan=5 class=xl142 style='border-right:1.0pt solid black; border-left:none; vertical-align:middle; text-align:center'>
   <?php
      if ($r8_head && !empty($r8_head['conducted_to'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r8_head['conducted_to'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=42 style='mso-height-source:userset;height:31.5pt'>
  <td height=42 class=xl66 style='height:31.5pt'></td>
  <td colspan=5 class=xl148 style='border-right:.5pt solid black'>Learning
  Areas</td>
  <td colspan=4 class=xl202 style='border-right:.5pt solid black;border-left:
  none'>Final Rating</td>
  <td colspan=4 class=xl151 width=105 style='border-right:.5pt solid black;
  border-left:none;width:79pt'>Remedial Class Mark</td>
  <td colspan=4 class=xl151 width=97 style='border-right:.5pt solid black;
  border-left:none;width:73pt'>Recomputed Final Grade</td>
  <td colspan=2 class=xl199 style='border-right:1.0pt solid black;border-left:
  none'>Remarks</td>
  <td class=xl66></td>
  <td colspan=5 class=xl148 style='border-right:.5pt solid black'>Learning
  Areas</td>
  <td colspan=9 class=xl151 width=108 style='border-left:none;width:84pt'>Final
  Rating</td>
  <td colspan="7" class="xl151" width="107" style="border-right:.5pt solid black;width:81pt">Remedial Class Mark</td>
  <td colspan=6 class=xl152 width=107 style='border-right:.5pt solid black;
  width:81pt'>Recomputed Final Grade</td>
  <td colspan=2 class=xl199 style='border-right:1.0pt solid black;border-left:
  none'>Remarks</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <?php $row3 = $remedial_grade7[0] ?? null; ?>
   <td colspan=5 class=xl203 style='border-right:.5pt solid black'><?php echo ($row3 && isset($row3['learning_area']) && $row3['learning_area'] !== '') ? htmlspecialchars($row3['learning_area']) : '&nbsp;'; ?></td>
   <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left: none'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row3['final_rating']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=4 class=xl132 style='border-left:none'><?php echo ($row3 && isset($row3['remedial_class_mark']) && $row3['remedial_class_mark'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row3['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left: none'><?php echo ($row3 && isset($row3['recomputed_final_grade']) && $row3['recomputed_final_grade'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row3['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl181 style='border-right:1.0pt solid black;border-left: none'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.1em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
   <?php $row4 = $remedial_grade8[0] ?? null; ?>
   <td class=xl66></td>
   <td colspan=5 class=xl131><?php echo ($row4 && isset($row4['learning_area']) && $row4['learning_area'] !== '') ? htmlspecialchars($row4['learning_area']) : '&nbsp;'; ?></td>
   <td colspan=9 class=xl132 style='border-left:none'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row4['final_rating']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=7 class=xl132 style='border-left:none'><?php echo ($row4 && isset($row4['remedial_class_mark']) && $row4['remedial_class_mark'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row4['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=6 class=xl132 style='border-left:none'><?php echo ($row4 && isset($row4['recomputed_final_grade']) && $row4['recomputed_final_grade'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row4['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl132 style='border-right:1.0pt solid black;border-left: none'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=28 style='mso-height-source:userset;height:21.0pt'>
  <td height=28 class=xl66 style='height:21.0pt'></td>
  <?php $row3 = $remedial_grade7[1] ?? null; ?>
    <td colspan=5 class=xl203 style='border-right:.5pt solid black'><?php echo ($row3 && isset($row3['learning_area']) && $row3['learning_area'] !== '') ? htmlspecialchars($row3['learning_area']) : '&nbsp;'; ?></td>
    <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left: none'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row3['final_rating']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=4 class=xl132 style='border-left:none'><?php echo ($row3 && isset($row3['remedial_class_mark']) && $row3['remedial_class_mark'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row3['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left: none'><?php echo ($row3 && isset($row3['recomputed_final_grade']) && $row3['recomputed_final_grade'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row3['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=2 class=xl181 style='border-right:1.0pt solid black;border-left: none'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.1em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
    <?php $row4 = $remedial_grade8[1] ?? null; ?>
    <td class=xl66></td>
    <td colspan=5 class=xl131><?php echo ($row4 && isset($row4['learning_area']) && $row4['learning_area'] !== '') ? htmlspecialchars($row4['learning_area']) : '&nbsp;'; ?></td>
    <td colspan=9 class=xl132 style='border-left:none'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row4['final_rating']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=7 class=xl132 style='border-left:none'><?php echo ($row4 && isset($row4['remedial_class_mark']) && $row4['remedial_class_mark'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row4['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=6 class=xl132 style='border-left:none'><?php echo ($row4 && isset($row4['recomputed_final_grade']) && $row4['recomputed_final_grade'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . round($row4['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
    <td colspan=2 class=xl132 style='border-right:1.0pt solid black;border-left: none'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.0em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=30 style='mso-height-source:userset;height:19pt'>
  <td height=30 class=xl66 style='height:19pt'></td>
  <td colspan=34 class=xl243>For Transfer Out /Elementary School Completer Only</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td colspan=49 class=xl253 style='border-right:.5pt solid black'>CERTIFICATION</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl251>&nbsp;</td>
  <td colspan=8 class=xl256>I CERTIFY that this is a true record of</td>
  <td colspan=9 class=xl249>&nbsp;</td>
  <td class=xl254>with LRN</td>
  <td colspan=6 class=xl249>&nbsp;</td>
  <td colspan=21 class=xl270>and that he/she is eligible for addmision to
  Grade</td>
  <td colspan=2 class=xl255>&nbsp;</td>
  <td class=xl262>.</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl251>&nbsp;</td>
  <td colspan=3 class=xl254>School Name:</td>
  <td colspan=7 class=xl257>&nbsp;</td>
  <td colspan=3 class=xl259>School ID:</td>
  <td colspan=4 class=xl258>&nbsp;</td>
  <td class=xl261>Division:</td>
  <td colspan=5 class=xl260>&nbsp;</td>
  <td class=xl261 colspan=12 style='mso-ignore:colspan'>Last School Year
  Attended:</td>
  <td class=xl66></td>
  <td colspan=11 class=xl255>&nbsp;</td>
  <td class=xl246>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:10pt'>
  <td height=27 class=xl66 style='height:10pt'></td>
  <td class=xl251>&nbsp;</td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td class=xl246>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:10pt'>
  <td height=27 class=xl66 style='height:10pt'></td>
  <td class=xl251>&nbsp;</td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl263></td>
  <td colspan=7 class=xl264>&nbsp;</td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td colspan=10 class=xl249>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td class=xl246>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl252>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl265>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td colspan=3 class=xl267>Date</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl265>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td colspan=10 class=xl268>Signature of Principal/School Head over Printed
  Name</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl269>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl265 colspan=8 style='mso-ignore:colspan'>(Affix School Seal here)</td>
  <td class=xl241>&nbsp;</td>
  <td class=xl241>&nbsp;</td>
  <td class=xl241>&nbsp;</td>
  <td class=xl247>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=22 style='height:5pt'>
  <td height=22 class=xl66 style='height:5pt'></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td colspan=49 class=xl253 style='border-right:.5pt solid black'>CERTIFICATION</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl251>&nbsp;</td>
  <td colspan=8 class=xl256>I CERTIFY that this is a true record of</td>
  <td colspan=9 class=xl249>&nbsp;</td>
  <td class=xl254>with LRN</td>
  <td colspan=6 class=xl249>&nbsp;</td>
  <td colspan=21 class=xl270>and that he/she is eligible for addmision to
  Grade</td>
  <td colspan=2 class=xl255>&nbsp;</td>
  <td class=xl262>.</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl251>&nbsp;</td>
  <td colspan=3 class=xl254>School Name:</td>
  <td colspan=7 class=xl257>&nbsp;</td>
  <td colspan=3 class=xl259>School ID:</td>
  <td colspan=4 class=xl258>&nbsp;</td>
  <td class=xl261>Division:</td>
  <td colspan=5 class=xl260>&nbsp;</td>
  <td class=xl261 colspan=12 style='mso-ignore:colspan'>Last School Year
  Attended:</td>
  <td class=xl66></td>
  <td colspan=11 class=xl255>&nbsp;</td>
  <td class=xl246>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:10pt'>
  <td height=27 class=xl66 style='height:10pt'></td>
  <td class=xl251>&nbsp;</td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td class=xl246>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:10pt'>
  <td height=27 class=xl66 style='height:10pt'></td>
  <td class=xl251>&nbsp;</td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl263></td>
  <td colspan=7 class=xl264>&nbsp;</td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td colspan=10 class=xl249>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td class=xl246>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl252>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl265>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td colspan=3 class=xl267>Date</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl265>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td colspan=10 class=xl268>Signature of Principal/School Head over Printed
  Name</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl269>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl265 colspan=8 style='mso-ignore:colspan'>(Affix School Seal here)</td>
  <td class=xl241>&nbsp;</td>
  <td class=xl241>&nbsp;</td>
  <td class=xl241>&nbsp;</td>
  <td class=xl247>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=22 style='height:5pt'>
  <td height=22 class=xl66 style='height:5pt'></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td colspan=49 class=xl253 style='border-right:.5pt solid black'>CERTIFICATION</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl251>&nbsp;</td>
  <td colspan=8 class=xl256>I CERTIFY that this is a true record of</td>
  <td colspan=9 class=xl249>&nbsp;</td>
  <td class=xl254>with LRN</td>
  <td colspan=6 class=xl249>&nbsp;</td>
  <td colspan=21 class=xl270>and that he/she is eligible for addmision to
  Grade></td>
  <td colspan=2 class=xl255>&nbsp;</td>
  <td class=xl262>.</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl251>&nbsp;</td>
  <td colspan=3 class=xl254>School Name:</td>
  <td colspan=7 class=xl257>&nbsp;</td>
  <td colspan=3 class=xl259>School ID:</td>
  <td colspan=4 class=xl258>&nbsp;</td>
  <td class=xl261>Division:</td>
  <td colspan=5 class=xl260>&nbsp;</td>
  <td class=xl261 colspan=12 style='mso-ignore:colspan'>Last School Year
  Attended:</td>
  <td class=xl66></td>
  <td colspan=11 class=xl255>&nbsp;</td>
  <td class=xl246>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:10pt'>
  <td height=27 class=xl66 style='height:10pt'></td>
  <td class=xl251>&nbsp;</td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl68></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td class=xl246>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:10pt'>
  <td height=27 class=xl66 style='height:10pt'></td>
  <td class=xl251>&nbsp;</td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl263></td>
  <td colspan=7 class=xl264>&nbsp;</td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td colspan=10 class=xl249>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td class=xl246>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl252>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl265>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td colspan=3 class=xl267>Date</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl265>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td class=xl248>&nbsp;</td>
  <td colspan=10 class=xl268>Signature of Principal/School Head over Printed
  Name</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl269>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl266>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl240>&nbsp;</td>
  <td class=xl265 colspan=8 style='mso-ignore:colspan'>(Affix School Seal here)</td>
  <td class=xl241>&nbsp;</td>
  <td class=xl241>&nbsp;</td>
  <td class=xl241>&nbsp;</td>
  <td class=xl247>&nbsp;</td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
 </tr>
 <tr height=19 style='mso-height-source:userset;height:14.45pt'>
  <td height=19 class=xl66 style='height:14.45pt'></td>
  <td class=xl66 colspan=7 style='mso-ignore:colspan'>May add Certification Box
  if needed</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td></td>
  <td colspan=9 class=xl272>SFRT Revised
  2017</td>
 </tr>
 <![endif]>
</table>
</div>

</body>

</html>
