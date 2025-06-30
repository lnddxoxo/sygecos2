<?php
// gestion_grades_enseignant.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Traitement AJAX pour les opérations CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        switch ($action) {
            case 'create':
                $nomGrade = trim($_POST['nom_grd']);
                
                // Validation
                if (empty($nomGrade)) {
                    throw new Exception("Le nom du grade est obligatoire");
                }
                
                // Vérifier si le grade existe déjà
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM grade WHERE nom_grd = ?");
                $checkStmt->execute([$nomGrade]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Ce grade existe déjà");
                }
                
                // Générer l'ID
                $stmtMaxId = $pdo->query("SELECT COALESCE(MAX(id_grd), 0) + 1 FROM grade");
                $idGrade = $stmtMaxId->fetchColumn();
                
                // Insérer le nouveau grade
                $stmt = $pdo->prepare("INSERT INTO grade (id_grd, nom_grd) VALUES (?, ?)");
                $stmt->execute([$idGrade, $nomGrade]);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Grade créé avec succès',
                    'data' => [
                        'id_grd' => $idGrade,
                        'nom_grd' => $nomGrade
                    ]
                ]);
                break;
                
            case 'update':
                $idGrade = $_POST['id_grd'];
                $nomGrade = trim($_POST['nom_grd']);
                
                // Validation
                if (empty($nomGrade)) {
                    throw new Exception("Le nom du grade est obligatoire");
                }
                
                // Vérifier si le grade existe déjà pour un autre ID
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM grade WHERE nom_grd = ? AND id_grd != ?");
                $checkStmt->execute([$nomGrade, $idGrade]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Ce grade existe déjà");
                }
                
                // Mettre à jour le grade
                $stmt = $pdo->prepare("UPDATE grade SET nom_grd = ? WHERE id_grd = ?");
                $stmt->execute([$nomGrade, $idGrade]);
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Grade modifié avec succès']);
                break;
                
            case 'delete':
                $idsGrades = json_decode($_POST['ids_grades'], true);
                
                // Vérifier si des enseignants utilisent ces grades
                $placeholders = str_repeat('?,', count($idsGrades) - 1) . '?';
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM avoir WHERE fk_id_grd IN ($placeholders)");
                $checkStmt->execute($idsGrades);
                $countUsage = $checkStmt->fetchColumn();
                
                if ($countUsage > 0) {
                    throw new Exception("Impossible de supprimer : $countUsage enseignant(s) utilisent ces grades");
                }
                
                // Supprimer les grades
                $stmt = $pdo->prepare("DELETE FROM grade WHERE id_grd IN ($placeholders)");
                $stmt->execute($idsGrades);
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Grade(s) supprimé(s) avec succès']);
                break;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Récupérer les grades existants
$grades = [];
try {
    $stmt = $pdo->query("SELECT * FROM grade ORDER BY nom_grd");
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erreur lors de la récupération des grades: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Grades Enseignants</title>
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
            margin-bottom: var(--space-6); /* Adjusted for consistency */
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

        .form-group input[type="text"] {
            padding: var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            color: var(--gray-800);
            transition: all var(--transition-fast);
        }

        .form-group input[type="text"]:focus {
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

        /* Barre de recherche */
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
            padding: var(--space-3) var(--space-10);
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

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--text-sm);
            color: var(--gray-800);
            min-width: 600px;
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
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 30px;
            min-height: 30px;
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

        /* Checkbox styling */
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

        /* Filtre dropdown */
        .filter-dropdown {
            position: relative;
            display: inline-block;
        }

        .filter-button {
            padding: var(--space-3);
            border-radius: var(--radius-md);
            background-color: var(--gray-200);
            color: var(--gray-700);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: var(--space-2);
            transition: all var(--transition-fast);
        }

        .filter-button:hover {
            background-color: var(--gray-300);
        }

        .filter-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: var(--white);
            min-width: 200px;
            box-shadow: var(--shadow-md);
            border-radius: var(--radius-md);
            z-index: 100;
            padding: var(--space-2);
            border: 1px solid var(--gray-200);
        }

        .filter-dropdown-content.show {
            display: block;
        }

        .filter-option {
            padding: var(--space-3);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: var(--space-2);
            border-radius: var(--radius-sm);
            transition: background-color var(--transition-fast);
        }

        .filter-option:hover {
            background-color: var(--gray-100);
        }

        /* Modal de message */
        .message-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .message-modal-content {
            background-color: var(--white);
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

        .message-icon.success {
            color: var(--success-500);
        }

        .message-icon.error {
            color: var(--error-500);
        }

        .message-icon.warning {
            color: var(--warning-500);
        }

        .message-icon.info {
            color: var(--info-500);
        }

        .message-title {
            font-size: var(--text-xl);
            font-weight: 600;
            margin-bottom: var(--space-2);
        }

        .message-text {
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

        /* Loading spinner */
        .loading {
            opacity: 0.6;
            pointer-events: none;
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
            
            .sidebar {
                width: var(--sidebar-collapsed-width);
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
            
            .sidebar-toggle .fa-bars {
                display: none;
            }
            
            .sidebar-toggle .fa-times {
                display: inline-block;
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-4);
            }
            
            .table-actions {
                width: 100%;
                justify-content: flex-end;
                margin-top: var(--space-4);
            }
            
            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .download-buttons {
                width: 100%;
                justify-content: flex-end;
            }
            
            .btn {
                padding: var(--space-2) var(--space-3);
                font-size: var(--text-sm);
            }

            .filter-dropdown-content {
                left: 0;
                right: auto;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: var(--space-4);
            }
            
            .form-card,
            .table-card,
            .search-bar {
                padding: var(--space-4);
            }
            
            .page-title-main {
                font-size: var(--text-2xl);
            }
            
            .page-subtitle {
                font-size: var(--text-base);
            }
            
            .form-actions {
                flex-direction: column;
                gap: var(--space-2);
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .table-actions {
                flex-wrap: wrap;
                gap: var(--space-2);
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            .search-button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Gestion des Grades Enseignants</h1>
                    <p class="page-subtitle">Ajoutez, modifiez ou supprimez les grades académiques des enseignants.</p>
                </div>

                <div class="form-card">
                    <h3 class="form-card-title">Ajouter un nouveau Grade</h3>
                    <form id="gradeForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="nom_grd">Nom du Grade <span style="color: var(--error-500);">*</span></label>
                                <input type="text" id="nom_grd" name="nom_grd" placeholder="Ex: A3, B2, Maître de Conférences" required>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-plus-circle"></i> <span id="submitText">Ajouter Grade</span>
                            </button>
                            <button type="reset" class="btn btn-secondary" id="cancelBtn">
                                <i class="fas fa-redo"></i> Annuler
                            </button>
                        </div>
                    </form>
                </div>

                <div class="search-bar">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher un grade...">
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

                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">Liste des Grades</h3>
                        <div class="table-actions">
                            <div class="filter-dropdown">
                                <button class="filter-button" id="filterButton">
                                    <i class="fas fa-filter"></i> Filtres
                                </button>
                                <div class="filter-dropdown-content" id="filterDropdown">
                                    <div class="filter-option" data-filter="all">
                                        <i class="fas fa-list"></i> Tous les grades
                                    </div>
                                    <div class="filter-option" data-filter="id-asc">
                                        <i class="fas fa-sort-numeric-down"></i> Tri par ID (croissant)
                                    </div>
                                    <div class="filter-option" data-filter="id-desc">
                                        <i class="fas fa-sort-numeric-up"></i> Tri par ID (décroissant)
                                    </div>
                                    <div class="filter-option" data-filter="name-asc">
                                        <i class="fas fa-sort-alpha-down"></i> Tri par nom (A-Z)
                                    </div>
                                    <div class="filter-option" data-filter="name-desc">
                                        <i class="fas fa-sort-alpha-up"></i> Tri par nom (Z-A)
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-secondary" id="modifierGradeBtn" disabled>
                                <i class="fas fa-edit"></i> <span class="action-text">Modifier</span>
                            </button>
                            <button class="btn btn-secondary" id="supprimerGradeBtn" disabled>
                                <i class="fas fa-trash-alt"></i> <span class="action-text">Supprimer</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="gradeTable">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>ID</th>
                                    <th>Nom du Grade</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($grades)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                        <i class="fas fa-medal" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucun grade trouvé. Ajoutez votre premier grade en utilisant le formulaire ci-dessus.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($grades as $grade): ?>
                                    <tr data-id="<?php echo htmlspecialchars($grade['id_grd']); ?>">
                                        <td>
                                            <label class="checkbox-container">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($grade['id_grd']); ?>">
                                                <span class="checkmark"></span>
                                            </label>
                                        </td>
                                        <td><?php echo htmlspecialchars($grade['id_grd']); ?></td>
                                        <td><?php echo htmlspecialchars($grade['nom_grd']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button edit" title="Modifier" onclick="modifierGrade('<?php echo htmlspecialchars($grade['id_grd']); ?>')">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="action-button delete" title="Supprimer" onclick="supprimerGrade('<?php echo htmlspecialchars($grade['id_grd']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script>
        // Variables globales
        let selectedGrades = new Set();
        let editingGrade = null;
        const { jsPDF } = window.jspdf;

        // Éléments DOM
        const gradeForm = document.getElementById('gradeForm');
        const nomGradeInput = document.getElementById('nom_grd');
        const gradeTableBody = document.querySelector('#gradeTable tbody');
        const modifierGradeBtn = document.getElementById('modifierGradeBtn');
        const supprimerGradeBtn = document.getElementById('supprimerGradeBtn');
        const exportPdfBtn = document.getElementById('exportPdfBtn'); // Corrected ID
        const exportExcelBtn = document.getElementById('exportExcelBtn'); // Corrected ID
        const exportCsvBtn = document.getElementById('exportCsvBtn'); // Corrected ID
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const cancelBtn = document.getElementById('cancelBtn');
        
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
        const mainContent = document.getElementById('mainContent');
        const searchInput = document.getElementById('searchInput');
        const searchButton = document.getElementById('searchButton');
        const filterButton = document.getElementById('filterButton');
        const filterDropdown = document.getElementById('filterDropdown');
        const filterOptions = document.querySelectorAll('.filter-option');
        const messageModal = document.getElementById('messageModal');
        const messageTitle = document.getElementById('messageTitle');
        const messageText = document.getElementById('messageText');
        const messageIcon = document.getElementById('messageIcon');
        const messageButton = document.getElementById('messageButton');
        const messageClose = document.getElementById('messageClose');

        // Fonction pour afficher les messages dans une modal
        function showAlert(message, type = 'success', title = null) {
            // Définir le titre par défaut en fonction du type
            if (!title) {
                switch (type) {
                    case 'success':
                        title = 'Succès';
                        break;
                    case 'error':
                        title = 'Erreur';
                        break;
                    case 'warning':
                        title = 'Attention';
                        break;
                    case 'info':
                        title = 'Information';
                        break;
                    default:
                        title = 'Message';
                }
            }

            // Définir l'icône en fonction du type
            messageIcon.className = 'message-icon';
            switch (type) {
                case 'success':
                    messageIcon.classList.add('success');
                    messageIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
                    break;
                case 'error':
                    messageIcon.classList.add('error');
                    messageIcon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
                    break;
                case 'warning':
                    messageIcon.classList.add('warning');
                    messageIcon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                    break;
                case 'info':
                    messageIcon.classList.add('info');
                    messageIcon.innerHTML = '<i class="fas fa-info-circle"></i>';
                    break;
                default:
                    messageIcon.innerHTML = '<i class="fas fa-bell"></i>';
            }

            messageTitle.textContent = title;
            messageText.textContent = message;
            messageModal.style.display = 'flex';
        }

        // Fermer la modal
        function closeMessageModal() {
            messageModal.style.display = 'none';
        }

        // Événements pour la modal
        messageButton.addEventListener('click', closeMessageModal);
        messageClose.addEventListener('click', closeMessageModal);
        messageModal.addEventListener('click', function(e) {
            if (e.target === messageModal) {
                closeMessageModal();
            }
        });

        // Gestion du toggle sidebar pour mobile
        function toggleSidebar() {
            sidebar.classList.toggle('mobile-open');
            mobileMenuOverlay.classList.toggle('active');
            
            // Basculer entre les icônes menu/fermer
            const barsIcon = sidebarToggle.querySelector('.fa-bars');
            const timesIcon = sidebarToggle.querySelector('.fa-times');
            
            if (sidebar.classList.contains('mobile-open')) {
                barsIcon.style.display = 'none';
                timesIcon.style.display = 'inline-block';
            } else {
                barsIcon.style.display = 'inline-block';
                timesIcon.style.display = 'none';
            }
        }

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }

        if (mobileMenuOverlay) {
            mobileMenuOverlay.addEventListener('click', toggleSidebar);
        }

        // Fonction pour faire une requête AJAX
        async function makeAjaxRequest(data) {
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams(data)
                });
                return await response.json();
            } catch (error) {
                console.error('Erreur AJAX:', error);
                throw error;
            }
        }

        // Fonction pour mettre à jour l'état des boutons
        function updateActionButtons() {
            if (selectedGrades.size === 1) {
                modifierGradeBtn.disabled = false;
                supprimerGradeBtn.disabled = false;
            } else if (selectedGrades.size > 1) {
                modifierGradeBtn.disabled = true;
                supprimerGradeBtn.disabled = false;
            } else {
                modifierGradeBtn.disabled = true;
                supprimerGradeBtn.disabled = true;
            }
        }

        // Fonction pour ajouter une ligne dans le tableau
        function addRowToTable(grade) {
            // Supprimer le message "Aucun grade trouvé" s'il existe
            const emptyRow = gradeTableBody.querySelector('td[colspan="4"]'); // Changed colspan to 4
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }

            const newRow = gradeTableBody.insertRow();
            newRow.setAttribute('data-id', grade.id_grd);
            newRow.innerHTML = `
                <td>
                    <label class="checkbox-container">
                        <input type="checkbox" value="${grade.id_grd}">
                        <span class="checkmark"></span>
                    </label>
                </td>
                <td>${grade.id_grd}</td> <td>${grade.nom_grd}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-button edit" title="Modifier" onclick="modifierGrade('${grade.id_grd}')">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button class="action-button delete" title="Supprimer" onclick="supprimerGrade('${grade.id_grd}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            `;
            attachEventListenersToRow(newRow);
        }

        // Fonction pour attacher les événements aux lignes
        function attachEventListenersToRow(row) {
            const checkbox = row.querySelector('input[type="checkbox"]');
            
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    selectedGrades.add(this.value);
                } else {
                    selectedGrades.delete(this.value);
                }
                updateActionButtons();
            });
        }

        // Fonction de recherche
        function searchGrades() {
            const searchTerm = searchInput.value.toLowerCase();
            const rows = gradeTableBody.querySelectorAll('tr[data-id]'); // Select only data rows
            
            let hasResults = false;
            
            rows.forEach(row => {
                const nomGrade = row.cells[2].textContent.toLowerCase(); // Check name column
                const idGrade = row.cells[1].textContent.toLowerCase(); // Check ID column
                
                if (nomGrade.includes(searchTerm) || idGrade.includes(searchTerm)) {
                    row.style.display = '';
                    hasResults = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Display message if no results and search term is not empty
            const emptyRowPlaceholder = gradeTableBody.querySelector('td[colspan="4"]');
            if (!hasResults && searchTerm !== "") {
                if (!emptyRowPlaceholder) { // Add if not already present
                    gradeTableBody.innerHTML = `
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                <i class="fas fa-search-minus" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                Aucun résultat trouvé pour "${searchTerm}".
                            </td>
                        </tr>
                    `;
                }
            } else if (searchTerm === "" && emptyRowPlaceholder && gradeTableBody.children.length === 1) {
                // If search is cleared and only placeholder exists, try to reload initial data
                loadInitialGrades();
            } else if (searchTerm !== "" && hasResults && emptyRowPlaceholder) {
                // If there are results now, remove the "no results" message
                 emptyRowPlaceholder.closest('tr').remove();
            }
        }

        // Fonction pour appliquer les filtres
        function applyFilter(filterType) {
            let rows = Array.from(gradeTableBody.querySelectorAll('tr[data-id]')); // Select only data rows
            
            // Remove previous empty message if any
            const emptyRowPlaceholder = gradeTableBody.querySelector('td[colspan="4"]');
            if (emptyRowPlaceholder) {
                emptyRowPlaceholder.closest('tr').remove();
            }

            // Show all rows initially before sorting/filtering by content
            rows.forEach(row => {
                row.style.display = '';
            });
            
            // Sort rows based on filterType
            rows.sort((a, b) => {
                const idA = parseInt(a.cells[1].textContent);
                const idB = parseInt(b.cells[1].textContent);
                const nameA = a.cells[2].textContent.toLowerCase();
                const nameB = b.cells[2].textContent.toLowerCase();
                
                switch (filterType) {
                    case 'id-asc':
                        return idA - idB;
                    case 'id-desc':
                        return idB - idA;
                    case 'name-asc':
                        return nameA.localeCompare(nameB);
                    case 'name-desc':
                        return nameB.localeCompare(nameA);
                    default: // 'all' or unknown filter, keep original order or default sort
                        return 0;
                }
            });
            
            // Re-append sorted rows to the table body
            gradeTableBody.innerHTML = ''; // Clear current display
            if (rows.length === 0) {
                 gradeTableBody.innerHTML = `
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-medal" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucun grade trouvé. Ajoutez votre premier grade en utilisant le formulaire ci-dessus.
                        </td>
                    </tr>
                `;
            } else {
                rows.forEach(row => gradeTableBody.appendChild(row));
            }
            showAlert('Filtre appliqué.', 'info');
        }

        // Function to reload initial grades if the table is empty due to search/filter
        function loadInitialGrades() {
            // This is a simple reload. In a real app, you might fetch data from the server.
            // For now, it restores the PHP-rendered initial state.
            location.reload(); 
        }

        // Soumission du formulaire
        gradeForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = {
                action: editingGrade ? 'update' : 'create',
                nom_grd: formData.get('nom_grd')
            };

            if (editingGrade) {
                data.id_grd = editingGrade;
            }

            try {
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;

                const result = await makeAjaxRequest(data);

                if (result.success) {
                    if (editingGrade) {
                        // Mettre à jour la ligne existante
                        const row = document.querySelector(`tr[data-id="${editingGrade}"]`);
                        if (row) {
                            row.cells[2].textContent = data.nom_grd; // Update name column
                        }
                        showAlert('Grade modifié avec succès', 'success');
                        resetForm();
                    } else {
                        // Ajouter une nouvelle ligne
                        addRowToTable(result.data);
                        showAlert(`Grade "${data.nom_grd}" créé avec succès`, 'success');
                    }
                    this.reset();
                    // Clear search and filters after successful operation
                    searchInput.value = '';
                    applyFilter('all'); // Re-apply 'all' filter to show everything
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'enregistrement', 'error');
            } finally {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }
        });

        // Fonction pour réinitialiser le formulaire
        function resetForm() {
            editingGrade = null;
            submitText.textContent = 'Ajouter Grade';
            submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Ajouter Grade';
            gradeForm.reset();
            selectedGrades.clear(); // Clear selections on reset
            updateActionButtons(); // Update button states
            // Ensure checkboxes are unchecked
            gradeTableBody.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
        }

        // Bouton Annuler
        cancelBtn.addEventListener('click', function() {
            resetForm();
        });

        // Fonction pour modifier un grade
        function modifierGrade(idGrade) {
            const row = document.querySelector(`tr[data-id="${idGrade}"]`);
            if (row) {
                editingGrade = idGrade;
                nomGradeInput.value = row.cells[2].textContent; // Get name from second cell (index 2)
                
                submitText.textContent = 'Mettre à jour';
                submitBtn.innerHTML = '<i class="fas fa-edit"></i> Mettre à jour';
                
                // Uncheck all other checkboxes and select this one
                selectedGrades.clear();
                gradeTableBody.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
                const currentCheckbox = row.querySelector('input[type="checkbox"]');
                currentCheckbox.checked = true;
                selectedGrades.add(currentCheckbox.value);
                updateActionButtons();

                // Faire défiler vers le formulaire
                document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Fonction pour supprimer un grade
        async function supprimerGrade(idGrade) {
            const row = document.querySelector(`tr[data-id="${idGrade}"]`);
            if (row) {
                const nomGrade = row.cells[2].textContent;
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer le grade "${nomGrade}" ?\n\nAttention : Cette action supprimera aussi les associations avec les enseignants.`)) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'delete',
                            ids_grades: JSON.stringify([idGrade])
                        });

                        if (result.success) {
                            row.remove();
                            selectedGrades.delete(idGrade);
                            updateActionButtons();
                            showAlert('Grade supprimé avec succès', 'success');
                            
                            // Si plus de grades, afficher le message vide
                            if (gradeTableBody.children.length === 0) {
                                gradeTableBody.innerHTML = `
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                            <i class="fas fa-medal" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                            Aucun grade trouvé. Ajoutez votre premier grade en utilisant le formulaire ci-dessus.
                                        </td>
                                    </tr>
                                `;
                            }
                        } else {
                            showAlert(result.message, 'error');
                        }
                    } catch (error) {
                        showAlert('Erreur lors de la suppression', 'error');
                    }
                }
            }
        }

        // Bouton Modifier global
        modifierGradeBtn.addEventListener('click', function() {
            if (selectedGrades.size === 1) {
                const idGrade = Array.from(selectedGrades)[0];
                modifierGrade(idGrade);
            } else {
                showAlert('Veuillez sélectionner un seul grade à modifier.', 'warning');
            }
        });

        // Bouton Supprimer global
        supprimerGradeBtn.addEventListener('click', async function() {
            if (selectedGrades.size === 0) {
                showAlert('Veuillez sélectionner au moins un grade à supprimer.', 'warning');
                return;
            }
            const idsArray = Array.from(selectedGrades);
            
            if (confirm(`Êtes-vous sûr de vouloir supprimer ${idsArray.length} grade(s) sélectionné(s) ?\n\nAttention : Cette action supprimera aussi les associations avec les enseignants.`)) {
                try {
                    const result = await makeAjaxRequest({
                        action: 'delete',
                        ids_grades: JSON.stringify(idsArray)
                    });

                    if (result.success) {
                        idsArray.forEach(id => {
                            const row = document.querySelector(`tr[data-id="${id}"]`);
                            if (row) row.remove();
                        });
                        selectedGrades.clear();
                        updateActionButtons();
                        showAlert('Grade(s) supprimé(s) avec succès', 'success');
                        
                        // If no grades left, display empty message
                        if (gradeTableBody.children.length === 0) {
                            gradeTableBody.innerHTML = `
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                        <i class="fas fa-medal" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucun grade trouvé. Ajoutez votre premier grade en utilisant le formulaire ci-dessus.
                                    </td>
                                </tr>
                            `;
                        }
                    } else {
                        showAlert(result.message, 'error');
                    }
                } catch (error) {
                    showAlert('Erreur lors de la suppression', 'error');
                }
            }
        });

        // Fonction pour obtenir les données d'export
        function getExportData() {
            const headers = ['ID', 'Nom du Grade'];
            const rows = [];
            
            const visibleRows = gradeTableBody.querySelectorAll('tr[data-id]');
            visibleRows.forEach(row => {
                if (row.style.display !== 'none') {
                    rows.push([
                        row.cells[1].textContent, // ID
                        row.cells[2].textContent  // Nom du Grade
                    ]);
                }
            });
            
            return { headers, rows };
        }

        // Export PDF
        exportPdfBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) {
                    showAlert('Aucun grade à exporter.', 'warning');
                    return;
                }
                
                const doc = new jsPDF();
                doc.setFontSize(18);
                doc.text('Liste des Grades Enseignants', 14, 20);
                doc.setFontSize(10);
                doc.text(`Exporté le: ${new Date().toLocaleDateString()}`, 14, 30);
                doc.autoTable({
                    head: [headers],
                    body: rows,
                    startY: 40,
                    styles: { fontSize: 10 },
                    headStyles: { fillColor: [59, 130, 246], textColor: 255, fontStyle: 'bold' }
                });
                
                doc.save(`grades_enseignants_${new Date().toISOString().slice(0,10)}.pdf`);
                showAlert('Exportation PDF terminée', 'success');
            } catch (error) {
                console.error("Erreur lors de l'export PDF:", error);
                showAlert("Erreur lors de l'export PDF", 'error');
            }
        });

        // Export Excel
        exportExcelBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) {
                    showAlert('Aucun grade à exporter.', 'warning');
                    return;
                }
                
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
                XLSX.utils.book_append_sheet(wb, ws, "Grades");
                XLSX.writeFile(wb, `grades_enseignants_${new Date().toISOString().slice(0,10)}.xlsx`);
                showAlert('Exportation Excel terminée', 'success');
            } catch (error) {
                console.error("Erreur lors de l'export Excel:", error);
                showAlert("Erreur lors de l'export Excel", 'error');
            }
        });

        // Export CSV
        exportCsvBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) {
                    showAlert('Aucun grade à exporter.', 'warning');
                    return;
                }
                
                let csvContent = headers.map(h => `"${h}"`).join(";") + "\n"; // Use semicolon for separator
                rows.forEach(row => csvContent += row.map(cell => `"${cell}"`).join(";") + "\n");
                
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                
                link.setAttribute('href', url);
                link.setAttribute('download', `grades_enseignants_${new Date().toISOString().slice(0,10)}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                showAlert('Exportation CSV terminée', 'success');
            } catch (error) {
                console.error("Erreur lors de l'export CSV:", error);
                showAlert("Erreur lors de l'export CSV", 'error');
            }
        });

        // Search event listeners
        searchButton.addEventListener('click', searchGrades);
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                searchGrades();
            }
        });
        searchInput.addEventListener('input', searchGrades); // Live search

        // Filter event listeners
        filterButton.addEventListener('click', function() {
            filterDropdown.classList.toggle('show');
        });

        filterOptions.forEach(option => {
            option.addEventListener('click', function() {
                const filterType = this.getAttribute('data-filter');
                applyFilter(filterType);
                filterDropdown.classList.remove('show');
            });
        });

        // Close dropdown if click outside
        window.addEventListener('click', function(e) {
            if (!e.target.matches('.filter-button') && !e.target.closest('.filter-dropdown')) {
                filterDropdown.classList.remove('show');
            }
        });

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Attach event listeners to existing rows
            document.querySelectorAll('#gradeTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="4"]')) { // Ensure it's a data row, not the empty message
                    attachEventListenersToRow(row);
                }
            });
            
            updateActionButtons();

            // Responsive: handle mobile layout on load
            handleResponsiveLayout();
        });

        // Responsive: Handle mobile layout
        function handleResponsiveLayout() {
            const actionTexts = document.querySelectorAll('.action-text');
            const isMobile = window.innerWidth < 768;

            actionTexts.forEach(text => {
                text.style.display = isMobile ? 'none' : 'inline';
            });

            // Adjust sidebar toggle icon for mobile
            if (sidebarToggle) {
                const barsIcon = sidebarToggle.querySelector('.fa-bars');
                const timesIcon = sidebarToggle.querySelector('.fa-times');
                if (isMobile) {
                    barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                } else {
                    barsIcon.style.display = 'inline-block'; // Or 'none' if sidebar is always open on desktop
                    if (timesIcon) timesIcon.style.display = 'none';
                }
            }

            // Collapse sidebar by default on mobile
            if (isMobile) {
                if (sidebar) sidebar.classList.add('collapsed');
                if (mainContent) mainContent.classList.add('sidebar-collapsed');
            } else {
                if (sidebar) sidebar.classList.remove('collapsed');
                if (mainContent) mainContent.classList.remove('sidebar-collapsed');
            }
        }

        window.addEventListener('resize', handleResponsiveLayout);
        handleResponsiveLayout(); // Call on initial load

        // Expose functions to global scope for inline onclicks
        window.modifierGrade = modifierGrade;
        window.supprimerGrade = supprimerGrade;
    </script>
</body>
</html>