<?php
/**
 * Worker de traitement de la file d'emails
 * Fichier : /cron/process_email_queue.php
 *
 * Exécution manuelle : php cron/process_email_queue.php
 * Via crontab (toutes les minutes) :
 *   * * * * * php /chemin/vers/UV_LOCAL/cron/process_email_queue.php >> /var/log/uv_email_queue.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';
use SendGrid\Mail\Mail;

// ── Connexion (config .env via includes/db_config.php) ──
require_once __DIR__ . '/../includes/db_config.php';
try {
    $conn = get_db_connection();
} catch (RuntimeException $e) {
    echo "[ERREUR] " . $e->getMessage() . "\n";
    exit(1);
}

// ── Config SendGrid ──
define('SENDGRID_FROM_EMAIL','contact@uvcoding.com');
define('SENDGRID_FROM_NAME', 'Université Virtuelle');
$_sg_res = $conn->query("SELECT valeur FROM parametres WHERE cle='sendgrid_api_key' LIMIT 1");
$sendgrid_api_key = $_sg_res ? trim($_sg_res->fetch_assoc()['valeur'] ?? '') : '';
if (empty($sendgrid_api_key)) {
    echo "[ERREUR] Clé API SendGrid non configurée dans la table parametres.\n";
    $conn->close();
    exit(1);
}

const MAX_ATTEMPTS = 3;
const BATCH_LIMIT  = 50; // emails traités par exécution

// ── Récupérer les emails en attente ──
$max_att   = MAX_ATTEMPTS;
$batch_lim = BATCH_LIMIT;
$stmt = $conn->prepare("
    SELECT id, to_email, to_name, template_id, dynamic_data
    FROM email_queue
    WHERE status = 'pending' AND attempts < ?
    ORDER BY created_at ASC
    LIMIT ?
");
$stmt->bind_param('ii', $max_att, $batch_lim);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)) {
    echo "[" . date('Y-m-d H:i:s') . "] Aucun email en attente.\n";
    $conn->close();
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] " . count($rows) . " email(s) à traiter...\n";

$sent   = 0;
$failed = 0;

foreach ($rows as $row) {
    $dynamic_data = json_decode($row['dynamic_data'], true);

    // ── Construire et envoyer ──
    try {
        $email = new Mail();
        $email->setFrom(SENDGRID_FROM_EMAIL, SENDGRID_FROM_NAME);
        $email->addTo($row['to_email'], $row['to_name']);
        $email->setTemplateId($row['template_id']);
        $email->addDynamicTemplateDatas($dynamic_data);
        $email->setReplyTo(SENDGRID_FROM_EMAIL, SENDGRID_FROM_NAME);
        $email->addCategory('course_discussion');

        $sg       = new \SendGrid($sendgrid_api_key);
        $response = $sg->send($email);

        if ($response->statusCode() == 202) {
            $upd = $conn->prepare(
                "UPDATE email_queue SET status='sent', processed_at=NOW(), attempts=attempts+1 WHERE id=?"
            );
            $upd->bind_param('i', $row['id']);
            $upd->execute();
            $upd->close();
            $sent++;
            echo "  ✅ Envoyé → {$row['to_name']} ({$row['to_email']})\n";
        } else {
            $error = 'HTTP ' . $response->statusCode();
            markFailed($conn, $row['id'], $error);
            $failed++;
            echo "  ❌ Échec [{$error}] → {$row['to_name']}\n";
        }
    } catch (Exception $e) {
        markFailed($conn, $row['id'], $e->getMessage());
        $failed++;
        echo "  ❌ Exception → {$row['to_name']}: " . $e->getMessage() . "\n";
    }

    // Pause courte pour respecter les limites de taux SendGrid
    usleep(50000);
}

echo "[" . date('Y-m-d H:i:s') . "] Terminé — $sent envoyés, $failed échoués.\n";
$conn->close();

// ── Helpers ──
function markFailed(mysqli $conn, int $id, string $error): void {
    $max  = MAX_ATTEMPTS;
    $stmt = $conn->prepare(
        "UPDATE email_queue
         SET attempts = attempts + 1,
             error_message = ?,
             status = IF(attempts + 1 >= ?, 'failed', 'pending'),
             processed_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param('sii', $error, $max, $id);
    $stmt->execute();
    $stmt->close();
}
