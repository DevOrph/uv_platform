<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Paiements - Université Virtuelle</title>
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

        /* Header */
        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid var(--border-color);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        h1 {
            font-size: 28px;
            color: var(--accent-color);
            margin-bottom: 15px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        nav ul {
            list-style: none;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 5px;
        }

        nav a {
            color: var(--text-light);
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        nav a:hover {
            background: rgba(3, 155, 229, 0.1);
            transform: translateY(-2px);
        }

        /* Container principal */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        /* Page header */
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

        /* Boutons */
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

        .btn-primary {
            background: var(--accent-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-small {
            padding: 8px 12px;
            font-size: 12px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        /* Cards statistiques */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
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

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .stat-title {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #ccc;
            font-weight: 600;
        }

        .stat-icon {
            font-size: 24px;
            color: var(--accent-color);
        }

        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .stat-change {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
            color: #ccc;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 30px;
            overflow-x: auto;
        }

        .tab {
            padding: 15px 20px;
            cursor: pointer;
            color: #ccc;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab.active {
            color: var(--accent-color);
            border-bottom-color: var(--accent-color);
        }

        .tab:hover {
            color: var(--text-light);
            background: rgba(255, 255, 255, 0.05);
        }

        /* Content des tabs */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Table */
        .table-container {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
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
        }

        .table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        /* Filtres */
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

        /* Status badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-paid {
            background: var(--success-color);
            color: white;
        }

        .status-partial {
            background: var(--warning-color);
            color: white;
        }

        .status-unpaid {
            background: var(--danger-color);
            color: white;
        }

        .status-overdue {
            background: #8e44ad;
            color: white;
        }

        /* Progress bar */
        .progress {
            width: 100px;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-success {
            background: var(--success-color);
        }

        .progress-warning {
            background: var(--warning-color);
        }

        .progress-danger {
            background: var(--danger-color);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--secondary-bg);
            margin: 5% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            border: 1px solid var(--border-color);
        }

        .modal-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            color: var(--accent-color);
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            color: #ccc;
            font-size: 24px;
            cursor: pointer;
            margin-left: auto;
        }

        .modal-close:hover {
            color: var(--text-light);
        }

        /* Form styling */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 20px 15px;
            }

            .page-header {
                flex-direction: column;
                align-items: stretch;
            }

            .page-actions {
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters-row {
                grid-template-columns: 1fr;
            }

            .tabs {
                justify-content: flex-start;
            }
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
            <nav>
                <ul>
                    <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="user_management.php"><i class="fas fa-users"></i> Utilisateurs</a></li>
                    <li><a href="course_management.php"><i class="fas fa-book"></i> Cours</a></li>
                    <li><a href="../api/payment_status.php" class="active"><i class="fas fa-money-bill-wave"></i> Paiements</a></li>
                    <li><a href="admin_profile.php"><i class="fas fa-user-cog"></i> Profil</a></li>
                    <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-money-bill-wave"></i>
                Gestion des Paiements
            </h2>
            <div class="page-actions">
                <button class="btn btn-primary" onclick="openModal('addPaymentModal')">
                    <i class="fas fa-plus"></i> Nouveau Paiement
                </button>
                <button class="btn btn-success" onclick="openModal('manageTuitionModal')">
                    <i class="fas fa-cog"></i> Gérer Frais
                </button>
                <button class="btn btn-warning" onclick="openModal('permissionsModal')">
                    <i class="fas fa-user-shield"></i> Permissions
                </button>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-title">Total Encaissé</span>
                    <i class="stat-icon fas fa-coins"></i>
                </div>
                <div class="stat-value" id="total-collected">2,450,000 FCFA</div>
                <div class="stat-change">
                    <i class="fas fa-arrow-up"></i> Cette année
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-header">
                    <span class="stat-title">En Attente</span>
                    <i class="stat-icon fas fa-clock"></i>
                </div>
                <div class="stat-value" id="total-pending">850,000 FCFA</div>
                <div class="stat-change">
                    <i class="fas fa-exclamation-triangle"></i> À recouvrer
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-header">
                    <span class="stat-title">Étudiants à Jour</span>
                    <i class="stat-icon fas fa-check-circle"></i>
                </div>
                <div class="stat-value" id="students-paid">145</div>
                <div class="stat-change">
                    <i class="fas fa-percent"></i> 72% du total
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-header">
                    <span class="stat-title">Retards de Paiement</span>
                    <i class="stat-icon fas fa-exclamation-circle"></i>
                </div>
                <div class="stat-value" id="overdue-students">28</div>
                <div class="stat-change">
                    <i class="fas fa-calendar-times"></i> En retard
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="filters">
            <div class="filters-row">
                <div class="form-group">
                    <label>Classe</label>
                    <select class="form-control" id="filter-class">
                        <option value="">Toutes les classes</option>
                        <option value="4">2ème année Génie Informatique</option>
                        <option value="5">2ème année Génie Civil</option>
                        <option value="6">2ème année Génie Électromécanique</option>
                        <option value="7">1ère année Cycle Préparatoire</option>
                        <option value="8">2ème année Cycle Préparatoire</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Statut de Paiement</label>
                    <select class="form-control" id="filter-status">
                        <option value="">Tous les statuts</option>
                        <option value="paid">Payé</option>
                        <option value="partial">Partiel</option>
                        <option value="unpaid">Non payé</option>
                        <option value="overdue">En retard</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Recherche Étudiant</label>
                    <input type="text" class="form-control" id="filter-search" placeholder="Nom ou ID étudiant">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="applyFilters()">
                        <i class="fas fa-search"></i> Filtrer
                    </button>
                </div>
            </div>
        </div>

        <!-- Onglets -->
        <div class="tabs">
            <div class="tab active" onclick="switchTab('overview')">
                <i class="fas fa-chart-pie"></i> Vue d'ensemble
            </div>
            <div class="tab" onclick="switchTab('students')">
                <i class="fas fa-users"></i> Étudiants
            </div>
            <div class="tab" onclick="switchTab('payments')">
                <i class="fas fa-receipt"></i> Historique Paiements
            </div>
            <div class="tab" onclick="switchTab('reports')">
                <i class="fas fa-chart-bar"></i> Rapports
            </div>
        </div>

        <!-- Contenu Onglet Vue d'ensemble -->
        <div class="tab-content active" id="overview-content">
            <div class="table-container">
                <h3 style="margin-bottom: 20px; color: var(--accent-color);">
                    <i class="fas fa-users"></i> Statut des Paiements par Étudiant
                </h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Étudiant</th>
                            <th>Classe</th>
                            <th>Montant Total</th>
                            <th>Payé</th>
                            <th>Restant</th>
                            <th>Progression</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="students-table">
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <img src="../uploads/avatars/default.png" style="width: 32px; height: 32px; border-radius: 50%;" alt="">
                                    <div>
                                        <strong>Evans BOUTITOU</strong><br>
                                        <small style="color: #ccc;">UAS-GI2-01</small>
                                    </div>
                                </div>
                            </td>
                            <td>2ème année GI</td>
                            <td><strong>850,000 FCFA</strong></td>
                            <td style="color: var(--success-color);">600,000 FCFA</td>
                            <td style="color: var(--warning-color);">250,000 FCFA</td>
                            <td>
                                <div class="progress">
                                    <div class="progress-bar progress-warning" style="width: 70.6%"></div>
                                </div>
                                <small>70.6%</small>
                            </td>
                            <td><span class="status-badge status-partial">Partiel</span></td>
                            <td>
                                <button class="btn btn-small btn-primary" onclick="viewStudentDetails('UAS-GI2-01')">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-small btn-success" onclick="addPayment('UAS-GI2-01')">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <img src="../uploads/avatars/default.png" style="width: 32px; height: 32px; border-radius: 50%;" alt="">
                                    <div>
                                        <strong>Orphé MYENE</strong><br>
                                        <small style="color: #ccc;">UAS-GI2-05</small>
                                    </div>
                                </div>
                            </td>
                            <td>2ème année GI</td>
                            <td><strong>850,000 FCFA</strong></td>
                            <td style="color: var(--success-color);">850,000 FCFA</td>
                            <td style="color: var(--success-color);">0 FCFA</td>
                            <td>
                                <div class="progress">
                                    <div class="progress-bar progress-success" style="width: 100%"></div>
                                </div>
                                <small>100%</small>
                            </td>
                            <td><span class="status-badge status-paid">Payé</span></td>
                            <td>
                                <button class="btn btn-small btn-primary" onclick="viewStudentDetails('UAS-GI2-05')">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-small btn-success" onclick="generateReceipt('UAS-GI2-05')">
                                    <i class="fas fa-receipt"></i>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <img src="../uploads/avatars/default.png" style="width: 32px; height: 32px; border-radius: 50%;" alt="">
                                    <div>
                                        <strong>KASSA Filbert</strong><br>
                                        <small style="color: #ccc;">UAS-GI2-07</small>
                                    </div>
                                </div>
                            </td>
                            <td>2ème année GI</td>
                            <td><strong>850,000 FCFA</strong></td>
                            <td style="color: var(--danger-color);">0 FCFA</td>
                            <td style="color: var(--danger-color);">850,000 FCFA</td>
                            <td>
                                <div class="progress">
                                    <div class="progress-bar progress-danger" style="width: 0%"></div>
                                </div>
                                <small>0%</small>
                            </td>
                            <td><span class="status-badge status-overdue">En retard</span></td>
                            <td>
                                <button class="btn btn-small btn-primary" onclick="viewStudentDetails('UAS-GI2-07')">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-small btn-warning" onclick="sendReminder('UAS-GI2-07')">
                                    <i class="fas fa-bell"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Contenu Onglet Étudiants -->
        <div class="tab-content" id="students-content">
            <div class="table-container">
                <h3 style="margin-bottom: 20px; color: var(--accent-color);">
                    <i class="fas fa-users"></i> Liste Détaillée des Étudiants
                </h3>
                <div style="margin-bottom: 20px;">
                    <button class="btn btn-primary" onclick="exportStudentsList()">
                        <i class="fas fa-download"></i> Exporter Liste
                    </button>
                    <button class="btn btn-warning" onclick="sendBulkReminders()">
                        <i class="fas fa-paper-plane"></i> Rappels en Masse
                    </button>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all"></th>
                            <th>Étudiant</th>
                            <th>Contact</th>
                            <th>Classe</th>
                            <th>Frais</th>
                            <th>Payé</th>
                            <th>% Progression</th>
                            <th>Dernier Paiement</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Les données seront chargées via JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Contenu Onglet Historique Paiements -->
        <div class="tab-content" id="payments-content">
            <div class="table-container">
                <h3 style="margin-bottom: 20px; color: var(--accent-color);">
                    <i class="fas fa-receipt"></i> Historique des Paiements
                </h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Étudiant</th>
                            <th>Montant</th>
                            <th>Méthode</th>
                            <th>Référence</th>
                            <th>Type</th>
                            <th>Enregistré par</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>28/07/2025 14:30</td>
                            <td>Evans BOUTITOU</td>
                            <td style="color: var(--success-color);"><strong>300,000 FCFA</strong></td>
                            <td>Virement bancaire</td>
                            <td>VIR-2025-001</td>
                            <td>Échéance 2</td>
                            <td>ADMIN01</td>
                            <td><span class="status-badge status-paid">Validé</span></td>
                            <td>
                                <button class="btn btn-small btn-primary">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-small btn-success">
                                    <i class="fas fa-print"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Contenu Onglet Rapports -->
        <div class="tab-content" id="reports-content">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                <div class="table-container">
                    <h4 style="color: var(--accent-color); margin-bottom: 15px;">
                        <i class="fas fa-chart-pie"></i> Résumé par Classe
                    </h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Classe</th>
                                <th>Étudiants</th>
                                <th>Collecté</th>
                                <th>Taux</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>2ème année GI</td>
                                <td>45</td>
                                <td>25,500,000 FCFA</td>
                                <td>75%</td>
                            </tr>
                            <tr>
                                <td>2ème année GC</td>
                                <td>38</td>
                                <td>19,200,000 FCFA</td>
                                <td>63%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="table-container">
                    <h4 style="color: var(--accent-color); margin-bottom: 15px;">
                        <i class="fas fa-calendar-alt"></i> Échéances à Venir
                    </h4>
                    <div style="space-y: 10px;">
                        <div style="padding: 15px; background: rgba(241, 196, 15, 0.1); border-left: 4px solid var(--warning-color); margin-bottom: 10px;">
                            <strong>31 Janvier 2025</strong><br>
                            <small>Échéance finale - 45 étudiants en attente</small>
                        </div>
                        <div style="padding: 15px; background: rgba(46, 204, 113, 0.1); border-left: 4px solid var(--success-color);">
                            <strong>15 Février 2025</strong><br>
                            <small>Début 2ème semestre - Nouveau cycle</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nouveau Paiement -->
    <div class="modal" id="addPaymentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-plus"></i> Nouveau Paiement
                </h3>
                <span class="modal-close" onclick="closeModal('addPaymentModal')">&times;</span>
            </div>
            <form id="paymentForm">
                <div class="form-row">
                    <div class="form-group">
                        <label>Étudiant</label>
                        <select class="form-control" id="student-select" required>
                            <option value="">Sélectionner un étudiant</option>
                            <!-- Options chargées via JavaScript -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Montant (FCFA)</label>
                        <input type="number" class="form-control" id="payment-amount" required min="0" step="1000">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Méthode de Paiement</label>
                        <select class="form-control" id="payment-method" required>
                            <option value="cash">Espèces</option>
                            <option value="bank_transfer">Virement bancaire</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="check">Chèque</option>
                            <option value="other">Autre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Type de Paiement</label>
                        <select class="form-control" id="payment-type" required>
                            <option value="registration">Inscription</option>
                            <option value="tuition">Scolarité</option>
                            <option value="insurance">Assurance</option>
                            <option value="library">Bibliothèque</option>
                            <option value="practical">Travaux pratiques</option>
                            <option value="installment">Échéance</option>
                            <option value="other">Autre</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Numéro de Référence</label>
                        <input type="text" class="form-control" id="reference-number" placeholder="Optionnel">
                    </div>
                    <div class="form-group">
                        <label>Numéro de Reçu</label>
                        <input type="text" class="form-control" id="receipt-number" placeholder="Généré automatiquement">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea class="form-control" id="payment-description" rows="3" placeholder="Description ou notes sur le paiement"></textarea>
                </div>
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" onclick="closeModal('addPaymentModal')" style="background: #95a5a6; color: white;">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Enregistrer Paiement
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Gestion des Frais -->
    <div class="modal" id="manageTuitionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-cog"></i> Gestion des Frais de Scolarité
                </h3>
                <span class="modal-close" onclick="closeModal('manageTuitionModal')">&times;</span>
            </div>
            <div class="tabs" style="border-bottom: 1px solid var(--border-color); margin-bottom: 20px;">
                <div class="tab active" onclick="switchTuitionTab('current')">Frais Actuels</div>
                <div class="tab" onclick="switchTuitionTab('new')">Nouveaux Frais</div>
            </div>
            
            <div class="tab-content active" id="current-tuition">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Classe</th>
                            <th>Année</th>
                            <th>Montant Total</th>
                            <th>Échéances</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>2ème année GI</td>
                            <td><?php echo htmlspecialchars(ANNEE_ACADEMIQUE_COURANTE); ?></td>
                            <td><strong>850,000 FCFA</strong></td>
                            <td>3 échéances</td>
                            <td>
                                <button class="btn btn-small btn-primary">
                                    <i class="fas fa-edit"></i> Modifier
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="tab-content" id="new-tuition">
                <form id="tuitionForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Classe</label>
                            <select class="form-control" id="tuition-class" required>
                                <option value="">Sélectionner une classe</option>
                                <!-- Options chargées via JavaScript -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Année Académique</label>
                            <input type="text" class="form-control" id="academic-year" placeholder="2024-2025" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Frais d'Inscription (FCFA)</label>
                            <input type="number" class="form-control" id="registration-fee" min="0" step="1000">
                        </div>
                        <div class="form-group">
                            <label>Frais de Scolarité (FCFA)</label>
                            <input type="number" class="form-control" id="tuition-fee" min="0" step="1000">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Frais d'Assurance (FCFA)</label>
                            <input type="number" class="form-control" id="insurance-fee" min="0" step="1000">
                        </div>
                        <div class="form-group">
                            <label>Frais de Bibliothèque (FCFA)</label>
                            <input type="number" class="form-control" id="library-fee" min="0" step="1000">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Frais de TP (FCFA)</label>
                            <input type="number" class="form-control" id="practical-fee" min="0" step="1000">
                        </div>
                        <div class="form-group">
                            <label>Autres Frais (FCFA)</label>
                            <input type="number" class="form-control" id="other-fees" min="0" step="1000">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Date Limite</label>
                            <input type="date" class="form-control" id="due-date">
                        </div>
                        <div class="form-group">
                            <label>Nombre d'Échéances</label>
                            <input type="number" class="form-control" id="installments" min="1" max="10" value="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" id="tuition-description" rows="3"></textarea>
                    </div>
                    <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 20px;">
                        <button type="button" class="btn" onclick="closeModal('manageTuitionModal')" style="background: #95a5a6; color: white;">Annuler</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Créer Frais
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Permissions -->
    <div class="modal" id="permissionsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-user-shield"></i> Gestion des Permissions
                </h3>
                <span class="modal-close" onclick="closeModal('permissionsModal')">&times;</span>
            </div>
            <div style="margin-bottom: 20px;">
                <button class="btn btn-primary" onclick="openGrantPermissionForm()">
                    <i class="fas fa-plus"></i> Accorder Permission
                </button>
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Utilisateur</th>
                        <th>Rôle</th>
                        <th>Accordée le</th>
                        <th>Expire le</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>ADMIN01</strong></td>
                        <td>Administrateur</td>
                        <td>Permanent</td>
                        <td>-</td>
                        <td><span class="status-badge status-paid">Actif</span></td>
                        <td>-</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Variables globales
        let currentTab = 'overview';
        let currentTuitionTab = 'current';

        // Fonctions de gestion des onglets
        function switchTab(tabName) {
            // Masquer tous les contenus
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Désactiver tous les onglets
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Activer l'onglet et le contenu sélectionnés
            document.getElementById(tabName + '-content').classList.add('active');
            event.target.classList.add('active');
            
            currentTab = tabName;
            
            // Charger les données si nécessaire
            loadTabData(tabName);
        }

        function switchTuitionTab(tabName) {
            document.querySelectorAll('#manageTuitionModal .tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            document.querySelectorAll('#manageTuitionModal .tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabName + '-tuition').classList.add('active');
            event.target.classList.add('active');
            
            currentTuitionTab = tabName;
        }

        // Fonctions de gestion des modales
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            if (modalId === 'addPaymentModal') {
                loadStudentsList();
                generateReceiptNumber();
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Fermeture des modales en cliquant à l'extérieur
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Fonctions de chargement des données
        function loadTabData(tabName) {
            switch(tabName) {
                case 'students':
                    loadDetailedStudentsList();
                    break;
                case 'payments':
                    loadPaymentsHistory();
                    break;
                case 'reports':
                    loadReports();
                    break;
            }
        }

        function loadStudentsList() {
            // Simulation - À remplacer par un appel AJAX
            const studentSelect = document.getElementById('student-select');
            studentSelect.innerHTML = `
                <option value="">Sélectionner un étudiant</option>
                <option value="UAS-GI2-01">Evans BOUTITOU (UAS-GI2-01)</option>
                <option value="UAS-GI2-05">Orphé MYENE (UAS-GI2-05)</option>
                <option value="UAS-GI2-07">KASSA Filbert (UAS-GI2-07)</option>
            `;
        }

        function generateReceiptNumber() {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            
            document.getElementById('receipt-number').value = `REC-${year}${month}${day}-${random}`;
        }

        // Fonctions d'actions
        function viewStudentDetails(studentId) {
            alert(`Voir détails de l'étudiant: ${studentId}`);
            // Rediriger vers une page de détails ou ouvrir une modale
        }

        function addPayment(studentId) {
            openModal('addPaymentModal');
            document.getElementById('student-select').value = studentId;
        }

        function generateReceipt(studentId) {
            alert(`Générer reçu pour: ${studentId}`);
            // Implémenter la génération de reçu PDF
        }

        function sendReminder(studentId) {
            if (confirm(`Envoyer un rappel de paiement à l'étudiant ${studentId} ?`)) {
                alert(`Rappel envoyé à ${studentId}`);
                // Implémenter l'envoi d'email/SMS
            }
        }

        function applyFilters() {
            const classFilter = document.getElementById('filter-class').value;
            const statusFilter = document.getElementById('filter-status').value;
            const searchFilter = document.getElementById('filter-search').value;
            
            // Implémenter la logique de filtrage
            console.log('Filtres appliqués:', { classFilter, statusFilter, searchFilter });
        }

        function exportStudentsList() {
            alert('Export de la liste des étudiants en cours...');
            // Implémenter l'export Excel/PDF
        }

        function sendBulkReminders() {
            if (confirm('Envoyer des rappels à tous les étudiants en retard ?')) {
                alert('Rappels en masse envoyés');
                // Implémenter l'envoi en masse
            }
        }

        // Gestion du formulaire de paiement
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = {
                student_id: document.getElementById('student-select').value,
                amount: document.getElementById('payment-amount').value,
                method: document.getElementById('payment-method').value,
                type: document.getElementById('payment-type').value,
                reference: document.getElementById('reference-number').value,
                receipt: document.getElementById('receipt-number').value,
                description: document.getElementById('payment-description').value
            };
            
            if (!formData.student_id || !formData.amount) {
                alert('Veuillez remplir tous les champs obligatoires');
                return;
            }
            
            // Simulation d'envoi
            alert('Paiement enregistré avec succès !');
            closeModal('addPaymentModal');
            
            // Recharger les données
            location.reload();
        });

        // Gestion du formulaire de frais
        document.getElementById('tuitionForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const fees = {
                registration: parseInt(document.getElementById('registration-fee').value) || 0,
                tuition: parseInt(document.getElementById('tuition-fee').value) || 0,
                insurance: parseInt(document.getElementById('insurance-fee').value) || 0,
                library: parseInt(document.getElementById('library-fee').value) || 0,
                practical: parseInt(document.getElementById('practical-fee').value) || 0,
                other: parseInt(document.getElementById('other-fees').value) || 0
            };
            
            const total = Object.values(fees).reduce((sum, fee) => sum + fee, 0);
            
            if (total === 0) {
                alert('Le montant total ne peut pas être 0');
                return;
            }
            
            if (confirm(`Créer des frais de ${total.toLocaleString()} FCFA ?`)) {
                alert('Frais de scolarité créés avec succès !');
                closeModal('manageTuitionModal');
            }
        });

        // Calcul automatique du total des frais
        function updateTotalFees() {
            const fees = [
                'registration-fee', 'tuition-fee', 'insurance-fee',
                'library-fee', 'practical-fee', 'other-fees'
            ].map(id => parseInt(document.getElementById(id).value) || 0);
            
            const total = fees.reduce((sum, fee) => sum + fee, 0);
            
            // Afficher le total quelque part
            const totalDisplay = document.getElementById('total-display');
            if (totalDisplay) {
                totalDisplay.textContent = `Total: ${total.toLocaleString()} FCFA`;
            }
        }

        // Ajouter des listeners pour le calcul automatique
        document.addEventListener('DOMContentLoaded', function() {
            const feeInputs = [
                'registration-fee', 'tuition-fee', 'insurance-fee',
                'library-fee', 'practical-fee', 'other-fees'
            ];
            
            feeInputs.forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    input.addEventListener('input', updateTotalFees);
                }
            });
            
            // Ajouter un affichage du total dans le formulaire
            const tuitionForm = document.getElementById('tuitionForm');
            if (tuitionForm) {
                const totalDiv = document.createElement('div');
                totalDiv.innerHTML = `
                    <div style="text-align: center; padding: 15px; background: var(--card-bg); border-radius: 8px; margin: 15px 0;">
                        <h4 id="total-display" style="color: var(--accent-color); margin: 0;">Total: 0 FCFA</h4>
                    </div>
                `;
                tuitionForm.insertBefore(totalDiv, tuitionForm.lastElementChild);
            }
        });

        // Fonction pour accorder une permission
        function openGrantPermissionForm() {
            const form = `
                <div style="background: var(--card-bg); padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h4 style="color: var(--accent-color); margin-bottom: 15px;">Accorder une Permission</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #ccc;">Utilisateur</label>
                            <select class="form-control" id="permission-user">
                                <option value="">Sélectionner un utilisateur</option>
                                <option value="ADMIN02">Filbert (ADMIN02)</option>
                                <option value="UAS-PRP-03">M. ROGUET (Enseignant)</option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #ccc;">Durée (jours)</label>
                            <input type="number" class="form-control" id="permission-duration" placeholder="7" min="1">
                        </div>
                    </div>
                    <div style="margin: 15px 0;">
                        <label style="display: block; margin-bottom: 5px; color: #ccc;">Notes</label>
                        <textarea class="form-control" id="permission-notes" rows="2" placeholder="Raison de la permission"></textarea>
                    </div>
                    <div style="text-align: right;">
                        <button class="btn btn-success" onclick="grantPermission()">
                            <i class="fas fa-check"></i> Accorder Permission
                        </button>
                    </div>
                </div>
            `;
            
            const existingForm = document.querySelector('.permission-form');
            if (existingForm) {
                existingForm.remove();
            }
            
            const container = document.querySelector('#permissionsModal .modal-content');
            const table = container.querySelector('table');
            const formDiv = document.createElement('div');
            formDiv.className = 'permission-form';
            formDiv.innerHTML = form;
            container.insertBefore(formDiv, table);
        }

        function grantPermission() {
            const userId = document.getElementById('permission-user').value;
            const duration = document.getElementById('permission-duration').value;
            const notes = document.getElementById('permission-notes').value;
            
            if (!userId) {
                alert('Veuillez sélectionner un utilisateur');
                return;
            }
            
            if (confirm(`Accorder la permission de gestion des paiements à ${userId} pour ${duration || 'une durée illimitée'} ?`)) {
                alert('Permission accordée avec succès !');
                
                // Ajouter à la table
                const table = document.querySelector('#permissionsModal table tbody');
                const row = table.insertRow();
                row.innerHTML = `
                    <td><strong>${userId}</strong></td>
                    <td>Administrateur</td>
                    <td>${new Date().toLocaleDateString()}</td>
                    <td>${duration ? new Date(Date.now() + duration * 24 * 60 * 60 * 1000).toLocaleDateString() : '-'}</td>
                    <td><span class="status-badge status-paid">Actif</span></td>
                    <td>
                        <button class="btn btn-small btn-danger" onclick="revokePermission('${userId}')">
                            <i class="fas fa-times"></i> Révoquer
                        </button>
                    </td>
                `;
                
                // Supprimer le formulaire
                document.querySelector('.permission-form').remove();
            }
        }

        function revokePermission(userId) {
            if (confirm(`Révoquer la permission de ${userId} ?`)) {
                alert(`Permission de ${userId} révoquée`);
                // Supprimer la ligne du tableau
                event.target.closest('tr').remove();
            }
        }

        // Fonctions de simulation pour les données détaillées
        function loadDetailedStudentsList() {
            const tbody = document.querySelector('#students-content tbody');
            tbody.innerHTML = `
                <tr>
                    <td><input type="checkbox" value="UAS-GI2-01"></td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <img src="../uploads/avatars/default.png" style="width: 32px; height: 32px; border-radius: 50%;" alt="">
                            <div>
                                <strong>Evans BOUTITOU</strong><br>
                                <small style="color: #ccc;">UAS-GI2-01</small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <i class="fas fa-envelope"></i> evans@email.com<br>
                        <i class="fas fa-phone"></i> +241 XX XX XX XX
                    </td>
                    <td>2ème année GI</td>
                    <td><strong>850,000 FCFA</strong></td>
                    <td style="color: var(--success-color);">600,000 FCFA</td>
                    <td>
                        <div class="progress">
                            <div class="progress-bar progress-warning" style="width: 70.6%"></div>
                        </div>
                        70.6%
                    </td>
                    <td>15/01/2025</td>
                    <td><span class="status-badge status-partial">Partiel</span></td>
                    <td>
                        <button class="btn btn-small btn-primary" onclick="viewStudentDetails('UAS-GI2-01')">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-small btn-success" onclick="addPayment('UAS-GI2-01')">
                            <i class="fas fa-plus"></i>
                        </button>
                    </td>
                </tr>
                <tr>
                    <td><input type="checkbox" value="UAS-GI2-05"></td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <img src="../uploads/avatars/default.png" style="width: 32px; height: 32px; border-radius: 50%;" alt="">
                            <div>
                                <strong>Orphé MYENE</strong><br>
                                <small style="color: #ccc;">UAS-GI2-05</small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <i class="fas fa-envelope"></i> orphe@email.com<br>
                        <i class="fas fa-phone"></i> +241 77 41 22 51
                    </td>
                    <td>2ème année GI</td>
                    <td><strong>850,000 FCFA</strong></td>
                    <td style="color: var(--success-color);">850,000 FCFA</td>
                    <td>
                        <div class="progress">
                            <div class="progress-bar progress-success" style="width: 100%"></div>
                        </div>
                        100%
                    </td>
                    <td>10/01/2025</td>
                    <td><span class="status-badge status-paid">Payé</span></td>
                    <td>
                        <button class="btn btn-small btn-primary" onclick="viewStudentDetails('UAS-GI2-05')">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-small btn-success" onclick="generateReceipt('UAS-GI2-05')">
                            <i class="fas fa-receipt"></i>
                        </button>
                    </td>
                </tr>
            `;
        }

        function loadPaymentsHistory() {
            const tbody = document.querySelector('#payments-content tbody');
            tbody.innerHTML = `
                <tr>
                    <td>28/07/2025 14:30</td>
                    <td>Evans BOUTITOU</td>
                    <td style="color: var(--success-color);"><strong>300,000 FCFA</strong></td>
                    <td>Virement bancaire</td>
                    <td>VIR-2025-001</td>
                    <td>Échéance 2</td>
                    <td>ADMIN01</td>
                    <td><span class="status-badge status-paid">Validé</span></td>
                    <td>
                        <button class="btn btn-small btn-primary">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-small btn-success">
                            <i class="fas fa-print"></i>
                        </button>
                    </td>
                </tr>
                <tr>
                    <td>25/07/2025 10:15</td>
                    <td>Orphé MYENE</td>
                    <td style="color: var(--success-color);"><strong>850,000 FCFA</strong></td>
                    <td>Mobile Money</td>
                    <td>MM-2025-078</td>
                    <td>Paiement complet</td>
                    <td>ADMIN01</td>
                    <td><span class="status-badge status-paid">Validé</span></td>
                    <td>
                        <button class="btn btn-small btn-primary">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-small btn-success">
                            <i class="fas fa-print"></i>
                        </button>
                    </td>
                </tr>
                <tr>
                    <td>20/07/2025 16:45</td>
                    <td>Evans BOUTITOU</td>
                    <td style="color: var(--success-color);"><strong>300,000 FCFA</strong></td>
                    <td>Espèces</td>
                    <td>-</td>
                    <td>Échéance 1</td>
                    <td>ADMIN01</td>
                    <td><span class="status-badge status-paid">Validé</span></td>
                    <td>
                        <button class="btn btn-small btn-primary">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-small btn-success">
                            <i class="fas fa-print"></i>
                        </button>
                    </td>
                </tr>
            `;
        }

        function loadReports() {
            // Les rapports sont déjà chargés statiquement
            console.log('Rapports chargés');
        }

        // Gestionnaire pour la sélection/désélection de tous les étudiants
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('select-all');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    const studentCheckboxes = document.querySelectorAll('#students-content input[type="checkbox"]:not(#select-all)');
                    studentCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
        });

        // Actualisation automatique des statistiques
        function refreshStats() {
            // Simulation d'actualisation des données
            console.log('Actualisation des statistiques...');
        }

        // Actualiser les stats toutes les 30 secondes
        setInterval(refreshStats, 30000);

        // Animation d'entrée pour les cartes
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html> 