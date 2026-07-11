<?php
date_default_timezone_set('Africa/Libreville');
require_once '../includes/db_connect.php';

$token = $_GET['token'] ?? null;
$message = '';
$success = false;
$userId = null;

// Vérification du token au chargement de la page
if ($token && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $tokenEscaped = $conn->real_escape_string($token);

    $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND token_expiration > NOW()");
    $stmt->bind_param("s", $tokenEscaped);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $message = "❌ Lien invalide ou expiré. Veuillez demander un nouveau lien.";
    } else {
        $user = $result->fetch_assoc();
        $userId = $user['id'];
    }
}

// Soumission du nouveau mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'], $_POST['confirm_password'], $_POST['token'])) {
    $token = $conn->real_escape_string($_POST['token']);
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    if ($newPassword !== $confirmPassword) {
        $message = "❌ Les mots de passe ne correspondent pas.";
    } elseif (strlen($newPassword) < 7) {
        $message = "❌ Le mot de passe doit contenir au moins 7 Caractères.";
    } else {
        // Vérifier le token avant mise à jour
        $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND token_expiration > NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $userId = $user['id'];

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, token_expiration = NULL WHERE id = ? AND reset_token = ?");
            $stmt->bind_param("sis", $hashedPassword, $userId, $token);

            if ($stmt->execute() && $stmt->affected_rows === 1) {
                $success = true;
                $message = "✅ Mot de passe mis à jour avec succès. Redirection...";
                echo "<script>
                    setTimeout(function() {
                        window.location.href = 'login.html';
                    }, 3000);
                </script>";
            } else {
                $message = "❌ Erreur lors de la mise à jour du mot de passe.";
            }
        } else {
            $message = "❌ Lien invalide ou expiré. Veuillez demander un nouveau lien.";
        }
    }
} else if (!$token) {
    $message = "❌ Lien de réinitialisation manquant.";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau mot de passe</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, sans-serif; }
        body { background-color: #0a1c2e; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .container { background-color: #fff; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.25); width: 100%; max-width: 400px; padding: 40px 30px; }
        .logo { width: 80px; height: 80px; border-radius: 50%; background-color: #fff; border: 2px solid #ff9500; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 28px; font-weight: bold; color: #0a1c2e; }
        h2 { text-align: center; margin-bottom: 25px; color: #0a1c2e; font-size: 24px; }
        .input-container { position: relative; margin-bottom: 20px; }
        .input-container input { width: 100%; padding: 12px 45px 12px 15px; border: 1px solid #ccc; border-radius: 25px; font-size: 14px; background-color: #f8f8f8; }
        .toggle-password { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; font-size: 18px; }
        button { width: 100%; padding: 12px; background-color: #ff9500; border: none; border-radius: 25px; color: white; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .message { text-align: center; margin-bottom: 20px; font-size: 14px; padding: 12px; border-radius: 10px; }
        .success { background-color: #d4edda; color: #155724; }
        .error { background-color: #f8d7da; color: #721c24; }
        a { display: block; text-align: center; margin-top: 20px; color: #0a1c2e; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">UV</div>
        <h2>Réinitialiser le mot de passe</h2>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo $success ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if (!$success && $token && $userId && empty($message)): ?>
        <form method="post" action="reset_password.php" id="resetForm">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">

            <div class="input-container">
                <input type="password" id="new_password" name="new_password" placeholder="Nouveau mot de passe" required minlength="7">
                <span class="toggle-password" data-target="new_password">👁️</span>
            </div>

            <div class="input-container">
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirmer le mot de passe" required minlength="7">
                <span class="toggle-password" data-target="confirm_password">👁️</span>
            </div>

            <button type="submit">Réinitialiser</button>
        </form>

        <?php elseif(!$success): ?>
            <a href="forgot_password.php">← Demander un nouveau lien</a>
        <?php endif; ?>
    </div>

    <script>
        document.querySelectorAll('.toggle-password').forEach(function(toggle) {
            toggle.addEventListener('click', function() {
                const input = document.getElementById(this.dataset.target);
                input.type = input.type === 'password' ? 'text' : 'password';
                this.textContent = input.type === 'password' ? '👁️' : '🙈';
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>
