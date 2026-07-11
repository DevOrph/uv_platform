<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ob_start();
require_once '../includes/db_connect.php';
require_once '../includes/grade_calculator.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Supprime les suffixes de groupe entre parenthèses
// Ex: "Algèbre 1 (groupe 5)" → "Algèbre 1"
// Préserve les autres parenthèses : "Licence 3 GI (big data)" reste intact
function removeGroupSuffix(string $name): string {
    return trim(preg_replace('/\s*\(groupe\s*\d+\)\s*/i', '', $name));
}

$classId  = $_GET['class_id']  ?? null;
$studentId = $_GET['student_id'] ?? null;
$periodId  = $_GET['period_id']  ?? null;

if ($classId && $studentId && $periodId) {
 
    try {
        // Vérification table teaching_units
        $checkTables = $conn->query("SHOW TABLES LIKE 'teaching_units'");
        if ($checkTables->num_rows == 0) {
            die("Erreur : La table 'teaching_units' n'existe pas. Veuillez exécuter migration_bulletin.sql");
        }

        // Configuration
        $configQuery = $conn->query("SELECT * FROM bulletin_config LIMIT 1");
        $config = $configQuery ? $configQuery->fetch_assoc() : null;

        if (!$config) {
            $conn->query("INSERT INTO bulletin_config (school_name, ministry_name, union_name, location) VALUES 
                ('UNIVERSITÉ POLYTECHNIQUE', 
                 'Ministère de l\\'Enseignement Supérieur, et de la Recherche Scientifique',
                 'Union-Travail-Justice',
                 'Libreville')");
            $config = $conn->query("SELECT * FROM bulletin_config LIMIT 1")->fetch_assoc();
        }

        // Colonnes disponibles dans users
        $columnsQuery = $conn->query("SHOW COLUMNS FROM users");
        $columns = [];
        while ($row = $columnsQuery->fetch_assoc()) {
            $columns[] = $row['Field'];
        }

        $selectFields = ['name', 'id'];
        if (in_array('matricule',     $columns)) $selectFields[] = 'matricule';
        if (in_array('date_of_birth', $columns)) $selectFields[] = 'date_of_birth';
        if (in_array('place_of_birth',$columns)) $selectFields[] = 'place_of_birth';

        // Étudiant
        $studentQuery = $conn->prepare("SELECT " . implode(', ', $selectFields) . " FROM users WHERE id = ?");
        $studentQuery->bind_param("s", $studentId);
        $studentQuery->execute();
        $student = $studentQuery->get_result()->fetch_assoc();
        if (!$student) die("Erreur : Étudiant non trouvé.");

        $studentName = $student['name'];
        $matricule   = $student['matricule']     ?? $student['id'];
        $dateOfBirth = $student['date_of_birth'] ?? '';
        $placeOfBirth= $student['place_of_birth']?? '';

        // Classe
        $classQuery = $conn->prepare("SELECT name FROM classes WHERE id = ?");
        $classQuery->bind_param("s", $classId);
        $classQuery->execute();
        $classResult = $classQuery->get_result()->fetch_assoc();
        if (!$classResult) die("Erreur : Classe non trouvée.");
        // Supprimer le suffixe de groupe entre parenthèses
        // Ex: "Licence 1 Génie Informatique (groupe 2)" → "Licence 1 Génie Informatique"
        $className = removeGroupSuffix($classResult['name']);

        // Période (nom + année académique)
        $periodQuery = $conn->prepare("SELECT name, school_year FROM evaluation_periods WHERE id = ?");
        $periodQuery->bind_param("i", $periodId);
        $periodQuery->execute();
        $periodResult = $periodQuery->get_result()->fetch_assoc();
        if (!$periodResult) die("Erreur : Période non trouvée.");
        $periodName      = $periodResult['name'];
        $periodSchoolYear = $periodResult['school_year'] ?? '';

        // ── Calcul des notes via grade_calculator.php ────────────────────────
        $calcResult = calculate_student_semester_grades(
            $conn, $studentId, $classId, (int)$periodId
        );

        if (empty($calcResult['ues'])) {
            die("Erreur : Aucun cours configuré pour cette classe et cette période.");
        }

        // Conversion vers le format attendu par le template HTML
        $allGrades = [];
        foreach ($calcResult['ues'] as $ue) {
            $courses = [];
            foreach ($ue['modules'] as $mod) {
                $noteNorm = round($mod['note_cc'] * 0.4 + $mod['note_exa'] * 0.6, 2);
                $courses[] = [
                    'name'             => removeGroupSuffix($mod['name']),
                    'credits'          => $mod['credit'],
                    'note_cc'          => $mod['note_cc'],
                    'note_exa'         => $mod['note_exa'],
                    'note_rattrapage'  => $mod['note_rattrapage'],
                    'note_normale'     => $noteNorm,
                    'note_finale'      => $mod['note_finale'],
                    'rattrapage_retenu'=> $mod['note_rattrapage'] !== null && $mod['note_rattrapage'] > $noteNorm,
                    'validated'        => $mod['note_finale'] >= 10,
                    'eliminatoire'     => $mod['eliminatoire'],
                ];
            }
            $allGrades[] = [
                'code'             => $ue['code'],
                'name'             => $ue['name'],
                'short_name'       => $ue['short_name'],
                'courses'          => $courses,
                'total_ects'       => $ue['credit_total'],
                'moyenne'          => $ue['moy_ue'],
                'validated'        => $ue['valide'],
                'has_eliminatoire' => $ue['has_eliminatoire'],
            ];
        }

        $generalAverage        = $calcResult['moy_generale'];
        $totalCreditsRequired  = $calcResult['credits_total'];
        $totalCreditsValidated = $calcResult['credits_obtenus'];
        $semestreValide        = ($totalCreditsRequired > 0 && $totalCreditsValidated >= $totalCreditsRequired);

        if ($generalAverage < 10) {
            $mention = "Insuffisant";
        } elseif ($generalAverage < 12) {
            $mention = "Passable";
        } elseif ($generalAverage < 14) {
            $mention = "Assez Bien";
        } elseif ($generalAverage < 16) {
            $mention = "Bien";
        } else {
            $mention = "Très Bien";
        }

        // Couleurs
        $moyenneColor = $semestreValide ? '#1a7a1a' : '#d32f2f';
        $creditColor  = $semestreValide ? '#1a7a1a' : '#d32f2f';

        // Rattrapage retenu sur au moins un module ?
        $hasAnyRattrapage = false;
        foreach ($allGrades as $_u) {
            foreach ($_u['courses'] as $_c) {
                if ($_c['rattrapage_retenu']) { $hasAnyRattrapage = true; break 2; }
            }
        }

        // ── Calcul dynamique de la taille de police ──────────────────────────
        // Pour chaque UE : 1 entête + N cours + 1 total + 1 module
        $totalRows = 0;
        foreach ($allGrades as $unit) {
            $totalRows += 1 + count($unit['courses']) + 1 + 1;
        }
        $totalRows += 1;  // TOTAL GÉNÉRAL
        $totalRows += 5;  // en-tête étudiant
        $totalRows += 6;  // résumé + footer + bas de page

        if ($totalRows <= 40) {
            $baseFontSize  = 9;    $smallFontSize = 8;    $tinyFontSize = 7.5;
            $rowHeight = 16; $headerSize = 12; $titleSize = 11;
            $bodyPadding = '8mm 12mm'; $sectionMargin = '5px'; $cellPadding = '2px 4px';
        } elseif ($totalRows <= 50) {
            $baseFontSize  = 8.5;  $smallFontSize = 7.5;  $tinyFontSize = 7;
            $rowHeight = 14; $headerSize = 11; $titleSize = 10;
            $bodyPadding = '6mm 12mm'; $sectionMargin = '4px'; $cellPadding = '2px 4px';
        } elseif ($totalRows <= 60) {
            $baseFontSize  = 7.5;  $smallFontSize = 7;    $tinyFontSize = 6.5;
            $rowHeight = 13; $headerSize = 10; $titleSize = 9.5;
            $bodyPadding = '4mm 10mm'; $sectionMargin = '3px'; $cellPadding = '1px 3px';
        } elseif ($totalRows <= 72) {
            $baseFontSize  = 7;    $smallFontSize = 6.5;  $tinyFontSize = 6;
            $rowHeight = 12; $headerSize = 9.5; $titleSize = 9;
            $bodyPadding = '3mm 10mm'; $sectionMargin = '2px'; $cellPadding = '1px 3px';
        } else {
            $baseFontSize  = 6.5;  $smallFontSize = 6;    $tinyFontSize = 5.5;
            $rowHeight = 11; $headerSize = 9; $titleSize = 8.5;
            $bodyPadding = '2mm 8mm'; $sectionMargin = '1px'; $cellPadding = '1px 2px';
        }

        // ── Génération PDF ──────────────────────────────────────────────────────
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled',      true);
        $options->set('isPhpEnabled',         true);
        $dompdf = new Dompdf($options);

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size: A4 landscape; margin: 14mm 16mm 12mm 16mm; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DejaVu Sans',Arial,sans-serif; font-size:7pt; color:#000; }

/* ─── Header ─── */
.hdr   { width:100%; border-collapse:collapse; margin-bottom:5px; }
.hdr td { border:none; padding:4px 6px; vertical-align:middle; }
.logo-cell  { width:16%; text-align:center; }
.logo-cell img { max-height:72px; }
.school-cell { width:44%; padding-left:8px; }
.school-name { font-size:9.5pt; font-weight:bold; color:#1565C0; }
.school-sub  { font-size:6.5pt; color:#444; margin-top:3px; line-height:1.5; }
.title-cell  { width:40%; text-align:center; }
.doc-title   { font-size:11pt; font-weight:bold; color:#1565C0; }
.period-lbl  { font-size:9pt; font-weight:bold; color:#1565C0; margin-top:2px; }
.acad-year   { font-size:8.5pt; font-weight:bold; color:#c0392b; margin-top:3px; }
.divider     { height:2px; background:#2196F3; margin:5px 0 6px 0; }

/* ─── Student info ─── */
.info-t  { width:100%; border-collapse:collapse; margin-bottom:6px; font-size:6.8pt; }
.info-t td { border:1px solid #90CAF9; padding:5px 5px; height:22px; }
.ilbl { background:#E3F2FD; font-weight:bold; white-space:nowrap; }

/* ─── Grades table (flat, no thead/tbody for reliable rowspan) ─── */
.gt    { width:100%; border-collapse:collapse; }
.gt td { border:1px solid #90CAF9; padding:3px 3px; vertical-align:middle; text-align:center; font-size:6.5pt; }

.th1 { background:#2196F3; color:#fff; font-weight:bold; font-size:6.3pt; padding:5px 2px; }
.th2 { background:#1976D2; color:#fff; font-weight:bold; font-size:6pt; padding:4px 2px; }

/* SEMESTRE vertical column */
.sem-td {
    background:#2196F3; color:#fff; font-weight:bold;
    font-size:6pt; width:2%; text-align:center; vertical-align:middle;
    letter-spacing:1px; padding:2px;
}
/* UE group cell */
.ue-td {
    background:#E3F2FD; font-weight:bold; font-size:6.5pt;
    text-align:left; padding:3px 5px; vertical-align:middle; width:14%;
}
/* Module name cell */
.mod-td { text-align:left; padding-left:6px; font-size:6.5pt; width:18%; }

.n-td  { width:6.5%; }
.c-td  { width:4.5%; }
.d-td  { width:9%; font-weight:bold; }

.valide     { color:#1a7a1a; }
.non-valide { color:#c0392b; }
.elim-bg    { background:#fdecea; color:#c0392b; }
.row-a      { background:#F5FBFF; }
.row-b      { background:#ffffff; }
.total-row  { background:#BBDEFB; font-weight:bold; font-size:7pt; }

/* ─── Footer — fixed at bottom of page ─── */
.footer-section {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
}
.ft   { width:100%; border-collapse:collapse; font-size:6.8pt; }
.ft td { border:1px solid #90CAF9; padding:7px 10px; vertical-align:top; }
.contact { font-size:5.8pt; color:#555; text-align:center; margin-top:3px; border-top:1px solid #90CAF9; padding-top:2px; }
</style>
</head>
<body>

<!-- ═══ HEADER ═══ -->
<table class="hdr">
<tr>
  <td class="logo-cell">
    <?php $lp = __DIR__.'../api/assets/images/logo_ismm.jpg';
    if (file_exists($lp)) echo '<img src="file://'.$lp.'" alt="Logo">'; ?>
  </td>
  <td class="school-cell">
    <div class="school-name"><?php echo htmlspecialchars($config['school_name'] ?? 'UNIVERSITÉ POLYTECHNIQUE'); ?></div>
    <div class="school-sub">
      <?php echo htmlspecialchars($config['union_name'] ?? 'Union-Travail-Justice'); ?><br>
      <?php echo htmlspecialchars($config['ministry_name'] ?? "Ministère de l'Enseignement Supérieur"); ?>
    </div>
  </td>
  <td class="title-cell">
    <div class="doc-title">RELEVÉ DE NOTES</div>
    <div class="period-lbl"><?php echo htmlspecialchars(mb_strtoupper($periodName)); ?></div>
    <?php $ay = $periodSchoolYear ? str_replace('-', ' – ', $periodSchoolYear) : ''; ?>
    <div class="acad-year">Année Académique <?php echo htmlspecialchars($ay); ?></div>
  </td>
</tr>
</table>
<div class="divider"></div>

<!-- ═══ INFO ÉTUDIANT ═══ -->
<table class="info-t">
<tr>
  <td class="ilbl" style="width:9%">Etudiant(e)</td>
  <td colspan="3" style="width:27%;font-weight:bold;"><?php echo htmlspecialchars($studentName); ?></td>
  <td class="ilbl" style="width:11%">Date de naissance</td>
  <td style="width:12%"><?php echo htmlspecialchars($dateOfBirth ?: '—'); ?></td>
  <td class="ilbl" style="width:9%">Nationalité</td>
  <td style="width:10%">—</td>
  <td class="ilbl" style="width:8%">Matricule</td>
  <td style="width:14%"><?php echo htmlspecialchars($matricule); ?></td>
</tr>
<tr>
  <td class="ilbl">Inscrit(e) en</td>
  <td colspan="3"><?php echo htmlspecialchars($periodName); ?></td>
  <td class="ilbl">Classe</td>
  <td><?php echo htmlspecialchars($className); ?></td>
  <td class="ilbl">Option</td>
  <td><?php echo htmlspecialchars($student['option'] ?? '—'); ?></td>
  <td class="ilbl">Redoublant</td>
  <td><?php echo htmlspecialchars($student['redoublant'] ?? 'non'); ?></td>
</tr>
</table>

<!-- ═══ TABLEAU DES NOTES ═══ -->
<?php
$totalCourseRows = 0;
foreach ($allGrades as $u) $totalCourseRows += max(1, count($u['courses']));
$totalTableRows = 2 /* 2 header rows */ + $totalCourseRows + 1 /* total row */;

// Vertical semestre label: one character per line
$semChars = preg_split('//u', mb_strtoupper($periodName), -1, PREG_SPLIT_NO_EMPTY);
$semVert  = implode('<br>', array_map('htmlspecialchars', $semChars));
?>
<table class="gt">

<!-- Row 1 : main column headers -->
<tr>
  <td rowspan="<?php echo $totalTableRows; ?>" class="sem-td"><?php echo $semVert; ?></td>
  <td colspan="2" class="th1" style="width:32%">Unités d'Enseignement (UE)</td>
  <td rowspan="2" class="th1 c-td">Crd</td>
  <td colspan="3" class="th1">Session normale</td>
  <td rowspan="2" class="th1 n-td" style="color:#90ee90">Rattr.</td>
  <td rowspan="2" class="th1 n-td">Note<br>finale</td>
  <td rowspan="2" class="th1 d-td">Moy UE<br>Décision</td>
</tr>

<!-- Row 2 : sub-column headers -->
<tr>
  <td class="th2 ue-td">Intitulé</td>
  <td class="th2 mod-td">Modules — Intitulé</td>
  <td class="th2 n-td">CC</td>
  <td class="th2 n-td">EXA</td>
  <td class="th2 n-td">Moy</td>
</tr>

<!-- Data rows -->
<?php
$isFirst = true; $rowIdx = 0;
foreach ($allGrades as $unit):
  $nc  = max(1, count($unit['courses']));
  $dec = $unit['validated'] ? 'Validé' : 'Non validé';
  $dcl = $unit['validated'] ? 'valide' : 'non-valide';
?>
<?php foreach ($unit['courses'] as $ci => $course):
  $hasRatt    = $course['note_rattrapage'] !== null;
  $rattRetenu = $course['rattrapage_retenu'];
  $rc = ($rowIdx % 2 === 0) ? 'row-a' : 'row-b'; $rowIdx++;
?>
<tr class="<?php echo $rc; ?>">
  <?php if ($ci === 0): ?>
  <td rowspan="<?php echo $nc; ?>" class="ue-td">
    <?php if (!empty($unit['code'])): ?><strong><?php echo htmlspecialchars($unit['code']); ?></strong><br><?php endif; ?>
    <?php echo htmlspecialchars($unit['name']); ?>
  </td>
  <?php endif; ?>
  <td class="mod-td <?php echo $course['eliminatoire'] ? 'elim-bg' : ''; ?>">
    <?php echo htmlspecialchars($course['name']); ?>
    <?php if ($course['eliminatoire']): ?><br><span style="font-size:5pt;background:#c0392b;color:#fff;padding:0 3px;border-radius:2px">Élim.</span><?php endif; ?>
  </td>
  <td class="c-td"><?php echo $course['credits']; ?></td>
  <td class="n-td"><?php echo number_format($course['note_cc'],  2, ',', ''); ?></td>
  <td class="n-td"><?php echo number_format($course['note_exa'], 2, ',', ''); ?></td>
  <td class="n-td <?php echo $course['eliminatoire'] ? 'elim-bg' : ''; ?>">
    <?php echo number_format($course['note_normale'], 2, ',', ''); ?>
  </td>
  <td class="n-td" style="<?php echo $hasRatt ? 'color:#1a7a1a;font-weight:bold' : 'color:#bbb'; ?>">
    <?php echo $hasRatt ? number_format($course['note_rattrapage'], 2, ',', '') : '—'; ?>
  </td>
  <td class="n-td <?php echo $course['eliminatoire'] ? 'elim-bg' : ''; ?>" style="font-weight:bold">
    <?php echo number_format($course['note_finale'], 2, ',', ''); ?>
    <?php if ($rattRetenu): ?><sup style="color:#1a7a1a;font-size:5.5pt">*</sup><?php endif; ?>
  </td>
  <?php if ($ci === 0): ?>
  <td rowspan="<?php echo $nc; ?>" class="d-td <?php echo $dcl; ?>" style="vertical-align:middle;">
    <?php echo number_format($unit['moyenne'], 2, ',', ''); ?><br>
    <span style="font-size:5.5pt"><?php echo $dec; ?></span><br>
    <span style="font-size:5pt;color:<?php echo $unit['validated'] ? '#1a7a1a' : '#c0392b'; ?>">
      <?php echo ($unit['validated'] ? round($unit['total_ects']) : 0); ?> cr.
    </span>
  </td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>

<!-- TOTAL GÉNÉRAL -->
<?php $genDec=$semestreValide?'Validé':'Non validé'; $genCls=$semestreValide?'valide':'non-valide'; ?>
<tr class="total-row">
  <td colspan="2" class="th1" style="text-align:right; padding-right:6px; font-size:6.5pt;">TOTAL GÉNÉRAL</td>
  <td class="c-td"><?php echo round($totalCreditsRequired); ?></td>
  <td colspan="2" style="color:#bbb; text-align:center; font-size:6pt">—</td>
  <td class="n-td" style="font-size:7.5pt; color:<?php echo $moyenneColor; ?>; font-weight:bold;">
    <?php echo number_format($generalAverage, 2, ',', ''); ?>
  </td>
  <td class="n-td" style="color:#bbb">—</td>
  <td class="n-td" style="font-weight:bold; color:<?php echo $creditColor; ?>">
    <?php echo round($totalCreditsValidated); ?>/<?php echo round($totalCreditsRequired); ?>
  </td>
  <td class="d-td <?php echo $genCls; ?>"><?php echo $genDec; ?></td>
</tr>

</table>
<?php if ($hasAnyRattrapage): ?>
<div style="font-size:5.5pt;color:#1a7a1a;margin-top:2px;">* Note de rattrapage retenue pour le calcul</div>
<?php endif; ?>

<!-- ═══ FOOTER ═══ -->
<table class="ft">
<tr>
  <td style="width:58%; background:#E3F2FD;">
    <strong>Crédits validés :</strong> <span style="color:<?php echo $creditColor; ?>;font-weight:bold"><?php echo round($totalCreditsValidated); ?> / <?php echo round($totalCreditsRequired); ?></span>
    &nbsp;&nbsp;
    <strong>Décision finale :</strong>
    <span class="<?php echo $genCls; ?>"><strong><?php echo $semestreValide ? 'Semestre validé' : 'Semestre non validé'; ?></strong></span>
    &nbsp;&nbsp;
    <strong>Mention :</strong> <?php echo $mention; ?>
    &nbsp;&nbsp;
    <strong>Absences non justifiées :</strong> 0
    <?php $uenv=array_filter($allGrades,fn($u)=>!$u['validated']);
    if (!empty($uenv)): ?>
    <br><em style="font-size:6pt; color:#c0392b;">UE non validée(s) :
    <?php foreach($uenv as $u): ?>
      <?php echo htmlspecialchars($u['code']?:$u['name']); ?>
      (<?php echo number_format($u['moyenne'],2,',',''); ?>/20<?php echo $u['has_eliminatoire']?', note éliminatoire':''; ?>)
    <?php endforeach; ?>
    </em>
    <?php endif; ?>
  </td>
  <td style="width:42%; text-align:center;">
    <strong><?php echo htmlspecialchars($config['location'] ?? 'Libreville'); ?>, le <?php echo date('d/m/Y'); ?></strong>
    <br><br>
    <?php echo htmlspecialchars($config['signature_title'] ?? 'Le Directeur(trice) Général(e)'); ?>
    <br><br><br>
    <strong><?php echo htmlspecialchars($config['signature_name'] ?? ''); ?></strong>
  </td>
</tr>
</table>

<div class="contact">
  <?php echo htmlspecialchars($config['school_name'] ?? ''); ?>
  <?php if (!empty($config['website']??'')): ?> — Site web : <?php echo htmlspecialchars($config['website']); ?><?php endif; ?>
  <?php if (!empty($config['email']??'')): ?> — Email : <?php echo htmlspecialchars($config['email']); ?><?php endif; ?>
  <?php if (!empty($config['phone']??'')): ?> — Tél : <?php echo htmlspecialchars($config['phone']); ?><?php endif; ?>
  <?php if (!empty($config['address']??'')): ?> — <?php echo htmlspecialchars($config['address']); ?><?php endif; ?>
</div>

</body>
</html>
        <?php
        $html = ob_get_clean();

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = "bulletin_"
            . preg_replace('/[^a-z0-9]/i', '_', $studentName)
            . "_" . date('Ymd') . ".pdf";

        ob_end_clean();
        $dompdf->stream($filename, ["Attachment" => false]);

    } catch (Exception $e) {
        echo "<h2>Erreur</h2><p>" . htmlspecialchars($e->getMessage()) . "</p>";
        error_log("Erreur bulletin : " . $e->getMessage());
    }

} else {
    echo "<h2>Paramètres manquants</h2><ul>";
    echo "<li>class_id : "  . ($classId   ? "✓" : "✗") . "</li>";
    echo "<li>student_id : ". ($studentId  ? "✓" : "✗") . "</li>";
    echo "<li>period_id : " . ($periodId   ? "✓" : "✗") . "</li>";
    echo "</ul>";
}
?>