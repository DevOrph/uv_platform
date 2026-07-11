<?php
// ============================================================
//  ISMM — Gestionnaire d'inscription (backend PHP + MySQL)
//  Fichier : register_handler.php
// ============================================================

// Chargement de Composer (SendGrid + autres dépendances)
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

require_once dirname(__DIR__) . '/includes/db_config.php';
$cfg = get_db_config();

// ── Constantes protégées contre la double définition ─────────
defined('DB_HOST')         or define('DB_HOST',         $cfg['host']);
defined('DB_NAME')         or define('DB_NAME',         $cfg['name']);
defined('DB_USER')         or define('DB_USER',         $cfg['user']);
defined('DB_PASS')         or define('DB_PASS',         $cfg['pass']);
defined('DB_CHARSET')      or define('DB_CHARSET',      $cfg['charset']);
defined('UPLOAD_BASE')     or define('UPLOAD_BASE',     dirname(__DIR__) . '/uploads/');
defined('UPLOAD_DIR')      or define('UPLOAD_DIR',      UPLOAD_BASE . 'documents/');
defined('UPLOAD_AVATAR')   or define('UPLOAD_AVATAR',   UPLOAD_BASE . 'avatars/');
defined('UPLOAD_PREUVE')   or define('UPLOAD_PREUVE',   UPLOAD_BASE . 'preuves/');
defined('UPLOAD_BASE_WEB') or define('UPLOAD_BASE_WEB', 'uploads/');

// ── Création silencieuse des dossiers upload ──────────────────
foreach ([UPLOAD_DIR, UPLOAD_AVATAR, UPLOAD_PREUVE] as $_dir) {
    if (!is_dir($_dir)) { @mkdir($_dir, 0755, true); }
}
unset($_dir);

// ── Helpers (protégés contre la double déclaration) ──────────
if (!function_exists('getDB')) {
    function getDB(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return $pdo;
    }
}

if (!function_exists('saveUpload')) {
    function saveUpload(array $file, string $destDir, string $webSubDir, array $allowed = [], int $maxBytes = 10485760): ?string {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
        if ($file['size'] > $maxBytes) return null;
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!empty($allowed) && !in_array($ext, $allowed, true)) return null;
        $newName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest    = rtrim($destDir, '/') . '/' . $newName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
        return UPLOAD_BASE_WEB . $webSubDir . $newName;
    }
}

if (!function_exists('genRef')) {
    function genRef(): string {
        return 'ISMM-' . date('Y') . '-' . str_pad(mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('clean')) {
    function clean(string $v): string {
        return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('cleanOrNull')) {
    function cleanOrNull(string $v): ?string {
        $v = trim($v);
        return $v !== '' ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : null;
    }
}

// ── TRAITEMENT POST uniquement ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {

    $response = ['success' => false, 'message' => '', 'ref' => ''];

    try {
        $db = getDB();

        $nom      = clean($_POST['nom']    ?? '');
        $prenom   = clean($_POST['prenom'] ?? '');
        $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $phone    = clean($_POST['phone']  ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        $birth_date    = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
        $address       = clean($_POST['address']       ?? '');
        $ville         = clean($_POST['ville']          ?? '');
        $domaine_pro   = clean($_POST['domaine_pro']    ?? '');
        $mode_paiement = clean($_POST['mode_paiement']  ?? '');

        $birth_place = cleanOrNull($_POST['birth_place'] ?? '');
        $sexe        = cleanOrNull($_POST['sexe']        ?? '');
        $nationalite = cleanOrNull($_POST['nationalite'] ?? '');

        $bac_serie = cleanOrNull($_POST['bac_serie'] ?? '');
        $bac_annee = cleanOrNull($_POST['bac_annee'] ?? '');

        $tuteur_nom       = cleanOrNull($_POST['tuteur_nom']       ?? '');
        $tuteur_lien      = cleanOrNull($_POST['tuteur_lien']      ?? '');
        $tuteur_adresse   = cleanOrNull($_POST['tuteur_adresse']   ?? '');
        $tuteur_telephone = cleanOrNull($_POST['tuteur_telephone'] ?? '');

        $urgence_nom       = cleanOrNull($_POST['urgence_nom']       ?? '');
        $urgence_lien      = cleanOrNull($_POST['urgence_lien']      ?? '');
        $urgence_adresse   = cleanOrNull($_POST['urgence_adresse']   ?? '');
        $urgence_telephone = cleanOrNull($_POST['urgence_telephone'] ?? '');

        $dernier_diplome       = cleanOrNull($_POST['dernier_diplome']       ?? '');
        $diplome_serie         = cleanOrNull($_POST['diplome_serie']         ?? '');
        $diplome_annee         = cleanOrNull($_POST['diplome_annee']         ?? '');
        $etablissement_origine = cleanOrNull($_POST['etablissement_origine'] ?? '');

        $regime = cleanOrNull($_POST['regime'] ?? '');

        $niveau       = clean($_POST['niveau'] ?? '');
        $niveau_autre = '';
        if ($niveau === 'Autre') {
            $niveau_autre = clean($_POST['niveau_autre'] ?? '');
            if (!empty($niveau_autre)) $niveau = 'Autre: ' . $niveau_autre;
        }

        $class_id_raw = trim($_POST['class_id'] ?? '');
        $class_id = (ctype_digit($class_id_raw) && $class_id_raw !== '') ? (int)$class_id_raw : null;

        $specialite       = clean($_POST['specialite'] ?? '');
        $specialite_autre = '';
        if ($specialite === 'autre') {
            $specialite_autre = clean($_POST['specialite_autre'] ?? '');
            if (!empty($specialite_autre)) $specialite = 'Autre: ' . $specialite_autre;
        }

        $exp_pro       = clean($_POST['exp_pro'] ?? '');
        $exp_pro_autre = '';
        if ($exp_pro === 'autre') {
            $exp_pro_autre = clean($_POST['exp_pro_autre'] ?? '');
            if (!empty($exp_pro_autre)) $exp_pro = 'Autre: ' . $exp_pro_autre;
        }

        // — Validations —
        $errors = [];
        if (strlen($nom) < 2)                          $errors[] = 'Nom trop court.';
        if (strlen($prenom) < 2)                       $errors[] = 'Prénom trop court.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))$errors[] = 'Email invalide.';
        if (strlen($password) < 6)                     $errors[] = 'Mot de passe trop court (min. 6 car.).';
        if ($password !== $confirm)                    $errors[] = 'Les mots de passe ne correspondent pas.';
        if (empty($niveau))                            $errors[] = "Niveau d'études requis.";
        if (empty($specialite))                        $errors[] = 'Spécialité requise.';
        if (empty($mode_paiement))                     $errors[] = 'Mode de paiement requis.';

        if (($_POST['niveau']     ?? '') === 'Autre' && empty($niveau_autre))     $errors[] = "Veuillez préciser votre niveau d'études.";
        if (($_POST['specialite'] ?? '') === 'autre' && empty($specialite_autre)) $errors[] = 'Veuillez préciser votre spécialité.';
        if (($_POST['exp_pro']    ?? '') === 'autre' && empty($exp_pro_autre))    $errors[] = 'Veuillez préciser votre expérience.';

        $chk = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) $errors[] = 'Cet email est déjà utilisé.';

        if (!empty($errors)) {
            $response['message'] = implode(' ', $errors);
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        // — Uploads —
        $avatarPath = saveUpload($_FILES['avatar'] ?? [], UPLOAD_AVATAR, 'avatars/', ['jpg','jpeg','png','gif','webp'], 5242880) ?? 'default_avatar.png';
        $acteNaissancePath     = saveUpload($_FILES['acte_naissance']     ?? [], UPLOAD_DIR, 'documents/', ['pdf','jpg','jpeg','png']);
        $diplomePath           = saveUpload($_FILES['diplome']            ?? [], UPLOAD_DIR, 'documents/', ['pdf','jpg','jpeg','png']);
        $releveNotesPath       = saveUpload($_FILES['releve_notes']       ?? [], UPLOAD_DIR, 'documents/', ['pdf','jpg','jpeg','png']);
        $photosPath            = saveUpload($_FILES['photos']             ?? [], UPLOAD_DIR, 'documents/', ['jpg','jpeg','png']);
        $attestationEmploiPath = saveUpload($_FILES['attestation_emploi'] ?? [], UPLOAD_DIR, 'documents/', ['pdf','jpg','jpeg','png']);
        $cvPath                = saveUpload($_FILES['cv']                 ?? [], UPLOAD_DIR, 'documents/', ['pdf','jpg','jpeg','png']);
        $preuvePath = null; // Plus de pièce jointe requise

        $hashedPwd = password_hash($password, PASSWORD_BCRYPT);
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
        );
        $ref = genRef();

        $db->prepare('
            INSERT INTO users
                (id, name, nom, prenom, email, phone, address, birth_date,
                 birth_place, sexe, nationalite, bac_serie, bac_annee,
                 tuteur_nom, tuteur_lien, tuteur_adresse, tuteur_telephone,
                 urgence_nom, urgence_lien, urgence_adresse, urgence_telephone,
                 dernier_diplome, diplome_serie, diplome_annee, etablissement_origine,
                 niveau, regime, specialite, exp_pro, domaine_pro, mode_paiement,
                 class_id, password, role, status, blocked, avatar)
            VALUES
                (:id, :name, :nom, :prenom, :email, :phone, :address, :birth_date,
                 :birth_place, :sexe, :nationalite, :bac_serie, :bac_annee,
                 :tuteur_nom, :tuteur_lien, :tuteur_adresse, :tuteur_telephone,
                 :urgence_nom, :urgence_lien, :urgence_adresse, :urgence_telephone,
                 :dernier_diplome, :diplome_serie, :diplome_annee, :etablissement_origine,
                 :niveau, :regime, :specialite, :exp_pro, :domaine_pro, :mode_paiement,
                 :class_id, :password, :role, :status, :blocked, :avatar)
        ')->execute([
            ':id'                    => $uuid,
            ':name'                  => $prenom . ' ' . $nom,
            ':nom'                   => $nom,
            ':prenom'                => $prenom,
            ':email'                 => $email,
            ':phone'                 => $phone,
            ':address'               => ($address ?: null),
            ':birth_date'            => $birth_date,
            ':birth_place'           => $birth_place,
            ':sexe'                  => $sexe,
            ':nationalite'           => $nationalite,
            ':bac_serie'             => $bac_serie,
            ':bac_annee'             => $bac_annee,
            ':tuteur_nom'            => $tuteur_nom,
            ':tuteur_lien'           => $tuteur_lien,
            ':tuteur_adresse'        => $tuteur_adresse,
            ':tuteur_telephone'      => $tuteur_telephone,
            ':urgence_nom'           => $urgence_nom,
            ':urgence_lien'          => $urgence_lien,
            ':urgence_adresse'       => $urgence_adresse,
            ':urgence_telephone'     => $urgence_telephone,
            ':dernier_diplome'       => $dernier_diplome,
            ':diplome_serie'         => $diplome_serie,
            ':diplome_annee'         => $diplome_annee,
            ':etablissement_origine' => $etablissement_origine,
            ':niveau'                => ($niveau ?: null),
            ':regime'                => $regime,
            ':specialite'            => ($specialite ?: null),
            ':exp_pro'               => ($exp_pro ?: null),
            ':domaine_pro'           => ($domaine_pro ?: null),
            ':mode_paiement'         => ($mode_paiement ?: null),
            ':class_id'              => $class_id,
            ':password'              => $hashedPwd,
            ':role'                  => 'student',
            ':status'                => 'inactive',
            ':blocked'               => 1,
            ':avatar'                => $avatarPath,
        ]);

        try {
$db->prepare('
                INSERT INTO candidatures
                    (user_id, ref_dossier, niveau, niveau_autre, specialite, specialite_autre,
                     exp_pro, exp_pro_autre, domaine_pro, ville, mode_paiement, preuve_paiement,
                     acte_naissance_path, diplome_path, releve_notes_path,
                     photos_path, attestation_emploi_path, cv_path, created_at)
                VALUES
                    (:user_id, :ref, :niveau, :niveau_autre, :specialite, :specialite_autre,
                     :exp_pro, :exp_pro_autre, :domaine_pro, :ville, :mode_paiement, :preuve_paiement,
                     :acte_naissance, :diplome, :releve_notes,
                     :photos, :attestation_emploi, :cv, NOW())
            ')->execute([
                ':user_id'            => $uuid,
                ':ref'                => $ref,
                ':niveau'             => $niveau,
                ':niveau_autre'       => ($niveau_autre ?: null),
                ':specialite'         => mb_substr($specialite, 0, 50),
                ':specialite_autre'   => ($specialite_autre ?: null),
                ':exp_pro'            => $exp_pro,
                ':exp_pro_autre'      => ($exp_pro_autre ?: null),
                ':domaine_pro'        => $domaine_pro,
                ':ville'              => $ville,
                ':mode_paiement'      => $mode_paiement,
                ':preuve_paiement'    => $preuvePath,
                ':acte_naissance'     => $acteNaissancePath,
                ':diplome'            => $diplomePath,
                ':releve_notes'       => $releveNotesPath,
                ':photos'             => $photosPath,
                ':attestation_emploi' => $attestationEmploiPath,
                ':cv'                 => $cvPath,
            ]);
        } catch (PDOException $e) {
            error_log('Candidatures insert warning: ' . $e->getMessage());
        }

        $response = ['success' => true, 'message' => 'Candidature enregistrée avec succès.', 'ref' => $ref, 'name' => $prenom . ' ' . strtoupper($nom)];

        // ── SendGrid ──────────────────────────────────────────
        $_sg_stmt = $db->query("SELECT valeur FROM parametres WHERE cle='sendgrid_api_key' LIMIT 1");
        $_sg_row  = $_sg_stmt ? $_sg_stmt->fetch(\PDO::FETCH_ASSOC) : null;
        $sgApiKey    = trim($_sg_row['valeur'] ?? '');
        $sgFromEmail = 'contact@uvcoding.com';
        $sgFromName  = 'Université Virtuelle';
        $tplAdmin    = 'd-b6b105f7263645b6ab437513d0cc1e4e';
        $tplApproved = 'd-cc24b02213a34194ae272fdc9cb87481';
        $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host        = $_SERVER['HTTP_HOST'] ?? 'ISMM.uvcoding.com';
        $hasAvatar   = ($avatarPath !== 'default_avatar.png');

        try {
            if (!class_exists('\SendGrid')) throw new Exception('SendGrid non disponible');
            $sg = new \SendGrid($sgApiKey);

            $me = new \SendGrid\Mail\Mail();
            $me->setFrom($sgFromEmail, $sgFromName);
            $me->addTo($email, $prenom . ' ' . $nom);
            $me->setTemplateId($tplApproved);
            $me->addDynamicTemplateDatas(['student_name'=>$prenom.' '.strtoupper($nom),'student_id'=>$ref,'student_email'=>$email,'class_name'=>$specialite,'login_url'=>$protocol.'://'.$host.'/pages/login.html','support_email'=>$sgFromEmail,'current_year'=>date('Y'),'prenom'=>$prenom,'NOM'=>strtoupper($nom),'reference'=>$ref]);
            $me->setReplyTo($sgFromEmail, $sgFromName);
            $me->addCategory('student_registration');
            $r = $sg->send($me);
            if ($r->statusCode() !== 202) error_log('SendGrid étudiant: '.$r->statusCode().' — '.$r->body());

            $admins = $db->query("SELECT name, email FROM users WHERE role='admin' AND blocked=0")->fetchAll();
            foreach ($admins as $admin) {
                $ma = new \SendGrid\Mail\Mail();
                $ma->setFrom($sgFromEmail, $sgFromName);
                $ma->addTo($admin['email'], $admin['name']);
                $ma->setTemplateId($tplAdmin);
                $ma->addDynamicTemplateDatas(['admin_name'=>$admin['name'],'student_name'=>$prenom.' '.strtoupper($nom),'student_email'=>$email,'student_phone'=>$phone,'class_name'=>$specialite,'avatar_status'=>$hasAvatar?'✅ Photo fournie':'⚠️ Aucune photo','has_avatar'=>$hasAvatar,'registration_date'=>date('d/m/Y à H:i'),'validation_url'=>$protocol.'://'.$host.'/admin/pending_registrations.php','current_year'=>date('Y'),'ref_dossier'=>$ref]);
                $ma->setReplyTo($sgFromEmail, $sgFromName);
                $ma->addCategory('admin_notification');
                $ra = $sg->send($ma);
                if ($ra->statusCode() !== 202) error_log('SendGrid admin ('.$admin['email'].'): '.$ra->statusCode());
            }
        } catch (Exception $e) {
            error_log('SendGrid erreur: ' . $e->getMessage());
        }

    } catch (PDOException $e) {
        $response['message'] = 'Erreur base de données : ' . $e->getMessage();
    } catch (Exception $e) {
        $response['message'] = 'Erreur : ' . $e->getMessage();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}