<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}

// Traitement de l'association
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) { 
    if ($_POST['action'] === 'assign') {
        $course_id = $_POST['course_id'];
        $unit_id = $_POST['unit_id'];
        $display_order = $_POST['display_order'] ?? 0;
        
        $stmt = $conn->prepare("UPDATE courses SET teaching_unit_id = ?, display_order = ? WHERE id = ?");
        $stmt->bind_param("iii", $unit_id, $display_order, $course_id);
        
        if ($stmt->execute()) {
            $success = "Cours associé avec succès !";
        } else {
            $error = "Erreur lors de l'association du cours.";
        }
    } elseif ($_POST['action'] === 'unassign') {
        $course_id = $_POST['course_id'];
        
        $stmt = $conn->prepare("UPDATE courses SET teaching_unit_id = NULL WHERE id = ?");
        $stmt->bind_param("i", $course_id);
        
        if ($stmt->execute()) {
            $success = "Cours dissocié avec succès !";
        } else {
            $error = "Erreur lors de la dissociation du cours.";
        }
    }
}

// Filtres
$filter_class = $_GET['class'] ?? '';
$filter_semester = $_GET['semester'] ?? '';

// Récupération des données
$classes = $conn->query("SELECT id, name FROM classes ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Cours non assignés ou filtrés
$coursesQuery = "
    SELECT c.id, c.name, c.coefficient, c.class_id, c.semester, 
           c.teaching_unit_id, c.display_order,
           tu.code as unit_code, tu.name as unit_name
    FROM courses c
    LEFT JOIN teaching_units tu ON c.teaching_unit_id = tu.id
    WHERE 1=1
";

if ($filter_class) {
    $coursesQuery .= " AND JSON_CONTAINS(c.class_id, JSON_QUOTE('$filter_class'))";
}
if ($filter_semester) {
    $coursesQuery .= " AND c.semester = $filter_semester";
}

$coursesQuery .= " ORDER BY c.teaching_unit_id IS NULL DESC, tu.code, c.display_order, c.name";

$courses = $conn->query($coursesQuery)->fetch_all(MYSQLI_ASSOC);

// Unités d'enseignement disponibles
$unitsQuery = "SELECT id, code, name, class_id, semester FROM teaching_units";
if ($filter_class) {
    $unitsQuery .= " WHERE class_id = '$filter_class'";
}
if ($filter_semester) {
    $unitsQuery .= ($filter_class ? " AND" : " WHERE") . " semester = $filter_semester";
}
$unitsQuery .= " ORDER BY display_order";

$units = $conn->query($unitsQuery)->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Association Cours ↔ Unités d'Enseignement - UV Platform</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #051e34;
            color: #ffffff;
            font-family: Arial, sans-serif;
        }
        .dashboard-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        .page-header {
            background: #0c2d48;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .alert-success {
            background-color: #28a745;
        }
        .alert-danger {
            background-color: #dc3545;
        }
        .filters {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
        }
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .filter-group select {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary {
            background-color: #039be5;
            color: white;
        }
        .btn-success {
            background-color: #28a745;
            color: white;
        }
        .btn-warning {
            background-color: #ffc107;
            color: #000;
        }
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        .btn:hover {
            opacity: 0.8;
            transform: translateY(-2px);
        }
        .courses-grid {
            display: grid;
            gap: 20px;
        }
        .course-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 20px;
            border-left: 4px solid #039be5;
        }
        .course-card.unassigned {
            border-left-color: #ffc107;
        }
        .course-card.assigned {
            border-left-color: #28a745;
        }
        .course-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        .course-title {
            font-size: 18px;
            font-weight: bold;
            color: #fff;
        }
        .course-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-warning {
            background-color: #ffc107;
            color: #000;
        }
        .badge-success {
            background-color: #28a745;
            color: white;
        }
        .assignment-form {
            display: flex;
            gap: 10px;
            align-items: center;
            background: rgba(0, 0, 0, 0.2);
            padding: 15px;
            border-radius: 8px;
        }
        .assignment-form select {
            flex: 1;
            padding: 8px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .assignment-form input[type="number"] {
            width: 80px;
            padding: 8px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .current-assignment {
            background: rgba(40, 167, 69, 0.2);
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-value {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1><i class="fas fa-link"></i> Association Cours ↔ Unités d'Enseignement</h1>
            <p>Associez vos cours existants aux unités d'enseignement configurées</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($courses); ?></div>
                <div class="stat-label">Cours Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #28a745;">
                    <?php echo count(array_filter($courses, fn($c) => !empty($c['teaching_unit_id']))); ?>
                </div>
                <div class="stat-label">Cours Associés</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #ffc107;">
                    <?php echo count(array_filter($courses, fn($c) => empty($c['teaching_unit_id']))); ?>
                </div>
                <div class="stat-label">Cours Non Associés</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($units); ?></div>
                <div class="stat-label">Unités d'Enseignement</div>
            </div>
        </div>

        <!-- Filtres -->
        <form class="filters" method="GET">
            <div class="filter-group">
                <label><i class="fas fa-graduation-cap"></i> Classe</label>
                <select name="class" onchange="this.form.submit()">
                    <option value="">Toutes les classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" 
                                <?php echo $filter_class === $class['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> Semestre</label>
                <select name="semester" onchange="this.form.submit()">
                    <option value="">Tous les semestres</option>
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <option value="<?php echo $i; ?>" 
                                <?php echo $filter_semester == $i ? 'selected' : ''; ?>>
                            Semestre <?php echo $i; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Filtrer
            </button>
        </form>

        <!-- Liste des cours -->
        <div class="courses-grid">
            <?php if (empty($courses)): ?>
                <div style="text-align: center; padding: 60px; color: rgba(255,255,255,0.6);">
                    <i class="fas fa-inbox" style="font-size: 64px; display: block; margin-bottom: 20px;"></i>
                    <h3>Aucun cours trouvé</h3>
                    <p>Essayez de modifier vos filtres ou ajoutez des cours</p>
                </div>
            <?php else: ?>
                <?php foreach ($courses as $course): ?>
                    <div class="course-card <?php echo empty($course['teaching_unit_id']) ? 'unassigned' : 'assigned'; ?>">
                        <div class="course-header">
                            <div>
                                <div class="course-title">
                                    <?php echo htmlspecialchars($course['name']); ?>
                                </div>
                                <div class="course-meta">
                                    <span><i class="fas fa-coins"></i> <?php echo $course['coefficient']; ?> crédits</span>
                                    <span><i class="fas fa-calendar"></i> Semestre <?php echo $course['semester']; ?></span>
                                </div>
                            </div>
                            <span class="badge <?php echo empty($course['teaching_unit_id']) ? 'badge-warning' : 'badge-success'; ?>">
                                <?php echo empty($course['teaching_unit_id']) ? 'Non associé' : 'Associé'; ?>
                            </span>
                        </div>

                        <?php if (!empty($course['teaching_unit_id'])): ?>
                            <div class="current-assignment">
                                <strong><i class="fas fa-check-circle"></i> Actuellement dans :</strong><br>
                                <?php echo htmlspecialchars($course['unit_code']); ?> - 
                                <?php echo htmlspecialchars($course['unit_name']); ?>
                                <br>
                                <small>Ordre d'affichage: <?php echo $course['display_order']; ?></small>
                            </div>
                            
                            <form method="POST" style="display: inline;"
                                  onsubmit="return confirm('Voulez-vous vraiment dissocier ce cours de son UE ?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                <input type="hidden" name="action" value="unassign">
                                <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="fas fa-unlink"></i> Dissocier
                                </button>
                            </form>
                        <?php endif; ?>

                        <form method="POST" class="assignment-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                            
                            <select name="unit_id" required>
                                <option value="">-- Sélectionner une UE --</option>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?php echo $unit['id']; ?>"
                                            <?php echo $course['teaching_unit_id'] == $unit['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($unit['code'] . ' - ' . $unit['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="number" 
                                   name="display_order" 
                                   placeholder="Ordre" 
                                   value="<?php echo $course['display_order'] ?? 0; ?>"
                                   title="Ordre d'affichage dans l'UE">
                            
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-link"></i> 
                                <?php echo empty($course['teaching_unit_id']) ? 'Associer' : 'Réassocier'; ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
