<?php
// gestion_ue.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Fonction pour adapter la structure de la base de données si nécessaire
function adapterStructureBD($pdo) {
    try {
        // Start a transaction for schema changes
        $pdo->beginTransaction();

        // 1. Vérifier et modifier la structure de la table ue si nécessaire
        // id_UE should be VARCHAR(20) to store generated codes like "INFO_ALG_S1"
        $stmt = $pdo->query("SHOW COLUMNS FROM ue LIKE 'id_UE'");
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($column && (strpos($column['Type'], 'int') !== false || $column['Type'] !== 'varchar(20)')) {
            // Check if fk_id_UE in ecue references an int id_UE in ue. If so, we need to drop/recreate constraint.
            $stmt_fk_ecue = $pdo->query("SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'ue' AND REFERENCED_COLUMN_NAME = 'id_UE' AND TABLE_NAME = 'ecue'");
            $fk_ecue_info = $stmt_fk_ecue->fetch(PDO::FETCH_ASSOC);

            if ($fk_ecue_info) {
                // Drop the foreign key constraint on 'ecue' table if it exists and refers to 'ue.id_UE'
                $pdo->exec("ALTER TABLE ecue DROP FOREIGN KEY " . $fk_ecue_info['CONSTRAINT_NAME']);
                error_log("Contrainte de clé étrangère '{$fk_ecue_info['CONSTRAINT_NAME']}' sur la table 'ecue' supprimée temporairement.");
            }

            $pdo->exec("ALTER TABLE ue MODIFY COLUMN id_UE VARCHAR(20) NOT NULL");
            error_log("Colonne id_UE modifiée en VARCHAR(20).");
            
            // If the foreign key was dropped, recreate it with the new VARCHAR type
            if ($fk_ecue_info) {
                $pdo->exec("ALTER TABLE ecue ADD CONSTRAINT fk_ecue_ue FOREIGN KEY (fk_id_UE) REFERENCES ue(id_UE) ON DELETE CASCADE ON UPDATE CASCADE");
                error_log("Contrainte de clé étrangère 'fk_ecue_ue' sur la table 'ecue' recréée.");
            }
        }
        
        // 2. Vérifier et ajouter la colonne id_Ac à la table ue si elle n'existe pas
        $stmt = $pdo->query("SHOW COLUMNS FROM ue LIKE 'id_Ac'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE ue ADD COLUMN id_Ac INT AFTER credit_UE");
            // Ajouter la contrainte foreign key si elle n'existe pas
            $pdo->exec("ALTER TABLE ue ADD CONSTRAINT fk_ue_annee_academique FOREIGN KEY (id_Ac) REFERENCES année_academique(id_Ac) ON DELETE CASCADE ON UPDATE CASCADE");
            error_log("Colonne id_Ac ajoutée à la table ue et FK recréée.");
        }

        // 3. Vérifier et ajouter la colonne semestre à la table ue si elle n'existe pas
        $stmt = $pdo->query("SHOW COLUMNS FROM ue LIKE 'semestre'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE ue ADD COLUMN semestre INT DEFAULT NULL AFTER credit_UE");
            error_log("Colonne semestre ajoutée à la table ue.");
        }

        // Commit the transaction if all operations are successful
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        // Rollback the transaction on error
        $pdo->rollBack();
        error_log("Erreur lors de l'adaptation de la structure BD (ROLLBACK): " . $e->getMessage());
        return false;
    }
}

// Adapter la structure de la base de données si nécessaire
$structureOK = adapterStructureBD($pdo);

// Fonction pour générer le code UE automatiquement, incluant le semestre
function genererCodeUE($libelleUE, $semestre) {
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
    
    // Ajoute le semestre au code pour l'unicité et la lisibilité
    $code = (empty($code) ? 'UE' : $code) . '_S' . $semestre;
    
    return $code;
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
                $semestre = intval($_POST['semestre']); // Nouveau champ
                
                // Validation
                if (empty($libelleUE)) {
                    throw new Exception("Le libellé de l'UE est obligatoire");
                }
                
                if ($creditUE < 1 || $creditUE > 20) {
                    throw new Exception("Le nombre de crédits doit être entre 1 et 20");
                }

                // Updated validation for semestre: now allows 1 to 9
                if ($semestre < 1 || $semestre > 9) {
                    throw new Exception("Le semestre doit être entre 1 et 9");
                }
                
                // Générer le code UE automatiquement avec le semestre
                $codeUE = genererCodeUE($libelleUE, $semestre);
                
                // Vérifier si le code UE existe déjà pour cette année académique et ce semestre
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM ue WHERE id_UE = ? AND id_Ac = ? AND semestre = ?");
                $checkStmt->execute([$codeUE, $idAc, $semestre]);
                
                if ($checkStmt->fetchColumn() > 0) {
                    // Si le code existe, ajouter un suffixe numérique pour l'unicité
                    $counter = 1;
                    $originalCode = $codeUE;
                    do {
                        $codeUE = $originalCode . sprintf('%02d', $counter);
                        $checkStmt->execute([$codeUE, $idAc, $semestre]);
                        $counter++;
                    } while ($checkStmt->fetchColumn() > 0);
                }
                
                // Insérer la nouvelle UE
                $stmt = $pdo->prepare("INSERT INTO ue (id_UE, lib_UE, credit_UE, semestre, id_Ac) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$codeUE, $libelleUE, $creditUE, $semestre, $idAc]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'UE créée avec succès',
                    'data' => [
                        'id_UE' => $codeUE,
                        'lib_UE' => $libelleUE,
                        'credit_UE' => $creditUE,
                        'semestre' => $semestre
                    ]
                ]);
                break;
                
            case 'update':
                $idUE = $_POST['id_UE'];
                $libelleUE = trim($_POST['libelleUE']);
                $creditUE = intval($_POST['creditUE']);
                $semestre = intval($_POST['semestre']); // Nouveau champ
                
                // Validation
                if (empty($libelleUE)) {
                    throw new Exception("Le libellé de l'UE est obligatoire");
                }
                
                if ($creditUE < 1 || $creditUE > 20) {
                    throw new Exception("Le nombre de crédits doit être entre 1 et 20");
                }

                // Updated validation for semestre: now allows 1 to 9
                if ($semestre < 1 || $semestre > 9) {
                    throw new Exception("Le semestre doit être entre 1 et 9");
                }
                
                // Note: Changing id_UE (code) during update can be complex if it's a primary key.
                // For simplicity, we only allow updating lib_UE, credit_UE, and semestre.
                // If you need to change id_UE, you'd need to handle cascade updates or re-create the record.
                $stmt = $pdo->prepare("UPDATE ue SET lib_UE = ?, credit_UE = ?, semestre = ? WHERE id_UE = ?");
                $stmt->execute([$libelleUE, $creditUE, $semestre, $idUE]);
                
                echo json_encode(['success' => true, 'message' => 'UE modifiée avec succès']);
                break;
                
            case 'delete':
                $idsUE = json_decode($_POST['ids_ue'], true);
                
                if (empty($idsUE)) {
                    throw new Exception("Aucune UE sélectionnée pour la suppression");
                }
                
                // Vérifier si les UE sont utilisées dans d'autres tables (ECUE par exemple)
                foreach ($idsUE as $idUE) {
                    $checkUsage = $pdo->prepare("SELECT COUNT(*) FROM ecue WHERE fk_id_UE = ?"); // Ensure column name is correct (fk_id_UE from your schema)
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
        // Récupérer l'année académique active
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
                            <div class="form-group">
                                <label for="semestre">Semestre <span style="color: var(--error-500);">*</span></label>
                                <select id="semestre" name="semestre" required <?php echo !$anneeActive ? 'disabled' : ''; ?>>
                                    <option value="">Sélectionner un semestre</option>
                                    <?php for ($i = 1; $i <= 9; $i++): ?>
                                        <option value="<?php echo $i; ?>">Semestre <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
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

                <div class="search-bar">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher une UE par code, libellé ou semestre...">
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
                                    <?php for ($i = 1; $i <= 9; $i++): ?>
                                        <div class="filter-option" data-filter="semestre-<?php echo $i; ?>">
                                            <i class="fas fa-filter"></i> Semestre <?php echo $i; ?>
                                        </div>
                                    <?php endfor; ?>
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
                                    <th>Semestre</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($ues)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
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
                                        <td><?php echo htmlspecialchars($ue['semestre'] ?? 'N/A'); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button edit" title="Modifier" onclick="modifierUE('<?php echo htmlspecialchars($ue['id_UE']); ?>')">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="action-button delete" title="Supprimer" onclick="supprimerUEConfirm('<?php echo htmlspecialchars($ue['id_UE']); ?>')">
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
        let selectedUEs = new Set();
        let editingUE = null;
        let confirmActionCallback = null; // Callback for confirmation modal
        const anneeActive = <?php echo $anneeActive ? json_encode($anneeActive) : 'null'; ?>;
        const { jsPDF } = window.jspdf;

        // Éléments DOM
        const ueForm = document.getElementById('ueForm');
        const libelleUEInput = document.getElementById('libelleUE');
        const creditUEInput = document.getElementById('creditUE');
        const semestreInput = document.getElementById('semestre'); // Nouveau
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
                if (timesIcon) timesIcon.style.display = 'inline-block'; 
            } else {
                barsIcon.style.display = 'inline-block';
                if (timesIcon) timesIcon.style.display = 'none'; 
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

        // Function to add a row to the table
        function addRowToTable(ue) {
            // Remove "Aucune UE trouvée" message if it exists
            const emptyRow = ueTableBody.querySelector('td[colspan="6"]'); // Adjusted colspan for semestre
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
                <td>${ue.semestre}</td> <td>
                    <div class="action-buttons">
                        <button class="action-button edit" title="Modifier" onclick="modifierUE('${ue.id_UE}')">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button class="action-button delete" title="Supprimer" onclick="supprimerUEConfirm('${ue.id_UE}')">
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
                    selectedUEs.add(this.value);
                } else {
                    selectedUEs.delete(this.value);
                }
                updateActionButtons();
            });
        }

        // Search function
        function searchUEs() {
            const searchTerm = searchInput.value.toLowerCase();
            const rows = ueTableBody.querySelectorAll('tr[data-id]'); // Select only data rows
            
            let hasResults = false;
            
            rows.forEach(row => {
                const codeUE = row.cells[1].textContent.toLowerCase();
                const libelleUE = row.cells[2].textContent.toLowerCase();
                const creditUE = row.cells[3].textContent.toLowerCase();
                const semestre = row.cells[4].textContent.toLowerCase(); // New: search by semestre
                
                if (codeUE.includes(searchTerm) || 
                    libelleUE.includes(searchTerm) || 
                    creditUE.includes(searchTerm) ||
                    semestre.includes(searchTerm)) { // New: search by semestre
                    row.style.display = '';
                    hasResults = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Display message if no results and search term is not empty
            const emptyRowPlaceholder = ueTableBody.querySelector('td[colspan="6"]'); // Adjusted colspan
            if (!hasResults && searchTerm !== "") {
                if (!emptyRowPlaceholder) { // Add if not already present
                    ueTableBody.innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                <i class="fas fa-search-minus" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                Aucun résultat trouvé pour "${searchTerm}".
                            </td>
                        </tr>
                    `; // Adjusted colspan
                }
            } else if (searchTerm === "" && emptyRowPlaceholder && ueTableBody.children.length === 1) {
                applyFilter('all');
            } else if (searchTerm !== "" && hasResults && emptyRowPlaceholder) {
                // If there are results now, remove the "no results" message
                 emptyRowPlaceholder.closest('tr').remove();
            }
        }

        // Function to apply filters
        function applyFilter(filterType) {
            let rows = Array.from(ueTableBody.querySelectorAll('tr[data-id]')); // Select only data rows
            
            // Remove previous empty message if any
            const emptyRowPlaceholder = ueTableBody.querySelector('td[colspan="6"]'); // Adjusted colspan
            if (emptyRowPlaceholder) {
                emptyRowPlaceholder.closest('tr').remove();
            }

            // Show all rows initially before sorting/filtering by content
            rows.forEach(row => {
                row.style.display = '';
            });
            
            // Filter by credits or semester first
            if (filterType.includes('credit-') || filterType.includes('semestre-')) {
                rows.forEach(row => {
                    const credit = parseInt(row.cells[3].textContent);
                    const semestre = parseInt(row.cells[4].textContent); // New: get semestre
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
                        case 'semestre-1': // New: filter by semestre
                            shouldShow = semestre === 1;
                            break;
                        case 'semestre-2': // New: filter by semestre
                            shouldShow = semestre === 2;
                            break;
                        case 'semestre-3':
                            shouldShow = semestre === 3;
                            break;
                        case 'semestre-4':
                            shouldShow = semestre === 4;
                            break;
                        case 'semestre-5':
                            shouldShow = semestre === 5;
                            break;
                        case 'semestre-6':
                            shouldShow = semestre === 6;
                            break;
                        case 'semestre-7':
                            shouldShow = semestre === 7;
                            break;
                        case 'semestre-8':
                            shouldShow = semestre === 8;
                            break;
                        case 'semestre-9':
                            shouldShow = semestre === 9;
                            break;
                        default:
                            shouldShow = true;
                    }
                    
                    row.style.display = shouldShow ? '' : 'none';
                });
            }
            
            // Sort rows based on filterType
            rows.sort((a, b) => {
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
                    default: // 'all' or specific semester/credit filter applied already (no sort by default)
                        return 0; 
                }
            });
            
            // Re-append sorted/filtered rows to the table body
            ueTableBody.innerHTML = ''; // Clear current display
            const currentlyVisibleRows = rows.filter(row => row.style.display !== 'none');
            
            if (currentlyVisibleRows.length === 0) {
                 ueTableBody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucune UE trouvée correspondant aux critères de recherche.
                        </td>
                    </tr>
                `; // Adjusted colspan
            } else {
                currentlyVisibleRows.forEach(row => ueTableBody.appendChild(row));
            }
        }

        // Form submission
        ueForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            if (!anneeActive) {
                showAlert('Aucune année académique active. Impossible de créer ou modifier une UE.', 'error');
                return;
            }

            const formData = new FormData(this);
            const data = {
                action: editingUE ? 'update' : 'create',
                id_Ac: formData.get('id_Ac'),
                libelleUE: formData.get('libelleUE'),
                creditUE: formData.get('creditUE'),
                semestre: formData.get('semestre') 
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
                        // Update existing row
                        const row = document.querySelector(`tr[data-id="${editingUE}"]`);
                        if (row) {
                            row.cells[2].textContent = data.libelleUE;
                            row.cells[3].textContent = data.creditUE;
                            row.cells[4].textContent = data.semestre; 
                        }
                        showAlert('UE modifiée avec succès', 'success');
                        resetForm();
                    } else {
                        // Add new row
                        addRowToTable(result.data);
                        showAlert(`UE "${data.libelleUE}" (${result.data.id_UE}) créée avec succès`, 'success');
                    }
                    this.reset();
                    searchInput.value = '';
                    applyFilter('all'); 
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
            editingUE = null;
            submitText.textContent = 'Ajouter UE';
            submitBtn.innerHTML = '<i class="fas fa-plus"></i> Ajouter UE';
            ueForm.reset();
            // Reset the academic year value
            document.querySelector('input[name="id_Ac"]').value = anneeActive ? anneeActive.id_Ac : '';
            // Clear selected UEs and update buttons
            selectedUEs.clear();
            updateActionButtons();
            // Ensure checkboxes are unchecked
            ueTableBody.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
        }

        // Cancel button
        cancelBtn.addEventListener('click', function() {
            resetForm();
        });

        // Function to modify an UE
        function modifierUE(idUE) {
            const row = document.querySelector(`tr[data-id="${idUE}"]`);
            if (row) {
                editingUE = idUE;
                libelleUEInput.value = row.cells[2].textContent;
                creditUEInput.value = row.cells[3].textContent;
                semestreInput.value = row.cells[4].textContent; 
                
                submitText.textContent = 'Mettre à jour';
                submitBtn.innerHTML = '<i class="fas fa-edit"></i> Mettre à jour';
                
                // Uncheck all other checkboxes and select this one
                selectedUEs.clear();
                ueTableBody.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
                const currentCheckbox = row.querySelector('input[type="checkbox"]');
                currentCheckbox.checked = true;
                selectedUEs.add(currentCheckbox.value);
                updateActionButtons();

                // Scroll to the form
                document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Function to confirm deletion of a single UE
        function supprimerUEConfirm(idUE) {
            const row = document.querySelector(`tr[data-id="${idUE}"]`);
            if (row) {
                const libelleUE = row.cells[2].textContent;
                showConfirmModal(
                    'Confirmation de Suppression',
                    `Êtes-vous sûr de vouloir supprimer l'UE "${libelleUE}" (${idUE}) ?\n\nCette action ne peut être annulée et supprimera toutes les ECUEs associées.`,
                    async (confirmed) => {
                        if (confirmed) {
                            try {
                                const result = await makeAjaxRequest({
                                    action: 'delete',
                                    ids_ue: JSON.stringify([idUE])
                                });

                                if (result.success) {
                                    row.remove();
                                    selectedUEs.delete(idUE);
                                    updateActionButtons();
                                    showAlert('UE supprimée avec succès', 'success');
                                    
                                    // If no UEs, display empty message
                                    if (ueTableBody.children.length === 0) {
                                        ueTableBody.innerHTML = `
                                            <tr>
                                                <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                                    <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                                    Aucune UE trouvée pour cette année académique. Créez votre première UE en utilisant le formulaire ci-dessus.
                                                </td>
                                            </tr>
                                        `; // Adjusted colspan
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
        modifierBtn.addEventListener('click', function() {
            if (selectedUEs.size === 1) {
                const idUE = Array.from(selectedUEs)[0];
                modifierUE(idUE);
            } else {
                showAlert('Veuillez sélectionner une seule UE à modifier.', 'warning');
            }
        });

        // Global delete button
        supprimerBtn.addEventListener('click', async function() {
            if (selectedUEs.size === 0) {
                showAlert('Veuillez sélectionner au moins une UE à supprimer.', 'warning');
                return;
            }
            const idsArray = Array.from(selectedUEs);
            
            showConfirmModal(
                'Confirmation de Suppression',
                `Êtes-vous sûr de vouloir supprimer ${idsArray.length} UE(s) sélectionnée(s) ?\n\nCette action ne peut être annulée et supprimera toutes les ECUEs associées.`,
                async (confirmed) => {
                    if (confirmed) {
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
                                showAlert('UE(s) supprimée(s) avec succès', 'success');
                                
                                // If no UEs left, display empty message
                                if (ueTableBody.children.length === 0) {
                                    ueTableBody.innerHTML = `
                                        <tr>
                                            <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                                <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                                Aucune UE trouvée pour cette année académique. Créez votre première UE en utilisant le formulaire ci-dessus.
                                            </td>
                                        </tr>
                                    `; // Adjusted colspan
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

        // Function to export to PDF
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
            const headers = [['Code UE', 'Libellé UE', 'Crédits', 'Semestre']]; // Added Semestre
            const data = [];
            
            document.querySelectorAll('#ueTable tbody tr[data-id]').forEach(row => {
                if (row.style.display !== 'none') { // Check if row is visible
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent,
                        row.cells[4].textContent // Added Semestre
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
            showAlert('Exportation PDF terminée', 'success');
        }

        // Function to export to Excel
        function exportToExcel() {
            if (!anneeActive) {
                showAlert('Aucune année académique active pour l\'exportation', 'error');
                return;
            }

            // Create data for Excel
            const data = [['Code UE', 'Libellé UE', 'Crédits', 'Semestre']]; // Added Semestre
            
            document.querySelectorAll('#ueTable tbody tr[data-id]').forEach(row => {
                if (row.style.display !== 'none') { // Check if row is visible
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        parseInt(row.cells[3].textContent),
                        parseInt(row.cells[4].textContent) // Added Semestre
                    ]);
                }
            });

            if (data.length === 0) {
                showAlert('Aucune UE à exporter', 'warning');
                return;
            }

            // Create the Excel file
            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "UE");
            
            // Download the file
            const filename = `ues_${anneeActive.date_deb.substring(0, 4)}_${anneeActive.date_fin.substring(0, 4)}.xlsx`;
            XLSX.writeFile(wb, filename);
            
            showAlert('Exportation Excel terminée', 'success');
        }

        // Function to export to CSV
        function exportToCsv() {
            if (!anneeActive) {
                showAlert('Aucune année académique active pour l\'exportation', 'error');
                return;
            }

            let csv = "Code UE,Libellé UE,Crédits,Semestre\n"; // Added Semestre
            let hasData = false;
            
            document.querySelectorAll('#ueTable tbody tr[data-id]').forEach(row => {
                if (row.style.display !== 'none') { // Check if row is visible
                    csv += `"${row.cells[1].textContent}","${row.cells[2].textContent}","${row.cells[3].textContent}","${row.cells[4].textContent}"\n`; // Added Semestre
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
            
            showAlert('Exportation CSV terminée', 'success');
        }

        // Export buttons
        exportPdfBtn.addEventListener('click', exportToPdf);
        exportExcelBtn.addEventListener('click', exportToExcel);
        exportCsvBtn.addEventListener('click', exportToCsv);

        // Search
        searchButton.addEventListener('click', searchUEs);
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                searchUEs();
            } else {
                searchUEs();
            }
        });

        // Filters
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
            document.querySelectorAll('#ueTable tbody tr[data-id]').forEach(row => {
                attachEventListenersToRow(row);
            });
            
            updateActionButtons();
            
            // Handle responsive layout on load
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
        window.modifierUE = modifierUE;
        window.supprimerUEConfirm = supprimerUEConfirm;
    </script>
</body>
</html>