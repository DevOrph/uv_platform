<?php
session_start();

$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : "Une erreur s'est produite. Veuillez réessayer.";

// Supprimer le message après affichage
unset($_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erreur - UV Platform</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="error-container">
        <div class="error-box">
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
            <div class="actions">
                <a href="javascript:history.back()" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Retour</a>
                <a href="../pages/dashboard.php" class="btn btn-primary"><i class="fas fa-home"></i> Retour au Dashboard</a>
            </div>
        </div>
    </div>

    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8d7da;
            color: #721c24;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .error-container {
            text-align: center;
        }

        .error-box {
            background: #fff;
            padding: 20px 40px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .error-message {
            font-size: 18px;
            margin-bottom: 20px;
        }

        .error-message i {
            color: #dc3545;
            margin-right: 8px;
        }

        .actions .btn {
            display: inline-block;
            margin: 10px;
            padding: 10px 20px;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
        }

        .btn-primary {
            background-color: #007bff;
        }

        .btn-primary:hover {
            background-color: #0056b3;
        }

        .btn-secondary {
            background-color: #6c757d;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }
    </style>
</body>
</html>
