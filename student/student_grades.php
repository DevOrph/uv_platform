<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/semester_helper.php';
require_once '../includes/grade_calculator.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../pages/login.html');
    exit();
}

$student_id = (string)$_SESSION['user_id'];

// ── Infos étudiant ──────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, email, class_id FROM users WHERE id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

$current_class_id = $student_info['class_id'] ?? null;

$class_name = "Non assigné";
if ($current_class_id) {
    $stmt = $conn->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt->bind_param("i", $current_class_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) $class_name = $row['name'];
}

// ── Sélection d'année ────────────────────────────────────────────────────────
$current_period = get_current_period($conn);
$current_year   = ANNEE_ACADEMIQUE_COURANTE;

// Années où l'étudiant a réellement des notes (pas toutes les années système)
$ystmt = $conn->prepare(
    "SELECT DISTINCT ep.school_year
     FROM grades g
     JOIN evaluation_periods ep ON ep.id = g.evaluation_period_id
     WHERE g.student_id = ?
     ORDER BY ep.school_year DESC"
);
$ystmt->bind_param("s", $student_id);
$ystmt->execute();
$available_years = array_column($ystmt->get_result()->fetch_all(MYSQLI_ASSOC), 'school_year');
$ystmt->close();
if (!in_array($current_year, $available_years)) {
    array_unshift($available_years, $current_year);
}

$selected_year = (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year']))
    ? $_GET['year'] : $current_year;

// ── Périodes S1 et S2 ────────────────────────────────────────────────────────
$pstmt = $conn->prepare(
    "SELECT * FROM evaluation_periods WHERE school_year = ? ORDER BY id ASC"
);
$pstmt->bind_param("s", $selected_year);
$pstmt->execute();
$periods = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pstmt->close();

$period_s1 = $periods[0] ?? null;
$period_s2 = $periods[1] ?? null;

// ── Classe effective pour l'année sélectionnée ──────────────────────────────
// Pour une année passée, chercher la classe d'alors dans student_class_history.
$effective_class_id   = $current_class_id;
$effective_class_name = $class_name;
$hist_class_different = false;

if ($selected_year !== $current_year && $current_class_id) {
    $hstmt = $conn->prepare(
        "SELECT class_id FROM student_class_history
         WHERE student_id = ? AND academic_year = ?
         ORDER BY id DESC LIMIT 1"
    );
    $hstmt->bind_param("ss", $student_id, $selected_year);
    $hstmt->execute();
    $hist_row = $hstmt->get_result()->fetch_assoc();
    $hstmt->close();

    if ($hist_row && (int)$hist_row['class_id'] !== (int)$current_class_id) {
        $hist_cid = (int)$hist_row['class_id'];
        $nstmt = $conn->prepare("SELECT name FROM classes WHERE id = ?");
        $nstmt->bind_param("i", $hist_cid);
        $nstmt->execute();
        $nrow = $nstmt->get_result()->fetch_assoc();
        $nstmt->close();

        $effective_class_id   = $hist_cid;
        $effective_class_name = $nrow ? $nrow['name'] : $class_name;
        $hist_class_different = true;
    }
}

// ── Calcul des résultats via grade_calculator ────────────────────────────────
$result_s1 = null;
$result_s2 = null;

if ($effective_class_id && $period_s1) {
    $result_s1 = calculate_student_semester_grades(
        $conn, $student_id, $effective_class_id, (int)$period_s1['id']
    );
}
if ($effective_class_id && $period_s2) {
    $result_s2 = calculate_student_semester_grades(
        $conn, $student_id, $effective_class_id, (int)$period_s2['id']
    );
}

// ── Onglet actif par défaut (basé sur le semestre courant) ───────────────────
$active_tab = ($current_period['semester'] === 2 && $selected_year === $current_year)
    ? 's2' : 's1';

// ── Fonction d'affichage d'un panneau semestre ───────────────────────────────
function render_semester_panel(?array $result, string $label, ?array $period, $class_id): void
{
    if (!$class_id) {
        echo '
        <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <h3>Pas de classe assignée</h3>
            <p>Vous n\'êtes pas encore assigné(e) à une classe.</p>
        </div>';
        return;
    }
    if (!$period) {
        echo '
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h3>Période non configurée</h3>
            <p>La période de ' . htmlspecialchars($label) . ' n\'a pas encore été configurée pour cette année.</p>
        </div>';
        return;
    }
    if (!$result || empty($result['ues'])) {
        echo '
        <div class="empty-state">
            <i class="fas fa-clipboard"></i>
            <h3>Aucune note disponible pour ce semestre</h3>
            <p>Les notes apparaîtront ici dès qu\'elles auront été saisies par vos enseignants.</p>
        </div>';
        return;
    }

    $moy      = $result['moy_generale'];
    $cred_obt = $result['credits_obtenus'];
    $cred_tot = $result['credits_total'];
    $decision = $result['decision'];
    $seuil    = $result['seuil_eliminatoire'];

    $moy_class = '';
    if ($moy >= 16) $moy_class = 'note-excellent';
    elseif ($moy >= 14) $moy_class = 'note-good';
    elseif ($moy >= 10) $moy_class = 'note-average';
    else $moy_class = 'note-poor';
    ?>

    <!-- Récapitulatif semestre -->
    <div class="summary-grid">
        <div class="summary-card">
            <i class="fas fa-chart-bar"></i>
            <div class="summary-value <?php echo $moy_class; ?>"><?php echo number_format($moy, 2); ?>/20</div>
            <div class="summary-label">Moyenne générale</div>
        </div>
        <div class="summary-card">
            <i class="fas fa-graduation-cap"></i>
            <div class="summary-value"><?php echo $cred_obt; ?>/<?php echo $cred_tot; ?></div>
            <div class="summary-label">Crédits obtenus</div>
        </div>
        <div class="summary-card">
            <i class="fas fa-clipboard-check"></i>
            <div style="margin: 10px 0;">
                <?php if ($decision === 'valide'): ?>
                    <span class="decision-badge decision-valide">
                        <i class="fas fa-check-circle"></i> Validé
                    </span>
                <?php else: ?>
                    <span class="decision-badge decision-non-valide">
                        <i class="fas fa-times-circle"></i> Non validé
                    </span>
                <?php endif; ?>
            </div>
            <div class="summary-label">Décision</div>
        </div>
    </div>

    <!-- Tableau des UE et modules -->
    <div class="ues-container">
        <?php foreach ($result['ues'] as $ue): ?>
        <div class="ue-block">
            <div class="ue-header">
                <div class="ue-title">
                    <i class="fas fa-layer-group"></i>
                    <?php if (!empty($ue['code'])): ?>
                        <span><?php echo htmlspecialchars($ue['code']); ?></span>
                        <span style="color:var(--text-light);font-weight:400;opacity:.5;">–</span>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($ue['name']); ?>
                    <span class="ue-credits"><?php echo (int)round($ue['credit_total']); ?> cr.</span>
                </div>
                <div class="ue-meta">
                    <?php
                        $um = $ue['moy_ue'];
                        $um_cls = '';
                        if ($um >= 16) $um_cls = 'note-excellent';
                        elseif ($um >= 14) $um_cls = 'note-good';
                        elseif ($um >= 10) $um_cls = 'note-average';
                        else $um_cls = 'note-poor';
                    ?>
                    <span class="ue-moy <?php echo $um_cls; ?>"><?php echo number_format($um, 2); ?>/20</span>
                    <?php if ($ue['valide']): ?>
                        <span class="badge badge-success"><i class="fas fa-check"></i> Validée</span>
                    <?php else: ?>
                        <span class="badge badge-danger"><i class="fas fa-times"></i> Non validée</span>
                    <?php endif; ?>
                    <?php if ($ue['has_eliminatoire']): ?>
                        <span class="badge badge-elim"><i class="fas fa-exclamation-triangle"></i> Élim.</span>
                    <?php endif; ?>
                </div>
            </div>
            <table class="modules-table">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>CC</th>
                        <th>Examen</th>
                        <th>Rattrapage</th>
                        <th>Note finale</th>
                        <th>Crédits</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ue['modules'] as $mod):
                        $rat        = $mod['note_rattrapage'];
                        $fin        = $mod['note_finale'];
                        $rat_retenu = $rat !== null && abs($fin - $rat) < 0.001;
                        $fin_cls    = '';
                        if ($fin >= 16) $fin_cls = 'note-excellent';
                        elseif ($fin >= 14) $fin_cls = 'note-good';
                        elseif ($fin >= 10) $fin_cls = 'note-average';
                        else $fin_cls = 'note-poor';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($mod['name']); ?></strong>
                            <?php if ($mod['eliminatoire']): ?>
                                <span class="badge badge-elim" style="margin-left:7px;font-size:11px;">
                                    <i class="fas fa-exclamation-triangle"></i> Élim.
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="note-value"><?php echo number_format($mod['note_cc'], 2); ?></span>
                        </td>
                        <td>
                            <span class="note-value"><?php echo number_format($mod['note_exa'], 2); ?></span>
                        </td>
                        <td>
                            <?php if ($rat !== null): ?>
                                <span class="note-rattrapage-val"><?php echo number_format($rat, 2); ?></span>
                            <?php else: ?>
                                <span class="no-note">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="note-finale-display <?php echo $fin_cls; ?>">
                                <?php echo number_format($fin, 2); ?>/20<?php if ($rat_retenu): ?><span class="star-label" title="Note de rattrapage retenue">*</span><?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <span class="credit-val"><?php echo (int)round($mod['credit']); ?> cr.</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($result['ues'])): ?>
    <p class="footnote">
        * Note de rattrapage retenue (meilleure que la note initiale).
        Seuil éliminatoire : <?php echo number_format($seuil, 1); ?>/20.
    </p>
    <?php endif; ?>

    <?php
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <title>Mes Notes - UV Platform</title>
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #4CAF50;
            --warning-color: #ff9800;
            --error-color: #f44336;
            --info-color: #2196F3;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Google Sans', Arial, sans-serif;
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            color: var(--text-light);
            min-height: 100vh;
        }

        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

        /* ── En-tête étudiant ── */
        .student-header {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.3);
        }
        .student-header h1 {
            margin: 0 0 10px 0;
            font-size: 30px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .student-info {
            display: flex;
            gap: 28px;
            flex-wrap: wrap;
            margin-top: 14px;
            font-size: 14px;
            opacity: 0.95;
        }
        .student-info-item { display: flex; align-items: center; gap: 8px; }

        /* ── Sélecteur d'année ── */
        .year-selector {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 18px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .year-selector label {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.65);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .year-selector select {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            padding: 6px 12px;
            border-radius: 7px;
            font-size: 14px;
            cursor: pointer;
            outline: none;
        }
        .year-selector select option { background: var(--secondary-bg); }
        .year-back-link {
            font-size: 13px;
            color: var(--accent-color);
            text-decoration: none;
        }
        .year-back-link:hover { text-decoration: underline; }

        /* ── Onglets ── */
        .semester-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 24px;
            border-bottom: 2px solid var(--border-color);
        }
        .tab-btn {
            padding: 13px 30px;
            background: transparent;
            color: rgba(255, 255, 255, 0.55);
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: -2px;
        }
        .tab-btn:hover { color: var(--text-light); background: rgba(255, 255, 255, 0.04); }
        .tab-btn.active { color: var(--accent-color); border-bottom-color: var(--accent-color); }

        .semester-panel { display: none; }
        .semester-panel.active { display: block; }

        /* ── Récapitulatif ── */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 26px;
        }
        .summary-card {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 22px;
            text-align: center;
        }
        .summary-card > i { font-size: 26px; margin-bottom: 11px; color: var(--accent-color); display: block; }
        .summary-value { font-size: 26px; font-weight: 700; margin-bottom: 4px; }
        .summary-label { font-size: 13px; opacity: 0.65; }

        /* ── Décision ── */
        .decision-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 16px;
            border-radius: 24px;
            font-weight: 700;
            font-size: 14px;
        }
        .decision-valide {
            background: rgba(76, 175, 80, 0.2);
            color: #69f07a;
            border: 1px solid rgba(76, 175, 80, 0.35);
        }
        .decision-non-valide {
            background: rgba(244, 67, 54, 0.18);
            color: #ff6b6b;
            border: 1px solid rgba(244, 67, 54, 0.3);
        }

        /* ── Blocs UE ── */
        .ues-container { display: flex; flex-direction: column; gap: 20px; }
        .ue-block {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            overflow: hidden;
        }
        .ue-header {
            background: rgba(3, 155, 229, 0.12);
            border-bottom: 1px solid var(--border-color);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .ue-title {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--accent-color);
        }
        .ue-credits {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.45);
            font-weight: 400;
        }
        .ue-meta { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
        .ue-moy { font-size: 17px; font-weight: 700; }

        /* ── Badges ── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 13px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: rgba(76, 175, 80, 0.22); color: #69f07a; border: 1px solid rgba(76, 175, 80, 0.3); }
        .badge-danger  { background: rgba(244, 67, 54, 0.18); color: #ff6b6b; border: 1px solid rgba(244, 67, 54, 0.28); }
        .badge-elim    { background: rgba(244, 67, 54, 0.32); color: #ff4444; border: 1px solid rgba(244, 67, 54, 0.48); font-weight: 700; }

        /* ── Tableau modules ── */
        .modules-table { width: 100%; border-collapse: collapse; }
        .modules-table th {
            background: rgba(255, 255, 255, 0.06);
            padding: 11px 14px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.65);
            border-bottom: 1px solid var(--border-color);
        }
        .modules-table th:not(:first-child) { text-align: center; }
        .modules-table td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            font-size: 14px;
            vertical-align: middle;
        }
        .modules-table td:not(:first-child) { text-align: center; }
        .modules-table tr:last-child td { border-bottom: none; }
        .modules-table tr:hover { background: rgba(255, 255, 255, 0.03); }

        /* ── Notes ── */
        .note-value { font-weight: 600; }
        .note-excellent { color: #69f07a; }
        .note-good      { color: #64b5f6; }
        .note-average   { color: #ffb74d; }
        .note-poor      { color: #ff6b6b; }
        .note-rattrapage-val { color: #69f07a; font-weight: 700; }
        .note-finale-display { font-weight: 700; font-size: 15px; }
        .star-label {
            color: #4fc3f7;
            font-size: 11px;
            vertical-align: super;
            margin-left: 2px;
        }
        .no-note { color: rgba(255, 255, 255, 0.3); }
        .credit-val { color: rgba(255, 255, 255, 0.55); font-size: 13px; }

        /* ── État vide ── */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: rgba(255, 255, 255, 0.45);
        }
        .empty-state i { font-size: 54px; margin-bottom: 16px; opacity: 0.4; display: block; }
        .empty-state h3 { font-size: 20px; margin-bottom: 8px; font-weight: 600; }
        .empty-state p { font-size: 14px; }

        /* ── Notice classe historique ── */
        .hist-class-notice {
            background: rgba(3, 155, 229, 0.09);
            border: 1px solid rgba(3, 155, 229, 0.22);
            border-radius: 9px;
            padding: 10px 16px;
            margin-bottom: 18px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.78);
            display: flex;
            align-items: center;
            gap: 9px;
        }
        .hist-class-notice i { color: var(--accent-color); flex-shrink: 0; }

        /* ── Note de bas de page ── */
        .footnote {
            margin-top: 14px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.35);
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .student-header { padding: 20px; }
            .student-header h1 { font-size: 22px; }
            .student-info { flex-direction: column; gap: 8px; }
            .tab-btn { padding: 11px 16px; font-size: 14px; }
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            .modules-table th, .modules-table td { padding: 8px 10px; font-size: 13px; }
            .ue-header { flex-direction: column; align-items: flex-start; }
            /* Masquer la colonne crédits sur mobile */
            .modules-table th:last-child,
            .modules-table td:last-child { display: none; }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">

        <!-- En-tête étudiant -->
        <div class="student-header">
            <h1><i class="fas fa-user-graduate"></i> Mes Notes</h1>
            <div class="student-info">
                <div class="student-info-item">
                    <i class="fas fa-user"></i>
                    <strong><?php echo htmlspecialchars($student_info['name']); ?></strong>
                </div>
                <div class="student-info-item">
                    <i class="fas fa-envelope"></i>
                    <?php echo htmlspecialchars($student_info['email']); ?>
                </div>
                <div class="student-info-item">
                    <i class="fas fa-users"></i>
                    Classe : <strong><?php echo htmlspecialchars($class_name); ?></strong>
                </div>
                <div class="student-info-item">
                    <i class="fas fa-calendar-alt"></i>
                    <strong><?php echo htmlspecialchars($selected_year); ?></strong>
                </div>
            </div>
        </div>

        <!-- Sélecteur d'année académique -->
        <div class="year-selector">
            <label><i class="fas fa-calendar-alt"></i> Année académique :</label>
            <form method="GET" style="display:inline-flex;align-items:center;gap:8px;">
                <select name="year" onchange="this.form.submit()">
                    <?php foreach ($available_years as $yr): ?>
                        <option value="<?php echo htmlspecialchars($yr); ?>"
                            <?php echo $yr === $selected_year ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($yr); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if ($selected_year !== $current_year): ?>
                <a href="student_grades.php" class="year-back-link">
                    <i class="fas fa-arrow-left"></i> Année courante
                </a>
            <?php endif; ?>
        </div>

        <!-- Notice classe historique (années précédentes) -->
        <?php if ($hist_class_different): ?>
        <div class="hist-class-notice">
            <i class="fas fa-info-circle"></i>
            Notes de l'année <?php echo htmlspecialchars($selected_year); ?> — Classe :
            <strong><?php echo htmlspecialchars($effective_class_name); ?></strong>
        </div>
        <?php endif; ?>

        <!-- Onglets Semestre 1 / Semestre 2 -->
        <div class="semester-tabs">
            <button class="tab-btn <?php echo $active_tab === 's1' ? 'active' : ''; ?>"
                    onclick="switchTab('s1')">
                <i class="fas fa-calendar"></i>
                Semestre 1
                <?php if ($result_s1 && !empty($result_s1['ues'])): ?>
                    <?php if ($result_s1['decision'] === 'valide'): ?>
                        <span class="badge badge-success" style="font-size:11px;padding:2px 8px;">Validé</span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size:11px;padding:2px 8px;">Non validé</span>
                    <?php endif; ?>
                <?php endif; ?>
            </button>
            <button class="tab-btn <?php echo $active_tab === 's2' ? 'active' : ''; ?>"
                    onclick="switchTab('s2')">
                <i class="fas fa-calendar"></i>
                Semestre 2
                <?php if ($result_s2 && !empty($result_s2['ues'])): ?>
                    <?php if ($result_s2['decision'] === 'valide'): ?>
                        <span class="badge badge-success" style="font-size:11px;padding:2px 8px;">Validé</span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size:11px;padding:2px 8px;">Non validé</span>
                    <?php endif; ?>
                <?php endif; ?>
            </button>
        </div>

        <!-- Panneau Semestre 1 -->
        <div id="panel-s1" class="semester-panel <?php echo $active_tab === 's1' ? 'active' : ''; ?>">
            <?php render_semester_panel($result_s1, 'Semestre 1', $period_s1, $effective_class_id); ?>
        </div>

        <!-- Panneau Semestre 2 -->
        <div id="panel-s2" class="semester-panel <?php echo $active_tab === 's2' ? 'active' : ''; ?>">
            <?php render_semester_panel($result_s2, 'Semestre 2', $period_s2, $effective_class_id); ?>
        </div>

    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
    function switchTab(tab) {
        document.querySelectorAll('.semester-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('panel-' + tab).classList.add('active');
        const idx = tab === 's1' ? 0 : 1;
        document.querySelectorAll('.tab-btn')[idx].classList.add('active');
    }
    </script>
</body>
</html>
