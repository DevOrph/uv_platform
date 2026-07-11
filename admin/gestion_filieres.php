<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}
$current_admin_id = $_SESSION['user_id'];

/* ═══════════════════════════════════════════════════════════════
   INITIALISATION DU SCHÉMA
   La table filieres existe déjà (migration 012) avec les colonnes :
   id, code, name, type_diplome, duree_annees, niveau_depart, is_active, created_at
   On s'assure juste que class_transitions et les colonnes de classes existent.
═══════════════════════════════════════════════════════════════ */

// Colonnes de cheminement dans classes (idempotent via INFORMATION_SCHEMA)
foreach ([
    'filiere_id'          => 'INT NULL',
    'level_number'        => 'INT NULL',
    'semester_start'      => 'INT NULL',
    'academic_year_label' => 'VARCHAR(20) NULL',
    'next_class_id'       => 'INT NULL',
    'prev_class_id'       => 'INT NULL',
] as $col => $def) {
    $r = $conn->query("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME   = 'classes'
                         AND COLUMN_NAME  = '$col'");
    if ($r && (int)$r->fetch_assoc()['c'] === 0) {
        $conn->query("ALTER TABLE `classes` ADD COLUMN `$col` $def");
    }
}

// Table des transitions (bifurcations)
$conn->query("CREATE TABLE IF NOT EXISTS `class_transitions` (
    `id`              INT PRIMARY KEY AUTO_INCREMENT,
    `from_class_id`   INT          NOT NULL,
    `to_class_id`     INT          NOT NULL,
    `condition_label` VARCHAR(100) NULL,
    UNIQUE KEY `uq_transition` (`from_class_id`, `to_class_id`),
    FOREIGN KEY (`from_class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`to_class_id`)   REFERENCES `classes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

/* ═══════════════════════════════════════════════════════════════
   GESTION DES ACTIONS POST
═══════════════════════════════════════════════════════════════ */

$msg_ok  = '';
$msg_err = '';

function ajax_json(bool $ok, string $msg): void {
    header('Content-Type: application/json');
    echo json_encode($ok ? ['success' => true, 'message' => $msg] : ['error' => $msg]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $is_ajax = !empty($_POST['ajax']);

    switch ($action) {

        /* ── Créer / Modifier filière ── */
        case 'save_filiere': {
            $fid       = (int)($_POST['filiere_id'] ?? 0);
            $code      = trim($_POST['code'] ?? '');
            $name      = trim($_POST['name'] ?? '');
            $type      = trim($_POST['type_diplome'] ?? '');
            $duree     = max(1, (int)($_POST['duree_annees'] ?? 3));
            $niveau    = max(1, (int)($_POST['niveau_depart'] ?? 1));
            $niveauLmd = in_array($_POST['niveau_lmd'] ?? '', ['licence', 'master', 'doctorat'])
                       ? $_POST['niveau_lmd'] : 'licence';
            if (!$code || !$name) {
                if ($is_ajax) ajax_json(false, 'Code et nom sont requis.');
                $msg_err = 'Code et nom sont requis.';
                break;
            }
            if ($fid > 0) {
                $s = $conn->prepare("UPDATE filieres SET code=?, name=?, type_diplome=?, duree_annees=?, niveau_depart=?, niveau_lmd=? WHERE id=?");
                $s->bind_param('sssiisi', $code, $name, $type, $duree, $niveau, $niveauLmd, $fid);
                $label = 'Filière modifiée avec succès.';
            } else {
                $s = $conn->prepare("INSERT INTO filieres (code, name, type_diplome, duree_annees, niveau_depart, niveau_lmd) VALUES (?,?,?,?,?,?)");
                $s->bind_param('sssiis', $code, $name, $type, $duree, $niveau, $niveauLmd);
                $label = 'Filière créée avec succès.';
            }
            $s->execute(); $s->close();
            if ($is_ajax) ajax_json(true, $label);
            header('Location: gestion_filieres.php?ok=' . urlencode($label));
            exit();
        }

        /* ── Activer / Désactiver filière ── */
        case 'toggle_filiere': {
            $fid = (int)($_POST['filiere_id'] ?? 0);
            if ($fid) $conn->query("UPDATE filieres SET is_active = 1 - is_active WHERE id=$fid");
            if ($is_ajax) ajax_json(true, 'Statut mis à jour.');
            header('Location: gestion_filieres.php');
            exit();
        }

        /* ── Rattacher une classe à une filière ── */
        case 'attach_class': {
            $cid   = (int)($_POST['class_id'] ?? 0);
            $fid   = (int)($_POST['filiere_id'] ?? 0);
            $lvl   = max(1, (int)($_POST['level_number'] ?? 1));
            $sem   = max(1, (int)($_POST['semester_start'] ?? 1));
            $label = trim($_POST['academic_year_label'] ?? '');
            if (!$cid || !$fid) {
                if ($is_ajax) ajax_json(false, 'Classe et filière requises.');
                $msg_err = 'Classe et filière requises.';
                break;
            }
            $s = $conn->prepare("UPDATE classes SET filiere_id=?, level_number=?, semester_start=?, academic_year_label=? WHERE id=?");
            $s->bind_param('iiisi', $fid, $lvl, $sem, $label, $cid);
            $s->execute(); $s->close();
            if ($is_ajax) ajax_json(true, 'Classe rattachée à la filière.');
            header('Location: gestion_filieres.php');
            exit();
        }

        /* ── Définir une transition (y compris bifurcation) ── */
        case 'set_transition': {
            $from = (int)($_POST['from_class_id'] ?? 0);
            $to   = (int)($_POST['to_class_id'] ?? 0);
            $cond = trim($_POST['condition_label'] ?? '') ?: null;
            if (!$from || !$to || $from === $to) {
                if ($is_ajax) ajax_json(false, 'Sélection invalide.');
                $msg_err = 'Sélection invalide.';
                break;
            }
            $s = $conn->prepare("INSERT IGNORE INTO class_transitions (from_class_id, to_class_id, condition_label) VALUES (?,?,?)");
            $s->bind_param('iis', $from, $to, $cond);
            $s->execute(); $s->close();

            $cnt_r = $conn->query("SELECT COUNT(*) c FROM class_transitions WHERE from_class_id=$from");
            $cnt   = $cnt_r ? (int)$cnt_r->fetch_assoc()['c'] : 0;
            if ($cnt === 1) {
                $conn->query("UPDATE classes SET next_class_id=$to WHERE id=$from");
                $conn->query("UPDATE classes SET prev_class_id=$from WHERE id=$to");
            } else {
                // Bifurcation : next_class_id devient ambigu → NULL
                $conn->query("UPDATE classes SET next_class_id=NULL WHERE id=$from");
            }
            if ($is_ajax) ajax_json(true, 'Transition enregistrée.');
            header('Location: gestion_filieres.php');
            exit();
        }

        /* ── Retirer une classe du cheminement ── */
        case 'detach_class': {
            $cid = (int)($_POST['class_id'] ?? 0);
            if (!$cid) {
                if ($is_ajax) ajax_json(false, 'Classe invalide.');
                break;
            }
            $conn->query("UPDATE classes SET next_class_id=NULL WHERE next_class_id=$cid");
            $conn->query("UPDATE classes SET prev_class_id=NULL WHERE prev_class_id=$cid");
            $conn->query("DELETE FROM class_transitions WHERE from_class_id=$cid OR to_class_id=$cid");
            $s = $conn->prepare("UPDATE classes SET filiere_id=NULL, level_number=NULL, semester_start=NULL,
                                  academic_year_label=NULL, next_class_id=NULL, prev_class_id=NULL WHERE id=?");
            $s->bind_param('i', $cid);
            $s->execute(); $s->close();
            if ($is_ajax) ajax_json(true, 'Classe retirée du cheminement.');
            header('Location: gestion_filieres.php');
            exit();
        }

        /* ── Supprimer une transition ── */
        case 'remove_transition': {
            $tid = (int)($_POST['transition_id'] ?? 0);
            if ($tid) {
                $r = $conn->query("SELECT from_class_id, to_class_id FROM class_transitions WHERE id=$tid");
                if ($row = $r->fetch_assoc()) {
                    $from = (int)$row['from_class_id'];
                    $to   = (int)$row['to_class_id'];
                    $conn->query("DELETE FROM class_transitions WHERE id=$tid");
                    $cnt_r = $conn->query("SELECT COUNT(*) c FROM class_transitions WHERE from_class_id=$from");
                    $cnt   = $cnt_r ? (int)$cnt_r->fetch_assoc()['c'] : 0;
                    if ($cnt === 0) {
                        $conn->query("UPDATE classes SET next_class_id=NULL WHERE id=$from");
                        $conn->query("UPDATE classes SET prev_class_id=NULL WHERE id=$to");
                    } elseif ($cnt === 1) {
                        $r2 = $conn->query("SELECT to_class_id FROM class_transitions WHERE from_class_id=$from LIMIT 1");
                        if ($last = $r2->fetch_assoc()) {
                            $conn->query("UPDATE classes SET next_class_id={$last['to_class_id']} WHERE id=$from");
                        }
                    }
                }
            }
            if ($is_ajax) ajax_json(true, 'Transition supprimée.');
            header('Location: gestion_filieres.php');
            exit();
        }
    }
}

if (isset($_GET['ok'])) $msg_ok = htmlspecialchars($_GET['ok']);

/* ═══════════════════════════════════════════════════════════════
   DONNÉES
═══════════════════════════════════════════════════════════════ */

$filieres = $conn->query("SELECT * FROM filieres ORDER BY is_active DESC, name ASC")->fetch_all(MYSQLI_ASSOC);

$cheminement = [];
foreach ($filieres as $f) {
    $s = $conn->prepare("
        SELECT c.*,
               (SELECT COUNT(*) FROM users u
                WHERE u.class_id = c.id AND u.role = 'student' AND u.blocked = 0) AS student_count
        FROM classes c
        WHERE c.filiere_id = ?
        ORDER BY c.level_number ASC, c.name ASC
    ");
    $s->bind_param('i', $f['id']);
    $s->execute();
    $classes = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();

    foreach ($classes as &$cls) {
        $t = $conn->prepare("
            SELECT ct.id, ct.condition_label,
                   c2.id AS to_id, c2.name AS to_name, c2.code AS to_code
            FROM class_transitions ct
            JOIN classes c2 ON ct.to_class_id = c2.id
            WHERE ct.from_class_id = ?
        ");
        $t->bind_param('i', $cls['id']);
        $t->execute();
        $cls['transitions'] = $t->get_result()->fetch_all(MYSQLI_ASSOC);
        $t->close();
    }
    unset($cls);
    $cheminement[$f['id']] = $classes;
}

$unattached = $conn->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM users u
            WHERE u.class_id = c.id AND u.role = 'student' AND u.blocked = 0) AS student_count
    FROM classes c
    WHERE c.filiere_id IS NULL
    ORDER BY c.name ASC
")->fetch_all(MYSQLI_ASSOC);

$all_classes_raw  = $conn->query("SELECT id, name, code, filiere_id FROM classes ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$all_classes_json = json_encode($all_classes_raw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$filieres_json    = json_encode($filieres,        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

/* ── Stats globales (vue d'ensemble) ── */
$nb_active_filieres = count(array_filter($filieres, fn($f) => $f['is_active']));

$r = $conn->query("
    SELECT COUNT(*) c FROM users u
    INNER JOIN classes cl ON u.class_id = cl.id
    INNER JOIN filieres f  ON cl.filiere_id = f.id
    WHERE u.role = 'student' AND u.blocked = 0 AND f.is_active = 1
");
$total_students_active = $r ? (int)$r->fetch_assoc()['c'] : 0;

$nb_unattached_classes = count($unattached);

$r = $conn->query("
    SELECT COUNT(*) c FROM users u
    INNER JOIN classes cl ON u.class_id = cl.id
    INNER JOIN filieres f  ON cl.filiere_id = f.id
    WHERE u.role = 'student' AND u.blocked = 0
      AND cl.next_class_id IS NULL
      AND cl.level_number < f.duree_annees
");
$students_no_path = $r ? (int)$r->fetch_assoc()['c'] : 0;

/* ── Données pour le diagramme SVG : transitions + colonnes par niveau ── */
$diag_transitions   = [];
$diag_levels        = [];
$active_filieres_arr = array_values(array_filter($filieres, fn($f) => $f['is_active']));

foreach ($active_filieres_arr as $f) {
    $fid   = (int)$f['id'];
    $trans = [];
    $byLvl = [];
    foreach ($cheminement[$fid] ?? [] as $cls) {
        $lvl = (int)($cls['level_number'] ?? 0);
        $byLvl[$lvl][] = $cls;
        foreach ($cls['transitions'] as $tr) {
            $trans[] = ['from' => (int)$cls['id'], 'to' => (int)$tr['to_id']];
        }
        // Fallback si class_transitions vide mais next_class_id renseigné
        if (empty($cls['transitions']) && !empty($cls['next_class_id'])) {
            $trans[] = ['from' => (int)$cls['id'], 'to' => (int)$cls['next_class_id']];
        }
    }
    ksort($byLvl);
    $diag_transitions[$fid] = $trans;
    $diag_levels[$fid]      = $byLvl;
}
$diag_transitions_json = json_encode($diag_transitions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Filières – Administration</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<?php include '../includes/header.php'; ?>

<style>
/* ── Variables & Reset ── */
:root {
    --primary-bg:   #051e34;
    --secondary-bg: #0c2d48;
    --accent:       #039be5;
    --accent-hover: #0288d1;
    --text:         #ffffff;
    --muted:        rgba(255,255,255,0.55);
    --border:       rgba(255,255,255,0.1);
    --success:      #2ecc71;
    --danger:       #e74c3c;
    --warning:      #f39c12;
    --card-bg:      rgba(255,255,255,0.05);
    --node-bg:      #0e3459;
    --node-border:  rgba(3,155,229,0.4);
}
*, *::before, *::after { box-sizing: border-box; }

main {
    max-width: 1400px;
    margin: 0 auto;
    padding: 24px 20px 40px;
}

/* ── Page header ── */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-header h2 {
    margin: 0;
    font-size: 1.7rem;
    color: var(--accent);
    display: flex;
    align-items: center;
    gap: 10px;
}
.breadcrumb { font-size: 0.82rem; color: var(--muted); margin-bottom: 6px; }
.breadcrumb a { color: var(--accent); text-decoration: none; }
.breadcrumb a:hover { text-decoration: underline; }

/* ── Alerts ── */
.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-success { background: rgba(46,204,113,0.2); border: 1px solid var(--success); color: var(--success); }
.alert-error   { background: rgba(231,76,60,0.2);  border: 1px solid var(--danger);  color: var(--danger); }

/* ── Section cards ── */
.section-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 28px;
}
.section-card h3 {
    margin: 0 0 20px 0;
    font-size: 1.15rem;
    color: var(--accent);
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}

/* ── Tables ── */
.data-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.data-table th {
    background: var(--secondary-bg);
    padding: 10px 14px;
    text-align: left;
    color: var(--muted);
    font-weight: 600;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.data-table td { padding: 12px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover td { background: rgba(255,255,255,0.03); }
.data-table .empty-row td { text-align: center; color: var(--muted); padding: 30px; font-style: italic; }

/* ── Badges ── */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}
.badge-active   { background: rgba(46,204,113,0.2); color: var(--success); }
.badge-inactive { background: rgba(231,76,60,0.15); color: var(--danger); }

/* ── Buttons ── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    white-space: nowrap;
}
.btn-primary   { background: var(--accent);   color: #fff; }
.btn-primary:hover   { background: var(--accent-hover); }
.btn-secondary { background: rgba(255,255,255,0.12); color: var(--text); }
.btn-secondary:hover { background: rgba(255,255,255,0.2); }
.btn-success   { background: rgba(46,204,113,0.2); color: var(--success); border: 1px solid var(--success); }
.btn-success:hover   { background: rgba(46,204,113,0.35); }
.btn-warning   { background: rgba(243,156,18,0.2); color: var(--warning); border: 1px solid var(--warning); }
.btn-warning:hover   { background: rgba(243,156,18,0.35); }
.btn-danger    { background: rgba(231,76,60,0.2); color: var(--danger); border: 1px solid var(--danger); }
.btn-danger:hover    { background: rgba(231,76,60,0.35); }
.btn-sm  { padding: 5px 11px; font-size: 0.8rem; }
.btn-xs  { padding: 2px 7px; font-size: 0.73rem; border-radius: 4px; border: none; }
.btn-actions { display: flex; gap: 6px; flex-wrap: wrap; }

/* ── Filière block ── */
.filiere-block {
    background: rgba(12,45,72,0.6);
    border: 1px solid rgba(3,155,229,0.2);
    border-radius: 10px;
    margin-bottom: 20px;
    overflow: hidden;
}
.filiere-block-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 18px;
    background: rgba(3,155,229,0.08);
    border-bottom: 1px solid rgba(3,155,229,0.2);
    flex-wrap: wrap;
}
.filiere-tag { background: var(--accent); color: #fff; font-weight: 700; font-size: 0.85rem; padding: 3px 10px; border-radius: 4px; letter-spacing: 0.05em; }
.filiere-nom { font-weight: 600; font-size: 1rem; }
.filiere-meta { color: var(--muted); font-size: 0.82rem; margin-left: auto; }

/* ── Cheminement horizontal ── */
.path-wrap { overflow-x: auto; padding: 20px 18px; }
.path-row  { display: flex; align-items: flex-start; gap: 0; min-width: max-content; }
.path-empty { color: var(--muted); font-style: italic; font-size: 0.9rem; padding: 20px 18px; display: flex; align-items: center; gap: 12px; }

/* Nœud */
.path-node {
    background: var(--node-bg);
    border: 1px solid var(--node-border);
    border-radius: 10px;
    padding: 14px;
    width: 200px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}
.node-top   { display: flex; justify-content: space-between; align-items: center; }
.node-code  { background: var(--accent); color: #fff; font-size: 0.72rem; font-weight: 700; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.05em; }
.node-level { font-size: 0.7rem; color: var(--muted); font-weight: 600; }
.node-name  { font-size: 0.82rem; font-weight: 600; line-height: 1.3; color: var(--text); }
.node-meta  { display: flex; flex-wrap: wrap; gap: 6px; font-size: 0.72rem; color: var(--muted); }
.node-meta span { display: flex; align-items: center; gap: 3px; }
.node-year  { font-size: 0.7rem; color: var(--accent); font-weight: 600; }

.node-transitions { margin-top: 4px; border-top: 1px solid var(--border); padding-top: 6px; }
.transitions-header { font-size: 0.7rem; color: var(--warning); font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; gap: 4px; }
.transition-item { display: flex; align-items: center; gap: 5px; font-size: 0.72rem; padding: 2px 0; flex-wrap: wrap; }
.trans-cond { color: var(--muted); font-size: 0.68rem; }

.node-footer-actions { display: flex; gap: 5px; margin-top: 8px; border-top: 1px solid var(--border); padding-top: 8px; flex-wrap: wrap; }

/* Flèche entre nœuds */
.path-arrow { display: flex; align-items: center; padding: 0 6px; color: var(--accent); font-size: 1.3rem; align-self: center; flex-shrink: 0; margin-top: -20px; }

/* Bouton ajouter nœud */
.btn-add-node {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 130px;
    height: 80px;
    flex-shrink: 0;
    background: transparent;
    border: 2px dashed rgba(3,155,229,0.4);
    border-radius: 10px;
    color: var(--accent);
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    align-self: center;
    margin-left: 12px;
    gap: 5px;
}
.btn-add-node i { font-size: 1.2rem; }
.btn-add-node:hover { background: rgba(3,155,229,0.1); border-color: var(--accent); }

/* ── Modals ── */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.65);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: var(--secondary-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    width: 100%;
    max-width: 500px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
    animation: modal-in 0.2s ease;
    max-height: 90vh;
    overflow-y: auto;
}
@keyframes modal-in {
    from { opacity: 0; transform: translateY(-20px); }
    to   { opacity: 1; transform: translateY(0); }
}
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 1px solid var(--border); position: sticky; top: 0; background: var(--secondary-bg); z-index: 1; }
.modal-header h3 { margin: 0; font-size: 1.05rem; color: var(--accent); }
.modal-close { background: none; border: none; color: var(--muted); font-size: 1.4rem; cursor: pointer; line-height: 1; padding: 0; }
.modal-close:hover { color: var(--text); }
.modal-form { padding: 22px; display: flex; flex-direction: column; gap: 15px; }
.modal-context { padding: 12px 22px 0; font-size: 0.88rem; color: var(--accent); font-weight: 600; }

.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group label { font-size: 0.8rem; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.form-group input,
.form-group select {
    background: rgba(255,255,255,0.07);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 9px 12px;
    color: var(--text);
    font-size: 0.9rem;
    width: 100%;
    transition: border-color 0.2s;
}
.form-group input:focus,
.form-group select:focus { outline: none; border-color: var(--accent); }
.form-group select option { background: var(--secondary-bg); }
.form-group small { color: var(--muted); font-size: 0.75rem; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-actions { display: flex; justify-content: flex-end; gap: 10px; padding-top: 8px; border-top: 1px solid var(--border); margin-top: 4px; }

.unattached-count { display: inline-flex; align-items: center; justify-content: center; background: var(--warning); color: #000; font-size: 0.7rem; font-weight: 700; width: 20px; height: 20px; border-radius: 50%; margin-left: 6px; }

@media (max-width: 640px) {
    .page-header { flex-direction: column; align-items: flex-start; }
    .form-row { grid-template-columns: 1fr; }
    .path-node { width: 170px; }
}

/* ── Vue globale : stats ── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 14px;
}
.stat-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 15px 18px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: rgba(255,255,255,0.04);
}
.stat-icon {
    font-size: 1.5rem;
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    flex-shrink: 0;
}
.stat-value { font-size: 1.9rem; font-weight: 700; line-height: 1; }
.stat-label { font-size: 0.76rem; color: var(--muted); margin-top: 3px; }
.stat-primary .stat-icon  { background: rgba(3,155,229,0.15);  color: var(--accent); }
.stat-primary .stat-value { color: var(--accent); }
.stat-success .stat-icon  { background: rgba(46,204,113,0.15); color: var(--success); }
.stat-success .stat-value { color: var(--success); }
.stat-warning .stat-icon  { background: rgba(243,156,18,0.15); color: var(--warning); }
.stat-warning .stat-value { color: var(--warning); }
.stat-danger  .stat-icon  { background: rgba(231,76,60,0.15);  color: var(--danger); }
.stat-danger  .stat-value { color: var(--danger); }
.stat-neutral .stat-icon  { background: rgba(255,255,255,0.06); color: var(--muted); }
.stat-neutral .stat-value { color: var(--text); }

/* ── Diagramme de cheminement ── */
.diagram-wrap { overflow-x: auto; padding: 4px 0 12px; }

.diagram-levels-row {
    display: flex;
    align-items: flex-start;
    gap: 0;
    min-width: max-content;
    position: relative;
    padding: 14px 20px 24px;
}

.diagram-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    flex-shrink: 0;
    width: 210px;
    position: relative;
    z-index: 1;
}

.col-level-label {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    padding: 3px 10px;
    background: rgba(255,255,255,0.05);
    border-radius: 20px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.diagram-spacer { width: 56px; flex-shrink: 0; }

.diagram-arrows-svg {
    position: absolute;
    top: 0;
    left: 0;
    pointer-events: none;
    overflow: visible;
    z-index: 0;
}

/* ── Nœud (dnode) ── */
.dnode {
    background: var(--node-bg);
    border: 2px solid var(--node-border);
    border-radius: 10px;
    padding: 12px 13px;
    width: 200px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    cursor: pointer;
    transition: border-color 0.2s, transform 0.15s, box-shadow 0.2s;
    position: relative;
    box-shadow: 0 2px 8px rgba(0,0,0,0.25);
    user-select: none;
}
.dnode:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 22px rgba(3,155,229,0.28);
    border-color: var(--accent);
}
.dnode-active             { border-color: rgba(46,204,113,0.45); }
.dnode-active:hover       { border-color: var(--success); box-shadow: 0 6px 22px rgba(46,204,113,0.22); }
.dnode-empty              { border-color: rgba(255,255,255,0.1); opacity: 0.72; }

.dnode-header { display: flex; justify-content: space-between; align-items: center; }
.dnode-code {
    background: var(--accent);
    color: #fff;
    font-size: 0.69rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 4px;
    letter-spacing: 0.05em;
}
.dnode-active .dnode-code { background: var(--success); }

.dnode-count {
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    gap: 3px;
}
.dnode-count-active {
    background: rgba(46,204,113,0.18);
    color: var(--success);
    border: 1px solid rgba(46,204,113,0.35);
}
.dnode-count-empty {
    background: rgba(255,255,255,0.07);
    color: var(--muted);
    border: 1px solid rgba(255,255,255,0.1);
}

.dnode-name { font-size: 0.83rem; font-weight: 600; line-height: 1.3; color: var(--text); }
.dnode-sems { font-size: 0.71rem; color: var(--muted); display: flex; align-items: center; gap: 4px; }
.dnode-year { font-size: 0.69rem; color: var(--accent); font-weight: 600; }

.dnode-bifurc {
    margin-top: 2px;
    padding-top: 6px;
    border-top: 1px solid var(--border);
    font-size: 0.7rem;
    color: var(--warning);
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}
.dnode-bifurc-item { display: flex; align-items: center; gap: 3px; font-size: 0.7rem; }

.dnode-actions {
    display: flex;
    gap: 5px;
    margin-top: 2px;
    padding-top: 7px;
    border-top: 1px solid var(--border);
    flex-wrap: wrap;
}
.dnode-hint {
    font-size: 0.66rem;
    color: rgba(3,155,229,0.55);
    display: flex;
    align-items: center;
    gap: 3px;
    margin-top: 1px;
}

/* Bouton Associer (fin de row) */
.btn-add-node-col {
    width: 130px;
    min-height: 88px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 2px dashed rgba(3,155,229,0.35);
    border-radius: 10px;
    color: var(--accent);
    font-size: 0.81rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    gap: 6px;
    align-self: center;
    margin-left: 8px;
}
.btn-add-node-col i { font-size: 1.25rem; }
.btn-add-node-col:hover { background: rgba(3,155,229,0.08); border-color: var(--accent); }

/* Actions dans le header filière */
.filiere-header-actions {
    display: flex;
    gap: 7px;
    margin-left: auto;
    flex-shrink: 0;
    flex-wrap: wrap;
}

/* ── Print / Export ── */
@media print {
    nav, .breadcrumb, .page-header, .section-card:not(.print-section),
    .dnode-actions, .btn-add-node-col, .filiere-header-actions,
    .filiere-block[data-print-hide="1"] { display: none !important; }

    .section-card.print-section { display: block !important; }
    .filiere-block[data-print-hide="0"] { display: block !important; }
    body, main { background: #fff !important; color: #111 !important; }
    .dnode {
        border: 1px solid #bbb !important;
        background: #f5f5f5 !important;
        color: #111 !important;
        break-inside: avoid;
    }
    .dnode-name, .dnode-sems, .dnode-year { color: #111 !important; }
    .diagram-arrows-svg path { stroke: #444 !important; }
    .filiere-block-header { background: #e8e8e8 !important; }
    .filiere-tag { background: #555 !important; }
}
</style>

<main>
    <div class="breadcrumb">
        <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a>
        &rsaquo; <a href="class_management.php">Classes</a>
        &rsaquo; Gestion des filières
    </div>

    <div class="page-header">
        <h2><i class="fas fa-sitemap"></i> Gestion des Filières</h2>
        <button class="btn btn-primary" onclick="openModal('modal-filiere'); resetFiliereForm();">
            <i class="fas fa-plus"></i> Nouvelle filière
        </button>
    </div>

    <?php if ($msg_ok): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $msg_ok ?></div>
    <?php endif; ?>
    <?php if ($msg_err): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $msg_err ?></div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════
         SECTION 0 : Vue globale
    ═══════════════════════════════════════════════ -->
    <div class="section-card">
        <h3><i class="fas fa-chart-bar"></i> Vue globale</h3>
        <div class="stats-grid">
            <div class="stat-card stat-primary">
                <div class="stat-icon"><i class="fas fa-sitemap"></i></div>
                <div>
                    <div class="stat-value"><?= $nb_active_filieres ?></div>
                    <div class="stat-label">Filières actives</div>
                </div>
            </div>
            <div class="stat-card stat-success">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div>
                    <div class="stat-value"><?= $total_students_active ?></div>
                    <div class="stat-label">Étudiants en cours</div>
                </div>
            </div>
            <div class="stat-card <?= $nb_unattached_classes > 0 ? 'stat-warning' : 'stat-neutral' ?>">
                <div class="stat-icon"><i class="fas fa-unlink"></i></div>
                <div>
                    <div class="stat-value"><?= $nb_unattached_classes ?></div>
                    <div class="stat-label">Classes sans filière</div>
                </div>
            </div>
            <div class="stat-card <?= $students_no_path > 0 ? 'stat-danger' : 'stat-neutral' ?>">
                <div class="stat-icon"><i class="fas fa-route"></i></div>
                <div>
                    <div class="stat-value"><?= $students_no_path ?></div>
                    <div class="stat-label">Étudiants sans cheminement défini</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════
         SECTION 1 : Liste des filières
    ═══════════════════════════════════════════════ -->
    <div class="section-card">
        <h3><i class="fas fa-list"></i> Filières</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Nom</th>
                    <th>Type diplôme</th>
                    <th>LMD</th>
                    <th>Durée</th>
                    <th>Niv. départ</th>
                    <th>Classes</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filieres)): ?>
                    <tr class="empty-row"><td colspan="9">Aucune filière. Cliquez sur « Nouvelle filière » pour commencer.</td></tr>
                <?php else: ?>
                    <?php foreach ($filieres as $f): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($f['code']) ?></strong></td>
                            <td><?= htmlspecialchars($f['name']) ?></td>
                            <td><?= htmlspecialchars($f['type_diplome']) ?: '<span style="color:var(--muted)">—</span>' ?></td>
                            <td>
                                <?php if (($f['niveau_lmd'] ?? 'licence') === 'master'): ?>
                                    <span class="badge" style="background:rgba(155,89,182,0.2);color:#9b59b6;border:1px solid rgba(155,89,182,0.4);font-size:0.8rem;font-weight:700;padding:2px 9px;">M</span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(52,152,219,0.2);color:#3498db;border:1px solid rgba(52,152,219,0.4);font-size:0.8rem;font-weight:700;padding:2px 9px;">L</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$f['duree_annees'] ?> an<?= $f['duree_annees'] > 1 ? 's' : '' ?></td>
                            <td>Niveau <?= (int)$f['niveau_depart'] ?></td>
                            <td><?= count($cheminement[$f['id']]) ?></td>
                            <td>
                                <span class="badge <?= $f['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                    <i class="fas fa-<?= $f['is_active'] ? 'circle' : 'pause-circle' ?>"></i>
                                    <?= $f['is_active'] ? 'Actif' : 'Inactif' ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-actions">
                                    <button class="btn btn-sm btn-secondary"
                                            onclick='openEditFiliere(<?= json_encode($f, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                        <i class="fas fa-edit"></i> Modifier
                                    </button>
                                    <button class="btn btn-sm <?= $f['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                                            onclick="toggleFiliere(<?= (int)$f['id'] ?>, this)">
                                        <i class="fas fa-<?= $f['is_active'] ? 'pause' : 'play' ?>"></i>
                                        <?= $f['is_active'] ? 'Désactiver' : 'Activer' ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ═══════════════════════════════════════════════
         SECTION 2 : Cheminement des classes (diagramme)
    ═══════════════════════════════════════════════ -->
    <div class="section-card print-section">
        <h3><i class="fas fa-project-diagram"></i> Cheminement des classes</h3>

        <?php if (empty($active_filieres_arr)): ?>
            <p style="color:var(--muted); text-align:center; padding:20px 0;">
                Aucune filière active. Activez une filière pour voir son cheminement.
            </p>
        <?php else: ?>
            <?php foreach ($active_filieres_arr as $f): ?>
                <?php $byLevel = $diag_levels[(int)$f['id']]; ?>
                <div class="filiere-block" id="filiere-block-<?= (int)$f['id'] ?>" data-print-hide="0">
                    <div class="filiere-block-header">
                        <span class="filiere-tag"><?= htmlspecialchars($f['code']) ?></span>
                        <span class="filiere-nom"><?= htmlspecialchars($f['name']) ?></span>
                        <span class="filiere-meta">
                            <?= htmlspecialchars($f['type_diplome'] ?: 'Diplôme non précisé') ?>
                            &bull; <?= (int)$f['duree_annees'] ?> an<?= $f['duree_annees'] > 1 ? 's' : '' ?>
                            &bull; <?= count($cheminement[(int)$f['id']]) ?> classe<?= count($cheminement[(int)$f['id']]) > 1 ? 's' : '' ?>
                        </span>
                        <div class="filiere-header-actions">
                            <button class="btn btn-sm btn-secondary"
                                    onclick="exportDiagram(<?= (int)$f['id'] ?>)"
                                    title="Exporter / Imprimer ce cheminement">
                                <i class="fas fa-print"></i> Exporter
                            </button>
                            <button class="btn btn-sm btn-primary"
                                    onclick="openAttachModal(<?= (int)$f['id'] ?>, '<?= addslashes($f['name']) ?>')">
                                <i class="fas fa-plus"></i> Associer
                            </button>
                        </div>
                    </div>

                    <?php if (empty($byLevel)): ?>
                        <div class="path-empty">
                            <i class="fas fa-info-circle"></i>
                            Aucune classe dans cette filière.
                            <button class="btn btn-sm btn-primary"
                                    onclick="openAttachModal(<?= (int)$f['id'] ?>, '<?= addslashes($f['name']) ?>')">
                                <i class="fas fa-plus"></i> Associer une classe
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="diagram-wrap">
                            <div class="diagram-levels-row" id="levels-<?= (int)$f['id'] ?>">

                                <?php $colIdx = 0; foreach ($byLevel as $lvl => $lvl_classes): ?>
                                    <?php if ($colIdx > 0): ?>
                                        <div class="diagram-spacer"></div>
                                    <?php endif; ?>

                                    <div class="diagram-col">
                                        <div class="col-level-label">
                                            <i class="fas fa-layer-group"></i>
                                            <?= $lvl > 0 ? 'Niveau ' . (int)$lvl : 'Non défini' ?>
                                        </div>

                                        <?php foreach ($lvl_classes as $cls): ?>
                                            <?php $isActive = (int)$cls['student_count'] > 0; ?>
                                            <div class="dnode <?= $isActive ? 'dnode-active' : 'dnode-empty' ?>"
                                                 id="dnode-<?= (int)$cls['id'] ?>"
                                                 onclick="window.location.href='passage_classe.php?filiere_id=<?= (int)$f['id'] ?>&level=<?= (int)($cls['level_number'] ?? 1) ?>'"
                                                 title="Ouvrir les passages de <?= htmlspecialchars($cls['name']) ?>">

                                                <div class="dnode-header">
                                                    <span class="dnode-code"><?= htmlspecialchars($cls['code'] ?? '—') ?></span>
                                                    <span class="dnode-count <?= $isActive ? 'dnode-count-active' : 'dnode-count-empty' ?>">
                                                        <i class="fas fa-users"></i>
                                                        <?= (int)$cls['student_count'] ?>
                                                    </span>
                                                </div>

                                                <div class="dnode-name"><?= htmlspecialchars($cls['name']) ?></div>

                                                <?php if (!empty($cls['semester_start'])): ?>
                                                    <div class="dnode-sems">
                                                        <i class="fas fa-calendar-alt"></i>
                                                        S<?= (int)$cls['semester_start'] ?> → S<?= (int)$cls['semester_start'] + 1 ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($cls['academic_year_label'])): ?>
                                                    <div class="dnode-year"><?= htmlspecialchars($cls['academic_year_label']) ?></div>
                                                <?php endif; ?>

                                                <?php if (!empty($cls['transitions'])): ?>
                                                    <div class="dnode-bifurc">
                                                        <i class="fas fa-code-branch"></i>
                                                        <?php foreach ($cls['transitions'] as $tr): ?>
                                                            <span class="dnode-bifurc-item">
                                                                → <b><?= htmlspecialchars($tr['to_code'] ?? '') ?></b>
                                                                <?php if ($tr['condition_label']): ?>
                                                                    <em style="color:var(--muted);font-size:0.65rem">(<?= htmlspecialchars($tr['condition_label']) ?>)</em>
                                                                <?php endif; ?>
                                                                <button class="btn btn-xs btn-danger"
                                                                        onclick="event.stopPropagation(); removeTransition(<?= (int)$tr['id'] ?>)"
                                                                        title="Supprimer cette transition">×</button>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="dnode-actions">
                                                    <button class="btn btn-xs btn-secondary"
                                                            onclick="event.stopPropagation(); openTransitionModal(<?= (int)$cls['id'] ?>, <?= (int)$f['id'] ?>, '<?= addslashes(htmlspecialchars($cls['name'])) ?>')">
                                                        <i class="fas fa-arrow-right"></i> Suivante
                                                    </button>
                                                    <button class="btn btn-xs btn-danger"
                                                            onclick="event.stopPropagation(); detachClass(<?= (int)$cls['id'] ?>, '<?= addslashes(htmlspecialchars($cls['name'])) ?>')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>

                                                <div class="dnode-hint">
                                                    <i class="fas fa-external-link-alt"></i> Voir les passages
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php $colIdx++; endforeach; ?>

                                <button class="btn-add-node-col"
                                        onclick="openAttachModal(<?= (int)$f['id'] ?>, '<?= addslashes($f['name']) ?>')">
                                    <i class="fas fa-plus-circle"></i>
                                    Associer
                                </button>

                                <!-- SVG en dernier : au-dessus visuellement, pointer-events: none -->
                                <svg class="diagram-arrows-svg" id="arrows-<?= (int)$f['id'] ?>"></svg>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════
         SECTION 3 : Classes non rattachées
    ═══════════════════════════════════════════════ -->
    <?php if (!empty($unattached)): ?>
    <div class="section-card">
        <h3>
            <i class="fas fa-unlink"></i> Classes non rattachées
            <span class="unattached-count"><?= count($unattached) ?></span>
        </h3>
        <table class="data-table">
            <thead>
                <tr><th>Code</th><th>Nom</th><th>Étudiants actifs</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($unattached as $cls): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($cls['code'] ?? '—') ?></strong></td>
                        <td><?= htmlspecialchars($cls['name']) ?></td>
                        <td><?= (int)$cls['student_count'] ?></td>
                        <td>
                            <button class="btn btn-sm btn-primary"
                                    onclick="openAttachModalFromClass(<?= (int)$cls['id'] ?>, '<?= addslashes(htmlspecialchars($cls['name'])) ?>')">
                                <i class="fas fa-link"></i> Rattacher à une filière
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<!-- ═══════════════════════════════════════════════════
     MODAL : Créer / Modifier filière
═══════════════════════════════════════════════════ -->
<div id="modal-filiere" class="modal-overlay" onclick="if(event.target===this)closeModal('modal-filiere')">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modal-filiere-title"><i class="fas fa-sitemap"></i> Nouvelle filière</h3>
            <button class="modal-close" onclick="closeModal('modal-filiere')">&times;</button>
        </div>
        <form method="post" class="modal-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="save_filiere">
            <input type="hidden" name="filiere_id" id="f-id" value="0">

            <div class="form-row">
                <div class="form-group">
                    <label>Code *</label>
                    <input type="text" name="code" id="f-code" placeholder="GI, GC, CP…" maxlength="10" required>
                </div>
                <div class="form-group">
                    <label>Durée (années) *</label>
                    <input type="number" name="duree_annees" id="f-duree" value="3" min="1" max="10" required>
                </div>
            </div>
            <div class="form-group">
                <label>Nom complet *</label>
                <input type="text" name="name" id="f-name" placeholder="ex : Génie Informatique" maxlength="255" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Type de diplôme</label>
                    <select name="type_diplome" id="f-type">
                        <option value="">— Sélectionner —</option>
                        <option value="LICENCE PROFESSIONNELLE">Licence Professionnelle</option>
                        <option value="LICENCE FONDAMENTALE">Licence Fondamentale</option>
                        <option value="LICENCE">Licence</option>
                        <option value="MASTER">Master</option>
                        <option value="INGÉNIEUR">Ingénieur</option>
                        <option value="BTS">BTS</option>
                        <option value="DUT">DUT</option>
                        <option value="DIPLÔME D'ÉTAT">Diplôme d'État</option>
                        <option value="CYCLE PRÉPARATOIRE">Cycle Préparatoire</option>
                        <option value="TRONC COMMUN">Tronc Commun</option>
                        <option value="DOCTORAT">Doctorat</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Niveau de départ</label>
                    <input type="number" name="niveau_depart" id="f-niveau" value="1" min="1" max="10">
                    <small>Niveau de la 1ère classe (ex : 1 pour CP, 2 pour SI/OB après PM)</small>
                </div>
            </div>
            <div class="form-group">
                <label>Niveau LMD</label>
                <select name="niveau_lmd" id="f-niveau-lmd">
                    <option value="licence">Licence / DUT / Licence Pro</option>
                    <option value="master">Master / Master Pro</option>
                    <option value="doctorat">Doctorat / PhD</option>
                </select>
                <small>Détermine le tarif horaire enseignant — Licence : 7 500 F | Master : 10 000 F | Doctorat : 12 000 F</small>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-filiere')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     MODAL : Rattacher une classe à une filière
═══════════════════════════════════════════════════ -->
<div id="modal-attach" class="modal-overlay" onclick="if(event.target===this)closeModal('modal-attach')">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-link"></i> Rattacher une classe</h3>
            <button class="modal-close" onclick="closeModal('modal-attach')">&times;</button>
        </div>
        <p class="modal-context" id="attach-context"></p>
        <form method="post" class="modal-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="attach_class">

            <div class="form-group">
                <label>Filière *</label>
                <select name="filiere_id" id="attach-filiere-select" required>
                    <option value="">— Sélectionner une filière —</option>
                    <?php foreach ($filieres as $f): ?>
                        <option value="<?= (int)$f['id'] ?>">
                            <?= htmlspecialchars($f['code']) ?> – <?= htmlspecialchars($f['name']) ?>
                            <?= !$f['is_active'] ? ' (inactif)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Classe *</label>
                <select name="class_id" id="attach-class-select" required>
                    <option value="">— Sélectionner une classe —</option>
                    <?php foreach ($all_classes_raw as $cls): ?>
                        <option value="<?= (int)$cls['id'] ?>" data-filiere="<?= (int)($cls['filiere_id'] ?? 0) ?>">
                            <?= htmlspecialchars($cls['code'] ?? '—') ?> – <?= htmlspecialchars($cls['name']) ?>
                            <?= $cls['filiere_id'] ? ' [déjà rattachée]' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Numéro de niveau *</label>
                    <input type="number" name="level_number" id="attach-level" value="1" min="1" max="10" required>
                </div>
                <div class="form-group">
                    <label>Semestre de début *</label>
                    <input type="number" name="semester_start" id="attach-semester" value="1" min="1" max="20" required>
                    <small>ex : S1, S3, S5…</small>
                </div>
            </div>
            <div class="form-group">
                <label>Label année académique</label>
                <input type="text" name="academic_year_label" id="attach-year-label"
                       placeholder="ex : Année 1, L1, 2024-2025" maxlength="20">
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-attach')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-link"></i> Rattacher</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     MODAL : Définir classe suivante (transition)
═══════════════════════════════════════════════════ -->
<div id="modal-transition" class="modal-overlay" onclick="if(event.target===this)closeModal('modal-transition')">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-arrow-right"></i> Classe suivante</h3>
            <button class="modal-close" onclick="closeModal('modal-transition')">&times;</button>
        </div>
        <p class="modal-context" id="transition-from-label"></p>
        <form method="post" class="modal-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="set_transition">
            <input type="hidden" name="from_class_id" id="transition-from-id" value="0">

            <div class="form-group">
                <label>Classe suivante *</label>
                <select name="to_class_id" id="transition-to-select" required>
                    <option value="">— Sélectionner —</option>
                </select>
            </div>
            <div class="form-group">
                <label>Condition / étiquette (optionnel)</label>
                <input type="text" name="condition_label"
                       placeholder="ex : Orientation SI, Orientation OB, Admis…" maxlength="100">
                <small>Laisser vide pour un cheminement simple. Renseigner uniquement en cas de bifurcation.</small>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-transition')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
const ALL_CLASSES       = <?= $all_classes_json ?>;
const ALL_FILIERES      = <?= $filieres_json ?>;
const DIAG_TRANSITIONS  = <?= $diag_transitions_json ?>;

/* ── Modaux ── */
function openModal(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => {
            m.classList.remove('open'); document.body.style.overflow = '';
        });
    }
});

/* ── Modal filière ── */
function resetFiliereForm() {
    document.getElementById('f-id').value          = '0';
    document.getElementById('f-code').value        = '';
    document.getElementById('f-name').value        = '';
    document.getElementById('f-type').value        = '';
    document.getElementById('f-duree').value       = '3';
    document.getElementById('f-niveau').value      = '1';
    document.getElementById('f-niveau-lmd').value  = 'licence';
    document.getElementById('modal-filiere-title').innerHTML = '<i class="fas fa-sitemap"></i> Nouvelle filière';
}

function openEditFiliere(f) {
    document.getElementById('f-id').value          = f.id;
    document.getElementById('f-code').value        = f.code;
    document.getElementById('f-name').value        = f.name;
    document.getElementById('f-type').value        = f.type_diplome;
    document.getElementById('f-duree').value       = f.duree_annees;
    document.getElementById('f-niveau').value      = f.niveau_depart;
    document.getElementById('f-niveau-lmd').value  = f.niveau_lmd || 'licence';
    document.getElementById('modal-filiere-title').innerHTML = '<i class="fas fa-edit"></i> Modifier la filière';
    openModal('modal-filiere');
}

/* ── Toggle filière (AJAX) ── */
function toggleFiliere(fid, btn) {
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'toggle_filiere');
    fd.append('filiere_id', fid);
    fd.append('ajax', '1');
    fetch('gestion_filieres.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else { alert(d.error); btn.disabled = false; } });
}

/* ── Modal rattacher (depuis filière) ── */
function openAttachModal(filiereId, filiereName) {
    document.getElementById('attach-context').textContent  = 'Filière cible : ' + filiereName;
    document.getElementById('attach-filiere-select').value = filiereId;
    document.getElementById('attach-class-select').value   = '';
    document.getElementById('attach-level').value          = '1';
    document.getElementById('attach-semester').value       = '1';
    document.getElementById('attach-year-label').value     = '';
    openModal('modal-attach');
}

/* ── Modal rattacher (depuis classe non rattachée) ── */
function openAttachModalFromClass(classId, className) {
    document.getElementById('attach-context').textContent  = 'Classe : ' + className;
    document.getElementById('attach-class-select').value   = classId;
    document.getElementById('attach-filiere-select').value = '';
    document.getElementById('attach-level').value          = '1';
    document.getElementById('attach-semester').value       = '1';
    document.getElementById('attach-year-label').value     = '';
    openModal('modal-attach');
}

/* ── Modal transition ── */
function openTransitionModal(fromClassId, filiereId, fromClassName) {
    document.getElementById('transition-from-id').value    = fromClassId;
    document.getElementById('transition-from-label').textContent = 'Depuis : ' + fromClassName;

    const sel = document.getElementById('transition-to-select');
    sel.innerHTML = '<option value="">— Sélectionner —</option>';
    ALL_CLASSES.forEach(c => {
        if (c.id == fromClassId) return;
        const opt        = document.createElement('option');
        opt.value        = c.id;
        opt.textContent  = (c.code || '—') + ' – ' + c.name + (c.filiere_id ? '' : ' [non rattachée]');
        sel.appendChild(opt);
    });
    openModal('modal-transition');
}

/* ── Retirer classe (AJAX) ── */
function detachClass(classId, className) {
    if (!confirm('Retirer « ' + className + ' » du cheminement ?\nSes transitions seront aussi supprimées.')) return;
    const fd = new FormData();
    fd.append('action',   'detach_class');
    fd.append('class_id', classId);
    fd.append('ajax',     '1');
    fetch('gestion_filieres.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.error); });
}

/* ── Supprimer transition (AJAX) ── */
function removeTransition(tid) {
    if (!confirm('Supprimer cette transition ?')) return;
    const fd = new FormData();
    fd.append('action',        'remove_transition');
    fd.append('transition_id', tid);
    fd.append('ajax',          '1');
    fetch('gestion_filieres.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.error); });
}

/* ═══════════════════════════════════════════════════
   DIAGRAMME SVG : tracé des flèches
═══════════════════════════════════════════════════ */

function drawDiagramArrows(fid) {
    const transitions = DIAG_TRANSITIONS[fid];
    if (!transitions || transitions.length === 0) return;

    const row = document.getElementById('levels-' + fid);
    const svg = document.getElementById('arrows-' + fid);
    if (!row || !svg) return;

    const rowRect = row.getBoundingClientRect();
    const svgW    = row.scrollWidth;
    const svgH    = row.scrollHeight;

    svg.setAttribute('width',   svgW);
    svg.setAttribute('height',  svgH);
    svg.setAttribute('viewBox', `0 0 ${svgW} ${svgH}`);

    svg.innerHTML = `
        <defs>
            <marker id="ah-${fid}" markerWidth="9" markerHeight="7"
                    refX="8" refY="3.5" orient="auto">
                <polygon points="0 0, 9 3.5, 0 7" fill="#039be5" fill-opacity="0.85"/>
            </marker>
            <marker id="ah-ok-${fid}" markerWidth="9" markerHeight="7"
                    refX="8" refY="3.5" orient="auto">
                <polygon points="0 0, 9 3.5, 0 7" fill="#2ecc71" fill-opacity="0.75"/>
            </marker>
        </defs>`;

    transitions.forEach(({from, to}) => {
        const fromEl = document.getElementById('dnode-' + from);
        const toEl   = document.getElementById('dnode-' + to);
        if (!fromEl || !toEl) return;

        const fr  = fromEl.getBoundingClientRect();
        const tr  = toEl.getBoundingClientRect();
        const isFromActive = fromEl.classList.contains('dnode-active');

        // Coordonnées relatives à diagram-levels-row
        const x1 = fr.right  - rowRect.left;
        const y1 = fr.top    + fr.height / 2 - rowRect.top;
        const x2 = tr.left   - rowRect.left;
        const y2 = tr.top    + tr.height / 2 - rowRect.top;

        const midX   = (x1 + x2) / 2;
        const color  = isFromActive ? '#2ecc71' : '#039be5';
        const marker = isFromActive ? `url(#ah-ok-${fid})` : `url(#ah-${fid})`;

        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d',           `M${x1},${y1} C${midX},${y1} ${midX},${y2} ${x2},${y2}`);
        path.setAttribute('stroke',      color);
        path.setAttribute('stroke-width', '2.5');
        path.setAttribute('stroke-opacity', '0.72');
        path.setAttribute('fill',        'none');
        path.setAttribute('marker-end',  marker);
        svg.appendChild(path);
    });
}

function drawAllArrows() {
    Object.keys(DIAG_TRANSITIONS).forEach(fid => drawDiagramArrows(parseInt(fid)));
}

document.addEventListener('DOMContentLoaded', () => {
    drawAllArrows();

    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(drawAllArrows, 150);
    });
});

/* ═══════════════════════════════════════════════════
   EXPORT : impression d'une filière
═══════════════════════════════════════════════════ */

function exportDiagram(filiereId) {
    // Masquer toutes les filières sauf la cible pour l'impression
    document.querySelectorAll('.filiere-block').forEach(el => {
        el.dataset.printHide = (el.id !== 'filiere-block-' + filiereId) ? '1' : '0';
    });
    window.print();
    // Restaurer après impression (délai pour laisser la boîte de dialogue s'ouvrir)
    setTimeout(() => {
        document.querySelectorAll('.filiere-block').forEach(el => {
            el.dataset.printHide = '0';
        });
    }, 800);
}
</script>
