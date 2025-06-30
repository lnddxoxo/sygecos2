<?php
require_once 'config.php'; // Your database connection file

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Database connection (use your config.php or directly here as shown in mes_rapports.php)
try {
    $pdo = new PDO('mysql:host=127.0.0.1:3306;dbname=sygecos', 'root', ''); //
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); //
} catch (PDOException $e) {
    error_log("Error connecting to database in attributions.php: " . $e->getMessage()); //
    die("Erreur de connexion à la base de données."); //
}

// AJAX handler for fetching data and handling attributions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) { //
    header('Content-Type: application/json'); //

    try {
        $action = $_POST['action']; //

        if ($action === 'get_reports_for_attribution') { //
            $searchTerm = $_POST['search_term'] ?? ''; //
            $anneeId = intval($_POST['annee_id'] ?? 0); //

            $whereConditions = ["r.statut = 'approuve'"]; // Only validated reports
            $params = []; //

            if ($anneeId > 0) { //
                $whereConditions[] = "r.fk_id_Ac = ?"; //
                $params[] = $anneeId; //
            }

            if (!empty($searchTerm)) { //
                $whereConditions[] = "(r.theme_rapport LIKE ? OR e.nom_etu LIKE ? OR e.prenoms_etu LIKE ?)"; //
                $params[] = "%{$searchTerm}%"; //
                $params[] = "%{$searchTerm}%"; //
                $params[] = "%{$searchTerm}%"; //
            }

            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions); //

            // Fetch reports and their assigned encadrants/directors
            $sql = "
                SELECT
                    r.id_rapport,
                    r.theme_rapport,
                    e.num_etu,
                    CONCAT(e.nom_etu, ' ', e.prenoms_etu) AS etudiant_complet_nom,
                    CONCAT(YEAR(aa.date_deb), '-', YEAR(aa.date_fin)) as annee_academique_libelle,
                    GROUP_CONCAT(DISTINCT CASE WHEN a.type_encadrement = 'pedagogical_supervisor' THEN CONCAT(ens.nom_ens, ' ', ens.prenom_ens) ELSE NULL END SEPARATOR ', ') AS encadrant_pedagogique_nom,
                    GROUP_CONCAT(DISTINCT CASE WHEN a.type_encadrement = 'thesis_director' THEN CONCAT(ens.nom_ens, ' ', ens.prenom_ens) ELSE NULL END SEPARATOR ', ') AS directeur_memoire_nom
                FROM rapports r
                JOIN etudiant e ON r.fk_num_etu = e.num_etu
                JOIN année_academique aa ON r.fk_id_Ac = aa.id_Ac
                LEFT JOIN affecter a ON r.id_rapport = a.fk_id_rapport
                LEFT JOIN enseignant ens ON a.fk_id_ens = ens.id_ens
                {$whereClause}
                GROUP BY r.id_rapport, r.theme_rapport, e.num_etu, etudiant_complet_nom, annee_academique_libelle
                ORDER BY r.date_creation DESC
            "; //

            $stmt = $pdo->prepare($sql); //
            $stmt->execute($params); //
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC); //

            echo json_encode(['success' => true, 'data' => $reports]); //
            exit;

        } elseif ($action === 'get_enseignants') { //
            $stmt = $pdo->query("SELECT id_ens, nom_ens, prenom_ens, email FROM enseignant ORDER BY nom_ens ASC"); //
            $enseignants = $stmt->fetchAll(PDO::FETCH_ASSOC); //
            echo json_encode(['success' => true, 'data' => $enseignants]); //
            exit;

        } elseif ($action === 'save_attribution') { //
            $rapportId = intval($_POST['rapport_id'] ?? 0); //
            $encadrantId = intval($_POST['encadrant_id'] ?? 0); //
            $directeurId = intval($_POST['directeur_id'] ?? 0); //

            if ($rapportId <= 0) { //
                throw new Exception("Veuillez sélectionner un rapport."); //
            }

            // Start transaction for atomicity
            $pdo->beginTransaction(); //

            try {
                // Clear existing pedagogical supervisor and thesis director for this report
                $deleteStmt = $pdo->prepare("
                    DELETE FROM affecter
                    WHERE fk_id_rapport = ? AND type_encadrement IN ('pedagogical_supervisor', 'thesis_director')
                "); //
                $deleteStmt->execute([$rapportId]); //

                // Assign pedagogical supervisor
                if ($encadrantId > 0) { //
                    $insertEncadrantStmt = $pdo->prepare("
                        INSERT INTO affecter (fk_id_ens, fk_id_rapport, type_encadrement)
                        VALUES (?, ?, 'pedagogical_supervisor')
                    "); //
                    $insertEncadrantStmt->execute([$encadrantId, $rapportId]); //
                }

                // Assign thesis director
                if ($directeurId > 0) { //
                    $insertDirecteurStmt = $pdo->prepare("
                        INSERT INTO affecter (fk_id_ens, fk_id_rapport, type_encadrement)
                        VALUES (?, ?, 'thesis_director')
                    "); //
                    $insertDirecteurStmt->execute([$directeurId, $rapportId]); //
                }

                $pdo->commit(); //
                echo json_encode(['success' => true, 'message' => 'Attribution enregistrée avec succès.']); //
                exit;

            } catch (Exception $e) {
                $pdo->rollBack(); //
                throw $e; // Re-throw to be caught by the outer catch block
            }
        } else {
            throw new Exception("Action non reconnue."); //
        }

    } catch (Exception $e) {
        error_log("Error in attributions.php AJAX: " . $e->getMessage()); //
        echo json_encode(['success' => false, 'message' => $e->getMessage()]); //
        exit;
    }
}

// Fetch academic years for filter dropdown on initial load
$anneesAcademiques = []; //
try {
    $stmt = $pdo->query("SELECT id_Ac, CONCAT(YEAR(date_deb), '-', YEAR(date_fin)) as annee_libelle FROM année_academique ORDER BY date_deb DESC"); //
    $anneesAcademiques = $stmt->fetchAll(PDO::FETCH_ASSOC); //
} catch (PDOException $e) {
    error_log("Error fetching academic years for filter: " . $e->getMessage()); //
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Attributions</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* CSS provided in the original file, unchanged */
        :root {
            --primary-50: #f8fafc; --primary-100: #f1f5f9; --primary-200: #e2e8f0; --primary-300: #cbd5e1; --primary-400: #94a3b8; --primary-500: #64748b; --primary-600: #475569; --primary-700: #334155; --primary-800: #1e293b; --primary-900: #0f172a;
            --accent-50: #eff6ff; --accent-100: #dbeafe; --accent-200: #bfdbfe; --accent-300: #93c5fd; --accent-400: #60a5fa; --accent-500: #3b82f6; --accent-600: #2563eb; --accent-700: #1d4ed8; --accent-800: #1e40af; --accent-900: #1e3a8a;
            --secondary-50: #f0fdf4; --secondary-100: #dcfce7; --secondary-500: #22c55e; --secondary-600: #16a34a;
            --success-500: #22c55e; --warning-500: #f59e0b; --error-500: #ef4444; --info-500: #3b82f6;
            --white: #ffffff; --gray-50: #f9fafb; --gray-100: #f3f4f6; --gray-200: #e5e7eb; --gray-300: #d1d5db; --gray-400: #9ca3af; --gray-500: #6b7280; --gray-600: #4b5563; --gray-700: #374151; --gray-800: #1f2937; --gray-900: #111827;
            --sidebar-width: 280px; --sidebar-collapsed-width: 80px; --topbar-height: 70px;
            --font-primary: 'Segoe UI', system-ui, -apple-system, sans-serif;
            --text-xs: 0.75rem; --text-sm: 0.875rem; --text-base: 1rem; --text-lg: 1.125rem; --text-xl: 1.25rem; --text-2xl: 1.5rem; --text-3xl: 1.875rem;
            --space-1: 0.25rem; --space-2: 0.5rem; --space-3: 0.75rem; --space-4: 1rem; --space-5: 1.25rem; --space-6: 1.5rem; --space-8: 2rem; --space-10: 2.5rem; --space-12: 3rem; --space-16: 4rem;
            --radius-sm: 0.25rem; --radius-md: 0.5rem; --radius-lg: 0.75rem; --radius-xl: 1rem; --radius-2xl: 1.5rem; --radius-3xl: 2rem;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.05); --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --transition-fast: 150ms ease-in-out; --transition-normal: 250ms ease-in-out; --transition-slow: 350ms ease-in-out;
        }

        /* === RESET === */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--font-primary); background-color: var(--gray-50); color: var(--gray-800); overflow-x: hidden; }

        /* === LAYOUT PRINCIPAL === */
        .admin-layout { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-width); transition: margin-left var(--transition-normal); }
        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed-width); }

        /* === SIDEBAR === */
        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-width); height: 100vh; background: linear-gradient(180deg, var(--primary-800) 0%, var(--primary-900) 100%); color: white; z-index: 1000; transition: all var(--transition-normal); overflow-y: auto; overflow-x: hidden; }
        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .sidebar::-webkit-scrollbar { width: 4px; } .sidebar::-webkit-scrollbar-track { background: var(--primary-900); } .sidebar::-webkit-scrollbar-thumb { background: var(--primary-600); border-radius: 2px; }
        .sidebar-header { padding: var(--space-6); border-bottom: 1px solid var(--primary-700); display: flex; align-items: center; gap: var(--space-3); }
        .sidebar-logo { width: 40px; height: 40px; background: var(--accent-500); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; flex-shrink: 0; } .sidebar-logo img { width: 28px; height: 28px; object-fit: contain; filter: brightness(0) invert(1); }
        .sidebar-title { font-size: var(--text-xl); font-weight: 700; white-space: nowrap; opacity: 1; transition: opacity var(--transition-normal); } .sidebar.collapsed .sidebar-title { opacity: 0; }
        .sidebar-nav { padding: var(--space-4) 0; }
        .nav-section { margin-bottom: var(--space-6); }
        .nav-section-title { padding: var(--space-2) var(--space-6); font-size: var(--text-xs); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--primary-400); white-space: nowrap; opacity: 1; transition: opacity var(--transition-normal); } .sidebar.collapsed .nav-section-title { opacity: 0; }
        .nav-item { margin-bottom: var(--space-1); }
        .nav-link { display: flex; align-items: center; padding: var(--space-3) var(--space-6); color: var(--primary-200); text-decoration: none; transition: all var(--transition-fast); position: relative; gap: var(--space-3); }
        .nav-link:hover { background: rgba(255, 255, 255, 0.1); color: white; }
        .nav-link.active { background: var(--accent-600); color: white; }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--accent-300); }
        .nav-icon { width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .nav-text { white-space: nowrap; opacity: 1; transition: opacity var(--transition-normal); } .sidebar.collapsed .nav-text { opacity: 0; }
        .nav-submenu { margin-left: var(--space-8); margin-top: var(--space-2); border-left: 2px solid var(--primary-700); padding-left: var(--space-4); } .sidebar.collapsed .nav-submenu { display: none; }
        .nav-submenu .nav-link { padding: var(--space-2) var(--space-4); font-size: var(--text-sm); }

        /* === TOPBAR === */
        .topbar { height: var(--topbar-height); background: var(--white); border-bottom: 1px solid var(--gray-200); padding: 0 var(--space-6); display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; }
        .topbar-left { display: flex; align-items: center; gap: var(--space-4); }
        .sidebar-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); } .sidebar-toggle:hover { background: var(--gray-200); color: var(--gray-800); }
        .page-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-800); }
        .topbar-right { display: flex; align-items: center; gap: var(--space-4); }
        .topbar-button { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); position: relative; } .topbar-button:hover { background: var(--gray-200); color: var(--gray-800); }
        .notification-badge { position: absolute; top: -2px; right: -2px; width: 18px; height: 18px; background: var(--error-500); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: white; }
        .user-menu { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-2) var(--space-3); border-radius: var(--radius-lg); cursor: pointer; transition: background var(--transition-fast); } .user-menu:hover { background: var(--gray-100); }
        .user-info { text-align: right; } .user-name { font-size: var(--text-sm); font-weight: 600; color: var(--gray-800); line-height: 1.2; } .user-role { font-size: var(--text-xs); color: var(--gray-500); }

        /* === PAGE CONTENT === */
        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); display: flex; justify-content: space-between; align-items: center; }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); margin-top: var(--space-2); }

        .student-profile { display: grid; grid-template-columns: 280px 1fr; gap: var(--space-8); margin-bottom: var(--space-8); }
        .profile-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); }
        .profile-header { display: flex; flex-direction: column; align-items: center; text-align: center; margin-bottom: var(--space-6); }
        .profile-avatar { width: 120px; height: 120px; border-radius: 50%; background-color: var(--gray-200); display: flex; align-items: center; justify-content: center; margin-bottom: var(--space-4); overflow: hidden; }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-name { font-size: var(--text-xl); font-weight: 700; margin-bottom: var(--space-1); }
        .profile-id { color: var(--gray-600); font-size: var(--text-sm); margin-bottom: var(--space-4); }
        .profile-badge { display: inline-block; padding: var(--space-1) var(--space-3); background-color: var(--secondary-100); color: var(--secondary-600); border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 600; }
        .profile-details { width: 100%; }
        .detail-item { display: flex; justify-content: space-between; padding: var(--space-3) 0; border-bottom: 1px solid var(--gray-200); }
        .detail-label { color: var(--gray-600); font-weight: 600; }
        .detail-value { color: var(--gray-800); text-align: right; }

        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: var(--space-6); }
        .info-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); }
        .info-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); }
        .info-card-title { font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); }
        .info-card-icon { width: 40px; height: 40px; background-color: var(--accent-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; color: var(--accent-600); }

        .table-container { background: var(--white); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); overflow: hidden; margin-bottom: var(--space-8); }
        .table-header { padding: var(--space-6); border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; }
        .table-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .table-actions { display: flex; gap: var(--space-3); align-items: center; }

        .table-wrapper { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: var(--space-3); text-align: left; border-bottom: 1px solid var(--gray-200); font-size: var(--text-sm); }
        .data-table th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); }
        .data-table tbody tr:hover { background-color: var(--gray-50); }
        .data-table td { color: var(--gray-800); }

        .badge { padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; }
        .badge-success { background-color: var(--secondary-100); color: var(--secondary-600); }
        .badge-warning { background-color: #fef3c7; color: #d97706; }
        .badge-info { background-color: var(--accent-100); color: var(--accent-600); }

        .action-buttons { display: flex; gap: var(--space-1); }
        .btn { padding: var(--space-2) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); text-decoration: none; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover:not(:disabled) { background-color: var(--accent-700); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover:not(:disabled) { background-color: var(--secondary-600); }
        .btn-warning { background-color: var(--warning-500); color: white; } .btn-warning:hover:not(:disabled) { background-color: #f59e0b; }
        .btn-danger { background-color: var(--error-500); color: white; } .btn-danger:hover:not(:disabled) { background-color: #dc2626; }
        .btn-outline-primary { background-color: transparent; color: var(--accent-600); border: 1px solid var(--accent-600); } .btn-outline-primary:hover { background-color: var(--accent-50); }
        .btn-sm { padding: var(--space-1) var(--space-2); font-size: var(--text-xs); }

        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); display: none; }
        .alert.success { background-color: var(--secondary-50); color: var(--secondary-600); border: 1px solid var(--secondary-100); }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }

        .loading-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); display: none; align-items: center; justify-content: center; z-index: 9999; }
        .loading-spinner { width: 40px; height: 40px; border: 4px solid var(--gray-300); border-top-color: var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .empty-state { text-align: center; padding: var(--space-16); color: var(--gray-500); }
        .empty-state i { font-size: 3rem; margin-bottom: var(--space-4); }

        /* Responsive */
        @media (max-width: 992px) {
            .student-profile { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .main-content.sidebar-collapsed { margin-left: 0; }
            .page-header { flex-direction: column; align-items: flex-start; gap: var(--space-4); }
            .info-grid { grid-template-columns: 1fr; }
        }
        .table-hover tbody tr:hover {
            background-color: rgba(59, 130, 246, 0.05);
        }
        .badge-attribution {
            background-color: #e0f2fe;
            color: #0369a1;
        }

        /* Styles for search and filter controls */
        .search-bar {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-4) var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: var(--space-6);
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }

        .search-input-container {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: var(--space-3) var(--space-10); /* Increased padding for icon */
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-lg);
            font-size: var(--text-base);
            color: var(--gray-800);
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

        .search-button {
            padding: var(--space-3) var(--space-5);
            border-radius: var(--radius-lg);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            background-color: var(--accent-600);
            color: white;
        }

        .search-button:hover {
            background-color: var(--accent-700);
        }

        .filter-modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.5); /* Black w/ opacity */
            align-items: center; /* Center vertically */
            justify-content: center; /* Center horizontally */
        }

        .filter-modal-content {
            background-color: var(--white);
            margin: auto; /* Auto margin to center it */
            padding: var(--space-6);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            width: 90%; /* Responsive width */
            max-width: 500px; /* Max width for larger screens */
            position: relative; /* For closing button positioning */
        }

        .filter-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
            border-bottom: 1px solid var(--gray-200); /* Separator */
            padding-bottom: var(--space-3);
        }

        .filter-modal-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
        }

        .filter-modal-close {
            color: var(--gray-500);
            font-size: var(--text-2xl);
            font-weight: bold;
            cursor: pointer;
            border: none;
            background: none;
            padding: 0;
        }

        .filter-modal-close:hover,
        .filter-modal-close:focus {
            color: var(--gray-700);
            text-decoration: none;
            cursor: pointer;
        }

        .filter-group {
            margin-bottom: var(--space-4);
        }

        .filter-group label {
            display: block;
            margin-bottom: var(--space-2);
            font-weight: 500;
            color: var(--gray-700);
        }

        /* Specific styles for radio groups within filter dropdown */
        .filter-option-group {
            padding: var(--space-2) 0;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            max-height: 200px; /* Limit height for scrollable content */
            overflow-y: auto;
            background-color: var(--gray-50); /* Light background for the scrollable area */
        }

        .filter-option-group label {
            display: flex;
            align-items: center;
            padding: var(--space-2) var(--space-3);
            cursor: pointer;
            width: 100%;
            margin-bottom: 0; /* Override default label margin */
            font-weight: normal; /* Override default label font-weight */
            color: var(--gray-700);
        }
        .filter-option-group label:hover {
            background-color: var(--gray-100);
        }
        .filter-option-group input[type="radio"] {
            margin-right: var(--space-2); /* Space between radio and text */
            flex-shrink: 0; /* Prevent radio button from shrinking */
        }

        .filter-actions-dropdown {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
            margin-top: var(--space-6);
        }

        .btn-active-filter {
            background-color: var(--accent-200);
            color: var(--accent-800);
        }

        /* Message Modal Styles */
        .message-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .message-modal-content {
            background: var(--white);
            padding: var(--space-6);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            max-width: 400px;
            width: 90%;
            text-align: center;
            position: relative;
        }

        .message-icon {
            font-size: 2.5rem;
            margin-bottom: var(--space-4);
        }

        .message-icon.success { color: var(--success-500); }
        .message-icon.error { color: var(--error-500); }
        .message-icon.warning { color: var(--warning-500); }
        .message-icon.info { color: var(--info-500); }

        .message-title {
            font-size: var(--text-xl);
            font-weight: 600;
            margin-bottom: var(--space-2);
        }

        .message-text {
            font-size: var(--text-base);
            margin-bottom: var(--space-4);
            color: var(--gray-600);
        }

        .message-close {
            position: absolute;
            top: var(--space-3);
            right: var(--space-3);
            background: none;
            border: none;
            font-size: var(--text-lg);
            cursor: pointer;
            color: var(--gray-500);
        }

        .message-close:hover {
            color: var(--gray-700);
        }

        .message-button {
            padding: var(--space-3) var(--space-6);
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            border: none;
            background-color: var(--accent-600);
            color: white;
            transition: background-color var(--transition-fast);
        }

        .message-button:hover {
            background-color: var(--accent-700);
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

        @media (max-width: 1024px) {
            .main-content { margin-left: var(--sidebar-collapsed-width); }
            .sidebar-title, .nav-text, .nav-section-title { opacity: 0; pointer-events: none; }
            .nav-link { justify-content: center; }
            .sidebar-toggle .fa-bars { display: inline-block; }
            .sidebar-toggle .fa-times { display: none; }
            .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        }

        @media (max-width: 768px) {
            .admin-layout { position: relative; }
            .main-content { margin-left: 0; }
            .sidebar { left: -100%; transition: left var(--transition-normal); z-index: 1000; height: 100vh; overflow-y: auto; }
            .sidebar.mobile-open { left: 0; }
            .mobile-menu-overlay.active { display: block; }
            .sidebar-toggle .fa-bars { display: inline-block; }
            .sidebar-toggle .fa-times { display: none; }
            .sidebar.mobile-open + .topbar .sidebar-toggle .fa-bars { display: none; }
            .sidebar.mobile-open + .topbar .sidebar-toggle .fa-times { display: inline-block; }
            .page-header { flex-direction: column; align-items: flex-start; gap: var(--space-4); }
            .table-actions { width: 100%; justify-content: flex-start; flex-wrap: wrap; margin-top: var(--space-4); }
            .search-bar { flex-direction: column; align-items: stretch; }
            .download-buttons { width: 100%; justify-content: flex-end; }
        }

        @media (max-width: 480px) {
            .page-content { padding: var(--space-4); }
            .table-container { padding: var(--space-4); }
            .page-title-main { font-size: var(--text-2xl); }
            .page-subtitle { font-size: var(--text-base); }
            .table-actions { flex-wrap: wrap; gap: var(--space-2); }
        }
    </style>
</head>
<body>
    <?php include 'sidebar_commision.php'; /* This include is assumed to exist */ ?>

    <main class="main-content" id="mainContent">
        <?php include 'topbar.php'; /* This include is assumed to exist */ ?>

        <div class="page-content">
            <div class="container-fluid py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="page-title-main mb-0">Attribution des encadrants</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#attributionModal" id="newAttributionBtn">
                        <i class="fas fa-plus me-2"></i>Nouvelle attribution
                    </button>
                </div>

                <div class="message-modal" id="messageModal">
                    <div class="message-modal-content">
                        <button class="message-close" id="messageClose">&times;</button>
                        <div class="message-icon" id="messageIcon"></div>
                        <h3 class="message-title" id="messageTitle"></h3>
                        <p class="message-text" id="messageText"></p>
                        <button class="message-button" id="messageButton">OK</button>
                    </div>
                </div>

                <div class="search-bar">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher par thème, étudiant...">
                    </div>
                    <button class="search-button" id="searchButton">
                        <i class="fas fa-search"></i> Rechercher
                    </button>
                    <button class="btn btn-secondary" id="filterButton">
                         <i class="fas fa-filter"></i> <span id="filterButtonText">Filtres</span>
                     </button>
                </div>


                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Liste des rapports validés à attribuer (<span id="reportCount">0</span>)</h5>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Rapport</th>
                                        <th>Étudiant</th>
                                        <th>Encadrant pédagogique</th>
                                        <th>Directeur mémoire</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="attributionsTableBody">
                                    </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="attributionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="attributionModalTitle">Nouvelle attribution</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="attributionForm">
                        <input type="hidden" id="editRapportId">
                        <div class="mb-3">
                            <label for="selectRapport" class="form-label">Sélectionner un rapport</label>
                            <select class="form-select" id="selectRapport" required>
                                <option value="">Sélectionner un rapport</option>
                                </select>
                        </div>

                        <div class="mb-3">
                            <label for="selectEncadrant" class="form-label">Encadrant pédagogique</label>
                            <select class="form-select" id="selectEncadrant">
                                <option value="">Sélectionner un encadrant</option>
                                </select>
                        </div>

                        <div class="mb-3">
                            <label for="selectDirecteur" class="form-label">Directeur de mémoire</label>
                            <select class="form-select" id="selectDirecteur">
                                <option value="">Sélectionner un directeur</option>
                                </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-primary" id="saveAttributionBtn">Enregistrer</button>
                </div>
            </div>
        </div>
    </div>

    <div class="filter-modal" id="filterModal">
        <div class="filter-modal-content">
            <div class="filter-modal-header">
                <h3 class="filter-modal-title">Filtres et Tri des Rapports</h3>
                <button class="filter-modal-close" id="closeFilterModal">&times;</button>
            </div>
            <div class="filter-group">
                <label>Filtrer par Année Académique:</label>
                <div class="filter-option-group" id="anneeFilterRadioGroup">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="annee_filter_radio" value="all_annees" checked>
                            Toutes les Années
                        </label>
                    </div>
                    <?php foreach ($anneesAcademiques as $annee): /* */ ?>
                        <div class="filter-option radio-group">
                            <label>
                                <input type="radio" name="annee_filter_radio" value="<?php echo htmlspecialchars($annee['id_Ac']); /* */ ?>">
                                <?php echo htmlspecialchars($annee['annee_libelle']); /* */ ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="filter-actions-dropdown">
                <button class="btn btn-secondary" id="resetFilterModalBtn">Réinitialiser</button>
                <button class="btn btn-primary" id="applyFilterModalBtn">Appliquer</button>
            </div>
        </div>
    </div>


    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>
    <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // DOM Elements
        const attributionsTableBody = document.getElementById('attributionsTableBody');
        const reportCountSpan = document.getElementById('reportCount');
        const selectRapport = document.getElementById('selectRapport');
        const selectEncadrant = document.getElementById('selectEncadrant');
        const selectDirecteur = document.getElementById('selectDirecteur');
        const saveAttributionBtn = document.getElementById('saveAttributionBtn');
        const attributionModal = new bootstrap.Modal(document.getElementById('attributionModal'));
        const attributionModalTitle = document.getElementById('attributionModalTitle');
        const editRapportId = document.getElementById('editRapportId');
        const newAttributionBtn = document.getElementById('newAttributionBtn');

        // Search & Filter
        const searchInput = document.getElementById('searchInput');
        const searchButton = document.getElementById('searchButton');
        const filterModal = document.getElementById('filterModal');
        const closeFilterModalBtn = document.getElementById('closeFilterModal');
        const applyFilterModalBtn = document.getElementById('applyFilterModalBtn');
        const resetFilterModalBtn = document.getElementById('resetFilterModalBtn');
        const filterButton = document.getElementById('filterButton');
        const filterButtonText = document.getElementById('filterButtonText');
        const anneeFilterRadioGroup = document.getElementById('anneeFilterRadioGroup');

        // Message Modal elements
        const messageModal = document.getElementById('messageModal');
        const messageTitle = document.getElementById('messageTitle');
        const messageText = document.getElementById('messageText');
        const messageIcon = document.getElementById('messageIcon');
        const messageButton = document.getElementById('messageButton');
        const messageClose = document.getElementById('messageClose');
        const loadingOverlay = document.getElementById('loadingOverlay');

        // Sidebar elements for responsiveness
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

        let allReportsData = [];
        let allEnseignants = [];
        let currentAnneeFilter = 'all_annees'; // Initial filter state

        // --- Utility Functions ---
        function showAlert(message, type = 'success', title = null) {
            if (!title) {
                switch (type) {
                    case 'success': title = 'Succès'; break;
                    case 'error': title = 'Erreur'; break;
                    case 'warning': title = 'Attention'; break;
                    case 'info': title = 'Information'; break;
                    default: title = 'Message';
                }
            }
            messageIcon.className = 'message-icon ' + type;
            messageIcon.innerHTML = '';
            switch (type) {
                case 'success': messageIcon.innerHTML = '<i class="fas fa-check-circle"></i>'; break;
                case 'error': messageIcon.innerHTML = '<i class="fas fa-times-circle"></i>'; break;
                case 'warning': messageIcon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>'; break;
                case 'info': messageIcon.innerHTML = '<i class="fas fa-info-circle"></i>'; break;
                default: messageIcon.innerHTML = '<i class="fas fa-bell"></i>';
            }
            messageTitle.textContent = title;
            messageText.textContent = message;
            messageModal.style.display = 'flex';
        }

        function closeMessageModal() {
            messageModal.style.display = 'none';
        }
        messageButton.addEventListener('click', closeMessageModal);
        messageClose.addEventListener('click', closeMessageModal);
        messageModal.addEventListener('click', function(e) {
            if (e.target === messageModal) {
                closeMessageModal();
            }
        });

        function showLoading(show) {
            loadingOverlay.style.display = show ? 'flex' : 'none';
        }

        async function makeAjaxRequest(data) {
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(data)
                });
                return await response.json();
            } catch (error) {
                console.error('Erreur AJAX:', error);
                throw error;
            }
        }

        function htmlspecialchars(str) {
            if (typeof str !== 'string') return str;
            var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return str.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // --- Data Fetching and Rendering ---
        async function fetchAttributions() {
            showLoading(true);
            try {
                const result = await makeAjaxRequest({
                    action: 'get_reports_for_attribution',
                    search_term: searchInput.value,
                    annee_id: currentAnneeFilter === 'all_annees' ? 0 : currentAnneeFilter
                });

                if (result.success) {
                    allReportsData = result.data;
                    renderAttributionsTable(allReportsData);
                } else {
                    showAlert(result.message, 'error');
                    allReportsData = [];
                    renderAttributionsTable([]);
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des attributions. Veuillez réessayer.', 'error');
                allReportsData = [];
                renderAttributionsTable([]);
            } finally {
                showLoading(false);
            }
        }

        function renderAttributionsTable(reports) {
            attributionsTableBody.innerHTML = '';
            reportCountSpan.textContent = reports.length;

            if (reports.length === 0) {
                attributionsTableBody.innerHTML = `
                    <tr>
                        <td colspan="5" class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <p>Aucun rapport validé à attribuer trouvé avec ces critères.</p>
                        </td>
                    </tr>
                `;
                return;
            }

            reports.forEach(report => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${htmlspecialchars(report.theme_rapport)}</td>
                    <td>${htmlspecialchars(report.etudiant_complet_nom)}</td>
                    <td>
                        ${report.encadrant_pedagogique_nom ? `<span class="badge badge-attribution rounded-pill p-2">${htmlspecialchars(report.encadrant_pedagogique_nom)}</span>` : '<span class="text-muted">Non attribué</span>'}
                    </td>
                    <td>
                        ${report.directeur_memoire_nom ? `<span class="badge badge-attribution rounded-pill p-2">${htmlspecialchars(report.directeur_memoire_nom)}</span>` : '<span class="text-muted">Non attribué</span>'}
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="openAttributionModalForEdit('${report.id_rapport}')">
                            <i class="fas fa-edit"></i> Modifier
                        </button>
                    </td>
                `;
                attributionsTableBody.appendChild(row);
            });
        }

        async function fetchEnseignants() {
            try {
                const result = await makeAjaxRequest({ action: 'get_enseignants' });
                if (result.success) {
                    allEnseignants = result.data;
                    populateEnseignantDropdowns();
                } else {
                    showAlert(result.message, 'error', 'Erreur chargement enseignants');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des enseignants.', 'error', 'Erreur réseau');
            }
        }

        function populateEnseignantDropdowns() {
            selectEncadrant.innerHTML = '<option value="">Sélectionner un encadrant</option>';
            selectDirecteur.innerHTML = '<option value="">Sélectionner un directeur</option>';
            allEnseignants.forEach(ens => {
                const option = `<option value="${ens.id_ens}">${htmlspecialchars(ens.nom_ens)} ${htmlspecialchars(ens.prenom_ens)}</option>`;
                selectEncadrant.innerHTML += option;
                selectDirecteur.innerHTML += option;
            });
        }

        async function populateRapportDropdown() {
            // This function fetches only *approved* reports that can be attributed
            // We reuse the existing allReportsData or fetch anew if needed for the dropdown
            if (allReportsData.length === 0) {
                await fetchAttributions(); // This will populate allReportsData with approved reports
            }

            selectRapport.innerHTML = '<option value="">Sélectionner un rapport</option>';
            allReportsData.forEach(report => {
                const option = `<option value="${report.id_rapport}">${htmlspecialchars(report.theme_rapport)} - ${htmlspecialchars(report.etudiant_complet_nom)} (${htmlspecialchars(report.annee_academique_libelle)})</option>`;
                selectRapport.innerHTML += option;
            });
        }

        // --- Modal Logic ---
        newAttributionBtn.addEventListener('click', function() {
            attributionModalTitle.textContent = 'Nouvelle attribution';
            document.getElementById('attributionForm').reset();
            editRapportId.value = ''; // Clear hidden ID for new attribution
            selectRapport.disabled = false; // Enable report selection for new attribution
            populateRapportDropdown();
            // Ensure enseignants are loaded
            if (allEnseignants.length === 0) {
                fetchEnseignants();
            }
        });

        async function openAttributionModalForEdit(rapportId) {
            attributionModalTitle.textContent = 'Modifier l\'attribution';
            document.getElementById('attributionForm').reset();
            selectRapport.disabled = true; // Disable report selection in edit mode
            editRapportId.value = rapportId; // Set hidden ID

            // Fetch report details and current attributions
            showLoading(true);
            try {
                // Ensure enseignants are loaded
                if (allEnseignants.length === 0) {
                    await fetchEnseignants();
                }
                await populateRapportDropdown(); // Populate with all available reports

                // Select the current report in the dropdown
                selectRapport.value = rapportId;

                // Find the report in currently loaded data to get assigned encadrant/director names
                const report = allReportsData.find(r => r.id_rapport == rapportId);

                if (report) {
                    let currentEncadrantId = '';
                    let currentDirecteurId = '';

                    // Find IDs based on names (more robust if backend could return IDs directly)
                    // For now, matching by full name "Nom Prenom"
                    if (report.encadrant_pedagogique_nom) {
                        const foundEncadrant = allEnseignants.find(ens => `${ens.nom_ens} ${ens.prenom_ens}` === report.encadrant_pedagogique_nom);
                        if (foundEncadrant) currentEncadrantId = foundEncadrant.id_ens;
                    }
                    if (report.directeur_memoire_nom) {
                        const foundDirecteur = allEnseignants.find(ens => `${ens.nom_ens} ${ens.prenom_ens}` === report.directeur_memoire_nom);
                        if (foundDirecteur) currentDirecteurId = foundDirecteur.id_ens;
                    }

                    selectEncadrant.value = currentEncadrantId;
                    selectDirecteur.value = currentDirecteurId;

                    attributionModal.show();
                } else {
                    showAlert('Rapport non trouvé pour modification.', 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des données d\'attribution.', 'error');
            } finally {
                showLoading(false);
            }
        }

        saveAttributionBtn.addEventListener('click', async function() {
            const rapportId = editRapportId.value || selectRapport.value;
            const encadrantId = selectEncadrant.value;
            const directeurId = selectDirecteur.value;

            if (!rapportId) {
                showAlert("Veuillez sélectionner un rapport.", "warning");
                return;
            }

            if (!encadrantId && !directeurId) {
                showAlert("Veuillez attribuer au moins un encadrant pédagogique ou un directeur de mémoire.", "warning");
                return;
            }

            showLoading(true);
            try {
                const result = await makeAjaxRequest({
                    action: 'save_attribution',
                    rapport_id: rapportId,
                    encadrant_id: encadrantId,
                    directeur_id: directeurId
                });

                if (result.success) {
                    showAlert(result.message, 'success');
                    attributionModal.hide(); // Close modal
                    fetchAttributions(); // Refresh table
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'enregistrement de l\'attribution.', 'error');
            } finally {
                showLoading(false);
            }
        });

        // --- Search/Filter Logic ---
        searchInput.addEventListener('input', () => {
            // When typing in search, reset filters
            currentAnneeFilter = 'all_annees';
            document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true; // Reset radio
            updateFilterButtonText();
            fetchAttributions(); // Re-fetch with current search term
        });

        searchButton.addEventListener('click', () => fetchAttributions());

        if (filterButton) {
            filterButton.addEventListener('click', function(event) {
                event.stopPropagation();
                filterModal.style.display = 'flex';
            });
        }
        if (closeFilterModalBtn) {
            closeFilterModalBtn.addEventListener('click', () => filterModal.style.display = 'none');
        }
        if (filterModal) {
            filterModal.addEventListener('click', (e) => { if (e.target === filterModal) filterModal.style.display = 'none'; });
        }

        if (applyFilterModalBtn) {
            applyFilterModalBtn.addEventListener('click', function() {
                currentAnneeFilter = document.querySelector('input[name="annee_filter_radio"]:checked').value;
                searchInput.value = ''; // Clear search when applying modal filters
                fetchAttributions(); // Re-fetch data with new filter
                filterModal.style.display = 'none';
            });
        }

        if (resetFilterModalBtn) {
            resetFilterModalBtn.addEventListener('click', function() {
                currentAnneeFilter = 'all_annees';
                searchInput.value = '';

                document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true;

                fetchAttributions(); // Re-fetch data with reset filters
                filterModal.style.display = 'none';
                showAlert('Filtres et recherche réinitialisés.', 'info');
            });
        }

        function updateFilterButtonText() {
            let activeFiltersCount = 0;
            if (currentAnneeFilter !== 'all_annees') activeFiltersCount++;
            if (searchInput.value.trim() !== '') activeFiltersCount++;

            if (filterButton && filterButtonText) {
                if (activeFiltersCount > 0) {
                    filterButtonText.textContent = `Filtres (${activeFiltersCount} actifs)`;
                    filterButton.classList.add('btn-active-filter');
                } else {
                    filterButtonText.textContent = 'Filtres';
                    filterButton.classList.remove('btn-active-filter');
                }
            }
        }


        // --- Sidebar Responsiveness (from mes_rapports.php) ---
        function initSidebar() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (sidebarToggle && sidebar && mainContent) {
                handleResponsiveLayout();
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
            window.addEventListener('resize', handleResponsiveLayout);
        }

        function handleResponsiveLayout() {
            const isMobile = window.innerWidth < 768;
            if (sidebar) {
                if (isMobile) {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('sidebar-collapsed');
                    sidebar.classList.remove('mobile-open');
                    mobileMenuOverlay.classList.remove('active');
                } else {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('sidebar-collapsed');
                    sidebar.classList.remove('mobile-open');
                    mobileMenuOverlay.classList.remove('active');
                }
            }
            if (sidebarToggle) {
                const barsIcon = sidebarToggle.querySelector('.fa-bars');
                const timesIcon = sidebarToggle.querySelector('.fa-times');
                if (isMobile) {
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                } else {
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                }
                if (sidebar && sidebar.classList.contains('mobile-open')) {
                    if (barsIcon) barsIcon.style.display = 'none';
                    if (timesIcon) timesIcon.style.display = 'inline-block';
                }
            }
        }

        // --- Initialization ---
        document.addEventListener('DOMContentLoaded', function() {
            fetchAttributions(); // Initial load of reports
            fetchEnseignants(); // Load enseignants for dropdowns
            initSidebar(); // Initialize sidebar
            updateFilterButtonText(); // Update filter button on load

            // Make functions globally available for inline HTML events
            window.openAttributionModalForEdit = openAttributionModalForEdit;
        });

    </script>
</body>
</html>