<?php
// Helpers partagés entre attestations.php et generate_attestation.php.
// Requiert : $pdo (PDO) déjà instancié.

// ── Barème mentions ────────────────────────────────────────────────────────
function computeMentionAttest(float $avg): string {
    if ($avg < 10) return 'INSUFFISANT';
    if ($avg < 12) return 'PASSABLE';
    if ($avg < 14) return 'ASSEZ BIEN';
    if ($avg < 16) return 'BIEN';
    return 'TRÈS BIEN';
}

// ── Validation annuelle d'un étudiant ─────────────────────────────────────
// Reproduit exactement la logique de generate_pdf_bulletin_new.php :
//   - Moyenne cours = devoir×0.4 + examen×0.6
//   - Rattrapage remplace si strictement supérieur
//   - Note éliminatoire : moyenne cours < 8  → UE non validée
//   - UE validée : moyenne_UE ≥ 10 ET aucune note éliminatoire
//   - Semestre validé : TOUS les crédits validés
//   - Année validée : TOUS les semestres (periodIds) validés
//
// Retourne : ['validated' => bool, 'average' => float, 'mention' => string]
function computeStudentYearValidation(PDO $pdo, $studentId, $classId, array $periodIds): array
{
    if (empty($periodIds)) {
        return ['validated' => false, 'average' => 0.0, 'mention' => 'INSUFFISANT'];
    }

    // IDs des types d'évaluation (récupérés une seule fois)
    $devoirTypeId = (int)($pdo->query("SELECT id FROM evaluation_types WHERE LOWER(name)='devoir' LIMIT 1")->fetchColumn() ?: 0);
    $examTypeId   = (int)($pdo->query("SELECT id FROM evaluation_types WHERE LOWER(name)='examen' LIMIT 1")->fetchColumn() ?: 0);

    $examCoeff   = 0.6;
    $devoirCoeff = 0.4;

    $allValidated     = true;
    $totalWeightedSum = 0.0;
    $totalCredits     = 0.0;

    foreach ($periodIds as $periodId) {
        $periodId = (int)$periodId;

        // UEs du semestre
        $stUnit = $pdo->prepare(
            "SELECT id FROM teaching_units WHERE class_id = ? AND semester = ? ORDER BY display_order"
        );
        $stUnit->execute([$classId, $periodId]);
        $units = $stUnit->fetchAll();

        if (empty($units)) {
            $allValidated = false;
            continue;
        }

        $semTotalReq = 0.0;
        $semTotalVal = 0.0;
        $semWeighted = 0.0;

        foreach ($units as $unit) {
            // Cours de l'UE
            $stCourse = $pdo->prepare(
                "SELECT id, coefficient FROM courses
                 WHERE teaching_unit_id = ?
                   AND JSON_CONTAINS(class_id, JSON_QUOTE(?))
                   AND semester = ?
                 ORDER BY display_order"
            );
            $stCourse->execute([$unit['id'], $classId, $periodId]);
            $courses = $stCourse->fetchAll();

            $unitCredits     = 0.0;
            $unitWeightedSum = 0.0;
            $hasEliminatoire = false;

            foreach ($courses as $course) {
                $cid     = $course['id'];
                $credits = (float)$course['coefficient'];

                // Devoirs (moyenne de toutes les notes de type devoir)
                $stDev = $pdo->prepare(
                    "SELECT grade FROM grades
                     WHERE student_id=? AND course_id=? AND evaluation_period_id=? AND evaluation_type_id=?"
                );
                $stDev->execute([$studentId, $cid, $periodId, $devoirTypeId]);
                $devoirGrades = $stDev->fetchAll(PDO::FETCH_COLUMN);
                $devoirGrade  = count($devoirGrades) > 0
                    ? array_sum($devoirGrades) / count($devoirGrades)
                    : 0.0;

                // Examen (note unique)
                $stEx = $pdo->prepare(
                    "SELECT grade FROM grades
                     WHERE student_id=? AND course_id=? AND evaluation_period_id=? AND evaluation_type_id=?
                     LIMIT 1"
                );
                $stEx->execute([$studentId, $cid, $periodId, $examTypeId]);
                $examGrade = (float)($stEx->fetchColumn() ?: 0);

                $courseAvg = ($devoirGrade * $devoirCoeff) + ($examGrade * $examCoeff);

                // Rattrapage : remplace uniquement si strictement supérieur
                $stRatt = $pdo->prepare(
                    "SELECT grade FROM rattrapages
                     WHERE student_id=? AND course_id=? AND evaluation_period_id=? AND status='graded'
                     LIMIT 1"
                );
                $stRatt->execute([$studentId, $cid, $periodId]);
                $rattGrade = $stRatt->fetchColumn();
                if ($rattGrade !== false && (float)$rattGrade > $courseAvg) {
                    $courseAvg = (float)$rattGrade;
                }

                if ($courseAvg < 8) $hasEliminatoire = true;

                $unitCredits     += $credits;
                $unitWeightedSum += $courseAvg * $credits;
            }

            $unitMoyenne   = $unitCredits > 0 ? $unitWeightedSum / $unitCredits : 0.0;
            $unitValidated = $unitMoyenne >= 10 && !$hasEliminatoire;

            $semTotalReq += $unitCredits;
            if ($unitValidated) $semTotalVal += $unitCredits;
            $semWeighted += $unitWeightedSum;
        }

        $semValidated = ($semTotalReq > 0) && (abs($semTotalVal - $semTotalReq) < 0.001);
        if (!$semValidated) $allValidated = false;

        $totalWeightedSum += $semWeighted;
        $totalCredits     += $semTotalReq;
    }

    if ($totalCredits <= 0) $allValidated = false;

    $average = $totalCredits > 0 ? round($totalWeightedSum / $totalCredits, 2) : 0.0;

    return [
        'validated' => $allValidated,
        'average'   => $average,
        'mention'   => computeMentionAttest($average),
    ];
}

// ── Validation semestrielle d'un étudiant ─────────────────────────────────
// Wrapper : délègue à computeStudentYearValidation avec un seul semestre.
function computeStudentSemesterValidation(PDO $pdo, $studentId, $classId, int $periodId): array
{
    return computeStudentYearValidation($pdo, $studentId, $classId, [$periodId]);
}

// ── Génération du numéro d'enregistrement ─────────────────────────────────
// Appeler à l'intérieur d'une transaction PDO.
// Format : 0001/2025  (4 chiffres / dernière année de annee_academique)
function generateNumeroEnregistrement(PDO $pdo, string $anneeAcademique): string
{
    $parts = explode('-', $anneeAcademique);
    $year  = trim(end($parts)); // "2025"

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attestations WHERE annee_academique = ?");
    $stmt->execute([$anneeAcademique]);
    $count = (int)$stmt->fetchColumn();

    return sprintf('%04d/%s', $count + 1, $year);
}

// ── QR code base64 via API externe ────────────────────────────────────────
// Retourne une data URI PNG, ou '' si curl indisponible / hors-ligne.
function generateQRBase64(string $data): string
{
    if (!function_exists('curl_init')) return '';
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&ecc=M&data=' . urlencode($data);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $img  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$img || $code !== 200) return '';
    return 'data:image/png;base64,' . base64_encode($img);
}
