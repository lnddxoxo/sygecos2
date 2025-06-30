<?php
// charge_verification.php
require_once 'config.php'; // Ensure this file contains your database connection (PDO) and isLoggedIn() function.

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

$rapports = []; // Initialize an empty array to store fetched reports
$pdo = null; // Initialize PDO outside try-catch to be accessible in finally

try {
    $pdo = new PDO('mysql:host=127.0.0.1:3306;dbname=sygecos', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Handle AJAX actions (Mark as Verified, Flag, Get Reports, Get Report Details)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        $response = ['success' => false, 'message' => ''];

        if ($_POST['action'] === 'mark_verified') {
            $reportId = $_POST['report_id'] ?? null;
            if (!$reportId) {
                $response['message'] = 'ID du rapport manquant.';
                echo json_encode($response);
                exit();
            }
            // Update report status to 'verifie' (or whatever status indicates verified)
            $stmt = $pdo->prepare("UPDATE rapports SET statut = 'verifie', date_derniere_modif = NOW() WHERE id_rapport = :report_id");
            $stmt->bindParam(':report_id', $reportId);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Rapport marqué comme vérifié avec succès.';
                $response['new_status'] = 'verifie';
            } else {
                $response['message'] = 'Erreur lors de la mise à jour du statut du rapport.';
            }
        } elseif ($_POST['action'] === 'flag_report') {
            $reportId = $_POST['report_id'] ?? null;
            $flagComment = $_POST['comment'] ?? 'Signaled for review.'; // Get comment for flagging
            if (!$reportId) {
                $response['message'] = 'ID du rapport manquant.';
                echo json_encode($response);
                exit();
            }
            $stmt = $pdo->prepare("UPDATE rapports SET statut = 'signale', commentaire_signalement = :comment, date_derniere_modif = NOW() WHERE id_rapport = :report_id");
            $stmt->bindParam(':report_id', $reportId);
            $stmt->bindParam(':comment', $flagComment);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Rapport signalé avec succès.';
                $response['new_status'] = 'signale';
            } else {
                $response['message'] = 'Erreur lors du signalement du rapport.';
            }
        } elseif ($_POST['action'] === 'mark_verified_multiple') {
            $reportIds = json_decode($_POST['report_ids'], true);
            if (!is_array($reportIds) || empty($reportIds)) {
                $response['message'] = 'Aucun rapport sélectionné.';
                echo json_encode($response);
                exit();
            }
            $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
            $stmt = $pdo->prepare("UPDATE rapports SET statut = 'verifie', date_derniere_modif = NOW() WHERE id_rapport IN ($placeholders)");
            if ($stmt->execute($reportIds)) {
                $response['success'] = true;
                $response['message'] = count($reportIds) . ' rapport(s) marqué(s) comme vérifié(s) avec succès.';
            } else {
                $response['message'] = 'Erreur lors de la mise à jour des statuts.';
            }
        } elseif ($_POST['action'] === 'flag_report_multiple') {
            $reportIds = json_decode($_POST['report_ids'], true);
            $flagComment = $_POST['comment'] ?? 'Signaled for review (bulk).';
            if (!is_array($reportIds) || empty($reportIds)) {
                $response['message'] = 'Aucun rapport sélectionné.';
                echo json_encode($response);
                exit();
            }
            $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
            $stmt = $pdo->prepare("UPDATE rapports SET statut = 'signale', commentaire_signalement = :comment, date_derniere_modif = NOW() WHERE id_rapport IN ($placeholders)");
            $params = array_merge([$flagComment], $reportIds);
            if ($stmt->execute($params)) {
                $response['success'] = true;
                $response['message'] = count($reportIds) . ' rapport(s) signalé(s) avec succès.';
            } else {
                $response['message'] = 'Erreur lors du signalement des rapports.';
            }
        } elseif ($_POST['action'] === 'get_reports') {
            $query = "SELECT
                                r.id_rapport,
                                r.theme_rapport,
                                e.num_etu AS num_etudiant,
                                CONCAT(e.prenoms_etu, ' ', e.nom_etu) AS nom_etudiant,
                                r.date_creation AS date_soumission,
                                r.statut
                            FROM
                                rapports r
                            JOIN
                                etudiant e ON r.fk_num_etu = e.num_etu
                            ORDER BY
                                r.date_creation DESC"; // Fetch all, client-side filters
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $reportsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $reportsData]);
            exit();
        } elseif ($_POST['action'] === 'get_report_details') {
            $reportId = $_POST['report_id'] ?? null;
            if (!$reportId) {
                $response['message'] = 'ID du rapport manquant.';
                echo json_encode($response);
                exit();
            }
            $query = "SELECT
                                r.id_rapport,
                                r.theme_rapport,
                                r.contenu_rapport, -- Assuming you have a content column
                                e.num_etu AS num_etudiant,
                                CONCAT(e.prenoms_etu, ' ', e.nom_etu) AS nom_etudiant,
                                r.date_creation AS date_soumission,
                                r.date_derniere_modif,
                                r.statut,
                                r.commentaire_signalement
                            FROM
                                rapports r
                            JOIN
                                etudiant e ON r.fk_num_etu = e.num_etu
                            WHERE r.id_rapport = :report_id";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':report_id', $reportId);
            $stmt->execute();
            $reportDetails = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($reportDetails) {
                echo json_encode(['success' => true, 'data' => $reportDetails]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Rapport non trouvé.']);
            }
            exit();
        }
        echo json_encode($response);
        exit();
    }

    // Initial fetch for display (only pending reports if desired, or all for client-side filtering)
    $stmt = $pdo->prepare("SELECT
                                r.id_rapport,
                                r.theme_rapport,
                                e.num_etu AS num_etudiant,
                                CONCAT(e.prenoms_etu, ' ', e.nom_etu) AS nom_etudiant,
                                r.date_creation AS date_soumission,
                                r.statut
                            FROM
                                rapports r
                            JOIN
                                etudiant e ON r.fk_num_etu = e.num_etu
                            ORDER BY
                                r.date_creation DESC");
    $stmt->execute();
    $rapports = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error fetching reports: " . $e->getMessage());
    echo "Une erreur est survenue lors du chargement des rapports. Veuillez réessayer.";
    exit();
} finally {
    // Close the PDO connection
    $pdo = null;
}

// Map database statuses to display names and CSS classes
function getStatusDisplay($status) {
    switch ($status) {
        case 'brouillon': return ['text' => 'Brouillon', 'class' => 'badge-gray'];
        case 'soumis':
        case 'en_attente': return ['text' => 'En attente', 'class' => 'badge-warning'];
        case 'verifie': return ['text' => 'Vérifié', 'class' => 'badge-success'];
        case 'signale': return ['text' => 'Signalé', 'class' => 'badge-error'];
        default: return ['text' => ucfirst($status), 'class' => 'badge-default'];
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Vérification des rapports</title>
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

            /* Couleurs Sémantiques */
            --success-500: #22c55e;
            --warning-500: #f59e0b;
            --error-500: #ef4444;
            --info-500: #3b82f6;
            --secondary-100: #dcfce7; /* Specific for success badge */
            --secondary-600: #16a34a; /* Specific for success badge text */

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
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.05);
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
            padding: var(--space-6);
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title-main {
            font-size: var(--text-3xl);
            font-weight: 700;
            color: var(--gray-900);
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: var(--text-lg);
            margin-top: var(--space-2);
        }

        /* Search Bar (unified) */
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

        .download-buttons {
            display: flex;
            gap: var(--space-3);
            margin-left: auto; /* Push buttons to the right */
        }

        .download-button {
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-fast);
            border: 1px solid var(--gray-300);
            background-color: var(--white);
            color: var(--gray-700);
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
        }

        .download-button:hover {
            background-color: var(--gray-100);
            border-color: var(--gray-400);
        }

        .download-button i {
            font-size: var(--text-base);
        }

        /* Rapports Cards Container */
        .rapports-container { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); 
            gap: var(--space-4); 
            margin-bottom: var(--space-6);
        }
        .rapport-card { 
            background: var(--white); 
            border-radius: var(--radius-lg); 
            padding: var(--space-4); 
            box-shadow: var(--shadow-sm); 
            border: 1px solid var(--gray-200);
            transition: all var(--transition-fast);
            position: relative; /* For checkbox positioning */
        }
        .rapport-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .rapport-card.selected { border: 2px solid var(--accent-500); background-color: var(--accent-50); }
        .rapport-header { display: flex; align-items: center; gap: var(--space-3); margin-bottom: var(--space-3); }
        .rapport-icon { 
            width: 40px; height: 40px; 
            background-color: var(--accent-100); 
            color: var(--accent-600); 
            border-radius: var(--radius-md); 
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .rapport-info { flex: 1; }
        .rapport-theme { font-weight: 600; margin-bottom: var(--space-1); }
        .rapport-etudiant { font-size: var(--text-sm); color: var(--gray-600); }
        .rapport-date { font-size: var(--text-xs); color: var(--gray-500); margin-top: var(--space-1); }
        .rapport-actions { 
            display: flex; 
            justify-content: space-between; 
            margin-top: var(--space-3); 
            padding-top: var(--space-3); 
            border-top: 1px solid var(--gray-200);
        }
        
        /* Checkbox on cards */
        .card-checkbox-container {
            position: absolute;
            top: var(--space-3);
            right: var(--space-3);
            z-index: 10;
        }
        /* Checkbox styling (from liste_etudiants.php for consistency) */
        .checkbox-container {
            display: block;
            position: relative;
            padding-left: 25px;
            cursor: pointer;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .checkbox-container input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .checkmark {
            position: absolute;
            top: 50%;
            left: 0;
            transform: translateY(-50%);
            height: 18px;
            width: 18px;
            background-color: var(--gray-200);
            border-radius: var(--radius-sm);
            transition: all var(--transition-fast);
            border: 1px solid var(--gray-300);
        }

        .checkbox-container input:checked ~ .checkmark {
            background-color: var(--accent-600);
            border-color: var(--accent-600);
        }

        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }

        .checkbox-container input:checked ~ .checkmark:after {
            display: block;
        }

        .checkbox-container .checkmark:after {
            left: 6px;
            top: 2px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 3px 3px 0;
            -webkit-transform: rotate(45deg);
            -ms-transform: rotate(45deg);
            transform: rotate(45deg);
        }

        /* Bulk Actions Bar */
        .bulk-actions { 
            background: var(--white); 
            border-radius: var(--radius-lg); 
            box-shadow: var(--shadow-sm); 
            border: 1px solid var(--gray-200);
            padding: var(--space-4);
            margin-bottom: var(--space-6);
            display: none; 
            align-items: center; 
            gap: var(--space-4); 
        }
        .bulk-actions.show { 
            display: flex; 
        }
        .selected-count { 
            font-weight: 600; 
            color: var(--gray-700); 
        }

        /* Message Modal */
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

        /* Buttons */
        .btn { 
            padding: var(--space-2) var(--space-4); 
            border-radius: var(--radius-md); 
            font-size: var(--text-sm); 
            font-weight: 600; 
            cursor: pointer; 
            transition: all var(--transition-fast); 
            border: none; 
            display: inline-flex; 
            align-items: center; 
            gap: var(--space-2); 
            text-decoration: none;
        }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background-color: var(--accent-600); color: white; }
        .btn-primary:hover:not(:disabled) { background-color: var(--accent-700); }
        .btn-outline { background-color: transparent; color: var(--accent-600); border: 1px solid var(--accent-600); }
        .btn-outline:hover { background-color: var(--accent-50); }
        .btn-success { background-color: var(--success-500); color: white; }
        .btn-success:hover:not(:disabled) { background-color: #16a34a; }
        .btn-danger { background-color: var(--error-500); color: white; }
        .btn-danger:hover:not(:disabled) { background-color: #dc2626; }
        .btn-sm { padding: var(--space-1) var(--space-3); font-size: var(--text-xs); }
        .btn-secondary { /* Added for filter, modify, delete buttons */
            background-color: var(--gray-200);
            color: var(--gray-700);
        }
        .btn-secondary:hover:not(:disabled) {
            background-color: var(--gray-300);
        }
        .btn-active-filter { /* Style for active filter button */
            background-color: var(--accent-200);
            color: var(--accent-800);
        }
        
        /* Badges for status */
        .badge { padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; }
        .badge-success { background-color: var(--secondary-100); color: var(--secondary-600); }
        .badge-warning { background-color: #fef3c7; color: #d97706; }
        .badge-error { background-color: #fee2e2; color: #dc2626; } /* Adjusted from statut-signale */
        .badge-gray { background-color: var(--gray-200); color: var(--gray-700); } /* New for brouillon/default */

        /* Analyse section */
        .analyse-section { 
            margin-top: var(--space-6); 
            background: var(--white); 
            border-radius: var(--radius-lg); 
            padding: var(--space-4); 
            box-shadow: var(--shadow-sm); 
            border: 1px solid var(--gray-200);
            display: none;
        }
        .analyse-title { font-size: var(--text-lg); font-weight: 600; margin-bottom: var(--space-3); }
        .analyse-options { display: flex; gap: var(--space-3); margin-bottom: var(--space-4); }
        .analyse-option { 
            padding: var(--space-3); 
            border: 1px solid var(--gray-200); 
            border-radius: var(--radius-md); 
            cursor: pointer;
            transition: all var(--transition-fast);
            flex: 1;
            text-align: center;
        }
        .analyse-option:hover { border-color: var(--accent-500); background-color: var(--accent-50); }
        .analyse-option.selected { border-color: var(--accent-500); background-color: var(--accent-100); }
        .analyse-option i { font-size: var(--text-2xl); margin-bottom: var(--space-2); color: var(--accent-600); }
        
        /* Modal for Analysis Results */
        .modal-overlay { 
            position: fixed; 
            top: 0; left: 0; 
            width: 100%; height: 100%; 
            background-color: rgba(0, 0, 0, 0.5); 
            display: none; 
            justify-content: center; 
            align-items: center; 
            z-index: 10000; /* Higher than message modal */
        }
        .modal { 
            background: var(--white); 
            border-radius: var(--radius-xl); 
            width: 90%; 
            max-width: 600px; 
            max-height: 90vh; 
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
        }
        .modal-header { 
            padding: var(--space-4) var(--space-6); 
            border-bottom: 1px solid var(--gray-200); 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
        }
        .modal-title { font-size: var(--text-xl); font-weight: 600; }
        .modal-close { 
            background: none; border: none; 
            font-size: var(--text-2xl); 
            cursor: pointer; 
            color: var(--gray-500);
        }
        .modal-content-body { padding: var(--space-6); } /* Renamed to avoid conflict */
        .modal-actions { 
            padding: var(--space-4) var(--space-6); 
            border-top: 1px solid var(--gray-200); 
            display: flex; 
            justify-content: flex-end; 
            gap: var(--space-3);
        }
        .result-item { margin-bottom: var(--space-4); }
        .result-label { font-weight: 600; margin-bottom: var(--space-1); }
        .result-value { padding: var(--space-2); background-color: var(--gray-100); border-radius: var(--radius-md); }
        .progress-bar { 
            height: 10px; 
            background-color: var(--gray-200); 
            border-radius: var(--radius-md); 
            margin-top: var(--space-2);
            overflow: hidden;
        }
        .progress-fill { 
            height: 100%; 
            background-color: var(--accent-500); 
            width: 0%; 
            transition: width 0.5s ease;
        }
        
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none; /* Hidden by default */
            align-items: center;
            justify-content: center;
            z-index: 9999; /* Higher than other modals */
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--accent-500);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Empty State */
        .empty-state { text-align: center; padding: var(--space-16); color: var(--gray-500); grid-column: 1 / -1; }
        .empty-state i { font-size: 3rem; margin-bottom: var(--space-4); }

        /* Filter Modal */
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

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-4);
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
            
            .page-title-main {
                font-size: var(--text-2xl);
            }
            
            .page-subtitle {
                font-size: var(--text-base);
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_chargee_communication.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1 class="page-title-main">Vérification des rapports</h1>
                        <p class="page-subtitle">Analyse et validation des rapports soumis</p>
                    </div>
                    <div class="page-actions">
                        </div>
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
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher un rapport...">
                    </div>
                    <button class="search-button" id="searchButton">
                        <i class="fas fa-search"></i> Rechercher
                    </button>
                    <div class="download-buttons">
                        <button class="download-button" id="exportPdfBtn">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button class="download-button" id="exportExcelBtn">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button class="download-button" id="exportCsvBtn">
                            <i class="fas fa-file-csv"></i> CSV
                        </button>
                    </div>
                </div>

                <div class="bulk-actions" id="bulkActions">
                    <label class="checkbox-container">
                        <input type="checkbox" id="selectAllReports">
                        <span class="checkmark"></span>
                    </label>
                    <span class="selected-count" id="selectedCount">0 rapport(s) sélectionné(s)</span>
                    <button onclick="markSelectedVerified()" class="btn btn-success btn-sm">
                        <i class="fas fa-check"></i> Valider la sélection
                    </button>
                    <button onclick="flagSelectedReports()" class="btn btn-danger btn-sm">
                        <i class="fas fa-flag"></i> Signaler la sélection
                    </button>
                    <button class="btn btn-secondary btn-sm" id="filterButton">
                        <i class="fas fa-filter"></i> <span id="filterButtonText">Filtres</span>
                    </button>
                </div>

                <div class="rapports-container" id="rapportsContainer">
                    <?php if (empty($rapports)): ?>
                        <p class="empty-state">Aucun rapport à afficher pour le moment.</p>
                    <?php else: ?>
                        <?php endif; ?>
                </div>

                <div class="analyse-section" id="analyseSection">
                    <h3 class="analyse-title">Options d'analyse pour le rapport sélectionné</h3>
                    <div class="analyse-options">
                        <div class="analyse-option" data-analyse="plagiat">
                            <i class="fas fa-copy"></i>
                            <div>Détection de plagiat</div>
                        </div>
                        <div class="analyse-option" data-analyse="fraude">
                            <i class="fas fa-user-secret"></i>
                            <div>Détection de fraude</div>
                        </div>
                        <div class="analyse-option" data-analyse="ia">
                            <i class="fas fa-robot"></i>
                            <div>Analyse IA</div>
                        </div>
                    </div>
                    <button class="btn btn-primary" id="lancerAnalyseBtn">
                        <i class="fas fa-play"></i> Lancer l'analyse
                    </button>
                </div>
            </div>
        </main>
    </div>

    <div class="modal-overlay" id="resultModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Résultats de l'analyse</h3>
                <button class="modal-close" onclick="closeResultModal()">&times;</button>
            </div>
            <div class="modal-content-body">
                <div class="result-item">
                    <div class="result-label">Type d'analyse:</div>
                    <div class="result-value" id="analyseType"></div>
                </div>
                <div class="result-item">
                    <div class="result-label">Rapport analysé:</div>
                    <div class="result-value" id="rapportAnalyse"></div>
                </div>
                <div class="result-item">
                    <div class="result-label">Score de similarité:</div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="similarityBar"></div>
                    </div>
                    <div id="similarityValue" style="text-align: center; margin-top: var(--space-1);"></div>
                </div>
                <div class="result-item">
                    <div class="result-label">Sources suspectes:</div>
                    <div class="result-value" id="suspiciousSources">
                        <ul style="padding-left: var(--space-4);"></ul>
                    </div>
                </div>
                <div class="result-item">
                    <div class="result-label">Conclusion:</div>
                    <div class="result-value" id="analyseConclusion"></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-outline" onclick="closeResultModal()">Fermer</button>
                <button class="btn btn-danger" id="flagReportBtnSingle">
                    <i class="fas fa-flag"></i> Signaler ce rapport
                </button>
                <button class="btn btn-success" id="markVerifiedBtnSingle">
                    <i class="fas fa-check"></i> Valider ce rapport
                </button>
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
                <label>Trier par:</label>
                <div class="filter-option-group">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="default" checked>
                            <i class="fas fa-list"></i> Ordre initial (date soumission DESC)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="theme-asc">
                            <i class="fas fa-sort-alpha-down"></i> Thème (A-Z)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="theme-desc">
                            <i class="fas fa-sort-alpha-up"></i> Thème (Z-A)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="student-asc">
                            <i class="fas fa-user-circle"></i> Étudiant (A-Z)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="student-desc">
                            <i class="fas fa-user-circle"></i> Étudiant (Z-A)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="date-asc">
                            <i class="fas fa-calendar-alt"></i> Date Soumission (Ancien au récent)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="date-desc">
                            <i class="fas fa-calendar-alt"></i> Date Soumission (Récent à l'ancien)
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="filter-group">
                <label>Filtrer par Statut:</label>
                <div class="filter-option-group" id="statusFilterRadioGroup">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="status_filter_radio" value="all_statuses" checked>
                            Tous les statuts
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="status_filter_radio" value="soumis">
                            En attente
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="status_filter_radio" value="verifie">
                            Vérifié
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="status_filter_radio" value="signale">
                            Signalé
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="status_filter_radio" value="brouillon">
                            Brouillon
                        </label>
                    </div>
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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        window.jsPDF = window.jspdf.jsPDF;

        let rapportsData = []; // Full dataset from the server
        let selectedRapports = new Set(); // Stores id_rapport of selected reports

        // Variables pour stocker les sélections pour l'analyse individuelle
        let selectedRapportId = null;
        let selectedRapportTheme = null;
        let selectedRapportEtudiant = null;
        let selectedAnalyseType = null;
        let currentReportDetails = null; // Store details of the currently viewed report for preview modal

        // Filter and Sort states
        let currentSortType = 'default';
        let currentStatusFilter = 'all_statuses';

        // DOM Elements
        const rapportsContainer = document.getElementById('rapportsContainer');
        const selectAllReportsCheckbox = document.getElementById('selectAllReports');
        const bulkActionsDiv = document.getElementById('bulkActions');
        const selectedCountSpan = document.getElementById('selectedCount');
        const searchInput = document.getElementById('searchInput');
        const searchButton = document.getElementById('searchButton');
        const exportPdfBtn = document.getElementById('exportPdfBtn');
        const exportExcelBtn = document.getElementById('exportExcelBtn');
        const exportCsvBtn = document.getElementById('exportCsvBtn');
        const filterButton = document.getElementById('filterButton');
        const filterButtonText = document.getElementById('filterButtonText');
        const filterModal = document.getElementById('filterModal');
        const closeFilterModalBtn = document.getElementById('closeFilterModal');
        const applyFilterModalBtn = document.getElementById('applyFilterModalBtn');
        const resetFilterModalBtn = document.getElementById('resetFilterModalBtn');

        // Message Modal elements
        const messageModal = document.getElementById('messageModal');
        const messageTitle = document.getElementById('messageTitle');
        const messageText = document.getElementById('messageText');
        const messageIcon = document.getElementById('messageIcon');
        const messageButton = document.getElementById('messageButton');
        const messageClose = document.getElementById('messageClose');

        // Analysis related elements
        const analyseSection = document.getElementById('analyseSection');
        const lancerAnalyseBtn = document.getElementById('lancerAnalyseBtn');
        const resultModal = document.getElementById('resultModal');
        const markVerifiedBtnSingle = document.getElementById('markVerifiedBtnSingle');
        const flagReportBtnSingle = document.getElementById('flagReportBtnSingle');

        // Sidebar elements for responsiveness
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');


        // --- Utility Functions ---

        // Function to show messages in the unified modal
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

        // Close message modal
        function closeMessageModal() {
            messageModal.style.display = 'none';
        }

        // Event listeners for message modal
        messageButton.addEventListener('click', closeMessageModal);
        messageClose.addEventListener('click', closeMessageModal);
        messageModal.addEventListener('click', function(e) {
            if (e.target === messageModal) {
                closeMessageModal();
            }
        });

        // Toggle sidebar for mobile
        function toggleSidebar() {
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
        }

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }

        if (mobileMenuOverlay) {
            mobileMenuOverlay.addEventListener('click', toggleSidebar);
        }

        // Function to show/hide loading overlay
        function showLoading(show) {
            const overlay = document.getElementById('loadingOverlay');
            overlay.style.display = show ? 'flex' : 'none';
        }

        // Format date string to French locale
        function formatDate(dateStr) {
            if (!dateStr || dateStr === '0000-00-00' || dateStr.includes('N/A')) return 'N/A';
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return 'N/A'; // Check for invalid date
            return date.toLocaleDateString('fr-FR');
        }

        // Helper to get status text (from PHP, keep in sync)
        function getStatusDisplayText(status) {
            switch (status) {
                case 'brouillon': return 'Brouillon';
                case 'soumis':
                case 'en_attente': return 'En attente';
                case 'verifie': return 'Vérifié';
                case 'signale': return 'Signalé';
                default: return status.charAt(0).toUpperCase() + status.slice(1);
            }
        }

        // Helper to get status CSS class
        function getStatusCssClass(status) {
            switch (status) {
                case 'brouillon': return 'badge-gray';
                case 'soumis':
                case 'en_attente': return 'badge-warning';
                case 'verifie': return 'badge-success';
                case 'signale': return 'badge-error';
                default: return 'badge-default';
            }
        }

        // --- Data Loading and Display ---

        // Function to load all reports via AJAX
        async function loadRapports() {
            try {
                showLoading(true);
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'get_reports' })
                });
                const result = await response.json();
                if (result.success) {
                    rapportsData = result.data; // Store the full dataset
                    applyFiltersAndSort(
                        currentSortType,
                        currentStatusFilter,
                        searchInput.value
                    ); // Apply filters and display
                } else {
                    showAlert('Erreur lors du chargement des données: ' + result.message, 'error');
                    displayEmptyState('Erreur lors du chargement des données');
                }
            } catch (error) {
                console.error('Erreur AJAX:', error);
                showAlert('Erreur de connexion au serveur.', 'error');
                displayEmptyState('Erreur de connexion');
            } finally {
                showLoading(false);
            }
        }

        // Render report cards from an array of report objects
        function renderCards(reportsToRender) {
            rapportsContainer.innerHTML = ''; // Clear existing cards
            selectedRapports.clear(); // Clear selections
            updateSelection(); // Update buttons state after clearing selections
            analyseSection.style.display = 'none'; // Hide analysis section

            if (reportsToRender.length === 0) {
                displayEmptyState('Aucun rapport trouvé correspondant à vos critères');
                return;
            }

            reportsToRender.forEach(rapport => {
                const statusInfo = getStatusDisplay(rapport.statut);
                const newCard = document.createElement('div');
                newCard.classList.add('rapport-card');
                newCard.setAttribute('data-id', rapport.id_rapport);
                newCard.setAttribute('data-theme', rapport.theme_rapport);
                newCard.setAttribute('data-etudiant', `${rapport.num_etudiant} - ${rapport.nom_etudiant}`);

                newCard.innerHTML = `
                    <div class="card-checkbox-container">
                        <label class="checkbox-container">
                            <input type="checkbox" class="rapport-checkbox" value="${rapport.id_rapport}">
                            <span class="checkmark"></span>
                        </label>
                    </div>
                    <div class="rapport-header">
                        <div class="rapport-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="rapport-info">
                            <div class="rapport-theme">${htmlspecialchars(rapport.theme_rapport)}</div>
                            <div class="rapport-etudiant">
                                ${htmlspecialchars(rapport.num_etudiant)} - ${htmlspecialchars(rapport.nom_etudiant)}
                            </div>
                            <div class="rapport-date">
                                Soumis le ${formatDate(rapport.date_soumission)}
                            </div>
                        </div>
                    </div>
                    <div class="rapport-actions">
                        <span class="badge ${statusInfo.class}" id="status-${rapport.id_rapport}">
                            ${statusInfo.text}
                        </span>
                        <div>
                            <button class="btn btn-sm btn-outline preview-btn" data-id="${rapport.id_rapport}">
                                <i class="fas fa-eye"></i> Prévisualiser
                            </button>
                        </div>
                    </div>
                `;
                rapportsContainer.appendChild(newCard);
                attachEventListenersToCard(newCard);
            });
        }

        // Attach event listeners to cards (checkboxes and preview button)
        function attachEventListenersToCard(card) {
            const checkbox = card.querySelector('.rapport-checkbox');
            const previewBtn = card.querySelector('.preview-btn');

            // Handle card selection (for analysis section)
            card.addEventListener('click', function(e) {
                // Prevent card selection if checkbox or preview button is clicked
                if (e.target.closest('.rapport-checkbox') || e.target.closest('.preview-btn')) {
                    return;
                }

                // Deselect other cards for single analysis
                document.querySelectorAll('.rapport-card').forEach(c => {
                    if (c !== card) { // Don't deselect self
                        c.classList.remove('selected');
                    }
                });

                // Toggle selection for this card
                card.classList.toggle('selected');

                // Update selectedRapportId for single analysis section
                if (card.classList.contains('selected')) {
                    selectedRapportId = card.dataset.id;
                    selectedR RapportTheme = card.dataset.theme;
                    selectedRapportEtudiant = card.dataset.etudiant;
                    analyseSection.style.display = 'block';
                } else {
                    selectedRapportId = null;
                    selectedRapportTheme = null;
                    selectedRapportEtudiant = null;
                    analyseSection.style.display = 'none';
                }

                // Reset selected analysis type
                document.querySelectorAll('.analyse-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                selectedAnalyseType = null;
            });

            // Handle checkbox change (for bulk actions)
            checkbox.addEventListener('change', function(event) {
                event.stopPropagation(); // Prevent card click event from firing on checkbox click
                if (this.checked) {
                    selectedRapports.add(this.value);
                } else {
                    selectedRapports.delete(this.value);
                }
                updateSelection();
            });

            // Handle preview button click
            previewBtn.addEventListener('click', function(event) {
                event.stopPropagation(); // Prevent card click event from firing
                const rapportId = this.dataset.id;
                showReportDetailsModal(rapportId);
            });
        }

        // Display empty state message
        function displayEmptyState(message) {
            rapportsContainer.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p>${message}</p>
                </div>
            `;
        }

        // HTML escaping helper
        function htmlspecialchars(str) {
            if (typeof str !== 'string') return str;
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return str.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // --- Selection and Bulk Actions ---

        // Toggle all checkboxes
        selectAllReportsCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.rapport-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                if (this.checked) {
                    selectedRapports.add(checkbox.value);
                } else {
                    selectedRapports.delete(checkbox.value);
                }
            });
            updateSelection();
        });

        // Update selection state and bulk action buttons
        function updateSelection() {
            const checkedCheckboxes = document.querySelectorAll('.rapport-checkbox:checked');
            selectedRapports = new Set(Array.from(checkedCheckboxes).map(cb => cb.value));
            
            if (selectedRapports.size > 0) {
                bulkActionsDiv.classList.add('show');
                selectedCountSpan.textContent = `${selectedRapports.size} rapport(s) sélectionné(s)`;
            } else {
                bulkActionsDiv.classList.remove('show');
            }
            
            // Update "Select All" checkbox state
            const totalCheckboxes = document.querySelectorAll('.rapport-checkbox').length;
            selectAllReportsCheckbox.indeterminate = selectedRapports.size > 0 && selectedRapports.size < totalCheckboxes;
            selectAllReportsCheckbox.checked = selectedRapports.size === totalCheckboxes && totalCheckboxes > 0;
        }

        // --- Bulk Actions (Mark Verified, Flag) ---

        async function markSelectedVerified() {
            if (selectedRapports.size === 0) {
                showAlert('Aucun rapport sélectionné pour la validation.', 'warning');
                return;
            }

            if (!confirm("Confirmez-vous la validation des rapports sélectionnés ?")) {
                return;
            }

            showLoading(true);
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'mark_verified_multiple',
                        report_ids: JSON.stringify(Array.from(selectedRapports))
                    })
                });
                const result = await response.json();

                if (result.success) {
                    showAlert(result.message, 'success');
                    loadRapports(); // Reload reports to reflect new statuses
                } else {
                    showAlert('Erreur: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Error marking selected as verified:', error);
                showAlert('Une erreur est survenue lors de la validation des rapports.', 'error');
            } finally {
                showLoading(false);
            }
        }

        async function flagSelectedReports() {
            if (selectedRapports.size === 0) {
                showAlert('Aucun rapport sélectionné pour le signalement.', 'warning');
                return;
            }

            const comment = prompt("Veuillez entrer un commentaire pour signaler les rapports sélectionnés:");
            if (comment === null || comment.trim() === "") {
                showAlert("Le commentaire est requis pour signaler un rapport.", 'warning');
                return;
            }

            showLoading(true);
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'flag_report_multiple',
                        report_ids: JSON.stringify(Array.from(selectedRapports)),
                        comment: comment
                    })
                });
                const result = await response.json();

                if (result.success) {
                    showAlert(result.message, 'success');
                    loadRapports(); // Reload reports
                } else {
                    showAlert('Erreur: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Error flagging selected reports:', error);
                showAlert('Une erreur est survenue lors du signalement des rapports.', 'error');
            } finally {
                showLoading(false);
            }
        }

        // --- Export Functions ---

        // Get filtered and sorted data for export
        function getExportData(onlySelected = false) {
            const headers = [
                'ID Rapport', 'Thème du Rapport', 'Numéro Étudiant', 'Nom Étudiant',
                'Date Soumission', 'Statut'
            ];
            
            let dataToExport = [];
            if (onlySelected) {
                dataToExport = Array.from(selectedRapports).map(idRapport => 
                    rapportsData.find(r => r.id_rapport == idRapport)
                ).filter(Boolean); // Remove any undefined if not found
            } else {
                // Get data from currently displayed cards (after search/filter)
                const visibleCards = Array.from(rapportsContainer.querySelectorAll('.rapport-card'));
                const visibleReportIds = visibleCards.map(card => card.getAttribute('data-id'));
                dataToExport = rapportsData.filter(r => visibleReportIds.includes(r.id_rapport));
            }

            const rows = dataToExport.map(rapport => [
                rapport.id_rapport || 'N/A',
                rapport.theme_rapport || 'N/A',
                rapport.num_etudiant || 'N/A',
                rapport.nom_etudiant || 'N/A',
                formatDate(rapport.date_soumission),
                getStatusDisplayText(rapport.statut)
            ]);
            
            return { headers, rows };
        }

        // Export to PDF
        exportPdfBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) {
                    showAlert("Aucune donnée visible à exporter.", 'warning');
                    return;
                }
                
                const doc = new jsPDF('landscape'); // Use landscape for more columns
                doc.setFontSize(14);
                doc.text('Liste des Rapports', 14, 15);
                doc.setFontSize(10);
                doc.text(`Exporté le: ${new Date().toLocaleDateString('fr-FR')}`, 14, 22);
                doc.autoTable({
                    head: [headers],
                    body: rows,
                    startY: 25,
                    styles: { fontSize: 8 },
                    headStyles: { fillColor: [59, 130, 246] },
                    margin: { left: 10, right: 10 }
                });
                
                doc.save(`liste_rapports_${new Date().toISOString().slice(0,10)}.pdf`);
                showAlert("Export PDF réussi !", 'success');
            } catch (error) {
                console.error("Erreur lors de l'export PDF:", error);
                showAlert("Erreur lors de l'export PDF", 'error');
            }
        });

        // Export to Excel
        exportExcelBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) {
                    showAlert("Aucune donnée visible à exporter.", 'warning');
                    return;
                }
                
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
                XLSX.utils.book_append_sheet(wb, ws, "Rapports");
                XLSX.writeFile(wb, `liste_rapports_${new Date().toISOString().slice(0,10)}.xlsx`);
                showAlert("Export Excel réussi !", 'success');
            } catch (error) {
                console.error("Erreur lors de l'export Excel:", error);
                showAlert("Erreur lors de l'export Excel", 'error');
            }
        });

        // Export to CSV
        exportCsvBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) {
                    showAlert("Aucune donnée visible à exporter.", 'warning');
                    return;
                }
                
                let csvContent = headers.map(h => `"${h}"`).join(";") + "\n";
                rows.forEach(row => csvContent += row.map(cell => `"${cell}"`).join(";") + "\n");
                
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement("a");
                const url = URL.createObjectURL(blob);
                
                link.setAttribute("href", url);
                link.setAttribute("download", `liste_rapports_${new Date().toISOString().slice(0,10)}.csv`);
                link.style.visibility = 'hidden';
                
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                showAlert("Export CSV réussi !", 'success');
            } catch (error) {
                console.error("Erreur lors de l'export CSV:", error);
                showAlert("Erreur lors de l'export CSV", 'error');
            }
        });

        // --- Search, Filter, Sort Logic ---

        // Global function to apply all filters and sorting
        function applyFiltersAndSort(sortType, statusFilter, searchTerm) {
            let filteredAndSortedData = [...rapportsData];

            // 1. Apply Search
            if (searchTerm) {
                const lowerCaseSearchTerm = searchTerm.toLowerCase();
                filteredAndSortedData = filteredAndSortedData.filter(rapport => {
                    return (
                        (rapport.theme_rapport && rapport.theme_rapport.toLowerCase().includes(lowerCaseSearchTerm)) ||
                        (rapport.nom_etudiant && rapport.nom_etudiant.toLowerCase().includes(lowerCaseSearchTerm)) ||
                        (rapport.num_etudiant && rapport.num_etudiant.toString().includes(lowerCaseSearchTerm)) ||
                        (rapport.statut && getStatusDisplayText(rapport.statut).toLowerCase().includes(lowerCaseSearchTerm))
                    );
                });
            }

            // 2. Apply Status Filter
            if (statusFilter !== 'all_statuses') {
                filteredAndSortedData = filteredAndSortedData.filter(rapport => rapport.statut === statusFilter);
            }

            // 3. Apply Sorting
            filteredAndSortedData.sort((a, b) => {
                switch (sortType) {
                    case 'theme-asc': 
                        return (a.theme_rapport || '').localeCompare(b.theme_rapport || '');
                    case 'theme-desc':
                        return (b.theme_rapport || '').localeCompare(a.theme_rapport || '');
                    case 'student-asc':
                        return (a.nom_etudiant || '').localeCompare(b.nom_etudiant || '');
                    case 'student-desc':
                        return (b.nom_etudiant || '').localeCompare(a.nom_etudiant || '');
                    case 'date-asc':
                        return new Date(a.date_soumission) - new Date(b.date_soumission);
                    case 'date-desc':
                        return new Date(b.date_soumission) - new Date(a.date_soumission);
                    case 'default':
                    default:
                        // Default is latest submission first
                        return new Date(b.date_soumission) - new Date(a.date_soumission);
                }
            });

            renderCards(filteredAndSortedData);
            updateFilterButtonText();
        }

        // Search event listeners
        searchInput.addEventListener('input', () => {
            // When searching, reset filter selections visually and logically
            document.querySelector('input[name="sort_radio"][value="default"]').checked = true;
            document.querySelector('input[name="status_filter_radio"][value="all_statuses"]').checked = true;

            currentSortType = 'default';
            currentStatusFilter = 'all_statuses';

            applyFiltersAndSort(
                currentSortType,
                currentStatusFilter,
                searchInput.value
            );
        });
        searchButton.addEventListener('click', () => {
            // Same logic as input for consistency when button is clicked
            document.querySelector('input[name="sort_radio"][value="default"]').checked = true;
            document.querySelector('input[name="status_filter_radio"][value="all_statuses"]').checked = true;

            currentSortType = 'default';
            currentStatusFilter = 'all_statuses';

            applyFiltersAndSort(
                currentSortType,
                currentStatusFilter,
                searchInput.value
            );
        });

        // Filter modal handling
        filterButton.addEventListener('click', function(event) {
            event.stopPropagation();
            filterModal.style.display = 'flex';
        });

        closeFilterModalBtn.addEventListener('click', function() {
            filterModal.style.display = 'none';
        });

        filterModal.addEventListener('click', function(e) {
            if (e.target === filterModal) {
                filterModal.style.display = 'none';
            }
        });

        applyFilterModalBtn.addEventListener('click', function() {
            const selectedSortRadio = document.querySelector('input[name="sort_radio"]:checked');
            if (selectedSortRadio) {
                currentSortType = selectedSortRadio.value;
            }

            const selectedStatusFilterRadio = document.querySelector('input[name="status_filter_radio"]:checked');
            if (selectedStatusFilterRadio) {
                currentStatusFilter = selectedStatusFilterRadio.value;
            }
            
            // Clear search input when applying filters from modal
            searchInput.value = '';

            applyFiltersAndSort(
                currentSortType,
                currentStatusFilter,
                searchInput.value
            );
            filterModal.style.display = 'none';
        });

        resetFilterModalBtn.addEventListener('click', function() {
            currentSortType = 'default';
            currentStatusFilter = 'all_statuses';
            searchInput.value = '';

            document.querySelector('input[name="sort_radio"][value="default"]').checked = true;
            document.querySelector('input[name="status_filter_radio"][value="all_statuses"]').checked = true;

            applyFiltersAndSort(currentSortType, currentStatusFilter, '');
            filterModal.style.display = 'none';
            showAlert('Filtres et recherche réinitialisés.', 'info');
        });

        // Update filter button text based on active filters
        function updateFilterButtonText() {
            let activeFiltersCount = 0;
            if (currentSortType !== 'default') {
                activeFiltersCount++;
            }
            if (currentStatusFilter !== 'all_statuses') {
                activeFiltersCount++;
            }
            if (searchInput.value.trim() !== '') {
                activeFiltersCount++;
            }

            if (activeFiltersCount > 0) {
                filterButtonText.textContent = `Filtres (${activeFiltersCount} actifs)`;
                filterButton.classList.add('btn-active-filter');
            } else {
                filterButtonText.textContent = 'Filtres';
                filterButton.classList.remove('btn-active-filter');
            }
        }

        // --- Analysis Section ---

        // Selection of an analysis option
        document.querySelectorAll('.analyse-option').forEach(option => {
            option.addEventListener('click', function() {
                document.querySelectorAll('.analyse-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
                selectedAnalyseType = this.dataset.analyse;
            });
        });

        // Launch analysis
        lancerAnalyseBtn.addEventListener('click', async function() {
            if (!selectedRapportId) {
                showAlert('Veuillez sélectionner un rapport à analyser', 'warning');
                return;
            }

            if (!selectedAnalyseType) {
                showAlert('Veuillez sélectionner un type d\'analyse', 'warning');
                return;
            }

            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyse en cours...';
            this.disabled = true;
            showLoading(true);

            // In a real application, you'd send an AJAX request to a backend
            // that performs the actual analysis (plagiarism, fraud, AI detection).
            // For this example, we simulate a delay and random results.
            await new Promise(resolve => setTimeout(resolve, 2000)); // Simulate API call delay

            // Simulate random results
            const score = Math.floor(Math.random() * 80) + 10; // 10-90%
            let conclusion = '';
            let sources = [];

            switch(selectedAnalyseType) {
                case 'plagiat':
                    conclusion = 'Ce rapport présente un taux de similarité modéré avec d\'autres sources. Une vérification manuelle est recommandée pour les sections concernées.';
                    sources = [
                        { name: 'Source académique A', similarity: (score * 0.4).toFixed(0) + '%' },
                        { name: 'Site web B', similarity: (score * 0.3).toFixed(0) + '%' },
                        { name: 'Document interne C', similarity: (score * 0.2).toFixed(0) + '%' }
                    ];
                    if (score > 60) {
                        conclusion = 'Taux de plagiat élevé détecté. Des actions disciplinaires peuvent être nécessaires.';
                    } else if (score < 20) {
                        conclusion = 'Très faible probabilité de plagiat. Rapport original.';
                    }
                    break;
                case 'fraude':
                    conclusion = 'Aucun signe évident de fraude détecté. Le style d\'écriture semble cohérent avec le niveau académique attendu.';
                    if (score > 50) {
                         conclusion = 'Des anomalies comportementales ont été détectées, suggérant une possible fraude. Examen approfondi requis.';
                         sources = [
                            { name: 'Modèle de comportement inhabituel', similarity: (score * 0.7).toFixed(0) + '%' },
                         ];
                    } else if (score < 30) {
                        conclusion = 'Aucun indicateur de fraude détecté. Le rapport semble légitime.';
                    }
                    break;
                case 'ia':
                    conclusion = 'Le rapport présente certaines caractéristiques pouvant indiquer une génération automatique. Vérification manuelle recommandée.';
                    if (score < 30) {
                        conclusion = 'Faible probabilité de génération par IA.';
                    } else if (score > 70) {
                        conclusion = 'Forte probabilité de génération par IA. Recommandation: entretien oral de validation.';
                    }
                    break;
            }

            // Update result modal
            updateResultModal(selectedAnalyseType, selectedRapportTheme, selectedRapportEtudiant, score, sources, conclusion);

            // Show modal
            resultModal.style.display = 'flex';

            // Reset button
            this.innerHTML = '<i class="fas fa-play"></i> Lancer l\'analyse';
            this.disabled = false;
            showLoading(false);
        });

        // Function to update the analysis result modal
        function updateResultModal(analyseType, theme, etudiant, score, sources, conclusion) {
            document.getElementById('analyseType').textContent =
                analyseType === 'plagiat' ? 'Détection de plagiat' :
                analyseType === 'fraude' ? 'Détection de fraude' :
                'Analyse par intelligence artificielle';

            document.getElementById('rapportAnalyse').textContent = `${theme} - ${etudiant}`;
            document.getElementById('similarityValue').textContent = `${score}%`;
            document.getElementById('similarityBar').style.width = `${score}%`;

            const sourcesList = document.getElementById('suspiciousSources').querySelector('ul');
            sourcesList.innerHTML = ''; // Clear previous sources
            if (sources.length > 0) {
                sources.forEach(source => {
                    const li = document.createElement('li');
                    li.textContent = `${source.name}: ${source.similarity}`;
                    sourcesList.appendChild(li);
                });
            } else {
                sourcesList.innerHTML = '<li>Aucune source suspecte majeure détectée.</li>';
            }

            document.getElementById('analyseConclusion').textContent = conclusion;

            // Update action buttons based on analysis type or score
            if (selectedAnalyseType === 'plagiat' && score > 50) {
                // If high plagiarism, suggest flagging strongly
                markVerifiedBtnSingle.style.display = 'none';
                flagReportBtnSingle.style.display = 'inline-flex';
            } else {
                markVerifiedBtnSingle.style.display = 'inline-flex';
                flagReportBtnSingle.style.display = 'inline-flex';
            }
        }

        // Close analysis result modal
        function closeResultModal() {
            resultModal.style.display = 'none';
        }

        // Action: Mark as Verified (single report from analysis modal)
        markVerifiedBtnSingle.addEventListener('click', async function() {
            if (!selectedRapportId) return;

            if (confirm("Confirmez-vous que ce rapport est vérifié et valide ?")) {
                showLoading(true);
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'mark_verified',
                            report_id: selectedRapportId
                        })
                    });
                    const result = await response.json();

                    if (result.success) {
                        showAlert(result.message, 'success');
                        closeResultModal();
                        loadRapports(); // Reload the list to update status and potentially remove the card
                        selectedRapportId = null; // Clear selection
                        analyseSection.style.display = 'none'; // Hide analysis section
                    } else {
                        showAlert('Erreur: ' + result.message, 'error');
                    }
                } catch (error) {
                    console.error('Error marking as verified:', error);
                    showAlert('Une erreur est survenue lors de la validation du rapport.', 'error');
                } finally {
                    showLoading(false);
                }
            }
        });

        // Action: Flag (single report from analysis modal)
        flagReportBtnSingle.addEventListener('click', async function() {
            if (!selectedRapportId) return;

            const comment = prompt("Veuillez entrer un commentaire pour signaler ce rapport:");
            if (comment !== null && comment.trim() !== "") {
                showLoading(true);
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'flag_report',
                            report_id: selectedRapportId,
                            comment: comment
                        })
                    });
                    const result = await response.json();

                    if (result.success) {
                        showAlert(result.message, 'success');
                        closeResultModal();
                        loadRapports(); // Reload the list
                        selectedRapportId = null; // Clear selection
                        analyseSection.style.display = 'none'; // Hide analysis section
                    } else {
                        showAlert('Erreur: ' + result.message, 'error');
                    }
                } catch (error) {
                    console.error('Error flagging report:', error);
                    showAlert('Une erreur est survenue lors du signalement du rapport.', 'error');
                } finally {
                    showLoading(false);
                }
            } else {
                showAlert("Le commentaire est requis pour signaler un rapport.", 'warning');
            }
        });


        // --- Report Details / Preview Modal (new structure) ---
        // This modal is not explicitly defined in the original `charge_verification.php`
        // but implied by the "Prévisualiser" button. Let's create a simplified one
        // or just use an alert for demonstration purposes as full content isn't available.
        // For a real application, you would load the report content here.

        async function showReportDetailsModal(reportId) {
            try {
                showLoading(true);
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'get_report_details', report_id: reportId })
                });
                const result = await response.json();

                if (result.success && result.data) {
                    currentReportDetails = result.data;
                    const report = currentReportDetails;

                    // For demonstration, let's just use an alert with more details.
                    // In a real scenario, you'd populate a dedicated modal for this.
                    let detailsText = `
                        ID Rapport: ${report.id_rapport}
                        Thème: ${report.theme_rapport}
                        Étudiant: ${report.nom_etudiant} (${report.num_etudiant})
                        Soumis le: ${formatDate(report.date_soumission)}
                        Statut: ${getStatusDisplayText(report.statut)}
                        ${report.commentaire_signalement ? `Commentaire signalement: ${report.commentaire_signalement}` : ''}
                        
                        Contenu (extrait):
                        ${report.contenu_rapport ? report.contenu_rapport.substring(0, 200) + '...' : 'Contenu non disponible'}
                    `;
                    showAlert(detailsText, 'info', `Détails du Rapport: ${report.theme_rapport}`);

                    // If you had a dedicated modal for preview:
                    /*
                    document.getElementById('previewModalTitle').textContent = `Prévisualisation: ${report.theme_rapport}`;
                    document.getElementById('previewModalContent').innerHTML = `
                        <p><strong>Étudiant:</strong> ${report.nom_etudiant} (${report.num_etudiant})</p>
                        <p><strong>Date Soumission:</strong> ${formatDate(report.date_soumission)}</p>
                        <p><strong>Statut:</strong> <span class="badge ${getStatusCssClass(report.statut)}">${getStatusDisplayText(report.statut)}</span></p>
                        ${report.commentaire_signalement ? `<p><strong>Commentaire:</strong> ${report.commentaire_signalement}</p>` : ''}
                        <hr>
                        <h4>Contenu du Rapport:</h4>
                        <div style="white-space: pre-wrap; max-height: 300px; overflow-y: auto; border: 1px solid var(--gray-200); padding: var(--space-3); border-radius: var(--radius-md);">
                            ${htmlspecialchars(report.contenu_rapport || 'Contenu non disponible.')}
                        </div>
                    `;
                    document.getElementById('reportPreviewModal').style.display = 'flex';
                    */

                } else {
                    showAlert(result.message || 'Erreur lors du chargement des détails du rapport.', 'error');
                }
            } catch (error) {
                console.error('Error fetching report details:', error);
                showAlert('Une erreur est survenue lors du chargement des détails du rapport.', 'error');
            } finally {
                showLoading(false);
            }
        }


        // --- Initialization ---
        document.addEventListener('DOMContentLoaded', function() {
            loadRapports();
            initSidebar(); // Initialize sidebar responsiveness
            updateFilterButtonText(); // Set initial filter button text

            // Expose functions to global scope for inline onclicks
            window.markSelectedVerified = markSelectedVerified;
            window.flagSelectedReports = flagSelectedReports;
            window.closeResultModal = closeResultModal; // For analysis result modal close button
        });

        // Responsive Sidebar setup (copied for consistency)
        function initSidebar() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (sidebarToggle && sidebar && mainContent) {
                // Initial state based on window width
                handleResponsiveLayout();
                
                sidebarToggle.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        sidebar.classList.toggle('mobile-open');
                        mobileMenuOverlay.classList.toggle('active');
                        // Toggle icon
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
                    // Reset icon
                    const barsIcon = sidebarToggle.querySelector('.fa-bars');
                    const timesIcon = sidebarToggle.querySelector('.fa-times');
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                });
            }

            window.addEventListener('resize', handleResponsiveLayout);
        }

        // Responsive layout adjustments
        function handleResponsiveLayout() {
            const actionTexts = document.querySelectorAll('.action-text'); // No specific action-text for this page, but good to keep if added later
            const isMobile = window.innerWidth < 768;

            actionTexts.forEach(text => {
                text.style.display = isMobile ? 'none' : 'inline';
            });

            // Adjust sidebar state
            if (isMobile) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('sidebar-collapsed');
                sidebar.classList.remove('mobile-open'); // Ensure it's closed on resize to mobile
                mobileMenuOverlay.classList.remove('active'); // Hide overlay
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('sidebar-collapsed');
                sidebar.classList.remove('mobile-open'); // Ensure it's closed if was open on mobile and resized to desktop
                mobileMenuOverlay.classList.remove('active'); // Hide overlay
            }
            
            // Adjust sidebar toggle icon for mobile
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
                // Specific override if sidebar is actually open (mobile-open class)
                if (sidebar.classList.contains('mobile-open')) {
                    if (barsIcon) barsIcon.style.display = 'none';
                    if (timesIcon) timesIcon.style.display = 'inline-block';
                }
            }
        }
    </script>
</body>
</html>