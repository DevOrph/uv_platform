<?php
/**
 * Réponses JSON standardisées
 * Format : { "success": bool, "message": string, "data": mixed }
 */
class Response {

    public static function success(mixed $data = null, string $message = 'Succès', int $code = 200): never {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $code = 400, mixed $data = null): never {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Lire et valider le body JSON de la requête entrante
     */
    public static function getJsonBody(): array {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::error('Corps de requête JSON invalide', 400);
        }
        return $data ?? [];
    }

    /**
     * Vérifier que les champs requis sont présents et non vides
     */
    public static function requireFields(array $data, array $fields): void {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            self::error('Champs manquants : ' . implode(', ', $missing), 422);
        }
    }
}
