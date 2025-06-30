<?php
// mes_evaluations.php
require_once 'config.php'; // Ensure this path is correct for your setup

if (!isLoggedIn()) { // Check if user is logged in
    redirect('loginForm.php'); // Redirect to login form if not logged in
}

// Assume student ID is available, e.g., from session after login
// For demonstration purposes, we'll hardcode one. REPLACE THIS IN PRODUCTION!
$loggedInStudentNumEtu = 'LOGJAM020307'; // Placeholder student ID

// Récupération des années académiques pour le filtre
$anneesAcademiques = []; // Initialize empty array for academic years
try {
    $stmt = $pdo->query("SELECT id_Ac, CONCAT(YEAR(date_deb), '-', YEAR(date_fin)) as annee_libelle FROM année_academique ORDER BY date_deb DESC"); // Query to get academic years
    $anneesAcademiques = $stmt->fetchAll(PDO::FETCH_ASSOC); // Fetch all academic years
} catch (PDOException $e) {
    error_log("Erreur récupération années académiques: " . $e->getMessage()); // Log error if fetching academic years fails
    // Handle error gracefully, e.g., display an error message on the page
}

// Get the most recent academic year for automatic bulletin generation
$mostRecentAnneeId = 0;
if (!empty($anneesAcademiques)) {
    $mostRecentAnneeId = $anneesAcademiques[0]['id_Ac']; // Assumes array is already sorted DESC by date_deb
}


// === TRAITEMENT AJAX ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') { // Check if the request method is POST
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'] ?? ''; // Get the action from POST data

        switch ($action) { // Switch based on the action
            case 'rechercher_evaluations': // Action to search evaluations
                $anneeId = intval($_POST['annee_id'] ?? 0); // Get academic year ID
                $searchTerm = trim($_POST['search_term'] ?? ''); // Get search term

                $whereConditions = ["eval.fk_num_etu = ?"]; // Initial WHERE condition for student ID
                $params = [$loggedInStudentNumEtu]; // Parameters for the query

                if ($anneeId > 0) { // Add academic year filter if provided
                    $whereConditions[] = "eval.fk_id_Ac = ?"; // Add condition for academic year
                    $params[] = $anneeId; // Add academic year to parameters
                }

                if (!empty($searchTerm)) { // Add search term filter if provided
                    $whereConditions[] = "(ue.lib_UE LIKE ? OR ecue.lib_ECUE LIKE ?)"; // Add condition for UE or ECUE name
                    $params[] = "%{$searchTerm}%"; // Add search term to parameters
                    $params[] = "%{$searchTerm}%"; // Add search term to parameters again
                }

                $whereClause = 'WHERE ' . implode(' AND ', $whereConditions); // Combine all WHERE conditions

                $sql = "
                    SELECT
                        eval.id_eval,
                        ue.lib_UE,
                        ecue.lib_ECUE,
                        ecue.credit_ECUE,
                        eval.dte_eval,
                        eval.note,
                        eval.fk_id_Ac,
                        aa.date_deb,
                        aa.date_fin
                    FROM
                        evaluer eval
                    JOIN
                        ecue ON eval.fk_id_ECUE = ecue.id_ECUE
                    JOIN
                        ue ON ecue.fk_id_UE = ue.id_UE
                    JOIN
                        année_academique aa ON eval.fk_id_Ac = aa.id_Ac
                    {$whereClause}
                    ORDER BY
                        eval.dte_eval DESC, ue.lib_UE, ecue.lib_ECUE
                "; // SQL query to fetch evaluations

                $stmt = $pdo->prepare($sql); // Prepare the SQL statement
                $stmt->execute($params); // Execute the statement with parameters
                $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC); // Fetch all results

                echo json_encode(['success' => true, 'data' => $evaluations]); // Return success and data as JSON
                break;

            case 'obtenir_details_evaluation': // Action to get details of a single evaluation
                $idEval = intval($_POST['id_eval'] ?? 0);

                if ($idEval <= 0) {
                    throw new Exception("ID d'évaluation manquant.");
                }

                $sql = "
                    SELECT
                        eval.id_eval,
                        ue.lib_UE,
                        ecue.lib_ECUE,
                        ecue.credit_ECUE,
                        eval.dte_eval,
                        eval.note,
                        aa.date_deb,
                        aa.date_fin,
                        e.nom_etu,
                        e.prenoms_etu,
                        ens.nom_ens,
                        ens.prenom_ens
                    FROM
                        evaluer eval
                    JOIN
                        ecue ON eval.fk_id_ECUE = ecue.id_ECUE
                    JOIN
                        ue ON ecue.fk_id_UE = ue.id_UE
                    JOIN
                        année_academique aa ON eval.fk_id_Ac = aa.id_Ac
                    LEFT JOIN
                        etudiant e ON eval.fk_num_etu = e.num_etu
                    LEFT JOIN
                        enseignant ens ON eval.fk_id_ens = ens.id_ens
                    WHERE
                        eval.id_eval = ?
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([$idEval]);
                $evaluationDetails = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$evaluationDetails) {
                    throw new Exception("Détails de l'évaluation introuvables.");
                }

                echo json_encode(['success' => true, 'data' => $evaluationDetails]);
                break;

            case 'obtenir_bulletin': // Action to get student transcript (bulletin)
                $numEtu = trim($_POST['num_etu'] ?? '');
                $anneeId = intval($_POST['annee_id'] ?? 0);

                if (empty($numEtu) || $anneeId <= 0) {
                    throw new Exception("Paramètres manquants pour le bulletin.");
                }

                // Récupérer les infos de l'étudiant
                $stmtEtu = $pdo->prepare("
                    SELECT e.*, f.lib_filiere, ne.lib_niv_etu,
                           CONCAT(YEAR(aa.date_deb), '-', YEAR(aa.date_fin)) as annee_libelle
                    FROM etudiant e
                    LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                    LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                    LEFT JOIN inscrire i ON e.num_etu = i.fk_num_etu AND i.fk_id_Ac = ?
                    LEFT JOIN année_academique aa ON i.fk_id_Ac = aa.id_Ac
                    WHERE e.num_etu = ? AND i.fk_id_Ac = ?
                ");
                $stmtEtu->execute([$anneeId, $numEtu, $anneeId]);
                $etudiant = $stmtEtu->fetch(PDO::FETCH_ASSOC);

                if (!$etudiant) {
                    throw new Exception("Étudiant non trouvé ou non inscrit pour cette année académique.");
                }

                // Récupérer toutes les notes de l'étudiant
                $stmtNotes = $pdo->prepare("
                    SELECT u.lib_UE, ec.lib_ECUE, ec.credit_ECUE, ev.note, ev.dte_eval
                    FROM evaluer ev
                    INNER JOIN ecue ec ON ev.fk_id_ECUE = ec.id_ECUE
                    INNER JOIN ue u ON ec.fk_id_UE = u.id_UE
                    WHERE ev.fk_num_etu = ? AND ev.fk_id_Ac = ?
                    ORDER BY u.lib_UE, ec.lib_ECUE
                ");
                $stmtNotes->execute([$numEtu, $anneeId]);
                $notes = $stmtNotes->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'etudiant' => $etudiant,
                    'notes' => $notes
                ]);
                break;

            default:
                throw new Exception("Action non reconnue");
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
    <title>SYGECOS - Mes Évaluations</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
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
        .sidebar-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); position: relative; } .sidebar-toggle:hover { background: var(--gray-200); color: var(--gray-800); }
        .page-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-800); }
        .topbar-right { display: flex; align-items: center; gap: var(--space-4); }
        .topbar-button { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); position: relative; } .topbar-button:hover { background: var(--gray-200); color: var(--gray-800); }
        .notification-badge { position: absolute; top: -2px; right: -2px; width: 18px; height: 18px; background: var(--error-500); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: white; }

        /* === PAGE CONTENT === */
        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); display: flex; justify-content: space-between; align-items: center; }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); margin-top: var(--space-2); }

        .form-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-8); }
        .form-card-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-6); border-bottom: 1px solid var(--gray-200); padding-bottom: var(--space-4); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-6); }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: var(--text-sm); font-weight: 500; color: var(--gray-700); margin-bottom: var(--space-2); }
        .form-group input[type="text"], .form-group input[type="number"], .form-group input[type="date"], .form-group select, .form-group textarea { padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); font-size: var(--text-base); color: var(--gray-800); transition: all var(--transition-fast); }
        .form-group input[type="text"]:focus, .form-group input[type="number"]:focus, .form-group input[type="date"]:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--accent-500); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }

        .form-actions { display: flex; gap: var(--space-4); justify-content: flex-end; }
        .btn { padding: var(--space-3) var(--space-5); border-radius: var(--radius-md); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); text-decoration: none; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover:not(:disabled) { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover:not(:disabled) { background-color: var(--gray-300); }
        .btn-info { background-color: var(--info-500); color: white; } .btn-info:hover:not(:disabled) { background-color: #316be6; }
        .btn-sm { padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }


        .table-container { background: var(--white); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); overflow: hidden; margin-bottom: var(--space-8); }
        .table-header { padding: var(--space-6); border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-4); }
        .table-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .table-actions { display: flex; gap: var(--space-3); align-items: center; }

        .table-wrapper { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; min-width: 700px; }
        .data-table th, .data-table td { padding: var(--space-3); text-align: left; border-bottom: 1px solid var(--gray-200); font-size: var(--text-sm); }
        .data-table th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); }
        .data-table tbody tr:hover { background-color: var(--gray-50); }
        .data-table td { color: var(--gray-800); }

        .badge { padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; }
        .badge-success { background-color: var(--secondary-100); color: var(--secondary-600); }
        .badge-warning { background-color: #fef3c7; color: #d97706; }
        .badge-info { background-color: var(--accent-100); color: var(--accent-600); }

        /* Messages d'alerte */
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

        .empty-state { text-align: center; padding: var(--space-16); color: var(--gray-500); }
        .empty-state i { font-size: 3rem; margin-bottom: var(--space-4); }

        /* Loading */
        .loading-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); display: none; align-items: center; justify-content: center; z-index: 9999; }
        .loading-spinner { width: 40px; height: 40px; border: 4px solid var(--gray-300); border-top-color: var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Modal Styles - Evaluation Details (Adapted from liste_etudiants.php) */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 10000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.6); /* Darker overlay */
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background-color: #fefefe;
            padding: var(--space-8); /* More padding */
            border-radius: var(--radius-2xl); /* More rounded corners */
            box-shadow: var(--shadow-xl);
            width: 95%; /* Wider on smaller screens */
            max-width: 700px;
            max-height: 90vh; /* Limit height to viewport */
            overflow-y: auto; /* Scroll if content overflows */
            position: relative;
            transform: translateY(-50px);
            transition: transform 0.3s ease;
            box-sizing: border-box; /* Include padding in width/height */
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: var(--space-4);
            margin-bottom: var(--space-6);
        }

        .modal-header h2 {
            font-size: var(--text-2xl);
            color: var(--gray-900);
            font-weight: 700;
        }

        .modal-close {
            color: var(--gray-500);
            font-size: var(--text-3xl); /* Larger close icon */
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s ease;
            line-height: 1; /* Align vertically */
        }

        .modal-close:hover,
        .modal-close:focus {
            color: var(--gray-800);
            text-decoration: none;
        }

        .modal-body {
            padding-bottom: var(--space-6);
        }

        .detail-group {
            margin-bottom: var(--space-6); /* Space between groups */
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            background-color: var(--gray-50);
        }

        .detail-group-title {
            font-size: var(--text-lg);
            font-weight: 600;
            color: var(--primary-700);
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-2);
            border-bottom: 1px dashed var(--gray-300);
        }

        .detail-item {
            display: flex;
            flex-wrap: wrap; /* Allow wrapping on small screens */
            margin-bottom: var(--space-3); /* More space between items */
            font-size: var(--text-base);
            align-items: baseline;
        }

        .detail-item strong {
            flex-basis: 180px; /* Wider label column */
            color: var(--gray-700);
            font-weight: 600;
            padding-right: var(--space-2);
            line-height: 1.5;
        }

        .detail-item span {
            flex: 1;
            color: var(--gray-800);
            line-height: 1.5;
        }

        @media (max-width: 500px) {
            .detail-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .detail-item strong {
                flex-basis: auto;
                width: 100%;
                margin-bottom: var(--space-1);
            }
        }

        .modal-footer {
            border-top: 1px solid var(--gray-200);
            padding-top: var(--space-4);
            text-align: right;
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
        }


        /* Bulletin étudiant format A4 - From gestion_evaluations.php */
        .bulletin { padding: var(--space-6); font-family: 'Times New Roman', serif; }
        .bulletin-header { text-align: center; margin-bottom: var(--space-8); border-bottom: 2px solid var(--gray-900); padding-bottom: var(--space-4); }
        .bulletin-title { font-size: var(--text-2xl); font-weight: bold; color: var(--gray-900); margin-bottom: var(--space-2); }
        .bulletin-subtitle { font-size: var(--text-lg); color: var(--gray-600); }
        .bulletin-student-info { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-6); margin-bottom: var(--space-8); }
        .bulletin-notes-table { width: 100%; border-collapse: collapse; margin-bottom: var(--space-6); }
        .bulletin-notes-table th, .bulletin-notes-table td { padding: var(--space-3); border: 1px solid var(--gray-900); text-align: left; }
        .bulletin-notes-table th { background-color: var(--gray-100); font-weight: bold; }
        .bulletin-summary { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-6); margin-top: var(--space-6); }
        .info-section { background: var(--gray-50); padding: var(--space-4); border-radius: var(--radius-md); }
        .info-section h4 { font-size: var(--text-lg); margin-bottom: var(--space-3); color: var(--gray-900); }
        .info-item { display: flex; justify-content: space-between; margin-bottom: var(--space-2); }
        .info-label { font-weight: 600; }
        /* Print styles pour le bulletin */
        @media print {
            .modal-header, .btn, .topbar, .sidebar, .form-card, .search-bar, .table-header, .filter-modal, .message-modal, .loading-overlay { display: none !important; } /* Hide print controls, topbar, sidebar, form card */
            .modal-content { width: 100% !important; max-width: none !important; margin: 0 !important; box-shadow: none !important; border-radius: 0 !important; max-height: none !important; overflow: visible !important;}
            .bulletin { padding: 1cm !important; }
            body { background-color: white !important; }
        }
        .note-badge { padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm); font-size: var(--text-xs); font-weight: 600; text-align: center; }
        .note-badge.excellent { background: var(--secondary-100); color: var(--secondary-800); }
        .note-badge.tres-bien { background: var(--accent-100); color: var(--accent-800); } /* Added for consistency */
        .note-badge.bien { background: var(--accent-100); color: var(--accent-800); }
        .note-badge.passable { background: #fef3c7; color: #92400e; }
        .note-badge.insuffisant { background: #fecaca; color: #dc2626; }

        /* Search Bar (from liste_etudiants.php) */
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

        .btn-active-filter { /* Style for active filter button */
            background-color: var(--accent-200);
            color: var(--accent-800);
        }
        /* Filter Modal (from liste_etudiants.php) */
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
        <?php include 'sidebar_etudiant.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1 class="page-title-main">Mes Évaluations</h1>
                        <p class="page-subtitle">Consultez vos évaluations passées et à venir</p>
                    </div>
                    <div>
                        <button class="btn btn-primary" id="downloadBulletinBtn">
                            <i class="fas fa-file-alt"></i> Télécharger mon Bulletin
                        </button>
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
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher par UE ou ECUE...">
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

                <div class="table-container">
                    <div class="table-header">
                        <h3 class="table-title">
                            <i class="fas fa-clipboard-list"></i> Liste de mes Évaluations (<span id="evaluationCount">0</span>)
                        </h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" id="filterButton">
                                <i class="fas fa-filter"></i> <span id="filterButtonText">Filtres</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Libellé UE</th>
                                    <th>Libellé ECUE</th>
                                    <th>Crédit ECUE</th>
                                    <th>Date d'évaluation</th>
                                    <th>Note (/20)</th>
                                    <th>Appréciation</th>
                                    <th style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="evaluationsTableBody">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="evaluationDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Détails de l'évaluation</h2>
                <span class="modal-close" onclick="closeModal('evaluationDetailsModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="detail-group">
                    <h3 class="detail-group-title">Informations sur l'Évaluation</h3>
                    <div class="detail-item">
                        <strong>Unité d'Enseignement (UE):</strong> <span id="modalUeLibelle"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Élément Constitutif d'Unité d'Enseignement (ECUE):</strong> <span id="modalEcueLibelle"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Crédits ECUE:</strong> <span id="modalCreditEcue"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Date d'évaluation:</strong> <span id="modalDateEvaluation"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Note:</strong> <span id="modalNote"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Appréciation:</strong> <span id="modalAppreciation"></span>
                    </div>
                </div>

                <div class="detail-group">
                    <h3 class="detail-group-title">Informations Contextuelles</h3>
                    <div class="detail-item">
                        <strong>Année Académique:</strong> <span id="modalAnneeAcademique"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Évalué par:</strong> <span id="modalEvaluateur"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('evaluationDetailsModal')" class="btn btn-secondary">
                    Fermer
                </button>
                <button onclick="downloadEvaluationDetailsPdf()" class="btn btn-primary">
                    <i class="fas fa-file-pdf"></i> Télécharger PDF
                </button>
            </div>
        </div>
    </div>

    <div id="bulletinModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Bulletin de l'étudiant</h3>
                <div>
                    <button class="btn btn-primary btn-sm" onclick="imprimerBulletin()">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                    <button class="btn btn-info btn-sm" onclick="downloadBulletinPdf()">
                        <i class="fas fa-file-pdf"></i> Télécharger PDF
                    </button>
                    <span class="modal-close" onclick="closeModal('bulletinModal')">&times;</span>
                </div>
            </div>
            <div id="bulletinContent" class="bulletin">
                </div>
        </div>
    </div>

    <div class="filter-modal" id="filterModal">
        <div class="filter-modal-content">
            <div class="filter-modal-header">
                <h3 class="filter-modal-title">Filtres et Tri des Évaluations</h3>
                <button class="filter-modal-close" id="closeFilterModal">&times;</button>
            </div>
            <div class="filter-group">
                <label>Trier par:</label>
                <div class="filter-option-group">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="default" checked>
                            <i class="fas fa-list"></i> Ordre initial
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="ue-asc">
                            <i class="fas fa-sort-alpha-down"></i> UE (A-Z)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="ue-desc">
                            <i class="fas fa-sort-alpha-up"></i> UE (Z-A)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="ecue-asc">
                            <i class="fas fa-sort-alpha-down"></i> ECUE (A-Z)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="ecue-desc">
                            <i class="fas fa-sort-alpha-up"></i> ECUE (Z-A)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="date-desc">
                            <i class="fas fa-calendar-alt"></i> Date (plus récent)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="date-asc">
                            <i class="fas fa-calendar-alt"></i> Date (plus ancien)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="note-desc">
                            <i class="fas fa-sort-numeric-down"></i> Note (plus haute)
                        </label>
                    </div>
                     <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="note-asc">
                            <i class="fas fa-sort-numeric-up"></i> Note (plus basse)
                        </label>
                    </div>
                </div>
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
                    <?php foreach ($anneesAcademiques as $annee): ?>
                        <div class="filter-option radio-group">
                            <label>
                                <input type="radio" name="annee_filter_radio" value="<?php echo htmlspecialchars($annee['id_Ac']); ?>">
                                <?php echo htmlspecialchars($annee['annee_libelle']); ?>
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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        // Initialiser jsPDF
        window.jsPDF = window.jspdf.jsPDF;

        // Global Variables
        let allEvaluations = []; // Stores all fetched evaluations for the current filters
        let currentDisplayedEvaluations = []; // Stores evaluations currently displayed after search/filter
        const loggedInStudentNumEtu = "<?php echo $loggedInStudentNumEtu; ?>"; // Student ID from PHP
        let currentEvaluationDetails = null; // To store evaluation details for PDF export
        let currentBulletinData = null; // To store bulletin data for PDF export

        // Filter and Sort states
        let currentSortType = 'default';
        let currentAnneeFilter = 'all_annees';

        // DOM elements
        const evaluationsTableBody = document.getElementById('evaluationsTableBody');
        const evaluationCountSpan = document.getElementById('evaluationCount');
        const downloadBulletinBtn = document.getElementById('downloadBulletinBtn');
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

        // Details Modal elements
        const evaluationDetailsModal = document.getElementById('evaluationDetailsModal');
        const modalTitle = document.getElementById('modalTitle');

        // Sidebar elements for responsiveness
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

        // PHP variable for most recent academic year
        const mostRecentAnneeId = <?php echo json_encode($mostRecentAnneeId); ?>;


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

        async function makeAjaxRequest(data) { // Function to make AJAX requests
            try {
                const response = await fetch(window.location.href, { // Fetch current URL with POST method
                    method: 'POST', // Set method to POST
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded', // Set content type
                    },
                    body: new URLSearchParams(data) // Set request body with URLSearchParams
                });
                return await response.json(); // Parse and return JSON response
            } catch (error) {
                console.error('Erreur AJAX:', error); // Log AJAX error
                throw error; // Re-throw error
            }
        }

        function htmlspecialchars(str) { // Function to escape HTML characters
            if (typeof str !== 'string') return str; // Return as is if not a string
            var map = { // Map of characters to HTML entities
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return str.replace(/[&<>"']/g, function(m) { return map[m]; }); // Replace characters with entities
        }

        function formatDate(dateStr) {
            if (!dateStr || dateStr === '0000-00-00' || dateStr.includes('N/A')) return 'N/A';
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return 'N/A'; // Check for invalid date
            return date.toLocaleDateString('fr-FR');
        }

        function getAppreciation(note) { // Function to determine appreciation based on note
            if (note === null || note === '') return { text: 'Non noté', class: '' }; // Handle null or empty notes

            const numericNote = parseFloat(note); // Convert note to float
            if (isNaN(numericNote)) return { text: 'Invalide', class: '' }; // Handle invalid numeric notes

            if (numericNote >= 16) return { text: 'Excellent', class: 'excellent' }; // Excellent if note >= 16
            if (numericNote >= 14) return { text: 'Très bien', class: 'tres-bien' }; // Très bien if note >= 14
            if (numericNote >= 12) return { text: 'Bien', class: 'bien' }; // Bien if note >= 12
            if (numericNote >= 10) return { text: 'Passable', class: 'passable' }; // Passable if note >= 10
            return { text: 'Insuffisant', class: 'insuffisant' }; // Insuffisant otherwise
        }

        // --- Core Functions ---

        async function fetchAndRenderEvaluations() { // Function to fetch evaluations based on filters
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ // Make AJAX request to search evaluations
                    action: 'rechercher_evaluations', // Set action to search evaluations
                    annee_id: currentAnneeFilter === 'all_annees' ? 0 : currentAnneeFilter, // Pass academic year ID
                    search_term: searchInput.value // Pass search term
                });

                if (result.success) { // If request is successful
                    allEvaluations = result.data; // Store fetched evaluations
                    applyFiltersAndSort(
                        currentSortType,
                        currentAnneeFilter,
                        searchInput.value
                    );
                } else {
                    showAlert(result.message, 'error'); // Show error alert
                    allEvaluations = []; // Clear all evaluations
                    currentDisplayedEvaluations = [];
                    displayEvaluationsTable([]); // Clear table
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des évaluations. Veuillez réessayer.', 'error'); // Show generic error alert
                allEvaluations = []; // Clear all evaluations
                currentDisplayedEvaluations = [];
                displayEvaluationsTable([]); // Clear table
            } finally {
                showLoading(false);
            }
        }

        function displayEvaluationsTable(evals) { // Function to display evaluations in the table
            evaluationsTableBody.innerHTML = ''; // Clear existing table rows
            evaluationCountSpan.textContent = evals.length; // Update evaluation count
            currentDisplayedEvaluations = evals; // Store currently displayed evaluations

            if (evals.length === 0) { // If no evaluations found
                evaluationsTableBody.innerHTML = `
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fas fa-exclamation-circle"></i>
                            <p>Aucune évaluation trouvée avec ces critères.</p>
                        </td>
                    </tr>
                `; // Display empty state message
                return;
            }

            evals.forEach(evaluation => { // Iterate through each evaluation
                const row = document.createElement('tr'); // Create a new table row
                const noteDisplay = evaluation.note !== null ? parseFloat(evaluation.note).toFixed(2) : '-'; // Format note for display
                const appreciation = getAppreciation(evaluation.note); // Get appreciation for the note
                const dateDisplay = formatDate(evaluation.dte_eval); // Format date for display

                row.innerHTML = `
                    <td>${htmlspecialchars(evaluation.lib_UE)}</td>
                    <td>${htmlspecialchars(evaluation.lib_ECUE)}</td>
                    <td>${htmlspecialchars(evaluation.credit_ECUE)}</td>
                    <td>${dateDisplay}</td>
                    <td>${noteDisplay}</td>
                    <td><span class="note-badge ${appreciation.class}">${appreciation.text}</span></td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="showEvaluationDetailsModal(${evaluation.id_eval})">
                            <i class="fas fa-folder-open"></i>
                        </button>
                    </td>
                `; // Populate row with evaluation data
                evaluationsTableBody.appendChild(row); // Add row to table body
            });
        }

        async function showEvaluationDetailsModal(idEval) { // Function to show evaluation details in a modal
            try {
                showLoading(true);
                const result = await makeAjaxRequest({ // Make AJAX request for evaluation details
                    action: 'obtenir_details_evaluation', // Set action to get details
                    id_eval: idEval // Pass evaluation ID
                });

                if (result.success && result.data) { // If request is successful and data is available
                    currentEvaluationDetails = result.data; // Store for PDF export
                    const data = currentEvaluationDetails;
                    const appreciation = getAppreciation(data.note);

                    modalTitle.textContent = `Détails de l'évaluation`; // Reset title for generic modal
                    document.getElementById('modalUeLibelle').textContent = htmlspecialchars(data.lib_UE); // Set UE label
                    document.getElementById('modalEcueLibelle').textContent = htmlspecialchars(data.lib_ECUE); // Set ECUE label
                    document.getElementById('modalCreditEcue').textContent = htmlspecialchars(data.credit_ECUE); // Set ECUE credits
                    document.getElementById('modalDateEvaluation').textContent = formatDate(data.dte_eval); // Set evaluation date
                    document.getElementById('modalNote').textContent = data.note !== null ? parseFloat(data.note).toFixed(2) + '/20' : 'Non notée'; // Set note
                    document.getElementById('modalAppreciation').textContent = appreciation.text;
                    document.getElementById('modalAppreciation').className = `note-badge ${appreciation.class}`;

                    const anneeAcademiqueText = `${new Date(data.date_deb).getFullYear()}-${new Date(data.date_fin).getFullYear()}`;
                    document.getElementById('modalAnneeAcademique').textContent = anneeAcademiqueText; // Set academic year

                    const evaluateurName = data.nom_ens && data.prenom_ens ? `${htmlspecialchars(data.prenom_ens)} ${htmlspecialchars(data.nom_ens)}` : 'Non spécifié'; // Determine evaluator name
                    document.getElementById('modalEvaluateur').textContent = evaluateurName; // Set evaluator name

                    evaluationDetailsModal.classList.add('show'); // Display the modal
                } else {
                    showAlert(result.message || "Impossible de charger les détails de l'évaluation.", 'error'); // Show error alert if details not loaded
                }
            } catch (error) {
                showAlert("Erreur lors de la récupération des détails de l'évaluation.", 'error'); // Show generic error alert
            } finally {
                showLoading(false);
            }
        }

        async function downloadBulletin() { // Function to download student bulletin
            // Automatically use the most recent academic year
            const anneeIdToUse = mostRecentAnneeId;

            if (anneeIdToUse === 0) {
                showAlert("Aucune année académique disponible pour générer le bulletin. Veuillez en ajouter une.", "error");
                return;
            }
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ // Make AJAX request to get bulletin data
                    action: 'obtenir_bulletin', // Set action to obtain bulletin
                    num_etu: loggedInStudentNumEtu, // Pass logged-in student ID
                    annee_id: anneeIdToUse // Pass academic year ID
                });

                if (result.success) { // If request is successful
                    currentBulletinData = result; // Store bulletin data for PDF export
                    afficherBulletin(result.etudiant, result.notes); // Display bulletin in modal
                } else {
                    showAlert(result.message, 'error'); // Show error alert
                }
            } catch (error) {
                showAlert('Erreur lors du chargement du bulletin', 'error'); // Show generic error alert
            } finally {
                showLoading(false);
            }
        }

        // REUSED AND ADAPTED FROM gestion_evaluations.php for displaying the bulletin
        function afficherBulletin(etudiant, notes) { // Function to display student bulletin details
            const content = document.getElementById('bulletinContent'); // Get bulletin content div

            let totalNotes = 0; // Initialize total notes sum
            let totalCredits = 0; // Initialize total credits sum
            let notesCount = 0; // Initialize count of graded ECUEs

            notes.forEach(note => { // Iterate through each note
                if (note.note !== null && note.note !== '') { // If note exists and is not empty
                    totalNotes += parseFloat(note.note) * parseFloat(note.credit_ECUE); // Add weighted note to sum
                    totalCredits += parseFloat(note.credit_ECUE); // Add credits to sum
                    notesCount++; // Increment graded ECUE count
                }
            });

            const moyenne = totalCredits > 0 ? (totalNotes / totalCredits).toFixed(2) : '0.00'; // Calculate average or set to 0.00

            content.innerHTML = `
                <div class="bulletin-header">
                    <h1 class="bulletin-title">BULLETIN DE NOTES</h1>
                    <p class="bulletin-subtitle">Année académique ${htmlspecialchars(etudiant.annee_libelle)}</p>
                </div>

                <div class="bulletin-student-info">
                    <div class="info-section">
                        <h4>Informations étudiant</h4>
                        <div class="info-item">
                            <span class="info-label">Matricule :</span>
                            <span>${htmlspecialchars(etudiant.num_etu)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Nom :</span>
                            <span>${htmlspecialchars(etudiant.nom_etu)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Prénom(s) :</span>
                            <span>${htmlspecialchars(etudiant.prenoms_etu)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Date naissance :</span>
                            <span>${formatDate(etudiant.dte_naiss_etu)}</span>
                        </div>
                    </div>

                    <div class="info-section">
                        <h4>Informations académiques</h4>
                        <div class="info-item">
                            <span class="info-label">Niveau :</span>
                            <span>${htmlspecialchars(etudiant.lib_niv_etu)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Filière :</span>
                            <span>${htmlspecialchars(etudiant.lib_filiere || '-')}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Email :</span>
                            <span>${htmlspecialchars(etudiant.email_etu)}</span>
                        </div>
                    </div>
                </div>

                <table class="bulletin-notes-table">
                    <thead>
                        <tr>
                            <th>Unité d'Enseignement</th>
                            <th>ECUE</th>
                            <th>Crédits</th>
                            <th>Note (/20)</th>
                            <th>Date évaluation</th>
                            <th>Appréciation</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${notes.map(note => { // Map notes to table rows
                            const appreciation = getAppreciation(note.note); // Get appreciation for each note
                            return `
                                <tr>
                                    <td><strong>${htmlspecialchars(note.lib_UE)}</strong></td>
                                    <td>${htmlspecialchars(note.lib_ECUE)}</td>
                                    <td>${htmlspecialchars(note.credit_ECUE)}</td>
                                    <td>${note.note !== null ? parseFloat(note.note).toFixed(2) : '-'}</td>
                                    <td>${formatDate(note.dte_eval)}</td>
                                    <td><span class="note-badge ${appreciation.class}">${appreciation.text}</span></td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>

                <div class="bulletin-summary">
                    <div class="info-section">
                        <h4>Résumé</h4>
                        <div class="info-item">
                            <span class="info-label">Nombre d'ECUE :</span>
                            <span>${notes.length}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ECUE notées :</span>
                            <span>${notesCount}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Crédits obtenus :</span>
                            <span>${totalCredits}</span>
                        </div>
                    </div>

                    <div class="info-section">
                        <h4>Moyenne générale</h4>
                        <div style="text-align: center; padding: var(--space-4);">
                            <div style="font-size: var(--text-3xl); font-weight: bold; color: var(--accent-600);">
                                ${moyenne}/20
                            </div>
                            <div style="margin-top: var(--space-2);">
                                <span class="note-badge ${getAppreciation(parseFloat(moyenne)).class}">
                                    ${getAppreciation(parseFloat(moyenne)).text}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: var(--space-8); text-align: right; font-size: var(--text-sm); color: var(--gray-600);">
                    <p>Document généré le ${new Date().toLocaleDateString('fr-FR')}</p>
                </div>
            `;

            document.getElementById('bulletinModal').classList.add('show'); // Display the bulletin modal
        }

        function closeModal(modalId) { // Function to close any modal
            document.getElementById(modalId).classList.remove('show'); // Hide the modal
            currentEvaluationDetails = null; // Clear evaluation details on close
            currentBulletinData = null; // Clear bulletin data on close
        }

        function imprimerBulletin() { // Function to print the bulletin
            window.print(); // Trigger browser print dialog
        }

        // NEW: Function to download bulletin as PDF
        function downloadBulletinPdf() {
            if (!currentBulletinData) {
                showAlert("Aucune donnée de bulletin à exporter en PDF.", "warning");
                return;
            }

            showLoading(true);
            const { etudiant, notes } = currentBulletinData;
            const doc = new jsPDF();
            let currentY = 20;

            // Header
            doc.setFontSize(18);
            doc.text('BULLETIN DE NOTES', doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' });
            currentY += 10;
            doc.setFontSize(12);
            doc.text(`Année académique ${etudiant.annee_libelle}`, doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' });
            currentY += 15;

            // Student Information
            doc.setFontSize(14);
            doc.text('Informations étudiant', 15, currentY);
            currentY += 7;

            doc.autoTable({
                startY: currentY,
                body: [
                    ['Matricule:', etudiant.num_etu],
                    ['Nom:', etudiant.nom_etu],
                    ['Prénom(s):', etudiant.prenoms_etu],
                    ['Date naissance:', formatDate(etudiant.dte_naiss_etu)],
                    ['Lieu de naissance:', etudiant.lieu_naissance || 'N/A'],
                    ['Téléphone:', etudiant.telephone || 'N/A'],
                    ['Email:', etudiant.email_etu || 'N/A'],
                    ['Niveau:', etudiant.lib_niv_etu],
                    ['Filière:', etudiant.lib_filiere || 'N/A'],
                    ['Date inscription:', formatDate(etudiant.dte_insc)],
                    ['Montant inscription:', etudiant.montant_insc ? `${parseInt(etudiant.montant_insc).toLocaleString('fr-FR')} FCFA` : 'N/A']
                ],
                theme: 'grid',
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 50 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 }
            });
            currentY = doc.autoTable.previous.finalY + 10;

            // Notes Table
            doc.setFontSize(14);
            doc.text('Détail des Notes', 15, currentY);
            currentY += 7;

            const notesHeaders = [['Unité d\'Enseignement', 'ECUE', 'Crédits', 'Note (/20)', 'Date évaluation', 'Appréciation']];
            const notesRows = notes.map(note => [
                note.lib_UE,
                note.lib_ECUE,
                note.credit_ECUE,
                note.note !== null ? parseFloat(note.note).toFixed(2) : '-',
                formatDate(note.dte_eval),
                getAppreciation(note.note).text
            ]);

            doc.autoTable({
                startY: currentY,
                head: notesHeaders,
                body: notesRows,
                theme: 'grid',
                styles: { fontSize: 9, cellPadding: 2, overflow: 'linebreak' },
                headStyles: { fillColor: [59, 130, 246], textColor: [255, 255, 255] },
                margin: { left: 15, right: 15 },
                didDrawPage: function (data) {
                    doc.setFontSize(8);
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            currentY = doc.autoTable.previous.finalY + 10;


            // Summary (Moyenne, Credits)
            let totalNotesSum = 0;
            let totalCreditsSum = 0;
            let gradedEcueCount = 0;
            notes.forEach(note => {
                if (note.note !== null && note.note !== '') {
                    totalNotesSum += parseFloat(note.note) * parseFloat(note.credit_ECUE);
                    totalCreditsSum += parseFloat(note.credit_ECUE);
                    gradedEcueCount++;
                }
            });
            const moyenne = totalCreditsSum > 0 ? (totalNotesSum / totalCreditsSum).toFixed(2) : '0.00';
            const overallAppreciation = getAppreciation(parseFloat(moyenne)).text;


            doc.setFontSize(14);
            doc.text('Résumé Global', 15, currentY);
            currentY += 7;
            doc.autoTable({
                startY: currentY,
                body: [
                    ['Nombre d\'ECUE:', notes.length],
                    ['ECUE notées:', gradedEcueCount],
                    ['Crédits obtenus:', totalCreditsSum],
                    ['Moyenne Générale:', `${moyenne}/20`],
                    ['Appréciation Générale:', overallAppreciation]
                ],
                theme: 'grid',
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 60 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 },
                didDrawPage: function (data) {
                    doc.setFontSize(8);
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            currentY = doc.autoTable.previous.finalY + 15;


            doc.setFontSize(10);
            doc.text(`Document généré le: ${new Date().toLocaleDateString('fr-FR')}`, doc.internal.pageSize.getWidth() - 15, currentY, { align: 'right' });


            doc.save(`bulletin_${etudiant.nom_etu.replace(/\s/g, '_')}_${etudiant.annee_libelle.replace(/\s/g, '_')}.pdf`);
            showLoading(false);
            showAlert("Bulletin PDF généré avec succès !", 'success');
        }


        window.onclick = function(event) { // Close modal when clicking outside
            if (event.target.classList.contains('modal')) { // If clicked element has 'modal' class
                event.target.classList.remove('show'); // Hide the modal
            }
        }

        // --- Export Functions ---

        // Get filtered and sorted data for export
        function getExportData() {
            const headers = [
                'Libellé UE', 'Libellé ECUE', 'Crédit ECUE', 'Date d\'évaluation', 'Note (/20)', 'Appréciation'
            ];

            const rows = currentDisplayedEvaluations.map(evalu => {
                const appreciation = getAppreciation(evalu.note);
                return [
                    evalu.lib_UE || 'N/A',
                    evalu.lib_ECUE || 'N/A',
                    evalu.credit_ECUE || 'N/A',
                    formatDate(evalu.dte_eval),
                    evalu.note !== null ? parseFloat(evalu.note).toFixed(2) : '-',
                    appreciation.text
                ];
            });

            return { headers, rows };
        }

        // Export to PDF (all currently displayed evaluations)
        exportPdfBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) {
                    showAlert("Aucune donnée visible à exporter.", 'warning');
                    return;
                }

                const doc = new jsPDF('landscape'); // Use landscape for more columns
                doc.setFontSize(14);
                doc.text('Liste de Mes Évaluations', 14, 15);
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

                doc.save(`mes_evaluations_${new Date().toISOString().slice(0,10)}.pdf`);
                showAlert("Export PDF réussi !", 'success');
            } catch (error) {
                console.error("Erreur lors de l'export PDF:", error);
                showAlert("Erreur lors de l'export PDF", 'error');
            }
        });

        // Export to Excel (all currently displayed evaluations)
        exportExcelBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) {
                    showAlert("Aucune donnée visible à exporter.", 'warning');
                    return;
                }

                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
                XLSX.utils.book_append_sheet(wb, ws, "Mes Evaluations");
                XLSX.writeFile(wb, `mes_evaluations_${new Date().toISOString().slice(0,10)}.xlsx`);
                showAlert("Export Excel réussi !", 'success');
            } catch (error) {
                console.error("Erreur lors de l'export Excel:", error);
                showAlert("Erreur lors de l'export Excel", 'error');
            }
        });

        // Export to CSV (all currently displayed evaluations)
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
                link.setAttribute("download", `mes_evaluations_${new Date().toISOString().slice(0,10)}.csv`);
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

        // NEW: Data-driven PDF generation for single evaluation details
        function downloadEvaluationDetailsPdf() {
            if (!currentEvaluationDetails) {
                showAlert("Aucune donnée d'évaluation à exporter en PDF.", "warning");
                return;
            }

            showLoading(true);
            const evaluation = currentEvaluationDetails;
            const doc = new jsPDF();
            const startY = 20;
            let currentY = startY;
            const appreciation = getAppreciation(evaluation.note);

            // Header
            doc.setFontSize(18);
            doc.text('Fiche Détaillée d\'Évaluation', doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' });
            currentY += 10;
            doc.setFontSize(10);
            doc.text(`SYGECOS - Système de Gestion de Scolarité`, doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' });
            currentY += 15;

            // Evaluation Title
            doc.setFontSize(16);
            doc.setTextColor(59, 130, 246); // Accent color
            doc.text(`${evaluation.lib_UE} - ${evaluation.lib_ECUE}`, 15, currentY);
            doc.line(15, currentY + 1, doc.internal.pageSize.getWidth() - 15, currentY + 1); // Underline
            currentY += 10;
            doc.setTextColor(0, 0, 0); // Reset color

            // Evaluation Information
            doc.setFontSize(14);
            doc.text('Informations de l\'Évaluation', 15, currentY);
            currentY += 7;

            doc.autoTable({
                startY: currentY,
                body: [
                    ['Unité d\'Enseignement (UE):', evaluation.lib_UE || 'N/A'],
                    ['Élément Constitutif d\'UE (ECUE):', evaluation.lib_ECUE || 'N/A'],
                    ['Crédits ECUE:', evaluation.credit_ECUE || 'N/A'],
                    ['Date d\'évaluation:', formatDate(evaluation.dte_eval)],
                    ['Note:', evaluation.note !== null ? `${parseFloat(evaluation.note).toFixed(2)}/20` : 'Non notée'],
                    ['Appréciation:', appreciation.text]
                ],
                theme: 'grid',
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 70 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 },
                didDrawPage: function (data) {
                    // Footer
                    doc.setFontSize(8);
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            currentY = doc.autoTable.previous.finalY + 10;

            // Contextual Information
            doc.setFontSize(14);
            doc.text('Informations Contextuelles', 15, currentY);
            currentY += 7;

            const evaluatorName = evaluation.nom_ens && evaluation.prenom_ens ? `${evaluation.prenom_ens} ${evaluation.nom_ens}` : 'Non spécifié';
            const anneeAcademiqueText = `${new Date(evaluation.date_deb).getFullYear()}-${new Date(evaluation.date_fin).getFullYear()}`;

            doc.autoTable({
                startY: currentY,
                body: [
                    ['Année Académique:', anneeAcademiqueText],
                    ['Évalué par:', evaluatorName]
                ],
                theme: 'grid',
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 70 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 },
                didDrawPage: function (data) {
                    // Footer
                    doc.setFontSize(8);
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });

            doc.save(`details_evaluation_${evaluation.lib_ECUE.replace(/\s/g, '_')}.pdf`);
            showLoading(false);
            showAlert("Fiche d'évaluation PDF générée avec succès !", 'success');
        }

        // --- Search, Filter, Sort Logic ---

        // Global function to apply all filters and sorting
        function applyFiltersAndSort(sortType, anneeFilter, searchTerm) {
            let filteredAndSortedData = [...allEvaluations];

            // 1. Apply Search
            if (searchTerm) {
                const lowerCaseSearchTerm = searchTerm.toLowerCase();
                filteredAndSortedData = filteredAndSortedData.filter(evaluation => {
                    return (
                        (evaluation.lib_UE && evaluation.lib_UE.toLowerCase().includes(lowerCaseSearchTerm)) ||
                        (evaluation.lib_ECUE && evaluation.lib_ECUE.toLowerCase().includes(lowerCaseSearchTerm))
                    );
                });
            }

            // 2. Apply Année Academique Filter
            if (anneeFilter !== 'all_annees') {
                filteredAndSortedData = filteredAndSortedData.filter(evaluation => evaluation.fk_id_Ac == anneeFilter);
            }

            // 3. Apply Sorting
            filteredAndSortedData.sort((a, b) => {
                switch (sortType) {
                    case 'ue-asc':
                        return (a.lib_UE || '').localeCompare(b.lib_UE || '');
                    case 'ue-desc':
                        return (b.lib_UE || '').localeCompare(a.lib_UE || '');
                    case 'ecue-asc':
                        return (a.lib_ECUE || '').localeCompare(b.lib_ECUE || '');
                    case 'ecue-desc':
                        return (b.lib_ECUE || '').localeCompare(a.lib_ECUE || '');
                    case 'date-asc':
                        return new Date(a.dte_eval) - new Date(b.dte_eval);
                    case 'date-desc':
                        return new Date(b.dte_eval) - new Date(a.dte_eval);
                    case 'note-asc':
                        return (parseFloat(a.note) || 0) - (parseFloat(b.note) || 0);
                    case 'note-desc':
                        return (parseFloat(b.note) || 0) - (parseFloat(a.note) || 0);
                    case 'default':
                    default:
                        return 0;
                }
            });

            displayEvaluationsTable(filteredAndSortedData);
            updateFilterButtonText();
        }

        // Search event listeners
        searchInput.addEventListener('input', () => {
            // When searching, reset filter selections visually and logically
            document.querySelector('input[name="sort_radio"][value="default"]').checked = true;
            document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true;

            currentSortType = 'default';
            currentAnneeFilter = 'all_annees';

            applyFiltersAndSort(
                currentSortType,
                currentAnneeFilter,
                searchInput.value
            );
        });
        searchButton.addEventListener('click', () => {
            // Same logic as input for consistency when button is clicked
            document.querySelector('input[name="sort_radio"][value="default"]').checked = true;
            document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true;

            currentSortType = 'default';
            currentAnneeFilter = 'all_annees';

            applyFiltersAndSort(
                currentSortType,
                currentAnneeFilter,
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

            const selectedAnneeFilterRadio = document.querySelector('input[name="annee_filter_radio"]:checked');
            if (selectedAnneeFilterRadio) {
                currentAnneeFilter = selectedAnneeFilterRadio.value;
            }

            // Clear search input when applying filters from modal
            searchInput.value = '';

            applyFiltersAndSort(
                currentSortType,
                currentAnneeFilter,
                searchInput.value
            );
            filterModal.style.display = 'none';
        });

        resetFilterModalBtn.addEventListener('click', function() {
            currentSortType = 'default';
            currentAnneeFilter = 'all_annees';
            searchInput.value = '';

            document.querySelector('input[name="sort_radio"][value="default"]').checked = true;
            document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true;

            applyFiltersAndSort(currentSortType, currentAnneeFilter, '');
            filterModal.style.display = 'none';
            showAlert('Filtres et recherche réinitialisés.', 'info');
        });

        // Update filter button text based on active filters
        function updateFilterButtonText() {
            let activeFiltersCount = 0;
            if (currentSortType !== 'default') {
                activeFiltersCount++;
            }
            if (currentAnneeFilter !== 'all_annees') {
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

        // --- Initialization ---
        document.addEventListener('DOMContentLoaded', function() {
            fetchAndRenderEvaluations();
            initSidebar(); // Initialize sidebar responsiveness
            updateFilterButtonText(); // Set initial filter button text

            // Fix: Attach event listener to downloadBulletinBtn
            downloadBulletinBtn.addEventListener('click', downloadBulletin);

            // Expose functions to global scope for inline onclicks
            window.showEvaluationDetailsModal = showEvaluationDetailsModal;
            window.closeModal = closeModal;
            window.imprimerBulletin = imprimerBulletin;
            window.downloadEvaluationDetailsPdf = downloadEvaluationDetailsPdf;
            window.downloadBulletinPdf = downloadBulletinPdf; // Expose new function for bulletin PDF
        });

        // Responsive Sidebar setup
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
            const actionTexts = document.querySelectorAll('.action-text');
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
                    if (barsIcon) barsIcon.style.display = 'inline-block'; // Or 'none' if sidebar is always open on desktop
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