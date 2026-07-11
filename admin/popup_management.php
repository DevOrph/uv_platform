<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$current_admin_id = $_SESSION['user_id'];
$error_message = "";
$success_message = "";

// Créer le dossier pour les images de pop-ups
$upload_dir = "../uploads/popups/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// AJOUTER UN POP-UP PUBLICITAIRE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_popup'])) {
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $target_roles = $_POST['target_roles'] ?? 'all';
    $priority = intval($_POST['priority']);
    $auto_close_duration = intval($_POST['auto_close_duration']);
    $show_once = isset($_POST['show_once_per_session']) ? 1 : 0;
    $display_type = $_POST['display_type'] ?? 'popup'; // NOUVEAU
    
    // Gérer les classes sélectionnées
    $class_id = null;
    if (isset($_POST['class_ids']) && !empty($_POST['class_ids'])) {
        $class_id = json_encode($_POST['class_ids']);
    }
    
    // Upload image (OBLIGATOIRE pour les bannières latérales)
    $image_url = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = $_FILES['image']['type'];
        
        if (in_array($file_type, $allowed_types)) {
            $filename = 'popup_' . time() . '_' . uniqid() . '.' . pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $destination = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                $image_url = 'uploads/popups/' . $filename;
            } else {
                $error_message = "❌ Erreur lors de l'upload de l'image";
            }
        } else {
            $error_message = "❌ Type de fichier non autorisé. Utilisez JPG, PNG, GIF ou WEBP";
        }
    } elseif ($display_type === 'sidebar') {
        $error_message = "❌ Une image est obligatoire pour les bannières latérales";
    }
    
    if (empty($error_message)) {
        $stmt = $conn->prepare("
            INSERT INTO popups 
            (title, message, image_url, target_roles, class_id, start_date, end_date, priority, auto_close_duration, show_once_per_session, display_type, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        
        $stmt->bind_param("sssssssiiss", 
            $title, $message, $image_url, $target_roles, $class_id, $start_date, $end_date, $priority, $auto_close_duration, $show_once, $display_type
        );
        
        if ($stmt->execute()) {
            $type_label = ($display_type === 'sidebar') ? 'Bannière latérale' : 'Pop-up publicitaire';
            $success_message = "✅ {$type_label} créé avec succès !";
        } else {
            $error_message = "❌ Erreur: " . $stmt->error;
        }
        $stmt->close();
    }
}

// SUPPRIMER UN POP-UP
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_popup'])) {
    $id = intval($_POST['id']);
    
    // Récupérer l'image avant suppression
    $stmt = $conn->prepare("SELECT image_url FROM popups WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $popup_data = $result->fetch_assoc();
    $stmt->close();
    
    // Supprimer le pop-up
    $stmt = $conn->prepare("DELETE FROM popups WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        // Supprimer l'image du serveur si elle existe
        if ($popup_data && !empty($popup_data['image_url'])) {
            $image_path = "../" . $popup_data['image_url'];
            if (file_exists($image_path)) {
                unlink($image_path);
            }
        }
        $success_message = "✅ Publicité supprimée !";
    }
    $stmt->close();
}

// TOGGLE ACTIVE/INACTIVE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $id = intval($_POST['id']);
    $stmt = $conn->prepare("UPDATE popups SET is_active = NOT is_active WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $success_message = "✅ Statut modifié !";
    }
    $stmt->close();
}

// RÉCUPÉRER UNIQUEMENT LES POP-UPS PUBLICITAIRES (IMAGES)
// Exclure les documents (ceux qui sont dans uploads/documents/)
$popups = $conn->query("
    SELECT * FROM popups 
    WHERE (image_url IS NULL OR image_url NOT LIKE '%uploads/documents/%')
    ORDER BY display_type, priority DESC, created_at DESC
");

// Récupérer toutes les classes pour le sélecteur
$all_classes = $conn->query("SELECT id, name FROM classes ORDER BY name");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
    <script>
    (function() {
        var token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!token) return;
        window.CSRF_TOKEN = token;
        var originalFetch = window.fetch;
        window.fetch = function(url, options) {
            options = options || {};
            var method = (options.method || 'GET').toUpperCase();
            if (method === 'GET') return originalFetch(url, options);
            options.headers = options.headers || {};
            if (options.headers instanceof Headers) {
                options.headers.set('X-CSRF-Token', token);
            } else {
                options.headers['X-CSRF-Token'] = token;
            }
            return originalFetch(url, options);
        };
    })();
    </script>
    <title>Gestion des Publicités - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        h1, h2 {
            color: #333;
            margin-bottom: 20px;
        }
        
        h1 {
            text-align: center;
            font-size: 2rem;
            color: #667eea;
        }
        
        .info-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .info-banner i {
            font-size: 24px;
        }
        
        .message {
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .success { 
            background: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
        
        .error { 
            background: #f8d7da; 
            color: #721c24; 
            border: 1px solid #f5c6cb;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        input, textarea, select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 12px;
        }
        
        small.highlight {
            color: #ff9500;
            font-weight: 600;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-toggle {
            padding: 8px 16px;
            font-size: 13px;
        }
        
        .btn-toggle.active {
            background: #28a745;
            color: white;
        }
        
        .btn-toggle.inactive {
            background: #6c757d;
            color: white;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .popup-image {
            max-width: 100px;
            max-height: 60px;
            object-fit: cover;
            border-radius: 4px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        
        .priority-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 14px;
            display: inline-block;
        }
        
        .display-type-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
            display: inline-block;
            white-space: nowrap;
        }
        
        .display-type-popup {
            background: #667eea;
            color: white;
        }
        
        .display-type-sidebar {
            background: #ff9500;
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            display: block;
            color: #ddd;
        }
        
        .empty-state p {
            font-size: 16px;
            margin-top: 10px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .container {
                padding: 15px;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px;
            }
            
            .info-banner {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="admin_dashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Retour au tableau de bord
        </a>
        
        <h1>📢 Gestion des Publicités</h1>
        
        <div class="info-banner">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Deux types d'affichage :</strong><br>
                <strong>🪟 Pop-up central :</strong> S'affiche au centre de l'écran avec overlay<br>
                <strong>📌 Bannière latérale :</strong> S'affiche sur les côtés de la page de connexion (rectangles fixes)
            </div>
        </div>
        
        <?php if ($error_message): ?>
            <div class="message error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="message success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <h2>➕ Créer une nouvelle publicité</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <div class="form-group">
                <label><i class="fas fa-display"></i> Type d'affichage *</label>
                <select name="display_type" required id="display-type-select">
                    <option value="popup">🪟 Pop-up central</option>
                    <option value="sidebar">📌 Bannière latérale (login)</option>
                </select>
                <small id="display-info">Apparaît au centre de l'écran avec fond semi-transparent</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-heading"></i> Titre *</label>
                <input type="text" name="title" placeholder="INSCRIPTIONS 2025-2026" required>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-align-left"></i> Message *</label>
                <textarea name="message" placeholder="INSTITUT DE FORMATION EN SANTÉ&#10;Inscriptions ouvertes dès le 10 Juin 2025" required></textarea>
                <small>Utilisez des sauts de ligne pour structurer votre message</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-image"></i> Image publicitaire <span id="image-required">*</span></label>
                <input type="file" name="image" accept="image/*" id="image-input">
                <small id="image-recommendation">JPG, PNG, GIF ou WEBP recommandé. Résolution optimale: 600x400px</small>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-users"></i> Public cible *</label>
                    <select name="target_roles" required>
                        <option value="all">Tout le monde</option>
                        <option value="student">Étudiants uniquement</option>
                        <option value="teacher">Enseignants uniquement</option>
                        <option value="admin">Administrateurs uniquement</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-chalkboard"></i> Classes spécifiques (optionnel)</label>
                    <select name="class_ids[]" multiple size="4">
                        <?php
                        mysqli_data_seek($all_classes, 0);
                        while ($class = $all_classes->fetch_assoc()) {
                            echo "<option value='{$class['id']}'>{$class['name']}</option>";
                        }
                        ?>
                    </select>
                    <small>Maintenez Ctrl (ou Cmd) pour sélectionner plusieurs classes</small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-calendar-start"></i> Date de début *</label>
                    <input type="datetime-local" name="start_date" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-calendar-end"></i> Date de fin *</label>
                    <input type="datetime-local" name="end_date" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-star"></i> Priorité *</label>
                    <input type="number" name="priority" value="5" min="1" max="10" required>
                    <small>1 = basse priorité, 10 = haute priorité (s'affiche en premier)</small>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-clock"></i> Fermeture automatique (secondes) *</label>
                    <input type="number" name="auto_close_duration" value="10" min="0" max="60" required>
                    <small>0 = pas de fermeture automatique</small>
                </div>
            </div>
            
            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" name="show_once_per_session" id="show_once" checked>
                    <label for="show_once" style="margin: 0;">Afficher une seule fois par session</label>
                </div>
                <small style="margin-left: 30px;">Si coché, la publicité ne s'affichera qu'une fois jusqu'à la fermeture du navigateur</small>
            </div>
            
            <button type="submit" name="add_popup" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Créer la publicité
            </button>
        </form>
        
        <h2 style="margin-top: 40px;">📋 Publicités existantes</h2>
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Titre</th>
                    <th>Image</th>
                    <th>Message</th>
                    <th>Période</th>
                    <th>Cible</th>
                    <th>Priorité</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($popups && $popups->num_rows > 0): ?>
                    <?php while ($popup = $popups->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <?php 
                            $type = $popup['display_type'] ?? 'popup';
                            if ($type === 'sidebar') {
                                echo '<span class="display-type-badge display-type-sidebar">📌 Bannière</span>';
                            } else {
                                echo '<span class="display-type-badge display-type-popup">🪟 Pop-up</span>';
                            }
                            ?>
                        </td>
                        <td><strong><?php echo htmlspecialchars($popup['title']); ?></strong></td>
                        <td>
                            <?php if ($popup['image_url']): ?>
                                <img src="../<?php echo htmlspecialchars($popup['image_url']); ?>" class="popup-image" alt="Image">
                            <?php else: ?>
                                <em style="color: #999;">
                                    <i class="fas fa-image" style="opacity: 0.3;"></i> Aucune image
                                </em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small style="color: #666;">
                                <?php 
                                $msg = htmlspecialchars($popup['message']);
                                echo strlen($msg) > 50 ? substr($msg, 0, 50) . '...' : $msg; 
                                ?>
                            </small>
                        </td>
                        <td>
                            <small>
                                <strong>Du:</strong> <?php echo date('d/m/Y H:i', strtotime($popup['start_date'])); ?><br>
                                <strong>Au:</strong> <?php echo date('d/m/Y H:i', strtotime($popup['end_date'])); ?>
                            </small>
                        </td>
                        <td><?php echo htmlspecialchars($popup['target_roles']); ?></td>
                        <td style="text-align: center;">
                            <span class="priority-badge">
                                <?php echo $popup['priority']; ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                <input type="hidden" name="id" value="<?php echo $popup['id']; ?>">
                                <button type="submit" name="toggle_status" 
                                        class="btn btn-toggle <?php echo $popup['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $popup['is_active'] ? '✓ Actif' : '✕ Inactif'; ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('⚠️ Êtes-vous sûr de vouloir supprimer cette publicité ?\n\nCette action est irréversible.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                <input type="hidden" name="id" value="<?php echo $popup['id']; ?>">
                                <button type="submit" name="delete_popup" class="btn btn-danger">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p><strong>Aucune publicité créée</strong></p>
                            <p style="font-size: 14px; margin-top: 5px;">Créez votre première publicité pour informer vos utilisateurs !</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #667eea;">
            <h3 style="margin-bottom: 10px; color: #667eea;">
                <i class="fas fa-lightbulb"></i> Conseils d'utilisation
            </h3>
            <ul style="color: #666; line-height: 1.8; margin-left: 20px;">
                <li><strong>Pop-up central :</strong> Idéal pour les annonces importantes nécessitant l'attention immédiate</li>
                <li><strong>Bannière latérale :</strong> Parfait pour les publicités professionnelles, sponsoring, promotions continues</li>
                <li><strong>Ordre d'affichage :</strong> Les publicités s'affichent selon leur priorité (10 → 1)</li>
                <li><strong>Dimensions recommandées :</strong> Pop-up = 600x400px | Bannière = 300x250px ou 300x600px</li>
            </ul>
        </div>
    </div>
    
    <script>
        // Gestion du changement de type d'affichage
        const displayTypeSelect = document.getElementById('display-type-select');
        const displayInfo = document.getElementById('display-info');
        const imageInput = document.getElementById('image-input');
        const imageRequired = document.getElementById('image-required');
        const imageRecommendation = document.getElementById('image-recommendation');
        
        displayTypeSelect.addEventListener('change', function() {
            if (this.value === 'sidebar') {
                displayInfo.textContent = '📌 Apparaît sur les côtés de la page de connexion (rectangles fixes)';
                displayInfo.classList.add('highlight');
                imageInput.required = true;
                imageRequired.textContent = '* (obligatoire)';
                imageRequired.style.color = '#dc3545';
                imageRecommendation.innerHTML = '<strong style="color: #ff9500;">Format recommandé: 300x250px ou 300x600px</strong> - JPG, PNG, GIF ou WEBP';
            } else {
                displayInfo.textContent = '🪟 Apparaît au centre de l\'écran avec fond semi-transparent';
                displayInfo.classList.remove('highlight');
                imageInput.required = false;
                imageRequired.textContent = '';
                imageRecommendation.textContent = 'JPG, PNG, GIF ou WEBP recommandé. Résolution optimale: 600x400px';
            }
        });
        
        // Définir les dates par défaut
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const startDate = now.toISOString().slice(0, 16);
            const endDate = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 16);
            
            const startInput = document.querySelector('input[name="start_date"]');
            const endInput = document.querySelector('input[name="end_date"]');
            
            if (startInput && !startInput.value) startInput.value = startDate;
            if (endInput && !endInput.value) endInput.value = endDate;
        });
        
        // Prévisualisation de l'image avant upload
        document.querySelector('input[name="image"]')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    // Créer ou mettre à jour la prévisualisation
                    let preview = document.getElementById('image-preview');
                    if (!preview) {
                        preview = document.createElement('div');
                        preview.id = 'image-preview';
                        preview.style.cssText = 'margin-top: 10px; padding: 10px; border: 2px dashed #667eea; border-radius: 8px; text-align: center;';
                        e.target.parentNode.appendChild(preview);
                    }
                    preview.innerHTML = `
                        <img src="${event.target.result}" style="max-width: 100%; max-height: 200px; border-radius: 4px;">
                        <p style="margin-top: 10px; color: #667eea; font-weight: 600;">
                            <i class="fas fa-check-circle"></i> Image sélectionnée
                        </p>
                    `;
                };
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>