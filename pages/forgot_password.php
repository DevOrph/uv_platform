<?php require_once '../includes/db_connect.php'; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mot de passe oublié</title>
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
      height: 100vh;
      padding: 20px;
    }

    .container {
      background-color: #fff;
      border-radius: 15px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
      width: 100%;
      max-width: 400px;
      padding: 40px 30px;
      position: relative;
    }

    .logo {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background-color: #fff;
      border: 2px solid #ff9500;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px auto;
      font-size: 28px;
      font-weight: bold;
      color: #0a1c2e;
    }

    h2 {
      text-align: center;
      margin-bottom: 25px;
      color: #0a1c2e;
      font-size: 24px;
    }

    .input-container {
      margin-bottom: 20px;
    }

    .input-container input {
      width: 100%;
      padding: 12px 15px;
      border: 1px solid #ccc;
      border-radius: 25px;
      font-size: 14px;
      background-color: #f8f8f8;
    }

    button {
      width: 100%;
      padding: 12px;
      background-color: #ff9500;
      border: none;
      border-radius: 25px;
      color: white;
      font-size: 16px;
      font-weight: bold;
      cursor: pointer;
      transition: background 0.3s;
    }

    button:hover {
      background-color: #e78900;
    }

    a {
      display: block;
      text-align: center;
      margin-top: 20px;
      color: #0a1c2e;
      text-decoration: none;
      font-size: 14px;
    }

    a:hover {
      text-decoration: underline;
      color: #ff9500;
    }

    @media (max-width: 480px) {
      .container {
        padding: 30px 20px;
      }

      .logo {
        width: 60px;
        height: 60px;
        font-size: 24px;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="logo">UV</div>
    <h2>Mot de passe oublié</h2>
    <form action="process_forgot_password.php" method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
      <div class="input-container">
        <input type="email" name="email" placeholder="Entrez votre adresse e-mail" required>
      </div>
      <button type="submit">Envoyer le lien</button>
    </form>
    <a href="login.html">← Retour à la connexion</a>
  </div>
</body>
</html>
