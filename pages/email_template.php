<?php
/**
 * Fonction pour envoyer un email HTML professionnel avec l'ID de l'étudiant
 * À utiliser en remplacement de la fonction mail() simple dans pending_registrations.php
 */

function send_student_id_email($student_name, $student_email, $student_id) {
    $to = $student_email;
    $subject = "🎓 Votre compte UV a été validé !";
    
    // Email HTML
    $html_message = '
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validation compte UV</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #0a1c2e 0%, #0c2d48 100%); padding: 40px 30px; text-align: center;">
                            <div style="width: 80px; height: 80px; background: white; border-radius: 50%; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 36px; font-weight: bold; color: #0a1c2e; border: 3px solid #ff9500;">
                                UV
                            </div>
                            <h1 style="color: #ff9500; margin: 0; font-size: 28px;">Compte Validé !</h1>
                            <p style="color: #ffffff; margin: 10px 0 0 0; font-size: 16px;">Bienvenue sur l\'Université Virtuelle</p>
                        </td>
                    </tr>
                    
                    <!-- Body -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="color: #333; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
                                Bonjour <strong style="color: #0a1c2e;">' . htmlspecialchars($student_name) . '</strong>,
                            </p>
                            
                            <p style="color: #666; font-size: 15px; line-height: 1.6; margin: 0 0 25px 0;">
                                Nous sommes heureux de vous informer que votre compte sur la plateforme <strong>Université Virtuelle (UV)</strong> a été validé avec succès ! 🎉
                            </p>
                            
                            <!-- ID Box -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background: linear-gradient(135deg, #ff9500 0%, #ff8c00 100%); border-radius: 10px; margin: 0 0 25px 0; padding: 25px;">
                                <tr>
                                    <td style="text-align: center;">
                                        <p style="color: white; font-size: 14px; margin: 0 0 10px 0; text-transform: uppercase; letter-spacing: 1px; font-weight: bold;">
                                            🔑 Votre Identifiant de Connexion
                                        </p>
                                        <p style="color: white; font-size: 32px; font-weight: bold; margin: 0; letter-spacing: 2px; font-family: monospace;">
                                            ' . htmlspecialchars($student_id) . '
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Instructions -->
                            <div style="background: #f8f9fa; border-left: 4px solid #0a1c2e; padding: 20px; margin: 0 0 25px 0; border-radius: 5px;">
                                <h3 style="color: #0a1c2e; margin: 0 0 15px 0; font-size: 18px;">
                                    📋 Comment vous connecter ?
                                </h3>
                                <ol style="color: #666; font-size: 14px; line-height: 1.8; margin: 0; padding-left: 20px;">
                                    <li>Rendez-vous sur la page de connexion de l\'UV</li>
                                    <li>Entrez votre identifiant : <strong style="color: #ff9500;">' . htmlspecialchars($student_id) . '</strong></li>
                                    <li>Saisissez le mot de passe que vous avez défini lors de l\'inscription</li>
                                    <li>Cliquez sur "Se connecter"</li>
                                </ol>
                            </div>
                            
                            <!-- Warning Box -->
                            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 0 0 25px 0; border-radius: 5px;">
                                <p style="color: #856404; font-size: 14px; margin: 0; line-height: 1.6;">
                                    <strong>⚠️ IMPORTANT :</strong> Conservez cet identifiant précieusement. Vous en aurez besoin à chaque connexion à la plateforme.
                                </p>
                            </div>
                            
                            <!-- CTA Button -->
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="padding: 20px 0;">
                                        <a href="https://votre-domaine.com/pages/login.html" 
                                           style="display: inline-block; background: linear-gradient(135deg, #0a1c2e 0%, #0c2d48 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 25px; font-weight: bold; font-size: 16px; box-shadow: 0 4px 15px rgba(10, 28, 46, 0.3);">
                                            🚀 Se connecter maintenant
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background: #f8f9fa; padding: 30px; text-align: center; border-top: 1px solid #e0e0e0;">
                            <p style="color: #999; font-size: 13px; margin: 0 0 10px 0;">
                                Besoin d\'aide ? Contactez-nous à <a href="mailto:support@uv-platform.com" style="color: #ff9500; text-decoration: none;">support@uv-platform.com</a>
                            </p>
                            <p style="color: #999; font-size: 12px; margin: 0 0 10px 0;">
                                Université Virtuelle - Coding Enterprise
                            </p>
                            <p style="color: #ccc; font-size: 11px; margin: 0;">
                                © 2024 Développé par Orphé MYENE et Filbert KASSA
                            </p>
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

    // Email texte brut (fallback)
    $text_message = "Bonjour $student_name,\n\n";
    $text_message .= "Votre compte sur la plateforme Université Virtuelle (UV) a été validé !\n\n";
    $text_message .= "Voici votre identifiant de connexion :\n";
    $text_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $text_message .= "Identifiant : $student_id\n";
    $text_message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $text_message .= "Connectez-vous sur : https://votre-domaine.com/pages/login.html\n\n";
    $text_message .= "IMPORTANT : Conservez cet identifiant précieusement.\n\n";
    $text_message .= "Bienvenue sur l'UV !\n\n";
    $text_message .= "Université Virtuelle - Coding Enterprise\n";
    $text_message .= "© 2024 Développé par Orphé MYENE et Filbert KASSA";
    
    // Headers
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"boundary-uv\"\r\n";
    $headers .= "From: Université Virtuelle <noreply@uv-platform.com>\r\n";
    $headers .= "Reply-To: support@uv-platform.com\r\n";
    
    // Message multipart
    $full_message = "--boundary-uv\r\n";
    $full_message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $full_message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $full_message .= $text_message . "\r\n\r\n";
    $full_message .= "--boundary-uv\r\n";
    $full_message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $full_message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $full_message .= $html_message . "\r\n\r\n";
    $full_message .= "--boundary-uv--";
    
    // Envoyer l'email
    return mail($to, $subject, $full_message, $headers);
}

/**
 * UTILISATION dans pending_registrations.php :
 * 
 * Remplacer le bloc mail() par :
 * 
 * if (send_student_id_email($user['name'], $user['email'], $new_id)) {
 *     $success_message = "✅ Étudiant validé ! ID assigné : <strong>$new_id</strong>. Email HTML envoyé à {$user['email']}.";
 * } else {
 *     $success_message = "✅ Étudiant validé ! ID assigné : <strong>$new_id</strong>. ⚠️ Erreur d'envoi de l'email.";
 * }
 */
?>
