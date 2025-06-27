<?php
// gestion_ue.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Fonction pour adapter la structure de la base de données si nécessaire
function adapterStructureBD($pdo) {
    try {
        // 1. Vérifier et ajouter la colonne id_Ac à la table ue si elle n'existe pas
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
                
                $stmt = $pdo->prepare("UPDATE ue SET lib_UE = ?, credit_UE = ? WHERE id_UE = ?");
                $stmt->execute([$libelleUE, $creditUE, $idUE]);
                
                echo json_encode(['success' => true, 'message' => 'UE modifiée avec succès']);
                break;
                
            case 'delete':
                $idsUE = json_decode($_POST['ids_ue'], true);
                
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

// Récupérer l'année académique active (statut = 'actif')
$anneeActive = null;
$anneesAcademiques = [];
$ues = [];

if ($structureOK) {
    try {
        // Récupérer l'année académique active
        $stmtAnneeActive = $pdo->prepare("SELECT * FROM année_academique WHERE statut = 'acti' LIMIT 1");
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
            margin-bottom: var(--space-8);
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

        .alert.warning {
            background-color: #fffbeb;
            color: #92400e;
            border: 1px solid #fed7aa;
        }

        /* Loading spinner */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        /* === RESPONSIVE === */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.mobile {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .main-content.sidebar-collapsed {
                margin-left: 0;
            }

            .form-grid {
                grid-template-columns: 1fr;
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
                    <h1 class="page-title-main">Gestion des Unités d'Enseignement (UE)</h1>
                    <p class="page-subtitle">Créez et gérez les unités d'enseignement de la plateforme.</p>
                </div>

                <!-- Message d'alerte -->
                <div id="alertMessage" class="alert"></div>

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
                                <small style="color: var(--success-500); margin-top: var(--space-1); font-weight: 500;">
                                    <i class="fas fa-check-circle"></i> Année académique actuellement active
                                </small>
                            </div>
                            <div class="form-group">
                                <label for="libelleUE">Libellé UE</label>
                                <input type="text" id="libelleUE" name="libelleUE" placeholder="Ex: Introduction à l'Informatique" required <?php echo !$anneeActive ? 'disabled' : ''; ?>>
                                <small style="color: var(--gray-500); margin-top: var(--space-1);">Le code UE sera généré automatiquement</small>
                            </div>
                            <div class="form-group">
                                <label for="creditUE">Crédit UE</label>
                                <input type="number" id="creditUE" name="creditUE" placeholder="Ex: 5" required min="1" max="20" <?php echo !$anneeActive ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn" <?php echo !$anneeActive ? 'disabled' : ''; ?>>
                                <i class="fas fa-save"></i> <span id="submitText">Enregistrer</span>
                            </button>
                            <button type="reset" class="btn btn-secondary" id="cancelBtn">
                                <i class="fas fa-redo"></i> Annuler
                            </button>
                        </div>
                    </form>
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
                            <button class="btn btn-secondary" id="modifierBtn" disabled>
                                <i class="fas fa-edit"></i> Modifier
                            </button>
                            <button class="btn btn-secondary" id="supprimerBtn" disabled>
                                <i class="fas fa-trash-alt"></i> Supprimer
                            </button>
                            <button class="btn btn-secondary" id="exporterBtn">
                                <i class="fas fa-file-export"></i> Exporter
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
                                        <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
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

    <script>
        // Variables globales
        let selectedUEs = new Set();
        let editingUE = null;
        const anneeActive = <?php echo $anneeActive ? json_encode($anneeActive) : 'null'; ?>;

        // Éléments DOM
        const ueForm = document.getElementById('ueForm');
        const libelleUEInput = document.getElementById('libelleUE');
        const creditUEInput = document.getElementById('creditUE');
        const ueTableBody = document.querySelector('#ueTable tbody');
        const modifierBtn = document.getElementById('modifierBtn');
        const supprimerBtn = document.getElementById('supprimerBtn');
        const exporterBtn = document.getElementById('exporterBtn');
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const cancelBtn = document.getElementById('cancelBtn');
        const alertMessage = document.getElementById('alertMessage');

        // Gestion du toggle sidebar
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
            });
        }

        // Fonction pour afficher les messages
        function showAlert(message, type = 'success') {
            alertMessage.textContent = message;
            alertMessage.className = `alert ${type}`;
            alertMessage.style.display = 'block';
            setTimeout(() => {
                alertMessage.style.display = 'none';
            }, 5000);
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
            submitText.textContent = 'Enregistrer';
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Enregistrer';
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
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer l'UE "${libelleUE}" (${idUE}) ?`)) {
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
                                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
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
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer ${idsArray.length} UE(s) sélectionnée(s) ?`)) {
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
                                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
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

        // Bouton Exporter
        exporterBtn.addEventListener('click', function() {
            if (!anneeActive) {
                showAlert('Aucune année académique active pour l\'exportation', 'error');
                return;
            }

            // Vérifier s'il y a des UE à exporter
            const rows = document.querySelectorAll('#ueTable tbody tr');
            if (rows.length === 1 && rows[0].querySelector('td[colspan="5"]')) {
                showAlert('Aucune UE à exporter', 'warning');
                return;
            }

            // Créer les données CSV
            const csvRows = [['Code UE', 'Libellé UE', 'Crédits UE']];
            
            document.querySelectorAll('#ueTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="5"]')) {
                    csvRows.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent
                    ]);
                }
            });

            // Créer le contenu CSV
            const csvContent = csvRows.map(row => row.join(',')).join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            
            // Extraire l'année pour le nom du fichier
            const anneeText = anneeActive ? anneeActive.date_deb.substring(0, 4) + '_' + anneeActive.date_fin.substring(0, 4) : 'export';
            
            // Télécharger le fichier
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `ues_${anneeText}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showAlert('Exportation terminée');
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
        });

        // Responsive: Gestion mobile
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
        }

        window.addEventListener('resize', handleResize);
        handleResize();
    </script>
</body>
</html>