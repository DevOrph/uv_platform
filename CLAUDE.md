# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Vue d'ensemble

UV (Université Virtuelle) est un ERP académique en **PHP procédural + mysqli + MySQL/MariaDB** (base `uvcoding`), servi par XAMPP sur macOS. Pas de framework, pas de routeur, pas de tests automatisés, pas de build : chaque fichier PHP est une page ou un endpoint accessible directement. L'interface et les commentaires sont en **français**.

## Environnement / commandes

- URL locale : `http://localhost/img/<dossier>/<page>.php`
- MySQL : `/Applications/XAMPP/xamppfiles/bin/mysql -u root uvcoding` (pas de mot de passe par défaut ; la config réelle est dans `.env` à la racine — DB_HOST/DB_NAME/DB_USER/DB_PASS)
- Exécuter un script SQL : `/Applications/XAMPP/xamppfiles/bin/mysql -u root uvcoding < fichier.sql`
- Dépendances Composer (déjà installées dans `vendor/`) : phpspreadsheet, dompdf, tcpdf, phpmailer, sendgrid, firebase/php-jwt
- `uvcoding.sql` à la racine = dump complet de référence du schéma (consulter pour la structure des tables)

## ⚠️ Dossiers à ne pas toucher

- **`UV/` est une copie complète du projet** (backup). Ne jamais y faire de modifications — travailler uniquement à la racine `img/`.
- `UV 5/` est un site statique indépendant (vitrine HTML).

## Structure par rôle

| Dossier | Contenu |
|---|---|
| `pages/` | Login/register/logout, reset password |
| `student/` | Pages étudiant (dashboard, notes, documents, discussions/devoirs) |
| `professor/` | Pages enseignant (cours, saisie notes, discussions/devoirs, honoraires) |
| `admin/` | Back-office complet (utilisateurs, classes, paiements, comptabilité, paramètres) |
| `grades/` | Module notes partagé teacher/admin (saisie, bulletins PDF/XLSX, statistiques, périodes) |
| `includes/` | Connexion DB, auth, headers/footers HTML, helpers |
| `api/`, `backend/` | Endpoints AJAX internes (paiements, emploi du temps) |
| `uv-api-mobile/` | API REST mobile séparée (JWT via firebase/php-jwt, v2 avec refresh tokens) |
| `cron/` | File d'emails (`email_queue`) et rappels de paiement |

## Boilerplate standard d'une page

Toute page protégée commence par :

```php
<?php
session_start(); // parfois omis : db_connect.php le fait aussi
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    header("Location: ../pages/login.php?error=access_denied"); // ou JSON pour un endpoint AJAX
    exit();
}
```

`includes/db_connect.php` fournit et définit :
- `$conn` (mysqli, charset utf8mb4, `collation_connection = utf8mb4_general_ci`)
- `ANNEE_ACADEMIQUE_COURANTE` — calculée (mois ≥ 9 → « YYYY-(YYYY+1) »), surchargée par `parametres.cle = 'annee_academique_courante'` si non vide
- `INSTITUTION_ID` (paramètre `institution_id`, fallback 'UAS')
- **CSRF automatique** : tout POST (hors login/register, `/api/`, `/uv-api-mobile/`) doit porter `csrf_token` (champ POST, header `X-CSRF-Token`, ou clé JSON). Utiliser la constante `CSRF_TOKEN` dans les formulaires et appels AJAX, sinon 403.

Variantes : `includes/auth_check.php` (session + SSO token, crée aussi `$conn`), `includes/db_config.php` (`get_db_config()`, `get_db_connection()`, `get_pdo_connection()` — PDO existe mais le code est presque entièrement mysqli avec requêtes préparées `bind_param`).

Sessions : `$_SESSION['user_id']`, `role`, `name`, `email`, `avatar`.

## Modèle de données — pièges importants

- **`users.id` est un varchar(36)** semi-lisible (`UAS-PRP-03`, `ADMIN01`, parfois UUID) → toujours binder en `"s"`. `role` = enum `student`/`teacher`/`admin`. `users.class_id` = **int**.
- **`courses.class_id` est un JSON array de chaînes** : `'["4","5","6"]'`. La visibilité étudiant se fait par :
  - SQL : `JSON_CONTAINS(c.class_id, JSON_QUOTE(?))` avec le class_id casté en chaîne (`json_encode(strval($class_id))` selon les pages)
  - ou PHP : `json_decode` + `in_array` (student_dashboard)
- **Collations hétérogènes** (tables en `utf8mb4_general_ci`, certaines colonnes `utf8mb4_bin`) : plusieurs requêtes utilisent `CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci` pour éviter les erreurs « Illegal mix of collations ». En cas d'erreur de collation, appliquer ce pattern.
- `courses` : `teacher_id`, `semester` (1/2), `coefficient`, `teaching_unit_id`. Vérification de propriété enseignant : `SELECT id FROM courses WHERE id = ? AND teacher_id = ?` (cf. `verify_teacher_course_access()` dans `professor/manage_discussions.php`).
- `parametres` (`cle`/`valeur`) : configuration globale clé-valeur.

## Notes (`grades`) — logique d'insertion

Référence : `grades/add_grade.php` et `grades/submit_grades.php`.

- Colonnes : `student_id` (varchar 36), `course_id`, `evaluation_type_id`, `evaluation_period_id`, `grade` decimal(4,2) **sur 20** (contrainte `chk_grade_range` 0–20), `comment`, `created_by`, `eval_number`.
- Période active : `SELECT id FROM evaluation_periods WHERE CURRENT_DATE BETWEEN start_date AND end_date` — ou mieux, `get_current_period($conn)` de `includes/semester_helper.php` (gère l'année courante, le forçage `semestre_force`, et les fallbacks).
- Insertion dans une **transaction**, suivie de `CALL calculate_student_average(?, ?, ?)` (procédure stockée : student_id, course_id, period_id).
- `evaluation_types` : Devoir/Examen/etc. avec `coefficient`.

## Conventions de code

- Pages monolithiques : un seul fichier PHP contenant traitement POST/AJAX en haut (blocs retournant du JSON avec `exit()`), puis le HTML avec CSS/JS inline. Les gros modules (discussions, devoirs) traitent plusieurs actions dans le même fichier via des champs POST discriminants.
- Endpoints AJAX : réponse `echo json_encode(['success' => bool, 'message' => ...])`.
- Uploads : dossier `uploads/`, noms générés `uniqid()` + extension, nom original conservé en colonne (`original_name`), cf. devoirs dans `professor/manage_discussions.php`.
- Layout : `include '../includes/header.php'` (ou `header_student.php`, `header_admin.php`, `header_grades.php`) et `../includes/footer.php`. Beaucoup de pages professor n'incluent que le footer et embarquent leur propre `<head>`.
- Emails : ne pas appeler SendGrid en direct — insérer dans `email_queue` (traitée par `cron/process_email_queue.php`).
- Le module devoirs (`course_assignments` / `assignment_submissions`) vit **dans** `professor/manage_discussions.php` et `student/manage_discussions.php` (pages type « classroom »), pas dans des fichiers dédiés.
