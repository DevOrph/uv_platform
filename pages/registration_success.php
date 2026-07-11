<?php
// registration_success.php
session_start();

// Vérifier qu'on vient bien du processus d'inscription
if (!isset($_SESSION['registration_success'])) {
    header("Location: register.php");
    exit();
}

$student_name = $_SESSION['student_name'] ?? 'Étudiant';
$notification_sent = $_SESSION['notification_sent'] ?? false;
$notification_count = $_SESSION['notification_count'] ?? 0;

// Nettoyer la session
unset($_SESSION['registration_success']);
unset($_SESSION['student_name']);
unset($_SESSION['notification_sent']);
unset($_SESSION['notification_count']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription réussie - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a1c2e 0%, #072442 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .success-container {
            background: white;
            max-width: 600px;
            width: 100%;
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .success-icon {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: scaleIn 0.6s ease 0.2s both;
        }
        
        @keyframes scaleIn {
            from {
                transform: scale(0);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .success-icon i {
            font-size: 60px;
            color: white;
        }
        
        h1 {
            color: #2ecc71;
            font-size: 32px;
            margin-bottom: 15px;
            animation: fadeIn 0.6s ease 0.3s both;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .welcome-text {
            font-size: 20px;
            color: #0a1c2e;
            margin-bottom: 10px;
            font-weight: 600;
            animation: fadeIn 0.6s ease 0.4s both;
        }
        
        .subtitle {
            color: #666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
            animation: fadeIn 0.6s ease 0.5s both;
        }
        
        .info-box {
            background: rgba(255, 149, 0, 0.1);
            border-left: 4px solid #ff9500;
            padding: 20px;
            border-radius: 10px;
            margin: 25px 0;
            text-align: left;
            animation: fadeIn 0.6s ease 0.6s both;
        }
        
        .info-box h3 {
            color: #ff9500;
            font-size: 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-box ul {
            list-style: none;
            padding: 0;
        }
        
        .info-box li {
            color: #555;
            padding: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        
        .info-box li i {
            color: #ff9500;
            font-size: 16px;
            min-width: 20px;
        }
        
        .notification-status {
            background: rgba(3, 155, 229, 0.1);
            border-left: 4px solid #039be5;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: left;
            animation: fadeIn 0.6s ease 0.7s both;
        }
        
        .notification-status.success {
            background: rgba(46, 204, 113, 0.1);
            border-color: #2ecc71;
        }
        
        .notification-status.warning {
            background: rgba(243, 156, 18, 0.1);
            border-color: #f39c12;
        }
        
        .notification-status h4 {
            font-size: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .notification-status.success h4 {
            color: #2ecc71;
        }
        
        .notification-status.warning h4 {
            color: #f39c12;
        }
        
        .notification-status p {
            font-size: 13px;
            color: #666;
            line-height: 1.5;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            animation: fadeIn 0.6s ease 0.8s both;
        }
        
        .btn {
            flex: 1;
            padding: 15px 25px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #ff9500, #ff8c00);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 149, 0, 0.3);
        }
        
        .btn-secondary {
            background: white;
            color: #0a1c2e;
            border: 2px solid #0a1c2e;
        }
        
        .btn-secondary:hover {
            background: #0a1c2e;
            color: white;
        }
        
        .footer-note {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #999;
            font-size: 12px;
            animation: fadeIn 0.6s ease 0.9s both;
        }
        
        @media (max-width: 768px) {
            .success-container {
                padding: 30px 20px;
            }
            
            h1 {
                font-size: 26px;
            }
            
            .welcome-text {
                font-size: 18px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">
            <i class="fas fa-check"></i>
        </div>
        
        <h1>Inscription réussie !</h1>
        
        <p class="welcome-text">
            Bienvenue <?php echo htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8'); ?> ! 🎉
        </p>
        
        <p class="subtitle">
            Votre demande d'inscription a été enregistrée avec succès. 
        </p>
        
        <?php if ($notification_sent): ?>
        <div class="notification-status success">
            <h4>
                <i class="fas fa-bell"></i>
                <?php echo $notification_count; ?> administrateur(s) notifié(s)
            </h4>
            <p>
                Les administrateurs de la plateforme ont été automatiquement informés de votre inscription 
                et vont procéder à la validation de votre compte dans les plus brefs délais.
            </p>
        </div>
        <?php else: ?>
        <div class="notification-status warning">
            <h4>
                <i class="fas fa-exclamation-triangle"></i>
                Notification en attente
            </h4>
            <p>
                Votre inscription a été enregistrée mais l'envoi des notifications automatiques n'a pas fonctionné. 
                Les administrateurs verront quand même votre demande dans leur interface de gestion.
            </p>
        </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h3>
                <i class="fas fa-hourglass-half"></i>
                Prochaines étapes
            </h3>
            <ul>
                <li>
                    <i class="fas fa-user-check"></i>
                    <span>Un administrateur va vérifier et valider votre inscription</span>
                </li>
                <li>
                    <i class="fas fa-id-card"></i>
                    <span>Vous recevrez votre <strong>identifiant étudiant</strong> par email</span>
                </li>
                <li>
                    <i class="fas fa-envelope"></i>
                    <span>Surveillez votre boîte email : <strong><?php echo htmlspecialchars($student_email ?? 'votre email', ENT_QUOTES, 'UTF-8'); ?></strong></span>
                </li>
                <li>
                    <i class="fas fa-clock"></i>
                    <span>La validation prend généralement <strong>24 à 48 heures</strong></span>
                </li>
                <li>
                    <i class="fas fa-rocket"></i>
                    <span>Une fois validé, vous pourrez vous connecter et commencer vos cours !</span>
                </li>
            </ul>
        </div>
        
        <div class="action-buttons">
            <a href="login.html" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i>
                Aller à la page de connexion
            </a>
            <a href="../index.php" class="btn btn-secondary">
                <i class="fas fa-home"></i>
                Retour à l'accueil
            </a>
        </div>
        
        <div class="footer-note">
            <p>
                <i class="fas fa-info-circle"></i>
                En cas de problème ou question, contactez-nous à 
                <strong>contact@uvcoding.com</strong>
            </p>
            <p style="margin-top: 10px;">
                © 2024 Université Virtuelle - Tous droits réservés
            </p>
        </div>
    </div>
</body>
</html>
