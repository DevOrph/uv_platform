<?php
/**
 * DocumentController — gestion des documents partagés dans les discussions
 * Réplique exactement la logique de manage_discussions.php (web)
 */
class DocumentController {

    private string $uploadDir;
    private const BASE_URL  = 'https://esiitech.uvcoding.com/uploads/';
    private const MAX_SIZE  = 40 * 1024 * 1024; // 40 Mo (identique au web)

    public function __construct(private PDO $db) {
        // Remonter depuis /uv-api-mobile/v2/ jusqu'à /public_html/uploads/
        $this->uploadDir = dirname(dirname(API_ROOT)) . '/uploads/';
    }

    // ─── GET /student/documents?course_id=X ───────────────────────────────
    public function studentList(?object $user): void {
        Auth::requireRole($user, ['student', 'admin']);
        $this->listDocuments($user, 'student');
    }

    // ─── GET /professor/documents?course_id=X ─────────────────────────────
    public function professorList(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $this->listDocuments($user, 'teacher');
    }

    // ─── POST /student/documents (multipart/form-data) ────────────────────
    public function studentUpload(?object $user): void {
        Auth::requireRole($user, ['student', 'admin']);
        $this->uploadDocument($user, false);
    }

    // ─── POST /professor/documents (multipart/form-data) ──────────────────
    public function professorUpload(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $this->uploadDocument($user, true);
    }

    // ─── DELETE /student/documents?id=X ───────────────────────────────────
    public function studentDelete(?object $user): void {
        Auth::requireRole($user, ['student', 'admin']);
        $this->deleteDocument($user);
    }

    // ─── DELETE /professor/documents?id=X ─────────────────────────────────
    public function professorDelete(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $this->deleteDocument($user);
    }

    // ─── Logique commune ──────────────────────────────────────────────────

    private function listDocuments(?object $user, string $callerRole): void {
        $courseId = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
        if (!$courseId) {
            Response::error('course_id requis.', 422);
        }

        $this->verifyAccess($user, $courseId, $callerRole);

        // Même requête que le web : doc JOIN users, sous-requête sur course_id
        $stmt = $this->db->prepare(
            'SELECT doc.id, doc.file_path, doc.uploaded_by, doc.is_teacher, doc.uploaded_at,
                    u.name AS uploader_name, u.role AS uploader_role
             FROM documents doc
             JOIN users u ON u.id = doc.uploaded_by
             WHERE doc.discussion_id IN (
                 SELECT id FROM discussions WHERE course_id = ? AND academic_year = ?
             )
             ORDER BY doc.is_teacher DESC, doc.uploaded_at DESC'
        );
        $stmt->execute([$courseId, ANNEE_ACADEMIQUE_COURANTE]);
        $rows = $stmt->fetchAll();

        $teacher_docs = [];
        $student_docs = [];

        foreach ($rows as $r) {
            $doc = [
                'id'            => (int) $r['id'],
                'file_path'     => $r['file_path'],
                'file_url'      => self::BASE_URL . rawurlencode($r['file_path']),
                'uploaded_by'   => $r['uploaded_by'],
                'uploader_name' => $r['uploader_name'],
                'is_teacher'    => (bool) $r['is_teacher'],
                'uploaded_at'   => $r['uploaded_at'],
            ];
            if ((bool) $r['is_teacher']) {
                $teacher_docs[] = $doc;
            } else {
                $student_docs[] = $doc;
            }
        }

        Response::success([
            'teacher_documents' => $teacher_docs,
            'student_documents' => $student_docs,
        ]);
    }

    private function uploadDocument(?object $user, bool $isTeacher): void {
        $courseId = isset($_POST['course_id']) ? (int) $_POST['course_id'] : 0;
        if (!$courseId) {
            Response::error('course_id requis.', 422);
        }

        $this->verifyAccess($user, $courseId, $isTeacher ? 'teacher' : 'student');

        // Normaliser : 'file' (unique) ou 'files' (multiple) → tableau uniforme
        $files = $this->normalizeFiles();
        if (empty($files)) {
            Response::error('Aucun fichier fourni.', 422);
        }

        $uploaded = [];
        $errors   = [];

        foreach ($files as $f) {
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = $f['name'] . ' : erreur d\'upload (' . $f['error'] . ')';
                continue;
            }
            if ($f['size'] > self::MAX_SIZE) {
                $errors[] = $f['name'] . ' : trop volumineux (max 40 Mo)';
                continue;
            }

            $filename = basename($f['name']);
            $target   = $this->uploadDir . $filename;

            if (!move_uploaded_file($f['tmp_name'], $target)) {
                $errors[] = $f['name'] . ' : échec du déplacement';
                continue;
            }

            // Crée une entrée discussions vide, comme le fait le web
            $stmt = $this->db->prepare(
                'INSERT INTO discussions (course_id, sender_id, message, academic_year, semester, created_at)
                 VALUES (?, ?, \'\', ?, 1, NOW())'
            );
            $stmt->execute([$courseId, $user->user_id, ANNEE_ACADEMIQUE_COURANTE]);
            $discussionId = (int) $this->db->lastInsertId();

            // Enregistre le document
            $stmt = $this->db->prepare(
                'INSERT INTO documents (discussion_id, file_path, uploaded_by, is_teacher, uploaded_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            $stmt->execute([$discussionId, $filename, $user->user_id, $isTeacher ? 1 : 0]);

            $uploaded[] = [
                'id'         => (int) $this->db->lastInsertId(),
                'file_path'  => $filename,
                'file_url'   => self::BASE_URL . rawurlencode($filename),
                'is_teacher' => $isTeacher,
            ];
        }

        if (empty($uploaded)) {
            Response::error(implode('; ', $errors), 422);
        }

        Response::success(
            ['uploaded' => $uploaded, 'errors' => $errors],
            count($uploaded) . ' fichier(s) téléchargé(s) avec succès.',
            201
        );
    }

    private function deleteDocument(?object $user): void {
        $docId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if (!$docId) {
            Response::error('id requis.', 422);
        }

        // L'utilisateur ne peut supprimer que ses propres documents (identique au web)
        $stmt = $this->db->prepare(
            'SELECT file_path FROM documents WHERE id = ? AND uploaded_by = ?'
        );
        $stmt->execute([$docId, $user->user_id]);
        $doc = $stmt->fetch();

        if (!$doc) {
            Response::error('Document introuvable ou accès non autorisé.', 403);
        }

        $this->db->prepare('DELETE FROM documents WHERE id = ?')->execute([$docId]);

        Response::success(null, 'Document supprimé avec succès.');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * Normalise $_FILES pour supporter 'file' (unique) et 'files[]' (multiple).
     */
    private function normalizeFiles(): array {
        $files = [];

        if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
            $count = count($_FILES['files']['name']);
            for ($i = 0; $i < $count; $i++) {
                $files[] = [
                    'name'     => $_FILES['files']['name'][$i],
                    'tmp_name' => $_FILES['files']['tmp_name'][$i],
                    'size'     => $_FILES['files']['size'][$i],
                    'error'    => $_FILES['files']['error'][$i],
                ];
            }
        } elseif (!empty($_FILES['file']['name'])) {
            $files[] = [
                'name'     => $_FILES['file']['name'],
                'tmp_name' => $_FILES['file']['tmp_name'],
                'size'     => $_FILES['file']['size'],
                'error'    => $_FILES['file']['error'],
            ];
        }

        return $files;
    }

    /**
     * Vérifie que l'utilisateur a le droit d'accéder au cours.
     * Enseignant : doit être assigné au cours.
     * Étudiant : le cours doit être dans l'EDT de sa classe.
     */
    private function verifyAccess(?object $user, int $courseId, string $callerRole): void {
        if ($user->role === 'admin') return;

        if ($callerRole === 'teacher') {
            $stmt = $this->db->prepare(
                'SELECT id FROM courses WHERE id = ? AND teacher_id = ?'
            );
            $stmt->execute([$courseId, $user->user_id]);
            if (!$stmt->fetch()) {
                Response::error('Accès non autorisé à ce cours.', 403);
            }
        } else {
            $stmt = $this->db->prepare('SELECT class_id FROM users WHERE id = ?');
            $stmt->execute([$user->user_id]);
            $row     = $stmt->fetch();
            $classId = $row['class_id'] ?? null;

            if ($classId) {
                $stmt = $this->db->prepare(
                    'SELECT 1 FROM schedule WHERE course_id = ? AND class_id = ? LIMIT 1'
                );
                $stmt->execute([$courseId, $classId]);
                if (!$stmt->fetch()) {
                    Response::error('Accès non autorisé à ce cours.', 403);
                }
            }
        }
    }
}
