<?php
/**
 * Middleware d'authentification JWT
 * Appelé par le routeur sur les routes protégées
 */
class Auth {

    /**
     * Vérifie le Bearer token et retourne le payload utilisateur.
     * Coupe la requête avec 401 si le token est absent ou invalide.
     */
    public static function requireAuth(): object {
        $token = JWT::extractFromHeader();

        if (!$token) {
            Response::error('Token manquant. Veuillez vous connecter.', 401);
        }

        $payload = JWT::verifyAccessToken($token);

        if (!$payload) {
            Response::error('Token invalide ou expiré.', 401);
        }

        return $payload;
    }

    /**
     * Vérifie le rôle de l'utilisateur connecté
     */
    public static function requireRole(object $user, string|array $roles): void {
        $roles = (array) $roles;
        if (!in_array($user->role, $roles, true)) {
            Response::error('Accès non autorisé pour ce rôle.', 403);
        }
    }
}
