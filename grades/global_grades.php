<?php


session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}

$selectedClass = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;
$selectedPeriod = isset($_GET['period_id']) ? intval($_GET['period_id']) : null;

// Récupération des classes et périodes
$classes = $conn->query("SELECT id, name FROM classes ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$periods = $conn->query("SELECT id, name, school_year FROM evaluation_periods ORDER BY school_year DESC, start_date ASC")->fetch_all(MYSQLI_ASSOC);

// Initialisation des statistiques
$global_stats = [];
$course_stats = [];
$distribution = [];

if ($selectedClass && $selectedPeriod) {
    // Récupération des statistiques globales
    $stats_query = "
    SELECT 
        COALESCE(AVG(g.grade), 0) AS average,
        COALESCE(MIN(g.grade), 0) AS min_grade,
        COALESCE(MAX(g.grade), 0) AS max_grade,
        COUNT(DISTINCT g.student_id) AS student_count,
        COUNT(*) AS total_grades,
        COALESCE(STDDEV(g.grade), 0) AS std_dev
    FROM grades g
    JOIN courses c ON g.course_id = c.id
    WHERE JSON_CONTAINS(c.class_id, JSON_QUOTE(?))
      AND g.evaluation_period_id = ?;
";

$stmt = $conn->prepare($stats_query);
$stmt->bind_param("si", $selectedClass, $selectedPeriod);
$stmt->execute();
$global_stats = $stmt->get_result()->fetch_assoc();




    // Statistiques par matière
    $course_stats_query = "
    SELECT 
        c.name AS course_name,
        COALESCE(AVG(g.grade), 0) AS average,
        COALESCE(MIN(g.grade), 0) AS min_grade,
        COALESCE(MAX(g.grade), 0) AS max_grade,
        COUNT(*) AS total_grades,
        COALESCE(STDDEV(g.grade), 0) AS std_dev
    FROM grades g
    JOIN courses c ON g.course_id = c.id
    WHERE JSON_CONTAINS(c.class_id, JSON_QUOTE(?))
      AND g.evaluation_period_id = ?
    GROUP BY c.id
    ORDER BY c.name;
";

$stmt = $conn->prepare($course_stats_query);
$stmt->bind_param("si", $selectedClass, $selectedPeriod);
$stmt->execute();
$course_stats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);




    // Distribution des notes
    $distribution_query = "
    SELECT 
        CASE 
            WHEN grade >= 16 THEN '16-20'
            WHEN grade >= 14 THEN '14-16'
            WHEN grade >= 12 THEN '12-14'
            WHEN grade >= 10 THEN '10-12'
            WHEN grade >= 8 THEN '8-10'
            ELSE '0-8'
        END as `range`,
        COUNT(*) as count
    FROM grades g
    JOIN courses c ON g.course_id = c.id
    WHERE JSON_CONTAINS(c.class_id, JSON_QUOTE(?))
      AND g.evaluation_period_id = ?
    GROUP BY `range`
    ORDER BY FIELD(`range`, '16-20', '14-16', '12-14', '10-12', '8-10', '0-8');
";

$stmt = $conn->prepare($distribution_query);
$stmt->bind_param("si", $selectedClass, $selectedPeriod);
$stmt->execute();
$distribution = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


}


?>


<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vue Globale des Notes - UV Platform</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: #051e34;
            color: #ffffff;
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            flex: 1;
        }

        .filters-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            gap: 20px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.7);
        }

        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-card .value {
            font-size: 24px;
            font-weight: bold;
            color: #039be5;
        }

        .stat-card .label {
            color: rgba(255, 255, 255, 0.7);
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            height: 400px;
        }

        .course-stats {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        th {
            background: rgba(255, 255, 255, 0.1);
        } 
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
        }

        body {
            margin: 0;
            font-family: 'Google Sans', Arial, sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid var(--border-color);
            position: relative;
        }

        header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(to right, #039be5, #4CAF50, #039be5);
            animation: shimmer 2s infinite linear;
        }
    body {
        background-color: #051e34;  /* Bleu Firebase foncé */
        color: #ffffff;
        font-family: 'Google Sans', Arial, sans-serif;
        margin: 0;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    .dashboard-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }

    .page-header {
        margin-bottom: 30px;
        padding: 20px;
        background: #0c2d48;  /* Bleu Firebase plus clair */
        border-radius: 10px;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .page-header i {
        font-size: 24px;
        color: #039be5;  /* Bleu accent */
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: rgba(255, 255, 255, 0.1);
        padding: 20px;
        border-radius: 10px;
        text-align: center;
        border: 1px solid rgba(255, 255, 255, 0.1);
        transition: transform 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-5px);
    }

    .stat-card i {
        font-size: 32px;
        color: #039be5;
        margin-bottom: 10px;
    }

    .stat-card h3 {
        margin: 10px 0;
        color: #ffffff;
        font-size: 16px;
    }

    .stat-card p {
        font-size: 24px;
        font-weight: bold;
        margin: 0;
        color: #039be5;
    }

    .filters-card {
        background: rgba(255, 255, 255, 0.1);
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 30px;
        display: flex;
        gap: 20px;
        align-items: flex-end;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .filter-group {
        flex: 1;
    }

    .filter-group label {
        display: block;
        margin-bottom: 8px;
        color: #ffffff;
    }

    .filter-group select {
        width: 100%;
        padding: 10px;
        border-radius: 5px;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #ffffff;
    }

    .filter-group select option {
        background: #0c2d48;
        color: #ffffff;
    }

    .grade-table {
        background: rgba(255, 255, 255, 0.1);
        padding: 20px;
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th {
        background: #0c2d48;
        padding: 12px;
        text-align: left;
        color: #ffffff;
    }

    td {
        padding: 12px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        color: #ffffff;
    }

    .grade-value {
        font-weight: bold;
        padding: 4px 8px;
        border-radius: 4px;
        display: inline-block;
        min-width: 60px;
        text-align: center;
    }

    .grade-good {
        background: rgba(46, 204, 113, 0.2);
        color: #2ecc71;
    }

    .grade-average {
        background: rgba(241, 196, 15, 0.2);
        color: #f1c40f;
    }

    .grade-poor {
        background: rgba(231, 76, 60, 0.2);
        color: #e74c3c;
    }

    .btn-export {
        background: #039be5;
        color: #ffffff;
        border: none;
        padding: 10px 20px;
        border-radius: 5px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: background 0.3s;
    }

    .btn-export:hover {
        background: #0288d1;
    }

    .empty-state {
        text-align: center;
        padding: 40px;
        color: #ffffff;
    }

    .empty-state i {
        font-size: 48px;
        color: #039be5;
        margin-bottom: 20px;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .animated {
        animation: fadeIn 0.5s ease-out forwards;
    }
    </style>
</head>

<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
    <div class="page-header">
            <i class="fas fa-chart-bar"></i>
            <h1>Vue Globale des Notes</h1>
        </div>
        <div class="filters-card">
            <div class="filter-group">
                <label><i class="fas fa-graduation-cap"></i> Classe</label>
                <select id="classSelect" onchange="updateStats(this.value, document.getElementById('periodSelect').value)">
                    <option value="">Sélectionner une classe</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" 
                                <?php echo $selectedClass == $class['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> Période</label>
                <select id="periodSelect" onchange="updateStats(document.getElementById('classSelect').value, this.value)">
                    <option value="">Sélectionner une période</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?php echo $period['id']; ?>"
                                <?php echo $selectedPeriod == $period['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($period['name']); ?> — <?php echo htmlspecialchars($period['school_year'] ?? ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button onclick="exportData()" class="btn-export">
                <i class="fas fa-download"></i>
                Exporter les notes
            </button>
        </div>

        <?php if (!empty($global_stats) && $global_stats['total_grades'] > 0): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="value"><?php echo number_format($global_stats['average'], 2); ?>/20</div>
                    <div class="label">Moyenne Générale</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?php echo number_format($global_stats['min_grade'], 2); ?>/20</div>
                    <div class="label">Note Minimale</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?php echo number_format($global_stats['max_grade'], 2); ?>/20</div>
                    <div class="label">Note Maximale</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?php echo $global_stats['student_count']; ?></div>
                    <div class="label">Étudiants Notés</div>
                </div>
            </div>

            <div id="notesTableContainer" style="display: none;">
            <h3>Notes de l'étudiant</h3>
            <table>
                <thead>
                    <tr>
                        <th>Matière</th>
                        <th>Type d'Évaluation</th>
                        <th>Note</th>
                        <th>Commentaire</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody id="notesTableBody"></tbody>
            </table>
        </div>

        <div class="charts-grid">
            <div class="chart-container">
                <canvas id="distributionChart"></canvas>
            </div>
            <div class="chart-container">
                <canvas id="courseAveragesChart"></canvas>
            </div>
        </div>

        <div class="course-stats">
            <h3>Statistiques par Matière</h3>
            <table>
                <thead>
                    <tr>
                        <th>Matière</th>
                        <th>Moyenne</th>
                        <th>Min</th>
                        <th>Max</th>
                        <th>Écart-Type</th>
                        <th>Nombre de Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($course_stats as $stat): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($stat['course_name']); ?></td>
                            <td><?php echo number_format($stat['average'], 2); ?></td>
                            <td><?php echo number_format($stat['min_grade'], 2); ?></td>
                            <td><?php echo number_format($stat['max_grade'], 2); ?></td>
                            <td><?php echo number_format($stat['std_dev'], 2); ?></td>
                            <td><?php echo $stat['total_grades']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
            <?php else: ?>
            <div class="empty-state animated">
                <i class="fas fa-chart-line"></i>
                <h2>Aucune donnée à afficher</h2>
                <p>Veuillez sélectionner une classe et une période pour visualiser les notes.</p>
            </div>
        <?php endif; ?>

    </div>

    <script>
        function updateStats(classId, periodId) {
            if (classId && periodId) {
                window.location.href = `?class_id=${classId}&period_id=${periodId}`;
            }
        }

        function loadStudentNotes(classId, studentId) {
            const notesTableBody = document.getElementById('notesTableBody');
            const notesTableContainer = document.getElementById('notesTableContainer');

            if (!classId || !studentId) {
                notesTableBody.innerHTML = '';
                notesTableContainer.style.display = 'none';
                return;
            }

            fetch(`get_student_notes.php?class_id=${classId}&student_id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.length === 0) {
                        notesTableBody.innerHTML = '<tr><td colspan="5">Aucune note trouvée</td></tr>';
                    } else {
                        notesTableBody.innerHTML = data.map(note => `
                            <tr>
                                <td>${note.course_name}</td>
                                <td>${note.evaluation_type}</td>
                                <td>${note.grade}</td>
                                <td>${note.comment || '-'}</td>
                                <td>${new Date(note.created_at).toLocaleString()}</td>
                            </tr>
                        `).join('');
                    }
                    notesTableContainer.style.display = 'block';
                })
                .catch(error => console.error('Erreur:', error));
        }

        // Graphique de Distribution
        new Chart(document.getElementById('distributionChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($distribution, 'range')); ?>,
                datasets: [{
                    label: 'Nombre d\'Étudiants',
                    data: <?php echo json_encode(array_column($distribution, 'count')); ?>,
                    backgroundColor: 'rgba(46, 204, 113, 0.7)',
                }]
            },
            options: { responsive: true }
        });

        // Graphique des Moyennes par Matière
        new Chart(document.getElementById('courseAveragesChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($course_stats, 'course_name')); ?>,
                datasets: [{
                    label: 'Moyenne',
                    data: <?php echo json_encode(array_column($course_stats, 'average')); ?>,
                    backgroundColor: 'rgba(52, 152, 219, 0.7)',
                }]
            },
            options: { responsive: true }
        });
        
        function exportData() {
        const classId = document.getElementById('classSelect').value;
        const periodId = document.getElementById('periodSelect').value;
        
        if (classId && periodId) {
            window.location.href = `grade_export.php?class_id=${classId}&period_id=${periodId}`;
        } else {
            alert('Veuillez sélectionner une classe et une période pour exporter les notes');
        }
    }
    </script>
    

    <?php include '../includes/footer.php'; ?>
</body>
</html>
