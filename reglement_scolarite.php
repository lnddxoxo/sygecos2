<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS CFA - Gestion des Paiements</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* === VARIABLES CSS === */
        :root {
            /* Couleurs Primaires */
            --primary-50: #f8fafc;
            --primary-100: #f1f5f9;
            --primary-200: #e2e8f0;
            --primary-300: #cbd5e1;
            --primary-400: #94a3b8;
            --primary-500: #64748b;
            --primary-600: #475569;
            --primary-700: #334155;
            --primary-800: #1e293b;
            --primary-900: #0f172a;

            /* Couleurs d'Accent Bleu */
            --accent-50: #eff6ff;
            --accent-100: #dbeafe;
            --accent-200: #bfdbfe;
            --accent-300: #93c5fd;
            --accent-400: #60a5fa;
            --accent-500: #3b82f6;
            --accent-600: #2563eb;
            --accent-700: #1d4ed8;
            --accent-800: #1e40af;
            --accent-900: #1e3a8a;

            /* Couleurs Secondaires */
            --secondary-50: #f0fdf4;
            --secondary-100: #dcfce7;
            --secondary-500: #22c55e;
            --secondary-600: #16a34a;

            /* Couleurs Sémantiques */
            --success-500: #22c55e;
            --warning-500: #f59e0b;
            --error-500: #ef4444;
            --info-500: #3b82f6;

            /* Couleurs Neutres */
            --white: #ffffff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;

            /* Layout */
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --topbar-height: 70px;

            /* Typographie */
            --font-primary: 'Segoe UI', system-ui, -apple-system, sans-serif;
            --text-xs: 0.75rem;
            --text-sm: 0.875rem;
            --text-base: 1rem;
            --text-lg: 1.125rem;
            --text-xl: 1.25rem;
            --text-2xl: 1.5rem;
            --text-3xl: 1.875rem;

            /* Espacement */
            --space-1: 0.25rem;
            --space-2: 0.5rem;
            --space-3: 0.75rem;
            --space-4: 1rem;
            --space-5: 1.25rem;
            --space-6: 1.5rem;
            --space-8: 2rem;
            --space-10: 2.5rem;
            --space-12: 3rem;
            --space-16: 4rem;

            /* Bordures */
            --radius-sm: 0.25rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
            --radius-3xl: 2rem;

            /* Ombres */
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);

            /* Transitions */
            --transition-fast: 150ms ease-in-out;
            --transition-normal: 250ms ease-in-out;
            --transition-slow: 350ms ease-in-out;
        }

        /* === RESET === */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-primary);
            background-color: var(--gray-50);
            color: var(--gray-800);
            overflow-x: hidden;
        }

        /* === LAYOUT PRINCIPAL === */
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition-normal);
        }

        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* === SIDEBAR === */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-800) 0%, var(--primary-900) 100%);
            color: white;
            z-index: 1000;
            transition: all var(--transition-normal);
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: var(--primary-900);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: var(--primary-600);
            border-radius: 2px;
        }

        .sidebar-header {
            padding: var(--space-6) var(--space-6);
            border-bottom: 1px solid var(--primary-700);
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }

        .sidebar-logo {
            width: 40px;
            height: 40px;
            background: var(--accent-500);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar-logo img {
            width: 28px;
            height: 28px;
            object-fit: contain;
            filter: brightness(0) invert(1);
        }

        .sidebar-title {
            font-size: var(--text-xl);
            font-weight: 700;
            white-space: nowrap;
            opacity: 1;
            transition: opacity var(--transition-normal);
        }

        .sidebar.collapsed .sidebar-title {
            opacity: 0;
        }

        .sidebar-nav {
            padding: var(--space-4) 0;
        }

        .nav-section {
            margin-bottom: var(--space-6);
        }

        .nav-section-title {
            padding: var(--space-2) var(--space-6);
            font-size: var(--text-xs);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--primary-400);
            white-space: nowrap;
            opacity: 1;
            transition: opacity var(--transition-normal);
        }

        .sidebar.collapsed .nav-section-title {
            opacity: 0;
        }

        .nav-item {
            margin-bottom: var(--space-1);
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: var(--space-3) var(--space-6);
            color: var(--primary-200);
            text-decoration: none;
            transition: all var(--transition-fast);
            position: relative;
            gap: var(--space-3);
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-link.active {
            background: var(--accent-600);
            color: white;
        }

        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--accent-300);
        }

        .nav-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .nav-text {
            white-space: nowrap;
            opacity: 1;
            transition: opacity var(--transition-normal);
        }

        .sidebar.collapsed .nav-text {
            opacity: 0;
        }

        .nav-submenu {
            margin-left: var(--space-8);
            margin-top: var(--space-2);
            border-left: 2px solid var(--primary-700);
            padding-left: var(--space-4);
        }

        .sidebar.collapsed .nav-submenu {
            display: none;
        }

        .nav-submenu .nav-link {
            padding: var(--space-2) var(--space-4);
            font-size: var(--text-sm);
        }

        /* === TOPBAR === */
        .topbar {
            height: var(--topbar-height);
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
            padding: 0 var(--space-6);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }

        .sidebar-toggle {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--gray-100);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            color: var(--gray-600);
        }

        .sidebar-toggle:hover {
            background: var(--gray-200);
            color: var(--gray-800);
        }

        .page-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-800);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }

        .topbar-button {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--gray-100);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            color: var(--gray-600);
            position: relative;
        }

        .topbar-button:hover {
            background: var(--gray-200);
            color: var(--gray-800);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 18px;
            height: 18px;
            background: var(--error-500);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 600;
            color: white;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: background var(--transition-fast);
        }

        .user-menu:hover {
            background: var(--gray-100);
        }

        .user-info {
            text-align: right;
        }

        .user-name {
            font-size: var(--text-sm);
            font-weight: 600;
            color: var(--gray-800);
            line-height: 1.2;
        }

        .user-role {
            font-size: var(--text-xs);
            color: var(--gray-500);
        }

        /* === PAGE SPECIFIC STYLES === */
        .page-content {
            padding: var(--space-6);
        }

        .page-header {
            margin-bottom: var(--space-8);
        }

        .page-title-main {
            font-size: var(--text-3xl);
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: var(--space-2);
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: var(--text-lg);
        }

        .form-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: var(--space-8);
        }

        .form-card-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: var(--space-6);
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: var(--space-4);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-6);
            margin-bottom: var(--space-6);
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: var(--text-sm);
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: var(--space-2);
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="number"],
        .form-group input[type="date"],
        .form-group select,
        .form-group textarea {
            padding: var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            color: var(--gray-800);
            transition: all var(--transition-fast);
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="email"]:focus,
        .form-group input[type="tel"]:focus,
        .form-group input[type="number"]:focus,
        .form-group input[type="date"]:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .form-actions {
            display: flex;
            gap: var(--space-4);
            justify-content: flex-end;
        }

        .btn {
            padding: var(--space-3) var(--space-5);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-primary {
            background-color: var(--accent-600);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background-color: var(--accent-700);
        }

        .btn-secondary {
            background-color: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-secondary:hover:not(:disabled) {
            background-color: var(--gray-300);
        }

        .btn-success {
            background-color: var(--success-500);
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            background-color: var(--success-600);
        }

        .table-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-6);
        }

        .table-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
        }

        .table-actions {
            display: flex;
            gap: var(--space-3);
        }

        .search-container {
            position: relative;
            width: 300px;
        }

        .search-input {
            width: 100%;
            padding: var(--space-3) var(--space-10);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            transition: all var(--transition-fast);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--accent-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .search-icon {
            position: absolute;
            left: var(--space-3);
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--text-sm);
            color: var(--gray-800);
        }

        .data-table th,
        .data-table td {
            padding: var(--space-4);
            border-bottom: 1px solid var(--gray-200);
            text-align: left;
        }

        .data-table th {
            background-color: var(--gray-50);
            font-weight: 600;
            color: var(--gray-700);
            text-transform: uppercase;
            font-size: var(--text-xs);
            letter-spacing: 0.05em;
        }

        .data-table tbody tr:hover {
            background-color: var(--gray-100);
        }

        .action-buttons {
            display: flex;
            gap: var(--space-2);
        }

        .action-button {
            padding: var(--space-2);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            cursor: pointer;
            transition: all var(--transition-fast);
            border: none;
            color: white;
        }

        .action-button.view {
            background-color: var(--info-500);
        }
        .action-button.view:hover {
            background-color: #316be6;
        }

        .action-button.edit {
            background-color: var(--warning-500);
        }
        .action-button.edit:hover {
            background-color: #e68a00;
        }

        .action-button.delete {
            background-color: var(--error-500);
        }
        .action-button.delete:hover {
            background-color: #cc3131;
        }

        /* Badges pour les statuts */
        .badge {
            padding: var(--space-1) var(--space-3);
            border-radius: var(--radius-md);
            font-size: var(--text-xs);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge.success {
            background-color: var(--secondary-100);
            color: var(--secondary-600);
        }

        .badge.warning {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge.danger {
            background-color: #fee2e2;
            color: var(--error-500);
        }

        /* Modal pour affichage des informations */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: var(--space-6);
            border-radius: var(--radius-xl);
            width: 90%;
            max-width: 600px;
            box-shadow: var(--shadow-xl);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-4);
            border-bottom: 1px solid var(--gray-200);
        }

        .modal-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
        }

        .close {
            color: var(--gray-400);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color var(--transition-fast);
        }

        .close:hover {
            color: var(--gray-600);
        }

        .modal-body {
            margin-bottom: var(--space-6);
        }

        .payment-summary {
            background: var(--gray-50);
            border-radius: var(--radius-md);
            padding: var(--space-4);
            margin-bottom: var(--space-4);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: var(--space-2);
        }

        .summary-row.total {
            font-weight: 600;
            border-top: 1px solid var(--gray-200);
            padding-top: var(--space-2);
            margin-top: var(--space-2);
        }

        /* === RESPONSIVE === */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.mobile {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .main-content.sidebar-collapsed {
                margin-left: 0;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-4);
            }

            .search-container {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_respo_scolarité.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Gestion des Paiements</h1>
                    <p class="page-subtitle">Enregistrement et suivi des paiements de scolarité</p>
                </div>

                <!-- Formulaire de recherche d'étudiant -->
                <div class="form-card">
                    <h3 class="form-card-title">Rechercher un étudiant</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="search">Nom, prénom ou matricule</label>
                            <div class="search-container">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" id="search" class="search-input" placeholder="Rechercher un étudiant...">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="annee">Année académique</label>
                            <select id="annee">
                                <option value="">Toutes les années</option>
                                <option value="2023-2024" selected>2023-2024</option>
                                <option value="2022-2023">2022-2023</option>
                                <option value="2021-2022">2021-2022</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="niveau">Niveau</label>
                            <select id="niveau">
                                <option value="">Tous les niveaux</option>
                                <option value="L1">Licence 1</option>
                                <option value="L2">Licence 2</option>
                                <option value="L3">Licence 3</option>
                                <option value="M1">Master 1</option>
                                <option value="M2">Master 2</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" id="searchBtn">
                            <i class="fas fa-search"></i> Rechercher
                        </button>
                    </div>
                </div>

                <!-- Tableau des étudiants avec leurs paiements -->
                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">Liste des étudiants</h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary">
                                <i class="fas fa-download"></i> Exporter
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Matricule</th>
                                    <th>Nom & Prénom</th>
                                    <th>Niveau</th>
                                    <th>Filière</th>
                                    <th>Total à payer</th>
                                    <th>1er Versement</th>
                                    <th>2ème Versement</th>
                                    <th>Reste à payer</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Étudiant avec paiement complet -->
                                <tr>
                                    <td>ETD2023001</td>
                                    <td>Kouamé Jean</td>
                                    <td>L1</td>
                                    <td>Informatique</td>
                                    <td>500 000 FCFA</td>
                                    <td>250 000 FCFA (10/10/2023)</td>
                                    <td>250 000 FCFA (15/11/2023)</td>
                                    <td>0 FCFA</td>
                                    <td><span class="badge success">Soldé</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-button view" title="Voir détails" onclick="openPaymentModal('ETD2023001')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-button edit" title="Modifier" onclick="openEditModal('ETD2023001')">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- Étudiant avec 1er versement seulement -->
                                <tr>
                                    <td>ETD2023002</td>
                                    <td>Yao Aïcha</td>
                                    <td>L2</td>
                                    <td>Comptabilité</td>
                                    <td>450 000 FCFA</td>
                                    <td>225 000 FCFA (12/10/2023)</td>
                                    <td>-</td>
                                    <td>225 000 FCFA</td>
                                    <td><span class="badge warning">En cours</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-button view" title="Voir détails" onclick="openPaymentModal('ETD2023002')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-button edit" title="Modifier" onclick="openEditModal('ETD2023002')">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- Étudiant sans aucun paiement -->
                                <tr>
                                    <td>ETD2023003</td>
                                    <td>Konan Paul</td>
                                    <td>L3</td>
                                    <td>Marketing</td>
                                    <td>400 000 FCFA</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>400 000 FCFA</td>
                                    <td><span class="badge danger">Impayé</span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-button view" title="Voir détails" onclick="openPaymentModal('ETD2023003')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-button edit" title="Enregistrer paiement" onclick="openNewPaymentModal('ETD2023003')">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal pour voir les détails de paiement -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Détails des paiements</h3>
                <span class="close" onclick="closeModal('paymentModal')">&times;</span>
            </div>
            <div class="modal-body">
                <h4>Informations étudiant</h4>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Matricule</label>
                        <input type="text" id="modalMatricule" readonly>
                    </div>
                    <div class="form-group">
                        <label>Nom</label>
                        <input type="text" id="modalNom" readonly>
                    </div>
                    <div class="form-group">
                        <label>Prénom</label>
                        <input type="text" id="modalPrenom" readonly>
                    </div>
                    <div class="form-group">
                        <label>Niveau</label>
                        <input type="text" id="modalNiveau" readonly>
                    </div>
                    <div class="form-group">
                        <label>Filière</label>
                        <input type="text" id="modalFiliere" readonly>
                    </div>
                    <div class="form-group">
                        <label>Année académique</label>
                        <input type="text" id="modalAnnee" readonly>
                    </div>
                </div>
                
                <h4 style="margin-top: 20px;">Historique des paiements</h4>
                <div class="payment-summary">
                    <div class="summary-row">
                        <span>Total à payer:</span>
                        <span id="modalTotal">500 000 FCFA</span>
                    </div>
                    <div class="summary-row">
                        <span>1er versement:</span>
                        <span id="modalVersement1">250 000 FCFA (10/10/2023)</span>
                    </div>
                    <div class="summary-row">
                        <span>2ème versement:</span>
                        <span id="modalVersement2">250 000 FCFA (15/11/2023)</span>
                    </div>
                    <div class="summary-row total">
                        <span>Reste à payer:</span>
                        <span id="modalReste">0 FCFA</span>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('paymentModal')">Fermer</button>
            </div>
        </div>
    </div>

    <!-- Modal pour modifier un paiement existant -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Modifier un paiement</h3>
                <span class="close" onclick="closeModal('editModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="editMatricule">Matricule</label>
                        <input type="text" id="editMatricule" readonly>
                    </div>
                    <div class="form-group">
                        <label for="editNom">Nom & Prénom</label>
                        <input type="text" id="editNom" readonly>
                    </div>
                    <div class="form-group">
                        <label for="editNiveau">Niveau</label>
                        <input type="text" id="editNiveau" readonly>
                    </div>
                    <div class="form-group">
                        <label for="editTotal">Total à payer</label>
                        <input type="text" id="editTotal" readonly>
                    </div>
                    <div class="form-group">
                        <label for="editType">Type de versement</label>
                        <select id="editType">
                            <option value="1">1er versement</option>
                            <option value="2">2ème versement</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editMontant">Montant (FCFA)</label>
                        <input type="number" id="editMontant" placeholder="Entrez le montant">
                    </div>
                    <div class="form-group">
                        <label for="editDate">Date de paiement</label>
                        <input type="date" id="editDate">
                    </div>
                    <div class="form-group">
                        <label for="editMode">Mode de paiement</label>
                        <select id="editMode">
                            <option value="especes">Espèces</option>
                            <option value="cheque">Chèque</option>
                            <option value="virement">Virement</option>
                            <option value="mobile">Mobile Money</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editReference">Référence</label>
                        <input type="text" id="editReference" placeholder="N° chèque ou référence">
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('editModal')">Annuler</button>
                <button class="btn btn-primary" onclick="savePayment()">Enregistrer</button>
            </div>
        </div>
    </div>

    <!-- Modal pour nouveau paiement -->
    <div id="newPaymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Nouveau paiement</h3>
                <span class="close" onclick="closeModal('newPaymentModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="newMatricule">Matricule</label>
                        <input type="text" id="newMatricule" readonly>
                    </div>
                    <div class="form-group">
                        <label for="newNom">Nom & Prénom</label>
                        <input type="text" id="newNom" readonly>
                    </div>
                    <div class="form-group">
                        <label for="newNiveau">Niveau</label>
                        <input type="text" id="newNiveau" readonly>
                    </div>
                    <div class="form-group">
                        <label for="newTotal">Total à payer</label>
                        <input type="text" id="newTotal" readonly>
                    </div>
                    <div class="form-group">
                        <label for="newType">Type de versement</label>
                        <select id="newType">
                            <option value="1">1er versement</option>
                            <option value="2">2ème versement</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="newMontant">Montant (FCFA)</label>
                        <input type="number" id="newMontant" placeholder="Entrez le montant">
                    </div>
                    <div class="form-group">
                        <label for="newDate">Date de paiement</label>
                        <input type="date" id="newDate">
                    </div>
                    <div class="form-group">
                        <label for="newMode">Mode de paiement</label>
                        <select id="newMode">
                            <option value="especes">Espèces</option>
                            <option value="cheque">Chèque</option>
                            <option value="virement">Virement</option>
                            <option value="mobile">Mobile Money</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="newReference">Référence</label>
                        <input type="text" id="newReference" placeholder="N° chèque ou référence">
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('newPaymentModal')">Annuler</button>
                <button class="btn btn-success" onclick="saveNewPayment()">
                    <i class="fas fa-save"></i> Enregistrer le paiement
                </button>
            </div>
        </div>
    </div>

    <script>
        // Fonctions pour gérer les modals
        function openPaymentModal(matricule) {
            // Ici, normalement on ferait une requête AJAX pour récupérer les données de l'étudiant
            // Pour cet exemple, on simule avec des données statiques
            
            let studentData = {};
            if (matricule === 'ETD2023001') {
                studentData = {
                    matricule: 'ETD2023001',
                    nom: 'Kouamé',
                    prenom: 'Jean',
                    niveau: 'L1',
                    filiere: 'Informatique',
                    annee: '2023-2024',
                    total: '500 000 FCFA',
                    versement1: '250 000 FCFA (10/10/2023)',
                    versement2: '250 000 FCFA (15/11/2023)',
                    reste: '0 FCFA'
                };
            } else if (matricule === 'ETD2023002') {
                studentData = {
                    matricule: 'ETD2023002',
                    nom: 'Yao',
                    prenom: 'Aïcha',
                    niveau: 'L2',
                    filiere: 'Comptabilité',
                    annee: '2023-2024',
                    total: '450 000 FCFA',
                    versement1: '225 000 FCFA (12/10/2023)',
                    versement2: '-',
                    reste: '225 000 FCFA'
                };
            } else if (matricule === 'ETD2023003') {
                studentData = {
                    matricule: 'ETD2023003',
                    nom: 'Konan',
                    prenom: 'Paul',
                    niveau: 'L3',
                    filiere: 'Marketing',
                    annee: '2023-2024',
                    total: '400 000 FCFA',
                    versement1: '-',
                    versement2: '-',
                    reste: '400 000 FCFA'
                };
            }
            
            // Remplir les champs du modal
            document.getElementById('modalMatricule').value = studentData.matricule;
            document.getElementById('modalNom').value = studentData.nom;
            document.getElementById('modalPrenom').value = studentData.prenom;
            document.getElementById('modalNiveau').value = studentData.niveau;
            document.getElementById('modalFiliere').value = studentData.filiere;
            document.getElementById('modalAnnee').value = studentData.annee;
            document.getElementById('modalTotal').textContent = studentData.total;
            document.getElementById('modalVersement1').textContent = studentData.versement1;
            document.getElementById('modalVersement2').textContent = studentData.versement2;
            document.getElementById('modalReste').textContent = studentData.reste;
            
            // Afficher le modal
            document.getElementById('paymentModal').style.display = 'block';
        }

        function openEditModal(matricule) {
            // Récupérer les données de l'étudiant (simulé)
            let studentData = {};
            if (matricule === 'ETD2023001') {
                studentData = {
                    matricule: 'ETD2023001',
                    nom: 'Kouamé Jean',
                    niveau: 'L1',
                    total: '500 000 FCFA'
                };
            } else if (matricule === 'ETD2023002') {
                studentData = {
                    matricule: 'ETD2023002',
                    nom: 'Yao Aïcha',
                    niveau: 'L2',
                    total: '450 000 FCFA'
                };
            }
            
            // Remplir les champs du modal
            document.getElementById('editMatricule').value = studentData.matricule;
            document.getElementById('editNom').value = studentData.nom;
            document.getElementById('editNiveau').value = studentData.niveau;
            document.getElementById('editTotal').value = studentData.total;
            
            // Afficher le modal
            document.getElementById('editModal').style.display = 'block';
        }

        function openNewPaymentModal(matricule) {
            // Récupérer les données de l'étudiant (simulé)
            let studentData = {};
            if (matricule === 'ETD2023003') {
                studentData = {
                    matricule: 'ETD2023003',
                    nom: 'Konan Paul',
                    niveau: 'L3',
                    total: '400 000 FCFA'
                };
            }
            
            // Remplir les champs du modal
            document.getElementById('newMatricule').value = studentData.matricule;
            document.getElementById('newNom').value = studentData.nom;
            document.getElementById('newNiveau').value = studentData.niveau;
            document.getElementById('newTotal').value = studentData.total;
            
            // Définir la date d'aujourd'hui par défaut
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('newDate').value = today;
            
            // Afficher le modal
            document.getElementById('newPaymentModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function savePayment() {
            // Ici, on enverrait les données au serveur
            alert('Paiement modifié avec succès!');
            closeModal('editModal');
        }

        function saveNewPayment() {
            // Ici, on enverrait les données au serveur
            alert('Nouveau paiement enregistré avec succès!');
            closeModal('newPaymentModal');
            
            // Actualiser la page pour voir les changements
            setTimeout(() => {
                location.reload();
            }, 1000);
        }

        // Fermer les modals quand on clique en dehors
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }

        // Recherche d'étudiants
        document.getElementById('searchBtn').addEventListener('click', function() {
            const searchTerm = document.getElementById('search').value.toLowerCase();
            const annee = document.getElementById('annee').value;
            const niveau = document.getElementById('niveau').value;
            
            alert(`Recherche effectuée:\nTerme: ${searchTerm}\nAnnée: ${annee}\nNiveau: ${niveau}`);
            
            // En réalité, on ferait une requête AJAX ici pour filtrer les résultats
        });
    </script>
</body>
</html>