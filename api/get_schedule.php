<?php
session_start();
require_once '../includes/db_connect.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Utilisateur non connecté'
    ]);
    exit();
}

// Charger les variables d'environnement
require_once '../load_env.php';
loadEnv();


// Création de la connexion
$conn = new mysqli($host, $username, $password, $dbname);

// Vérifier la connexion
if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Erreur de connexion à la base de données'
    ]);
    exit();
}

// Paramètres de filtrage
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;
$teacher_id = isset($_GET['teacher_id']) ? $_GET['teacher_id'] : null;
$classroom_id = isset($_GET['classroom_id']) ? intval($_GET['classroom_id']) : null;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$weekday_id = isset($_GET['weekday_id']) ? intval($_GET['weekday_id']) : null;

// Construction de la requête en fonction du rôle
$params = [];
$types = "";
$filter_conditions = [];

// Si c'est un étudiant, filtrer par sa classe
if ($role === 'student') {
    // Récupérer la classe de l'étudiant si non fournie
    if (!$class_id) {
        $stmt = $conn->prepare("SELECT class_id FROM users WHERE id = ?");
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $class_id = $result->fetch_assoc()['class_id'];
        }
        $stmt->close();
    }
    
    if ($class_id) {
        $filter_conditions[] = "s.class_id = ?";
        $params[] = $class_id;
        $types .= "i";
    }
}

// Si c'est un enseignant, filtrer par son ID
if ($role === 'teacher' && !$teacher_id) {
    $teacher_id = $user_id;
}

if ($teacher_id) {
    $filter_conditions[] = "s.teacher_id = ?";
    $params[] = $teacher_id;
    $types .= "s";
}

// Filtres supplémentaires
if ($classroom_id) {
    $filter_conditions[] = "s.classroom_id = ?";
    $params[] = $classroom_id;
    $types .= "i";
}

if ($weekday_id) {
    $filter_conditions[] = "s.weekday_id = ?";
    $params[] = $weekday_id;
    $types .= "i";
}

// Filtres de date
if ($start_date) {
    $filter_conditions[] = "(s.start_date IS NULL OR s.start_date <= ?)";
    $params[] = $start_date;
    $types .= "s";
}

if ($end_date) {
    $filter_conditions[] = "(s.end_date IS NULL OR s.end_date >= ?)";
    $params[] = $end_date;
    $types .= "s";
}

// Construire la clause WHERE
$where_clause = count($filter_conditions) > 0 ? "WHERE " . implode(" AND ", $filter_conditions) : "";

// Requête pour récupérer les données de l'emploi du temps
$query = "SELECT 
            s.id, 
            c.id as course_id, 
            c.name as course_name, 
            u.id as teacher_id, 
            u.name as teacher_name, 
            r.id as classroom_id, 
            r.name as classroom_name, 
            cl.id as class_id, 
            cl.name as class_name, 
            w.id as weekday_id, 
            w.name as weekday_name, 
            ts.id as timeslot_id, 
            TIME_FORMAT(ts.start_time, '%H:%i') as start_time, 
            TIME_FORMAT(ts.end_time, '%H:%i') as end_time, 
            ts.name as timeslot_name,
            s.start_date,
            s.end_date,
            s.is_recurring
          FROM schedule s
          JOIN courses c ON s.course_id = c.id
          JOIN users u ON s.teacher_id = u.id
          JOIN classrooms r ON s.classroom_id = r.id
          JOIN classes cl ON s.class_id = cl.id
          JOIN weekdays w ON s.weekday_id = w.id
          JOIN time_slots ts ON s.time_slot_id = ts.id
          $where_clause
          ORDER BY w.id, ts.start_time";

$stmt = $conn->prepare($query);

// Lier les paramètres si présents
if (!empty($params)) {
    $bind_params = array($types);
    foreach ($params as $key => $value) {
        $bind_params[] = &$params[$key];
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind_params);
}

$stmt->execute();
$result = $stmt->get_result();

$schedule_data = [];
while ($row = $result->fetch_assoc()) {
    // Pour des raisons de sécurité, on peut filtrer les données sensibles ici
    $schedule_data[] = $row;
}

// Récupérer les jours de la semaine et les créneaux horaires pour compléter la réponse
$weekdays_query = "SELECT id, name FROM weekdays ORDER BY id";
$weekdays_result = $conn->query($weekdays_query);
$weekdays = [];
while ($weekday = $weekdays_result->fetch_assoc()) {
    $weekdays[] = $weekday;
}

$timeslots_query = "SELECT id, TIME_FORMAT(start_time, '%H:%i') as start_time, TIME_FORMAT(end_time, '%H:%i') as end_time, name FROM time_slots ORDER BY start_time";
$timeslots_result = $conn->query($timeslots_query);
$timeslots = [];
while ($timeslot = $timeslots_result->fetch_assoc()) {
    $timeslots[] = $timeslot;
}

// Préparer la réponse JSON
$response = [
    'success' => true,
    'schedule' => $schedule_data,
    'weekdays' => $weekdays,
    'timeslots' => $timeslots,
    'filters' => [
        'class_id' => $class_id,
        'teacher_id' => $teacher_id,
        'classroom_id' => $classroom_id,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'weekday_id' => $weekday_id
    ]
];

// Fermer les ressources
$stmt->close();
$conn->close();

// Envoyer la réponse JSON
header('Content-Type: application/json');
echo json_encode($response);
?>