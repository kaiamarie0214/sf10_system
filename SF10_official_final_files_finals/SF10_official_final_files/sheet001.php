<?php
// --- SF10 dynamic data fetcher ---
require_once dirname(__DIR__, 1) . '/includes/db.php';

$student = null;
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

   // Helper function for fetching school record for a grade
   function fetch_grade_school($conn, $student_id, $grade_label, $grade_num) {
      $stmt = $conn->prepare('SELECT * FROM schools_attended WHERE student_id = ? AND (grade_level = ? OR grade_level = ?) ORDER BY id ASC LIMIT 1');
      $stmt->bind_param('iss', $student_id, $grade_label, $grade_num);
      $stmt->execute();
      $result = $stmt->get_result();
      return $result->fetch_assoc();
   }

   $grade1_school = fetch_grade_school($conn, $student_id, 'Grade 1', '1');
   $grade2_school = fetch_grade_school($conn, $student_id, 'Grade 2', '2');
   $grade3_school = fetch_grade_school($conn, $student_id, 'Grade 3', '3');
   $grade4_school = fetch_grade_school($conn, $student_id, 'Grade 4', '4');
   $grade5_school = fetch_grade_school($conn, $student_id, 'Grade 5', '5');
   $grade6_school = fetch_grade_school($conn, $student_id, 'Grade 6', '6');

// Function to get subject name for a specific student, matching preview logic
function getSubjectNameForStudent($conn, $subject_id, $student_id, $school_attended_id) {
    // First, determine if this is a transfer student
    $is_transfer = false;
    $grade_level = null;
    
    $school_info = $conn->query("SELECT grade_level, adviser_name FROM schools_attended WHERE id = $school_attended_id");
    if ($school_info && $school_info->num_rows > 0) {
        $school_data = $school_info->fetch_assoc();
        $grade_level = $school_data['grade_level'];
        
        // Auto-detect transfer status based on adviser existence in users table
        if (!empty($school_data['adviser_name'])) {
            $adviser_check = $conn->query("SELECT id FROM users WHERE full_name = '" . $conn->real_escape_string($school_data['adviser_name']) . "'")->num_rows;
            $is_transfer = ($adviser_check == 0); // Transfer if adviser not in system
        } else {
            $is_transfer = true; // No adviser = transfer student
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
            return $custom_result['custom_subject_name'];
        }
    }
    
    // IMPORTANT: Only use grade-level config for regular students (non-transfer)
    // Transfer students should NOT be affected by global subject format changes
    if (!$is_transfer && $grade_level) {
        $table_check = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'");
        
        if ($table_check && $table_check->num_rows > 0) {
            $group_query = $conn->query("SELECT subject_name 
                                         FROM subject_grade_groups 
                                         WHERE grade_level = '$grade_level' 
                                         AND subject_id = $subject_id");
            if ($group_query && $group_query->num_rows > 0) {
                $group_result = $group_query->fetch_assoc();
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
	{margin:.24in 0in .24in 0in;
	mso-header-margin:0in;
	mso-footer-margin:0in;
	mso-horizontal-page-align:center;}
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
<div style="padding:8px 16px;">
   <div class="no-print" style="display:inline-block; background:#0d6efd; color:#fff; border-radius:6px; padding:8px 12px;">
      <a href="../../pages/sf10_preview.php?student_id=<?= isset($student_id) ? intval($student_id) : '' ?>" style="color:inherit; text-decoration:none; font-weight:600;">
         &larr; Back to Preview
      </a>
   </div>
</div>
<style>
@media print { .no-print { display: none !important; } }
</style>

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
   <td colspan=15 class=xl218><?php if(isset($student['birthdate'])) echo strtoupper(htmlspecialchars($student['birthdate'])); else echo '&nbsp;'; ?></td>
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
            echo strtoupper(htmlspecialchars($student['pept_rating']));
         } else {
            echo '&nbsp;';
         }
      ?></td>
   <td colspan=11 class=xl117>Date of Examination/Assessment (mm/dd/yyyy):</td>
   <td colspan=6 class=xl94>
      <?php
         if ($student && !empty($student['pept_exam_date'])) {
            echo date('m/d/Y', strtotime($student['pept_exam_date']));
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
            echo strtoupper(htmlspecialchars($student['credential_other_details']));
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
            echo strtoupper(htmlspecialchars($student['pept_testing_center']));
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66></td>
  <td class=xl87 colspan=5 style='mso-ignore:colspan'>Remark:</td>
  <td class=xl86></td>
  <td colspan=15 class=xl164>&nbsp;</td>
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
  <td colspan=2 class=xl235>School:</td>
     <td colspan=11 class=xl154>
         <?php
            if (isset($grade1_school) && !empty($grade1_school['school_name'])) {
               echo htmlspecialchars($grade1_school['school_name']);
            } else {
               echo '&nbsp;';
            }
         ?>
      </td>
   <td colspan=4 class=xl201>School ID:</td>
   <td colspan=2 class=xl154 style='border-right:1.0pt solid black'> <?php
         if (isset($grade1_school) && !empty($grade1_school['school_id'])) {
            echo htmlspecialchars($grade1_school['school_id']);
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66></td>
  <td class=xl89 colspan=2>School:</td>
   <td colspan=20 class=xl154> 
   <?php
         if (isset($grade2_school) && !empty($grade2_school['school_name'])) {
            echo htmlspecialchars($grade2_school['school_name']);
         } else {
            echo '&nbsp;';
         }
      ?>
      </td>
  <td colspan=5 class=xl155>School ID:</td>
  <td colspan=2 class=xl154 style='border-right:1.0pt solid black'> <?php
         if (isset($grade2_school) && !empty($grade2_school['school_id'])) {
            echo htmlspecialchars($grade2_school['school_id']);
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
         if (isset($grade1_school) && !empty($grade1_school['district'])) {
            echo htmlspecialchars($grade1_school['district']);
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66 colspan=2 style='mso-ignore:colspan'>Division:</td>
  <td colspan=9 class=xl94><?php
         if (isset($grade1_school) && !empty($grade1_school['division'])) {
            echo htmlspecialchars($grade1_school['division']);
         } else {
            echo '&nbsp;';
         }
      ?>
      </td>
  <td colspan=2 class=xl76>Region:</td>
  <td class=xl92><?php
         if (isset($grade1_school) && !empty($grade1_school['region'])) {
            echo htmlspecialchars($grade1_school['region']);
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
   <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
   <td colspan=3 class=xl123>
      <?php
         if (isset($grade2_school) && !empty($grade2_school['district'])) {
            echo htmlspecialchars($grade2_school['district']);
         } else {
            echo '&nbsp;';
         }
      ?>
   </td>
  <td class=xl66 colspan=3 style='mso-ignore:colspan'>Division:</td>
  <td colspan=17 class=xl94><?php
         if (isset($grade2_school) && !empty($grade2_school['division'])) {
            echo htmlspecialchars($grade2_school['division']);
         } else {
            echo '&nbsp;';
         }
      ?>
      </td>
  <td colspan=3 class=xl76>Region:</td>
  <td class=xl93 style='border-top:none'><?php
         if (isset($grade2_school) && !empty($grade2_school['region'])) {
            echo htmlspecialchars($grade2_school['region']);
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
            echo htmlspecialchars($grade1_school['grade_level']);
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl95 colspan=2 style='mso-ignore:colspan'>Section:</td>
  <td class=xl66></td>
  <td colspan=5 class=xl94><?php
         if (isset($grade1_school) && !empty($grade1_school['section'])) {
            echo htmlspecialchars($grade1_school['section']);
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=4 class=xl75>School Year:</td>
  <td colspan=2 class=xl94 style='border-right:1.0pt solid black'><?php
         if (isset($grade1_school) && !empty($grade1_school['school_year'])) {
            echo htmlspecialchars($grade1_school['school_year']);
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td class=xl66></td>
  <td colspan=4 class=xl96>Classified as Grade:</td>
  <td colspan=2 class=xl94><?php
         if (isset($grade2_school) && !empty($grade2_school['grade_level'])) {
            echo htmlspecialchars($grade2_school['grade_level']);
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=3 class=xl76>Section:</td>
  <td colspan=10 class=xl94><?php
         if (isset($grade2_school) && !empty($grade2_school['section'])) {
            echo htmlspecialchars($grade2_school['section']);
         } else {
            echo '&nbsp;';
         }
      ?></td>
  <td colspan=6 class=xl76>School Year:</td>
  <td colspan=4 class=xl94 style='border-right:1.0pt solid black'><?php
         if (isset($grade2_school) && !empty($grade2_school['school_year'])) {
            echo htmlspecialchars($grade2_school['school_year']);
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
         if (isset($grade1_school) && !empty($grade1_school['adviser_name'])) {
            echo htmlspecialchars($grade1_school['adviser_name']);
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
         if (isset($grade2_school) && !empty($grade2_school['adviser_name'])) {
            echo htmlspecialchars($grade2_school['adviser_name']);
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl225 width=42 style='border-right:.5pt solid black;
  border-left:none;width:32pt'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 2; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Filipino';
        }
    ?>
</td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 3; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'English';
        }
    ?>
  </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 4; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Mathematics';
        }
    ?>
  </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 5; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Science';
        }
    ?>
  </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl224 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl105 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl106 style='border-top:none'>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 7; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'EPP / TLE';
        }
    ?>
  </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl105 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl106 style='border-top:none'>&nbsp;</td>
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
  <td class=xl107 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl176 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl108 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl108 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 9; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade1_school['id']));
        } else {
           echo 'Music';
        }
    ?>
  </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl120 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl121 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl121 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl120 style='border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl121 style='border-left:none'>&nbsp;</td>
  <td class=xl121 style='border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl197 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=9 class=xl194 style='border-right:.5pt solid black'>General
  Average</td>
  <td class=xl109 style='border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl186 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl109 style='border-left:none'>&nbsp;</td>
  <td class=xl109 style='border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl139 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  none'>&nbsp;</td>
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
  <td colspan=14 class=xl142 style='border-right:1.0pt solid black;border-left:
  none'>Conducted from:to</td>
  <td class=xl66></td>
  <td colspan=5 class=xl145 style='border-right:.5pt solid black'>Remedial
  Classes</td>
  <td colspan=24 class=xl142 style='border-right:1.0pt solid black;border-left:
  none'>Conducted from:to</td>
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
  <td colspan=5 class=xl203 style='border-right:.5pt solid black'>&nbsp;</td>
  <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=4 class=xl132 style='border-left:none'>&nbsp;</td>
  <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl181 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=5 class=xl131>&nbsp;</td>
  <td colspan=9 class=xl132 style='border-left:none'>&nbsp;</td>
  <td colspan=7 class=xl132 style='border-left:none'>&nbsp;</td>
  <td colspan=6 class=xl132 style='border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl132 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=29 style='mso-height-source:userset;height:21.95pt'>
  <td height=29 class=xl66 style='height:21.95pt'></td>
  <td colspan=5 class=xl204 style='border-right:.5pt solid black'>&nbsp;</td>
  <td colspan=4 class=xl189 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=4 class=xl188 style='border-left:none'>&nbsp;</td>
  <td colspan=4 class=xl189 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl192 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=5 class=xl211>&nbsp;</td>
  <td colspan=9 class=xl188 style='border-left:none'>&nbsp;</td>
  <td colspan=7 class=xl188 style='border-left:none'>&nbsp;</td>
  <td colspan=6 class=xl188 style='border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl188 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=2 class=xl235>School:</td>
  <td colspan=11 class=xl154>&nbsp;</td>
  <td colspan=4 class=xl201>School ID:</td>
  <td colspan=2 class=xl154 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl89 colspan=2 style='mso-ignore:colspan'>School:</td>
  <td colspan=20 class=xl154>&nbsp;</td>
  <td colspan=5 class=xl155>School ID:</td>
  <td colspan=2 class=xl154 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
  <td colspan=3 class=xl123>&nbsp;</td>
  <td class=xl66 colspan=2 style='mso-ignore:colspan'>Division</td>
  <td colspan=9 class=xl94>&nbsp;</td>
  <td colspan=2 class=xl76>Region:</td>
  <td class=xl92>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl91 colspan=2 style='mso-ignore:colspan'>District:</td>
  <td colspan=3 class=xl123>&nbsp;</td>
  <td class=xl66 colspan=3 style='mso-ignore:colspan'>Division:</td>
  <td colspan=17 class=xl94>&nbsp;</td>
  <td colspan=3 class=xl76>Region:</td>
  <td class=xl93 style='border-top:none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td class=xl91 colspan=4 style='mso-ignore:colspan'>Classified as Grade:</td>
  <td class=xl94>&nbsp;</td>
  <td class=xl95 colspan=2 style='mso-ignore:colspan'>Section:</td>
  <td class=xl66></td>
  <td colspan=5 class=xl94>&nbsp;</td>
  <td colspan=4 class=xl75>School Year:</td>
  <td colspan=2 class=xl94 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=4 class=xl96>Classified as Grade:</td>
  <td colspan=2 class=xl94>&nbsp;</td>
  <td colspan=3 class=xl76>Section:</td>
  <td colspan=10 class=xl94>&nbsp;</td>
  <td colspan=6 class=xl76>School Year:</td>
  <td colspan=4 class=xl94 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=27 style='mso-height-source:userset;height:20.25pt'>
  <td height=27 class=xl66 style='height:20.25pt'></td>
  <td colspan=6 class=xl96>Name of Adviser/Teacher:</td>
  <td colspan=7 class=xl94>&nbsp;</td>
  <td colspan=3 class=xl76>Signature:</td>
  <td colspan=3 class=xl94 style='border-right:1.0pt solid black'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl96 colspan=5 style='mso-ignore:colspan'>Name of Adviser/Teacher:</td>
  <td class=xl75></td>
  <td class=xl75></td>
  <td colspan=13 class=xl94>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 2; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Filipino';
        }
    ?>
  </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 3; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'English';
        }
    ?>
  </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 4; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Mathematics';
        }
    ?>
  </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 5; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Science';
        }
    ?>
  </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl105 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl106 style='border-top:none'>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 7; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'EPP / TLE';
        }
    ?>
  </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl105 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl106 style='border-top:none'>&nbsp;</td>
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
  <td class=xl107 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl176 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl108 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl108 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
           $subject_id = 9; // Example: 1 for Mother Tongue, 2 for Filipino, etc.
           echo htmlspecialchars(getSubjectNameForStudent($conn, $subject_id, $student_id, $grade3_school['id']));
        } else {
           echo 'Music';
        }
    ?>
    </td>
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl118 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl119 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl120 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl121 style='border-top:none;border-left:none'>&nbsp;</td>
  <td class=xl121 style='border-top:none;border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td class=xl120 style='border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl171 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl121 style='border-left:none'>&nbsp;</td>
  <td class=xl121 style='border-left:none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl124 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=3 class=xl127 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl105 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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
  none'>&nbsp;</td>
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
  none'>&nbsp;</td>
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
  <td colspan=14 class=xl142 style='border-right:1.0pt solid black;border-left:
  none'>Conducted from:to</td>
  <td class=xl114 style='border-left:none'>&nbsp;</td>
  <td colspan=5 class=xl145 style='border-right:.5pt solid black;border-left:
  none'>Remedial Classes</td>
  <td colspan=24 class=xl142 style='border-right:1.0pt solid black;border-left:
  none'>Conducted from:to</td>
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
  <td colspan=5 class=xl203 style='border-right:.5pt solid black'>&nbsp;</td>
  <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=4 class=xl132 style='border-left:none'>&nbsp;</td>
  <td colspan=4 class=xl183 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl181 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=5 class=xl131>&nbsp;</td>
  <td colspan=9 class=xl132 style='border-left:none'>&nbsp;</td>
  <td colspan=7 class=xl132 style='border-left:none'>&nbsp;</td>
  <td colspan=6 class=xl132 style='border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl132 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td class=xl66></td>
  <td class=xl66></td>
 </tr>
 <tr height=28 style='mso-height-source:userset;height:21.0pt'>
  <td height=28 class=xl66 style='height:21.0pt'></td>
  <td colspan=5 class=xl204 style='border-right:.5pt solid black'>&nbsp;</td>
  <td colspan=4 class=xl189 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=4 class=xl188 style='border-left:none'>&nbsp;</td>
  <td colspan=4 class=xl189 style='border-right:.5pt solid black;border-left:
  none'>&nbsp;</td>
  <td colspan=2 class=xl192 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
  <td class=xl66></td>
  <td colspan=5 class=xl211>&nbsp;</td>
  <td colspan=9 class=xl188 style='border-left:none'>&nbsp;</td>
  <td colspan=7 class=xl188 style='border-left:none'>&nbsp;</td>
  <td colspan=6 class=xl188 style='border-left:none'>&nbsp;</td>
  <td colspan=2 class=xl188 style='border-right:1.0pt solid black;border-left:
  none'>&nbsp;</td>
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

</body>

</html>
