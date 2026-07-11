<?php
/**
 * AuthController — login, logout, refresh, forgot-password
 */
class AuthController {

    public function __construct(private PDO $db) {}

    // ─── GET /ping ────────────────────────────────────────────────────────
    public function ping(?object $currentUser): void {
        Response::success([
            'version' => APP_VERSION,
            'env'     => APP_ENV,
            'time'    => date('Y-m-d H:i:s'),
        ], 'API opérationnelle');
    }

    // ─── POST /auth/login ─────────────────────────────────────────────────
    public function login(?object $currentUser): void {
        $body = Response::getJsonBody();
        Response::requireFields($body, ['identifiant', 'password']);

        $identifiant = trim($body['identifiant']);
        $password    = $body['password'];

        // Chercher par ID ou email
        $stmt = $this->db->prepare(
            'SELECT id, name, email, password, role, blocked, avatar
             FROM users
             WHERE id = ? OR email = ?
             LIMIT 1'
        );
        $stmt->execute([$identifiant, $identifiant]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('Identifiant ou mot de passe incorrect.', 401);
        }

        if ((int) $user['blocked'] === 1) {
            Response::error('Compte bloqué. Contactez l\'administrateur.', 403);
        }

        if (!password_verify($password, $user['password'])) {
            $this->logFailedLogin($identifiant);
            Response::error('Identifiant ou mot de passe incorrect.', 401);
        }

        // Mettre à jour last_login
        $this->db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
                 ->execute([$user['id']]);

        // Générer les tokens
        $accessToken  = JWT::generateAccessToken($user);
        $refreshToken = JWT::generateRefreshToken();

        // Stocker le refresh token en base
        $this->storeRefreshToken($user['id'], $refreshToken);

        // Logger
        $this->logSuccessfulLogin($user['id']);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => JWT_ACCESS_EXPIRY,
            'user' => [
                'id'     => $user['id'],
                'name'   => $user['name'],
                'email'  => $user['email'],
                'role'   => $user['role'],
                'avatar' => $user['avatar'],
            ],
        ], 'Connexion réussie');
    }

    // ─── POST /auth/logout ────────────────────────────────────────────────
    public function logout(?object $currentUser): void {
        $body = Response::getJsonBody();
        $refreshToken = $body['refresh_token'] ?? null;

        if ($refreshToken) {
            $stmt = $this->db->prepare(
                'DELETE FROM refresh_tokens WHERE token = ?'
            );
            $stmt->execute([$refreshToken]);
        }

        Response::success(null, 'Déconnexion réussie');
    }

    // ─── POST /auth/refresh ───────────────────────────────────────────────
    public function refresh(?object $currentUser): void {
        $body = Response::getJsonBody();
        Response::requireFields($body, ['refresh_token']);

        $refreshToken = $body['refresh_token'];

        // Vérifier en base que le token existe et n'est pas expiré
        $stmt = $this->db->prepare(
            'SELECT rt.user_id, u.id, u.name, u.email, u.role, u.blocked, u.profile_photo
             FROM refresh_tokens rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.token = ?
               AND rt.expires_at > NOW()
               AND rt.revoked = 0
             LIMIT 1'
        );
        $stmt->execute([$refreshToken]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('Refresh token invalide ou expiré. Veuillez vous reconnecter.', 401);
        }

        if ((int) $row['blocked'] === 1) {
            Response::error('Compte bloqué.', 403);
        }

        // Rotation du refresh token (révoquer l'ancien, créer un nouveau)
        $this->db->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE token = ?')
                 ->execute([$refreshToken]);

        $newAccessToken  = JWT::generateAccessToken($row);
        $newRefreshToken = JWT::generateRefreshToken();
        $this->storeRefreshToken($row['id'], $newRefreshToken);

        Response::success([
            'access_token'  => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in'    => JWT_ACCESS_EXPIRY,
        ], 'Token rafraîchi');
    }

    // ─── POST /auth/forgot-password ───────────────────────────────────────
    public function forgotPassword(?object $currentUser): void {
        $body = Response::getJsonBody();
        Response::requireFields($body, ['email']);

        $email = trim($body['email']);

        $stmt = $this->db->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Réponse identique qu'il existe ou non (sécurité)
        if (!$user) {
            Response::success(null, 'Si cet email existe, un lien de réinitialisation a été envoyé.');
        }

        // Générer un token de reset (1 heure)
        $resetToken = bin2hex(random_bytes(32));
        $expiresAt  = date('Y-m-d H:i:s', time() + 3600);

        $this->db->prepare(
            'INSERT INTO password_resets (user_id, token, expires_at, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = NOW()'
        )->execute([$user['id'], $resetToken, $expiresAt]);

        // TODO Phase 4 : envoyer l'email via PHPMailer/SendGrid
        // Pour l'instant, retourner le token en dev uniquement
        $responseData = APP_ENV === 'development' ? ['reset_token' => $resetToken] : null;

        Response::success($responseData, 'Si cet email existe, un lien de réinitialisation a été envoyé.');
    }

    // ─── Helpers privés ───────────────────────────────────────────────────

    private function storeRefreshToken(string $userId, string $token): void {
        $expiresAt = date('Y-m-d H:i:s', time() + JWT_REFRESH_EXPIRY);

        // ip_address et user_agent sont optionnels — on les insère seulement si
        // les colonnes existent (migration complète). Sinon le INSERT minimal suffit.
        // revoked DEFAULT 0, created_at DEFAULT CURRENT_TIMESTAMP → pas besoin de les préciser
        $this->db->prepare(
            'INSERT INTO refresh_tokens (user_id, token, expires_at)
             VALUES (?, ?, ?)'
        )->execute([$userId, $token, $expiresAt]);
    }

    private function logSuccessfulLogin(string $userId): void {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'mobile-app', 0, 255);
            // login_time a un DEFAULT current_timestamp() — on ne la précise pas
            $this->db->prepare(
                'INSERT INTO user_logins (user_id, ip_address, user_agent, success)
                 VALUES (?, ?, ?, 1)'
            )->execute([$userId, $ip, $ua]);
        } catch (\Exception) {
            // Non bloquant
        }
    }

    private function logFailedLogin(string $identifiant): void {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'mobile-app', 0, 255);
            // failed_logins peut ne pas exister — non bloquant
            $this->db->prepare(
                'INSERT INTO failed_logins (identifiant, ip_address, user_agent)
                 VALUES (?, ?, ?)'
            )->execute([$identifiant, $ip, $ua]);
        } catch (\Exception) {
            // Non bloquant
        }
    }
}
