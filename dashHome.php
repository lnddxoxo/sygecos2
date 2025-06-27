<?php
// main.php
session_start();

// Vérifier que l'utilisateur est connecté
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== TRUE) {
    header('Location: login.php');
    exit;
}

// Récupérer les informations utilisateur
$user_name = $_SESSION['nom_prenom'] ?? 'Utilisateur';
$user_role = $_SESSION['role'] ?? 'Utilisateur';
$user_type = $_SESSION['user_type'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Dashboard</title>
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

        /* === TOPBAR STYLES === */
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

        /* === MAIN DASHBOARD === */
        .dashboard-content {
            padding: var(--space-6);
        }

        .dashboard-header {
            margin-bottom: var(--space-8);
        }

        .dashboard-title {
            font-size: var(--text-3xl);
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: var(--space-2);
        }

        .dashboard-subtitle {
            color: var(--gray-600);
            font-size: var(--text-lg);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            transition: all var(--transition-normal);
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-4);
        }

        .stat-title {
            font-size: var(--text-sm);
            font-weight: 500;
            color: var(--gray-600);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-lg);
            color: white;
        }

        .stat-icon.users {
            background: linear-gradient(135deg, var(--accent-500), var(--accent-600));
        }

        .stat-icon.reports {
            background: linear-gradient(135deg, var(--secondary-500), var(--secondary-600));
        }

        .stat-icon.pending {
            background: linear-gradient(135deg, var(--warning-500), #f97316);
        }

        .stat-icon.completed {
            background: linear-gradient(135deg, var(--success-500), #15803d);
        }

        .stat-value {
            font-size: var(--text-3xl);
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: var(--space-1);
        }

        .stat-change {
            font-size: var(--text-sm);
            font-weight: 500;
        }

        .stat-change.positive {
            color: var(--success-500);
        }

        .stat-change.negative {
            color: var(--error-500);
        }

        .recent-activity {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        /* === RESPONSIVE === */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .main-content.sidebar-collapsed {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M12 14l9-5-9-5-9 5 9 5z'/%3E%3Cpath d='M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z'/%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z'/%3E%3C/svg%3E" alt="SYGECOS">
                </div>
                <span class="sidebar-title">SYGECOS</span>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    <div class="nav-item">
                        <a href="main.php" class="nav-link active">
                            <div class="nav-icon">
                                <i class="fas fa-home"></i>
                            </div>
                            <span class="nav-text">Tableau de bord</span>
                        </a>
                    </div>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Paramètres généraux</div>
                    <div class="nav-item">
                        <a href="gestion_type_util.php" class="nav-link">
                            <div class="nav-icon">
                                <i class="fas fa-user-tag"></i>
                            </div>
                            <span class="nav-text">Types utilisateur</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="gestion_groupes.php" class="nav-link">
                            <div class="nav-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <span class="nav-text">Groupes</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="gestion_utilisateur.php" class="nav-link">
                            <div class="nav-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <span class="nav-text">Utilisateurs</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="gestion_traitements.php" class="nav-link">
                            <div class="nav-icon">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <span class="nav-text">Traitements</span>
                        </a>
                    </div>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Gestion académique</div>
                    <div class="nav-item">
                        <a href="gestion_annee_academique.php" class="nav-link">
                            <div class="nav-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <span class="nav-text">Année académique</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="gestion_ue.php" class="nav-link">
                            <div class="nav-icon">
                                <i class="fas fa-book"></i>
                            </div>
                            <span class="nav-text">UE</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="gestion_ecue.php" class="nav-link">
                            <div class="nav-icon">
                                <i class="fas fa-book-open"></i>
                            </div>
                            <span class="nav-text">ECUE</span>
                        </a>
                    </div>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Gestion Utilisateurs</div>
                    <div class="nav-item">
                        <a href="gestion_utilisateur.php" class="nav-link">
                            <div class="nav-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <span class="nav-text">Créer compte utilisateur</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="reset_password.php" class="nav-link">
                            <div class="nav-icon">
                                <i class="fas fa-key"></i>
                            </div>
                            <span class="nav-text">Réinitialiser mot de passe</span>
                        </a>
                    </div>
                </div>
            </nav>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content" id="mainContent">
            <!-- TOPBAR -->
            <div class="topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle" id="sidebarToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="page-title">Tableau de bord</h1>
                </div>
                <div class="topbar-right">
                    <div class="user-menu">
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
                        </div>
                        <a href="logout.php" style="color: var(--error-500); margin-left: var(--space-3);">
                            <i class="fas fa-sign-out-alt"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- DASHBOARD CONTENT -->
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <h1 class="dashboard-title">Tableau de bord</h1>
                    <p class="dashboard-subtitle">Vue d'ensemble de la plateforme SYGECOS</p>
                </div>

                <!-- STATISTIQUES -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Utilisateurs actifs</div>
                            <div class="stat-icon users">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                        <div class="stat-value">248</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> +12% ce mois
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Rapports déposés</div>
                            <div class="stat-icon reports">
                                <i class="fas fa-file-alt"></i>
                            </div>
                        </div>
                        <div class="stat-value">342</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> +8% ce mois
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">En attente</div>
                            <div class="stat-icon pending">
                                <i class="fas fa-clock"></i>
                            </div>
                        </div>
                        <div class="stat-value">23</div>
                        <div class="stat-change negative">
                            <i class="fas fa-arrow-down"></i> -15% ce mois
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Soutenances validées</div>
                            <div class="stat-icon completed">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                        <div class="stat-value">98</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> +25% ce mois
                        </div>
                    </div>
                </div>

                <!-- ACTIVITÉ RÉCENTE -->
                <div class="recent-activity">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-6);">
                        <h3 style="font-size: var(--text-lg); font-weight: 600; color: var(--gray-900);">Activité récente</h3>
                        <a href="#" style="color: var(--accent-600); text-decoration: none; font-size: var(--text-sm); font-weight: 500;">Voir tout</a>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: var(--space-4);">
                        <div style="display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); border-radius: var(--radius-lg);">
                            <div style="width: 36px; height: 36px; border-radius: var(--radius-lg); background: var(--accent-500); display: flex; align-items: center; justify-content: center; color: white;">
                                <i class="fas fa-upload"></i>
                            </div>
                            <div>
                                <div style="font-size: var(--text-sm); color: var(--gray-800);">
                                    <strong>Jean Dupont</strong> a déposé son rapport de stage
                                </div>
                                <div style="font-size: var(--text-xs); color: var(--gray-500);">Il y a 2 heures</div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); border-radius: var(--radius-lg);">
                            <div style="width: 36px; height: 36px; border-radius: var(--radius-lg); background: var(--success-500); display: flex; align-items: center; justify-content: center; color: white;">
                                <i class="fas fa-check"></i>
                            </div>
                            <div>
                                <div style="font-size: var(--text-sm); color: var(--gray-800);">
                                    <strong>Commission M2</strong> a validé 5 nouveaux rapports
                                </div>
                                <div style="font-size: var(--text-xs); color: var(--gray-500);">Il y a 4 heures</div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); border-radius: var(--radius-lg);">
                            <div style="width: 36px; height: 36px; border-radius: var(--radius-lg); background: var(--warning-500); display: flex; align-items: center; justify-content: center; color: white;">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div>
                                <div style="font-size: var(--text-sm); color: var(--gray-800);">
                                    <strong>Dr. Martin</strong> a envoyé un message à l'étudiant
                                </div>
                                <div style="font-size: var(--text-xs); color: var(--gray-500);">Il y a 6 heures</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Gestion du toggle sidebar
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
            });
        }

        // Navigation corrigée - permettre la navigation normale
        const navLinks = document.querySelectorAll('.nav-link');
        
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                // Ne pas empêcher la navigation normale
                // Juste marquer visuellement le lien comme actif
                
                // Retirer la classe active de tous les liens
                navLinks.forEach(l => l.classList.remove('active'));
                
                // Ajouter la classe active au lien cliqué
                this.classList.add('active');
                
                // Sauvegarder l'état dans localStorage pour la persistance
                const href = this.getAttribute('href');
                if (href) {
                    localStorage.setItem('activeNavLink', href);
                }
            });
        });

        // Restaurer l'état actif après le chargement de la page
        function restoreActiveNav() {
            const currentPage = window.location.pathname.split('/').pop();
            const savedActiveLink = localStorage.getItem('activeNavLink');
            
            navLinks.forEach(link => {
                const linkHref = link.getAttribute('href');
                
                // Vérifier si c'est la page actuelle
                if (linkHref === currentPage || linkHref === savedActiveLink) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            });
        }

        // Restaurer l'état au chargement
        window.addEventListener('load', restoreActiveNav);

        // Responsive: Gestion mobile
        function handleResize() {
            if (window.innerWidth <= 768) {
                if (sidebar) sidebar.classList.add('mobile');
            } else {
                if (sidebar) sidebar.classList.remove('mobile');
            }
        }

        window.addEventListener('resize', handleResize);
        handleResize(); // Initial check

        // Animation des stats au chargement
        function animateStats() {
            const statValues = document.querySelectorAll('.stat-value');
            
            statValues.forEach(stat => {
                const finalValue = parseInt(stat.textContent.replace(/,/g, ''));
                let currentValue = 0;
                const increment = finalValue / 50;
                
                const timer = setInterval(() => {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        stat.textContent = finalValue.toLocaleString();
                        clearInterval(timer);
                    } else {
                        stat.textContent = Math.floor(currentValue).toLocaleString();
                    }
                }, 20);
            });
        }

        // Démarrer l'animation après le chargement de la page
        window.addEventListener('load', () => {
            setTimeout(animateStats, 500);
        });
    </script>
</body>
</html>