<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}

// Récupération des périodes
$periods = $conn->query("SELECT * FROM evaluation_periods ORDER BY start_date DESC");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Paramètres d'Évaluation</title>
    
<style>/* Section Périodes d'évaluation */
.section {
    margin: 20px auto;
    padding: 20px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

.section h2 {
    font-size: 1.8em;
    margin-bottom: 20px;
    color: var(--text-light);
    text-align: center;
    background: var(--secondary-bg);
    padding: 10px;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
}

.add-form {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    justify-content: space-between;
    margin-bottom: 20px;
}

.add-form input,
.add-form button {
    flex: 1;
    min-width: 200px;
    padding: 10px;
    border-radius: 5px;
    border: 1px solid var(--border-color);
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-light);
    font-size: 1em;
    transition: all 0.3s ease;
}

.add-form input::placeholder {
    color: rgba(255, 255, 255, 0.7);
}

.add-form input:focus,
.add-form button:hover {
    border-color: var(--accent-color);
    background: rgba(3, 155, 229, 0.2);
}

.add-form button {
    cursor: pointer;
    background: var(--accent-color);
    color: var(--text-light);
    font-weight: bold;
    text-transform: uppercase;
}

.add-form button:hover {
    background: var(--hover-color);
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

thead th {
    background: var(--secondary-bg);
    color: var(--text-light);
    text-align: left;
    padding: 12px;
    text-transform: uppercase;
}

tbody tr:nth-child(even) {
    background: rgba(255, 255, 255, 0.05);
}

tbody tr:hover {
    background: rgba(3, 155, 229, 0.2);
}

tbody td {
    padding: 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    color: var(--text-light);
    text-align: left;
}

.btn-edit,
.btn-delete {
    padding: 8px 15px;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9em;
    text-transform: uppercase;
    border: none;
}

.btn-edit {
    background: #4CAF50;
    color: var(--text-light);
}

.btn-edit:hover {
    background: #45a049;
}

.btn-delete {
    background: #e74c3c;
    color: var(--text-light);
}

.btn-delete:hover {
    background: #c0392b;
}
</style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="section">
        <h2>Périodes d'évaluation</h2>
        
        <form id="add-form" class="add-form">
            <input type="text" name="period_name" placeholder="Nom de la période" required>
            <input type="date" name="start_date" required>
            <input type="date" name="end_date" required>
            <input type="text" name="school_year" placeholder="2024-2025" required>
            <button type="submit">Ajouter</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Période</th>
                    <th>Début</th>
                    <th>Fin</th>
                    <th>Année scolaire</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="periods-list">
                <?php while ($period = $periods->fetch_assoc()): ?>
                    <tr id="row-<?php echo $period['id']; ?>">
                        <td><?php echo htmlspecialchars($period['name']); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($period['start_date'])); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($period['end_date'])); ?></td>
                        <td><?php echo htmlspecialchars($period['school_year']); ?></td>
                        <td>
                            <button onclick="editPeriod(<?php echo $period['id']; ?>)" class="btn-edit">Modifier</button>
                            <button onclick="deletePeriod(<?php echo $period['id']; ?>)" class="btn-delete">Supprimer</button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script>
    // Ajouter une période via AJAX
    document.getElementById("add-form").addEventListener("submit", function(e) {
        e.preventDefault();
        let formData = new FormData(this);

        fetch("add_period.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.text())
        .then(() => location.reload());
    });

    // Modifier une période
    function editPeriod(id) {
        fetch("get_period.php?id=" + id)
            .then(response => response.json())
            .then(data => {
                const name = prompt('Nom de la période:', data.name);
                const start_date = prompt('Date de début (YYYY-MM-DD):', data.start_date);
                const end_date = prompt('Date de fin (YYYY-MM-DD):', data.end_date);
                const school_year = prompt('Année scolaire:', data.school_year);

                if (name && start_date && end_date && school_year) {
                    fetch("update_period.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ id, name, start_date, end_date, school_year })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) location.reload();
                        else alert(data.message);
                    });
                }
            });
    }

    // Supprimer une période
    function deletePeriod(id) {
        if (confirm("Voulez-vous vraiment supprimer cette période ?")) {
            fetch("delete_period.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById("row-" + id).remove();
                } else {
                    alert(data.message);
                }
            });
        }
    }
    </script>

    <?php include '../includes/footer.php'; ?>
</body>
</html>



