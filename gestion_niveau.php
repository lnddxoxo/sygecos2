<?php
// gestion_niveau.php
require_once 'config.php'; // Assurez-vous que ce fichier inclut votre connexion PDO et les fonctions isLoggedIn/redirect

if (!isLoggedIn()) {
    redirect('loginForm.php'); // Redirige si l'utilisateur n'est pas connecté
}

// Traitement AJAX pour l'ajout/suppression/modification de niveaux
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';

    try {
        $pdo->beginTransaction();

        switch ($action) {
            case 'create':
                $libNiveau = trim($_POST['lib_niveau']);
                $fkIdFiliere = $_POST['fk_id_filiere'];

                if (empty($libNiveau)) {
                    throw new Exception("Le libellé du niveau ne peut pas être vide.");
                }
                if (empty($fkIdFiliere)) {
                    throw new Exception("Veuillez sélectionner une filière.");
                }

                // Vérifier si le niveau existe déjà pour cette filière
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM niveau_etude WHERE lib_niv_etu = ? AND fk_id_filiere = ?");
                $checkStmt->execute([$libNiveau, $fkIdFiliere]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Le niveau '" . htmlspecialchars($libNiveau) . "' existe déjà pour cette filière.");
                }

                // Générer un ID unique pour le niveau (car id_niv_etu n'est pas AUTO_INCREMENT)
                // Trouver le plus grand ID existant et ajouter 1
                $stmtMaxId = $pdo->query("SELECT COALESCE(MAX(id_niv_etu), 0) + 1 FROM niveau_etude");
                $newId = $stmtMaxId->fetchColumn();


                // Insérer le nouveau niveau
                $stmt = $pdo->prepare("INSERT INTO niveau_etude (id_niv_etu, lib_niv_etu, fk_id_filiere) VALUES (?, ?, ?)");
                $stmt->execute([$newId, $libNiveau, $fkIdFiliere]);

                // Récupérer le libellé de la filière pour le renvoyer au client
                $stmtFiliere = $pdo->prepare("SELECT lib_filiere FROM filiere WHERE id_filiere = ?");
                $stmtFiliere->execute([$fkIdFiliere]);
                $libFiliere = $stmtFiliere->fetchColumn();

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Niveau ajouté avec succès !', 'data' => ['id_niv_etu' => $newId, 'lib_niv_etu' => $libNiveau, 'fk_id_filiere' => $fkIdFiliere, 'lib_filiere' => $libFiliere]]);
                break;

            case 'update':
                $idNiveau = $_POST['id_niveau'];
                $libNiveau = trim($_POST['lib_niveau']);
                $fkIdFiliere = $_POST['fk_id_filiere']; // Nouvelle filière sélectionnée

                // Validation
                if (empty($idNiveau)) {
                    throw new Exception("ID de niveau manquant pour la modification.");
                }
                if (empty($libNiveau)) {
                    throw new Exception("Le libellé du niveau ne peut pas être vide.");
                }
                if (empty($fkIdFiliere)) {
                    throw new Exception("Veuillez sélectionner une filière.");
                }

                // Vérifier si le niveau existe déjà avec le même libellé pour une AUTRE ID OU pour la même ID mais une nouvelle filière
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM niveau_etude WHERE lib_niv_etu = ? AND id_niv_etu != ? AND fk_id_filiere = ?");
                $checkStmt->execute([$libNiveau, $idNiveau, $fkIdFiliere]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Le niveau '" . htmlspecialchars($libNiveau) . "' existe déjà pour cette filière.");
                }
                
                $stmt = $pdo->prepare("UPDATE niveau_etude SET lib_niv_etu = ?, fk_id_filiere = ? WHERE id_niv_etu = ?");
                $stmt->execute([$libNiveau, $fkIdFiliere, $idNiveau]);

                if ($stmt->rowCount() > 0) {
                    // Récupérer le libellé de la filière pour le renvoyer au client
                    $stmtFiliere = $pdo->prepare("SELECT lib_filiere FROM filiere WHERE id_filiere = ?");
                    $stmtFiliere->execute([$fkIdFiliere]);
                    $libFiliere = $stmtFiliere->fetchColumn();

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Niveau modifié avec succès !', 'data' => ['id_niv_etu' => $idNiveau, 'lib_niv_etu' => $libNiveau, 'fk_id_filiere' => $fkIdFiliere, 'lib_filiere' => $libFiliere]]);
                } else {
                    throw new Exception("Niveau non trouvé ou aucune modification effectuée.");
                }
                break;

            case 'delete':
                $idsNiveaux = json_decode($_POST['ids_niveaux'], true);

                foreach ($idsNiveaux as $idNiveau) {
                    if (empty($idNiveau)) {
                        throw new Exception("ID de niveau manquant pour la suppression.");
                    }

                    // Vérifier si le niveau est utilisé par des étudiants
                    $checkUsageStmt = $pdo->prepare("SELECT COUNT(*) FROM etudiant WHERE fk_id_niv_etu = ?");
                    $checkUsageStmt->execute([$idNiveau]);
                    if ($checkUsageStmt->fetchColumn() > 0) {
                        // Récupérer le libellé du niveau pour un message plus spécifique
                        $stmtNiveau = $pdo->prepare("SELECT lib_niv_etu FROM niveau_etude WHERE id_niv_etu = ?");
                        $stmtNiveau->execute([$idNiveau]);
                        $libNiveau = $stmtNiveau->fetchColumn();
                        throw new Exception("Impossible de supprimer le niveau '{$libNiveau}' (ID {$idNiveau}). Il est associé à des étudiants. Veuillez d'abord modifier ou supprimer les étudiants associés.");
                    }

                    $stmt = $pdo->prepare("DELETE FROM niveau_etude WHERE id_niv_etu = ?");
                    $stmt->execute([$idNiveau]);

                    if ($stmt->rowCount() === 0) {
                        throw new Exception("Le niveau ID {$idNiveau} non trouvé ou déjà supprimé.");
                    }
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Niveau(x) supprimé(s) avec succès !']);
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

// Récupérer les niveaux existants pour l'affichage avec le libellé de la filière
$niveaux = [];
try {
    $stmt = $pdo->query("SELECT ne.id_niv_etu, ne.lib_niv_etu, ne.fk_id_filiere, f.lib_filiere 
                         FROM niveau_etude ne
                         LEFT JOIN filiere f ON ne.fk_id_filiere = f.id_filiere
                         ORDER BY f.lib_filiere ASC, ne.lib_niv_etu ASC");
    $niveaux = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de récupération des niveaux: " . $e->getMessage());
}

// Récupérer toutes les filières pour le sélecteur dans le formulaire
$filieres = [];
try {
    $stmt = $pdo->query("SELECT id_filiere, lib_filiere FROM filiere ORDER BY lib_filiere ASC");
    $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de récupération des filières pour le formulaire: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Gestion des Niveaux</title>
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
                    <h1 class="page-title-main">Gestion des Niveaux d'Étude</h1>
                    <p class="page-subtitle">Ajoutez ou supprimez les niveaux d'études disponibles.</p>
                </div>

                <div class="form-card">
                    <h3 class="form-card-title">Ajouter un nouveau Niveau d'Étude</h3>
                    <form id="niveauForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="fk_id_filiere">Filière <span style="color: var(--error-500);">*</span></label>
                                <select id="fk_id_filiere" name="fk_id_filiere" required>
                                    <option value="">Sélectionnez une filière</option>
                                    <?php foreach ($filieres as $filiere): ?>
                                        <option value="<?php echo htmlspecialchars($filiere['id_filiere']); ?>"><?php echo htmlspecialchars($filiere['lib_filiere']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="lib_niveau">Libellé du Niveau <span style="color: var(--error-500);">*</span></label>
                                <input type="text" id="lib_niveau" name="lib_niveau" placeholder="Ex: M2" required>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-plus-circle"></i> <span id="submitText">Ajouter Niveau</span>
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
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher un niveau d'étude...">
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
                        <h3 class="table-title">Liste des Niveaux d'Étude</h3>
                        <div class="table-actions">
                             <div class="filter-dropdown">
                                <button class="filter-button" id="filterButton">
                                    <i class="fas fa-filter"></i> Filtres
                                </button>
                                <div class="filter-dropdown-content" id="filterDropdown">
                                    <div class="filter-option" data-filter="all">
                                        <i class="fas fa-list"></i> Tous les niveaux
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
                                     <div class="filter-option" data-filter="filiere-asc">
                                        <i class="fas fa-graduation-cap"></i> Tri par Filière (A-Z)
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-secondary" id="modifierNiveauBtn" disabled>
                                <i class="fas fa-edit"></i> <span class="action-text">Modifier</span>
                            </button>
                            <button class="btn btn-secondary" id="supprimerNiveauBtn" disabled>
                                <i class="fas fa-trash-alt"></i> <span class="action-text">Supprimer</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="niveauTable">
                            <thead>
                                <tr>
                                    <th style="width: 50px;"></th>
                                    <th>ID</th>
                                    <th>Libellé du Niveau</th>
                                    <th>Filière</th>
                                    <th style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($niveaux)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                        <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucun niveau d'étude trouvé. Ajoutez votre premier niveau ci-dessus.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($niveaux as $niveau): ?>
                                    <tr data-id="<?php echo htmlspecialchars($niveau['id_niv_etu']); ?>" data-filiere-id="<?php echo htmlspecialchars($niveau['fk_id_filiere']); ?>">
                                        <td>
                                            <label class="checkbox-container">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($niveau['id_niv_etu']); ?>">
                                                <span class="checkmark"></span>
                                            </label>
                                        </td>
                                        <td><?php echo htmlspecialchars($niveau['id_niv_etu']); ?></td>
                                        <td><?php echo htmlspecialchars($niveau['lib_niv_etu']); ?></td>
                                        <td><?php echo htmlspecialchars(isset($niveau['lib_filiere']) ? $niveau['lib_filiere'] : 'N/A'); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button edit" title="Modifier" onclick="modifierNiveau('<?php echo htmlspecialchars($niveau['id_niv_etu']); ?>')">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="action-button delete" title="Supprimer" onclick="supprimerNiveauJS('<?php echo htmlspecialchars($niveau['id_niv_etu']); ?>')">
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
        let selectedNiveaux = new Set();
        let editingNiveau = null;
        const { jsPDF } = window.jspdf;

        // Éléments DOM
        const niveauForm = document.getElementById('niveauForm');
        const libNiveauInput = document.getElementById('lib_niveau');
        const fkIdFiliereSelect = document.getElementById('fk_id_filiere'); // Nouveau sélecteur
        const niveauTableBody = document.querySelector('#niveauTable tbody');
        const modifierNiveauBtn = document.getElementById('modifierNiveauBtn');
        const supprimerNiveauBtn = document.getElementById('supprimerNiveauBtn');
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
                const formData = new FormData();  // Use FormData for all POST requests
                for (const key in data) {
                    formData.append(key, data[key]);
                }

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData  
                });
                return await response.json();
            } catch (error) {
                console.error('Erreur AJAX:', error);
                throw error;
            }
        }

        // Fonction pour mettre à jour l'état des boutons
        function updateActionButtons() {
            if (selectedNiveaux.size === 1) {
                modifierNiveauBtn.disabled = false;
                supprimerNiveauBtn.disabled = false;
            } else if (selectedNiveaux.size > 1) {
                modifierNiveauBtn.disabled = true; // Cannot modify multiple at once
                supprimerNiveauBtn.disabled = false;
            } else {
                modifierNiveauBtn.disabled = true;
                supprimerNiveauBtn.disabled = true;
            }
        }

        // Fonction pour ajouter une ligne dans le tableau
        function addRowToTable(niveau) {
            // Supprimer le message "Aucun niveau trouvé" s'il existe
            const emptyRow = niveauTableBody.querySelector('td[colspan="5"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }

            const newRow = niveauTableBody.insertRow();
            newRow.setAttribute('data-id', niveau.id_niv_etu);
            newRow.setAttribute('data-filiere-id', niveau.fk_id_filiere); // Store filiere ID
            newRow.innerHTML = `
                <td>
                    <label class="checkbox-container">
                        <input type="checkbox" value="${niveau.id_niv_etu}">
                        <span class="checkmark"></span>
                    </label>
                </td>
                <td>${niveau.id_niv_etu}</td>
                <td>${niveau.lib_niv_etu}</td>
                <td>${niveau.lib_filiere ?? 'N/A'}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-button edit" title="Modifier" onclick="modifierNiveau('${niveau.id_niv_etu}')">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button class="action-button delete" title="Supprimer" onclick="supprimerNiveauJS('${niveau.id_niv_etu}')">
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
                    selectedNiveaux.add(this.value);
                } else {
                    selectedNiveaux.delete(this.value);
                }
                updateActionButtons();
            });
        }

        // Fonction de recherche
        function searchNiveaux() {
            const searchTerm = searchInput.value.toLowerCase();
            const rows = niveauTableBody.querySelectorAll('tr');
            
            let foundRows = 0;
            rows.forEach(row => {
                // Skip the "no data" row if it's currently displayed
                if (row.querySelector('td[colspan="5"]')) {
                    row.style.display = 'none'; 
                    return;
                }
                
                const libNiveau = row.cells[2].textContent.toLowerCase();
                const filiereName = row.cells[3].textContent.toLowerCase(); // Get filiere name
                const idNiveau = row.cells[1].textContent.toLowerCase();
                
                if (libNiveau.includes(searchTerm) || idNiveau.includes(searchTerm) || filiereName.includes(searchTerm)) {
                    row.style.display = '';
                    foundRows++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Show "no data" message if no results and it's not already there
            const noDataRow = niveauTableBody.querySelector('td[colspan="5"]');
            if (foundRows === 0) {
                if (!noDataRow) {
                     niveauTableBody.innerHTML = `
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                Aucun niveau d'étude trouvé pour cette recherche.
                            </td>
                        </tr>
                    `;
                } else {
                    noDataRow.closest('tr').style.display = '';
                    noDataRow.innerHTML = `
                        <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                        Aucun niveau d'étude trouvé pour cette recherche.
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
            const rows = Array.from(niveauTableBody.querySelectorAll('tr'));
            
            // Remove the "no data" message if it exists
            const emptyRow = niveauTableBody.querySelector('td[colspan="5"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }
            
            // Display all rows before applying the filter
            rows.forEach(row => {
                if (!row.querySelector('td[colspan="5"]')) {
                    row.style.display = '';
                }
            });
            
            // Sort rows based on the filter type
            rows.sort((a, b) => {
                if (a.querySelector('td[colspan="5"]') || b.querySelector('td[colspan="5"]')) return 0;
                
                const idA = parseInt(a.cells[1].textContent);
                const idB = parseInt(b.cells[1].textContent);
                const libA = a.cells[2].textContent.toLowerCase();
                const libB = b.cells[2].textContent.toLowerCase();
                const filiereA = a.cells[3].textContent.toLowerCase();
                const filiereB = b.cells[3].textContent.toLowerCase();
                
                switch (filterType) {
                    case 'id-asc':
                        return idA - idB;
                    case 'id-desc':
                        return idB - idA;
                    case 'name-asc':
                        return libA.localeCompare(libB);
                    case 'name-desc':
                        return libB.localeCompare(libA);
                    case 'filiere-asc':
                        return filiereA.localeCompare(filiereB) || libA.localeCompare(libB); // Sort by filiere, then by name
                    default:
                        return 0;
                }
            });
            
            // Re-append sorted rows to the DOM
            rows.forEach(row => {
                niveauTableBody.appendChild(row);
            });
            
            // If no rows after filtering, display the "no data" message
            if (niveauTableBody.children.length === 0 || (niveauTableBody.children.length === 1 && niveauTableBody.querySelector('td[colspan="5"]'))) {
                niveauTableBody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucun niveau d'étude trouvé. Ajoutez votre premier niveau ci-dessus.
                        </td>
                    </tr>
                `;
            }
        }


        // Soumission du formulaire d'ajout/modification de niveau
        niveauForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = {
                action: editingNiveau ? 'update' : 'create',
                lib_niveau: formData.get('lib_niveau'),
                fk_id_filiere: formData.get('fk_id_filiere') // Get selected filiere ID
            };

            if (editingNiveau) {
                data.id_niveau = editingNiveau;
            }

            submitBtn.classList.add('loading');
            submitBtn.disabled = true;

            try {
                const result = await makeAjaxRequest(data);

                if (result.success) {
                    showAlert(result.message, 'success');
                    if (editingNiveau) {
                        // Update existing row
                        const row = document.querySelector(`tr[data-id="${editingNiveau}"]`);
                        if (row) {
                            row.cells[2].textContent = result.data.lib_niv_etu; // Update level name
                            row.cells[3].textContent = result.data.lib_filiere; // Update filiere name
                            row.setAttribute('data-filiere-id', result.data.fk_id_filiere); // Update filiere ID
                        }
                        resetForm();
                    } else {
                        // Add new row
                        addRowToTable(result.data);
                    }
                    this.reset();
                    applyFilter('filiere-asc'); // Re-sort after adding/updating
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'enregistrement du niveau.', 'error');
            } finally {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }
        });

        // Fonction pour réinitialiser le formulaire
        function resetForm() {
            editingNiveau = null;
            submitText.textContent = 'Ajouter Niveau';
            submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Ajouter Niveau';
            niveauForm.reset();
            selectedNiveaux.clear();
            updateActionButtons();
            // Uncheck all checkboxes
            document.querySelectorAll('#niveauTable tbody input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });
        }

        // Bouton Annuler/Reset
        cancelBtn.addEventListener('click', function() {
            resetForm();
            // Optionally clear search and filters
            searchInput.value = '';
            searchNiveaux(); // Re-apply search to clear results
            applyFilter('all'); // Re-apply default filter
        });

        // Fonction pour modifier un seul niveau
        function modifierNiveau(idNiveau) {
            const row = document.querySelector(`tr[data-id="${idNiveau}"]`);
            if (row) {
                editingNiveau = idNiveau;
                libNiveauInput.value = row.cells[2].textContent; // Column 2 is Libellé du Niveau
                fkIdFiliereSelect.value = row.getAttribute('data-filiere-id'); // Set the filiere dropdown value
                
                submitText.textContent = 'Mettre à jour';
                submitBtn.innerHTML = '<i class="fas fa-edit"></i> Mettre à jour';
                
                // Scroll to the form
                document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
                
                // Uncheck all other checkboxes and select only this one
                document.querySelectorAll('#niveauTable tbody input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
                const currentCheckbox = row.querySelector('input[type="checkbox"]');
                currentCheckbox.checked = true;
                selectedNiveaux.clear();
                selectedNiveaux.add(idNiveau);
                updateActionButtons();
            }
        }

        // Fonction pour supprimer un ou plusieurs niveaux (version JS pour un seul élément aussi)
        function supprimerNiveauJS(idNiveau = null) {
            let idsArray;
            if (idNiveau) {
                idsArray = [idNiveau];
            } else {
                idsArray = Array.from(selectedNiveaux);
            }

            if (idsArray.length === 0) {
                showAlert('Aucun niveau sélectionné pour la suppression.', 'warning');
                return;
            }

            const confirmationMessage = idsArray.length === 1
                ? `Êtes-vous sûr de vouloir supprimer ce niveau (ID: ${idsArray[0]}) ?\n\nCette action est irréversible et pourrait échouer si le niveau est associé à des étudiants.`
                : `Êtes-vous sûr de vouloir supprimer les ${idsArray.length} niveau(x) sélectionné(s) ?\n\nCette action est irréversible et pourrait échouer si des niveaux sont associés à des étudiants.`;

            if (confirm(confirmationMessage)) {
                let successCount = 0;
                let errorMessages = [];

                // Use a loop to send each deletion request individually or send them all at once.
                // For simplicity and better error handling per item, we'll loop for now.
                // If performance is an issue for many items, a single AJAX call with an array of IDs could be used.
                (async () => {
                    for (const id of idsArray) {
                        try {
                            const result = await makeAjaxRequest({
                                action: 'delete',
                                ids_niveaux: JSON.stringify([id]) // Send as array of one ID for current PHP logic
                            });
                            if (result.success) {
                                successCount++;
                                document.querySelector(`tr[data-id="${id}"]`).remove();
                            } else {
                                errorMessages.push(`Niveau ID ${id}: ${result.message}`);
                            }
                        } catch (error) {
                            errorMessages.push(`Niveau ID ${id}: Erreur réseau ou serveur.`);
                        }
                    }

                    selectedNiveaux.clear(); // Efface la sélection après le traitement
                    updateActionButtons();

                    if (successCount > 0) {
                        showAlert(`${successCount} niveau(x) supprimé(s) avec succès !`, 'success');
                    }
                    if (errorMessages.length > 0) {
                        showAlert(`Erreurs lors de la suppression de certains niveaux:\n${errorMessages.join('\n')}`, 'error');
                    }
                    // Si plus de niveaux, afficher le message vide
                    if (niveauTableBody.children.length === 0) {
                        niveauTableBody.innerHTML = `
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                    <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                    Aucun niveau d'étude trouvé. Ajoutez votre premier niveau ci-dessus.
                                </td>
                            </tr>
                        `;
                    }
                })(); // Execute the async IIFE immediately
            }
        }


        // Bouton de suppression de la sélection multiple (qui appellera supprimerNiveauJS sans argument)
        supprimerNiveauBtn.addEventListener('click', function() {
            supprimerNiveauJS();
        });


        // Bouton Modifier global
        modifierNiveauBtn.addEventListener('click', function() {
            if (selectedNiveaux.size === 1) {
                const idNiveau = Array.from(selectedNiveaux)[0];
                modifierNiveau(idNiveau);
            } else {
                showAlert("Veuillez sélectionner exactement un niveau à modifier.", "warning");
            }
        });

        // Fonction pour exporter en PDF
        function exportToPdf() {
            const doc = new jsPDF();
            const title = "Liste des Niveaux d'Étude";
            const date = new Date().toLocaleDateString();
            
            // Titre
            doc.setFontSize(18);
            doc.text(title, 14, 20);
            
            // Date
            doc.setFontSize(10);
            doc.text(`Exporté le: ${date}`, 14, 30);
            
            // Tableau
            const headers = [['ID', 'Libellé du Niveau', 'Filière']];
            const data = [];
            
            document.querySelectorAll('#niveauTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="5"]')) { // Exclude "no data" row
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent
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
            
            doc.save(`niveaux_etude_${new Date().toISOString().split('T')[0]}.pdf`);
            showAlert('Exportation PDF terminée');
        }

        // Fonction pour exporter en Excel
        function exportToExcel() {
            const data = [['ID', 'Libellé du Niveau', 'Filière']];
            
            document.querySelectorAll('#niveauTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="5"]')) { // Exclude "no data" row
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent
                    ]);
                }
            });

            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Niveaux Etude");
            
            XLSX.writeFile(wb, `niveaux_etude_${new Date().toISOString().split('T')[0]}.xlsx`);
            
            showAlert('Exportation Excel terminée');
        }

        // Fonction pour exporter en CSV
        function exportToCsv() {
            let csv = "ID,Libellé du Niveau,Filière\n";
            
            document.querySelectorAll('#niveauTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="5"]')) { // Exclude "no data" row
                    // Escape commas and double quotes in cell content for CSV format
                    const id = `"${row.cells[1].textContent.replace(/"/g, '""')}"`;
                    const lib = `"${row.cells[2].textContent.replace(/"/g, '""')}"`;
                    const filiere = `"${row.cells[3].textContent.replace(/"/g, '""')}"`;
                    csv += `${id},${lib},${filiere}\n`;
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', `niveaux_etude_${new Date().toISOString().split('T')[0]}.csv`);
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
        searchButton.addEventListener('click', searchNiveaux);
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                searchNiveaux();
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
            document.querySelectorAll('#niveauTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="5"]')) { // Éviter le message "Aucune donnée"
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