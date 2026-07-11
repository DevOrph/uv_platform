<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statuts de Paiement - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --card-bg: rgba(255, 255, 255, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            color: var(--text-light);
            min-height: 100vh;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .page-header {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--accent-color);
            font-size: 24px;
        }

        .page-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .btn-primary { background: var(--accent-color); color: white; }
        .btn-success { background: var(--success-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }
        .btn-small { padding: 8px 12px; font-size: 12px; }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            border: 1px solid var(--danger-color);
            color: var(--danger-color);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--accent-color);
        }

        .stat-card.success::before { background: var(--success-color); }
        .stat-card.warning::before { background: var(--warning-color); }
        .stat-card.danger::before { background: var(--danger-color); }
        .stat-card.info::before { background: var(--info-color); }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .stat-title {
            font-size: 14px;
            color: #ccc;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 30px;
            overflow-x: auto;
        }

        .tab {
            padding: 15px 25px;
            cursor: pointer;
            color: #ccc;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .tab.active {
            color: var(--accent-color);
            border-bottom-color: var(--accent-color);
        }

        .tab:hover {
            color: var(--text-light);
            background: rgba(255, 255, 255, 0.05);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .filters {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }

        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group label {
            font-size: 12px;
            color: #ccc;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-control {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            font-size: 14px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 2px rgba(3, 155, 229, 0.2);
        }

        .table-container {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
            overflow-x: auto;
            margin-bottom: 30px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--accent-color);
            position: sticky;
            top: 0;
            cursor: pointer;
            user-select: none;
        }

        .table th:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .table th.sortable::after {
            content: ' ↕';
            opacity: 0.5;
        }

        .table th.sort-asc::after {
            content: ' ↑';
            opacity: 1;
        }

        .table th.sort-desc::after {
            content: ' ↓';
            opacity: 1;
        }

        .table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-paid { background: var(--success-color); color: white; }
        .status-partial { background: var(--warning-color); color: white; }
        .status-unpaid { background: var(--danger-color); color: white; }
        .status-overdue { background: #8e44ad; color: white; }

        .progress {
            width: 100px;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 5px;
        }

        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-success { background: var(--success-color); }
        .progress-warning { background: var(--warning-color); }
        .progress-danger { background: var(--danger-color); }

        .class-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .class-name {
            font-size: 18px;
            font-weight: bold;
            color: var(--accent-color);
        }

        .class-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .class-stat {
            text-align: center;
            padding: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
        }

        .class-stat-value {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .class-stat-label {
            font-size: 12px;
            color: #ccc;
            text-transform: uppercase;
        }

        .urgency-high {
            border-left: 4px solid var(--danger-color);
        }

        .urgency-medium {
            border-left: 4px solid var(--warning-color);
        }

        .urgency-low {
            border-left: 4px solid var(--success-color);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.8);
        }

        .modal-content {
            background: var(--secondary-bg);
            margin: 5% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            border: 1px solid var(--border-color);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            color: var(--accent-color);
            font-size: 20px;
            font-weight: bold;
        }

        .close {
            color: #ccc;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .close:hover {
            color: var(--text-light);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }

        @media (max-width: 768px) {
            .container { padding: 20px 15px; }
            .page-header { flex-direction: column; align-items: stretch; }
            .page-actions { justify-content: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filters-row { grid-template-columns: 1fr; }
            .class-stats { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Messages d'alerte -->
        <div id="alertContainer"></div>

        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-chart-pie"></i>
                Statuts de Paiement
            </h2>
            <div class="page-actions">
                <button class="btn btn-warning" onclick="openBulkReminderModal()">
                    <i class="fas fa-paper-plane"></i> Rappels en Masse
                </button>
                <button class="btn btn-success" onclick="exportStatus()">
                    <i class="fas fa-download"></i> Exporter
                </button>
                <a href="payment_management.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Retour
                </a>
            </div>
        </div>

        <!-- Statistiques globales -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-value">15</div>
                <div class="stat-title">Étudiants à jour</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-value">8</div>
                <div class="stat-title">Paiements partiels</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-value">5</div>
                <div class="stat-title">Non payés</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-value">3</div>
                <div class="stat-title">En retard</div>
            </div>
            <div class="stat-card info">
                <div class="stat-value">12,450,000</div>
                <div class="stat-title">FCFA collectés</div>
            </div>
            <div class="stat-card info">
                <div class="stat-value">78.5%</div>
                <div class="stat-title">Taux de recouvrement</div>
            </div>
        </div>

        <!-- Onglets -->
        <div class="tabs">
            <div class="tab active" onclick="switchTab('overview')">
                <i class="fas fa-users"></i> Vue d'ensemble
            </div>
            <div class="tab" onclick="switchTab('classes')">
                <i class="fas fa-school"></i> Par classe
            </div>
            <div class="tab" onclick="switchTab('overdue')">
                <i class="fas fa-exclamation-triangle"></i> Retards (3)
            </div>
        </div>

        <!-- Onglet Vue d'ensemble -->
        <div class="tab-content active" id="overview-content">
            <!-- Filtres -->
            <div class="filters">
                <form id="filterForm">
                    <div class="filters-row">
                        <div class="form-group">
                            <label>Classe</label>
                            <select class="form-control" name="class_filter">
                                <option value="">Toutes les classes</option>
                                <option value="1">2ème année Génie informatique</option>
                                <option value="2">2ème année Génie civil</option>
                                <option value="3">2ème année Génie Électromécanique</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Statut</label>
                            <select class="form-control" name="status_filter">
                                <option value="">Tous statuts</option>
                                <option value="paid">Payé</option>
                                <option value="partial">Partiel</option>
                                <option value="unpaid">Non payé</option>
                                <option value="overdue">En retard</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Rechercher</label>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Nom ou ID étudiant">
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filtrer
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tableau des étudiants -->
            <div class="table-container">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="color: var(--accent-color); margin: 0;">
                        <i class="fas fa-users"></i> Statut par Étudiant (31)
                    </h3>
                    <div>
                        <button class="btn btn-small btn-warning" onclick="selectOverdueStudents()">
                            <i class="fas fa-check-square"></i> Sélectionner en retard
                        </button>
                        <button class="btn btn-small btn-primary" onclick="clearSelection()">
                            <i class="fas fa-times"></i> Tout désélectionner
                        </button>
                    </div>
                </div>
                
                <table class="table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all" onchange="toggleSelectAll()"></th>
                            <th class="sortable" onclick="sortTable('student_name')">Étudiant</th>
                            <th class="sortable" onclick="sortTable('class_name')">Classe</th>
                            <th class="sortable" onclick="sortTable('total_amount')">Montant Total</th>
                            <th class="sortable" onclick="sortTable('total_paid')">Payé</th>
                            <th class="sortable" onclick="sortTable('remaining_balance')">Restant</th>
                            <th class="sortable" onclick="sortTable('payment_percentage')">Progression</th>
                            <th class="sortable" onclick="sortTable('payment_status')">Statut</th>
                            <th>Dernière activité</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="studentsTableBody">
                        <!-- Les données seront chargées ici via JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Onglet Par classe -->
        <div class="tab-content" id="classes-content">
            <div id="classesContainer">
                <!-- Les classes seront chargées ici -->
            </div>
        </div>

        <!-- Onglet Retards -->
        <div class="tab-content" id="overdue-content">
            <div class="table-container">
                <h3 style="color: var(--danger-color); margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle"></i> Étudiants en Retard
                </h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Étudiant</th>
                            <th>Classe</th>
                            <th>Montant Dû</th>
                            <th>Jours de Retard</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="overdueTableBody">
                        <!-- Les données seront chargées ici -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Rappels en Masse -->
    <div id="bulkReminderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-paper-plane"></i> Envoyer des Rappels en Masse
                </h3>
                <span class="close" onclick="closeBulkReminderModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="bulkReminderForm">
                    <div class="form-group">
                        <label>Cibler les étudiants avec le statut :</label>
                        <select class="form-control" name="target_status" required>
                            <option value="overdue">En retard</option>
                            <option value="partial">Paiement partiel</option>
                            <option value="unpaid">Non payé</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Message personnalisé (optionnel) :</label>
                        <textarea class="form-control" name="custom_message" rows="4" 
                                  placeholder="Message personnalisé à ajouter au rappel..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeBulkReminderModal()">
                    Annuler
                </button>
                <button type="button" class="btn btn-warning" onclick="sendBulkReminders()">
                    <i class="fas fa-paper-plane"></i> Envoyer les Rappels
                </button>
            </div>
        </div>
    </div>

    <script>
        // Données simulées
        const studentsData = [
            {
                id: 'UAS-GI2-01',
                name: 'Evans BOUTITOU',
                class: '2ème année Génie informatique',
                total_amount: 850000,
                total_paid: 850000,
                remaining_balance: 0,
                payment_percentage: 100,
                status: 'paid',
                last_payment: '2025-01-15',
                days_since_payment: 15
            },
            {
                id: 'UAS-GI2-02',
                name: 'Pierre Joël MVE MOUKETOU',
                class: '2ème année Génie informatique',
                total_amount: 850000,
                total_paid: 425000,
                remaining_balance: 425000,
                payment_percentage: 50,
                status: 'partial',
                last_payment: '2024-12-10',
                days_since_payment: 50
            },
            {
                id: 'UAS-GI2-05',
                name: 'Orphé MYENE',
                class: '2ème année Génie informatique',
                total_amount: 850000,
                total_paid: 200000,
                remaining_balance: 650000,
                payment_percentage: 23.5,
                status: 'overdue',
                last_payment: '2024-11-20',
                days_since_payment: 70,
                days_overdue: 30
            }
        ];

        const classesData = [
            {
                name: '2ème année Génie informatique',
                total_students: 7,
                total_expected: 5950000,
                total_collected: 4675000,
                students_paid: 3,
                students_partial: 2,
                students_unpaid: 1,
                students_overdue: 1
            },
            {
                name: '2ème année Génie civil',
                total_students: 4,
                total_expected: 3200000,
                total_collected: 2400000,
                students_paid: 2,
                students_partial: 1,
                students_unpaid: 0,
                students_overdue: 1
            }
        ];

        // Fonctions de l'interface
        function switchTab(tabName) {
            // Désactiver tous les onglets
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            // Activer l'onglet sélectionné
            event.target.classList.add('active');
            document.getElementById(tabName + '-content').classList.add('active');

            // Charger les données selon l'onglet
            if (tabName === 'overview') {
                loadStudentsData();
            } else if (tabName === 'classes') {
                loadClassesData();
            } else if (tabName === 'overdue') {
                loadOverdueData();
            }
        }

        function loadStudentsData() {
            const tbody = document.getElementById('studentsTableBody');
            tbody.innerHTML = '';

            studentsData.forEach(student => {
                const urgencyClass = student.status === 'overdue' && student.days_overdue > 30 ? 'urgency-high' : 
                                   student.status === 'overdue' && student.days_overdue > 7 ? 'urgency-medium' : 
                                   student.status === 'partial' ? 'urgency-low' : '';

                const row = `
                    <tr class="${urgencyClass}">
                        <td>
                            <input type="checkbox" class="student-checkbox" 
                                   value="${student.id}" data-status="${student.status}">
                        </td>
                        <td>
                            <div>
                                <strong>${student.name}</strong><br>
                                <small style="color: #ccc;">${student.id}</small>
                            </div>
                        </td>
                        <td>${student.class}</td>
                        <td><strong>${student.total_amount.toLocaleString()} FCFA</strong></td>
                        <td style="color: var(--success-color);">${student.total_paid.toLocaleString()} FCFA</td>
                        <td style="color: ${student.remaining_balance > 0 ? 'var(--warning-color)' : 'var(--success-color)'};">
                            ${student.remaining_balance.toLocaleString()} FCFA
                            ${student.days_overdue ? `<br><small style="color: var(--danger-color);"><i class="fas fa-exclamation-triangle"></i> ${student.days_overdue} jours de retard</small>` : ''}
                        </td>
                        <td>
                            <div class="progress">
                                <div class="progress-bar ${student.payment_percentage === 100 ? 'progress-success' : student.payment_percentage > 50 ? 'progress-warning' : 'progress-danger'}" 
                                     style="width: ${student.payment_percentage}%"></div>
                            </div>
                            <small>${student.payment_percentage}%</small>
                        </td>
                        <td>
                            <span class="status-badge status-${student.status}">
                                ${getStatusText(student.status)}
                            </span>
                        </td>
                        <td>
                            ${student.last_payment ? `
                                <div>
                                    <strong>${formatDate(student.last_payment)}</strong><br>
                                    <small style="color: #ccc;">Il y a ${student.days_since_payment} jours</small>
                                </div>
                            ` : '<span style="color: #ccc;">Aucun paiement</span>'}
                        </td>
                        <td>
                            <div style="display: flex; gap: 5px;">
                                <button class="btn btn-small btn-primary" onclick="viewStudentDetails('${student.id}')" title="Voir détails">
                                    <i class="fas fa-eye"></i>
                                </button>
                                ${student.remaining_balance > 0 ? `
                                    <button class="btn btn-small btn-success" onclick="addPayment('${student.id}')" title="Nouveau paiement">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                ` : ''}
                                ${student.status === 'overdue' || student.status === 'partial' ? `
                                    <button class="btn btn-small btn-warning" onclick="sendReminder('${student.id}')" title="Envoyer rappel">
                                        <i class="fas fa-bell"></i>
                                    </button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        function loadClassesData() {
            const container = document.getElementById('classesContainer');
            container.innerHTML = '';

            classesData.forEach(classData => {
                const collectionRate = ((classData.total_collected / classData.total_expected) * 100).toFixed(1);
                
                const classCard = `
                    <div class="class-card">
                        <div class="class-header">
                            <div class="class-name">
                                <i class="fas fa-graduation-cap"></i> ${classData.name}
                            </div>
                            <div>
                                <span style="color: #ccc;">${classData.total_students} étudiants</span>
                            </div>
                        </div>
                        
                        <div class="class-stats">
                            <div class="class-stat">
                                <div class="class-stat-value" style="color: var(--success-color);">${classData.students_paid}</div>
                                <div class="class-stat-label">Payés</div>
                            </div>
                            <div class="class-stat">
                                <div class="class-stat-value" style="color: var(--warning-color);">${classData.students_partial}</div>
                                <div class="class-stat-label">Partiels</div>
                            </div>
                            <div class="class-stat">
                                <div class="class-stat-value" style="color: var(--danger-color);">${classData.students_unpaid}</div>
                                <div class="class-stat-label">Non payés</div>
                            </div>
                            <div class="class-stat">
                                <div class="class-stat-value" style="color: var(--danger-color);">${classData.students_overdue}</div>
                                <div class="class-stat-label">En retard</div>
                            </div>
                            <div class="class-stat">
                                <div class="class-stat-value" style="color: var(--info-color);">${classData.total_collected.toLocaleString()}</div>
                                <div class="class-stat-label">FCFA collectés</div>
                            </div>
                            <div class="class-stat">
                                <div class="class-stat-value" style="color: var(--accent-color);">${collectionRate}%</div>
                                <div class="class-stat-label">Taux recouvrement</div>
                            </div>
                        </div>
                    </div>
                `;
                container.innerHTML += classCard;
            });
        }

        function loadOverdueData() {
            const tbody = document.getElementById('overdueTableBody');
            tbody.innerHTML = '';

            const overdueStudents = studentsData.filter(student => student.status === 'overdue');
            
            overdueStudents.forEach(student => {
                const row = `
                    <tr>
                        <td>
                            <div>
                                <strong>${student.name}</strong><br>
                                <small style="color: #ccc;">${student.id}</small>
                            </div>
                        </td>
                        <td>${student.class}</td>
                        <td style="color: var(--danger-color);">
                            <strong>${student.remaining_balance.toLocaleString()} FCFA</strong>
                        </td>
                        <td>
                            <span style="color: var(--danger-color); font-weight: bold;">
                                <i class="fas fa-exclamation-triangle"></i> ${student.days_overdue} jours
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 5px;">
                                <button class="btn btn-small btn-warning" onclick="sendReminder('${student.id}')" title="Envoyer rappel">
                                    <i class="fas fa-bell"></i> Rappel
                                </button>
                                <button class="btn btn-small btn-success" onclick="addPayment('${student.id}')" title="Nouveau paiement">
                                    <i class="fas fa-plus"></i> Paiement
                                </button>
                                <button class="btn btn-small btn-primary" onclick="viewStudentDetails('${student.id}')" title="Voir détails">
                                    <i class="fas fa-eye"></i> Détails
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });

            if (overdueStudents.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #ccc;">
                            <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 10px; color: var(--success-color);"></i><br>
                            Aucun étudiant en retard de paiement
                        </td>
                    </tr>
                `;
            }
        }

        // Fonctions utilitaires
        function getStatusText(status) {
            const statusTexts = {
                'paid': 'Payé',
                'partial': 'Partiel',
                'unpaid': 'Non payé',
                'overdue': 'En retard'
            };
            return statusTexts[status];
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('fr-FR');
        }

        function showAlert(message, type = 'success') {
            const alertContainer = document.getElementById('alertContainer');
            const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            
            const alert = `
                <div class="alert ${alertClass}">
                    <i class="fas ${icon}"></i>
                    ${message}
                </div>
            `;
            
            alertContainer.innerHTML = alert;
            
            setTimeout(() => {
                alertContainer.innerHTML = '';
            }, 5000);
        }

        // Fonctions des modals
        function openBulkReminderModal() {
            document.getElementById('bulkReminderModal').style.display = 'block';
        }

        function closeBulkReminderModal() {
            document.getElementById('bulkReminderModal').style.display = 'none';
        }

        // Fonctions d'action
        function sendBulkReminders() {
            const form = document.getElementById('bulkReminderForm');
            const formData = new FormData(form);
            const targetStatus = formData.get('target_status');
            const customMessage = formData.get('custom_message');
            
            // Simuler l'envoi
            const targetCount = studentsData.filter(s => s.status === targetStatus).length;
            
            closeBulkReminderModal();
            showAlert(`Rappels envoyés avec succès à ${targetCount} étudiants avec le statut "${getStatusText(targetStatus)}"`);
        }

        function sendReminder(studentId) {
            const student = studentsData.find(s => s.id === studentId);
            if (student) {
                showAlert(`Rappel envoyé avec succès à ${student.name}`);
            }
        }

        function addPayment(studentId) {
            const student = studentsData.find(s => s.id === studentId);
            if (student) {
                showAlert(`Redirection vers la page d'ajout de paiement pour ${student.name}`, 'success');
                // Ici vous pourriez rediriger vers la page d'ajout de paiement
                // window.location.href = `add_payment.php?student_id=${studentId}`;
            }
        }

        function viewStudentDetails(studentId) {
            const student = studentsData.find(s => s.id === studentId);
            if (student) {
                showAlert(`Affichage des détails pour ${student.name}`, 'success');
                // Ici vous pourriez ouvrir une modal avec les détails ou rediriger
                // window.location.href = `student_details.php?id=${studentId}`;
            }
        }

        function exportStatus() {
            showAlert('Export des statuts de paiement en cours...', 'success');
            // Ici vous pourriez implémenter l'export CSV/Excel
        }

        // Fonctions de sélection
        function toggleSelectAll() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.student-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }

        function selectOverdueStudents() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                if (checkbox.dataset.status === 'overdue') {
                    checkbox.checked = true;
                } else {
                    checkbox.checked = false;
                }
            });
            
            const overdueCount = document.querySelectorAll('.student-checkbox[data-status="overdue"]:checked').length;
            showAlert(`${overdueCount} étudiants en retard sélectionnés`);
        }

        function clearSelection() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            document.getElementById('select-all').checked = false;
            showAlert('Sélection effacée');
        }

        // Fonctions de tri
        let sortDirection = {};
        
        function sortTable(column) {
            const currentDirection = sortDirection[column] || 'asc';
            const newDirection = currentDirection === 'asc' ? 'desc' : 'asc';
            sortDirection[column] = newDirection;
            
            // Mettre à jour les indicateurs visuels
            document.querySelectorAll('.table th').forEach(th => {
                th.classList.remove('sort-asc', 'sort-desc');
            });
            
            const clickedHeader = event.target;
            clickedHeader.classList.add(newDirection === 'asc' ? 'sort-asc' : 'sort-desc');
            
            // Trier les données
            studentsData.sort((a, b) => {
                let aVal = a[column];
                let bVal = b[column];
                
                if (column === 'student_name') {
                    aVal = a.name;
                    bVal = b.name;
                }
                
                if (typeof aVal === 'string') {
                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                }
                
                if (newDirection === 'asc') {
                    return aVal > bVal ? 1 : -1;
                } else {
                    return aVal < bVal ? 1 : -1;
                }
            });
            
            loadStudentsData();
        }

        // Gestion des filtres
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const classFilter = formData.get('class_filter');
            const statusFilter = formData.get('status_filter');
            const searchFilter = formData.get('search');
            
            let filteredData = [...studentsData];
            
            if (statusFilter) {
                filteredData = filteredData.filter(student => student.status === statusFilter);
            }
            
            if (searchFilter) {
                const search = searchFilter.toLowerCase();
                filteredData = filteredData.filter(student => 
                    student.name.toLowerCase().includes(search) || 
                    student.id.toLowerCase().includes(search)
                );
            }
            
            // Temporairement remplacer les données pour l'affichage
            const originalData = [...studentsData];
            studentsData.length = 0;
            studentsData.push(...filteredData);
            
            loadStudentsData();
            
            showAlert(`${filteredData.length} résultat(s) trouvé(s)`);
            
            // Remettre les données originales après un délai
            setTimeout(() => {
                studentsData.length = 0;
                studentsData.push(...originalData);
            }, 100);
        });

        // Fermer les modals en cliquant à l'extérieur
        window.onclick = function(event) {
            const modal = document.getElementById('bulkReminderModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            loadStudentsData();
            
            // Simuler un message de succès au chargement
            setTimeout(() => {
                showAlert('Données des paiements chargées avec succès');
            }, 500);
        });

        // Fonctions supplémentaires pour améliorer l'expérience utilisateur
        function refreshData() {
            showAlert('Actualisation des données...', 'success');
            
            // Simuler le rechargement des données
            setTimeout(() => {
                loadStudentsData();
                showAlert('Données actualisées avec succès');
            }, 1000);
        }

        function generateReport() {
            showAlert('Génération du rapport en cours...', 'success');
            
            // Simuler la génération d'un rapport
            setTimeout(() => {
                showAlert('Rapport généré et téléchargé avec succès');
            }, 2000);
        }

        // Gestion du responsive
        function handleResize() {
            const container = document.querySelector('.container');
            if (window.innerWidth < 768) {
                container.style.padding = '20px 15px';
            } else {
                container.style.padding = '30px 20px';
            }
        }

        window.addEventListener('resize', handleResize);
        handleResize(); // Appel initial

        // Animation des cartes statistiques
        function animateStats() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.animation = 'fadeInUp 0.5s ease forwards';
                }, index * 100);
            });
        }

        // CSS pour l'animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .stat-card {
                opacity: 0;
            }
        `;
        document.head.appendChild(style);

        // Lancer l'animation au chargement
        setTimeout(animateStats, 200);
    </script>
</body>
</html>