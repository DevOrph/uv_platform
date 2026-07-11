<?php
/**
 * Helpers : période semestrielle courante
 *
 * Utilisé par student/manage_discussions.php,
 * professor/manage_discussions.php, et tout fichier qui a besoin
 * de connaître le semestre actif sans passer par db_connect.php.
 *
 * Définit ANNEE_ACADEMIQUE_COURANTE si elle n'est pas encore définie
 * (les fichiers qui incluent déjà db_connect.php l'ont déjà).
 */

/**
 * Retourne la période d'évaluation active pour l'année courante.
 *
 * Algorithme :
 *   1. Période dont start_date <= CURDATE() <= end_date   → période en cours
 *   2. Aucune trouvée : prochaine période (avant la rentrée)
 *   3. Toujours rien : dernière période passée (après la fin d'année)
 *   4. Fallback absolu → ['id' => null, 'semester' => 1, ...]
 *
 * @param  mysqli $conn  Connexion MySQLi active
 * @return array{id: int|null, name: string, semester: int, school_year: string}
 */
function get_current_period($conn): array
{
    // ── Définir ANNEE_ACADEMIQUE_COURANTE si non encore définie ──
    if (!defined('ANNEE_ACADEMIQUE_COURANTE')) {
        $m     = (int) date('n');
        $y     = (int) date('Y');
        $annee = $m >= 9 ? "$y-" . ($y + 1) : ($y - 1) . "-$y";

        $r = $conn->query(
            "SELECT valeur FROM parametres WHERE cle = 'annee_academique_courante' LIMIT 1"
        );
        if ($r && $r->num_rows > 0) {
            $forced = trim($r->fetch_assoc()['valeur']);
            if ($forced !== '') {
                $annee = $forced;
            }
        }
        define('ANNEE_ACADEMIQUE_COURANTE', $annee);
    }

    $year = ANNEE_ACADEMIQUE_COURANTE;

    // ── 0. Semestre forcé depuis les paramètres ───────────────
    $r_force = $conn->query(
        "SELECT valeur FROM parametres WHERE cle = 'semestre_force' LIMIT 1"
    );
    if ($r_force && $r_force->num_rows > 0) {
        $force_val = trim($r_force->fetch_assoc()['valeur']);
        if ($force_val === '1' || $force_val === '2') {
            $forced_sem = (int) $force_val;
            // Chercher la période correspondant à ce semestre pour l'année courante
            $stmt_f = $conn->prepare(
                "SELECT id, name, start_date, end_date, school_year
                 FROM evaluation_periods
                 WHERE school_year = ?
                 ORDER BY start_date ASC"
            );
            if ($stmt_f) {
                $stmt_f->bind_param('s', $year);
                $stmt_f->execute();
                $res_f = $stmt_f->get_result();
                while ($row_f = $res_f->fetch_assoc()) {
                    if (get_semester_from_period($row_f['name']) === $forced_sem) {
                        $stmt_f->close();
                        return [
                            'id'          => (int) $row_f['id'],
                            'name'        => $row_f['name'],
                            'semester'    => $forced_sem,
                            'school_year' => $row_f['school_year'],
                        ];
                    }
                }
                $stmt_f->close();
            }
            // Pas de période trouvée : fallback synthétique avec le semestre forcé
            return [
                'id'          => null,
                'name'        => "Semestre $forced_sem",
                'semester'    => $forced_sem,
                'school_year' => $year,
            ];
        }
    }

    // ── 1. Période active ─────────────────────────────────────
    $stmt = $conn->prepare(
        "SELECT id, name, start_date, end_date, school_year
         FROM evaluation_periods
         WHERE start_date <= CURDATE()
           AND end_date   >= CURDATE()
           AND school_year = ?
         ORDER BY start_date ASC
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param("s", $year);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [
                'id'          => (int) $row['id'],
                'name'        => $row['name'],
                'semester'    => get_semester_from_period($row['name']),
                'school_year' => $row['school_year'],
            ];
        }
    }

    // ── 2. Prochaine période (avant la rentrée) ───────────────
    $stmt = $conn->prepare(
        "SELECT id, name, start_date, end_date, school_year
         FROM evaluation_periods
         WHERE school_year = ?
           AND start_date > CURDATE()
         ORDER BY start_date ASC
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param("s", $year);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [
                'id'          => (int) $row['id'],
                'name'        => $row['name'],
                'semester'    => get_semester_from_period($row['name']),
                'school_year' => $row['school_year'],
            ];
        }
    }

    // ── 3. Dernière période passée ────────────────────────────
    $stmt = $conn->prepare(
        "SELECT id, name, start_date, end_date, school_year
         FROM evaluation_periods
         WHERE school_year = ?
           AND end_date < CURDATE()
         ORDER BY end_date DESC
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param("s", $year);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [
                'id'          => (int) $row['id'],
                'name'        => $row['name'],
                'semester'    => get_semester_from_period($row['name']),
                'school_year' => $row['school_year'],
            ];
        }
    }

    // ── 4. Fallback absolu ────────────────────────────────────
    return ['id' => null, 'name' => '', 'semester' => 1, 'school_year' => $year];
}

/**
 * Déduit le numéro de semestre depuis le nom d'une période.
 *
 * @param  string $period_name  Ex. "Premier Semestre", "Deuxième Semestre"
 * @return int                  1 ou 2
 */
function get_semester_from_period(string $period_name): int
{
    $lower = mb_strtolower($period_name, 'UTF-8');
    if (str_contains($lower, 'deuxi')
        || str_contains($lower, 'second')
        || str_contains($lower, '2ème')
        || str_contains($lower, '2e ')
        || str_contains($lower, ' 2')
    ) {
        return 2;
    }
    return 1;
}
