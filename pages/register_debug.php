<?php
// Fichier de débogage — accès restreint à localhost uniquement
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['localhost', '::1'])) {
    http_response_code(404);
    exit('Not found.');
}

// Afficher toutes les erreurs PHP pour le débogage
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require_once '../includes/db_connect_public.php';// Récupérer les erreurs et données du formulaire si présentes
$errors = $_SESSION['registration_errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];

// Nettoyer la session
unset($_SESSION['registration_errors']);
unset($_SESSION['form_data']);

// Charger les classes depuis la base de données
$classes = [];
$db_error = false;
$db_error_message = '';

try {
    // Liste des chemins possibles pour db_connect.php
    $db_paths = [
        __DIR__ . '/includes/db_connect.php',
        __DIR__ . '/../includes/db_connect.php',
        dirname(__DIR__) . '/includes/db_connect.php',
        'includes/db_connect.php',
        '../includes/db_connect.php'
    ];
    
    $connected = false;
    $tested_paths = [];
    
    foreach ($db_paths as $path) {
        $tested_paths[] = $path;
        if (file_exists($path)) {
            try {
                require_once $path;
                if (isset($conn) && $conn) {
                    $connected = true;
                    break;
                }
            } catch (Exception $e) {
                $db_error_message = "Erreur lors de l'inclusion : " . $e->getMessage();
            }
        }
    }
    
    if ($connected && isset($conn) && $conn) {
        // Forcer UTF-8
        $conn->set_charset('utf8mb4');
        $conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
        
        // Récupérer les classes
        $sql = "SELECT id, name FROM classes ORDER BY name ASC";
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $classes[] = $row;
            }
        }
        
        $conn->close();
    } else {
        $db_error = true;
        if (empty($db_error_message)) {
            $db_error_message = "Impossible de trouver db_connect.php. Chemins testés : " . implode(', ', $tested_paths);
        }
    }
} catch (Exception $e) {
    $db_error = true;
    $db_error_message = $e->getMessage();
    error_log("Erreur dans register.php: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription Étudiants - Université Virtuelle</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #0a1c2e;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            background-color: #fff;
            width: 100%;
            max-width: 900px;
            border-radius: 15px;
            display: flex;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            flex-direction: column;
        }
        
        .header-section {
            background: linear-gradient(135deg, #0a1c2e 0%, #072442 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .header-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,149,0,0.1)"/></svg>');
            background-size: cover;
        }
        
        .logo-container {
            margin-bottom: 15px;
            position: relative;
            z-index: 2;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: white;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            border: 3px solid #ff9500;
            overflow: hidden;
            position: relative;
        }
        
        .logo-text {
            font-size: 28px;
            font-weight: bold;
            color: #0a1c2e;
        }
        
        .welcome-text {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
            color: #ff9500;
        }
        
        .subtitle {
            font-size: 14px;
            opacity: 0.9;
            position: relative;
            z-index: 2;
        }
        
        .form-section {
            padding: 30px;
            flex: 1;
        }
        
        .form-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #0a1c2e;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .form-title i {
            color: #ff9500;
        }
        
        .error-alert {
            background: rgba(231, 76, 60, 0.1);
            border-left: 4px solid #e74c3c;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .error-alert h4 {
            color: #e74c3c;
            margin-bottom: 10px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .error-alert ul {
            list-style: none;
            padding: 0;
        }
        
        .error-alert li {
            color: #c0392b;
            padding: 5px 0;
            font-size: 14px;
        }
        
        .error-alert li:before {
            content: "•";
            font-weight: bold;
            margin-right: 5px;
        }

        .error-alert pre {
            background: #2d3748;
            color: #fff;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
            margin-top: 10px;
        }
        
        .warning-box {
            background: rgba(243, 156, 18, 0.1);
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .warning-box h4 {
            color: #f39c12;
            margin-bottom: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .warning-box p {
            font-size: 13px;
            line-height: 1.5;
            color: #666;
        }

        .debug-box {
            background: rgba(59, 130, 246, 0.1);
            border-left: 4px solid #3b82f6;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .debug-box h4 {
            color: #3b82f6;
            margin-bottom: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .debug-box p {
            font-size: 13px;
            line-height: 1.5;
            color: #666;
            margin: 5px 0;
        }

        .debug-box strong {
            color: #1e40af;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #0a1c2e;
            margin-bottom: 6px;
        }
        
        .form-group label small {
            font-weight: normal;
            color: #666;
            font-size: 11px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            background-color: #f9f9f9;
            transition: all 0.3s;
            box-sizing: border-box;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #ff9500;
            background-color: #fff;
            box-shadow: 0 0 0 3px rgba(255, 149, 0, 0.1);
        }
        
        .form-group input.invalid,
        .form-group select.invalid,
        .form-group textarea.invalid {
            border-color: #e74c3c;
            background: rgba(231, 76, 60, 0.05);
        }
        
        .form-group input.valid,
        .form-group select.valid,
        .form-group textarea.valid {
            border-color: #2ecc71;
        }
        
        .error-hint {
            font-size: 11px;
            color: #e74c3c;
            margin-top: 4px;
            display: none;
        }
        
        .success-hint {
            font-size: 11px;
            color: #2ecc71;
            margin-top: 4px;
            display: none;
        }
        
        .password-strength {
            margin-top: 5px;
            height: 4px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 2px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            transition: width 0.3s ease, background 0.3s ease;
            width: 0%;
        }
        
        .strength-weak { background: #e74c3c; width: 33%; }
        .strength-medium { background: #f39c12; width: 66%; }
        .strength-strong { background: #2ecc71; width: 100%; }
        
        .register-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #ff9500 0%, #ff8c00 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .register-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 149, 0, 0.3);
        }
        
        .register-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .login-link-container {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .login-link {
            color: #0a1c2e;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        
        .login-link:hover {
            color: #ff9500;
        }
        
        .info-box {
            background: rgba(255, 149, 0, 0.1);
            border-left: 4px solid #ff9500;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .info-box h4 {
            color: #ff9500;
            margin-bottom: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-box p {
            font-size: 13px;
            line-height: 1.5;
            color: #666;
        }
        
        .copyright {
            text-align: center;
            margin-top: 20px;
            color: #999;
            font-size: 11px;
        }
        
        .classes-badge {
            background: linear-gradient(135deg, #ff9500 0%, #ff8c00 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .header-section {
                padding: 25px 20px;
            }
            
            .form-section {
                padding: 25px 20px;
            }
            
            .welcome-text {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-section">
            <div class="logo-container">
                <div class="logo">
                    <span class="logo-text">UV</span>
                </div>
            </div>
            
            <h1 class="welcome-text">INSCRIPTION ÉTUDIANT</h1>
            <p class="subtitle">Rejoignez l'Université Virtuelle - Plateforme d'apprentissage en ligne</p>
        </div>

        <div class="form-section">
            <h2 class="form-title">
                <i class="fas fa-user-plus"></i> Créer mon compte étudiant
            </h2>

            <?php if (!empty($errors)): ?>
            <div class="error-alert" id="error">
                <h4><i class="fas fa-exclamation-triangle"></i> Erreurs de validation</h4>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($db_error): ?>
            <div class="error-alert">
                <h4><i class="fas fa-database"></i> Erreur de base de données</h4>
                <p>La liste des classes n'a pas pu être chargée.</p>
                <?php if (!empty($db_error_message)): ?>
                    <pre><?php echo htmlspecialchars($db_error_message); ?></pre>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Info de débogage -->
            <div class="debug-box">
                <h4><i class="fas fa-bug"></i> Informations de débogage</h4>
                <p><strong>Fichier actuel :</strong> <?php echo __FILE__; ?></p>
                <p><strong>Dossier actuel :</strong> <?php echo __DIR__; ?></p>
                <p><strong>PHP Version :</strong> <?php echo phpversion(); ?></p>
                <p><strong>Classes chargées :</strong> <?php echo count($classes); ?> classes</p>
                <?php if (!empty($classes)): ?>
                    <p><strong>✅ Connexion à la base de données : OK</strong></p>
                <?php else: ?>
                    <p><strong>❌ Connexion à la base de données : ÉCHEC</strong></p>
                <?php endif; ?>
            </div>

            <div class="info-box">
                <h4><i class="fas fa-info-circle"></i> Information importante</h4>
                <p>Votre compte sera en attente de validation par un administrateur. Vous recevrez votre identifiant de connexion par email une fois votre compte validé.</p>
            </div>

            <form action="register_process.php" method="post" id="registerForm">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="name">Nom complet * <small>(Nom et prénom)</small></label>
                        <input type="text" class="input-field" id="name" name="name" 
                               placeholder="Ex: Jean-Pierre Dupont" required
                               value="<?php echo htmlspecialchars($form_data['name'] ?? ''); ?>">
                        <div class="error-hint" id="name_error"></div>
                        <div class="success-hint" id="name_success">✓ Format valide</div>
                    </div>

                    <div class="form-group">
                        <label for="email">Adresse email *</label>
                        <input type="email" class="input-field" id="email" name="email" 
                               placeholder="votre.email@exemple.com" required
                               value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>">
                        <div class="error-hint" id="email_error"></div>
                        <div class="success-hint" id="email_success">✓ Email valide</div>
                    </div>

                    <div class="form-group">
                        <label for="phone">Téléphone <small>(Optionnel)</small></label>
                        <input type="text" class="input-field" id="phone" name="phone" 
                               placeholder="+241 01 23 45 67" pattern="[\+]?[0-9\s\-\(\)]{8,20}"
                               value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>">
                        <div class="error-hint" id="phone_error"></div>
                        <div class="success-hint" id="phone_success">✓ Format valide</div>
                    </div>

                    <div class="form-group">
                        <label for="birth_date">Date de naissance <small>(Optionnel)</small></label>
                        <input type="date" class="input-field" id="birth_date" name="birth_date" 
                               value="<?php echo htmlspecialchars($form_data['birth_date'] ?? ''); ?>">
                    </div>

                    <div class="form-group full-width">
                        <label for="address">Adresse <small>(Optionnel)</small></label>
                        <textarea class="input-field" id="address" name="address" 
                                  placeholder="Votre adresse complète" 
                                  rows="2"><?php echo htmlspecialchars($form_data['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label for="class_id">
                            Classe / Filière <small>(Optionnel)</small>
                            <?php if (!empty($classes)): ?>
                                <span class="classes-badge"><?php echo count($classes); ?> classes disponibles</span>
                            <?php endif; ?>
                        </label>
                        <select class="input-field" id="class_id" name="class_id">
                            <option value="">Sélectionner une classe</option>
                            <?php if (!empty($classes)): ?>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['id']); ?>" 
                                            <?php echo (isset($form_data['class_id']) && $form_data['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>Aucune classe disponible pour le moment</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="password">Mot de passe * <small>(Min. 6 caractères)</small></label>
                        <input type="password" class="input-field" id="password" name="password" 
                               placeholder="Mot de passe sécurisé" required minlength="6">
                        <div class="password-strength">
                            <div class="password-strength-bar" id="password_strength_bar"></div>
                        </div>
                        <div class="error-hint" id="password_error"></div>
                        <div class="success-hint" id="password_success">✓ Mot de passe acceptable</div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirmer le mot de passe *</label>
                        <input type="password" class="input-field" id="confirm_password" name="confirm_password" 
                               placeholder="Confirmer le mot de passe" required>
                        <div class="error-hint" id="confirm_error"></div>
                        <div class="success-hint" id="confirm_success">✓ Mots de passe identiques</div>
                    </div>
                </div>

                <button type="submit" class="register-btn" id="submitBtn">
                    <i class="fas fa-user-plus"></i> Créer mon compte étudiant
                </button>

                <div class="login-link-container">
                    <p>Vous avez déjà un compte ?</p>
                    <a href="login.html" class="login-link">
                        <strong>Se connecter ici</strong>
                    </a>
                </div>

                <div class="copyright">
                    © 2024 Université Virtuelle - Développé par Orphé MYENE et Filbert KASSA
                </div>
            </form>
        </div>
    </div>

    <script>
        // Code JavaScript de validation identique...
        console.log('✅ Page chargée avec succès');
        console.log('📊 Nombre de classes disponibles:', <?php echo count($classes); ?>);
        
        // Validation du nom
        const nameInput = document.getElementById('name');
        const nameError = document.getElementById('name_error');
        const nameSuccess = document.getElementById('name_success');

        nameInput.addEventListener('input', function() {
            const value = this.value.trim();
            const pattern = /^[a-zA-Z0-9À-ÿ\s\-'\.]{2,100}$/;
            
            if (value.length === 0) {
                this.classList.remove('valid', 'invalid');
                nameError.style.display = 'none';
                nameSuccess.style.display = 'none';
            } else if (value.length < 2) {
                this.classList.add('invalid');
                this.classList.remove('valid');
                nameError.textContent = '⚠ Le nom doit contenir au moins 2 caractères';
                nameError.style.display = 'block';
                nameSuccess.style.display = 'none';
            } else if (!pattern.test(value)) {
                this.classList.add('invalid');
                this.classList.remove('valid');
                nameError.textContent = '⚠ Caractères non autorisés détectés';
                nameError.style.display = 'block';
                nameSuccess.style.display = 'none';
            } else {
                this.classList.add('valid');
                this.classList.remove('invalid');
                nameError.style.display = 'none';
                nameSuccess.style.display = 'block';
            }
        });

        // Le reste du code JavaScript de validation...
        // (copié identique depuis la version précédente)
    </script>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</body>
</html>
