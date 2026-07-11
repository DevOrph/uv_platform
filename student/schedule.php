<?php
require_once '../includes/db_connect.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.html");
    exit();
}
// Récupérer l'ID de la classe de l'étudiant
$student_id = $_SESSION['user_id'];
$query_class = "SELECT class_id FROM users WHERE id = ?";
$stmt_class = $conn->prepare($query_class);
$stmt_class->bind_param("s", $student_id);
$stmt_class->execute();
$result_class = $stmt_class->get_result();
$class_data = $result_class->fetch_assoc();
$class_id = $class_data ? $class_data['class_id'] : null;

// Si l'étudiant n'a pas de classe assignée
if (!$class_id) {
    $error_message = "Aucune classe n'est assignée à votre compte. Veuillez contacter l'administration.";
} else {
    // Récupérer le nom de la classe
    $query_class_name = "SELECT name FROM classes WHERE id = ?";
    $stmt_class_name = $conn->prepare($query_class_name);
    $stmt_class_name->bind_param("i", $class_id);
    $stmt_class_name->execute();
    $result_class_name = $stmt_class_name->get_result();
    $class_name = $result_class_name->fetch_assoc()['name'];

    // Récupérer les jours de la semaine
    $query_weekdays = "SELECT id, name FROM weekdays ORDER BY id";
    $weekdays_result = $conn->query($query_weekdays);
    $weekdays = [];
    while ($weekday = $weekdays_result->fetch_assoc()) {
        $weekdays[] = $weekday;
    }

    // Récupérer les créneaux horaires
    $query_timeslots = "SELECT id, TIME_FORMAT(start_time, '%H:%i') as start_time, TIME_FORMAT(end_time, '%H:%i') as end_time, name 
                        FROM time_slots 
                        ORDER BY start_time";
    $timeslots_result = $conn->query($query_timeslots);
    $timeslots = [];
    while ($timeslot = $timeslots_result->fetch_assoc()) {
        $timeslots[] = $timeslot;
    }

    // Récupérer l'emploi du temps de la classe
    $current_date = date('Y-m-d');
    $query_schedule = "SELECT s.id, c.name as course_name, u.name as teacher_name, r.name as classroom_name,
                       w.id as weekday_id, w.name as weekday_name, 
                       ts.id as timeslot_id, TIME_FORMAT(ts.start_time, '%H:%i') as start_time, TIME_FORMAT(ts.end_time, '%H:%i') as end_time
                      FROM schedule s
                      JOIN courses c ON s.course_id = c.id
                      JOIN users u ON s.teacher_id = u.id
                      JOIN classrooms r ON s.classroom_id = r.id
                      JOIN weekdays w ON s.weekday_id = w.id
                      JOIN time_slots ts ON s.time_slot_id = ts.id
                      WHERE s.class_id = ? 
                      AND (s.start_date IS NULL OR s.start_date <= ?)
                      AND (s.end_date IS NULL OR s.end_date >= ?)
                      ORDER BY w.id, ts.start_time";
    
    $stmt_schedule = $conn->prepare($query_schedule);
    $stmt_schedule->bind_param("iss", $class_id, $current_date, $current_date);
    $stmt_schedule->execute();
    $schedule_result = $stmt_schedule->get_result();
    
    $schedule = [];
    while ($row = $schedule_result->fetch_assoc()) {
        $schedule[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon emploi du temps - Université Virtuelle</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --error-color: #dc3545;
            --success-color: #28a745;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Google Sans', Arial, sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            flex: 1;
        }

        h1, h2 {
            color: var(--accent-color);
            margin-bottom: 20px;
        }

        .schedule-container {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 20px;
            border: 1px solid var(--border-color);
            margin-top: 20px;
        }

        .error {
            background-color: var(--error-color);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .class-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .class-info h2 {
            margin: 0;
        }

        .schedule-options {
            display: flex;
            gap: 15px;
        }

        .schedule-options button {
            background-color: var(--accent-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .schedule-options button:hover {
            background-color: #0288d1;
        }

        /* Tableau d'emploi du temps style moderne */
        .timetable {
            width: 100%;
            border-collapse: separate;
            border-spacing: 2px;
            margin-top: 20px;
        }

        .timetable th {
            background-color: var(--secondary-bg);
            color: var(--text-light);
            padding: 12px;
            text-align: center;
            font-weight: 600;
            border-radius: 5px;
        }

        .timetable td {
            padding: 0;
            height: 100px;
            vertical-align: top;
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 5px;
        }

        .timetable td.time-column {
            width: 100px;
            padding: 10px;
            text-align: center;
            background-color: var(--secondary-bg);
            font-weight: 600;
            vertical-align: middle;
        }

        .course-block {
            height: 100%;
            padding: 10px;
            border-radius: 5px;
            background-color: rgba(3, 155, 229, 0.2);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }

        .course-block:hover {
            background-color: rgba(3, 155, 229, 0.3);
            transform: scale(1.02);
        }

        .course-block .course-name {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 5px;
        }

        .course-block .course-details {
            font-size: 12px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .course-block .course-details span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .empty-slot {
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            color: rgba(255, 255, 255, 0.3);
            font-style: italic;
        }

        /* Vue hebdomadaire/journalière */
        .view-toggle {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }

        .view-toggle button {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .view-toggle button.active {
            background-color: var(--accent-color);
        }

        .view-toggle button:hover:not(.active) {
            background-color: rgba(255, 255, 255, 0.2);
        }

        /* Réglages pour les écrans mobiles */
        @media (max-width: 768px) {
            .timetable {
                display: block;
                overflow-x: auto;
            }

            .class-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .schedule-options {
                flex-wrap: wrap;
            }
        }

        /* Animation pour les blocs de cours */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .course-block {
            animation: fadeIn 0.5s ease forwards;
        }

        /* Légende */
        .schedule-legend {
            margin-top: 20px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 5px;
        }

        .legend-color.course {
            background-color: rgba(3, 155, 229, 0.2);
        }
    </style>
</head>
<body>
<?php include '../includes/header_discussion_student.php'; ?>

    <div class="container">
        <h1><i class="fas fa-calendar-alt"></i> Mon emploi du temps</h1>

        <?php if (isset($error_message)): ?>
            <div class="error">
                <?php echo $error_message; ?>
            </div>
        <?php else: ?>
            <div class="schedule-container">
                <div class="class-info">
                    <h2>Classe: <?php echo $class_name; ?></h2>
                    <div class="schedule-options">
                        <button id="btn-today" title="Afficher aujourd'hui">
                            <i class="fas fa-calendar-day"></i> Aujourd'hui
                        </button>
                        <button id="btn-print" title="Imprimer l'emploi du temps">
                            <i class="fas fa-print"></i> Imprimer
                        </button>
                    </div>
                </div>

                <div class="view-toggle">
                    <button class="active" data-view="week">Vue hebdomadaire</button>
                    <button data-view="day">Vue journalière</button>
                </div>

                <div id="week-view">
                    <table class="timetable">
                        <thead>
                            <tr>
                                <th>Horaire</th>
                                <?php foreach ($weekdays as $weekday): ?>
                                    <th><?php echo $weekday['name']; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($timeslots as $timeslot): ?>
                                <tr>
                                    <td class="time-column">
                                        <?php echo $timeslot['start_time']; ?><br>-<br><?php echo $timeslot['end_time']; ?>
                                    </td>
                                    
                                    <?php foreach ($weekdays as $weekday): ?>
                                        <td>
                                            <?php
                                            $has_course = false;
                                            foreach ($schedule as $course) {
                                                if ($course['weekday_id'] == $weekday['id'] && $course['timeslot_id'] == $timeslot['id']) {
                                                    $has_course = true;
                                                    echo '<div class="course-block">';
                                                    echo '<div class="course-name">' . $course['course_name'] . '</div>';
                                                    echo '<div class="course-details">';
                                                    echo '<span><i class="fas fa-user"></i> ' . $course['teacher_name'] . '</span>';
                                                    echo '<span><i class="fas fa-map-marker-alt"></i> ' . $course['classroom_name'] . '</span>';
                                                    echo '<span><i class="fas fa-clock"></i> ' . $course['start_time'] . ' - ' . $course['end_time'] . '</span>';
                                                    echo '</div>';
                                                    echo '</div>';
                                                    break;
                                                }
                                            }
                                            
                                            if (!$has_course) {
                                                echo '<div class="empty-slot">Aucun cours</div>';
                                            }
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="day-view" style="display: none;">
                    <h3 id="day-title">Emploi du temps pour <span id="current-day">aujourd'hui</span></h3>
                    <div class="day-navigation">
                        <button id="prev-day"><i class="fas fa-chevron-left"></i></button>
                        <span id="selected-date"><?php echo date('d/m/Y'); ?></span>
                        <button id="next-day"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    
                    <table class="timetable day-timetable">
                        <thead>
                            <tr>
                                <th>Horaire</th>
                                <th>Cours</th>
                            </tr>
                        </thead>
                        <tbody id="day-schedule-body">
                            <!-- Contenu généré dynamiquement par JavaScript -->
                        </tbody>
                    </table>
                </div>

                <div class="schedule-legend">
                    <div class="legend-item">
                        <div class="legend-color course"></div>
                        <span>Cours programmé</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Données de l'emploi du temps
            const scheduleData = <?php echo json_encode($schedule); ?>;
            const weekdays = <?php echo json_encode($weekdays); ?>;
            const timeslots = <?php echo json_encode($timeslots); ?>;
            
            // Gestion des vues (hebdomadaire/journalière)
            const weekView = document.getElementById('week-view');
            const dayView = document.getElementById('day-view');
            const viewButtons = document.querySelectorAll('.view-toggle button');
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    viewButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    const view = this.getAttribute('data-view');
                    if (view === 'week') {
                        weekView.style.display = 'block';
                        dayView.style.display = 'none';
                    } else {
                        weekView.style.display = 'none';
                        dayView.style.display = 'block';
                        updateDayView();
                    }
                });
            });
            
            // Fonction pour obtenir le jour de la semaine (0-6, où 0 est Dimanche)
            function getDayOfWeek(dateStr) {
                const date = new Date(dateStr);
                return date.getDay();
            }
            
            // Gestion de la vue journalière
            let currentDate = new Date();
            const dayTitle = document.getElementById('current-day');
            const selectedDate = document.getElementById('selected-date');
            const prevDayBtn = document.getElementById('prev-day');
            const nextDayBtn = document.getElementById('next-day');
            const dayScheduleBody = document.getElementById('day-schedule-body');
            
            function formatDate(date) {
                const day = String(date.getDate()).padStart(2, '0');
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const year = date.getFullYear();
                return `${day}/${month}/${year}`;
            }
            
            function updateDayView() {
                // Mettre à jour l'affichage de la date
                selectedDate.textContent = formatDate(currentDate);
                
                // Déterminer le jour de la semaine (1-7, où 1 est Lundi dans notre base de données)
                let dayOfWeek = getDayOfWeek(currentDate);
                dayOfWeek = dayOfWeek === 0 ? 7 : dayOfWeek; // Convertir dimanche (0) en 7
                
                // Trouver le nom du jour
                const dayName = weekdays.find(day => day.id == dayOfWeek)?.name || 'Inconnu';
                dayTitle.textContent = `Emploi du temps pour ${dayName}`;
                
                // Vider le contenu actuel
                dayScheduleBody.innerHTML = '';
                
                // Filtrer les cours pour ce jour
                const daySchedule = scheduleData.filter(course => course.weekday_id == dayOfWeek);
                
                // Générer les lignes pour chaque créneau horaire
                timeslots.forEach(timeslot => {
                    const row = document.createElement('tr');
                    
                    const timeCell = document.createElement('td');
                    timeCell.className = 'time-column';
                    timeCell.innerHTML = `${timeslot.start_time}<br>-<br>${timeslot.end_time}`;
                    row.appendChild(timeCell);
                    
                    const courseCell = document.createElement('td');
                    
                    // Trouver si un cours est programmé dans ce créneau
                    const course = daySchedule.find(c => c.timeslot_id == timeslot.id);
                    
                    if (course) {
                        const courseBlock = document.createElement('div');
                        courseBlock.className = 'course-block';
                        
                        courseBlock.innerHTML = `
                            <div class="course-name">${course.course_name}</div>
                            <div class="course-details">
                                <span><i class="fas fa-user"></i> ${course.teacher_name}</span>
                                <span><i class="fas fa-map-marker-alt"></i> ${course.classroom_name}</span>
                                <span><i class="fas fa-clock"></i> ${course.start_time} - ${course.end_time}</span>
                            </div>
                        `;
                        
                        courseCell.appendChild(courseBlock);
                    } else {
                        const emptySlot = document.createElement('div');
                        emptySlot.className = 'empty-slot';
                        emptySlot.textContent = 'Aucun cours';
                        courseCell.appendChild(emptySlot);
                    }
                    
                    row.appendChild(courseCell);
                    dayScheduleBody.appendChild(row);
                });
            }
            
            // Navigation entre les jours
            prevDayBtn.addEventListener('click', function() {
                currentDate.setDate(currentDate.getDate() - 1);
                updateDayView();
            });
            
            nextDayBtn.addEventListener('click', function() {
                currentDate.setDate(currentDate.getDate() + 1);
                updateDayView();
            });
            
            // Bouton Aujourd'hui
            document.getElementById('btn-today').addEventListener('click', function() {
                currentDate = new Date();
                
                // Si en vue journalière, mettre à jour l'affichage
                if (dayView.style.display !== 'none') {
                    updateDayView();
                }
                
                // Faire défiler vers le jour actuel dans la vue hebdomadaire
                highlightToday();
            });
            
            function highlightToday() {
                // Mettre en évidence la colonne correspondant au jour actuel
                const today = new Date();
                let dayOfWeek = today.getDay();
                dayOfWeek = dayOfWeek === 0 ? 7 : dayOfWeek; // Convertir dimanche (0) en 7
                
                // Supprimer la mise en évidence précédente
                document.querySelectorAll('.timetable th.today-highlight').forEach(el => {
                    el.classList.remove('today-highlight');
                });
                
                // Ajouter la mise en évidence
                const headers = document.querySelectorAll('.timetable thead th');
                if (headers.length > dayOfWeek) {
                    headers[dayOfWeek].classList.add('today-highlight');
                }
            }
            
            // Impression de l'emploi du temps
            document.getElementById('btn-print').addEventListener('click', function() {
                window.print();
            });
            
            // Initialisation
            updateDayView();
            highlightToday();
            
            // Ajouter des styles pour l'impression
            const printStyles = document.createElement('style');
            printStyles.textContent = `
                @media print {
                    header, footer, .schedule-options, .view-toggle, .day-navigation {
                        display: none !important;
                    }
                    
                    body, .container, .schedule-container {
                        background-color: white !important;
                        color: black !important;
                    }
                    
                    .timetable th {
                        background-color: #f0f0f0 !important;
                        color: black !important;
                    }
                    
                    .timetable td.time-column {
                        background-color: #f0f0f0 !important;
                        color: black !important;
                    }
                    
                    .course-block {
                        background-color: #e1f5fe !important;
                        color: black !important;
                        border: 1px solid #b3e5fc !important;
                    }
                    
                    .empty-slot {
                        color: #999 !important;
                    }
                    
                    h1, h2, h3 {
                        color: #0288d1 !important;
                    }
                }
            `;
            document.head.appendChild(printStyles);
        });
    </script>
</body>
<style>
/* Forcer les styles d'animation pour les icônes flottantes */
.floating-icons {
    position: absolute !important;
    width: 100% !important;
    height: 100% !important;
    top: 0 !important;
    left: 0 !important;
    pointer-events: none !important;
    z-index: 10 !important; /* Augmenter le z-index */
}

.floating-icon {
    position: absolute !important;
    font-size: 20px !important;
    color: var(--accent-color) !important;
    opacity: 0 !important;
    animation: floatIcon 3s ease-in-out infinite !important;
}

@keyframes floatIcon {
    0% { transform: translateY(0); opacity: 0; }
    50% { transform: translateY(-20px); opacity: 0.6; }
    100% { transform: translateY(-40px); opacity: 0; }
}
</style>

<script>
// S'assurer que les animations sont initialisées
document.addEventListener('DOMContentLoaded', function() {
    const floatingIcons = document.querySelectorAll('.floating-icon');
    if (floatingIcons.length > 0) {
        floatingIcons.forEach(icon => {
            // Réinitialiser l'animation
            icon.style.animation = 'none';
            setTimeout(() => {
                icon.style.animation = 'floatIcon 3s ease-in-out infinite';
            }, 10);
        });
    }
});
</script>
</html>