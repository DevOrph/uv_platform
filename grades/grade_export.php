<?php
session_start();
require_once '../includes/db_connect.php';
require_once('../vendor/tecnickcom/tcpdf/tcpdf.php');  // Inclure la bibliothèque TCPDF

// Inclusion des fonctions d'export précédentes
require_once 'export_functions.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}

$filter_query = "
    SELECT 
        g.grade, 
        c.name as course_name, 
        g.created_at,
        et.name as evaluation_type
    FROM grades g
    JOIN courses c ON g.course_id = c.id
    JOIN evaluation_types et ON g.evaluation_type_id = et.id
    WHERE JSON_CONTAINS(c.class_id, JSON_QUOTE(?)) 
      AND g.evaluation_period_id = ?
";
$stmt = $conn->prepare($filter_query);
$stmt->bind_param("si", $_POST['class_id'], $_POST['period_id']);
$stmt->execute();
$grades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Traitement de l'export
if (isset($_POST['export'])) {
    $class_id = filter_var($_POST['class_id'], FILTER_VALIDATE_INT);
    $period_id = filter_var($_POST['period_id'], FILTER_VALIDATE_INT);
    $format = $_POST['format'];
    $eval_type = isset($_POST['evaluation_type']) ? $_POST['evaluation_type'] : null;
    $grade_filter = isset($_POST['grade_filter']) ? $_POST['grade_filter'] : null;
    $include_stats = isset($_POST['include_stats']);
    
    if ($class_id && $period_id && $format) {
        try {
            // Configuration des en-têtes selon le format
            $filename = "notes_export_" . date('Y-m-d');
            
            switch($format) {
                case 'csv':
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
                    export_csv($conn, $class_id, $period_id, [
                        'eval_type' => $eval_type,
                        'grade_filter' => $grade_filter,
                        'include_stats' => $include_stats
                    ]);
                    exit();
                    
                case 'excel':
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
                    export_excel($conn, $class_id, $period_id, [
                        'eval_type' => $eval_type,
                        'grade_filter' => $grade_filter,
                        'include_stats' => $include_stats
                    ]);
                    exit();
                    
                case 'pdf':
                    export_pdf($conn, $class_id, $period_id, [
                        'eval_type' => $eval_type,
                        'grade_filter' => $grade_filter,
                        'include_stats' => $include_stats
                    ]);
                    exit();
                    
                default:
                    throw new Exception("Format d'export non supporté");
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Erreur lors de l'export : " . $e->getMessage();
        }
    }
}


$classes = $conn->query("SELECT id, name FROM classes ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$periods = $conn->query("SELECT id, name FROM evaluation_periods ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);
$evaluation_types = $conn->query("SELECT id, name FROM evaluation_types ORDER BY name")->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export des Notes - UV Platform</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Styles existants */

        .export-section {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .export-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .export-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .option-card {
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .option-card:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .option-card.selected {
            border: 2px solid #039be5;
        }

        .preview-data {
            background: rgba(255, 255, 255, 0.05);
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .stat-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-card .value {
            font-size: 24px;
            font-weight: bold;
            color: #039be5;
        }

        .stat-card .label {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
        }

        .chart-container {
            background: rgba(255, 255, 255, 0.05);
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            height: 300px;
        }

        .advanced-filters {
            background: rgba(255, 255, 255, 0.05);
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .checkbox-group {
            display: flex;
            gap: 20px;
            margin-top: 15px;
        }

        .custom-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #039be5;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: none;
            z-index: 1000;
        }

        .notification.success {
            background: rgba(46, 204, 113, 0.9);
            color: white;
        }

        .notification.error {
            background: rgba(231, 76, 60, 0.9);
            color: white;
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
            <i class="fas fa-file-export"></i>
            <h1>Export des Notes</h1>
        </div>

        <div class="export-section">
            <form method="post" id="exportForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <div class="export-grid">
                    <div class="form-group">
                        <label><i class="fas fa-graduation-cap"></i> Classe</label>
                        <select name="class_id" required onchange="updatePreview()">
    <option value="">Sélectionner une classe</option>
    <?php foreach ($classes as $class): ?>
        <option value="<?php echo htmlspecialchars($class['id']); ?>">
            <?php echo htmlspecialchars($class['name']); ?>
        </option>
    <?php endforeach; ?>
</select>

                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Période</label>
                        <select name="period_id" required onchange="updatePreview()">
    <option value="">Sélectionner une période</option>
    <?php foreach ($periods as $period): ?>
        <option value="<?php echo htmlspecialchars($period['id']); ?>">
            <?php echo htmlspecialchars($period['name']); ?>
        </option>
    <?php endforeach; ?>
</select>

                    </div>
                </div>


                <div class="export-options">
                    <div class="option-card" onclick="selectFormat('csv')">
                        <i class="fas fa-file-csv fa-2x"></i>
                        <h3>Export CSV</h3>
                        <p>Format compatible avec Excel</p>
                    </div>
                    <div class="option-card" onclick="selectFormat('excel')">
                        <i class="fas fa-file-excel fa-2x"></i>
                        <h3>Export Excel</h3>
                        <p>Avec mise en forme avancée</p>
                    </div>
                    <div class="option-card" onclick="selectFormat('pdf')">
                        <i class="fas fa-file-pdf fa-2x"></i>
                        <h3>Export PDF</h3>
                        <p>Format d'impression</p>
                    </div>
                </div>

                <input type="hidden" name="format" id="formatInput">
                <button type="submit" name="export" class="btn-export">
                    <i class="fas fa-download"></i> Exporter
                </button>
            </form>
        </div>

    </div>

    <div class="loading-overlay">
        <div class="loading-spinner"></div>
        ```php
    </div>

    <div class="notification" id="notification"></div>

    <?php include '../includes/footer.php'; ?>

    <script>
    // Fonction pour sélectionner le format d'export
    function selectFormat(format) {
    // Définir le format sélectionné dans l'entrée masquée
    document.getElementById('formatInput').value = format;

    // Ajouter une classe 'selected' à la carte cliquée
    const cards = document.querySelectorAll('.option-card');
    cards.forEach(card => card.classList.remove('selected'));

    // Trouver la carte correspondante et ajouter la classe
    const selectedCard = document.querySelector(`.option-card[onclick="selectFormat('${format}')"]`);
    if (selectedCard) selectedCard.classList.add('selected');
}


    // Fonction de mise à jour de la prévisualisation
    function updatePreview() {
    const classId = document.querySelector('select[name="class_id"]').value;
    const periodId = document.querySelector('select[name="period_id"]').value;

    if (classId && periodId) {
        // Mettez à jour la prévisualisation via AJAX ou un autre mécanisme
        fetch(`/path/to/api?class_id=${classId}&period_id=${periodId}`)
            .then(response => response.json())
            .then(data => {
                // Mettez à jour l'interface utilisateur avec les données de prévisualisation
                console.log(data);
            })
            .catch(error => console.error('Erreur lors de la mise à jour :', error));
    }
}


 // Gestion du formulaire d'export
document.getElementById('exportForm').onsubmit = function(e) {
    const format = document.getElementById('formatInput').value;
    if (!format) {
        e.preventDefault();
        showNotification('Veuillez sélectionner un format d\'export', 'error');
        return false;
    }

    const classId = document.querySelector('select[name="class_id"]').value;
    const periodId = document.querySelector('select[name="period_id"]').value;

    if (!classId || !periodId) {
        e.preventDefault();
        showNotification('Veuillez sélectionner une classe et une période', 'error');
        return false;
    }

    document.querySelector('.loading-overlay').style.display = 'flex';

    // Attendre quelques secondes avant d'actualiser la page
    setTimeout(() => {
        document.querySelector('.loading-overlay').style.display = 'none';
        showNotification('Téléchargement terminé', 'success');

        // Actualiser la page après le téléchargement
        window.location.reload();
    }, 2000); // 3 secondes (ajuste si nécessaire)
};



    // Fonction pour afficher les notifications
    function showNotification(message, type = 'success') {
        const notification = document.getElementById('notification');
        notification.textContent = message;
        notification.className = 'notification ' + type;
        notification.style.display = 'block';

        setTimeout(() => {
            notification.style.display = 'none';
        }, 3000);
    }
    </script>
</body>
</html>
