<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}
?>
<html xmlns:v="urn:schemas-microsoft-com:vml"
xmlns:o="urn:schemas-microsoft-com:office:office"
xmlns:x="urn:schemas-microsoft-com:office:excel"
xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta name="Excel Workbook Frameset">
<meta http-equiv=Content-Type content="text/html; charset=windows-1252">
<meta name=ProgId content=Excel.Sheet>
<meta name=Generator content="Microsoft Excel 15">
<link rel=File-List href="SF10_official_final_files/filelist.xml">
<title>School Form 10 ES Learners Permanent Record Final (Back)</title>
<script language="JavaScript">
<!--
// Function to close the tab or navigate back
function closeTab() {
 if (window.opener) {
  window.opener.focus();
  window.close();
 } else {
  window.close();
 }
}
//-->
</script>
<?php $student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0; ?>
<frameset rows="*" border=0 width=0 frameborder=no framespacing=0>
 <frame src="SF10_official_final_files/sheet002.php?student_id=<?php echo $student_id; ?>" name="frSheet">
 <noframes>
  <body>
   <p>This page uses frames, but your browser doesn't support them.</p>
  </body>
 </noframes>
</frameset>
</html>
