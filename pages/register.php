<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/register_handler.php';

// Charger les classes depuis la base de données (via PDO, déjà initialisé par le handler)
$classes_db = [];
try {
    $classes_db = getDB()
        ->query("SELECT id, name, code, level FROM classes ORDER BY name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Silencieux : la liste sera vide, le formulaire reste fonctionnel
    error_log('Chargement classes: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription Candidats - ISMM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }

        body {
            background-color: #0a1c2e;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            background-color: #fff;
            width: 100%;
            max-width: 900px;
            border-radius: 15px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }

        /* ===== HEADER ===== */
        .header-section {
            background: linear-gradient(135deg, #0a1c2e 0%, #072442 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .header-section::before {
            content:'';
            position:absolute;top:0;left:0;right:0;bottom:0;
            background:url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,149,0,0.1)"/></svg>');
            background-size:cover;
        }
        .logo-container { margin-bottom:15px; position:relative; z-index:2; }
        .logo {
            width:90px; height:90px; border-radius:50%; background:white;
            display:inline-flex; justify-content:center; align-items:center;
            border:3px solid #ff9500; overflow:hidden;
            box-shadow:0 0 20px rgba(255,149,0,0.4);
        }
        .logo img { width:100%; height:100%; object-fit:cover; }
        .header-badges { display:flex; justify-content:center; gap:16px; margin-bottom:10px; position:relative; z-index:2; }
        .school-name-badge {
            background:rgba(255,149,0,0.15); border:1px solid rgba(255,149,0,0.4);
            color:#ff9500; padding:4px 14px; border-radius:20px; font-size:12px;
            font-weight:600; letter-spacing:1px; text-transform:uppercase;
        }
        .welcome-text { font-size:24px; font-weight:700; margin-bottom:4px; position:relative; z-index:2; color:#ff9500; }
        .subtitle { font-size:13px; opacity:0.85; position:relative; z-index:2; margin-bottom:6px; }
        .devises { font-size:11px; color:rgba(255,149,0,0.7); position:relative; z-index:2; letter-spacing:1px; font-weight:600; }

        /* ===== FORM SECTION ===== */
        .form-section { padding:30px; flex:1; }
        .form-title {
            font-size:20px; font-weight:600; margin-bottom:20px; color:#0a1c2e;
            text-align:center; display:flex; align-items:center; justify-content:center; gap:10px;
        }
        .form-title i { color:#ff9500; }

        .info-box {
            background:rgba(255,149,0,0.1); border-left:4px solid #ff9500;
            padding:15px; margin:20px 0; border-radius:5px;
        }
        .info-box h4 { color:#ff9500; margin-bottom:8px; font-size:14px; display:flex; align-items:center; gap:8px; }
        .info-box p { font-size:13px; line-height:1.5; color:#666; }

        /* ===== FORM GRID ===== */
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px; }
        .form-group { margin-bottom:15px; }
        .form-group.full-width { grid-column:1/-1; }
        .form-group label { display:block; font-size:13px; font-weight:600; color:#0a1c2e; margin-bottom:6px; }
        .form-group label small { font-weight:normal; color:#666; font-size:11px; }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width:100%; padding:12px 15px; border:2px solid #e0e0e0; border-radius:8px;
            font-size:14px; background-color:#f9f9f9; transition:all 0.3s; box-sizing:border-box;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline:none; border-color:#ff9500; background-color:#fff;
            box-shadow:0 0 0 3px rgba(255,149,0,0.1);
        }
        .form-group input.invalid, .form-group select.invalid { border-color:#e74c3c; background:rgba(231,76,60,0.05); }
        .form-group input.valid, .form-group select.valid { border-color:#2ecc71; }
        .error-hint { font-size:11px; color:#e74c3c; margin-top:4px; display:none; }
        .success-hint { font-size:11px; color:#2ecc71; margin-top:4px; display:none; }

        .password-strength { margin-top:5px; height:4px; background:rgba(0,0,0,0.1); border-radius:2px; overflow:hidden; }
        .password-strength-bar { height:100%; transition:width 0.3s,background 0.3s; width:0%; }
        .password-wrapper { position:relative; }
        .password-wrapper input { padding-right:46px; }
        .toggle-pwd {
            position:absolute; top:50%; right:12px; transform:translateY(-50%);
            background:none; border:none; cursor:pointer; color:#888; font-size:16px;
            padding:4px; line-height:1; transition:color 0.2s;
        }
        .toggle-pwd:hover { color:#ff9500; }
        .strength-weak   { background:#e74c3c; width:33%; }
        .strength-medium { background:#f39c12; width:66%; }
        .strength-strong { background:#2ecc71; width:100%; }

        /* ===== CHAMP "AUTRE" DYNAMIQUE ===== */
        .other-input-wrapper {
            display: none;
            margin-top: 10px;
            animation: fadeIn 0.3s ease;
        }
        .other-input-wrapper.visible { display: block; }
        .other-input-wrapper .other-label {
            font-size: 12px; color: #ff9500; font-weight: 600; display: block; margin-bottom: 5px;
        }
        .other-input-wrapper input {
            width: 100%; padding: 10px 14px; border: 2px solid #ff9500;
            border-radius: 8px; font-size: 13px; background: #fff8ee;
            box-sizing: border-box; font-family: inherit; transition: all 0.3s;
        }
        .other-input-wrapper input:focus { outline: none; box-shadow: 0 0 0 3px rgba(255,149,0,0.15); background: #fff; }

        /* ===== AVATAR ===== */
        .avatar-upload-section {
            background:rgba(255,149,0,0.05); border:2px dashed #ff9500;
            border-radius:10px; padding:20px; text-align:center; margin-bottom:20px;
        }
        .avatar-preview-container { width:100px; height:100px; margin:0 auto 15px; }
        .avatar-preview {
            width:100px; height:100px; border-radius:50%; object-fit:cover;
            border:4px solid #ff9500; box-shadow:0 4px 15px rgba(255,149,0,0.3);
            background:white; display:flex; align-items:center; justify-content:center;
            font-size:40px; color:#ff9500;
        }
        .avatar-upload-label {
            display:inline-block; padding:8px 18px;
            background:linear-gradient(135deg,#ff9500 0%,#ff8c00 100%);
            color:white; border-radius:8px; cursor:pointer; font-weight:600;
            margin-bottom:8px; font-size:13px; transition:all 0.3s;
        }
        .avatar-upload-label:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(255,149,0,0.4); }
        .avatar-upload-input { display:none; }
        .avatar-info { font-size:11px; color:#666; margin-top:8px; }
        .avatar-benefit {
            background:rgba(46,204,113,0.1); border-left:3px solid #2ecc71;
            padding:8px 10px; margin-top:8px; border-radius:5px; font-size:12px;
            color:#27ae60; display:flex; align-items:center; gap:8px; text-align:left;
        }

        /* ===== SECTION DIVIDER ===== */
        .section-divider { border:none; border-top:1px solid #eee; margin:20px 0; }
        .section-label {
            font-size:15px; font-weight:700; color:#0a1c2e; margin-bottom:15px;
            display:flex; align-items:center; gap:8px;
        }
        .section-label i { color:#ff9500; }

        /* ===== SECTION ISMM (sous-titres des blocs de la fiche) ===== */
        .ISMM-block-title {
            font-size:12px; font-weight:700; color:#fff; background:#0a1c2e;
            padding:6px 12px; border-radius:5px; margin:18px 0 10px;
            display:flex; align-items:center; gap:7px; grid-column:1/-1;
            letter-spacing:0.4px;
        }
        .ISMM-block-title i { color:#ff9500; }

        /* Radio inline */
        .radio-inline { display:flex; gap:20px; margin-top:8px; flex-wrap:wrap; }
        .radio-inline label {
            display:flex; align-items:center; gap:7px; font-size:13px;
            font-weight:normal; color:#333; cursor:pointer; margin-bottom:0;
        }
        .radio-inline input[type="radio"] {
            width:16px; height:16px; accent-color:#ff9500; cursor:pointer;
            flex-shrink:0; padding:0; border:none; background:none;
        }

        /* ===== LEVEL BUTTONS ===== */
        .level-buttons { display:flex; flex-wrap:wrap; gap:8px; }
        .level-btn {
            padding:8px 16px; border:2px solid #e0e0e0; border-radius:20px;
            background:#f9f9f9; font-size:13px; font-weight:500; color:#666;
            cursor:pointer; transition:all 0.2s; font-family:inherit;
        }
        .level-btn:hover { border-color:#ff9500; color:#ff9500; }
        .level-btn.active { background:#0a1c2e; border-color:#0a1c2e; color:white; }

        /* ===== SPECIALTY PILLS ===== */
        .specialty-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; }
        .specialty-pill {
            border:2px solid #e0e0e0; border-radius:8px; padding:10px 12px;
            cursor:pointer; transition:all 0.2s; display:flex; align-items:center; gap:8px;
            background:#f9f9f9; font-size:13px; font-weight:500; color:#333;
        }
        .specialty-pill:hover { border-color:#ff9500; background:rgba(255,149,0,0.05); }
        .specialty-pill.selected { border-color:#ff9500; background:rgba(255,149,0,0.1); color:#0a1c2e; }
        .specialty-pill i { color:#999; font-size:14px; transition:color 0.2s; }
        .specialty-pill.selected i { color:#ff9500; }
        .class-level-badge {
            display:inline-block; margin-left:4px;
            background:#e8edf3; color:#0a1c2e; font-size:10px;
            padding:1px 6px; border-radius:10px; font-weight:600;
            vertical-align:middle; letter-spacing:0.3px;
        }
        .specialty-pill.selected .class-level-badge {
            background:rgba(255,149,0,0.2); color:#b36a00;
        }

        /* ===== FILE ZONES ===== */
        .file-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:15px; }
        .file-zone {
            border:2px dashed #e0e0e0; border-radius:8px; padding:16px 12px;
            text-align:center; background:#f9f9f9; cursor:pointer; transition:all 0.3s;
            position:relative;
        }
        .file-zone:hover { border-color:#ff9500; background:rgba(255,149,0,0.04); }
        .file-zone.has-file { border-color:#2ecc71; background:rgba(46,204,113,0.04); }
        .file-zone input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
        .file-zone i { font-size:22px; color:#ff9500; margin-bottom:6px; display:block; }
        .file-zone.has-file i { color:#2ecc71; }
        .file-zone-title { font-size:12px; font-weight:600; color:#0a1c2e; margin-bottom:2px; }
        .file-zone-desc { font-size:11px; color:#999; }
        .file-zone-desc span { color:#ff9500; text-decoration:underline; }
        .file-added {
            display:none; align-items:center; gap:6px; margin-top:8px;
            padding:6px 10px; background:rgba(46,204,113,0.1); border-radius:5px;
            font-size:11px; color:#27ae60;
        }
        .file-added i { color:#27ae60; font-size:11px; display:inline; }

        /* ── PAIEMENT — même style que specialty-pill ── */
        .payment-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; }
        .payment-pill {
            border:2px solid #e0e0e0; border-radius:8px; padding:10px 12px;
            cursor:pointer; transition:all 0.2s; display:flex; align-items:center; gap:8px;
            background:#f9f9f9; font-size:13px; font-weight:500; color:#333;
        }
        .payment-pill:hover { border-color:#ff9500; background:rgba(255,149,0,0.05); }
        .payment-pill.selected { border-color:#ff9500; background:rgba(255,149,0,0.1); color:#0a1c2e; font-weight:600; }
        .payment-pill i { color:#999; font-size:14px; transition:color 0.2s; }
        .payment-pill.selected i { color:#ff9500; }

        /* ===== REGISTER BTN ===== */
        .register-btn {
            width:100%; padding:15px;
            background:linear-gradient(135deg,#ff9500 0%,#ff8c00 100%);
            color:white; border:none; border-radius:10px; font-size:16px; font-weight:600;
            cursor:pointer; margin-top:10px; transition:all 0.3s;
            display:flex; align-items:center; justify-content:center; gap:8px;
        }
        .register-btn:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(255,149,0,0.3); }
        .register-btn:disabled { background:#ccc; cursor:not-allowed; transform:none; box-shadow:none; }

        .login-link-container { text-align:center; margin-top:20px; padding-top:20px; border-top:1px solid #eee; }
        .login-link { color:#0a1c2e; text-decoration:none; font-size:14px; font-weight:500; }
        .login-link:hover { color:#ff9500; }
        .copyright { text-align:center; margin-top:20px; color:#999; font-size:11px; }

        /* ===== SUCCESS MODAL ===== */
        .modal-overlay {
            display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6);
            backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center; padding:20px;
        }
        .modal-overlay.show { display:flex; }
        .modal-card {
            background:white; border-radius:15px; padding:40px 36px; max-width:480px; width:100%;
            text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.3);
            animation:fadeIn 0.4s cubic-bezier(0.16,1,0.3,1);
        }
        .modal-success-icon {
            width:70px; height:70px; background:rgba(46,204,113,0.1); border:3px solid #2ecc71;
            border-radius:50%; display:flex; align-items:center; justify-content:center;
            font-size:32px; color:#2ecc71; margin:0 auto 16px;
        }
        .modal-title { font-size:22px; font-weight:700; color:#0a1c2e; margin-bottom:8px; }
        .modal-text { font-size:13px; color:#666; line-height:1.7; margin-bottom:20px; }
        .modal-ref {
            background:#f9f9f9; border-radius:8px; padding:12px; font-size:16px; font-weight:700;
            color:#0a1c2e; margin-bottom:20px; letter-spacing:1px;
        }
        .modal-ref small { display:block; font-size:11px; font-weight:400; color:#999; margin-bottom:3px; letter-spacing:0; }
        .btn-download {
            width:100%; padding:13px;
            background:linear-gradient(135deg,#ff9500 0%,#ff8c00 100%);
            color:white; border:none; border-radius:8px; font-size:14px; font-weight:600;
            cursor:pointer; display:flex; align-items:center; justify-content:center;
            gap:8px; margin-bottom:10px; transition:all 0.3s;
        }
        .btn-download:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(255,149,0,0.4); }
        .btn-new {
            width:100%; padding:11px; background:transparent; border:2px solid #e0e0e0;
            border-radius:8px; font-size:13px; color:#888; cursor:pointer; transition:all 0.2s;
        }
        .btn-new:hover { border-color:#0a1c2e; color:#0a1c2e; }

        /* ===== LOADING SPINNER ===== */
        .spinner {
            display:inline-block; width:14px; height:14px; border:2px solid rgba(255,255,255,0.4);
            border-top:2px solid white; border-radius:50%; animation:spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* ===== RESPONSIVE ===== */
        @media (max-width:768px) {
            .form-grid,.file-grid,.card-row { grid-template-columns:1fr; }
            .specialty-grid { grid-template-columns:1fr 1fr; }
            .payment-grid { grid-template-columns:1fr 1fr; }
            .form-section { padding:20px; }
        }
        @media (max-width:480px) {
            .specialty-grid { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="container">

    <!-- HEADER -->
    <div class="header-section">
        <div class="logo-container">
            <div class="logo">
                <img src="https://ISMM.uvcoding.com/uploads/logo-ISMM.jpg"
                     alt="ISMM"
                     onerror="this.style.display='none'">
            </div>
        </div>
        <div class="header-badges">
            <span class="school-name-badge">ISMM</span>
        </div>
        <h1 class="welcome-text">INSCRIPTION CANDIDAT</h1>
        <p class="subtitle">École Supérieure de Formation pour l'Insertion Professionnelle</p>
        <p class="devises">TRAVAIL &nbsp;—&nbsp; SUCCÈS &nbsp;—&nbsp; RESPECT</p>
    </div>

    <!-- FORM -->
    <div class="form-section">
        <h2 class="form-title">
            <i class="fas fa-user-plus"></i> Déposer ma candidature en ligne
        </h2>

        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> Information importante</h4>
            <p>Après enregistrement, vous recevrez un <strong>accusé de réception PDF</strong> confirmant la réception de votre dossier et la programmation de votre rendez-vous d'entretien.</p>
        </div>

        <form action="register_process.php" method="post" id="registerForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">

            <!-- PHOTO -->
            <div class="avatar-upload-section">
                <h3 style="color:#ff9500;margin-bottom:12px;font-size:15px;">
                    <i class="fas fa-camera"></i> Photo d'identité <small style="color:#999;font-weight:normal;">(Optionnel)</small>
                </h3>
                <div class="avatar-preview-container">
                    <img id="avatarPreview" src="" alt="Aperçu" class="avatar-preview"
                         onerror="this.style.display='none';document.getElementById('avatarIcon').style.display='flex';">
                    <div id="avatarIcon" class="avatar-preview" style="display:flex;"><i class="fas fa-user"></i></div>
                </div>
                <label for="avatar" class="avatar-upload-label"><i class="fas fa-upload"></i> Choisir une photo</label>
                <input type="file" id="avatar" name="avatar" class="avatar-upload-input"
                       accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewAvatar(this)">
                <div class="avatar-benefit">
                    <i class="fas fa-bolt"></i>
                    <span><strong>Astuce :</strong> Ajouter une photo accélère la validation de votre dossier !</span>
                </div>
                <p class="avatar-info"><i class="fas fa-check-circle" style="color:#2ecc71;"></i> Formats : JPG, PNG, GIF, WEBP — Max. 5 MB</p>
            </div>

            <!-- ============================================================== -->
            <!-- SECTION : FICHE D'INSCRIPTION ISMM                            -->
            <!-- ============================================================== -->

            <!-- ===== 1. IDENTIFICATION ===== -->
            <div class="section-label"><i class="fas fa-id-card"></i> 1. Identification de l'étudiant(e)</div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="nom">Nom(s) * <small>(Nom de famille)</small></label>
                    <input type="text" id="nom" name="nom" placeholder="Ex: DUPONT" required>
                    <div class="error-hint" id="nom_error"></div>
                    <div class="success-hint" id="nom_success">✓ Format valide</div>
                </div>
                <div class="form-group">
                    <label for="prenom">Prénom(s) *</label>
                    <input type="text" id="prenom" name="prenom" placeholder="Ex: Jean-Pierre" required>
                    <div class="error-hint" id="prenom_error"></div>
                    <div class="success-hint" id="prenom_success">✓ Format valide</div>
                </div>
                <div class="form-group">
                    <label for="birth_date">Date de naissance <small>(Optionnel)</small></label>
                    <input type="date" id="birth_date" name="birth_date">
                </div>
                <div class="form-group">
                    <label for="birth_place">Lieu de naissance <small>(Optionnel)</small></label>
                    <input type="text" id="birth_place" name="birth_place" placeholder="Ex: Libreville">
                </div>
                <div class="form-group">
                    <label>Sexe <small>(Optionnel)</small></label>
                    <div class="radio-inline">
                        <label><input type="radio" name="sexe" value="M"> Masculin</label>
                        <label><input type="radio" name="sexe" value="F"> Féminin</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="nationalite">Nationalité <small>(Optionnel)</small></label>
                    <input type="text" id="nationalite" name="nationalite" placeholder="Ex: Gabonaise">
                </div>
            </div>

            <!-- ===== 2. CONTACT ===== -->
            <div class="section-label"><i class="fas fa-phone"></i> 2. Contact</div>
            <div class="form-grid">
                <div class="form-group full-width">
                    <label for="address">Adresse <small>(Optionnel)</small></label>
                    <textarea id="address" name="address" placeholder="Quartier, ville" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label for="phone">Téléphone(s) *</label>
                    <input type="text" id="phone" name="phone" placeholder="+241 01 23 45 67" required>
                    <div class="error-hint" id="phone_error"></div>
                    <div class="success-hint" id="phone_success">✓ Format valide</div>
                </div>
                <div class="form-group">
                    <label for="email">Adresse email *</label>
                    <input type="email" id="email" name="email" placeholder="votre.email@exemple.com" required>
                    <div class="error-hint" id="email_error"></div>
                    <div class="success-hint" id="email_success">✓ Email valide</div>
                </div>
                <div class="form-group">
                    <label for="bac_serie">Bac série <small>(Optionnel)</small></label>
                    <input type="text" id="bac_serie" name="bac_serie" placeholder="Ex: D, C, A...">
                </div>
                <div class="form-group">
                    <label for="bac_annee">Année d'obtention Bac <small>(Optionnel)</small></label>
                    <input type="number" id="bac_annee" name="bac_annee" placeholder="Ex: 2022" min="1980" max="2030">
                </div>
                <div class="form-group">
                    <label for="tuteur_nom">Tuteur légal (M/Mme) <small>(Optionnel)</small></label>
                    <input type="text" id="tuteur_nom" name="tuteur_nom" placeholder="Nom du tuteur légal">
                </div>
                <div class="form-group">
                    <label for="tuteur_lien">Lien avec le tuteur <small>(Optionnel)</small></label>
                    <input type="text" id="tuteur_lien" name="tuteur_lien" placeholder="Ex: Père, Mère, Oncle...">
                </div>
                <div class="form-group">
                    <label for="tuteur_adresse">Adresse du tuteur <small>(Optionnel)</small></label>
                    <input type="text" id="tuteur_adresse" name="tuteur_adresse" placeholder="Adresse du tuteur légal">
                </div>
                <div class="form-group">
                    <label for="tuteur_telephone">Téléphone du tuteur <small>(Optionnel)</small></label>
                    <input type="text" id="tuteur_telephone" name="tuteur_telephone" placeholder="+241 ...">
                </div>
            </div>

            <!-- ===== 3. URGENCE ===== -->
            <div class="section-label"><i class="fas fa-ambulance"></i> 3. Personne à contacter en cas d'urgence</div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="urgence_nom">Nom(s) <small>(Optionnel)</small></label>
                    <input type="text" id="urgence_nom" name="urgence_nom" placeholder="Nom de la personne">
                </div>
                <div class="form-group">
                    <label for="urgence_lien">Lien <small>(Optionnel)</small></label>
                    <input type="text" id="urgence_lien" name="urgence_lien" placeholder="Ex: Frère, Sœur...">
                </div>
                <div class="form-group">
                    <label for="urgence_adresse">Adresse <small>(Optionnel)</small></label>
                    <input type="text" id="urgence_adresse" name="urgence_adresse" placeholder="Adresse">
                </div>
                <div class="form-group">
                    <label for="urgence_telephone">Téléphone(s) <small>(Optionnel)</small></label>
                    <input type="text" id="urgence_telephone" name="urgence_telephone" placeholder="+241 ...">
                </div>
            </div>

            <!-- ===== 4. INFORMATIONS ACADÉMIQUES ===== -->
            <div class="section-label"><i class="fas fa-graduation-cap"></i> 4. Informations académiques</div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="dernier_diplome">Dernier diplôme obtenu <small>(Optionnel)</small></label>
                    <input type="text" id="dernier_diplome" name="dernier_diplome" placeholder="Ex: Baccalauréat, BTS...">
                </div>
                <div class="form-group">
                    <label for="diplome_serie">Série / Filière du diplôme <small>(Optionnel)</small></label>
                    <input type="text" id="diplome_serie" name="diplome_serie" placeholder="Ex: Sciences, Lettres...">
                </div>
                <div class="form-group">
                    <label for="diplome_annee">Année d'obtention <small>(Optionnel)</small></label>
                    <input type="number" id="diplome_annee" name="diplome_annee" placeholder="Ex: 2023" min="1980" max="2030">
                </div>
                <div class="form-group">
                    <label for="etablissement_origine">Établissement d'origine <small>(Optionnel)</small></label>
                    <input type="text" id="etablissement_origine" name="etablissement_origine" placeholder="Nom de l'établissement">
                </div>
            </div>

            <hr class="section-divider">

            <!-- ===== 5. PARCOURS & FORMATION CHOISIE ===== -->
            <div class="section-label"><i class="fas fa-graduation-cap"></i> 5. Formation choisie à l'ISMM</div>

            <!-- Niveau d'études -->
            <div class="form-group" style="margin-bottom:15px;">
                <label>Niveau d'études actuel *</label>
                <div class="level-buttons" id="levelButtons">
                    <button type="button" class="level-btn" onclick="selectLevel(this)">Bac</button>
                    <button type="button" class="level-btn" onclick="selectLevel(this)">Bac+1</button>
                    <button type="button" class="level-btn" onclick="selectLevel(this)">Bac+2</button>
                    <button type="button" class="level-btn" onclick="selectLevel(this)">Bac+3 (Licence)</button>
                    <button type="button" class="level-btn" onclick="selectLevel(this)">Bac+4 (Master 1)</button>
                    <button type="button" class="level-btn" onclick="selectLevel(this)">Bac+5 (Master 2)</button>
                    <button type="button" class="level-btn autre-btn" onclick="selectLevel(this)" data-is-autre="true">Autre</button>
                </div>
                <div class="other-input-wrapper" id="level_other_wrapper">
                    <span class="other-label"><i class="fas fa-pen" style="font-size:11px;"></i> Précisez votre niveau d'études</span>
                    <input type="text" id="level_other_input" name="niveau_autre"
                           placeholder="Ex: Bac+6, Doctorat, BTS, formation professionnelle..."
                           oninput="onLevelOtherInput(this)">
                </div>
                <input type="hidden" id="selected_level" name="niveau">
                <div class="error-hint" id="level_error"></div>
            </div>

            <!-- Régime -->
            <div class="form-group" style="margin-bottom:15px;">
                <label>Régime <small>(Optionnel)</small></label>
                <div class="radio-inline">
                    <label><input type="radio" name="regime" value="Initiale"> Initiale</label>
                    <label><input type="radio" name="regime" value="Continue"> Continue</label>
                </div>
            </div>

            <!-- Expérience professionnelle -->
            <div class="form-grid">
                <div class="form-group">
                    <label for="exp_pro">Expérience professionnelle <small>(Optionnel)</small></label>
                    <select id="exp_pro" name="exp_pro" onchange="onExpProChange(this)">
                        <option value="">Sélectionner...</option>
                        <option value="Aucune expérience">Aucune expérience</option>
                        <option value="Moins d'1 an">Moins d'1 an</option>
                        <option value="1 à 3 ans">1 à 3 ans</option>
                        <option value="3 à 5 ans">3 à 5 ans</option>
                        <option value="autre">Autre</option>
                    </select>
                    <div class="other-input-wrapper" id="exp_other_wrapper">
                        <span class="other-label"><i class="fas fa-pen" style="font-size:11px;"></i> Précisez votre expérience</span>
                        <input type="text" id="exp_other_input" name="exp_pro_autre"
                               placeholder="Ex: Plus de 5 ans, 10 ans...">
                    </div>
                </div>
                <div class="form-group">
                    <label for="domaine_pro">Domaine professionnel <small>(Optionnel)</small></label>
                    <input type="text" id="domaine_pro" name="domaine_pro" placeholder="Ex: Commerce, Informatique...">
                </div>
            </div>

            <!-- Spécialité souhaitée -->
            <div class="form-group" style="margin-bottom:15px;">
                <label>Spécialité / Filière souhaitée *</label>
                <div class="specialty-grid">
                    <?php if (!empty($classes_db)): ?>
                        <?php
                        // Associer une icône FontAwesome selon des mots-clés dans le nom de la classe
                        function getClassIcon(string $name): string {
                            $name_lower = strtolower($name);
                            if (preg_match('/info|réseau|réseau|digital|web|tech|informatique|système|log|data/i', $name_lower))        return 'fa-laptop-code';
                            if (preg_match('/gestion|finance|compta|audit|banque|fiscal|économi/i', $name_lower))   return 'fa-chart-bar';
                            if (preg_match('/droit|juridi|loi|notariat|avocat/i', $name_lower))                     return 'fa-balance-scale';
                            if (preg_match('/market|comm|publicité|médias|relation/i', $name_lower))                return 'fa-bullhorn';
                            if (preg_match('/logistique|supply|transport|import|export/i', $name_lower))            return 'fa-truck';
                            if (preg_match('/rh|ressource|humain|personnel|social/i', $name_lower))                 return 'fa-users';
                            if (preg_match('/santé|médic|infirmi|pharma|paramédic/i', $name_lower))                 return 'fa-heartbeat';
                            if (preg_match('/bâtiment|civil|architec|génie|travaux/i', $name_lower))               return 'fa-hard-hat';
                            if (preg_match('/agriculture|agrono|élev|environ/i', $name_lower))                      return 'fa-leaf';
                            if (preg_match('/tourisme|hôtell|restaur/i', $name_lower))                             return 'fa-hotel';
                            if (preg_match('/éducation|enseignement|pédagogie/i', $name_lower))                     return 'fa-chalkboard-teacher';
                            if (preg_match('/art|design|graphi|mode/i', $name_lower))                              return 'fa-palette';
                            return 'fa-graduation-cap'; // icône par défaut
                        }
                        ?>
                        <?php foreach ($classes_db as $classe): ?>
                            <?php
                            $class_id   = htmlspecialchars($classe['id'], ENT_QUOTES, 'UTF-8');
                            $class_name = htmlspecialchars($classe['name'], ENT_QUOTES, 'UTF-8');
                            $class_val  = 'class_' . $class_id;
                            $badge      = '';
                            if (!empty($classe['level'])) {
                                $badge = '<small class="class-level-badge">' . htmlspecialchars($classe['level'], ENT_QUOTES, 'UTF-8') . '</small>';
                            } elseif (!empty($classe['code'])) {
                                $badge = '<small class="class-level-badge">' . htmlspecialchars($classe['code'], ENT_QUOTES, 'UTF-8') . '</small>';
                            }
                            $icon = getClassIcon($classe['name']);
                            ?>
                            <div class="specialty-pill" onclick="selectSpecialty(this,'<?= $class_val ?>')" data-class-id="<?= $class_id ?>" data-class-name="<?= $class_name ?>">
                                <i class="fas <?= $icon ?>"></i>
                                <span><?= $class_name ?><?= $badge ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Aucune classe en base — affichage du message d'information -->
                        <div style="grid-column:1/-1; padding:14px; background:rgba(255,149,0,0.08); border:1px dashed #ff9500; border-radius:8px; font-size:13px; color:#7a5800; text-align:center;">
                            <i class="fas fa-info-circle" style="color:#ff9500;"></i>
                            Aucune filière disponible pour le moment. Veuillez préciser votre choix ci-dessous.
                        </div>
                    <?php endif; ?>
                    <div class="specialty-pill autre-pill" onclick="selectSpecialty(this,'autre')"><i class="fas fa-plus-circle"></i> Autre spécialité</div>
                </div>
                <div class="other-input-wrapper" id="specialty_other_wrapper">
                    <span class="other-label"><i class="fas fa-pen" style="font-size:11px;"></i> Précisez la spécialité souhaitée</span>
                    <input type="text" id="specialty_other_input" name="specialite_autre"
                           placeholder="Ex: Architecture, Agronomie, Tourisme, Art..."
                           oninput="onSpecialtyOtherInput(this)">
                </div>
                <input type="hidden" id="selected_specialty" name="specialite">
                <input type="hidden" id="selected_class_id" name="class_id">
                <div class="error-hint" id="specialty_error"></div>
            </div>

            <hr class="section-divider">

            <!-- ===== 6. PIÈCES À FOURNIR ===== -->
            <div class="section-label"><i class="fas fa-folder-open"></i> 6. Pièces à fournir</div>
            <div class="file-grid">

                <div class="form-group">
                    <label>Acte de naissance légalisé *</label>
                    <div class="file-zone" id="zone_acte_naissance">
                        <input type="file" name="acte_naissance" accept=".pdf,.jpg,.jpeg,.png" onchange="handleFile(this,'acte_naissance')">
                        <i class="fas fa-file-alt"></i>
                        <div class="file-zone-title">Acte de naissance</div>
                        <div class="file-zone-desc">PDF, JPG, PNG — Max 10MB<br>ou <span>parcourir</span></div>
                    </div>
                    <div class="file-added" id="added_acte_naissance"><i class="fas fa-check-circle"></i> <span id="name_acte_naissance"></span></div>
                    <div class="error-hint" id="err_acte_naissance"></div>
                </div>

                <div class="form-group">
                    <label>Copie du diplôme (Bac ou autre) *</label>
                    <div class="file-zone" id="zone_diplome">
                        <input type="file" name="diplome" accept=".pdf,.jpg,.jpeg,.png" onchange="handleFile(this,'diplome')">
                        <i class="fas fa-award"></i>
                        <div class="file-zone-title">Copie du diplôme</div>
                        <div class="file-zone-desc">PDF, JPG, PNG — Max 10MB<br>ou <span>parcourir</span></div>
                    </div>
                    <div class="file-added" id="added_diplome"><i class="fas fa-check-circle"></i> <span id="name_diplome"></span></div>
                    <div class="error-hint" id="err_diplome"></div>
                </div>

                <div class="form-group">
                    <label>Relevé de notes *</label>
                    <div class="file-zone" id="zone_releve_notes">
                        <input type="file" name="releve_notes" accept=".pdf,.jpg,.jpeg,.png" onchange="handleFile(this,'releve_notes')">
                        <i class="fas fa-list-ol"></i>
                        <div class="file-zone-title">Relevé de notes</div>
                        <div class="file-zone-desc">PDF, JPG, PNG — Max 10MB<br>ou <span>parcourir</span></div>
                    </div>
                    <div class="file-added" id="added_releve_notes"><i class="fas fa-check-circle"></i> <span id="name_releve_notes"></span></div>
                    <div class="error-hint" id="err_releve_notes"></div>
                </div>

                <div class="form-group">
                    <label>photo d'identité *</label>
                    <div class="file-zone" id="zone_photos">
                        <input type="file" name="photos" accept=".jpg,.jpeg,.png" multiple onchange="handleFile(this,'photos')">
                        <i class="fas fa-id-badge"></i>
                        <div class="file-zone-title">Photos d'identité</div>
                        <div class="file-zone-desc">JPG, PNG — Max 10MB<br>ou <span>parcourir</span></div>
                    </div>
                    <div class="file-added" id="added_photos"><i class="fas fa-check-circle"></i> <span id="name_photos"></span></div>
                    <div class="error-hint" id="err_photos"></div>
                </div>

                <div class="form-group">
                    <label>Attestation d'emploi <small>(Formation continue uniquement)</small></label>
                    <div class="file-zone" id="zone_attestation_emploi">
                        <input type="file" name="attestation_emploi" accept=".pdf,.jpg,.jpeg,.png" onchange="handleFile(this,'attestation_emploi')">
                        <i class="fas fa-briefcase"></i>
                        <div class="file-zone-title">Attestation d'emploi</div>
                        <div class="file-zone-desc">PDF, JPG, PNG — Max 10MB<br>ou <span>parcourir</span></div>
                    </div>
                    <div class="file-added" id="added_attestation_emploi"><i class="fas fa-check-circle"></i> <span id="name_attestation_emploi"></span></div>
                </div>

                <div class="form-group">
                    <label>Curriculum Vitae (CV) <small>(Optionnel)</small></label>
                    <div class="file-zone" id="zone_cv">
                        <input type="file" name="cv" accept=".pdf,.jpg,.jpeg,.png" onchange="handleFile(this,'cv')">
                        <i class="fas fa-file-pdf"></i>
                        <div class="file-zone-title">Votre CV</div>
                        <div class="file-zone-desc">PDF, JPG, PNG — Max 10MB<br>ou <span>parcourir</span></div>
                    </div>
                    <div class="file-added" id="added_cv"><i class="fas fa-check-circle"></i> <span id="name_cv"></span></div>
                </div>

            </div>

            <hr class="section-divider">
            <!-- ===== 7. MODE DE PAIEMENT ===== -->
            <div class="section-label"><i class="fas fa-credit-card"></i> 7. Mode de paiement des frais de scolarité</div>

            <div class="form-group">
                <label>Mode de paiement *</label>
                <div class="payment-grid">
                    <div class="payment-pill" onclick="selectPayment(this,'airtel')"><i class="fas fa-mobile-alt"></i> Airtel Money</div>
                    <div class="payment-pill" onclick="selectPayment(this,'moov')"><i class="fas fa-mobile-alt"></i> Moov Money</div>
                    <div class="payment-pill" onclick="selectPayment(this,'card')"><i class="fas fa-credit-card"></i> Carte bancaire</div>
                    <div class="payment-pill" onclick="selectPayment(this,'virement')"><i class="fas fa-university"></i> Virement bancaire</div>
                    <div class="payment-pill" onclick="selectPayment(this,'especes')"><i class="fas fa-money-bill-wave"></i> Espèces (sur place)</div>
                </div>
                <input type="hidden" name="mode_paiement" id="selected_payment">
                <div class="error-hint" id="payment_error"></div>
            </div>

            <hr class="section-divider">

            <!-- ===== SÉCURITÉ ===== -->
            <div class="section-label"><i class="fas fa-shield-alt"></i> Sécurité du compte</div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="password">Mot de passe * <small>(Min. 6 caractères)</small></label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" placeholder="Mot de passe sécurisé" required minlength="6">
                        <button type="button" class="toggle-pwd" onclick="togglePwd('password','icon-pwd')" tabindex="-1">
                            <i class="fas fa-eye" id="icon-pwd"></i>
                        </button>
                    </div>
                    <div class="password-strength"><div class="password-strength-bar" id="password_strength_bar"></div></div>
                    <div class="error-hint" id="password_error"></div>
                    <div class="success-hint" id="password_success"></div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirmer le mot de passe *</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Répétez le mot de passe" required>
                        <button type="button" class="toggle-pwd" onclick="togglePwd('confirm_password','icon-confirm')" tabindex="-1">
                            <i class="fas fa-eye" id="icon-confirm"></i>
                        </button>
                    </div>
                    <div class="error-hint" id="confirm_error"></div>
                    <div class="success-hint" id="confirm_success">✓ Mots de passe identiques</div>
                </div>
            </div>

            <button type="submit" class="register-btn" id="submitBtn">
                <i class="fas fa-paper-plane"></i> Soumettre ma candidature
            </button>

            <div class="login-link-container">
                <p>Vous avez déjà un compte ?</p>
                <a href="login.html" class="login-link"><strong>Se connecter ici</strong></a>
            </div>

            <div class="copyright">
                © 2024 Université Virtuelle - Développé par Coding Enterprise
            </div>
        </form>
    </div>
</div>

<!-- SUCCESS MODAL -->
<div class="modal-overlay" id="successModal">
    <div class="modal-card">
        <div class="modal-success-icon"><i class="fas fa-check"></i></div>
        <div class="modal-title">Candidature enregistrée !</div>
        <div class="modal-text">
            Votre dossier a été soumis avec succès. Un <strong>accusé de réception PDF</strong> vous sera envoyé à l'adresse email renseignée.
        </div>
        <div class="modal-ref">
            <small>Numéro de dossier</small>
            <span id="refNumber">ISMM-2025-00000</span>
        </div>
        <button class="btn-download" onclick="downloadAccuse()">
            <i class="fas fa-file-pdf"></i> Télécharger l'accusé de réception
        </button>
        <button class="btn-new" onclick="closeModal()">Soumettre une nouvelle candidature</button>
    </div>
</div>

<script>
// ============================
// ÉTAT GLOBAL
// ============================
const formState = {
    avatar: null,
    files: { acte_naissance: null, diplome: null, releve_notes: null, photos: null, attestation_emploi: null, cv: null },
    level: '',
    specialty: '',
    payment: ''
};

// ============================
// NIVEAU D'ÉTUDES — gestion "Autre"
// ============================
function selectLevel(btn) {
    document.querySelectorAll('.level-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const isAutre = btn.dataset.isAutre === 'true';
    const wrapper = document.getElementById('level_other_wrapper');
    const input   = document.getElementById('level_other_input');
    if (isAutre) {
        wrapper.classList.add('visible');
        input.focus();
        formState.level = input.value.trim() || '';
        document.getElementById('selected_level').value = formState.level;
    } else {
        wrapper.classList.remove('visible');
        formState.level = btn.textContent.trim();
        document.getElementById('selected_level').value = formState.level;
    }
    document.getElementById('level_error').style.display = 'none';
}

function onLevelOtherInput(input) {
    formState.level = input.value.trim();
    document.getElementById('selected_level').value = formState.level;
}

// ============================
// EXPÉRIENCE PRO — gestion "Autre"
// ============================
function onExpProChange(select) {
    const wrapper = document.getElementById('exp_other_wrapper');
    const input   = document.getElementById('exp_other_input');
    if (select.value === 'autre') {
        wrapper.classList.add('visible');
        input.focus();
    } else {
        wrapper.classList.remove('visible');
        input.value = '';
    }
}

// ============================
// SPÉCIALITÉ — gestion "Autre"
// ============================
function selectSpecialty(el, val) {
    document.querySelectorAll('.specialty-pill').forEach(p => p.classList.remove('selected'));
    el.classList.add('selected');
    const wrapper = document.getElementById('specialty_other_wrapper');
    const input   = document.getElementById('specialty_other_input');
    const classIdField = document.getElementById('selected_class_id');
    if (val === 'autre') {
        wrapper.classList.add('visible');
        input.focus();
        formState.specialty = input.value.trim() || 'autre';
        document.getElementById('selected_specialty').value = formState.specialty;
        if (classIdField) classIdField.value = '';
    } else {
        wrapper.classList.remove('visible');
        formState.specialty = el.dataset.className || val;
        document.getElementById('selected_specialty').value = formState.specialty;
        if (classIdField) classIdField.value = el.dataset.classId || '';
    }
    document.getElementById('specialty_error').style.display = 'none';
}

function onSpecialtyOtherInput(input) {
    formState.specialty = input.value.trim();
    document.getElementById('selected_specialty').value = formState.specialty;
    const classIdField = document.getElementById('selected_class_id');
    if (classIdField) classIdField.value = '';
}

// ============================
// PASSWORD TOGGLE
// ============================
function togglePwd(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// ============================
// AVATAR
// ============================
function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (!['image/jpeg','image/png','image/gif','image/webp'].includes(file.type)) {
        showToast('⚠ Format non autorisé. Utilisez JPG, PNG, GIF ou WEBP.', 'error'); input.value=''; return;
    }
    if (file.size > 5*1024*1024) { showToast('⚠ Photo trop volumineuse (max 5MB).','error'); input.value=''; return; }
    formState.avatar = file;
    const reader = new FileReader();
    reader.onload = e => {
        const prev = document.getElementById('avatarPreview');
        const icon = document.getElementById('avatarIcon');
        prev.src = e.target.result;
        prev.style.display = 'block';
        icon.style.display = 'none';
    };
    reader.readAsDataURL(file);
}

// ============================
// FILE UPLOAD
// ============================
function handleFile(input, type) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (file.size > 10*1024*1024) { showToast('⚠ Fichier trop volumineux (max 10MB)','error'); input.value=''; return; }
    formState.files[type] = file;
    document.getElementById('zone_'+type).classList.add('has-file');
    document.getElementById('name_'+type).textContent = file.name;
    document.getElementById('added_'+type).style.display = 'flex';
    const errDiv = document.getElementById('err_'+type);
    if (errDiv) errDiv.style.display = 'none';
}

// Drag & drop
document.querySelectorAll('.file-zone').forEach(zone => {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.style.borderColor='#ff9500'; });
    zone.addEventListener('dragleave', () => { zone.style.borderColor=''; });
    zone.addEventListener('drop', e => {
        e.preventDefault(); zone.style.borderColor='';
        const input = zone.querySelector('input[type="file"]');
        if (e.dataTransfer.files.length) {
            const dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]);
            input.files = dt.files;
            input.dispatchEvent(new Event('change'));
        }
    });
});

// ============================
// PAYMENT
// ============================
function selectPayment(el, method) {
    document.querySelectorAll('.payment-pill').forEach(p => p.classList.remove('selected'));
    el.classList.add('selected');
    formState.payment = method;
    document.getElementById('selected_payment').value = method;
    document.getElementById('payment_error').style.display = 'none';
}

// ============================
// VALIDATION CHAMPS
// ============================
function setField(id, ok, errMsg) {
    const el  = document.getElementById(id);
    const err = document.getElementById(id+'_error');
    const suc = document.getElementById(id+'_success');
    if (!el) return;
    if (ok === null) {
        el.classList.remove('valid','invalid');
        if (err) err.style.display='none';
        if (suc) suc.style.display='none';
    } else if (ok) {
        el.classList.add('valid'); el.classList.remove('invalid');
        if (err) err.style.display='none';
        if (suc) suc.style.display='block';
    } else {
        el.classList.add('invalid'); el.classList.remove('valid');
        if (err) { err.textContent=errMsg; err.style.display='block'; }
        if (suc) suc.style.display='none';
    }
}

['nom','prenom'].forEach(id => {
    document.getElementById(id).addEventListener('input', function() {
        const v = this.value.trim();
        if (!v) setField(id, null);
        else if (v.length < 2) setField(id, false, '⚠ Minimum 2 caractères');
        else setField(id, true);
    });
});

document.getElementById('email').addEventListener('input', function() {
    const v = this.value.trim();
    if (!v) setField('email', null);
    else setField('email', /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v), "⚠ Format d'email invalide");
});

document.getElementById('phone').addEventListener('input', function() {
    const v = this.value.trim();
    if (!v) { setField('phone', false, '⚠ Téléphone obligatoire'); return; }
    setField('phone', /^[\+]?[0-9\s\-\(\)]{8,20}$/.test(v), '⚠ Format invalide (+241 01 23 45 67)');
});

document.getElementById('password').addEventListener('input', function() {
    const v   = this.value;
    const bar = document.getElementById('password_strength_bar');
    const suc = document.getElementById('password_success');
    if (!v) { this.classList.remove('valid','invalid'); document.getElementById('password_error').style.display='none'; suc.style.display='none'; bar.className='password-strength-bar'; return; }
    if (v.length < 6) { setField('password', false, '⚠ Minimum 6 caractères'); bar.className='password-strength-bar strength-weak'; return; }
    let s=0;
    if (v.length>=8) s++;
    if (/[a-z]/.test(v)&&/[A-Z]/.test(v)) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[^a-zA-Z0-9]/.test(v)) s++;
    this.classList.add('valid'); this.classList.remove('invalid');
    document.getElementById('password_error').style.display='none'; suc.style.display='block';
    if (s<=1) { bar.className='password-strength-bar strength-weak'; suc.textContent='✓ Faible'; }
    else if (s<=2) { bar.className='password-strength-bar strength-medium'; suc.textContent='✓ Moyen'; }
    else { bar.className='password-strength-bar strength-strong'; suc.textContent='✓ Fort !'; }
    if (document.getElementById('confirm_password').value) document.getElementById('confirm_password').dispatchEvent(new Event('input'));
});

document.getElementById('confirm_password').addEventListener('input', function() {
    const v = this.value;
    if (!v) { setField('confirm_password', null); return; }
    setField('confirm_password', v === document.getElementById('password').value, '⚠ Mots de passe différents');
});

// ============================
// VALIDATION GLOBALE
// ============================
function validateAll() {
    let valid = true;
    const errors = [];

    if (document.getElementById('nom').value.trim().length < 2) {
        setField('nom', false, '⚠ Nom requis (min. 2 caractères)'); errors.push('nom'); valid = false;
    }
    if (document.getElementById('prenom').value.trim().length < 2) {
        setField('prenom', false, '⚠ Prénom requis (min. 2 caractères)'); errors.push('prenom'); valid = false;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(document.getElementById('email').value.trim())) {
        setField('email', false, "⚠ Email invalide"); errors.push('email'); valid = false;
    }
    if (!/^[\+]?[0-9\s\-\(\)]{8,20}$/.test(document.getElementById('phone').value.trim())) {
        setField('phone', false, '⚠ Téléphone invalide'); errors.push('phone'); valid = false;
    }

    const levelOtherBtn = document.querySelector('.level-btn.autre-btn.active');
    if (!formState.level || (levelOtherBtn && !document.getElementById('level_other_input').value.trim())) {
        const e = document.getElementById('level_error');
        e.textContent = levelOtherBtn ? '⚠ Veuillez préciser votre niveau' : '⚠ Sélectionnez un niveau d\'études';
        e.style.display = 'block';
        errors.push('level'); valid = false;
    }

    const specialtyOtherPill = document.querySelector('.specialty-pill.autre-pill.selected');
    if (!formState.specialty || (specialtyOtherPill && !document.getElementById('specialty_other_input').value.trim())) {
        const e = document.getElementById('specialty_error');
        e.textContent = specialtyOtherPill ? '⚠ Veuillez préciser la spécialité' : '⚠ Sélectionnez une spécialité';
        e.style.display = 'block';
        errors.push('specialty'); valid = false;
    }

    ['acte_naissance','diplome','releve_notes','photos'].forEach(doc => {
        if (!formState.files[doc]) {
            const e = document.getElementById('err_'+doc);
            if (e) { e.textContent = '⚠ Document obligatoire'; e.style.display = 'block'; }
            errors.push(doc); valid = false;
        }
    });

    if (!formState.payment) {
        const e = document.getElementById('payment_error');
        e.textContent = '⚠ Sélectionnez un mode de paiement'; e.style.display = 'block';
        errors.push('payment'); valid = false;
    }

    if (document.getElementById('password').value.length < 6) {
        setField('password', false, '⚠ Mot de passe trop court'); errors.push('password'); valid = false;
    }
    if (document.getElementById('password').value !== document.getElementById('confirm_password').value) {
        setField('confirm_password', false, '⚠ Mots de passe différents'); errors.push('confirm'); valid = false;
    }

    if (errors.length > 0) {
        const targets = {
            nom:'nom', prenom:'prenom', email:'email', phone:'phone',
            level:'levelButtons', specialty:'specialty_other_wrapper',
            cv:'zone_cv', diplome:'zone_diplome', cni:'zone_cni',
            payment:'payment_error', password:'password', confirm:'confirm_password'
        };
        const key = errors[0];
        const el = document.getElementById(targets[key] || key);
        if (el) el.scrollIntoView({ behavior:'smooth', block:'center' });
    }
    return valid;
}

// ============================
// SUBMIT
// ============================
document.getElementById('registerForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (!validateAll()) return;

    const expSelect = document.getElementById('exp_pro');
    if (expSelect.value === 'autre') {
        const expOther = document.getElementById('exp_other_input').value.trim();
        expSelect.value = expOther || 'Autre';
    }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> &nbsp; Envoi en cours...';

    try {
        const fd = new FormData(document.getElementById('registerForm'));
        // Forcer les champs hidden mis à jour par JS (au cas où le DOM ne les a pas encore)
        fd.set('niveau',        document.getElementById('selected_level').value);
        fd.set('specialite',    document.getElementById('selected_specialty').value);
        fd.set('class_id',      document.getElementById('selected_class_id').value);
        fd.set('mode_paiement', document.getElementById('selected_payment').value);
        if (formState.avatar)                       fd.set('avatar',              formState.avatar);
        if (formState.files.acte_naissance)         fd.set('acte_naissance',      formState.files.acte_naissance);
        if (formState.files.diplome)                fd.set('diplome',             formState.files.diplome);
        if (formState.files.releve_notes)           fd.set('releve_notes',        formState.files.releve_notes);
        if (formState.files.photos)                 fd.set('photos',              formState.files.photos);
        if (formState.files.attestation_emploi)     fd.set('attestation_emploi',  formState.files.attestation_emploi);
        if (formState.files.cv)                     fd.set('cv',                  formState.files.cv);

        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            document.getElementById('refNumber').textContent = data.ref;
            document.getElementById('successModal').classList.add('show');
        } else {
            showToast('⚠ ' + (data.message || 'Erreur lors de l\'envoi.'), 'error');
        }
    } catch (err) {
        showToast('⚠ Erreur réseau : ' + err.message, 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Soumettre ma candidature';
});

// ============================
// MODAL & ACCUSÉ DE RÉCEPTION PDF
// ============================
function downloadAccuse() {
    const ref    = document.getElementById('refNumber').textContent;
    const nom    = document.getElementById('nom').value.trim();
    const prenom = document.getElementById('prenom').value.trim();
    const email  = document.getElementById('email').value.trim();
    const now    = new Date();
    const dateStr = now.toLocaleDateString('fr-FR',{day:'2-digit',month:'long',year:'numeric'});
    const rdv    = new Date(now.getTime() + 7*24*60*60*1000);
    const rdvStr = rdv.toLocaleDateString('fr-FR',{weekday:'long',day:'2-digit',month:'long',year:'numeric'});

    const niveauFinal = document.getElementById('selected_level').value
        || document.getElementById('level_other_input').value.trim()
        || 'Non précisé';

    const expProSelect = document.getElementById('exp_pro');
    const expFinal = expProSelect.value === 'autre'
        ? (document.getElementById('exp_other_input').value.trim() || 'Autre')
        : (expProSelect.options[expProSelect.selectedIndex]?.text || 'Non précisé');

    const specialtyFinal = document.getElementById('selected_specialty').value
        || document.getElementById('specialty_other_input').value.trim()
        || 'Non précisé';

    const html = `<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
    <title>Accusé de réception — ${ref}</title>
    <style>
        body{font-family:'Segoe UI',sans-serif;max-width:700px;margin:40px auto;padding:40px;color:#1e2d3d;}
        .header{text-align:center;border-bottom:3px solid #ff9500;padding-bottom:20px;margin-bottom:30px;}
        h1{color:#0a1c2e;font-size:22px;}
        .ref-box{background:#f9f9f9;border:2px solid #ff9500;border-radius:8px;padding:16px;text-align:center;margin:20px 0;}
        .ref-box .ref{font-size:22px;font-weight:700;color:#ff9500;letter-spacing:2px;}
        table{width:100%;border-collapse:collapse;margin:20px 0;}
        td{padding:10px 12px;border-bottom:1px solid #eee;font-size:13px;}
        td:first-child{color:#888;width:40%;font-weight:600;}
        td:last-child{color:#0a1c2e;font-weight:500;}
        .rdv-box{background:#0a1c2e;color:white;border-radius:8px;padding:16px;margin:20px 0;}
        .rdv-box h3{color:#ff9500;margin-bottom:8px;}
        .green{color:#2ecc71;font-weight:700;}
        .footer{text-align:center;font-size:11px;color:#999;margin-top:30px;padding-top:20px;border-top:1px solid #eee;}
    </style></head><body>
    <div class="header">
        <h1>ISMM — Accusé de réception</h1>
        <p style="color:#888;font-size:13px;">Émis le ${dateStr}</p>
    </div>
    <div class="ref-box">
        <small>Numéro de dossier</small><br>
        <span class="ref">${ref}</span>
    </div>
    <table>
        <tr><td>Nom complet</td><td>${prenom} ${nom.toUpperCase()}</td></tr>
        <tr><td>Email</td><td>${email}</td></tr>
        <tr><td>Niveau d'études</td><td>${niveauFinal}</td></tr>
        <tr><td>Expérience pro.</td><td>${expFinal}</td></tr>
        <tr><td>Spécialité souhaitée</td><td>${specialtyFinal}</td></tr>
        <tr><td>Mode de paiement</td><td>${formState.payment}</td></tr>
        <tr><td>Statut</td><td><span class="green">✔ Reçu — En cours de traitement</span></td></tr>
    </table>
    <div class="rdv-box">
        <h3>📅 Rendez-vous d'entretien</h3>
        <p>Entretien provisoirement fixé au <strong>${rdvStr}</strong>.<br>
        Confirmation envoyée à <strong>${email}</strong>.</p>
    </div>
    <div class="footer">© 2026 ISMM — Université Virtuelle — Coding Enterprise</div>
    </body></html>`;

    const blob = new Blob([html], {type:'text/html'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `Accuse_Reception_${ref}.html`;
    a.click();
}

function closeModal() {
    document.getElementById('successModal').classList.remove('show');
    document.getElementById('registerForm').reset();
    formState.avatar = null;
    formState.files  = {acte_naissance:null, diplome:null, releve_notes:null, photos:null, attestation_emploi:null, cv:null};
    formState.level  = ''; formState.specialty = ''; formState.payment = '';
    document.querySelectorAll('.level-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.specialty-pill').forEach(p => p.classList.remove('selected'));
    document.querySelectorAll('.payment-pill').forEach(p => p.classList.remove('selected'));
    document.querySelectorAll('.other-input-wrapper').forEach(w => w.classList.remove('visible'));
    document.querySelectorAll('.file-zone').forEach(z => z.classList.remove('has-file'));
    document.querySelectorAll('.file-added').forEach(el => el.style.display='none');
    document.getElementById('avatarPreview').src = '';
    document.getElementById('avatarIcon').style.display = 'flex';
    document.getElementById('avatarPreview').style.display = 'none';
    document.getElementById('password_strength_bar').className = 'password-strength-bar';
}

document.getElementById('successModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ============================
// TOAST
// ============================
function showToast(msg, type='info') {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const t = document.createElement('div');
    t.className = 'toast';
    t.style.cssText = `position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:500;
        color:white;z-index:9999;max-width:320px;box-shadow:0 4px 16px rgba(0,0,0,0.2);
        background:${type==='error'?'#e74c3c':type==='success'?'#2ecc71':'#0a1c2e'};`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity 0.3s'; setTimeout(()=>t.remove(),300); }, 3500);
}
</script>
</body>
</html>