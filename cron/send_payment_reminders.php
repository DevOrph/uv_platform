<?php
/**
 * Script d'envoi automatique de rappels de paiement — Refonte complète
 * Niveaux : J-7 (doux) · J-3 (urgent) · J0 (dernier délai) · J+3 (retard)
 *
 * Exécution : php /path/to/cron/send_payment_reminders.php
 * Crontab   : 0 8 * * * php /path/to/cron/send_payment_reminders.php
 */

declare(strict_types=1);

$script_start = microtime(true);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sendgrid_helper.php';

// ── Logging ───────────────────────────────────────────────────────────────────

$log_dir  = '/var/log/uv_platform';
$log_file = $log_dir . '/reminders_' . date('Ymd') . '.log';

function log_msg(string $msg): void
{
    global $log_file, $log_dir;
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0750, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($log_file, $line, FILE_APPEND);
    echo $line;
}

// ── Migrations de schéma ──────────────────────────────────────────────────────

// Colonne reminder_level (ne fait rien si elle existe déjà)
$conn->query("
    ALTER TABLE payment_deadlines
    ADD COLUMN IF NOT EXISTS reminder_level
        ENUM('j7','j3','j0','j3late') DEFAULT NULL
        COMMENT 'Niveau du dernier rappel envoyé'
");

// Table de journalisation des rappels
$conn->query("
    CREATE TABLE IF NOT EXISTS reminder_log (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        student_id    VARCHAR(36)                        NOT NULL,
        deadline_id   INT                                NOT NULL,
        level         ENUM('j7','j3','j0','j3late')     NOT NULL,
        email_sent_to VARCHAR(255)                       NOT NULL,
        sent_at       DATETIME    DEFAULT CURRENT_TIMESTAMP NOT NULL,
        status        ENUM('sent','failed')              NOT NULL DEFAULT 'sent',
        error_message TEXT                               DEFAULT NULL,
        INDEX idx_student  (student_id),
        INDEX idx_deadline (deadline_id),
        INDEX idx_sent_at  (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
      COMMENT='Journal des rappels de paiement envoyés'
");

// ── Chargement des paramètres ─────────────────────────────────────────────────

function load_params(mysqli $conn): array
{
    $keys = [
        'banque_nom', 'banque_compte',
        'airtel_money', 'moov_money',
        'mobile_money_nom', 'mobile_money_numero',
        'contact_telephone', 'contact_email_admin',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass',
        'smtp_from',
    ];
    $params = array_fill_keys($keys, '');
    $ph     = implode(',', array_fill(0, count($keys), '?'));
    $stmt   = $conn->prepare("SELECT cle, valeur FROM parametres WHERE cle IN ($ph)");
    $stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $params[$row['cle']] = trim($row['valeur']);
    }
    $stmt->close();
    return $params;
}

function load_institution(mysqli $conn): array
{
    $res = $conn->query("SELECT school_name, logo_path FROM bulletin_config LIMIT 1");
    $row = $res ? $res->fetch_assoc() : [];
    return [
        'name'      => trim($row['school_name'] ?? 'Université Africaine des Sciences'),
        'logo_path' => $row['logo_path'] ?? null,
    ];
}

$params      = load_params($conn);
$institution = load_institution($conn);
$from_email  = $params['smtp_from'] ?: 'contact@uvcoding.com';
$from_name   = 'Service Financier — ' . $institution['name'];


// ── Templates HTML ────────────────────────────────────────────────────────────

function build_payment_methods_html(array $params): string
{
    $items = ['En espèces au service financier du campus'];

    if ($params['banque_nom'] && $params['banque_compte']) {
        $items[] = 'Virement bancaire : <strong>' . htmlspecialchars($params['banque_nom'])
                 . '</strong> — Compte <strong>' . htmlspecialchars($params['banque_compte']) . '</strong>';
    }
    if ($params['mobile_money_nom'] && $params['mobile_money_numero']) {
        $items[] = 'Mobile Money : <strong>' . htmlspecialchars($params['mobile_money_nom'])
                 . '</strong> — <strong>' . htmlspecialchars($params['mobile_money_numero']) . '</strong>';
    }
    if ($params['airtel_money']) {
        $items[] = 'Airtel Money : <strong>' . htmlspecialchars($params['airtel_money']) . '</strong>';
    }
    if ($params['moov_money']) {
        $items[] = 'Moov Money : <strong>' . htmlspecialchars($params['moov_money']) . '</strong>';
    }

    $li = array_map(fn ($i) => "<li style=\"margin-bottom:4px;\">$i</li>", $items);
    return '<ul style="margin:6px 0 10px;padding-left:20px;font-size:13px;color:#444;line-height:1.7;">'
         . implode('', $li)
         . '</ul>';
}

function build_email(string $level, array $student, array $params, array $institution): string
{
    $palette = [
        'j7'     => ['accent' => '#1565C0', 'bg' => '#E3F2FD', 'badge' => 'Rappel — J-7'],
        'j3'     => ['accent' => '#E65100', 'bg' => '#FFF3E0', 'badge' => 'Action requise — J-3'],
        'j0'     => ['accent' => '#B71C1C', 'bg' => '#FFEBEE', 'badge' => "Aujourd'hui — Dernier délai"],
        'j3late' => ['accent' => '#4A0000', 'bg' => '#FFCDD2', 'badge' => 'Retard de paiement'],
    ];

    $c    = $palette[$level];
    $acc  = $c['accent'];
    $bg   = $c['bg'];
    $badge = $c['badge'];

    $name    = htmlspecialchars($student['student_name']);
    $sid     = htmlspecialchars($student['student_id']);
    $class   = htmlspecialchars($student['class_name'] ?? '—');
    $year    = htmlspecialchars($student['academic_year'] ?? '—');
    $inst    = htmlspecialchars($institution['name']);
    $due_fmt = date('d/m/Y', strtotime($student['due_date']));
    $amount  = number_format((float) $student['remaining'],       0, ',', ' ') . ' FCFA';
    $total   = number_format((float) ($student['total_remaining'] ?? 0), 0, ',', ' ') . ' FCFA';
    $inst_n  = (int) $student['installment_number'];

    $admin_email = htmlspecialchars($params['contact_email_admin'] ?: 'N/A');
    $admin_tel   = htmlspecialchars($params['contact_telephone']   ?: 'N/A');

    $intros = [
        'j7' => "Nous vous informons qu'une échéance de paiement arrive dans <strong>7 jours</strong>. Pensez à régulariser votre situation avant la date limite pour éviter toute interruption de service.",
        'j3' => "Votre échéance de paiement arrive dans <strong>3 jours</strong>. Nous vous invitons à procéder au règlement <strong>dès maintenant</strong> afin d'éviter tout blocage de votre accès à la plateforme.",
        'j0' => "<strong>Aujourd'hui est le dernier jour</strong> pour régler votre échéance de paiement. Passé ce délai, des restrictions d'accès pourront être appliquées à votre compte étudiant.",
        'j3late' => "Votre échéance du <strong>{$due_fmt}</strong> n'a pas été réglée. Un retard de paiement peut entraîner la <strong>suspension de votre accès aux cours</strong>, l'impossibilité de passer les examens ainsi que l'application de pénalités. Nous vous demandons de régulariser votre situation <strong>immédiatement</strong>.",
    ];
    $intro = $intros[$level];

    $payment_methods = build_payment_methods_html($params);

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>$badge</title>
</head>
<body style="margin:0;padding:0;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="background:#f0f2f5;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0"
       style="background:#ffffff;border-radius:10px;overflow:hidden;
              box-shadow:0 2px 12px rgba(0,0,0,0.10);max-width:600px;">

  <!-- EN-TÊTE -->
  <tr>
    <td style="background:$acc;padding:30px 32px;text-align:center;">
      <div style="color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:.4px;
                  text-transform:uppercase;line-height:1.3;">$inst</div>
      <div style="color:rgba(255,255,255,.80);font-size:12px;margin-top:5px;letter-spacing:.3px;">
        Service Financier
      </div>
    </td>
  </tr>

  <!-- BADGE NIVEAU -->
  <tr>
    <td style="background:$bg;padding:11px 32px;text-align:center;
               border-bottom:3px solid $acc;">
      <span style="color:$acc;font-size:14px;font-weight:bold;letter-spacing:.3px;">
        $badge
      </span>
    </td>
  </tr>

  <!-- CORPS -->
  <tr>
    <td style="padding:28px 32px 22px;">

      <p style="margin:0 0 14px;font-size:15px;color:#222;">
        Bonjour <strong>$name</strong>,
      </p>
      <p style="margin:0 0 22px;font-size:14px;line-height:1.65;color:#444;">
        $intro
      </p>

      <!-- Tableau de détails -->
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
             style="background:$bg;border-left:4px solid $acc;border-radius:5px;
                    margin-bottom:24px;">
        <tr><td style="padding:16px 20px;">
          <table role="presentation" width="100%" cellpadding="5" cellspacing="0"
                 style="font-size:13px;color:#333;">
            <tr>
              <td style="color:#666;width:48%;">Numéro étudiant</td>
              <td style="font-weight:bold;">$sid</td>
            </tr>
            <tr>
              <td style="color:#666;">Classe</td>
              <td style="font-weight:bold;">$class</td>
            </tr>
            <tr>
              <td style="color:#666;">Année académique</td>
              <td style="font-weight:bold;">$year</td>
            </tr>
            <tr>
              <td style="color:#666;">Échéance n°</td>
              <td style="font-weight:bold;">$inst_n</td>
            </tr>
            <tr>
              <td style="color:#666;">Date limite</td>
              <td style="font-weight:bold;color:$acc;">$due_fmt</td>
            </tr>
            <tr>
              <td style="color:#666;">Montant de cette échéance</td>
              <td style="font-weight:bold;font-size:15px;color:$acc;">$amount</td>
            </tr>
            <tr>
              <td style="color:#666;border-top:1px solid rgba(0,0,0,.08);padding-top:8px;">
                Solde total restant
              </td>
              <td style="font-weight:bold;border-top:1px solid rgba(0,0,0,.08);padding-top:8px;">
                $total
              </td>
            </tr>
          </table>
        </td></tr>
      </table>

      <!-- Modalités de paiement -->
      <p style="margin:0 0 6px;font-size:14px;font-weight:bold;color:#333;">
        Modalités de paiement
      </p>
      $payment_methods
      <p style="margin:8px 0 0;font-size:12px;color:#666;">
        Merci de mentionner votre numéro étudiant <strong>$sid</strong>
        lors de chaque paiement.
      </p>

    </td>
  </tr>

  <!-- PIED DE PAGE -->
  <tr>
    <td style="background:#f8f9fa;padding:18px 32px;border-top:1px solid #e8e8e8;">
      <p style="margin:0 0 5px;font-size:13px;color:#555;font-weight:bold;">
        Contact administration
      </p>
      <p style="margin:0;font-size:13px;color:#666;">
        ✉ $admin_email &nbsp;&nbsp;|&nbsp;&nbsp; ☎ $admin_tel
      </p>
      <p style="margin:14px 0 0;font-size:11px;color:#aaa;text-align:center;line-height:1.5;">
        Ceci est un message automatique — merci de ne pas répondre directement à cet email.<br>
        &copy; $inst — Tous droits réservés.
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

// ── Traitement des 4 niveaux ──────────────────────────────────────────────────

$levels = [
    'j7' => [
        'subject'      => 'Rappel : votre échéance arrive dans 7 jours',
        'where_date'   => 'pd.due_date = DATE_ADD(CURDATE(), INTERVAL 7 DAY)',
        'where_status' => "pd.status IN ('pending','partial')",
    ],
    'j3' => [
        'subject'      => 'Action requise : échéance dans 3 jours',
        'where_date'   => 'pd.due_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY)',
        'where_status' => "pd.status IN ('pending','partial')",
    ],
    'j0' => [
        'subject'      => "Aujourd'hui : dernière échéance de paiement",
        'where_date'   => 'pd.due_date = CURDATE()',
        'where_status' => "pd.status IN ('pending','partial')",
    ],
    'j3late' => [
        'subject'      => 'Retard de paiement — action immédiate requise',
        'where_date'   => 'pd.due_date = DATE_SUB(CURDATE(), INTERVAL 3 DAY)',
        'where_status' => "pd.status IN ('pending','partial','overdue')",
    ],
];

$counts = [];

log_msg('========================================');
log_msg('Démarrage des rappels de paiement');
log_msg('Mode : SendGrid API');
log_msg('========================================');

foreach ($levels as $level => $cfg) {
    $counts[$level] = ['found' => 0, 'sent' => 0, 'failed' => 0];
    log_msg('');
    log_msg("--- Niveau $level : {$cfg['subject']} ---");

    // Anti-doublon : exclure les échéances déjà traitées au même niveau dans les 24 dernières heures
    $sql = "
        SELECT
            pd.id                   AS deadline_id,
            pd.student_id,
            pd.installment_number,
            pd.due_date,
            pd.amount_due,
            pd.amount_paid,
            (pd.amount_due - pd.amount_paid) AS remaining,
            (
                SELECT SUM(pd2.amount_due - pd2.amount_paid)
                FROM   payment_deadlines pd2
                WHERE  pd2.student_id = pd.student_id
                  AND  pd2.status != 'paid'
            )                       AS total_remaining,
            u.name                  AS student_name,
            u.email                 AS student_email,
            c.name                  AS class_name,
            tf.academic_year
        FROM  payment_deadlines pd
        JOIN  users u           ON pd.student_id = u.id
        LEFT JOIN classes c     ON u.class_id    = c.id
        LEFT JOIN tuition_fees tf ON pd.tuition_fee_id = tf.id
        WHERE {$cfg['where_date']}
          AND {$cfg['where_status']}
          AND u.role    = 'student'
          AND u.blocked = 0
          AND (
              pd.reminder_level    IS NULL
              OR pd.reminder_level  != '$level'
              OR pd.last_reminder_date IS NULL
              OR pd.last_reminder_date < DATE_SUB(NOW(), INTERVAL 24 HOUR)
          )
        ORDER BY pd.due_date, u.name
    ";

    $result = $conn->query($sql);
    if (!$result) {
        log_msg("Erreur SQL niveau $level : " . $conn->error);
        continue;
    }

    $counts[$level]['found'] = $result->num_rows;
    log_msg("Trouvé {$counts[$level]['found']} échéance(s) éligible(s)");

    while ($student = $result->fetch_assoc()) {
        $html = build_email($level, $student, $params, $institution);
        $res  = send_email_sendgrid(
            $student['student_email'],
            $student['student_name'],
            $cfg['subject'],
            $html,
            $from_email,
            $from_name
        );

        if ($res['success']) {
            // Mise à jour de l'échéance
            $upd = $conn->prepare(
                'UPDATE payment_deadlines
                    SET reminder_sent = 1,
                        last_reminder_date = NOW(),
                        reminder_level = ?
                  WHERE id = ?'
            );
            $upd->bind_param('si', $level, $student['deadline_id']);
            $upd->execute();
            $upd->close();

            // Journalisation succès
            $log_stmt = $conn->prepare(
                "INSERT INTO reminder_log
                    (student_id, deadline_id, level, email_sent_to, status)
                 VALUES (?, ?, ?, ?, 'sent')"
            );
            $log_stmt->bind_param(
                'siss',
                $student['student_id'],
                $student['deadline_id'],
                $level,
                $student['student_email']
            );
            $log_stmt->execute();
            $log_stmt->close();

            $counts[$level]['sent']++;
            log_msg('  ✓ ' . $student['student_name'] . ' <' . $student['student_email'] . '>');
        } else {
            $err = $res['error'];

            // Journalisation échec
            $log_stmt = $conn->prepare(
                "INSERT INTO reminder_log
                    (student_id, deadline_id, level, email_sent_to, status, error_message)
                 VALUES (?, ?, ?, ?, 'failed', ?)"
            );
            $log_stmt->bind_param(
                'sisss',
                $student['student_id'],
                $student['deadline_id'],
                $level,
                $student['student_email'],
                $err
            );
            $log_stmt->execute();
            $log_stmt->close();

            $counts[$level]['failed']++;
            log_msg('  ✗ ' . $student['student_name'] . ' <' . $student['student_email'] . '> — ' . $err);
        }
    }

    log_msg(sprintf(
        'Résultat %s : %d envoyé(s), %d échoué(s) sur %d éligible(s)',
        $level,
        $counts[$level]['sent'],
        $counts[$level]['failed'],
        $counts[$level]['found']
    ));
}

// ── Rapport final ─────────────────────────────────────────────────────────────

$duration     = round(microtime(true) - $script_start, 2);
$total_found  = array_sum(array_column($counts, 'found'));
$total_sent   = array_sum(array_column($counts, 'sent'));
$total_failed = array_sum(array_column($counts, 'failed'));

$report_lines = [
    '',
    '========================================',
    'RAPPORT FINAL — ' . date('Y-m-d H:i:s'),
    '========================================',
    "Durée d'exécution : {$duration} secondes",
    "Total éligibles   : {$total_found}",
    "Total envoyés     : {$total_sent}",
    "Total erreurs     : {$total_failed}",
    '— Détail par niveau :',
    sprintf('  J-7   (doux)    : %d/%d', $counts['j7']['sent'],     $counts['j7']['found']),
    sprintf('  J-3   (urgent)  : %d/%d', $counts['j3']['sent'],     $counts['j3']['found']),
    sprintf('  J0    (dernier) : %d/%d', $counts['j0']['sent'],     $counts['j0']['found']),
    sprintf('  J+3   (retard)  : %d/%d', $counts['j3late']['sent'], $counts['j3late']['found']),
    '========================================',
];

foreach ($report_lines as $line) {
    log_msg($line);
}

$conn->close();
exit(0);
