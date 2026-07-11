<?php
ob_start();
ini_set('display_errors', 0);
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';
require_once '../includes/grade_calculator.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}
$current_admin_id = $_SESSION['user_id'];

/* ═══════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════ */

function next_academic_year(string $y): string {
    $p = explode('-', $y);
    if (count($p) === 2 && ctype_digit($p[0]) && ctype_digit($p[1])) {
        return ((int)$p[0] + 1) . '-' . ((int)$p[1] + 1);
    }
    return $y;
}

function ajax_json(bool $ok, string $msg, array $extra = []): void {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($ok ? array_merge(['success' => true, 'message' => $msg], $extra)
                         : ['error' => $msg]);
    exit();
}

/* ═══════════════════════════════════════════════════════
   ACTION : charger les étudiants (AJAX)
═══════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'load_students') {
    $filiere_id   = (int)($_POST['filiere_id']   ?? 0);
    $level_number = (int)($_POST['level_number'] ?? 0);
    $source_year  = trim($_POST['academic_year'] ?? '');

    if (!$filiere_id || !$level_number || !$source_year) {
        ajax_json(false, 'Filière, niveau et année académique sont requis.');
    }

    // Classes du niveau dans la filière
    $cr = $conn->prepare("
        SELECT c.id, c.name, c.code, c.next_class_id,
               nc.name AS next_class_name
        FROM classes c
        LEFT JOIN classes nc ON nc.id = c.next_class_id
        WHERE c.filiere_id = ? AND c.level_number = ?
        ORDER BY c.name
    ");
    $cr->bind_param('ii', $filiere_id, $level_number);
    $cr->execute();
    $classes_in_level = $cr->get_result()->fetch_all(MYSQLI_ASSOC);
    $cr->close();

    if (empty($classes_in_level)) {
        ajax_json(false, 'Aucune classe pour ce niveau dans cette filière.');
    }

    $class_map  = array_column($classes_in_level, null, 'id');
    $class_ids  = array_column($classes_in_level, 'id');

    // Transitions possibles pour chaque classe du niveau (bifurcations)
    $transitions_by_class = [];
    if (!empty($class_ids)) {
        $ct_ph = implode(',', $class_ids); // entiers issus de la DB, sûrs
        $ctr = $conn->query("
            SELECT ct.from_class_id, ct.to_class_id, ct.condition_label,
                   c.name AS to_name, c.code AS to_code
            FROM class_transitions ct
            JOIN classes c ON c.id = ct.to_class_id
            WHERE ct.from_class_id IN ($ct_ph)
            ORDER BY ct.from_class_id, c.name ASC
        ");
        while ($ctr && ($row = $ctr->fetch_assoc())) {
            $transitions_by_class[(int)$row['from_class_id']][] = [
                'to_class_id'     => (int)$row['to_class_id'],
                'to_name'         => $row['to_name'],
                'to_code'         => $row['to_code'],
                'condition_label' => $row['condition_label'],
            ];
        }
    }

    $pid_ph     = implode(',', array_fill(0, count($class_ids), '?'));
    $bind_types = str_repeat('i', count($class_ids)) . 's';
    $bind_vals  = array_merge($class_ids, [$source_year]);

    $sr = $conn->prepare("
        SELECT u.id, u.name, u.nom, u.prenom,
               sch.id AS sch_id, sch.class_id AS current_class_id
        FROM student_class_history sch
        JOIN users u ON u.id = sch.student_id
        WHERE sch.class_id IN ($pid_ph)
          AND sch.academic_year = ?
          AND sch.end_date IS NULL
          AND sch.status = 'en_cours'
          AND u.role = 'student'
        ORDER BY u.name ASC
    ");
    $sr->bind_param($bind_types, ...$bind_vals);
    $sr->execute();
    $students = $sr->get_result()->fetch_all(MYSQLI_ASSOC);
    $sr->close();

    // Périodes d'évaluation pour l'année source
    $pr = $conn->prepare("
        SELECT id, name FROM evaluation_periods
        WHERE school_year = ? ORDER BY start_date ASC LIMIT 2
    ");
    $pr->bind_param('s', $source_year);
    $pr->execute();
    $periods = $pr->get_result()->fetch_all(MYSQLI_ASSOC);
    $pr->close();

    $p1_id = $periods[0]['id'] ?? 0;
    $p2_id = $periods[1]['id'] ?? 0;

    // Calcul des moyennes et crédits via calculate_student_semester_grades()
    // Applique la logique LMD complète : UE, modules éliminatoires, seuil Licence/Master
    $calc_by_student = [];
    foreach ($students as $s) {
        $sid = $s['id'];
        $cid = (int)$s['current_class_id'];
        $calc_by_student[$sid] = [
            's1' => $p1_id ? calculate_student_semester_grades($conn, $sid, $cid, $p1_id) : null,
            's2' => $p2_id ? calculate_student_semester_grades($conn, $sid, $cid, $p2_id) : null,
        ];
    }

    // Construire la réponse
    $result_students = [];
    $warn_no_next    = false;

    foreach ($students as $s) {
        $cid  = (int)$s['current_class_id'];
        $cls  = $class_map[$cid] ?? null;

        $calc = $calc_by_student[$s['id']];
        $c1   = $calc['s1'];
        $c2   = $calc['s2'];

        $s1 = ($c1 && $c1['credits_total'] > 0) ? $c1['moy_generale'] : null;
        $s2 = ($c2 && $c2['credits_total'] > 0) ? $c2['moy_generale'] : null;

        // Moyenne annuelle pondérée par les crédits de chaque semestre
        $annW = 0.0; $annC = 0.0;
        if ($c1 && $c1['credits_total'] > 0) { $annW += $c1['moy_generale'] * $c1['credits_total']; $annC += $c1['credits_total']; }
        if ($c2 && $c2['credits_total'] > 0) { $annW += $c2['moy_generale'] * $c2['credits_total']; $annC += $c2['credits_total']; }
        $annual = $annC > 0 ? $annW / $annC : null;

        $credits_s1 = ($c1 && $c1['credits_total'] > 0)
            ? $c1['credits_obtenus'] . '/' . (int)round($c1['credits_total']) : null;
        $credits_s2 = ($c2 && $c2['credits_total'] > 0)
            ? $c2['credits_obtenus'] . '/' . (int)round($c2['credits_total']) : null;

        $next_id = $cls ? (int)($cls['next_class_id'] ?? 0) : 0;
        $trans   = $transitions_by_class[$cid] ?? [];
        // Alerte seulement si ni next_class_id ni aucune transition définie
        if (!$next_id && empty($trans)) $warn_no_next = true;

        $result_students[] = [
            'id'              => $s['id'],
            'name'            => $s['name'],
            'sch_id'          => (int)$s['sch_id'],
            'class_id'        => $cid,
            'class_name'      => $cls['name']       ?? '',
            'class_code'      => $cls['code']       ?? '',
            'next_class_id'   => $next_id ?: null,
            'next_class_name' => $cls['next_class_name'] ?? null,
            'transitions'     => $trans,
            'avg_s1'          => $s1     !== null ? round($s1, 2)     : null,
            'avg_s2'          => $s2     !== null ? round($s2, 2)     : null,
            'avg_annual'      => $annual !== null ? round($annual, 2) : null,
            'credits_s1'      => $credits_s1,
            'credits_s2'      => $credits_s2,
        ];
    }

    ajax_json(true, '', [
        'students'      => $result_students,
        'target_year'   => next_academic_year($source_year),
        'warn_no_next'  => $warn_no_next,
        'count'         => count($result_students),
    ]);
}

/* ═══════════════════════════════════════════════════════
   ACTION : valider les décisions (POST classique)
═══════════════════════════════════════════════════════ */

$msg_ok  = '';
$msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate_decisions') {
    $source_year  = trim($_POST['source_year']  ?? '');
    $target_year  = trim($_POST['target_year']  ?? '');
    $decisions      = $_POST['decisions']    ?? [];
    $xfer_classes   = $_POST['xfer_class']   ?? [];
    $target_classes = $_POST['target_class'] ?? [];
    $today        = date('Y-m-d');

    if (!$source_year || !$target_year) {
        $msg_err = 'Années source et cible requises.';
        goto page_render;
    }

    $ok_count   = 0;
    $err_count  = 0;
    $err_msgs   = [];
    $ip         = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua         = $_SERVER['HTTP_USER_AGENT'] ?? '';

    foreach ($decisions as $raw_sid => $decision) {
        $sid      = trim((string)$raw_sid);
        $decision = trim((string)$decision);
        if (!$sid || !in_array($decision, ['admis', 'redoublant', 'transfere', 'abandonne'], true)) continue;

        // Récupérer l'historique actif
        $hr = $conn->prepare("
            SELECT sch.id, sch.class_id, c.next_class_id, c.name AS class_name
            FROM student_class_history sch
            JOIN classes c ON c.id = sch.class_id
            WHERE sch.student_id = ?
              AND sch.academic_year = ?
              AND sch.end_date IS NULL
              AND sch.status = 'en_cours'
            ORDER BY sch.id DESC LIMIT 1
        ");
        $hr->bind_param('ss', $sid, $source_year);
        $hr->execute();
        $sch = $hr->get_result()->fetch_assoc();
        $hr->close();

        if (!$sch) {
            $err_count++;
            $err_msgs[] = "$sid : aucun historique actif.";
            continue;
        }

        $sch_id           = (int)$sch['id'];
        $current_class_id = (int)$sch['class_id'];
        $next_class_id    = $sch['next_class_id'] ? (int)$sch['next_class_id'] : null;

        // Déterminer la classe cible
        $target_class_id = null;
        switch ($decision) {
            case 'admis':
                $chosen_target = isset($target_classes[$sid]) ? (int)$target_classes[$sid] : 0;
                if ($chosen_target) {
                    // Vérifier que la transition est bien autorisée dans class_transitions
                    $vr = $conn->prepare("
                        SELECT id FROM class_transitions
                        WHERE from_class_id = ? AND to_class_id = ?
                    ");
                    $vr->bind_param('ii', $current_class_id, $chosen_target);
                    $vr->execute();
                    $is_valid = (bool)$vr->get_result()->fetch_assoc();
                    $vr->close();
                    if (!$is_valid) {
                        $err_count++;
                        $err_msgs[] = "$sid : classe cible non autorisée pour ce cheminement.";
                        continue 2;
                    }
                    $target_class_id = $chosen_target;
                } elseif ($next_class_id) {
                    $target_class_id = $next_class_id;
                } else {
                    $err_count++;
                    $err_msgs[] = "$sid : cheminement non défini (aucune classe cible sélectionnée).";
                    continue 2;
                }
                break;
            case 'redoublant':
                $target_class_id = $current_class_id;
                break;
            case 'transfere':
                $xfer = isset($xfer_classes[$sid]) ? (int)$xfer_classes[$sid] : 0;
                if (!$xfer) {
                    $err_count++;
                    $err_msgs[] = "$sid : classe de transfert non spécifiée.";
                    continue 2;
                }
                $target_class_id = $xfer;
                break;
            case 'abandonne':
                $target_class_id = null;
                break;
        }

        // Transaction par étudiant
        $conn->begin_transaction();
        try {
            // Clore l'historique actuel
            $u1 = $conn->prepare("
                UPDATE student_class_history
                SET end_date = ?, status = ?, decision_date = ?, decision_by = ?
                WHERE id = ? AND end_date IS NULL
            ");
            $u1->bind_param('ssssi', $today, $decision, $today, $current_admin_id, $sch_id);
            $u1->execute();
            if ($u1->affected_rows === 0) throw new RuntimeException("Historique déjà clôturé.");
            $u1->close();

            if ($decision !== 'abandonne') {
                // Créer nouvel enregistrement
                $i1 = $conn->prepare("
                    INSERT INTO student_class_history
                    (student_id, class_id, academic_year, start_date, status)
                    VALUES (?, ?, ?, ?, 'en_cours')
                ");
                $i1->bind_param('siss', $sid, $target_class_id, $target_year, $today);
                $i1->execute();
                $i1->close();

                if (in_array($decision, ['admis', 'transfere'], true)) {
                    $u2 = $conn->prepare("UPDATE users SET class_id = ?, current_academic_year = ? WHERE id = ?");
                    $u2->bind_param('iss', $target_class_id, $target_year, $sid);
                } else { // redoublant
                    $u2 = $conn->prepare("UPDATE users SET current_academic_year = ? WHERE id = ?");
                    $u2->bind_param('ss', $target_year, $sid);
                }
                $u2->execute();
                $u2->close();
            } else {
                // Abandonné
                $u2 = $conn->prepare("UPDATE users SET status = 'inactive', class_id = NULL WHERE id = ?");
                $u2->bind_param('s', $sid);
                $u2->execute();
                $u2->close();
            }

            $conn->commit();
            $ok_count++;

            // Audit log
            $desc    = "Délibération $source_year → $target_year — $sid — Décision: $decision";
            $new_val = json_encode([
                'student_id'      => $sid,
                'decision'        => $decision,
                'source_year'     => $source_year,
                'target_year'     => $target_year,
                'target_class_id' => $target_class_id,
            ]);
            $lg = $conn->prepare("
                INSERT INTO audit_log
                (action_type, entity_type, entity_id, description, new_value, performed_by, ip_address, user_agent)
                VALUES ('UPDATE', 'student_progression', ?, ?, ?, ?, ?, ?)
            ");
            $lg->bind_param('ssssss', $sid, $desc, $new_val, $current_admin_id, $ip, $ua);
            $lg->execute();
            $lg->close();

        } catch (Exception $e) {
            $conn->rollback();
            $err_count++;
            $err_msgs[] = "$sid : " . $e->getMessage();
        }
    }

    $summary = "$ok_count décision(s) appliquée(s).";
    if ($err_count > 0) {
        $summary .= " $err_count erreur(s) : " . implode(' | ', $err_msgs);
    }
    header('Location: passage_classe.php?ok=' . urlencode($summary));
    exit();
}

/* ═══════════════════════════════════════════════════════
   ACTION : annuler une décision (AJAX)
═══════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'annuler_decision') {
    try {
        $sch_id = (int)($_POST['sch_id'] ?? 0);
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (!$sch_id) ajax_json(false, 'ID manquant.');

        $sq = $conn->prepare("
            SELECT sch.*, c.name AS class_name
            FROM student_class_history sch
            JOIN classes c ON c.id = sch.class_id
            WHERE sch.id = ?
              AND sch.end_date IS NOT NULL
              AND sch.status IN ('admis','redoublant','transfere','abandonne')
        ");
        $sq->bind_param('i', $sch_id);
        $sq->execute();
        $source = $sq->get_result()->fetch_assoc();
        $sq->close();

        if (!$source) ajax_json(false, 'Décision introuvable ou déjà annulée.');

        $dest = null;
        if ($source['status'] !== 'abandonne') {
            $dq = $conn->prepare("
                SELECT * FROM student_class_history
                WHERE student_id = ? AND start_date = ? AND id > ?
                ORDER BY id ASC LIMIT 1
            ");
            $dq->bind_param('ssi', $source['student_id'], $source['end_date'], $sch_id);
            $dq->execute();
            $dest = $dq->get_result()->fetch_assoc();
            $dq->close();

            if ($dest && $dest['end_date'] !== null) {
                ajax_json(false, 'Impossible : une autre décision a déjà été prise depuis pour cet étudiant.');
            }
        }

        $warnings = [];
        if ($dest) {
            try {
                $pmts = $conn->prepare("SELECT COUNT(*) AS cnt FROM payments WHERE student_id = ? AND created_at >= ?");
                if ($pmts) {
                    $pmts->bind_param('ss', $source['student_id'], $source['end_date']);
                    $pmts->execute();
                    $prow = $pmts->get_result()->fetch_assoc();
                    $pmts->close();
                    if ($prow && (int)$prow['cnt'] > 0) {
                        $warnings[] = "L'étudiant a " . (int)$prow['cnt'] . " paiement(s) enregistré(s) depuis la décision.";
                    }
                }
            } catch (Exception $e) { /* table payments peut ne pas exister */ }
        }

        $inTransaction = false;
        $conn->begin_transaction();
        $inTransaction = true;
        try {
            if ($dest) {
                $dest_id = (int)$dest['id'];
                $del = $conn->prepare("DELETE FROM student_class_history WHERE id = ?");
                $del->bind_param('i', $dest_id);
                $del->execute();
                $del->close();
            }

            $upd = $conn->prepare("
                UPDATE student_class_history
                SET end_date = NULL, status = 'en_cours', decision_date = NULL, decision_by = NULL
                WHERE id = ?
            ");
            $upd->bind_param('i', $sch_id);
            $upd->execute();
            $upd->close();

            $src_class = (int)$source['class_id'];
            $src_year  = $source['academic_year'];
            $uu = $conn->prepare("UPDATE users SET class_id = ?, current_academic_year = ?, status = 'active' WHERE id = ?");
            $uu->bind_param('iss', $src_class, $src_year, $source['student_id']);
            $uu->execute();
            $uu->close();

            $conn->commit();
            $inTransaction = false;

            $desc    = "Annulation décision '{$source['status']}' pour étudiant {$source['student_id']} (SCH #{$sch_id})";
            $old_val = json_encode(['sch_id' => $sch_id, 'decision' => $source['status']]);
            $lg = $conn->prepare("INSERT INTO audit_log (action_type,entity_type,entity_id,description,old_value,performed_by,ip_address,user_agent) VALUES ('UPDATE','student_progression',?,?,?,?,?,?)");
            $lg->bind_param('ssssss', $source['student_id'], $desc, $old_val, $current_admin_id, $ip, $ua);
            $lg->execute();
            $lg->close();

            // DEBUG TEMPORAIRE
            $debug_output = ob_get_contents();
            if (!empty($debug_output)) {
                error_log("OUTPUT AVANT JSON annuler_decision: " . $debug_output);
            }

            ajax_json(true, 'Décision annulée avec succès.', ['warnings' => $warnings]);
        } catch (Exception $e) {
            $conn->rollback();
            $inTransaction = false;
            ajax_json(false, 'Erreur transaction : ' . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log("ERREUR annuler_decision: " . $e->getMessage() . " ligne " . $e->getLine() . " fichier " . $e->getFile());
        if (!empty($inTransaction)) {
            try { $conn->rollback(); } catch (\Throwable $re) {}
        }
        ajax_json(false, $e->getMessage());
    }
}

/* ═══════════════════════════════════════════════════════
   ACTION : get_decision_info (AJAX — pour modal modifier)
═══════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_decision_info') {
    $sch_id = (int)($_POST['sch_id'] ?? 0);
    if (!$sch_id) ajax_json(false, 'ID manquant.');

    $sq = $conn->prepare("
        SELECT sch.*, u.name AS student_name, u.id AS matricule, c.name AS source_class_name
        FROM student_class_history sch
        JOIN users u ON u.id = sch.student_id
        JOIN classes c ON c.id = sch.class_id
        WHERE sch.id = ? AND sch.status IN ('admis','redoublant','transfere','abandonne')
    ");
    $sq->bind_param('i', $sch_id);
    $sq->execute();
    $info = $sq->get_result()->fetch_assoc();
    $sq->close();

    if (!$info) ajax_json(false, 'Décision introuvable.');

    ajax_json(true, '', ['info' => [
        'student_name'      => $info['student_name'],
        'matricule'         => $info['matricule'] ?? '',
        'source_class_name' => $info['source_class_name'],
        'source_year'       => $info['academic_year'],
        'current_decision'  => $info['status'],
    ]]);
}

/* ═══════════════════════════════════════════════════════
   ACTION : confirmer_modification (AJAX)
═══════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirmer_modification') {
    $sch_id       = (int)trim($_POST['sch_id']          ?? 0);
    $new_decision = trim($_POST['new_decision']          ?? '');
    $new_target   = (int)trim($_POST['new_target_class'] ?? 0);
    $today        = date('Y-m-d');
    $ip           = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua           = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (!$sch_id || !in_array($new_decision, ['admis','redoublant','transfere','abandonne'], true)) {
        ajax_json(false, 'Paramètres invalides.');
    }
    if (in_array($new_decision, ['admis','transfere'], true) && !$new_target) {
        ajax_json(false, 'Classe cible requise pour cette décision.');
    }

    $sq = $conn->prepare("
        SELECT sch.*, c.next_class_id
        FROM student_class_history sch
        JOIN classes c ON c.id = sch.class_id
        WHERE sch.id = ? AND sch.end_date IS NOT NULL
          AND sch.status IN ('admis','redoublant','transfere','abandonne')
    ");
    $sq->bind_param('i', $sch_id);
    $sq->execute();
    $source = $sq->get_result()->fetch_assoc();
    $sq->close();
    if (!$source) ajax_json(false, 'Décision introuvable ou déjà annulée.');

    $dq = $conn->prepare("
        SELECT * FROM student_class_history
        WHERE student_id = ? AND start_date = ? AND id > ?
        ORDER BY id ASC LIMIT 1
    ");
    $dq->bind_param('ssi', $source['student_id'], $source['end_date'], $sch_id);
    $dq->execute();
    $dest = $dq->get_result()->fetch_assoc();
    $dq->close();

    if ($dest && $dest['end_date'] !== null) {
        ajax_json(false, 'Impossible : une autre décision a déjà été prise depuis.');
    }

    // Valider la transition pour admis
    if ($new_decision === 'admis' && $new_target) {
        $src_class = (int)$source['class_id'];
        $vr = $conn->prepare("SELECT id FROM class_transitions WHERE from_class_id = ? AND to_class_id = ?");
        $vr->bind_param('ii', $src_class, $new_target);
        $vr->execute();
        $valid_trans = (bool)$vr->get_result()->fetch_assoc();
        $vr->close();
        // On vérifie aussi si des transitions existent pour cette classe
        $ct = $conn->prepare("SELECT COUNT(*) AS cnt FROM class_transitions WHERE from_class_id = ?");
        $ct->bind_param('i', $src_class);
        $ct->execute();
        $ct_count = (int)($ct->get_result()->fetch_assoc()['cnt'] ?? 0);
        $ct->close();
        if ($ct_count > 0 && !$valid_trans) {
            ajax_json(false, 'Classe cible non autorisée pour ce cheminement (class_transitions).');
        }
    }

    $target_year = next_academic_year($source['academic_year']);
    $new_class_id = null;
    switch ($new_decision) {
        case 'admis':     $new_class_id = $new_target; break;
        case 'redoublant':$new_class_id = (int)$source['class_id']; break;
        case 'transfere': $new_class_id = $new_target; break;
        case 'abandonne': $new_class_id = null; break;
    }

    $conn->begin_transaction();
    try {
        if ($dest) {
            $dest_id = (int)$dest['id'];
            $del = $conn->prepare("DELETE FROM student_class_history WHERE id = ?");
            $del->bind_param('i', $dest_id);
            $del->execute();
            $del->close();
        }

        $upd = $conn->prepare("UPDATE student_class_history SET status = ?, decision_date = ?, decision_by = ? WHERE id = ?");
        $upd->bind_param('sssi', $new_decision, $today, $current_admin_id, $sch_id);
        $upd->execute();
        $upd->close();

        if ($new_decision !== 'abandonne') {
            $ins = $conn->prepare("INSERT INTO student_class_history (student_id,class_id,academic_year,start_date,status) VALUES (?,?,?,?,'en_cours')");
            $ins->bind_param('siss', $source['student_id'], $new_class_id, $target_year, $today);
            $ins->execute();
            $ins->close();

            if (in_array($new_decision, ['admis','transfere'], true)) {
                $uu = $conn->prepare("UPDATE users SET class_id = ?, current_academic_year = ? WHERE id = ?");
                $uu->bind_param('iss', $new_class_id, $target_year, $source['student_id']);
            } else {
                $uu = $conn->prepare("UPDATE users SET current_academic_year = ? WHERE id = ?");
                $uu->bind_param('ss', $target_year, $source['student_id']);
            }
            $uu->execute();
            $uu->close();
        } else {
            $uu = $conn->prepare("UPDATE users SET status = 'inactive', class_id = NULL WHERE id = ?");
            $uu->bind_param('s', $source['student_id']);
            $uu->execute();
            $uu->close();
        }

        $conn->commit();

        $desc    = "Modification décision: {$source['status']} → {$new_decision} pour étudiant {$source['student_id']}";
        $new_val = json_encode(['sch_id' => $sch_id, 'old_decision' => $source['status'], 'new_decision' => $new_decision, 'new_class' => $new_class_id]);
        $lg = $conn->prepare("INSERT INTO audit_log (action_type,entity_type,entity_id,description,new_value,performed_by,ip_address,user_agent) VALUES ('UPDATE','student_progression',?,?,?,?,?,?)");
        $lg->bind_param('ssssss', $source['student_id'], $desc, $new_val, $current_admin_id, $ip, $ua);
        $lg->execute();
        $lg->close();

        ajax_json(true, 'Décision modifiée avec succès.');
    } catch (Exception $e) {
        $conn->rollback();
        ajax_json(false, 'Erreur : ' . $e->getMessage());
    }
}

/* ═══════════════════════════════════════════════════════
   ACTION : historique filtré des décisions (AJAX)
═══════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_decisions_history') {
    $search      = trim($_POST['search']        ?? '');
    $filiere_id  = (int)($_POST['filiere_id']   ?? 0);
    $dec_type    = trim($_POST['decision_type'] ?? '');
    $source_year = trim($_POST['source_year']   ?? '');
    $per_page    = 100;
    $page        = max(0, (int)($_POST['page']  ?? 0));
    $offset      = $page * $per_page;

    $where  = ["sch.status IN ('admis','redoublant','transfere','abandonne')"];
    $params = [];
    $types  = '';

    if ($search !== '') {
        $likeVal  = '%' . $search . '%';
        $where[]  = "(u.name LIKE ? OR u.id LIKE ?)";
        $params[] = $likeVal;
        $params[] = $likeVal;
        $types   .= 'ss';
    }
    if ($filiere_id > 0) {
        $where[]  = "src_c.filiere_id = ?";
        $params[] = $filiere_id;
        $types   .= 'i';
    }
    if ($dec_type !== '' && in_array($dec_type, ['admis','redoublant','transfere','abandonne'], true)) {
        $where[]  = "sch.status = ?";
        $params[] = $dec_type;
        $types   .= 's';
    }
    if ($source_year !== '') {
        $where[]  = "sch.academic_year = ?";
        $params[] = $source_year;
        $types   .= 's';
    }

    $whereSQL = implode(' AND ', $where);

    // Compte total
    $cntSQL = "SELECT COUNT(*) AS total
        FROM student_class_history sch
        JOIN users u ON u.id = sch.student_id
        JOIN classes src_c ON src_c.id = sch.class_id
        WHERE $whereSQL";
    if ($types !== '') {
        $cq = $conn->prepare($cntSQL);
        $cq->bind_param($types, ...$params);
        $cq->execute();
        $total = (int)($cq->get_result()->fetch_assoc()['total'] ?? 0);
        $cq->close();
    } else {
        $total = (int)($conn->query($cntSQL)->fetch_assoc()['total'] ?? 0);
    }

    // Données paginées (100 par page)
    $dataSQL = "SELECT
        sch.id, sch.student_id, sch.class_id, sch.academic_year,
        sch.status AS decision, sch.decision_date, sch.end_date,
        u.name AS student_name, u.id AS matricule,
        src_c.name AS source_class_name, src_c.code AS source_class_code,
        adm.name AS admin_name,
        dst_sch.id AS dst_sch_id, dst_sch.class_id AS dst_class_id,
        dst_sch.academic_year AS dst_year, dst_sch.end_date AS dst_end_date,
        dst_c.name AS dst_class_name
    FROM student_class_history sch
    JOIN users u ON u.id = sch.student_id
    JOIN classes src_c ON src_c.id = sch.class_id
    LEFT JOIN users adm ON adm.id = sch.decision_by
    LEFT JOIN student_class_history dst_sch ON dst_sch.id = (
        SELECT MIN(d2.id) FROM student_class_history d2
        WHERE d2.student_id = sch.student_id AND d2.start_date = sch.end_date AND d2.id > sch.id
    )
    LEFT JOIN classes dst_c ON dst_c.id = dst_sch.class_id
    WHERE $whereSQL
    ORDER BY sch.decision_date DESC, sch.id DESC
    LIMIT ? OFFSET ?";

    $paramsD = array_merge($params, [$per_page, $offset]);
    $typesD  = $types . 'ii';

    $dq = $conn->prepare($dataSQL);
    $dq->bind_param($typesD, ...$paramsD);
    $dq->execute();
    $rows = $dq->get_result()->fetch_all(MYSQLI_ASSOC);
    $dq->close();

    ajax_json(true, '', ['rows' => $rows, 'total' => $total, 'page' => $page]);
}

page_render:

/* ═══════════════════════════════════════════════════════
   DONNÉES DE LA PAGE
═══════════════════════════════════════════════════════ */

if (isset($_GET['ok'])) $msg_ok  = htmlspecialchars($_GET['ok']);

$filieres = $conn->query("
    SELECT * FROM filieres WHERE is_active = 1 ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);

// Classes avec infos filière pour le JS
$all_classes_raw = $conn->query("
    SELECT c.id, c.name, c.code, c.filiere_id, c.level_number, c.next_class_id,
           f.name AS filiere_name, f.code AS filiere_code
    FROM classes c
    LEFT JOIN filieres f ON f.id = c.filiere_id
    ORDER BY f.name ASC, c.level_number ASC, c.name ASC
")->fetch_all(MYSQLI_ASSOC);

// Classes groupées par filière+niveau pour le sélecteur
$levels_by_filiere = [];
foreach ($all_classes_raw as $cls) {
    if ($cls['filiere_id'] && $cls['level_number']) {
        $fid = $cls['filiere_id'];
        $lvl = (int)$cls['level_number'];
        if (!isset($levels_by_filiere[$fid]) || !in_array($lvl, $levels_by_filiere[$fid])) {
            $levels_by_filiere[$fid][] = $lvl;
        }
    }
}
ksort($levels_by_filiere);
foreach ($levels_by_filiere as &$lvls) sort($lvls);
unset($lvls);

// Années académiques connues (pour suggestions)
$years_r = $conn->query("
    SELECT DISTINCT academic_year FROM student_class_history
    UNION SELECT DISTINCT school_year FROM evaluation_periods
    ORDER BY 1 DESC LIMIT 10
");
$known_years = $years_r ? array_column($years_r->fetch_all(MYSQLI_ASSOC), null) : [];

$filieres_json    = json_encode($filieres,         JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP);
$levels_json      = json_encode($levels_by_filiere, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP);
$all_classes_json = json_encode($all_classes_raw,   JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passage en classe supérieure – Administration</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<?php include '../includes/header.php'; ?>

<style>
:root {
    --primary-bg:   #051e34;
    --secondary-bg: #0c2d48;
    --accent:       #039be5;
    --accent-hover: #0288d1;
    --text:         #ffffff;
    --muted:        rgba(255,255,255,0.55);
    --border:       rgba(255,255,255,0.1);
    --success:      #2ecc71;
    --danger:       #e74c3c;
    --warning:      #f39c12;
    --info:         #3498db;
    --purple:       #9b59b6;
    --card-bg:      rgba(255,255,255,0.05);
}
*, *::before, *::after { box-sizing: border-box; }

main { max-width: 1400px; margin: 0 auto; padding: 24px 20px 60px; }

.breadcrumb { font-size: 0.82rem; color: var(--muted); margin-bottom: 6px; }
.breadcrumb a { color: var(--accent); text-decoration: none; }
.breadcrumb a:hover { text-decoration: underline; }

.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.page-header h2 {
    margin: 0; font-size: 1.7rem; color: var(--accent);
    display: flex; align-items: center; gap: 10px;
}

.alert {
    padding: 12px 18px; border-radius: 8px; margin-bottom: 20px;
    font-weight: 600; display: flex; align-items: flex-start; gap: 10px;
}
.alert-success { background: rgba(46,204,113,.2); border: 1px solid var(--success); color: var(--success); }
.alert-error   { background: rgba(231,76,60,.2);  border: 1px solid var(--danger);  color: var(--danger); }

.section-card {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 12px; padding: 24px; margin-bottom: 28px;
}
.section-card h3 {
    margin: 0 0 20px; font-size: 1.1rem; color: var(--accent);
    display: flex; align-items: center; gap: 8px;
    padding-bottom: 12px; border-bottom: 1px solid var(--border);
}

/* ── Filter grid ── */
.filter-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px; align-items: end;
}
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group label { font-size: 0.78rem; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.form-group input,
.form-group select {
    background: rgba(255,255,255,.07); border: 1px solid var(--border);
    border-radius: 6px; padding: 9px 12px; color: var(--text);
    font-size: .9rem; width: 100%; transition: border-color .2s;
}
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--accent); }
.form-group select option { background: var(--secondary-bg); }
.form-group input[readonly] { opacity: .7; cursor: not-allowed; }

/* ── Buttons ── */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border: none; border-radius: 6px;
    font-size: .88rem; font-weight: 600; cursor: pointer;
    transition: all .2s; text-decoration: none; white-space: nowrap;
}
.btn-primary   { background: var(--accent); color: #fff; }
.btn-primary:hover   { background: var(--accent-hover); }
.btn-secondary { background: rgba(255,255,255,.12); color: var(--text); }
.btn-secondary:hover { background: rgba(255,255,255,.2); }
.btn-success   { background: rgba(46,204,113,.2);  color: var(--success); border: 1px solid var(--success); }
.btn-success:hover   { background: rgba(46,204,113,.35); }
.btn-danger    { background: rgba(231,76,60,.2);   color: var(--danger);  border: 1px solid var(--danger); }
.btn-danger:hover    { background: rgba(231,76,60,.35); }
.btn-lg { padding: 11px 24px; font-size: .95rem; }
.btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Loading ── */
.loading-spinner { display: flex; align-items: center; gap: 10px; color: var(--muted); font-style: italic; padding: 20px 0; }
.spin { animation: spin 1s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Warning banner ── */
.warn-banner {
    background: rgba(243,156,18,.15); border: 1px solid var(--warning);
    border-radius: 8px; padding: 10px 16px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 10px; font-size: .88rem; color: var(--warning);
}

/* ── Students table ── */
.data-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.data-table th {
    background: var(--secondary-bg); padding: 10px 12px; text-align: left;
    color: var(--muted); font-weight: 600; font-size: .75rem;
    text-transform: uppercase; letter-spacing: .05em;
}
.data-table td { padding: 11px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover td { background: rgba(255,255,255,.02); }
.data-table .empty-row td { text-align: center; color: var(--muted); padding: 30px; font-style: italic; }

.avg-cell { font-weight: 600; font-size: .85rem; }
.avg-good { color: var(--success); }
.avg-mid  { color: var(--warning); }
.avg-bad  { color: var(--danger); }
.avg-none { color: var(--muted); font-style: italic; font-weight: 400; }

/* ── Decision select ── */
.decision-select {
    min-width: 160px; padding: 6px 10px;
    background: rgba(255,255,255,.07); border: 1px solid var(--border);
    border-radius: 6px; color: var(--text); font-size: .85rem; cursor: pointer;
}
.decision-select option { background: var(--secondary-bg); }
.decision-select.d-admis     { border-color: var(--success); color: var(--success); }
.decision-select.d-redoublant { border-color: var(--warning); color: var(--warning); }
.decision-select.d-transfere  { border-color: var(--info);    color: var(--info); }
.decision-select.d-abandonne  { border-color: var(--danger);  color: var(--danger); }

/* ── Transfer inline select ── */
.xfer-wrap { margin-top: 6px; }
.xfer-wrap select {
    min-width: 180px; padding: 5px 9px;
    background: rgba(52,152,219,.12); border: 1px solid var(--info);
    border-radius: 6px; color: var(--text); font-size: .8rem;
}
.xfer-wrap select option { background: var(--secondary-bg); }

/* ── Admis : sélecteur cible en cas de bifurcation ── */
.admis-target-wrap { margin-top: 6px; }
.admis-target-select {
    min-width: 180px; padding: 5px 9px;
    background: rgba(46,204,113,.1); border: 1px solid var(--success);
    border-radius: 6px; color: var(--text); font-size: .8rem; cursor: pointer;
    width: 100%;
}
.admis-target-select option { background: var(--secondary-bg); }
.admis-target-select:focus { outline: none; border-color: #27ae60; }

/* ── Classe suivante : bifurcation ── */
.next-fork { display: flex; flex-direction: column; gap: 3px; font-size: .76rem; }
.next-fork-label {
    color: var(--warning); font-weight: 600;
    display: flex; align-items: center; gap: 5px; margin-bottom: 2px;
}
.next-fork-item {
    color: var(--text); font-size: .72rem;
    padding-left: 12px; display: flex; align-items: center; gap: 4px;
}
.next-fork-item::before { content: "→"; color: var(--accent); font-size: .7rem; }
.next-fork-item em { color: var(--muted); font-size: .66rem; font-style: italic; }

/* ── Next class badge ── */
.next-badge {
    display: inline-flex; align-items: center; gap: 4px;
    background: rgba(3,155,229,.15); border: 1px solid rgba(3,155,229,.3);
    color: var(--accent); border-radius: 4px; padding: 2px 7px; font-size: .72rem; font-weight: 600;
}
.no-next { color: var(--warning); font-size: .78rem; display: flex; align-items: center; gap: 4px; }

/* ── Validate bar ── */
.validate-bar {
    position: sticky; bottom: 0; background: var(--secondary-bg);
    border-top: 1px solid var(--border); padding: 14px 20px;
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 10px; margin-top: 20px; border-radius: 0 0 12px 12px;
}
.validate-bar .summary { font-size: .88rem; color: var(--muted); }
.validate-bar .summary strong { color: var(--text); }

/* ── Modals ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.65); z-index: 1000;
    align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: var(--secondary-bg); border: 1px solid var(--border);
    border-radius: 12px; width: 100%; max-width: 520px;
    box-shadow: 0 20px 60px rgba(0,0,0,.5); max-height: 90vh; overflow-y: auto;
    animation: modal-in .2s ease;
}
@keyframes modal-in {
    from { opacity: 0; transform: translateY(-16px); }
    to   { opacity: 1; transform: translateY(0); }
}
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 22px; border-bottom: 1px solid var(--border);
    position: sticky; top: 0; background: var(--secondary-bg); z-index: 1;
}
.modal-header h3 { margin: 0; font-size: 1.05rem; color: var(--accent); }
.modal-close { background: none; border: none; color: var(--muted); font-size: 1.4rem; cursor: pointer; }
.modal-close:hover { color: var(--text); }
.modal-body { padding: 22px; display: flex; flex-direction: column; gap: 16px; }
.modal-footer { padding: 14px 22px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; }

/* ── Confirm list ── */
.confirm-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; max-height: 320px; overflow-y: auto; }
.confirm-list li { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; font-size: .85rem; }
.confirm-list .cl-name { flex: 1; font-weight: 600; }
.confirm-list .cl-badge {
    padding: 2px 9px; border-radius: 12px; font-size: .73rem; font-weight: 700; white-space: nowrap;
}
.badge-admis     { background: rgba(46,204,113,.2);  color: var(--success); }
.badge-redoublant { background: rgba(243,156,18,.2);  color: var(--warning); }
.badge-transfere  { background: rgba(52,152,219,.2);  color: var(--info); }
.badge-abandonne  { background: rgba(231,76,60,.2);   color: var(--danger); }
.confirm-list li.pending { background: rgba(255,255,255,.03); }

.count-strip {
    display: flex; flex-wrap: wrap; gap: 12px;
    padding: 12px 16px; background: rgba(255,255,255,.04);
    border-radius: 8px; font-size: .82rem;
}
.count-item { display: flex; align-items: center; gap: 5px; }
.count-item .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

@media (max-width: 700px) {
    .filter-grid { grid-template-columns: 1fr; }
    .data-table { font-size: .8rem; }
    .decision-select { min-width: 130px; }
}

.btn-warning   { background: rgba(243,156,18,.2);  color: var(--warning); border: 1px solid var(--warning); }
.btn-warning:hover { background: rgba(243,156,18,.35); }

.badge-decision {
    display: inline-block; padding: 2px 8px; border-radius: 10px;
    font-size: .72rem; font-weight: 700; text-transform: uppercase; white-space: nowrap;
}
.badge-admis      { background: rgba(46,204,113,.15);  color: var(--success); }
.badge-redoublant { background: rgba(243,156,18,.15);  color: var(--warning); }
.badge-transfere  { background: rgba(52,152,219,.15);  color: var(--info); }
.badge-abandonne  { background: rgba(231,76,60,.15);   color: var(--danger); }
.locked-badge     { color: var(--muted); font-size: .75rem; display: flex; align-items: center; gap: 4px; }
</style>

<main>
    <div class="breadcrumb">
        <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a>
        &rsaquo; <a href="class_management.php">Classes</a>
        &rsaquo; Passage en classe supérieure
    </div>

    <div class="page-header">
        <h2><i class="fas fa-level-up-alt"></i> Délibération &amp; Passage en classe</h2>
        <a href="gestion_filieres.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-sitemap"></i> Filières
        </a>
    </div>

    <?php if ($msg_ok): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle" style="flex-shrink:0"></i> <span><?= $msg_ok ?></span></div>
    <?php endif; ?>
    <?php if ($msg_err): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle" style="flex-shrink:0"></i> <span><?= $msg_err ?></span></div>
    <?php endif; ?>

    <!-- ── FILTRES ── -->
    <div class="section-card">
        <h3><i class="fas fa-filter"></i> Sélection de la promotion</h3>
        <div class="filter-grid">
            <div class="form-group">
                <label>Filière</label>
                <select id="sel-filiere" onchange="onFiliereChange()">
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($filieres as $f): ?>
                        <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['code']) ?> – <?= htmlspecialchars($f['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Année académique source</label>
                <input type="text" id="sel-year" placeholder="ex : 2024-2025"
                       list="years-list" oninput="onYearChange()" autocomplete="off">
                <datalist id="years-list">
                    <?php foreach ($known_years as $yr): ?>
                        <option value="<?= htmlspecialchars($yr['academic_year'] ?? $yr['school_year'] ?? '') ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label>Niveau source</label>
                <select id="sel-level" disabled>
                    <option value="">— Choisir la filière d'abord —</option>
                </select>
            </div>
            <div class="form-group">
                <label>Année cible (auto-calculée)</label>
                <input type="text" id="sel-target-year" placeholder="2025-2026" autocomplete="off">
            </div>
            <div class="form-group" style="justify-content:flex-end">
                <button class="btn btn-primary" onclick="loadStudents()" id="btn-load">
                    <i class="fas fa-users"></i> Charger les étudiants
                </button>
            </div>
        </div>
    </div>

    <!-- ── ZONE DÉLIBÉRATION ── -->
    <div id="delib-zone"></div>

    <!-- ── HISTORIQUE DES DÉCISIONS (chargé via AJAX) ── -->
    <div class="section-card" id="decisions-history-section" style="margin-top:32px">
        <h3><i class="fas fa-history"></i> Historique des décisions</h3>

        <!-- Filtres -->
        <div class="filter-grid" style="margin-bottom:16px">
            <div class="form-group">
                <label>Rechercher</label>
                <input type="text" id="hist-search" placeholder="Nom étudiant ou matricule"
                       oninput="scheduleHistSearch()" onkeydown="if(event.key==='Enter')loadHistory(0)">
            </div>
            <div class="form-group">
                <label>Filière</label>
                <select id="hist-filiere">
                    <option value="">Toutes les filières</option>
                    <?php foreach ($filieres as $f): ?>
                    <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['code']) ?> – <?= htmlspecialchars($f['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Type de décision</label>
                <select id="hist-decision">
                    <option value="">Toutes</option>
                    <option value="admis">Admis</option>
                    <option value="redoublant">Redoublant</option>
                    <option value="transfere">Transféré</option>
                    <option value="abandonne">Abandonné</option>
                </select>
            </div>
            <div class="form-group">
                <label>Année source</label>
                <select id="hist-year">
                    <option value="">Toutes les années</option>
                    <?php foreach ($known_years as $yr):
                        $yval = $yr['academic_year'] ?? $yr['school_year'] ?? '';
                        if (!$yval) continue;
                    ?>
                    <option value="<?= htmlspecialchars($yval) ?>"><?= htmlspecialchars($yval) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="justify-content:flex-end">
                <button class="btn btn-primary" onclick="loadHistory(0)" id="btn-hist-search">
                    <i class="fas fa-search"></i> Rechercher
                </button>
            </div>
        </div>

        <!-- Info résultats + pagination haute -->
        <div id="hist-meta" style="display:none;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;font-size:.82rem;color:var(--muted)">
            <span id="hist-count-label"></span>
            <div id="hist-pager-top"></div>
        </div>

        <!-- Tableau -->
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Étudiant</th>
                        <th>Matricule</th>
                        <th>Classe source</th>
                        <th>→ Destination</th>
                        <th>Décision</th>
                        <th>Date</th>
                        <th>Admin</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="hist-tbody">
                    <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:30px">
                        <i class="fas fa-search" style="display:block;font-size:1.4rem;margin-bottom:8px;opacity:.4"></i>
                        Saisissez des critères et cliquez sur Rechercher
                    </td></tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination basse -->
        <div id="hist-pager-bottom" style="display:flex;justify-content:center;margin-top:14px"></div>
    </div>

</main>

<!-- ── MODAL : Annuler une décision ── -->
<div id="modal-annuler" class="modal-overlay" onclick="if(event.target===this)closeModal('modal-annuler')">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-times-circle"></i> Annuler une décision</h3>
            <button class="modal-close" onclick="closeModal('modal-annuler')">&times;</button>
        </div>
        <div class="modal-body">
            <p id="annuler-text" style="color:var(--muted);font-size:.9rem;line-height:1.5"></p>
            <div id="annuler-warnings" class="warn-banner" style="display:none"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modal-annuler')">Fermer</button>
            <button class="btn btn-danger btn-lg" onclick="submitAnnuler()" id="btn-annuler-confirm">
                <i class="fas fa-trash"></i> Confirmer l'annulation
            </button>
        </div>
    </div>
</div>

<!-- ── MODAL : Modifier une décision ── -->
<div id="modal-modifier" class="modal-overlay" onclick="if(event.target===this)closeModal('modal-modifier')">
    <div class="modal-box" style="max-width:580px">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Modifier une décision</h3>
            <button class="modal-close" onclick="closeModal('modal-modifier')">&times;</button>
        </div>
        <div class="modal-body" id="modal-modifier-body">
            <div class="loading-spinner"><i class="fas fa-spinner spin"></i> Chargement…</div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modal-modifier')">Annuler</button>
            <button class="btn btn-success btn-lg" onclick="submitModifier()" id="btn-modifier-confirm">
                <i class="fas fa-check"></i> Appliquer la modification
            </button>
        </div>
    </div>
</div>

<!-- ── MODAL : Confirmation ── -->
<div id="modal-confirm" class="modal-overlay" onclick="if(event.target===this)closeModal('modal-confirm')">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-gavel"></i> Confirmer les décisions</h3>
            <button class="modal-close" onclick="closeModal('modal-confirm')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="color:var(--muted);font-size:.9rem;">
                Résumé des décisions à appliquer. Cette action est irréversible.
            </p>
            <div id="confirm-counts" class="count-strip"></div>
            <ul id="confirm-list" class="confirm-list"></ul>
            <p style="color:var(--warning);font-size:.82rem;display:flex;align-items:center;gap:6px" id="confirm-pending-warn" hidden>
                <i class="fas fa-exclamation-triangle"></i>
                Certains étudiants n'ont pas de décision sélectionnée et seront ignorés.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modal-confirm')">Annuler</button>
            <button class="btn btn-success btn-lg" onclick="submitDecisions()" id="btn-confirm-submit">
                <i class="fas fa-check-double"></i> Appliquer les décisions
            </button>
        </div>
    </div>
</div>

<!-- ── FORM caché pour la soumission ── -->
<form id="delib-form" method="POST" action="passage_classe.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
    <input type="hidden" name="action"      value="validate_decisions">
    <input type="hidden" name="source_year" id="form-source-year" value="">
    <input type="hidden" name="target_year" id="form-target-year" value="">
    <div id="form-decisions-container"></div>
</form>

<?php include '../includes/footer.php'; ?>

<script>
const FILIERES  = <?= $filieres_json ?>;
const LEVELS    = <?= $levels_json ?>;
const ALL_CLASSES = <?= $all_classes_json ?>;

let currentStudents = [];
let currentSourceYear = '';
let currentTargetYear = '';

/* ── Utilitaires ── */
function openModal(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => {
        m.classList.remove('open'); document.body.style.overflow = '';
    });
});

function nextYear(y) {
    const m = y.match(/^(\d{4})-(\d{4})$/);
    return m ? (parseInt(m[1])+1) + '-' + (parseInt(m[2])+1) : y;
}

function fmtAvg(v) {
    if (v === null || v === undefined) return '<span class="avg-none">—</span>';
    const cls = v >= 12 ? 'avg-good' : v >= 10 ? 'avg-mid' : 'avg-bad';
    return `<span class="avg-cell ${cls}">${parseFloat(v).toFixed(2)}</span>`;
}

function fmtCredits(v) {
    if (v === null || v === undefined) return '<span class="avg-none">—</span>';
    const parts = v.split('/');
    const obt = parseInt(parts[0]), tot = parseInt(parts[1]);
    const cls = obt >= tot ? 'avg-good' : obt > 0 ? 'avg-mid' : 'avg-bad';
    return `<span class="avg-cell ${cls}">${v}</span>`;
}

/* ── Sélecteurs dynamiques ── */
function onFiliereChange() {
    const fid = parseInt(document.getElementById('sel-filiere').value);
    const lvlSel = document.getElementById('sel-level');
    lvlSel.innerHTML = '<option value="">— Sélectionner —</option>';
    if (!fid || !LEVELS[fid]) {
        lvlSel.disabled = true;
        lvlSel.firstElementChild.textContent = '— Aucun niveau défini —';
        return;
    }
    LEVELS[fid].forEach(lvl => {
        const o = document.createElement('option');
        o.value = lvl;
        o.textContent = 'Niveau ' + lvl;
        lvlSel.appendChild(o);
    });
    lvlSel.disabled = false;
}

function onYearChange() {
    const y = document.getElementById('sel-year').value.trim();
    const ty = y.match(/^\d{4}-\d{4}$/) ? nextYear(y) : '';
    document.getElementById('sel-target-year').value = ty;
}

/* ── Chargement des étudiants ── */
async function loadStudents() {
    const fid   = parseInt(document.getElementById('sel-filiere').value || '0');
    const year  = document.getElementById('sel-year').value.trim();
    const level = parseInt(document.getElementById('sel-level').value || '0');

    if (!fid)   { alert('Veuillez sélectionner une filière.'); return; }
    if (!year)  { alert('Veuillez saisir l\'année académique source.'); return; }
    if (!level) { alert('Veuillez sélectionner un niveau.'); return; }

    const target = document.getElementById('sel-target-year').value.trim() || nextYear(year);
    document.getElementById('sel-target-year').value = target;

    const zone = document.getElementById('delib-zone');
    zone.innerHTML = `
        <div class="section-card">
            <div class="loading-spinner">
                <i class="fas fa-spinner spin"></i> Chargement des étudiants et calcul des moyennes…
            </div>
        </div>`;

    document.getElementById('btn-load').disabled = true;

    try {
        const fd = new FormData();
        fd.append('action',       'load_students');
        fd.append('ajax',         '1');
        fd.append('filiere_id',   fid);
        fd.append('academic_year', year);
        fd.append('level_number', level);

        const resp = await fetch('passage_classe.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (!data.success) {
            zone.innerHTML = `<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> ${data.error}</div>`;
            return;
        }

        currentStudents    = data.students;
        currentSourceYear  = year;
        currentTargetYear  = target;
        document.getElementById('form-source-year').value = year;
        document.getElementById('form-target-year').value = target;

        renderTable(data);
    } catch(e) {
        zone.innerHTML = `<div class="alert alert-error"><i class="fas fa-times-circle"></i> Erreur réseau : ${e.message}</div>`;
    } finally {
        document.getElementById('btn-load').disabled = false;
    }
}

/* ── Rendu du tableau ── */
function renderTable(data) {
    const { students, warn_no_next, target_year } = data;
    const zone = document.getElementById('delib-zone');

    if (!students.length) {
        zone.innerHTML = `
            <div class="section-card">
                <div class="data-table empty-row">
                    <p style="color:var(--muted);text-align:center;padding:20px">
                        <i class="fas fa-inbox fa-2x" style="display:block;margin-bottom:10px"></i>
                        Aucun étudiant actif trouvé pour ce niveau / cette année.
                    </p>
                </div>
            </div>`;
        return;
    }

    // Build class options for transfer
    const xferOptions = ALL_CLASSES.map(c => {
        const label = (c.filiere_code ? `[${c.filiere_code}] ` : '') + (c.code || '—') + ' – ' + c.name;
        return `<option value="${c.id}">${label}</option>`;
    }).join('');

    let rows = '';
    students.forEach(s => {
        const trans   = s.transitions || [];
        const hasFork = trans.length > 1;
        const hasNext = !!s.next_class_id || trans.length > 0;

        // Colonne "Classe suivante"
        let nextHtml;
        if (hasFork) {
            const items = trans.map(t =>
                `<span class="next-fork-item">${t.to_code || '—'} ${t.to_name}${t.condition_label ? ` <em>(${t.condition_label})</em>` : ''}</span>`
            ).join('');
            nextHtml = `<div class="next-fork">
                <span class="next-fork-label"><i class="fas fa-code-branch"></i> ${trans.length} choix</span>
                ${items}
            </div>`;
        } else if (s.next_class_name) {
            nextHtml = `<span class="next-badge"><i class="fas fa-arrow-right"></i> ${s.next_class_name}</span>`;
        } else if (trans.length === 1) {
            nextHtml = `<span class="next-badge"><i class="fas fa-arrow-right"></i> ${trans[0].to_code || '—'} ${trans[0].to_name}</span>`;
        } else {
            nextHtml = `<span class="no-next"><i class="fas fa-exclamation-triangle"></i> Non défini</span>`;
        }

        const admisDisabled = hasNext ? '' : 'disabled title="Aucune classe suivante définie"';

        // Select cible pour admis en cas de bifurcation
        let admisTargetHtml = '';
        if (hasFork) {
            const opts = trans.map(t =>
                `<option value="${t.to_class_id}">${t.to_code || '—'} – ${t.to_name}${t.condition_label ? ` (${t.condition_label})` : ''}</option>`
            ).join('');
            admisTargetHtml = `
                <div id="admis-target-wrap-${s.id}" class="admis-target-wrap" style="display:none">
                    <select id="admis-target-${s.id}" class="admis-target-select">
                        <option value="">— Choisir la classe cible —</option>
                        ${opts}
                    </select>
                </div>`;
        }

        rows += `
            <tr id="row-${s.id}" data-sid="${s.id}" data-class="${s.class_id}" data-has-next="${hasNext ? 1 : 0}" data-fork="${hasFork ? 1 : 0}">
                <td>
                    <div style="font-weight:600">${s.name}</div>
                    <div style="font-size:.75rem;color:var(--muted)">${s.class_name} <span style="color:var(--accent)">${s.class_code || ''}</span></div>
                </td>
                <td style="font-family:monospace;font-size:.82rem">${s.id}</td>
                <td>${fmtAvg(s.avg_s1)}</td>
                <td>${fmtCredits(s.credits_s1)}</td>
                <td>${fmtAvg(s.avg_s2)}</td>
                <td>${fmtCredits(s.credits_s2)}</td>
                <td>${fmtAvg(s.avg_annual)}</td>
                <td>${nextHtml}</td>
                <td>
                    <select name="decisions[${s.id}]" class="decision-select"
                            onchange="onDecisionChange(this, '${s.id}')">
                        <option value="">— Décision —</option>
                        <option value="admis"      ${admisDisabled}>Admis</option>
                        <option value="redoublant">Redoublant</option>
                        <option value="transfere">Transfere</option>
                        <option value="abandonne">Abandonne</option>
                    </select>
                    ${admisTargetHtml}
                    <div id="xfer-${s.id}" class="xfer-wrap" style="display:none">
                        <select name="xfer_class[${s.id}]" id="xfer-sel-${s.id}">
                            <option value="">— Classe cible —</option>
                            ${xferOptions}
                        </select>
                    </div>
                </td>
            </tr>`;
    });

    zone.innerHTML = `
        <div class="section-card" style="padding-bottom:0">
            <h3>
                <i class="fas fa-users"></i>
                Étudiants éligibles
                <span style="margin-left:auto;font-size:.78rem;color:var(--muted);font-weight:400">
                    ${students.length} étudiant(s) &bull;
                    <span style="color:var(--accent)">${currentSourceYear}</span>
                    <i class="fas fa-arrow-right" style="margin:0 4px"></i>
                    <span style="color:var(--success)">${target_year}</span>
                </span>
            </h3>

            ${warn_no_next ? `
                <div class="warn-banner">
                    <i class="fas fa-exclamation-triangle"></i>
                    Certaines classes n'ont pas de classe suivante définie (next_class_id = NULL).
                    L'option « Admis » sera désactivée pour ces étudiants.
                    <a href="gestion_filieres.php" style="color:var(--warning);font-weight:700;margin-left:auto">Configurer →</a>
                </div>` : ''}

            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nom / Classe</th>
                            <th>Matricule</th>
                            <th>Moy. S1</th>
                            <th>Crédits S1</th>
                            <th>Moy. S2</th>
                            <th>Crédits S2</th>
                            <th>Moy. Annuelle</th>
                            <th>Classe suivante</th>
                            <th>Décision</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>

            <div class="validate-bar" id="validate-bar">
                <div class="summary" id="decision-summary">
                    <strong>0</strong> décision(s) sélectionnée(s) sur <strong>${students.length}</strong>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button class="btn btn-secondary" onclick="applyAll('admis')">
                        <i class="fas fa-check-double"></i> Tous admis
                    </button>
                    <button class="btn btn-warning" onclick="applyAll('redoublant')">
                        <i class="fas fa-redo"></i> Tous redoublants
                    </button>
                    <button class="btn btn-success btn-lg" onclick="openConfirmModal()" id="btn-validate">
                        <i class="fas fa-gavel"></i> Valider les décisions
                    </button>
                </div>
            </div>
        </div>`;

    updateSummary();
}

/* ── Changement de décision ── */
function onDecisionChange(sel, sid) {
    const val       = sel.value;
    const xfer      = document.getElementById('xfer-' + sid);
    const admisWrap = document.getElementById('admis-target-wrap-' + sid);

    sel.className = 'decision-select' + (val ? ' d-' + val : '');

    if (xfer)      xfer.style.display      = (val === 'transfere') ? 'block' : 'none';
    if (admisWrap) admisWrap.style.display  = (val === 'admis')    ? 'block' : 'none';

    updateSummary();
}

/* ── Appliquer à tous ── */
function applyAll(decision) {
    document.querySelectorAll('.decision-select').forEach(sel => {
        const sid  = sel.name.replace('decisions[', '').replace(']', '');
        const row  = document.getElementById('row-' + sid);
        const hasNext = row && row.dataset.hasNext === '1';

        if (decision === 'admis' && !hasNext) return; // skip if no next class

        sel.value = decision;
        onDecisionChange(sel, sid);
    });
}

/* ── Résumé ── */
function updateSummary() {
    const sels = document.querySelectorAll('.decision-select');
    let total = 0, filled = 0;
    sels.forEach(s => { total++; if (s.value) filled++; });
    const el = document.getElementById('decision-summary');
    if (el) el.innerHTML = `<strong>${filled}</strong> décision(s) sélectionnée(s) sur <strong>${total}</strong>`;
}

/* ── Modal confirmation ── */
function openConfirmModal() {
    const sels = document.querySelectorAll('.decision-select');
    if (!sels.length) return;

    const counts = { admis: 0, redoublant: 0, transfere: 0, abandonne: 0, pending: 0 };
    let listHtml = '';
    let hasPending = false;

    sels.forEach(sel => {
        const sid  = sel.name.replace('decisions[', '').replace(']', '');
        const row  = document.getElementById('row-' + sid);
        const name = row ? row.querySelector('td').querySelector('div').textContent.trim() : sid;
        const dec  = sel.value;

        if (!dec) { counts.pending++; hasPending = true; return; }

        counts[dec]++;
        let extra = '';
        if (dec === 'admis') {
            const admisTarget = document.getElementById('admis-target-' + sid);
            const admisWrap   = document.getElementById('admis-target-wrap-' + sid);
            if (admisTarget && admisWrap && admisWrap.style.display !== 'none') {
                if (admisTarget.value) {
                    extra = ` <span style="color:var(--muted);font-size:.78rem">→ ${admisTarget.options[admisTarget.selectedIndex].text}</span>`;
                } else {
                    extra = ` <span style="color:var(--danger);font-size:.78rem"><i class="fas fa-exclamation-triangle"></i> Classe non choisie</span>`;
                }
            }
        } else if (dec === 'transfere') {
            const xferSel = document.getElementById('xfer-sel-' + sid);
            const xferText = xferSel && xferSel.value
                ? xferSel.options[xferSel.selectedIndex].text
                : '<em style="color:var(--danger)">Classe non spécifiée</em>';
            extra = ` <span style="color:var(--muted);font-size:.78rem">→ ${xferText}</span>`;
        }
        listHtml += `
            <li class="pending">
                <span class="cl-name">${name}</span>
                <span class="cl-badge badge-${dec}">${dec.toUpperCase()}</span>
                ${extra}
            </li>`;
    });

    const colors = { admis: '#2ecc71', redoublant: '#f39c12', transfere: '#3498db', abandonne: '#e74c3c' };
    const labels = { admis: 'Admis', redoublant: 'Redoublants', transfere: 'Transferes', abandonne: 'Abandonnes' };
    let countHtml = '';
    ['admis', 'redoublant', 'transfere', 'abandonne'].forEach(d => {
        if (counts[d]) {
            countHtml += `<span class="count-item">
                <span class="dot" style="background:${colors[d]}"></span>
                <strong>${counts[d]}</strong> ${labels[d]}
            </span>`;
        }
    });

    document.getElementById('confirm-counts').innerHTML = countHtml;
    document.getElementById('confirm-list').innerHTML   = listHtml || '<li style="color:var(--muted);text-align:center;padding:16px">Aucune décision sélectionnée.</li>';

    const pw = document.getElementById('confirm-pending-warn');
    pw.hidden = !hasPending;

    openModal('modal-confirm');
}

/* ── Soumission ── */
function submitDecisions() {
    // Injecter les décisions dans le form caché
    const container = document.getElementById('form-decisions-container');
    container.innerHTML = '';

    document.querySelectorAll('.decision-select').forEach(sel => {
        if (!sel.value) return;
        const sid = sel.name.replace('decisions[', '').replace(']', '');

        const hd = document.createElement('input');
        hd.type  = 'hidden';
        hd.name  = sel.name;
        hd.value = sel.value;
        container.appendChild(hd);

        // Classe cible choisie pour "admis" en cas de bifurcation
        if (sel.value === 'admis') {
            const admisTarget = document.getElementById('admis-target-' + sid);
            if (admisTarget && admisTarget.value) {
                const ha = document.createElement('input');
                ha.type  = 'hidden';
                ha.name  = 'target_class[' + sid + ']';
                ha.value = admisTarget.value;
                container.appendChild(ha);
            }
        }

        if (sel.value === 'transfere') {
            const xferSel = document.getElementById('xfer-sel-' + sid);
            if (xferSel && xferSel.value) {
                const hx = document.createElement('input');
                hx.type  = 'hidden';
                hx.name  = 'xfer_class[' + sid + ']';
                hx.value = xferSel.value;
                container.appendChild(hx);
            }
        }
    });

    document.getElementById('btn-confirm-submit').disabled = true;
    document.getElementById('delib-form').submit();
}

/* ═══════════════════════════════════════════════════════
   Annuler / Modifier une décision
═══════════════════════════════════════════════════════ */

let _annulerSchId = null;
let _modifierSchId = null;

function confirmAnnuler(schId, studentName) {
    _annulerSchId = schId;
    document.getElementById('annuler-text').innerHTML =
        `Voulez-vous annuler la décision pour <strong>${studentName}</strong> ?<br>
         <span style="color:var(--danger);font-size:.85rem">
           L'inscription source sera réouverte et l'inscription de destination supprimée.
         </span>`;
    document.getElementById('annuler-warnings').style.display = 'none';
    document.getElementById('btn-annuler-confirm').disabled = false;
    document.getElementById('btn-annuler-confirm').innerHTML = '<i class="fas fa-trash"></i> Confirmer l\'annulation';
    openModal('modal-annuler');
}

async function submitAnnuler() {
    if (!_annulerSchId) return;
    const btn = document.getElementById('btn-annuler-confirm');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner spin"></i> Annulation…';

    const fd = new FormData();
    fd.append('action', 'annuler_decision');
    fd.append('sch_id', _annulerSchId);
    try {
        const resp = await fetch('passage_classe.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            if (data.warnings && data.warnings.length > 0) {
                const wDiv = document.getElementById('annuler-warnings');
                wDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i>&nbsp;' + data.warnings.join('<br>');
                wDiv.style.display = 'flex';
            }
            setTimeout(() => { window.location.reload(); }, data.warnings?.length ? 2500 : 0);
        } else {
            alert('Erreur : ' + data.error);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i> Confirmer l\'annulation';
        }
    } catch(e) {
        alert('Erreur réseau : ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash"></i> Confirmer l\'annulation';
    }
}

async function openModifierModal(schId, studentName) {
    _modifierSchId = schId;
    openModal('modal-modifier');
    document.getElementById('btn-modifier-confirm').disabled = false;
    document.getElementById('btn-modifier-confirm').innerHTML = '<i class="fas fa-check"></i> Appliquer la modification';

    const body = document.getElementById('modal-modifier-body');
    body.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner spin"></i> Chargement…</div>';

    const fd = new FormData();
    fd.append('action', 'get_decision_info');
    fd.append('sch_id', schId);
    try {
        const resp = await fetch('passage_classe.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (!data.success) {
            body.innerHTML = `<p style="color:var(--danger)">${data.error}</p>`;
            return;
        }
        const d = data.info;
        const classOptions = ALL_CLASSES.map(c => {
            const lbl = (c.filiere_code ? `[${c.filiere_code}] ` : '') + (c.code || '—') + ' – ' + c.name;
            return `<option value="${c.id}">${lbl}</option>`;
        }).join('');

        body.innerHTML = `
            <div style="background:rgba(255,255,255,.04);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.88rem">
                <div>Étudiant : <strong style="color:var(--text)">${d.student_name}</strong>
                  ${d.matricule ? `<span style="color:var(--muted);margin-left:6px">(${d.matricule})</span>` : ''}</div>
                <div style="margin-top:4px">Classe source : <strong style="color:var(--accent)">${d.source_class_name}</strong>
                  <span style="color:var(--muted);margin-left:6px">${d.source_year}</span></div>
                <div style="margin-top:4px">Décision actuelle : <span class="badge-decision badge-${d.current_decision}">${d.current_decision}</span></div>
            </div>
            <div class="form-group">
                <label>Nouvelle décision</label>
                <select id="mod-decision" class="decision-select" onchange="onModDecisionChange()">
                    <option value="">— Choisir —</option>
                    <option value="admis">Admis</option>
                    <option value="redoublant">Redoublant</option>
                    <option value="transfere">Transféré</option>
                    <option value="abandonne">Abandonné</option>
                </select>
            </div>
            <div id="mod-target-wrap" style="display:none" class="form-group">
                <label>Classe cible (admis)</label>
                <select id="mod-target-class" class="admis-target-select">
                    <option value="">— Sélectionner —</option>
                    ${classOptions}
                </select>
            </div>
            <div id="mod-xfer-wrap" style="display:none" class="form-group">
                <label>Classe de transfert</label>
                <select id="mod-xfer-class" style="background:rgba(52,152,219,.12);border:1px solid var(--info);border-radius:6px;padding:9px 12px;color:var(--text);width:100%">
                    <option value="">— Sélectionner —</option>
                    ${classOptions}
                </select>
            </div>`;
    } catch(e) {
        body.innerHTML = `<p style="color:var(--danger)">Erreur réseau : ${e.message}</p>`;
    }
}

function onModDecisionChange() {
    const val = document.getElementById('mod-decision')?.value;
    const tw  = document.getElementById('mod-target-wrap');
    const xw  = document.getElementById('mod-xfer-wrap');
    if (tw) tw.style.display = (val === 'admis')     ? 'block' : 'none';
    if (xw) xw.style.display = (val === 'transfere') ? 'block' : 'none';
}

async function submitModifier() {
    if (!_modifierSchId) return;
    const decision = document.getElementById('mod-decision')?.value;
    const target   = document.getElementById('mod-target-class')?.value;
    const xfer     = document.getElementById('mod-xfer-class')?.value;

    if (!decision) { alert('Veuillez choisir une nouvelle décision.'); return; }
    if (decision === 'admis'     && !target) { alert('Veuillez choisir la classe cible.'); return; }
    if (decision === 'transfere' && !xfer)   { alert('Veuillez choisir la classe de transfert.'); return; }

    const btn = document.getElementById('btn-modifier-confirm');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner spin"></i> Application…';

    const fd = new FormData();
    fd.append('action',       'confirmer_modification');
    fd.append('sch_id',       _modifierSchId);
    fd.append('new_decision', decision);
    if (decision === 'admis'     && target) fd.append('new_target_class', target);
    if (decision === 'transfere' && xfer)   fd.append('new_target_class', xfer);

    try {
        const resp = await fetch('passage_classe.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert('Erreur : ' + data.error);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Appliquer la modification';
        }
    } catch(e) {
        alert('Erreur réseau : ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Appliquer la modification';
    }
}

/* ═══════════════════════════════════════════════════════
   Historique des décisions — recherche + pagination AJAX
═══════════════════════════════════════════════════════ */

const HIST_PER_PAGE = 100;
let _histSearchTimer = null;

function scheduleHistSearch() {
    clearTimeout(_histSearchTimer);
    _histSearchTimer = setTimeout(() => loadHistory(0), 450);
}

async function loadHistory(page) {
    const tbody = document.getElementById('hist-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)"><i class="fas fa-spinner spin"></i> Chargement…</td></tr>';

    const fd = new FormData();
    fd.append('action',        'get_decisions_history');
    fd.append('search',        document.getElementById('hist-search')?.value  ?? '');
    fd.append('filiere_id',    document.getElementById('hist-filiere')?.value ?? '');
    fd.append('decision_type', document.getElementById('hist-decision')?.value ?? '');
    fd.append('source_year',   document.getElementById('hist-year')?.value    ?? '');
    fd.append('page',          page);

    try {
        const resp = await fetch('passage_classe.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--danger);padding:20px">${escH(data.error)}</td></tr>`;
            return;
        }
        renderHistoryTable(data.rows, data.total, data.page);
    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--danger);padding:20px">Erreur réseau : ${escH(e.message)}</td></tr>`;
    }
}

function renderHistoryTable(rows, total, page) {
    const tbody    = document.getElementById('hist-tbody');
    const metaEl   = document.getElementById('hist-meta');
    const countEl  = document.getElementById('hist-count-label');
    const pagerTop = document.getElementById('hist-pager-top');
    const pagerBot = document.getElementById('hist-pager-bottom');

    const from       = total === 0 ? 0 : page * HIST_PER_PAGE + 1;
    const to         = Math.min((page + 1) * HIST_PER_PAGE, total);
    const totalPages = Math.ceil(total / HIST_PER_PAGE);

    if (metaEl)  metaEl.style.display  = '';
    if (countEl) countEl.textContent   = `${from}–${to} sur ${total} résultat(s)`;

    const pagerHtml = totalPages > 1 ? `
        <div style="display:flex;align-items:center;gap:8px">
            <button class="btn btn-secondary" style="padding:5px 12px;font-size:.8rem"
                    onclick="loadHistory(${page - 1})" ${page === 0 ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i> Précédent
            </button>
            <span style="font-size:.82rem;color:var(--muted)">Page ${page + 1} / ${totalPages}</span>
            <button class="btn btn-secondary" style="padding:5px 12px;font-size:.8rem"
                    onclick="loadHistory(${page + 1})" ${page >= totalPages - 1 ? 'disabled' : ''}>
                Suivant <i class="fas fa-chevron-right"></i>
            </button>
        </div>` : '';
    if (pagerTop) pagerTop.innerHTML = pagerHtml;
    if (pagerBot) pagerBot.innerHTML = pagerHtml;

    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:30px">Aucune décision trouvée pour ces critères.</td></tr>';
        return;
    }

    const decLabels = { admis: 'Admis', redoublant: 'Redoublant', transfere: 'Transféré', abandonne: 'Abandonné' };
    let html = '';

    rows.forEach(rd => {
        const canModify  = !rd.dst_end_date;
        const lockReason = rd.dst_end_date
            ? 'Une autre délibération a déjà eu lieu depuis cette décision'
            : '';

        let dstHtml;
        if (rd.dst_class_name) {
            dstHtml = `<span style="color:var(--accent)">→</span> ${escH(rd.dst_class_name)}`
                    + `<div style="font-size:.72rem;color:var(--muted)">${escH(rd.dst_year ?? '')}</div>`
                    + (rd.dst_end_date ? '<span style="font-size:.7rem;color:var(--warning)"><i class="fas fa-lock"></i> Modifié depuis</span>' : '');
        } else if (rd.decision === 'abandonne') {
            dstHtml = '<span style="color:var(--danger)"><i class="fas fa-door-open"></i> Abandonné</span>';
        } else {
            dstHtml = '<span style="color:var(--muted)">—</span>';
        }

        // data-* attributes évitent tout problème d'échappement dans onclick
        const actionsHtml = canModify
            ? `<div style="display:flex;gap:6px;flex-wrap:wrap">
                   <button class="btn btn-danger" style="padding:5px 10px;font-size:.77rem"
                           data-sch-id="${rd.id}"
                           data-student="${escAttr(rd.student_name)}"
                           onclick="confirmAnnuler(this.dataset.schId, this.dataset.student)">
                       <i class="fas fa-times"></i> Annuler
                   </button>
                   <button class="btn btn-secondary" style="padding:5px 10px;font-size:.77rem"
                           data-sch-id="${rd.id}"
                           data-student="${escAttr(rd.student_name)}"
                           onclick="openModifierModal(this.dataset.schId, this.dataset.student)">
                       <i class="fas fa-edit"></i> Modifier
                   </button>
               </div>`
            : `<span class="locked-badge" title="${escAttr(lockReason)}"><i class="fas fa-lock"></i> Verrouillé
                   <span style="font-size:.68rem;display:block;color:var(--muted);margin-top:2px">${escH(lockReason)}</span>
               </span>`;

        html += `
            <tr>
                <td style="font-weight:600">${escH(rd.student_name)}</td>
                <td style="font-family:monospace;font-size:.82rem">${escH(rd.matricule)}</td>
                <td>
                    ${escH(rd.source_class_name)}${rd.source_class_code ? ` <span style="color:var(--accent);font-size:.75rem">(${escH(rd.source_class_code)})</span>` : ''}
                    <div style="font-size:.72rem;color:var(--muted)">${escH(rd.academic_year)}</div>
                </td>
                <td>${dstHtml}</td>
                <td><span class="badge-decision badge-${escH(rd.decision)}">${escH(decLabels[rd.decision] || rd.decision)}</span></td>
                <td style="font-size:.82rem">${escH(rd.decision_date ?? '—')}</td>
                <td style="font-size:.82rem">${escH(rd.admin_name ?? '—')}</td>
                <td>${actionsHtml}</td>
            </tr>`;
    });
    tbody.innerHTML = html;
}

// Échappe le HTML (texte affiché dans le DOM)
function escH(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
// Échappe pour un attribut HTML (title, data-*)
function escAttr(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/* ── Pré-sélection depuis URL (?filiere_id=X&level=Y) ── */
document.addEventListener('DOMContentLoaded', () => {
    const p   = new URLSearchParams(window.location.search);
    const fid = p.get('filiere_id');
    const lvl = p.get('level');
    if (!fid) return;

    const selF = document.getElementById('sel-filiere');
    if (selF) {
        selF.value = fid;
        onFiliereChange();
        if (lvl) {
            const selL = document.getElementById('sel-level');
            if (selL) selL.value = lvl;
        }
        // Scroller jusqu'aux filtres
        selF.closest('.section-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
</script>
