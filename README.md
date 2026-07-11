# UV — Université Virtuelle

ERP académique complet : scolarité (notes, bulletins, emploi du temps, présences), finance étudiante (paiements par tranches, relances), paie enseignants/staff, comptabilité en partie double, discussions/devoirs type « classroom », quiz en ligne et API mobile.

## Stack technique

- **PHP procédural** + mysqli (requêtes préparées) — pas de framework, pas de routeur, pas de build : chaque fichier PHP est une page ou un endpoint accessible directement.
- **MySQL/MariaDB**, base `uvcoding` (~73 tables). Le dump de référence du schéma est [`uvcoding.sql`](uvcoding.sql).
- Servi par **XAMPP** (développé sous macOS).
- Dépendances Composer (déjà dans `vendor/`) : phpspreadsheet, dompdf, tcpdf, phpmailer, sendgrid, firebase/php-jwt.
- Interface et commentaires en **français**.

## Installation locale

1. Placer le projet dans `htdocs` de XAMPP (dossier `img/`).
2. Créer la base et importer le schéma :
   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -e "CREATE DATABASE uvcoding CHARACTER SET utf8mb4"
   /Applications/XAMPP/xamppfiles/bin/mysql -u root uvcoding < uvcoding.sql
   ```
3. Configurer `.env` à la racine : `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
4. Ouvrir `http://localhost/img/pages/login.php`.

Les migrations ponctuelles se trouvent dans [`database/`](database/) et s'appliquent avec `mysql -u root uvcoding < database/<fichier>.sql`.

## Structure du projet

| Dossier | Contenu |
|---|---|
| `pages/` | Login, register, logout, réinitialisation de mot de passe |
| `student/` | Espace étudiant : dashboard, notes, documents, paiements, discussions/devoirs, quiz, rattrapages |
| `professor/` | Espace enseignant : cours, saisie de notes, discussions/devoirs, honoraires, quiz |
| `admin/` | Back-office : utilisateurs, classes, filières, paiements, comptabilité, attestations, emploi du temps, paramètres |
| `grades/` | Module notes partagé prof/admin : saisie, bulletins PDF/XLSX, statistiques, périodes d'évaluation |
| `includes/` | Socle commun : connexion DB, auth, headers/footers, helpers (semestre, verrou de notes, super admin, quiz, SendGrid) |
| `api/`, `backend/` | Endpoints AJAX internes (paiements, emploi du temps) |
| `uv-api-mobile/` | API REST mobile séparée (JWT via firebase/php-jwt, v2 avec refresh tokens) |
| `cron/` | File d'emails (`email_queue`) et rappels de paiement |
| `database/` | Scripts de migration SQL |
| `uploads/` | Fichiers déposés (devoirs, documents) |

⚠️ **Dossiers à ne pas toucher** : `UV/` est un backup complet du projet (ne jamais y travailler) ; `UV 5/` est un site vitrine statique indépendant.

## Fonctionnement d'une page

Toute page protégée suit le même boilerplate : `require_once '../includes/db_connect.php'` puis contrôle de session/rôle. `db_connect.php` fournit :

- `$conn` (mysqli, utf8mb4) ;
- `ANNEE_ACADEMIQUE_COURANTE` (calculée, surchargée par le paramètre `annee_academique_courante`) ;
- `INSTITUTION_ID` ;
- **protection CSRF automatique** : tout POST (hors login/register, `/api/`, `/uv-api-mobile/`) doit porter `csrf_token` — utiliser la constante `CSRF_TOKEN` dans les formulaires et appels AJAX.

Sessions : `$_SESSION['user_id']`, `role` (`student`/`teacher`/`admin`), `name`, `email`, `avatar`.

## Points d'attention du modèle de données

- `users.id` est un **varchar(36)** semi-lisible (`UAS-PRP-03`, `ADMIN01`…) → toujours binder en `"s"`.
- `courses.class_id` est un **JSON array de chaînes** (`'["4","5"]'`) : visibilité via `JSON_CONTAINS(c.class_id, JSON_QUOTE(?))` ou `json_decode` + `in_array`.
- Collations hétérogènes : en cas d'erreur « Illegal mix of collations », appliquer `CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci`.
- Notes sur 20 (`grades`, contrainte 0–20), insérées en transaction puis `CALL calculate_student_average(...)`. Période active via `get_current_period($conn)` (`includes/semester_helper.php`).
- **Verrou de notes** : modification bloquée 7 jours après saisie pour les enseignants (`includes/grade_lock.php`) — à appliquer sur tout nouvel endpoint notes.
- Super admin : utiliser `is_super_admin()` (`includes/super_admin.php`), pas d'ID en dur.

## Conventions

- Pages monolithiques : traitement POST/AJAX en haut du fichier (blocs JSON + `exit()`), HTML avec CSS/JS inline ensuite.
- Réponses AJAX : `{"success": bool, "message": ...}`.
- Emails : ne jamais appeler SendGrid en direct — insérer dans `email_queue`, traitée par `cron/process_email_queue.php`.
- Uploads : dossier `uploads/`, nom généré `uniqid()` + extension, nom original conservé en base.
- Le module devoirs (`course_assignments` / `assignment_submissions`) vit dans `professor/manage_discussions.php` et `student/manage_discussions.php`.

## Module Quiz (en cours — juillet 2026)

Schéma : [`uv_quiz_module.sql`](uv_quiz_module.sql) · Helpers : `includes/quiz_functions.php`
Côté enseignant : `professor/quiz_dashboard.php`, `quiz_manage.php`, `quiz_bank.php`, `quiz_aiken_import.php` (import de questions au format Aiken).
Côté étudiant : `student/quiz_list.php`, `quiz_take.php`, `quiz_result.php`.

## Tâches planifiées (cron)

| Script | Rôle |
|---|---|
| `cron/process_email_queue.php` | Envoi des emails en attente dans `email_queue` (SendGrid) |
| `cron/send_payment_reminders.php` | Rappels d'échéances de paiement |
| `cron/update_payment_deadlines.sh` | Mise à jour des échéances |
