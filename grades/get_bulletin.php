<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/grade_calculator.php';

if (!isset($_GET['class_id']) || !isset($_GET['student_id'])) {
    exit('Paramètres manquants');
}

$classId   = (int)$_GET['class_id'];
$studentId = $_GET['student_id'];

// Récupérer la période : GET ou sinon la plus récente pour la classe
if (!empty($_GET['period_id'])) {
    $periodId = (int)$_GET['period_id'];
} else {
    $pq = $conn->prepare(
        "SELECT ep.id FROM evaluation_periods ep
         JOIN student_class_history sch ON sch.academic_year = ep.school_year
         WHERE sch.student_id = ? AND sch.class_id = ?
         ORDER BY ep.start_date DESC LIMIT 1"
    );
    $pq->bind_param("si", $studentId, $classId);
    $pq->execute();
    $pRow     = $pq->get_result()->fetch_assoc();
    $pq->close();
    $periodId = $pRow ? (int)$pRow['id'] : 0;
}

$student = $conn->query(
    "SELECT * FROM users WHERE id = '" . $conn->real_escape_string($studentId) . "'"
)->fetch_assoc();

$result = $periodId
    ? calculate_student_semester_grades($conn, $studentId, $classId, $periodId)
    : null;
?>

<div class="bulletin">
    <div class="bulletin-header">
        <h2>Bulletin de Notes — <?php echo htmlspecialchars($student['name'] ?? $studentId); ?></h2>
        <p>Classe ID : <?php echo $classId; ?></p>
    </div>

    <?php if (!$result || empty($result['ues'])): ?>
        <p>Aucune donnée de notes disponible pour cette période.</p>
    <?php else: ?>

    <table class="bulletin-table">
        <thead>
            <tr>
                <th>UE</th>
                <th>Module</th>
                <th>Crédit</th>
                <th>CC</th>
                <th>Examen</th>
                <th>Note finale</th>
                <th>Moy. UE</th>
                <th>Crédits UE</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($result['ues'] as $ue): ?>
                <?php $nc = count($ue['modules']); $first = true; ?>
                <?php foreach ($ue['modules'] as $mod): ?>
                <tr <?php if ($mod['eliminatoire']) echo 'style="color:#c0392b"'; ?>>
                    <?php if ($first): ?>
                    <td rowspan="<?php echo $nc; ?>" style="font-weight:bold;vertical-align:middle">
                        <?php echo htmlspecialchars($ue['code'] ?: $ue['name']); ?>
                    </td>
                    <?php $first = false; endif; ?>
                    <td><?php echo htmlspecialchars($mod['name']); ?></td>
                    <td><?php echo $mod['credit']; ?></td>
                    <td><?php echo number_format($mod['note_cc'],  2); ?></td>
                    <td><?php echo number_format($mod['note_exa'], 2); ?></td>
                    <td>
                        <?php echo number_format($mod['note_finale'], 2); ?>
                        <?php if ($mod['note_rattrapage'] !== null): ?>
                            <small>(ratt. <?php echo number_format($mod['note_rattrapage'], 2); ?>)</small>
                        <?php endif; ?>
                        <?php if ($mod['eliminatoire']): ?> <em>Élim.</em><?php endif; ?>
                    </td>
                    <?php if ($first === false && count($ue['modules']) > 1 ? false : true): // first module ?>
                    <?php endif; ?>
                    <?php // UE cells on first module row ?>
                    <?php if (array_search($mod, $ue['modules']) === 0): ?>
                    <td rowspan="<?php echo $nc; ?>" style="font-weight:bold;vertical-align:middle">
                        <?php echo number_format($ue['moy_ue'], 2); ?>
                        <?php if ($ue['has_eliminatoire']): ?><br><small style="color:#c0392b">Note élim.</small><?php endif; ?>
                    </td>
                    <td rowspan="<?php echo $nc; ?>" style="vertical-align:middle">
                        <?php echo $ue['credits_obtenus']; ?>/<?php echo (int)round($ue['credit_total']); ?>
                    </td>
                    <td rowspan="<?php echo $nc; ?>" style="vertical-align:middle;font-weight:bold;color:<?php echo $ue['valide'] ? '#1a7a1a' : '#c0392b'; ?>">
                        <?php echo $ue['valide'] ? 'Validé' : 'Non validé'; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="text-align:right"><strong>Moyenne Générale :</strong></td>
                <td colspan="2"><strong><?php echo number_format($result['moy_generale'], 2); ?>/20</strong></td>
                <td><strong><?php echo $result['credits_obtenus']; ?>/<?php echo $result['credits_total']; ?> crédits</strong></td>
                <td style="font-weight:bold;color:<?php echo $result['decision'] === 'valide' ? '#1a7a1a' : '#c0392b'; ?>">
                    <?php echo $result['decision'] === 'valide' ? 'Semestre validé' : 'Non validé'; ?>
                </td>
            </tr>
        </tfoot>
    </table>

    <?php endif; ?>
</div>
