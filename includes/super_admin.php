<?php
/**
 * Super administrateur — remplace l'ancien contrôle codé en dur sur l'ID
 * 'ADMIN01' (migration : database/superadmin_migration.sql).
 *
 * Un super admin est un utilisateur `role = 'admin'` avec
 * `users.is_super_admin = 1`. Il conserve le rôle 'admin' : tous les
 * contrôles de rôle existants continuent de fonctionner.
 *
 * Privilèges exclusifs (historiquement ADMIN01) :
 *   - gestion des autres administrateurs (création, blocage, suppression)
 *   - octroi/révocation des permissions d'examen et de paiement
 *   - annulation de paiements, rapports sensibles
 *
 * Gestion (promouvoir / rétrograder) : admin/manage_admins.php.
 */

/**
 * L'utilisateur est-il super administrateur ?
 * $user_id omis → utilisateur de la session courante.
 */
function is_super_admin(mysqli $conn, ?string $user_id = null): bool
{
    static $cache = [];

    if ($user_id === null) {
        $user_id = $_SESSION['user_id'] ?? '';
    }
    if ($user_id === '') {
        return false;
    }
    if (!array_key_exists($user_id, $cache)) {
        $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin' AND is_super_admin = 1");
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $cache[$user_id] = $stmt->get_result()->num_rows > 0;
    }
    return $cache[$user_id];
}

/**
 * Nombre de super admins actifs — pour interdire de rétrograder/supprimer
 * le dernier (l'institution doit toujours en avoir au moins un).
 */
function super_admin_count(mysqli $conn): int
{
    $res = $conn->query("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND is_super_admin = 1 AND status = 'active' AND blocked = 0");
    return $res ? (int) $res->fetch_assoc()['n'] : 0;
}

/**
 * Message d'erreur standard pour un accès réservé.
 */
function super_admin_required_message(): string
{
    return "Action réservée à un super administrateur.";
}
