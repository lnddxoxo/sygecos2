<?php
// gestion_ue.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Fonction pour adapter la structure de la base de données si nécessaire
function adapterStructureBD($pdo) {
    try {
        // 1. Vérifier et modifier la structure de la table ue si nécessaire
        $stmt = $pdo->query("SHOW COLUMNS FROM ue LIKE 'id_UE'");
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($column && strpos($column['Type'], 'int') !== false) {
            // Modifier la colonne id_UE pour accepter des chaînes
            $pdo->exec("ALTER TABLE ue MODIFY COLUMN id_UE VARCHAR(20) NOT NULL");
        }
        
        // 2. Vérifier et ajouter la colonne id_Ac à la table ue si elle n'existe pas
        $stmt = $pdo->query("SHOW COLUMNS FROM ue LIKE 'id_Ac'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE ue ADD COLUMN id_Ac INT AFTER credit_UE");
            
            // Ajouter la contrainte foreign key
            $pdo->exec("ALTER TABLE ue ADD CONSTRAINT fk_ue_annee_academique FOREIGN KEY (id_Ac) REFERENCES année_academique(id_Ac) ON DELETE CASCADE ON UPDATE CASCADE");
        }

        return true;
    } catch (Exception $e) {
        error_log("Erreur lors de l'adaptation de la structure BD: " . $e->getMessage());
        return false;
    }
}

// Adapter la structure de la base de données si nécessaire
$structureOK = adapterStructureBD($pdo);

// Fonction pour générer le code UE automatiquement
function genererCodeUE($libelleUE) {
    $mots = explode(' ', trim($libelleUE));
    $code = '';
    
    foreach ($mots as $mot) {
        // Nettoyer le mot (enlever les apostrophes, etc.)
        $mot = preg_replace('/[^a-zA-ZÀ-ÿ]/', '', $mot);
        if (strlen($mot) >= 3) {
            $code .= strtoupper(substr($mot, 0, 3));
        } elseif (strlen($mot) > 0) {
            $code .= strtoupper($mot);
        }
    }
    
    return empty($code) ? 'UE' : $code;
}

// Traitement AJAX pour les opérations CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create':
                $idAc = $_POST['id_Ac'];
                $libelleUE = trim($_POST['libelleUE']);
                $creditUE = intval($_POST['creditUE']);
                
                // Validation
                if (empty($libelleUE)) {
                    throw new Exception("Le libellé de l'UE est obligatoire");
                }
                
                if ($creditUE < 1 || $creditUE > 20) {
                    throw new Exception("Le nombre de crédits doit être entre 1 et 20");
                }
                
                // Générer le code UE automatiquement
                $codeUE = genererCodeUE($libelleUE);
                
                // Vérifier si le code UE existe déjà pour cette année académique
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM ue WHERE id_UE = ? AND id_Ac = ?");
                $checkStmt->execute([$codeUE, $idAc]);
                
                if ($checkStmt->fetchColumn() > 0) {
                    // Si le code existe, ajouter un suffixe numérique
                    $counter = 1;
                    $originalCode = $codeUE;
                    do {
                        $codeUE = $originalCode . sprintf('%02d', $counter);
                        $checkStmt->execute([$codeUE, $idAc]);
                        $counter++;
                    } while ($checkStmt->fetchColumn() > 0);
                }
                
                // Insérer la nouvelle UE (id_UE correspond au code_ue)
                $stmt = $pdo->prepare("INSERT INTO ue (id_UE, lib_UE, credit_UE, id_Ac) VALUES (?, ?, ?, ?)");
                $stmt->execute([$codeUE, $libelleUE, $creditUE, $idAc]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'UE créée avec succès',
                    'data' => [
                        'id_UE' => $codeUE,
                        'lib_UE' => $libelleUE,
                        'credit_UE' => $creditUE
                    ]
                ]);
                break;
                
            case 'update':
                $idUE = $_POST['id_UE'];
                $libelleUE = trim($_POST['libelleUE']);
                $creditUE = intval($_POST['creditUE']);
                
                // Validation
                if (empty($libelleUE)) {
                    throw new Exception("Le libellé de l'UE est obligatoire");
                }
                
                if ($creditUE < 1 || $creditUE > 20) {
                    throw new Exception("Le nombre de crédits doit être entre 1 et 20");
                }
                
                $stmt = $pdo->prepare("UPDATE ue SET lib_UE = ?, credit_UE = ? WHERE id_UE = ?");
                $stmt->execute([$libelleUE, $creditUE, $idUE]);
                
                echo json_encode(['success' => true, 'message' => 'UE modifiée avec succès']);
                break;
                
            case 'delete':
                $idsUE = json_decode($_POST['ids_ue'], true);
                
                if (empty($idsUE)) {
                    throw new Exception("Aucune UE sélectionnée pour la suppression");
                }
                
                // Vérifier si les UE sont utilisées dans d'autres tables (ECUE par exemple)
                foreach ($idsUE as $idUE) {
                    $checkUsage = $pdo->prepare("SELECT COUNT(*) FROM ecue WHERE id_UE = ?");
                    $checkUsage->execute([$idUE]);
                    if ($checkUsage->fetchColumn() > 0) {
                        throw new Exception("Impossible de supprimer l'UE '$idUE' car elle contient des ECUE");
                    }
                }
                
                $placeholders = str_repeat('?,', count($idsUE) - 1) . '?';
                $stmt = $pdo->prepare("DELETE FROM ue WHERE id_UE IN ($placeholders)");
                $stmt->execute($idsUE);
                
                echo json_encode(['success' => true, 'message' => 'UE(s) supprimée(s) avec succès']);
                break;
                
            case 'get_ues':
                $idAc = $_POST['id_Ac'];
                
                $stmt = $pdo->prepare("SELECT * FROM ue WHERE id_Ac = ? ORDER BY id_UE");
                $stmt->execute([$idAc]);
                $ues = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $ues]);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Récupérer l'année académique active (statut = 'active')
$anneeActive = null;
$anneesAcademiques = [];
$ues = [];

if ($structureOK) {
    try {
        // Récupérer l'année académique active (CORRIGÉ: 'active' au lieu de 'acti')
        $stmtAnneeActive = $pdo->prepare("SELECT * FROM année_academique WHERE statut = 'active' LIMIT 1");
        $stmtAnneeActive->execute();
        $anneeActive = $stmtAnneeActive->fetch(PDO::FETCH_ASSOC);

        // Récupérer toutes les années académiques pour référence
        $stmtAnnees = $pdo->prepare("SELECT * FROM année_academique ORDER BY date_deb DESC");
        $stmtAnnees->execute();
        $anneesAcademiques = $stmtAnnees->fetchAll(PDO::FETCH_ASSOC);

        // Récupérer les UE de l'année active
        if ($anneeActive) {
            $stmtUEs = $pdo->prepare("SELECT * FROM ue WHERE id_Ac = ? ORDER BY id_UE");
            $stmtUEs->execute([$anneeActive['id_Ac']]);
            $ues = $stmtUEs->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Erreur lors de la récupération des données: " . $e->getMessage());
    }
}

// Fonction pour formater l'affichage de l'année académique
function formatAnneeAcademique($dateDeb, $dateFin) {
    $anneeDebut = date('Y', strtotime($dateDeb));
    $anneeFin = date('Y', strtotime($dateFin));
    return $anneeDebut . '-' . $anneeFin;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Gestion des UE</title>
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

        /* === PAGE SPECIFIC STYLES (Gestion UE) === */
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
            margin-bottom: var(--space-6);
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

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group select {
            padding: var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            color: var(--gray-800);
            transition: all var(--transition-fast);
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="number"]:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .form-group select {
            background-color: white;
            cursor: pointer;
        }

        .form-group input:disabled,
        .form-group select:disabled {
            background-color: var(--gray-100);
            color: var(--gray-500);
            cursor: not-allowed;
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

        /* Badge pour année académique active */
        .annee-badge {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-1) var(--space-2);
            background-color: var(--success-500);
            color: white;
            border-radius: var(--radius-md);
            font-size: var(--text-xs);
            font-weight: 600;
            margin-left: var(--space-2);
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
            min-width: 250px;
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
        
        <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>

        <main class="main-content" id="mainContent">
            <div class="topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle" id="sidebarToggle">
                        <i class="fas fa-bars"></i>
                        <i class="fas fa-times" style="display: none;"></i>
                    </button>
                    <h2 class="page-title">Gestion des UE</h2>
                </div>
                <div class="topbar-right">
                    <div class="user-menu">
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($_SESSION['user_role'] ?? 'Administrateur'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Gestion des Unités d'Enseignement (UE)</h1>
                    <p class="page-subtitle">Créez et gérez les unités d'enseignement de la plateforme.</p>
                </div>

                <?php if (!$structureOK): ?>
                <div class="alert error" style="display: block;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Erreur lors de l'initialisation de la base de données. Veuillez vérifier les permissions.
                </div>
                <?php elseif (!$anneeActive): ?>
                <div class="alert warning" style="display: block;">
                    <i class="fas fa-info-circle"></i>
                    Aucune année académique active trouvée. Veuillez d'abord activer une année académique dans le module de gestion des années académiques.
                </div>
                <?php endif; ?>

                <div class="form-card">
                    <h3 class="form-card-title">Créer une nouvelle UE</h3>
                    <form id="ueForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="anneeAcademique">Année Académique</label>
                                <input type="text" id="anneeAcademique" readonly style="background-color: var(--gray-50); border: 2px solid var(--success-500);" value="<?php 
                                    if ($anneeActive) {
                                        echo htmlspecialchars(formatAnneeAcademique($anneeActive['date_deb'], $anneeActive['date_fin']) . ' (Active)');
                                    } else {
                                        echo 'Aucune année active';
                                    }
                                ?>">
                                <input type="hidden" name="id_Ac" value="<?php echo $anneeActive['id_Ac'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="libelleUE">Libellé UE <span style="color: var(--error-500);">*</span></label>
                                <input type="text" id="libelleUE" name="libelleUE" placeholder="Ex: Introduction à l'Informatique" required <?php echo !$anneeActive ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="creditUE">Crédit UE <span style="color: var(--error-500);">*</span></label>
                                <input type="number" id="creditUE" name="creditUE" placeholder="Ex: 5" required min="1" max="20" <?php echo !$anneeActive ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn" <?php echo !$anneeActive ? 'disabled' : ''; ?>>
                                <i class="fas fa-plus"></i> <span id="submitText">Ajouter UE</span>
                            </button>
                            <button type="reset" class="btn btn-secondary" id="cancelBtn">
                                <i class="fas fa-redo"></i> Annuler
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Barre de recherche -->
                <div class="search-bar">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher une UE par code ou libellé...">
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
                        <h3 class="table-title">
                            Liste des Unités d'Enseignement
                            <?php if ($anneeActive): ?>
                                <span class="annee-badge">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo htmlspecialchars(formatAnneeAcademique($anneeActive['date_deb'], $anneeActive['date_fin'])); ?>
                                </span>
                            <?php endif; ?>
                        </h3>
                        <div class="table-actions">
                            <div class="filter-dropdown">
                                <button class="filter-button" id="filterButton">
                                    <i class="fas fa-filter"></i> Filtres
                                </button>
                                <div class="filter-dropdown-content" id="filterDropdown">
                                    <div class="filter-option" data-filter="all">
                                        <i class="fas fa-list"></i> Toutes les UE
                                    </div>
                                    <div class="filter-option" data-filter="code-asc">
                                        <i class="fas fa-sort-alpha-down"></i> Tri par code (A-Z)
                                    </div>
                                    <div class="filter-option" data-filter="code-desc">
                                        <i class="fas fa-sort-alpha-up"></i> Tri par code (Z-A)
                                    </div>
                                    <div class="filter-option" data-filter="libelle-asc">
                                        <i class="fas fa-sort-alpha-down"></i> Tri par libellé (A-Z)
                                    </div>
                                    <div class="filter-option" data-filter="libelle-desc">
                                        <i class="fas fa-sort-alpha-up"></i> Tri par libellé (Z-A)
                                    </div>
                                    <div class="filter-option" data-filter="credit-asc">
                                        <i class="fas fa-sort-numeric-down"></i> Tri par crédits (croissant)
                                    </div>
                                    <div class="filter-option" data-filter="credit-desc">
                                        <i class="fas fa-sort-numeric-up"></i> Tri par crédits (décroissant)
                                    </div>
                                    <div class="filter-option" data-filter="credit-low">
                                        <i class="fas fa-filter"></i> UE faibles crédits (≤ 3)
                                    </div>
                                    <div class="filter-option" data-filter="credit-medium">
                                        <i class="fas fa-filter"></i> UE moyens crédits (4-6)
                                    </div>
                                    <div class="filter-option" data-filter="credit-high">
                                        <i class="fas fa-filter"></i> UE hauts crédits (≥ 7)
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-secondary" id="modifierBtn" disabled>
                                <i class="fas fa-edit"></i> <span class="action-text">Modifier</span>
                            </button>
                            <button class="btn btn-secondary" id="supprimerBtn" disabled>
                                <i class="fas fa-trash-alt"></i> <span class="action-text">Supprimer</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="ueTable">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Code UE</th>
                                    <th>Libellé UE</th>
                                    <th>Crédits UE</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($ues)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                        <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucune UE trouvée pour cette année académique. Créez votre première UE en utilisant le formulaire ci-dessus.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($ues as $ue): ?>
                                    <tr data-id="<?php echo htmlspecialchars($ue['id_UE']); ?>">
                                        <td>
                                            <label class="checkbox-container">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($ue['id_UE']); ?>">
                                                <span class="checkmark"></span>
                                            </label>
                                        </td>
                                        <td><?php echo htmlspecialchars($ue['id_UE']); ?></td>
                                        <td><?php echo htmlspecialchars($ue['lib_UE']); ?></td>
                                        <td><?php echo htmlspecialchars($ue['credit_UE']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button edit" title="Modifier" onclick="modifierUE('<?php echo htmlspecialchars($ue['id_UE']); ?>')">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="action-button delete" title="Supprimer" onclick="supprimerUE('<?php echo htmlspecialchars($ue['id_UE']); ?>')">
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

    <!-- Modal pour les messages -->
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
        let selectedUEs = new Set();
        let editingUE = null;
        const anneeActive = <?php echo $anneeActive ? json_encode($anneeActive) : 'null'; ?>;
        const { jsPDF } = window.jspdf;

        // Éléments DOM
        const ueForm = document.getElementById('ueForm');
        const libelleUEInput = document.getElementById('libelleUE');
        const creditUEInput = document.getElementById('creditUE');
        const ueTableBody = document.querySelector('#ueTable tbody');
        const modifierBtn = document.getElementById('modifierBtn');
        const supprimerBtn = document.getElementById('supprimerBtn');
        const exportPdfBtn = document.getElementById('exportPdfBtn');
        const exportExcelBtn = document.getElementById('exportExcelBtn');
        const exportCsvBtn = document.getElementById('exportCsvBtn');
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
            if (selectedUEs.size === 1) {
                modifierBtn.disabled = false;
                supprimerBtn.disabled = false;
            } else if (selectedUEs.size > 1) {
                modifierBtn.disabled = true;
                supprimerBtn.disabled = false;
            } else {
                modifierBtn.disabled = true;
                supprimerBtn.disabled = true;
            }
        }

        // Fonction pour ajouter une ligne dans le tableau
        function addRowToTable(ue) {
            // Supprimer le message "Aucune UE trouvée" s'il existe
            const emptyRow = ueTableBody.querySelector('td[colspan="5"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }

            const newRow = ueTableBody.insertRow();
            newRow.setAttribute('data-id', ue.id_UE);
            newRow.innerHTML = `
                <td>
                    <label class="checkbox-container">
                        <input type="checkbox" value="${ue.id_UE}">
                        <span class="checkmark"></span>
                    </label>
                </td>
                <td>${ue.id_UE}</td>
                <td>${ue.lib_UE}</td>
                <td>${ue.credit_UE}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-button edit" title="Modifier" onclick="modifierUE('${ue.id_UE}')">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button class="action-button delete" title="Supprimer" onclick="supprimerUE('${ue.id_UE}')">
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
                    selectedUEs.add(this.value);
                } else {
                    selectedUEs.delete(this.value);
                }
                updateActionButtons();
            });
        }

        // Fonction de recherche
        function searchUEs() {
            const searchTerm = searchInput.value.toLowerCase();
            const rows = ueTableBody.querySelectorAll('tr');
            
            rows.forEach(row => {
                if (row.querySelector('td[colspan="5"]')) return; // Ignorer le message vide
                
                const codeUE = row.cells[1].textContent.toLowerCase();
                const libelleUE = row.cells[2].textContent.toLowerCase();
                const creditUE = row.cells[3].textContent.toLowerCase();
                
                if (codeUE.includes(searchTerm) || 
                    libelleUE.includes(searchTerm) || 
                    creditUE.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Fonction pour appliquer les filtres
        function applyFilter(filterType) {
            const rows = Array.from(ueTableBody.querySelectorAll('tr'));
            
            // Supprimer le message "Aucune UE trouvée" s'il existe
            const emptyRow = ueTableBody.querySelector('td[colspan="5"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }
            
            // Afficher toutes les lignes avant d'appliquer le filtre
            rows.forEach(row => {
                if (!row.querySelector('td[colspan="5"]')) {
                    row.style.display = '';
                }
            });
            
            // Filtrer par crédits d'abord
            if (filterType.includes('credit-')) {
                rows.forEach(row => {
                    if (!row.querySelector('td[colspan="5"]')) {
                        const credit = parseInt(row.cells[3].textContent);
                        let shouldShow = true;
                        
                        switch (filterType) {
                            case 'credit-low':
                                shouldShow = credit <= 3;
                                break;
                            case 'credit-medium':
                                shouldShow = credit >= 4 && credit <= 6;
                                break;
                            case 'credit-high':
                                shouldShow = credit >= 7;
                                break;
                        }
                        
                        row.style.display = shouldShow ? '' : 'none';
                    }
                });
                return;
            }
            
            // Trier les lignes selon le filtre
            const visibleRows = rows.filter(row => !row.querySelector('td[colspan="5"]'));
            visibleRows.sort((a, b) => {
                const codeA = a.cells[1].textContent.toLowerCase();
                const codeB = b.cells[1].textContent.toLowerCase();
                const libelleA = a.cells[2].textContent.toLowerCase();
                const libelleB = b.cells[2].textContent.toLowerCase();
                const creditA = parseInt(a.cells[3].textContent);
                const creditB = parseInt(b.cells[3].textContent);
                
                switch (filterType) {
                    case 'code-asc':
                        return codeA.localeCompare(codeB);
                    case 'code-desc':
                        return codeB.localeCompare(codeA);
                    case 'libelle-asc':
                        return libelleA.localeCompare(libelleB);
                    case 'libelle-desc':
                        return libelleB.localeCompare(libelleA);
                    case 'credit-asc':
                        return creditA - creditB;
                    case 'credit-desc':
                        return creditB - creditA;
                    default:
                        return 0;
                }
            });
            
            // Réorganiser les lignes dans le DOM
            visibleRows.forEach(row => {
                ueTableBody.appendChild(row);
            });
            
            // Si aucune ligne après filtrage, afficher le message
            const visibleRowsAfterFilter = visibleRows.filter(row => row.style.display !== 'none');
            if (visibleRowsAfterFilter.length === 0) {
                ueTableBody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucune UE trouvée correspondant aux critères de recherche.
                        </td>
                    </tr>
                `;
            }
        }

        // Soumission du formulaire
        ueForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            if (!anneeActive) {
                showAlert('Aucune année académique active. Impossible de créer une UE.', 'error');
                return;
            }

            const formData = new FormData(this);
            const data = {
                action: editingUE ? 'update' : 'create',
                id_Ac: formData.get('id_Ac'),
                libelleUE: formData.get('libelleUE'),
                creditUE: formData.get('creditUE')
            };

            if (editingUE) {
                data.id_UE = editingUE;
            }

            try {
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;

                const result = await makeAjaxRequest(data);

                if (result.success) {
                    if (editingUE) {
                        // Mettre à jour la ligne existante
                        const row = document.querySelector(`tr[data-id="${editingUE}"]`);
                        if (row) {
                            row.cells[2].textContent = data.libelleUE;
                            row.cells[3].textContent = data.creditUE;
                        }
                        showAlert('UE modifiée avec succès');
                        resetForm();
                    } else {
                        // Ajouter une nouvelle ligne
                        addRowToTable(result.data);
                        showAlert(`UE "${data.libelleUE}" (${result.data.id_UE}) créée avec succès`);
                    }
                    this.reset();
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
            editingUE = null;
            submitText.textContent = 'Ajouter UE';
            submitBtn.innerHTML = '<i class="fas fa-plus"></i> Ajouter UE';
            ueForm.reset();
            // Remettre la valeur de l'année académique
            document.querySelector('input[name="id_Ac"]').value = anneeActive ? anneeActive.id_Ac : '';
        }

        // Bouton Annuler
        cancelBtn.addEventListener('click', function() {
            resetForm();
        });

        // Fonction pour modifier une UE
        function modifierUE(idUE) {
            const row = document.querySelector(`tr[data-id="${idUE}"]`);
            if (row) {
                editingUE = idUE;
                libelleUEInput.value = row.cells[2].textContent;
                creditUEInput.value = row.cells[3].textContent;
                
                submitText.textContent = 'Mettre à jour';
                submitBtn.innerHTML = '<i class="fas fa-edit"></i> Mettre à jour';
                
                // Faire défiler vers le formulaire
                document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Fonction pour supprimer une UE
        async function supprimerUE(idUE) {
            const row = document.querySelector(`tr[data-id="${idUE}"]`);
            if (row) {
                const libelleUE = row.cells[2].textContent;
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer l'UE "${libelleUE}" (${idUE}) ?\n\nCette action ne peut être annulée.`)) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'delete',
                            ids_ue: JSON.stringify([idUE])
                        });

                        if (result.success) {
                            row.remove();
                            selectedUEs.delete(idUE);
                            updateActionButtons();
                            showAlert('UE supprimée avec succès');
                            
                            // Si plus d'UE, afficher le message vide
                            if (ueTableBody.children.length === 0) {
                                ueTableBody.innerHTML = `
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                            <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                            Aucune UE trouvée pour cette année académique. Créez votre première UE en utilisant le formulaire ci-dessus.
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
        modifierBtn.addEventListener('click', function() {
            if (selectedUEs.size === 1) {
                const idUE = Array.from(selectedUEs)[0];
                modifierUE(idUE);
            }
        });

        // Bouton Supprimer global
        supprimerBtn.addEventListener('click', async function() {
            if (selectedUEs.size > 0) {
                const idsArray = Array.from(selectedUEs);
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer ${idsArray.length} UE(s) sélectionnée(s) ?\n\nCette action ne peut être annulée.`)) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'delete',
                            ids_ue: JSON.stringify(idsArray)
                        });

                        if (result.success) {
                            idsArray.forEach(id => {
                                const row = document.querySelector(`tr[data-id="${id}"]`);
                                if (row) row.remove();
                            });
                            selectedUEs.clear();
                            updateActionButtons();
                            showAlert('UE(s) supprimée(s) avec succès');
                            
                            // Si plus d'UE, afficher le message vide
                            if (ueTableBody.children.length === 0) {
                                ueTableBody.innerHTML = `
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                            <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                            Aucune UE trouvée pour cette année académique. Créez votre première UE en utilisant le formulaire ci-dessus.
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
        });

        // Fonction pour exporter en PDF
        function exportToPdf() {
            if (!anneeActive) {
                showAlert('Aucune année académique active pour l\'exportation', 'error');
                return;
            }

            const doc = new jsPDF();
            const title = "Liste des Unités d'Enseignement (UE)";
            const anneeText = `Année académique: ${anneeActive.date_deb.substring(0, 4)}-${anneeActive.date_fin.substring(0, 4)}`;
            const date = new Date().toLocaleDateString();
            
            // Titre
            doc.setFontSize(18);
            doc.text(title, 14, 20);
            
            // Année académique
            doc.setFontSize(12);
            doc.text(anneeText, 14, 30);
            
            // Date
            doc.setFontSize(10);
            doc.text(`Exporté le: ${date}`, 14, 40);
            
            // Tableau
            const headers = [['Code UE', 'Libellé UE', 'Crédits']];
            const data = [];
            
            document.querySelectorAll('#ueTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="5"]') && row.style.display !== 'none') {
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent
                    ]);
                }
            });
            
            if (data.length === 0) {
                showAlert('Aucune UE à exporter', 'warning');
                return;
            }
            
            doc.autoTable({
                head: headers,
                body: data,
                startY: 50,
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
            
            const filename = `ues_${anneeActive.date_deb.substring(0, 4)}_${anneeActive.date_fin.substring(0, 4)}.pdf`;
            doc.save(filename);
            showAlert('Exportation PDF terminée');
        }

        // Fonction pour exporter en Excel
        function exportToExcel() {
            if (!anneeActive) {
                showAlert('Aucune année académique active pour l\'exportation', 'error');
                return;
            }

            // Créer les données pour Excel
            const data = [['Code UE', 'Libellé UE', 'Crédits']];
            
            document.querySelectorAll('#ueTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="5"]') && row.style.display !== 'none') {
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        parseInt(row.cells[3].textContent)
                    ]);
                }
            });

            if (data.length === 1) {
                showAlert('Aucune UE à exporter', 'warning');
                return;
            }

            // Créer le fichier Excel
            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "UE");
            
            // Télécharger le fichier
            const filename = `ues_${anneeActive.date_deb.substring(0, 4)}_${anneeActive.date_fin.substring(0, 4)}.xlsx`;
            XLSX.writeFile(wb, filename);
            
            showAlert('Exportation Excel terminée');
        }

        // Fonction pour exporter en CSV
        function exportToCsv() {
            if (!anneeActive) {
                showAlert('Aucune année académique active pour l\'exportation', 'error');
                return;
            }

            let csv = "Code UE,Libellé UE,Crédits\n";
            let hasData = false;
            
            document.querySelectorAll('#ueTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="5"]') && row.style.display !== 'none') {
                    csv += `"${row.cells[1].textContent}","${row.cells[2].textContent}","${row.cells[3].textContent}"\n`;
                    hasData = true;
                }
            });
            
            if (!hasData) {
                showAlert('Aucune UE à exporter', 'warning');
                return;
            }
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            const filename = `ues_${anneeActive.date_deb.substring(0, 4)}_${anneeActive.date_fin.substring(0, 4)}.csv`;
            link.setAttribute('download', filename);
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
        searchButton.addEventListener('click', searchUEs);
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                searchUEs();
            } else {
                // Recherche en temps réel
                searchUEs();
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

        // Fermer le dropdown si on clique ailleurs
        window.addEventListener('click', function(e) {
            if (!e.target.matches('.filter-button') && !e.target.closest('.filter-dropdown')) {
                filterDropdown.classList.remove('show');
            }
        });

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Attacher les événements aux lignes existantes
            document.querySelectorAll('#ueTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="5"]')) {
                    attachEventListenersToRow(row);
                }
            });
            
            updateActionButtons();
            
            // Masquer les textes des boutons d'action sur mobile
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
            
            handleResponsiveActions();
            window.addEventListener('resize', handleResponsiveActions);
        });

        // Gestion du redimensionnement de la fenêtre
        function handleResize() {
            // Sur les grands écrans, s'assurer que la sidebar est visible
            if (window.innerWidth >= 1024) {
                sidebar.classList.remove('mobile-open');
                mobileMenuOverlay.classList.remove('active');
            }
        }

        window.addEventListener('resize', handleResize);
    </script>
</body>
</html>