<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$current_admin_id = $_SESSION['user_id'];
$error_message = "";
$success_message = "";

// Créer le dossier uploads pour les annonces visuelles
$upload_dir = "../uploads/visual_announcements/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Fonction pour uploader une image
function uploadAnnouncementImage($file) {
    global $upload_dir;
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Erreur lors de l\'upload.'];
    }
    
    // Vérifier le type MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Type de fichier non autorisé.'];
    }
    
    // Vérifier la taille
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'Fichier trop volumineux (max 5MB).'];
    }
    
    // Générer un nom unique
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'visual_' . time() . '_' . uniqid() . '.' . $extension;
    $destination = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => true, 'filename' => $filename, 'path' => 'uploads/visual_announcements/' . $filename];
    }
    
    return ['success' => false, 'message' => 'Erreur lors du déplacement du fichier.'];
}

// AJOUTER UNE ANNONCE VISUELLE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_visual_announcement'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $visual_style = $_POST['visual_style'];
    $announcement_type = $_POST['announcement_type'];
    $target_roles = isset($_POST['target_roles']) ? implode(',', $_POST['target_roles']) : 'all';
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $priority = intval($_POST['priority']);
    $bg_color = $_POST['bg_color'] ?? '#051e34';
    $text_color = $_POST['text_color'] ?? '#ffffff';
    $cta_text = $_POST['cta_text'] ?? '';
    $cta_link = $_POST['cta_link'] ?? '';
    $class_id = !empty($_POST['class_id']) ? $_POST['class_id'] : null;
    
    $image_url = null;
    
    // Traitement de l'image
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_result = uploadAnnouncementImage($_FILES['image']);
        if ($upload_result['success']) {
            $image_url = $upload_result['path'];
        } else {
            $error_message = "Erreur d'upload d'image : " . $upload_result['message'];
        }
    }
    
    if (empty($title) || empty($content)) {
        $error_message = "Veuillez remplir tous les champs requis.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO visual_announcements 
            (title, content, image_url, visual_style, announcement_type, 
             target_roles, start_date, end_date, priority, bg_color, 
             text_color, cta_text, cta_link, class_id, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        
        $stmt->bind_param("sssssssssissssi", 
            $title, $content, $image_url, $visual_style, $announcement_type,
            $target_roles, $start_date, $end_date, $priority, $bg_color,
            $text_color, $cta_text, $cta_link, $class_id
        );
        
        if ($stmt->execute()) {
            $announcement_id = $stmt->insert_id;
            $success_message = "✅ Annonce visuelle créée avec succès !";
            
            // Log admin action
            log_admin_action(
                $conn,
                $current_admin_id,
                'add_visual_announcement',
                "Création d'annonce visuelle: $title",
                $announcement_id,
                'visual_announcement',
                null,
                null,
                json_encode([
                    'title' => $title,
                    'visual_style' => $visual_style,
                    'announcement_type' => $announcement_type
                ])
            );
        } else {
            $error_message = "❌ Erreur lors de la création : " . $stmt->error;
        }
        $stmt->close();
    }
}

// MODIFIER UNE ANNONCE VISUELLE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_visual_announcement'])) {
    $id = $_POST['id'];
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $visual_style = $_POST['visual_style'];
    $announcement_type = $_POST['announcement_type'];
    $target_roles = isset($_POST['target_roles']) ? implode(',', $_POST['target_roles']) : 'all';
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $priority = intval($_POST['priority']);
    $bg_color = $_POST['bg_color'];
    $text_color = $_POST['text_color'];
    $cta_text = $_POST['cta_text'];
    $cta_link = $_POST['cta_link'];
    $class_id = !empty($_POST['class_id']) ? $_POST['class_id'] : null;
    
    // Récupérer l'ancienne annonce pour le log
    $old_stmt = $conn->prepare("SELECT * FROM visual_announcements WHERE id = ?");
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_announcement = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();
    
    // Gestion de l'image
    $image_url = $old_announcement['image_url'];
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_result = uploadAnnouncementImage($_FILES['image']);
        if ($upload_result['success']) {
            // Supprimer l'ancienne image si elle existe
            if (!empty($image_url) && file_exists("../" . $image_url)) {
                unlink("../" . $image_url);
            }
            $image_url = $upload_result['path'];
        }
    }
    
    $stmt = $conn->prepare("
        UPDATE visual_announcements 
        SET title = ?, content = ?, image_url = ?, visual_style = ?, 
            announcement_type = ?, target_roles = ?, start_date = ?, 
            end_date = ?, priority = ?, bg_color = ?, text_color = ?,
            cta_text = ?, cta_link = ?, class_id = ?, updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->bind_param("ssssssssissssii", 
        $title, $content, $image_url, $visual_style, $announcement_type,
        $target_roles, $start_date, $end_date, $priority, $bg_color,
        $text_color, $cta_text, $cta_link, $class_id, $id
    );
    
    if ($stmt->execute()) {
        $success_message = "✅ Annonce visuelle modifiée avec succès !";
        
        // Log admin action
        log_admin_action(
            $conn,
            $current_admin_id,
            'edit_visual_announcement',
            "Modification d'annonce visuelle ID: $id",
            $id,
            'visual_announcement',
            json_encode($old_announcement),
            json_encode([
                'title' => $title,
                'visual_style' => $visual_style,
                'announcement_type' => $announcement_type
            ])
        );
    } else {
        $error_message = "❌ Erreur lors de la modification : " . $stmt->error;
    }
    $stmt->close();
}

// SUPPRIMER UNE ANNONCE VISUELLE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_visual_announcement'])) {
    $id = $_POST['id'];
    
    // Récupérer l'annonce pour le log et l'image
    $stmt = $conn->prepare("SELECT * FROM visual_announcements WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $announcement = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Supprimer l'image associée
    if (!empty($announcement['image_url']) && file_exists("../" . $announcement['image_url'])) {
        unlink("../" . $announcement['image_url']);
    }
    
    // Supprimer l'annonce
    $stmt = $conn->prepare("DELETE FROM visual_announcements WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $success_message = "✅ Annonce visuelle supprimée avec succès !";
        
        // Log admin action
        log_admin_action(
            $conn,
            $current_admin_id,
            'delete_visual_announcement',
            "Suppression d'annonce visuelle ID: $id",
            $id,
            'visual_announcement',
            json_encode($announcement),
            null
        );
    } else {
        $error_message = "❌ Erreur lors de la suppression : " . $stmt->error;
    }
    $stmt->close();
}

// BASCILLER LE STATUT ACTIF/INACTIF
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $id = $_POST['id'];
    
    $stmt = $conn->prepare("SELECT is_active FROM visual_announcements WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $new_status = $result['is_active'] ? 0 : 1;
    
    $stmt = $conn->prepare("UPDATE visual_announcements SET is_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $id);
    
    if ($stmt->execute()) {
        $status_text = $new_status ? "activée" : "désactivée";
        $success_message = "✅ Annonce visuelle $status_text avec succès !";
    }
    $stmt->close();
}

// RÉCUPÉRER TOUTES LES ANNONCES VISUELLES
$announcements_query = "
    SELECT va.*, c.name as class_name 
    FROM visual_announcements va 
    LEFT JOIN classes c ON va.class_id = c.id 
    ORDER BY va.priority DESC, va.created_at DESC
";
$announcements_result = $conn->query($announcements_query);
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
    <title>Gestion des Annonces Visuelles - Admin UV</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #051e34;
            --secondary-color: #0c2d48;
            --accent-color: #039be5;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: #333;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Header */
        .header {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 5px solid var(--accent-color);
        }
        
        .header h1 {
            color: var(--primary-color);
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header h1 i {
            color: var(--accent-color);
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 25px;
            background: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
        }
        
        /* Messages */
        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.5s;
            font-weight: 500;
        }
        
        .message.success {
            background: rgba(46, 204, 113, 0.15);
            border: 1px solid rgba(46, 204, 113, 0.3);
            color: var(--success-color);
        }
        
        .message.error {
            background: rgba(231, 76, 60, 0.15);
            border: 1px solid rgba(231, 76, 60, 0.3);
            color: var(--danger-color);
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            background: white;
            padding: 10px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        
        .tab-btn {
            flex: 1;
            padding: 15px 25px;
            background: transparent;
            border: none;
            color: #666;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .tab-btn.active {
            background: var(--accent-color);
            color: white;
            box-shadow: 0 5px 15px rgba(3, 155, 229, 0.3);
        }
        
        .tab-btn:hover:not(.active) {
            background: rgba(3, 155, 229, 0.1);
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        
        .card h2 {
            color: var(--primary-color);
            margin-bottom: 25px;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        /* Form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #444;
            font-size: 14px;
        }
        
        .form-group label i {
            color: var(--accent-color);
            margin-right: 8px;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e1e5eb;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
            background: white;
            box-shadow: 0 0 0 3px rgba(3, 155, 229, 0.1);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
            font-family: 'Poppins', sans-serif;
        }
        
        /* File Upload */
        .file-upload {
            border: 2px dashed #d1d9e6;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            transition: all 0.3s;
        }
        
        .file-upload:hover {
            border-color: var(--accent-color);
            background: rgba(3, 155, 229, 0.05);
        }
        
        .file-upload input[type="file"] {
            display: none;
        }
        
        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            cursor: pointer;
        }
        
        .file-upload-icon {
            font-size: 48px;
            color: var(--accent-color);
        }
        
        .image-preview {
            max-width: 100%;
            max-height: 200px;
            border-radius: 10px;
            margin-top: 15px;
            border: 2px solid #e1e5eb;
        }
        
        /* Checkbox Group */
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        
        .checkbox-label input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        /* Color Picker */
        .color-picker {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .color-input {
            width: 50px;
            height: 50px;
            padding: 5px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
        }
        
        /* Visual Styles */
        .visual-styles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .style-option {
            border: 3px solid transparent;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        
        .style-option:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .style-option.selected {
            border-color: var(--accent-color);
            background: rgba(3, 155, 229, 0.1);
        }
        
        .style-preview {
            width: 100%;
            height: 120px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
        }
        
        .style-type1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .style-type2 { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .style-type3 { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .style-custom { background: linear-gradient(135deg, #051e34 0%, #039be5 100%); }
        
        /* Buttons */
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--accent-color) 0%, #0277bd 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(3, 155, 229, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color) 0%, #c0392b 100%);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success-color) 0%, #27ae60 100%);
            color: white;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color) 0%, #d35400 100%);
            color: white;
        }
        
        /* Announcements Grid */
        .announcements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }
        
        .announcement-item {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transition: transform 0.3s;
        }
        
        .announcement-item:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        .announcement-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
        }
        
        .announcement-content {
            padding: 25px;
        }
        
        .announcement-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .announcement-text {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 15px;
            white-space: pre-line;
        }
        
        .announcement-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #e1e5eb;
            font-size: 12px;
            color: #888;
        }
        
        .announcement-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active { background: rgba(46, 204, 113, 0.15); color: var(--success-color); }
        .status-inactive { background: rgba(231, 76, 60, 0.15); color: var(--danger-color); }
        
        .announcement-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .announcement-actions .btn {
            flex: 1;
            padding: 10px;
            font-size: 14px;
        }
        
        /* Preview Section */
        .preview-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        
        .preview-container {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            margin-top: 20px;
        }
        
        /* Statistics */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: var(--accent-color);
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            padding: 30px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .announcements-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
        }
        
        /* Annonce visuelle styles */
        .visual-announcement {
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 20px;
            color: white;
            background-size: cover;
            background-position: center;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
        }
        
        .visual-announcement::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.4);
            z-index: 1;
        }
        
        .visual-announcement-content {
            position: relative;
            z-index: 2;
            text-align: center;
        }
        
        .visual-badge {
            display: inline-block;
            padding: 8px 20px;
            background: rgba(255,255,255,0.2);
            border-radius: 25px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .visual-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 15px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }
        
        .visual-subtitle {
            font-size: 18px;
            margin-bottom: 25px;
            opacity: 0.9;
        }
        
        .visual-cta {
            display: inline-block;
            padding: 12px 35px;
            background: white;
            color: #051e34;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 20px;
            transition: all 0.3s;
        }
        
        .visual-cta:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-images"></i>
                Gestion des Annonces Visuelles
            </h1>
            <a href="admin_dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Retour au tableau de bord
            </a>
        </div>
        
        <!-- Messages -->
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
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('create')">
                <i class="fas fa-plus-circle"></i> Créer une annonce
            </button>
            <button class="tab-btn" onclick="switchTab('manage')">
                <i class="fas fa-list"></i> Gérer les annonces
            </button>
            <button class="tab-btn" onclick="switchTab('preview')">
                <i class="fas fa-eye"></i> Aperçu
            </button>
            <button class="tab-btn" onclick="switchTab('stats')">
                <i class="fas fa-chart-bar"></i> Statistiques
            </button>
        </div>
        
        <!-- Tab 1: Créer une annonce -->
        <div id="create" class="tab-content active">
            <div class="card">
                <h2><i class="fas fa-plus-circle"></i> Créer une nouvelle annonce visuelle</h2>
                
                <!-- Preview en temps réel -->
                <div class="preview-section">
                    <h3><i class="fas fa-eye"></i> Aperçu en temps réel</h3>
                    <div class="preview-container">
                        <div class="visual-announcement" id="livePreview">
                            <div class="visual-announcement-content">
                                <div class="visual-badge" id="previewBadge">INSCRIPTIONS</div>
                                <h1 class="visual-title" id="previewTitle">Rentrée 2025-2026</h1>
                                <p class="visual-subtitle" id="previewSubtitle">INSTITUT DE FORMATION EN SANTÉ</p>
                                <p id="previewContent">Dès le 10 Juin 2025</p>
                                <a href="#" class="visual-cta" id="previewCta">En savoir plus</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Formulaire -->
                <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm()">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                    <div class="form-grid">
                        <!-- Titre et Contenu -->
                        <div class="form-group">
                            <label for="title"><i class="fas fa-heading"></i> Titre principal</label>
                            <input type="text" id="title" name="title" class="form-control" 
                                   placeholder="Ex: INSCRIPTIONS 2025-2026" 
                                   oninput="updatePreview()" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="content"><i class="fas fa-paragraph"></i> Contenu/sous-titre</label>
                            <textarea id="content" name="content" class="form-control" 
                                      placeholder="Chaque ligne sera un paragraphe séparé"
                                      oninput="updatePreview()" required></textarea>
                        </div>
                    </div>
                    
                    <!-- Style visuel -->
                    <div class="form-group">
                        <label><i class="fas fa-palette"></i> Style visuel</label>
                        <div class="visual-styles">
                            <div class="style-option" onclick="selectStyle('type1')" data-style="type1">
                                <div class="style-preview style-type1">Style 1</div>
                                <span>Style Éducation</span>
                            </div>
                            <div class="style-option" onclick="selectStyle('type2')" data-style="type2">
                                <div class="style-preview style-type2">Style 2</div>
                                <span>Style Santé</span>
                            </div>
                            <div class="style-option" onclick="selectStyle('type3')" data-style="type3">
                                <div class="style-preview style-type3">Style 3</div>
                                <span>Style Technologie</span>
                            </div>
                            <div class="style-option selected" onclick="selectStyle('custom')" data-style="custom">
                                <div class="style-preview style-custom">Personnalisé</div>
                                <span>Personnalisé</span>
                            </div>
                        </div>
                        <input type="hidden" id="visual_style" name="visual_style" value="custom">
                    </div>
                    
                    <!-- Image de fond -->
                    <div class="form-group">
                        <label><i class="fas fa-image"></i> Image de fond (optionnel)</label>
                        <div class="file-upload">
                            <input type="file" id="image" name="image" accept="image/*" 
                                   onchange="previewImage(this)">
                            <label class="file-upload-label" for="image">
                                <i class="fas fa-cloud-upload-alt file-upload-icon"></i>
                                <span id="fileLabel">Cliquez pour choisir une image</span>
                                <small>Formats acceptés: JPG, PNG, GIF, WebP (max 5MB)</small>
                            </label>
                        </div>
                        <div id="imagePreview" class="preview-container"></div>
                    </div>
                    
                    <!-- CTA -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="cta_text"><i class="fas fa-hand-pointer"></i> Texte du bouton</label>
                            <input type="text" id="cta_text" name="cta_text" class="form-control" 
                                   placeholder="Ex: En savoir plus" 
                                   oninput="updatePreview()">
                        </div>
                        
                        <div class="form-group">
                            <label for="cta_link"><i class="fas fa-link"></i> Lien du bouton</label>
                            <input type="url" id="cta_link" name="cta_link" class="form-control" 
                                   placeholder="Ex: https://www.example.com">
                        </div>
                    </div>
                    
                    <!-- Paramètres avancés -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="announcement_type"><i class="fas fa-tag"></i> Type d'annonce</label>
                            <select id="announcement_type" name="announcement_type" class="form-control" required>
                                <option value="global">Globale (tous les utilisateurs)</option>
                                <option value="class">Spécifique à une classe</option>
                                <option value="teacher">Pour les enseignants</option>
                                <option value="student">Pour les étudiants</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="classField" style="display: none;">
                            <label for="class_id"><i class="fas fa-users"></i> Classe spécifique</label>
                            <select id="class_id" name="class_id" class="form-control">
                                <option value="">Sélectionnez une classe</option>
                                <?php
                                $classes = $conn->query("SELECT id, name FROM classes ORDER BY name");
                                while ($class = $classes->fetch_assoc()) {
                                    echo "<option value='{$class['id']}'>{$class['name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Dates et Priorité -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="start_date"><i class="fas fa-calendar-plus"></i> Date de début</label>
                            <input type="datetime-local" id="start_date" name="start_date" 
                                   class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date"><i class="fas fa-calendar-minus"></i> Date de fin</label>
                            <input type="datetime-local" id="end_date" name="end_date" 
                                   class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="priority"><i class="fas fa-star"></i> Priorité (1-10)</label>
                            <input type="number" id="priority" name="priority" 
                                   class="form-control" value="1" min="1" max="10">
                        </div>
                    </div>
                    
                    <!-- Couleurs (pour style personnalisé) -->
                    <div class="form-grid" id="colorFields">
                        <div class="form-group">
                            <label><i class="fas fa-fill-drip"></i> Couleur de fond</label>
                            <div class="color-picker">
                                <input type="color" id="bg_color" name="bg_color" 
                                       value="#051e34" class="color-input"
                                       onchange="updatePreview()">
                                <input type="text" id="bg_color_text" class="form-control" 
                                       value="#051e34" readonly>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-font"></i> Couleur du texte</label>
                            <div class="color-picker">
                                <input type="color" id="text_color" name="text_color" 
                                       value="#ffffff" class="color-input"
                                       onchange="updatePreview()">
                                <input type="text" id="text_color_text" class="form-control" 
                                       value="#ffffff" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Cible -->
                    <div class="form-group">
                        <label><i class="fas fa-user-tag"></i> Cible (qui verra l'annonce)</label>
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="target_roles[]" value="student" checked>
                                <i class="fas fa-user-graduate"></i> Étudiants
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="target_roles[]" value="teacher" checked>
                                <i class="fas fa-chalkboard-teacher"></i> Enseignants
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="target_roles[]" value="admin">
                                <i class="fas fa-user-shield"></i> Administrateurs
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" name="add_visual_announcement" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Créer l'annonce visuelle
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Tab 2: Gérer les annonces -->
        <div id="manage" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-list"></i> Gestion des annonces visuelles</h2>
                
                <div class="announcements-grid">
                    <?php while ($announcement = $announcements_result->fetch_assoc()): ?>
                        <div class="announcement-item">
                            <?php if (!empty($announcement['image_url'])): ?>
                                <img src="../<?php echo htmlspecialchars($announcement['image_url']); ?>" 
                                     class="announcement-image" 
                                     alt="<?php echo htmlspecialchars($announcement['title']); ?>">
                            <?php endif; ?>
                            
                            <div class="announcement-content">
                                <h3 class="announcement-title">
                                    <?php echo htmlspecialchars($announcement['title']); ?>
                                </h3>
                                
                                <p class="announcement-text">
                                    <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                                </p>
                                
                                <div class="announcement-meta">
                                    <span class="announcement-status <?php echo $announcement['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <i class="fas fa-circle"></i>
                                        <?php echo $announcement['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <span>
                                        <?php echo date('d/m/Y', strtotime($announcement['created_at'])); ?>
                                    </span>
                                </div>
                                
                                <div class="announcement-actions">
                                    <button class="btn btn-secondary" onclick="editAnnouncement(<?php echo $announcement['id']; ?>)">
                                        <i class="fas fa-edit"></i> Modifier
                                    </button>
                                    <form method="POST" style="display: inline;"
                                          onsubmit="return confirm('Supprimer cette annonce ?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                        <input type="hidden" name="id" value="<?php echo $announcement['id']; ?>">
                                        <button type="submit" name="delete_visual_announcement" class="btn btn-danger">
                                            <i class="fas fa-trash"></i> Supprimer
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
        
        <!-- Tab 3: Aperçu -->
        <div id="preview" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-eye"></i> Aperçu des annonces actives</h2>
                
                <?php
                // Récupérer les annonces actives
                $active_query = "
                    SELECT * FROM visual_announcements 
                    WHERE is_active = 1 
                    AND start_date <= NOW() 
                    AND end_date >= NOW()
                    ORDER BY priority DESC, created_at DESC
                ";
                $active_result = $conn->query($active_query);
                
                while ($announcement = $active_result->fetch_assoc()):
                    $style = "background: linear-gradient(135deg, {$announcement['bg_color']} 0%, {$announcement['bg_color']}66 100%); color: {$announcement['text_color']};";
                    if (!empty($announcement['image_url'])) {
                        $style = "background-image: url('../{$announcement['image_url']}'); color: {$announcement['text_color']};";
                    }
                ?>
                    <div class="visual-announcement" style="<?php echo $style; ?>">
                        <div class="visual-announcement-content">
                            <div class="visual-badge">
                                <?php echo strtoupper($announcement['announcement_type']); ?>
                            </div>
                            <h1 class="visual-title"><?php echo htmlspecialchars($announcement['title']); ?></h1>
                            <p class="visual-subtitle"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                            <?php if (!empty($announcement['cta_text'])): ?>
                                <a href="<?php echo htmlspecialchars($announcement['cta_link']); ?>" 
                                   class="visual-cta" target="_blank">
                                    <?php echo htmlspecialchars($announcement['cta_text']); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
        
        <!-- Tab 4: Statistiques -->
        <div id="stats" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-chart-bar"></i> Statistiques des annonces visuelles</h2>
                
                <?php
                // Récupérer les statistiques
                $total = $conn->query("SELECT COUNT(*) as count FROM visual_announcements")->fetch_assoc()['count'];
                $active = $conn->query("SELECT COUNT(*) as count FROM visual_announcements WHERE is_active = 1")->fetch_assoc()['count'];
                $expired = $conn->query("SELECT COUNT(*) as count FROM visual_announcements WHERE end_date < NOW()")->fetch_assoc()['count'];
                $upcoming = $conn->query("SELECT COUNT(*) as count FROM visual_announcements WHERE start_date > NOW()")->fetch_assoc()['count'];
                ?>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total; ?></div>
                        <div class="stat-label">Total des annonces</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $active; ?></div>
                        <div class="stat-label">Annonces actives</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $expired; ?></div>
                        <div class="stat-label">Annonces expirées</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $upcoming; ?></div>
                        <div class="stat-label">Annonces à venir</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Modal d'édition -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <h2><i class="fas fa-edit"></i> Modifier l'annonce</h2>
                <form id="editForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                    <!-- Les champs seront remplis par JavaScript -->
                </form>
            </div>
        </div>
    </div>

    <script>
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Définir les dates par défaut
            const now = new Date();
            const startDate = now.toISOString().slice(0, 16);
            const endDate = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 16);
            
            document.getElementById('start_date').value = startDate;
            document.getElementById('end_date').value = endDate;
            
            // Gérer l'affichage conditionnel
            document.getElementById('announcement_type').addEventListener('change', function() {
                document.getElementById('classField').style.display = 
                    this.value === 'class' ? 'block' : 'none';
            });
            
            // Initialiser les couleurs
            document.getElementById('bg_color').addEventListener('input', function() {
                document.getElementById('bg_color_text').value = this.value;
                updatePreview();
            });
            
            document.getElementById('text_color').addEventListener('input', function() {
                document.getElementById('text_color_text').value = this.value;
                updatePreview();
            });
            
            // Mettre à jour l'aperçu initial
            updatePreview();
        });
        
        // Gestion des onglets
        function switchTab(tabName) {
            // Désactiver tous les onglets
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Activer l'onglet sélectionné
            document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
            document.getElementById(tabName).classList.add('active');
        }
        
        // Sélection du style
        function selectStyle(style) {
            // Désélectionner tous
            document.querySelectorAll('.style-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Sélectionner celui cliqué
            document.querySelector(`[data-style="${style}"]`).classList.add('selected');
            document.getElementById('visual_style').value = style;
            
            // Appliquer le style à l'aperçu
            const preview = document.getElementById('livePreview');
            const colorFields = document.getElementById('colorFields');
            
            switch(style) {
                case 'type1':
                    preview.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
                    preview.style.color = '#ffffff';
                    colorFields.style.display = 'none';
                    break;
                case 'type2':
                    preview.style.background = 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)';
                    preview.style.color = '#ffffff';
                    colorFields.style.display = 'none';
                    break;
                case 'type3':
                    preview.style.background = 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)';
                    preview.style.color = '#ffffff';
                    colorFields.style.display = 'none';
                    break;
                case 'custom':
                    colorFields.style.display = 'grid';
                    updatePreview();
                    break;
            }
        }
        
        // Aperçu d'image
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            const fileLabel = document.getElementById('fileLabel');
            const previewContainer = document.getElementById('livePreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    // Afficher l'aperçu miniature
                    preview.innerHTML = `
                        <img src="${e.target.result}" class="image-preview" alt="Aperçu">
                        <p style="text-align: center; margin-top: 10px; font-size: 12px;">
                            ${input.files[0].name} (${(input.files[0].size / 1024).toFixed(2)} KB)
                        </p>
                    `;
                    
                    // Mettre à jour l'aperçu principal
                    previewContainer.style.backgroundImage = `url(${e.target.result})`;
                    previewContainer.style.backgroundSize = 'cover';
                    previewContainer.style.backgroundPosition = 'center';
                    
                    fileLabel.textContent = 'Image sélectionnée';
                };
                
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Mise à jour de l'aperçu
        function updatePreview() {
            const title = document.getElementById('title').value || 'Rentrée 2025-2026';
            const content = document.getElementById('content').value || 'INSTITUT DE FORMATION EN SANTÉ\nDès le 10 Juin 2025';
            const ctaText = document.getElementById('cta_text').value || 'En savoir plus';
            const bgColor = document.getElementById('bg_color').value;
            const textColor = document.getElementById('text_color').value;
            const style = document.getElementById('visual_style').value;
            
            // Mettre à jour le texte
            document.getElementById('previewTitle').textContent = title;
            
            // Gérer le contenu multiligne
            const lines = content.split('\n');
            if (lines.length > 0) {
                document.getElementById('previewSubtitle').textContent = lines[0];
                if (lines.length > 1) {
                    document.getElementById('previewContent').innerHTML = 
                        lines.slice(1).join('<br>');
                } else {
                    document.getElementById('previewContent').innerHTML = '';
                }
            }
            
            // Mettre à jour le CTA
            document.getElementById('previewCta').textContent = ctaText || 'En savoir plus';
            document.getElementById('previewCta').style.display = 
                ctaText ? 'inline-block' : 'none';
            
            // Mettre à jour le badge selon le type
            const type = document.getElementById('announcement_type').value;
            const badge = document.getElementById('previewBadge');
            
            switch(type) {
                case 'global':
                    badge.textContent = 'IMPORTANT';
                    break;
                case 'class':
                    badge.textContent = 'CLASSE SPÉCIFIQUE';
                    break;
                case 'teacher':
                    badge.textContent = 'ENSEIGNANTS';
                    break;
                case 'student':
                    badge.textContent = 'ÉTUDIANTS';
                    break;
                default:
                    badge.textContent = 'ANNONCE';
            }
            
            // Mettre à jour les couleurs si style personnalisé
            if (style === 'custom') {
                const preview = document.getElementById('livePreview');
                if (!preview.style.backgroundImage || preview.style.backgroundImage === 'none') {
                    preview.style.background = `linear-gradient(135deg, ${bgColor} 0%, ${bgColor}66 100%)`;
                }
                preview.style.color = textColor;
            }
        }
        
        // Éditer une annonce
        function editAnnouncement(id) {
            // Récupérer les données via AJAX
            fetch(`get_visual_announcement.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    // Remplir le formulaire modal
                    const form = document.getElementById('editForm');
                    form.innerHTML = `
                        <input type="hidden" name="id" value="${data.id}">
                        
                        <div class="form-group">
                            <label>Titre</label>
                            <input type="text" name="title" value="${data.title}" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Contenu</label>
                            <textarea name="content" class="form-control" required>${data.content}</textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>Style visuel</label>
                            <select name="visual_style" class="form-control">
                                <option value="type1" ${data.visual_style === 'type1' ? 'selected' : ''}>Style 1</option>
                                <option value="type2" ${data.visual_style === 'type2' ? 'selected' : ''}>Style 2</option>
                                <option value="type3" ${data.visual_style === 'type3' ? 'selected' : ''}>Style 3</option>
                                <option value="custom" ${data.visual_style === 'custom' ? 'selected' : ''}>Personnalisé</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Type d'annonce</label>
                            <select name="announcement_type" class="form-control">
                                <option value="global" ${data.announcement_type === 'global' ? 'selected' : ''}>Globale</option>
                                <option value="class" ${data.announcement_type === 'class' ? 'selected' : ''}>Classe</option>
                                <option value="teacher" ${data.announcement_type === 'teacher' ? 'selected' : ''}>Enseignants</option>
                                <option value="student" ${data.announcement_type === 'student' ? 'selected' : ''}>Étudiants</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Date de début</label>
                            <input type="datetime-local" name="start_date" value="${data.start_date.replace(' ', 'T')}" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label>Date de fin</label>
                            <input type="datetime-local" name="end_date" value="${data.end_date.replace(' ', 'T')}" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label>Priorité</label>
                            <input type="number" name="priority" value="${data.priority}" class="form-control" min="1" max="10">
                        </div>
                        
                        <div class="form-group">
                            <label>Texte CTA</label>
                            <input type="text" name="cta_text" value="${data.cta_text || ''}" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label>Lien CTA</label>
                            <input type="url" name="cta_link" value="${data.cta_link || ''}" class="form-control">
                        </div>
                        
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" name="edit_visual_announcement" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="closeModal()">
                                <i class="fas fa-times"></i> Annuler
                            </button>
                        </div>
                    `;
                    
                    // Afficher le modal
                    document.getElementById('editModal').style.display = 'flex';
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors du chargement des données');
                });
        }
        
        // Fermer le modal
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        // Validation du formulaire
        function validateForm() {
            const title = document.getElementById('title').value.trim();
            const content = document.getElementById('content').value.trim();
            const startDate = new Date(document.getElementById('start_date').value);
            const endDate = new Date(document.getElementById('end_date').value);
            
            if (!title || !content) {
                alert('Veuillez remplir le titre et le contenu.');
                return false;
            }
            
            if (endDate <= startDate) {
                alert('La date de fin doit être après la date de début.');
                return false;
            }
            
            return true;
        }
        
        // Fermer le modal en cliquant à l'extérieur
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target === modal) {
                closeModal();
            }
        };
    </script>
</body>
</html>
<?php
$conn->close();
?>