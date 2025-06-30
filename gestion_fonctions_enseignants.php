<?php
// gestion_fonctions_enseignant.php
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
                $nomFonction = trim($_POST['nom_fonction']);
                
                // Validation
                if (empty($nomFonction)) {
                    throw new Exception("Le nom de la fonction est obligatoire");
                }
                
                // Vérifier si la fonction existe déjà
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM fonction WHERE nom_fonction = ?");
                $checkStmt->execute([$nomFonction]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Cette fonction existe déjà");
                }
                
                // Générer l'ID
                $stmtMaxId = $pdo->query("SELECT COALESCE(MAX(id_fonction), 0) + 1 FROM fonction");
                $idFonction = $stmtMaxId->fetchColumn();
                
                // Insérer la nouvelle fonction
                $stmt = $pdo->prepare("INSERT INTO fonction (id_fonction, nom_fonction) VALUES (?, ?)");
                $stmt->execute([$idFonction, $nomFonction]);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Fonction créée avec succès',
                    'data' => [
                        'id_fonction' => $idFonction,
                        'nom_fonction' => $nomFonction
                    ]
                ]);
                break;
                
            case 'update':
                $idFonction = $_POST['id_fonction'];
                $nomFonction = trim($_POST['nom_fonction']);
                
                // Validation
                if (empty($nomFonction)) {
                    throw new Exception("Le nom de la fonction est obligatoire");
                }
                
                // Vérifier si la fonction existe déjà pour un autre ID
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM fonction WHERE nom_fonction = ? AND id_fonction != ?");
                $checkStmt->execute([$nomFonction, $idFonction]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Cette fonction existe déjà");
                }
                
                // Mettre à jour la fonction
                $stmt = $pdo->prepare("UPDATE fonction SET nom_fonction = ? WHERE id_fonction = ?");
                $stmt->execute([$nomFonction, $idFonction]);
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Fonction modifiée avec succès']);
                break;
                
            case 'delete':
                $idsFonctions = json_decode($_POST['ids_fonctions'], true);
                
                // Vérifier si des enseignants utilisent ces fonctions
                $placeholders = str_repeat('?,', count($idsFonctions) - 1) . '?';
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM occuper WHERE fk_id_fonc IN ($placeholders)");
                $checkStmt->execute($idsFonctions);
                $countUsage = $checkStmt->fetchColumn();
                
                if ($countUsage > 0) {
                    throw new Exception("Impossible de supprimer : $countUsage enseignant(s) utilisent ces fonctions");
                }
                
                // Supprimer les fonctions
                $stmt = $pdo->prepare("DELETE FROM fonction WHERE id_fonction IN ($placeholders)");
                $stmt->execute($idsFonctions);
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Fonction(s) supprimée(s) avec succès']);
                break;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Récupérer les fonctions existantes
$fonctions = [];
try {
    $stmt = $pdo->query("SELECT * FROM fonction ORDER BY nom_fonction");
    $fonctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erreur lors de la récupération des fonctions: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Fonctions Enseignants</title>
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
            padding: var(--space-4); /* Reduced padding for compact look */
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: var(--space-6);
            display: flex;
            align-items: center;
            gap: var(--space-3); /* Reduced gap */
        }

        .search-input-container {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: var(--space-3) var(--space-8); /* Adjusted padding for icon */
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
            /* This button is explicitly mentioned in HTML, keeping styles */
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

        /* Confirmation Modal Styles */
        .confirm-modal {
            display: none; /* Hidden by default */
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1050; /* Higher than message-modal */
            justify-content: center;
            align-items: center;
        }

        .confirm-modal-content {
            background-color: var(--white);
            padding: var(--space-6);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            max-width: 450px;
            width: 90%;
            text-align: center;
            position: relative;
        }

        .confirm-modal-icon {
            font-size: 3rem;
            color: var(--warning-500); /* Yellow for warning/confirmation */
            margin-bottom: var(--space-4);
        }

        .confirm-modal-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: var(--space-2);
        }

        .confirm-modal-text {
            color: var(--gray-700);
            margin-bottom: var(--space-6);
            line-height: 1.5;
        }

        .confirm-modal-actions {
            display: flex;
            justify-content: center;
            gap: var(--space-4);
        }

        .confirm-btn-cancel {
            background-color: var(--gray-300);
            color: var(--gray-800);
        }

        .confirm-btn-cancel:hover {
            background-color: var(--gray-400);
        }

        .confirm-btn-delete {
            background-color: var(--error-500);
            color: white;
        }

        .confirm-btn-delete:hover {
            background-color: var(--error-600);
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
                padding: var(--space-4); /* Ensure padding consistency for mobile */
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
                    <h1 class="page-title-main">Gestion des Fonctions Enseignants</h1>
                    <p class="page-subtitle">Ajoutez, modifiez ou supprimez les fonctions des enseignants.</p>
                </div>

                <div class="form-card">
                    <h3 class="form-card-title">Ajouter une nouvelle Fonction</h3>
                    <form id="functionForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="nom_fonction">Nom de la Fonction <span style="color: var(--error-500);">*</span></label>
                                <input type="text" id="nom_fonction" name="nom_fonction" placeholder="Ex: Professeur Assistant" required>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-plus-circle"></i> <span id="submitText">Ajouter Fonction</span>
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
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher une fonction...">
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
                        <h3 class="table-title">Liste des Fonctions</h3>
                        <div class="table-actions">
                            <div class="filter-dropdown">
                                <button class="filter-button" id="filterButton">
                                    <i class="fas fa-filter"></i> Filtres
                                </button>
                                <div class="filter-dropdown-content" id="filterDropdown">
                                    <div class="filter-option" data-filter="all">
                                        <i class="fas fa-list"></i> Toutes les fonctions
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
                            <button class="btn btn-secondary" id="modifierFunctionBtn" disabled>
                                <i class="fas fa-edit"></i> <span class="action-text">Modifier</span>
                            </button>
                            <button class="btn btn-secondary" id="supprimerFunctionBtn" disabled>
                                <i class="fas fa-trash-alt"></i> <span class="action-text">Supprimer</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="functionTable">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>ID</th>
                                    <th>Nom de la Fonction</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($fonctions)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                        <i class="fas fa-briefcase" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucune fonction trouvée. Ajoutez votre première fonction en utilisant le formulaire ci-dessus.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($fonctions as $fonction): ?>
                                    <tr data-id="<?php echo htmlspecialchars($fonction['id_fonction']); ?>">
                                        <td>
                                            <label class="checkbox-container">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($fonction['id_fonction']); ?>">
                                                <span class="checkmark"></span>
                                            </label>
                                        </td>
                                        <td><?php echo htmlspecialchars($fonction['id_fonction']); ?></td>
                                        <td><?php echo htmlspecialchars($fonction['nom_fonction']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button edit" title="Modifier" onclick="modifierFonction('<?php echo htmlspecialchars($fonction['id_fonction']); ?>')">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="action-button delete" title="Supprimer" onclick="supprimerFonctionConfirm('<?php echo htmlspecialchars($fonction['id_fonction']); ?>')">
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

    <div class="confirm-modal" id="confirmModal">
        <div class="confirm-modal-content">
            <div class="confirm-modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="confirm-modal-title" id="confirmModalTitle"></h3>
            <p class="confirm-modal-text" id="confirmModalText"></p>
            <div class="confirm-modal-actions">
                <button class="btn confirm-btn-delete" id="confirmDeleteBtn">Oui, Supprimer</button>
                <button class="btn confirm-btn-cancel" id="confirmCancelBtn">Annuler</button>
            </div>
        </div>
    </div>


    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script>
        // Variables globales
        let selectedFunctions = new Set();
        let editingFunction = null;
        let confirmActionCallback = null; // Callback function for confirmation modal
        const { jsPDF } = window.jspdf;

        // Éléments DOM
        const functionForm = document.getElementById('functionForm');
        const nomFonctionInput = document.getElementById('nom_fonction');
        const functionTableBody = document.querySelector('#functionTable tbody');
        const modifierFunctionBtn = document.getElementById('modifierFunctionBtn');
        const supprimerFunctionBtn = document.getElementById('supprimerFunctionBtn');
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

        // Message Modal elements
        const messageModal = document.getElementById('messageModal');
        const messageTitle = document.getElementById('messageTitle');
        const messageText = document.getElementById('messageText');
        const messageIcon = document.getElementById('messageIcon');
        const messageButton = document.getElementById('messageButton');
        const messageClose = document.getElementById('messageClose');

        // Confirmation Modal elements
        const confirmModal = document.getElementById('confirmModal');
        const confirmModalTitle = document.getElementById('confirmModalTitle');
        const confirmModalText = document.getElementById('confirmModalText');
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        const confirmCancelBtn = document.getElementById('confirmCancelBtn');

        // Function to show alert messages in a modal
        function showAlert(message, type = 'success', title = null) {
            // Set default title based on type
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

            // Set icon based on type
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

        // Close the message modal
        function closeMessageModal() {
            messageModal.style.display = 'none';
        }

        // Event listeners for the message modal
        messageButton.addEventListener('click', closeMessageModal);
        messageClose.addEventListener('click', closeMessageModal);
        messageModal.addEventListener('click', function(e) {
            if (e.target === messageModal) {
                closeMessageModal();
            }
        });

        // Function to show confirmation modal
        function showConfirmModal(title, text, callback) {
            confirmModalTitle.textContent = title;
            confirmModalText.textContent = text;
            confirmActionCallback = callback; // Store the callback function
            confirmModal.style.display = 'flex';
        }

        // Close the confirmation modal
        function closeConfirmModal() {
            confirmModal.style.display = 'none';
            confirmActionCallback = null; // Clear the callback
        }

        // Event listeners for the confirmation modal buttons
        confirmDeleteBtn.addEventListener('click', function() {
            if (confirmActionCallback) {
                confirmActionCallback(true); // Execute callback with true (confirmed)
            }
            closeConfirmModal();
        });

        confirmCancelBtn.addEventListener('click', function() {
            if (confirmActionCallback) {
                confirmActionCallback(false); // Execute callback with false (cancelled)
            }
            closeConfirmModal();
        });

        // Close confirmation modal if clicked outside
        confirmModal.addEventListener('click', function(e) {
            if (e.target === confirmModal) {
                closeConfirmModal();
            }
        });


        // Handle sidebar toggle for mobile
        function toggleSidebar() {
            sidebar.classList.toggle('mobile-open');
            mobileMenuOverlay.classList.toggle('active');
            
            // Toggle between menu/close icons
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

        // Function for making AJAX requests
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

        // Function to update button states
        function updateActionButtons() {
            if (selectedFunctions.size === 1) {
                modifierFunctionBtn.disabled = false;
                supprimerFunctionBtn.disabled = false;
            } else if (selectedFunctions.size > 1) {
                modifierFunctionBtn.disabled = true;
                supprimerFunctionBtn.disabled = false;
            } else {
                modifierFunctionBtn.disabled = true;
                supprimerFunctionBtn.disabled = true;
            }
        }

        // Function to add a row to the table
        function addRowToTable(fonction) {
            // Remove "No function found" message if it exists
            const emptyRow = functionTableBody.querySelector('td[colspan="4"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }

            const newRow = functionTableBody.insertRow();
            newRow.setAttribute('data-id', fonction.id_fonction);
            newRow.innerHTML = `
                <td>
                    <label class="checkbox-container">
                        <input type="checkbox" value="${fonction.id_fonction}">
                        <span class="checkmark"></span>
                    </label>
                </td>
                <td>${fonction.id_fonction}</td> <td>${fonction.nom_fonction}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-button edit" title="Modifier" onclick="modifierFonction('${fonction.id_fonction}')">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button class="action-button delete" title="Supprimer" onclick="supprimerFonctionConfirm('${fonction.id_fonction}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            `;
            attachEventListenersToRow(newRow);
        }

        // Function to attach event listeners to rows
        function attachEventListenersToRow(row) {
            const checkbox = row.querySelector('input[type="checkbox"]');
            
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    selectedFunctions.add(this.value);
                } else {
                    selectedFunctions.delete(this.value);
                }
                updateActionButtons();
            });
        }

        // Search function
        function searchFunctions() {
            const searchTerm = searchInput.value.toLowerCase();
            const rows = functionTableBody.querySelectorAll('tr[data-id]'); // Select only data rows
            
            let hasResults = false;
            
            rows.forEach(row => {
                const nomFonction = row.cells[2].textContent.toLowerCase(); // Check name column
                const idFonction = row.cells[1].textContent.toLowerCase(); // Check ID column
                
                if (nomFonction.includes(searchTerm) || idFonction.includes(searchTerm)) {
                    row.style.display = '';
                    hasResults = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Display message if no results and search term is not empty
            const emptyRowPlaceholder = functionTableBody.querySelector('td[colspan="4"]');
            if (!hasResults && searchTerm !== "") {
                if (!emptyRowPlaceholder) { // Add if not already present
                    functionTableBody.innerHTML = `
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                <i class="fas fa-search-minus" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                Aucun résultat trouvé pour "${searchTerm}".
                            </td>
                        </tr>
                    `;
                }
            } else if (searchTerm === "" && emptyRowPlaceholder && functionTableBody.children.length === 1) {
                // If search is cleared and only placeholder exists, try to reload initial data
                loadInitialFunctions();
            } else if (searchTerm !== "" && hasResults && emptyRowPlaceholder) {
                // If there are results now, remove the "no results" message
                 emptyRowPlaceholder.closest('tr').remove();
            }
        }

        // Function to apply filters
        function applyFilter(filterType) {
            let rows = Array.from(functionTableBody.querySelectorAll('tr[data-id]')); // Select only data rows
            
            // Remove previous empty message if any
            const emptyRowPlaceholder = functionTableBody.querySelector('td[colspan="4"]');
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
            functionTableBody.innerHTML = ''; // Clear current display
            if (rows.length === 0) {
                 functionTableBody.innerHTML = `
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-briefcase" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucune fonction trouvée. Ajoutez votre première fonction en utilisant le formulaire ci-dessus.
                        </td>
                    </tr>
                `;
            } else {
                rows.forEach(row => functionTableBody.appendChild(row));
            }
            // No showAlert here as it's just a filter application, not a data modification confirmation.
        }

        // Function to reload initial functions if the table is empty due to search/filter
        function loadInitialFunctions() {
            // This is a simple reload. In a real app, you might fetch data from the server.
            // For now, it restores the PHP-rendered initial state.
            location.reload(); 
        }

        // Form submission
        functionForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = {
                action: editingFunction ? 'update' : 'create',
                nom_fonction: formData.get('nom_fonction')
            };

            if (editingFunction) {
                data.id_fonction = editingFunction;
            }

            try {
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;

                const result = await makeAjaxRequest(data);

                if (result.success) {
                    if (editingFunction) {
                        // Update existing row
                        const row = document.querySelector(`tr[data-id="${editingFunction}"]`);
                        if (row) {
                            row.cells[2].textContent = data.nom_fonction; // Update name column
                        }
                        showAlert('Fonction modifiée avec succès', 'success');
                        resetForm();
                    } else {
                        // Add new row
                        addRowToTable(result.data);
                        showAlert(`Fonction "${data.nom_fonction}" créée avec succès`, 'success');
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

        // Function to reset the form
        function resetForm() {
            editingFunction = null;
            submitText.textContent = 'Ajouter Fonction';
            submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Ajouter Fonction';
            functionForm.reset();
            selectedFunctions.clear(); // Clear selections on reset
            updateActionButtons(); // Update button states
            // Ensure checkboxes are unchecked
            functionTableBody.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
        }

        // Cancel button
        cancelBtn.addEventListener('click', function() {
            resetForm();
        });

        // Function to modify a function
        function modifierFonction(idFonction) {
            const row = document.querySelector(`tr[data-id="${idFonction}"]`);
            if (row) {
                editingFunction = idFonction;
                nomFonctionInput.value = row.cells[2].textContent; // Get name from the second cell (index 2)
                
                submitText.textContent = 'Mettre à jour';
                submitBtn.innerHTML = '<i class="fas fa-edit"></i> Mettre à jour';
                
                // Uncheck all other checkboxes and select this one
                selectedFunctions.clear();
                functionTableBody.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
                const currentCheckbox = row.querySelector('input[type="checkbox"]');
                currentCheckbox.checked = true;
                selectedFunctions.add(currentCheckbox.value);
                updateActionButtons();

                // Scroll to the form
                document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Function to confirm deletion of a single function
        function supprimerFonctionConfirm(idFonction) {
            const row = document.querySelector(`tr[data-id="${idFonction}"]`);
            if (row) {
                const nomFonction = row.cells[2].textContent;
                showConfirmModal(
                    'Confirmation de Suppression',
                    `Êtes-vous sûr de vouloir supprimer la fonction "${nomFonction}" ?\n\nAttention : Cette action supprimera aussi les associations avec les enseignants.`,
                    async (confirmed) => {
                        if (confirmed) {
                            try {
                                const result = await makeAjaxRequest({
                                    action: 'delete',
                                    ids_fonctions: JSON.stringify([idFonction])
                                });

                                if (result.success) {
                                    row.remove();
                                    selectedFunctions.delete(idFonction);
                                    updateActionButtons();
                                    showAlert('Fonction supprimée avec succès', 'success');
                                    
                                    // If no functions, display empty message
                                    if (functionTableBody.children.length === 0) {
                                        functionTableBody.innerHTML = `
                                            <tr>
                                                <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                                    <i class="fas fa-briefcase" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                                    Aucune fonction trouvée. Ajoutez votre première fonction en utilisant le formulaire ci-dessus.
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
                );
            }
        }

        // Global modify button
        modifierFunctionBtn.addEventListener('click', function() {
            if (selectedFunctions.size === 1) {
                const idFonction = Array.from(selectedFunctions)[0];
                modifierFonction(idFonction);
            } else {
                showAlert('Veuillez sélectionner une seule fonction à modifier.', 'warning');
            }
        });

        // Global delete button
        supprimerFunctionBtn.addEventListener('click', async function() {
            if (selectedFunctions.size === 0) {
                showAlert('Veuillez sélectionner au moins une fonction à supprimer.', 'warning');
                return;
            }
            const idsArray = Array.from(selectedFunctions);
            
            showConfirmModal(
                'Confirmation de Suppression',
                `Êtes-vous sûr de vouloir supprimer ${idsArray.length} fonction(s) sélectionnée(s) ?\n\nAttention : Cette action supprimera aussi les associations avec les enseignants.`,
                async (confirmed) => {
                    if (confirmed) {
                        try {
                            const result = await makeAjaxRequest({
                                action: 'delete',
                                ids_fonctions: JSON.stringify(idsArray)
                            });

                            if (result.success) {
                                idsArray.forEach(id => {
                                    const row = document.querySelector(`tr[data-id="${id}"]`);
                                    if (row) row.remove();
                                });
                                selectedFunctions.clear();
                                updateActionButtons();
                                showAlert('Fonction(s) supprimée(s) avec succès', 'success');
                                
                                // If no functions left, display empty message
                                if (functionTableBody.children.length === 0) {
                                    functionTableBody.innerHTML = `
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                                <i class="fas fa-briefcase" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                                Aucune fonction trouvée. Ajoutez votre première fonction en utilisant le formulaire ci-dessus.
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
            );
        });

        // Function to get export data
        function getExportData() {
            const headers = ['ID', 'Nom de la Fonction'];
            const rows = [];
            
            const visibleRows = functionTableBody.querySelectorAll('tr[data-id]'); // Only visible rows
            visibleRows.forEach(row => {
                if (row.style.display !== 'none') { // Check if row is visible
                    rows.push([
                        row.cells[1].textContent, // ID
                        row.cells[2].textContent  // Nom de la Fonction
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
                    showAlert('Aucune fonction à exporter.', 'warning');
                    return;
                }
                
                const doc = new jsPDF();
                doc.setFontSize(18);
                doc.text('Liste des Fonctions Enseignants', 14, 20);
                doc.setFontSize(10);
                doc.text(`Exporté le: ${new Date().toLocaleDateString()}`, 14, 30);
                doc.autoTable({
                    head: [headers],
                    body: rows,
                    startY: 40,
                    styles: { fontSize: 10 },
                    headStyles: { fillColor: [59, 130, 246], textColor: 255, fontStyle: 'bold' }
                });
                
                doc.save(`fonctions_enseignants_${new Date().toISOString().slice(0,10)}.pdf`);
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
                    showAlert('Aucune fonction à exporter.', 'warning');
                    return;
                }
                
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
                XLSX.utils.book_append_sheet(wb, ws, "Fonctions");
                XLSX.writeFile(wb, `fonctions_enseignants_${new Date().toISOString().slice(0,10)}.xlsx`);
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
                    showAlert('Aucune fonction à exporter.', 'warning');
                    return;
                }
                
                let csvContent = headers.map(h => `"${h}"`).join(";") + "\n"; // Use semicolon for separator
                rows.forEach(row => csvContent += row.map(cell => `"${cell}"`).join(";") + "\n");
                
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                
                link.setAttribute('href', url);
                link.setAttribute('download', `fonctions_enseignants_${new Date().toISOString().slice(0,10)}.csv`);
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
        searchButton.addEventListener('click', searchFunctions);
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                searchFunctions();
            }
        });
        searchInput.addEventListener('input', searchFunctions); // Live search

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

        // Initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Attach event listeners to existing rows
            document.querySelectorAll('#functionTable tbody tr').forEach(row => {
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
        window.modifierFonction = modifierFonction;
        window.supprimerFonctionConfirm = supprimerFonctionConfirm; // Expose the new confirmation function
    </script>
</body>
</html>