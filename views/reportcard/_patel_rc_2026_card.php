<?php
/* PATEL_RC_MODERN 2026-10-05 - Patel Public School, Term 1 report card, shared by the 1-5, 6-8 and 9-10 tabs.
   The school's card in the Millennium World School layout (gradient header, numbered sections, grade badges,
   KPI boxes) with the St. Mary's subject-wise Performance Analysis graph. Every figure is read and worked
   out exactly as the previous cards did, so a printed card carries the same numbers:
     - a cell is the mark scaled to the exam's weightage, round(mark / max_mark * weightage, 2);
       an absent pupil's cell says AB and counts 0, a missing mark counts 0 (as before);
     - the Half Yearly rule stands: a subject whose Half Yearly mark is absent or below 26.5 prints no total
       and no grade, and the pupil's overall grade is withheld;
     - percentage = total / (subjects x weightage sum) x 100, graded by MyHelper::getGradeByMark.
   The four weighted columns are Periodic Tests, Notebook Work, Sub Enrichment and Half Yearly. ANY OTHER
   term-1 exam of the class becomes an Additional Subject - COMPUTER (out of 50, split THEORY/PRACTICAL) for
   classes 1-8, IT (out of 100, likewise split) for the 9th - graded on its own maximum rescaled to 100, which
   is the "x 2" the old cards hard-coded for the 50-mark Computer. "SA2 Extra 1" (is_extra_exam = 1) carries
   General Knowledge and Drawing, where the stored mark IS the letter grade.
   Co-scholastic: every category mapped to the class for term 1 prints, one box each. The old cards each
   hand-picked a single category and so left mapped areas off the sheet.
   Patel's grade bands have decimal edges (90.5-100, 80.5-90.49, ...) so they leave no gap between bands -
   the floor the Millennium card needs is not wanted here, and marks are passed as they are.
   $ppCfg: label (page heading), page (the getStusForRC1to2 target), menuItem (sidebar li to highlight). */
if (isset(Yii::app()->session['assessment_academic_id'])) {
    $academic_id = Yii::app()->session['assessment_academic_id'];
} else {
    $academic_id = Yii::app()->session['academic_id'];
}
$term_id = 1;
if (!isset($ppCfg) || !is_array($ppCfg)) { $ppCfg = array(); }
$ppLabel    = isset($ppCfg['label']) ? $ppCfg['label'] : 'Report Card';
$ppPage     = isset($ppCfg['page']) ? $ppCfg['page'] : '';
$ppMenuItem = isset($ppCfg['menuItem']) ? $ppCfg['menuItem'] : '';
$ppConsolidate = isset($ppCfg['consolidate']) ? (bool) $ppCfg['consolidate'] : true;
/* PATEL_RC_NOPTM68 2026-10-09 - the school does not want the PTM box on the 6-8 tab */
$ppShowPtm = ($ppPage !== 'newRC20266to8Patel');
/* PATEL_RC_NODRAW68 2026-10-09 - Drawing duplicates Art Education, so the 6-8 tab drops it */
$ppShowDrawing = ($ppPage !== 'newRC20266to8Patel');

/* PATEL_RC_FONT 2026-10-09 - font only. The school reads the printed sheet across a desk, so every
   font-size below is the earlier card's size x1.2 and every grey or slate TEXT colour is now black;
   light text that sits on the purple header, the table heads and the grade badges stays light so it
   still reads. No palette token, background, gradient, border, badge colour, box size or layout rule
   is changed - the card's format is exactly as it was. */
/* the card's look in one place */
$ppDeep = '#2a1a5e'; $ppMid = '#4c2a86'; $ppEnd = '#7a3fa8';
$ppBord = '#ddd6ef'; $ppTint1 = '#f6f4fb'; $ppTint2 = '#f9f7fd'; $ppTint3 = '#efeafa';
$ppLine = '#e6dff5'; $ppChip = '#e9e3f6'; $ppShad = '42,26,94';
$ppGold = '#e6b422';
$ppGraphH    = 112;     /* graph plot height in px */
$ppShowGraph = true;    /* false hides the whole Performance Analysis section */
$ppTitle     = 'Performance Profile';
$ppExamLine  = 'Term 1';
/* the weighted columns of the grid, in the order the school's sheets print them */
$ppMainExams = array('PERIODIC TESTS', 'NOTEBOOK WORK', 'SUB ENRICHMENT', 'HALF YEARLY');
/* PATEL_RC_IA 2026-10-06: the grid prints those columns CONSOLIDATED, as the Millennium card does -
   Internal Assessment = Periodic Tests + Notebook Work + Sub Enrichment, Written Exam = Half Yearly. Display
   only: every cell, total and grade is worked out exactly as before and the cells are then added up per
   group, so Internal Assessment + Written Exam = the subject total. A group with none of its exams set up
   for the class is left out; an exam in no group keeps a column of its own.
   $ppCfg['consolidate'] = false prints the separate columns again. */
$ppColGroups = array(
    array('label' => 'Internal Assessment', 'exams' => array('PERIODIC TESTS', 'NOTEBOOK WORK', 'SUB ENRICHMENT')),
    array('label' => 'Written Exam',        'exams' => array('HALF YEARLY')),
);

if (!function_exists('ppNum')) {
    /* the previous cards printed every mark to two places and the school reads its sheets against them, so
       the grid keeps doing that; ppNum is for the places that read better bare. */
    function ppNum($v) { $s = number_format((float) $v, 2, '.', ''); return rtrim(rtrim($s, '0'), '.'); }
    function ppMark($v) { return number_format((float) $v, 2); }
    function ppSubjectLabel($name) {
        $n = trim($name);
        if (preg_match('/^social\s*(science|stud)/i', $n)) { return 'Social Science'; }
        /* a short all-capitals name is an initialism and stays capitalised - EVS, E.V.S, G.K., IT */
        $bare = preg_replace('/[^A-Za-z]/', '', $n);
        if ($bare !== '' && strlen($bare) <= 4 && strtoupper($bare) === $bare) { return strtoupper($n); }
        return ucwords(strtolower($n));
    }
    /* a category's printed name - the setup name may carry its scale in brackets */
    function ppCatLabel($n) {
        $n = trim(preg_replace('/\s*[\[(].*$/', '', (string) $n));
        $n = rtrim($n, " .\t");
        return $n !== '' ? $n : 'Co-Scholastic Areas';
    }
    /* a co-scholastic area's printed name. The school's own set-up carries a typo in
       FINICIAL LITERACY; correct it on the card without touching the stored name. */
    function ppAreaName($n) {
        $n = trim(preg_replace('/\s+/', ' ', (string) $n));
        if (preg_match('/^fin[ia]*ci[ai]*l\s+literacy$/i', $n)) { return 'Financial Literacy'; }
        return $n;
    }
    function ppBadge($g) {
        $g = trim((string) $g);
        if ($g === '' || $g === '-') { return '<span class="gb g-none">-</span>'; }
        return '<span class="gb g-' . preg_replace('/[^A-Za-z0-9]/', '', $g) . '">' . CHtml::encode($g) . '</span>';
    }
}
if (!function_exists('ppColCell')) {
    /* PATEL_RC_IA: one printed cell of the grid. A column of one exam prints that exam's cell exactly as before;
       a consolidated column prints the sum of what its cells add to the subject total - AB when the only thing
       punched is an absence, a dash when nothing is punched at all. */
    function ppColCell($r, $col) {
        if (count($col['ix']) === 1) { return $r['cells'][$col['ix'][0]]; }
        $sum = 0; $num = false; $ab = false;
        foreach ($col['ix'] as $k) {
            $c = $r['cells'][$k];
            $sum += isset($r['vals'][$k]) ? $r['vals'][$k] : 0;
            if ($c === 'AB') { $ab = true; } elseif ($c !== null) { $num = true; }
        }
        if ($num || $sum > 0) { return ppMark(round($sum, 2)); }
        return $ab ? 'AB' : null;
    }
}
if (!function_exists('getGrade')) {
    function getGrade($mark, $standard_id) { $g = new MyHelper(); return $g->getGradeByMark($mark, $standard_id); }
}
if (!function_exists('getGradePoint')) {
    function getGradePoint($mark, $standard_id) { $g = new MyHelper(); return $g->getGradePointByMark($mark, $standard_id); }
}
$ppPaCache = array();
/* two placeholder sets for the same four names: PDO refuses a named parameter used twice in one statement,
   and the exam query needs the list in both its WHERE and its ORDER BY. */
$ppMainQ = array(); $ppMainP = array(); $ppMainQ2 = array(); $ppMainP2 = array();
foreach ($ppMainExams as $i => $n) {
    $ppMainQ[] = ':mx' . $i;  $ppMainP[':mx' . $i] = $n;
    $ppMainQ2[] = ':my' . $i; $ppMainP2[':my' . $i] = $n;
}
$ppMainIn = implode(',', $ppMainQ);
$ppMainIn2 = implode(',', $ppMainQ2);
?>
<div class="page-content-wrapper">
    <div class="page-content">
        <div class="row">
            <div class="col-md-12">
                <h3 class="page-title">Report Card 2026-2027 &middot; <?php echo CHtml::encode($ppLabel); ?> <small></small></h3>
                <ul class="page-breadcrumb breadcrumb">
                    <li><i class="fa fa-list"></i><a href="#">Assessment</a><i class="fa fa-angle-right"></i></li>
                    <li><a href="#">Report Card 2026-2027 &middot; <?php echo CHtml::encode($ppLabel); ?></a></li>
                </ul>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="portlet">
                    <div class="portlet-body">
                        <div class="tab-content">
                            <div class="tab-pane active" id="tab_1">
                                <div class="portlet purple box" style="width:100% !important">
                                    <div class="portlet-title">
                                        <div class="caption">Report Card 2026-2027 &middot; <?php echo CHtml::encode($ppLabel); ?></div>
                                        <div style="float:right;margin-top: 6px;">
                                            <?php if ($student_ids) { echo '<button class="btn btn-sm btn-warning" id="print"><i class="fa fa-print" aria-hidden="true"></i> Print</button>'; } ?>
                                        </div>
                                    </div>
                                    <div class="portlet-body">
<?php if (!$student_ids) { ?>
                                        <form id="filter_form" onsubmit="return false;" style="width: 90%;">
                                            <div class="form-group">
                                                <label class="col-md-1 control-label">Standard</label>
                                                <div class="col-md-2">
                                                    <?php
                                                    $selectStandard = '';
                                                    if (Yii::app()->session['userTypeId'] == 3) {
                                                        $sql = "SELECT GROUP_CONCAT(standard_id SEPARATOR ',') FROM tb_staff_allocation WHERE staff_id = " . Yii::app()->session['staff_id'] . " AND academic_id = " . Yii::app()->session['academic_id'];
                                                        $standards = Yii::app()->db->createCommand($sql)->queryScalar();
                                                        if ($standards == '') { $standards = 0; }
                                                        echo CHtml::dropDownList('standard', $selectStandard, CHtml::listData(TbStandardDetails::model()->findAll('standard_id IN (' . $standards . ') AND status=1'), 'standard_id', 'standard_description'), array('prompt' => 'All', 'class' => 'form-control', 'style' => 'width:150px'));
                                                    } else {
                                                        echo CHtml::dropDownList('standard', $selectStandard, CHtml::listData(TbStandardDetails::model()->findAll('status=1'), 'standard_id', 'standard_description'), array('prompt' => 'All', 'class' => 'form-control', 'style' => 'width:150px'));
                                                    }
                                                    ?>
                                                </div>
                                                <label class="control-label col-md-1"></label>
                                                <label class="col-md-1 control-label">Section</label>
                                                <div class="col-md-2">
                                                    <select id="section" name="section" class="form-control"><option>All</option></select>
                                                </div>
                                                <label class="col-md-1 control-label" id="loader"></label>
                                                <input type="button" id="gen_rc" name="apply_filter" class="btn blue" value="Go"/>
                                            </div>
                                        </form>
<?php } else { ?>
<div id="print_condent">
<style type="text/css">
/* Every colour the ERP's site-wide table skin could override (thead th / tbody td background and text)
   is pinned at id-level specificity with !important, as on the Millennium and St. Mary's cards. */
.rc{max-width:800px;margin:0 auto 22px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 28px rgba(<?php echo $ppShad; ?>,.16);border:1px solid <?php echo $ppBord; ?>;font-family:'Segoe UI',Calibri,Arial,sans-serif;color:#000;page-break-after:always;line-height:1.3}
.rc-top{background:linear-gradient(120deg,<?php echo $ppDeep; ?> 0%,<?php echo $ppMid; ?> 52%,<?php echo $ppEnd; ?> 100%);color:#fff;padding:16px 22px;display:table;width:100%;box-sizing:border-box;position:relative}
.rc-top .lg{display:table-cell;width:92px;vertical-align:middle}
.rc-top .lg span{display:inline-block;width:84px;height:84px;border-radius:50%;background:#fff;box-shadow:0 0 0 3px <?php echo $ppGold; ?>,0 2px 10px rgba(0,0,0,.3);text-align:center}
.rc-top .lg img{width:74px;height:74px;margin-top:5px;object-fit:contain;border-radius:50%}
.rc-top .sc{display:table-cell;vertical-align:middle;padding-left:14px}
.rc-top .sc h1{margin:0;font-size:25px;letter-spacing:.6px;text-transform:uppercase;font-weight:800;line-height:1.1;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.25)}
.rc-top .sc p{margin:3px 0 0;font-size:14px;color:#e3e7f7}
.rc-top .tg{display:table-cell;width:100px;vertical-align:middle;text-align:right}
.rc-top .tg .cbl{display:block;width:84px;height:84px;margin:0 0 0 auto;border-radius:50%;background:#fff;box-shadow:0 0 0 3px <?php echo $ppGold; ?>,0 2px 10px rgba(0,0,0,.3);text-align:center}
.rc-top .tg .cbl img{width:74px;height:74px;margin-top:5px;object-fit:contain;border-radius:50%}
.rc-body{padding:14px 22px 18px}
.rc-title{text-align:center;margin:0 0 10px}
.rc-title b{font-size:23px;color:<?php echo $ppDeep; ?>;letter-spacing:3px;text-transform:uppercase}
.rc-title small{display:block;color:#000;font-size:13px;letter-spacing:2px;text-transform:uppercase;margin-top:1px}
.rc-title:after{content:'';display:block;width:64px;height:3px;background:<?php echo $ppGold; ?>;margin:6px auto 0;border-radius:2px}
table.prof{width:100%;border-collapse:separate;border-spacing:0;background:<?php echo $ppTint1; ?>;border:1px solid <?php echo $ppBord; ?>;border-radius:10px;border-left:5px solid <?php echo $ppGold; ?>}
#print_condent .rc table.prof td{padding:7px 12px;width:33.3%;font-size:15px;vertical-align:top;background:transparent !important;color:#000 !important;border:0 !important}
.prof td small{display:inline;color:<?php echo $ppDeep; ?>;font-size:16px;font-weight:700;text-transform:none;letter-spacing:0}
.prof td small::after{content:' : '}
.prof td b{font-size:16px;font-weight:800;color:<?php echo $ppDeep; ?>}
.sec{display:table;width:100%;margin:13px 0 6px}
.sec .no{display:table-cell;width:26px;height:26px;border-radius:8px;background:<?php echo $ppGold; ?>;color:<?php echo $ppDeep; ?>;font-weight:800;font-size:14px;text-align:center;vertical-align:middle}
.sec h3{display:table-cell;vertical-align:middle;padding-left:9px;margin:0;font-size:16px;letter-spacing:1.2px;text-transform:uppercase;color:<?php echo $ppDeep; ?>;font-weight:800}
.sec .sub{display:table-cell;vertical-align:middle;text-align:right;font-size:12.5px;color:#000;font-weight:700;text-transform:uppercase;letter-spacing:.6px}
table.grid{width:100%;border-collapse:separate;border-spacing:0;font-size:15.5px;border:1px solid <?php echo $ppBord; ?>;border-radius:10px;overflow:hidden}
#print_condent .rc table.grid thead th{background:<?php echo $ppDeep; ?> !important;background-image:linear-gradient(180deg,<?php echo $ppMid; ?>,<?php echo $ppDeep; ?>) !important;color:#fff !important;font-weight:700;padding:7px 5px;text-align:center;font-size:12.5px;letter-spacing:.3px;border:0 !important;border-bottom:2px solid <?php echo $ppGold; ?> !important}
.grid th small{display:block;font-weight:600;opacity:.85;font-size:12px}
#print_condent .rc table.grid tbody td{padding:6px 5px;text-align:center;font-weight:700;border:0 !important;border-top:1px solid <?php echo $ppLine; ?> !important;background:#fff !important;color:#000 !important}
#print_condent .rc table.grid tbody tr:nth-child(even) td{background:<?php echo $ppTint2; ?> !important}
#print_condent .rc table.grid tbody td.l{text-align:left;font-weight:800 !important;padding-left:10px;font-size:16px}
#print_condent .rc table.grid tbody tr.tot td{background:#fff6d8 !important;font-weight:800;color:<?php echo $ppDeep; ?> !important;border-top:1.5px solid <?php echo $ppGold; ?> !important}
.grid .dash{color:#000}
.grid .ab{color:#c0392b;font-weight:800}
.gb{display:inline-block;min-width:26px;padding:2px 8px;border-radius:12px;font-weight:800;font-size:13.5px;color:#fff;background:#8592a3;line-height:1.35}
.gb.g-none{background:<?php echo $ppChip; ?>;color:#000}
.gb.g-A1,.gb.g-A,.gb.g-Aplus{background:#1a9e5c}.gb.g-A2{background:#3aa876}.gb.g-B1,.gb.g-B,.gb.g-Bplus{background:#2f80c3}.gb.g-B2{background:#5a93cf}.gb.g-C1,.gb.g-C,.gb.g-Cplus{background:#e39b1a}.gb.g-C2{background:#d97d13}.gb.g-D{background:#cf5b2d}.gb.g-E{background:#c0392b}
.two{display:table;width:100%;border-spacing:0}
.two>div{display:table-cell;width:50%;vertical-align:top}
.two>div.a{padding-right:6px}.two>div.b{padding-left:6px}
.two>div>table.grid{width:100%}
.key{border:1px solid <?php echo $ppBord; ?>;border-radius:10px;padding:7px 9px;font-size:12.5px;color:#000;background:#fff}
.key b{display:block;color:<?php echo $ppDeep; ?>;text-transform:uppercase;letter-spacing:.8px;font-size:12px;margin-bottom:4px}
.key span{display:inline-block;margin:2px 5px 2px 0;white-space:nowrap}
/* Performance Analysis - the St. Mary's graph */
.pa{display:flex;align-items:flex-start;border:1px solid <?php echo $ppBord; ?>;border-radius:10px;background:#fff;padding:14px 12px 6px;box-sizing:border-box}
.pa-y{position:relative;width:30px;flex:none;height:<?php echo (int) $ppGraphH; ?>px}
.pa-y span{position:absolute;right:6px;font-size:11px;color:#000;line-height:1;transform:translateY(-50%)}
.pa-plot{position:relative;flex:1;min-width:0}
.pa-grid{position:absolute;left:0;right:0;top:0;height:<?php echo (int) $ppGraphH; ?>px}
.pa-grid i{position:absolute;left:0;right:0;border-top:1px dashed #e3e6f2}
.pa-cols{position:relative;display:flex;align-items:flex-end}
.pa-col{flex:1;display:flex;flex-direction:column;align-items:center;min-width:0}
.pa-bars{height:<?php echo (int) $ppGraphH; ?>px;width:100%;display:flex;align-items:flex-end;justify-content:center}
.pa-b{width:15px;margin:0 2px;border-radius:3px 3px 0 0;position:relative}
.pa-b b{position:absolute;top:-12px;left:50%;transform:translateX(-50%);font-size:10px;font-weight:700;color:<?php echo $ppDeep; ?>;white-space:nowrap}
.pa-b.h{background:<?php echo $ppGold; ?>}.pa-b.a{background:#a78bd0}.pa-b.m{background:<?php echo $ppDeep; ?>}
.pa-b.none{background:transparent}
.pa-lbl{font-size:11px;font-weight:700;color:#000;margin-top:4px;text-align:center;line-height:1.15;word-break:break-word}
.pa-key{margin-top:5px;text-align:center;font-size:11.5px;color:#000;font-weight:700;letter-spacing:.3px}
.pa-key .sw{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px;vertical-align:-1px}
.pa-key .sw.h{background:<?php echo $ppGold; ?>}.pa-key .sw.a{background:#a78bd0}.pa-key .sw.m{background:<?php echo $ppDeep; ?>}
.kpi{display:table;width:100%;border-spacing:0;margin-top:12px}
.kpi>div{display:table-cell;width:25%;padding:0 5px}
.kpi>div>div{background:linear-gradient(180deg,<?php echo $ppTint2; ?>,<?php echo $ppTint3; ?>);border:1px solid <?php echo $ppBord; ?>;border-top:3px solid <?php echo $ppGold; ?>;border-radius:10px;padding:9px 6px;text-align:center}
.kpi b{display:block;font-size:23px;color:<?php echo $ppDeep; ?>;line-height:1.15}
.kpi small{display:block;color:#000;text-transform:uppercase;letter-spacing:1px;font-size:12px;margin-top:2px}
.att{display:table;width:100%;border-spacing:0;margin-top:8px}
.att>div{display:table-cell;width:50%;padding:0 5px}
.att1>div{width:100%}
.att>div>div{border:1px solid <?php echo $ppBord; ?>;border-radius:10px;padding:6px 10px;font-size:14.5px;background:<?php echo $ppTint1; ?>}
.att small{color:<?php echo $ppDeep; ?>;text-transform:uppercase;letter-spacing:.8px;font-size:12px;font-weight:800;margin-right:8px}
.rem{margin-top:8px;border:1px dashed #c9a94a;border-radius:10px;padding:9px 12px;background:#fffbf0;font-size:15px;line-height:1.3}
.rem small{display:inline-block;color:<?php echo $ppDeep; ?>;text-transform:uppercase;letter-spacing:.8px;font-size:12px;font-weight:800;margin:0 10px 0 0}
.sig{display:table;width:100%;margin-top:26px}
.sig>div{display:table-cell;width:33.33%;text-align:center;padding:0 12px}
.sig .ln{border-top:1.5px solid <?php echo $ppDeep; ?>;padding-top:5px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:<?php echo $ppDeep; ?>}
.sig .nm{font-size:13px;color:#000;margin-top:1px}
.foot{margin-top:12px;text-align:center;font-size:12px;color:#000;letter-spacing:.5px}
/* print: one sheet a pupil, filled top to bottom */
@media print{body{margin:0}.rc{box-shadow:none;border-radius:0;border:0;max-width:none;margin:0;zoom:1;height:1190px;display:flex;flex-direction:column;overflow:visible;page-break-inside:avoid;page-break-after:always}.rc-top{flex:none}.rc-body{flex:1;display:flex;flex-direction:column;padding:12px 20px 14px}.rc-title,.prof,.sec,.grid,.two,.kpi,.key,.pa,.pa-key,.att,.rem{flex:none}.sig{flex:none;margin-top:auto;padding-top:20px}.rc *{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
<?php
$school  = Yii::app()->db->createCommand("SELECT * FROM tb_school WHERE 1 LIMIT 1")->queryRow();
$logosrc = (!empty($school['logo_url'])) ? $school['logo_url'] : '/images/report_logo.png';
$cbsesrc = '/images/cbse-logo.gif';
$ppPhone = trim((string) $school['phone_number']);
if (trim((string) $school['mobile_number']) !== '' && trim($school['mobile_number']) !== $ppPhone) { $ppPhone .= ', ' . trim($school['mobile_number']); }

foreach ($student_ids as $student_id) {
    $studsql = "SELECT SD.first_name, SD.admission_number, SD.class_admission_no, SD.father_name, SD.mother_name, SD.date_of_birth,
                       SAD.standard_id, SAD.section_id, SAD.roll_number, STD.standard_description, SEC.section_description,
                       (SELECT academic_description FROM tb_academic_details WHERE academic_id = SAD.academic_id) AS academic_description
                FROM tb_student_details AS SD
                LEFT JOIN tb_student_academic_details AS SAD ON SAD.student_id = SD.student_id
                LEFT JOIN tb_standard_details AS STD ON STD.standard_id = SAD.standard_id
                LEFT JOIN tb_section_details AS SEC ON SEC.section_id = SAD.section_id
                WHERE SD.student_id = " . (int) $student_id . " " . MyHelper::getStudentCondition() . " AND SAD.academic_id = " . (int) $academic_id;
    $stu = Yii::app()->db->createCommand($studsql)->queryRow();
    if (!$stu) { continue; }
    $standard_id = (int) $stu['standard_id'];
    $section_id  = (int) $stu['section_id'];
    $session     = trim(preg_replace('/^(Batch|Session)[ _:-]*/i', '', (string) $stu['academic_description']));
    $ppP = array_merge(array(':std' => $standard_id, ':ac' => $academic_id, ':term' => $term_id), $ppMainP);

    /* ---- the weighted columns, in the order the school's sheets print them ---- */
    $exams = Yii::app()->db->createCommand("SELECT exam_id, exam_details, weightage FROM tb_exam_details
        WHERE FIND_IN_SET(:std, REPLACE(standard_id, ' ', '')) > 0 AND academic_id = :ac AND status = 1 AND term = :term
          AND is_extra_exam = 0 AND FIELD(UPPER(TRIM(exam_details)), $ppMainIn) > 0
        ORDER BY FIELD(UPPER(TRIM(exam_details)), $ppMainIn2), exam_id")->queryAll(true, array_merge($ppP, $ppMainP2));

    $subjects = array();
    if ($exams) {
        /* the subject list comes off the first column, exactly as the previous cards took it */
        $subjects = Yii::app()->db->createCommand("SELECT se.subject_id, sd.subject_name FROM tb_subject_for_exam_details se
            JOIN tb_subject_detail sd ON sd.subject_id = se.subject_id
            WHERE se.standard_id = :std AND se.exam_id = :ex AND se.academic_id = :ac
            ORDER BY FIELD(UPPER(TRIM(sd.subject_name)), 'ENGLISH', 'HINDI', 'PUNJABI', 'MATHEMATICS', 'SCIENCE', 'SOCIAL SCIENCE') = 0,
                     FIELD(UPPER(TRIM(sd.subject_name)), 'ENGLISH', 'HINDI', 'PUNJABI', 'MATHEMATICS', 'SCIENCE', 'SOCIAL SCIENCE'), se.id")
            ->queryAll(true, array(':std' => $standard_id, ':ex' => $exams[0]['exam_id'], ':ac' => $academic_id));
    }
    $wsum = 0; foreach ($exams as $e) { $wsum += (float) $e['weightage']; }

    /* PATEL_RC_IA: the printed columns - each one is a list of indexes into $exams. Groups come first, in the
       order of $ppColGroups; an exam in no group follows on its own. Without consolidation every exam is its
       own column, which is the previous card. */
    $ppCols = array(); $ppUsed = array();
    if ($ppConsolidate) {
        foreach ($ppColGroups as $g) {
            $ix = array(); $w = 0;
            foreach ($exams as $k => $e) {
                if (in_array(strtoupper(trim($e['exam_details'])), $g['exams'])) { $ix[] = $k; $w += (float) $e['weightage']; $ppUsed[$k] = 1; }
            }
            if ($ix) { $ppCols[] = array('label' => $g['label'], 'w' => $w, 'ix' => $ix); }
        }
    }
    foreach ($exams as $k => $e) {
        if (!isset($ppUsed[$k])) { $ppCols[] = array('label' => strtoupper(trim($e['exam_details'])), 'w' => (float) $e['weightage'], 'ix' => array($k)); }
    }

    $rows = array(); $colTot = array(); $gtt = 0; $anyMark = false; $isHalfYearlyFail = 0;
    foreach ($subjects as $s) {
        $r = array('name' => ppSubjectLabel($s['subject_name']), 'cells' => array(), 'vals' => array(), 'total' => 0, 'fail' => 0);
        foreach ($exams as $e) {
            $cfg = Yii::app()->db->createCommand("SELECT max_mark FROM tb_subject_for_exam_details
                WHERE standard_id = :std AND exam_id = :ex AND academic_id = :ac AND subject_id = :sub")
                ->queryRow(true, array(':std' => $standard_id, ':ex' => $e['exam_id'], ':ac' => $academic_id, ':sub' => $s['subject_id']));
            $md = Yii::app()->db->createCommand("SELECT mark, is_absent FROM tb_mark_details
                WHERE student_id = :sid AND exam_id = :ex AND academic_id = :ac AND subject_id = :sub")
                ->queryRow(true, array(':sid' => $student_id, ':ex' => $e['exam_id'], ':ac' => $academic_id, ':sub' => $s['subject_id']));

            /* a cell the school has not punched yet prints a dash rather than the 0 the previous cards
               showed, which read as a zero scored. It still counts 0 towards the totals, as before. */
            $mark = 0; $hasMark = false;
            if ($md && trim((string) $md['mark']) !== '' && $cfg && (float) $cfg['max_mark'] > 0) {
                $mark = round(((float) $md['mark'] / (float) $cfg['max_mark']) * (float) $e['weightage'], 2);
                $hasMark = true;
            }
            if ($md && (int) $md['is_absent'] === 1) { $r['cells'][] = 'AB'; }
            elseif ($hasMark) { $r['cells'][] = ppMark($mark); }
            else { $r['cells'][] = null; }
            $r['vals'][] = $mark;   /* PATEL_RC_IA: the figure this cell adds to the total, for the consolidated columns */
            if ($mark > 0) { $anyMark = true; }

            /* the school's Half Yearly rule, unchanged */
            if (strcasecmp(trim((string) $e['exam_details']), 'Half Yearly') === 0) {
                if (($md && (int) $md['is_absent'] === 1) || !$md || (float) $md['mark'] < 26.5) { $r['fail'] = 1; $isHalfYearlyFail = 1; }
            }
            $r['total'] += $mark;
            $colTot[$e['exam_id']] = (isset($colTot[$e['exam_id']]) ? $colTot[$e['exam_id']] : 0) + $mark;
        }
        $r['grade'] = $r['fail'] ? '' : getGrade($r['total'], $standard_id);
        $gtt += $r['total'];
        $rows[] = $r;
    }
    $sub_cnt    = count($subjects);
    $maxTotal   = $sub_cnt * $wsum;
    /* the percentage is stated for every pupil, as the previous cards' footer did. The overall grade is held
       back while any subject is under the Half Yearly rule, so the card never shows a grade built on marks
       it is itself leaving blank. */
    $percentage = ($sub_cnt && $maxTotal > 0) ? round(($gtt / $maxTotal) * 100, 2) : null;
    $overall    = ($percentage !== null && !$isHalfYearlyFail) ? getGrade($percentage, $standard_id) : '-';
    if ($overall === 'FAIL' || $overall === 'F') { $overall = '-'; }

    /* ---- Additional subjects: every other term-1 exam of the class. COMPUTER (1-8) and IT (9th) are split
           into THEORY and PRACTICAL; anything else prints its plain mark. Graded on its own maximum rescaled
           to 100 - the "x 2" the old cards hard-coded for the 50-mark Computer. ---- */
    $addRows = array(); $addHeads = array();
    $addExams = Yii::app()->db->createCommand("SELECT exam_id, exam_details FROM tb_exam_details
        WHERE FIND_IN_SET(:std, REPLACE(standard_id, ' ', '')) > 0 AND academic_id = :ac AND status = 1 AND term = :term
          AND is_extra_exam = 0 AND FIELD(UPPER(TRIM(exam_details)), $ppMainIn) = 0 ORDER BY exam_id")->queryAll(true, $ppP);
    foreach ($addExams as $ax) {
        $addSubs = Yii::app()->db->createCommand("SELECT se.subject_id, se.max_mark, sd.subject_name
            FROM tb_subject_for_exam_details se JOIN tb_subject_detail sd ON sd.subject_id = se.subject_id
            WHERE se.exam_id = :ex AND se.standard_id = :std AND se.academic_id = :ac ORDER BY sd.subject_name")
            ->queryAll(true, array(':ex' => $ax['exam_id'], ':std' => $standard_id, ':ac' => $academic_id));
        foreach ($addSubs as $as) {
            $mx = (float) $as['max_mark'];
            $titles = ExamSplitupTitle::model()->findAll(array(
                'condition' => 'exam_id = :ex AND subject_id = :sub AND standard_id = :std', 'order' => 'id',
                'params' => array(':ex' => $ax['exam_id'], ':sub' => $as['subject_id'], ':std' => $standard_id)));
            $parts = array(); $tot = 0; $got = false;
            if ($titles) {
                foreach ($titles as $t) {
                    /* the split-up mark carries its own academic_id; the previous cards did not filter on it,
                       which would have read another session's mark once a second session had them */
                    $m = ExamSplitupMark::model()->findByAttributes(array('exam_splitup_title_id' => $t['id'], 'student_id' => $student_id, 'academic_id' => $academic_id));
                    $v = ($m && $m->is_absent) ? 'AB' : (($m && $m->mark !== null && $m->mark !== '') ? (float) $m->mark : null);
                    $parts[] = array('title' => ppSubjectLabel($t['title']), 'mark' => $v);
                    if (is_numeric($v)) { $tot += $v; $got = true; }
                }
                if (count($parts) > count($addHeads)) { $addHeads = array(); foreach ($parts as $p) { $addHeads[] = $p['title']; } }
            } else {
                $md = Yii::app()->db->createCommand("SELECT mark, is_absent FROM tb_mark_details
                    WHERE student_id = :sid AND exam_id = :ex AND academic_id = :ac AND subject_id = :sub")
                    ->queryRow(true, array(':sid' => $student_id, ':ex' => $ax['exam_id'], ':ac' => $academic_id, ':sub' => $as['subject_id']));
                if ($md && (int) $md['is_absent'] === 1) { $parts[] = array('title' => 'Marks', 'mark' => 'AB'); }
                elseif ($md && trim((string) $md['mark']) !== '') { $tot = (float) $md['mark']; $got = true; $parts[] = array('title' => 'Marks', 'mark' => $tot); }
                else { $parts[] = array('title' => 'Marks', 'mark' => null); }
            }
            $addRows[] = array('name' => ppSubjectLabel($as['subject_name']), 'parts' => $parts, 'max' => $mx,
                               'total' => $got ? $tot : null,
                               'grade' => ($got && $mx > 0) ? getGrade(round($tot / $mx * 100, 2), $standard_id) : '-');
        }
    }

    /* ---- the graded extras ("SA2 Extra 1": General Knowledge, Drawing) - the stored mark IS the grade ---- */
    $extraRows = array();
    $exExams = Yii::app()->db->createCommand("SELECT exam_id FROM tb_exam_details
        WHERE FIND_IN_SET(:std, REPLACE(standard_id, ' ', '')) > 0 AND academic_id = :ac AND status = 1 AND term = :term
          AND is_extra_exam = 1 ORDER BY exam_id")->queryColumn(array(':std' => $standard_id, ':ac' => $academic_id, ':term' => $term_id));
    foreach ($exExams as $xe) {
        $exSubs = Yii::app()->db->createCommand("SELECT se.subject_id, sd.subject_name FROM tb_subject_for_exam_details se
            JOIN tb_subject_detail sd ON sd.subject_id = se.subject_id
            WHERE se.exam_id = :ex AND se.standard_id = :std AND se.academic_id = :ac ORDER BY sd.subject_name")
            ->queryAll(true, array(':ex' => $xe, ':std' => $standard_id, ':ac' => $academic_id));
        foreach ($exSubs as $xs) {
            $g = Yii::app()->db->createCommand("SELECT mark FROM tb_mark_details
                WHERE student_id = :sid AND exam_id = :ex AND academic_id = :ac AND subject_id = :sub")
                ->queryScalar(array(':sid' => $student_id, ':ex' => $xe, ':ac' => $academic_id, ':sub' => $xs['subject_id']));
            if (!$ppShowDrawing && preg_match('/^\s*drawing\s*$/i', (string) $xs['subject_name'])) { continue; }
            $extraRows[] = array('name' => ppSubjectLabel($xs['subject_name']), 'grade' => (trim((string) $g) !== '') ? trim($g) : '-');
        }
    }

    /* ---- co-scholastic: every category mapped to this class for term 1, one box each ---- */
    $coBoxes = array();
    $coMaps = Yii::app()->db->createCommand("SELECT m.map_id, sc.co_sch_sub_cat_name, c.co_sch_category_id, c.co_sch_category_name
        FROM co_sch_sub_cat_class_mapping m
        JOIN co_scholastic_sub_category sc ON sc.co_sch_sub_cat_id = m.co_sch_sub_cat_id
        JOIN co_scholastic_category c ON c.co_sch_category_id = sc.co_sch_category_id
        WHERE m.academic_id = :ac AND m.standard_id = :std AND sc.is_active = 1 AND c.is_active = 1 AND c.co_sch_term = :term
        ORDER BY c.co_sch_category_id, m.map_id")
        ->queryAll(true, array(':ac' => $academic_id, ':std' => $standard_id, ':term' => $term_id));
    foreach ($coMaps as $m) {
        /* keyed on the PRINTED name, so two set-up categories that print the same ("Personality Traits"
           and "Personality Traits.") land in one box rather than two identical-looking ones */
        $cid = strtolower(ppCatLabel($m['co_sch_category_name']));
        if (!isset($coBoxes[$cid])) { $coBoxes[$cid] = array('name' => ppCatLabel($m['co_sch_category_name']), 'rows' => array()); }
        $g = Yii::app()->db->createCommand("SELECT gd.grade_desc FROM co_sch_mark_details cm
            JOIN grade_detail gd ON gd.grade_id = cm.obtained_grade
            WHERE cm.student_id = :sid AND cm.sub_cat_std_id = :map ORDER BY cm.mark_id DESC")
            ->queryScalar(array(':sid' => $student_id, ':map' => $m['map_id']));
        $coBoxes[$cid]['rows'][] = array('name' => $m['co_sch_sub_cat_name'], 'grade' => $g ? trim($g) : '-');
    }
    $coTallest = 0;
    foreach ($coBoxes as $bx) { if (count($bx['rows']) > $coTallest) { $coTallest = count($bx['rows']); } }

    /* ---- Performance Analysis: every pupil of this class-section, weighted per subject, cached for the
           whole print run. The pupil's own bar reuses $rows so it matches the grid above. ---- */
    $paRows = array();
    if ($ppShowGraph && $anyMark && $exams && $wsum > 0) {
        $pkey = $standard_id . '-' . $section_id;
        if (!isset($ppPaCache[$pkey])) {
            $iStd = (int) $standard_id; $iAc = (int) $academic_id; $iSec = (int) $section_id; $iTerm = (int) $term_id;
            $qMain = array(); foreach ($ppMainExams as $n) { $qMain[] = "'" . addslashes($n) . "'"; }
            $inMain = implode(',', $qMain);
            $subTots = Yii::app()->db->createCommand("SELECT md.subject_id, md.student_id, SUM((md.mark + 0) / cfg.max_mark * ed.weightage) AS tot
                FROM tb_mark_details md
                JOIN tb_exam_details ed ON ed.exam_id = md.exam_id
                JOIN tb_subject_for_exam_details cfg ON cfg.exam_id = md.exam_id AND cfg.subject_id = md.subject_id AND cfg.standard_id = $iStd AND cfg.academic_id = $iAc AND cfg.max_mark > 0
                JOIN tb_student_academic_details sad ON sad.student_id = md.student_id AND sad.academic_id = $iAc AND sad.standard_id = $iStd AND sad.section_id = $iSec AND sad.status = 1
                WHERE md.academic_id = $iAc AND ed.academic_id = $iAc AND ed.term = $iTerm AND ed.is_extra_exam = 0 AND ed.status = 1
                  AND UPPER(TRIM(ed.exam_details)) IN ($inMain)
                  AND md.mark <> '' AND md.is_absent = 0 AND FIND_IN_SET('$iStd', REPLACE(ed.standard_id, ' ', '')) > 0
                GROUP BY md.subject_id, md.student_id")->queryAll();
            $agg = array();
            foreach ($subTots as $t) {
                $sb = (int) $t['subject_id']; $v = (float) $t['tot'];
                if (!isset($agg[$sb])) { $agg[$sb] = array('hi' => 0, 'sum' => 0, 'n' => 0); }
                if ($v > $agg[$sb]['hi']) { $agg[$sb]['hi'] = $v; }
                $agg[$sb]['sum'] += $v; $agg[$sb]['n']++;
            }
            $ppPaCache[$pkey] = $agg;
        }
        $agg = $ppPaCache[$pkey];
        foreach ($subjects as $si => $s) {
            $sb = (int) $s['subject_id'];
            $paRows[] = array(
                'name' => ppSubjectLabel($s['subject_name']),
                'hi'   => isset($agg[$sb]) ? $agg[$sb]['hi'] : 0,
                'av'   => (isset($agg[$sb]) && $agg[$sb]['n'] > 0) ? $agg[$sb]['sum'] / $agg[$sb]['n'] : 0,
                'me'   => isset($rows[$si]) ? (float) $rows[$si]['total'] : null,
            );
        }
    }

    /* ---- attendance, PTM, remarks, class incharge ---- */
    $ppParam = function ($name) use ($academic_id, $student_id) {
        $p = AssParamsDetails::model()->findByAttributes(array('param_name' => $name));
        if (!$p) { return '-'; }
        $v = AssStudentParamsMaping::model()->findByAttributes(array('academic_id' => $academic_id, 'student_id' => $student_id, 'param_id' => $p->param_id));
        return ($v && trim((string) $v->description) !== '') ? trim($v->description) : '-';
    };
    $term1_att     = $ppParam('Term1 Attendance');
    $term1_remarks = $ppParam('Term 1 Remarks');
    $ptm           = $ppParam('PTM');

    $incharge = Yii::app()->db->createCommand("SELECT IFNULL(GROUP_CONCAT(DISTINCT staff_name), '') FROM staff_incharge_details WHERE section_id = :sec")
        ->queryScalar(array(':sec' => $section_id));
    if (trim((string) $incharge) === 'No Incharge') { $incharge = ''; }

    $bands = Yii::app()->db->createCommand("SELECT grade_desc, from_percentage f, to_percentage t FROM grade_detail
        WHERE grade_type = 1 AND academic_id = :ac AND is_active = 1
          AND grade_id IN (SELECT grade_id FROM grade_class_mapping WHERE standard_id = :std) ORDER BY from_percentage DESC")
        ->queryAll(true, array(':ac' => $academic_id, ':std' => $standard_id));

    /* Print zoom, so each pupil fills one A4 sheet and never spills onto a second. The costs below are this
       card's own CSS read back at the PATEL_RC_FONT sizes: a section header is 16px + 13/6px margins = 35,
       a table head 12.5px + 14 padding = 30, a body row 15.5px x 1.3 + 12 padding = 33. The base is
       everything a card always carries - header 130, title 58, profile 100, section 1 and its head 70,
       five subject rows 165, the total row 33, KPI 80, attendance 46, remarks 52, signatures 62, foot 28,
       padding 30. Side-by-side boxes do not add up: the taller of the pair sets the height. Bigger type
       leans on the zoom a little sooner, which is why the floor goes to .58. */
    $ppEst = 854 + (count($rows) - 5) * 33
           + ($addRows || $extraRows ? 70 + max(count($addRows), count($extraRows)) * 33 : 0)
           + 70 + max($coTallest, 1) * 33
           + ($bands ? 92 : 0)
           + ($paRows ? ($ppGraphH + 110) : 0);
    /* PATEL_RC_FONT: the cap was .92, so even a sparse card printed 8% under its own type size; it is
       now 1, and the floor is .72 rather than .58 - below that the card's 15.5px grid text lands under
       11px on paper, which is what the school was reading as too light. A card dense enough to want
       less than .72 (many subjects plus the graph) is held at .72 and allowed to run a little long
       rather than shrunk to nothing; $ppShowGraph = false frees ~220px and lifts it well clear. */
    $ppZoom = 1;
    if ($ppEst + 25 > 1190) {
        $ppZoom = floor((1110 / ($ppEst + 25)) * 100) / 100;
        if ($ppZoom > 1) { $ppZoom = 1; }
        if ($ppZoom < 0.72) { $ppZoom = 0.72; }
    }
    $secNo = 0;
?>
<input type="hidden" name="standard" id="standard" value="<?php echo $standard_id; ?>">
<input type="hidden" name="section" id="section" value="<?php echo $section_id; ?>">
<style type="text/css">@media print{#rc_<?php echo $student_id; ?>{zoom:<?php echo $ppZoom; ?>;height:<?php echo round(1110 / $ppZoom); ?>px}}</style>
<div class="rc" id="rc_<?php echo $student_id; ?>">
    <div class="rc-top">
        <div class="lg"><span><img src="<?php echo Yii::app()->getBaseUrl(true) . $logosrc; ?>" alt=""></span></div>
        <div class="sc">
            <h1><?php echo CHtml::encode(rtrim(trim((string) $school['school_name']), ',')); ?></h1>
            <?php $ppAddr = trim(preg_replace('/\s+/', ' ', (string) $school['address'])); ?>
            <?php if ($ppAddr !== '') { ?><p><?php echo CHtml::encode($ppAddr); ?></p><?php } ?>
            <p><?php echo CHtml::encode(trim((string) $school['board']) !== '' ? trim($school['board']) : 'Affiliated to CBSE'); ?><?php if (!empty($school['affliation_no'])) { echo ' &nbsp;&middot;&nbsp; Affiliation No. ' . CHtml::encode($school['affliation_no']); } ?></p>
            <?php if ($ppPhone !== '') { ?><p>Ph. <?php echo CHtml::encode($ppPhone); ?></p><?php } ?>
        </div>
        <div class="tg"><span class="cbl"><img src="<?php echo Yii::app()->getBaseUrl(true) . $cbsesrc; ?>" alt="CBSE"></span></div>
    </div>
    <div class="rc-body">
        <div class="rc-title"><b><?php echo CHtml::encode($ppTitle); ?></b><small>Session <?php echo CHtml::encode($session); ?> &middot; <?php echo CHtml::encode($ppExamLine); ?></small></div>
        <table class="prof">
            <tr>
                <td><small>Name</small><b><?php echo strtoupper(CHtml::encode($stu['first_name'])); ?></b></td>
                <td><small>Class &amp; Section</small><b><?php echo CHtml::encode(trim($stu['standard_description']) . ' - ' . $stu['section_description']); ?></b></td>
                <td><small>Roll No.</small><b><?php echo CHtml::encode($stu['roll_number']); ?></b></td>
            </tr>
            <tr>
                <td><small>Father's Name</small><b><?php echo strtoupper(CHtml::encode($stu['father_name'])); ?></b></td>
                <td><small>Mother's Name</small><b><?php echo trim((string) $stu['mother_name']) !== '' ? strtoupper(CHtml::encode($stu['mother_name'])) : '-'; ?></b></td>
                <td><small>Admission No.</small><b><?php echo CHtml::encode(trim((string) $stu['class_admission_no']) !== '' ? trim($stu['class_admission_no']) : $stu['admission_number']); ?></b></td>
            </tr>
            <tr>
                <td colspan="3"><small>Date of Birth</small><b><?php echo ($stu['date_of_birth'] && $stu['date_of_birth'] !== '0000-00-00') ? date('F j, Y', strtotime($stu['date_of_birth'])) : '-'; ?></b></td>
            </tr>
        </table>

        <div class="sec"><span class="no"><?php echo ++$secNo; ?></span><h3>Term 1 &middot; Scholastic Areas</h3></div>
        <table class="grid">
            <thead>
                <tr>
                <?php if ($exams) { ?>
                    <th style="width:6%;">S.No</th>
                    <th style="width:24%;text-align:left;padding-left:10px;">Subjects</th>
                    <?php foreach ($ppCols as $col) { echo '<th>' . CHtml::encode($col['label']) . '<small>(' . ppNum($col['w']) . ')</small></th>'; } ?>
                    <th><?php echo $ppConsolidate ? 'Total' : 'Marks Obtained'; ?><small>(<?php echo ppNum($wsum); ?>)</small></th>
                    <th style="width:11%;"><?php echo $ppConsolidate ? 'Grade' : 'Grades'; ?></th>
                <?php } else { ?>
                    <th style="text-align:left;padding-left:10px;">Subjects</th>
                <?php } ?>
                </tr>
            </thead>
            <tbody>
            <?php if (!$exams) { echo '<tr><td class="l" style="color:#b00;">No exams are set up for this class in ' . CHtml::encode($session) . '</td></tr>'; }
                  elseif (!$subjects) { echo '<tr><td colspan="' . (count($ppCols) + 4) . '" style="color:#b00;">Subject not mapped</td></tr>'; }
                  else {
                      $i = 0;
                      foreach ($rows as $r) { $i++;
                          echo '<tr><td>' . $i . '</td><td class="l">' . CHtml::encode($r['name']) . '</td>';
                          foreach ($ppCols as $col) {
                              $c = ppColCell($r, $col);
                              echo '<td>' . ($c === null ? '<span class="dash">&ndash;</span>' : ($c === 'AB' ? '<span class="ab">AB</span>' : $c)) . '</td>';
                          }
                          echo '<td><b>' . ($r['fail'] ? '<span class="dash">&ndash;</span>' : ppMark($r['total'])) . '</b></td>'
                             . '<td>' . ($r['fail'] ? '<span class="gb g-none">-</span>' : ppBadge($r['grade'])) . '</td></tr>';
                      }
                      echo '<tr class="tot"><td></td><td class="l">Total</td>';
                      foreach ($ppCols as $col) {
                          $ct = 0;
                          foreach ($col['ix'] as $k) { $ct += isset($colTot[$exams[$k]['exam_id']]) ? $colTot[$exams[$k]['exam_id']] : 0; }
                          echo '<td>' . ppMark($ct) . '</td>';
                      }
                      echo '<td>' . ppMark($gtt) . ' / ' . ppNum($maxTotal) . '</td><td>' . ppBadge($overall) . '</td></tr>';
                  } ?>
            </tbody>
        </table>

<?php if ($addRows || $extraRows) { ?>
        <div class="sec"><span class="no"><?php echo ++$secNo; ?></span><h3>Additional Subjects</h3></div>
        <?php
        $addTable = '';
        if ($addRows) {
            if (!$addHeads) { $addHeads = array('Marks'); }
            $addTable = '<table class="grid"><thead><tr><th style="text-align:left;padding-left:10px;">Subject</th>';
            foreach ($addHeads as $h) { $addTable .= '<th>' . CHtml::encode($h) . '</th>'; }
            $addTable .= '<th>Total</th><th style="width:16%;">Grade</th></tr></thead><tbody>';
            foreach ($addRows as $ar) {
                $addTable .= '<tr><td class="l">' . CHtml::encode($ar['name']) . '</td>';
                for ($k = 0; $k < count($addHeads); $k++) {
                    $p = isset($ar['parts'][$k]) ? $ar['parts'][$k]['mark'] : null;
                    $addTable .= '<td>' . ($p === null ? '<span class="dash">&ndash;</span>' : ($p === 'AB' ? '<span class="ab">AB</span>' : ppNum($p))) . '</td>';
                }
                $addTable .= '<td><b>' . ($ar['total'] === null ? '<span class="dash">&ndash;</span>' : ppNum($ar['total']) . ($ar['max'] > 0 ? ' / ' . ppNum($ar['max']) : '')) . '</b></td>'
                           . '<td>' . ppBadge($ar['grade']) . '</td></tr>';
            }
            $addTable .= '</tbody></table>';
        }
        $extraTable = '';
        if ($extraRows) {
            $extraTable = '<table class="grid"><thead><tr><th style="text-align:left;padding-left:10px;">Subject</th><th style="width:34%;">Grade</th></tr></thead><tbody>';
            foreach ($extraRows as $xr) {
                $extraTable .= '<tr><td class="l">' . CHtml::encode($xr['name']) . '</td><td>' . ppBadge($xr['grade']) . '</td></tr>';
            }
            $extraTable .= '</tbody></table>';
        }
        if ($addTable && $extraTable) { echo '<div class="two"><div class="a">' . $addTable . '</div><div class="b">' . $extraTable . '</div></div>'; }
        else { echo $addTable . $extraTable; }
        ?>
<?php } ?>

        <div class="sec"><span class="no"><?php echo ++$secNo; ?></span><h3>Co-Scholastic Areas</h3></div>
        <?php
        $ppBox = function ($box, $pad) {
            $h = '<table class="grid"><thead><tr><th style="text-align:left;padding-left:10px;">' . CHtml::encode($box['name'])
               . '</th><th style="width:34%;">Grade</th></tr></thead><tbody>';
            foreach ($box['rows'] as $r) {
                /* the area's name as the school set it up - re-casing it mangles the ones carrying a
                   bracketed list, e.g. "Health and Physical Education {Sports,Martial,Yoga,NCC,etc}" */
                $nm = ppAreaName($r['name']);
                $h .= '<tr><td class="l">' . CHtml::encode($nm) . '</td><td>' . ppBadge($r['grade']) . '</td></tr>';
            }
            for ($k = count($box['rows']); $k < $pad; $k++) { $h .= '<tr><td class="l">&nbsp;</td><td></td></tr>'; }
            return $h . '</tbody></table>';
        };
        if (!$coBoxes) { echo '<table class="grid"><tbody><tr><td colspan="2" style="color:#b00;">No co-scholastic areas mapped to this class</td></tr></tbody></table>'; }
        elseif (count($coBoxes) === 1) { $one = reset($coBoxes); echo $ppBox($one, 0); }
        else {
            $pair = array_slice($coBoxes, 0, 2, true);   /* the card has room for two boxes across */
            echo '<div class="two">';
            $side = 'a';
            foreach ($pair as $bx) { echo '<div class="' . $side . '">' . $ppBox($bx, $coTallest) . '</div>'; $side = 'b'; }
            echo '</div>';
            if (count($coBoxes) > 2) { echo '<div class="chk-note">More co-scholastic groups are mapped to this class than the card prints.</div>'; }
        }
        ?>

<?php if ($bands) { ?>
        <div class="sec"><span class="no"><?php echo ++$secNo; ?></span><h3>Grading Scale</h3><?php if ($wsum > 0) { ?><span class="sub">marks out of <?php echo ppNum($wsum); ?></span><?php } ?></div>
        <div class="key"><b>Marks range &rarr; grade</b>
            <?php foreach ($bands as $b) { echo '<span>' . ppBadge($b['grade_desc']) . ' ' . ppNum($b['f']) . '&ndash;' . ppNum($b['t']) . '</span>'; } ?>
        </div>
<?php } ?>

<?php if ($paRows) { ?>
        <div class="sec"><span class="no"><?php echo ++$secNo; ?></span><h3>Performance Analysis</h3><span class="sub">subject-wise &middot; out of <?php echo ppNum($wsum); ?></span></div>
        <div class="pa">
            <div class="pa-y"><?php for ($k = 4; $k >= 0; $k--) { echo '<span style="top:' . ((4 - $k) * 25) . '%">' . ppNum(round($wsum * $k / 4, 1)) . '</span>'; } ?></div>
            <div class="pa-plot">
                <div class="pa-grid"><?php for ($k = 0; $k <= 4; $k++) { echo '<i style="top:' . ($k * 25) . '%"></i>'; } ?></div>
                <div class="pa-cols">
                <?php foreach ($paRows as $p) {
                          echo '<div class="pa-col"><div class="pa-bars">';
                          foreach (array(array('h', 'hi'), array('a', 'av'), array('m', 'me')) as $b) {
                              $v = $p[$b[1]];
                              if ($v === null) { echo '<div class="pa-b ' . $b[0] . ' none"><b>&ndash;</b></div>'; continue; }
                              $pc = ($v / $wsum) * 100;
                              if ($pc > 100) { $pc = 100; }
                              if ($pc < 1.5) { $pc = 1.5; }   /* a hairline so an empty bar still sits on the axis */
                              echo '<div class="pa-b ' . $b[0] . '" style="height:' . round($pc, 1) . '%"><b>' . ppNum(round($v, 1)) . '</b></div>';
                          }
                          echo '</div><div class="pa-lbl">' . CHtml::encode($p['name']) . '</div></div>';
                      } ?>
                </div>
            </div>
        </div>
        <div class="pa-key"><span class="sw h"></span>Highest in class &nbsp; <span class="sw a"></span>Class average &nbsp; <span class="sw m"></span>This student</div>
<?php } ?>

        <div class="kpi">
            <div><div><b><?php echo $maxTotal > 0 ? ppNum($maxTotal) : '&ndash;'; ?></b><small>Total Marks</small></div></div>
            <div><div><b><?php echo $sub_cnt ? ppMark($gtt) : '&ndash;'; ?></b><small>Marks Obtained</small></div></div>
            <div><div><b><?php echo $percentage === null ? '&ndash;' : ppNum($percentage) . ' %'; ?></b><small>Percentage</small></div></div>
            <div><div><b><?php echo ppBadge($overall); ?></b><small>Overall Grade</small></div></div>
        </div>

        <div class="att<?php echo $ppShowPtm ? '' : ' att1'; ?>">
            <div><div><small>Attendance</small><?php echo CHtml::encode($term1_att); ?></div></div>
            <?php if ($ppShowPtm) { ?><div><div><small>PTM Attended</small><?php echo CHtml::encode($ptm); ?></div></div><?php } ?>
        </div>
        <div class="rem"><small>Remarks</small><?php echo CHtml::encode($term1_remarks); ?></div>

        <div class="sig">
            <div><div class="ln">Class Incharge</div><?php if (trim((string) $incharge) !== '') { echo '<div class="nm">' . CHtml::encode($incharge) . '</div>'; } ?></div>
            <div><div class="ln">Parent's Signature</div></div>
            <div><div class="ln">Principal</div></div>
        </div>
        <div class="foot"><?php echo CHtml::encode(rtrim(trim((string) $school['school_name']), ',')); ?> &middot; Session <?php echo CHtml::encode($session); ?> &middot; <?php echo CHtml::encode($ppExamLine); ?></div>
    </div>
</div>
<?php } /* foreach pupil */ ?>
</div>
<?php Yii::app()->user->setState('student_ids', array()); ?>
<?php } /* pupils chosen */ ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo Yii::app()->request->baseUrl; ?>/theme/plugins/jquery-1.10.2.min.js" type="text/javascript"></script>
<script type="text/javascript">
    window.addEventListener("load", function () { if (document.getElementById('standard')) { document.getElementById('standard').disabled = false; } }, false);

    jQuery(document).ready(function () {
        $('ul.page-sidebar-menu li').removeClass('active');
        $('#co-sch').addClass('active');
        $('ul.page-sidebar-menu li a span.selected').remove();
        $('#co-sch a').append('<span class="selected"></span>');
        $('ul.page-sidebar-menu  li ul.sub-menu li').removeClass('active');
        $('#co-sch ul.sub-menu li#<?php echo $ppMenuItem; ?>').addClass('active');
        $('#new_report_cards').addClass('open active');
    });

    $('#standard').change(function (event) {
        var standard = $('#standard').val();
        $('#loader').html('<image src="/images/ajax-loader.gif">');
        $.ajax({
            url: '<?php echo Yii::app()->baseUrl; ?>/index.php/studentDetails/getSection',
            type: 'POST',
            data: { 'standard': standard },
            success: function (msg) { $('#section').html(msg); $('#loader').html(''); },
            error: function (msg) { alert(msg); }
        });
    });

    $('#gen_rc').click(function (event) {
        var standard = $('#standard').val();
        var section = $('#section').val();
        if (standard == null) { return false; }
        if (standard != '' && section == '') {
            window.open('<?php echo Yii::app()->baseUrl; ?>/index.php/reportcard/getStusForRC1to2?standard=' + standard + '&page=<?php echo $ppPage; ?>', '_blank');
        }
        if (standard != '' && section != '') {
            window.open('<?php echo Yii::app()->baseUrl; ?>/index.php/reportcard/getStusForRC1to2?standard=' + standard + '&section=' + section + '&page=<?php echo $ppPage; ?>', '_blank');
        }
    });

    /* print: the cards travel with their own stylesheet, and the #print_condent wrapper goes too -
       the colour-locked rules are scoped to it. */
    $('#print').click(function () {
        var css = '';
        $('style').each(function () { css += $(this).html(); });
        var inner = $('#print_condent').html();
        var w = window.open('', '', 'height=700,width=1000,scrollbars=1,resizable=1');
        w.document.write('<html><head><title>Report Card</title>'
            + '<style type="text/css">html,body{font-family:"Segoe UI",Calibri,Arial;margin:0;padding:0;background:#fff} @page{margin:5mm}</style>'
            + '<style type="text/css">' + css + '</style></head><body><div id="print_condent">' + inner + '</div></body></html>');
        w.document.close();
        setTimeout(function () { w.focus(); w.print(); }, 900);
    });
</script>
