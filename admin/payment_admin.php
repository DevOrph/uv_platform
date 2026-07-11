<?php
session_start();
require_once '../includes/db_connect.php';
$conn->set_charset('utf8mb4');
$conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html"); exit();
}
$admin_id = $_SESSION['user_id'];
$TARIF_DEFAULT = 7500;

// ── Filtre année académique ──────────────────────────────────────────────────
// Si l'admin change l'année via GET → sauvegarder en session
if (!empty($_GET['annee']) && preg_match('/^\d{4}-\d{4}$/', $_GET['annee'])) {
    $annee_filtre = $_GET['annee'];
    $_SESSION['annee_filtre_paiement'] = $annee_filtre;
// Sinon lire depuis la session
} elseif (!empty($_SESSION['annee_filtre_paiement'])) {
    $annee_filtre = $_SESSION['annee_filtre_paiement'];
// Sinon défaut = année courante
} else {
    $annee_filtre = ANNEE_ACADEMIQUE_COURANTE;
}
// Valeur sûre pour interpolation SQL (format validé par regex)
$annee_filtre_sql = $annee_filtre;

// Plage de dates de l'année académique : septembre → août
$annee_parts    = explode('-', $annee_filtre);
$annee_debut_y  = intval($annee_parts[0]);
$annee_fin_y    = intval($annee_parts[1]);
$annee_debut    = $annee_debut_y . '-09-01';
$annee_fin      = $annee_fin_y   . '-08-31';
// Ex : 2025-2026 → 2025-09-01 → 2026-08-31

// Placeholder initialisé ici ; sera peuplé depuis la BDD dans le bloc de rendu HTML
$annees_dispo = [ANNEE_ACADEMIQUE_COURANTE];

function nf($n) { return number_format(floatval($n), 0, ',', ' '); }
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
$csrf_token = generateCSRFToken();

function logAudit($conn, $action_type, $entity_type, $entity_id, $description, $old_val=null, $new_val=null, $performed_by='', $ip='') {
    try {
        $old_json = $old_val  ? json_encode($old_val,  JSON_UNESCAPED_UNICODE) : null;
        $new_json = $new_val  ? json_encode($new_val,  JSON_UNESCAPED_UNICODE) : null;
        $ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? 'N/A', 0, 255);
        $eid_s    = (string)($entity_id ?? '');
        $stmt = $conn->prepare("INSERT INTO audit_log (action_type,entity_type,entity_id,description,old_value,new_value,performed_by,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssssss", $action_type,$entity_type,$eid_s,$description,$old_json,$new_json,$performed_by,$ip,$ua);
        $stmt->execute();
    } catch(Exception $e) { error_log("Audit: ".$e->getMessage()); }
}

// ── Migrations ──────────────────────────────────────────────────────────────
$migrations = [
"CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action_type ENUM('CREATE','UPDATE','DELETE','CANCEL','PAYMENT','ASSIGN') NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(50) DEFAULT NULL,
    description TEXT NOT NULL,
    old_value JSON DEFAULT NULL,
    new_value JSON DEFAULT NULL,
    performed_by VARCHAR(255) NOT NULL,
    performed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_performed_at (performed_at),
    INDEX idx_performed_by (performed_by)
)",
"CREATE TABLE IF NOT EXISTS staff_payment_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    category ENUM('salary','bonus','allowance','social','operational','supplier') NOT NULL,
    description TEXT, is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)",
"CREATE TABLE IF NOT EXISTS staff_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    staff_id VARCHAR(255) NOT NULL,
    payment_type_id INT NOT NULL,
    amount_brut DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_retenue DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_net DECIMAL(12,2) NOT NULL DEFAULT 0,
    nb_heures DECIMAL(6,2) DEFAULT NULL,
    prix_heure DECIMAL(10,2) DEFAULT NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payment_method ENUM('bank_transfer','cash','check','mobile_money') DEFAULT 'cash',
    reference_number VARCHAR(100), description TEXT, receipt_number VARCHAR(50),
    status ENUM('pending','processed','cancelled') DEFAULT 'processed',
    processed_by VARCHAR(255) NOT NULL, notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)",
"CREATE TABLE IF NOT EXISTS operational_expenses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    expense_type VARCHAR(100) NOT NULL,
    category ENUM('equipment','maintenance','utilities','supplies','services','other') NOT NULL,
    amount DECIMAL(12,2) NOT NULL, expense_date DATE NOT NULL,
    vendor_name VARCHAR(200), invoice_number VARCHAR(100), description TEXT,
    payment_method ENUM('bank_transfer','cash','check') DEFAULT 'bank_transfer',
    reference_number VARCHAR(100), status ENUM('pending','paid','cancelled') DEFAULT 'paid',
    processed_by VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)",
"CREATE TABLE IF NOT EXISTS paiements_enseignant (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enseignant_id VARCHAR(255) NOT NULL,
    periode VARCHAR(50) NOT NULL,
    nb_heures_total DECIMAL(6,1) NOT NULL DEFAULT 0,
    prix_heure DECIMAL(10,2) NOT NULL DEFAULT 7500,
    montant_total_brut DECIMAL(12,2) NOT NULL DEFAULT 0,
    montant_retenue DECIMAL(12,2) NOT NULL DEFAULT 0,
    montant_total_net DECIMAL(12,2) NOT NULL DEFAULT 0,
    mode_echelon ENUM('100','60/40','50/50','33/33/34','libre') DEFAULT '100',
    statut ENUM('brouillon','partiel','complete','annule') DEFAULT 'brouillon',
    notes TEXT DEFAULT NULL, created_by VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)",
"CREATE TABLE IF NOT EXISTS paiement_cours_detail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paiement_id INT NOT NULL,
    course_id INT NOT NULL,
    course_name VARCHAR(255) NOT NULL,
    nb_heures_prevues DECIMAL(5,1) NOT NULL DEFAULT 0,
    nb_heures DECIMAL(5,1) NOT NULL DEFAULT 0,
    prix_heure DECIMAL(10,2) NOT NULL DEFAULT 7500,
    montant DECIMAL(12,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (paiement_id) REFERENCES paiements_enseignant(id) ON DELETE CASCADE
)",
"CREATE TABLE IF NOT EXISTS paiement_tranches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paiement_id INT NOT NULL,
    numero_tranche TINYINT NOT NULL,
    pourcentage DECIMAL(5,2) NOT NULL,
    montant DECIMAL(12,2) NOT NULL,
    date_echeance DATE NOT NULL,
    statut ENUM('pending','processed','cancelled') DEFAULT 'pending',
    payment_method ENUM('cash','mobile_money','bank_transfer','check') DEFAULT 'cash',
    date_paiement DATETIME DEFAULT NULL,
    receipt_number VARCHAR(50) DEFAULT NULL,
    processed_by VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (paiement_id) REFERENCES paiements_enseignant(id) ON DELETE CASCADE
)",
"CREATE TABLE IF NOT EXISTS versements_cours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paiement_id INT NOT NULL,
    montant DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash','mobile_money','bank_transfer','check') DEFAULT 'cash',
    receipt_number VARCHAR(50),
    date_versement DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_by VARCHAR(255),
    notes TEXT,
    FOREIGN KEY (paiement_id) REFERENCES paiements_enseignant(id) ON DELETE CASCADE
)"
];
foreach ($migrations as $sql) { try { $conn->query($sql); } catch (Exception $e) { error_log($e->getMessage()); } }

// Migration colonnes si absentes
try {
    $cols = $conn->query("SHOW COLUMNS FROM paiements_enseignant")->fetch_all(MYSQLI_ASSOC);
    $col_names = array_column($cols, 'Field');
    if (!in_array('statut', $col_names) || !str_contains(
        $conn->query("SHOW COLUMNS FROM paiements_enseignant LIKE 'statut'")->fetch_assoc()['Type'] ?? '',
        'brouillon'
    )) {
        try { $conn->query("ALTER TABLE paiements_enseignant MODIFY statut ENUM('brouillon','partiel','complete','annule') DEFAULT 'brouillon'"); } catch(Exception $e){}
    }
    // Ajouter nb_heures_prevues dans paiement_cours_detail si absent
    $cols2 = $conn->query("SHOW COLUMNS FROM paiement_cours_detail")->fetch_all(MYSQLI_ASSOC);
    $col2_names = array_column($cols2, 'Field');
    if (!in_array('nb_heures_prevues', $col2_names)) {
        $conn->query("ALTER TABLE paiement_cours_detail ADD COLUMN nb_heures_prevues DECIMAL(5,1) NOT NULL DEFAULT 0 AFTER course_name");
        $conn->query("UPDATE paiement_cours_detail SET nb_heures_prevues = nb_heures WHERE nb_heures_prevues = 0");
    }
} catch(Exception $e) { error_log($e->getMessage()); }

// Migration staff_payments colonnes
try {
    $sp_cols = $conn->query("SHOW COLUMNS FROM staff_payments")->fetch_all(MYSQLI_ASSOC);
    $sp_names = array_column($sp_cols, 'Field');
    if (!in_array('amount_brut', $sp_names)) {
        $conn->query("ALTER TABLE staff_payments ADD COLUMN amount_brut DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER payment_type_id");
        $conn->query("ALTER TABLE staff_payments ADD COLUMN amount_retenue DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_brut");
        $conn->query("ALTER TABLE staff_payments ADD COLUMN amount_net DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_retenue");
    }
    if (!in_array('nb_heures', $sp_names)) {
        $conn->query("ALTER TABLE staff_payments ADD COLUMN nb_heures DECIMAL(6,2) DEFAULT NULL AFTER amount_net");
        $conn->query("ALTER TABLE staff_payments ADD COLUMN prix_heure DECIMAL(10,2) DEFAULT NULL AFTER nb_heures");
    }
} catch(Exception $e) { error_log($e->getMessage()); }

// Migration paiements_enseignant — semestre, annee_academique, reduction
try {
    $pe2_cols  = $conn->query("SHOW COLUMNS FROM paiements_enseignant")->fetch_all(MYSQLI_ASSOC);
    $pe2_names = array_column($pe2_cols, 'Field');
    if (!in_array('semestre', $pe2_names)) {
        $conn->query("ALTER TABLE paiements_enseignant ADD COLUMN semestre ENUM('S1','S2') DEFAULT 'S1' AFTER periode");
    }
    if (!in_array('annee_academique', $pe2_names)) {
        $conn->query("ALTER TABLE paiements_enseignant ADD COLUMN annee_academique VARCHAR(9) DEFAULT '2025-2026' AFTER semestre");
    }
    if (!in_array('reduction', $pe2_names)) {
        $conn->query("ALTER TABLE paiements_enseignant ADD COLUMN reduction DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER montant_total_net");
    }
} catch(Exception $e) { error_log($e->getMessage()); }

// Migration paiements_enseignant — montant_negocie, retenue_appliquee
try {
    $pe3_cols  = $conn->query("SHOW COLUMNS FROM paiements_enseignant")->fetch_all(MYSQLI_ASSOC);
    $pe3_names = array_column($pe3_cols, 'Field');
    if (!in_array('montant_negocie', $pe3_names)) {
        $conn->query("ALTER TABLE paiements_enseignant ADD COLUMN montant_negocie DECIMAL(12,2) DEFAULT NULL AFTER reduction");
    }
    if (!in_array('retenue_appliquee', $pe3_names)) {
        $conn->query("ALTER TABLE paiements_enseignant ADD COLUMN retenue_appliquee TINYINT(1) NOT NULL DEFAULT 1 AFTER montant_negocie");
    }
} catch(Exception $e) { error_log($e->getMessage()); }

// Migration versements_cours — statut
try {
    $vc_cols = $conn->query("SHOW COLUMNS FROM versements_cours")->fetch_all(MYSQLI_ASSOC);
    $vc_names = array_column($vc_cols, 'Field');
    if (!in_array('statut', $vc_names)) {
        $conn->query("ALTER TABLE versements_cours ADD COLUMN statut ENUM('actif','annule') NOT NULL DEFAULT 'actif' AFTER notes");
    }
} catch(Exception $e) { error_log($e->getMessage()); }

// Migration tarifs_niveau + extension filieres niveau_lmd
try {
    $conn->query("CREATE TABLE IF NOT EXISTS tarifs_niveau (
        id INT AUTO_INCREMENT PRIMARY KEY,
        niveau_lmd ENUM('licence','master','doctorat') NOT NULL,
        tarif_horaire DECIMAL(10,2) NOT NULL DEFAULT 7500.00,
        description VARCHAR(255) NULL,
        updated_by VARCHAR(36) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_niveau (niveau_lmd)
    )");
    $conn->query("INSERT IGNORE INTO tarifs_niveau (niveau_lmd, tarif_horaire, description) VALUES
        ('licence',  7500.00, 'Tarif licence'),
        ('master',  10000.00, 'Tarif master'),
        ('doctorat',12000.00, 'Tarif doctorat')");
    // Étendre l'ENUM filieres.niveau_lmd pour inclure doctorat
    $fil_col = $conn->query("SHOW COLUMNS FROM filieres LIKE 'niveau_lmd'")->fetch_assoc();
    if ($fil_col && !str_contains($fil_col['Type'] ?? '', 'doctorat')) {
        $conn->query("ALTER TABLE filieres MODIFY COLUMN niveau_lmd ENUM('licence','master','doctorat') NULL");
    }
} catch(Exception $e) { error_log("Migration tarifs_niveau: ".$e->getMessage()); }

// Migration course_teacher_history — historique affectation prof/cours par année
try {
    $conn->query("CREATE TABLE IF NOT EXISTS course_teacher_history (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        course_id        INT NOT NULL,
        teacher_id       VARCHAR(36) NOT NULL,
        annee_academique VARCHAR(9) NOT NULL,
        semestre         ENUM('S1','S2','S1-S2') NOT NULL,
        created_by       VARCHAR(36) NULL,
        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_course_teacher_year_sem (course_id, annee_academique, semestre),
        INDEX idx_teacher_year (teacher_id, annee_academique),
        INDEX idx_course_year  (course_id, annee_academique)
    )");
    // Peupler uniquement si table vide (évite doublons lors des rechargements)
    $cth_cnt = $conn->query("SELECT COUNT(*) AS cnt FROM course_teacher_history")->fetch_assoc()['cnt'] ?? 1;
    if ((int)$cth_cnt === 0) {
        $annee_init = $conn->real_escape_string(ANNEE_ACADEMIQUE_COURANTE);
        // Affectation courante (courses.teacher_id)
        $conn->query("INSERT IGNORE INTO course_teacher_history
            (course_id, teacher_id, annee_academique, semestre, created_by)
            SELECT c.id, c.teacher_id, '$annee_init',
                CASE c.semester WHEN 1 THEN 'S1' WHEN 2 THEN 'S2' ELSE 'S1-S2' END,
                'MIGRATION'
            FROM courses c
            WHERE c.teacher_id IS NOT NULL AND c.teacher_id != ''");
        // Historique depuis les engagements existants
        $conn->query("INSERT IGNORE INTO course_teacher_history
            (course_id, teacher_id, annee_academique, semestre, created_by)
            SELECT DISTINCT pcd.course_id, pe.enseignant_id, pe.annee_academique, pe.semestre,
                'MIGRATION_ENGAGEMENT'
            FROM paiement_cours_detail pcd
            JOIN paiements_enseignant pe ON pe.id = pcd.paiement_id
            WHERE pcd.course_id IS NOT NULL AND pe.enseignant_id IS NOT NULL
              AND pe.annee_academique IS NOT NULL AND pe.annee_academique != ''");
    }
    // Créer le trigger seulement s'il n'existe pas
    $trig_r = $conn->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'after_course_teacher_update'");
    if ($trig_r && $trig_r->num_rows === 0) {
        $conn->query("CREATE TRIGGER after_course_teacher_update
            AFTER UPDATE ON courses FOR EACH ROW
            BEGIN
                IF (NEW.teacher_id IS NOT NULL AND NEW.teacher_id != ''
                    AND (OLD.teacher_id IS NULL OR OLD.teacher_id = ''
                         OR NEW.teacher_id != OLD.teacher_id)) THEN
                    INSERT IGNORE INTO course_teacher_history
                        (course_id, teacher_id, annee_academique, semestre, created_by)
                    VALUES (
                        NEW.id, NEW.teacher_id,
                        COALESCE((SELECT valeur FROM parametres
                                  WHERE cle = 'annee_academique_courante' LIMIT 1), ''),
                        CASE NEW.semester WHEN 1 THEN 'S1' WHEN 2 THEN 'S2' ELSE 'S1-S2' END,
                        'TRIGGER'
                    );
                END IF;
            END");
    }
} catch(Exception $e) { error_log("Migration course_teacher_history: ".$e->getMessage()); }

// ═══════════════════════════════════════════════════════════════════
// AJAX
// ═══════════════════════════════════════════════════════════════════

// ── Charger cours enseignant — niveau_lmd, tarif_horaire, engagement_existant ──
if (isset($_GET['action']) && $_GET['action'] === 'get_cours_enseignant') {
    header('Content-Type: application/json; charset=utf-8');
    $eid      = $_GET['enseignant_id'] ?? '';
    $sem_raw  = $_GET['semestre'] ?? '';          // '1','2','S1','S2' ou ''
    $annee    = $_GET['annee_academique'] ?? ANNEE_ACADEMIQUE_COURANTE;
    $excl_pid = intval($_GET['exclude_paiement_id'] ?? 0);

    // Normaliser semestre
    $sem_int = 0; $sem_str = 'S1';
    if ($sem_raw !== '') {
        if (is_numeric($sem_raw)) {
            $sem_int = intval($sem_raw);
            $sem_str = 'S' . $sem_int;
        } else {
            $sem_str = strtoupper(trim($sem_raw));
            $sem_int = intval(substr($sem_str, 1));
        }
    }

    try {
        $excl_cond    = $excl_pid > 0 ? 'AND pe.id != ?' : '';
        // Filtre semestre : accepte S1, S2 ou S1-S2 (cours couvrant les deux semestres)
        $sem_cond_cth = $sem_int  > 0 ? "AND (cth.semestre = ? OR cth.semestre = 'S1-S2')" : '';

        $sql = "
            SELECT
                c.id   AS course_id,
                c.name AS course_name,
                c.total_hours AS heures_prevues,
                COALESCE(SUM(CASE WHEN cs.session_date IS NOT NULL THEN cs.hours ELSE 0 END), 0) AS heures_effectuees,
                (
                    SELECT f.niveau_lmd
                    FROM classes cl JOIN filieres f ON f.id = cl.filiere_id
                    WHERE c.class_id IS NOT NULL AND c.class_id NOT IN ('', '[]')
                    AND JSON_CONTAINS(c.class_id, JSON_QUOTE(CAST(cl.id AS CHAR)), '\$')
                    AND cl.filiere_id IS NOT NULL
                    AND f.niveau_lmd IS NOT NULL
                    ORDER BY FIELD(f.niveau_lmd, 'licence', 'master', 'doctorat') DESC
                    LIMIT 1
                ) AS niveau_lmd,
                COALESCE((
                    SELECT tn.tarif_horaire
                    FROM classes cl JOIN filieres f ON f.id = cl.filiere_id
                    JOIN tarifs_niveau tn ON tn.niveau_lmd = f.niveau_lmd
                    WHERE c.class_id IS NOT NULL AND c.class_id NOT IN ('', '[]')
                    AND JSON_CONTAINS(c.class_id, JSON_QUOTE(CAST(cl.id AS CHAR)), '\$')
                    AND cl.filiere_id IS NOT NULL
                    ORDER BY FIELD(f.niveau_lmd, 'licence', 'master', 'doctorat') DESC
                    LIMIT 1
                ), 7500.00) AS tarif_horaire,
                (
                    SELECT JSON_OBJECT('id', pe.id, 'statut', pe.statut)
                    FROM paiements_enseignant pe
                    JOIN paiement_cours_detail pcd ON pcd.paiement_id = pe.id
                    WHERE pe.enseignant_id = ?
                    AND pcd.course_id = c.id
                    AND pe.semestre = ?
                    AND pe.annee_academique = ?
                    AND pe.statut != 'annule'
                    $excl_cond
                    LIMIT 1
                ) AS engagement_existant_json
            FROM course_teacher_history cth
            JOIN courses c ON c.id = cth.course_id
            LEFT JOIN course_sessions cs ON cs.course_id = c.id
            WHERE cth.teacher_id = ?
            AND cth.annee_academique = ?
            $sem_cond_cth
            GROUP BY c.id, c.name, c.total_hours, c.class_id
            ORDER BY c.name ASC
        ";

        $types  = 'sss';
        $params = [$eid, $sem_str, $annee];
        if ($excl_pid > 0) { $types .= 'i'; $params[] = $excl_pid; }
        $types .= 'ss'; $params[] = $eid; $params[] = $annee;
        if ($sem_int > 0) { $types .= 's'; $params[] = $sem_str; }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as &$row) {
            $row['engagement_existant'] = $row['engagement_existant_json']
                ? json_decode($row['engagement_existant_json'], true)
                : null;
            unset($row['engagement_existant_json']);
        }
        unset($row);

        echo json_encode(['success' => true, 'cours' => $rows], JSON_UNESCAPED_UNICODE);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Enregistrer engagement (brouillon) — avec protection double paiement ──────
if (isset($_GET['action']) && $_GET['action'] === 'save_engagement') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $eid               = $data['enseignant_id'] ?? '';
        $semestre          = $data['semestre'] ?? 'S1';
        $annee             = $data['annee_academique'] ?? ANNEE_ACADEMIQUE_COURANTE;
        $periode           = $semestre . ' ' . $annee;
        $cours_list        = $data['cours'] ?? [];
        $notes             = $data['notes'] ?? '';
        $pid_data          = $data['paiement_id'] ?? null;
        $force             = !empty($data['force']);   // bypass double-paiement
        $mn_raw            = $data['montant_negocie'] ?? null;
        $montant_negocie   = ($mn_raw !== null && $mn_raw !== '' && $mn_raw !== false) ? floatval($mn_raw) : null;
        $retenue_appliquee = intval($data['retenue_appliquee'] ?? 1);

        if (empty($eid) || empty($cours_list)) throw new Exception("Données manquantes.");

        $role_row   = $conn->query("SELECT role FROM users WHERE id='".addslashes($eid)."' LIMIT 1")->fetch_assoc();
        $is_teacher = ($role_row['role'] ?? 'teacher') === 'teacher';

        // ── Vérification double paiement (côté PHP) ──
        if (!$force) {
            $conflicts = [];
            foreach ($cours_list as $c) {
                $cid = intval($c['course_id']);
                $excl = $pid_data ? 'AND pe.id != '.intval($pid_data) : '';
                $chk = $conn->query("
                    SELECT pe.id, pe.statut FROM paiements_enseignant pe
                    JOIN paiement_cours_detail pcd ON pcd.paiement_id = pe.id
                    WHERE pe.enseignant_id = '".addslashes($eid)."'
                    AND pcd.course_id = $cid
                    AND pe.semestre = '".addslashes($semestre)."'
                    AND pe.annee_academique = '".addslashes($annee)."'
                    AND pe.statut != 'annule'
                    $excl
                    LIMIT 1
                ")->fetch_assoc();
                if ($chk) {
                    $conflicts[] = [
                        'course_id'     => $cid,
                        'course_name'   => $c['course_name'] ?? "Cours $cid",
                        'engagement_id' => $chk['id'],
                        'statut'        => $chk['statut'],
                    ];
                }
            }
            if (!empty($conflicts)) {
                echo json_encode(['needs_confirm' => true, 'conflicts' => $conflicts]);
                exit();
            }
        }

        // ── Calcul des montants (heures prévues × prix/h par cours) ──
        $nb_cours        = count($cours_list);
        $total_h_prevues = 0;
        $total_brut_calc = 0;
        $prix_heure_ref  = 0;   // prix/h du premier cours (pour l'en-tête)

        foreach ($cours_list as $i => $c) {
            $h_prev  = floatval($c['nb_heures_prevues'] ?? $c['nb_heures'] ?? 0);
            $ph      = floatval($c['prix_heure'] ?? $TARIF_DEFAULT);
            $mont_c  = round($h_prev * $ph);   // heures prévues × prix/h
            $total_h_prevues += $h_prev;
            $total_brut_calc += $mont_c;
            if ($i === 0) $prix_heure_ref = $ph;
        }

        if ($montant_negocie !== null && $montant_negocie > 0) {
            // CAS 3 : montant négocié (override)
            $brut = $montant_negocie;
            $ret  = ($is_teacher && $retenue_appliquee) ? round($brut * 0.095) : 0;
        } else {
            $brut = $total_brut_calc;
            $ret  = $is_teacher ? round($brut * 0.095) : 0;
            $montant_negocie   = null;
            $retenue_appliquee = 1;
        }
        $net                = $brut - $ret;
        $total_h_for_record = $total_h_prevues;

        $conn->begin_transaction();
        if ($pid_data) {
            $stmt = $conn->prepare("UPDATE paiements_enseignant SET periode=?,semestre=?,annee_academique=?,nb_heures_total=?,prix_heure=?,montant_total_brut=?,montant_retenue=?,montant_total_net=?,montant_negocie=?,retenue_appliquee=?,notes=?,updated_at=NOW() WHERE id=? AND enseignant_id=?");
            $stmt->bind_param("sssddddddisis", $periode,$semestre,$annee,$total_h_for_record,$prix_heure_ref,$brut,$ret,$net,$montant_negocie,$retenue_appliquee,$notes,$pid_data,$eid);
            $stmt->execute();
            $conn->query("DELETE FROM paiement_cours_detail WHERE paiement_id=".intval($pid_data));
            $paiement_id = intval($pid_data);
        } else {
            $stmt = $conn->prepare("INSERT INTO paiements_enseignant (enseignant_id,periode,semestre,annee_academique,nb_heures_total,prix_heure,montant_total_brut,montant_retenue,montant_total_net,montant_negocie,retenue_appliquee,notes,statut,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'brouillon',?)");
            $stmt->bind_param("ssssddddddiss", $eid,$periode,$semestre,$annee,$total_h_for_record,$prix_heure_ref,$brut,$ret,$net,$montant_negocie,$retenue_appliquee,$notes,$admin_id);
            $stmt->execute();
            $paiement_id = $conn->insert_id;
        }

        foreach ($cours_list as $c) {
            $cid    = intval($c['course_id']);
            $cnom   = $c['course_name'] ?? 'Cours';
            $h_prev = floatval($c['nb_heures_prevues'] ?? $c['nb_heures'] ?? 0);
            $ph     = floatval($c['prix_heure'] ?? $TARIF_DEFAULT);
            $mont_c = round($h_prev * $ph);   // heures prévues × prix/h
            $stmt2  = $conn->prepare("INSERT INTO paiement_cours_detail (paiement_id,course_id,course_name,nb_heures_prevues,nb_heures,prix_heure,montant) VALUES (?,?,?,?,?,?,?)");
            $stmt2->bind_param("iisdddd", $paiement_id,$cid,$cnom,$h_prev,$h_prev,$ph,$mont_c);
            $stmt2->execute();
        }
        $conn->commit();

        $cas_label  = ($montant_negocie!==null) ? 'CAS 3 — Montant négocié' : 'Grille par cours (H.réelles×Prix/h)';
        $action_str = $pid_data ? 'UPDATE' : 'CREATE';
        $desc = ($pid_data?'Modification':'Création')." engagement #{$paiement_id} — {$eid} — {$semestre} {$annee} — {$nb_cours} cours — {$cas_label} — Brut: ".round($brut)." — Net: ".round($net)." FCFA".($force?' [FORCE]':'');
        logAudit($conn, $action_str, 'engagement', $paiement_id, $desc,
            null,
            ['eid'=>$eid,'semestre'=>$semestre,'annee'=>$annee,'nb_cours'=>$nb_cours,'brut'=>round($brut),'ret'=>round($ret),'net'=>round($net),'cas'=>$cas_label,'force'=>$force],
            $admin_id, $_SERVER['REMOTE_ADDR']??''
        );
        echo json_encode(['success'=>true,'paiement_id'=>$paiement_id,'net'=>$net,'brut'=>$brut,'ret'=>$ret,'total_h'=>$total_h_for_record], JSON_UNESCAPED_UNICODE);
    } catch(Exception $e) {
        try { if ($conn->in_transaction) $conn->rollback(); } catch(Exception $re){}
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit();
}

// ── Mettre à jour les heures réelles d'un engagement ─────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'update_heures_reelles') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $pid = intval($data['paiement_id'] ?? 0);
        $cours_heures = $data['cours_heures'] ?? [];
        if ($pid <= 0) throw new Exception("ID invalide.");
        foreach ($cours_heures as $ch) {
            $cid = intval($ch['course_id'] ?? 0);
            $h   = floatval($ch['nb_heures'] ?? 0);
            $stmt = $conn->prepare("UPDATE paiement_cours_detail SET nb_heures=? WHERE paiement_id=? AND course_id=?");
            $stmt->bind_param("dii", $h, $pid, $cid);
            $stmt->execute();
        }
        logAudit($conn, 'UPDATE', 'engagement', $pid, "Heures réelles modifiées — Engagement #{$pid}",
            null, $cours_heures, $admin_id, $_SERVER['REMOTE_ADDR']??'');
        echo json_encode(['success'=>true]);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit();
}

// ── Liste de tous les cours disponibles (sans enseignant ou assignés à eid) ─
if (isset($_GET['action']) && $_GET['action'] === 'get_all_courses') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $result = $conn->query("
            SELECT c.id, c.name, c.semester, c.major, c.total_hours, c.teacher_id,
                   u.name AS teacher_name
            FROM courses c
            LEFT JOIN users u ON u.id = c.teacher_id
            ORDER BY
                CASE WHEN (c.teacher_id IS NULL OR c.teacher_id = '') THEN 0 ELSE 1 END ASC,
                c.semester ASC, c.name ASC
        ");
        $courses = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success'=>true,'courses'=>$courses], JSON_UNESCAPED_UNICODE);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit();
}

// ── Enregistrer versement (paiement) ───────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'save_versement') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $pid    = intval($data['paiement_id'] ?? 0);
        $mont   = floatval($data['montant'] ?? 0);
        $method = $data['payment_method'] ?? 'cash';
        $notes  = $data['notes'] ?? '';
        if ($pid <= 0 || $mont <= 0) throw new Exception("Données invalides.");

        $pe = $conn->query("SELECT * FROM paiements_enseignant WHERE id=$pid")->fetch_assoc();
        if (!$pe) throw new Exception("Engagement introuvable.");

        $receipt = 'REC-TR-'.date('Y').'-'.str_pad(mt_rand(1,9999),4,'0',STR_PAD_LEFT);
        $now = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("INSERT INTO versements_cours (paiement_id,montant,payment_method,receipt_number,date_versement,processed_by,notes) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("idsssss", $pid,$mont,$method,$receipt,$now,$admin_id,$notes);
        $stmt->execute();

        // Calculer total versé (hors annulés)
        $tv_row = $conn->query("SELECT COALESCE(SUM(montant),0) AS tv FROM versements_cours WHERE paiement_id=$pid AND statut != 'annule'")->fetch_assoc();
        $total_verse = floatval($tv_row['tv']);
        $net_total   = floatval($pe['montant_total_net']);

        $new_statut = $total_verse >= $net_total ? 'complete' : 'partiel';
        $conn->query("UPDATE paiements_enseignant SET statut='$new_statut', updated_at=NOW() WHERE id=$pid");

        $ensnom = $pe['enseignant_id'];
        logAudit($conn, 'PAYMENT', 'versement', $conn->insert_id,
            "Versement {$receipt} — Engagement #{$pid} ({$ensnom}) — ".nf($mont)." FCFA — {$method} — Statut: {$new_statut}",
            null,
            ['paiement_id'=>$pid,'montant'=>$mont,'method'=>$method,'receipt'=>$receipt,'statut'=>$new_statut,'total_verse'=>$total_verse,'restant'=>max(0,$net_total-$total_verse)],
            $admin_id, $_SERVER['REMOTE_ADDR']??''
        );
        echo json_encode([
            'success'      => true,
            'receipt'      => $receipt,
            'total_verse'  => $total_verse,
            'restant'      => max(0, $net_total - $total_verse),
            'statut'       => $new_statut,
        ], JSON_UNESCAPED_UNICODE);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit();
}

// ── Charger détail engagement pour modal Payer / Facture ───────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_engagement') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = intval($_GET['id'] ?? 0);
    try {
        $stmt = $conn->prepare("
            SELECT pe.*, u.name AS enseignant_nom, u.email AS enseignant_email,
                   adm.name AS created_by_name
            FROM paiements_enseignant pe
            LEFT JOIN users u   ON CONVERT(pe.enseignant_id USING utf8mb4)=CONVERT(u.id USING utf8mb4)
            LEFT JOIN users adm ON CONVERT(pe.created_by USING utf8mb4)=CONVERT(adm.id USING utf8mb4)
            WHERE pe.id=?
        ");
        $stmt->bind_param("i",$pid); $stmt->execute();
        $pe = $stmt->get_result()->fetch_assoc();
        if (!$pe) throw new Exception("Introuvable.");

        $cours_rows = $conn->query("SELECT * FROM paiement_cours_detail WHERE paiement_id=$pid")->fetch_all(MYSQLI_ASSOC);
        // Enrichir chaque cours avec les heures effectuées AUJOURD'HUI (dynamique)
        foreach ($cours_rows as &$cr) {
            $cid_dyn   = intval($cr['course_id']);
            $h_today   = floatval($conn->query("SELECT COALESCE(SUM(hours),0) AS h FROM course_sessions WHERE course_id=$cid_dyn AND session_date IS NOT NULL")->fetch_assoc()['h'] ?? 0);
            $h_base    = floatval($cr['nb_heures_prevues'] ?: $cr['nb_heures']);
            $delta     = $h_today - $h_base;
            $cr['heures_today']    = $h_today;
            $cr['heures_delta']    = $delta;
            $cr['delta_warning']   = $delta > 0;   // heures_today > nb_heures_prevues
            $cr['delta_info']      = $delta < 0;   // heures restantes à effectuer
            $cr['heures_restantes']= $delta < 0 ? abs($delta) : 0;
        }
        unset($cr);
        $pe['cours']      = $cours_rows;
        $pe['versements'] = $conn->query("
            SELECT vc.*, adm.name AS processed_by_name
            FROM versements_cours vc
            LEFT JOIN users adm ON CONVERT(vc.processed_by USING utf8mb4)=CONVERT(adm.id USING utf8mb4)
            WHERE vc.paiement_id=$pid ORDER BY vc.date_versement ASC
        ")->fetch_all(MYSQLI_ASSOC);

        $tv = $conn->query("SELECT COALESCE(SUM(montant),0) AS tv FROM versements_cours WHERE paiement_id=$pid AND statut != 'annule'")->fetch_assoc()['tv'] ?? 0;
        $pe['total_verse']  = floatval($tv);
        $pe['restant']      = max(0, floatval($pe['montant_total_net']) - floatval($tv));

        echo json_encode(['success'=>true,'engagement'=>$pe], JSON_UNESCAPED_UNICODE);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit();
}

// ── Vue enseignant — engagements + paiements libres + totaux ───────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_teacher_payment_view') {
    header('Content-Type: application/json; charset=utf-8');
    $sid      = $_GET['enseignant_id'] ?? $_GET['staff_id'] ?? '';
    $annee_tv = trim($_GET['annee_academique'] ?? '');
    try {
        // 1. Engagements cours avec versements
        // Filtre optionnel par annee_academique (vide = toutes les années)
        $annee_tv_cond = ($annee_tv !== '') ? 'AND pe.annee_academique = ?' : '';
        $stmtE = $conn->prepare("
            SELECT pe.*,
                   COALESCE(SUM(CASE WHEN vc.statut != 'annule' THEN vc.montant ELSE 0 END), 0) AS total_verse,
                   pe.montant_total_net - COALESCE(SUM(CASE WHEN vc.statut != 'annule' THEN vc.montant ELSE 0 END), 0) AS restant,
                   (SELECT COUNT(*) FROM paiement_cours_detail WHERE paiement_id = pe.id) AS nb_modules
            FROM paiements_enseignant pe
            LEFT JOIN versements_cours vc ON vc.paiement_id = pe.id
            WHERE pe.enseignant_id = ?
            $annee_tv_cond
            GROUP BY pe.id
            ORDER BY pe.created_at DESC
        ");
        if ($annee_tv !== '') {
            $stmtE->bind_param("ss", $sid, $annee_tv);
        } else {
            $stmtE->bind_param("s", $sid);
        }
        $stmtE->execute();
        $engagements = $stmtE->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($engagements as &$eng) {
            $eng['cours'] = $conn->query("SELECT course_name, nb_heures, nb_heures_prevues, prix_heure, montant FROM paiement_cours_detail WHERE paiement_id=".intval($eng['id']))->fetch_all(MYSQLI_ASSOC);
            $eng['restant'] = max(0, floatval($eng['restant']));
        }
        unset($eng);

        // 2. Paiements libres
        $stmtP = $conn->prepare("
            SELECT sp.*, spt.name AS type_name, spt.category
            FROM staff_payments sp
            JOIN staff_payment_types spt ON spt.id = sp.payment_type_id
            WHERE sp.staff_id = ? ORDER BY sp.payment_date DESC
        ");
        $stmtP->bind_param("s", $sid); $stmtP->execute();
        $paiements_libres = $stmtP->get_result()->fetch_all(MYSQLI_ASSOC);

        // 3. Totaux
        $total_eng_brut  = 0; $total_eng_verse = 0; $total_eng_restant = 0;
        foreach ($engagements as $e) {
            if ($e['statut'] !== 'annule') {
                $total_eng_brut    += floatval($e['montant_total_brut']);
                $total_eng_verse   += floatval($e['total_verse']);
                $total_eng_restant += floatval($e['restant']);
            }
        }
        $total_libres = array_sum(array_column(array_filter($paiements_libres, fn($p) => $p['status'] !== 'cancelled'), 'amount_net'));

        echo json_encode([
            'success'           => true,
            'engagements'       => $engagements,
            'paiements_libres'  => $paiements_libres,
            'total_eng_brut'    => $total_eng_brut,
            'total_eng_verse'   => $total_eng_verse,
            'total_eng_restant' => $total_eng_restant,
            'total_libres'      => $total_libres,
        ], JSON_UNESCAPED_UNICODE);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit();
}

// ── Annuler engagement ─────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'annuler_engagement') {
    header('Content-Type: application/json; charset=utf-8');
    $data             = json_decode(file_get_contents('php://input'), true);
    $pid              = intval($data['id'] ?? 0);
    $motif            = trim($data['motif'] ?? '');
    $confirmed        = !empty($data['confirmed']);
    $comptable_action = $data['comptable_action'] ?? 'none';
    try {
        if (empty($motif)) throw new Exception("Le motif d'annulation est obligatoire.");

        // Vérifier les versements liés
        $v_row = $conn->query("
            SELECT COUNT(*) AS nb, COALESCE(SUM(montant), 0) AS total
            FROM versements_cours WHERE paiement_id = $pid
        ")->fetch_assoc();
        $nb_versements = intval($v_row['nb'] ?? 0);
        $total_verse   = floatval($v_row['total'] ?? 0);

        // Si versements existants et pas encore confirmé → demander choix
        if ($nb_versements > 0 && !$confirmed) {
            echo json_encode([
                'needs_confirm' => true,
                'nb_versements' => $nb_versements,
                'total_verse'   => $total_verse,
                'message'       => "Cet engagement a {$nb_versements} versement(s) déjà effectué(s) pour un total de " . nf($total_verse) . " FCFA.",
            ]);
            exit();
        }

        $now     = date('Y-m-d H:i:s');
        $notes   = "[ANNULÉ le $now — Motif : $motif]";
        $old_row = $conn->query("SELECT enseignant_id,semestre,annee_academique,montant_total_net,statut FROM paiements_enseignant WHERE id=$pid")->fetch_assoc();

        // Contre-passation comptable si demandée
        if ($comptable_action === 'reverse' && $nb_versements > 0) {
            $admin_esc = $conn->real_escape_string($admin_id);
            $conn->query("
                UPDATE ecritures_comptables
                SET statut = 'annule',
                    annule_par = '$admin_esc',
                    annule_le = NOW(),
                    motif_annulation = CONCAT('Contre-passation - engagement #$pid annulé')
                WHERE source_type = 'versement_cours'
                  AND source_id IN (SELECT id FROM versements_cours WHERE paiement_id = $pid)
                  AND statut = 'valide'
            ");
            $conn->query("UPDATE versements_cours SET statut = 'annule' WHERE paiement_id = $pid");
        }

        $stmt = $conn->prepare("UPDATE paiements_enseignant SET statut='annule', notes=CONCAT(COALESCE(notes,''),' ',?), updated_at=NOW() WHERE id=?");
        $stmt->bind_param("si", $notes, $pid);
        $stmt->execute();
        logAudit($conn, 'CANCEL', 'engagement', $pid,
            "Annulation engagement #{$pid} — ".($old_row['enseignant_id']??'')." — ".($old_row['semestre']??'')." ".($old_row['annee_academique']??'')." — Motif : {$motif}".($comptable_action==='reverse'?' — Contre-passation comptable':''),
            $old_row,
            ['statut'=>'annule','motif'=>$motif,'comptable_action'=>$comptable_action],
            $admin_id, $_SERVER['REMOTE_ADDR']??''
        );
        echo json_encode(['success'=>true]);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit();
}

// ── Journal d'audit ───────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_audit_log') {
    header('Content-Type: application/json; charset=utf-8');
    $f_entity  = $_GET['entity_type'] ?? '';
    $f_action  = $_GET['action_type'] ?? '';
    $f_from    = $_GET['date_from'] ?? '';
    $f_to      = $_GET['date_to']   ?? '';
    $f_search  = trim($_GET['search'] ?? '');
    $limit     = min(intval($_GET['limit'] ?? 200), 500);
    try {
        $where = []; $params = []; $types = '';
        if ($f_entity)  { $where[] = "al.entity_type=?"; $params[] = $f_entity;             $types .= 's'; }
        if ($f_action)  { $where[] = "al.action_type=?"; $params[] = $f_action;             $types .= 's'; }
        if ($f_from)    { $where[] = "al.performed_at>=?"; $params[] = $f_from.' 00:00:00'; $types .= 's'; }
        if ($f_to)      { $where[] = "al.performed_at<=?"; $params[] = $f_to.' 23:59:59';   $types .= 's'; }
        if ($f_search)  { $where[] = "al.description LIKE ?"; $params[] = '%'.$f_search.'%'; $types .= 's'; }
        $sql = "SELECT al.*, u.name AS performed_by_name FROM audit_log al LEFT JOIN users u ON CONVERT(al.performed_by USING utf8mb4)=CONVERT(u.id USING utf8mb4)";
        if ($where) $sql .= " WHERE ".implode(" AND ", $where);
        $sql .= " ORDER BY al.performed_at DESC LIMIT ".$limit;
        $stmt = $conn->prepare($sql);
        if ($params) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        // Stats rapides
        $stats = $conn->query("SELECT action_type, COUNT(*) AS cnt FROM audit_log GROUP BY action_type")->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success'=>true,'logs'=>$logs,'total'=>count($logs),'stats'=>$stats], JSON_UNESCAPED_UNICODE);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit();
}

// ── Reçu paiement libre ────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_payment_receipt') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = intval($_GET['payment_id'] ?? 0);
    try {
        $stmt = $conn->prepare("
            SELECT sp.*, u.name AS staff_name, u.email AS staff_email, u.role AS staff_role,
                   spt.name AS payment_type_name, spt.category, adm.name AS processed_by_name
            FROM staff_payments sp
            LEFT JOIN users u ON CONVERT(sp.staff_id USING utf8mb4)=CONVERT(u.id USING utf8mb4)
            LEFT JOIN staff_payment_types spt ON sp.payment_type_id=spt.id
            LEFT JOIN users adm ON CONVERT(sp.processed_by USING utf8mb4)=CONVERT(adm.id USING utf8mb4)
            WHERE sp.id=?
        ");
        $stmt->bind_param("i",$pid); $stmt->execute();
        $res = $stmt->get_result();
        echo $res && $res->num_rows > 0
            ? json_encode(['success'=>true,'payment'=>$res->fetch_assoc()],JSON_UNESCAPED_UNICODE)
            : json_encode(['success'=>false,'error'=>'Introuvable']);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit();
}

// ── Toggle statut paiement libre ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_toggle_raw  = file_get_contents('php://input');
    $_toggle_data = $_toggle_raw ? (json_decode($_toggle_raw, true) ?? []) : [];
    if (($_toggle_data['action'] ?? '') === 'toggle_status') {
        header('Content-Type: application/json; charset=utf-8');
        $pid = intval($_toggle_data['id'] ?? 0);
        try {
            $cur = $conn->query("SELECT status FROM staff_payments WHERE id=$pid")->fetch_assoc()['status'];
            $new = ($cur === 'pending') ? 'processed' : 'pending';
            $conn->query("UPDATE staff_payments SET status='$new' WHERE id=$pid");
            echo json_encode(['success'=>true,'new_status'=>$new]);
        } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit();
    }
}

// ── Historique paiements libres ────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_staff_history') {
    header('Content-Type: application/json; charset=utf-8');
    $sid = $_GET['staff_id'] ?? '';
    try {
        $stmt = $conn->prepare("
            SELECT sp.payment_date, spt.name AS type_name, spt.category,
                   sp.amount_brut, sp.amount_retenue, sp.amount_net,
                   sp.nb_heures, sp.prix_heure, sp.payment_method, sp.receipt_number, sp.status, sp.description
            FROM staff_payments sp
            JOIN staff_payment_types spt ON sp.payment_type_id=spt.id
            WHERE sp.staff_id=? ORDER BY sp.payment_date DESC
        ");
        $stmt->bind_param("s",$sid); $stmt->execute();
        $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $total_month = 0;
        foreach ($payments as $p) {
            if (date('Y-m', strtotime($p['payment_date'])) === date('Y-m'))
                $total_month += floatval($p['amount_net']);
        }
        echo json_encode(['success'=>true,'count'=>count($payments),'total_month'=>$total_month,'payments'=>$payments],JSON_UNESCAPED_UNICODE);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit();
}

// ── Bulletin mensuel ───────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_bulletin_mensuel') {
    header('Content-Type: application/json; charset=utf-8');
    $sid   = $_GET['staff_id'] ?? '';
    $mois  = str_pad(intval($_GET['mois'] ?? date('m')),2,'0',STR_PAD_LEFT);
    $annee = intval($_GET['annee'] ?? date('Y'));
    try {
        $stmt = $conn->prepare("SELECT id,name,email,phone,role FROM users WHERE id=?");
        $stmt->bind_param("s",$sid); $stmt->execute();
        $employe = $stmt->get_result()->fetch_assoc();
        if (!$employe) throw new Exception("Employé introuvable.");

        $annee_s = (string)$annee;
        $stmt2 = $conn->prepare("
            SELECT sp.*, spt.name AS type_name, spt.category, 'libre' AS source_type
            FROM staff_payments sp JOIN staff_payment_types spt ON sp.payment_type_id=spt.id
            WHERE sp.staff_id=? AND DATE_FORMAT(sp.payment_date,'%m')=? AND DATE_FORMAT(sp.payment_date,'%Y')=? AND sp.status!='cancelled'
            ORDER BY sp.payment_date ASC
        ");
        $stmt2->bind_param("sss",$sid,$mois,$annee_s); $stmt2->execute();
        $libres = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt3 = $conn->prepare("
            SELECT pe.*, 'cours' AS source_type
            FROM paiements_enseignant pe
            WHERE pe.enseignant_id=? AND DATE_FORMAT(pe.created_at,'%m')=? AND DATE_FORMAT(pe.created_at,'%Y')=? AND pe.statut!='annule'
            ORDER BY pe.created_at ASC
        ");
        $stmt3->bind_param("sss",$sid,$mois,$annee_s); $stmt3->execute();
        $cours_rows = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($cours_rows as &$cr) {
            $cr['cours']    = $conn->query("SELECT * FROM paiement_cours_detail WHERE paiement_id=".$cr['id'])->fetch_all(MYSQLI_ASSOC);
            $tv = $conn->query("SELECT COALESCE(SUM(montant),0) AS tv FROM versements_cours WHERE paiement_id=".$cr['id'])->fetch_assoc()['tv'];
            $cr['total_verse'] = floatval($tv);
            $cr['restant']     = max(0, floatval($cr['montant_total_net']) - floatval($tv));
            $cr['nb_modules']  = count($cr['cours']);
            $cr['amount_brut'] = $cr['montant_total_brut'];
            $cr['amount_net']  = $cr['montant_total_net'];
            $cr['amount_retenue'] = $cr['montant_retenue'];
            $cr['nb_heures']   = $cr['nb_heures_total'];
        } unset($cr);

        $tb=$tn=$trr=$th=0;
        foreach (array_merge($libres,$cours_rows) as $r) {
            $tb += floatval($r['amount_brut']??0);
            $tn += floatval($r['amount_net']??0);
            $trr+= floatval($r['amount_retenue']??0);
            $th += floatval($r['nb_heures']??0);
        }
        echo json_encode(['success'=>true,'employe'=>$employe,'libres'=>$libres,'cours'=>$cours_rows,'total_brut'=>$tb,'total_net'=>$tn,'total_retenue'=>$trr,'total_heures'=>$th,'mois'=>$mois,'annee'=>$annee],JSON_UNESCAPED_UNICODE);
    } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_tarifs') {
    header('Content-Type: application/json; charset=utf-8');
    $tarifs = ['licence'=>7500,'master'=>10000,'doctorat'=>12000];
    $r = $conn->query("SELECT niveau_lmd, tarif_horaire FROM tarifs_niveau
                       ORDER BY FIELD(niveau_lmd,'licence','master','doctorat')");
    if ($r) { while ($row = $r->fetch_assoc()) { $tarifs[$row['niveau_lmd']] = floatval($row['tarif_horaire']); } }
    echo json_encode(['success'=>true, 'tarifs'=>$tarifs]);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'save_tarifs') {
    header('Content-Type: application/json; charset=utf-8');
    $tarifs = json_decode(file_get_contents('php://input'), true) ?: [];
    $allowed = ['licence','master','doctorat'];
    foreach ($tarifs as $niveau => $tarif) {
        if (!in_array($niveau, $allowed, true)) continue;
        $tarif = floatval($tarif);
        if ($tarif <= 0) continue;
        $niveau_esc = $conn->real_escape_string($niveau);
        $admin_esc  = $conn->real_escape_string($admin_id);
        $conn->query("INSERT INTO tarifs_niveau (niveau_lmd, tarif_horaire, updated_by)
                      VALUES ('$niveau_esc', $tarif, '$admin_esc')
                      ON DUPLICATE KEY UPDATE tarif_horaire = $tarif, updated_by = '$admin_esc'");
    }
    echo json_encode(['success'=>true]);
    exit();
}

// ═══════════════════════════════════════════════════════════════════
// POST : Paiement Libre / Dépense / Type / Personnel
// ═══════════════════════════════════════════════════════════════════
$message = $message_type = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Token CSRF invalide."; $message_type = "error";
    } else {
        try {
            switch ($_POST['action'] ?? '') {
                case 'add_staff_payment':
                    $staff_id  = $_POST['staff_id'] ?? '';
                    $pt_id     = intval($_POST['payment_type_id'] ?? 0);
                    $method    = $_POST['payment_method'] ?? 'cash';
                    $desc      = trim($_POST['description'] ?? '');
                    $ref       = trim($_POST['reference'] ?? '');
                    $statut_i  = $_POST['statut_initial'] ?? 'processed';
                    $mode      = $_POST['mode_paiement'] ?? 'horaire';
                    if (empty($staff_id)||$pt_id<=0) throw new Exception("Champs obligatoires manquants.");
                    $urole = $conn->prepare("SELECT role FROM users WHERE id=?");
                    $urole->bind_param("s",$staff_id); $urole->execute();
                    $user_role = $urole->get_result()->fetch_assoc()['role'] ?? 'admin';
                    $nb_h=null; $px_h=null;
                    if ($mode==='horaire') {
                        $nb_h = floatval(str_replace(',','.',$_POST['nb_heures']??'0'));
                        $px_h = floatval(str_replace(',','.',$_POST['prix_heure']??$TARIF_DEFAULT));
                        $amount_brut = round($nb_h*$px_h);
                        if ($nb_h<=0||$amount_brut<=0) throw new Exception("Volume horaire invalide.");
                    } else {
                        $amount_brut = floatval(str_replace(',','.',$_POST['amount_brut']??'0'));
                        if ($amount_brut<=0) throw new Exception("Montant invalide.");
                    }
                    $cat_res  = $conn->query("SELECT category FROM staff_payment_types WHERE id=$pt_id LIMIT 1");
                    $category = $cat_res ? ($cat_res->fetch_assoc()['category']??'') : '';
                    $cats_r   = ['salary','bonus','allowance'];
                    $amount_retenue = ($user_role==='teacher'&&in_array($category,$cats_r)) ? round($amount_brut*0.095) : 0;
                    $amount_net     = $amount_brut - $amount_retenue;
                    $receipt  = 'REC-'.date('Y').'-'.str_pad(mt_rand(1,9999),4,'0',STR_PAD_LEFT);
                    $pdate    = date('Y-m-d H:i:s');
                    $status   = in_array($statut_i,['pending','processed'])?$statut_i:'processed';
                    $stmt = $conn->prepare("INSERT INTO staff_payments (staff_id,payment_type_id,amount_brut,amount_retenue,amount_net,nb_heures,prix_heure,payment_date,payment_method,description,reference_number,receipt_number,status,processed_by,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Paiement UV')");
                    $stmt->bind_param("sidddddsssssss",$staff_id,$pt_id,$amount_brut,$amount_retenue,$amount_net,$nb_h,$px_h,$pdate,$method,$desc,$ref,$receipt,$status,$admin_id);
                    $stmt->execute();
                    $pay_lid = $conn->insert_id;
                    logAudit($conn,'CREATE','payment_libre',$pay_lid,
                        "Paiement libre {$receipt} — {$staff_id} — ".nf($amount_net)." FCFA net — catégorie: {$category}",
                        null, ['staff_id'=>$staff_id,'brut'=>$amount_brut,'retenue'=>$amount_retenue,'net'=>$amount_net,'receipt'=>$receipt,'category'=>$category,'method'=>$method],
                        $admin_id, $_SERVER['REMOTE_ADDR']??'');
                    $message = "Paiement enregistré — $receipt | Net : ".nf($amount_net)." FCFA";
                    $message_type = 'success';
                    break;

                case 'add_operational_expense':
                    $et=$_POST['expense_type']??''; $cat=$_POST['category']??'other';
                    $amt=floatval(str_replace(',','.',$_POST['amount']??'0'));
                    $ed=$_POST['expense_date']??date('Y-m-d');
                    $vn=$_POST['vendor_name']??''; $inv=$_POST['invoice_number']??'';
                    $dsc=$_POST['description']??''; $pm=$_POST['payment_method']??'bank_transfer';
                    $rfr=$_POST['reference']??'';
                    if (empty($et)||$amt<=0) throw new Exception("Champs obligatoires.");
                    $stmt = $conn->prepare("INSERT INTO operational_expenses (expense_type,category,amount,expense_date,vendor_name,invoice_number,description,payment_method,reference_number,processed_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("ssdsssssss",$et,$cat,$amt,$ed,$vn,$inv,$dsc,$pm,$rfr,$admin_id);
                    $stmt->execute();
                    $message = "Dépense enregistrée."; $message_type = 'success';
                    break;

                case 'add_payment_type':
                    $nm=trim($_POST['type_name']??''); $ct=$_POST['type_category']??'salary'; $dc=$_POST['type_description']??'';
                    if (empty($nm)) throw new Exception("Nom obligatoire.");
                    $stmt = $conn->prepare("INSERT INTO staff_payment_types (name,category,description) VALUES (?,?,?)");
                    $stmt->bind_param("sss",$nm,$ct,$dc); $stmt->execute();
                    $message = "Type ajouté."; $message_type = 'success';
                    break;

                case 'edit_payment_type':
                    $tid=intval($_POST['type_id']??0); $nm=trim($_POST['type_name']??''); $ct=$_POST['type_category']??'salary'; $dc=$_POST['type_description']??'';
                    if (!$tid||empty($nm)) throw new Exception("Données invalides.");
                    $stmt = $conn->prepare("UPDATE staff_payment_types SET name=?,category=?,description=? WHERE id=?");
                    $stmt->bind_param("sssi",$nm,$ct,$dc,$tid); $stmt->execute();
                    $message = "Type modifié."; $message_type = 'success';
                    break;

                case 'add_staff_member':
                    $sn=trim($_POST['staff_name']??''); $se=trim($_POST['staff_email']??'');
                    $sp=trim($_POST['staff_phone']??''); $sr=$_POST['staff_role']??'teacher'; $sa=trim($_POST['staff_address']??'');
                    if (empty($sn)||empty($se)||empty($sr)) throw new Exception("Champs obligatoires.");
                    if (!filter_var($se,FILTER_VALIDATE_EMAIL)) throw new Exception("Email invalide.");
                    $chk=$conn->prepare("SELECT id FROM users WHERE email=?");
                    $chk->bind_param("s",$se); $chk->execute();
                    if ($chk->get_result()->num_rows>0) throw new Exception("Email déjà utilisé.");
                    $prefix=$sr==='teacher'?'ISMM-VAC-':'ADMIN-';
                    $lq=$conn->prepare("SELECT id FROM users WHERE id LIKE ? ORDER BY id DESC LIMIT 1");
                    $pat=$prefix.'%'; $lq->bind_param("s",$pat); $lq->execute();
                    $lr=$lq->get_result();
                    $num=($lr&&$lr->num_rows>0)?intval(substr($lr->fetch_assoc()['id'],strlen($prefix)))+1:1;
                    $new_id=$prefix.str_pad($num,2,'0',STR_PAD_LEFT);
                    $hpw=password_hash('password123',PASSWORD_DEFAULT);
                    $stmt=$conn->prepare("INSERT INTO users (id,name,email,phone,address,password,role,status,created_at) VALUES (?,?,?,?,?,?,?,'active',NOW())");
                    $stmt->bind_param("sssssss",$new_id,$sn,$se,$sp,$sa,$hpw,$sr);
                    $stmt->execute();
                    // Assigner les cours sélectionnés (enseignants seulement)
                    if ($sr === 'teacher' && !empty($_POST['cours_assignes'])) {
                        foreach ($_POST['cours_assignes'] as $cid_raw) {
                            $cid_i = intval($cid_raw);
                            if ($cid_i > 0) {
                                $stmtC = $conn->prepare("UPDATE courses SET teacher_id=? WHERE id=?");
                                $stmtC->bind_param("si", $new_id, $cid_i);
                                $stmtC->execute();
                            }
                        }
                    }
                    $nb_cours_assignes = ($sr==='teacher'&&!empty($_POST['cours_assignes']))?count($_POST['cours_assignes']):0;
                    logAudit($conn,'CREATE','personnel',$new_id,
                        "Nouveau personnel créé — {$sn} — {$sr} — ID: {$new_id}".($nb_cours_assignes?" — {$nb_cours_assignes} cours assignés":''),
                        null, ['id'=>$new_id,'name'=>$sn,'role'=>$sr,'email'=>$se,'cours_assignes'=>$nb_cours_assignes],
                        $admin_id, $_SERVER['REMOTE_ADDR']??'');
                    $message="Personnel ajouté (ID: $new_id). Mdp: password123"; $message_type='success';
                    break;
            }
        } catch(Exception $e) { $message=$e->getMessage(); $message_type='error'; }
    }
    if (!empty($message)&&$message_type==='success') {
        $_SESSION['flash_message']=$message; $_SESSION['flash_type']=$message_type;
        header('Location: '.$_SERVER['REQUEST_URI']); exit();
    }
}

// ── Données affichage ───────────────────────────────────────────────────────
$staff_data=$payment_types=$recent_payments=$recent_expenses=[];
$engagements_recents=[];
$total_brut=$total_net=$total_retenue=$total_exp=$active_staff=0;
$stats_cours=['total_net'=>0,'total'=>0,'brouillon'=>0,'partiel'=>0];

// Années disponibles — union tuition_fees + paiements_enseignant
$annees_dispo = [ANNEE_ACADEMIQUE_COURANTE];
$__yr = $conn->query("
    SELECT DISTINCT annee FROM (
        SELECT academic_year as annee FROM tuition_fees
        UNION
        SELECT annee_academique FROM paiements_enseignant
        WHERE annee_academique IS NOT NULL AND annee_academique != ''
    ) t ORDER BY annee DESC
");
if ($__yr) {
    while ($__r = $__yr->fetch_assoc()) {
        if (!in_array($__r['annee'], $annees_dispo)) {
            $annees_dispo[] = $__r['annee'];
        }
    }
}
rsort($annees_dispo);
unset($__yr, $__r);

try {
    // Stats KPIs — plage annuelle (septembre → août)
    $sr = $conn->query("SELECT
        COALESCE(SUM(CASE WHEN spt.category IN ('salary','bonus','allowance','social') THEN sp.amount_brut END),0) tb,
        COALESCE(SUM(CASE WHEN spt.category IN ('salary','bonus','allowance','social') THEN sp.amount_net  END),0) tn,
        COALESCE(SUM(CASE WHEN spt.category IN ('salary','bonus','allowance','social') THEN sp.amount_retenue END),0) tr,
        (SELECT COALESCE(SUM(amount),0) FROM operational_expenses oe WHERE oe.expense_date BETWEEN '$annee_debut' AND '$annee_fin') te,
        COUNT(DISTINCT CASE WHEN u.role IN ('teacher','admin') AND u.status='active' THEN u.id END) us
        FROM users u
        LEFT JOIN staff_payments sp ON CONVERT(u.id USING utf8mb4)=CONVERT(sp.staff_id USING utf8mb4)
            AND sp.payment_date BETWEEN '$annee_debut' AND '$annee_fin'
        LEFT JOIN staff_payment_types spt ON sp.payment_type_id=spt.id
        WHERE u.role IN ('teacher','admin')");
    if ($sr){$s=$sr->fetch_assoc();$total_brut=$s['tb'];$total_net=$s['tn'];$total_retenue=$s['tr'];$total_exp=$s['te'];$active_staff=$s['us'];}

    // Personnel
    $sdr=$conn->query("SELECT u.id AS staff_id,u.name AS staff_name,u.email,u.role,
        COALESCE(SUM(CASE WHEN sp.payment_date BETWEEN '$annee_debut' AND '$annee_fin' THEN sp.amount_net END),0) monthly_net,
        COALESCE(MAX(sp.payment_date),NULL) last_payment_date,
        COALESCE(SUM(sp.amount_net),0) total_net_paid,
        COUNT(DISTINCT c.id) nb_cours,
        COALESCE(SUM(DISTINCT cs_t.total_h),0) total_heures_cours
        FROM users u
        LEFT JOIN staff_payments sp ON CONVERT(u.id USING utf8mb4)=CONVERT(sp.staff_id USING utf8mb4)
        LEFT JOIN courses c ON c.teacher_id=u.id
        LEFT JOIN (SELECT course_id,SUM(hours) AS total_h FROM course_sessions GROUP BY course_id) cs_t ON cs_t.course_id=c.id
        WHERE u.role IN ('teacher','admin') AND (u.blocked=0 OR u.blocked IS NULL)
        GROUP BY u.id,u.name,u.email,u.role ORDER BY u.role,u.name");
    if ($sdr) $staff_data=$sdr->fetch_all(MYSQLI_ASSOC);

    // Types de paiement
    $ptr=$conn->query("SELECT * FROM staff_payment_types WHERE is_active=1 ORDER BY category,name");
    if ($ptr) $payment_types=$ptr->fetch_all(MYSQLI_ASSOC);

    // Paiements libres récents — filtrés par année académique
    $prr=$conn->query("SELECT sp.*,u.name AS staff_name,u.role AS staff_role,spt.name AS payment_type_name,spt.category,adm.name AS processed_by_name
        FROM staff_payments sp
        LEFT JOIN users u ON CONVERT(sp.staff_id USING utf8mb4)=CONVERT(u.id USING utf8mb4)
        LEFT JOIN staff_payment_types spt ON sp.payment_type_id=spt.id
        LEFT JOIN users adm ON CONVERT(sp.processed_by USING utf8mb4)=CONVERT(adm.id USING utf8mb4)
        WHERE sp.payment_date BETWEEN '$annee_debut' AND '$annee_fin'
        ORDER BY sp.payment_date DESC LIMIT 50");
    if ($prr) while ($r=$prr->fetch_assoc()) $recent_payments[]=$r;

    // Dépenses — filtrées par année académique
    $err=$conn->query("SELECT oe.*,u.name AS processed_by_name FROM operational_expenses oe LEFT JOIN users u ON CONVERT(oe.processed_by USING utf8mb4)=CONVERT(u.id USING utf8mb4) WHERE oe.expense_date BETWEEN '$annee_debut' AND '$annee_fin' ORDER BY oe.expense_date DESC LIMIT 50");
    if ($err) while ($r=$err->fetch_assoc()) $recent_expenses[]=$r;

    // Engagements cours — filtrés par année académique sélectionnée
    $engr=$conn->query("SELECT pe.*,u.name AS enseignant_nom,
        adm.name AS created_by_name,
        (SELECT COALESCE(SUM(montant),0) FROM versements_cours WHERE paiement_id=pe.id) AS total_verse,
        (SELECT COUNT(*) FROM paiement_cours_detail WHERE paiement_id=pe.id) AS nb_modules,
        (SELECT MAX(date_versement) FROM versements_cours WHERE paiement_id=pe.id) AS last_versement
        FROM paiements_enseignant pe
        LEFT JOIN users u   ON CONVERT(pe.enseignant_id USING utf8mb4)=CONVERT(u.id USING utf8mb4)
        LEFT JOIN users adm ON CONVERT(pe.created_by USING utf8mb4)=CONVERT(adm.id USING utf8mb4)
        WHERE pe.annee_academique = '$annee_filtre_sql'
        ORDER BY pe.updated_at DESC");
    if ($engr) $engagements_recents=$engr->fetch_all(MYSQLI_ASSOC);

    $sc=$conn->query("SELECT COALESCE(SUM(montant_total_net),0) tn, COUNT(*) nb, SUM(statut='brouillon') brd, SUM(statut='partiel') prt FROM paiements_enseignant WHERE statut!='annule' AND annee_academique='$annee_filtre_sql'");
    if ($sc){$sv=$sc->fetch_assoc();$stats_cours=['total_net'=>$sv['tn'],'total'=>$sv['nb'],'brouillon'=>$sv['brd'],'partiel'=>$sv['prt']];}

    // Historique global — tous versements réellement effectués (cours + libres), triés par date paiement
    $historique_global = [];
    $hg = $conn->query("
        SELECT
            'cours' AS type_paiement,
            vc.date_versement AS date_paiement,
            vc.montant,
            vc.payment_method COLLATE utf8mb4_unicode_ci AS payment_method,
            vc.receipt_number COLLATE utf8mb4_unicode_ci AS receipt_number,
            COALESCE(vc.notes,'') COLLATE utf8mb4_unicode_ci AS description,
            COALESCE(u.name,'') COLLATE utf8mb4_unicode_ci AS staff_name,
            COALESCE(u.role,'') COLLATE utf8mb4_unicode_ci AS staff_role,
            COALESCE(pe.periode,'') COLLATE utf8mb4_unicode_ci AS periode,
            COALESCE(pe.enseignant_id,'') COLLATE utf8mb4_unicode_ci AS staff_id,
            COALESCE((SELECT GROUP_CONCAT(course_name SEPARATOR ', ') FROM paiement_cours_detail WHERE paiement_id=pe.id),'') COLLATE utf8mb4_unicode_ci AS modules,
            pe.montant_total_net AS net_total_engagement,
            pe.id AS engagement_id,
            '' COLLATE utf8mb4_unicode_ci AS payment_type_name
        FROM versements_cours vc
        JOIN paiements_enseignant pe ON pe.id = vc.paiement_id
        LEFT JOIN users u ON CONVERT(pe.enseignant_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci
        WHERE pe.annee_academique = '$annee_filtre_sql'
        UNION ALL
        SELECT
            'libre' COLLATE utf8mb4_unicode_ci AS type_paiement,
            sp.payment_date AS date_paiement,
            sp.amount_net AS montant,
            sp.payment_method COLLATE utf8mb4_unicode_ci AS payment_method,
            COALESCE(sp.receipt_number,'') COLLATE utf8mb4_unicode_ci AS receipt_number,
            COALESCE(sp.description,'') COLLATE utf8mb4_unicode_ci AS description,
            COALESCE(u.name,'') COLLATE utf8mb4_unicode_ci AS staff_name,
            COALESCE(u.role,'') COLLATE utf8mb4_unicode_ci AS staff_role,
            '' COLLATE utf8mb4_unicode_ci AS periode,
            COALESCE(sp.staff_id,'') COLLATE utf8mb4_unicode_ci AS staff_id,
            '' COLLATE utf8mb4_unicode_ci AS modules,
            NULL AS net_total_engagement,
            NULL AS engagement_id,
            COALESCE(spt.name,'') COLLATE utf8mb4_unicode_ci AS payment_type_name
        FROM staff_payments sp
        LEFT JOIN users u ON CONVERT(sp.staff_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci
        LEFT JOIN staff_payment_types spt ON sp.payment_type_id = spt.id
        WHERE sp.status = 'processed'
          AND sp.payment_date BETWEEN '$annee_debut' AND '$annee_fin'
        ORDER BY date_paiement DESC
    ");
    if ($hg) $historique_global = $hg->fetch_all(MYSQLI_ASSOC);
} catch(Exception $e){$message="Erreur : ".$e->getMessage();$message_type='error';}

$cat_names=['salary'=>'Salaires','bonus'=>'Primes','allowance'=>'Indemnités','social'=>'Charges Sociales'];
$cats_r_php=['salary','bonus','allowance'];
$mois_liste=['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin','07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre'];
$periode_courante = $mois_liste[date('m')].' '.date('Y');

$conn->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
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
<script>
const ANNEE_DEBUT = <?= json_encode($annee_debut) ?>;
const ANNEE_FIN   = <?= json_encode($annee_fin) ?>;
</script>
<title>Paiements Personnel — ISMM</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<style>
:root{--bg:#051e34;--bg2:#0c2d48;--accent:#039be5;--green:#2ecc71;--red:#e74c3c;--yellow:#f39c12;--purple:#9b59b6;--teal:#1abc9c;--gold:#d4a843;--orange:#e67e22;--border:rgba(255,255,255,.1);--text:#fff;--muted:#aab8c5;--card:rgba(255,255,255,.07);}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Tahoma,sans-serif;background:linear-gradient(135deg,var(--bg) 0%,var(--bg2) 100%);color:var(--text);min-height:100vh;}
header{background:var(--bg2);padding:15px 0;box-shadow:0 4px 6px rgba(0,0,0,.1);border-bottom:1px solid var(--border);}
.hdr{max-width:1400px;margin:0 auto;padding:0 20px;}
.hdr h1{font-size:24px;color:var(--accent);margin-bottom:12px;text-align:center;display:flex;align-items:center;justify-content:center;gap:10px;}
nav ul{list-style:none;display:flex;justify-content:center;flex-wrap:wrap;gap:4px;}
nav a{color:var(--text);text-decoration:none;padding:8px 13px;border-radius:6px;display:flex;align-items:center;gap:7px;transition:all .3s;font-size:13px;}
nav a:hover,nav a.active{background:rgba(3,155,229,.15);}
.container{max-width:1400px;margin:0 auto;padding:24px 20px;}
.alert{padding:13px 18px;border-radius:8px;margin-bottom:18px;display:none;align-items:center;gap:10px;font-size:13px;}
.alert.show{display:flex;}
.alert-success{background:rgba(46,204,113,.1);border:1px solid var(--green);color:var(--green);}
.alert-error{background:rgba(231,76,60,.1);border:1px solid var(--red);color:var(--red);}
.page-hdr{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px 26px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;backdrop-filter:blur(8px);}
.page-hdr h2{font-size:20px;color:var(--accent);display:flex;align-items:center;gap:10px;}
.page-hdr p{color:var(--muted);font-size:12px;margin-top:4px;}
.page-actions{display:flex;gap:8px;flex-wrap:wrap;}
.btn{padding:9px 16px;border:none;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;transition:all .2s;text-decoration:none;}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,.3);}
.btn-primary{background:var(--accent);color:#fff;}
.btn-success{background:var(--green);color:#051e34;}
.btn-warning{background:var(--yellow);color:#051e34;}
.btn-danger{background:var(--red);color:#fff;}
.btn-teal{background:var(--teal);color:#051e34;}
.btn-purple{background:var(--purple);color:#fff;}
.btn-orange{background:var(--orange);color:#fff;}
.btn-ghost{background:rgba(3,155,229,.1);color:var(--accent);border:1px solid var(--border);}
.btn-info{background:rgba(3,155,229,.85);color:#fff;}
.btn-sm{padding:5px 10px;font-size:11px;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:22px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px;position:relative;overflow:hidden;}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.stat-card.green::before{background:linear-gradient(90deg,var(--green),#00c853);}
.stat-card.blue::before{background:linear-gradient(90deg,var(--accent),#0277bd);}
.stat-card.yellow::before{background:linear-gradient(90deg,var(--yellow),#f57f17);}
.stat-card.red::before{background:linear-gradient(90deg,var(--red),#c62828);}
.stat-card.teal::before{background:linear-gradient(90deg,var(--teal),#00897b);}
.stat-card.purple::before{background:linear-gradient(90deg,var(--purple),#6a1b9a);}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);margin-bottom:6px;}
.stat-val{font-size:20px;font-weight:700;margin-bottom:3px;}
.stat-val.green{color:var(--green);}.stat-val.blue{color:var(--accent);}.stat-val.yellow{color:var(--yellow);}.stat-val.red{color:var(--red);}.stat-val.teal{color:var(--teal);}.stat-val.purple{color:var(--purple);}
.stat-sub{font-size:11px;color:var(--muted);}
.stat-icon-bg{position:absolute;right:14px;top:12px;font-size:26px;opacity:.1;}
.tabs{display:flex;background:var(--card);border:1px solid var(--border);border-bottom:none;border-radius:12px 12px 0 0;overflow-x:auto;}
.tab-btn{padding:12px 18px;background:transparent;border:none;color:var(--muted);cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap;transition:all .2s;display:flex;align-items:center;gap:7px;border-bottom:3px solid transparent;}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent);background:rgba(3,155,229,.06);}
.tab-btn:hover:not(.active){color:var(--text);background:rgba(255,255,255,.04);}
.tab-content{background:var(--card);border:1px solid var(--border);border-radius:0 0 12px 12px;padding:24px;display:none;}
.tab-content.active{display:block;}
.data-table{width:100%;border-collapse:collapse;font-size:13px;}
.data-table th{background:rgba(3,155,229,.08);color:var(--accent);padding:10px 13px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--border);}
.data-table td{padding:10px 13px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
.data-table tr:hover td{background:rgba(3,155,229,.04);}
.badge{padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;display:inline-block;}
.badge-processed,.badge-paid,.badge-complete{background:rgba(46,204,113,.15);color:var(--green);}
.badge-pending,.badge-partiel{background:rgba(243,156,18,.15);color:var(--yellow);}
.badge-cancelled,.badge-annule{background:rgba(231,76,60,.15);color:var(--red);}
.badge-brouillon{background:rgba(3,155,229,.15);color:var(--accent);}
.badge-teacher{background:rgba(26,188,156,.15);color:var(--teal);}
.badge-admin{background:rgba(155,89,182,.15);color:var(--purple);}
.status-toggle{cursor:pointer;padding:4px 10px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;border:none;transition:all .2s;}
.status-toggle.processed{background:rgba(46,204,113,.15);color:var(--green);}
.status-toggle.pending{background:rgba(243,156,18,.15);color:var(--yellow);}
/* Modal */
.modal{display:none;position:fixed;z-index:1000;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(4px);}
.modal-box{background:var(--bg2);border:1px solid var(--border);border-radius:14px;width:92%;max-width:720px;margin:1.5% auto;padding:26px;max-height:94vh;overflow-y:auto;}
.modal-box.wide{max-width:900px;}
.modal-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:13px;border-bottom:1px solid var(--border);}
.modal-hdr h3{color:var(--accent);font-size:16px;display:flex;align-items:center;gap:9px;}
.modal-close{color:var(--muted);font-size:22px;cursor:pointer;line-height:1;}
.modal-close:hover{color:var(--text);}
/* Form */
.form-group{margin-bottom:14px;}
.form-group label{display:block;margin-bottom:6px;color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;}
.form-control{width:100%;padding:10px 13px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,.06);color:var(--text);font-size:13px;transition:border-color .2s;}
.form-control:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(3,155,229,.1);}
select.form-control{background-color:#0c2d48;}
select.form-control option{background:#0c2d48;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.info-box{background:rgba(3,155,229,.06);border:1px solid rgba(3,155,229,.2);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--muted);margin-bottom:14px;}

/* ── ENGAGEMENT COURS ── */
.eng-cours-wrapper{background:rgba(0,0,0,.25);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:14px;}
.eng-cours-header{padding:10px 16px;background:rgba(26,188,156,.08);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.eng-cours-header span{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--teal);}
.eng-cours-grid{display:grid;grid-template-columns:1fr auto auto auto;gap:0;align-items:stretch;}
.eng-cours-grid-hdr{display:grid;grid-template-columns:1fr auto auto auto;gap:0;padding:7px 14px;background:rgba(255,255,255,.04);border-bottom:1px solid var(--border);}
.eng-cours-grid-hdr span{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;font-weight:700;text-align:center;}
.eng-cours-grid-hdr span:first-child{text-align:left;}
.cours-eng-row{display:contents;}
.cours-eng-row:hover > div{background:rgba(3,155,229,.04);}
.cours-eng-row > div{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:center;}
.cours-eng-row > div.num-cell{justify-content:center;}
.cours-eng-cell-name{flex:1;min-width:160px;}
.cours-name-main{font-weight:600;font-size:13px;}
.cours-name-sub{font-size:11px;color:var(--muted);}
/* Inputs dans le grid */
.h-input{width:80px;padding:6px 8px;background:rgba(255,255,255,.08);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px;text-align:center;transition:border-color .2s;}
.h-input:focus{outline:none;border-color:var(--teal);}
.h-input.heures-reel{border-color:rgba(26,188,156,.4);color:var(--teal);}
.h-input.heures-reel:focus{border-color:var(--teal);}
/* Prix/h global */
.prix-heure-bar{display:flex;align-items:center;gap:14px;background:rgba(3,155,229,.06);border:1px solid rgba(3,155,229,.2);border-radius:10px;padding:12px 16px;margin-bottom:14px;}
.prix-heure-bar label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;}
.prix-heure-bar input{background:transparent;border:none;border-bottom:2px solid var(--accent);color:var(--accent);font-size:20px;font-weight:700;width:130px;text-align:center;padding:2px 0;}
.prix-heure-bar input:focus{outline:none;}
.prix-heure-bar .fcfa{font-size:13px;color:var(--muted);}
/* Récap calcul */
.recap-eng{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;}
.recap-cell{background:rgba(0,0,0,.2);border:1px solid var(--border);border-radius:8px;padding:10px 12px;text-align:center;}
.recap-cell .rc-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px;}
.recap-cell .rc-val{font-size:16px;font-weight:700;}
.recap-cell.rc-net{background:rgba(46,204,113,.08);border-color:rgba(46,204,113,.3);}
.recap-cell.rc-net .rc-val{color:var(--green);font-size:20px;}
.recap-cell.rc-ret{background:rgba(231,76,60,.06);border-color:rgba(231,76,60,.2);}
.recap-cell.rc-ret .rc-val{color:var(--red);}
.recap-cell.rc-reduc{background:rgba(230,126,34,.06);border-color:rgba(230,126,34,.2);}
.recap-cell.rc-reduc .rc-val{color:var(--orange);}
.recap-cell.rc-h .rc-val{color:var(--yellow);}
.recap-cell.rc-brut .rc-val{color:var(--muted);}
/* Barre de progression versement */
.verse-bar-wrap{background:rgba(255,255,255,.08);border-radius:20px;height:8px;overflow:hidden;margin:6px 0;}
.verse-bar-fill{height:100%;border-radius:20px;transition:width .5s ease;background:linear-gradient(90deg,var(--teal),var(--green));}
/* Boutons footer modal */
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid var(--border);}
/* Mode toggle paiement libre */
.mode-toggle{display:flex;gap:8px;margin-bottom:14px;}
.mode-btn{flex:1;padding:10px;border:2px solid var(--border);border-radius:8px;background:transparent;color:var(--muted);cursor:pointer;font-size:13px;font-weight:600;transition:all .2s;}
.mode-btn.active{border-color:var(--teal);color:var(--teal);background:rgba(26,188,156,.08);}
.horaire-bloc{background:rgba(26,188,156,.06);border:1px solid rgba(26,188,156,.2);border-radius:12px;padding:16px;margin-bottom:14px;}
.horaire-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px;}
.horaire-input-wrap{background:rgba(0,0,0,.2);border-radius:8px;padding:10px 12px;}
.horaire-input-wrap label{display:block;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;}
.horaire-input{width:100%;background:transparent;border:none;border-bottom:2px solid rgba(255,255,255,.2);color:var(--text);font-size:16px;font-weight:700;padding:4px 0;text-align:center;transition:border-color .2s;}
.horaire-input:focus{outline:none;border-bottom-color:var(--teal);}
.net-final{background:rgba(46,204,113,.1);border:1px solid rgba(46,204,113,.3);border-radius:8px;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;}
.net-final .lbl{font-size:13px;color:var(--muted);font-weight:600;}
.net-final .val{font-size:20px;font-weight:800;color:var(--green);}
.ret-info{background:rgba(231,76,60,.07);border:1px solid rgba(231,76,60,.2);border-radius:8px;padding:9px 13px;font-size:12px;margin-bottom:8px;display:none;}
.ret-row{display:flex;justify-content:space-between;padding:3px 0;}
/* Spinner */
.spinner{border:4px solid rgba(255,255,255,.1);border-left-color:var(--accent);border-radius:50%;width:36px;height:36px;animation:spin 1s linear infinite;margin:20px auto;}
@keyframes spin{to{transform:rotate(360deg);}}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted);}
.empty-state i{font-size:44px;margin-bottom:14px;opacity:.25;}
/* Versement modal */
.verse-modal-info{background:rgba(26,188,156,.08);border:1px solid rgba(26,188,156,.3);border-radius:10px;padding:14px 18px;margin-bottom:16px;}
.verse-modal-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:13px;}
.verse-modal-row:last-child{border-bottom:none;}
.verse-modal-row .lbl{color:var(--muted);}
.verse-modal-row .val{font-weight:700;}
.verse-hist-item{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:rgba(46,204,113,.07);border:1px solid rgba(46,204,113,.2);border-radius:8px;margin-bottom:6px;font-size:12px;}
/* Slider montant */
.montant-slider-wrap{margin:14px 0;}
.montant-display{font-size:28px;font-weight:900;color:var(--green);text-align:center;margin:8px 0;}
.montant-slider{width:100%;height:6px;-webkit-appearance:none;appearance:none;background:rgba(255,255,255,.1);border-radius:3px;outline:none;cursor:pointer;}
.montant-slider::-webkit-slider-thumb{-webkit-appearance:none;width:22px;height:22px;border-radius:50%;background:var(--green);cursor:pointer;}
.montant-presets{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;justify-content:center;}
.preset-btn{padding:5px 12px;background:rgba(255,255,255,.07);border:1px solid var(--border);border-radius:14px;color:var(--muted);font-size:11px;cursor:pointer;font-weight:600;transition:all .2s;}
.preset-btn:hover,.preset-btn.active{background:rgba(46,204,113,.15);color:var(--green);border-color:rgba(46,204,113,.4);}
/* ── Nouvelle grille sélection engagement ── */
.eng-sel-table{width:100%;border-collapse:collapse;font-size:12px;}
.eng-sel-table th{background:rgba(3,155,229,.08);color:var(--accent);padding:8px 10px;text-align:left;font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--border);}
.eng-sel-table td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
.eng-sel-table tr:hover td{background:rgba(3,155,229,.04);}
.eng-row-already td{background:rgba(230,126,34,.04);}
.eng-row-disabled td{opacity:.5;}
.eng-prix-input{width:90px;padding:5px 7px;background:rgba(255,255,255,.08);border:1px solid var(--border);border-radius:6px;color:var(--accent);font-size:12px;font-weight:700;text-align:center;transition:border-color .2s;}
.eng-prix-input:focus{outline:none;border-color:var(--teal);}
.badge-licence{background:rgba(3,155,229,.15);color:var(--accent);}
.badge-master{background:rgba(155,89,182,.15);color:var(--purple);}
.badge-doctorat{background:rgba(212,168,67,.15);color:var(--gold);}
.badge-no-niveau{background:rgba(255,255,255,.07);color:var(--muted);}
.eng-context-box{background:rgba(3,155,229,.05);border:1px solid rgba(3,155,229,.15);border-radius:10px;padding:14px 16px;margin-bottom:14px;}
.eng-recap-new{background:rgba(0,0,0,.2);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:14px;}
.eng-recap-new .rc-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
.eng-recap-new .rc-item{text-align:center;}
.eng-recap-new .rc-lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px;}
.eng-recap-new .rc-val{font-size:16px;font-weight:700;}
.eng-recap-net{background:rgba(46,204,113,.08);border:1px solid rgba(46,204,113,.2);border-radius:8px;padding:10px;}
.eng-recap-net .rc-lbl{color:var(--green);}
.eng-recap-net .rc-val{color:var(--green);font-size:20px;font-weight:800;}
/* Vue Enseignant */
.teacher-view-section{margin-bottom:20px;}
.teacher-view-section h4{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border);}

@media(max-width:768px){
    .stats-grid{grid-template-columns:1fr 1fr;}
    .form-row{grid-template-columns:1fr;}
    .recap-eng{grid-template-columns:1fr 1fr;}
    .eng-cours-grid,.eng-cours-grid-hdr{grid-template-columns:1fr auto auto;}
    .eng-recap-new .rc-grid{grid-template-columns:1fr 1fr;}
}
</style>
</head>
<body>
<header>
<div class="hdr">
    <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle — ISMM</h1>
    <nav><ul>
        <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
        <li><a href="user_management.php"><i class="fas fa-users"></i> Utilisateurs</a></li>
        <li><a href="course_management.php"><i class="fas fa-book"></i> Cours</a></li>
        <li><a href="payment_dashboard.php"><i class="fas fa-credit-card"></i> Étudiants</a></li>
        <li><a href="comptabilite.php"><i class="fas fa-calculator"></i> Comptabilité</a></li>
        <li><a href="payment_admin.php" class="active"><i class="fas fa-users-cog"></i> Personnel</a></li>
        <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
    </ul></nav>
</div>
</header>
<div class="container">
<?php if (!empty($message)): ?>
<div class="alert alert-<?= $message_type==='success'?'success':'error' ?> show">
    <i class="fas fa-<?= $message_type==='success'?'check-circle':'exclamation-circle' ?>"></i>
    <span><?= htmlspecialchars($message) ?></span>
</div>
<?php endif; ?>
<div class="alert alert-success" id="aOk"><i class="fas fa-check-circle"></i><span id="aOkMsg"></span></div>
<div class="alert alert-error"   id="aErr"><i class="fas fa-exclamation-circle"></i><span id="aErrMsg"></span></div>

<div class="page-hdr">
    <div>
        <h2><i class="fas fa-users-cog"></i> Gestion Paiements Personnel</h2>
        <p>Enseignants · Personnel admin · Retenue 9,5% enseignants · Suivi versements</p>
    </div>
    <div class="page-actions">
        <!-- Sélecteur d'année académique -->
        <form method="GET" style="display:inline-flex;align-items:center;gap:8px;margin-right:6px;">
            <label style="font-size:13px;color:#a0b4c8;white-space:nowrap;"><i class="fas fa-calendar-alt"></i> Année :</label>
            <select name="annee" onchange="this.form.submit()" style="background:#0a2a45;color:#e0eaf5;border:1px solid #1e4060;border-radius:6px;padding:6px 10px;font-size:13px;cursor:pointer;">
                <?php foreach ($annees_dispo as $__a): ?>
                <option value="<?= htmlspecialchars($__a) ?>" <?= $__a === $annee_filtre ? 'selected' : '' ?>><?= htmlspecialchars($__a) ?></option>
                <?php endforeach; unset($__a); ?>
            </select>
        </form>
        <button class="btn btn-teal"    onclick="openModal('mEngagement')"><i class="fas fa-chalkboard-teacher"></i> Payer Cours</button>
        <button class="btn btn-primary" onclick="openModal('mPaiementLibre')"><i class="fas fa-plus"></i> Paiement Libre</button>
        <button class="btn btn-success" onclick="openModal('mDepense')"><i class="fas fa-receipt"></i> Dépense</button>
        <button class="btn btn-ghost"   onclick="openModal('mType')"><i class="fas fa-cog"></i> Types</button>
        <button class="btn btn-ghost"   onclick="ouvrirTarifs()">💰 Tarifs</button>
        <button class="btn btn-warning" onclick="openModal('mPersonnel')"><i class="fas fa-user-plus"></i> Ajouter</button>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card green"><i class="fas fa-money-check-alt stat-icon-bg"></i><div class="stat-label">NET VERSÉ <?= htmlspecialchars($annee_filtre) ?> (LIBRE)</div><div class="stat-val green"><?= nf($total_net) ?> FCFA</div><div class="stat-sub">Brut : <?= nf($total_brut) ?> FCFA</div></div>
    <div class="stat-card teal"><i class="fas fa-chalkboard-teacher stat-icon-bg"></i><div class="stat-label">Engagements cours (total)</div><div class="stat-val teal"><?= nf($stats_cours['total_net']) ?> FCFA</div><div class="stat-sub"><?= $stats_cours['total'] ?> engagement(s) — <?= htmlspecialchars($annee_filtre) ?></div></div>
    <div class="stat-card red"><i class="fas fa-hand-holding-usd stat-icon-bg"></i><div class="stat-label">RETENUES DGI <?= htmlspecialchars($annee_filtre) ?></div><div class="stat-val red"><?= nf($total_retenue) ?> FCFA</div><div class="stat-sub">Enseignants uniquement</div></div>
    <div class="stat-card yellow"><i class="fas fa-building stat-icon-bg"></i><div class="stat-label">DÉPENSES <?= htmlspecialchars($annee_filtre) ?></div><div class="stat-val yellow"><?= nf($total_exp) ?> FCFA</div><div class="stat-sub">Opérationnelles</div></div>
    <div class="stat-card blue"><i class="fas fa-user-friends stat-icon-bg"></i><div class="stat-label">Personnel actif</div><div class="stat-val blue"><?= nf($active_staff) ?></div><div class="stat-sub">Employés</div></div>
    <div class="stat-card purple"><i class="fas fa-clock stat-icon-bg"></i><div class="stat-label">Brouillons / En cours</div><div class="stat-val purple"><?= $stats_cours['brouillon'] + $stats_cours['partiel'] ?></div><div class="stat-sub">Engagements à finaliser</div></div>
</div>

<div class="tabs">
    <button class="tab-btn active" onclick="showTab('personnel',this)"><i class="fas fa-users"></i> Personnel</button>
    <button class="tab-btn"        onclick="showTab('cours',this)"><i class="fas fa-chalkboard-teacher"></i> Engagements Cours</button>
    <button class="tab-btn"        onclick="showTab('historique',this)"><i class="fas fa-history"></i> Historique Paiements</button>
    <button class="tab-btn"        onclick="showTab('paiements',this)"><i class="fas fa-money-bill-wave"></i> Paiements Libres</button>
    <button class="tab-btn"        onclick="showTab('depenses',this)"><i class="fas fa-receipt"></i> Dépenses</button>
    <button class="tab-btn"        onclick="showTab('types',this)"><i class="fas fa-tags"></i> Types</button>
    <button class="tab-btn"        onclick="showTab('tracabilite',this);loadAuditLog()"><i class="fas fa-shield-alt"></i> Journal</button>
</div>

<!-- ── Personnel ── -->
<div class="tab-content active" id="tab-personnel">
<?php if (empty($staff_data)): ?>
    <div class="empty-state"><i class="fas fa-user-plus"></i><p>Aucun personnel trouvé.</p></div>
<?php else: ?>
<table class="data-table"><thead><tr>
    <th>Personnel</th><th>Rôle</th><th>Retenue</th><th>Cours assignés</th><th>H. cours</th><th>Net mois (libre)</th><th>Total perçu</th><th>Actions</th>
</tr></thead><tbody>
<?php foreach ($staff_data as $s): ?>
<tr>
    <td><div style="display:flex;align-items:center;gap:10px;">
        <div style="width:36px;height:36px;border-radius:50%;background:<?= $s['role']==='teacher'?'var(--teal)':'var(--purple)' ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0;"><?= strtoupper(substr($s['staff_name'],0,2)) ?></div>
        <div><div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($s['staff_name']) ?></div><div style="font-size:11px;color:var(--muted);"><?= htmlspecialchars($s['staff_id']) ?></div></div>
    </div></td>
    <td><span class="badge badge-<?= $s['role'] ?>"><?= $s['role']==='teacher'?'Enseignant':'Administrateur' ?></span></td>
    <td><?= $s['role']==='teacher'?'<span style="color:var(--red);font-size:12px;font-weight:600;"><i class="fas fa-percent"></i> 9,5%</span>':'<span style="color:var(--muted);font-size:12px;">Exonéré</span>' ?></td>
    <td style="text-align:center;color:var(--accent);font-weight:700;"><?= $s['nb_cours'] ?></td>
    <td style="color:var(--yellow);font-weight:600;"><?= $s['total_heures_cours']>0?number_format($s['total_heures_cours'],1).'h':'—' ?></td>
    <td style="color:var(--green);font-weight:700;"><?= nf($s['monthly_net']) ?> FCFA</td>
    <td style="color:var(--green);font-weight:700;"><?= nf($s['total_net_paid']) ?> FCFA</td>
    <td style="white-space:nowrap;">
        <?php if ($s['role']==='teacher'): ?>
        <button class="btn btn-sm btn-teal" onclick="ouvrirEngagement('<?= $s['staff_id'] ?>','<?= htmlspecialchars(addslashes($s['staff_name'])) ?>')"
                <?= $s['nb_cours'] == 0 ? 'disabled title="Aucun cours assigné"' : '' ?>>
            <i class="fas fa-chalkboard-teacher"></i> Cours</button>
        <button class="btn btn-sm btn-primary" onclick="prefillStaff('<?= $s['staff_id'] ?>','teacher')"><i class="fas fa-plus"></i> Libre</button>
        <button class="btn btn-sm btn-info" onclick="viewTeacher('<?= $s['staff_id'] ?>','<?= htmlspecialchars(addslashes($s['staff_name'])) ?>')" title="Suivi financier"><i class="fas fa-chart-line"></i> Suivi</button>
        <button class="btn btn-sm btn-ghost" onclick="openBulletin('<?= $s['staff_id'] ?>','<?= htmlspecialchars(addslashes($s['staff_name'])) ?>')" title="Bulletin mensuel"><i class="fas fa-file-invoice"></i></button>
        <?php else: ?>
        <button class="btn btn-sm btn-primary" onclick="prefillStaff('<?= $s['staff_id'] ?>','admin')"><i class="fas fa-plus"></i> Libre</button>
        <button class="btn btn-sm btn-ghost" onclick="viewHistory('<?= $s['staff_id'] ?>')" title="Historique paiements"><i class="fas fa-history"></i></button>
        <button class="btn btn-sm btn-ghost" onclick="openBulletin('<?= $s['staff_id'] ?>','<?= htmlspecialchars(addslashes($s['staff_name'])) ?>')" title="Bulletin mensuel"><i class="fas fa-file-invoice"></i></button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>

<!-- ── Engagements Cours ── -->
<div class="tab-content" id="tab-cours">
<?php if (empty($engagements_recents)): ?>
    <div class="empty-state"><i class="fas fa-chalkboard-teacher"></i><p>Aucun engagement cours. Cliquez sur <strong>Payer Cours</strong>.</p></div>
<?php else: ?>

<!-- Barre filtre + compteurs -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
    <span style="font-size:12px;color:var(--muted);font-weight:600;">Filtrer :</span>
    <button class="eng-filter-btn active" data-filter="all"    onclick="filterEng('all',this)">Tous (<?= count($engagements_recents) ?>)</button>
    <button class="eng-filter-btn" data-filter="brouillon"     onclick="filterEng('brouillon',this)">Brouillons (<?= count(array_filter($engagements_recents,fn($r)=>$r['statut']==='brouillon')) ?>)</button>
    <button class="eng-filter-btn" data-filter="partiel"       onclick="filterEng('partiel',this)">En cours (<?= count(array_filter($engagements_recents,fn($r)=>$r['statut']==='partiel')) ?>)</button>
    <button class="eng-filter-btn" data-filter="complete"      onclick="filterEng('complete',this)">Soldés (<?= count(array_filter($engagements_recents,fn($r)=>$r['statut']==='complete')) ?>)</button>
    <button class="eng-filter-btn" data-filter="annule"        onclick="filterEng('annule',this)" style="color:var(--red);">Annulés (<?= count(array_filter($engagements_recents,fn($r)=>$r['statut']==='annule')) ?>)</button>
</div>
<style>
.eng-filter-btn{padding:5px 13px;border:1px solid var(--border);border-radius:16px;background:transparent;color:var(--muted);cursor:pointer;font-size:12px;font-weight:600;transition:all .2s;}
.eng-filter-btn.active{background:rgba(3,155,229,.15);color:var(--accent);border-color:var(--accent);}
.eng-filter-btn:hover:not(.active){background:rgba(255,255,255,.06);color:var(--text);}
tr.eng-row-annule td{opacity:.45;text-decoration:line-through;}
tr.eng-row-annule td:last-child{text-decoration:none;opacity:.65;}
</style>

<table class="data-table">
<thead><tr>
    <th>Enseignant</th><th>Période</th><th>Créé le</th><th>Dernier versement</th><th>Modules</th><th>Heures</th><th>Net total</th><th>Versé</th><th>Restant</th><th>Statut</th><th>Actions</th>
</tr></thead>
<tbody id="engTableBody">
<?php foreach ($engagements_recents as $p):
    $restant  = max(0, floatval($p['montant_total_net']) - floatval($p['total_verse']));
    $annule   = $p['statut'] === 'annule';
    $complete = $p['statut'] === 'complete';
    $last_verse = !empty($p['last_versement']) ? date('d/m/Y H:i', strtotime($p['last_versement'])) : null;
?>
<tr class="eng-tr <?= $annule?'eng-row-annule':'' ?>" data-statut="<?= $p['statut'] ?>">
    <td style="font-weight:600;"><?= htmlspecialchars($p['enseignant_nom']??'—') ?></td>
    <td style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($p['periode']) ?></td>
    <td style="font-size:11px;color:var(--muted);">
        <?= date('d/m/Y', strtotime($p['created_at'])) ?>
        <?php $saisiBadge = $p['created_by_name'] ?? $p['created_by'] ?? null; if ($saisiBadge): ?>
        <div style="margin-top:3px;font-size:10px;color:var(--accent);opacity:.8;">
            <i class="fas fa-user-pen" style="font-size:9px;"></i> <?= htmlspecialchars($saisiBadge) ?>
        </div>
        <?php endif; ?>
    </td>
    <td style="font-size:11px;">
        <?php if ($last_verse): ?>
            <span style="color:var(--teal);font-weight:600;"><i class="fas fa-check-circle" style="font-size:10px;margin-right:3px;"></i><?= $last_verse ?></span>
        <?php else: ?>
            <span style="color:var(--muted);">—</span>
        <?php endif; ?>
    </td>
    <td><span style="background:rgba(3,155,229,.15);color:var(--accent);padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;"><?= $p['nb_modules'] ?> mod.</span></td>
    <td style="color:var(--yellow);font-weight:600;"><?= number_format($p['nb_heures_total'],1) ?>h</td>
    <td style="color:var(--green);font-weight:700;"><?= nf($p['montant_total_net']) ?> FCFA</td>
    <td style="color:var(--teal);"><?= nf($p['total_verse']) ?> FCFA</td>
    <td style="color:<?= $restant>0&&!$annule?'var(--orange)':'var(--green)' ?>;font-weight:600;">
        <?= $annule ? '—' : ($restant>0 ? nf($restant).' FCFA' : '✓ Soldé') ?>
    </td>
    <td><span class="badge badge-<?= $p['statut'] ?>"><?= ucfirst($p['statut']) ?></span></td>
    <td style="white-space:nowrap;">
        <?php if (!$annule && !$complete): ?>
            <button class="btn btn-sm btn-teal" onclick="ouvrirEngagementEdit(<?= $p['id'] ?>)" title="Modifier"><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-success" onclick="ouvrirVersement(<?= $p['id'] ?>)" title="Payer"><i class="fas fa-money-bill"></i> Payer</button>
            <button class="btn btn-sm btn-danger" onclick="confirmerAnnulation(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['enseignant_nom']??'')) ?>','<?= htmlspecialchars(addslashes($p['periode'])) ?>')" title="Annuler cet engagement"><i class="fas fa-ban"></i></button>
        <?php endif; ?>
        <?php if (!$annule): ?>
            <button class="btn btn-sm btn-primary" onclick="ouvrirFacture(<?= $p['id'] ?>)" title="Facture"><i class="fas fa-file-pdf"></i></button>
        <?php else: ?>
            <span style="font-size:11px;color:var(--red);"><i class="fas fa-ban"></i> Annulé</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p style="font-size:11px;color:var(--muted);margin-top:10px;"><i class="fas fa-info-circle"></i> Les engagements annulés sont conservés dans l'historique à titre de traçabilité et ne peuvent pas être supprimés.</p>
<?php endif; ?>
</div>

<!-- ── Historique Global Paiements ── -->
<div class="tab-content" id="tab-historique">
<?php
$total_hg = array_sum(array_column($historique_global,'montant'));
$nb_hg    = count($historique_global);
?>
<!-- Résumé rapide -->
<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;">
    <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 20px;display:flex;align-items:center;gap:12px;">
        <div style="width:38px;height:38px;border-radius:50%;background:rgba(46,204,113,.15);display:flex;align-items:center;justify-content:center;"><i class="fas fa-money-bill-wave" style="color:var(--green);"></i></div>
        <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;">Total versé (tous)</div><div style="font-size:20px;font-weight:800;color:var(--green);"><?= nf($total_hg) ?> FCFA</div></div>
    </div>
    <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 20px;display:flex;align-items:center;gap:12px;">
        <div style="width:38px;height:38px;border-radius:50%;background:rgba(3,155,229,.15);display:flex;align-items:center;justify-content:center;"><i class="fas fa-list" style="color:var(--accent);"></i></div>
        <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;">Nombre de paiements</div><div style="font-size:20px;font-weight:800;color:var(--accent);"><?= $nb_hg ?></div></div>
    </div>
</div>

<?php if (empty($historique_global)): ?>
    <div class="empty-state"><i class="fas fa-history"></i><p>Aucun paiement effectué pour le moment.</p></div>
<?php else:
    $mp_hg=['bank_transfer'=>'Virement','cash'=>'Espèces','check'=>'Chèque','mobile_money'=>'Mobile Money'];
?>
<table class="data-table">
<thead><tr>
    <th>Date paiement</th>
    <th>Personnel</th>
    <th>Type</th>
    <th>Détail</th>
    <th>Montant versé</th>
    <th>Méthode</th>
    <th>N° Reçu</th>
</tr></thead>
<tbody>
<?php foreach ($historique_global as $h):
    $is_cours = $h['type_paiement'] === 'cours';
?>
<tr>
    <td style="white-space:nowrap;">
        <div style="font-weight:600;font-size:13px;"><?= date('d/m/Y', strtotime($h['date_paiement'])) ?></div>
        <div style="font-size:11px;color:var(--muted);"><?= date('H:i', strtotime($h['date_paiement'])) ?></div>
    </td>
    <td>
        <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($h['staff_name']??'—') ?></div>
        <span class="badge badge-<?= $h['staff_role']??'teacher' ?>" style="font-size:10px;"><?= ($h['staff_role']??'')==='teacher'?'Enseignant':'Admin' ?></span>
    </td>
    <td>
        <?php if ($is_cours): ?>
            <span style="background:rgba(26,188,156,.15);color:var(--teal);padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;"><i class="fas fa-chalkboard-teacher" style="margin-right:4px;"></i>Cours</span>
            <?php if ($h['periode']): ?><div style="font-size:11px;color:var(--muted);margin-top:3px;"><?= htmlspecialchars($h['periode']) ?></div><?php endif; ?>
        <?php else: ?>
            <span style="background:rgba(3,155,229,.15);color:var(--accent);padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;"><i class="fas fa-money-check-alt" style="margin-right:4px;"></i>Libre</span>
            <?php if ($h['payment_type_name']): ?><div style="font-size:11px;color:var(--muted);margin-top:3px;"><?= htmlspecialchars($h['payment_type_name']) ?></div><?php endif; ?>
        <?php endif; ?>
    </td>
    <td style="font-size:12px;color:var(--muted);max-width:220px;">
        <?php if ($is_cours && $h['modules']): ?>
            <span title="<?= htmlspecialchars($h['modules']) ?>"><?= htmlspecialchars(mb_substr($h['modules'],0,60)).(mb_strlen($h['modules'])>60?'…':'') ?></span>
        <?php elseif ($h['description']): ?>
            <?= htmlspecialchars(mb_substr($h['description'],0,60)) ?>
        <?php else: ?>
            —
        <?php endif; ?>
    </td>
    <td style="font-weight:800;font-size:15px;color:var(--green);white-space:nowrap;"><?= nf($h['montant']) ?> FCFA</td>
    <td style="font-size:12px;"><?= $mp_hg[$h['payment_method']]??$h['payment_method'] ?></td>
    <td style="font-family:monospace;font-size:11px;color:var(--muted);"><?= htmlspecialchars($h['receipt_number']??'—') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<!-- ── Paiements Libres ── -->
<div class="tab-content" id="tab-paiements">
<?php if (empty($recent_payments)): ?>
    <div class="empty-state"><i class="fas fa-money-bill-wave"></i><p>Aucun paiement libre.</p></div>
<?php else: $mp_lbl=['bank_transfer'=>'Virement','cash'=>'Espèces','check'=>'Chèque','mobile_money'=>'Mobile Money']; ?>
<table class="data-table"><thead><tr>
    <th>Date</th><th>Personnel</th><th>Type</th><th>Heures</th><th>Prix/h</th><th>Brut</th><th>Retenue</th><th>NET</th><th>Méthode</th><th>N° Reçu</th><th>Statut</th><th>Actions</th>
</tr></thead><tbody>
<?php foreach ($recent_payments as $p):
    $brut=floatval($p['amount_brut']??0);$ret=floatval($p['amount_retenue']??0);$net=floatval($p['amount_net']??0);
    $nb_h=floatval($p['nb_heures']??0);$px_h=floatval($p['prix_heure']??0);
?>
<tr>
    <td style="font-size:12px;white-space:nowrap;"><?= date('d/m/Y',strtotime($p['payment_date'])) ?></td>
    <td><div style="font-weight:600;font-size:12px;"><?= htmlspecialchars($p['staff_name']??'—') ?></div><span class="badge badge-<?= $p['staff_role']??'teacher' ?>"><?= ($p['staff_role']??'')==='teacher'?'Ens.':'Admin' ?></span></td>
    <td style="font-size:12px;"><?= htmlspecialchars($p['payment_type_name']??'—') ?></td>
    <td style="text-align:center;color:var(--yellow);font-size:12px;"><?= $nb_h>0?number_format($nb_h,1).'h':'—' ?></td>
    <td style="font-size:11px;color:var(--muted);"><?= $px_h>0?nf($px_h).' F':'—' ?></td>
    <td style="font-size:12px;color:var(--muted);"><?= nf($brut) ?></td>
    <td style="color:var(--red);font-size:12px;"><?= $ret>0?'−'.nf($ret):'—' ?></td>
    <td style="color:var(--green);font-weight:700;"><?= nf($net) ?> F</td>
    <td style="font-size:12px;"><?= $mp_lbl[$p['payment_method']]??$p['payment_method'] ?></td>
    <td style="font-family:monospace;font-size:11px;"><?= htmlspecialchars($p['receipt_number']??'—') ?></td>
    <td><button class="status-toggle <?= $p['status'] ?>" onclick="toggleStatus(<?= $p['id'] ?>,this)"><?php $sl=['processed'=>'✓ Payé','pending'=>'⏳ Attente','cancelled'=>'✕ Annulé'];echo $sl[$p['status']]??$p['status']; ?></button></td>
    <td><button class="btn btn-sm btn-success" onclick="openRecu(<?= $p['id'] ?>)"><i class="fas fa-receipt"></i></button></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>

<!-- ── Dépenses ── -->
<div class="tab-content" id="tab-depenses">
<?php if (empty($recent_expenses)): ?>
    <div class="empty-state"><i class="fas fa-receipt"></i><p>Aucune dépense.</p></div>
<?php else: $exp_cats=['equipment'=>'Équipement','maintenance'=>'Maintenance','utilities'=>'Services','supplies'=>'Fournitures','services'=>'Services pro','other'=>'Autre'];$mp2=['bank_transfer'=>'Virement','cash'=>'Espèces','check'=>'Chèque']; ?>
<table class="data-table"><thead><tr><th>Date</th><th>Type</th><th>Catégorie</th><th>Fournisseur</th><th>Montant</th><th>N° Facture</th><th>Méthode</th><th>Statut</th></tr></thead><tbody>
<?php foreach ($recent_expenses as $e): $ess=['paid'=>'badge-paid','pending'=>'badge-pending','cancelled'=>'badge-cancelled']; ?>
<tr>
    <td><?= date('d/m/Y',strtotime($e['expense_date'])) ?></td>
    <td><strong><?= htmlspecialchars($e['expense_type']) ?></strong></td>
    <td><span class="badge badge-salary"><?= $exp_cats[$e['category']]??$e['category'] ?></span></td>
    <td style="font-size:12px;"><?= htmlspecialchars($e['vendor_name']??'N/A') ?></td>
    <td style="color:var(--red);font-weight:700;"><?= nf($e['amount']) ?> FCFA</td>
    <td style="font-family:monospace;font-size:11px;"><?= htmlspecialchars($e['invoice_number']??'—') ?></td>
    <td style="font-size:12px;"><?= $mp2[$e['payment_method']]??$e['payment_method'] ?></td>
    <td><span class="badge <?= $ess[$e['status']]??'badge-paid' ?>"><?php $esl=['paid'=>'Payé','pending'=>'Attente','cancelled'=>'Annulé'];echo $esl[$e['status']]??$e['status']; ?></span></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>

<!-- ── Types ── -->
<div class="tab-content" id="tab-types">
<table class="data-table"><thead><tr><th>Nom</th><th>Catégorie</th><th>Retenue ?</th><th>Description</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($payment_types as $t): $wr=in_array($t['category'],$cats_r_php); ?>
<tr>
    <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
    <td><span class="badge badge-salary"><?= $cat_names[$t['category']]??$t['category'] ?></span></td>
    <td><?= $wr?'<span style="color:var(--red);font-size:12px;font-weight:600;">✓ Oui (enseignants) 9,5%</span>':'<span style="color:var(--muted);font-size:12px;">Non</span>' ?></td>
    <td style="color:var(--muted);font-size:12px;"><?= htmlspecialchars($t['description']??'—') ?></td>
    <td><button class="btn btn-ghost btn-sm" onclick="openEditType(<?= $t['id'] ?>,'<?= htmlspecialchars(addslashes($t['name'])) ?>','<?= $t['category'] ?>','<?= htmlspecialchars(addslashes($t['description']??'')) ?>')"><i class="fas fa-edit"></i></button></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>

<!-- ── TRAÇABILITÉ ── -->
<div class="tab-content" id="tab-tracabilite">
<style>
.audit-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;padding:14px 16px;background:rgba(0,0,0,.2);border:1px solid var(--border);border-radius:10px;}
.audit-filters select,.audit-filters input{background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:12px;}
.audit-filters input[type=text]{min-width:180px;}
.audit-stats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
.audit-stat-pill{padding:5px 13px;border-radius:16px;font-size:11px;font-weight:700;background:rgba(255,255,255,.06);border:1px solid var(--border);}
.audit-timeline{display:flex;flex-direction:column;gap:0;}
.audit-entry{display:flex;gap:14px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.05);position:relative;}
.audit-entry:last-child{border-bottom:none;}
.audit-icon{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;margin-top:2px;}
.audit-icon.CREATE{background:rgba(46,204,113,.15);color:var(--green);}
.audit-icon.UPDATE{background:rgba(3,155,229,.15);color:var(--accent);}
.audit-icon.PAYMENT{background:rgba(26,188,156,.15);color:var(--teal);}
.audit-icon.CANCEL{background:rgba(231,76,60,.15);color:var(--red);}
.audit-icon.ASSIGN{background:rgba(155,89,182,.15);color:var(--purple);}
.audit-icon.DELETE{background:rgba(231,76,60,.15);color:var(--red);}
.audit-body{flex:1;min-width:0;}
.audit-desc{font-size:12px;font-weight:600;margin-bottom:4px;word-break:break-word;}
.audit-meta{display:flex;gap:10px;flex-wrap:wrap;align-items:center;font-size:11px;color:var(--muted);}
.audit-badge{padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;text-transform:uppercase;}
.audit-badge.engagement{background:rgba(26,188,156,.12);color:var(--teal);}
.audit-badge.versement{background:rgba(46,204,113,.12);color:var(--green);}
.audit-badge.payment_libre{background:rgba(3,155,229,.12);color:var(--accent);}
.audit-badge.personnel{background:rgba(155,89,182,.12);color:var(--purple);}
.audit-json{display:none;margin-top:8px;background:rgba(0,0,0,.3);border-radius:6px;padding:8px 12px;font-size:11px;font-family:'Courier New',monospace;color:var(--muted);white-space:pre-wrap;word-break:break-all;max-height:160px;overflow-y:auto;}
.audit-toggle{font-size:10px;color:var(--muted);cursor:pointer;text-decoration:underline;margin-left:6px;}
.audit-toggle:hover{color:var(--text);}
</style>

<!-- Stats résumées -->
<div class="audit-stats" id="auditStats"></div>

<!-- Filtres -->
<div class="audit-filters">
    <select id="afType" onchange="loadAuditLog()">
        <option value="">Tous les types</option>
        <option value="CREATE">Création</option>
        <option value="UPDATE">Modification</option>
        <option value="PAYMENT">Versement</option>
        <option value="CANCEL">Annulation</option>
        <option value="ASSIGN">Assignation</option>
    </select>
    <select id="afEntity" onchange="loadAuditLog()">
        <option value="">Toutes entités</option>
        <option value="engagement">Engagements</option>
        <option value="versement">Versements</option>
        <option value="payment_libre">Paiements libres</option>
        <option value="personnel">Personnel</option>
    </select>
    <input type="date" id="afFrom" onchange="loadAuditLog()" title="Date début">
    <input type="date" id="afTo"   onchange="loadAuditLog()" title="Date fin">
    <input type="text" id="afSearch" placeholder="Rechercher…" oninput="clearTimeout(window._auditTimer);window._auditTimer=setTimeout(loadAuditLog,400)">
    <button class="btn btn-ghost btn-sm" onclick="loadAuditLog()"><i class="fas fa-sync"></i> Actualiser</button>
    <button class="btn btn-sm" style="background:rgba(46,204,113,.15);color:var(--green);border:1px solid rgba(46,204,113,.3);" onclick="exportAuditCSV()"><i class="fas fa-file-csv"></i> CSV</button>
</div>

<!-- Timeline -->
<div id="auditTimeline"><div class="empty-state"><i class="fas fa-shield-alt"></i><p>Cliquez sur l'onglet pour charger le journal.</p></div></div>
</div>

</div>

<!-- ════════════════════════════════════════════════════
     MODAL : ENGAGEMENT COURS — Grille sélection par cours
════════════════════════════════════════════════════ -->
<div class="modal" id="mEngagement">
<div class="modal-box wide" style="max-width:1020px;">
    <div class="modal-hdr">
        <h3 id="engTitre"><i class="fas fa-chalkboard-teacher"></i> Engagement Cours</h3>
        <span class="modal-close" onclick="fermerEngagement()">&times;</span>
    </div>

    <!-- Étape 1 : Contexte -->
    <div class="eng-context-box">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--accent);margin-bottom:12px;">
            <i class="fas fa-filter" style="margin-right:5px;"></i> Étape 1 — Contexte
        </div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:flex-end;">
            <div class="form-group" style="margin-bottom:0;">
                <label>Enseignant *</label>
                <select id="engSelEns" class="form-control">
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($staff_data as $e): if ($e['role']==='teacher'): ?>
                    <option value="<?= htmlspecialchars($e['staff_id']) ?>" data-nom="<?= htmlspecialchars($e['staff_name']) ?>">
                        <?= htmlspecialchars($e['staff_name']) ?> (<?= $e['nb_cours'] ?> cours)
                    </option>
                    <?php endif; endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Semestre *</label>
                <select id="engSemestre" class="form-control">
                    <option value="S1">S1</option>
                    <option value="S2" selected>S2</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Année académique *</label>
                <select id="engAnnee" class="form-control">
                    <?php foreach ($annees_dispo as $__a): ?>
                    <option value="<?= htmlspecialchars($__a) ?>" <?= $__a === ANNEE_ACADEMIQUE_COURANTE ? 'selected' : '' ?>><?= htmlspecialchars($__a) ?></option>
                    <?php endforeach; unset($__a); ?>
                </select>
            </div>
            <div>
                <button type="button" class="btn btn-primary" onclick="loadCoursEnseignant()">
                    <i class="fas fa-sync"></i> Charger les cours
                </button>
            </div>
        </div>
    </div>

    <!-- Étape 2 : Grille des cours -->
    <div id="engCoursZone">
        <div class="empty-state" style="padding:20px;">
            <i class="fas fa-book" style="font-size:30px;margin-bottom:8px;opacity:.2;"></i>
            <p style="font-size:13px;">Sélectionnez un enseignant et cliquez sur "Charger les cours"</p>
        </div>
    </div>

    <!-- Option montant négocié -->
    <div id="engOptionBtn" style="display:none;margin-bottom:12px;">
        <button type="button" id="btnOption" class="btn btn-orange" onclick="toggleOption()">
            <i class="fas fa-handshake"></i> Option : Montant Négocié
        </button>
    </div>
    <div id="engOptionZone" style="display:none;background:rgba(230,126,34,.06);border:1px solid rgba(230,126,34,.25);border-radius:12px;padding:16px;margin-bottom:14px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--orange);letter-spacing:.8px;margin-bottom:12px;"><i class="fas fa-handshake" style="margin-right:6px;"></i> Montant Négocié (override)</div>
        <div class="form-row" style="align-items:center;gap:14px;">
            <div class="form-group" style="margin-bottom:0;">
                <label>Montant accordé (brut)</label>
                <input type="number" id="engMontantAccorde" class="form-control" min="0" step="1000" placeholder="Ex: 450000" oninput="recalcEngNew()">
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding-top:18px;">
                <input type="checkbox" id="engAppliquerRetenue" checked onchange="recalcEngNew()" style="width:16px;height:16px;cursor:pointer;">
                <label for="engAppliquerRetenue" style="font-size:12px;color:var(--muted);cursor:pointer;">Appliquer retenue 9,5%</label>
            </div>
        </div>
    </div>

    <!-- Récap totaux -->
    <div class="eng-recap-new" id="engRecapNew" style="display:none;">
        <div class="rc-grid">
            <div class="rc-item"><div class="rc-lbl">Cours sélectionnés</div><div class="rc-val" id="rNbCours" style="color:var(--yellow);">0</div></div>
            <div class="rc-item"><div class="rc-lbl">Sous-total brut</div><div class="rc-val" id="rBrutNew" style="color:var(--muted);">0 F</div></div>
            <div class="rc-item"><div class="rc-lbl" style="color:var(--red);">Retenue IRPP 9,5%</div><div class="rc-val" id="rRetNew" style="color:var(--red);">—</div></div>
            <div class="rc-item eng-recap-net"><div class="rc-lbl">Net à payer</div><div class="rc-val" id="rNetNew">0 FCFA</div></div>
        </div>
    </div>

    <div class="form-group">
        <label>Notes (optionnel)</label>
        <textarea id="engNotes" class="form-control" rows="2" placeholder="Observations…"></textarea>
    </div>
    <input type="hidden" id="engPaiementId" value="">

    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="fermerEngagement()">Annuler</button>
        <button type="button" class="btn btn-primary" id="btnEngSave" onclick="saveEngagement(false, false)" disabled>
            <i class="fas fa-save"></i> Enregistrer l'engagement
        </button>
        <button type="button" class="btn btn-teal" id="btnEngPayer" onclick="saveEngagement(true, false)" disabled>
            <i class="fas fa-money-bill"></i> Enregistrer & Payer
        </button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════
     MODAL : CONFIRMATION DOUBLE PAIEMENT
════════════════════════════════════════════════════ -->
<div class="modal" id="mConfirmDoublePaiement">
<div class="modal-box" style="max-width:540px;">
    <div class="modal-hdr">
        <h3 style="color:var(--orange);"><i class="fas fa-exclamation-triangle"></i> Cours déjà engagés</h3>
        <span class="modal-close" onclick="closeModal('mConfirmDoublePaiement')">&times;</span>
    </div>
    <div style="background:rgba(230,126,34,.08);border:1px solid rgba(230,126,34,.3);border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;">
        <div style="font-weight:700;color:var(--orange);margin-bottom:10px;">⚠️ Les cours suivants ont déjà un engagement actif ce semestre :</div>
        <div id="conflictsList"></div>
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">Voulez-vous continuer quand même et créer un nouvel engagement pour ces cours ?</p>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('mConfirmDoublePaiement')">Annuler</button>
        <button type="button" class="btn btn-warning" id="btnForceEng" onclick="forceEngagement(false)">
            <i class="fas fa-bolt"></i> Continuer quand même
        </button>
        <button type="button" class="btn btn-teal" id="btnForceEngPay" onclick="forceEngagement(true)" style="display:none;">
            <i class="fas fa-money-bill"></i> Continuer & Payer
        </button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════
     MODAL : VUE ENSEIGNANT
════════════════════════════════════════════════════ -->
<div class="modal" id="mTeacherView">
<div class="modal-box wide" style="max-width:920px;">
    <div class="modal-hdr">
        <h3 id="tvTitre"><i class="fas fa-chart-line"></i> Suivi Financier</h3>
        <span class="modal-close" onclick="closeModal('mTeacherView')">&times;</span>
    </div>
    <div style="display:flex;border-bottom:1px solid var(--border);margin-bottom:14px;gap:0;">
        <button class="tab-btn active" id="tvTabSuivi" onclick="tvShowTab('suivi')"><i class="fas fa-chart-line"></i> Suivi</button>
        <button class="tab-btn" id="tvTabHist" onclick="tvShowTab('historique')"><i class="fas fa-history"></i> Historique</button>
    </div>
    <div id="tvContent"><div style="text-align:center;padding:30px;"><div class="spinner"></div></div></div>
    <div id="tvHistContent" style="display:none;"></div>
</div>
</div>

<!-- ════════════════════════════════════════════════════
     MODAL : VERSEMENT
     On choisit combien on paye sur le montant total
════════════════════════════════════════════════════ -->
<div class="modal" id="mVersement">
<div class="modal-box">
    <div class="modal-hdr">
        <h3><i class="fas fa-money-bill-wave"></i> Enregistrer un Versement</h3>
        <span class="modal-close" onclick="closeModal('mVersement')">&times;</span>
    </div>

    <!-- Info engagement -->
    <div class="verse-modal-info" id="verseInfo">
        <div class="verse-modal-row"><span class="lbl">Enseignant</span><span class="val" id="verseNom">—</span></div>
        <div class="verse-modal-row" id="verseSaisiParRow" style="display:none;"><span class="lbl" style="color:var(--muted);">Engagement saisi par</span><span class="val" id="verseSaisiPar" style="color:var(--accent);font-size:12px;">—</span></div>
        <div class="verse-modal-row"><span class="lbl">Montant total net</span><span class="val" id="verseNet" style="color:var(--green);">0 FCFA</span></div>
        <div class="verse-modal-row"><span class="lbl">Déjà versé</span><span class="val" id="verseDejaVerse" style="color:var(--teal);">0 FCFA</span></div>
        <div class="verse-modal-row"><span class="lbl" style="font-weight:700;">Reste à payer</span><span class="val" id="verseRestant" style="color:var(--orange);font-size:16px;">0 FCFA</span></div>
    </div>

    <!-- Historique versements précédents -->
    <div id="verseHistZone" style="margin-bottom:14px;display:none;">
        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;font-weight:700;">Versements précédents</div>
        <div id="verseHistItems"></div>
    </div>

    <!-- Heures réelles modifiables (CAS 1 ou CAS 3) -->
    <div id="verseHeuresZone" style="display:none;margin-bottom:14px;">
        <div style="font-size:11px;color:var(--teal);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;font-weight:700;"><i class="fas fa-pencil-alt" style="margin-right:5px;"></i>Heures réelles effectuées</div>
        <div id="verseHeuresList"></div>
        <button type="button" class="btn btn-sm btn-teal" style="margin-top:8px;" onclick="saveHeuresReelles()"><i class="fas fa-save"></i> Mettre à jour les heures</button>
    </div>

    <!-- Slider montant -->
    <div class="form-group">
        <label>Montant à verser maintenant</label>
        <div class="montant-slider-wrap">
            <div class="montant-display" id="verseSliderDisplay">0 FCFA</div>
            <input type="range" class="montant-slider" id="verseSlider" min="0" max="1000000" step="1000" value="0" oninput="onSliderChange()">
            <div class="montant-presets" id="versePresets">
                <button class="preset-btn" onclick="setPreset(0.25)">25%</button>
                <button class="preset-btn" onclick="setPreset(0.5)">50%</button>
                <button class="preset-btn" onclick="setPreset(0.6)">60%</button>
                <button class="preset-btn" onclick="setPreset(1)">Tout (<?= '' ?><span id="presetTotal"></span>)</button>
            </div>
        </div>
        <input type="number" id="verseMontantInput" class="form-control" style="margin-top:10px;font-size:16px;font-weight:700;text-align:center;" placeholder="Ou saisir le montant exact…" min="1" oninput="onMontantInput()">
    </div>

    <div class="form-row">
        <div class="form-group">
            <label>Méthode de paiement</label>
            <select id="verseMethMethod" class="form-control">
                <option value="cash">💵 Espèces</option>
                <option value="mobile_money">📱 Mobile Money</option>
                <option value="bank_transfer">🏦 Virement bancaire</option>
                <option value="check">📄 Chèque</option>
            </select>
        </div>
        <div class="form-group">
            <label>Notes (optionnel)</label>
            <input type="text" id="verseNotes" class="form-control" placeholder="Tranche 1, solde, acompte…">
        </div>
    </div>
    <input type="hidden" id="versePaiementId" value="">

    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('mVersement')">Annuler</button>
        <button type="button" class="btn btn-success" id="btnVerseSave" onclick="saveVersement()" disabled>
            <i class="fas fa-check"></i> Confirmer le versement
        </button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════
     MODAL : FACTURE SIMPLIFIÉE
════════════════════════════════════════════════════ -->
<div class="modal" id="mFacture">
<div class="modal-box wide">
    <div class="modal-hdr">
        <h3><i class="fas fa-file-pdf"></i> Facture d'Honoraires</h3>
        <span class="modal-close" onclick="closeModal('mFacture')">&times;</span>
    </div>
    <div id="factureContent"><div style="text-align:center;padding:30px;"><div class="spinner"></div></div></div>
    <div style="display:flex;justify-content:center;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">
        <button class="btn btn-primary" onclick="printDoc('printableFacture')"><i class="fas fa-print"></i> Imprimer</button>
        <button class="btn btn-success" onclick="dlPDF('printableFacture','facture_ISMM')"><i class="fas fa-download"></i> PDF</button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════
     MODAL : PAIEMENT LIBRE
════════════════════════════════════════════════════ -->
<div class="modal" id="mPaiementLibre">
<div class="modal-box">
    <div class="modal-hdr">
        <h3><i class="fas fa-money-check-alt"></i> Paiement Libre</h3>
        <span class="modal-close" onclick="closeModal('mPaiementLibre')">&times;</span>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="add_staff_payment">
        <input type="hidden" name="mode_paiement" id="hiddenMode" value="horaire">
        <div class="form-group">
            <label>Personnel *</label>
            <select name="staff_id" id="staffSel" class="form-control" required onchange="onStaffChange()">
                <option value="">— Sélectionner —</option>
                <?php foreach ($staff_data as $s): ?>
                <option value="<?= htmlspecialchars($s['staff_id']) ?>" data-role="<?= $s['role'] ?>">
                    <?= htmlspecialchars($s['staff_name']) ?> (<?= $s['role']==='teacher'?'Enseignant — retenue 9,5%':'Admin — exonéré' ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mode-toggle">
            <button type="button" class="mode-btn active" id="btnModeH" onclick="setMode('horaire')"><i class="fas fa-clock"></i> Mode Horaire</button>
            <button type="button" class="mode-btn" id="btnModeL" onclick="setMode('libre')"><i class="fas fa-pen"></i> Montant Libre</button>
        </div>
        <div class="horaire-bloc" id="blocHoraire">
            <div class="horaire-grid">
                <div class="horaire-input-wrap"><label>Heures</label><input type="number" class="horaire-input" id="inpH" name="nb_heures" min="0.5" step="0.5" value="1" oninput="calcHoraire('h')"></div>
                <div class="horaire-input-wrap"><label>Prix/heure (FCFA)</label><input type="number" class="horaire-input" style="color:var(--accent);" id="inpP" name="prix_heure" min="1" step="1" value="<?= $TARIF_DEFAULT ?>" oninput="calcHoraire('p')"></div>
                <div class="horaire-input-wrap"><label>Total brut (FCFA)</label><input type="number" class="horaire-input" style="color:var(--green);" id="inpT" min="1" step="1" value="<?= $TARIF_DEFAULT ?>" oninput="calcHoraire('t')"></div>
            </div>
            <div class="ret-info" id="retInfo">
                <div class="ret-row"><span style="color:var(--muted);">Brut</span><span id="retBrut" style="font-weight:700;"></span></div>
                <div class="ret-row"><span style="color:var(--red);">Retenue DGI 9,5%</span><span id="retRet" style="color:var(--red);font-weight:700;"></span></div>
            </div>
            <div class="net-final"><span class="lbl">NET À VERSER</span><span class="val" id="netVal"><?= nf($TARIF_DEFAULT) ?> FCFA</span></div>
        </div>
        <div style="display:none;" id="blocLibre">
            <div class="form-group"><label>Montant Brut (FCFA) *</label><input type="number" name="amount_brut" id="amtBrut" class="form-control" min="1" step="1" oninput="calcLibre()"></div>
            <div class="ret-info" id="retInfoL" style="margin-bottom:10px;">
                <div class="ret-row"><span style="color:var(--red);">Retenue 9,5%</span><span id="retRetL" style="color:var(--red);font-weight:700;"></span></div>
                <div class="ret-row"><span style="color:var(--muted);">NET</span><span id="retNetL" style="color:var(--green);font-weight:700;font-size:15px;"></span></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Type de Paiement *</label>
                <select name="payment_type_id" id="typeSel" class="form-control" required onchange="onTypeChange()">
                    <option value="">— Choisir —</option>
                    <?php $cc=''; foreach ($payment_types as $t): if ($t['category']!==$cc){if($cc!=='')echo'</optgroup>';echo'<optgroup label="'.($cat_names[$t['category']]??$t['category']).'">';$cc=$t['category'];} ?>
                    <option value="<?= $t['id'] ?>" data-category="<?= $t['category'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; if(!empty($payment_types))echo'</optgroup>'; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Méthode</label>
                <select name="payment_method" class="form-control">
                    <option value="cash">Espèces</option><option value="mobile_money">Mobile Money</option>
                    <option value="bank_transfer">Virement</option><option value="check">Chèque</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Statut initial</label>
                <select name="statut_initial" class="form-control">
                    <option value="processed">✓ Payé immédiatement</option>
                    <option value="pending">⏳ En attente</option>
                </select>
            </div>
            <div class="form-group"><label>Référence</label><input type="text" name="reference" class="form-control"></div>
        </div>
        <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('mPaiementLibre')">Annuler</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
    </form>
</div>
</div>

<!-- Dépense -->
<div class="modal" id="mDepense">
<div class="modal-box">
    <div class="modal-hdr"><h3><i class="fas fa-receipt"></i> Nouvelle Dépense</h3><span class="modal-close" onclick="closeModal('mDepense')">&times;</span></div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="add_operational_expense">
        <div class="form-row">
            <div class="form-group"><label>Type *</label><input type="text" name="expense_type" class="form-control" required></div>
            <div class="form-group"><label>Catégorie *</label>
                <select name="category" class="form-control" required>
                    <option value="equipment">Équipement</option><option value="maintenance">Maintenance</option>
                    <option value="utilities">Services</option><option value="supplies">Fournitures</option>
                    <option value="services">Services pro</option><option value="other">Autre</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Montant (FCFA) *</label><input type="number" name="amount" class="form-control" min="1" required></div>
            <div class="form-group"><label>Date *</label><input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Fournisseur</label><input type="text" name="vendor_name" class="form-control"></div>
            <div class="form-group"><label>N° Facture</label><input type="text" name="invoice_number" class="form-control"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Méthode *</label><select name="payment_method" class="form-control" required><option value="cash">Espèces</option><option value="bank_transfer">Virement</option><option value="check">Chèque</option></select></div>
            <div class="form-group"><label>Référence</label><input type="text" name="reference" class="form-control"></div>
        </div>
        <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('mDepense')">Annuler</button>
            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
    </form>
</div>
</div>

<!-- Type / Edit Type / Personnel / Historique / Bulletin / Reçu -->
<div class="modal" id="mType"><div class="modal-box"><div class="modal-hdr"><h3><i class="fas fa-tags"></i> Nouveau Type</h3><span class="modal-close" onclick="closeModal('mType')">&times;</span></div>
<form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="action" value="add_payment_type">
<div class="form-group"><label>Nom *</label><input type="text" name="type_name" class="form-control" required></div>
<div class="form-group"><label>Catégorie *</label><select name="type_category" class="form-control" required><option value="">— Choisir —</option><option value="salary">Salaire</option><option value="bonus">Prime</option><option value="allowance">Indemnités</option><option value="social">Charges Sociales</option></select></div>
<div class="info-box"><i class="fas fa-info-circle" style="color:var(--accent);"></i> Salaire, Prime, Indemnités → retenue 9,5% pour les enseignants.</div>
<div class="form-group"><label>Description</label><textarea name="type_description" class="form-control" rows="2"></textarea></div>
<div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('mType')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Ajouter</button></div>
</form></div></div>

<div class="modal" id="mEditType"><div class="modal-box"><div class="modal-hdr"><h3><i class="fas fa-edit"></i> Modifier Type</h3><span class="modal-close" onclick="closeModal('mEditType')">&times;</span></div>
<form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="action" value="edit_payment_type"><input type="hidden" name="type_id" id="editTypeId">
<div class="form-group"><label>Nom *</label><input type="text" name="type_name" id="editTypeName" class="form-control" required></div>
<div class="form-group"><label>Catégorie</label><select name="type_category" id="editTypeCat" class="form-control"><option value="salary">Salaire</option><option value="bonus">Prime</option><option value="allowance">Indemnités</option><option value="social">Charges Sociales</option></select></div>
<div class="form-group"><label>Description</label><textarea name="type_description" id="editTypeDesc" class="form-control" rows="2"></textarea></div>
<div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('mEditType')">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button></div>
</form></div></div>

<div class="modal" id="mPersonnel"><div class="modal-box"><div class="modal-hdr"><h3><i class="fas fa-user-plus"></i> Nouveau Personnel</h3><span class="modal-close" onclick="closeModal('mPersonnel')">&times;</span></div>
<form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="action" value="add_staff_member">
<div class="form-row"><div class="form-group"><label>Nom *</label><input type="text" name="staff_name" class="form-control" required></div><div class="form-group"><label>Email *</label><input type="email" name="staff_email" class="form-control" required></div></div>
<div class="form-row"><div class="form-group"><label>Téléphone</label><input type="tel" name="staff_phone" class="form-control"></div><div class="form-group"><label>Rôle *</label><select id="staffRoleSel" name="staff_role" class="form-control" required onchange="onPersonnelRoleChange()"><option value="">— Choisir —</option><option value="teacher">Enseignant (retenue 9,5%)</option><option value="admin">Administrateur (exonéré)</option></select></div></div>
<div class="form-group"><label>Adresse</label><textarea name="staff_address" class="form-control" rows="2"></textarea></div>
<div id="coursAssignZone" style="display:none;margin-bottom:14px;">
    <label style="display:block;margin-bottom:8px;color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;"><i class="fas fa-book" style="color:var(--accent);margin-right:5px;"></i>Cours à assigner (optionnel)</label>
    <div id="coursAssignList" style="max-height:200px;overflow-y:auto;background:rgba(0,0,0,.15);border:1px solid var(--border);border-radius:8px;padding:10px;">
        <div style="color:var(--muted);font-size:12px;text-align:center;">Chargement…</div>
    </div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('mPersonnel')">Annuler</button><button type="submit" class="btn btn-warning"><i class="fas fa-user-plus"></i> Ajouter</button></div>
</form></div></div>

<div class="modal" id="mHistorique"><div class="modal-box wide"><div class="modal-hdr"><h3><i class="fas fa-history"></i> Historique Paiements Libres</h3><span class="modal-close" onclick="closeModal('mHistorique')">&times;</span></div><div id="historiqueContent"><div style="text-align:center;padding:30px;"><div class="spinner"></div></div></div></div></div>

<div class="modal" id="mBulletin"><div class="modal-box wide">
    <div class="modal-hdr"><h3><i class="fas fa-file-invoice"></i> Bulletin — <span id="bulletinStaffName"></span></h3><span class="modal-close" onclick="closeModal('mBulletin')">&times;</span></div>
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
        <select id="bulletinMois" class="form-control" style="width:140px;"><option value="01">Janvier</option><option value="02">Février</option><option value="03">Mars</option><option value="04">Avril</option><option value="05">Mai</option><option value="06">Juin</option><option value="07">Juillet</option><option value="08">Août</option><option value="09">Septembre</option><option value="10">Octobre</option><option value="11">Novembre</option><option value="12">Décembre</option></select>
        <select id="bulletinAnnee" class="form-control" style="width:100px;"><?php for($y=date('Y');$y>=date('Y')-3;$y--):?><option value="<?=$y?>"><?=$y?></option><?php endfor;?></select>
        <button class="btn btn-primary btn-sm" onclick="loadBulletin()"><i class="fas fa-search"></i> Générer</button>
    </div>
    <div id="bulletinContent"><div class="empty-state"><i class="fas fa-file-invoice" style="opacity:.2;"></i><p>Choisissez un mois.</p></div></div>
    <div id="bulletinActions" style="display:none;text-align:center;margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">
        <button class="btn btn-primary" onclick="printDoc('printableBulletin')"><i class="fas fa-print"></i> Imprimer</button>
        <button class="btn btn-success" onclick="dlPDF('printableBulletin','bulletin_ISMM')" style="margin-left:10px;"><i class="fas fa-download"></i> PDF</button>
    </div>
</div></div>

<div class="modal" id="mRecu"><div class="modal-box wide"><div class="modal-hdr"><h3><i class="fas fa-receipt"></i> Reçu de Caisse</h3><span class="modal-close" onclick="closeModal('mRecu')">&times;</span></div><div id="recuContent"><div style="text-align:center;padding:30px;"><div class="spinner"></div></div></div><div style="display:flex;justify-content:center;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid var(--border);"><button class="btn btn-primary" onclick="printDoc('printableRecu')"><i class="fas fa-print"></i> Imprimer</button><button class="btn btn-success" onclick="dlPDF('printableRecu','recu_ISMM')"><i class="fas fa-download"></i> PDF</button></div></div></div>

<!-- ════════════════════════════════════════════════════
     MODAL : ANNULATION ENGAGEMENT (motif obligatoire)
════════════════════════════════════════════════════ -->
<div class="modal" id="mAnnulation">
<div class="modal-box" style="max-width:480px;">
    <div class="modal-hdr">
        <h3 style="color:var(--red);"><i class="fas fa-ban"></i> Annuler l'engagement</h3>
        <span class="modal-close" onclick="closeModal('mAnnulation')">&times;</span>
    </div>

    <div id="annulInfoBox" style="background:rgba(231,76,60,.08);border:1px solid rgba(231,76,60,.25);border-radius:10px;padding:12px 16px;margin-bottom:16px;">
        <div style="font-size:13px;font-weight:600;" id="annulNomPeriode">—</div>
        <div style="font-size:11px;color:var(--muted);margin-top:3px;">L'engagement sera marqué <strong style="color:var(--red);">Annulé</strong> et conservé dans l'historique pour traçabilité. Action irréversible.</div>
    </div>

    <div class="form-group">
        <label style="color:var(--red);">Motif d'annulation <span style="color:var(--red);">*</span></label>
        <textarea id="annulMotif" class="form-control" rows="3"
            placeholder="Ex : Erreur de saisie, cours non effectué, doublon, désistement enseignant…"
            oninput="document.getElementById('btnAnnulConfirm').disabled = this.value.trim().length < 5;"
            style="border-color:rgba(231,76,60,.4);resize:vertical;"></textarea>
        <div style="font-size:11px;color:var(--muted);margin-top:5px;">Minimum 5 caractères. Ce motif sera enregistré dans les notes de l'engagement.</div>
    </div>

    <input type="hidden" id="annulPid" value="">

    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('mAnnulation')">Retour</button>
        <button type="button" class="btn btn-danger" id="btnAnnulConfirm" disabled onclick="executerAnnulation()">
            <i class="fas fa-ban"></i> Confirmer l'annulation
        </button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════
     MODAL : CHOIX COMPTABLE ANNULATION AVEC VERSEMENTS
════════════════════════════════════════════════════ -->
<div class="modal" id="mAnnulVersements">
<div class="modal-box" style="max-width:520px;">
    <div class="modal-hdr">
        <h3 style="color:var(--yellow);"><i class="fas fa-exclamation-triangle"></i> Versements déjà effectués</h3>
        <span class="modal-close" onclick="closeModal('mAnnulVersements')">&times;</span>
    </div>

    <div id="annulVersementsMsg" style="background:rgba(243,156,18,.08);border:1px solid rgba(243,156,18,.4);border-radius:10px;padding:14px 16px;margin-bottom:20px;color:var(--yellow);font-size:13px;font-weight:600;">—</div>

    <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">Comment souhaitez-vous procéder ?</p>

    <div style="display:flex;flex-direction:column;gap:10px;">
        <button type="button" class="btn btn-warning" style="justify-content:flex-start;flex-direction:column;align-items:flex-start;padding:12px 16px;" onclick="confirmerAvecAction('none')">
            <span><i class="fas fa-ban"></i> Annuler sans impact comptable</span>
            <span style="font-size:11px;font-weight:400;opacity:.8;margin-top:3px;">Erreur de saisie — l'argent a déjà été payé correctement à l'enseignant</span>
        </button>
        <button type="button" class="btn btn-danger" style="justify-content:flex-start;flex-direction:column;align-items:flex-start;padding:12px 16px;" onclick="confirmerAvecAction('reverse')">
            <span><i class="fas fa-undo"></i> Annuler et contre-passer les écritures</span>
            <span style="font-size:11px;font-weight:400;opacity:.8;margin-top:3px;">Remboursement requis — les écritures versements seront annulées en comptabilité</span>
        </button>
        <button type="button" class="btn btn-ghost" style="opacity:.65;" onclick="confirmerAvecAction('none')">
            <i class="fas fa-times"></i> Annuler quand même (sans action comptable)
        </button>
    </div>

    <div class="modal-footer" style="margin-top:20px;">
        <button type="button" class="btn btn-ghost" onclick="closeModal('mAnnulVersements')">Retour</button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════
     MODAL : TARIFS HORAIRES
════════════════════════════════════════════════════ -->
<div class="modal" id="mTarifs">
<div class="modal-box" style="max-width:480px;">
    <div class="modal-hdr">
        <h3><i class="fas fa-money-bill-wave"></i> Tarifs horaires par niveau</h3>
        <span class="modal-close" onclick="closeModal('mTarifs')">&times;</span>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:16px;">
        Ces tarifs sont appliqués par défaut lors de la création d'un engagement cours, selon le niveau LMD de la filière associée.
    </div>
    <table class="data-table" style="margin-bottom:18px;">
        <thead>
            <tr>
                <th>Niveau</th>
                <th style="text-align:right;">Tarif / heure (FCFA)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><span class="badge badge-licence">Licence</span></td>
                <td style="text-align:right;">
                    <input type="number" id="tarifsInput-licence" min="1" step="100" value="7500"
                           style="width:130px;text-align:right;background:rgba(255,255,255,.07);border:1px solid var(--border);color:inherit;padding:6px 10px;border-radius:6px;font-size:13px;">
                    <span style="font-size:11px;color:var(--muted);">F/h</span>
                </td>
            </tr>
            <tr>
                <td><span class="badge badge-master">Master</span></td>
                <td style="text-align:right;">
                    <input type="number" id="tarifsInput-master" min="1" step="100" value="10000"
                           style="width:130px;text-align:right;background:rgba(255,255,255,.07);border:1px solid var(--border);color:inherit;padding:6px 10px;border-radius:6px;font-size:13px;">
                    <span style="font-size:11px;color:var(--muted);">F/h</span>
                </td>
            </tr>
            <tr>
                <td><span class="badge badge-doctorat">Doctorat</span></td>
                <td style="text-align:right;">
                    <input type="number" id="tarifsInput-doctorat" min="1" step="100" value="12000"
                           style="width:130px;text-align:right;background:rgba(255,255,255,.07);border:1px solid var(--border);color:inherit;padding:6px 10px;border-radius:6px;font-size:13px;">
                    <span style="font-size:11px;color:var(--muted);">F/h</span>
                </td>
            </tr>
        </tbody>
    </table>
    <div style="display:flex;align-items:center;gap:12px;">
        <button type="button" class="btn btn-primary" onclick="saveTarifs()">
            <i class="fas fa-save"></i> Enregistrer tous les tarifs
        </button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('mTarifs')">Annuler</button>
        <span id="tarifsModalMsg" style="font-size:12px;"></span>
    </div>
</div>
</div>

<?php include '../includes/footer.php'; ?>
<script>
const fmt = n => new Intl.NumberFormat('fr-FR').format(Math.round(parseFloat(n)||0));
const TAUX = 0.095;
const TARIF = <?= $TARIF_DEFAULT ?>;
const CATS_R = ['salary','bonus','allowance'];
const ANNEE_FILTRE  = <?= json_encode($annee_filtre) ?>;
const ANNEE_COURANTE = <?= json_encode(ANNEE_ACADEMIQUE_COURANTE) ?>;

function openModal(id){document.getElementById(id).style.display='block';}
function closeModal(id){document.getElementById(id).style.display='none';}
window.addEventListener('click',e=>{if(e.target.classList.contains('modal'))e.target.style.display='none';});
function showTab(name,btn){document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));document.getElementById('tab-'+name).classList.add('active');btn.classList.add('active');}
function showAlert(msg,type='success'){const id=type==='success'?'aOk':'aErr',mid=type==='success'?'aOkMsg':'aErrMsg';document.getElementById(mid).textContent=msg;const el=document.getElementById(id);el.classList.add('show');setTimeout(()=>el.classList.remove('show'),5500);}
function escHtml(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function fmtDate(d){if(!d)return'—';return new Date(d).toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'});}

// ═══════════════════════════════════════════════════════════
// MODULE TARIFS HORAIRES
// ═══════════════════════════════════════════════════════════
function ouvrirTarifs() {
    const msg = document.getElementById('tarifsModalMsg');
    if (msg) msg.textContent = '';
    fetch('?action=get_tarifs')
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            ['licence','master','doctorat'].forEach(niv => {
                const inp = document.getElementById('tarifsInput-'+niv);
                if (inp && d.tarifs[niv] !== undefined) inp.value = Math.round(d.tarifs[niv]);
            });
        });
    openModal('mTarifs');
}

async function saveTarifs() {
    const tarifs = {};
    ['licence','master','doctorat'].forEach(niv => {
        const inp = document.getElementById('tarifsInput-'+niv);
        if (inp) tarifs[niv] = parseFloat(inp.value) || 0;
    });
    const msg = document.getElementById('tarifsModalMsg');
    try {
        const resp = await fetch('?action=save_tarifs', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(tarifs)
        });
        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Erreur');
        if (msg) { msg.style.color = 'var(--green)'; msg.textContent = '✓ Tarifs enregistrés'; }
        setTimeout(() => { if (msg) msg.textContent = ''; closeModal('mTarifs'); }, 1800);
    } catch(e) {
        if (msg) { msg.style.color = 'var(--red)'; msg.textContent = e.message; }
    }
}

// ═══════════════════════════════════════════════════════════
// MODULE ENGAGEMENT COURS — Grille sélection par cours
// ═══════════════════════════════════════════════════════════
let _engCours    = [];   // tous les cours retournés par get_cours_enseignant
let _engSelected = {};   // {course_id: boolean}
let _engIsTeacher= true;
let _engSavedId  = null;
let _optionActive= false;
let _engForPay   = false;  // si on veut payer immédiatement après save

const NIVEAU_LABELS = {licence:'Licence', master:'Master', doctorat:'Doctorat', null:'—'};
const NIVEAU_CLASSES= {licence:'badge-licence', master:'badge-master', doctorat:'badge-doctorat'};

function niveauBadge(n) {
    const cls = NIVEAU_CLASSES[n] || 'badge-no-niveau';
    return `<span class="badge ${cls}" style="font-size:10px;">${NIVEAU_LABELS[n]||'N/A'}</span>`;
}

function ouvrirEngagement(eid, nom) {
    _engSavedId = null; _optionActive = false; _engCours = []; _engSelected = {};
    document.getElementById('engPaiementId').value = '';
    document.getElementById('engTitre').innerHTML = '<i class="fas fa-chalkboard-teacher"></i> Engagement Cours — '+escHtml(nom);
    const sel = document.getElementById('engSelEns');
    for (const o of sel.options) if (o.value === eid) { o.selected = true; break; }
    document.getElementById('engOptionBtn').style.display  = 'none';
    document.getElementById('engOptionZone').style.display = 'none';
    document.getElementById('engRecapNew').style.display   = 'none';
    document.getElementById('engCoursZone').innerHTML = '<div class="empty-state" style="padding:20px;"><i class="fas fa-book" style="font-size:30px;margin-bottom:8px;opacity:.2;"></i><p style="font-size:13px;">Cliquez sur "Charger les cours"</p></div>';
    document.getElementById('btnEngSave').disabled  = true;
    document.getElementById('btnEngPayer').disabled = true;
    openModal('mEngagement');
}

function ouvrirEngagementEdit(pid) {
    fetch('?action=get_engagement&id='+pid)
        .then(r=>r.json())
        .then(d=>{
            if (!d.success){showAlert(d.error||'Erreur','error');return;}
            const eng = d.engagement;
            _engSavedId = eng.id; _optionActive = false;
            document.getElementById('engPaiementId').value = eng.id;
            document.getElementById('engTitre').innerHTML = '<i class="fas fa-edit"></i> Modifier Engagement — '+escHtml(eng.enseignant_nom||'');
            const semSel = document.getElementById('engSemestre');
            const anSel  = document.getElementById('engAnnee');
            if (eng.semestre) { for (const o of semSel.options) if(o.value===eng.semestre){o.selected=true;break;} }
            if (eng.annee_academique) { for (const o of anSel.options) if(o.value===eng.annee_academique){o.selected=true;break;} }
            document.getElementById('engNotes').value = eng.notes || '';
            const sel = document.getElementById('engSelEns');
            for (const o of sel.options) if(o.value===eng.enseignant_id){o.selected=true;break;}
            // Charger les cours en excluant l'engagement courant
            const url = '?action=get_cours_enseignant&enseignant_id='+encodeURIComponent(eng.enseignant_id)
                +'&semestre='+encodeURIComponent(eng.semestre||'S1')
                +'&annee_academique='+encodeURIComponent(eng.annee_academique||ANNEE_COURANTE)
                +'&exclude_paiement_id='+eng.id;
            fetch(url).then(r=>r.json()).then(dc=>{
                if (!dc.success) return;
                _engCours = dc.cours;
                _engIsTeacher = true;
                // Pré-sélectionner les cours de l'engagement
                const savedIds = new Set((eng.cours||[]).map(c=>c.course_id));
                _engSelected = {};
                _engCours.forEach(c=>{ _engSelected[c.course_id] = savedIds.has(c.course_id); });
                renderEngTable(_engCours);
                // Restaurer prix/h sauvegardés
                (eng.cours||[]).forEach(c=>{
                    const inp = document.getElementById('engPrix-'+c.course_id);
                    if (inp) inp.value = parseFloat(c.prix_heure||7500).toFixed(0);
                });
                // Restaurer montant négocié CAS 3
                const mn = parseFloat(eng.montant_negocie||0);
                if (mn > 0) {
                    _optionActive = true;
                    document.getElementById('engOptionZone').style.display = 'block';
                    document.getElementById('engOptionBtn').style.display  = 'block';
                    document.getElementById('engMontantAccorde').value = mn;
                    const cbRet = document.getElementById('engAppliquerRetenue');
                    if (cbRet) cbRet.checked = parseInt(eng.retenue_appliquee??1)===1;
                    const btnOpt = document.getElementById('btnOption');
                    if (btnOpt){btnOpt.style.background='var(--green)';btnOpt.innerHTML='<i class="fas fa-check"></i> Option active — Montant Négocié';}
                }
                recalcEngNew();
            });
            openModal('mEngagement');
        });
}

function fermerEngagement() {
    closeModal('mEngagement');
    _engSavedId = null; _engCours = []; _engSelected = {}; _optionActive = false;
    document.getElementById('engPaiementId').value = '';
    document.getElementById('engCoursZone').innerHTML = '<div class="empty-state" style="padding:20px;"><i class="fas fa-book" style="font-size:30px;margin-bottom:8px;opacity:.2;"></i><p style="font-size:13px;">Sélectionnez un enseignant et cliquez sur "Charger les cours"</p></div>';
    document.getElementById('engRecapNew').style.display  = 'none';
    document.getElementById('engOptionZone').style.display= 'none';
    document.getElementById('engOptionBtn').style.display = 'none';
    const btn = document.getElementById('btnOption');
    if (btn){btn.style.background='';btn.innerHTML='<i class="fas fa-handshake"></i> Option : Montant Négocié';}
    document.getElementById('btnEngSave').disabled  = true;
    document.getElementById('btnEngPayer').disabled = true;
}

function loadCoursEnseignant() {
    const eid   = document.getElementById('engSelEns').value;
    const sem   = document.getElementById('engSemestre').value;
    const annee = document.getElementById('engAnnee').value;
    if (!eid) { showAlert('Sélectionnez un enseignant','error'); return; }
    _engCours = []; _engSelected = {}; _optionActive = false;
    document.getElementById('engOptionZone').style.display = 'none';
    document.getElementById('engCoursZone').innerHTML = '<div style="text-align:center;padding:18px;"><div class="spinner"></div></div>';
    document.getElementById('engRecapNew').style.display = 'none';
    const pid = document.getElementById('engPaiementId').value;
    const excl = pid ? '&exclude_paiement_id='+pid : '';
    fetch('?action=get_cours_enseignant&enseignant_id='+encodeURIComponent(eid)+'&semestre='+encodeURIComponent(sem)+'&annee_academique='+encodeURIComponent(annee)+excl)
        .then(r=>r.json())
        .then(d=>{
            if (!d.success){showAlert(d.error||'Erreur chargement','error');return;}
            _engCours = d.cours;
            _engIsTeacher = true;
            _engSelected = {};
            _engCours.forEach(c=>{
                // Sélectionner par défaut les cours sans engagement existant
                _engSelected[c.course_id] = !c.engagement_existant;
            });
            renderEngTable(_engCours);
            recalcEngNew();
        });
}

function renderEngTable(cours) {
    const zone = document.getElementById('engCoursZone');
    if (!cours.length) {
        zone.innerHTML = '<div class="empty-state" style="padding:16px;"><i class="fas fa-book-open"></i><p>Aucun cours assigné à cet enseignant pour ce semestre.</p></div>';
        document.getElementById('engRecapNew').style.display = 'none';
        document.getElementById('engOptionBtn').style.display= 'none';
        document.getElementById('btnEngSave').disabled = true;
        document.getElementById('btnEngPayer').disabled= true;
        return;
    }

    const rows = cours.map(c => {
        const isSel  = _engSelected[c.course_id] !== false;
        const hasEng = !!c.engagement_existant;
        const hPrev  = parseFloat(c.heures_prevues||0);
        const hEff   = parseFloat(c.heures_effectuees||0);
        const prix   = parseFloat(c.tarif_horaire||7500);
        const montant= Math.round(hPrev * prix);
        const badgeEng = hasEng
            ? `<span class="badge" style="background:rgba(230,126,34,.15);color:var(--orange);font-size:10px;">⚠️ Déjà engagé (${escHtml(c.engagement_existant.statut)})</span>`
            : '<span style="color:var(--muted);font-size:11px;">—</span>';
        const hEffDisplay = hEff > 0 ? hEff+'h' : '—';
        const hEffBadge = hEff > hPrev
            ? `<span style="color:var(--orange);font-size:10px;display:block;">⚠️ Dépassement</span>`
            : '';
        return `<tr id="eng-row-${c.course_id}" class="${hasEng?'eng-row-already':''}">
            <td style="text-align:center;">
                <input type="checkbox" id="engChk-${c.course_id}"
                       ${isSel?'checked':''}
                       onchange="onCoursCheck(${c.course_id}, this.checked)"
                       style="width:15px;height:15px;cursor:pointer;">
            </td>
            <td>
                <div style="font-weight:600;">${escHtml(c.course_name)}</div>
                <div style="font-size:10px;color:var(--muted);">ID: ${c.course_id}</div>
            </td>
            <td>${niveauBadge(c.niveau_lmd)}</td>
            <td style="text-align:center;color:var(--yellow);font-weight:600;">${hPrev>0?hPrev+'h':'—'}</td>
            <td style="text-align:center;color:var(--muted);font-style:italic;font-size:11px;"
                title="Heures documentées dans la progression — pour information">
                ${hEffDisplay}${hEffBadge}
            </td>
            <td style="text-align:center;">
                <input type="number" id="engPrix-${c.course_id}" value="${prix.toFixed(0)}"
                       min="1" step="100" class="eng-prix-input"
                       oninput="onPrixHeureRowChange(${c.course_id})"
                       title="Prix/heure pour ce cours">
            </td>
            <td style="text-align:right;font-weight:700;color:var(--green);" id="engMont-${c.course_id}">${fmt(montant)} F</td>
            <td>${badgeEng}</td>
        </tr>`;
    }).join('');

    zone.innerHTML = `
        <div style="font-size:11px;color:var(--muted);margin-bottom:8px;">
            <i class="fas fa-info-circle" style="color:var(--accent);"></i>
            Montant = heures prévues × prix/h — Cochez les cours à inclure dans l'engagement
        </div>
        <table class="eng-sel-table">
            <thead><tr>
                <th style="width:36px;text-align:center;">
                    <input type="checkbox" id="engCheckAll" onchange="toggleAllCours(this.checked)" title="Tout cocher/décocher">
                </th>
                <th>Cours</th>
                <th>Niveau</th>
                <th style="text-align:center;">H.Prévues</th>
                <th style="text-align:center;">H.Effectuées</th>
                <th style="text-align:center;">Prix/h</th>
                <th style="text-align:right;">Montant</th>
                <th>Statut</th>
            </tr></thead>
            <tbody>${rows}</tbody>
            <tfoot>
                <tr style="border-top:2px solid var(--border);background:rgba(0,0,0,.2);">
                    <td colspan="6" style="text-align:right;font-weight:700;font-size:13px;padding:10px;color:var(--muted);">TOTAL</td>
                    <td style="text-align:right;font-weight:800;font-size:15px;color:var(--green);padding:10px;" id="engTotalCell">0 FCFA</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>`;

    document.getElementById('engOptionBtn').style.display = 'block';
    document.getElementById('engRecapNew').style.display  = 'block';
    document.getElementById('btnEngSave').disabled  = false;
    document.getElementById('btnEngPayer').disabled = false;
}

function toggleAllCours(checked) {
    _engCours.forEach(c => {
        _engSelected[c.course_id] = checked;
        const chk = document.getElementById('engChk-'+c.course_id);
        if (chk) chk.checked = checked;
    });
    recalcEngNew();
}

function onCoursCheck(courseId, checked) {
    _engSelected[courseId] = checked;
    recalcEngNew();
}

function onPrixHeureRowChange(courseId) {
    const prix = parseFloat(document.getElementById('engPrix-'+courseId)?.value||7500);
    const cours = _engCours.find(c=>c.course_id===courseId);
    if (!cours) return;
    const hPrev = parseFloat(cours.heures_prevues||0);
    const mont = Math.round(hPrev * prix);
    const el = document.getElementById('engMont-'+courseId);
    if (el) el.textContent = fmt(mont)+' F';
    recalcEngNew();
}

function recalcEngNew() {
    const optionActive = _optionActive;
    let totalBrut = 0; let nbSel = 0;

    _engCours.forEach(c => {
        if (!_engSelected[c.course_id]) return;
        nbSel++;
        const prix  = parseFloat(document.getElementById('engPrix-'+c.course_id)?.value||c.tarif_horaire||7500);
        const hPrev = parseFloat(c.heures_prevues||0);
        totalBrut += Math.round(hPrev * prix);
    });

    let brut, ret, net;
    if (optionActive) {
        const ma    = parseFloat(document.getElementById('engMontantAccorde')?.value||0);
        const apRet = document.getElementById('engAppliquerRetenue')?.checked ?? true;
        brut = ma > 0 ? ma : totalBrut;
        ret  = (_engIsTeacher && apRet) ? Math.round(brut*TAUX) : 0;
    } else {
        brut = totalBrut;
        ret  = _engIsTeacher ? Math.round(brut*TAUX) : 0;
    }
    net = brut - ret;

    document.getElementById('rNbCours').textContent  = nbSel;
    document.getElementById('rBrutNew').textContent  = fmt(brut)+' F';
    document.getElementById('rRetNew').textContent   = ret>0 ? '−'+fmt(ret)+' F' : '—';
    document.getElementById('rNetNew').textContent   = fmt(net)+' FCFA';
    const tc = document.getElementById('engTotalCell');
    if (tc) tc.textContent = fmt(totalBrut)+' FCFA';
    document.getElementById('engRecapNew').style.display = nbSel>0 ? 'block' : 'none';
}

function toggleOption() {
    if (!_engCours || !_engCours.length) return;
    _optionActive = !_optionActive;
    const zone   = document.getElementById('engOptionZone');
    const btnOpt = document.getElementById('btnOption');
    if (_optionActive) {
        zone.style.display = 'block';
        btnOpt.style.background = 'var(--green)';
        btnOpt.innerHTML = '<i class="fas fa-check"></i> Option active — Montant Négocié';
    } else {
        zone.style.display = 'none';
        btnOpt.style.background = '';
        btnOpt.innerHTML = '<i class="fas fa-handshake"></i> Option : Montant Négocié';
    }
    recalcEngNew();
}

// Construit la liste des cours sélectionnés pour envoi
function buildSelectedCoursList() {
    return _engCours
        .filter(c => _engSelected[c.course_id])
        .map(c => ({
            course_id: c.course_id,
            course_name: c.course_name,
            nb_heures_prevues: parseFloat(c.heures_prevues||0),
            nb_heures: parseFloat(c.heures_prevues||0),
            prix_heure: parseFloat(document.getElementById('engPrix-'+c.course_id)?.value||c.tarif_horaire||7500),
        }));
}

// Sauvegarde l'engagement — forPay=vrai si on veut payer, force=vrai si on bypass double-paiement
async function saveEngagement(forPay=false, force=false) {
    const eid      = document.getElementById('engSelEns').value;
    const semestre = document.getElementById('engSemestre').value;
    const annee    = document.getElementById('engAnnee').value;
    const notes    = document.getElementById('engNotes').value;
    const pid      = document.getElementById('engPaiementId').value || null;

    const cours_list = buildSelectedCoursList();
    let montant_negocie   = null;
    let retenue_appliquee = 1;
    if (_optionActive) {
        const ma = parseFloat(document.getElementById('engMontantAccorde')?.value||0);
        if (ma>0) montant_negocie = ma;
        retenue_appliquee = document.getElementById('engAppliquerRetenue')?.checked ? 1 : 0;
    }

    if (!eid) { showAlert('Sélectionnez un enseignant','error'); return null; }
    if (!cours_list.length) { showAlert('Sélectionnez au moins un cours','error'); return null; }

    // Vérification JS immédiate des conflits
    if (!force) {
        const conflicts = cours_list.filter(c => {
            const orig = _engCours.find(oc => oc.course_id === c.course_id);
            return orig && orig.engagement_existant;
        }).map(c => {
            const orig = _engCours.find(oc => oc.course_id === c.course_id);
            return {
                course_id:     c.course_id,
                course_name:   c.course_name,
                engagement_id: orig.engagement_existant.id,
                statut:        orig.engagement_existant.statut,
            };
        });
        if (conflicts.length) {
            showConflictModal(conflicts, forPay);
            return null;
        }
    }

    const btn = document.getElementById('btnEngSave');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement…';
    try {
        const resp = await fetch('?action=save_engagement', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                enseignant_id: eid, semestre, annee_academique: annee,
                cours: cours_list, notes, paiement_id: pid,
                montant_negocie, retenue_appliquee, force
            })
        });
        const data = await resp.json();
        if (data.needs_confirm) {
            // PHP a aussi détecté des conflits
            showConflictModal(data.conflicts, forPay);
            return null;
        }
        if (!data.success) throw new Error(data.error||'Erreur');
        _engSavedId = data.paiement_id;
        document.getElementById('engPaiementId').value = data.paiement_id;
        showAlert('Engagement enregistré — Net : '+fmt(data.net)+' FCFA');
        if (forPay) {
            fermerEngagement();
            setTimeout(()=>ouvrirVersement(data.paiement_id), 300);
        } else {
            setTimeout(()=>location.reload(), 1500);
        }
        return data;
    } catch(e) {
        showAlert(e.message,'error'); return null;
    } finally {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer l\'engagement';
    }
}

// Modal confirmation double paiement
function showConflictModal(conflicts, forPay) {
    const list = conflicts.map(c =>
        `<div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:12px;">
            <strong>${escHtml(c.course_name)}</strong>
            <span style="color:var(--orange);margin-left:6px;">statut: ${escHtml(c.statut)}</span>
            <span style="color:var(--muted);font-size:11px;margin-left:6px;">engagement #${c.engagement_id}</span>
        </div>`
    ).join('');
    document.getElementById('conflictsList').innerHTML = list;
    document.getElementById('btnForceEng').onclick     = () => forceEngagement(false);
    document.getElementById('btnForceEngPay').onclick  = () => forceEngagement(true);
    document.getElementById('btnForceEngPay').style.display = forPay ? '' : 'none';
    window._engForcePayPending = forPay;
    openModal('mConfirmDoublePaiement');
}

function forceEngagement(forPay) {
    closeModal('mConfirmDoublePaiement');
    saveEngagement(forPay, true);
}

async function engSaveThenPay() {
    await saveEngagement(true, false);
}


// ═══════════════════════════════════════════════════════════
// MODULE VERSEMENT
// ═══════════════════════════════════════════════════════════
let _verseRestant = 0;
let _verseNetTotal = 0;

function ouvrirVersement(pid) {
    document.getElementById('versePaiementId').value = pid;
    document.getElementById('verseSlider').value     = 0;
    document.getElementById('verseMontantInput').value = '';
    document.getElementById('verseSliderDisplay').textContent = '0 FCFA';
    document.getElementById('btnVerseSave').disabled = true;
    document.getElementById('verseHistZone').style.display    = 'none';
    document.getElementById('verseHeuresZone').style.display  = 'none';
    openModal('mVersement');

    fetch('?action=get_engagement&id='+pid)
        .then(r=>r.json())
        .then(d=>{
            if (!d.success){showAlert(d.error||'Erreur','error');closeModal('mVersement');return;}
            const eng = d.engagement;
            _verseNetTotal = parseFloat(eng.montant_total_net||0);
            _verseRestant  = parseFloat(eng.restant||0);

            document.getElementById('verseNom').textContent       = eng.enseignant_nom||'—';
            const verseSaisi = eng.created_by_name || eng.created_by || null;
            document.getElementById('verseSaisiParRow').style.display = verseSaisi ? '' : 'none';
            document.getElementById('verseSaisiPar').textContent = verseSaisi ? verseSaisi : '—';
            document.getElementById('verseNet').textContent       = fmt(_verseNetTotal)+' FCFA';
            document.getElementById('verseDejaVerse').textContent = fmt(eng.total_verse||0)+' FCFA';
            document.getElementById('verseRestant').textContent   = fmt(_verseRestant)+' FCFA';
            document.getElementById('presetTotal').textContent    = fmt(_verseRestant);

            const slider = document.getElementById('verseSlider');
            slider.max   = Math.ceil(_verseRestant/1000)*1000;
            slider.value = Math.ceil(_verseRestant/1000)*1000;
            onSliderChange();

            if (eng.versements && eng.versements.length > 0) {
                document.getElementById('verseHistZone').style.display='block';
                const mp={cash:'Espèces',mobile_money:'Mobile Money',bank_transfer:'Virement',check:'Chèque'};
                document.getElementById('verseHistItems').innerHTML = eng.versements.map(v=>`
                    <div class="verse-hist-item">
                        <span>${fmtDate(v.date_versement)} — ${mp[v.payment_method]||v.payment_method}</span>
                        <span style="font-weight:700;color:var(--green);">${fmt(v.montant)} FCFA</span>
                        <span style="font-size:11px;color:var(--muted);">
                            ${v.receipt_number||''}
                            ${v.processed_by_name ? `<br><i class="fas fa-user-pen" style="font-size:9px;"></i> ${escHtml(v.processed_by_name)}` : ''}
                        </span>
                    </div>`).join('');
            }

            // Zone heures réelles modifiables (CAS 1 ou CAS 3)
            const montNeg = parseFloat(eng.montant_negocie||0);
            const cours   = eng.cours||[];
            const isCas1  = cours.length === 1;
            const isCas3  = cours.length >= 2 && montNeg > 0;
            if ((isCas1 || isCas3) && cours.length > 0) {
                document.getElementById('verseHeuresZone').style.display = 'block';
                document.getElementById('verseHeuresList').innerHTML = cours.map(c=>{
                    const hPrev   = parseFloat(c.nb_heures_prevues||c.nb_heures||0);
                    const hToday  = parseFloat(c.heures_today||0);
                    const delta   = hToday - hPrev;
                    let badge = '';
                    if (delta > 0)
                        badge = `<span style="color:var(--orange);font-size:10px;font-weight:700;">⚠️ +${delta.toFixed(1)}h au-delà du contrat</span>`;
                    else if (delta < 0)
                        badge = `<span style="color:var(--accent);font-size:10px;">📊 ${Math.abs(delta).toFixed(1)}h restantes à effectuer</span>`;
                    return `<div style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06);">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;">
                            <span style="font-size:12px;font-weight:600;">${escHtml(c.course_name)}</span>
                            ${badge}
                        </div>
                        <div style="display:flex;gap:16px;font-size:11px;color:var(--muted);margin-bottom:6px;">
                            <span><i class="fas fa-clock" style="color:var(--yellow);margin-right:3px;"></i>H. Prévues (base paiement) : <strong style="color:var(--yellow);">${hPrev.toFixed(1)}h</strong></span>
                            <span style="font-style:italic;">H. Effectuées aujourd'hui : <strong style="color:${hToday>0?'var(--green)':'var(--muted)'};">${hToday.toFixed(1)}h</strong></span>
                        </div>
                    </div>`;
                }).join('');
            }
        });
}

async function saveHeuresReelles() {
    const pid = document.getElementById('versePaiementId').value;
    const cours_heures = [];
    document.querySelectorAll('[id^="verseHr-"]').forEach(inp=>{
        const course_id = parseInt(inp.id.replace('verseHr-',''));
        cours_heures.push({course_id, nb_heures: parseFloat(inp.value)||0});
    });
    try {
        const resp = await fetch('?action=update_heures_reelles', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({paiement_id: pid, cours_heures})
        });
        const data = await resp.json();
        if (!data.success) throw new Error(data.error||'Erreur');
        showAlert('Heures réelles mises à jour.');
    } catch(e) { showAlert(e.message,'error'); }
}

function onSliderChange() {
    const v = parseInt(document.getElementById('verseSlider').value)||0;
    document.getElementById('verseSliderDisplay').textContent = fmt(v)+' FCFA';
    document.getElementById('verseMontantInput').value = v;
    document.querySelectorAll('.preset-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('btnVerseSave').disabled = v <= 0;
}

function onMontantInput() {
    const v = parseInt(document.getElementById('verseMontantInput').value)||0;
    const slider = document.getElementById('verseSlider');
    slider.value = Math.min(v, slider.max);
    document.getElementById('verseSliderDisplay').textContent = fmt(v)+' FCFA';
    document.getElementById('btnVerseSave').disabled = v <= 0;
}

function setPreset(ratio) {
    const v = Math.round(_verseRestant * ratio);
    document.getElementById('verseSlider').value = Math.min(v, parseInt(document.getElementById('verseSlider').max));
    document.getElementById('verseMontantInput').value = v;
    document.getElementById('verseSliderDisplay').textContent = fmt(v)+' FCFA';
    document.querySelectorAll('.preset-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('btnVerseSave').disabled = v <= 0;
}

function saveVersement() {
    const pid    = document.getElementById('versePaiementId').value;
    const montant= parseInt(document.getElementById('verseMontantInput').value)||0;
    const method = document.getElementById('verseMethMethod').value;
    const notes  = document.getElementById('verseNotes').value;
    if (montant<=0){showAlert('Montant invalide','error');return;}
    if (montant>_verseRestant+1){showAlert('Montant supérieur au restant dû','error');return;}

    const btn = document.getElementById('btnVerseSave');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Traitement…';

    fetch('?action=save_versement',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({paiement_id:pid,montant,payment_method:method,notes})
    }).then(r=>r.json()).then(d=>{
        if (!d.success){showAlert(d.error||'Erreur','error');btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Confirmer le versement';return;}
        showAlert('Versement enregistré — '+d.receipt+' — Restant : '+fmt(d.restant)+' FCFA');
        closeModal('mVersement');
        setTimeout(()=>location.reload(),1500);
    });
}

// ═══════════════════════════════════════════════════════════
// FACTURE SIMPLIFIÉE
// ═══════════════════════════════════════════════════════════
function ouvrirFacture(pid){
    document.getElementById('factureContent').innerHTML='<div style="text-align:center;padding:30px;"><div class="spinner"></div></div>';
    openModal('mFacture');
    fetch('?action=get_engagement&id='+pid)
        .then(r=>r.json())
        .then(d=>{
            if(!d.success){document.getElementById('factureContent').innerHTML='<p style="color:var(--red);text-align:center;">Erreur</p>';return;}
            document.getElementById('factureContent').innerHTML=buildFacture(d.engagement);
        });
}

function buildFacture(eng){
    const today=new Date();
    const day=String(today.getDate()).padStart(2,'0'),mon=String(today.getMonth()+1).padStart(2,'0'),yr=today.getFullYear();
    const ret  = parseFloat(eng.montant_retenue||0);
    const net  = parseFloat(eng.montant_total_net||0);
    const tv   = parseFloat(eng.total_verse||0);
    const rest = parseFloat(eng.restant||0);
    const montNeg = parseFloat(eng.montant_negocie||0);
    const cours = eng.cours||[];
    const nbMod = cours.length;

    // Détecter le cas
    const isCas1 = nbMod === 1 && !montNeg;
    const isCas3 = nbMod >= 2 && montNeg > 0;
    // brut théorique = somme des montants par cours (h_reel×prix pour CAS 1, h_prev×prix pour CAS 2/3)
    const theoBrut = cours.reduce((s,c)=>s+parseFloat(c.montant||0),0);
    // brut effectif utilisé pour le calcul net
    const brut = isCas3 ? montNeg : theoBrut;

    const verseRows=(eng.versements||[]).map(v=>`
        <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #eee;font-size:11px;">
            <span>${fmtDate(v.date_versement)}</span>
            <span style="font-weight:700;color:#27ae60;">${fmt(v.montant)} FCFA</span>
            <span style="color:#888;">${v.receipt_number||''}</span>
        </div>`).join('');

    // Tableau des modules selon le cas
    let tableHeader, moduleRows, totColspan;
    if (isCas1) {
        tableHeader = `<tr style="background:#1abc9c;color:#fff;">
            <th style="padding:7px 10px;text-align:left;border:1px solid #17a589;">Module / Cours</th>
            <th style="padding:7px 10px;text-align:center;border:1px solid #17a589;">H. réelles</th>
            <th style="padding:7px 10px;text-align:right;border:1px solid #17a589;">Prix/h</th>
            <th style="padding:7px 10px;text-align:right;border:1px solid #17a589;">Montant</th>
        </tr>`;
        totColspan = 3;
        moduleRows = cours.map((c,i)=>`<tr style="${i%2?'background:#f8f8f8':''}">
            <td style="padding:7px 10px;border:1px solid #ddd;font-weight:600;">${escHtml(c.course_name)}</td>
            <td style="padding:7px 10px;border:1px solid #ddd;text-align:center;font-weight:700;color:#1abc9c;">${parseFloat(c.nb_heures||0).toFixed(1)}h</td>
            <td style="padding:7px 10px;border:1px solid #ddd;text-align:right;">${fmt(c.prix_heure)} FCFA</td>
            <td style="padding:7px 10px;border:1px solid #ddd;text-align:right;font-weight:700;">${fmt(c.montant)} FCFA</td>
        </tr>`).join('');
    } else {
        tableHeader = `<tr style="background:#1abc9c;color:#fff;">
            <th style="padding:7px 10px;text-align:left;border:1px solid #17a589;">Module / Cours</th>
            <th style="padding:7px 10px;text-align:center;border:1px solid #17a589;">H. prévues</th>
            <th style="padding:7px 10px;text-align:center;border:1px solid #17a589;">H. réelles</th>
            <th style="padding:7px 10px;text-align:right;border:1px solid #17a589;">Prix/h</th>
            <th style="padding:7px 10px;text-align:right;border:1px solid #17a589;">Montant théo.</th>
        </tr>`;
        totColspan = 4;
        moduleRows = cours.map((c,i)=>`<tr style="${i%2?'background:#f8f8f8':''}">
            <td style="padding:7px 10px;border:1px solid #ddd;font-weight:600;">${escHtml(c.course_name)}</td>
            <td style="padding:7px 10px;border:1px solid #ddd;text-align:center;">${parseFloat(c.nb_heures_prevues||0).toFixed(1)}h</td>
            <td style="padding:7px 10px;border:1px solid #ddd;text-align:center;font-weight:700;color:#1abc9c;">${parseFloat(c.nb_heures||0).toFixed(1)}h</td>
            <td style="padding:7px 10px;border:1px solid #ddd;text-align:right;">${fmt(c.prix_heure)} FCFA</td>
            <td style="padding:7px 10px;border:1px solid #ddd;text-align:right;font-weight:700;">${fmt(c.montant)} FCFA</td>
        </tr>`).join('');
    }

    // Section calcul net selon le cas
    let netCalcRows;
    if (isCas3) {
        netCalcRows = `
            <tr><td style="padding:5px 10px;color:#666;">Total théorique brut</td><td style="padding:5px 10px;text-align:right;font-weight:600;">${fmt(theoBrut)} FCFA</td></tr>
            <tr style="color:#e67e22;"><td style="padding:5px 10px;font-weight:700;">Montant négocié accordé</td><td style="padding:5px 10px;text-align:right;font-weight:700;">${fmt(montNeg)} FCFA</td></tr>
            ${ret>0?`<tr style="color:#e74c3c;"><td style="padding:5px 10px;">Retenue DGI 9,5%</td><td style="padding:5px 10px;text-align:right;">−${fmt(ret)} FCFA</td></tr>`:''}`;
    } else {
        netCalcRows = `
            <tr><td style="padding:5px 10px;color:#666;">Montant brut</td><td style="padding:5px 10px;text-align:right;font-weight:600;">${fmt(brut)} FCFA</td></tr>
            ${ret>0?`<tr style="color:#e74c3c;"><td style="padding:5px 10px;">Retenue DGI 9,5%</td><td style="padding:5px 10px;text-align:right;">−${fmt(ret)} FCFA</td></tr>`:''}`;
    }

    return `<div id="printableFacture" style="background:#fff;color:#000;padding:30px 38px;max-width:800px;margin:0 auto;font-family:Arial,sans-serif;font-size:12px;line-height:1.6;border:1px solid #ddd;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:14px;margin-bottom:20px;border-bottom:3px solid #1abc9c;">
            <div><div style="font-size:24px;font-weight:900;color:#1abc9c;letter-spacing:2px;">ISMM</div>
                <div style="font-size:11px;color:#555;">École Supérieure de Formation — Groupe CEFIP · Libreville, Gabon</div></div>
            <div style="text-align:right;">
                <div style="font-size:18px;font-weight:900;color:#333;">FACTURE D'HONORAIRES</div>
                <div style="font-size:13px;font-weight:700;color:#1abc9c;margin-top:3px;">Semestre ${escHtml(eng.semestre||'')} — ${escHtml(eng.annee_academique||eng.periode)}</div>
                <div style="font-size:11px;color:#777;">Émise le ${day}/${mon}/${yr}</div>
            </div>
        </div>
        <div style="background:#f4faf8;border:1px solid #c8e6e0;border-radius:6px;padding:12px 16px;margin-bottom:20px;">
            <div style="font-size:10px;color:#1abc9c;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px;">ENSEIGNANT VACATAIRE</div>
            <div style="font-size:14px;font-weight:700;">${escHtml(eng.enseignant_nom||'—')}</div>
            <div style="font-size:11px;color:#555;">${escHtml(eng.enseignant_email||'')} — Matricule : ${escHtml(eng.enseignant_id||'—')}</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;">
            <div style="background:#f0f4f8;border-radius:8px;padding:14px;text-align:center;border:1px solid #ddd;">
                <div style="font-size:10px;color:#666;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Modules</div>
                <div style="font-size:36px;font-weight:900;color:#1abc9c;">${nbMod}</div>
                <div style="font-size:10px;color:#888;">module(s)</div>
            </div>
            <div style="background:#f0f4f8;border-radius:8px;padding:14px;text-align:center;border:1px solid #ddd;">
                <div style="font-size:10px;color:#666;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Net à payer</div>
                <div style="font-size:22px;font-weight:900;color:#333;">${fmt(net)}</div>
                <div style="font-size:10px;color:#888;">FCFA${ret>0?' (retenue déduite)':''}${isCas3?' · montant négocié':''}</div>
            </div>
            <div style="background:${rest>0?'#fff8f0':'#f0faf4'};border-radius:8px;padding:14px;text-align:center;border:1px solid ${rest>0?'#f0d0b0':'#b8e6cc'};">
                <div style="font-size:10px;color:#666;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Déjà versé</div>
                <div style="font-size:22px;font-weight:900;color:${tv>0?'#27ae60':'#999'};">${fmt(tv)}</div>
                <div style="font-size:10px;color:${rest>0?'#e67e22':'#27ae60'};">${rest>0?'Reste : '+fmt(rest)+' FCFA':'✓ Soldé'}</div>
            </div>
        </div>
        <div style="margin-bottom:16px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#444;letter-spacing:1px;margin-bottom:8px;padding-bottom:4px;border-bottom:2px solid #1abc9c;">Détail des modules</div>
            <table style="width:100%;border-collapse:collapse;font-size:11px;">
                <thead>${tableHeader}</thead>
                <tbody>${moduleRows}
                <tr style="background:#e8f8f5;font-weight:700;">
                    <td style="padding:7px 10px;border:1px solid #ddd;" colspan="${totColspan}">TOTAL BRUT</td>
                    <td style="padding:7px 10px;border:1px solid #ddd;text-align:right;">${fmt(theoBrut)} FCFA</td>
                </tr></tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
            <table style="width:280px;border-collapse:collapse;font-size:11px;">
                ${netCalcRows}
                <tr style="background:#1abc9c;color:#fff;"><td style="padding:8px 10px;font-weight:700;font-size:13px;">NET À PAYER</td><td style="padding:8px 10px;text-align:right;font-size:15px;font-weight:900;">${fmt(net)} FCFA</td></tr>
            </table>
        </div>
        ${tv>0?`<div style="border:1px solid ${rest>0?'#e67e22':'#27ae60'};border-radius:8px;padding:14px 18px;margin-bottom:16px;background:${rest>0?'#fff8f0':'#f0faf4'};">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:${rest>0?'#e67e22':'#27ae60'};letter-spacing:1px;margin-bottom:8px;">Versements effectués</div>
            ${verseRows}
            <div style="border-top:1px solid ${rest>0?'#e67e22':'#27ae60'};margin-top:8px;padding-top:8px;display:flex;justify-content:space-between;font-weight:900;color:${rest>0?'#e67e22':'#27ae60'};font-size:14px;">
                <span>${rest>0?'RESTE À PAYER':'SOLDE SOLDÉ ✓'}</span><span>${fmt(rest)} FCFA</span>
            </div>
        </div>`:''}
        <div style="border:1px solid #ccc;padding:7px 12px;background:#f9f9f9;margin-bottom:20px;font-size:11px;"><strong>Arrêté à : </strong><span style="text-transform:capitalize;">${n2w(Math.round(net))} francs CFA</span></div>
        <div style="display:flex;justify-content:space-between;margin-top:32px;padding-top:16px;border-top:1px solid #ddd;">
            <div style="text-align:center;width:190px;"><div style="font-size:12px;font-weight:700;text-decoration:underline;margin-bottom:34px;">L'ENSEIGNANT</div><div style="border-top:1px solid #333;padding-top:4px;font-size:10px;color:#777;">Signature & Date</div></div>
            <div style="text-align:center;width:190px;"><div style="font-size:12px;font-weight:700;text-decoration:underline;margin-bottom:34px;">DIRECTION ISMM</div><div style="border-top:1px solid #333;padding-top:4px;font-size:10px;color:#777;">Signature & Cachet</div></div>
        </div>
        <div style="margin-top:22px;text-align:center;font-size:10px;color:#999;border-top:1px solid #eee;padding-top:10px;">ISMM — Groupe CEFIP — Libreville, Gabon · Université Virtuelle — Coding Enterprise © ${yr}</div>
    </div>`;
}

// ═══════════════════════════════════════════════════════════
// PAIEMENT LIBRE
// ═══════════════════════════════════════════════════════════
let _curRole='teacher',_curMode='horaire';
const CATS_RET=['salary','bonus','allowance'];

function prefillStaff(id,role){
    const sel=document.getElementById('staffSel');
    if(sel) for(const o of sel.options) if(o.value===id){o.selected=true;break;}
    _curRole=role;
    if(role==='teacher'){setMode('horaire');calcHoraire('h');}
    else{setMode('libre');calcLibre();}
    openModal('mPaiementLibre');
}
function onStaffChange(){const sel=document.getElementById('staffSel');_curRole=sel.options[sel.selectedIndex]?.dataset?.role||'teacher';_curMode==='horaire'?calcHoraire('h'):calcLibre();}
function onTypeChange(){_curMode==='horaire'?calcHoraire('h'):calcLibre();}
function hasRet(){if(_curRole!=='teacher')return false;const t=document.getElementById('typeSel');return CATS_RET.includes(t?.options[t.selectedIndex]?.dataset?.category||'');}
function setMode(m){_curMode=m;document.getElementById('hiddenMode').value=m;document.getElementById('blocHoraire').style.display=m==='horaire'?'block':'none';document.getElementById('blocLibre').style.display=m==='libre'?'block':'none';document.getElementById('btnModeH').classList.toggle('active',m==='horaire');document.getElementById('btnModeL').classList.toggle('active',m==='libre');m==='horaire'?calcHoraire('h'):calcLibre();}
function calcHoraire(src){
    const ih=document.getElementById('inpH'),ip=document.getElementById('inpP'),it=document.getElementById('inpT');
    let h=parseFloat(ih.value)||0,p=parseFloat(ip.value)||TARIF,t;
    if(src==='h'||src==='p'){t=Math.round(h*p);it.value=t;}else{t=parseFloat(it.value)||0;p=h>0?Math.round(t/h):TARIF;ip.value=p;}
    t=parseFloat(it.value)||0;
    const ret=hasRet()?Math.round(t*TAUX):0,net=t-ret;
    const ri=document.getElementById('retInfo');ri.style.display=ret>0?'block':'none';
    document.getElementById('retBrut').textContent=fmt(t)+' FCFA';document.getElementById('retRet').textContent=fmt(ret)+' FCFA';
    document.getElementById('netVal').textContent=fmt(net)+' FCFA';
}
function calcLibre(){const b=parseFloat(document.getElementById('amtBrut').value)||0;const ret=hasRet()?Math.round(b*TAUX):0,net=b-ret;const ri=document.getElementById('retInfoL');ri.style.display=(b>0&&hasRet())?'block':'none';document.getElementById('retRetL').textContent=fmt(ret)+' FCFA';document.getElementById('retNetL').textContent=fmt(net)+' FCFA';}
function toggleStatus(pid,btn){fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle_status',id:pid})}).then(r=>r.json()).then(d=>{if(!d.success){showAlert('Erreur','error');return;}const l={processed:'✓ Payé',pending:'⏳ Attente'};btn.textContent=l[d.new_status]||d.new_status;btn.className='status-toggle '+d.new_status;showAlert('Statut mis à jour');});}

// ═══════════════════════════════════════════════════════════
// ANNULATION ENGAGEMENT + FILTRE
// ═══════════════════════════════════════════════════════════
function confirmerAnnulation(pid, nom, periode) {
    // Ouvrir le modal avec motif obligatoire
    document.getElementById('annulPid').value         = pid;
    document.getElementById('annulNomPeriode').textContent = nom + ' — ' + periode;
    document.getElementById('annulMotif').value        = '';
    document.getElementById('btnAnnulConfirm').disabled = true;
    openModal('mAnnulation');
}

function executerAnnulation() {
    const pid   = document.getElementById('annulPid').value;
    const motif = document.getElementById('annulMotif').value.trim();
    if (motif.length < 5) { showAlert('Le motif est trop court.','error'); return; }

    const btn = document.getElementById('btnAnnulConfirm');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Vérification…';

    fetch('?action=annuler_engagement', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id: parseInt(pid), motif})
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-ban"></i> Confirmer l\'annulation';
        if (d.needs_confirm) {
            closeModal('mAnnulation');
            document.getElementById('annulVersementsMsg').textContent = d.message;
            openModal('mAnnulVersements');
            return;
        }
        if (!d.success) {
            showAlert(d.error || 'Erreur', 'error');
            return;
        }
        closeModal('mAnnulation');
        showAlert('Engagement annulé — conservé dans l\'historique.');
        setTimeout(() => location.reload(), 1200);
    });
}

function confirmerAvecAction(comptableAction) {
    const pid   = document.getElementById('annulPid').value;
    const motif = document.getElementById('annulMotif').value.trim();
    closeModal('mAnnulVersements');
    fetch('?action=annuler_engagement', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id: parseInt(pid), motif, confirmed: true, comptable_action: comptableAction})
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) { showAlert(d.error || 'Erreur', 'error'); return; }
        showAlert(comptableAction === 'reverse'
            ? 'Engagement annulé — contre-passation comptable effectuée.'
            : 'Engagement annulé — conservé dans l\'historique.');
        setTimeout(() => location.reload(), 1200);
    });
}

function filterEng(statut, btn) {
    document.querySelectorAll('.eng-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#engTableBody .eng-tr').forEach(tr => {
        const s = tr.dataset.statut;
        tr.style.display = (statut === 'all' || s === statut) ? '' : 'none';
    });
}

// ═══════════════════════════════════════════════════════════
// HISTORIQUE + BULLETIN
// ═══════════════════════════════════════════════════════════
function viewHistory(sid){
    const c=document.getElementById('historiqueContent');
    c.innerHTML='<div style="text-align:center;padding:30px;"><div class="spinner"></div></div>';
    openModal('mHistorique');
    fetch('?action=get_staff_history&staff_id='+encodeURIComponent(sid))
        .then(r=>r.json())
        .then(d=>{
            if(!d.success){c.innerHTML='<p style="color:var(--red);">'+d.error+'</p>';return;}
            const mp={bank_transfer:'Virement',cash:'Espèces',check:'Chèque',mobile_money:'Mobile Money'};
            const rows=d.payments.map(p=>{
                const b=parseFloat(p.amount_brut||0),ret=parseFloat(p.amount_retenue||0),n=parseFloat(p.amount_net||0);
                const nb_h=parseFloat(p.nb_heures||0),px_h=parseFloat(p.prix_heure||0);
                const sl={processed:'✓ Payé',pending:'⏳ Attente',cancelled:'✕ Annulé'};
                return `<tr><td>${new Date(p.payment_date).toLocaleDateString('fr-FR')}</td><td>${p.type_name||'—'}</td><td style="text-align:center;color:var(--yellow);">${nb_h>0?nb_h.toFixed(1)+'h':'—'}</td><td style="font-size:11px;color:var(--muted);">${px_h>0?fmt(px_h)+' F':'—'}</td><td style="color:var(--muted);font-size:12px;">${fmt(b)}</td><td style="color:var(--red);">${ret>0?'−'+fmt(ret):'—'}</td><td style="color:var(--green);font-weight:700;">${fmt(n)} FCFA</td><td>${mp[p.payment_method]||''}</td><td><span class="badge badge-${p.status}">${sl[p.status]||p.status}</span></td></tr>`;
            }).join('');
            c.innerHTML=`<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;"><div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 18px;text-align:center;flex:1;"><div style="font-size:11px;color:var(--muted);text-transform:uppercase;margin-bottom:4px;">Paiements</div><div style="font-size:20px;font-weight:700;color:var(--accent);">${d.count}</div></div><div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 18px;text-align:center;flex:1;"><div style="font-size:11px;color:var(--muted);text-transform:uppercase;margin-bottom:4px;">Net ce mois</div><div style="font-size:18px;font-weight:700;color:var(--green);">${fmt(d.total_month)} FCFA</div></div></div><table class="data-table"><thead><tr><th>Date</th><th>Type</th><th>Heures</th><th>Prix/h</th><th>Brut</th><th>Retenue</th><th>Net</th><th>Méthode</th><th>Statut</th></tr></thead><tbody>${rows||'<tr><td colspan="9" style="text-align:center;color:var(--muted);">Aucun paiement</td></tr>'}</tbody></table>`;
        });
}

let _bulletinSid='';
function openBulletin(sid,nom){_bulletinSid=sid;document.getElementById('bulletinStaffName').textContent=nom;const n=new Date();document.getElementById('bulletinMois').value=String(n.getMonth()+1).padStart(2,'0');document.getElementById('bulletinAnnee').value=n.getFullYear();document.getElementById('bulletinContent').innerHTML='<div class="empty-state"><i class="fas fa-file-invoice" style="opacity:.2;"></i><p>Choisissez un mois.</p></div>';document.getElementById('bulletinActions').style.display='none';openModal('mBulletin');}
function loadBulletin(){const m=document.getElementById('bulletinMois').value,a=document.getElementById('bulletinAnnee').value;const c=document.getElementById('bulletinContent');c.innerHTML='<div style="text-align:center;padding:20px;"><div class="spinner"></div></div>';document.getElementById('bulletinActions').style.display='none';fetch(`?action=get_bulletin_mensuel&staff_id=${encodeURIComponent(_bulletinSid)}&mois=${m}&annee=${a}`).then(r=>r.json()).then(d=>{if(!d.success){c.innerHTML=`<div class="empty-state"><p>${d.error}</p></div>`;return;}if(!d.libres.length&&!d.cours.length){c.innerHTML='<div class="empty-state"><i class="fas fa-inbox"></i><p>Aucun paiement ce mois.</p></div>';return;}c.innerHTML=buildBulletin(d);document.getElementById('bulletinActions').style.display='flex';});}

function buildBulletin(data){
    const mois=['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    const period=mois[parseInt(data.mois)]+' '+data.annee;
    const emp=data.employe;
    const mp={bank_transfer:'Virement',cash:'Espèces',check:'Chèque',mobile_money:'Mobile Money'};
    const yr=data.annee;
    const libresRows=data.libres.map(p=>{const brut=parseFloat(p.amount_brut||0),ret=parseFloat(p.amount_retenue||0),net=parseFloat(p.amount_net||0),nb_h=parseFloat(p.nb_heures||0),px_h=parseFloat(p.prix_heure||0);return `<tr style="border-left:3px solid #039be5;"><td style="padding:5px 9px;border:1px solid #ddd;">${new Date(p.payment_date).toLocaleDateString('fr-FR')}</td><td style="padding:5px 9px;border:1px solid #ddd;"><span style="font-size:10px;background:#e8f4f8;color:#039be5;padding:1px 6px;border-radius:4px;margin-right:4px;">LIBRE</span>${p.type_name||'—'}</td><td style="padding:5px 9px;border:1px solid #ddd;text-align:center;">${nb_h>0?nb_h.toFixed(1)+'h':'—'}</td><td style="padding:5px 9px;border:1px solid #ddd;text-align:right;font-size:11px;">${px_h>0?fmt(px_h)+' F':'—'}</td><td style="padding:5px 9px;border:1px solid #ddd;text-align:right;">${fmt(brut)}</td><td style="padding:5px 9px;border:1px solid #ddd;text-align:right;color:#c00;">${ret>0?fmt(ret):'—'}</td><td style="padding:5px 9px;border:1px solid #ddd;text-align:right;font-weight:bold;">${fmt(net)} FCFA</td><td style="padding:5px 9px;border:1px solid #ddd;font-size:11px;">${mp[p.payment_method]||p.payment_method}</td></tr>`;}).join('');
    const coursRows=data.cours.map(p=>{const brut=parseFloat(p.amount_brut||0),ret=parseFloat(p.amount_retenue||0),net=parseFloat(p.amount_net||0),nb_h=parseFloat(p.nb_heures||0),nbMod=p.nb_modules||0,tv=parseFloat(p.total_verse||0);return `<tr style="border-left:3px solid #1abc9c;"><td style="padding:5px 9px;border:1px solid #ddd;">${new Date(p.payment_date||p.created_at).toLocaleDateString('fr-FR')}</td><td style="padding:5px 9px;border:1px solid #ddd;"><span style="font-size:10px;background:#e8faf5;color:#1abc9c;padding:1px 6px;border-radius:4px;margin-right:4px;">COURS</span>${nbMod} module(s)</td><td style="padding:5px 9px;border:1px solid #ddd;text-align:center;">${nb_h>0?nb_h.toFixed(1)+'h':'—'}</td><td style="padding:5px 9px;border:1px solid #ddd;text-align:right;font-size:11px;">—</td><td style="padding:5px 9px;border:1px solid #ddd;text-align:right;">${fmt(brut)}</td><td style="padding:5px 9px;border:1px solid #ddd;text-align:right;color:#c00;">${ret>0?fmt(ret):'—'}</td><td style="padding:5px 9px;border:1px solid #ddd;text-align:right;font-weight:bold;">${fmt(tv)} FCFA <span style="font-size:10px;color:#888;">(versé)</span></td><td style="padding:5px 9px;border:1px solid #ddd;font-size:11px;">Voir facture</td></tr>`;}).join('');
    return `<div id="printableBulletin" style="background:#fff;color:#000;padding:26px 36px;max-width:760px;margin:0 auto;font-family:Arial,sans-serif;font-size:11px;line-height:1.6;border:1px solid #ddd;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;padding-bottom:14px;border-bottom:3px solid #039be5;">
            <div><div style="font-size:20px;font-weight:900;color:#039be5;">ISMM</div><div style="font-size:11px;color:#555;">École Supérieure de Formation pour l'Insertion Professionnelle- Groupe CEFIP</div></div>
            <div style="text-align:right;"><div style="font-size:17px;font-weight:900;">BULLETIN DE PAIE</div><div style="font-size:12px;font-weight:700;color:#039be5;">Période : ${period}</div></div>
        </div>
        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:11px;">
            <tr><td style="padding:5px 8px;border:1px solid #ccc;background:#f0f4f8;font-weight:bold;">Nom</td><td style="padding:5px 8px;border:1px solid #ccc;">${emp.name||'—'}</td><td style="padding:5px 8px;border:1px solid #ccc;background:#f0f4f8;font-weight:bold;">Matricule</td><td style="padding:5px 8px;border:1px solid #ccc;">${emp.id||'—'}</td></tr>
            <tr><td style="padding:5px 8px;border:1px solid #ccc;background:#f0f4f8;font-weight:bold;">Poste</td><td style="padding:5px 8px;border:1px solid #ccc;">${emp.role==='teacher'?'Enseignant Vacataire':'Administrateur'}</td><td style="padding:5px 8px;border:1px solid #ccc;background:#f0f4f8;font-weight:bold;">Retenue</td><td style="padding:5px 8px;border:1px solid #ccc;">${emp.role==='teacher'?'9,5%':'Exonéré'}</td></tr>
        </table>
        <table style="width:100%;border-collapse:collapse;margin-bottom:14px;font-size:11px;">
            <thead><tr style="background:#039be5;color:#fff;"><th style="padding:6px 9px;border:1px solid #0277bd;text-align:left;">Date</th><th style="padding:6px 9px;border:1px solid #0277bd;text-align:left;">Libellé</th><th style="padding:6px 9px;border:1px solid #0277bd;text-align:center;">H.</th><th style="padding:6px 9px;border:1px solid #0277bd;text-align:right;">Prix/h</th><th style="padding:6px 9px;border:1px solid #0277bd;text-align:right;">Brut</th><th style="padding:6px 9px;border:1px solid #0277bd;text-align:right;">Ret.</th><th style="padding:6px 9px;border:1px solid #0277bd;text-align:right;">Net</th><th style="padding:6px 9px;border:1px solid #0277bd;">Mode</th></tr></thead>
            <tbody>${libresRows}${coursRows}</tbody>
            <tfoot><tr style="background:#e8f4f8;font-weight:bold;"><td style="padding:7px 9px;border:1px solid #ccc;" colspan="2">TOTAUX ${period}</td><td style="padding:7px 9px;border:1px solid #ccc;text-align:center;">${data.total_heures>0?parseFloat(data.total_heures).toFixed(1)+'h':'—'}</td><td style="padding:7px 9px;border:1px solid #ccc;"></td><td style="padding:7px 9px;border:1px solid #ccc;text-align:right;">${fmt(data.total_brut)}</td><td style="padding:7px 9px;border:1px solid #ccc;text-align:right;color:#c00;">${data.total_retenue>0?fmt(data.total_retenue):'—'}</td><td style="padding:7px 9px;border:1px solid #ccc;" colspan="2"></td></tr></tfoot>
        </table>
        <div style="background:#039be5;color:#fff;padding:11px 16px;display:flex;justify-content:space-between;align-items:center;border-radius:4px;margin-bottom:12px;"><span style="font-weight:bold;letter-spacing:1px;">NET TOTAL — ${period}</span><span style="font-size:18px;font-weight:900;">${fmt(data.total_net)} FCFA</span></div>
        <div style="border:1px solid #ccc;padding:7px 12px;background:#f9f9f9;margin-bottom:18px;font-size:11px;"><strong>Arrêté à : </strong><span style="text-transform:capitalize;">${n2w(Math.round(data.total_net))} francs CFA</span></div>
        <div style="display:flex;justify-content:space-between;margin-top:32px;"><div style="text-align:center;width:180px;"><div style="font-weight:bold;text-decoration:underline;font-size:12px;margin-bottom:32px;">L'EMPLOYÉ</div><div style="border-top:1px solid #000;padding-top:4px;font-size:10px;color:#777;">Signature & Date</div></div><div style="text-align:center;width:180px;"><div style="font-weight:bold;text-decoration:underline;font-size:12px;margin-bottom:32px;">DIRECTION ISMM</div><div style="border-top:1px solid #000;padding-top:4px;font-size:10px;color:#777;">Signature & Cachet</div></div></div>
        <div style="margin-top:22px;text-align:center;font-size:10px;color:#999;border-top:1px solid #eee;padding-top:10px;">ISMM — Groupe CEFIP — Libreville, Gabon — Université Virtuelle · Coding Enterprise © ${yr}</div>
    </div>`;
}

// Reçu paiement libre
function openRecu(id){const c=document.getElementById('recuContent');c.innerHTML='<div style="text-align:center;padding:30px;"><div class="spinner"></div></div>';openModal('mRecu');fetch('?action=get_payment_receipt&payment_id='+id).then(r=>r.json()).then(d=>{if(!d.success){c.innerHTML='<p style="color:var(--red);">'+d.error+'</p>';return;}c.innerHTML=buildRecu(d.payment);});}
function buildRecu(p){const d=new Date(p.payment_date),day=String(d.getDate()).padStart(2,'0'),mon=String(d.getMonth()+1).padStart(2,'0'),yr=d.getFullYear();const brut=parseFloat(p.amount_brut||0),ret=parseFloat(p.amount_retenue||0),net=parseFloat(p.amount_net||0),nb_h=parseFloat(p.nb_heures||0),px_h=parseFloat(p.prix_heure||0);const num=(p.receipt_number||'').split('-').pop().padStart(6,'0');const isCash=p.payment_method==='cash',isMM=p.payment_method==='mobile_money',isCk=p.payment_method==='check';return `<div id="printableRecu" style="background:#fff;color:#000;padding:28px 38px;max-width:760px;margin:0 auto;font-family:'Courier New',monospace;font-size:12.5px;line-height:1.7;border:1px solid #ddd;"><div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;padding-bottom:12px;border-bottom:2px solid #039be5;"><div><div style="font-size:20px;font-weight:900;color:#039be5;">ISMM</div><div style="font-size:11px;color:#555;">École Supérieure de Formation — Groupe CEFIP — Libreville</div></div><div style="text-align:right;font-size:12px;"><div style="font-size:17px;font-weight:700;color:#c00;">N° ${num}</div><div>Le ${day}/${mon}/${yr}</div><div style="margin-top:5px;font-weight:bold;">BPF <strong>${fmt(net)}</strong> F CFA</div></div></div><div style="text-align:center;margin:18px 0;font-size:19px;font-weight:bold;text-decoration:underline;letter-spacing:2px;">REÇU DE CAISSE</div><div style="margin:14px 0;border-bottom:1px dotted #000;padding-bottom:4px;"><strong>M/Mlle :</strong> ${p.staff_name||''}</div><div style="margin:14px 0;border-bottom:1px dotted #000;padding-bottom:4px;"><strong>Motif :</strong> ${p.payment_type_name||''}${nb_h>0?' — '+nb_h.toFixed(1)+'h × '+fmt(px_h)+' F/h':''}${p.description?' ('+p.description+')':''}</div><div style="margin:14px 0;display:flex;justify-content:center;gap:28px;align-items:center;"><span style="font-weight:bold;">MODE :</span><label><input type="checkbox" ${isCash?'checked':''} disabled> CASH</label><label><input type="checkbox" ${isMM?'checked':''} disabled> MOBILE MONEY</label><label><input type="checkbox" ${isCk?'checked':''} disabled> CHÈQUE</label></div>${ret>0?`<div style="background:#fff5f5;border:1px solid #ffcccc;border-radius:4px;padding:9px 13px;margin:12px 0;font-size:11px;"><div style="display:flex;justify-content:space-between;"><span>Brut :</span><span>${fmt(brut)} FCFA</span></div><div style="display:flex;justify-content:space-between;color:#c00;"><span>Retenue DGI 9,5% :</span><span>−${fmt(ret)} FCFA</span></div></div>`:''}<div style="margin:14px 0;border-bottom:1px dotted #000;padding-bottom:4px;"><strong>NET en Lettres :</strong> <span style="text-transform:capitalize;">${n2w(net)} francs CFA</span></div><div style="border-top:1px dotted #000;margin:14px 0;"></div><div style="margin:14px 0;display:flex;justify-content:space-between;border-bottom:1px dotted #000;padding-bottom:4px;"><div><strong>Net versé :</strong> ${fmt(net)} F CFA</div><div><strong>Reste :</strong> 0 F CFA</div></div><div style="display:flex;justify-content:space-between;margin-top:42px;padding-top:12px;border-top:1px solid #000;"><div style="font-weight:bold;text-decoration:underline;">VISA CLIENT</div><div style="font-weight:bold;text-decoration:underline;">COMPTABILITÉ ISMM</div></div><div style="margin-top:26px;text-align:center;font-size:10px;color:#777;border-top:1px solid #ddd;padding-top:10px;">ISMM — Groupe CEFIP — Libreville, Gabon · Université Virtuelle © ${yr}</div></div>`;}

// ═══════════════════════════════════════════════════════════
// TRAÇABILITÉ / JOURNAL D'AUDIT
// ═══════════════════════════════════════════════════════════
let _auditLogs = [];

const AUDIT_ICONS = {
    CREATE:'fa-plus-circle', UPDATE:'fa-pencil-alt', PAYMENT:'fa-money-bill-wave',
    CANCEL:'fa-ban', ASSIGN:'fa-link', DELETE:'fa-trash'
};
const AUDIT_LABELS = {
    CREATE:'Création', UPDATE:'Modification', PAYMENT:'Versement',
    CANCEL:'Annulation', ASSIGN:'Assignation', DELETE:'Suppression'
};
const ENTITY_LABELS = {
    engagement:'Engagement', versement:'Versement', payment_libre:'Paiement libre',
    personnel:'Personnel', depense:'Dépense'
};

function loadAuditLog() {
    const tl = document.getElementById('auditTimeline');
    tl.innerHTML = '<div style="text-align:center;padding:30px;"><div class="spinner"></div></div>';
    const afFrom = document.getElementById('afFrom');
    const afTo   = document.getElementById('afTo');
    if (afFrom && !afFrom.value) afFrom.value = ANNEE_DEBUT;
    if (afTo   && !afTo.value)   afTo.value   = ANNEE_FIN;
    const params = new URLSearchParams({
        action:      'get_audit_log',
        entity_type: document.getElementById('afEntity')?.value||'',
        action_type: document.getElementById('afType')?.value||'',
        date_from:   afFrom?.value||'',
        date_to:     afTo?.value||'',
        search:      document.getElementById('afSearch')?.value||'',
        limit:       200
    });
    fetch('?'+params.toString())
        .then(r=>r.json())
        .then(d=>{
            if (!d.success) { tl.innerHTML='<div class="empty-state"><p>Erreur : '+escHtml(d.error)+'</p></div>'; return; }
            _auditLogs = d.logs;
            renderAuditStats(d.stats||[]);
            renderAuditTimeline(d.logs);
        })
        .catch(()=>{ tl.innerHTML='<div class="empty-state"><p>Erreur réseau.</p></div>'; });
}

function renderAuditStats(stats) {
    const el = document.getElementById('auditStats');
    if (!el) return;
    const colors = {CREATE:'var(--green)',UPDATE:'var(--accent)',PAYMENT:'var(--teal)',CANCEL:'var(--red)',ASSIGN:'var(--purple)'};
    el.innerHTML = stats.map(s=>`
        <div class="audit-stat-pill" style="color:${colors[s.action_type]||'var(--muted)'};border-color:${colors[s.action_type]||'var(--border)'}20;">
            ${AUDIT_LABELS[s.action_type]||s.action_type} : <strong>${s.cnt}</strong>
        </div>`).join('');
}

function renderAuditTimeline(logs) {
    const tl = document.getElementById('auditTimeline');
    if (!logs.length) {
        tl.innerHTML = '<div class="empty-state"><i class="fas fa-shield-alt"></i><p>Aucune entrée dans le journal pour ces filtres.</p></div>';
        return;
    }
    // Grouper par jour
    const byDay = {};
    logs.forEach(l => {
        const d = l.performed_at ? l.performed_at.substring(0,10) : '?';
        if (!byDay[d]) byDay[d] = [];
        byDay[d].push(l);
    });

    tl.innerHTML = Object.entries(byDay).map(([day, entries])=>`
        <div style="margin-bottom:20px;">
            <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.07);">
                <i class="fas fa-calendar-day" style="margin-right:6px;"></i>${formatAuditDay(day)}
                <span style="font-size:10px;margin-left:8px;background:rgba(255,255,255,.06);padding:1px 8px;border-radius:8px;">${entries.length} action(s)</span>
            </div>
            <div class="audit-timeline">
                ${entries.map((l,i)=>renderAuditEntry(l,i)).join('')}
            </div>
        </div>`).join('');
}

function renderAuditEntry(l, i) {
    const icon  = AUDIT_ICONS[l.action_type] || 'fa-info-circle';
    const actor = escHtml(l.performed_by_name || l.performed_by || '—');
    const time  = l.performed_at ? l.performed_at.substring(11,16) : '';
    const ip    = l.ip_address ? `<span title="${escHtml(l.ip_address)}"><i class="fas fa-network-wired" style="margin-right:3px;"></i>${escHtml(l.ip_address)}</span>` : '';
    const eid   = l.entity_id  ? `#${escHtml(l.entity_id)}` : '';
    const hasDetail = l.new_value || l.old_value;
    const toggleId  = 'aj-'+i+'-'+(l.id||i);
    const newPretty = l.new_value ? JSON.stringify(JSON.parse(l.new_value),null,2) : '';
    const oldPretty = l.old_value ? JSON.stringify(JSON.parse(l.old_value),null,2) : '';

    return `<div class="audit-entry">
        <div class="audit-icon ${escHtml(l.action_type)}"><i class="fas ${icon}"></i></div>
        <div class="audit-body">
            <div class="audit-desc">${escHtml(l.description)}</div>
            <div class="audit-meta">
                <span style="color:var(--accent);"><i class="fas fa-clock" style="margin-right:3px;"></i>${time}</span>
                <span class="audit-badge ${escHtml(l.entity_type||'')}">${ENTITY_LABELS[l.entity_type]||escHtml(l.entity_type||'')} ${eid}</span>
                <span><i class="fas fa-user" style="margin-right:3px;color:var(--purple);"></i>${actor}</span>
                ${ip}
                ${hasDetail?`<span class="audit-toggle" onclick="toggleAuditDetail('${toggleId}')">▶ Détails</span>`:''}
            </div>
            ${hasDetail?`<div class="audit-json" id="${toggleId}">${oldPretty?'AVANT:\n'+oldPretty+'\n\n':''}${newPretty?'APRÈS:\n'+newPretty:''}</div>`:''}
        </div>
    </div>`;
}

function toggleAuditDetail(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const tog = el.previousElementSibling?.querySelector('.audit-toggle');
    if (el.style.display==='block') {
        el.style.display='none';
        if (tog) tog.textContent=tog.textContent.replace('▼','▶');
    } else {
        el.style.display='block';
        if (tog) tog.textContent=tog.textContent.replace('▶','▼');
    }
}

function formatAuditDay(dateStr) {
    if (!dateStr||dateStr==='?') return '—';
    const d = new Date(dateStr+'T00:00:00');
    return d.toLocaleDateString('fr-FR',{weekday:'long',day:'2-digit',month:'long',year:'numeric'});
}

function exportAuditCSV() {
    if (!_auditLogs.length) { showAlert('Aucune donnée à exporter','error'); return; }
    const cols = ['performed_at','action_type','entity_type','entity_id','description','performed_by_name','ip_address'];
    const heads = ['Date/Heure','Action','Entité','ID','Description','Utilisateur','IP'];
    const rows  = _auditLogs.map(l=>cols.map(c=>{
        const v = l[c]||'';
        return '"'+String(v).replace(/"/g,'""')+'"';
    }).join(';'));
    const csv = '﻿'+heads.join(';')+'\n'+rows.join('\n');
    const a   = document.createElement('a');
    a.href    = URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
    a.download= 'audit_log_ISMM_'+new Date().toISOString().substring(0,10)+'.csv';
    a.click();
}

// ── Gestion personnel — rôle + cours ──────────────────────────────────────
function onPersonnelRoleChange() {
    const role = document.getElementById('staffRoleSel')?.value;
    const zone = document.getElementById('coursAssignZone');
    if (!zone) return;
    if (role === 'teacher') { zone.style.display='block'; loadCoursDisponibles(); }
    else { zone.style.display='none'; }
}

function loadCoursDisponibles() {
    const list = document.getElementById('coursAssignList');
    if (!list) return;
    list.innerHTML = '<div style="color:var(--muted);font-size:12px;text-align:center;">Chargement…</div>';
    fetch('?action=get_all_courses')
        .then(r=>r.json())
        .then(d=>{
            if (!d.success||!d.courses.length) {
                list.innerHTML='<div style="color:var(--muted);font-size:12px;text-align:center;">Aucun cours disponible.</div>';
                return;
            }
            // Séparer libres et déjà assignés
            const libres = d.courses.filter(c=>!c.teacher_id||c.teacher_id==='');
            const pris   = d.courses.filter(c=>c.teacher_id&&c.teacher_id!=='');

            function groupBySem(courses, isAssigned) {
                const bySem={};
                courses.forEach(c=>{const k='Semestre '+(c.semester||'?');if(!bySem[k])bySem[k]=[];bySem[k].push(c);});
                return Object.entries(bySem).map(([sem,cs])=>`
                    <div style="margin-bottom:10px;">
                        <div style="font-size:10px;font-weight:700;color:${isAssigned?'var(--muted)':'var(--accent)'};text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px;">${escHtml(sem)}</div>
                        ${cs.map(c=>`<label style="display:flex;align-items:center;gap:8px;padding:3px 0;cursor:pointer;font-size:12px;${isAssigned?'opacity:.6':''}">
                            <input type="checkbox" name="cours_assignes[]" value="${c.id}" style="cursor:pointer;">
                            <span>${escHtml(c.name)}${c.major?' · '+escHtml(c.major):''}${c.total_hours?' ('+c.total_hours+'h)':''}</span>
                            ${isAssigned?`<span style="font-size:10px;color:var(--yellow);margin-left:4px;">↳ ${escHtml(c.teacher_name||c.teacher_id)}</span>`:''}
                        </label>`).join('')}
                    </div>`).join('');
            }

            let html = '';
            if (libres.length) html += `<div style="font-size:10px;font-weight:700;color:var(--green);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px;padding-bottom:4px;border-bottom:1px solid rgba(46,204,113,.2);">✓ Disponibles</div>${groupBySem(libres,false)}`;
            if (pris.length)   html += `<div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid rgba(255,255,255,.08);">Déjà assignés</div>${groupBySem(pris,true)}`;
            list.innerHTML = html;
        })
        .catch(()=>{ list.innerHTML='<div style="color:var(--red);font-size:12px;">Erreur chargement.</div>'; });
}

// Types
function openEditType(id,name,cat,desc){document.getElementById('editTypeId').value=id;document.getElementById('editTypeName').value=name;document.getElementById('editTypeCat').value=cat;document.getElementById('editTypeDesc').value=desc;openModal('mEditType');}

// ═══════════════════════════════════════════════════════════
// VUE ENSEIGNANT
// ═══════════════════════════════════════════════════════════
let _tvEid='';
function viewTeacher(eid, nom) {
    _tvEid=eid;
    document.getElementById('tvTabSuivi').classList.add('active');
    document.getElementById('tvTabHist').classList.remove('active');
    document.getElementById('tvContent').style.display='block';
    document.getElementById('tvHistContent').style.display='none';
    document.getElementById('tvHistContent').innerHTML='';
    document.getElementById('tvContent').innerHTML = '<div style="text-align:center;padding:30px;"><div class="spinner"></div></div>';
    document.getElementById('tvTitre').innerHTML = '<i class="fas fa-chart-line"></i> Suivi Financier — '+escHtml(nom);
    openModal('mTeacherView');
    fetch('?action=get_teacher_payment_view&enseignant_id='+encodeURIComponent(eid)+'&annee_academique='+encodeURIComponent(ANNEE_FILTRE))
        .then(r=>r.json())
        .then(d=>{
            if (!d.success){document.getElementById('tvContent').innerHTML='<div style="color:var(--red);padding:16px;">'+escHtml(d.error||'Erreur')+'</div>';return;}
            renderTeacherView(d);
        })
        .catch(()=>{ document.getElementById('tvContent').innerHTML='<div style="color:var(--red);padding:16px;">Erreur réseau</div>'; });
}
function tvShowTab(tab){
    document.getElementById('tvTabSuivi').classList.toggle('active',tab==='suivi');
    document.getElementById('tvTabHist').classList.toggle('active',tab==='historique');
    document.getElementById('tvContent').style.display=tab==='suivi'?'block':'none';
    const hc=document.getElementById('tvHistContent');
    hc.style.display=tab==='historique'?'block':'none';
    if(tab==='historique'&&hc.innerHTML===''){
        hc.innerHTML='<div style="text-align:center;padding:30px;"><div class="spinner"></div></div>';
        fetch('?action=get_staff_history&staff_id='+encodeURIComponent(_tvEid))
            .then(r=>r.json())
            .then(d=>{
                if(!d.success){hc.innerHTML='<p style="color:var(--red);padding:16px;">'+escHtml(d.error||'Erreur')+'</p>';return;}
                const mp={bank_transfer:'Virement',cash:'Espèces',check:'Chèque',mobile_money:'Mobile Money'};
                const sl={processed:'✓ Payé',pending:'⏳ Attente',cancelled:'✕ Annulé'};
                const rows=d.payments.map(p=>{
                    const b=parseFloat(p.amount_brut||0),ret=parseFloat(p.amount_retenue||0),n=parseFloat(p.amount_net||0);
                    const nb_h=parseFloat(p.nb_heures||0),px_h=parseFloat(p.prix_heure||0);
                    return `<tr><td>${new Date(p.payment_date).toLocaleDateString('fr-FR')}</td><td>${p.type_name||'—'}</td><td style="text-align:center;color:var(--yellow);">${nb_h>0?nb_h.toFixed(1)+'h':'—'}</td><td style="font-size:11px;color:var(--muted);">${px_h>0?fmt(px_h)+' F':'—'}</td><td style="color:var(--muted);font-size:12px;">${fmt(b)}</td><td style="color:var(--red);">${ret>0?'−'+fmt(ret):'—'}</td><td style="color:var(--green);font-weight:700;">${fmt(n)} FCFA</td><td>${mp[p.payment_method]||''}</td><td><span class="badge badge-${p.status}">${sl[p.status]||p.status}</span></td></tr>`;
                }).join('');
                hc.innerHTML=`<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                    <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 18px;text-align:center;flex:1;"><div style="font-size:11px;color:var(--muted);text-transform:uppercase;margin-bottom:4px;">Paiements</div><div style="font-size:20px;font-weight:700;color:var(--accent);">${d.count}</div></div>
                    <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 18px;text-align:center;flex:1;"><div style="font-size:11px;color:var(--muted);text-transform:uppercase;margin-bottom:4px;">Net ce mois</div><div style="font-size:18px;font-weight:700;color:var(--green);">${fmt(d.total_month)} FCFA</div></div>
                </div>
                <table class="data-table"><thead><tr><th>Date</th><th>Type</th><th>Heures</th><th>Prix/h</th><th>Brut</th><th>Retenue</th><th>Net</th><th>Méthode</th><th>Statut</th></tr></thead>
                <tbody>${rows||'<tr><td colspan="9" style="text-align:center;color:var(--muted);">Aucun paiement libre enregistré</td></tr>'}</tbody></table>`;
            })
            .catch(()=>{hc.innerHTML='<p style="color:var(--red);padding:16px;">Erreur réseau</p>';});
    }
}

function renderTeacherView(d) {
    const fmtRow = (label, val, style='') =>
        `<div class="rc-item"><div class="rc-lbl">${label}</div><div class="rc-val" style="${style}">${val}</div></div>`;

    // Résumé
    let html = `<div class="rc-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px;">
        ${fmtRow('Total brut engagements', fmt(d.total_eng_brut)+' F', 'color:var(--accent);')}
        ${fmtRow('Versé', fmt(d.total_eng_verse)+' F', 'color:var(--green);')}
        ${fmtRow('Restant', fmt(d.total_eng_restant)+' F', 'color:'+((d.total_eng_restant>0)?'var(--orange)':'var(--green)')+';')}
        ${fmtRow('Paiements libres', fmt(d.total_libres)+' F', 'color:var(--yellow);')}
    </div>`;

    // Engagements
    if (d.engagements && d.engagements.length) {
        html += '<div class="teacher-view-section"><strong><i class="fas fa-chalkboard-teacher" style="margin-right:6px;color:var(--accent);"></i>Engagements cours</strong></div>';
        html += '<table class="data-table" style="margin-bottom:16px;font-size:12px;"><thead><tr><th>#</th><th>Semestre</th><th>Année</th><th>Statut</th><th>Cours</th><th style="text-align:right;">Brut</th><th style="text-align:right;">Versé</th><th style="text-align:right;">Restant</th></tr></thead><tbody>';
        d.engagements.forEach(e=>{
            const restColor = e.restant>0 ? 'var(--orange)' : 'var(--green)';
            const coursList = (e.cours||[]).map(c=>`<span style="font-size:11px;display:block;">${escHtml(c.course_name)} <span style="color:var(--muted);">(${c.nb_heures}h × ${fmt(c.prix_heure)} F)</span></span>`).join('');
            html += `<tr>
                <td>${e.id}</td>
                <td>${escHtml(e.semestre||'—')}</td>
                <td>${escHtml(e.annee_academique||'—')}</td>
                <td><span class="badge" style="background:rgba(0,0,0,.2);">${escHtml(e.statut)}</span></td>
                <td>${coursList||'—'}</td>
                <td style="text-align:right;font-weight:700;">${fmt(e.montant_brut)} F</td>
                <td style="text-align:right;color:var(--green);">${fmt(e.total_verse)} F</td>
                <td style="text-align:right;color:${restColor};font-weight:700;">${fmt(e.restant)} F</td>
            </tr>`;
        });
        html += '</tbody></table>';
    } else {
        html += '<div class="empty-state" style="padding:12px;margin-bottom:12px;"><p>Aucun engagement cours.</p></div>';
    }

    // Paiements libres
    if (d.paiements_libres && d.paiements_libres.length) {
        html += '<div class="teacher-view-section"><strong><i class="fas fa-money-bill-wave" style="margin-right:6px;color:var(--yellow);"></i>Paiements libres</strong></div>';
        html += '<table class="data-table" style="font-size:12px;"><thead><tr><th>#</th><th>Type</th><th>Date</th><th style="text-align:right;">Montant</th><th>Notes</th></tr></thead><tbody>';
        d.paiements_libres.forEach(p=>{
            html += `<tr>
                <td>${p.id}</td>
                <td>${escHtml(p.type_name||'Paiement libre')}</td>
                <td>${escHtml(p.payment_date||'—')}</td>
                <td style="text-align:right;font-weight:700;color:var(--green);">${fmt(p.amount_net)} F</td>
                <td style="color:var(--muted);font-size:11px;">${escHtml(p.notes||'')}</td>
            </tr>`;
        });
        html += '</tbody></table>';
    }

    document.getElementById('tvContent').innerHTML = html;
}

// Print & PDF
function printDoc(eid){const el=document.getElementById(eid);if(!el)return;const w=window.open('','_blank');w.document.write(`<!DOCTYPE html><html><head><title>ISMM</title><style>body{margin:0;padding:14px;background:#fff;}@media print{body{margin:0;padding:4px;}}</style></head><body onload="window.print();window.close();">${el.outerHTML}</body></html>`);w.document.close();}
function dlPDF(eid,fname){const el=document.getElementById(eid);if(!el)return;const go=()=>{html2canvas(el,{scale:2,backgroundColor:'#ffffff',logging:false,useCORS:true}).then(c=>{const {jsPDF}=window.jspdf,iw=210,ih=(c.height*iw)/c.width,pdf=new jsPDF('p','mm','a4');pdf.addImage(c.toDataURL('image/png'),'PNG',0,0,iw,ih);pdf.save(fname+'_'+Date.now()+'.pdf');});};if(typeof html2canvas==='undefined'){const s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';s.onload=go;document.head.appendChild(s);}else go();}

// N → Lettres
function n2w(num){num=Math.round(num);if(num===0)return'zéro';const U=['','un','deux','trois','quatre','cinq','six','sept','huit','neuf'],T=['dix','onze','douze','treize','quatorze','quinze','seize','dix-sept','dix-huit','dix-neuf'],D=['','','vingt','trente','quarante','cinquante','soixante','soixante-dix','quatre-vingt','quatre-vingt-dix'];function c100(n){let r='';const h=Math.floor(n/100),m=n%100;if(h>0){r+=(h===1?'cent':U[h]+' cent');if(m>0)r+=' ';}if(m>=20){r+=D[Math.floor(m/10)];if(m%10>0)r+='-'+U[m%10];}else if(m>=10)r+=T[m-10];else if(m>0)r+=U[m];return r;}const M=Math.floor(num/1e6),K=Math.floor((num%1e6)/1000),R=num%1000;let res='';if(M>0){res+=c100(M)+' million'+(M>1?'s':'');if(K>0||R>0)res+=' ';}if(K>0){res+=(K===1?'mille':c100(K)+' mille');if(R>0)res+=' ';}if(R>0)res+=c100(R);return res.trim();}

document.querySelectorAll('.alert.show').forEach(a=>setTimeout(()=>a.classList.remove('show'),6000));
calcHoraire('h');
</script>
</body>
</html>