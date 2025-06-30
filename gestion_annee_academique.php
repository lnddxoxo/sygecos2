<?php
// gestion_annee_academique.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Traitement des actions AJAX
if (isset($_POST['action']) || isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        global $pdo;
        if (!isset($pdo)) {
            $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=sygecos;charset=utf8mb4", "root", "");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        $action = $_POST['action'] ?? $_GET['action'];
        
        switch ($action) {
            case 'create':
                handleCreateAjax($pdo);
                break;
            case 'delete':
                handleDeleteAjax($pdo);
                break;
            case 'activate':
                handleActivateAjax($pdo);
                break;
            case 'fetch_all': // Nouvelle action pour la récupération des données via AJAX (pour recherche/filtrage)
                handleFetchAllAjax($pdo);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Action non valide']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Chargement initial des données
try {
    global $pdo;
    if (!isset($pdo)) {
        $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=sygecos;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    
    // Vérifier si la colonne statut existe
    $stmt = $pdo->query("DESCRIBE année_academique");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $hasStatut = in_array('statut', $columns);
    
    if ($hasStatut) {
        $stmt = $pdo->query("SELECT id_Ac as id_annee, YEAR(date_deb) as annee_debut, YEAR(date_fin) as annee_fin, statut FROM année_academique ORDER BY date_deb DESC");
    } else {
        // Si la colonne statut n'existe pas, l'ajouter et définir un statut par défaut
        $pdo->exec("ALTER TABLE année_academique ADD COLUMN statut ENUM('active', 'preparation', 'archivee') DEFAULT 'preparation'");
        $stmt = $pdo->query("SELECT id_Ac as id_annee, YEAR(date_deb) as annee_debut, YEAR(date_fin) as annee_fin, 'preparation' as statut FROM année_academique ORDER BY date_deb DESC");
    }
    $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $years = [];
    $error_message = "Erreur de chargement: " . $e->getMessage();
}

function handleCreateAjax($pdo) {
    try {
        $anneeDebut = intval($_POST['anneeDebut'] ?? 0);
        $anneeFin = intval($_POST['anneeFin'] ?? 0);
        
        if (empty($anneeDebut) || empty($anneeFin)) {
            echo json_encode(['success' => false, 'message' => 'Veuillez remplir tous les champs']);
            return;
        }
        
        if ($anneeDebut < 2025 || $anneeFin != ($anneeDebut + 1)) {
            echo json_encode(['success' => false, 'message' => 'Années invalides']);
            return;
        }
        
        $finShort = substr($anneeFin, -2);
        $debutShort = substr($anneeDebut, -2);
        $idAnnee = "2{$finShort}{$debutShort}";
        
        $stmt = $pdo->prepare("SELECT id_Ac FROM année_academique WHERE id_Ac = ? OR (YEAR(date_deb) = ? AND YEAR(date_fin) = ?)");
        $stmt->execute([$idAnnee, $anneeDebut, $anneeFin]);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Cette année académique existe déjà']);
            return;
        }
        
        $dateDebut = $anneeDebut . '-09-01';
        $dateFin = $anneeFin . '-08-31';
        
        // Vérifier si la colonne statut existe
        $stmt = $pdo->query("DESCRIBE année_academique");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $hasStatut = in_array('statut', $columns);
        
        if ($hasStatut) {
            $stmt = $pdo->prepare("INSERT INTO année_academique (id_Ac, date_deb, date_fin, statut) VALUES (?, ?, ?, 'preparation')");
        } else {
            $stmt = $pdo->prepare("INSERT INTO année_academique (id_Ac, date_deb, date_fin) VALUES (?, ?, ?)");
        }
        
        $result = $stmt->execute([$idAnnee, $dateDebut, $dateFin]);
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'enregistrement']);
            return;
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Année académique {$anneeDebut}-{$anneeFin} créée avec succès (ID: {$idAnnee})",
            'data' => [ // Retourner les données de la nouvelle année pour mise à jour côté client
                'id_annee' => $idAnnee,
                'annee_debut' => $anneeDebut,
                'annee_fin' => $anneeFin,
                'statut' => 'preparation'
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
}

function handleActivateAjax($pdo) {
    try {
        $idAnnee = $_POST['id_annee'] ?? '';
        
        if (empty($idAnnee)) {
            echo json_encode(['success' => false, 'message' => 'ID année manquant']);
            return;
        }
        
        // Vérifier si la colonne statut existe, sinon l'ajouter
        $stmt = $pdo->query("DESCRIBE année_academique");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $hasStatut = in_array('statut', $columns);
        
        if (!$hasStatut) {
            $pdo->exec("ALTER TABLE année_academique ADD COLUMN statut ENUM('active', 'preparation', 'archivee') DEFAULT 'preparation'");
        }
        
        // Vérifier que l'année existe
        $stmt = $pdo->prepare("SELECT id_Ac FROM année_academique WHERE id_Ac = ?");
        $stmt->execute([$idAnnee]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Année académique introuvable']);
            return;
        }
        
        // Transaction pour activer
        $pdo->beginTransaction();
        
        try {
            // Désactiver toutes les autres années (les mettre en "archivee")
            $pdo->exec("UPDATE année_academique SET statut = 'archivee' WHERE statut = 'active'");
            
            // Activer la nouvelle année
            $stmt = $pdo->prepare("UPDATE année_academique SET statut = 'active' WHERE id_Ac = ?");
            $stmt->execute([$idAnnee]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Année {$idAnnee} activée avec succès"
            ]);
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'activation: ' . $e->getMessage()]);
    }
}

function handleDeleteAjax($pdo) {
    try {
        $idsJson = $_POST['ids_annee'] ?? '';
        $ids = json_decode($idsJson, true);
        
        if (!is_array($ids) || empty($ids)) {
            echo json_encode(['success' => false, 'message' => 'Aucune année sélectionnée']);
            return;
        }
        
        // Vérifier qu'aucune année active n'est sélectionnée pour la suppression
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id_Ac FROM année_academique WHERE id_Ac IN ($placeholders) AND statut = 'active'");
        $stmt->execute($ids);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Impossible de supprimer une année active']);
            return;
        }
        
        $stmt = $pdo->prepare("DELETE FROM année_academique WHERE id_Ac IN ($placeholders)");
        $result = $stmt->execute($ids);
        $deletedCount = $stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'message' => "{$deletedCount} année(s) supprimée(s) avec succès"
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
}

// Nouvelle fonction pour la récupération des données via AJAX pour le rafraîchissement ou la recherche
function handleFetchAllAjax($pdo) {
    try {
        $stmt = $pdo->query("DESCRIBE année_academique");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $hasStatut = in_array('statut', $columns);
        
        if ($hasStatut) {
            $stmt = $pdo->query("SELECT id_Ac as id_annee, YEAR(date_deb) as annee_debut, YEAR(date_fin) as annee_fin, statut FROM année_academique ORDER BY date_deb DESC");
        } else {
            $stmt = $pdo->query("SELECT id_Ac as id_annee, YEAR(date_deb) as annee_debut, YEAR(date_fin) as annee_fin, 'preparation' as statut FROM année_academique ORDER BY date_deb DESC");
        }
        $years = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $years
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur de chargement: ' . $e->getMessage()]);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Gestion des Années Académiques</title>
    <link href="anneeacademique.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
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
            grid-template-columns: 1fr;
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

        .form-group input[type="number"],
        .form-group input[type="text"] {
            padding: var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            color: var(--gray-800);
            transition: all var(--transition-fast);
        }

        .form-group input[type="number"]:focus,
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
            min-width: 600px; /* Minimum width for table on smaller screens */
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

        /* Styles spécifiques pour gestion_annee_academique */
        .year-id { font-family: monospace; font-weight: bold; color: #2c3e50; }
        .badge { padding: 0.35em 0.65em; font-size: 0.75em; border-radius: 0.25rem; color: white; }
        .badge.bg-success { background-color: #28a745; } /* Active */
        .badge.bg-info { background-color: #17a2b8; }    /* Préparation */
        .badge.bg-secondary { background-color: #6c757d; } /* Archivée */
        .badge.bg-warning { background-color: #ffc107; color: #212529; } /* Could be for error or other state */

        /* Toast messages */
        .toast { 
            position: fixed; top: 20px; right: 20px; padding: 1rem; border-radius: 0.25rem; 
            color: white; z-index: 1000; animation: fadeIn 0.3s forwards; 
            box-shadow: var(--shadow-md); display: flex; align-items: center;
        }
        .toast-success { background-color: var(--success-500); }
        .toast-error { background-color: var(--error-500); }
        .toast-warning { background-color: var(--warning-500); color: #212529; }
        .toast-info { background-color: var(--info-500); }
        .toast-close-btn { 
            background: none; border: none; color: inherit; margin-left: 10px; cursor: pointer; 
            font-size: 1.2em;
        }
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateX(100%); } 
            to { opacity: 1; transform: translateX(0); } 
        }

        /* Modals */
        .modal { 
            display: none; /* Hidden by default */
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; 
        }
        .modal-content { 
            background: white; padding: 1.5rem; border-radius: 0.5rem; width: 90%; max-width: 500px; 
            box-shadow: var(--shadow-lg); position: relative;
        }
        .close-button { 
            position: absolute; top: 10px; right: 15px; cursor: pointer; font-size: 1.5rem; 
            color: var(--gray-500);
        }
        .close-button:hover {
            color: var(--gray-800);
        }
        .modal-buttons {
            display: flex; justify-content: flex-end; gap: var(--space-3); margin-top: var(--space-6);
        }
        .modal-buttons .btn {
            min-width: 100px; /* Ensure buttons have consistent width */
        }
        .modal-buttons .btn-primary {
            background-color: var(--accent-600);
            color: white;
        }
        .modal-buttons .btn-secondary {
            background-color: var(--gray-200);
            color: var(--gray-700);
        }


        /* Activation button */
        .btn-activate { 
            background: var(--accent-500); 
            color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 0.25rem; 
            cursor: pointer; margin-right: 0.25rem; 
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.85em; font-weight: 500;
        }
        .btn-activate:hover { background: var(--accent-600); }
        .btn-activate:disabled { background: var(--gray-400); cursor: not-allowed; }
        
        /* Checkbox styling from gestion_type_util.php */
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

        /* Responsive adjustments from gestion_type_util.php */
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
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Gestion des Années Académiques</h1>
                    <p class="page-subtitle">Créez et gérez les périodes académiques de la plateforme.</p>
                </div>

                <div class="form-card">
                    <h3 class="form-card-title">Créer une Année Académique</h3>
                    <form id="academicYearForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="anneeDebut">Année de début</label>
                                <input type="number" id="anneeDebut" name="anneeDebut" placeholder="Ex: 2025" required min="2025" max="2100">
                            </div>
                            <div class="form-group">
                                <label for="anneeFin">Année de fin</label>
                                <input type="number" id="anneeFin" name="anneeFin" placeholder="Ex: 2026" required min="2026" max="2100">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Annuler
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Barre de recherche et Boutons d'exportation -->
                <div class="search-bar">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher une année académique (ID, période)...">
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
                        <h3 class="table-title">Liste des Années Académiques</h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" id="supprimerBtn" disabled>
                                <i class="fas fa-trash-alt"></i> Supprimer
                            </button>
                            <!-- L'ancien bouton Exporter a été déplacé/supprimé car les nouveaux boutons d'exportation sont au-dessus -->
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="academicYearsTable">
                            <thead>
                                <tr>
                                    <th width="40px"></th> <!-- Colonne pour la case à cocher -->
                                    <th>ID</th>
                                    <th>Année Académique</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($years)): ?>
                                <tr id="no-data-row">
                                    <td colspan="5" style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                        <i class="fas fa-calendar-alt" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucune année académique enregistrée.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($years as $year): ?>
                                <tr data-id="<?= htmlspecialchars($year['id_annee']) ?>">
                                    <td>
                                        <label class="checkbox-container">
                                            <input type="checkbox" value="<?= htmlspecialchars($year['id_annee']) ?>" 
                                                <?= $year['statut'] === 'active' ? 'disabled' : '' ?>>
                                            <span class="checkmark"></span>
                                        </label>
                                    </td>
                                    <td class="year-id"><?= htmlspecialchars($year['id_annee']) ?></td>
                                    <td><?= htmlspecialchars($year['annee_debut']) ?>-<?= htmlspecialchars($year['annee_fin']) ?></td>
                                    <td>
                                        <?php if ($year['statut'] === 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php elseif ($year['statut'] === 'preparation'): ?>
                                            <span class="badge bg-info">Préparation</span>
                                        <?php else: /* archivee */ ?>
                                            <span class="badge bg-secondary">Archivée</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($year['statut'] !== 'active'): ?>
                                        <button class="btn-activate" data-id="<?= htmlspecialchars($year['id_annee']) ?>" title="Activer cette année académique">
                                            <i class="fas fa-power-off"></i> Activer
                                        </button>
                                        <?php else: ?>
                                        <span style="color: var(--success-500); font-weight: bold;">
                                            <i class="fas fa-check-circle"></i> Année Active
                                        </span>
                                        <?php endif; ?>
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

    <div id="confirmationModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h4 id="modalTitle">Confirmer l'action</h4>
            <p id="modalMessage">Êtes-vous sûr de vouloir effectuer cette action ?</p>
            <div class="modal-buttons">
                <button id="modalConfirmBtn" class="btn btn-primary">Confirmer</button>
                <button id="modalCancelBtn" class="btn btn-secondary">Annuler</button>
            </div>
        </div>
    </div>

    <script>
        // Initialisation de jsPDF
        const { jsPDF } = window.jspdf;

        class GestionAnneeAcademique {
            constructor() {
                this.selectedRows = new Set();
                this.initElements();
                this.initEventListeners();
                this.loadAcademicYears(); // Charger les données initiales au démarrage
            }

            initElements() {
                this.form = document.getElementById('academicYearForm');
                this.anneeDebutInput = document.getElementById('anneeDebut');
                this.anneeFinInput = document.getElementById('anneeFin');
                this.tableBody = document.querySelector('#academicYearsTable tbody');
                this.supprimerBtn = document.getElementById('supprimerBtn');
                this.searchInput = document.getElementById('searchInput');
                this.searchButton = document.getElementById('searchButton');
                this.exportPdfBtn = document.getElementById('exportPdfBtn');
                this.exportExcelBtn = document.getElementById('exportExcelBtn');
                this.exportCsvBtn = document.getElementById('exportCsvBtn');
                this.modal = document.getElementById('confirmationModal');
                this.noDataRow = document.getElementById('no-data-row'); // The row that displays "no data" message
            }

            initEventListeners() {
                this.form.addEventListener('submit', (e) => this.handleFormSubmit(e));
                this.form.addEventListener('reset', () => setTimeout(() => this.resetForm(), 10));

                // Auto-complétion de l'année de fin
                this.anneeDebutInput.addEventListener('input', (e) => {
                    const value = e.target.value;
                    if (value && value.length === 4) {
                        this.anneeFinInput.value = parseInt(value) + 1;
                        // this.showToast('Année de fin complétée automatiquement', 'info'); // Commenté comme demandé
                    }
                });

                // Boutons d'action
                this.supprimerBtn.addEventListener('click', () => this.handleDelete());

                // Barre de recherche
                this.searchButton.addEventListener('click', () => this.searchYears());
                this.searchInput.addEventListener('keyup', (e) => {
                    if (e.key === 'Enter') {
                        this.searchYears();
                    } else {
                        // Live search as user types
                        this.searchYears();
                    }
                });

                // Boutons d'exportation
                this.exportPdfBtn.addEventListener('click', () => this.exportToPdf());
                this.exportExcelBtn.addEventListener('click', () => this.exportToExcel());
                this.exportCsvBtn.addEventListener('click', () => this.exportToCsv());

                // Checkboxes et boutons d'activation (délégation d'événements)
                this.tableBody.addEventListener('change', (e) => {
                    console.log('Change event on table body, target:', e.target); // Log pour le débogage
                    if (e.target.type === 'checkbox') {
                        console.log('Checkbox state:', e.target.value, e.target.checked, 'is disabled:', e.target.disabled); // Log pour le débogage
                        this.updateSelections();
                    }
                });

                this.tableBody.addEventListener('click', (e) => {
                    if (e.target.closest('.btn-activate')) {
                        const btn = e.target.closest('.btn-activate');
                        const id = btn.dataset.id;
                        this.activateYear(id);
                    }
                });

                // Sidebar toggle for mobile
                const sidebarToggle = document.getElementById('sidebarToggle');
                const sidebar = document.getElementById('sidebar');
                const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
                if (sidebarToggle && sidebar && mobileMenuOverlay) {
                    sidebarToggle.addEventListener('click', () => {
                        sidebar.classList.toggle('mobile-open');
                        mobileMenuOverlay.classList.toggle('active');
                        // Toggle icons
                        const barsIcon = sidebarToggle.querySelector('.fa-bars');
                        const timesIcon = sidebarToggle.querySelector('.fa-times');
                        if (sidebar.classList.contains('mobile-open')) {
                            barsIcon.style.display = 'none';
                            timesIcon.style.display = 'inline-block';
                        } else {
                            barsIcon.style.display = 'inline-block';
                            timesIcon.style.display = 'none';
                        }
                    });
                    mobileMenuOverlay.addEventListener('click', () => {
                        sidebar.classList.remove('mobile-open');
                        mobileMenuOverlay.classList.remove('active');
                        // Reset icons
                        sidebarToggle.querySelector('.fa-bars').style.display = 'inline-block';
                        sidebarToggle.querySelector('.fa-times').style.display = 'none';
                    });
                }
            }

            async makeAjaxRequest(data) {
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams(data)
                    });
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return await response.json();
                } catch (error) {
                    console.error('Erreur AJAX:', error);
                    throw error;
                }
            }

            async loadAcademicYears() {
                try {
                    const result = await this.makeAjaxRequest({ action: 'fetch_all' });
                    if (result.success) {
                        this.renderTable(result.data);
                    } else {
                        this.showToast(result.message, 'error');
                        this.renderTable([]); // Render empty table on error
                    }
                } catch (error) {
                    this.showToast('Erreur lors du chargement des années académiques: ' + error.message, 'error');
                    this.renderTable([]);
                }
            }

            renderTable(years) {
                this.tableBody.innerHTML = ''; // Clear existing rows
                if (years.length === 0) {
                    this.tableBody.innerHTML = `
                        <tr id="no-data-row">
                            <td colspan="5" style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                <i class="fas fa-calendar-alt" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                Aucune année académique enregistrée.
                            </td>
                        </tr>
                    `;
                } else {
                    years.forEach(year => {
                        const newRow = this.tableBody.insertRow();
                        newRow.setAttribute('data-id', year.id_annee);
                        newRow.innerHTML = `
                            <td>
                                <label class="checkbox-container">
                                    <input type="checkbox" value="${year.id_annee}" 
                                        ${year.statut === 'active' ? 'disabled' : ''}>
                                    <span class="checkmark"></span>
                                </label>
                            </td>
                            <td class="year-id">${year.id_annee}</td>
                            <td>${year.annee_debut}-${year.annee_fin}</td>
                            <td>
                                ${year.statut === 'active' ? 
                                    '<span class="badge bg-success">Active</span>' : 
                                    (year.statut === 'preparation' ? 
                                        '<span class="badge bg-info">Préparation</span>' : 
                                        '<span class="badge bg-secondary">Archivée</span>'
                                    )
                                }
                            </td>
                            <td>
                                ${year.statut !== 'active' ? 
                                    `<button class="btn-activate" data-id="${year.id_annee}" title="Activer cette année académique">
                                        <i class="fas fa-power-off"></i> Activer
                                    </button>` : 
                                    `<span style="color: var(--success-500); font-weight: bold;">
                                        <i class="fas fa-check-circle"></i> Année Active
                                    </span>`
                                }
                            </td>
                        `;
                    });
                }
                this.updateSelections(); // Update selection status after re-rendering
            }

            async handleFormSubmit(e) {
                e.preventDefault();
                
                const anneeDebut = parseInt(this.anneeDebutInput.value);
                const anneeFin = parseInt(this.anneeFinInput.value);

                if (!anneeDebut || !anneeFin) {
                    this.showToast('Veuillez remplir tous les champs', 'error');
                    return;
                }

                const formData = new FormData();
                formData.append('anneeDebut', anneeDebut);
                formData.append('anneeFin', anneeFin);
                formData.append('action', 'create');

                try {
                    this.showLoading(true);
                    const result = await this.makeAjaxRequest(Object.fromEntries(formData)); // Convert FormData to plain object for makeAjaxRequest

                    if (result.success) {
                        this.showToast(result.message, 'success');
                        this.resetForm();
                        await this.loadAcademicYears(); // Reload table data via AJAX
                    } else {
                        this.showToast(result.message, 'error');
                    }
                } catch (error) {
                    this.showToast('Erreur de connexion: ' + error.message, 'error');
                } finally {
                    this.showLoading(false);
                }
            }

            async activateYear(id) {
                const confirmed = await this.showConfirmationModal(
                    `Voulez-vous vraiment activer l'année ${id} ?\nCela désactivera l'année actuellement active.`
                );
                
                if (!confirmed) return;

                try {
                    const result = await this.makeAjaxRequest({ action: 'activate', id_annee: id });
                    this.showToast(result.message, result.success ? 'success' : 'error');
                    
                    if (result.success) {
                        await this.loadAcademicYears(); // Reload table data via AJAX
                    }
                } catch (error) {
                    this.showToast('Erreur lors de l\'activation: ' + error.message, 'error');
                }
            }

            async handleDelete() {
                const ids = Array.from(this.selectedRows);
                if (ids.length === 0) {
                    this.showToast('Veuillez sélectionner au moins une année', 'warning');
                    return;
                }

                const confirmed = await this.showConfirmationModal(
                    `Voulez-vous vraiment supprimer ${ids.length} année(s) sélectionnée(s) ?`
                );
                
                if (!confirmed) return;

                try {
                    const result = await this.makeAjaxRequest({ action: 'delete', ids_annee: JSON.stringify(ids) });
                    this.showToast(result.message, result.success ? 'success' : 'error');
                    
                    if (result.success) {
                        await this.loadAcademicYears(); // Reload table data via AJAX
                        this.selectedRows.clear(); // Clear selections after successful deletion
                        this.updateSelections(); // Update button state
                    }
                } catch (error) {
                    this.showToast('Erreur: ' + error.message, 'error');
                }
            }

            searchYears() {
                const searchTerm = this.searchInput.value.toLowerCase();
                const rows = this.tableBody.querySelectorAll('tr[data-id]');
                let foundResults = false;

                rows.forEach(row => {
                    const id = row.getAttribute('data-id').toLowerCase();
                    const periode = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                    const statut = row.querySelector('td:nth-child(4)').textContent.toLowerCase();
                    
                    if (id.includes(searchTerm) || periode.includes(searchTerm) || statut.includes(searchTerm)) {
                        row.style.display = '';
                        foundResults = true;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Show/hide "No data" message based on search results
                if (this.noDataRow) {
                    this.noDataRow.style.display = foundResults || rows.length === 0 ? 'none' : 'table-row';
                }
                // If there were rows but none matched the search, and no initial "no data" row,
                // we might need to display a temporary message.
                if (!foundResults && rows.length > 0 && !document.getElementById('no-search-results-row')) {
                    const tempNoResultsRow = this.tableBody.insertRow();
                    tempNoResultsRow.id = 'no-search-results-row';
                    tempNoResultsRow.innerHTML = `<td colspan="5" style="text-align: center; padding: 2rem; color: var(--gray-500);">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                        Aucun résultat ne correspond à votre recherche.
                    </td>`;
                } else if (foundResults && document.getElementById('no-search-results-row')) {
                    document.getElementById('no-search-results-row').remove();
                } else if (!foundResults && rows.length === 0 && !this.noDataRow) {
                     // If table was initially empty and no search results, ensure original empty message is shown.
                     // This case is handled by renderTable already.
                }
            }

            exportToPdf() {
                const doc = new jsPDF();
                const title = "Liste des Années Académiques";
                const date = new Date().toLocaleDateString('fr-FR');
                
                doc.setFontSize(18);
                doc.text(title, 14, 20);
                
                doc.setFontSize(10);
                doc.text(`Exporté le: ${date}`, 14, 30);
                
                const headers = [['ID', 'Année Académique', 'Statut']];
                const data = [];
                
                document.querySelectorAll('#academicYearsTable tbody tr[data-id]').forEach(row => {
                    // Only export visible rows (after search)
                    if (row.style.display !== 'none') {
                        const id = row.cells[1].textContent.trim();
                        const periode = row.cells[2].textContent.trim();
                        const statut = row.cells[3].textContent.trim();
                        data.push([id, periode, statut]);
                    }
                });

                if (data.length === 0) {
                    this.showToast('Aucune donnée visible à exporter en PDF.', 'warning');
                    return;
                }
                
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
                        fillColor: [59, 130, 246], // accent-600
                        textColor: 255,
                        fontStyle: 'bold'
                    },
                    alternateRowStyles: {
                        fillColor: [241, 245, 249] // gray-100
                    }
                });
                
                doc.save(`Années_Academiques_${new Date().toISOString().split('T')[0]}.pdf`);
                this.showToast('Exportation PDF terminée', 'success');
            }

            exportToExcel() {
                const data = [['ID', 'Année Académique', 'Statut']];
                
                document.querySelectorAll('#academicYearsTable tbody tr[data-id]').forEach(row => {
                    // Only export visible rows (after search)
                    if (row.style.display !== 'none') {
                        const id = row.cells[1].textContent.trim();
                        const periode = row.cells[2].textContent.trim();
                        const statut = row.cells[3].textContent.trim();
                        data.push([id, periode, statut]);
                    }
                });

                if (data.length === 1) { // Only headers
                    this.showToast('Aucune donnée visible à exporter en Excel.', 'warning');
                    return;
                }

                const ws = XLSX.utils.aoa_to_sheet(data);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, "Années Académiques");
                
                XLSX.writeFile(wb, `Années_Academiques_${new Date().toISOString().split('T')[0]}.xlsx`);
                this.showToast('Exportation Excel terminée', 'success');
            }

            exportToCsv() {
                let csv = "ID,Année Académique,Statut\n";
                
                let hasDataToExport = false;
                document.querySelectorAll('#academicYearsTable tbody tr[data-id]').forEach(row => {
                    // Only export visible rows (after search)
                    if (row.style.display !== 'none') {
                        const id = `"${row.cells[1].textContent.trim()}"`;
                        const periode = `"${row.cells[2].textContent.trim()}"`;
                        const statut = `"${row.cells[3].textContent.trim()}"`;
                        csv += `${id},${periode},${statut}\n`;
                        hasDataToExport = true;
                    }
                });
                
                if (!hasDataToExport) {
                    this.showToast('Aucune donnée visible à exporter en CSV.', 'warning');
                    return;
                }

                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                
                link.setAttribute('href', url);
                link.setAttribute('download', `Années_Academiques_${new Date().toISOString().split('T')[0]}.csv`);
                link.style.visibility = 'hidden';
                
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                this.showToast('Exportation CSV terminée', 'success');
            }

            updateSelections() {
                console.log('Running updateSelections()...'); // Log pour le débogage
                this.selectedRows.clear();
                // Sélectionner uniquement les cases à cocher qui sont cochées ET non désactivées
                const checkboxes = this.tableBody.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)');
                checkboxes.forEach(cb => this.selectedRows.add(cb.value));
                console.log('selectedRows:', Array.from(this.selectedRows)); // Log pour le débogage
                this.supprimerBtn.disabled = this.selectedRows.size === 0;
                console.log('supprimerBtn disabled state:', this.supprimerBtn.disabled); // Log pour le débogage
            }

            resetForm() {
                this.form.reset();
                this.selectedRows.clear();
                this.supprimerBtn.disabled = true;
            }

            showLoading(show) {
                const btn = this.form.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = show;
                    btn.innerHTML = show 
                        ? '<i class="fas fa-spinner fa-spin"></i> Traitement...' 
                        : '<i class="fas fa-save"></i> Enregistrer';
                }
            }

            showToast(message, type = 'info') {
                // Remove existing toasts to prevent stacking too many
                document.querySelectorAll('.toast').forEach(t => t.remove());
                
                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;
                toast.innerHTML = `
                    <span>${message}</span>
                    <button class="toast-close-btn" onclick="this.parentElement.remove()">×</button>
                `;
                
                document.body.appendChild(toast);
                setTimeout(() => {
                    if (toast.parentElement) { // Check if toast hasn't been manually closed
                        toast.remove();
                    }
                }, type === 'error' ? 8000 : 5000);
            }

            showConfirmationModal(message, title = 'Confirmation') {
                console.log('showConfirmationModal called. Modal element:', this.modal); // Log pour le débogage
                return new Promise(resolve => {
                    const modal = this.modal;
                    modal.querySelector('#modalTitle').textContent = title;
                    modal.querySelector('#modalMessage').textContent = message;
                    modal.style.display = 'flex'; // Show the modal
                    console.log('Modal display set to flex. Current computed style:', window.getComputedStyle(modal).display); // Log pour le débogage

                    const cleanUp = () => {
                        modal.style.display = 'none'; // Hide the modal
                        // Remove event listeners to prevent memory leaks and multiple resolutions
                        modal.querySelector('#modalConfirmBtn').onclick = null;
                        modal.querySelector('#modalCancelBtn').onclick = null;
                        modal.querySelector('.close-button').onclick = null;
                    };

                    modal.querySelector('#modalConfirmBtn').onclick = () => {
                        cleanUp();
                        resolve(true); // Resolve with true if confirmed
                    };
                    
                    modal.querySelector('#modalCancelBtn').onclick = () => {
                        cleanUp();
                        resolve(false); // Resolve with false if cancelled
                    };
                    
                    modal.querySelector('.close-button').onclick = () => {
                        cleanUp();
                        resolve(false); // Resolve with false if closed by X button
                    };
                });
            }
        }

        // Initialisation de la gestion des années académiques quand le DOM est chargé
        document.addEventListener('DOMContentLoaded', () => {
            window.gestionAnnee = new GestionAnneeAcademique();
            // setTimeout(() => { // Commenté comme demandé
            //     window.gestionAnnee.showToast('Interface de gestion prête', 'success'); // Commenté comme demandé
            // }, 500); // Commenté comme demandé

            // Gestion du redimensionnement de la fenêtre pour la sidebar (copié de gestion_type_util.php)
            function handleResize() {
                const sidebar = document.getElementById('sidebar');
                const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
                // Sur les grands écrans, s'assurer que la sidebar est visible et overlay masqué
                if (window.innerWidth >= 1024) {
                    if (sidebar) sidebar.classList.remove('mobile-open');
                    if (mobileMenuOverlay) mobileMenuOverlay.classList.remove('active');
                }
            }
            window.addEventListener('resize', handleResize);
        });
    </script>
</body>
</html>
