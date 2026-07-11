<?php
/**
 * Verrouillage des notes après un délai (règle de gouvernance).
 *
 * Un enseignant ne peut plus MODIFIER ni SUPPRIMER une note au-delà de
 * N jours après sa saisie (grades.created_at). Les administrateurs ne
 * sont pas concernés.
 *
 * Le délai est paramétrable via la table `parametres`
 * (cle = 'verrou_notes_jours', défaut 7 ; une valeur <= 0 désactive le verrou).
 *
 * Utilisé par : professor/grades_management.php, grades/grades_table_view.php,
 * grades/edit_grade.php, grades/delete_grade.php, includes/delete_grade.php,
 * grades/grades_management.php.
 */

/**
 * Délai de verrouillage en jours (défaut 7). <= 0 = verrou désactivé.
 */
function grade_lock_days(mysqli $conn): int
{
    static $days = null;
    if ($days === null) {
        $days = 7;
        $r = $conn->query("SELECT valeur FROM parametres WHERE cle = 'verrou_notes_jours' LIMIT 1");
        if ($r && $r->num_rows > 0) {
            $v = trim($r->fetch_assoc()['valeur']);
            if ($v !== '' && is_numeric($v)) {
                $days = (int) $v;
            }
        } else {
            // Rendre le paramètre visible/modifiable dans admin/parametres.php
            $stmt = $conn->prepare("INSERT INTO parametres (cle, valeur, description) VALUES ('verrou_notes_jours', '7', ?)");
            $desc = "Nombre de jours après saisie au-delà duquel un enseignant ne peut plus modifier/supprimer une note (0 = désactivé, les admins ne sont pas concernés)";
            $stmt->bind_param("s", $desc);
            $stmt->execute();
        }
    }
    return $days;
}

/**
 * Message d'erreur standard affiché à l'enseignant.
 */
function grade_lock_message(mysqli $conn): string
{
    $days = grade_lock_days($conn);
    return "Cette note a été saisie il y a plus de $days jour(s) : elle est verrouillée. Contactez l'administration pour toute correction.";
}

/**
 * La note $grade_id est-elle verrouillée pour cet utilisateur ?
 * (admin → jamais ; note inexistante → non verrouillée, le contrôle
 * d'existence/propriété reste à la charge de l'appelant)
 */
function grade_is_locked(mysqli $conn, int $grade_id, string $role): bool
{
    if ($role === 'admin') {
        return false;
    }
    $days = grade_lock_days($conn);
    if ($days <= 0) {
        return false;
    }
    $stmt = $conn->prepare("SELECT 1 FROM grades WHERE id = ? AND created_at <= NOW() - INTERVAL ? DAY");
    $stmt->bind_param("ii", $grade_id, $days);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * Nombre de notes verrouillées dans une "colonne" d'évaluation
 * (cours + type + numéro + classe) — pour bloquer les suppressions en masse
 * côté enseignant si au moins une note est verrouillée.
 */
function grade_count_locked_in_column(mysqli $conn, int $course_id, int $evaluation_type_id, int $eval_number, int $class_id, string $role): int
{
    if ($role === 'admin') {
        return 0;
    }
    $days = grade_lock_days($conn);
    if ($days <= 0) {
        return 0;
    }
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS n FROM grades
        WHERE course_id = ? AND evaluation_type_id = ? AND eval_number = ?
          AND student_id IN (SELECT id FROM users WHERE class_id = ?)
          AND created_at <= NOW() - INTERVAL ? DAY
    ");
    $stmt->bind_param("iiiii", $course_id, $evaluation_type_id, $eval_number, $class_id, $days);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_assoc()['n'];
}
