<?php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../vendor/autoload.php';

use SendGrid\Mail\Mail;
date_default_timezone_set('Africa/Libreville');

$message = "";
$success = false;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["email"])) {
    $email = trim($_POST["email"]);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "❌ Adresse e-mail invalide.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        if ($result->num_rows > 0) {
            $token = bin2hex(random_bytes(50));
            $expiration = date("Y-m-d H:i:s", strtotime('+1 hour'));

            $upd = $conn->prepare("UPDATE users SET reset_token = ?, token_expiration = ? WHERE email = ?");
            $upd->bind_param("sss", $token, $expiration, $email);
            $upd->execute();
            $upd->close();

            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $resetLink = $protocol . "://" . $host . "/pages/reset_password.php?token=" . $token;

            try {
                $emailToSend = new Mail();
                $emailToSend->setFrom("contact@uvcoding.com", "Université Virtuelle");
                $emailToSend->addTo($email);
                $emailToSend->setSubject("Réinitialisation de votre mot de passe");
                $emailToSend->setTemplateId("d-a5bdb05a6bb34e868afd5730a5eb8ee6");
                $emailToSend->addDynamicTemplateDatas([
                    "reset_link" => $resetLink,
                    "user_email" => $email
                ]);
                $emailToSend->setReplyTo("contact@uvcoding.com", "Université Virtuelle");
                $emailToSend->setClickTracking(false, false);
                $emailToSend->setOpenTracking(false);

                $sg_res = $conn->query("SELECT valeur FROM parametres WHERE cle='sendgrid_api_key' LIMIT 1");
                $sg_key = $sg_res ? trim($sg_res->fetch_assoc()['valeur'] ?? '') : '';
                if (empty($sg_key)) { throw new Exception('Clé API SendGrid non configurée dans parametres'); }
                $sendgrid = new \SendGrid($sg_key);
                $response = $sendgrid->send($emailToSend);

                if ($response->statusCode() == 202) {
                    $message = "📧 Un e-mail de réinitialisation a été envoyé à votre adresse.";
                    $success = true;
                } else {
                    $message = "❌ Échec de l'envoi via SendGrid. Code : " . $response->statusCode();
                }
            } catch (Exception $e) {
                $message = "❌ Erreur : " . $e->getMessage();
            }
        } else {
            $message = "❌ Aucun utilisateur trouvé avec cet e-mail.";
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Réinitialisation</title>
  <style>
    body {
      background-color: #0a1c2e;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      padding: 20px;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .container {
      background-color: #fff;
      padding: 30px;
      border-radius: 15px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
      text-align: center;
      max-width: 400px;
      width: 100%;
    }

    .message {
      font-size: 16px;
      padding: 15px;
      border-radius: 10px;
      margin-bottom: 20px;
      color: #fff;
    }

    .success {
      background-color: #28a745;
    }

    .error {
      background-color: #dc3545;
    }

    a {
      color: #0a1c2e;
      text-decoration: none;
      font-weight: bold;
    }

    @media (max-width: 480px) {
      .container {
        padding: 20px;
      }

      .message {
        font-size: 14px;
        padding: 10px;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="message <?= $success ? 'success' : 'error' ?>">
      <?= htmlspecialchars($message, ENT_QUOTES) ?>
    </div>
    <p>Redirection en cours...</p>
  </div>

  <script>
    setTimeout(function () {
      window.location.href = 'forgot_password.php';
    }, 5000);
  </script>
</body>
</html>