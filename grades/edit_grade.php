<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/grade_lock.php';
require_once '../includes/super_admin.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Fonction pour vérifier les permissions d'examen
function canModifyExamGrade($conn, $user_id, $user_role) {
    if (is_super_admin($conn, $user_id)) {
        return true;
    }
    
    $query = "SELECT * FROM exam_permissions WHERE user_id = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

// GET : Récupération des détails de la note
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['id'])) {
        header('Location: grades_management.php');
        exit();
    }
    
    $grade_id = $_GET['id'];
    
    // Récupération des informations de la note avec vérification des permissions
    $query = "SELECT g.*, u.name as student_name, c.name as course_name, 
              et.name as evaluation_type_name, et.id as evaluation_type_id,
              ep.name as period_name, cl.name as class_name
              FROM grades g
              JOIN users u ON g.student_id = u.id
              JOIN courses c ON g.course_id = c.id
              JOIN evaluation_types et ON g.evaluation_type_id = et.id
              JOIN evaluation_periods ep ON g.evaluation_period_id = ep.id
              LEFT JOIN classes cl ON u.class_id = cl.id
              WHERE g.id = ?";
    
    // Vérification des droits de modification
    if ($user_role === 'teacher') {
        $query .= " AND (g.created_by = ? OR c.teacher_id = ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iss", $grade_id, $user_id, $user_id);
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $grade_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $error = 'Note introuvable ou accès non autorisé';
    } else {
        $grade = $result->fetch_assoc();
        
        // Vérifier les permissions pour les examens
        if ($grade['evaluation_type_id'] == 2 && !canModifyExamGrade($conn, $user_id, $user_role)) {
            $exam_error = "Vous n'avez pas l'autorisation de modifier les notes d'examen.";
        }
    }
    
    // Récupération des types d'évaluation
    $types_query = "SELECT id, name FROM evaluation_types ORDER BY name";
    $types_result = $conn->query($types_query);
    
    // Récupération des périodes d'évaluation
    $periods_query = "SELECT id, name FROM evaluation_periods ORDER BY start_date DESC";
    $periods_result = $conn->query($periods_query);
}

// POST : Mise à jour de la note
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id']) || !isset($data['grade']) || !isset($data['evaluation_type_id'])) {
        echo json_encode(['success' => false, 'message' => 'Données manquantes']);
        exit();
    }
    
    $grade_id = $data['id'];
    $new_grade = $data['grade'];
    $evaluation_type_id = $data['evaluation_type_id'];
    $comment = $data['comment'] ?? '';
    
    try {
        $conn->begin_transaction();
        
        // Vérifier que la note existe et les permissions
        $check_query = "SELECT g.*, c.teacher_id FROM grades g 
                       JOIN courses c ON g.course_id = c.id 
                       WHERE g.id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("i", $grade_id);
        $stmt->execute();
        $existing_grade = $stmt->get_result()->fetch_assoc();
        
        if (!$existing_grade) {
            throw new Exception('Note introuvable');
        }
        
        // Vérifier les permissions pour les enseignants
        if ($user_role === 'teacher') {
            if ($existing_grade['created_by'] !== $user_id && $existing_grade['teacher_id'] !== $user_id) {
                throw new Exception('Vous ne pouvez modifier que vos propres notes ou celles de vos cours');
            }
            // Verrou : un enseignant ne modifie plus une note trop ancienne
            if (grade_is_locked($conn, (int) $grade_id, $user_role)) {
                throw new Exception(grade_lock_message($conn));
            }
        }
        
        // Vérifier les permissions pour les examens
        if ($evaluation_type_id == 2 && !canModifyExamGrade($conn, $user_id, $user_role)) {
            throw new Exception('Vous n\'avez pas l\'autorisation de modifier les notes d\'examen');
        }
        
        // Enregistrement de l'ancienne valeur pour l'historique
        $old_grade = $existing_grade['grade'];
        $old_type = $existing_grade['evaluation_type_id'];
        
        // Mise à jour de la note
        $update_query = "UPDATE grades 
                        SET grade = ?, 
                            evaluation_type_id = ?, 
                            comment = ?,
                            updated_at = NOW(),
                            updated_by = ?
                        WHERE id = ?";
                        
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("diisi", $new_grade, $evaluation_type_id, $comment, $user_id, $grade_id);
        $stmt->execute();
        
        // Enregistrement dans l'historique si la table existe
        try {
            $log_query = "INSERT INTO grade_history 
                         (grade_id, action, performed_by, details) 
                         VALUES (?, 'UPDATE', ?, ?)";
            $details = "Note modifiée de {$old_grade}/20 à {$new_grade}/20";
            if ($old_type != $evaluation_type_id) {
                $details .= " - Type d'évaluation modifié";
            }
            $stmt = $conn->prepare($log_query);
            $stmt->bind_param("iss", $grade_id, $user_id, $details);
            $stmt->execute();
        } catch (Exception $e) {
            // Si la table grade_history n'existe pas, continuer sans erreur
            error_log("Historique non enregistré: " . $e->getMessage());
        }
        
        $conn->commit();
        echo json_encode([
            'success' => true, 
            'message' => 'Note modifiée avec succès',
            'new_grade' => $new_grade
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Erreur modification note: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// Si on arrive ici, c'est une requête GET pour afficher le formulaire
if (isset($error)) {
    echo "<div class='error'>$error</div>";
    echo "<a href='grades_management.php'>Retour à la gestion des notes</a>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
    <script>
    (function() {
        var t = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!t) return;
        var o = window.fetch;
        window.fetch = function(u, p) {
            p = p || {};
            if ((p.method || 'GET').toUpperCase() === 'GET') return o(u, p);
            p.headers = p.headers || {};
            if (p.headers instanceof Headers) {
                p.headers.set('X-CSRF-Token', t);
            } else {
                p.headers['X-CSRF-Token'] = t;
            }
            return o(u, p);
        };
    })();
    </script>
    <title>Modifier la Note - UV</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #4CAF50;
            --error-color: #f44336;
            --warning-color: #ff9800;
        }

        body {
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            min-height: 100vh;
            font-family: 'Google Sans', Arial, sans-serif;
            color: var(--text-light);
            margin: 0;
            padding: 20px;
        }

        .edit-container {
            max-width: 800px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .edit-header {
            padding: 25px;
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .edit-header h1 {
            margin: 0;
            font-size: 1.8rem;
        }

        .student-info {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            font-weight: 600;
        }

        .info-value {
            font-size: 16px;
            color: var(--text-light);
            font-weight: 500;
        }

        .edit-form {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            font-size: 14px;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(3, 155, 229, 0.2);
        }

        .form-group select option {
            background: var(--secondary-bg);
            color: var(--text-light);
        }

        .grade-input-container {
            position: relative;
        }

        .grade-preview {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .grade-preview.grade-excellent {
            background: #4CAF50;
            color: white;
        }

        .grade-preview.grade-good {
            background: #2196F3;
            color: white;
        }

        .grade-preview.grade-average {
            background: #ff9800;
            color: white;
        }

        .grade-preview.grade-poor {
            background: #f44336;
            color: white;
        }

        .grade-preview.invalid {
            background: #9e9e9e;
            color: white;
        }

        .exam-warning {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .permission-denied {
            background: linear-gradient(135deg, var(--error-color), #d32f2f);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }

        .btn-group {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            text-align: center;
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: var(--text-light);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #666, #555);
            color: var(--text-light);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .btn:disabled {
            background: #666;
            cursor: not-allowed;
            transform: none;
            opacity: 0.6;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .alert.success {
            background: linear-gradient(135deg, var(--success-color), #45a049);
            color: white;
        }

        .alert.error {
            background: linear-gradient(135deg, var(--error-color), #d32f2f);
            color: white;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: var(--accent-color);
        }

        .loading.show {
            display: block;
        }

        @media (max-width: 768px) {
            .btn-group {
                flex-direction: column;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .edit-container {
                margin: 10px;
            }
        }

        .form-help {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.6);
            margin-top: 5px;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }
    </style>
</head>
<body>
    <div class="edit-container">
        <div class="edit-header">
            <i class="fas fa-edit"></i>
            <h1>Modifier la Note</h1>
        </div>

        <div class="student-info">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Étudiant</div>
                    <div class="info-value"><?php echo htmlspecialchars($grade['student_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Cours</div>
                    <div class="info-value"><?php echo htmlspecialchars($grade['course_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Classe</div>
                    <div class="info-value"><?php echo htmlspecialchars($grade['class_name'] ?? 'Non définie'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Période</div>
                    <div class="info-value"><?php echo htmlspecialchars($grade['period_name']); ?></div>
                </div>
            </div>
        </div>

        <div class="edit-form">
            <div id="alertContainer"></div>

            <?php if (isset($exam_error)): ?>
                <div class="permission-denied">
                    <i class="fas fa-lock" style="font-size: 24px; margin-bottom: 10px;"></i>
                    <h3>Accès Restreint</h3>
                    <p><?php echo htmlspecialchars($exam_error); ?></p>
                    <p style="margin-top: 15px; font-size: 14px;">
                        Contactez un super administrateur pour obtenir les permissions nécessaires.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($grade['evaluation_type_id'] == 2 && canModifyExamGrade($conn, $user_id, $user_role)): ?>
                <div class="exam-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Vous modifiez une note d'examen. Cette action nécessite des permissions spéciales.</span>
                </div>
            <?php endif; ?>

            <form id="editForm">
                <input type="hidden" id="gradeId" value="<?php echo $grade['id']; ?>">
                
                <div class="form-group">
                    <label for="evaluation_type">
                        <i class="fas fa-clipboard-list"></i>
                        Type d'évaluation
                    </label>
                    <select id="evaluation_type" name="evaluation_type_id" required 
                            <?php echo (isset($exam_error)) ? 'disabled' : ''; ?>>
                        <?php while ($type = $types_result->fetch_assoc()): ?>
                            <option value="<?php echo $type['id']; ?>" 
                                    <?php echo ($type['id'] == $grade['evaluation_type_id']) ? 'selected' : ''; ?>
                                    <?php echo ($type['id'] == 2 && !canModifyExamGrade($conn, $user_id, $user_role)) ? 'disabled' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                                <?php echo ($type['id'] == 2 && !canModifyExamGrade($conn, $user_id, $user_role)) ? ' (Non autorisé)' : ''; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="grade">
                        <i class="fas fa-star"></i>
                        Note (/20)
                    </label>
                    <div class="grade-input-container">
                        <input type="number" id="grade" name="grade" 
                               step="0.25" min="0" max="20" 
                               value="<?php echo $grade['grade']; ?>" 
                               required oninput="previewGrade(this.value)"
                               <?php echo (isset($exam_error)) ? 'disabled' : ''; ?>>
                        <div id="gradePreview" class="grade-preview"></div>
                    </div>
                    <div class="form-help">
                        Utilisez des incréments de 0.25 (ex: 12.25, 15.50)
                    </div>
                </div>

                <div class="form-group">
                    <label for="comment">
                        <i class="fas fa-comment"></i>
                        Commentaire
                    </label>
                    <textarea id="comment" name="comment" 
                              placeholder="Ajouter un commentaire sur cette évaluation..."
                              <?php echo (isset($exam_error)) ? 'disabled' : ''; ?>><?php echo htmlspecialchars($grade['comment'] ?? ''); ?></textarea>
                    <div class="form-help">
                        Optionnel - Ajoutez des observations sur la performance de l'étudiant
                    </div>
                </div>

                <div class="loading" id="loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Enregistrement en cours...</p>
                </div>

                <div class="btn-group">
                    <a href="javascript:history.back()" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Annuler
                    </a>
                    <button type="submit" class="btn btn-primary" id="saveBtn"
                            <?php echo (isset($exam_error)) ? 'disabled' : ''; ?>>
                        <i class="fas fa-save"></i>
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Variables globales
        const canModifyExam = <?php echo canModifyExamGrade($conn, $user_id, $user_role) ? 'true' : 'false'; ?>;
        const originalGrade = <?php echo $grade['grade']; ?>;
        const originalType = <?php echo $grade['evaluation_type_id']; ?>;

        function previewGrade(value) {
            const preview = document.getElementById('gradePreview');
            const numValue = parseFloat(value);
            
            if (isNaN(numValue) || numValue < 0 || numValue > 20) {
                preview.className = 'grade-preview invalid';
                preview.textContent = 'Invalide';
                return;
            }

            let gradeClass = '';
            if (numValue >= 16) gradeClass = 'grade-excellent';
            else if (numValue >= 14) gradeClass = 'grade-good';
            else if (numValue >= 10) gradeClass = 'grade-average';
            else gradeClass = 'grade-poor';

            preview.className = `grade-preview ${gradeClass}`;
            preview.textContent = `${numValue.toFixed(2)}/20`;
        }

        function showAlert(message, type = 'success') {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = `alert ${type}`;
            alert.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            `;
            container.innerHTML = '';
            container.appendChild(alert);
            
            // Auto-suppression après 5 secondes
            setTimeout(() => {
                alert.remove();
            }, 5000);
        }

        function checkExamPermission() {
            const evaluationType = document.getElementById('evaluation_type').value;
            const saveBtn = document.getElementById('saveBtn');
            
            if (evaluationType == '2' && !canModifyExam) {
                saveBtn.disabled = true;
                showAlert('Vous n\'avez pas l\'autorisation de modifier les notes d\'examen', 'error');
            } else {
                saveBtn.disabled = false;
            }
        }

        // Initialisation de la prévisualisation
        document.addEventListener('DOMContentLoaded', function() {
            previewGrade(originalGrade);
            
            // Écouter les changements de type d'évaluation
            document.getElementById('evaluation_type').addEventListener('change', checkExamPermission);
        });

        // Gestion du formulaire
        document.getElementById('editForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const gradeId = document.getElementById('gradeId').value;
            const grade = document.getElementById('grade').value;
            const evaluationType = document.getElementById('evaluation_type').value;
            const comment = document.getElementById('comment').value;
            const loading = document.getElementById('loading');
            const saveBtn = document.getElementById('saveBtn');
            
            // Validation
            if (!grade || grade < 0 || grade > 20) {
                showAlert('Veuillez entrer une note valide entre 0 et 20', 'error');
                return;
            }
            
            if (evaluationType == '2' && !canModifyExam) {
                showAlert('Vous n\'avez pas l\'autorisation de modifier les notes d\'examen', 'error');
                return;
            }
            
            // Afficher le chargement
            loading.classList.add('show');
            saveBtn.disabled = true;
            
            // Préparer les données
            const data = {
                id: gradeId,
                grade: parseFloat(grade),
                evaluation_type_id: evaluationType,
                comment: comment
            };
            
            // Envoyer la requête
            fetch('edit_grade.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                loading.classList.remove('show');
                saveBtn.disabled = false;
                
                if (data.success) {
                    showAlert('Note modifiée avec succès !', 'success');
                    
                    // Redirection après 2 secondes
                    setTimeout(() => {
                        window.location.href = 'javascript:history.back()';
                    }, 2000);
                } else {
                    showAlert(data.message || 'Erreur lors de la modification', 'error');
                }
            })
            .catch(error => {
                loading.classList.remove('show');
                saveBtn.disabled = false;
                console.error('Erreur:', error);
                showAlert('Erreur de connexion', 'error');
            });
        });

        // Confirmation avant de quitter si des modifications ont été faites
        let hasChanges = false;
        
        document.getElementById('grade').addEventListener('input', () => hasChanges = true);
        document.getElementById('evaluation_type').addEventListener('change', () => hasChanges = true);
        document.getElementById('comment').addEventListener('input', () => hasChanges = true);
        
        window.addEventListener('beforeunload', function(e) {
            if (hasChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        
        // Désactiver l'alerte lors de la soumission du formulaire
        document.getElementById('editForm').addEventListener('submit', () => {
            hasChanges = false;
        });
    </script>
</body>
</html>