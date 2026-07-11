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

// Créer le dossier pour les documents
$upload_dir = "../uploads/documents/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// AJOUTER UN DOCUMENT POP-UP
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_document_popup'])) {
    $title = trim($_POST['doc_title']);
    $description = trim($_POST['doc_description']);
    $start_date = $_POST['doc_start_date'];
    $end_date = $_POST['doc_end_date'];
    $target_roles = $_POST['doc_target_roles'] ?? 'all';
    $priority = intval($_POST['doc_priority']);
    
    // Gérer les classes sélectionnées
    $class_id = null;
    if (isset($_POST['doc_class_ids']) && !empty($_POST['doc_class_ids'])) {
        $class_id = json_encode($_POST['doc_class_ids']);
    }
    
    // Upload du document (obligatoire)
    $document_url = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'application/zip',
            'application/x-rar-compressed'
        ];
        
        $file_type = $_FILES['document']['type'];
        $file_extension = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_type, $allowed_types) || in_array($file_extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar'])) {
            $filename = 'doc_' . time() . '_' . uniqid() . '.' . $file_extension;
            $destination = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $destination)) {
                $document_url = 'uploads/documents/' . $filename;
            } else {
                $error_message = "❌ Erreur lors de l'upload du document";
            }
        } else {
            $error_message = "❌ Type de fichier non autorisé. Utilisez PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, ZIP ou RAR";
        }
    } else {
        $error_message = "❌ Le document est obligatoire";
    }
    
    if (empty($error_message) && $document_url) {
        $message = $description;
        
        $stmt = $conn->prepare("
            INSERT INTO popups 
            (title, message, image_url, target_roles, class_id, start_date, end_date, priority, auto_close_duration, show_once_per_session, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 1)
        ");
        
        $stmt->bind_param("ssssssi", 
            $title, $message, $document_url, $target_roles, $class_id, $start_date, $end_date, $priority
        );
        
        if ($stmt->execute()) {
            $success_message = "✅ Document pop-up créé avec succès !";
            
            log_admin_action(
                $conn,
                $current_admin_id,
                'add_document_popup',
                "Ajout d'un document pop-up: $title",
                $conn->insert_id,
                'popup',
                null,
                null,
                json_encode([
                    'title' => $title,
                    'document_url' => $document_url,
                    'target_roles' => $target_roles
                ])
            );
        } else {
            $error_message = "❌ Erreur: " . $stmt->error;
        }
        $stmt->close();
    }
}

// SUPPRIMER UN DOCUMENT POP-UP
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_doc_popup'])) {
    $id = intval($_POST['id']);
    
    $stmt = $conn->prepare("SELECT image_url, title FROM popups WHERE id = ? AND image_url LIKE '%uploads/documents/%'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $popup_data = $result->fetch_assoc();
    $stmt->close();
    
    $stmt = $conn->prepare("DELETE FROM popups WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        if ($popup_data && !empty($popup_data['image_url'])) {
            $file_path = "../" . $popup_data['image_url'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        $success_message = "✅ Document pop-up supprimé !";
        
        log_admin_action(
            $conn,
            $current_admin_id,
            'delete_document_popup',
            "Suppression du document pop-up: " . ($popup_data['title'] ?? 'ID ' . $id),
            $id,
            'popup',
            null,
            json_encode($popup_data),
            null
        );
    }
    $stmt->close();
}

// TOGGLE STATUT DOCUMENT
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_doc_status'])) {
    $id = intval($_POST['id']);
    $stmt = $conn->prepare("UPDATE popups SET is_active = NOT is_active WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $success_message = "✅ Statut modifié !";
    }
    $stmt->close();
}

// AJOUTER UNE ANNONCE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_announcement'])) {
    $content = trim($_POST['content']);
    $announcement_type = trim($_POST['announcement_type']);
    $class_id = !empty($_POST['class_id']) ? $_POST['class_id'] : null;

    if (empty($content) || empty($announcement_type)) {
        $error_message = "Veuillez remplir tous les champs requis.";
    } else {
        $stmt = $conn->prepare("INSERT INTO announcements (content, announcement_type, class_id) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssi", $content, $announcement_type, $class_id);
            if ($stmt->execute()) {
                $success_message = "✅ Annonce ajoutée avec succès.";
                log_admin_action(
                    $conn,
                    $current_admin_id,
                    'add_announcement',
                    "Ajout d'une annonce",
                    $conn->insert_id,
                    'announcement',
                    null,
                    null,
                    json_encode(['content' => $content, 'announcement_type' => $announcement_type, 'class_id' => $class_id])
                );
            } else {
                $error_message = "❌ Erreur lors de l'ajout de l'annonce : " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// MISE À JOUR D'UNE ANNONCE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_announcement'])) {
    $announcement_id = $_POST['announcement_id'];
    $content = trim($_POST['content']);
    $announcement_type = trim($_POST['announcement_type']);
    $class_id = !empty($_POST['class_id']) ? $_POST['class_id'] : null;

    if (empty($content) || empty($announcement_type)) {
        $error_message = "Veuillez remplir tous les champs requis.";
    } else {
        $old_stmt = $conn->prepare("SELECT * FROM announcements WHERE id = ?");
        $old_stmt->bind_param("i", $announcement_id);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();

        if ($class_id) {
            $stmt = $conn->prepare("UPDATE announcements SET content = ?, announcement_type = ?, class_id = ? WHERE id = ?");
            $stmt->bind_param("ssii", $content, $announcement_type, $class_id, $announcement_id);
        } else {
            $stmt = $conn->prepare("UPDATE announcements SET content = ?, announcement_type = ?, class_id = NULL WHERE id = ?");
            $stmt->bind_param("ssi", $content, $announcement_type, $announcement_id);
        }

        if ($stmt->execute()) {
            $success_message = "✅ Annonce modifiée avec succès.";
            log_admin_action(
                $conn,
                $current_admin_id,
                'edit_announcement',
                "Modification de l'annonce ID $announcement_id",
                $announcement_id,
                'announcement',
                null,
                json_encode($old_result),
                json_encode(['content' => $content, 'announcement_type' => $announcement_type, 'class_id' => $class_id])
            );
        } else {
            $error_message = "❌ Erreur lors de la modification : " . $stmt->error;
        }
        $stmt->close();
    }
}

// SUPPRESSION D'UNE ANNONCE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_announcement'])) {
    $announcement_id = $_POST['announcement_id'];

    $old_stmt = $conn->prepare("SELECT * FROM announcements WHERE id = ?");
    $old_stmt->bind_param("i", $announcement_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    $stmt = $conn->prepare("DELETE FROM announcements WHERE id=?");
    $stmt->bind_param("i", $announcement_id);

    if ($stmt->execute()) {
        $success_message = "✅ Annonce supprimée avec succès.";
        log_admin_action(
            $conn,
            $current_admin_id,
            'delete_announcement',
            "Suppression de l'annonce ID $announcement_id",
            $announcement_id,
            'announcement',
            null,
            json_encode($old_result),
            null
        );
    } else {
        $error_message = "❌ Erreur lors de la suppression : " . $stmt->error;
    }
    $stmt->close();
}

// Récupérer les documents pop-up
$doc_popups = $conn->query("
    SELECT * FROM popups 
    WHERE image_url LIKE '%uploads/documents/%' 
    ORDER BY priority DESC, created_at DESC
");

// Récupérer toutes les classes pour les sélecteurs
$all_classes = $conn->query("SELECT id, name FROM classes ORDER BY name");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Annonces - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../includes/admin_styles.css">
</head>
<body>
    <?php include '../includes/header_admin.php'; ?>
    
    <main>
        <h1><i class="fas fa-bullhorn"></i> Gestion des Annonces et Pop-ups</h1>

        <!-- NAVIGATION -->
        <div class="nav-buttons">
            <a href="popup_management.php" class="nav-link-btn">
                <i class="fas fa-ad"></i>
                Gérer les Pop-ups Publicitaires
            </a>
            <span class="nav-hint">
                <i class="fas fa-info-circle"></i>
                Système de pop-ups temporaires après connexion
            </span>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <!-- SECTION: DOCUMENTS POP-UP -->
        <section class="section-container">
            <h2><i class="fas fa-file-alt" style="color: #ff9500;"></i> Documents Pop-up</h2>
            
            <div class="form-container">
                <h3>📄 Publier un document en pop-up</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-heading"></i> Titre du document *</label>
                            <input type="text" name="doc_title" placeholder="Ex: Emploi du temps Semestre 2" required>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-upload"></i> Fichier document *</label>
                            <input type="file" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar" required>
                            <small>PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, ZIP ou RAR</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description</label>
                        <textarea name="doc_description" rows="3" placeholder="Description du document..."></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-users"></i> Public cible *</label>
                            <select name="doc_target_roles" required>
                                <option value="all">Tout le monde</option>
                                <option value="student">Étudiants uniquement</option>
                                <option value="teacher">Enseignants uniquement</option>
                                <option value="admin">Administrateurs uniquement</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-chalkboard"></i> Classes spécifiques</label>
                            <select name="doc_class_ids[]" multiple size="4">
                                <?php
                                mysqli_data_seek($all_classes, 0);
                                while ($class = $all_classes->fetch_assoc()) {
                                    echo "<option value='{$class['id']}'>{$class['name']}</option>";
                                }
                                ?>
                            </select>
                            <small>Ctrl+Clic pour sélection multiple</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-start"></i> Date de début *</label>
                            <input type="datetime-local" name="doc_start_date" required>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-calendar-end"></i> Date de fin *</label>
                            <input type="datetime-local" name="doc_end_date" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-star"></i> Priorité *</label>
                        <input type="number" name="doc_priority" value="5" min="1" max="10" required>
                        <small>1 = basse priorité, 10 = haute priorité</small>
                    </div>
                    
                    <button type="submit" name="add_document_popup" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Publier le document
                    </button>
                </form>
            </div>

            <!-- LISTE DES DOCUMENTS -->
            <h3>📋 Documents pop-up existants</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Titre</th>
                            <th>Document</th>
                            <th>Dates</th>
                            <th>Cible</th>
                            <th>Priorité</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($doc_popups && $doc_popups->num_rows > 0): ?>
                            <?php while ($doc = $doc_popups->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($doc['title']); ?></strong></td>
                                <td>
                                    <?php 
                                    $file_path = "../" . $doc['image_url'];
                                    $file_name = basename($file_path);
                                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                                    $file_size = file_exists($file_path) ? round(filesize($file_path) / 1024, 2) : 0;
                                    
                                    $icon_map = [
                                        'pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
                                        'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel', 
                                        'ppt' => 'fa-file-powerpoint', 'pptx' => 'fa-file-powerpoint',
                                        'txt' => 'fa-file-alt', 'zip' => 'fa-file-archive', 'rar' => 'fa-file-archive'
                                    ];
                                    
                                    $icon = $icon_map[$file_ext] ?? 'fa-file';
                                    ?>
                                    <a href="../<?php echo $doc['image_url']; ?>" target="_blank" class="doc-link">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                        <?php echo htmlspecialchars($file_name); ?>
                                        <small>(<?php echo $file_size; ?> KB)</small>
                                    </a>
                                </td>
                                <td>
                                    <small>
                                        <strong>Du:</strong> <?php echo date('d/m/Y H:i', strtotime($doc['start_date'])); ?><br>
                                        <strong>Au:</strong> <?php echo date('d/m/Y H:i', strtotime($doc['end_date'])); ?>
                                    </small>
                                </td>
                                <td><?php echo htmlspecialchars($doc['target_roles']); ?></td>
                                <td class="text-center">
                                    <span class="priority-badge"><?php echo $doc['priority']; ?></span>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                        <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                                        <button type="submit" name="toggle_doc_status" 
                                                class="btn-toggle <?php echo $doc['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $doc['is_active'] ? '✓ Actif' : '✕ Inactif'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Supprimer ce document pop-up ?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                        <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                                        <button type="submit" name="delete_doc_popup" class="btn btn-danger">
                                            <i class="fas fa-trash"></i> Supprimer
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <p>Aucun document pop-up créé</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- SECTION: ANNONCES NORMALES -->
        <section class="section-container">
            <h2><i class="fas fa-bullhorn" style="color: #039be5;"></i> Annonces défilantes</h2>
            
            <div class="form-container">
                <h3>Ajouter une nouvelle annonce</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                    <div class="form-group">
                        <label>Contenu de l'annonce *</label>
                        <textarea name="content" placeholder="Contenu de l'annonce" required></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Type d'annonce *</label>
                            <select name="announcement_type" required>
                                <option value="">Sélectionnez un type</option>
                                <option value="global">Globale</option>
                                <option value="class">Spécifique à une classe</option>
                                <option value="teacher">Pour les professeurs</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Classe (optionnel)</label>
                            <select name="class_id">
                                <option value="">Toutes les classes</option>
                                <?php
                                mysqli_data_seek($all_classes, 0);
                                while ($class = $all_classes->fetch_assoc()) {
                                    echo "<option value='{$class['id']}'>{$class['name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" name="add_announcement" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Ajouter l'Annonce
                    </button>
                </form>
            </div>

            <h3>Liste des Annonces</h3>
            <?php
            $announcements = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC");
            if ($announcements && $announcements->num_rows > 0):
            ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Contenu</th>
                            <th>Type</th>
                            <th>Classe</th>
                            <th>Date de création</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $announcements->fetch_assoc()): ?>
                        <tr>
                            <form method='POST' class='announcement-form' data-id='<?php echo $row['id']; ?>'>
                                <input type='hidden' name='csrf_token' value='<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>'>
                                <td><?php echo $row['id']; ?></td>
                                <td><textarea name='content'><?php echo htmlspecialchars($row['content']); ?></textarea></td>
                                <td>
                                    <select name='announcement_type'>
                                        <option value='global' <?php echo $row['announcement_type'] == 'global' ? 'selected' : ''; ?>>Globale</option>
                                        <option value='class' <?php echo $row['announcement_type'] == 'class' ? 'selected' : ''; ?>>Spécifique</option>
                                        <option value='teacher' <?php echo $row['announcement_type'] == 'teacher' ? 'selected' : ''; ?>>Professeurs</option>
                                    </select>
                                </td>
                                <td>
                                    <select name='class_id'>
                                        <option value=''>Aucune</option>
                                        <?php
                                        mysqli_data_seek($all_classes, 0);
                                        while ($class = $all_classes->fetch_assoc()) {
                                            $selected = ($row['class_id'] == $class['id']) ? 'selected' : '';
                                            echo "<option value='{$class['id']}' $selected>{$class['name']}</option>";
                                        }
                                        ?>
                                    </select>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></td>
                                <td class="action-buttons">
                                    <button type='button' onclick='updateAnnouncement(<?php echo $row['id']; ?>)' class="btn btn-primary">
                                        <i class="fas fa-save"></i> Enregistrer
                                    </button>
                                    <button type='button' onclick='deleteAnnouncement(<?php echo $row['id']; ?>)' class="btn btn-danger">
                                        <i class="fas fa-trash"></i> Supprimer
                                    </button>
                                </td>
                            </form>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="empty-state">Aucune annonce trouvée.</p>
            <?php endif; ?>
        </section>
    </main>

    <script>
    function updateAnnouncement(id) {
        var form = document.querySelector('.announcement-form[data-id="' + id + '"]');
        var formData = new FormData(form);
        formData.append('announcement_id', id);
        formData.append('edit_announcement', true);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            alert('Annonce mise à jour avec succès.');
            location.reload();
        })
        .catch(error => {
            alert('Erreur lors de la mise à jour.');
            console.error(error);
        });
    }

    function deleteAnnouncement(id) {
        if (confirm('Voulez-vous vraiment supprimer cette annonce ?')) {
            var formData = new FormData();
            formData.append('announcement_id', id);
            formData.append('delete_announcement', true);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                alert('Annonce supprimée avec succès.');
                location.reload();
            })
            .catch(error => {
                alert('Erreur lors de la suppression.');
                console.error(error);
            });
        }
    }

    // Définir les dates par défaut
    document.addEventListener('DOMContentLoaded', function() {
        const now = new Date();
        const startDate = now.toISOString().slice(0, 16);
        const endDate = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 16);
        
        const docStartInput = document.querySelector('input[name="doc_start_date"]');
        const docEndInput = document.querySelector('input[name="doc_end_date"]');
        
        if (docStartInput && !docStartInput.value) docStartInput.value = startDate;
        if (docEndInput && !docEndInput.value) docEndInput.value = endDate;
    });
    </script>
</body>
<?php include '../includes/footer.php'; ?>
</html>
<?php $conn->close(); ?>