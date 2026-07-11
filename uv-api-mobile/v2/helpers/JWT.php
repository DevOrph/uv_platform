<?php

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

/**
 * Wrapper autour de firebase/php-jwt
 */
class JWT {

    /**
     * Génère un access token (courte durée)
     */
    public static function generateAccessToken(array $user): string {
        $now = time();
        $payload = [
            'iss'     => APP_NAME,
            'iat'     => $now,
            'exp'     => $now + JWT_ACCESS_EXPIRY,
            'type'    => 'access',
            'user_id' => $user['id'],
            'role'    => $user['role'],
            'name'    => $user['name'],
        ];
        return FirebaseJWT::encode($payload, JWT_SECRET, JWT_ALGO);
    }

    /**
     * Génère un refresh token opaque (stocké en DB)
     */
    public static function generateRefreshToken(): string {
        return bin2hex(random_bytes(40));
    }

    /**
     * Vérifie et décode un access token
     * Retourne le payload (objet stdClass) ou null si invalide
     */
    public static function verifyAccessToken(string $token): ?object {
        try {
            $decoded = FirebaseJWT::decode($token, new Key(JWT_SECRET, JWT_ALGO));

            if (($decoded->type ?? '') !== 'access') {
                return null;
            }
            return $decoded;

        } catch (ExpiredException) {
            return null;
        } catch (SignatureInvalidException) {
            return null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Extrait le Bearer token du header Authorization
     */
    public static function extractFromHeader(): ?string {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? apache_request_headers()['Authorization']
            ?? null;

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        return trim(substr($authHeader, 7));
    }
}
