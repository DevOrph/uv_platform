<?php
/**
 * Endpoint de déconnexion
 * 
 * POST /auth/logout.php
 */

require_once '../config/helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Méthode non autorisée', 405);
}

// Pour l'instant, juste confirmer
sendSuccess([], 'Déconnexion réussie');
?>