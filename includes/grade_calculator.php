<?php
/**
 * Calcul des notes ESIITECH selon le système LMD/ECTS.
 *
 * Règles appliquées :
 *  1. note_module = EXA×0.6 + CC×0.4  (rattrapage si meilleur)
 *  2. module éliminatoire si note_finale < seuil (8 Licence, 9 Master)
 *  3. moy_UE = SUM(note×crédit) / SUM(crédit)  — toujours calculée
 *  4. UE validée si moy_UE >= 10 ET aucun module éliminatoire
 *  5. moy_générale = SUM(moy_UE×crédit_UE) / SUM(crédit_UE)
 *  6. Semestre validé si crédits_obtenus >= credits_total × pct/100
 */

/**
 * @param mysqli $conn
 * @param string $student_id
 * @param int|string $class_id
 * @param int $evaluation_period_id
 * @return array{
 *   seuil_eliminatoire: float,
 *   ues: array,
 *   moy_generale: float,
 *   credits_total: int,
 *   credits_obtenus: int,
 *   decision: string
 * }
 */
function calculate_student_semester_grades(
    mysqli $conn,
    string $student_id,
    $class_id,
    int $evaluation_period_id
): array {

    // ── 1. Seuil éliminatoire (paramètres + type diplôme) ────────────────
    $threshLicence = 8.0;
    $threshMaster  = 9.0;

    $pr = $conn->prepare(
        "SELECT cle, valeur FROM parametres
         WHERE cle IN ('note_eliminatoire_licence','note_eliminatoire_master')"
    );
    $pr->execute();
    $pMap = array_column($pr->get_result()->fetch_all(MYSQLI_ASSOC), 'valeur', 'cle');
    $pr->close();
    if (isset($pMap['note_eliminatoire_licence'])) $threshLicence = (float)$pMap['note_eliminatoire_licence'];
    if (isset($pMap['note_eliminatoire_master']))  $threshMaster  = (float)$pMap['note_eliminatoire_master'];

    $classInt = (int)$class_id;
    $niveauQ = $conn->prepare(
        "SELECT f.niveau_lmd
         FROM filieres f
         JOIN classes c ON c.filiere_id = f.id
         WHERE c.id = ?"
    );
    $niveauQ->bind_param("i", $classInt);
    $niveauQ->execute();
    $niveauRow = $niveauQ->get_result()->fetch_assoc();
    $niveauQ->close();
    $seuil = ($niveauRow && ($niveauRow['niveau_lmd'] ?? '') === 'master') ? $threshMaster : $threshLicence;

    // ── 2. UEs configurées pour cette classe / période ───────────────────
    // Note : teaching_units.semester stocke l'evaluation_period_id
    $classStr = (string)$class_id;
    $uq = $conn->prepare(
        "SELECT id, code, name, short_name
         FROM teaching_units
         WHERE class_id = ? AND semester = ?
         ORDER BY display_order, id"
    );
    $uq->bind_param("si", $classStr, $evaluation_period_id);
    $uq->execute();
    $units = $uq->get_result()->fetch_all(MYSQLI_ASSOC);
    $uq->close();

    $ueMap = [];
    foreach ($units as $u) {
        $ueMap[(int)$u['id']] = [
            'id'         => (int)$u['id'],
            'code'       => $u['code'],
            'name'       => $u['name'],
            'short_name' => $u['short_name'] ?? '',
            'modules'    => [],
        ];
    }

    // ── 3. Cours de la classe pour cette période ─────────────────────────
    // courses.class_id est un tableau JSON ; courses.semester = evaluation_period_id
    $cq = $conn->prepare(
        "SELECT id, name, coefficient, teaching_unit_id
         FROM courses
         WHERE JSON_CONTAINS(class_id, JSON_QUOTE(?)) AND semester = ?
         ORDER BY display_order, id"
    );
    $cq->bind_param("si", $classStr, $evaluation_period_id);
    $cq->execute();
    $courses = $cq->get_result()->fetch_all(MYSQLI_ASSOC);
    $cq->close();

    if (empty($courses)) {
        return [
            'seuil_eliminatoire' => $seuil,
            'ues'                => [],
            'moy_generale'       => 0.0,
            'credits_total'      => 0,
            'credits_obtenus'    => 0,
            'decision'           => 'non_valide',
        ];
    }

    // ── 4. Notes CC + Examen de l'étudiant ──────────────────────────────
    $gq = $conn->prepare(
        "SELECT g.course_id, LOWER(et.name) AS eval_type, g.grade
         FROM grades g
         JOIN evaluation_types et ON et.id = g.evaluation_type_id
         WHERE g.student_id = ? AND g.evaluation_period_id = ?"
    );
    $gq->bind_param("si", $student_id, $evaluation_period_id);
    $gq->execute();
    $gradeRows = $gq->get_result()->fetch_all(MYSQLI_ASSOC);
    $gq->close();

    $byC = [];
    foreach ($gradeRows as $r) {
        $cid  = (int)$r['course_id'];
        $type = $r['eval_type'];
        if (!isset($byC[$cid])) $byC[$cid] = ['devoirs' => [], 'examen' => null];
        if ($type === 'devoir')  $byC[$cid]['devoirs'][] = (float)$r['grade'];
        elseif ($type === 'examen') $byC[$cid]['examen'] = (float)$r['grade'];
    }

    // ── 5. Rattrapages (table séparée, status='graded') ──────────────────
    $rq = $conn->prepare(
        "SELECT course_id, grade
         FROM rattrapages
         WHERE student_id = ? AND evaluation_period_id = ? AND status = 'graded'"
    );
    $rq->bind_param("si", $student_id, $evaluation_period_id);
    $rq->execute();
    $rattRows = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
    $rq->close();
    $ratt = array_column($rattRows, 'grade', 'course_id');

    // ── 6. Calcul note finale par module + affectation UE ────────────────
    $orphans = [];

    foreach ($courses as $course) {
        $cid    = (int)$course['id'];
        $credit = (float)$course['coefficient'];
        $tuId   = $course['teaching_unit_id'] ? (int)$course['teaching_unit_id'] : null;

        $g    = $byC[$cid] ?? ['devoirs' => [], 'examen' => null];
        $cc   = count($g['devoirs']) > 0
              ? array_sum($g['devoirs']) / count($g['devoirs'])
              : 0.0;
        $exa  = $g['examen'] ?? 0.0;
        $norm = $exa * 0.6 + $cc * 0.4;

        $rattGrade = isset($ratt[$cid]) ? (float)$ratt[$cid] : null;
        $finale    = ($rattGrade !== null && $rattGrade > $norm) ? $rattGrade : $norm;

        $mod = [
            'id'              => $cid,
            'name'            => $course['name'],
            'credit'          => $credit,
            'note_cc'         => round($cc,         2),
            'note_exa'        => round($exa,        2),
            'note_rattrapage' => $rattGrade !== null ? round($rattGrade, 2) : null,
            'note_finale'     => round($finale,     2),
            'eliminatoire'    => $finale < $seuil,
        ];

        if ($tuId !== null && isset($ueMap[$tuId])) {
            $ueMap[$tuId]['modules'][] = $mod;
        } else {
            // Fallback : le cours devient sa propre UE individuelle
            $orphans[] = [
                'id'         => 'c_' . $cid,
                'code'       => '',
                'name'       => $course['name'],
                'short_name' => '',
                'modules'    => [$mod],
            ];
        }
    }

    // ── 7. Calcul moyenne UE, validation, crédits ────────────────────────
    // UEs configurées (avec au moins un module) + UEs orphelines
    $allUes = array_values(array_filter($ueMap, fn($u) => !empty($u['modules'])));
    $allUes = array_merge($allUes, $orphans);

    $genWSum        = 0.0;
    $genTCoef       = 0.0;
    $creditsTotal   = 0;
    $creditsObtenus = 0;

    foreach ($allUes as &$ue) {
        $wSum    = 0.0;
        $tCoef   = 0.0;
        $hasElim = false;

        foreach ($ue['modules'] as $m) {
            $wSum  += $m['note_finale'] * $m['credit'];
            $tCoef += $m['credit'];
            if ($m['eliminatoire']) $hasElim = true;
        }

        $moyUe = $tCoef > 0 ? round($wSum / $tCoef, 2) : 0.0;
        // UE validée : moy >= 10 ET aucun module éliminatoire
        $valide = $moyUe >= 10.0 && !$hasElim;
        $cObt   = $valide ? (int)round($tCoef) : 0;

        $ue['credit_total']     = $tCoef;
        $ue['moy_ue']           = $moyUe;
        $ue['has_eliminatoire'] = $hasElim;
        $ue['valide']           = $valide;
        $ue['credits_obtenus']  = $cObt;

        // La moy_generale inclut TOUTES les UE (validées ou non)
        $genWSum        += $wSum;
        $genTCoef       += $tCoef;
        $creditsTotal   += (int)round($tCoef);
        $creditsObtenus += $cObt;
    }
    unset($ue);

    // ── 8. Moyenne générale + décision semestre ──────────────────────────
    $moyGenerale = $genTCoef > 0 ? round($genWSum / $genTCoef, 2) : 0.0;

    $pctQ = $conn->prepare(
        "SELECT valeur FROM parametres WHERE cle = 'credits_validation_semestre_pct' LIMIT 1"
    );
    $pctQ->execute();
    $pctRow = $pctQ->get_result()->fetch_assoc();
    $pctQ->close();
    $pct           = $pctRow ? (float)$pctRow['valeur'] : 100.0;
    $creditsRequis = (int)round($creditsTotal * $pct / 100.0);
    $decision      = ($creditsTotal > 0 && $creditsObtenus >= $creditsRequis)
                   ? 'valide'
                   : 'non_valide';

    return [
        'seuil_eliminatoire' => $seuil,
        'ues'                => $allUes,
        'moy_generale'       => $moyGenerale,
        'credits_total'      => $creditsTotal,
        'credits_obtenus'    => $creditsObtenus,
        'decision'           => $decision,
    ];
}
