<?php
// Démarrer la session
session_start();

// Détruire toutes les variables de session
$_SESSION = array();

// Si vous souhaitez détruire la session complètement, utilisez également la fonction session_destroy()
session_destroy();

// Rediriger vers la page de connexion
header("Location: login.html");
exit();
?>
