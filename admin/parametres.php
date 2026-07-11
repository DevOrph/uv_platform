<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$success_message = '';
$error_message   = '';

// ── Semestre forcé : lecture avant fermeture de connexion ─────────────────
$r_sf = $conn->query("SELECT valeur FROM parametres WHERE cle = 'semestre_force' LIMIT 1");
$current_semestre_force = '';
if ($r_sf && $row_sf = $r_sf->fetch_assoc()) {
    $current_semestre_force = $row_sf['valeur'];
}

// ── Traitement du formulaire semestre (séparé du formulaire principal) ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_semestre'])) {
    $sf_val = $_POST['semestre_force'] ?? '';
    if (in_array($sf_val, ['', '1', '2'], true)) {
        $sf_stmt = $conn->prepare(
            "INSERT INTO parametres (cle, valeur) VALUES ('semestre_force', ?)
             ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)"
        );
        $sf_stmt->bind_param('s', $sf_val);
        if ($sf_stmt->execute()) {
            $current_semestre_force = $sf_val;
            $success_message = 'Semestre courant mis à jour.';
        } else {
            $error_message = 'Erreur lors de la mise à jour du semestre.';
        }
        $sf_stmt->close();
    }
}

// Clés gérées par cette page, avec leur libellé pour l'affichage
$managed_keys = [
    'banque_nom'          => 'Nom de la banque',
    'banque_compte'       => 'Numéro de compte bancaire',
    'mobile_money_nom'    => 'Nom du service Mobile Money',
    'mobile_money_numero' => 'Numéro Mobile Money',
    'contact_telephone'   => 'Téléphone du service financier',
    'contact_email_admin' => 'Email du service financier',
    'sendgrid_api_key'    => 'Clé API SendGrid',
];

// S'assurer que toutes les clés existent dans la table (INSERT IGNORE)
foreach (array_keys($managed_keys) as $cle) {
    $conn->query("INSERT IGNORE INTO parametres (cle, valeur) VALUES ('" . $conn->real_escape_string($cle) . "', '')");
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_params'])) {
    $errors = [];
    foreach (array_keys($managed_keys) as $cle) {
        $val  = trim($_POST[$cle] ?? '');
        $stmt = $conn->prepare("UPDATE parametres SET valeur = ? WHERE cle = ?");
        $stmt->bind_param('ss', $val, $cle);
        if (!$stmt->execute()) {
            $errors[] = $cle;
        }
        $stmt->close();
    }
    if (empty($errors)) {
        $success_message = 'Paramètres enregistrés avec succès.';
    } else {
        $error_message = 'Erreur lors de la mise à jour de : ' . implode(', ', $errors);
    }
}

// Lecture des valeurs actuelles
$current = array_fill_keys(array_keys($managed_keys), '');
$ph   = implode(',', array_fill(0, count($managed_keys), '?'));
$keys = array_keys($managed_keys);
$stmt = $conn->prepare("SELECT cle, valeur FROM parametres WHERE cle IN ($ph)");
$stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $current[$row['cle']] = $row['valeur'];
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
    <script>
    (function() {
        var token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!token) return;
        window.CSRF_TOKEN = token;
        var originalFetch = window.fetch;
        window.fetch = function(url, options) {
            options = options || {};
            var method = (options.method || 'GET').toUpperCase();
            if (method === 'GET') return originalFetch(url, options);
            options.headers = options.headers || {};
            if (options.headers instanceof Headers) {
                options.headers.set('X-CSRF-Token', token);
            } else {
                options.headers['X-CSRF-Token'] = token;
            }
            return originalFetch(url, options);
        };
    })();
    </script>
    <title>Paramètres - Administration</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --text-light: #ffffff;
            --card-bg: rgba(255, 255, 255, 0.08);
            --border-color: rgba(255, 255, 255, 0.12);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-bg) 100%);
            color: var(--text-light);
            min-height: 100vh;
        }
        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.15);
            border-bottom: 1px solid var(--border-color);
        }
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .header-title {
            font-size: 22px;
            color: var(--accent-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .back-link {
            color: #ccc;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            transition: all 0.2s;
        }
        .back-link:hover { background: rgba(255,255,255,0.08); color: white; }
        .container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .alert {
            padding: 14px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .alert-success { background: rgba(39,174,96,0.12); border: 1px solid var(--success-color); color: #2ecc71; }
        .alert-error   { background: rgba(231,76,60,0.12);  border: 1px solid var(--danger-color);  color: #e74c3c; }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 25px;
        }
        .card-title {
            color: var(--accent-color);
            font-size: 18px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-subtitle {
            color: #aaa;
            font-size: 13px;
            margin-bottom: 24px;
        }
        .section-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #aaa;
            margin: 24px 0 14px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border-color);
        }
        .section-label:first-child { margin-top: 0; }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            color: #ccc;
            margin-bottom: 6px;
        }
        .form-group label span.required { color: var(--danger-color); margin-left: 3px; }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            background: rgba(255,255,255,0.07);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: white;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control::placeholder { color: #666; }
        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(3,155,229,0.15);
        }
        .hint {
            font-size: 12px;
            color: #888;
            margin-top: 4px;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 30px;
        }
        .btn {
            padding: 11px 28px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--accent-color); color: white; }
        .btn-primary:hover { background: #0288d1; box-shadow: 0 4px 12px rgba(3,155,229,0.3); }
        .info-box {
            background: rgba(3,155,229,0.08);
            border: 1px solid rgba(3,155,229,0.25);
            border-radius: 8px;
            padding: 14px 18px;
            font-size: 13px;
            color: #aad4f5;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .info-box i { margin-top: 2px; flex-shrink: 0; }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <div class="header-title">
            <i class="fas fa-sliders-h"></i>
            Paramètres de l'application
        </div>
        <a href="admin_dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Tableau de bord
        </a>
    </div>
</header>

<div class="container">

    <?php if ($success_message): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success_message); ?>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error_message); ?>
    </div>
    <?php endif; ?>

    <div class="info-box" style="margin-bottom: 24px;">
        <i class="fas fa-info-circle"></i>
        <div>
            Ces coordonnées sont affichées dans les emails de rappel de paiement envoyés aux étudiants,
            ainsi que dans la section <strong>Comment Payer</strong> de leur espace.
            Laissez un champ vide pour masquer la ligne correspondante.
        </div>
    </div>

    <form method="POST">
        <input type="hidden" name="save_params" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">

        <div class="card">
            <div class="card-title"><i class="fas fa-university"></i> Coordonnées bancaires</div>
            <div class="card-subtitle">Informations pour les virements bancaires</div>

            <div class="form-group">
                <label>Nom de la banque</label>
                <input type="text" name="banque_nom" class="form-control"
                       placeholder="ex. BGFI Bank Gabon"
                       value="<?php echo htmlspecialchars($current['banque_nom']); ?>">
                <div class="hint">Affiché comme : <em>Par virement : [Nom] - Compte [N°]</em></div>
            </div>

            <div class="form-group">
                <label>Numéro de compte</label>
                <input type="text" name="banque_compte" class="form-control"
                       placeholder="ex. 40001-00000-1234567890X"
                       value="<?php echo htmlspecialchars($current['banque_compte']); ?>">
            </div>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-mobile-alt"></i> Mobile Money</div>
            <div class="card-subtitle">Numéro pour les paiements via Mobile Money</div>

            <div class="form-group">
                <label>Nom du service</label>
                <input type="text" name="mobile_money_nom" class="form-control"
                       placeholder="ex. Airtel Money"
                       value="<?php echo htmlspecialchars($current['mobile_money_nom']); ?>">
                <div class="hint">Affiché comme : <em>Mobile Money : [Nom] : [Numéro]</em></div>
            </div>

            <div class="form-group">
                <label>Numéro</label>
                <input type="text" name="mobile_money_numero" class="form-control"
                       placeholder="ex. +241 07 12 34 56"
                       value="<?php echo htmlspecialchars($current['mobile_money_numero']); ?>">
            </div>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-headset"></i> Contact du service financier</div>
            <div class="card-subtitle">Coordonnées affichées dans les emails et sur la page étudiant</div>

            <div class="form-group">
                <label>Téléphone</label>
                <input type="text" name="contact_telephone" class="form-control"
                       placeholder="ex. +241 01 23 45 67"
                       value="<?php echo htmlspecialchars($current['contact_telephone']); ?>">
            </div>

            <div class="form-group">
                <label>Adresse email</label>
                <input type="email" name="contact_email_admin" class="form-control"
                       placeholder="ex. finance@universite.ga"
                       value="<?php echo htmlspecialchars($current['contact_email_admin']); ?>">
                <div class="hint">Utilisée aussi comme expéditeur (<em>From:</em>) des emails automatiques.</div>
            </div>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-envelope-open-text"></i> Envoi d'emails (SendGrid)</div>
            <div class="card-subtitle">Clé API pour l'envoi des emails transactionnels (confirmation, mot de passe, notifications)</div>

            <div class="form-group">
                <label>Clé API SendGrid</label>
                <div style="position:relative;">
                    <input type="password" name="sendgrid_api_key" id="sgKeyInput" class="form-control"
                           placeholder="SG.xxxxxxxx..."
                           value="<?php echo htmlspecialchars($current['sendgrid_api_key']); ?>"
                           autocomplete="off" style="padding-right:120px;">
                    <button type="button" onclick="toggleSgKey()"
                            style="position:absolute;right:8px;top:50%;transform:translateY(-50%);
                                   background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);
                                   color:#ccc;padding:4px 12px;border-radius:4px;cursor:pointer;font-size:12px;white-space:nowrap;">
                        <i class="fas fa-eye" id="sgToggleIcon"></i>
                        <span id="sgToggleLabel">Afficher</span>
                    </button>
                </div>
                <div class="hint">Commence par <code style="background:rgba(255,255,255,0.08);padding:1px 5px;border-radius:3px;">SG.</code> — Gérer vos clés sur app.sendgrid.com &gt; Settings &gt; API Keys</div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer les paramètres
                </button>
            </div>
        </div>

    </form>

    <!-- ── Section Semestre courant (formulaire indépendant) ── -->
    <form method="POST">
        <input type="hidden" name="save_semestre" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
        <div class="card">
            <div class="card-title"><i class="fas fa-calendar-alt"></i> Semestre courant</div>
            <div class="card-subtitle">
                Forcer le semestre actif utilisé pour les présences, les notes et les statistiques.
                En mode automatique, le semestre est déduit de la date du jour.
            </div>

            <div style="display:flex;flex-direction:column;gap:14px;">
                <?php
                $sem_options = [
                    ''  => ['label' => 'Automatique (par date)',  'icon' => 'fa-clock',            'color' => '#039be5'],
                    '1' => ['label' => 'Forcer Semestre 1 (S1)', 'icon' => 'fa-calendar-check',   'color' => '#27ae60'],
                    '2' => ['label' => 'Forcer Semestre 2 (S2)', 'icon' => 'fa-calendar-check',   'color' => '#f39c12'],
                ];
                foreach ($sem_options as $val => $opt):
                    $checked  = ($current_semestre_force === (string)$val) ? 'checked' : '';
                    $is_active = $current_semestre_force === (string)$val;
                ?>
                <label style="display:flex;align-items:center;gap:14px;padding:14px 18px;
                              border-radius:10px;cursor:pointer;border:1px solid <?php echo $is_active ? $opt['color'] : 'rgba(255,255,255,.1)'; ?>;
                              background:<?php echo $is_active ? 'rgba(255,255,255,.05)' : 'transparent'; ?>;
                              transition:border-color .2s,background .2s;">
                    <input type="radio" name="semestre_force" value="<?php echo htmlspecialchars($val); ?>"
                           <?php echo $checked; ?>
                           style="accent-color:<?php echo $opt['color']; ?>;width:18px;height:18px;">
                    <i class="fas <?php echo $opt['icon']; ?>" style="color:<?php echo $opt['color']; ?>;font-size:18px;width:22px;text-align:center;"></i>
                    <div>
                        <div style="font-weight:600;font-size:14px;"><?php echo $opt['label']; ?></div>
                        <?php if ($val === ''): ?>
                        <div class="hint">Le système calcule automatiquement S1 ou S2 selon la date du jour.</div>
                        <?php elseif ($val === '1'): ?>
                        <div class="hint">Tous les filtres semestriels utilisent le Semestre 1, quelle que soit la date.</div>
                        <?php else: ?>
                        <div class="hint">Tous les filtres semestriels utilisent le Semestre 2, quelle que soit la date.</div>
                        <?php endif; ?>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Enregistrer le semestre
                </button>
            </div>
        </div>
    </form>


</div>
<script>
function toggleSgKey() {
    var inp   = document.getElementById('sgKeyInput');
    var icon  = document.getElementById('sgToggleIcon');
    var label = document.getElementById('sgToggleLabel');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fas fa-eye-slash';
        label.textContent = 'Masquer';
    } else {
        inp.type = 'password';
        icon.className = 'fas fa-eye';
        label.textContent = 'Afficher';
    }
}
</script>
</body>
</html>
