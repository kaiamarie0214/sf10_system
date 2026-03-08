<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}
// --- SF10 dynamic data fetcher ---
require_once dirname(__DIR__, 1) . '/includes/db.php';

$student = null;
// Sheet001 covers Grade 1-4 slots (left+right columns per row)
// With mid-year transfers, a single grade can occupy TWO slots (originating + receiving)
$grade1_school = null;
$grade2_school = null;
$grade3_school = null;
$grade4_school = null;
$grade5_school = null;
$grade6_school = null;
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

   // Single-record backward-compat (first record per grade)
   $grade1_school = $grade1_schools_all[0] ?? null;
   $grade2_school = $grade2_schools_all[0] ?? null;
   $grade3_school = $grade3_schools_all[0] ?? null;
   $grade4_school = $grade4_schools_all[0] ?? null;
   $grade5_school = $grade5_schools_all[0] ?? null;
   $grade6_school = $grade6_schools_all[0] ?? null;

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
    
    // Check if there's a custom subject name for this student (custom override)
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
    
    // Check grade-level configuration (global aliases)
    if ($grade_level_num) {
        $table_check = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'");
        
        if ($table_check && $table_check->num_rows > 0) {
            $group_query = $conn->prepare("SELECT subject_name FROM subject_grade_groups 
                                          WHERE grade_level = ? AND subject_id = ? 
                                          AND (school_year = ? OR school_year IS NULL)
                                          ORDER BY school_year DESC LIMIT 1");
            $group_query->bind_param("iis", $grade_level_num, $subject_id, $school_year);
            $group_query->execute();
            $group_res = $group_query->get_result();
            
            if ($group_res && $group_res->num_rows > 0) {
                $group_result = $group_res->fetch_assoc();
                // Only return if name is not empty
                if (!empty($group_result['subject_name'])) {
                    return $group_result['subject_name'];
                }
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
        'school_name' => $school_row['school_name'] ?? '',
        'school_id' => $school_row['school_id'] ?? '',
        'district' => $school_row['district'] ?? '',
        'division' => $school_row['division'] ?? '',
        'region' => $school_row['region'] ?? ''
    ];

    // If any critical field is missing, try to fetch from the assigned adviser in the system
    if (empty($info['school_name']) || empty($info['school_id']) || empty($info['district'])) {
        $adviser_name = $school_row['adviser_name'] ?? '';
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
            $grade_label = $school_row['grade_level'] ?? '';
            $section = $school_row['section'] ?? '';
            $school_year_str = $school_row['school_year'] ?? '';
            
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
   // Build $sf10_slots: an ordered flat list of slots for the SF10 form.
   // Each slot = one school record (possibly a transfer split of the same grade).
   // Sheet001 covers Grades 1-4 → 4 slots (slots 0-3 → displayed as Grade I-IV).
   // If grade N has TWO records (mid-year transfer), they occupy TWO consecutive slots.
   // The school variables $grade1_school .. $grade4_school are re-assigned from slots.
   // -------------------------------------------------------------------------
   $sf10_slots = []; // flat ordered list
   for ($gn = 1; $gn <= 8; $gn++) {
      if (!empty($grades_by_grade[$gn])) {
         foreach ($grades_by_grade[$gn] as $slot) {
            $sf10_slots[] = $slot;
         }
      } else {
         // Grade has no school record — add an empty placeholder slot
         $sf10_slots[] = ['school' => null, 'grades' => []];
      }
   }

   // Re-assign convenience school variables for sheet001 (slots 0-3 = Grade row 1 left/right + row 2 left/right)
   $grade1_school = $sf10_slots[0]['school'] ?? null;
   $grade2_school = $sf10_slots[1]['school'] ?? null;
   $grade3_school = $sf10_slots[2]['school'] ?? null;
   $grade4_school = $sf10_slots[3]['school'] ?? null;

   // Re-assign grades arrays from slots
   $grades_grade1 = $sf10_slots[0]['grades'] ?? [];
   $grades_grade2 = $sf10_slots[1]['grades'] ?? [];
   $grades_grade3 = $sf10_slots[2]['grades'] ?? [];
   $grades_grade4 = $sf10_slots[3]['grades'] ?? [];
   // Grades 5-6 are on sheet002, but keep fallback for any shared usage
   for ($i = 5; $i <= 8; $i++) {
      ${"grades_grade" . $i} = $grades_by_grade[$i][0]['grades'] ?? [];
   }

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
      // Extract the numeric grade number from grade_level (e.g. "Grade 3" -> 3, "3" -> 3)
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
<link id=Main-File rel=Main-File href="../SF10_official_final.htm">
<link rel=File-List href=filelist.xml>
<!--[if !mso]>
<style>
v\:* {behavior:url(#default#VML);}
o\:* {behavior:url(#default#VML);}
x\:* {behavior:url(#default#VML);}
.shape {behavior:url(#default#VML);}
</style>
<![endif]-->
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
        width: 1152px !important;
        margin: 0 auto !important;
        padding: 0 !important;
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        /* Balanced zoom for sheet1 centering */
        zoom: 1;
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
   parent.fnSetActiveSheet(0);
  else
   window.setTimeout("fnUpdateTabs();",150);
 }
}

if (window.name!="frSheet")
 window.location.replace("../SF10_official_final.htm");
else
 fnUpdateTabs();
//-->
</script>
<![endif]>
</head>

<body link="#0563C1" vlink="#954F72">

<!-- Back to preview card (hidden when printing) -->
<div class="no-print" style="width: 100%; max-width: 1152px; margin-bottom: 15px;">
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
<table border=0 cellpadding=0 cellspacing=0 width=1152 style='border-collapse:
 collapse;table-layout:fixed;width:872pt'>
 <col class=xl66 width=10 style='mso-width-source:userset;mso-width-alt:365;
 width:8pt'>
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
 <tr height=25 style='mso-height-source:userset;height:18.75pt'>
  <td height=25 class=xl66 width=10 style='height:18.75pt;width:8pt'><a
  name="Print_Area"></a></td>
  <td width=41 style='width:31pt' align=left valign=top><!--[if gte vml 1]><v:shapetype
   id="_x0000_t75" coordsize="21600,21600" o:spt="75" o:preferrelative="t"
   path="m@4@5l@4@11@9@11@9@5xe" filled="f" stroked="f">
   <v:stroke joinstyle="miter"/>
   <v:formulas>
    <v:f eqn="if lineDrawn pixelLineWidth 0"/>
    <v:f eqn="sum @0 1 0"/>
    <v:f eqn="sum 0 0 @1"/>
    <v:f eqn="prod @2 1 2"/>
    <v:f eqn="prod @3 21600 pixelWidth"/>
    <v:f eqn="prod @3 21600 pixelHeight"/>
    <v:f eqn="sum @0 0 1"/>
    <v:f eqn="prod @6 1 2"/>
    <v:f eqn="prod @7 21600 pixelWidth"/>
    <v:f eqn="sum @8 21600 0"/>
    <v:f eqn="prod @7 21600 pixelHeight"/>
    <v:f eqn="sum @10 21600 0"/>
   </v:formulas>
   <v:path o:extrusionok="f" gradientshapeok="t" o:connecttype="rect"/>
   <o:lock v:ext="edit" aspectratio="t"/>
  </v:shapetype><v:shape id="Picture_x0020_19" o:spid="_x0000_s1032" type="#_x0000_t75"
   style='position:absolute;margin-left:27pt;margin-top:6pt;width:84.75pt;
   height:1in;z-index:1;visibility:visible'
   <v:imagedata src="image001.png" o:title=""/>
   <x:ClientData ObjectType="Pict">
    <x:SizeWithCells/>
    <x:CF>Bitmap</x:CF>
    <x:AutoPict/>
   </x:ClientData>
  </v:shape><![endif]--><![if !vml]><span style='mso-ignore:vglayout;
  position:absolute;z-index:1;margin-left:36px;margin-top:8px;width:113px;
  height:96px'><img width=113 height=96 src=image002.png v:shapes="Picture_x0020_19"></span><![endif]><span
  style='mso-ignore:vglayout2'>
  <table cellpadding=0 cellspacing=0>
   <tr>
    <td height=25 class=xl65 width=41 style='height:18.75pt;width:31pt'>SF10-ES</td>
   </tr>
  </table>
  </span></td>
  <td class=xl66 width=19 style='width:14pt'></td>
  <td class=xl66 width=40 style='width:30pt'></td>
  <td class=xl66 width=37 style='width:28pt'></td>
  <td class=xl66 width=23 style='width:17pt'></td>
  <td class=xl66 width=21 style='width:16pt'></td>
  <td colspan=31 rowspan=2 class=xl71 width=697 style='width:528pt'>Republic of
  the Philippines</td>
  <td class=xl71 width=26 style='width:20pt'></td>
  <td class=xl71 width=16 style='width:12pt'></td>
  <td class=xl71 width=12 style='width:9pt'></td>
  <td width=12 style='width:9pt' align=left valign=top><!--[if gte vml 1]><v:shape
   id="Picture_x0020_2" o:spid="_x0000_s1033" type="#_x0000_t75" alt="http://depedverify.appspot.com/img/logo.gif"
   style='position:absolute;margin-left:2.25pt;margin-top:8.25pt;width:139.5pt;
   height:63pt;z-index:2;visibility:visible'
   <v:imagedata src="image003.png" o:title=""/>
   <x:ClientData ObjectType="Pict">
    <x:SizeWithCells/>
    <x:CF>Bitmap</x:CF>
    <x:AutoPict/>
   </x:ClientData>
  </v:shape><![endif]--><![if !vml]><span style='mso-ignore:vglayout;
  position:absolute;z-index:2;margin-left:3px;margin-top:11px;width:186px;
  height:84px'><img width=186 height=84 src=image004.png
  alt="http://depedverify.appspot.com/img/logo.gif" v:shapes="Picture_x0020_2"></span><![endif]><span
  style='mso-ignore:vglayout2'>
  <table cellpadding=0 cellspacing=0>
   <tr>
    <td height=25 width=12 style='height:18.75pt;width:9pt'></td>
   </tr>
  </table>
  </span></td>
  <td class=xl66 width=12 style='width:9pt'></td>
  <td class=xl66 width=24 style='width:18pt'></td>
  <td class=xl66 width=14 style='width:11pt'></td>
  <td class=xl66 width=17 style='width:13pt'></td>
  <td class=xl66 width=24 style='width:18pt'></td>
  <td class=xl66 width=16 style='width:12pt'></td>
  <td class=xl66 width=33 style='width:25pt'></td>
  <td class=xl66 width=52 style='width:39pt'></td>
  <td class=xl66 width=6 style='width:5pt'></td>
  <td class=xl66 width=0></td>
  <td class=xl66 width=0></td>
 </tr>
 <tr height=5 style='mso-height-source:userset;height:3.75pt'>
  <td height=5 class=xl66 style='height:3.75pt'></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl71></td>
  <td class=xl71></td>
  <td class=xl71></td>
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
 </tr>
 <tr height=24 style='mso-height-source:userset;height:18.0pt'>
  <td height=24 class=xl66 style='height:18.0pt'></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td colspan=31 rowspan=1 class=xl71>Department of Education</td>
  <td class=xl71></td>
  <td class=xl71></td>
  <td class=xl71></td>
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
 </tr>
 <tr height=0 style='display:none;mso-height-source:userset;mso-height-alt:
  375'>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl71></td>
  <td class=xl71></td>
  <td class=xl71></td>
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
 </tr>
 <tr height=31 style='mso-height-source:userset;height:23.25pt'>
  <td height=31 class=xl66 style='height:23.25pt'></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
   <td colspan=31 rowspan=3 class=xl227 width=697 style='width:528pt; text-align:center;'>Learner
   Permanent Academic Record for Elementary School (SF10-ES)<br />
      <font class="font16">(Formerly Form 137)</font></td>
  <td class=xl72></td>
  <td class=xl72></td>
  <td class=xl72></td>
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
 </tr>
 <tr height=31 style='mso-height-source:userset;height:23.25pt'>
  <td height=31 class=xl66 style='height:23.25pt'></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl72></td>
  <td class=xl72></td>
  <td class=xl72></td>
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
 </tr>
 <tr height=25 style='mso-height-source:userset;height:18.75pt'>
  <td height=25 class=xl66 style='height:18.75pt'></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl72></td>
  <td class=xl72></td>
  <td class=xl72></td>
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
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td colspan=49 class=xl230>LEARNER'S PERSONAL INFORMATION</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=33 style='mso-height-source:userset;height:24.75pt'>
  <td height=33 class=xl66 style='height:24.75pt'></td>
   <td class="xl66" colspan="3" style="mso-ignore:colspan">LAST NAME:</td>
   <td colspan="9" class="xl159"><?php if(isset($student['last_name'])) echo strtoupper(htmlspecialchars($student['last_name'])); else echo '&nbsp;'; ?></td>
   <td class="xl66" colspan="3" style="mso-ignore:colspan">FIRST NAME:</td>
   <td class="xl66"></td>
   <td colspan="7" class="xl157"><?php if(isset($student['first_name'])) echo strtoupper(htmlspecialchars($student['first_name'])); else echo '&nbsp;'; ?></td>
   <td class="xl73" colspan="2" style="mso-ignore:colspan">NAME EXTN. (Jr,I,II)</td>
   <td class="xl74" style="border-top:none"><?php if(isset($student['name_extn'])) echo strtoupper(htmlspecialchars($student['name_extn'])); else echo '&nbsp;'; ?></td>
   <td class="xl74" style="border-top:none">&nbsp;</td>
   <td class="xl74" style="border-top:none">&nbsp;</td>
   <td colspan="6" class="xl158">&nbsp;</td>
   <td class="xl69" colspan="6" style="mso-ignore:colspan">MIDDLE NAME:</td>
   <td class="xl66"></td>
   <td colspan="8" class="xl159"><?php if(isset($student['middle_name'])) echo strtoupper(htmlspecialchars($student['middle_name'])); else echo '&nbsp;'; ?></td>
   <td class="xl66"></td>
   <td class="xl75"></td>
   <td class="xl66"></td>
 </tr>
 <tr height=35 style='mso-height-source:userset;height:26.25pt'>
  <td height=35 class=xl66 style='height:26.25pt'></td>
  <td class=xl66>Learner Reference Number (LRN):</td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
  <td class=xl69></td>
   <td colspan=6 class=xl130><?php if(isset($student['lrn'])) echo strtoupper(htmlspecialchars($student['lrn'])); else echo '&nbsp;'; ?></td>
  <td class=xl75>Birthdate (mm/dd/yyyy):</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
   <td colspan=15 class=xl218><?php
      if (isset($student['birthdate']) && $student['birthdate'] !== '') {
         $dt = date_create($student['birthdate']);
         if ($dt) {
            echo htmlspecialchars(date_format($dt, 'm/d/Y'));
         } else {
            echo htmlspecialchars($student['birthdate']);
         }
      } else {
         echo '&nbsp;';
      }
   ?></td>
  <td class=xl76></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl75>Sex:</td>
  <td class=xl66></td>
   <td colspan=5 class=xl219>
         <?php
             $sex_val = '';
             if (isset($student['sex'])) $sex_val = $student['sex'];
             elseif (isset($student['gender'])) $sex_val = $student['gender'];
             elseif (isset($student['SEX'])) $sex_val = $student['SEX'];
             elseif (isset($student['GENDER'])) $sex_val = $student['GENDER'];
             echo $sex_val ? strtoupper(htmlspecialchars($sex_val)) : '&nbsp;';
         ?>
   </td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=3 style='mso-height-source:userset;height:2.25pt'>
  <td height=3 class=xl66 style='height:2.25pt'></td>
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
  <td class=xl77></td>
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
 </tr>
 <tr height=22 style='mso-height-source:userset;height:16.5pt'>
  <td height=22 class=xl66 style='height:16.5pt'></td>
  <td colspan=49 class=xl165 style='border-right:.5pt solid black'>ELIGIBILITY
  FOR ELEMENTARY SCHOOL ENROLLMENT</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=6 style='mso-height-source:userset;height:4.5pt'>
  <td height=6 class=xl66 style='height:4.5pt'></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl116></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl78 colspan=6 style='mso-ignore:colspan'>Credential Presented for
  Grade 1:</td>
  <td class=xl79>&nbsp;</td>
  <td class=xl79>&nbsp;</td>
  <td class=xl79>&nbsp;</td>
  <td align=left valign=top><!--[if gte vml 1]><v:shapetype id="_x0000_t201"
   coordsize="21600,21600" o:spt="201" path="m,l,21600r21600,l21600,xe">
   <v:stroke joinstyle="miter"/>
   <v:path shadowok="f" o:extrusionok="f" strokeok="f" fillok="f"
    o:connecttype="rect"/>
   <o:lock v:ext="edit" shapetype="t"/>
  </v:shapetype><v:shape id="_x0000_s1028" type="#_x0000_t201" style='position:absolute;
   margin-left:3pt;margin-top:.75pt;width:18.75pt;height:19.5pt;z-index:6;
   mso-wrap-style:tight' filled="f" fillcolor="window" stroked="f"
   strokecolor="windowText" o:insetmode="auto">
   <v:path shadowok="t" strokeok="t" fillok="t"/>
   <o:lock v:ext="edit" rotation="t"/>
   <v:textbox style='mso-direction-alt:auto' o:singleclick="f">
    <![if excel]>
    <div></div>
    <![endif]></v:textbox>
   <![if excel]><x:ClientData ObjectType="Checkbox">
    <x:SizeWithCells/>
    <x:AutoFill>False</x:AutoFill>
    <x:AutoLine>False</x:AutoLine>
    <x:TextVAlign>Center</x:TextVAlign>
    <x:NoThreeD/>
   </x:ClientData>
   <![endif]></v:shape><![endif]--><![if !vml]><span style='mso-ignore:vglayout;
  position:absolute;z-index:6;margin-left:4px;margin-top:1px;width:26px;
  height:27px'><![endif]><![if !excel]><img width=26 height=27
  src=image005.png v:shapes="_x0000_s1028" class=shape v:dpi="96"><![endif]><![if !vml]></span><![endif]><span
  style='mso-ignore:vglayout2'>
  <table cellpadding=0 cellspacing=0>
   <tr>
    <td height=27 class=xl79 width=33 style='height:20.25pt;width:25pt'>&nbsp;</td>
   </tr>
  </table>
  </span></td>
  <td class=xl80 colspan=6 style='mso-ignore:colspan'>Kinder Progress Report</td>
  <td class=xl79>&nbsp;</td>
  <td class=xl79>&nbsp;</td>
  <td align=left valign=top><!--[if gte vml 1]><v:shape id="_x0000_s1026"
   type="#_x0000_t201" style='position:absolute;margin-left:33pt;margin-top:.75pt;
   width:28.5pt;height:19.5pt;z-index:4;mso-wrap-style:tight' filled="f"
   fillcolor="window" stroked="f" strokecolor="windowText" o:insetmode="auto">
   <v:path shadowok="t" strokeok="t" fillok="t"/>
   <o:lock v:ext="edit" rotation="t"/>
   <v:textbox style='mso-direction-alt:auto' o:singleclick="f">
    <![if excel]>
    <div></div>
    <![endif]></v:textbox>
   <![if excel]><x:ClientData ObjectType="Checkbox">
    <x:SizeWithCells/>
    <x:AutoFill>False</x:AutoFill>
    <x:AutoLine>False</x:AutoLine>
    <x:TextVAlign>Center</x:TextVAlign>
    <x:NoThreeD/>
   </x:ClientData>
   <![endif]></v:shape><![endif]--><![if !vml]><span style='mso-ignore:vglayout;
  position:absolute;z-index:4;margin-left:44px;margin-top:1px;width:39px;
  height:27px'><![endif]><![if !excel]><img width=39 height=27
  src=image006.png v:shapes="_x0000_s1026" class=shape v:dpi="96"><![endif]><![if !vml]></span><![endif]><span
  style='mso-ignore:vglayout2'>
  <table cellpadding=0 cellspacing=0>
   <tr>
    <td height=27 class=xl79 width=56 style='height:20.25pt;width:42pt'>&nbsp;</td>
   </tr>
  </table>
  </span></td>
  <td class=xl80>&nbsp;</td>
  <td class=xl80 colspan=4 style='mso-ignore:colspan'>ECCD Checklist</td>
  <td class=xl80>&nbsp;</td>
  <td class=xl79>&nbsp;</td>
  <td class=xl79>&nbsp;</td>
  <td class=xl79>&nbsp;</td>
  <td class=xl79>&nbsp;</td>
  <td align=left valign=top><!--[if gte vml 1]><v:shape id="_x0000_s1027"
   type="#_x0000_t201" style='position:absolute;margin-left:6pt;margin-top:0;
   width:22.5pt;height:20.25pt;z-index:5;mso-wrap-style:tight' filled="f"
   fillcolor="window" stroked="f" strokecolor="windowText" o:insetmode="auto">
   <v:path shadowok="t" strokeok="t" fillok="t"/>
   <o:lock v:ext="edit" rotation="t"/>
   <v:textbox style='mso-direction-alt:auto' o:singleclick="f">
    <![if excel]>
    <div></div>
    <![endif]></v:textbox>
   <![if excel]><x:ClientData ObjectType="Checkbox">
    <x:SizeWithCells/>
    <x:AutoFill>False</x:AutoFill>
    <x:AutoLine>False</x:AutoLine>
    <x:TextVAlign>Center</x:TextVAlign>
    <x:NoThreeD/>
   </x:ClientData>
   <![endif]></v:shape><![endif]--><![if !vml]><span style='mso-ignore:vglayout;
  position:absolute;z-index:5;margin-left:8px;margin-top:0px;width:31px;
  height:28px'><![endif]><![if !excel]><img width=31 height=28
  src=image007.png v:shapes="_x0000_s1027" class=shape v:dpi="96"><![endif]><![if !vml]></span><![endif]><span
  style='mso-ignore:vglayout2'>
  <table cellpadding=0 cellspacing=0>
   <tr>
    <td height=27 class=xl79 width=22 style='height:20.25pt;width:17pt'>&nbsp;</td>
   </tr>
  </table>
  </span></td>
  <td class=xl79>&nbsp;</td>
  <td class=xl80>&nbsp;</td>
  <td class=xl80>&nbsp;</td>
  <td class=xl80 colspan=13 style='mso-ignore:colspan'>Kindergarten Certificate
  of Completion</td>
  <td class=xl79>&nbsp;</td>
  <td class=xl79>&nbsp;</td>
  <td class=xl81>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=18 style='mso-height-source:userset;height:13.5pt'>
  <td height=18 class=xl66 style='height:13.5pt'></td>
   <td colspan=4 class=xl228>Name of School:</td>
   <td colspan=10 class=xl160>
      <?php
         if ($student && !empty($student['eligibility_school_name'])) {
            echo strtoupper(htmlspecialchars($student['eligibility_school_name']));
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
   <td class=xl82>&nbsp;</td>
   <td class=xl82>&nbsp;</td>
   <td class=xl82>School ID:</td>
   <td class=xl82></td>
   <td colspan=2 class=xl229> <?php
         if ($student && !empty($student['eligibility_school_id'])) {
            echo strtoupper(htmlspecialchars($student['eligibility_school_id']));
         } else {
            echo '&nbsp;';
         }
      ?></td>
   <td class=xl83 colspan=4 style='mso-ignore:colspan'>Address of School:</td>
   <td colspan=25 class=xl160 style='border-right:.5pt solid black'>
      <?php
         if ($student && !empty($student['eligibility_school_address'])) {
            echo strtoupper(htmlspecialchars($student['eligibility_school_address']));
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=7 style='mso-height-source:userset;height:5.25pt'>
  <td height=7 class=xl66 style='height:5.25pt'></td>
  <td class=xl84></td>
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
  <td class=xl84></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl84></td>
  <td class=xl84></td>
  <td class=xl84></td>
  <td class=xl84></td>
  <td class=xl84></td>
  <td class=xl84></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl84></td>
  <td class=xl84></td>
  <td class=xl84></td>
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
  <td class=xl85></td>
  <td class=xl85></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=21 style='mso-height-source:userset;height:15.75pt'>
  <td height=21 class=xl66 style='height:15.75pt'></td>
  <td class=xl66 colspan=5 style='mso-ignore:colspan'>Other Credential
  Presented</td>
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
  <td class=xl66></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td colspan=18 class=xl117></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td align=left valign=top><!--[if gte vml 1]><v:shape id="_x0000_s1029"
   type="#_x0000_t201" style='position:absolute;margin-left:30.75pt;
   margin-top:3pt;width:26.25pt;height:20.25pt;z-index:7;mso-wrap-style:tight'
   filled="f" fillcolor="window" stroked="f" strokecolor="windowText"
   o:insetmode="auto">
   <v:path shadowok="t" strokeok="t" fillok="t"/>
   <o:lock v:ext="edit" rotation="t"/>
   <v:textbox style='mso-direction-alt:auto' o:singleclick="f">
    <![if excel]>
    <div></div>
    <![endif]></v:textbox>
   <![if excel]><x:ClientData ObjectType="Checkbox">
    <x:SizeWithCells/>
    <x:AutoFill>False</x:AutoFill>
    <x:AutoLine>False</x:AutoLine>
    <x:TextVAlign>Center</x:TextVAlign>
    <x:NoThreeD/>
   </x:ClientData>
   <![endif]></v:shape><![endif]--><![if !vml]><span style='mso-ignore:vglayout;
  position:absolute;z-index:7;margin-left:41px;margin-top:4px;width:36px;
  height:28px'><![endif]><![if !excel]><img width=36 height=28
  src=image008.png v:shapes="_x0000_s1029" class=shape v:dpi="96"><![endif]><![if !vml]></span><![endif]><span
  style='mso-ignore:vglayout2'>
  <table cellpadding=0 cellspacing=0>
   <tr>
    <td height=27 class=xl69 width=41 style='height:20.25pt;width:31pt'></td>
   </tr>
  </table>
  </span></td>
   <td colspan=6 class=xl117>PEPT PasserRating:</td>
   <td class=xl117>   
   </td>
   <td colspan=2 class=xl164><?php
         if ($student && !empty($student['pept_rating'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($student['pept_rating'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
   <td colspan=11 class=xl117>Date of Examination/Assessment (mm/dd/yyyy):</td>
   <td colspan=6 class=xl94>
      <?php
         if ($student && !empty($student['pept_exam_date'])) {
            echo '<strong>' . date('m/d/Y', strtotime($student['pept_exam_date'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td align=left valign=top><!--[if gte vml 1]><v:shape id="_x0000_s1025"
   type="#_x0000_t201" style='position:absolute;margin-left:9pt;margin-top:3pt;
   width:24.75pt;height:20.25pt;z-index:3;mso-wrap-style:tight' filled="f"
   fillcolor="window" stroked="f" strokecolor="windowText" o:insetmode="auto">
   <v:path shadowok="t" strokeok="t" fillok="t"/>
   <o:lock v:ext="edit" rotation="t"/>
   <v:textbox style='mso-direction-alt:auto' o:singleclick="f">
    <![if excel]>
    <div></div>
    <![endif]></v:textbox>
   <![if excel]><x:ClientData ObjectType="Checkbox">
    <x:SizeWithCells/>
    <x:AutoFill>False</x:AutoFill>
    <x:AutoLine>False</x:AutoLine>
    <x:TextVAlign>Center</x:TextVAlign>
    <x:NoThreeD/>
   </x:ClientData>
   <![endif]></v:shape><![endif]--><![if !vml]><span style='mso-ignore:vglayout;
  position:absolute;z-index:3;margin-left:12px;margin-top:4px;width:34px;
  height:28px'><![endif]><![if !excel]><img width=34 height=28
  src=image009.png v:shapes="_x0000_s1025" class=shape v:dpi="96"><![endif]><![if !vml]></span><![endif]><span
  style='mso-ignore:vglayout2'>
  <table cellpadding=0 cellspacing=0>
   <tr>
    <td height=27 class=xl66 width=31 style='height:20.25pt;width:23pt'></td>
   </tr>
  </table>
  </span></td>
  <td class=xl66></td>
   <td colspan=12 class=xl87>Others (Pls. Specify):</td>
   <td colspan=8 class=xl164>
      <?php
         if ($student && !empty($student['credential_other_details'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($student['credential_other_details'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl67></td>
   <td colspan=9 class=xl87>Name and Address of Testing Center:</td>
   <td colspan=17 class=xl94 style="text-align:left;">
      <?php
         if ($student && !empty($student['pept_testing_center'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($student['pept_testing_center'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66></td>
  <td class=xl87 colspan=5 style='mso-ignore:colspan'>Remarks:</td>
  <td class=xl86></td>
  <td colspan=15 class=xl164><?php
     $pept_remark = $student['pept_remark'] ?? $student['pept_result'] ?? $student['pept_remarks'] ?? null;
     $elig_remark = $student['eligibility_remark'] ?? $student['remarks'] ?? $student['remark'] ?? null;
     $remark = $pept_remark ?: $elig_remark;
     if (!empty($remark)) {
        echo '<strong>' . strtoupper(htmlspecialchars($remark)) . '</strong>';
     } else {
        echo '&nbsp;';
     }
  ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=3 style='mso-height-source:userset;height:2.25pt'>
  <td height=3 class=xl66 style='height:2.25pt'></td>
  <td class=xl67></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl69></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl67></td>
  <td class=xl69></td>
  <td class=xl66></td>
  <td class=xl69></td>
  <td class=xl66></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td class=xl86></td>
  <td class=xl86></td>
  <td class=xl86></td>
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
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=20 style='mso-height-source:userset;height:15.0pt'>
  <td height=20 class=xl66 style='height:15.0pt'></td>
  <td colspan=49 class=xl232 style='border-right:.5pt solid black'>SCHOLASTIC
  RECORD</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
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
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <?php $grade1_info = getSchoolInfo($conn, $grade1_school); ?>
  <?php $grade2_info = getSchoolInfo($conn, $grade2_school); ?>
  <td colspan=2 class=xl235>School:</td>
     <td colspan=11 class=xl154>
         <?php
            if (!empty($grade1_info['school_name'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade1_info['school_name'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
         ?>
      </td>
   <td colspan=4 class=xl201>School ID:</td>
   <td colspan=2 class=xl154 style='border-right:1.0pt solid black'> <?php
         if (!empty($grade1_info['school_id'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade1_info['school_id'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66></td>
  <td class=xl89 colspan=2>School:</td>
   <td colspan=20 class=xl154> 
   <?php
         if (!empty($grade2_info['school_name'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade2_info['school_name'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
      </td>
  <td colspan=5 class=xl155>School ID:</td>
  <td colspan=2 class=xl154 style='border-right:1.0pt solid black'> <?php
         if (!empty($grade2_info['school_id'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade2_info['school_id'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
   <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
   <td colspan=3 class=xl123>
      <?php
         if (!empty($grade1_info['district'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade1_info['district'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66 colspan=2 style='mso-ignore:colspan'>Division:</td>
  <td colspan=9 class=xl94><?php
         if (!empty($grade1_info['division'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade1_info['division'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
      </td>
  <td colspan=2 class=xl76>Region:</td>
  <td class=xl92><?php
         if (!empty($grade1_info['region'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade1_info['region'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
   <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
   <td colspan=3 class=xl123>
      <?php
         if (!empty($grade2_info['district'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade2_info['district'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66 colspan=3 style='mso-ignore:colspan'>Division:</td>
  <td colspan=17 class=xl94><?php
         if (!empty($grade2_info['division'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade2_info['division'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?>
      </td>
  <td colspan=3 class=xl76>Region:</td>
  <td class=xl93 style='border-top:none'><?php
         if (!empty($grade2_info['region'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade2_info['region'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl91 colspan=4 style='mso-ignore:colspan'>Classified as Grade:</td>
  <td class=xl94><?php
         if (isset($grade1_school) && !empty($grade1_school['grade_level'])) {
            $roman = grade_label_to_roman($grade1_school['grade_level']);
            echo '<strong>' . htmlspecialchars($roman) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl95 colspan=2 style='mso-ignore:colspan'>Section:</td>
  <td class=xl66></td>
  <td colspan=5 class=xl94><?php
         if (isset($grade1_school) && !empty($grade1_school['section'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade1_school['section'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=4 class=xl75>School Year:</td>
  <td colspan=2 class=xl94 style='border-right:1.0pt solid black'><?php
         if (isset($grade1_school) && !empty($grade1_school['school_year'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade1_school['school_year'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
  <td colspan=4 class=xl96>Classified as Grade:</td>
  <td colspan=2 class=xl94><?php
         if (isset($grade2_school) && !empty($grade2_school['grade_level'])) {
            $roman2 = grade_label_to_roman($grade2_school['grade_level']);
            echo '<strong>' . htmlspecialchars($roman2) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=3 class=xl76>Section:</td>
  <td colspan=10 class=xl94><?php
         if (isset($grade2_school) && !empty($grade2_school['section'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade2_school['section'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=6 class=xl76>School Year:</td>
  <td colspan=4 class=xl94 style='border-right:1.0pt solid black'><?php
         if (isset($grade2_school) && !empty($grade2_school['school_year'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade2_school['school_year'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=26 style='mso-height-source:userset;height:19.5pt'>
  <td height=26 class=xl66 style='height:19.5pt'></td>
  <td colspan=6 class=xl96>Name of Adviser/Teacher:</td>
  <td colspan=7 class=xl94><?php
         $adv_full = getAdviserFullName($conn, $grade1_school);
         if (!empty($adv_full)) {
            echo '<strong>' . strtoupper(htmlspecialchars($adv_full)) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=3 class=xl76>Signature:</td>
  <td colspan=3 class=xl94 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl96 colspan=5 style='mso-ignore:colspan'>Name of Adviser/Teacher:</td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td colspan=13 class=xl94><?php
         $adv_full2 = getAdviserFullName($conn, $grade2_school);
         if (!empty($adv_full2)) {
            echo '<strong>' . strtoupper(htmlspecialchars($adv_full2)) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=5 class=xl76>Signature:</td>
  <td colspan=4 class=xl123 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
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
 </tr>
 </tr>
<?php
// Compute printable General Average final rating for Grade 1 and Grade 2
$ga_skip_ids = [9,10,11,12];
// Helper: returns true only when all 4 quarters are non-empty for a grade entry
$allQFilled = function($g) {
   return isset($g['q1'],$g['q2'],$g['q3'],$g['q4'])
       && $g['q1'] !== '' && $g['q1'] !== null
       && $g['q2'] !== '' && $g['q2'] !== null
       && $g['q3'] !== '' && $g['q3'] !== null
       && $g['q4'] !== '' && $g['q4'] !== null;
};

$ga1_finals = [];
foreach($grades_grade1 as $sid => $g) {
   if (in_array(intval($sid), $ga_skip_ids)) continue;
   if ($allQFilled($g) && (!empty($g['final_rating']) || $g['final_rating'] === '0')) {
      $ga1_finals[] = round(floatval($g['final_rating']));
   }
}
$ga1_final = (!empty($ga1_finals)) ? round(array_sum($ga1_finals)/count($ga1_finals)) : null;

$ga2_finals = [];
foreach($grades_grade2 as $sid => $g) {
   if (in_array(intval($sid), $ga_skip_ids)) continue;
   if ($allQFilled($g) && (!empty($g['final_rating']) || $g['final_rating'] === '0')) {
      $ga2_finals[] = round(floatval($g['final_rating']));
   }
}
$ga2_final = (!empty($ga2_finals)) ? round(array_sum($ga2_finals)/count($ga2_finals)) : null;

// Compute for Grade 3 and Grade 4 as well (used later in the sheet)
$ga3_finals = [];
foreach($grades_grade3 as $sid => $g) {
   if (in_array(intval($sid), $ga_skip_ids)) continue;
   if ($allQFilled($g) && (!empty($g['final_rating']) || $g['final_rating'] === '0')) {
      $ga3_finals[] = round(floatval($g['final_rating']));
   }
}
$ga3_final = (!empty($ga3_finals)) ? round(array_sum($ga3_finals)/count($ga3_finals)) : null;

$ga4_finals = [];
foreach($grades_grade4 as $sid => $g) {
   if (in_array(intval($sid), $ga_skip_ids)) continue;
   if ($allQFilled($g) && (!empty($g['final_rating']) || $g['final_rating'] === '0')) {
      $ga4_finals[] = round(floatval($g['final_rating']));
   }
}
$ga4_final = (!empty($ga4_finals)) ? round(array_sum($ga4_finals)/count($ga4_finals)) : null;
?>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
    <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 1; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Mother Tongue';
        }
    ?>
  </td>
   <?php $row = $grades_grade1[1] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
    <?php
        // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 1; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'Mother Tongue';
        }
    ?>
</td>
   <?php $row2 = $grades_grade2[1] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 2; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Filipino';
        }
    ?>
</td>
    <?php $row = $grades_grade1[2] ?? null; ?>
    <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
    <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
    <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
    <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
    <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
    <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
   <td class=xl66></td>
   <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 2; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'Filipino';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[2] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 3; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'English';
        }
    ?>
  </td>
  <?php $row = $grades_grade1[3] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 3; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'English';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[3] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 4; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Mathematics';
        }
    ?>
  </td>
  <?php $row = $grades_grade1[4] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 4; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'Mathematics';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[4] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 5; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Science';
        }
    ?>
  </td>
  <?php $row = $grades_grade1[5] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 5; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'Science';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[5] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 6; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Araling Panlipunan';
        }
    ?>
  </td>
  <?php $row = $grades_grade1[6] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 6; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'Araling Panlipunan';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[6] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 7; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'EPP / TLE';
        }
    ?>
  </td>
  <?php $row = $grades_grade1[7] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 7; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'EPP / TLE';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[7] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 8; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'MAPEH';
        }
    ?>
  </td>
  <?php $row = $grades_grade1[8] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 8; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'MAPEH';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[8] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 9; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Music';
        }
    ?>
  </td>
 <?php $row = $grades_grade1[9] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 9; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'Music';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[9] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
 <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 10; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Arts';
        }
    ?>
  </td>
  <?php $row = $grades_grade1[10] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 10; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'Arts';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[10] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 11; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Physical Education';
        }
    ?>
    </td>
  <?php $row = $grades_grade1[11] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 11; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'Physical Education';
        }
    ?>
    </td>
  <?php $row2 = $grades_grade2[11] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 12; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Health';
        }
    ?>
    </td>
  <?php $row = $grades_grade1[12] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 12; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'Health';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[12] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 13; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Eduk. sa Pagpapakatao';
        }
    ?>
  </td>
  <?php $row = $grades_grade1[13] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 13; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo 'Eduk. sa Pagpapakatao';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[13] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl173 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 14; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo '*Arabic Language';
        }
    ?>
    </td>
  <?php $row = $grades_grade1[14] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl173 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 14; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo '*Arabic Language';
        }
    ?>
    </td>
  <?php $row2 = $grades_grade2[14] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl173 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade1_school) && $grade1_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 15; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo '*Islamic Values Education';
        }
    ?>
  </td>
  <?php $row = $grades_grade1[15] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q1']) && $row['q1'] !== '') ? '<strong>' . round($row['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q2']) && $row['q2'] !== '') ? '<strong>' . round($row['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q3']) && $row['q3'] !== '') ? '<strong>' . round($row['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['q4']) && $row['q4'] !== '') ? '<strong>' . round($row['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['final_rating']) && $row['final_rating'] !== '') ? '<strong>' . round($row['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row && isset($row['remarks']) && $row['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl173 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade2_school) && $grade2_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 15; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade2_school['id']));
        } else {
           echo '*Islamic Values Education';
        }
    ?>
  </td>
  <?php $row2 = $grades_grade2[15] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q1']) && $row2['q1'] !== '') ? '<strong>' . round($row2['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;border-left:none;width:32pt;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q2']) && $row2['q2'] !== '') ? '<strong>' . round($row2['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q3']) && $row2['q3'] !== '') ? '<strong>' . round($row2['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['q4']) && $row2['q4'] !== '') ? '<strong>' . round($row2['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['final_rating']) && $row2['final_rating'] !== '') ? '<strong>' . round($row2['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row2 && isset($row2['remarks']) && $row2['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row2['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
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
   <td colspan=3 class=xl139 style='border-right:.5pt solid black;border-left:
   none'><?php echo ($ga1_final !== null) ? '<strong>' . $ga1_final . '%</strong>' : '&nbsp;'; ?></td>
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
  none'><?php echo ($ga2_final !== null) ? '<strong>' . $ga2_final . '%</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl207 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
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
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=5 class=xl145 style='border-right:.5pt solid black'>Remedial
  Classes</td>
   <td colspan="4" class="xl142" style="border-left:
   none">Conducted from:</td>
   <td colspan="4" class="xl142" style="border-left:none; vertical-align:middle; text-align:center">
   <?php
      $r_head = $remedial_grade1[0] ?? null;
      if ($r_head && !empty($r_head['conducted_from'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r_head['conducted_from'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
   <td colspan="1" class=xl142 style='border-left:
   none'>&nbsp;&nbsp;to:</td>
   <td colspan="5" class="xl142" style="border-right:1.0pt solid black; border-left:none; vertical-align:middle; text-align:center">
   <?php
      if ($r_head && !empty($r_head['conducted_to'])) {
         echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r_head['conducted_to'])) . '</span>';
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
      $r2_head = $remedial_grade2[0] ?? null;
      if ($r2_head && !empty($r2_head['conducted_from'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r2_head['conducted_from'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
   <td colspan=2 class=xl142 style='border-left:
   none'>&nbsp;&nbsp;to:</td>
   <td colspan=5 class=xl142 style='border-right:1.0pt solid black; border-left:none; vertical-align:middle; text-align:center'>
   <?php
      if ($r2_head && !empty($r2_head['conducted_to'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r2_head['conducted_to'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
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
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
   <?php $r = $remedial_grade1[0] ?? null; ?>
   <td colspan=5 class=xl203 style='border-right:.5pt solid black'><?= $r && !empty($r['learning_area']) ? htmlspecialchars($r['learning_area']) : '&nbsp;' ?></td>
   <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:none'><?= $r && isset($r['final_rating']) && $r['final_rating'] !== '' ? round($r['final_rating']) : '&nbsp;' ?></td>
   <td colspan=4 class=xl132 style='border-left:none'><?= $r && isset($r['remedial_class_mark']) && $r['remedial_class_mark'] !== '' ? round($r['remedial_class_mark']) : '&nbsp;' ?></td>
   <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:none'><?= $r && isset($r['recomputed_final_grade']) && $r['recomputed_final_grade'] !== '' ? round($r['recomputed_final_grade']) : '&nbsp;' ?></td>
   <td colspan=2 class=xl181 style='border-right:1.0pt solid black;border-left:none'><?= $r && !empty($r['remarks']) ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($r['remarks']) . '</span>' : '&nbsp;' ?></td>
  <td class=xl66></td>
   <?php $r2 = $remedial_grade2[0] ?? null; ?>
   <td colspan=5 class=xl131><?= $r2 && !empty($r2['learning_area']) ? htmlspecialchars($r2['learning_area']) : '&nbsp;' ?></td>
   <td colspan=9 class=xl132 style='border-left:none'><?= $r2 && isset($r2['final_rating']) && $r2['final_rating'] !== '' ? round($r2['final_rating']) : '&nbsp;' ?></td>
   <td colspan=7 class=xl132 style='border-left:none'><?= $r2 && isset($r2['remedial_class_mark']) && $r2['remedial_class_mark'] !== '' ? round($r2['remedial_class_mark']) : '&nbsp;' ?></td>
   <td colspan=6 class=xl132 style='border-left:none'><?= $r2 && isset($r2['recomputed_final_grade']) && $r2['recomputed_final_grade'] !== '' ? round($r2['recomputed_final_grade']) : '&nbsp;' ?></td>
   <td colspan=2 class=xl132 style='border-right:1.0pt solid black;border-left:none'><?= $r2 && !empty($r2['remarks']) ? '<span style="font-size:1.05em;font-weight:700">' . format_remark_sheet($r2['remarks']) . '</span>' : '&nbsp;' ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <?php $r_second = $remedial_grade1[1] ?? null; ?>
  <td colspan=5 class=xl203 style='border-right:.5pt solid black'><?= $r_second && !empty($r_second['learning_area']) ? htmlspecialchars($r_second['learning_area']) : '&nbsp;' ?></td>
  <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:none'><?= $r_second && isset($r_second['final_rating']) && $r_second['final_rating'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r_second['final_rating']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=4 class=xl132 style='border-left:none'><?= $r_second && isset($r_second['remedial_class_mark']) && $r_second['remedial_class_mark'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r_second['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:none'><?= $r_second && isset($r_second['recomputed_final_grade']) && $r_second['recomputed_final_grade'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r_second['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl181 style='border-right:1.0pt solid black;border-left:none'><?= $r_second && !empty($r_second['remarks']) ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($r_second['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <?php $r2_second = $remedial_grade2[1] ?? null; ?>
  <td class=xl66></td>
  <td colspan=5 class=xl131><?= $r2_second && !empty($r2_second['learning_area']) ? htmlspecialchars($r2_second['learning_area']) : '&nbsp;' ?></td>
  <td colspan=9 class=xl132 style='border-left:none'><?= $r2_second && isset($r2_second['final_rating']) && $r2_second['final_rating'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r2_second['final_rating']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=7 class=xl132 style='border-left:none'><?= $r2_second && isset($r2_second['remedial_class_mark']) && $r2_second['remedial_class_mark'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r2_second['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=6 class=xl132 style='border-left:none'><?= $r2_second && isset($r2_second['recomputed_final_grade']) && $r2_second['recomputed_final_grade'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r2_second['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl132 style='border-right:1.0pt solid black;border-left:none'><?= $r2_second && !empty($r2_second['remarks']) ? '<span style="font-size:1.05em;font-weight:700">' . format_remark_sheet($r2_second['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
  </tr>
 <tr height=16 style='mso-height-source:userset;height:12.0pt'>
  <td height=16 class=xl66 style='height:12.0pt'></td>
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
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <?php $grade3_info = getSchoolInfo($conn, $grade3_school); ?>
  <?php $grade4_info = getSchoolInfo($conn, $grade4_school); ?>
  <td colspan=2 class=xl235>School:</td>
  <td colspan=11 class=xl154><?php
            if (!empty($grade3_info['school_name'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade3_info['school_name'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
         ?></td>
  <td colspan=4 class=xl201>School ID:</td>
  <td colspan=2 class=xl154 style='border-right:1.0pt solid black'> <?php
         if (!empty($grade3_info['school_id'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade3_info['school_id'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
  <td class=xl89 colspan=2 style='mso-ignore:colspan'>School:</td>
  <td colspan=20 class=xl154><?php
            if (!empty($grade4_info['school_name'])) {
               echo '<strong>' . strtoupper(htmlspecialchars($grade4_info['school_name'])) . '</strong>';
            } else {
               echo '&nbsp;';
            }
         ?></td>
  <td colspan=5 class=xl155>School ID:</td>
  <td colspan=2 class=xl154 style='border-right:1.0pt solid black'> <?php
         if (!empty($grade4_info['school_id'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade4_info['school_id'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
  <td colspan=3 class=xl123><?php
         if (!empty($grade3_info['district'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade3_info['district'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66 colspan=2 style='mso-ignore:colspan'>Division:</td>
  <td colspan=9 class=xl94><?php
         if (!empty($grade3_info['division'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade3_info['division'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=2 class=xl76>Region:</td>
  <td class=xl92><?php
         if (!empty($grade3_info['region'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade3_info['region'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
  <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
  <td colspan=3 class=xl123><?php
         if (!empty($grade4_info['district'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade4_info['district'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66 colspan=3 style='mso-ignore:colspan'>Division:</td>
  <td colspan=17 class=xl94><?php
         if (!empty($grade4_info['division'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade4_info['division'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=3 class=xl76>Region:</td>
  <td class=xl93 style='border-top:none'><?php
         if (!empty($grade4_info['region'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade4_info['region'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl91 colspan=4 style='mso-ignore:colspan'>Classified as Grade:</td>
  <td class=xl94><?php
         if (isset($grade3_school) && !empty($grade3_school['grade_level'])) {
            $roman3 = grade_label_to_roman($grade3_school['grade_level']);
            echo '<strong>' . htmlspecialchars($roman3) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl95 colspan=2 style='mso-ignore:colspan'>Section:</td>
  <td class=xl66></td>
  <td colspan=5 class=xl94><?php
         if (isset($grade3_school) && !empty($grade3_school['section'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade3_school['section'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=4 class=xl75>School Year:</td>
  <td colspan=2 class=xl94 style='border-right:1.0pt solid black'><?php
         if (isset($grade3_school) && !empty($grade3_school['school_year'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade3_school['school_year'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
  <td colspan=4 class=xl96>Classified as Grade:</td>
  <td colspan=2 class=xl94><?php
         if (isset($grade4_school) && !empty($grade4_school['grade_level'])) {
            $roman4 = grade_label_to_roman($grade4_school['grade_level']);
            echo '<strong>' . htmlspecialchars($roman4) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=3 class=xl76>Section:</td>
  <td colspan=10 class=xl94><?php
         if (isset($grade4_school) && !empty($grade4_school['section'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade4_school['section'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=6 class=xl76>School Year:</td>
  <td colspan=4 class=xl94 style='border-right:1.0pt solid black'><?php
         if (isset($grade4_school) && !empty($grade4_school['school_year'])) {
            echo '<strong>' . strtoupper(htmlspecialchars($grade4_school['school_year'])) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td colspan=6 class=xl96>Name of Adviser/Teacher:</td>
  <td colspan=7 class=xl94><?php
         $adv_full3 = getAdviserFullName($conn, $grade3_school);
         if (!empty($adv_full3)) {
            echo '<strong>' . strtoupper(htmlspecialchars($adv_full3)) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=3 class=xl76>Signature:</td>
  <td colspan=3 class=xl94 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl96 colspan=5 style='mso-ignore:colspan'>Name of Adviser/Teacher:</td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td colspan=13 class=xl94><?php
         $adv_full4 = getAdviserFullName($conn, $grade4_school);
         if (!empty($adv_full4)) {
            echo '<strong>' . strtoupper(htmlspecialchars($adv_full4)) . '</strong>';
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=5 class=xl76>Signature:</td>
  <td colspan=4 class=xl123 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
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
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
        // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 1; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Mother Tongue';
        }
    ?>
    </td>
   <?php $row3 = $grades_grade3[1] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
        // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 1; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'Mother Tongue';
        }
    ?>
    </td>
  <?php $row4 = $grades_grade4[1] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 2; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Filipino';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[2] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 2; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'Filipino';
        }
  ?>
  </td>
  <?php $row4 = $grades_grade4[2] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 3; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'English';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[3] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>

  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 3; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'English';
        }
  ?>
  </td>
  <?php $row4 = $grades_grade4[3] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 4; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Mathematics';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[4] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 4; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'Mathematics';
        }
    ?>
  </td>
  <?php $row4 = $grades_grade4[4] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 5; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Science';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[5] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 5; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'Science';
        }
    ?>
  </td>
  <?php $row4 = $grades_grade4[5] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 6; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Araling Panlipunan';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[6] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 6; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'Araling Panlipunan';
        }
    ?>
  </td>
  <?php $row4 = $grades_grade4[6] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 7; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'EPP / TLE';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[7] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 7; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'EPP / TLE';
        }
    ?>
  </td>
  <?php $row4 = $grades_grade4[7] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 8; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'MAPEH';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[8] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 8; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'MAPEH';
        }
    ?>
  </td>
  <?php $row4 = $grades_grade4[8] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 9; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Music';
        }
    ?>
    </td>
  <?php $row3 = $grades_grade3[9] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 9; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'Music';
        }
    ?>
  </td>
  <?php $row4 = $grades_grade4[9] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 10; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Arts';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[10] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 10; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'Arts';
        }
    ?>
  </td>
  <?php $row4 = $grades_grade4[10] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 11; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Physical Education';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[11] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 11; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'Physical Education';
        }
    ?>
  </td>
 <?php $row4 = $grades_grade4[11] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 12; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Health';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[12] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=14 class=xl178 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 12; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'Health';
        }
    ?>
  </td>
  <?php $row4 = $grades_grade4[12] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 13; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Eduk. sa Pagpapakatao';
        }
    ?>
    </td>
  <?php $row3 = $grades_grade3[13] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl168 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 13; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo 'Eduk. sa Pagpapakatao';
        }
    ?>
  </td>
  <?php $row4 = $grades_grade4[13] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl173 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 14; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo '*Arabic Language';
        }
    ?>
    </td>
  <?php $row3 = $grades_grade3[14] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl173 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 14; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo '*Arabic Language';
        }
    ?>
    </td>
  <?php $row4 = $grades_grade4[14] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=9 class=xl173 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade3_school) && $grade3_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 15; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo '*Islamic Values Education';
        }
    ?>
  </td>
  <?php $row3 = $grades_grade3[15] ?? null; ?>
   <td class=xl118 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q1']) && $row3['q1'] !== '') ? '<strong>' . round($row3['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q2']) && $row3['q2'] !== '') ? '<strong>' . round($row3['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q3']) && $row3['q3'] !== '') ? '<strong>' . round($row3['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td class=xl119 style='border-top:none;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['q4']) && $row3['q4'] !== '') ? '<strong>' . round($row3['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['final_rating']) && $row3['final_rating'] !== '') ? '<strong>' . round($row3['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row3 && isset($row3['remarks']) && $row3['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row3['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td colspan=14 class=xl173 style='border-right:.5pt solid black'>
  <?php
      // Use the same logic as the preview for subject name mapping
        if (isset($grade4_school) && $grade4_school) {
           // Set the subject_id for this cell (replace 1 with the correct subject id for this cell)
           $subject_id = 15; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade4_school['id']));
        } else {
           echo '*Islamic Values Education';
        }
    ?>
  </td>
  <?php $row4 = $grades_grade4[15] ?? null; ?>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q1']) && $row4['q1'] !== '') ? '<strong>' . round($row4['q1']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q2']) && $row4['q2'] !== '') ? '<strong>' . round($row4['q2']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q3']) && $row4['q3'] !== '') ? '<strong>' . round($row4['q3']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['q4']) && $row4['q4'] !== '') ? '<strong>' . round($row4['q4']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['final_rating']) && $row4['final_rating'] !== '') ? '<strong>' . round($row4['final_rating']) . '</strong>' : '&nbsp;'; ?></td>
   <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:none;text-align:center;vertical-align:middle'><?php echo ($row4 && isset($row4['remarks']) && $row4['remarks'] !== '') ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($row4['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
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
  none'><?php echo ($ga3_final !== null) ? '<strong>' . $ga3_final . '%</strong>' : '&nbsp;'; ?></td>
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
  none'><?php echo ($ga4_final !== null) ? '<strong>' . $ga4_final . '%</strong>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl207 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=10 style='mso-height-source:userset;height:7.5pt'>
  <td height=10 class=xl66 style='height:7.5pt'></td>
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
 </tr>
 <tr height=21 style='mso-height-source:userset;height:15.75pt'>
  <td height=21 class=xl66 style='height:15.75pt'></td>
  <td colspan=5 class=xl145 style='border-right:.5pt solid black'>Remedial
  Classes</td>
  <td colspan="4" class="xl142" style="border-left:
   none">Conducted from:</td>
   <td colspan="4" class="xl142" style="border-left:none; vertical-align:middle; text-align:center">
   <?php
      $r3_head = $remedial_grade3[0] ?? null;
      if ($r3_head && !empty($r3_head['conducted_from'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r3_head['conducted_from'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
   <td colspan="1" class=xl142 style='border-left:
   none'>&nbsp;&nbsp;to:</td>
   <td colspan="5" class="xl142" style="border-right:1.0pt solid black; border-left:none; vertical-align:middle; text-align:center">
   <?php
      if ($r3_head && !empty($r3_head['conducted_to'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r3_head['conducted_to'])) . '</span>';
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
      $r4_head = $remedial_grade4[0] ?? null;
      if ($r4_head && !empty($r4_head['conducted_from'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r4_head['conducted_from'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
   <td colspan=2 class=xl142 style='border-left:
   none'>&nbsp;&nbsp;to:</td>
   <td colspan=5 class=xl142 style='border-right:1.0pt solid black; border-left:none; vertical-align:middle; text-align:center'>
   <?php
      if ($r4_head && !empty($r4_head['conducted_to'])) {
          echo '<span style="font-size:1.05em;font-weight:700">' . date('m/d/Y', strtotime($r4_head['conducted_to'])) . '</span>';
      } else {
          echo '&nbsp;';
      }
   ?>
</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
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
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
   <?php $r3 = $remedial_grade3[0] ?? null; ?>
   <td colspan=5 class=xl203 style='border-right:.5pt solid black'><?= $r3 && !empty($r3['learning_area']) ? htmlspecialchars($r3['learning_area']) : '&nbsp;' ?></td>
   <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:none'><?= $r3 && isset($r3['final_rating']) && $r3['final_rating'] !== '' ? round($r3['final_rating']) : '&nbsp;' ?></td>
   <td colspan=4 class=xl132 style='border-left:none'><?= $r3 && isset($r3['remedial_class_mark']) && $r3['remedial_class_mark'] !== '' ? round($r3['remedial_class_mark']) : '&nbsp;' ?></td>
   <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:none'><?= $r3 && isset($r3['recomputed_final_grade']) && $r3['recomputed_final_grade'] !== '' ? round($r3['recomputed_final_grade']) : '&nbsp;' ?></td>
   <td colspan=2 class=xl181 style='border-right:1.0pt solid black;border-left:none'><?= $r3 && !empty($r3['remarks']) ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($r3['remarks']) . '</span>' : '&nbsp;' ?></td>
  <td class=xl66></td>
   <?php $r4 = $remedial_grade4[0] ?? null; ?>
   <td colspan=5 class=xl131><?= $r4 && !empty($r4['learning_area']) ? htmlspecialchars($r4['learning_area']) : '&nbsp;' ?></td>
   <td colspan=9 class=xl132 style='border-left:none'><?= $r4 && isset($r4['final_rating']) && $r4['final_rating'] !== '' ? round($r4['final_rating']) : '&nbsp;' ?></td>
   <td colspan=7 class=xl132 style='border-left:none'><?= $r4 && isset($r4['remedial_class_mark']) && $r4['remedial_class_mark'] !== '' ? round($r4['remedial_class_mark']) : '&nbsp;' ?></td>
   <td colspan=6 class=xl132 style='border-left:none'><?= $r4 && isset($r4['recomputed_final_grade']) && $r4['recomputed_final_grade'] !== '' ? round($r4['recomputed_final_grade']) : '&nbsp;' ?></td>
   <td colspan=2 class=xl132 style='border-right:1.0pt solid black;border-left:none'><?= $r4 && !empty($r4['remarks']) ? '<span style="font-size:1.05em;font-weight:700">' . format_remark_sheet($r4['remarks']) . '</span>' : '&nbsp;' ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=28 style='mso-height-source:userset;height:21.0pt'>
  <td height=28 class=xl66 style='height:21.0pt'></td>
  <?php $r3_second = $remedial_grade3[1] ?? null; ?>
  <td colspan=5 class=xl203 style='border-right:.5pt solid black'><?= $r3_second && !empty($r3_second['learning_area']) ? htmlspecialchars($r3_second['learning_area']) : '&nbsp;' ?></td>
  <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:none'><?= $r3_second && isset($r3_second['final_rating']) && $r3_second['final_rating'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r3_second['final_rating']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=4 class=xl132 style='border-left:none'><?= $r3_second && isset($r3_second['remedial_class_mark']) && $r3_second['remedial_class_mark'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r3_second['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:none'><?= $r3_second && isset($r3_second['recomputed_final_grade']) && $r3_second['recomputed_final_grade'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r3_second['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl181 style='border-right:1.0pt solid black;border-left:none'><?= $r3_second && !empty($r3_second['remarks']) ? '<span style="font-size:1.2em;font-weight:700">' . format_remark_sheet($r3_second['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <?php $r4_second = $remedial_grade4[1] ?? null; ?>
  <td class=xl66></td>
  <td colspan=5 class=xl131><?= $r4_second && !empty($r4_second['learning_area']) ? htmlspecialchars($r4_second['learning_area']) : '&nbsp;' ?></td>
  <td colspan=9 class=xl132 style='border-left:none'><?= $r4_second && isset($r4_second['final_rating']) && $r4_second['final_rating'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r4_second['final_rating']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=7 class=xl132 style='border-left:none'><?= $r4_second && isset($r4_second['remedial_class_mark']) && $r4_second['remedial_class_mark'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r4_second['remedial_class_mark']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=6 class=xl132 style='border-left:none'><?= $r4_second && isset($r4_second['recomputed_final_grade']) && $r4_second['recomputed_final_grade'] !== '' ? '<span style="font-size:1.0em;font-weight:700">' . round($r4_second['recomputed_final_grade']) . '</span>' : '&nbsp;'; ?></td>
  <td colspan=2 class=xl132 style='border-right:1.0pt solid black;border-left:none'><?= $r4_second && !empty($r4_second['remarks']) ? '<span style="font-size:1.05em;font-weight:700">' . format_remark_sheet($r4_second['remarks']) . '</span>' : '&nbsp;'; ?></td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=19 style='mso-height-source:userset;height:14.45pt'>
  <td height=19 class=xl66 style='height:14.45pt'></td>
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
  <td class=xl69></td>
  <td colspan=29 class=xl122>SFRT
  Revised 2017</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <![if supportMisalignedColumns]>
 <tr height=0 style='display:none'>
  <td width=10 style='width:8pt'></td>
  <td width=41 style='width:31pt'></td>
  <td width=19 style='width:14pt'></td>
  <td width=40 style='width:30pt'></td>
  <td width=37 style='width:28pt'></td>
  <td width=23 style='width:17pt'></td>
  <td width=21 style='width:16pt'></td>
  <td width=41 style='width:31pt'></td>
  <td width=12 style='width:9pt'></td>
  <td width=34 style='width:26pt'></td>
  <td width=33 style='width:25pt'></td>
  <td width=23 style='width:17pt'></td>
  <td width=16 style='width:12pt'></td>
  <td width=33 style='width:25pt'></td>
  <td width=35 style='width:26pt'></td>
  <td width=17 style='width:13pt'></td>
  <td width=17 style='width:13pt'></td>
  <td width=28 style='width:21pt'></td>
  <td width=33 style='width:25pt'></td>
  <td width=56 style='width:42pt'></td>
  <td width=13 style='width:10pt'></td>
  <td width=40 style='width:30pt'></td>
  <td width=19 style='width:14pt'></td>
  <td width=19 style='width:14pt'></td>
  <td width=58 style='width:44pt'></td>
  <td width=21 style='width:16pt'></td>
  <td width=8 style='width:6pt'></td>
  <td width=14 style='width:11pt'></td>
  <td width=31 style='width:23pt'></td>
  <td width=10 style='width:8pt'></td>
  <td width=22 style='width:17pt'></td>
  <td width=10 style='width:8pt'></td>
  <td width=2 style='width:2pt'></td>
  <td width=5 style='width:4pt'></td>
  <td width=6 style='width:5pt'></td>
  <td width=23 style='width:17pt'></td>
  <td width=6 style='width:5pt'></td>
  <td width=12 style='width:9pt'></td>
  <td width=26 style='width:20pt'></td>
  <td width=16 style='width:12pt'></td>
  <td width=12 style='width:9pt'></td>
  <td width=12 style='width:9pt'></td>
  <td width=12 style='width:9pt'></td>
  <td width=24 style='width:18pt'></td>
  <td width=14 style='width:11pt'></td>
  <td width=17 style='width:13pt'></td>
  <td width=24 style='width:18pt'></td>
  <td width=16 style='width:12pt'></td>
  <td width=33 style='width:25pt'></td>
  <td width=52 style='width:39pt'></td>
  <td width=6 style='width:5pt'></td>
  <td width=0></td>
  <td width=0></td>
 </tr>
 <![endif]>
</table>
</div>

</body>

</html>
