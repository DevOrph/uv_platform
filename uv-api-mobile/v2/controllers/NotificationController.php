<?php
/**
 * Gestion des tokens push Expo et envoi de notifications
 */
class NotificationController {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // ── POST /shared/device-token ─────────────────────────────────────────────

    public function registerDeviceToken(object $currentUser): void {
        $body = Response::getJsonBody();
        Response::requireFields($body, ['expo_push_token']);

        $token    = trim($body['expo_push_token']);
        $userId   = (int) $currentUser->user_id;
        $userRole = $currentUser->role; // 'student' | 'teacher'

        // Valider le format du token Expo
        if (!str_starts_with($token, 'ExponentPushToken[') && !str_starts_with($token, 'ExpoPushToken[')) {
            Response::error('Format de token push invalide.', 422);
        }

        // Upsert : créer ou mettre à jour pour cet utilisateur + token
        $stmt = $this->db->prepare('
            INSERT INTO device_tokens (user_id, user_role, expo_push_token, updated_at)
            VALUES (:user_id, :user_role, :token, NOW())
            ON DUPLICATE KEY UPDATE
                expo_push_token = VALUES(expo_push_token),
                updated_at      = NOW()
        ');

        $stmt->execute([
            ':user_id'   => $userId,
            ':user_role' => $userRole,
            ':token'     => $token,
        ]);

        Response::success(null, 'Token enregistré avec succès.');
    }

    // ── Méthode utilitaire statique : envoyer une notification push ───────────

    /**
     * Envoyer une notification Expo Push à une liste de tokens.
     *
     * @param string[] $tokens    Tokens Expo (ExponentPushToken[...])
     * @param string   $title     Titre de la notification
     * @param string   $body      Corps du message
     * @param array    $data      Données supplémentaires (navigation, etc.)
     */
    public static function sendPush(array $tokens, string $title, string $body, array $data = []): void {
        if (empty($tokens)) return;

        $messages = array_map(fn($t) => [
            'to'    => $t,
            'title' => $title,
            'body'  => $body,
            'data'  => $data,
            'sound' => 'default',
        ], $tokens);

        $ch = curl_init('https://exp.host/--/api/v2/push/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($messages),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
        // Erreurs non bloquantes : l'action principale ne doit pas échouer si push échoue
    }

    // ── Helpers : récupérer les tokens d'un ou plusieurs utilisateurs ─────────

    public static function getTokensForUser(PDO $db, int $userId, string $role): array {
        $stmt = $db->prepare('
            SELECT expo_push_token FROM device_tokens
            WHERE user_id = :uid AND user_role = :role
        ');
        $stmt->execute([':uid' => $userId, ':role' => $role]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function getTokensForClass(PDO $db, int $classId): array {
        $stmt = $db->prepare('
            SELECT dt.expo_push_token
            FROM device_tokens dt
            JOIN users u ON u.id = dt.user_id AND dt.user_role = \'student\'
            JOIN student_profiles sp ON sp.user_id = u.id
            WHERE sp.class_id = :class_id
        ');
        $stmt->execute([':class_id' => $classId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function getAllStudentTokens(PDO $db): array {
        $stmt = $db->query("SELECT expo_push_token FROM device_tokens WHERE user_role = 'student'");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
