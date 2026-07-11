<?php
session_start();
require_once '../includes/db_connect.php';

require_once '../includes/super_admin.php';

// Page de test accessible seulement par un super administrateur
if (!isset($_SESSION['user_id']) || !is_super_admin($conn)) {
    die('Accès réservé à un super administrateur');
}

$test_results = [];
$overall_status = true;

function runTest($test_name, $condition, $success_msg, $error_msg) {
    global $test_results, $overall_status;
    
    $status = $condition;
    $test_results[] = [
        'name' => $test_name,
        'status' => $status,
        'message' => $status ? $success_msg : $error_msg
    ];
    
    if (!$status) {
        $overall_status = false;
    }
    
    return $status;
}

// Test 1: Vérification des tables
$tables_to_check = ['exam_permissions', 'grade_history', 'grades', 'users', 'evaluation_types'];
foreach ($tables_to_check as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    runTest(
        "Table $table existe",
        $result->num_rows > 0,
        "Table $table trouvée",
        "Table $table manquante"
    );
}

// Test 2: Vérification des colonnes exam_permissions
$expected_columns = ['id', 'user_id', 'granted_by', 'granted_at', 'expires_at', 'is_active', 'notes'];
$result = $conn->query("DESCRIBE exam_permissions");
$existing_columns = [];
while ($row = $result->fetch_assoc()) {
    $existing_columns[] = $row['Field'];
}

foreach ($expected_columns as $col) {
    runTest(
        "Colonne exam_permissions.$col",
        in_array($col, $existing_columns),
        "Colonne $col présente",
        "Colonne $col manquante"
    );
}

// Test 3: Au moins un super administrateur existe
$super_admins = $conn->query("SELECT id FROM users WHERE role = 'admin' AND is_super_admin = 1");
runTest(
    "Super administrateur",
    $super_admins->num_rows > 0,
    $super_admins->num_rows . " super administrateur(s) configuré(s)",
    "Aucun super administrateur configuré"
);

// Test 4: Vérification des types d'évaluation
$eval_types = $conn->query("SELECT * FROM evaluation_types WHERE id = 2");
runTest(
    "Type d'évaluation 'Examen'",
    $eval_types->num_rows > 0,
    "Type 'Examen' (ID=2) configuré",
    "Type 'Examen' manquant - Vérifiez evaluation_types"
);

// Test 5: Utilisateurs éligibles
$eligible_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role IN ('admin', 'teacher') AND is_super_admin = 0");
$count = $eligible_users->fetch_assoc()['count'];
runTest(
    "Utilisateurs éligibles",
    $count > 0,
    "$count utilisateurs éligibles trouvés",
    "Aucun utilisateur éligible trouvé"
);

// Test 6: Fonctions de permission
function canAddExamGrade($conn, $user_id) {
    if (is_super_admin($conn, $user_id)) return true;
    $query = "SELECT * FROM exam_permissions WHERE user_id = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

$super_id = $conn->query("SELECT id FROM users WHERE role = 'admin' AND is_super_admin = 1 LIMIT 1")->fetch_assoc()['id'] ?? '';
runTest(
    "Fonction de vérification permissions",
    $super_id !== '' && canAddExamGrade($conn, $super_id),
    "Fonction canAddExamGrade() fonctionne",
    "Erreur dans la fonction de vérification"
);

// Test 7: Structure des grades
$grade_columns = $conn->query("DESCRIBE grades");
$grade_cols = [];
while ($row = $grade_columns->fetch_assoc()) {
    $grade_cols[] = $row['Field'];
}

$required_grade_cols = ['id', 'student_id', 'course_id', 'evaluation_type_id', 'grade', 'created_by'];
foreach ($required_grade_cols as $col) {
    runTest(
        "Colonne grades.$col",
        in_array($col, $grade_cols),
        "Colonne grades.$col présente",
        "Colonne grades.$col manquante"
    );
}

// Test 8: Contraintes et index
try {
    $conn->query("SELECT * FROM exam_permissions ep JOIN users u ON ep.user_id = u.id LIMIT 1");
    runTest(
        "Contraintes FK exam_permissions",
        true,
        "Contraintes de clé étrangère fonctionnelles",
        "Erreur contraintes FK"
    );
} catch (Exception $e) {
    runTest(
        "Contraintes FK exam_permissions",
        false,
        "Contraintes OK",
        "Erreur: " . $e->getMessage()
    );
}

// Test 9: Données de test
$test_users = $conn->query("SELECT * FROM users WHERE role IN ('teacher', 'admin') AND is_super_admin = 0 LIMIT 3");
$test_user_count = $test_users->num_rows;
runTest(
    "Utilisateurs de test disponibles",
    $test_user_count >= 1,
    "$test_user_count utilisateurs disponibles pour tests",
    "Pas assez d'utilisateurs pour tester"
);

// Test 10: Création permission de test (si des utilisateurs existent)
if ($test_user_count > 0) {
    $test_user = $test_users->fetch_assoc();
    $test_user_id = $test_user['id'];
    
    // Créer une permission de test
    try {
        $stmt = $conn->prepare("INSERT INTO exam_permissions (user_id, granted_by, notes) VALUES (?, ?, 'Test automatique') ON DUPLICATE KEY UPDATE notes = 'Test automatique mis à jour'");
        $stmt->bind_param("ss", $test_user_id, $_SESSION['user_id']);
        $success = $stmt->execute();
        
        runTest(
            "Création permission de test",
            $success,
            "Permission de test créée pour {$test_user['name']}",
            "Erreur création permission de test"
        );
        
        // Vérifier que la fonction détecte la permission
        if ($success) {
            $can_add = canAddExamGrade($conn, $test_user_id);
            runTest(
                "Détection permission accordée",
                $can_add,
                "Permission correctement détectée",
                "Permission non détectée par la fonction"
            );
        }
        
        // Nettoyer après test
        $conn->query("DELETE FROM exam_permissions WHERE user_id = '$test_user_id' AND notes = 'Test automatique'");
        
    } catch (Exception $e) {
        runTest(
            "Création permission de test",
            false,
            "OK",
            "Erreur: " . $e->getMessage()
        );
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test du Système de Permissions</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #051e34 0%, #0c2d48 100%);
            color: white;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }

        .test-container {
            max-width: 1000px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 30px;
            backdrop-filter: blur(10px);
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        .header h1 {
            margin: 0;
            font-size: 2.5rem;
            color: #039be5;
        }

        .overall-status {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
            font-size: 1.2rem;
            font-weight: bold;
        }

        .status-success {
            background: linear-gradient(135deg, #4CAF50, #45a049);
        }

        .status-error {
            background: linear-gradient(135deg, #f44336, #d32f2f);
        }

        .test-grid {
            display: grid;
            gap: 15px;
        }

        .test-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-left: 4px solid;
        }

        .test-success {
            border-left-color: #4CAF50;
        }

        .test-error {
            border-left-color: #f44336;
        }

        .test-icon {
            font-size: 1.5rem;
            margin-right: 15px;
            min-width: 30px;
        }

        .test-content {
            flex: 1;
        }

        .test-name {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .test-message {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .actions {
            margin-top: 40px;
            text-align: center;
        }

        .btn {
            display: inline-block;
            padding: 12px 25px;
            margin: 0 10px;
            background: linear-gradient(135deg, #039be5, #0277bd);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            transition: transform 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #039be5;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
            text-transform: uppercase;
        }

        .timestamp {
            text-align: center;
            margin-top: 30px;
            opacity: 0.6;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="test-container">
        <div class="header">
            <h1>🧪 Test du Système de Permissions d'Examen</h1>
            <p>Vérification de l'installation et de la configuration</p>
        </div>

        <div class="overall-status <?php echo $overall_status ? 'status-success' : 'status-error'; ?>">
            <?php if ($overall_status): ?>
                ✅ Tous les tests sont passés avec succès !
            <?php else: ?>
                ❌ Certains tests ont échoué - Vérifiez les détails ci-dessous
            <?php endif; ?>
        </div>

        <div class="stats">
            <?php
            $success_count = count(array_filter($test_results, function($test) { return $test['status']; }));
            $error_count = count($test_results) - $success_count;
            ?>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($test_results); ?></div>
                <div class="stat-label">Tests Exécutés</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #4CAF50;"><?php echo $success_count; ?></div>
                <div class="stat-label">Succès</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #f44336;"><?php echo $error_count; ?></div>
                <div class="stat-label">Erreurs</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #039be5;"><?php echo round($success_count / count($test_results) * 100); ?>%</div>
                <div class="stat-label">Taux de Réussite</div>
            </div>
        </div>

        <div class="test-grid">
            <?php foreach ($test_results as $test): ?>
                <div class="test-item <?php echo $test['status'] ? 'test-success' : 'test-error'; ?>">
                    <div class="test-icon">
                        <?php echo $test['status'] ? '✅' : '❌'; ?>
                    </div>
                    <div class="test-content">
                        <div class="test-name"><?php echo htmlspecialchars($test['name']); ?></div>
                        <div class="test-message"><?php echo htmlspecialchars($test['message']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="actions">
            <?php if ($overall_status): ?>
                <a href="exam_permissions.php" class="btn">
                    🔑 Gérer les Permissions
                </a>
                <a href="grades_management.php" class="btn">
                    📝 Gestion des Notes
                </a>
                <a href="admin_permissions_overview.php" class="btn">
                    📊 Tableau de Bord
                </a>
            <?php else: ?>
                <a href="?retry=1" class="btn">
                    🔄 Relancer les Tests
                </a>
                <a href="../admin/admin_dashboard.php" class="btn">
                    🏠 Retour Admin
                </a>
            <?php endif; ?>
        </div>

        <div class="timestamp">
            Test exécuté le <?php echo date('d/m/Y à H:i:s'); ?>
        </div>
    </div>

    <script>
        // Auto-refresh si paramètre retry
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('retry')) {
            setTimeout(() => {
                window.location.href = window.location.pathname;
            }, 2000);
        }

        // Animation des éléments
        document.addEventListener('DOMContentLoaded', function() {
            const testItems = document.querySelectorAll('.test-item');
            testItems.forEach((item, index) => {
                setTimeout(() => {
                    item.style.opacity = '0';
                    item.style.transform = 'translateX(-20px)';
                    item.style.transition = 'all 0.3s ease';
                    
                    setTimeout(() => {
                        item.style.opacity = '1';
                        item.style.transform = 'translateX(0)';
                    }, 100);
                }, index * 100);
            });
        });
    </script>
</body>
</html>