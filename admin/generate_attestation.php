<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ob_start();

require_once '../includes/db_connect.php';
require_once '../includes/db_pdo.php';
require_once '../includes/attestation_helpers.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    ob_end_clean();
    header('Location: ../pages/login.html');
    exit();
}

$adminId = (int)$_SESSION['user_id'];

// Vérifier une fois si les nouvelles colonnes existent (évite les try/catch imbriqués)
$stCols = $pdo->query("SHOW COLUMNS FROM attestations LIKE 'mode'");
$hasNewCols = ($stCols && $stCols->rowCount() > 0);

// ══════════════════════════════════════════════════════════════════════════
// MODE A — Retéléchargement d'une attestation existante (GET ?attestation_id=X)
// ══════════════════════════════════════════════════════════════════════════
if (isset($_GET['attestation_id'])) {
    $attId = (int)$_GET['attestation_id'];

    $stAttest = $pdo->prepare("SELECT * FROM attestations WHERE id = ?");
    $stAttest->execute([$attId]);
    $attest = $stAttest->fetch();
    if (!$attest) {
        ob_end_clean();
        http_response_code(404);
        die('<h2>Attestation introuvable (id=' . $attId . ').</h2>');
    }

    $stStudent = $pdo->prepare(
        "SELECT id, name, birth_date, place_of_birth FROM users WHERE id = ? LIMIT 1"
    );
    $stStudent->execute([$attest['student_id']]);
    $student = $stStudent->fetch();
    if (!$student) {
        ob_end_clean();
        die('<h2>Étudiant introuvable.</h2>');
    }

    $periodLabel = '';
    if ($hasNewCols && ($attest['mode'] ?? '') === 'semestre' && !empty($attest['evaluation_period_id'])) {
        $stP = $pdo->prepare("SELECT name FROM evaluation_periods WHERE id = ? LIMIT 1");
        $stP->execute([$attest['evaluation_period_id']]);
        $periodLabel = $stP->fetchColumn() ?: '';
    }

    $config = $pdo->query("SELECT * FROM bulletin_config LIMIT 1")->fetch() ?: [];

    ob_end_clean();
    generateAndStreamPDF($pdo, $attest, $student, $config, $periodLabel);
    exit();
}

// ══════════════════════════════════════════════════════════════════════════
// MODE B — Nouvelle génération (POST depuis attestations.php)
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    header('Location: attestations.php');
    exit();
}

$studentId       = trim($_POST['student_id']        ?? '');
$anneeAcad       = trim($_POST['annee_academique']  ?? '');
$filiere         = trim($_POST['filiere']           ?? '');
$typeDiplome     = trim($_POST['type_diplome']      ?? 'LICENCE PROFESSIONNELLE');
$promotion       = trim($_POST['promotion']         ?? $anneeAcad);
$dateEmissionRaw = trim($_POST['date_emission']     ?? date('d/m/Y'));
$mode            = in_array($_POST['mode'] ?? '', ['annee_complete', 'semestre'])
                   ? $_POST['mode'] : 'annee_complete';
$periodId        = (int)($_POST['period_id'] ?? 0);

error_log('[generate_attestation] POST : student=' . $studentId . ' annee=' . $anneeAcad
    . ' filiere=' . $filiere . ' mode=' . $mode . ' period=' . $periodId
    . ' hasNewCols=' . ($hasNewCols ? 'yes' : 'no'));

if (!$studentId || !$anneeAcad || !$filiere) {
    $dbg = 'student_id=' . $studentId . ' | annee=' . $anneeAcad . ' | filiere=' . $filiere;
    error_log('[generate_attestation] Paramètres manquants : ' . $dbg);
    ob_end_clean();
    die('<h2>Paramètres manquants.</h2><pre>' . htmlspecialchars($dbg) . '</pre>
         <p><a href="attestations.php">← Retour</a></p>');
}
if ($mode === 'semestre' && !$periodId) {
    ob_end_clean();
    die('<h2>Paramètre manquant : period_id requis pour le mode semestre.</h2>
         <p><a href="attestations.php">← Retour</a></p>');
}

$dateEmission = DateTime::createFromFormat('d/m/Y', $dateEmissionRaw);
$dateEmission = $dateEmission ? $dateEmission->format('Y-m-d') : date('Y-m-d');

$stStudent = $pdo->prepare(
    "SELECT id, name, birth_date, place_of_birth, class_id FROM users WHERE id = ? AND role='student' LIMIT 1"
);
$stStudent->execute([$studentId]);
$student = $stStudent->fetch();
if (!$student) {
    ob_end_clean();
    die('<h2>Étudiant introuvable (id=' . $studentId . ').</h2>');
}

$classId = $student['class_id'];
$config  = $pdo->query("SELECT * FROM bulletin_config LIMIT 1")->fetch() ?: [];

// ── Validation selon le mode ──────────────────────────────────────────────
if ($mode === 'semestre') {
    $stPCheck = $pdo->prepare("SELECT id, name FROM evaluation_periods WHERE id = ? AND school_year = ? LIMIT 1");
    $stPCheck->execute([$periodId, $anneeAcad]);
    $period = $stPCheck->fetch();
    if (!$period) {
        ob_end_clean();
        die('<h2>Période introuvable pour cette année académique.</h2>
             <p><a href="attestations.php">← Retour</a></p>');
    }
    $periodLabel = $period['name'];

    $validation = computeStudentSemesterValidation($pdo, $studentId, $classId, $periodId);
    if (!$validation['validated']) {
        ob_end_clean();
        die('<h2>Cet étudiant n\'a pas validé ' . htmlspecialchars($periodLabel) . '.</h2>
             <p>Moyenne : ' . $validation['average'] . '/20 — ' . $validation['mention'] . '</p>
             <p><a href="attestations.php">← Retour</a></p>');
    }
} else {
    $periodLabel = '';
    $stPeriods   = $pdo->prepare("SELECT id FROM evaluation_periods WHERE school_year = ? ORDER BY id");
    $stPeriods->execute([$anneeAcad]);
    $periodIds = $stPeriods->fetchAll(PDO::FETCH_COLUMN);

    if (empty($periodIds)) {
        ob_end_clean();
        die('<h2>Aucune période d\'évaluation pour ' . htmlspecialchars($anneeAcad) . '.</h2>
             <p><a href="attestations.php">← Retour</a></p>');
    }

    $validation = computeStudentYearValidation($pdo, $studentId, $classId, $periodIds);
    if (!$validation['validated']) {
        ob_end_clean();
        die('<h2>Cet étudiant n\'a pas validé l\'intégralité de son année.</h2>
             <p>Moyenne : ' . $validation['average'] . '/20 — ' . $validation['mention'] . '</p>
             <p><a href="attestations.php">← Retour</a></p>');
    }
}

$mention = $validation['mention'];

// ── Vérifier si une attestation existe déjà ──────────────────────────────
$existing = false;
if ($hasNewCols && $mode === 'semestre') {
    $stCheck = $pdo->prepare(
        "SELECT * FROM attestations WHERE student_id=? AND annee_academique=? AND evaluation_period_id=?"
    );
    $stCheck->execute([$studentId, $anneeAcad, $periodId]);
} elseif ($hasNewCols) {
    $stCheck = $pdo->prepare(
        "SELECT * FROM attestations WHERE student_id=? AND annee_academique=? AND evaluation_period_id IS NULL"
    );
    $stCheck->execute([$studentId, $anneeAcad]);
} else {
    $stCheck = $pdo->prepare(
        "SELECT * FROM attestations WHERE student_id=? AND annee_academique=?"
    );
    $stCheck->execute([$studentId, $anneeAcad]);
}
$existing = $stCheck->fetch();

if ($existing) {
    error_log('[generate_attestation] Attestation existante trouvée, retéléchargement id=' . $existing['id']);
    ob_end_clean();
    generateAndStreamPDF($pdo, $existing, $student, $config, $periodLabel);
    exit();
}

// ── Insertion en base ─────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    $numero = generateNumeroEnregistrement($pdo, $anneeAcad);
    $lieu   = $config['location'] ?? 'Libreville';

    if ($hasNewCols) {
        $stInsert = $pdo->prepare(
            "INSERT INTO attestations
                (student_id, annee_academique, mode, evaluation_period_id,
                 numero_enregistrement, mention, filiere,
                 type_diplome, promotion, lieu_emission, date_emission, generated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stInsert->execute([
            $studentId, $anneeAcad, $mode,
            $mode === 'semestre' ? $periodId : null,
            $numero, $mention, $filiere,
            $typeDiplome, $promotion, $lieu, $dateEmission, $adminId,
        ]);
    } else {
        $stInsert = $pdo->prepare(
            "INSERT INTO attestations
                (student_id, annee_academique, numero_enregistrement, mention, filiere,
                 type_diplome, promotion, lieu_emission, date_emission, generated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stInsert->execute([
            $studentId, $anneeAcad, $numero, $mention, $filiere,
            $typeDiplome, $promotion, $lieu, $dateEmission, $adminId,
        ]);
    }

    $newId = (int)$pdo->lastInsertId();
    $pdo->commit();
    error_log('[generate_attestation] INSERT OK id=' . $newId . ' hasNewCols=' . ($hasNewCols ? 'yes' : 'no'));

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('[generate_attestation] ERREUR INSERT : ' . $e->getMessage());

    if ($e->getCode() === '23000') {
        $stFallback = $pdo->prepare(
            "SELECT * FROM attestations WHERE student_id=? AND annee_academique=?"
        );
        $stFallback->execute([$studentId, $anneeAcad]);
        $existing = $stFallback->fetch();
        if ($existing) {
            ob_end_clean();
            generateAndStreamPDF($pdo, $existing, $student, $config, $periodLabel);
            exit();
        }
    }

    ob_end_clean();
    die('<h2>Erreur base de données.</h2><pre>' . htmlspecialchars($e->getMessage()) . '</pre>
         <p><a href="attestations.php">← Retour</a></p>');
}

$stFetch = $pdo->prepare("SELECT * FROM attestations WHERE id = ?");
$stFetch->execute([$newId]);
$attest = $stFetch->fetch();

ob_end_clean();
generateAndStreamPDF($pdo, $attest, $student, $config, $periodLabel);
exit();

// ══════════════════════════════════════════════════════════════════════════
// Génération du PDF — A4 paysage
// ══════════════════════════════════════════════════════════════════════════
function generateAndStreamPDF(PDO $pdo, array $attest, array $student, array $config, string $periodLabel = ''): void
{
    $schoolName    = 'Institut de Formation en Santé Elyana (IFSE)';
    $directorTitle = $config['signature_title'] ?? 'Le Directeur Général';
    $directorName  = $config['signature_name']  ?? '';
    $location      = $config['location']        ?? 'Libreville';

    $studentName = strtoupper($student['name']);
    $typeDiplome = $attest['type_diplome'];
    $promotion   = $attest['promotion'];
    $filiere     = $attest['filiere'];
    $mention     = strtoupper($attest['mention']);
    $numero      = $attest['numero_enregistrement'];
    $mode        = $attest['mode'] ?? 'annee_complete';
    $annee       = $attest['annee_academique'];

    if ($mode === 'semestre' && $periodLabel === '' && !empty($attest['evaluation_period_id'] ?? null)) {
        $stP = $pdo->prepare("SELECT name FROM evaluation_periods WHERE id = ? LIMIT 1");
        $stP->execute([$attest['evaluation_period_id']]);
        $periodLabel = $stP->fetchColumn() ?: '';
    }

    $scopeLabel = ($mode === 'semestre' && $periodLabel)
        ? mb_strtoupper($periodLabel, 'UTF-8') . ' — ' . $annee
        : 'ANNÉE ACADÉMIQUE ' . $annee;

    if ($mode === 'semestre' && $periodLabel) {
        $conditionsText = 'A satisfait aux conditions requises pour la validation du&nbsp;<span class="b">'
            . htmlspecialchars(mb_strtoupper($periodLabel, 'UTF-8'))
            . '</span> de la <span class="b">' . htmlspecialchars($typeDiplome) . '</span>,';
    } else {
        $conditionsText = 'A satisfait aux conditions requises pour l\'obtention de la&nbsp;<span class="b">'
            . htmlspecialchars($typeDiplome) . '</span>,';
    }

    $dateEmObj = DateTime::createFromFormat('Y-m-d', $attest['date_emission']);
    $moisFr    = ['','janvier','février','mars','avril','mai','juin',
                  'juillet','août','septembre','octobre','novembre','décembre'];
    $dateDisplay = $dateEmObj
        ? $dateEmObj->format('d') . ' ' . $moisFr[(int)$dateEmObj->format('n')] . ' ' . $dateEmObj->format('Y')
        : date('d/m/Y');

    $dobText = '';
    if (!empty($student['birth_date'])) {
        $dobObj = DateTime::createFromFormat('Y-m-d', $student['birth_date']);
        if ($dobObj) {
            $dobText = 'né(e) le ' . $dobObj->format('d') . ' ' . $moisFr[(int)$dobObj->format('n')] . ' ' . $dobObj->format('Y');
            $pob = trim($student['place_of_birth'] ?? '');
            $dobText .= $pob ? ' à ' . $pob : ' à ' . $location;
        }
    }

    $qrDataUri = generateQRBase64($numero);

    ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
@page { size: A4 landscape; margin: 0; }

body {
  font-family: "Times New Roman", Times, serif;
  background: white;
  width: 297mm;
  height: 210mm;
  overflow: hidden;
  position: relative;
  font-size: 9.5pt;
  color: #111;
}

/* Double bordure orange — 8px outer, ~4mm gap, 2px inner */
.frame-outer { position:absolute; inset:3.5mm; border:8px solid #E87722; pointer-events:none; }
.frame-inner { position:absolute; inset:8mm;   border:2px solid #E87722; pointer-events:none; }

.page {
  position: absolute;
  top: 12mm; left: 13mm; right: 13mm; bottom: 11mm;
  display: flex;
  flex-direction: column;
}

/* ── EN-TÊTE : 3 colonnes ── */
.header { display:flex; align-items:flex-start; gap:3mm; margin-bottom:2mm; }

.hdr-left { width:62mm; flex-shrink:0; }
.rep-title    { font-size:9pt; font-weight:bold; letter-spacing:1.5px; text-transform:uppercase; margin-bottom:0.8mm; }
.rep-motto    { font-size:7.5pt; color:#444; margin-bottom:1mm; }
.rep-ministry { font-size:7pt; text-transform:uppercase; letter-spacing:.3px; color:#333; line-height:1.4; }

.hdr-center {
  flex:1;
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  text-align:center;
}
.logo-svg      { width:50mm; height:auto; margin-bottom:1.5mm; }
.logo-label    { font-size:7.5pt; font-weight:bold; color:#E87722; text-transform:uppercase; letter-spacing:.8px; line-height:1.5; }
.logo-sublabel { font-size:6.5pt; color:#1A6BAA; font-weight:bold; text-transform:uppercase; letter-spacing:.5px; }

.hdr-right {
  width:62mm; flex-shrink:0;
  display:flex; align-items:flex-start; justify-content:flex-end;
  text-align:right;
}
.school-name-right {
  font-size:8pt; font-weight:bold; text-transform:uppercase;
  letter-spacing:.4px; line-height:1.6; color:#111;
}

/* ── SÉPARATEUR ── */
.divider {
  height:1.5px;
  background:linear-gradient(to right, transparent, #1A6BAA 10%, #1A6BAA 90%, transparent);
  margin:1.5mm 0;
}

/* ── NOM ÉTABLISSEMENT ── */
.school-name {
  text-align:center; font-size:11.5pt; font-weight:bold;
  text-transform:uppercase; letter-spacing:1px; margin-bottom:2mm;
}

/* ── TITRE ── */
.cert-title { text-align:center; margin:0 0 2mm; }
.cert-title h1 {
  font-size:24pt; font-weight:bold; text-transform:uppercase;
  letter-spacing:3px; text-decoration:underline; line-height:1.15;
}
.cert-diplome {
  font-size:12pt; font-weight:bold; text-transform:uppercase;
  letter-spacing:2px; color:#1A6BAA; margin-top:1mm;
}
.cert-scope { font-size:9pt; font-style:italic; color:#444; margin-top:1mm; }

/* ── CORPS ── */
.corps { font-size:9.5pt; line-height:2.1; margin-top:1.5mm; flex:1; }
.corps p { margin-bottom:0; }
.name-etudiant { font-weight:bold; font-size:10pt; text-decoration:underline; text-transform:uppercase; }
.b { font-weight:bold; }

/* ── PIED DE PAGE ── */
.footer {
  display:flex; align-items:flex-end;
  gap:5mm; margin-top:2mm; padding-top:1.5mm;
  border-top:1px solid rgba(0,0,0,.12);
}

/* QR en haut, N° juste en dessous, note encore en dessous */
.foot-left { width:45mm; flex-shrink:0; font-size:7.5pt; color:#222; }
.qr-img {
  width:18mm; height:18mm; display:block; margin-bottom:1.5mm;
}
.qr-fallback {
  width:18mm; height:18mm; border:1px solid #aaa; display:block;
  font-size:4pt; color:#888; text-align:center; margin-bottom:1.5mm;
}
.num-line  { font-weight:bold; font-size:7.5pt; margin-bottom:1mm; }
.note-text { font-size:5.5pt; color:#555; line-height:1.5; max-width:43mm; }

/* Date centrée */
.foot-center { flex:1; text-align:center; font-size:8.5pt; color:#222; }

/* Deux blocs de signatures côte à côte */
.foot-right { display:flex; gap:8mm; align-items:flex-end; flex-shrink:0; }
.sig-block  { text-align:center; font-size:7.5pt; color:#222; width:52mm; }
.sig-block .sig-label    { font-weight:bold; font-size:8pt; line-height:1.5; margin-bottom:1mm; }
.sig-block .sig-space    { height:22mm; border-bottom:1px solid #333; margin-bottom:1.5mm; }
.sig-block .sig-sublabel { font-style:italic; font-size:7pt; color:#333; line-height:1.5; }
.sig-block .sig-name     { font-weight:bold; font-size:8pt; margin-top:1mm; }
</style>
</head>
<body>

<div class="frame-outer"></div>
<div class="frame-inner"></div>

<div class="page">

  <!-- ══ EN-TÊTE ══ -->
  <div class="header">

    <div class="hdr-left">
      <div class="rep-title">RÉPUBLIQUE GABONAISE</div>
      <div class="rep-motto">Union &nbsp;—&nbsp; Travail &nbsp;—&nbsp; Justice</div>
      <div class="rep-ministry">MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR ET DE LA RECHERCHE SCIENTIFIQUE</div>
    </div>

    <div class="hdr-center">
      <svg class="logo-svg" viewBox="0 0 110 100" xmlns="http://www.w3.org/2000/svg">
        <ellipse cx="55" cy="54" rx="38" ry="34" fill="#1A6BAA" opacity=".12"/>
        <path d="M55 15 C38 26 22 44 28 66 C33 82 47 89 55 89 C63 89 77 82 82 66 C88 44 72 26 55 15Z" fill="#1A6BAA"/>
        <path d="M55 15 C50 34 47 54 50 70 C52 79 55 89 55 89 C55 89 58 79 60 70 C63 54 60 34 55 15Z" fill="#E87722"/>
        <path d="M28 46 Q42 37 55 42 Q41 54 28 57Z" fill="#E87722"/>
        <path d="M82 46 Q68 37 55 42 Q69 54 82 57Z" fill="#0d4f82"/>
      </svg>
      <div class="logo-label">INSTITUT DE FORMATION EN SANTÉ ELYANA</div>
      <div class="logo-sublabel">IFSE</div>
    </div>

    <div class="hdr-right">
      <div class="school-name-right">INSTITUT DE FORMATION<br>EN SANTÉ ELYANA<br>(IFSE)</div>
    </div>

  </div>

  <div class="divider"></div>
  <div class="school-name">INSTITUT DE FORMATION EN SANTÉ ELYANA</div>

  <!-- ══ TITRE ══ -->
  <div class="cert-title">
    <h1>ATTESTATION DE RÉUSSITE</h1>
    <div class="cert-diplome"><?= htmlspecialchars($typeDiplome) ?></div>
    <div class="cert-scope"><?= htmlspecialchars($scopeLabel) ?></div>
  </div>

  <!-- ══ CORPS ══ -->
  <div class="corps">
    <p>Le Directeur Général de l'<span class="b"><?= htmlspecialchars($schoolName) ?></span></p>
    <p>Vu les textes réglementant les examens universitaires ;</p>
    <p>Le Procès-Verbal du jury de délibération de la <span class="b"><?= htmlspecialchars($typeDiplome) ?></span> – Promotion <span class="b"><?= htmlspecialchars($promotion) ?></span> ;</p>
    <p>Atteste que <span class="name-etudiant"><?= htmlspecialchars($studentName) ?></span><?= $dobText ? ",&nbsp;" . htmlspecialchars($dobText) : '' ?>,</p>
    <p><?= $conditionsText ?></p>
    <p>Filière&nbsp;: <span class="b"><?= htmlspecialchars($filiere) ?></span> &nbsp;&nbsp;&nbsp; Mention&nbsp;: <span class="b"><?= htmlspecialchars($mention) ?></span></p>
  </div>

  <!-- ══ PIED DE PAGE ══ -->
  <div class="footer">

    <div class="foot-left">
      <?php if ($qrDataUri): ?>
        <img class="qr-img" src="<?= $qrDataUri ?>" alt="QR">
      <?php else: ?>
        <div class="qr-fallback"><?= htmlspecialchars($numero) ?></div>
      <?php endif; ?>
      <div class="num-line">N°&nbsp;: <?= htmlspecialchars($numero) ?></div>
      <div class="note-text">Il ne sera délivré qu'une seule attestation de réussite. L'intéressé(e) devra tirer les photocopies dont il pourrait avoir besoin.</div>
    </div>

    <div class="foot-center">
      Fait à <?= htmlspecialchars($location) ?>, le <?= htmlspecialchars($dateDisplay) ?>
    </div>

    <div class="foot-right">

      <div class="sig-block">
        <div class="sig-label">Signature de l'Impétrant(e)</div>
        <div class="sig-space"></div>
        <div class="sig-sublabel">
          P/O le Chef &amp; <?= htmlspecialchars($directorTitle) ?><br>
          Le <?= htmlspecialchars($directorTitle) ?>
        </div>
        <?php if ($directorName): ?>
          <div class="sig-name"><?= htmlspecialchars($directorName) ?></div>
        <?php endif; ?>
      </div>

      <div class="sig-block">
        <div class="sig-label">P/O la Comptable</div>
        <div class="sig-space"></div>
        <div class="sig-sublabel">La Comptratrice</div>
      </div>

    </div>

  </div>
</div>

</body>
</html>
<?php
    $html = ob_get_clean();

    $options = new Options();
    $options->set('defaultFont',         'Times New Roman');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled',      true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $safeName = preg_replace('/[^a-z0-9]/i', '_', $student['name']);
    $safeYear = str_replace('/', '-', $attest['annee_academique']);
    $suffix   = ($mode === 'semestre' && $periodLabel)
                ? '_' . preg_replace('/[^a-z0-9]/i', '_', $periodLabel)
                : '';
    $filename = 'attestation_' . $safeName . '_' . $safeYear . $suffix . '.pdf';

    // 'Attachment' => true : déclenche un téléchargement sans quitter la page courante.
    // Élimine le problème de popup bloquée (plus de target="_blank" nécessaire).
    $dompdf->stream($filename, ['Attachment' => true]);
}
