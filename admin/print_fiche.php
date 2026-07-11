<?php
/**
 * print_fiche.php
 * Reproduction fidèle de la Fiche d'Inscription ISMM (PDF original)
 * Accessible depuis pending_registrations.php via ?student_id=TEMP-xxx
 */
session_start();
require_once '../includes/db_connect.php';

date_default_timezone_set('Africa/Libreville');
$conn->set_charset('utf8mb4');

// Vérification admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$student_id = $_GET['student_id'] ?? '';
if (empty($student_id)) { echo "ID étudiant manquant."; exit(); }

$stmt = $conn->prepare("
    SELECT u.*, c.name AS class_name
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE u.id = ?
");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) { echo "Étudiant introuvable."; exit(); }
$s = $result->fetch_assoc();
$stmt->close();

// Essayer de récupérer des données depuis candidatures si elles existent
$cand = [];
$cs = $conn->prepare("SELECT * FROM candidatures WHERE user_id = ? LIMIT 1");
if ($cs) {
    $cs->bind_param("s", $student_id);
    $cs->execute();
    $cr = $cs->get_result();
    if ($cr && $cr->num_rows > 0) $cand = $cr->fetch_assoc();
    $cs->close();
}

$conn->close();

// Fusionner user + candidature (candidature prioritaire pour certains champs)
foreach (['niveau','specialite','exp_pro','domaine_pro','ville','mode_paiement','ref_dossier'] as $k) {
    if (!empty($cand[$k]) && empty($s[$k])) $s[$k] = $cand[$k];
}

// Fusionner les chemins de documents depuis candidatures (nouvelles + anciennes colonnes)
foreach ([
    'acte_naissance_path', 'diplome_path', 'releve_notes_path',
    'photos_path', 'attestation_emploi_path', 'cv_path',
    'cni_path', 'lettre_path', 'preuve_paiement'
] as $k) {
    if (!empty($cand[$k])) $s[$k] = $cand[$k];
}

// Helpers
function v($val) { return htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8'); }

function fdate($d) {
    if (empty($d)) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d/m/Y') : $d;
}

function chk($arr, $key) {
    $data = is_string($arr) ? json_decode($arr, true) : (array)$arr;
    return in_array($key, (array)$data) ? '&#10003;' : '';
}

function box($checked) {
    $c = $checked ? '&#10003;' : '';
    return '<span class="cb">' . $c . '</span>';
}

// Nom affiché
$nom    = v($s['nom'] ?? (explode(' ', $s['name'] ?? '')[0] ?? ''));
$prenom = v($s['prenom'] ?? (implode(' ', array_slice(explode(' ', $s['name'] ?? ''), 1)) ?: ''));

// Pièces & paiement
$pieces   = $s['pieces']   ?? '[]';
$paiement = $s['paiement'] ?? '[]';

// Mode paiement direct (champ texte)
$modePay = $s['mode_paiement'] ?? '';
if (!empty($modePay) && $paiement === '[]') {
    $paiement = json_encode([$modePay]);
}

$annee_acad = date('Y') . ' / ' . (date('Y') + 1);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Fiche d'inscription ISMM — <?php echo v($s['name']); ?></title>
<style>
/* ===== RESET ===== */
* { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    background: #f0f0f0;
    color: #000;
}

/* ===== BARRE D'IMPRESSION (cachée à l'impression) ===== */
.no-print {
    background: #0a1c2e;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.btn-print {
    background: #ff9500;
    color: white;
    border: none;
    padding: 10px 22px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 7px;
}
.btn-print:hover { background: #e08400; }
.btn-back {
    background: rgba(255,255,255,0.15);
    color: white;
    border: 1px solid rgba(255,255,255,0.3);
    padding: 10px 22px;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 7px;
}
.btn-back:hover { background: rgba(255,255,255,0.25); }
.no-print span { color: rgba(255,255,255,0.6); font-size: 13px; margin-left: 10px; }

/* ===== PAGE A4 ===== */
.page {
    width: 210mm;
    min-height: 297mm;
    margin: 15px auto;
    background: white;
    padding: 10mm 12mm 12mm;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}

/* ===== EN-TÊTE ===== */
.entete {
    display: flex;
    align-items: stretch;
    margin-bottom: 6px;
    border: 1.5px solid #000;
}

.entete-logo {
    width: 28mm;
    border-right: 1.5px solid #000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 6px;
    flex-shrink: 0;
}
.entete-logo img {
    width: 100%;
    max-height: 28mm;
    object-fit: contain;
}

.entete-centre {
    flex: 1;
    text-align: center;
    padding: 6px 10px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}
.entete-centre .school-name {
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
    line-height: 1.5;
    letter-spacing: 0.3px;
}
.entete-centre .contact {
    font-size: 9.5px;
    margin-top: 3px;
    line-height: 1.6;
    color: #222;
}
.entete-centre .site {
    font-size: 9.5px;
    font-weight: bold;
    color: #0a1c2e;
}

.entete-photo {
    width: 32mm;
    border-left: 1.5px solid #000;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    min-height: 38mm;
    font-size: 11px;
    font-weight: bold;
    text-align: center;
    color: #444;
    font-style: italic;
    overflow: hidden;
}
.entete-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.entete-photo.no-photo {
    flex-direction: column;
    gap: 4px;
}

/* Ligne année académique */
.annee-line {
    text-align: center;
    font-size: 11px;
    font-weight: bold;
    margin: 5px 0 6px;
    letter-spacing: 0.3px;
}
.annee-val {
    border-bottom: 1.5px solid #000;
    padding-bottom: 1px;
    min-width: 100px;
    display: inline-block;
    letter-spacing: 1px;
}

/* ===== SECTIONS ===== */
.section {
    border: 1.5px solid #000;
    margin-bottom: -1px; /* chevauchement pour éviter double bordure */
}

.section-title {
    background: #2e6da4;
    color: #fff;
    font-weight: bold;
    font-size: 10px;
    padding: 5px 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.section-body {
    padding: 7px 10px;
}

/* Lignes de champs */
.field-row {
    display: flex;
    align-items: baseline;
    margin-bottom: 6px;
    gap: 0;
    flex-wrap: wrap;
}
.field-row:last-child { margin-bottom: 0; }

.fl { /* field label */
    font-size: 10.5px;
    white-space: nowrap;
    flex-shrink: 0;
    font-weight: normal;
}
.fv { /* field value */
    border-bottom: 1px solid #555;
    flex: 1;
    min-width: 40px;
    font-size: 10.5px;
    padding: 0 4px 1px;
    margin-left: 3px;
    font-weight: 600;
    color: #0a1c2e;
}
.fsep { width: 10px; flex-shrink: 0; }

/* Cases à cocher */
.cb {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 12px;
    height: 12px;
    border: 1.5px solid #000;
    font-size: 10px;
    line-height: 1;
    vertical-align: middle;
    margin-right: 2px;
    flex-shrink: 0;
    color: #0a1c2e;
    font-weight: bold;
}

.check-row {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 16px;
    margin: 4px 0;
    align-items: center;
}

.check-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10.5px;
    white-space: nowrap;
}

/* Régime inline */
.regime-inline {
    display: inline-flex;
    align-items: center;
    gap: 14px;
    margin-left: 8px;
}
.regime-opt {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10.5px;
}

/* Signature */
.signature-block {
    margin-top: 14px;
}
.sig-text {
    font-size: 10.5px;
    line-height: 1.9;
}
.sig-name {
    border-bottom: 1px solid #555;
    min-width: 160px;
    display: inline-block;
    padding: 0 3px;
    font-weight: 600;
    color: #0a1c2e;
}
.sig-line {
    margin-top: 16px;
    font-size: 10.5px;
    font-weight: bold;
}
.sig-area {
    border-bottom: 1px solid #555;
    height: 30px;
    display: block;
    margin-top: 4px;
    width: 120px;
}

/* Pied de page */
.footer-print {
    text-align: center;
    font-size: 9px;
    color: #888;
    margin-top: 10px;
    padding-top: 6px;
    border-top: 1px solid #eee;
}

/* ===== IMPRESSION ===== */
@media print {
    @page { size: A4; margin: 0; }
    body { background: white; }
    .no-print { display: none !important; }
    .page {
        margin: 0;
        padding: 8mm 10mm 10mm;
        box-shadow: none;
        width: 100%;
        min-height: 100vh;
    }
    .section-title {
        background: #2e6da4 !important;
        color: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .cb { border-color: #000 !important; }
}
</style>
</head>
<body>

<!-- BARRE D'IMPRESSION -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">
        🖨️ Imprimer la fiche
    </button>
    <a class="btn-back" href="pending_registrations.php">
        ← Retour
    </a>
    <span>Fiche d'inscription de <strong style="color:white;"><?php echo v($s['name']); ?></strong></span>
</div>

<!-- PAGE A4 -->
<div class="page">

    <!-- EN-TÊTE -->
    <div class="entete">
        <div class="entete-logo">
            <img src="../uploads/logo-ismm.jpg"
                 alt="Logo ISMM"
                 onerror="this.parentNode.innerHTML='<span style=\'font-size:9px;font-weight:bold;text-align:center;\'>ISMM</span>'">
        </div>

        <div class="entete-centre">
            <div class="school-name">
                Institut des Sciences et des Métiers de la Mer
            </div>
            <div class="contact">
                066 87 20 65 &nbsp;/&nbsp; 074 91 92 13 &nbsp;/&nbsp; 062 35 51 04
            </div>
            <div class="site">ISMM.uvcoding.com</div>
        </div>

        <div class="entete-photo <?php echo empty($s['avatar']) ? 'no-photo' : ''; ?>">
            <?php if (!empty($s['avatar'])): ?>
                <?php
                // Chemin depuis admin/ → ../uploads/avatars/
                $avatarPath = '../uploads/avatars/' . basename($s['avatar']);
                ?>
                <img src="<?php echo htmlspecialchars($avatarPath); ?>"
                     alt="Photo"
                     onerror="this.parentNode.classList.add('no-photo');this.parentNode.innerHTML='<span style=\'font-size:11px;\'>PHOTO</span>'">
            <?php else: ?>
                <span style="font-size:11px;">PHOTO</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ANNÉE ACADÉMIQUE -->
    <div class="annee-line">
        ANNEE ACADEMIQUE :&nbsp;<span class="annee-val"><?php echo $annee_acad; ?></span>
    </div>

    <!-- 1. IDENTIFICATION -->
    <div class="section">
        <div class="section-title">1. &nbsp; IDENTIFICATION DE L'ETUDIANT (E)</div>
        <div class="section-body">
            <div class="field-row">
                <span class="fl">Nom (s) :</span>
                <span class="fv"><?php echo $nom; ?></span>
                <span class="fsep"></span>
                <span class="fl">Prénom (s) :</span>
                <span class="fv"><?php echo $prenom; ?></span>
            </div>
            <div class="field-row">
                <span class="fl">Date et lieu de naissance :</span>
                <span class="fv" style="max-width:80mm;">
                    <?php echo fdate($s['birth_date'] ?? ''); ?>
                    <?php if (!empty($s['birth_place'])): ?>&nbsp;&mdash;&nbsp;<?php echo v($s['birth_place']); ?><?php endif; ?>
                </span>
                <span class="fsep"></span>
                <span class="fl">Sexe :</span>
                <span class="fv" style="max-width:20mm;"><?php echo v($s['sexe'] ?? ''); ?></span>
                <span class="fsep"></span>
                <span class="fl">Nationalité :</span>
                <span class="fv"><?php echo v($s['nationalite'] ?? ''); ?></span>
            </div>
        </div>
    </div>

    <!-- 2. CONTACT -->
    <div class="section">
        <div class="section-title">2. &nbsp; CONTACT</div>
        <div class="section-body">
            <div class="field-row">
                <span class="fl">Adresse :</span>
                <span class="fv"><?php echo v($s['address'] ?? ''); ?></span>
                <span class="fsep"></span>
                <span class="fl">Téléphone (s) :</span>
                <span class="fv"><?php echo v($s['phone'] ?? ''); ?></span>
            </div>
            <div class="field-row">
                <span class="fl">Email :</span>
                <span class="fv"><?php echo v($s['email'] ?? ''); ?></span>
                <span class="fsep"></span>
                <span class="fl">Bac série :</span>
                <span class="fv" style="max-width:18mm;"><?php echo v($s['bac_serie'] ?? ''); ?></span>
                <span class="fsep"></span>
                <span class="fl">Année d'obtention :</span>
                <span class="fv" style="max-width:18mm;"><?php echo v($s['bac_annee'] ?? ''); ?></span>
            </div>
            <div class="field-row">
                <span class="fl">Tuteur légal : M/Mme</span>
                <span class="fv"><?php echo v($s['tuteur_nom'] ?? ''); ?></span>
                <span class="fsep"></span>
                <span class="fl">Lien :</span>
                <span class="fv"><?php echo v($s['tuteur_lien'] ?? ''); ?></span>
            </div>
            <div class="field-row">
                <span class="fl">Adresse :</span>
                <span class="fv"><?php echo v($s['tuteur_adresse'] ?? ''); ?></span>
                <span class="fsep"></span>
                <span class="fl">Téléphone (s) :</span>
                <span class="fv"><?php echo v($s['tuteur_telephone'] ?? ''); ?></span>
            </div>
        </div>
    </div>

    <!-- 3. URGENCE -->
    <div class="section">
        <div class="section-title">3. &nbsp; PERSONNE A CONTACTER EN CAS D'URGENCE</div>
        <div class="section-body">
            <div class="field-row">
                <span class="fl">Nom (s) :</span>
                <span class="fv"><?php echo v($s['urgence_nom'] ?? ''); ?></span>
                <span class="fsep"></span>
                <span class="fl">Lien :</span>
                <span class="fv"><?php echo v($s['urgence_lien'] ?? ''); ?></span>
            </div>
            <div class="field-row">
                <span class="fl">Adresse :</span>
                <span class="fv"><?php echo v($s['urgence_adresse'] ?? ''); ?></span>
                <span class="fsep"></span>
                <span class="fl">Téléphone (s) :</span>
                <span class="fv"><?php echo v($s['urgence_telephone'] ?? ''); ?></span>
            </div>
        </div>
    </div>

    <!-- 4. INFORMATIONS ACADÉMIQUES -->
    <div class="section">
        <div class="section-title">4. &nbsp; INFORMATIONS ACADEMIQUES</div>
        <div class="section-body">
            <div class="field-row">
                <span class="fl">Dernier diplôme obtenu :</span>
                <span class="fv"><?php echo v($s['dernier_diplome'] ?? ''); ?></span>
                <span class="fsep"></span>
                <span class="fl">Série / Filière :</span>
                <span class="fv"><?php echo v($s['diplome_serie'] ?? ''); ?></span>
            </div>
            <div class="field-row">
                <span class="fl">Année d'obtention :</span>
                <span class="fv" style="max-width:22mm;"><?php echo v($s['diplome_annee'] ?? ''); ?></span>
                <span class="fsep"></span>
                <span class="fl">Etablissement d'origine :</span>
                <span class="fv"><?php echo v($s['etablissement_origine'] ?? ''); ?></span>
            </div>
        </div>
    </div>

    <!-- 5. FORMATION CHOISIE -->
    <div class="section">
        <div class="section-title">5. &nbsp; FORMATION CHOISIE A L'ISMM</div>
        <div class="section-body">
            <div class="field-row" style="align-items:center;">
                <span class="fl">Filière :</span>
                <span class="fv">
                    <?php echo v($s['class_name'] ?? $s['specialite'] ?? ''); ?>
                </span>
                <span class="fsep"></span>
                <span class="fl">Niveau :</span>
                <span class="fv" style="max-width:24mm;"><?php echo v($s['niveau'] ?? ''); ?></span>
                <span class="fsep" style="width:14px;"></span>
                <span class="fl">Régime :</span>
                <span class="regime-inline">
                    <?php
                    // Nettoyer la valeur du régime (peut contenir ✓ ou entités HTML)
                    $regimeVal = strip_tags(html_entity_decode($s['regime'] ?? '', ENT_QUOTES, 'UTF-8'));
                    $regimeVal = trim(preg_replace('/[^\w\s]/u', '', $regimeVal));
                    ?>
                    <span class="regime-opt">
                        <?php echo box(stripos($regimeVal, 'Initiale') !== false); ?>
                        Initiale
                    </span>
                    <span class="regime-opt">
                        <?php echo box(stripos($regimeVal, 'Continue') !== false); ?>
                        Continue
                    </span>
                </span>
            </div>
        </div>
    </div>

    <!-- 6. PIÈCES À FOURNIR -->
    <div class="section">
        <div class="section-title">6. &nbsp; PIECES A FOURNIR</div>
        <div class="section-body" style="padding:7px 10px;">
            <?php
            // Correspondance directe : nouvelles colonnes + fallback anciennes colonnes
            // Ancien formulaire : cv_path, diplome_path, cni_path, lettre_path
            // Nouveau formulaire : acte_naissance_path, diplome_path, releve_notes_path, photos_path, attestation_emploi_path
            $hasActeNaissance     = !empty($s['acte_naissance_path']) || !empty($s['cni_path']);
            $hasDiplome           = !empty($s['diplome_path']);
            $hasReleveNotes       = !empty($s['releve_notes_path'])   || !empty($s['cv_path']);
            $hasPhotos            = !empty($s['photos_path'])         || !empty($s['lettre_path']);
            $hasAttestationEmploi = !empty($s['attestation_emploi_path']);
            ?>
            <div class="check-row">
                <span class="check-item"><?php echo box($hasActeNaissance); ?> Copie de l'acte de naissance légalisé</span>
                <span class="check-item"><?php echo box($hasDiplome); ?> Copie du diplôme (Bac ou autre)</span>
                <span class="check-item"><?php echo box($hasReleveNotes); ?> Relevé de notes</span>
                <span class="check-item"><?php echo box($hasPhotos); ?> 02 photos d'identité</span>
            </div>
            <div class="check-row">
                <span class="check-item"><?php echo box($hasAttestationEmploi); ?> Attestation d'emploi (pour la formation continue)</span>
                <span class="check-item"><?php echo box(false); ?> 01 rame de papier (marque double A)</span>
            </div>
        </div>
    </div>

    <!-- 7. MODE DE PAIEMENT -->
    <div class="section">
        <div class="section-title">7. &nbsp; MODE DE PAIEMENT DES FRAIS DE SCOLARITE (Choisissez votre mode de paiement)</div>
        <div class="section-body" style="padding:7px 10px;">
            <div class="check-row">
                <span class="check-item">
                    <?php echo box(chk($paiement,'airtel_money')||chk($paiement,'airtel')||stripos($modePay,'airtel')!==false); ?>
                    Airtel Money &nbsp;: &nbsp;<strong>Code Agent : ISMM</strong>
                </span>
                <span class="check-item">
                    <?php echo box(chk($paiement,'mobicash')||chk($paiement,'moov')||stripos($modePay,'moov')!==false||stripos($modePay,'mobicash')!==false); ?>
                    MobiCash
                </span>
            </div>
            <div class="check-row" style="margin-top:4px;">
                <span class="check-item">
                    <?php echo box(chk($paiement,'virement')||stripos($modePay,'virement')!==false); ?>
                    Virement Bancaire
                </span>
                <span class="check-item">
                    <?php echo box(chk($paiement,'carte')||chk($paiement,'card')||stripos($modePay,'carte')!==false||stripos($modePay,'card')!==false); ?>
                    Carte Bancaire
                </span>
            </div>
        </div>
    </div>

    <!-- 8. ENGAGEMENT -->
    <div class="section">
        <div class="section-title">8. &nbsp; ENGAGEMENT DE L'ETUDIANT (E)</div>
        <div class="section-body" style="padding:10px 10px 16px;">
            <div class="signature-block">
                <p class="sig-text">
                    Je soussigné (e) :&nbsp;
                    <span class="sig-name">&nbsp;<?php echo v($s['name']); ?>&nbsp;</span>
                    &nbsp;certifie exactes les informations fournies dans cette fiche d'inscription
                    et m'engage à respecter le règlement intérieur de l'ISMM.
                </p>
                <div class="sig-line">
                    Signature :
                    <span class="sig-area"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- PIED DE PAGE -->
    <div class="footer-print">
        Fiche générée le <?php echo date('d/m/Y à H:i'); ?>
        &nbsp;&mdash;&nbsp;
        Ref. dossier : <strong><?php echo v($s['id']); ?></strong>
        &nbsp;&mdash;&nbsp;
        ISMM &mdash; ISMM.uvcoding.com
    </div>

</div><!-- fin .page -->

<script>
// Auto-print si paramètre ?auto=1 dans l'URL
if (new URLSearchParams(window.location.search).get('auto') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 500));
}
</script>
</body>
</html>