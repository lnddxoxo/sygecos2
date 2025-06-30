<?php
// gestion_filiere.php
require_once 'config.php'; // Assurez-vous que ce fichier inclut votre connexion PDO et les fonctions isLoggedIn/redirect

if (!isLoggedIn()) {
    redirect('loginForm.php'); // Redirige si l'utilisateur n'est pas connecté
}

// Traitement AJAX pour l'ajout/suppression/modification de filières
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';

    try {
        $pdo->beginTransaction();

        switch ($action) {
            case 'create':
                $libFiliere = trim($_POST['lib_filiere']);

                if (empty($libFiliere)) {
                    throw new Exception("Le libellé de la filière ne peut pas être vide.");
                }

                // Vérifier si la filière existe déjà
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM filiere WHERE lib_filiere = ?");
                $checkStmt->execute([$libFiliere]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("La filière '" . htmlspecialchars($libFiliere) . "' existe déjà.");
                }

                // Insérer la nouvelle filière (id_filiere est AUTO_INCREMENT)
                $stmt = $pdo->prepare("INSERT INTO filiere (lib_filiere) VALUES (?)");
                $stmt->execute([$libFiliere]);
                $newId = $pdo->lastInsertId(); // Récupérer l'ID auto-généré

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Filière ajoutée avec succès !', 'data' => ['id_filiere' => $newId, 'lib_filiere' => $libFiliere]]);
                break;

            case 'update':
                $idFiliere = $_POST['id_filiere'];
                $libFiliere = trim($_POST['lib_filiere']);

                // Validation
                if (empty($idFiliere)) {
                    throw new Exception("ID de filière manquant pour la modification.");
                }
                if (empty($libFiliere)) {
                    throw new Exception("Le libellé de la filière ne peut pas être vide.");
                }

                // Vérifier si la filière existe déjà pour un autre ID
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM filiere WHERE lib_filiere = ? AND id_filiere != ?");
                $checkStmt->execute([$libFiliere, $idFiliere]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("La filière '" . htmlspecialchars($libFiliere) . "' existe déjà.");
                }

                $stmt = $pdo->prepare("UPDATE filiere SET lib_filiere = ? WHERE id_filiere = ?");
                $stmt->execute([$libFiliere, $idFiliere]);

                if ($stmt->rowCount() > 0) {
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Filière modifiée avec succès !']);
                } else {
                    throw new Exception("Filière non trouvée ou aucune modification effectuée.");
                }
                break;

            case 'delete':
                $idsFilieres = json_decode($_POST['ids_filieres'], true);

                foreach ($idsFilieres as $idFiliere) {
                    if (empty($idFiliere)) {
                        throw new Exception("ID de filière manquant pour la suppression.");
                    }

                    // Vérifier si la filière est utilisée par des étudiants
                    $checkUsageStmt = $pdo->prepare("SELECT COUNT(*) FROM etudiant WHERE fk_id_filiere = ?");
                    $checkUsageStmt->execute([$idFiliere]);
                    if ($checkUsageStmt->fetchColumn() > 0) {
                        throw new Exception("Impossible de supprimer la filière ID {$idFiliere}. Elle est associée à des étudiants. Veuillez d'abord modifier ou supprimer les étudiants associés.");
                    }

                    $stmt = $pdo->prepare("DELETE FROM filiere WHERE id_filiere = ?");
                    $stmt->execute([$idFiliere]);

                    if ($stmt->rowCount() === 0) {
                        // Si la suppression échoue pour un ID spécifique, annuler la transaction et lever une exception
                        throw new Exception("La filière ID {$idFiliere} non trouvée ou déjà supprimée.");
                    }
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Filière(s) supprimée(s) avec succès !']);
                break;

            default:
                throw new Exception("Action non reconnue.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
    }
    exit;
}

// Récupérer les filières existantes pour l'affichage
$filieres = [];
try {
    $stmt = $pdo->query("SELECT id_filiere, lib_filiere FROM filiere ORDER BY lib_filiere ASC");
    $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de récupération des filières: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Gestion des Filières</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Inclure votre CSS existant ici */
        /* === VARIABLES CSS === */
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

        /* === PAGE SPECIFIC STYLES === */
        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--space-2); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); }

        .form-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-8); }
        .form-card-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-6); border-bottom: 1px solid var(--gray-200); padding-bottom: var(--space-4); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-6); }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: var(--text-sm); font-weight: 500; color: var(--gray-700); margin-bottom: var(--space-2); }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group select {
            padding: var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            color: var(--gray-800);
            transition: all var(--transition-fast);
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="email"]:focus,
        .form-group select:focus {
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

        /* Messages d'alerte */
        .alert {
            padding: var(--space-4);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-4);
            display: none;
        }

        .alert.success {
            background-color: var(--secondary-50);
            color: var(--secondary-600);
            border: 1px solid var(--secondary-100);
        }

        .alert.error {
            background-color: #fef2f2;
            color: var(--error-500);
            border: 1px solid #fecaca;
        }

        /* Loading spinner */
        .loading {
            opacity: 0.6;
            pointer-events: none;
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
        <?php include 'sidebar_respo_scolarité.php'; // Assurez-vous que le chemin est correct ?>
        <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>


        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; // Assurez-vous que le chemin est correct ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Gestion des Filières</h1>
                    <p class="page-subtitle">Ajoutez ou supprimez les filières d'études disponibles.</p>
                </div>

                <div class="form-card">
                    <h3 class="form-card-title">Ajouter une nouvelle Filière</h3>
                    <form id="filiereForm">
                        <div class="form-grid" style="grid-template-columns: 1fr;">
                            <div class="form-group">
                                <label for="lib_filiere">Libellé de la Filière <span style="color: var(--error-500);">*</span></label>
                                <input type="text" id="lib_filiere" name="lib_filiere" placeholder="Ex: MIAGE" required>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-plus-circle"></i> <span id="submitText">Ajouter Filière</span>
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
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher une filière...">
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
                        <h3 class="table-title">Liste des Filières</h3>
                        <div class="table-actions">
                            <div class="filter-dropdown">
                                <button class="filter-button" id="filterButton">
                                    <i class="fas fa-filter"></i> Filtres
                                </button>
                                <div class="filter-dropdown-content" id="filterDropdown">
                                    <div class="filter-option" data-filter="all">
                                        <i class="fas fa-list"></i> Toutes les filières
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
                            <button class="btn btn-secondary" id="modifierFiliereBtn" disabled>
                                <i class="fas fa-edit"></i> <span class="action-text">Modifier</span>
                            </button>
                            <button class="btn btn-secondary" id="supprimerFiliereBtn" disabled>
                                <i class="fas fa-trash-alt"></i> <span class="action-text">Supprimer</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="filiereTable">
                            <thead>
                                <tr>
                                    <th style="width: 50px;"></th>
                                    <th>ID</th>
                                    <th>Libellé de la Filière</th>
                                    <th style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($filieres)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                        <i class="fas fa-chalkboard" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucune filière trouvée. Ajoutez votre première filière ci-dessus.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($filieres as $filiere): ?>
                                    <tr data-id="<?php echo htmlspecialchars($filiere['id_filiere']); ?>">
                                        <td>
                                            <label class="checkbox-container">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($filiere['id_filiere']); ?>">
                                                <span class="checkmark"></span>
                                            </label>
                                        </td>
                                        <td><?php echo htmlspecialchars($filiere['id_filiere']); ?></td>
                                        <td><?php echo htmlspecialchars($filiere['lib_filiere']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button edit" title="Modifier" onclick="modifierFiliere('<?php echo htmlspecialchars($filiere['id_filiere']); ?>')">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="action-button delete" title="Supprimer" onclick="supprimerFiliere('<?php echo htmlspecialchars($filiere['id_filiere']); ?>')">
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
        let selectedFilieres = new Set();
        let editingFiliere = null;
        const { jsPDF } = window.jspdf;

        // Éléments DOM
        const filiereForm = document.getElementById('filiereForm');
        const libFiliereInput = document.getElementById('lib_filiere');
        const filiereTableBody = document.querySelector('#filiereTable tbody');
        const modifierFiliereBtn = document.getElementById('modifierFiliereBtn');
        const supprimerFiliereBtn = document.getElementById('supprimerFiliereBtn');
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
        const exportPdfBtn = document.getElementById('exportPdfBtn');
        const exportExcelBtn = document.getElementById('exportExcelBtn');
        const exportCsvBtn = document.getElementById('exportCsvBtn');


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
            if (selectedFilieres.size === 1) {
                modifierFiliereBtn.disabled = false;
                supprimerFiliereBtn.disabled = false;
            } else if (selectedFilieres.size > 1) {
                modifierFiliereBtn.disabled = true;
                supprimerFiliereBtn.disabled = false;
            } else {
                modifierFiliereBtn.disabled = true;
                supprimerFiliereBtn.disabled = true;
            }
        }

        // Fonction pour ajouter une ligne dans le tableau
        function addRowToTable(filiere) {
            // Supprimer le message "Aucune filière trouvée" s'il existe
            const emptyRow = filiereTableBody.querySelector('td[colspan="4"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }

            const newRow = filiereTableBody.insertRow();
            newRow.setAttribute('data-id', filiere.id_filiere);
            newRow.innerHTML = `
                <td>
                    <label class="checkbox-container">
                        <input type="checkbox" value="${filiere.id_filiere}">
                        <span class="checkmark"></span>
                    </label>
                </td>
                <td>${filiere.id_filiere}</td>
                <td>${filiere.lib_filiere}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-button edit" title="Modifier" onclick="modifierFiliere('${filiere.id_filiere}')">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button class="action-button delete" title="Supprimer" onclick="supprimerFiliere('${filiere.id_filiere}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            `;
            attachEventListenersToRow(newRow);
        }

        // Fonction pour attacher les événements aux lignes (checkbox)
        function attachEventListenersToRow(row) {
            const checkbox = row.querySelector('input[type="checkbox"]');
            
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    selectedFilieres.add(this.value);
                } else {
                    selectedFilieres.delete(this.value);
                }
                updateActionButtons();
            });
        }

        // Fonction de recherche
        function searchFilieres() {
            const searchTerm = searchInput.value.toLowerCase();
            const rows = filiereTableBody.querySelectorAll('tr');
            
            let foundRows = 0;
            rows.forEach(row => {
                // Skip the "no data" row
                if (row.querySelector('td[colspan="4"]')) {
                    row.style.display = 'none'; // Hide it during search
                    return;
                }
                
                const libFiliere = row.cells[2].textContent.toLowerCase();
                const idFiliere = row.cells[1].textContent.toLowerCase();
                
                if (libFiliere.includes(searchTerm) || idFiliere.includes(searchTerm)) {
                    row.style.display = '';
                    foundRows++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Show "no data" message if no results and it's not already there
            const noDataRow = filiereTableBody.querySelector('td[colspan="4"]');
            if (foundRows === 0) {
                if (!noDataRow) {
                     filiereTableBody.innerHTML = `
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                <i class="fas fa-chalkboard" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                Aucune filière trouvée pour cette recherche.
                            </td>
                        </tr>
                    `;
                } else {
                    noDataRow.closest('tr').style.display = '';
                    noDataRow.innerHTML = `
                        <i class="fas fa-chalkboard" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                        Aucune filière trouvée pour cette recherche.
                    `;
                }
            } else {
                if (noDataRow) {
                    noDataRow.closest('tr').remove(); // Remove the "no data" message
                }
            }
        }

        // Fonction pour appliquer les filtres
        function applyFilter(filterType) {
            const rows = Array.from(filiereTableBody.querySelectorAll('tr'));
            
            // Remove the "no data" message if it exists
            const emptyRow = filiereTableBody.querySelector('td[colspan="4"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }
            
            // Display all rows before applying the filter
            rows.forEach(row => {
                if (!row.querySelector('td[colspan="4"]')) {
                    row.style.display = '';
                }
            });
            
            // Sort rows based on the filter type
            rows.sort((a, b) => {
                if (a.querySelector('td[colspan="4"]') || b.querySelector('td[colspan="4"]')) return 0;
                
                const idA = parseInt(a.cells[1].textContent);
                const idB = parseInt(b.cells[1].textContent);
                const libA = a.cells[2].textContent.toLowerCase();
                const libB = b.cells[2].textContent.toLowerCase();
                
                switch (filterType) {
                    case 'id-asc':
                        return idA - idB;
                    case 'id-desc':
                        return idB - idA;
                    case 'name-asc':
                        return libA.localeCompare(libB);
                    case 'name-desc':
                        return libB.localeCompare(libA);
                    default:
                        return 0;
                }
            });
            
            // Re-append sorted rows to the DOM
            rows.forEach(row => {
                filiereTableBody.appendChild(row);
            });
            
            // If no rows after filtering, display the "no data" message
            if (filiereTableBody.children.length === 0 || (filiereTableBody.children.length === 1 && filiereTableBody.querySelector('td[colspan="4"]'))) {
                filiereTableBody.innerHTML = `
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-chalkboard" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucune filière trouvée. Ajoutez votre première filière ci-dessus.
                        </td>
                    </tr>
                `;
            }
        }


        // Soumission du formulaire d'ajout/modification de filière
        filiereForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = {
                action: editingFiliere ? 'update' : 'create',
                lib_filiere: formData.get('lib_filiere')
            };

            if (editingFiliere) {
                data.id_filiere = editingFiliere;
            }

            submitBtn.classList.add('loading');
            submitBtn.disabled = true;

            try {
                const result = await makeAjaxRequest(data);

                if (result.success) {
                    showAlert(result.message, 'success');
                    if (editingFiliere) {
                        // Update existing row
                        const row = document.querySelector(`tr[data-id="${editingFiliere}"]`);
                        if (row) {
                            row.cells[2].textContent = data.lib_filiere;
                        }
                        resetForm();
                    } else {
                        // Add new row
                        addRowToTable(result.data);
                    }
                    this.reset();
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'enregistrement de la filière.', 'error');
            } finally {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }
        });

        // Fonction pour réinitialiser le formulaire
        function resetForm() {
            editingFiliere = null;
            submitText.textContent = 'Ajouter Filière';
            submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Ajouter Filière';
            filiereForm.reset();
            selectedFilieres.clear();
            updateActionButtons();
        }

        // Bouton Annuler/Reset
        cancelBtn.addEventListener('click', function() {
            resetForm();
            // Optionally clear search and filters
            searchInput.value = '';
            searchFilieres(); // Re-apply search to clear results
            applyFilter('all'); // Re-apply default filter
        });

        // Fonction pour modifier une seule filière
        function modifierFiliere(idFiliere) {
            const row = document.querySelector(`tr[data-id="${idFiliere}"]`);
            if (row) {
                editingFiliere = idFiliere;
                libFiliereInput.value = row.cells[2].textContent; // Column 2 is Libellé de la Filière
                
                submitText.textContent = 'Mettre à jour';
                submitBtn.innerHTML = '<i class="fas fa-edit"></i> Mettre à jour';
                
                // Scroll to the form
                document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
                
                // Uncheck all other checkboxes and select only this one
                document.querySelectorAll('#filiereTable tbody input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
                const currentCheckbox = row.querySelector('input[type="checkbox"]');
                currentCheckbox.checked = true;
                selectedFilieres.clear();
                selectedFilieres.add(idFiliere);
                updateActionButtons();
            }
        }

        // Bouton de suppression de la sélection multiple ou d'un seul élément
        supprimerFiliereBtn.addEventListener('click', async function() {
            if (selectedFilieres.size === 0) {
                showAlert('Aucune filière sélectionnée pour la suppression.', 'warning');
                return;
            }

            const idsArray = Array.from(selectedFilieres);
            const confirmationMessage = idsArray.length === 1
                ? `Êtes-vous sûr de vouloir supprimer cette filière (ID: ${idsArray[0]}) ?\n\nCette action est irréversible et pourrait échouer si la filière est associée à des étudiants.`
                : `Êtes-vous sûr de vouloir supprimer les ${idsArray.length} filière(s) sélectionnée(s) ?\n\nCette action est irréversible et pourrait échouer si des filières sont associées à des étudiants.`;

            if (confirm(confirmationMessage)) {
                let successCount = 0;
                let errorMessages = [];

                for (const idFiliere of idsArray) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'delete',
                            ids_filieres: JSON.stringify([idFiliere])
                        });
                        if (result.success) {
                            successCount++;
                            document.querySelector(`tr[data-id="${idFiliere}"]`).remove();
                        } else {
                            errorMessages.push(`Filière ID ${idFiliere}: ${result.message}`);
                        }
                    } catch (error) {
                        errorMessages.push(`Filière ID ${idFiliere}: Erreur réseau ou serveur.`);
                    }
                }

                selectedFilieres.clear(); // Efface la sélection après le traitement
                updateActionButtons();

                if (successCount > 0) {
                    showAlert(`${successCount} filière(s) supprimée(s) avec succès !`, 'success');
                }
                if (errorMessages.length > 0) {
                    showAlert(`Erreurs lors de la suppression de certaines filières:\n${errorMessages.join('\n')}`, 'error');
                }
                // Si plus de filières, afficher le message vide
                if (filiereTableBody.children.length === 0) {
                    filiereTableBody.innerHTML = `
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                <i class="fas fa-chalkboard" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                Aucune filière trouvée. Ajoutez votre première filière ci-dessus.
                            </td>
                        </tr>
                    `;
                }
            }
        });

        // Bouton Modifier global
        modifierFiliereBtn.addEventListener('click', function() {
            if (selectedFilieres.size === 1) {
                const idFiliere = Array.from(selectedFilieres)[0];
                modifierFiliere(idFiliere);
            } else {
                showAlert("Veuillez sélectionner exactement une filière à modifier.", "warning");
            }
        });

        // Fonction pour exporter en PDF
        function exportToPdf() {
            const doc = new jsPDF();
            const title = "Liste des Filières";
            const date = new Date().toLocaleDateString();
            
            // Titre
            doc.setFontSize(18);
            doc.text(title, 14, 20);
            
            // Date
            doc.setFontSize(10);
            doc.text(`Exporté le: ${date}`, 14, 30);
            
            // Tableau
            const headers = [['ID', 'Libellé de la Filière']];
            const data = [];
            
            document.querySelectorAll('#filiereTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="4"]')) { // Exclude "no data" row
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent
                    ]);
                }
            });
            
            doc.autoTable({
                head: headers,
                body: data,
                startY: 40,
                styles: {
                    fontSize: 10,
                    cellPadding: 3,
                    valign: 'middle'
                },
                headStyles: {
                    fillColor: [59, 130, 246],
                    textColor: 255,
                    fontStyle: 'bold'
                },
                alternateRowStyles: {
                    fillColor: [241, 245, 249]
                }
            });
            
            doc.save(`filieres_${new Date().toISOString().split('T')[0]}.pdf`);
            showAlert('Exportation PDF terminée');
        }

        // Fonction pour exporter en Excel
        function exportToExcel() {
            const data = [['ID', 'Libellé de la Filière']];
            
            document.querySelectorAll('#filiereTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="4"]')) { // Exclude "no data" row
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent
                    ]);
                }
            });

            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Filières");
            
            XLSX.writeFile(wb, `filieres_${new Date().toISOString().split('T')[0]}.xlsx`);
            
            showAlert('Exportation Excel terminée');
        }

        // Fonction pour exporter en CSV
        function exportToCsv() {
            let csv = "ID,Libellé de la Filière\n";
            
            document.querySelectorAll('#filiereTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="4"]')) { // Exclude "no data" row
                    csv += `"${row.cells[1].textContent}","${row.cells[2].textContent}"\n`;
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', `filieres_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showAlert('Exportation CSV terminée');
        }

        // Boutons d'export individuels
        exportPdfBtn.addEventListener('click', exportToPdf);
        exportExcelBtn.addEventListener('click', exportToExcel);
        exportCsvBtn.addEventListener('click', exportToCsv);

        // Recherche
        searchButton.addEventListener('click', searchFilieres);
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                searchFilieres();
            }
        });

        // Filtres
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

        // Close dropdown if clicked outside
        window.addEventListener('click', function(e) {
            if (!e.target.matches('.filter-button') && !e.target.closest('.filter-dropdown')) {
                filterDropdown.classList.remove('show');
            }
        });


        // Initialisation: attacher les événements aux lignes existantes au chargement
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#filiereTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="4"]')) { // Éviter le message "Aucune donnée"
                    attachEventListenersToRow(row);
                }
            });
            updateActionButtons();
            handleResponsiveActions();
        });

        // Responsive: Gestion mobile (réutilisée)
        function handleResize() {
            if (window.innerWidth <= 768) {
                if (sidebar) sidebar.classList.add('mobile');
            } else {
                if (sidebar) {
                    sidebar.classList.remove('mobile');
                    sidebar.classList.remove('collapsed');
                }
                if (mainContent) mainContent.classList.remove('sidebar-collapsed');
            }
            handleResponsiveActions();
        }
        window.addEventListener('resize', handleResize);
        handleResize();

        function handleResponsiveActions() {
            const actionTexts = document.querySelectorAll('.action-text');
            if (window.innerWidth < 768) {
                actionTexts.forEach(text => {
                    text.style.display = 'none';
                });
            } else {
                actionTexts.forEach(text => {
                    text.style.display = 'inline';
                });
            }
        }
    </script>
</body>
</html>