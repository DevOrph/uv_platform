<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/super_admin.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}

// Redirige tout admin qui n'est pas super administrateur
if (!is_super_admin($conn)) {
    header('Location: ../admin/admin_dashboard.php');
    exit();
}

require_once '../includes/semester_helper.php';

// Paramètres GET
$selectedYear    = isset($_GET['year'])       ? $_GET['year']       : ANNEE_ACADEMIQUE_COURANTE;
$selectedClass   = isset($_GET['class_id'])   ? $_GET['class_id']   : null;
$selectedPeriod  = isset($_GET['period_id'])  ? $_GET['period_id']  : null;
$selectedStudent = isset($_GET['student_id']) ? $_GET['student_id'] : null;

// Classes
$classes = $conn->query("SELECT id, name FROM classes ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Années académiques disponibles
$availableYears = $conn->query(
    "SELECT DISTINCT school_year FROM evaluation_periods ORDER BY school_year DESC"
)->fetch_all(MYSQLI_ASSOC);

// Périodes filtrées par l'année sélectionnée
$stmtPeriods = $conn->prepare(
    "SELECT id, name FROM evaluation_periods WHERE school_year = ? ORDER BY id ASC"
);
$stmtPeriods->bind_param('s', $selectedYear);
$stmtPeriods->execute();
$periods = $stmtPeriods->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtPeriods->close();
?>

<!DOCTYPE html>
<html lang="fr"> 
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulletins de Notes - UV Platform</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
            background-color: #051e34;
            color: #ffffff;
        }

        .dashboard-container {
            flex: 1;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        .page-header {
            background: #0c2d48;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-header i {
            font-size: 24px;
            color: #039be5;
        }

        .filters-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
        }

        .filter-group select {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 10px;
            border-radius: 5px;
        }

        .bulletin {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-top: 20px;
            color: #333;
        }

        .bulletin-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eee;
        }

        .student-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .grades-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        .grades-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            color: #333;
        }

        .grades-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            color: #333;
        }

        .grade-value {
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
        }

        .grade-good { background: rgba(46, 204, 113, 0.2); color: #27ae60; }
        .grade-average { background: rgba(241, 196, 15, 0.2); color: #f39c12; }
        .grade-poor { background: rgba(231, 76, 60, 0.2); color: #c0392b; }

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

        .buttons-container {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #039be5;
            color: white;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .export-actions {
            grid-column: 1 / -1;
        }

        .export-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        @media print {
            body {
                background: white;
            }
            .no-print {
                display: none;
            }
            .bulletin {
                box-shadow: none;
                margin: 0;
                padding: 0;
            }
        }
        select {
    background-color: #f0f0f0; /* Gris clair */
    color: #333; /* Couleur du texte */
    border: 1px solid #ccc;
    padding: 5px;
    border-radius: 4px;
}

/* Couleur de fond pour les options */
option {
    background: #0c2d48;
    color: #ffffff;
}
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <i class="fas fa-file-alt"></i>
            <h1>Bulletins de Notes</h1>
        </div>

        <!-- Filtres -->
<div class="filters-card no-print">
    <div class="filter-group">
        <label><i class="fas fa-calendar"></i> Année académique</label>
        <select id="yearSelect" onchange="changeYear(this.value)">
            <?php foreach ($availableYears as $yr): ?>
                <option value="<?php echo htmlspecialchars($yr['school_year']); ?>"
                        <?php echo $selectedYear === $yr['school_year'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($yr['school_year']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group">
        <label><i class="fas fa-graduation-cap"></i> Classe</label>
        <select id="classSelect" onchange="loadStudents(this.value)">
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
        <label><i class="fas fa-user-graduate"></i> Étudiant</label>
        <select id="studentSelect" <?php echo !$selectedClass ? 'disabled' : ''; ?>>
            <option value="">Sélectionner un étudiant</option>
        </select>
    </div>

    <div class="filter-group">
        <label><i class="fas fa-calendar-alt"></i> Période</label>
        <select id="periodSelect">
            <option value="">Sélectionner une période</option>
            <?php foreach ($periods as $period): ?>
                <option value="<?php echo $period['id']; ?>"
                        <?php echo $selectedPeriod == $period['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($period['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group export-actions">
        <label><i class="fas fa-download"></i> Exporter</label>
        <div class="export-buttons">
            <button onclick="generateBulletinXLSX()" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Bulletin Excel
            </button>
            <button onclick="exportClassXLSX()" class="btn btn-primary">
                <i class="fas fa-file-excel"></i> Toute la classe
            </button>
        </div>
    </div>
</div>

<script>
function changeYear(year) {
    const classId = document.getElementById('classSelect').value;
    let url = '?year=' + encodeURIComponent(year);
    if (classId) url += '&class_id=' + encodeURIComponent(classId);
    window.location.href = url;
}

function _getBulletinParams() {
    const classId   = document.getElementById('classSelect').value;
    const studentId = document.getElementById('studentSelect').value;
    const periodId  = document.getElementById('periodSelect').value;
    const year      = document.getElementById('yearSelect').value;
    if (!classId || !studentId || !periodId) {
        alert('Veuillez sélectionner une classe, un étudiant et une période.');
        return null;
    }
    return { classId, studentId, periodId, year };
}

function generateBulletinXLSX() {
    const p = _getBulletinParams();
    if (!p) return;
    window.location.href = `generate_xlsx_bulletin.php?class_id=${p.classId}&student_id=${p.studentId}&period_id=${p.periodId}&year=${encodeURIComponent(p.year)}`;
}

function exportClassXLSX() {
    const classId  = document.getElementById('classSelect').value;
    const periodId = document.getElementById('periodSelect').value;
    const year     = document.getElementById('yearSelect').value;
    if (!classId || !periodId) {
        alert('Veuillez sélectionner une classe et une période.');
        return;
    }
    showExportSpinner();
    window.location.href = `generate_xlsx_bulletin.php?class_id=${classId}&period_id=${periodId}&mode=class&year=${encodeURIComponent(year)}`;
}

function showExportSpinner() {
    const overlay = document.getElementById('exportSpinner');
    overlay.style.display = 'flex';
    const hide = () => {
        overlay.style.display = 'none';
        window.removeEventListener('focus', hide);
        clearTimeout(timer);
    };
    window.addEventListener('focus', hide);
    const timer = setTimeout(hide, 120000);
}
</script>

<!-- Spinner de génération classe -->
<div id="exportSpinner" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(5,30,52,0.88); z-index:9999; align-items:center; justify-content:center; flex-direction:column; gap:20px;">
    <i class="fas fa-spinner fa-spin" style="font-size:52px; color:#039be5;"></i>
    <p style="color:#ffffff; font-size:18px; margin:0;">Génération en cours… Veuillez patienter.</p>
    <p style="color:rgba(255,255,255,0.6); font-size:13px; margin:0;">Cette opération peut prendre jusqu'à 2 minutes pour une grande classe.</p>
</div>

<!-- Contenu du bulletin -->
<div id="bulletinContent">
    <div class="empty-state animated">
        <i class="fas fa-file-alt"></i>
        <h2>Sélectionnez les critères</h2>
        <p>Veuillez sélectionner une classe, un étudiant et une période pour générer le bulletin.</p>
    </div>
</div>

    </div>

    <?php include '../includes/footer.php'; ?>


<script>
function loadStudents(classId) {
    if (!classId) {
        document.getElementById('studentSelect').innerHTML = '<option value="">Sélectionner un étudiant</option>';
        document.getElementById('studentSelect').disabled = true;
        return;
    }

    fetch(`../includes/get_students.php?class_id=${classId}`)
        .then(response => response.json())
        .then(students => {
            const select = document.getElementById('studentSelect');
            select.innerHTML = '<option value="">Sélectionner un étudiant</option>';
            students.forEach(student => {
                select.innerHTML += `<option value="${student.id}">${student.name}</option>`;
            });
            select.disabled = false;
        });
}

function generateBulletinAjax() {
    const classId = document.getElementById('classSelect').value;
    const studentId = document.getElementById('studentSelect').value;
    const periodId = document.getElementById('periodSelect').value;

    if (!classId || !studentId || !periodId) {
        alert('Veuillez sélectionner tous les critères');
        return;
    }

    fetch(`generate_bulletin.php?class_id=${classId}&student_id=${studentId}&period_id=${periodId}`)
        .then(response => response.json())
        .then(data => {
            const bulletinContent = document.getElementById('bulletinContent');

            if (data.error) {
                bulletinContent.innerHTML = `<p class="empty-state"><i class="fas fa-exclamation-circle"></i> ${data.error}</p>`;
            } else {
                let html = `
                    <div class="bulletin-header">
                        <h2>Bulletin de Notes</h2>
                        <p>Classe: ${document.getElementById('classSelect').selectedOptions[0].text}</p>
                        <p>Étudiant: ${document.getElementById('studentSelect').selectedOptions[0].text}</p>
                        <p>Période: ${document.getElementById('periodSelect').selectedOptions[0].text}</p>
                    </div>
                    <table class="grades-table">
                        <thead>
                            <tr>
                                <th>Matière</th>
                                <th>Type d'Évaluation</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>`;

                data.forEach(grade => {
                    html += `
                        <tr>
                            <td>${grade.course_name}</td>
                            <td>${grade.evaluation_type}</td>
                            <td>${grade.grade}</td>
                        </tr>`;
                });

                html += `</tbody></table>`;
                bulletinContent.innerHTML = html;
            }
        })
        .catch(error => {
            console.error('Erreur lors du chargement du bulletin:', error);
            document.getElementById('bulletinContent').innerHTML = `<p class="empty-state"><i class="fas fa-exclamation-circle"></i> Une erreur est survenue lors de la génération du bulletin.</p>`;
        });
}
</script>

</body>
</html>
