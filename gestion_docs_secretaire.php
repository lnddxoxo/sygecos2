<?php
// gestion_docs_secretaire.php
session_start();
require_once 'config.php'; // Your database connection file

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Function to safely get a value from $_POST
function getPostValue($key, $default = '') {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

// AJAX handler for fetching documents
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $pdo = new PDO('mysql:host=127.0.0.1:3306;dbname=sygecos', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $action = getPostValue('action');

        if ($action === 'get_documents') {
            $searchTerm = getPostValue('search_term');
            $anneeId = intval(getPostValue('annee_id', 0));
            $documentTypeFilter = getPostValue('document_type_filter', 'all');

            $whereConditions = [];
            $params = [];

            if ($anneeId > 0) {
                $whereConditions[] = "f.fk_id_Ac = ?";
                $params[] = $anneeId;
            }

            if ($documentTypeFilter !== 'all') {
                // This assumes a column like 'document_type' exists in rapports_etudiant_files
                // If not, you might need to adjust your database schema or logic here.
                // For now, let's assume 'description' or part of 'file_name' could indicate type,
                // or we join with another table if document types are formally managed.
                // For simplicity, let's filter by a conceptual 'type' that might be inferred or stored.
                // As the current schema for rapports_etudiant_files doesn't have a specific 'type' column,
                // we'll simulate it by searching in the description or file_name.
                // A more robust solution would involve adding a 'document_type' column to rapports_etudiant_files.
                $whereConditions[] = "(f.file_name LIKE ? OR f.description LIKE ?)";
                $params[] = "%{$documentTypeFilter}%";
                $params[] = "%{$documentTypeFilter}%";
            }

            if (!empty($searchTerm)) {
                $whereConditions[] = "(f.file_name LIKE ? OR f.description LIKE ? OR e.nom_etu LIKE ? OR e.prenoms_etu LIKE ?)";
                $params[] = "%{$searchTerm}%";
                $params[] = "%{$searchTerm}%";
                $params[] = "%{$searchTerm}%";
                $params[] = "%{$searchTerm}%";
            }

            $whereClause = '';
            if (count($whereConditions) > 0) {
                $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
            }

            $sql = "
                SELECT
                    f.id_report_file,
                    f.file_name,
                    f.file_path,
                    f.upload_date,
                    f.description,
                    e.num_etu,
                    CONCAT(e.nom_etu, ' ', e.prenoms_etu) AS etudiant_nom_complet,
                    CONCAT(YEAR(aa.date_deb), '-', YEAR(aa.date_fin)) as annee_academique_libelle
                FROM rapports_etudiant_files f
                JOIN etudiant e ON f.fk_num_etu = e.num_etu
                JOIN année_academique aa ON f.fk_id_Ac = aa.id_Ac
                {$whereClause}
                ORDER BY f.upload_date DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $documents]);

        } elseif ($action === 'delete_document') {
            $documentId = intval(getPostValue('document_id', 0));
            if ($documentId <= 0) {
                throw new Exception("ID du document manquant.");
            }

            // Fetch file path before deleting record
            $stmt = $pdo->prepare("SELECT file_path FROM rapports_etudiant_files WHERE id_report_file = ?");
            $stmt->execute([$documentId]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$document) {
                throw new Exception("Document introuvable.");
            }

            // Delete file from server
            $filePath = __DIR__ . '/' . $document['file_path']; // Adjust path if needed
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete record from database
            $stmt = $pdo->prepare("DELETE FROM rapports_etudiant_files WHERE id_report_file = ?");
            $stmt->execute([$documentId]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Document supprimé avec succès.']);
            } else {
                throw new Exception("Erreur lors de la suppression du document de la base de données.");
            }
        } else {
            throw new Exception("Action non reconnue.");
        }

    } catch (Exception $e) {
        error_log("Error in gestion_docs_secretaire AJAX: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch academic years for filter dropdown on initial load
$anneesAcademiques = [];
try {
    $pdo = new PDO('mysql:host=127.0.0.1:3306;dbname=sygecos', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("SELECT id_Ac, CONCAT(YEAR(date_deb), '-', YEAR(date_fin)) as annee_libelle FROM année_academique ORDER BY date_deb DESC");
    $anneesAcademiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching academic years for filter: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Gestion des Documents</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Your CSS from mes_rapports.php and sidebar_secretaire.php combined,
           with adjustments for general document management */
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
        .badge-danger { background-color: #fecaca; color: #dc2626; }
        .badge-primary { background-color: var(--accent-100); color: var(--accent-800); }


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

        /* Modal Styles */
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
        <?php include 'sidebar_secretaire.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; // Assuming topbar.php exists ?>

            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1 class="page-title-main">Gestion des Documents</h1>
                        <p class="page-subtitle">Consultez et gérez les documents soumis par les étudiants.</p>
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
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher par nom de fichier, étudiant...">
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
                            <i class="fas fa-folder-open"></i> Liste des Documents Soumis (<span id="documentCount">0</span>)
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
                                    <th>Nom du Fichier</th>
                                    <th>Étudiant</th>
                                    <th>Année Académique</th>
                                    <th>Description</th>
                                    <th>Date d'Upload</th>
                                    <th style="width: 120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="documentsTableBody">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="filter-modal" id="filterModal">
        <div class="filter-modal-content">
            <div class="filter-modal-header">
                <h3 class="filter-modal-title">Filtres et Tri des Documents</h3>
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
                            <input type="radio" name="sort_radio" value="filename-asc">
                            <i class="fas fa-sort-alpha-down"></i> Nom de fichier (A-Z)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="filename-desc">
                            <i class="fas fa-sort-alpha-up"></i> Nom de fichier (Z-A)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="date-upload-desc">
                            <i class="fas fa-calendar-alt"></i> Date d'upload (plus récent)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="date-upload-asc">
                            <i class="fas fa-calendar-alt"></i> Date d'upload (plus ancien)
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

        let allDocuments = [];
        let currentDisplayedDocuments = [];

        // Filter and Sort states
        let currentSortType = 'date-upload-desc';
        let currentAnneeFilter = 'all_annees';
        let currentDocumentTypeFilter = 'all'; // Not directly used in backend query for now, but kept for consistency if schema changes

        // DOM elements
        const documentsTableBody = document.getElementById('documentsTableBody');
        const documentCountSpan = document.getElementById('documentCount');
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
        const loadingOverlay = document.getElementById('loadingOverlay');

        // Sidebar elements for responsiveness
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');


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

        function formatDate(dateStr) {
            if (!dateStr || dateStr === '0000-00-00 00:00:00' || dateStr.includes('N/A')) return 'N/A';
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return 'N/A';
            return date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'});
        }

        // --- Core Functions ---
        async function fetchAndRenderDocuments() {
            showLoading(true);
            try {
                const result = await makeAjaxRequest({
                    action: 'get_documents',
                    search_term: searchInput.value,
                    annee_id: currentAnneeFilter === 'all_annees' ? 0 : currentAnneeFilter,
                    document_type_filter: currentDocumentTypeFilter
                });

                if (result.success) {
                    allDocuments = result.data;
                    applyFiltersAndSort(currentSortType); // Re-apply sort
                } else {
                    showAlert(result.message, 'error');
                    allDocuments = [];
                    currentDisplayedDocuments = [];
                    displayDocumentsTable([]);
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des documents. Veuillez réessayer.', 'error');
                allDocuments = [];
                currentDisplayedDocuments = [];
                displayDocumentsTable([]);
            } finally {
                showLoading(false);
            }
        }

        function displayDocumentsTable(documents) {
            documentsTableBody.innerHTML = '';
            documentCountSpan.textContent = documents.length;
            currentDisplayedDocuments = documents;

            if (documents.length === 0) {
                documentsTableBody.innerHTML = `
                    <tr>
                        <td colspan="6" class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <p>Aucun document trouvé avec ces critères.</p>
                        </td>
                    </tr>
                `;
                return;
            }

            documents.forEach(doc => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${htmlspecialchars(doc.file_name)}</td>
                    <td>${htmlspecialchars(doc.etudiant_nom_complet)} (${htmlspecialchars(doc.num_etu)})</td>
                    <td>${htmlspecialchars(doc.annee_academique_libelle)}</td>
                    <td>${htmlspecialchars(doc.description || 'N/A')}</td>
                    <td>${formatDate(doc.upload_date)}</td>
                    <td>
                        <a href="${htmlspecialchars(doc.file_path)}" target="_blank" class="btn btn-sm btn-info" title="Télécharger">
                            <i class="fas fa-download"></i>
                        </a>
                        <button class="btn btn-sm btn-danger" onclick="deleteDocument('${doc.id_report_file}', '${doc.file_name}')" title="Supprimer">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                `;
                documentsTableBody.appendChild(row);
            });
        }

        async function deleteDocument(documentId, fileName) {
            if (!confirm(`Êtes-vous sûr de vouloir supprimer le document "${fileName}" ? Cette action est irréversible et supprimera le fichier du serveur.`)) {
                return;
            }
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ action: 'delete_document', document_id: documentId });
                if (result.success) {
                    showAlert(result.message, 'success');
                    fetchAndRenderDocuments(); // Reload the table
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la suppression du document.', 'error');
            } finally {
                showLoading(false);
            }
        }

        // --- Export Functions ---
        function getExportData() {
            const headers = [
                'Nom du Fichier', 'Étudiant', 'Numéro Étudiant', 'Année Académique', 'Description', 'Date d\'Upload'
            ];
            const rows = currentDisplayedDocuments.map(doc => [
                doc.file_name,
                doc.etudiant_nom_complet,
                doc.num_etu,
                doc.annee_academique_libelle,
                doc.description || 'N/A',
                formatDate(doc.upload_date)
            ]);
            return { headers, rows };
        }

        exportPdfBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) { showAlert("Aucune donnée visible à exporter.", 'warning'); return; }
                const doc = new jsPDF('landscape');
                doc.setFontSize(14);
                doc.text('Liste des Documents Soumis', 14, 15);
                doc.setFontSize(10);
                doc.text(`Exporté le: ${new Date().toLocaleDateString('fr-FR')}`, 14, 22);
                doc.autoTable({
                    head: [headers], body: rows, startY: 25, styles: { fontSize: 8 },
                    headStyles: { fillColor: [59, 130, 246] }, margin: { left: 10, right: 10 }
                });
                doc.save(`documents_soumis_${new Date().toISOString().slice(0,10)}.pdf`);
                showAlert("Export PDF réussi !", 'success');
            } catch (error) { console.error("Erreur lors de l'export PDF:", error); showAlert("Erreur lors de l'export PDF", 'error'); }
        });

        exportExcelBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) { showAlert("Aucune donnée visible à exporter.", 'warning'); return; }
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
                XLSX.utils.book_append_sheet(wb, ws, "Documents Soumis");
                XLSX.writeFile(wb, `documents_soumis_${new Date().toISOString().slice(0,10)}.xlsx`);
                showAlert("Export Excel réussi !", 'success');
            } catch (error) { console.error("Erreur lors de l'export Excel:", error); showAlert("Erreur lors de l'export Excel", 'error'); }
        });

        exportCsvBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) { showAlert("Aucune donnée visible à exporter.", 'warning'); return; }
                let csvContent = headers.map(h => `"${h}"`).join(";") + "\n";
                rows.forEach(row => csvContent += row.map(cell => `"${cell}"`).join(";") + "\n");
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement("a");
                const url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", `documents_soumis_${new Date().toISOString().slice(0,10)}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                showAlert("Export CSV réussi !", 'success');
            } catch (error) { console.error("Erreur lors de l'export CSV:", error); showAlert("Erreur lors de l'export CSV", 'error'); }
        });


        // --- Search, Filter, Sort Logic ---
        function applyFiltersAndSort(sortType) {
            let filteredAndSortedData = [...allDocuments];

            // Apply Search
            const searchTerm = searchInput.value.toLowerCase();
            if (searchTerm) {
                filteredAndSortedData = filteredAndSortedData.filter(doc => {
                    return (
                        (doc.file_name && doc.file_name.toLowerCase().includes(searchTerm)) ||
                        (doc.description && doc.description.toLowerCase().includes(searchTerm)) ||
                        (doc.etudiant_nom_complet && doc.etudiant_nom_complet.toLowerCase().includes(searchTerm)) ||
                        (doc.num_etu && doc.num_etu.toLowerCase().includes(searchTerm))
                    );
                });
            }

            // Apply Année Academique Filter
            if (currentAnneeFilter !== 'all_annees') {
                filteredAndSortedData = filteredAndSortedData.filter(doc => doc.fk_id_Ac == currentAnneeFilter);
            }

            // Apply Document Type Filter (if applicable) - currently not tied to backend field
            // if (currentDocumentTypeFilter !== 'all') {
            //     filteredAndSortedData = filteredAndSortedData.filter(doc => doc.document_type === currentDocumentTypeFilter);
            // }

            // Apply Sorting
            filteredAndSortedData.sort((a, b) => {
                switch (sortType) {
                    case 'filename-asc': return (a.file_name || '').localeCompare(b.file_name || '');
                    case 'filename-desc': return (b.file_name || '').localeCompare(a.file_name || '');
                    case 'date-upload-asc': return new Date(a.upload_date) - new Date(b.upload_date);
                    case 'date-upload-desc': return new Date(b.upload_date) - new Date(a.upload_date);
                    case 'default':
                    default: return 0;
                }
            });

            displayDocumentsTable(filteredAndSortedData);
            updateFilterButtonText();
        }

        searchInput.addEventListener('input', () => {
            // Reset filters in modal when typing in search
            document.querySelector('input[name="sort_radio"][value="date-upload-desc"]').checked = true;
            document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true;
            currentSortType = 'date-upload-desc';
            currentAnneeFilter = 'all_annees';
            // currentDocumentTypeFilter = 'all';

            applyFiltersAndSort(currentSortType);
        });
        searchButton.addEventListener('click', () => applyFiltersAndSort(currentSortType));

        filterButton.addEventListener('click', function(event) {
            event.stopPropagation();
            filterModal.style.display = 'flex';
        });
        closeFilterModalBtn.addEventListener('click', () => filterModal.style.display = 'none');
        filterModal.addEventListener('click', (e) => { if (e.target === filterModal) filterModal.style.display = 'none'; });

        applyFilterModalBtn.addEventListener('click', function() {
            currentSortType = document.querySelector('input[name="sort_radio"]:checked').value;
            currentAnneeFilter = document.querySelector('input[name="annee_filter_radio"]:checked').value;
            // currentDocumentTypeFilter = document.querySelector('input[name="document_type_filter_radio"]:checked').value; // Uncomment if you add document type filter

            searchInput.value = ''; // Clear search when applying modal filters
            applyFiltersAndSort(currentSortType);
            filterModal.style.display = 'none';
        });

        resetFilterModalBtn.addEventListener('click', function() {
            currentSortType = 'date-upload-desc';
            currentAnneeFilter = 'all_annees';
            // currentDocumentTypeFilter = 'all';
            searchInput.value = '';

            document.querySelector('input[name="sort_radio"][value="date-upload-desc"]').checked = true;
            document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true;
            // if (document.querySelector('input[name="document_type_filter_radio"]')) document.querySelector('input[name="document_type_filter_radio"][value="all"]').checked = true;

            applyFiltersAndSort(currentSortType);
            filterModal.style.display = 'none';
            showAlert('Filtres et recherche réinitialisés.', 'info');
        });

        function updateFilterButtonText() {
            let activeFiltersCount = 0;
            if (currentSortType !== 'date-upload-desc') activeFiltersCount++;
            if (currentAnneeFilter !== 'all_annees') activeFiltersCount++;
            // if (currentDocumentTypeFilter !== 'all') activeFiltersCount++;
            if (searchInput.value.trim() !== '') activeFiltersCount++;

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
            fetchAndRenderDocuments();
            initSidebar(); // Call the sidebar initialization
            updateFilterButtonText();

            // Make functions globally available if needed by inline HTML or other scripts
            // window.viewDocument = viewDocument; // Not needed for this page directly as there's no "view details" modal for documents
            window.deleteDocument = deleteDocument;
            // window.closeModal = closeModal; // No generic modal, specific message modal and filter modal are handled
        });

        function initSidebar() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (sidebarToggle && sidebar && mainContent) {
                // Set the current active link in the sidebar
                const currentPath = window.location.pathname.split('/').pop();
                const navLinks = sidebar.querySelectorAll('.nav-link');
                navLinks.forEach(link => {
                    if (link.getAttribute('href') === currentPath) {
                        link.classList.add('active');
                    } else {
                        link.classList.remove('active');
                    }
                });

                handleResponsiveLayout(); // Initial call to set up layout based on screen size

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
            document.querySelectorAll('.action-text').forEach(text => { text.style.display = isMobile ? 'none' : 'inline'; });
            if (isMobile) {
                sidebar.classList.add('collapsed'); // Always start collapsed on mobile
                mainContent.classList.add('sidebar-collapsed');
                // Ensure mobile-open class is only applied via toggle
                sidebar.classList.remove('mobile-open');
                mobileMenuOverlay.classList.remove('active');
            } else {
                // On larger screens, ensure sidebar is not collapsed by default unless toggled
                // And remove mobile-specific classes
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('sidebar-collapsed');
                sidebar.classList.remove('mobile-open');
                mobileMenuOverlay.classList.remove('active');
            }
            // Update toggle icon based on mobile state
            if (sidebarToggle) {
                const barsIcon = sidebarToggle.querySelector('.fa-bars');
                const timesIcon = sidebarToggle.querySelector('.fa-times');
                if (isMobile) {
                    // On mobile, always show bars unless mobile-open
                    if (sidebar.classList.contains('mobile-open')) {
                        if (barsIcon) barsIcon.style.display = 'none';
                        if (timesIcon) timesIcon.style.display = 'inline-block';
                    } else {
                        if (barsIcon) barsIcon.style.display = 'inline-block';
                        if (timesIcon) timesIcon.style.display = 'none';
                    }
                } else {
                    // On desktop, bars always visible for collapsing
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>