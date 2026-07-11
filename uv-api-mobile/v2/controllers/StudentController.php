<?php
/**
 * StudentController — profil, notes, emploi du temps, annonces
 * Phase 2 MVP — Étudiant
 */
class StudentController {

    public function __construct(private PDO $db) {}

    // ─── GET /student/profile ──────────────────────────────────────────────
    public function profile(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);

        $stmt = $this->db->prepare(
            'SELECT u.id, u.name, u.email, u.phone, u.avatar, u.class_id,
                    c.name AS class_name, c.image_path AS class_image, u.created_at
             FROM users u
             LEFT JOIN classes c ON c.id = u.class_id
             WHERE u.id = ?'
        );
        $stmt->execute([$currentUser->user_id]);
        $student = $stmt->fetch();

        if (!$student) {
            Response::error('Étudiant introuvable.', 404);
        }

        // Champs optionnels (colonnes qui peuvent ne pas exister sur tous les déploiements)
        $extra = $this->fetchOptionalColumns($currentUser->user_id, ['matricule', 'date_of_birth', 'place_of_birth']);

        Response::success([
            'id'              => $student['id'],
            'name'            => $student['name'],
            'email'           => $student['email'],
            'phone'           => $student['phone'] ?? '',
            'avatar_url'      => $this->avatarUrl($student['avatar']),
            'class_id'        => $student['class_id'],
            'class_name'      => $student['class_name'] ?? 'Non assigné',
            'class_image_url' => $this->uploadUrl($student['class_image']),
            'matricule'       => $extra['matricule']      ?? $student['id'],
            'date_of_birth'   => $extra['date_of_birth']  ?? null,
            'place_of_birth'  => $extra['place_of_birth'] ?? null,
            'created_at'      => $student['created_at'],
        ]);
    }

    // ─── PUT /student/profile ──────────────────────────────────────────────
    public function updateProfile(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);

        $body   = Response::getJsonBody();
        $userId = $currentUser->user_id;

        $updates = [];
        $params  = [];

        if (isset($body['email'])) {
            $email = trim($body['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Adresse email invalide.', 422);
            }
            // Unicité de l'email
            $check = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $check->execute([$email, $userId]);
            if ($check->fetch()) {
                Response::error('Cette adresse email est déjà utilisée.', 409);
            }
            $updates[] = 'email = ?';
            $params[]  = $email;
        }

        if (isset($body['phone'])) {
            $updates[] = 'phone = ?';
            $params[]  = trim($body['phone']);
        }

        if (empty($updates)) {
            Response::error('Aucun champ à mettre à jour.', 422);
        }

        $params[] = $userId;
        $this->db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')
                 ->execute($params);

        Response::success(null, 'Profil mis à jour avec succès.');
    }

    // ─── POST /student/change-password ────────────────────────────────────
    public function changePassword(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);

        $body = Response::getJsonBody();
        Response::requireFields($body, ['current_password', 'new_password']);

        $userId = $currentUser->user_id;

        $stmt = $this->db->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($body['current_password'], $user['password'])) {
            Response::error('Mot de passe actuel incorrect.', 401);
        }

        if (strlen($body['new_password']) < 6) {
            Response::error('Le nouveau mot de passe doit contenir au moins 6 caractères.', 422);
        }

        $hashed = password_hash($body['new_password'], PASSWORD_DEFAULT);
        $this->db->prepare('UPDATE users SET password = ? WHERE id = ?')
                 ->execute([$hashed, $userId]);

        Response::success(null, 'Mot de passe modifié avec succès.');
    }

    // ─── GET /student/grades?period_id= ───────────────────────────────────
    public function grades(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);

        $studentId = $currentUser->user_id;
        $periodId  = isset($_GET['period_id']) ? (int) $_GET['period_id'] : null;

        // Classe de l'étudiant
        $stmt = $this->db->prepare('SELECT class_id FROM users WHERE id = ?');
        $stmt->execute([$studentId]);
        $row     = $stmt->fetch();
        $classId = $row['class_id'] ?? null;

        if (!$classId) {
            Response::error('Aucune classe assignée à ce compte.', 404);
        }

        $periods = $this->getPeriods();

        // Sans period_id → liste des périodes uniquement
        if (!$periodId) {
            Response::success([
                'periods'             => $periods,
                'teaching_units'      => [],
                'general_average'     => 0,
                'mention'             => 'N/A',
                'semestre_valide'     => false,
                'total_ects_required' => 0,
                'total_ects_validated'=> 0,
            ], 'Sélectionnez une période.');
        }

        // Vérifier que teaching_units existe
        $tableCheck = $this->db->query("SHOW TABLES LIKE 'teaching_units'")->fetch();
        if (!$tableCheck) {
            Response::error('Les unités d\'enseignement ne sont pas encore configurées.', 503);
        }

        // UEs pour cette classe et ce semestre (period_id = semester dans le bulletin)
        $stmt = $this->db->prepare(
            'SELECT id, code, name, short_name
             FROM teaching_units
             WHERE class_id = ? AND semester = ?
             ORDER BY display_order'
        );
        $stmt->execute([$classId, $periodId]);
        $teachingUnits = $stmt->fetchAll();

        if (empty($teachingUnits)) {
            Response::success([
                'periods'             => $periods,
                'teaching_units'      => [],
                'general_average'     => 0,
                'mention'             => 'N/A',
                'semestre_valide'     => false,
                'total_ects_required' => 0,
                'total_ects_validated'=> 0,
            ], 'Aucune UE configurée pour cette période.');
        }

        // Même logique que generate_pdf_bulletin_new.php
        $examCoeff   = 0.6;
        $devoirCoeff = 0.4;

        $allGrades             = [];
        $totalCreditsRequired  = 0;
        $totalCreditsValidated = 0;
        $totalWeightedSum      = 0;

        foreach ($teachingUnits as $unit) {
            $unitId   = $unit['id'];
            $unitData = [
                'code'             => $unit['code'],
                'name'             => $unit['name'],
                'short_name'       => $unit['short_name'],
                'courses'          => [],
                'total_ects'       => 0,
                'weighted_sum'     => 0,
                'moyenne'          => 0,
                'validated'        => false,
                'has_eliminatoire' => false,
            ];

            // Cours de cette UE pour cette classe et cette période
            $stmt = $this->db->prepare(
                'SELECT id, name, coefficient
                 FROM courses
                 WHERE teaching_unit_id = ?
                   AND JSON_CONTAINS(class_id, JSON_QUOTE(?))
                   AND semester = ?
                 ORDER BY display_order'
            );
            $stmt->execute([$unitId, $classId, $periodId]);
            $courses = $stmt->fetchAll();

            foreach ($courses as $course) {
                $courseId = $course['id'];
                $credits  = (float) $course['coefficient'];

                // Devoirs (moyenne si plusieurs notes)
                $stmt = $this->db->prepare(
                    'SELECT g.grade FROM grades g
                     WHERE g.student_id = ? AND g.course_id = ? AND g.evaluation_period_id = ?
                       AND g.evaluation_type_id = (
                           SELECT id FROM evaluation_types WHERE LOWER(name) = ? LIMIT 1
                       )'
                );
                $stmt->execute([$studentId, $courseId, $periodId, 'devoir']);
                $devoirRows  = $stmt->fetchAll();
                $devoirGrade = count($devoirRows) > 0
                    ? array_sum(array_column($devoirRows, 'grade')) / count($devoirRows)
                    : 0;

                // Examen (une seule note)
                $stmt = $this->db->prepare(
                    'SELECT g.grade FROM grades g
                     WHERE g.student_id = ? AND g.course_id = ? AND g.evaluation_period_id = ?
                       AND g.evaluation_type_id = (
                           SELECT id FROM evaluation_types WHERE LOWER(name) = ? LIMIT 1
                       )
                     LIMIT 1'
                );
                $stmt->execute([$studentId, $courseId, $periodId, 'examen']);
                $examRow   = $stmt->fetch();
                $examGrade = $examRow ? (float) $examRow['grade'] : 0;

                $courseAverage = ($devoirGrade * $devoirCoeff) + ($examGrade * $examCoeff);

                // Rattrapage : remplace uniquement si strictement supérieur
                $stmt = $this->db->prepare(
                    'SELECT grade FROM rattrapages
                     WHERE student_id = ? AND course_id = ? AND evaluation_period_id = ? AND status = ?
                     LIMIT 1'
                );
                $stmt->execute([$studentId, $courseId, $periodId, 'graded']);
                $ratt = $stmt->fetch();
                if ($ratt && (float) $ratt['grade'] > $courseAverage) {
                    $courseAverage = (float) $ratt['grade'];
                }

                $isEliminatoire = $courseAverage < 8;
                if ($isEliminatoire) {
                    $unitData['has_eliminatoire'] = true;
                }

                $unitData['courses'][] = [
                    'name'         => $this->removeGroupSuffix($course['name']),
                    'credits'      => $credits,
                    'devoir'       => round($devoirGrade, 2),
                    'examen'       => round($examGrade, 2),
                    'moyenne'      => round($courseAverage, 2),
                    'validated'    => $courseAverage >= 10,
                    'eliminatoire' => $isEliminatoire,
                ];

                $unitData['total_ects']   += $credits;
                $unitData['weighted_sum'] += $courseAverage * $credits;
            }

            // Validation UE : moyenne >= 10 ET aucune note éliminatoire (< 8)
            if ($unitData['total_ects'] > 0) {
                $unitData['moyenne']   = round($unitData['weighted_sum'] / $unitData['total_ects'], 2);
                $unitData['validated'] = $unitData['moyenne'] >= 10 && !$unitData['has_eliminatoire'];
                $totalWeightedSum     += $unitData['weighted_sum'];
            }

            $totalCreditsRequired += $unitData['total_ects'];
            if ($unitData['validated']) {
                $totalCreditsValidated += $unitData['total_ects'];
            }

            unset($unitData['weighted_sum']);
            $allGrades[] = $unitData;
        }

        $generalAverage = $totalCreditsRequired > 0
            ? round($totalWeightedSum / $totalCreditsRequired, 2)
            : 0;

        $semestreValide = ($totalCreditsValidated === $totalCreditsRequired) && $totalCreditsRequired > 0;

        $mention = match (true) {
            $generalAverage >= 16 => 'Très Bien',
            $generalAverage >= 14 => 'Bien',
            $generalAverage >= 12 => 'Assez Bien',
            $generalAverage >= 10 => 'Passable',
            default               => 'Insuffisant',
        };

        Response::success([
            'periods'              => $periods,
            'teaching_units'       => $allGrades,
            'general_average'      => $generalAverage,
            'mention'              => $mention,
            'semestre_valide'      => $semestreValide,
            'total_ects_required'  => $totalCreditsRequired,
            'total_ects_validated' => $totalCreditsValidated,
        ]);
    }

    // ─── GET /student/schedule?week_offset=0 ──────────────────────────────
    public function schedule(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);

        $studentId  = $currentUser->user_id;
        $weekOffset = isset($_GET['week_offset']) ? (int) $_GET['week_offset'] : 0;

        // Classe de l'étudiant
        $stmt = $this->db->prepare('SELECT class_id FROM users WHERE id = ?');
        $stmt->execute([$studentId]);
        $row     = $stmt->fetch();
        $classId = $row['class_id'] ?? null;

        if (!$classId) {
            Response::error('Aucune classe assignée à ce compte.', 404);
        }

        // Calculer les 7 dates de la semaine cible (Lundi = index 0 … Dimanche = index 6)
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

        // Récupérer l'EDT récurrent filtré par plage de validité
        $stmt = $this->db->prepare(
            'SELECT s.id,
                    c.name  AS course_name,
                    u.name  AS teacher_name,
                    r.name  AS classroom_name,
                    w.id    AS weekday_id,
                    w.name  AS weekday_name,
                    ts.id   AS timeslot_id,
                    TIME_FORMAT(ts.start_time, "%H:%i") AS start_time,
                    TIME_FORMAT(ts.end_time,   "%H:%i") AS end_time
             FROM schedule s
             JOIN courses    c  ON c.id  = s.course_id
             JOIN users      u  ON u.id  = s.teacher_id
             JOIN classrooms r  ON r.id  = s.classroom_id
             JOIN weekdays   w  ON w.id  = s.weekday_id
             JOIN time_slots ts ON ts.id = s.time_slot_id
             WHERE s.class_id = ?
               AND (s.start_date IS NULL OR s.start_date <= ?)
               AND (s.end_date   IS NULL OR s.end_date   >= ?)
             ORDER BY w.id, ts.start_time'
        );
        // La semaine s'étend de weekDates[0] (Lundi) à weekDates[6] (Dimanche)
        $stmt->execute([$classId, $weekDates[6], $weekDates[0]]);
        $rows = $stmt->fetchAll();

        // Grouper par weekday_id (1=Lundi … 7=Dimanche correspond à index weekday_id-1)
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
                'teacher_name'   => $slot['teacher_name'],
                'classroom_name' => $slot['classroom_name'],
                'start_time'     => $slot['start_time'],
                'end_time'       => $slot['end_time'],
            ];
        }
        ksort($byDay);

        Response::success([
            'week_offset'  => $weekOffset,
            'week_start'   => $weekDates[0],
            'week_end'     => $weekDates[4], // Vendredi
            'current_date' => date('Y-m-d'),
            'days'         => array_values($byDay),
        ]);
    }

    // ─── GET /student/announcements ───────────────────────────────────────
    public function announcements(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);

        $studentId = $currentUser->user_id;

        // Classe de l'étudiant
        $stmt = $this->db->prepare('SELECT class_id FROM users WHERE id = ?');
        $stmt->execute([$studentId]);
        $row     = $stmt->fetch();
        $classId = $row['class_id'] ?? null;

        // Annonces globales
        $stmt = $this->db->prepare(
            'SELECT id, content, created_at
             FROM announcements
             WHERE announcement_type = ?
             ORDER BY created_at DESC
             LIMIT 20'
        );
        $stmt->execute(['global']);
        $announcements = $stmt->fetchAll();

        // Annonces de la classe
        if ($classId) {
            $stmt = $this->db->prepare(
                'SELECT id, content, created_at
                 FROM announcements
                 WHERE announcement_type = ? AND class_id = ?
                 ORDER BY created_at DESC
                 LIMIT 20'
            );
            $stmt->execute(['class', $classId]);
            $classAnnouncements = $stmt->fetchAll();
            $announcements = array_merge($announcements, $classAnnouncements);
        }

        // Cours du jour (depuis l'EDT récurrent)
        $todayDow  = (int) date('N'); // 1=Lundi … 7=Dimanche (ISO-8601)
        $todayDate = date('Y-m-d');
        $todayCourses = [];

        if ($classId) {
            $stmt = $this->db->prepare(
                'SELECT c.name       AS course_name,
                        c.image_path AS course_image,
                        u.name       AS teacher_name,
                        r.name       AS classroom_name,
                        TIME_FORMAT(ts.start_time, "%H:%i") AS start_time,
                        TIME_FORMAT(ts.end_time,   "%H:%i") AS end_time
                 FROM schedule s
                 JOIN courses    c  ON c.id  = s.course_id
                 JOIN users      u  ON u.id  = s.teacher_id
                 JOIN classrooms r  ON r.id  = s.classroom_id
                 JOIN time_slots ts ON ts.id = s.time_slot_id
                 WHERE s.class_id   = ?
                   AND s.weekday_id = ?
                   AND (s.start_date IS NULL OR s.start_date <= ?)
                   AND (s.end_date   IS NULL OR s.end_date   >= ?)
                 ORDER BY ts.start_time'
            );
            $stmt->execute([$classId, $todayDow, $todayDate, $todayDate]);
            $rawCourses = $stmt->fetchAll();
            foreach ($rawCourses as &$tc) {
                $tc['course_image_url'] = $this->uploadUrl($tc['course_image']);
                unset($tc['course_image']);
            }
            $todayCourses = $rawCourses;
        }

        Response::success([
            'announcements' => $announcements,
            'today_courses' => $todayCourses,
        ]);
    }

    // ─── GET /student/discussions[?course_id=X] ───────────────────────────
    public function discussions(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);

        $studentId = $currentUser->user_id;
        $courseId  = isset($_GET['course_id']) ? (int) $_GET['course_id'] : null;

        // Classe de l'étudiant (nécessaire dans tous les cas)
        $stmt = $this->db->prepare('SELECT class_id FROM users WHERE id = ?');
        $stmt->execute([$studentId]);
        $row     = $stmt->fetch();
        $classId = $row['class_id'] ?? null;

        if (!$courseId) {
            // Liste des cours distincts de la classe (depuis l'EDT)
            if (!$classId) {
                Response::success(['courses' => []], 'Aucune classe assignée.');
                return;
            }

            $stmt = $this->db->prepare(
                'SELECT DISTINCT c.id, c.name, c.semester, c.image_path
                 FROM schedule s
                 JOIN courses c ON c.id = s.course_id
                 WHERE s.class_id = ?
                 ORDER BY c.name'
            );
            $stmt->execute([$classId]);
            $courses = $stmt->fetchAll();

            Response::success(['courses' => array_map(fn($c) => [
                'id'               => (int) $c['id'],
                'name'             => $c['name'],
                'semester'         => (int) ($c['semester'] ?? 0),
                'course_image_url' => $this->uploadUrl($c['image_path']),
            ], $courses)]);
            return;
        }

        // Vérifier que l'étudiant a accès à ce cours
        if ($classId) {
            $check = $this->db->prepare(
                'SELECT 1 FROM schedule WHERE course_id = ? AND class_id = ? LIMIT 1'
            );
            $check->execute([$courseId, $classId]);
            if (!$check->fetch()) {
                Response::error('Accès non autorisé à ce cours.', 403);
            }
        }

        // Messages du cours filtrés par année académique courante
        $stmt = $this->db->prepare(
            'SELECT d.id, d.sender_id, d.message, d.created_at,
                    u.name   AS sender_name,
                    u.avatar AS sender_avatar,
                    u.role   AS sender_role
             FROM discussions d
             JOIN users u ON u.id = d.sender_id
             WHERE d.course_id = ?
               AND d.academic_year = ?
             ORDER BY d.created_at ASC'
        );
        $stmt->execute([$courseId, ANNEE_ACADEMIQUE_COURANTE]);
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

        $messages = array_map(fn($r) => [
            'id'            => (int) $r['id'],
            'sender_id'     => $r['sender_id'],
            'sender_name'   => $r['sender_name'],
            'sender_role'   => $r['sender_role'],
            'sender_avatar' => $this->avatarUrl($r['sender_avatar']),
            'message'       => $r['message'],
            'created_at'    => $r['created_at'],
            'documents'     => $docsByMsg[(int) $r['id']] ?? [],
        ], $rows);

        Response::success(['messages' => $messages]);
    }

    // ─── POST /student/discussions ────────────────────────────────────────
    public function sendMessage(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);

        $studentId = $currentUser->user_id;
        $body      = Response::getJsonBody();
        Response::requireFields($body, ['course_id', 'message']);

        $courseId = (int) $body['course_id'];
        $message  = trim($body['message']);

        if (empty($message)) {
            Response::error('Le message ne peut pas être vide.', 422);
        }
        if (strlen($message) > 1000) {
            Response::error('Le message est trop long (max 1000 caractères).', 422);
        }

        // Vérifier l'accès au cours
        $stmt = $this->db->prepare('SELECT class_id FROM users WHERE id = ?');
        $stmt->execute([$studentId]);
        $row     = $stmt->fetch();
        $classId = $row['class_id'] ?? null;

        if ($classId) {
            $check = $this->db->prepare(
                'SELECT 1 FROM schedule WHERE course_id = ? AND class_id = ? LIMIT 1'
            );
            $check->execute([$courseId, $classId]);
            if (!$check->fetch()) {
                Response::error('Accès non autorisé à ce cours.', 403);
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO discussions (course_id, sender_id, message, academic_year, semester, created_at)
             VALUES (?, ?, ?, ?, 1, NOW())'
        );
        $stmt->execute([$courseId, $studentId, $message, ANNEE_ACADEMIQUE_COURANTE]);

        Response::success(['id' => (int) $this->db->lastInsertId()], 'Message envoyé avec succès.');
    }

    // ─── Helpers privés ───────────────────────────────────────────────────

    private function getPeriods(): array {
        $stmt = $this->db->prepare(
            'SELECT id, name FROM evaluation_periods ORDER BY id DESC'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function removeGroupSuffix(string $name): string {
        return trim(preg_replace('/\s*\(groupe\s*\d+\)\s*/i', '', $name));
    }

    private function avatarUrl(?string $filename): ?string {
        if (empty($filename)) return null;
        return 'https://esiitech.uvcoding.com/uploads/avatars/' . $filename;
    }

    private function uploadUrl(?string $rawPath): ?string {
        if (empty($rawPath)) return null;
        $base = basename($rawPath);
        return 'https://esiitech.uvcoding.com/uploads/' . rawurlencode($base);
    }

    /**
     * Récupère des colonnes optionnelles qui peuvent ne pas exister selon
     * l'état des migrations. Retourne un tableau vide si la colonne est absente.
     */
    private function fetchOptionalColumns(string $userId, array $columns): array {
        try {
            $cols = implode(', ', $columns);
            $stmt = $this->db->prepare("SELECT $cols FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch() ?: [];
        } catch (\PDOException) {
            return [];
        }
    }

    // ─── GET /student/payments ────────────────────────────────────────────────
    public function payments(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);
        $studentId = $currentUser->user_id;

        $stmt = $this->db->prepare(
            'SELECT u.id, u.name, u.class_id, c.name AS class_name
             FROM users u
             LEFT JOIN classes c ON c.id = u.class_id
             WHERE u.id = ?'
        );
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) Response::error('Étudiant introuvable.', 404);

        $classId = $student['class_id'];

        // Frais de scolarité de la classe (année courante)
        $stmt = $this->db->prepare(
            "SELECT * FROM tuition_fees WHERE class_id = ? AND academic_year = '" . ANNEE_ACADEMIQUE_COURANTE . "'"
        );
        $stmt->execute([$classId]);
        $tf = $stmt->fetch() ?: null;

        // Paiements validés
        $stmt = $this->db->prepare(
            "SELECT id, amount_paid, payment_date, payment_method, payment_type,
                    reference_number, installment_number
             FROM student_payments
             WHERE student_id = ? AND status = 'validated'
             ORDER BY payment_date DESC"
        );
        $stmt->execute([$studentId]);
        $payments = $stmt->fetchAll();

        $totalPaid      = array_sum(array_column($payments, 'amount_paid'));
        $totalAmount    = $tf ? (float) $tf['total_amount'] : 0;
        $remaining      = $totalAmount - $totalPaid;
        $percentage     = $totalAmount > 0 ? round(($totalPaid / $totalAmount) * 100, 2) : 0;

        $status = 'unpaid';
        if ($remaining <= 0) {
            $status = 'paid';
        } elseif ($tf && ($tf['due_date'] ?? null) < date('Y-m-d') && $remaining > 0) {
            $status = 'overdue';
        } elseif ($totalPaid > 0) {
            $status = 'partial';
        }

        // Échéances avec statut calculé
        $stmt = $this->db->prepare(
            "SELECT id, installment_number, due_date, amount_due, amount_paid, notes,
                    DATEDIFF(due_date, CURDATE()) AS days_until_due,
                    CASE
                        WHEN amount_paid >= amount_due THEN 'paid'
                        WHEN due_date < CURDATE() AND amount_paid < amount_due THEN 'overdue'
                        WHEN amount_paid > 0 THEN 'partial'
                        ELSE 'pending'
                    END AS computed_status
             FROM payment_deadlines
             WHERE student_id = ?
             ORDER BY installment_number"
        );
        $stmt->execute([$studentId]);
        $deadlines = $stmt->fetchAll();

        $overdueCount = count(array_filter($deadlines, fn($d) => $d['computed_status'] === 'overdue'));

        // Messages au service financier
        $stmt = $this->db->prepare(
            'SELECT fm.id, fm.subject, fm.message, fm.priority, fm.status,
                    fm.response, fm.response_date, fm.created_at,
                    r.name AS responded_by_name
             FROM finance_messages fm
             LEFT JOIN users r ON fm.responded_by = r.id
             WHERE fm.student_id = ?
             ORDER BY fm.created_at DESC'
        );
        $stmt->execute([$studentId]);
        $messages = $stmt->fetchAll();

        Response::success([
            'student' => [
                'id'         => $student['id'],
                'name'       => $student['name'],
                'class_name' => $student['class_name'] ?? 'Non assignée',
            ],
            'tuition_fees' => $tf ? [
                'total_amount'     => (float) $tf['total_amount'],
                'registration_fee' => (float) ($tf['registration_fee'] ?? 0),
                'tuition_fee'      => (float) ($tf['tuition_fee']      ?? 0),
                'insurance_fee'    => (float) ($tf['insurance_fee']    ?? 0),
                'library_fee'      => (float) ($tf['library_fee']      ?? 0),
                'practical_fee'    => (float) ($tf['practical_fee']    ?? 0),
                'other_fees'       => (float) ($tf['other_fees']       ?? 0),
                'academic_year'    => $tf['academic_year'],
                'due_date'         => $tf['due_date'] ?? null,
            ] : null,
            'summary' => [
                'total_amount'            => $totalAmount,
                'total_paid'              => $totalPaid,
                'remaining_balance'       => $remaining,
                'payment_percentage'      => $percentage,
                'status'                  => $status,
                'overdue_deadlines_count' => (int) $overdueCount,
            ],
            'payments' => array_map(fn($p) => [
                'id'                 => (int) $p['id'],
                'amount_paid'        => (float) $p['amount_paid'],
                'payment_date'       => $p['payment_date'],
                'payment_method'     => $p['payment_method'],
                'payment_type'       => $p['payment_type'],
                'reference_number'   => $p['reference_number'],
                'installment_number' => $p['installment_number'] !== null ? (int) $p['installment_number'] : null,
            ], $payments),
            'deadlines' => array_map(fn($d) => [
                'id'                 => (int) $d['id'],
                'installment_number' => (int) $d['installment_number'],
                'due_date'           => $d['due_date'],
                'amount_due'         => (float) $d['amount_due'],
                'amount_paid'        => (float) $d['amount_paid'],
                'days_until_due'     => (int) $d['days_until_due'],
                'computed_status'    => $d['computed_status'],
                'notes'              => $d['notes'],
            ], $deadlines),
            'messages' => array_map(fn($m) => [
                'id'                => (int) $m['id'],
                'subject'           => $m['subject'],
                'message'           => $m['message'],
                'priority'          => $m['priority'],
                'status'            => $m['status'],
                'response'          => $m['response'],
                'response_date'     => $m['response_date'],
                'responded_by_name' => $m['responded_by_name'],
                'created_at'        => $m['created_at'],
            ], $messages),
        ]);
    }

    // ─── POST /student/payments/message ──────────────────────────────────────
    public function sendFinanceMessage(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);
        $studentId = $currentUser->user_id;

        $body = Response::getJsonBody();
        Response::requireFields($body, ['subject', 'message']);

        $subject  = trim($body['subject']);
        $message  = trim($body['message']);
        $priority = in_array($body['priority'] ?? '', ['normal', 'high', 'urgent'])
            ? $body['priority'] : 'normal';

        if (empty($subject)) Response::error('Le sujet ne peut pas être vide.', 422);
        if (empty($message)) Response::error('Le message ne peut pas être vide.', 422);

        $stmt = $this->db->prepare(
            "INSERT INTO finance_messages (student_id, subject, message, priority, status)
             VALUES (?, ?, ?, ?, 'new')"
        );
        $stmt->execute([$studentId, $subject, $message, $priority]);
        $messageId = (int) $this->db->lastInsertId();

        $hist = $this->db->prepare(
            "INSERT INTO finance_message_history (message_id, user_id, user_type, message)
             VALUES (?, ?, 'student', ?)"
        );
        $hist->execute([$messageId, $studentId, $message]);

        Response::success(['id' => $messageId], 'Message envoyé au service financier.', 201);
    }

    // ─── GET /student/rattrapage?period_id= ──────────────────────────────────
    public function rattrapage(?object $currentUser): void {
        Auth::requireRole($currentUser, ['student', 'admin']);
        $studentId = $currentUser->user_id;
        $periodId  = isset($_GET['period_id']) ? (int) $_GET['period_id'] : 0;

        // Périodes disponibles
        $stmt = $this->db->prepare(
            'SELECT DISTINCT ep.id, ep.name
             FROM rattrapages r
             JOIN evaluation_periods ep ON r.evaluation_period_id = ep.id
             WHERE r.student_id = ?
             ORDER BY ep.name DESC'
        );
        $stmt->execute([$studentId]);
        $periods = $stmt->fetchAll();

        // Liste des rattrapages (filtrée ou complète)
        $params = [$studentId];
        $where  = 'WHERE r.student_id = ?';
        if ($periodId) {
            $where   .= ' AND r.evaluation_period_id = ?';
            $params[] = $periodId;
        }

        $stmt = $this->db->prepare(
            "SELECT r.id, r.status, r.grade, r.original_average, r.eligibility_reason,
                    r.graded_at, r.comment,
                    co.name AS course_name,
                    ep.name AS period_name,
                    tu.name AS ue_name,
                    tu.code AS ue_code,
                    gb.name AS graded_by_name
             FROM rattrapages r
             JOIN courses co            ON r.course_id             = co.id
             JOIN evaluation_periods ep ON r.evaluation_period_id  = ep.id
             LEFT JOIN teaching_units tu ON co.teaching_unit_id    = tu.id
             LEFT JOIN users gb         ON r.graded_by             = gb.id
             $where
             ORDER BY ep.name DESC, tu.code, co.name"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = count($rows);
        $pending = $graded = $passed = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'graded') {
                $graded++;
                if ((float) $r['grade'] >= 10) $passed++;
            } else {
                $pending++;
            }
        }

        // Regroupement par période → UE
        $byPeriod = [];
        foreach ($rows as $r) {
            $pname  = $r['period_name'];
            $ueKey  = $r['ue_code'] ?: '__none__';
            if (!isset($byPeriod[$pname][$ueKey])) {
                $byPeriod[$pname][$ueKey] = [
                    'ue_code'     => $r['ue_code'],
                    'ue_name'     => $r['ue_name'],
                    'rattrapages' => [],
                ];
            }
            $byPeriod[$pname][$ueKey]['rattrapages'][] = [
                'id'                 => (int) $r['id'],
                'course_name'        => $r['course_name'],
                'status'             => $r['status'],
                'grade'              => $r['grade'] !== null ? (float) $r['grade'] : null,
                'original_average'   => $r['original_average'] !== null ? (float) $r['original_average'] : null,
                'eligibility_reason' => $r['eligibility_reason'],
                'graded_at'          => $r['graded_at'],
                'graded_by_name'     => $r['graded_by_name'],
                'comment'            => $r['comment'],
            ];
        }

        $result = [];
        foreach ($byPeriod as $pname => $ueGroups) {
            $result[] = ['period_name' => $pname, 'ue_groups' => array_values($ueGroups)];
        }

        Response::success([
            'periods'   => $periods,
            'summary'   => ['total' => $total, 'pending' => $pending, 'graded' => $graded, 'passed' => $passed],
            'by_period' => $result,
        ]);
    }
}
