<?php
// statuer_rapport.php
session_start();
// Assurez-vous que votre fichier config.php gère bien la connexion PDO et isLoggedIn()
// et idéalement le rôle de l'utilisateur.
require_once 'config.php';

header('Content-Type: text/html; charset=UTF-8'); // Définit l'en-tête pour le HTML

// Fonction utilitaire pour la connexion PDO si elle n'est pas dans config.php
if (!function_exists('getPDO')) {
    function getPDO() {
        static $pdo = null;
        if ($pdo === null) {
            $host = '127.0.0.1:3306';
            $db   = 'sygecos';
            $user = 'root';
            $pass = '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                $pdo = new PDO($dsn, $user, $pass, $options);
            } catch (\PDOException $e) {
                error_log("Erreur de connexion à la base de données: " . $e->getMessage());
                die("Impossible de se connecter à la base de données.");
            }
        }
        return $pdo;
    }
}

// Fonction utilitaire pour isLoggedIn() et redirect() si non incluses via config.php
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']); // Adapte ceci à ta logique de session
    }
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit;
    }
}

// Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    redirect('loginForm.php'); // Redirige vers la page de connexion si non connecté
}

// Récupérer l'ID de l'utilisateur connecté (qui est censé être un enseignant membre de la commission)
$loggedInUserId = $_SESSION['user_id'] ?? null;
$fk_id_ens = null;

if ($loggedInUserId) {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT id_ens FROM enseignant WHERE fk_id_util = :user_id");
        $stmt->bindParam(':user_id', $loggedInUserId);
        $stmt->execute();
        $enseignantInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($enseignantInfo) {
            $fk_id_ens = $enseignantInfo['id_ens'];
        } else {
            error_log("L'utilisateur ID " . $loggedInUserId . " n'est pas associé à un enseignant.");
        }
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération de l'ID de l'enseignant: " . $e->getMessage());
    }
} else {
    redirect('loginForm.php');
}


// --- Logique de traitement des requêtes AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $pdo = getPDO();
        $action = $_POST['action'];

        switch ($action) {
            case 'get_pending_reports':
                $sql = "
                    SELECT
                        r.id_rapport,
                        r.theme_rapport,
                        r.date_creation AS date_depot,
                        CONCAT(e.nom_etu, ' ', e.prenoms_etu) AS nom_complet_etudiant
                    FROM rapports r
                    JOIN etudiant e ON r.fk_num_etu = e.num_etu
                    WHERE r.statut = 'soumis'
                    ORDER BY r.date_creation ASC
                ";
                $stmt = $pdo->query($sql);
                $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $reports]);
                break;

            case 'get_report_details':
                $rapportId = filter_var($_POST['rapport_id'], FILTER_VALIDATE_INT);
                if (!$rapportId) {
                    throw new Exception("ID du rapport invalide.");
                }

                $sql = "
                    SELECT
                        r.id_rapport,
                        r.theme_rapport,
                        r.entreprise,
                        r.periode_stage,
                        r.nom_encadrant,
                        r.date_creation AS date_depot,
                        r.statut,
                        r.contenu_rapport,
                        CONCAT(e.prenoms_etu, ' ', e.nom_etu) AS nom_etudiant,
                        e.num_etu,
                        ne.lib_niv_etu AS niveau_etude,
                        f.lib_filiere AS filiere
                    FROM rapports r
                    JOIN etudiant e ON r.fk_num_etu = e.num_etu
                    LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                    LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                    WHERE r.id_rapport = :rapport_id AND r.statut = 'soumis'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':rapport_id', $rapportId, PDO::PARAM_INT);
                $stmt->execute();
                $reportDetails = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$reportDetails) {
                    throw new Exception("Rapport introuvable ou non en attente de validation.");
                }
                echo json_encode(['success' => true, 'data' => $reportDetails]);
                break;

            case 'save_decision':
                if (!$fk_id_ens) {
                    echo json_encode(['success' => false, 'message' => "ID de l'enseignant non trouvé. Impossible d'enregistrer la décision."]);
                    exit;
                }

                $rapportId = filter_var($_POST['id_rapport'], FILTER_VALIDATE_INT);
                $decision = $_POST['decision'] ?? '';
                $commentaire = $_POST['commentaire'] ?? '';
                $score = filter_var($_POST['score'], FILTER_VALIDATE_INT);

                if (!$rapportId || !in_array($decision, ['ACCEPTER', 'A_CORRIGER', 'REJETER'])) {
                    throw new Exception("Données de décision invalides.");
                }

                $pdo->beginTransaction();

                $newStatut = '';
                switch ($decision) {
                    case 'ACCEPTER':
                        $newStatut = 'approuve';
                        break;
                    case 'A_CORRIGER':
                        $newStatut = 'brouillon';
                        break;
                    case 'REJETER':
                        $newStatut = 'rejete';
                        break;
                }

                $stmt = $pdo->prepare("UPDATE rapports SET statut = :statut, date_modification = NOW() WHERE id_rapport = :id_rapport AND statut = 'soumis'");
                $stmt->bindParam(':statut', $newStatut);
                $stmt->bindParam(':id_rapport', $rapportId, PDO::PARAM_INT);
                $stmt->execute();

                if ($stmt->rowCount() === 0) {
                    throw new Exception("Le statut du rapport n'a pas pu être mis à jour. Il n'est peut-être plus en attente de validation.");
                }

                $stmt = $pdo->query("SELECT MAX(id_validation) FROM valider");
                $nextIdValidation = ($stmt->fetchColumn() ?: 0) + 1;

                $stmt = $pdo->prepare("
                    INSERT INTO valider (id_validation, fk_id_ens, fk_id_rapport, dte_val, com_val)
                    VALUES (:id_validation, :fk_id_ens, :fk_id_rapport, CURDATE(), :com_val)
                ");
                $stmt->bindParam(':id_validation', $nextIdValidation, PDO::PARAM_INT);
                $stmt->bindParam(':fk_id_ens', $fk_id_ens, PDO::PARAM_INT);
                $stmt->bindParam(':fk_id_rapport', $rapportId, PDO::PARAM_INT);
                $stmt->bindParam(':com_val', $commentaire);
                $stmt->execute();

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Décision enregistrée avec succès. Statut du rapport mis à jour: ' . $newStatut]);
                break;

            default:
                throw new Exception("Action non reconnue.");
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erreur dans statuer_rapport.php (AJAX): " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Statuer sur un rapport</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-50: #f8fafc; --primary-100: #f1f5f9; --primary-200: #e2e8f0; --primary-300: #cbd5e1; --primary-400: #94a3b8; --primary-500: #64748b; --primary-600: #475569; --primary-700: #334155; --primary-800: #1e293b; --primary-900: #0f172a;
            --accent-50: #eff6ff; --accent-100: #dbeafe; --accent-200: #bfdbfe; --accent-300: #93c5fd; --accent-400: #60a5fa; --accent-500: #3b82f6; --accent-600: #2563eb; --accent-700: #1d4ed8; --accent-800: #1e40af; --accent-900: #1e3a8a;
            --success-500: #22c55e; --success-600: #16a34a; --warning-500: #f59e0b; --error-500: #ef4444; --info-500: #3b82f6;
            --white: #ffffff; --gray-50: #f9fafb; --gray-100: #f3f4f6; --gray-200: #e5e7eb; --gray-300: #d1d5db; --gray-400: #9ca3af; --gray-500: #6b7280; --gray-600: #4b5563; --gray-700: #374151; --gray-800: #1f2937; --gray-900: #111827;
            --sidebar-width: 280px; --sidebar-collapsed-width: 80px; --topbar-height: 70px;
            --font-primary: 'Segoe UI', system-ui, -apple-system, sans-serif;
            --text-xs: 0.75rem; --text-sm: 0.875rem; --text-base: 1rem; --text-lg: 1.125rem; --text-xl: 1.25rem; --text-2xl: 1.5rem; --text-3xl: 1.875rem;
            --space-1: 0.25rem; --space-2: 0.5rem; --space-3: 0.75rem; --space-4: 1rem; --space-5: 1.25rem; --space-6: 1.5rem; --space-8: 2rem; --space-10: 2.5rem; --space-12: 3rem; --space-16: 4rem;
            --radius-sm: 0.25rem; --radius-md: 0.5rem; --radius-lg: 0.75rem; --radius-xl: 1rem; --radius-2xl: 1.5rem; --radius-3xl: 2rem;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.05); --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --transition-fast: 150ms ease-in-out; --transition-normal: 250ms ease-in-out; --transition-slow: 350ms ease-in-out;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--font-primary); background-color: var(--gray-50); color: var(--gray-800); overflow-x: hidden; }
        
        /* Layout */
        .admin-layout { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-width); transition: margin-left var(--transition-normal); }
        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed-width); }

        /* Sidebar */
        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-width); height: 100vh; background: linear-gradient(180deg, var(--primary-800) 0%, var(--primary-900) 100%); color: white; z-index: 1000; transition: all var(--transition-normal); overflow-y: auto; overflow-x: hidden; }
        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .sidebar-header { padding: var(--space-6); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-title { font-size: var(--text-xl); font-weight: 700; }
        .sidebar-nav { padding: var(--space-4) 0; }
        .nav-item { margin-bottom: var(--space-2); }
        .nav-link { display: flex; align-items: center; padding: var(--space-3) var(--space-6); color: rgba(255,255,255,0.8); text-decoration: none; transition: all var(--transition-fast); }
        .nav-link:hover, .nav-link.active { background-color: rgba(255,255,255,0.1); color: white; }
        .nav-icon { margin-right: var(--space-3); width: 20px; }

        /* Topbar */
        .topbar { height: var(--topbar-height); background: var(--white); border-bottom: 1px solid var(--gray-200); padding: 0 var(--space-6); display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; }
        .topbar-left { display: flex; align-items: center; gap: var(--space-4); }
        .topbar-right { display: flex; align-items: center; gap: var(--space-4); }
        .user-info { display: flex; align-items: center; gap: var(--space-3); }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--accent-500); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; }

        /* Page Content */
        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--space-2); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); }

        /* Form Styles */
        .form-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-6); }
        .form-card-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-6); border-bottom: 1px solid var(--gray-200); padding-bottom: var(--space-4); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-6); }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: var(--text-sm); font-weight: 500; color: var(--gray-700); margin-bottom: var(--space-2); }
        .form-group input, .form-group select, .form-group textarea { padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); font-size: var(--text-base); color: var(--gray-800); transition: all var(--transition-fast); }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--accent-500); box-shadow: 0 0 0 3px var(--accent-100); }
        .form-group input:disabled { background-color: var(--gray-100); color: var(--gray-500); cursor: not-allowed; }

        /* Buttons */
        .btn { padding: var(--space-3) var(--space-5); border-radius: var(--radius-md); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); text-decoration: none; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover { background-color: var(--gray-300); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover { background-color: var(--success-600); }
        .btn-warning { background-color: var(--warning-500); color: white; }
        .btn-info { background-color: var(--info-500); color: white; }
        .btn-danger { background-color: var(--error-500); color: white; }
        .btn-sm { padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }

        /* Analysis Sections */
        .analysis-section { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); margin-bottom: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); }
        .analysis-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); }
        .analysis-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        
        /* Score */
        .score-container { display: flex; align-items: center; gap: var(--space-4); margin-bottom: var(--space-4); }
        .score-circle { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: var(--text-xl); color: white; }
        .score-circle.high { background: var(--success-500); }
        .score-circle.medium { background: var(--warning-500); }
        .score-circle.low { background: var(--error-500); }
        .score-info { flex: 1; }
        .score-label { font-weight: 600; margin-bottom: var(--space-1); }
        .score-description { color: var(--gray-600); font-size: var(--text-sm); }
        
        /* Criteria */
        .criteria-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--space-4); margin-bottom: var(--space-4); }
        .criteria-item { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); background: var(--gray-50); border-radius: var(--radius-md); }
        .criteria-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .criteria-icon.valid { background: var(--success-500); color: white; }
        .criteria-icon.invalid { background: var(--error-500); color: white; }
        .criteria-icon.medium { background: var(--warning-500); color: white; }
        .criteria-label { flex: 1; }
        .criteria-value { font-weight: 600; }
        
        /* Plagiarism */
        .plagiarism-check { margin-top: var(--space-6); padding-top: var(--space-4); border-top: 1px solid var(--gray-200); }
        .plagiarism-result { display: flex; align-items: center; gap: var(--space-3); margin-bottom: var(--space-3); }
        .plagiarism-percent { font-weight: 700; }
        .plagiarism-bar { flex: 1; height: 8px; background: var(--gray-200); border-radius: var(--radius-md); overflow: hidden; }
        .plagiarism-progress { height: 100%; }
        .plagiarism-progress.low { background: var(--success-500); }
        .plagiarism-progress.medium { background: var(--warning-500); }
        .plagiarism-progress.high { background: var(--error-500); }
        
        /* Decision */
        .decision-section { background: var(--gray-50); border-radius: var(--radius-lg); padding: var(--space-6); }
        .decision-options { display: flex; gap: var(--space-4); margin-bottom: var(--space-4); }
        .decision-option { flex: 1; text-align: center; }
        .decision-btn { width: 100%; padding: var(--space-4); border-radius: var(--radius-md); border: 2px solid transparent; background: var(--white); cursor: pointer; transition: all var(--transition-fast); display: block; }
        .decision-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }
        .decision-btn.selected { border-color: var(--accent-500); }
        .decision-btn.accepter { color: var(--success-500); }
        .decision-btn.rejeter { color: var(--error-500); }
        .decision-btn.corriger { color: var(--warning-500); }
        .decision-icon { font-size: var(--text-2xl); margin-bottom: var(--space-2); }
        
        /* Hidden elements */
        .hidden { display: none; }

        /* Alerts */
        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); }
        .alert.success { background-color: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }
        .alert.warning { background-color: #fffbeb; color: #92400e; border: 1px solid #fed7aa; }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            display: none; /* Hidden by default */
        }
        .loading-spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--accent-500);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Mobile menu overlay */
        .mobile-menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }
        
        /* === RESPONSIVE === */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
            .sidebar-title,
            .nav-text,
            .nav-section-title {
                opacity: 0;
                pointer-events: none;
            }
            .nav-link {
                justify-content: center;
            }
            /* Adjust topbar toggle icon */
            .sidebar-toggle .fa-bars {
                display: inline-block; /* Show bars icon by default on desktop for toggle */
            }
            .sidebar-toggle .fa-times {
                display: none;
            }
            .sidebar.collapsed {
                width: var(--sidebar-collapsed-width);
            }
        }

        @media (max-width: 768px) {
            .admin-layout {
                position: relative;
            }
            .main-content {
                margin-left: 0;
            }
            
            .sidebar {
                position: fixed;
                left: -100%;
                transition: left var(--transition-normal);
                z-index: 1000;
                height: 100vh;
                overflow-y: auto;
            }
            
            .sidebar.mobile-open {
                left: 0;
            }
            
            .mobile-menu-overlay.active {
                display: block;
            }

            .sidebar-toggle .fa-bars {
                display: inline-block;
            }
            .sidebar-toggle .fa-times {
                display: none;
            }
            /* When sidebar is open on mobile, show times */
            .sidebar.mobile-open + .topbar .sidebar-toggle .fa-bars {
                display: none;
            }
            .sidebar.mobile-open + .topbar .sidebar-toggle .fa-times {
                display: inline-block;
            }

            .stats-grid { grid-template-columns: 1fr; }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-4);
            }
            
            .table-actions {
                width: 100%;
                justify-content: flex-start; /* Adjust to flex-start for stacking */
                flex-wrap: wrap; /* Allow wrapping */
                margin-top: var(--space-4); /* Add some space if it wraps */
            }
            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .download-buttons {
                width: 100%;
                justify-content: flex-end;
            }

            /* Hide action text on smaller screens for action buttons */
            .action-text {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: var(--space-4);
            }
            
            .table-container {
                padding: var(--space-4);
            }
            
            .page-title-main {
                font-size: var(--text-2xl);
            }
            
            .page-subtitle {
                font-size: var(--text-base);
            }
            
            .table-actions {
                flex-wrap: wrap;
                gap: var(--space-2);
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_commision.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Statuer sur un rapport</h1>
                    <p class="page-subtitle">Analyse et validation des rapports de stage</p>
                </div>

                <div id="alertContainer"></div>

                <div class="form-card">
                    <h3 class="form-card-title">Sélectionner un rapport</h3>
                    <div class="form-group">
                        <label for="rapport_select">Rapports en attente de validation</label>
                        <select id="rapport_select" class="form-control">
                            <option value="">-- Sélectionner un rapport --</option>
                            </select>
                        <p id="noReportsMessage" style="display: none; color: var(--gray-600); margin-top: var(--space-3);">Aucun rapport déposé en attente de validation.</p>
                    </div>
                </div>

                <div id="analysisSection" style="display: none;">
                    <div class="form-card">
                        <h3 class="form-card-title">Informations du rapport</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Étudiant</label>
                                <input type="text" id="etudiant_info" disabled>
                            </div>
                            <div class="form-group">
                                <label>Titre du rapport</label>
                                <input type="text" id="rapport_titre" disabled>
                            </div>
                            <div class="form-group">
                                <label>Date de dépôt</label>
                                <input type="text" id="date_depot" disabled>
                            </div>
                            <div class="form-group">
                                <label>Niveau/Filière</label>
                                <input type="text" id="niveau_filiere" disabled>
                            </div>
                        </div>
                    </div>

                    <div class="analysis-section">
                        <div class="analysis-header">
                            <h3 class="analysis-title">Analyse automatique</h3>
                            <button class="btn btn-info btn-sm" id="refreshAnalysisBtn">
                                <i class="fas fa-sync-alt"></i> Actualiser l'analyse
                            </button>
                        </div>

                        <div class="score-container">
                            <div class="score-circle medium" id="scoreCircle">0%</div>
                            <div class="score-info">
                                <div class="score-label">Score de conformité</div>
                                <div class="score-description">
                                    Le score est calculé en fonction de différents critères de qualité. Un score supérieur à 75% est recommandé pour la validation.
                                </div>
                            </div>
                        </div>

                        <h4 style="margin-bottom: var(--space-3);">Critères d'évaluation</h4>
                        <div class="criteria-grid" id="criteriaGrid">
                            <div class="criteria-item">
                                <div class="criteria-icon valid" id="critere1_icon">
                                    <i class="fas fa-check"></i>
                                </div>
                                <div class="criteria-label" id="critere1_label">Structure du document</div>
                                <div class="criteria-value" id="critere1_value">0%</div>
                            </div>
                            <div class="criteria-item">
                                <div class="criteria-icon valid" id="critere2_icon">
                                    <i class="fas fa-check"></i>
                                </div>
                                <div class="criteria-label" id="critere2_label">Qualité rédactionnelle</div>
                                <div class="criteria-value" id="critere2_value">0%</div>
                            </div>
                            <div class="criteria-item">
                                <div class="criteria-icon medium" id="critere3_icon">
                                    <i class="fas fa-exclamation"></i>
                                </div>
                                <div class="criteria-label" id="critere3_label">Profondeur d'analyse</div>
                                <div class="criteria-value" id="critere3_value">0%</div>
                            </div>
                            <div class="criteria-item">
                                <div class="criteria-icon valid" id="critere4_icon">
                                    <i class="fas fa-check"></i>
                                </div>
                                <div class="criteria-label" id="critere4_label">Respect des consignes</div>
                                <div class="criteria-value" id="critere4_value">0%</div>
                            </div>
                        </div>

                        <div class="plagiarism-check">
                            <h4 style="margin-bottom: var(--space-3);">Détection de similarité</h4>
                            <div class="plagiarism-result">
                                <div class="plagiarism-percent" id="plagiarism_percent">0%</div>
                                <div class="plagiarism-bar">
                                    <div class="plagiarism-progress low" id="plagiarism_progress" style="width: 0%;"></div>
                                </div>
                            </div>
                            <p style="font-size: var(--text-sm); color: var(--gray-600);">
                                <i class="fas fa-info-circle"></i> Pourcentage de similarité avec d'autres documents. Un score inférieur à 20% est recommandé.
                            </p>
                        </div>
                    </div>

                    <form method="POST" id="decisionForm" class="analysis-section">
                        <input type="hidden" name="id_rapport" id="id_rapport">
                        <input type="hidden" name="score" id="score_input" value="0">

                        <h3 class="analysis-title">Décision finale</h3>
                        
                        <div class="decision-options">
                            <div class="decision-option">
                                <input type="radio" name="decision" id="decision_accepter" value="ACCEPTER" class="hidden">
                                <label for="decision_accepter" class="decision-btn accepter">
                                    <div class="decision-icon"><i class="fas fa-check-circle"></i></div>
                                    <div>Accepter</div>
                                </label>
                            </div>
                            <div class="decision-option">
                                <input type="radio" name="decision" id="decision_corriger" value="A_CORRIGER" class="hidden">
                                <label for="decision_corriger" class="decision-btn corriger">
                                    <div class="decision-icon"><i class="fas fa-edit"></i></div>
                                    <div>À corriger</div>
                                </label>
                            </div>
                            <div class="decision-option">
                                <input type="radio" name="decision" id="decision_rejeter" value="REJETER" class="hidden">
                                <label for="decision_rejeter" class="decision-btn rejeter">
                                    <div class="decision-icon"><i class="fas fa-times-circle"></i></div>
                                    <div>Rejeter</div>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="commentaire">Commentaire (optionnel)</label>
                            <textarea id="commentaire" name="commentaire" rows="4" class="form-control" placeholder="Ajoutez un commentaire pour justifier votre décision..."></textarea>
                        </div>

                        <div style="display: flex; justify-content: flex-end; margin-top: var(--space-4);">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check"></i> Enregistrer la décision
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>
    <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>


    <script>
        // Variables globales
        let currentRapport = null;

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Charger les rapports en attente au chargement de la page
            chargerRapportsEnAttente();

            // Écouteur pour la sélection d'un rapport
            document.getElementById('rapport_select').addEventListener('change', function() {
                const rapportId = parseInt(this.value);
                if (rapportId) {
                    chargerDetailsRapport(rapportId);
                } else {
                    document.getElementById('analysisSection').style.display = 'none';
                    resetFormAndDisplay();
                }
            });

            // Écouteur pour le bouton d'actualisation de l'analyse
            document.getElementById('refreshAnalysisBtn').addEventListener('click', function() {
                if (currentRapport) {
                    simulerChargement(() => {
                        analyserRapport(currentRapport.id_rapport);
                        showAlert('Analyse actualisée avec succès', 'success');
                    });
                } else {
                    showAlert('Veuillez sélectionner un rapport pour actualiser l\'analyse.', 'info');
                }
            });

            // Écouteurs pour les boutons de décision
            document.querySelectorAll('.decision-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.decision-btn').forEach(b => b.classList.remove('selected'));
                    this.classList.add('selected');
                    document.querySelector(`#${this.getAttribute('for')}`).checked = true;
                });
            });

            // Écouteur pour le formulaire de décision
            document.getElementById('decisionForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                if (!validerFormulaire()) {
                    return;
                }
                
                showLoading(true);

                const formData = new FormData(this);
                formData.append('action', 'save_decision');

                try {
                    // Les requêtes AJAX pointent vers le même fichier
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: new URLSearchParams(formData), // Utilisation de URLSearchParams pour x-www-form-urlencoded
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        }
                    });
                    const result = await response.json();

                    if (result.success) {
                        showAlert(result.message, 'success');
                        resetFormAndDisplay();
                        chargerRapportsEnAttente(); // Recharger la liste des rapports
                    } else {
                        showAlert(result.message, 'error');
                    }
                } catch (error) {
                    console.error('Erreur lors de l\'enregistrement de la décision:', error);
                    showAlert('Une erreur est survenue lors de l\'enregistrement de la décision.', 'error');
                } finally {
                    showLoading(false);
                }
            });

            // Initialiser le comportement responsive de la sidebar
            initSidebar();
        });

        function showLoading(show) {
            document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
        }

        // Fonction utilitaire pour les appels AJAX (pointe vers le même fichier)
        async function makeAjaxRequest(data) {
            try {
                showLoading(true);
                const response = await fetch(window.location.href, { // Pointe vers le fichier actuel
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(data)
                });
                const result = await response.json();
                return result;
            } catch (error) {
                console.error('Erreur AJAX:', error);
                showAlert('Une erreur de communication est survenue.', 'error');
                throw error;
            } finally {
                showLoading(false);
            }
        }

        // Charger les rapports en attente de validation dans le select
        async function chargerRapportsEnAttente() {
            const rapportSelect = document.getElementById('rapport_select');
            rapportSelect.innerHTML = '<option value="">-- Sélectionner un rapport --</option>'; // Réinitialiser
            const noReportsMessage = document.getElementById('noReportsMessage');

            try {
                const result = await makeAjaxRequest({ action: 'get_pending_reports' });
                if (result.success && result.data.length > 0) {
                    result.data.forEach(rapport => {
                        const option = document.createElement('option');
                        option.value = rapport.id_rapport;
                        option.textContent = `${rapport.nom_complet_etudiant} - ${rapport.theme_rapport} (${formatDate(rapport.date_depot)})`;
                        rapportSelect.appendChild(option);
                    });
                    rapportSelect.style.display = 'block'; // S'assurer que le select est visible
                    noReportsMessage.style.display = 'none'; // Masquer le message d'absence de rapports
                } else {
                    // Afficher le message "Aucun rapport déposé"
                    rapportSelect.style.display = 'none'; // Masquer le select
                    noReportsMessage.style.display = 'block'; // Afficher le message
                    showAlert(result.message || 'Aucun rapport en attente de validation.', 'info'); // Afficher une alerte temporaire
                }
            } catch (error) {
                // Erreur déjà gérée par makeAjaxRequest
                rapportSelect.style.display = 'none'; // Masquer le select en cas d'erreur aussi
                noReportsMessage.style.display = 'block'; // Afficher le message d'absence
            }
        }

        // Charger les détails d'un rapport sélectionné
        async function chargerDetailsRapport(rapportId) {
            try {
                const result = await makeAjaxRequest({ action: 'get_report_details', rapport_id: rapportId });
                if (result.success && result.data) {
                    currentRapport = result.data;

                    // Afficher les informations de base
                    document.getElementById('etudiant_info').value = `${currentRapport.nom_etudiant} (${currentRapport.num_etu})`;
                    document.getElementById('rapport_titre').value = currentRapport.theme_rapport;
                    document.getElementById('date_depot').value = formatDate(currentRapport.date_depot);
                    document.getElementById('niveau_filiere').value = `${currentRapport.niveau_etude || 'N/A'} - ${currentRapport.filiere || 'N/A'}`;
                    document.getElementById('id_rapport').value = currentRapport.id_rapport;

                    // Afficher la section d'analyse
                    document.getElementById('analysisSection').style.display = 'block';

                    // Lancer l'analyse automatique (simulation)
                    analyserRapport(rapportId);

                    // Réinitialiser les boutons de décision
                    document.querySelectorAll('.decision-btn').forEach(b => b.classList.remove('selected'));
                    document.querySelectorAll('input[name="decision"]').forEach(input => input.checked = false);
                    document.getElementById('commentaire').value = '';

                } else {
                    showAlert(result.message || 'Impossible de charger les détails du rapport.', 'error');
                    resetFormAndDisplay();
                }
            } catch (error) {
                resetFormAndDisplay();
            }
        }

        // Analyser un rapport (simulation avec données variées)
        function analyserRapport(rapportId) {
            // Données d'analyse différentes selon l'ID du rapport (simulation)
            const seed = rapportId * 13 % 100; // Simple seed for variety

            const criteres = [
                Math.min(95, 60 + seed + (rapportId % 5) * 5), // Structure
                Math.min(95, 55 + seed + (rapportId % 7) * 4), // Qualité rédactionnelle
                Math.min(95, 50 + seed + (rapportId % 3) * 6), // Profondeur d'analyse
                Math.min(95, 60 + seed + (rapportId % 4) * 3)  // Respect des consignes
            ];
            
            const plagiatPercent = Math.min(40, 5 + (rapportId * 7) % 30);

            const scoreGlobal = Math.round(criteres.reduce((a, b) => a + b, 0) / criteres.length);

            // Mettre à jour l'interface
            updateCritere(1, criteres[0], 'Structure du document');
            updateCritere(2, criteres[1], 'Qualité rédactionnelle');
            updateCritere(3, criteres[2], 'Profondeur d'analyse');
            updateCritere(4, criteres[3], 'Respect des consignes');
            updateScoreGlobal(scoreGlobal);
            updatePlagiat(plagiatPercent);

            // Enregistrer le score pour le formulaire
            document.getElementById('score_input').value = scoreGlobal;
        }

        // Mettre à jour l'affichage d'un critère
        function updateCritere(index, value, label) {
            const icon = document.getElementById(`critere${index}_icon`);
            const valueEl = document.getElementById(`critere${index}_value`);
            const labelEl = document.getElementById(`critere${index}_label`);
            
            valueEl.textContent = `${value}%`;
            labelEl.textContent = label;
            
            if (value >= 75) {
                icon.className = 'criteria-icon valid';
                icon.innerHTML = '<i class="fas fa-check"></i>';
            } else if (value >= 60) {
                icon.className = 'criteria-icon medium';
                icon.innerHTML = '<i class="fas fa-exclamation"></i>';
            } else {
                icon.className = 'criteria-icon invalid';
                icon.innerHTML = '<i class="fas fa-times"></i>';
            }
        }

        // Mettre à jour le score global
        function updateScoreGlobal(score) {
            const scoreCircle = document.getElementById('scoreCircle');
            
            scoreCircle.textContent = `${score}%`;
            
            if (score >= 80) {
                scoreCircle.className = 'score-circle high';
            } else if (score >= 65) {
                scoreCircle.className = 'score-circle medium';
            } else {
                scoreCircle.className = 'score-circle low';
            }
        }

        // Mettre à jour le pourcentage de plagiat
        function updatePlagiat(percent) {
            const percentEl = document.getElementById('plagiarism_percent');
            const progress = document.getElementById('plagiarism_progress');
            
            percentEl.textContent = `${percent}%`;
            progress.style.width = `${percent}%`;
            
            if (percent < 15) {
                progress.className = 'plagiarism-progress low';
            } else if (percent < 25) {
                progress.className = 'plagiarism-progress medium';
            } else {
                progress.className = 'plagiarism-progress high';
            }
        }

        // Afficher une alerte
        function showAlert(message, type) {
            const alertContainer = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = `alert ${type}`;
            alert.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span><i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i> ${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; font-size: 1.2em; cursor: pointer; color: inherit;">&times;</button>
                </div>
            `;
            
            alertContainer.appendChild(alert);
            
            // Auto-remove après 5 secondes
            setTimeout(() => {
                if (alert.parentElement) {
                    alert.remove();
                }
            }, 5000);
        }

        // Simulation d'animations de chargement pour le bouton d'actualisation
        function simulerChargement(callback) {
            const button = document.getElementById('refreshAnalysisBtn');
            const originalText = button.innerHTML;
            
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyse en cours...';
            button.disabled = true;
            
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
                if (callback) callback();
            }, 1500);
        }

        // Réinitialiser le formulaire et masquer la section d'analyse
        function resetFormAndDisplay() {
            currentRapport = null;
            document.getElementById('etudiant_info').value = '';
            document.getElementById('rapport_titre').value = '';
            document.getElementById('date_depot').value = '';
            document.getElementById('niveau_filiere').value = '';
            document.getElementById('id_rapport').value = '';
            document.getElementById('score_input').value = '0';

            updateScoreGlobal(0);
            updatePlagiat(0);
            updateCritere(1, 0, 'Structure du document');
            updateCritere(2, 0, 'Qualité rédactionnelle');
            updateCritere(3, 0, 'Profondeur d\'analyse');
            updateCritere(4, 0, 'Respect des consignes');

            document.getElementById('analysisSection').style.display = 'none';
            document.querySelectorAll('.decision-btn').forEach(b => b.classList.remove('selected'));
            document.querySelectorAll('input[name="decision"]').forEach(input => input.checked = false);
            document.getElementById('commentaire').value = '';
            document.getElementById('rapport_select').value = ''; // Réinitialiser la sélection
        }

        // Validation du formulaire améliorée
        function validerFormulaire() {
            const decision = document.querySelector('input[name="decision"]:checked');
            if (!decision) {
                showAlert('Veuillez sélectionner une décision avant de continuer', 'error');
                return false;
            }

            const commentaire = document.getElementById('commentaire').value.trim();
            if ((decision.value === 'REJETER' || decision.value === 'A_CORRIGER') && commentaire === '') {
                showAlert('Un commentaire est requis pour justifier le rejet ou les corrections.', 'warning');
                return false;
            }

            return true;
        }

        // Fonctions utilitaires
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('fr-FR');
        }

        // Gestion des raccourcis clavier
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey) {
                switch(e.key) {
                    case '1':
                        e.preventDefault();
                        document.getElementById('decision_accepter').click();
                        break;
                    case '2':
                        e.preventDefault();
                        document.getElementById('decision_corriger').click();
                        break;
                    case '3':
                        e.preventDefault();
                        document.getElementById('decision_rejeter').click();
                        break;
                    case 'Enter':
                        e.preventDefault();
                        // Déclencher la soumission du formulaire seulement si un rapport est sélectionné et que le formulaire est valide
                        if (currentRapport && validerFormulaire()) {
                             document.getElementById('decisionForm').dispatchEvent(new Event('submit'));
                        } else if (!currentRapport) {
                             showAlert('Veuillez sélectionner un rapport avant de valider.', 'info');
                        }
                        break;
                }
            }
        });

        // Ajout d'informations de raccourcis
        document.addEventListener('DOMContentLoaded', function() {
            const footer = document.createElement('div');
            footer.style.cssText = `
                position: fixed;
                bottom: 10px;
                right: 10px;
                background: var(--gray-800);
                color: white;
                padding: 8px 12px;
                border-radius: 6px;
                font-size: 0.8em;
                opacity: 0.7;
                z-index: 1000;
            `;
            footer.innerHTML = 'Raccourcis: Ctrl+1 (Accepter), Ctrl+2 (Corriger), Ctrl+3 (Rejeter), Ctrl+Enter (Valider)';
            document.body.appendChild(footer);

            // Masquer les raccourcis après 10 secondes
            setTimeout(() => {
                footer.style.display = 'none';
            }, 10000);
        });

        // Responsive Sidebar Logic (copiée pour assurer la cohérence)
        function initSidebar() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay'); 

            if (sidebarToggle && sidebar && mainContent && mobileMenuOverlay) {
                handleResponsiveLayout(); // Vérification initiale
                sidebarToggle.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        sidebar.classList.toggle('mobile-open');
                        mobileMenuOverlay.classList.toggle('active');
                        const barsIcon = sidebarToggle.querySelector('.fa-bars');
                        const timesIcon = sidebarToggle.querySelector('.fa-times');
                        if (sidebar.classList.contains('mobile-open')) {
                            if (barsIcon) barsIcon.style.display = 'none';
                            if (timesIcon) timesIcon.style.display = 'inline-block';
                        } else {
                            if (barsIcon) barsIcon.style.display = 'inline-block';
                            if (timesIcon) timesIcon.style.display = 'none';
                        }
                    } else {
                        sidebar.classList.toggle('collapsed');
                        mainContent.classList.toggle('sidebar-collapsed');
                    }
                });
            }
            if (mobileMenuOverlay) {
                mobileMenuOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('mobile-open');
                    mobileMenuOverlay.classList.remove('active');
                    const barsIcon = sidebarToggle.querySelector('.fa-bars');
                    const timesIcon = sidebarToggle.querySelector('.fa-times');
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                });
            }
            window.addEventListener('resize', handleResponsiveLayout); // Réévaluer sur redimensionnement
        }

        function handleResponsiveLayout() {
            const isMobile = window.innerWidth < 768;
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (isMobile) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('sidebar-collapsed');
                sidebar.classList.remove('mobile-open'); // S'assurer qu'il est fermé par défaut
                mobileMenuOverlay.classList.remove('active');
                if (sidebarToggle) {
                    sidebarToggle.querySelector('.fa-bars').style.display = 'inline-block';
                    sidebarToggle.querySelector('.fa-times').style.display = 'none';
                }
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('sidebar-collapsed');
                sidebar.classList.remove('mobile-open');
                mobileMenuOverlay.classList.remove('active');
                if (sidebarToggle) {
                    sidebarToggle.querySelector('.fa-bars').style.display = 'inline-block';
                    sidebarToggle.querySelector('.fa-times').style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>