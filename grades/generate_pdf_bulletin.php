<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ob_start();
require_once '../includes/db_connect.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$classId = $_GET['class_id'] ?? null;
$studentId = $_GET['student_id'] ?? null;
$periodId = $_GET['period_id'] ?? null;

if ($classId && $studentId && $periodId) {
    // Étudiant
    $studentQuery = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $studentQuery->bind_param("s", $studentId);
    $studentQuery->execute();
    $studentName = $studentQuery->get_result()->fetch_assoc()['name'];

    // Classe
    $classQuery = $conn->prepare("SELECT name FROM classes WHERE id = ?");
    $classQuery->bind_param("s", $classId);
    $classQuery->execute();
    $className = $classQuery->get_result()->fetch_assoc()['name'];

    // Semestre
    $periodQuery = $conn->prepare("SELECT name FROM evaluation_periods WHERE id = ?");
    $periodQuery->bind_param("i", $periodId);
    $periodQuery->execute();
    $periodName = $periodQuery->get_result()->fetch_assoc()['name'];

    // Cours
    $coursesQuery = $conn->prepare("
        SELECT id, name AS course_name, coefficient AS credits 
        FROM courses 
        WHERE JSON_CONTAINS(class_id, JSON_QUOTE(?))
          AND semester = ?
    ");
    $coursesQuery->bind_param("si", $classId, $periodId);
    $coursesQuery->execute();
    $allCourses = $coursesQuery->get_result()->fetch_all(MYSQLI_ASSOC);

    $grades = [];
    $totalCredits = 0;
    $validCredits = 0;
    $weightedSum = 0;

    // Coefficients des types
    $examCoeff = 0.6;
    $devoirCoeff = 1 - $examCoeff;

    foreach ($allCourses as $course) {
        $courseId = $course['id'];
        $courseName = $course['course_name'];
        $coefficient = $course['credits'];

        $total = 0;
        $totalCoeff = 0;

// DEVOIR - Moyenne de tous les devoirs
$devoirQuery = $conn->prepare("
    SELECT grade 
    FROM grades 
    WHERE student_id = ? 
      AND course_id = ? 
      AND evaluation_period_id = ? 
      AND evaluation_type_id = (
          SELECT id FROM evaluation_types WHERE LOWER(name) = 'devoir' LIMIT 1
      )
");
$devoirQuery->bind_param("sii", $studentId, $courseId, $periodId);
$devoirQuery->execute();
$devoirResult = $devoirQuery->get_result();

$devoirGrades = [];
while ($row = $devoirResult->fetch_assoc()) {
    $devoirGrades[] = $row['grade'];
}
$devoirGrade = count($devoirGrades) > 0 ? array_sum($devoirGrades) / count($devoirGrades) : 0;


        // EXAMEN
        $examQuery = $conn->prepare("
            SELECT grade 
            FROM grades 
            WHERE student_id = ? 
              AND course_id = ? 
              AND evaluation_period_id = ? 
              AND evaluation_type_id = (
                  SELECT id FROM evaluation_types WHERE LOWER(name) = 'examen' LIMIT 1
              )
        ");
        $examQuery->bind_param("sii", $studentId, $courseId, $periodId);
        $examQuery->execute();
        $examResult = $examQuery->get_result();
        $examGrade = $examResult->num_rows > 0 ? $examResult->fetch_assoc()['grade'] : 0;

        // Moyenne pondérée
        $average = ($devoirGrade * $devoirCoeff) + ($examGrade * $examCoeff);

        $totalCredits += $coefficient;
        $weightedSum += $average * $coefficient;
        if ($average >= 10) {
            $validCredits += $coefficient;
        }

        $grades[] = [
            'course_name' => $courseName,
            'credits' => $coefficient,
            'devoir' => round($devoirGrade, 2),
            'examen' => round($examGrade, 2),
            'weighted_average' => round($average, 2)
        ];
    }

    $generalAverage = $totalCredits > 0 ? round($weightedSum / $totalCredits, 2) : 0;

    // Mention
    if ($generalAverage < 10) {
        $mention = "Insuffisant";
    } elseif ($generalAverage < 12) {
        $mention = "Passable";
    } elseif ($generalAverage < 14) {
        $mention = "Assez Bien";
    } elseif ($generalAverage < 16) {
        $mention = "Bien";
    } else {
        $mention = "Très Bien";
    }

    // PDF
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);

    ob_start();
    ?>

    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 14px; }
        .header { text-align: center; margin-bottom: 20px; }
        .logo { font-size: 18px; font-weight: bold; color: #0056b3; }
        .student-info { margin: 10px 20px; font-size: 13px; }
        table { width: 95%; border-collapse: collapse; margin: 10px auto; }
        th, td { border: 1px solid #000; padding: 6px; text-align: center; }
        th { background-color: #f2f2f2; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 12px; }
        .signature { text-align: right; margin-top: 30px; margin-right: 40px; }
    </style>

    <div class="header">
        <p>Ministère de l'Enseignement Supérieur, de la Recherche Scientifique et de l'Innovation Technologique</p>
        <p class="logo">UNIVERSITÉ POLYTECHNIQUE</p>
        <p><strong>RELEVÉ DE NOTES</strong></p>
    </div>

    <div class="student-info">
        <p><strong>Nom et prénom :</strong> <?= htmlspecialchars($studentName) ?></p>
        <p><strong>Niveau et Filière :</strong> <?= htmlspecialchars($className) ?></p>
        <p><strong>Semestre :</strong> <?= htmlspecialchars($periodName) ?></p>
    </div>

    <table>
        <tr>
            <th>Unité d'Enseignement</th>
            <th>Devoir</th>
            <th>Examen</th>
            <th>MOY/20</th>
            <th>Crédits</th>
        </tr>
        <?php foreach ($grades as $grade): ?>
            <tr>
                <td><?= htmlspecialchars($grade['course_name']) ?></td>
                <td><?= number_format($grade['devoir'], 2) ?></td>
                <td><?= number_format($grade['examen'], 2) ?></td>
                <td><?= number_format($grade['weighted_average'], 2) ?></td>
                <td><?= $grade['credits'] ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <table>
        <tr>
            <th>Nombre de Crédits exigés</th>
            <th>Nombre de Crédits validés</th>
            <th>Moyenne Semestrielle</th>
            <th>Mention</th>
        </tr>
        <tr>
            <td><?= $totalCredits ?></td>
            <td><?= $validCredits ?></td>
            <td><?= $generalAverage ?></td>
            <td><?= $mention ?></td>
        </tr>
    </table>

    <div class="signature">
        <p>Fait à Libreville, le <?= date('d/m/Y') ?></p>
        <p><strong>Le Secrétaire Général</strong></p>
    </div>

    <footer class="footer">
        <p><strong>Ce document est généré par l'Université Polytechnique dans le cadre de la gestion des relevés de notes des étudiants inscrits.</strong></p>
    </footer>

    <?php
    $html = ob_get_clean();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    ob_end_clean();
    $dompdf->stream("bulletin_$studentName.pdf", ["Attachment" => false]);

} else {
    echo "Erreur : paramètres manquants.";
}
?>
