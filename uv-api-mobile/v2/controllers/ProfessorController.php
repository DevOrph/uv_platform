<?php
/**
 * ProfessorController — classes, notes, emploi du temps, discussions
 * Phase 3 MVP — Enseignant
 */
class ProfessorController {

    public function __construct(private PDO $db) {}

    // ─── GET /professor/classes ───────────────────────────────────────────
    public function classes(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId = $user->user_id;

        $stmt = $this->db->prepare(
            'SELECT DISTINCT cl.id, cl.name,
                    (SELECT COUNT(*) FROM users u2
                     WHERE u2.class_id = cl.id AND u2.role = \'student\') AS student_count
             FROM classes cl
             INNER JOIN courses co
               ON JSON_CONTAINS(co.class_id, JSON_QUOTE(CAST(cl.id AS CHAR)), \'$\')
             WHERE co.teacher_id = ?
             ORDER BY cl.name'
        );
        $stmt->execute([$teacherId]);
        $classes = $stmt->fetchAll();

        Response::success(array_map(fn($c) => [
            'id'            => (int) $c['id'],
            'name'          => $c['name'],
            'student_count' => (int) $c['student_count'],
        ], $classes));
    }

    // ─── GET /professor/courses?class_id= ────────────────────────────────
    public function courses(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId = $user->user_id;
        $classId   = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;

        if (!$classId) {
            Response::error('class_id requis.', 422);
        }

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.coefficient, c.semester
             FROM courses c
             WHERE c.teacher_id = ?
               AND JSON_CONTAINS(c.class_id, JSON_QUOTE(CAST(? AS CHAR)), \'$\')
             ORDER BY c.name'
        );
        $stmt->execute([$teacherId, $classId]);
        $courses = $stmt->fetchAll();

        $stmt = $this->db->prepare(
            'SELECT id, name, avatar FROM users
             WHERE class_id = ? AND role = \'student\'
             ORDER BY name'
        );
        $stmt->execute([$classId]);
        $students = $stmt->fetchAll();

        Response::success([
            'courses'  => array_map(fn($c) => [
                'id'          => (int) $c['id'],
                'name'        => $c['name'],
                'coefficient' => (float) $c['coefficient'],
                'semester'    => (int) $c['semester'],
            ], $courses),
            'students' => array_map(fn($s) => [
                'id'         => $s['id'],
                'name'       => $s['name'],
                'avatar_url' => $s['avatar']
                    ? 'https://esiitech.uvcoding.com/uploads/avatars/' . $s['avatar']
                    : null,
            ], $students),
        ]);
    }

    // ─── GET /professor/schedule?week_offset=0 ────────────────────────────
    public function schedule(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId  = $user->user_id;
        $weekOffset = isset($_GET['week_offset']) ? (int) $_GET['week_offset'] : 0;

        $monday = new DateTime();
        $monday->modify('monday this week');
        if ($weekOffset !== 0) {
            $monday->modify("$weekOffset weeks");
        }

        $weekDates = [];
        for ($i = 0; $i < 7; $i++) {
            $d = clone $monday;
            $d->modify("+$i days");
            $weekDates[] = $d->format('Y-m-d');
        }

        $stmt = $this->db->prepare(
            'SELECT s.id,
                    c.name  AS course_name,
                    cl.name AS class_name,
                    r.name  AS classroom_name,
                    w.id    AS weekday_id,
                    w.name  AS weekday_name,
                    TIME_FORMAT(ts.start_time, "%H:%i") AS start_time,
                    TIME_FORMAT(ts.end_time,   "%H:%i") AS end_time
             FROM schedule s
             JOIN courses    c  ON c.id  = s.course_id
             JOIN classes    cl ON cl.id = s.class_id
             JOIN classrooms r  ON r.id  = s.classroom_id
             JOIN weekdays   w  ON w.id  = s.weekday_id
             JOIN time_slots ts ON ts.id = s.time_slot_id
             WHERE s.teacher_id = ?
               AND (s.start_date IS NULL OR s.start_date <= ?)
               AND (s.end_date   IS NULL OR s.end_date   >= ?)
             ORDER BY w.id, ts.start_time'
        );
        $stmt->execute([$teacherId, $weekDates[6], $weekDates[0]]);
        $rows = $stmt->fetchAll();

        $byDay = [];
        foreach ($rows as $slot) {
            $wid = (int) $slot['weekday_id'];
            if (!isset($byDay[$wid])) {
                $byDay[$wid] = [
                    'weekday_id'   => $wid,
                    'weekday_name' => $slot['weekday_name'],
                    'date'         => $weekDates[$wid - 1] ?? null,
                    'slots'        => [],
                ];
            }
            $byDay[$wid]['slots'][] = [
                'id'             => (int) $slot['id'],
                'course_name'    => $slot['course_name'],
                'class_name'     => $slot['class_name'],
                'classroom_name' => $slot['classroom_name'],
                'start_time'     => $slot['start_time'],
                'end_time'       => $slot['end_time'],
            ];
        }
        ksort($byDay);

        Response::success([
            'week_offset'  => $weekOffset,
            'week_start'   => $weekDates[0],
            'week_end'     => $weekDates[4],
            'current_date' => date('Y-m-d'),
            'days'         => array_values($byDay),
        ]);
    }

    // ─── GET /professor/exam-permission ──────────────────────────────────
    public function examPermission(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);

        if ($user->role === 'admin') {
            Response::success(['allowed' => true, 'reason' => 'admin']);
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM exam_permissions
             WHERE user_id = ? AND is_active = 1
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1'
        );
        $stmt->execute([$user->user_id]);

        Response::success(['allowed' => (bool) $stmt->fetch()]);
    }

    // ─── GET /professor/grades?course_id=&period_id= ─────────────────────
    public function grades(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId = $user->user_id;
        $courseId  = isset($_GET['course_id'])  ? (int) $_GET['course_id']  : 0;
        $periodId  = isset($_GET['period_id'])  ? (int) $_GET['period_id']  : 0;

        // Sans filtres : retourner les listes de sélection
        if (!$courseId || !$periodId) {
            Response::success([
                'courses'    => $this->getTeacherCourses($teacherId),
                'periods'    => $this->getPeriods(),
                'eval_types' => $this->getEvalTypes(),
            ]);
        }

        // Vérifier ownership du cours (admin exempt)
        if ($user->role !== 'admin') {
            $stmt = $this->db->prepare(
                'SELECT id FROM courses WHERE id = ? AND teacher_id = ?'
            );
            $stmt->execute([$courseId, $teacherId]);
            if (!$stmt->fetch()) {
                Response::error('Cours introuvable ou accès non autorisé.', 403);
            }
        }

        // Classes du cours
        $stmt = $this->db->prepare('SELECT class_id FROM courses WHERE id = ?');
        $stmt->execute([$courseId]);
        $courseRow = $stmt->fetch();
        $classIds  = json_decode($courseRow['class_id'] ?? '[]', true);
        $cleanIds  = array_values(array_filter(array_map('intval', (array) $classIds)));

        $students = [];
        if (!empty($cleanIds)) {
            $ph = implode(',', array_fill(0, count($cleanIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT id, name FROM users WHERE class_id IN ($ph) AND role = 'student' ORDER BY name"
            );
            $stmt->execute($cleanIds);
            $students = $stmt->fetchAll();
        }

        // Notes existantes
        $stmt = $this->db->prepare(
            'SELECT g.id, g.student_id, g.evaluation_type_id, g.grade, g.comment
             FROM grades g
             WHERE g.course_id = ? AND g.evaluation_period_id = ?'
        );
        $stmt->execute([$courseId, $periodId]);
        $grades = $stmt->fetchAll();

        Response::success([
            'students'   => array_map(fn($s) => [
                'id'   => $s['id'],
                'name' => $s['name'],
            ], $students),
            'grades'     => array_map(fn($g) => [
                'id'                 => (int) $g['id'],
                'student_id'         => $g['student_id'],
                'evaluation_type_id' => (int) $g['evaluation_type_id'],
                'grade'              => (float) $g['grade'],
                'comment'            => $g['comment'] ?? '',
            ], $grades),
            'eval_types' => $this->getEvalTypes(),
        ]);
    }

    // ─── POST /professor/grades ───────────────────────────────────────────
    public function addGrade(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId = $user->user_id;

        if ($user->role !== 'admin' && !$this->hasExamPermission($teacherId)) {
            Response::error('Vous n\'avez pas la permission de saisir des notes.', 403);
        }

        $body = Response::getJsonBody();
        Response::requireFields($body, ['student_id', 'course_id', 'evaluation_type_id', 'grade', 'period_id']);

        $studentId  = (string) $body['student_id'];
        $courseId   = (int)    $body['course_id'];
        $evalTypeId = (int)    $body['evaluation_type_id'];
        $grade      = (float)  $body['grade'];
        $periodId   = (int)    $body['period_id'];
        $comment    = trim((string) ($body['comment'] ?? ''));

        if ($grade < 0 || $grade > 20) {
            Response::error('La note doit être comprise entre 0 et 20.', 422);
        }

        if ($user->role !== 'admin') {
            $stmt = $this->db->prepare(
                'SELECT id FROM courses WHERE id = ? AND teacher_id = ?'
            );
            $stmt->execute([$courseId, $teacherId]);
            if (!$stmt->fetch()) {
                Response::error('Cours non assigné à cet enseignant.', 403);
            }
        }

        // Doublon
        $stmt = $this->db->prepare(
            'SELECT id FROM grades
             WHERE student_id = ? AND course_id = ? AND evaluation_type_id = ? AND evaluation_period_id = ?
             LIMIT 1'
        );
        $stmt->execute([$studentId, $courseId, $evalTypeId, $periodId]);
        if ($stmt->fetch()) {
            Response::error('Une note existe déjà pour cet étudiant / cours / type. Utilisez la modification.', 409);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO grades
               (student_id, course_id, evaluation_type_id, evaluation_period_id, grade, comment, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$studentId, $courseId, $evalTypeId, $periodId, $grade, $comment ?: null, $teacherId]);

        Response::success(['id' => (int) $this->db->lastInsertId()], 'Note enregistrée avec succès.', 201);
    }

    // ─── PUT /professor/grades ────────────────────────────────────────────
    public function updateGrade(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId = $user->user_id;

        if ($user->role !== 'admin' && !$this->hasExamPermission($teacherId)) {
            Response::error('Vous n\'avez pas la permission de modifier des notes.', 403);
        }

        $body = Response::getJsonBody();
        Response::requireFields($body, ['id', 'grade']);

        $gradeId = (int)   $body['id'];
        $grade   = (float) $body['grade'];
        $comment = trim((string) ($body['comment'] ?? ''));

        if ($grade < 0 || $grade > 20) {
            Response::error('La note doit être comprise entre 0 et 20.', 422);
        }

        if ($user->role !== 'admin') {
            $stmt = $this->db->prepare(
                'SELECT g.id FROM grades g
                 JOIN courses c ON c.id = g.course_id
                 WHERE g.id = ? AND c.teacher_id = ?'
            );
            $stmt->execute([$gradeId, $teacherId]);
            if (!$stmt->fetch()) {
                Response::error('Note introuvable ou accès non autorisé.', 403);
            }

            // Verrou : un enseignant ne modifie plus une note trop ancienne
            // (parametres.verrou_notes_jours, défaut 7 ; <= 0 = désactivé)
            $days = 7;
            $v = $this->db->query("SELECT valeur FROM parametres WHERE cle = 'verrou_notes_jours' LIMIT 1")->fetchColumn();
            if ($v !== false && is_numeric(trim((string) $v))) {
                $days = (int) trim((string) $v);
            }
            if ($days > 0) {
                $stmt = $this->db->prepare('SELECT 1 FROM grades WHERE id = ? AND created_at <= NOW() - INTERVAL ? DAY');
                $stmt->execute([$gradeId, $days]);
                if ($stmt->fetch()) {
                    Response::error("Cette note a été saisie il y a plus de $days jour(s) : elle est verrouillée. Contactez l'administration.", 403);
                }
            }
        }

        $stmt = $this->db->prepare(
            'UPDATE grades SET grade = ?, comment = ? WHERE id = ?'
        );
        $stmt->execute([$grade, $comment ?: null, $gradeId]);

        Response::success(null, 'Note modifiée avec succès.');
    }

    // ─── GET /professor/discussions?course_id= ────────────────────────────
    public function discussions(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId = $user->user_id;
        $courseId  = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;

        // Sans course_id : retourner les cours de l'enseignant
        if (!$courseId) {
            Response::success(['courses' => $this->getTeacherCourses($teacherId)]);
        }

        if ($user->role !== 'admin') {
            $stmt = $this->db->prepare(
                'SELECT id FROM courses WHERE id = ? AND teacher_id = ?'
            );
            $stmt->execute([$courseId, $teacherId]);
            if (!$stmt->fetch()) {
                Response::error('Accès non autorisé.', 403);
            }
        }

        $stmt = $this->db->prepare(
            'SELECT d.id, d.sender_id, d.message, d.created_at,
                    u.name AS sender_name, u.avatar AS sender_avatar, u.role AS sender_role
             FROM discussions d
             JOIN users u ON u.id = d.sender_id
             WHERE d.course_id = ?
             ORDER BY d.created_at ASC'
        );
        $stmt->execute([$courseId]);
        $rows = $stmt->fetchAll();

        // Documents attachés à chaque message (même logique que le web)
        $docStmt = $this->db->prepare(
            'SELECT doc.id, doc.discussion_id, doc.file_path, doc.is_teacher
             FROM documents doc
             JOIN discussions d ON d.id = doc.discussion_id
             WHERE d.course_id = ?
             ORDER BY doc.uploaded_at ASC'
        );
        $docStmt->execute([$courseId]);
        $docsByMsg = [];
        foreach ($docStmt->fetchAll() as $doc) {
            $docsByMsg[(int) $doc['discussion_id']][] = [
                'id'         => (int) $doc['id'],
                'file_path'  => $doc['file_path'],
                'file_url'   => 'https://esiitech.uvcoding.com/uploads/' . rawurlencode($doc['file_path']),
                'is_teacher' => (bool) $doc['is_teacher'],
            ];
        }

        Response::success([
            'messages' => array_map(fn($m) => [
                'id'            => (int) $m['id'],
                'sender_id'     => $m['sender_id'],
                'sender_name'   => $m['sender_name'],
                'sender_role'   => $m['sender_role'],
                'sender_avatar' => $m['sender_avatar']
                    ? 'https://esiitech.uvcoding.com/uploads/avatars/' . $m['sender_avatar']
                    : null,
                'message'       => $m['message'],
                'created_at'    => $m['created_at'],
                'documents'     => $docsByMsg[(int) $m['id']] ?? [],
            ], $rows),
        ]);
    }

    // ─── POST /professor/discussions ──────────────────────────────────────
    public function sendMessage(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId = $user->user_id;

        $body = Response::getJsonBody();
        Response::requireFields($body, ['course_id', 'message']);

        $courseId = (int)    $body['course_id'];
        $message  = trim((string) $body['message']);

        if (strlen($message) === 0) {
            Response::error('Le message ne peut pas être vide.', 422);
        }

        if ($user->role !== 'admin') {
            $stmt = $this->db->prepare(
                'SELECT id FROM courses WHERE id = ? AND teacher_id = ?'
            );
            $stmt->execute([$courseId, $teacherId]);
            if (!$stmt->fetch()) {
                Response::error('Accès non autorisé.', 403);
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO discussions (sender_id, course_id, message) VALUES (?, ?, ?)'
        );
        $stmt->execute([$teacherId, $courseId, $message]);

        Response::success(['id' => (int) $this->db->lastInsertId()], 'Message envoyé.', 201);
    }

    // ─── Helpers privés ───────────────────────────────────────────────────

    private function hasExamPermission(string $userId): bool {
        $stmt = $this->db->prepare(
            'SELECT id FROM exam_permissions
             WHERE user_id = ? AND is_active = 1
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        return (bool) $stmt->fetch();
    }

    private function getPeriods(): array {
        return $this->db->query(
            'SELECT id, name FROM evaluation_periods ORDER BY id DESC'
        )->fetchAll();
    }

    private function getEvalTypes(): array {
        try {
            return $this->db->query(
                'SELECT id, name FROM evaluation_types ORDER BY id'
            )->fetchAll();
        } catch (\PDOException) {
            return [];
        }
    }

    private function getTeacherCourses(string $teacherId): array {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.coefficient, c.semester, c.class_id
             FROM courses c
             WHERE c.teacher_id = ?
             ORDER BY c.name'
        );
        $stmt->execute([$teacherId]);
        return array_map(fn($c) => [
            'id'          => (int) $c['id'],
            'name'        => $c['name'],
            'coefficient' => (float) $c['coefficient'],
            'semester'    => (int) $c['semester'],
        ], $stmt->fetchAll());
    }

    // ─── GET /professor/profile ───────────────────────────────────────────────
    public function profile(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId = $user->user_id;

        $stmt = $this->db->prepare(
            "SELECT id, name, email, phone, avatar, created_at
             FROM users WHERE id = ? AND role = 'teacher'"
        );
        $stmt->execute([$teacherId]);
        $teacher = $stmt->fetch();
        if (!$teacher) Response::error('Enseignant introuvable.', 404);

        $coursesStmt = $this->db->prepare(
            'SELECT id, name, coefficient, semester FROM courses WHERE teacher_id = ? ORDER BY name'
        );
        $coursesStmt->execute([$teacherId]);
        $courses = $coursesStmt->fetchAll();

        $classesStmt = $this->db->prepare(
            "SELECT DISTINCT cl.id, cl.name
             FROM classes cl
             INNER JOIN courses co ON JSON_CONTAINS(co.class_id, JSON_QUOTE(CAST(cl.id AS CHAR)), '\$')
             WHERE co.teacher_id = ?
             ORDER BY cl.name"
        );
        $classesStmt->execute([$teacherId]);
        $classes = $classesStmt->fetchAll();

        $statsStmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT d.id) AS total_discussions,
                    COUNT(DISTINCT doc.id) AS total_documents
             FROM courses c
             LEFT JOIN discussions d   ON c.id = d.course_id
             LEFT JOIN documents doc   ON d.id = doc.discussion_id
             WHERE c.teacher_id = ?'
        );
        $statsStmt->execute([$teacherId]);
        $stats = $statsStmt->fetch();

        Response::success([
            'id'           => $teacher['id'],
            'name'         => $teacher['name'],
            'email'        => $teacher['email'],
            'phone'        => $teacher['phone'] ?? '',
            'avatar_url'   => $teacher['avatar']
                ? 'https://esiitech.uvcoding.com/uploads/avatars/' . $teacher['avatar']
                : null,
            'created_at'   => $teacher['created_at'],
            'course_count' => count($courses),
            'class_count'  => count($classes),
            'stats' => [
                'total_discussions' => (int) $stats['total_discussions'],
                'total_documents'   => (int) $stats['total_documents'],
            ],
            'courses' => array_map(fn($c) => [
                'id'          => (int) $c['id'],
                'name'        => $c['name'],
                'coefficient' => (float) $c['coefficient'],
                'semester'    => (int) $c['semester'],
            ], $courses),
            'classes' => array_map(fn($c) => [
                'id'   => (int) $c['id'],
                'name' => $c['name'],
            ], $classes),
        ]);
    }

    // ─── PUT /professor/profile ───────────────────────────────────────────────
    public function updateProfile(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $userId  = $user->user_id;
        $body    = Response::getJsonBody();
        $updates = [];
        $params  = [];

        if (isset($body['email'])) {
            $email = trim($body['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Adresse email invalide.', 422);
            }
            $check = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $check->execute([$email, $userId]);
            if ($check->fetch()) Response::error('Cette adresse email est déjà utilisée.', 409);
            $updates[] = 'email = ?';
            $params[]  = $email;
        }

        if (isset($body['phone'])) {
            $updates[] = 'phone = ?';
            $params[]  = trim($body['phone']);
        }

        if (empty($updates)) Response::error('Aucun champ à mettre à jour.', 422);

        $params[] = $userId;
        $this->db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')
                 ->execute($params);

        Response::success(null, 'Profil mis à jour avec succès.');
    }

    // ─── POST /professor/change-password ──────────────────────────────────────
    public function changePassword(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);

        $body = Response::getJsonBody();
        Response::requireFields($body, ['current_password', 'new_password']);

        $userId = $user->user_id;

        $stmt = $this->db->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($body['current_password'], $row['password'])) {
            Response::error('Mot de passe actuel incorrect.', 401);
        }

        if (strlen($body['new_password']) < 6) {
            Response::error('Le nouveau mot de passe doit contenir au moins 6 caractères.', 422);
        }

        $hashed = password_hash($body['new_password'], PASSWORD_DEFAULT);
        $this->db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hashed, $userId]);

        Response::success(null, 'Mot de passe modifié avec succès.');
    }

    // ─── GET /professor/rattrapage?class_id=&period_id= ──────────────────────
    public function rattrapageList(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId = $user->user_id;

        $canSaisie = $user->role === 'admin';
        if (!$canSaisie) {
            $stmt = $this->db->prepare(
                'SELECT id FROM exam_permissions
                 WHERE user_id = ? AND is_active = 1 AND allow_rattrapage = 1
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1'
            );
            $stmt->execute([$teacherId]);
            $canSaisie = (bool) $stmt->fetch();
        }

        $classesStmt = $this->db->prepare(
            "SELECT DISTINCT cl.id, cl.name
             FROM classes cl
             INNER JOIN courses co ON JSON_CONTAINS(co.class_id, JSON_QUOTE(CAST(cl.id AS CHAR)), '\$')
             WHERE co.teacher_id = ?
             ORDER BY cl.name"
        );
        $classesStmt->execute([$teacherId]);
        $classes = $classesStmt->fetchAll();

        $periods = $this->db->query(
            'SELECT id, name FROM evaluation_periods ORDER BY name DESC'
        )->fetchAll();

        $classId  = isset($_GET['class_id'])  ? (int) $_GET['class_id']  : 0;
        $periodId = isset($_GET['period_id']) ? (int) $_GET['period_id'] : 0;

        $classesMapped  = array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $classes);
        $periodsMapped  = array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name']], $periods);

        if (!$classId || !$periodId) {
            Response::success([
                'can_saisie'  => $canSaisie,
                'classes'     => $classesMapped,
                'periods'     => $periodsMapped,
                'summary'     => ['pending' => 0, 'graded' => 0],
                'rattrapages' => [],
            ]);
        }

        $stmt = $this->db->prepare(
            "SELECT r.id, r.student_id, r.status, r.grade, r.original_average,
                    r.eligibility_reason, r.graded_at, r.comment,
                    u.name  AS student_name,
                    co.name AS course_name,
                    tu.name AS ue_name,
                    tu.code AS ue_code
             FROM rattrapages r
             JOIN users u    ON r.student_id = u.id
             JOIN courses co ON r.course_id  = co.id
             LEFT JOIN teaching_units tu ON co.teaching_unit_id = tu.id
             WHERE co.teacher_id           = ?
               AND u.class_id              = ?
               AND r.evaluation_period_id  = ?
             ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END, u.name, co.name"
        );
        $stmt->execute([$teacherId, $classId, $periodId]);
        $rows = $stmt->fetchAll();

        $pending = count(array_filter($rows, fn($r) => $r['status'] === 'pending'));
        $graded  = count($rows) - $pending;

        Response::success([
            'can_saisie'  => $canSaisie,
            'classes'     => $classesMapped,
            'periods'     => $periodsMapped,
            'summary'     => ['pending' => $pending, 'graded' => $graded],
            'rattrapages' => array_map(fn($r) => [
                'id'                 => (int) $r['id'],
                'student_name'       => $r['student_name'],
                'course_name'        => $r['course_name'],
                'ue_code'            => $r['ue_code'],
                'ue_name'            => $r['ue_name'],
                'status'             => $r['status'],
                'grade'              => $r['grade'] !== null ? (float) $r['grade'] : null,
                'original_average'   => $r['original_average'] !== null ? (float) $r['original_average'] : null,
                'eligibility_reason' => $r['eligibility_reason'],
                'graded_at'          => $r['graded_at'],
                'comment'            => $r['comment'],
            ], $rows),
        ]);
    }

    // ─── POST /professor/rattrapage ───────────────────────────────────────────
    public function saveRattrapageGrade(?object $user): void {
        Auth::requireRole($user, ['teacher', 'admin']);
        $teacherId = $user->user_id;

        $canSaisie = $user->role === 'admin';
        if (!$canSaisie) {
            $stmt = $this->db->prepare(
                'SELECT id FROM exam_permissions
                 WHERE user_id = ? AND is_active = 1 AND allow_rattrapage = 1
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1'
            );
            $stmt->execute([$teacherId]);
            $canSaisie = (bool) $stmt->fetch();
        }

        if (!$canSaisie) {
            Response::error("Vous n'avez pas l'autorisation de saisir des notes de rattrapage.", 403);
        }

        $body = Response::getJsonBody();
        Response::requireFields($body, ['ratt_id', 'grade']);

        $rattId  = (int)   $body['ratt_id'];
        $grade   = (float) $body['grade'];
        $comment = trim((string) ($body['comment'] ?? ''));

        if ($grade < 0 || $grade > 20) {
            Response::error('La note doit être comprise entre 0 et 20.', 422);
        }

        // Vérification ownership (admin exempt)
        if ($user->role !== 'admin') {
            $check = $this->db->prepare(
                "SELECT r.id FROM rattrapages r
                 JOIN courses c ON r.course_id = c.id
                 WHERE r.id = ? AND c.teacher_id = ? AND r.status = 'pending'"
            );
            $check->execute([$rattId, $teacherId]);
        } else {
            $check = $this->db->prepare(
                "SELECT id FROM rattrapages WHERE id = ? AND status = 'pending'"
            );
            $check->execute([$rattId]);
        }

        if (!$check->fetch()) {
            Response::error('Rattrapage introuvable ou déjà noté.', 404);
        }

        $this->db->prepare(
            "UPDATE rattrapages
             SET grade = ?, comment = ?, status = 'graded', graded_at = NOW(), graded_by = ?
             WHERE id = ?"
        )->execute([$grade, $comment ?: null, $teacherId, $rattId]);

        Response::success(null, 'Note de rattrapage enregistrée avec succès.');
    }
}
