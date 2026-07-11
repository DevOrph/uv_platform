<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}

// Traitement des actions (Ajout, Modification, Suppression)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $code = $_POST['code'];
        $name = $_POST['name'];
        $short_name = $_POST['short_name'];
        $class_id = $_POST['class_id'];
        $semester = $_POST['semester'];
        $display_order = $_POST['display_order'];
        
        $stmt = $conn->prepare("INSERT INTO teaching_units (code, name, short_name, class_id, semester, display_order) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssis", $code, $name, $short_name, $class_id, $semester, $display_order);
        $stmt->execute();
        $success = "Unité d'enseignement ajoutée avec succès !";
        
    } elseif ($action === 'edit') {
        $id = $_POST['id'];
        $code = $_POST['code'];
        $name = $_POST['name'];
        $short_name = $_POST['short_name'];
        $class_id = $_POST['class_id'];
        $semester = $_POST['semester'];
        $display_order = $_POST['display_order'];
        
        $stmt = $conn->prepare("UPDATE teaching_units SET code=?, name=?, short_name=?, class_id=?, semester=?, display_order=? WHERE id=?");
        $stmt->bind_param("ssssisi", $code, $name, $short_name, $class_id, $semester, $display_order, $id);
        $stmt->execute();
        $success = "Unité d'enseignement modifiée avec succès !";
        
    } elseif ($action === 'delete') {
        $id = $_POST['id'];
        
        // Vérifier s'il y a des cours associés
        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM courses WHERE teaching_unit_id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $result = $checkStmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $error = "Impossible de supprimer cette UE car elle contient des cours. Veuillez d'abord réassigner les cours.";
        } else {
            $stmt = $conn->prepare("DELETE FROM teaching_units WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $success = "Unité d'enseignement supprimée avec succès !";
        }
    }
}

// Récupération des données
$classes = $conn->query("SELECT id, name FROM classes ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$units = $conn->query("
    SELECT tu.*, c.name as class_name 
    FROM teaching_units tu 
    LEFT JOIN classes c ON tu.class_id = c.id 
    ORDER BY tu.class_id, tu.semester, tu.display_order
")->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Unités d'Enseignement - UV Platform</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #051e34;
            color: #ffffff;
            font-family: Arial, sans-serif;
        }
        .dashboard-container {
            max-width: 1400px;
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
            color: white;
        }
        .alert-danger {
            background-color: #dc3545;
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
        .form-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            overflow: hidden;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        th {
            background: rgba(3, 155, 229, 0.3);
            font-weight: bold;
        }
        tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        .actions {
            display: flex;
            gap: 5px;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }
        .modal-content {
            background-color: #0c2d48;
            margin: 5% auto;
            padding: 30px;
            border-radius: 10px;
            width: 80%;
            max-width: 600px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: white;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1><i class="fas fa-layer-group"></i> Gestion des Unités d'Enseignement (UE)</h1>
            <p>Configurez les unités d'enseignement pour chaque classe et semestre</p>
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

        <div class="form-card">
            <h2><i class="fas fa-plus-circle"></i> Ajouter une Unité d'Enseignement</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="add">
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Code UE *</label>
                        <input type="text" name="code" placeholder="Ex: UE 13" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-graduation-cap"></i> Classe *</label>
                        <select name="class_id" required>
                            <option value="">Sélectionner une classe</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-heading"></i> Nom Complet *</label>
                    <input type="text" name="name" placeholder="Ex: UNITE D'ENSEIGNEMENT DE DROIT MARITIME" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-font"></i> Nom Court (Sigle)</label>
                        <input type="text" name="short_name" placeholder="Ex: UEDR">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Semestre *</label>
                        <select name="semester" required>
                            <option value="">Sélectionner</option>
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>">Semestre <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-sort-numeric-up"></i> Ordre d'affichage *</label>
                    <input type="number" name="display_order" value="0" required>
                    <small style="color: rgba(255,255,255,0.7);">Plus le nombre est petit, plus l'UE apparaît en haut</small>
                </div>

                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Ajouter l'Unité d'Enseignement
                </button>
            </form>
        </div>

        <div class="form-card">
            <h2><i class="fas fa-list"></i> Liste des Unités d'Enseignement</h2>
            
            <?php if (empty($units)): ?>
                <p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.6);">
                    <i class="fas fa-inbox" style="font-size: 48px; display: block; margin-bottom: 15px;"></i>
                    Aucune unité d'enseignement configurée
                </p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Nom</th>
                            <th>Sigle</th>
                            <th>Classe</th>
                            <th>Semestre</th>
                            <th>Ordre</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($units as $unit): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($unit['code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($unit['name']); ?></td>
                                <td><?php echo htmlspecialchars($unit['short_name']); ?></td>
                                <td><?php echo htmlspecialchars($unit['class_name']); ?></td>
                                <td>S<?php echo $unit['semester']; ?></td>
                                <td><?php echo $unit['display_order']; ?></td>
                                <td class="actions">
                                    <button onclick="editUnit(<?php echo htmlspecialchars(json_encode($unit)); ?>)" 
                                            class="btn btn-warning btn-sm">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display: inline;"
                                          onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette UE ?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $unit['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de modification -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2><i class="fas fa-edit"></i> Modifier l'Unité d'Enseignement</h2>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Code UE *</label>
                        <input type="text" name="code" id="edit_code" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Classe *</label>
                        <select name="class_id" id="edit_class_id" required>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Nom Complet *</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nom Court</label>
                        <input type="text" name="short_name" id="edit_short_name">
                    </div>
                    
                    <div class="form-group">
                        <label>Semestre *</label>
                        <select name="semester" id="edit_semester" required>
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>">Semestre <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Ordre d'affichage *</label>
                    <input type="number" name="display_order" id="edit_display_order" required>
                </div>

                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Enregistrer les modifications
                </button>
            </form>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function editUnit(unit) {
            document.getElementById('edit_id').value = unit.id;
            document.getElementById('edit_code').value = unit.code;
            document.getElementById('edit_name').value = unit.name;
            document.getElementById('edit_short_name').value = unit.short_name || '';
            document.getElementById('edit_class_id').value = unit.class_id;
            document.getElementById('edit_semester').value = unit.semester;
            document.getElementById('edit_display_order').value = unit.display_order;
            
            document.getElementById('editModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Fermer le modal en cliquant en dehors
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
