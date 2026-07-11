<?php
/**
 * Helper SendGrid — envoi direct via API v3 (cURL, sans dépendance SDK)
 *
 * Usage : send_email_sendgrid($to, $name, $subject, $html)
 *
 * Résolution de la clé API :
 *   Lecture depuis parametres WHERE cle = 'sendgrid_api_key' (via $GLOBALS['conn'])
 */

function send_email_sendgrid(
    string  $to_email,
    string  $to_name,
    string  $subject,
    string  $html_body,
    ?string $from_email = null,
    ?string $from_name  = null
): array {

    // ── Clé API ───────────────────────────────────────────────────────────────
    $api_key = '';
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        $stmt = $GLOBALS['conn']->prepare(
            "SELECT valeur FROM parametres WHERE cle = 'sendgrid_api_key' LIMIT 1"
        );
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $api_key = trim($row['valeur'] ?? '');
        }
    }
    if ($api_key === '') {
        error_log("SendGrid: clé API non configurée dans parametres");
        return ['success' => false, 'error' => 'Clé API SendGrid non configurée'];
    }

    // ── Expéditeur par défaut ─────────────────────────────────────────────────
    if ($from_email === null) {
        $from_email = 'contact@uvcoding.com'; // dernier recours
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $stmt = $GLOBALS['conn']->prepare(
                "SELECT valeur FROM parametres WHERE cle = 'smtp_from' LIMIT 1"
            );
            if ($stmt) {
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!empty(trim($row['valeur'] ?? ''))) {
                    $from_email = trim($row['valeur']);
                }
            }
        }
    }
    if ($from_name === null) {
        $from_name = defined('SENDGRID_FROM_NAME') ? SENDGRID_FROM_NAME : 'Université Virtuelle';
    }

    // ── Corps texte brut (alternatif) ─────────────────────────────────────────
    $plain = wordwrap(
        strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</div>'], "\n", $html_body)),
        75, "\n"
    );

    // ── Payload JSON SendGrid v3 ───────────────────────────────────────────────
    $payload = json_encode([
        'personalizations' => [[
            'to' => [['email' => $to_email, 'name' => $to_name]],
        ]],
        'from'    => ['email' => $from_email, 'name' => $from_name],
        'subject' => $subject,
        'content' => [
            ['type' => 'text/plain', 'value' => $plain ?: ' '],
            ['type' => 'text/html',  'value' => $html_body],
        ],
    ], JSON_UNESCAPED_UNICODE);

    // ── Appel cURL ────────────────────────────────────────────────────────────
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'error' => 'cURL : ' . $curl_error];
    }

    // SendGrid retourne 202 Accepted pour un envoi réussi
    if ($http_code >= 200 && $http_code < 300) {
        return ['success' => true, 'error' => null];
    }

    $body = json_decode($response, true);
    $msg  = $body['errors'][0]['message'] ?? ('HTTP ' . $http_code . ' — ' . substr($response, 0, 200));
    return ['success' => false, 'error' => $msg];
}
