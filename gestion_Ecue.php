<?php
// gestion_ecue.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Fonction pour adapter la structure de la base de données si nécessaire
function adapterStructureBD($pdo) {
    try {
        // 1. Vérifier et ajouter la colonne id_UE à la table ecue si elle n'existe pas
        $stmt = $pdo->query("SHOW COLUMNS FROM ecue LIKE 'id_UE'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE ecue ADD COLUMN id_UE VARCHAR(20) AFTER credit_ECUE");
            
            // Ajouter la contrainte foreign key
            $pdo->exec("ALTER TABLE ecue ADD CONSTRAINT fk_ecue_ue FOREIGN KEY (id_UE) REFERENCES ue(id_UE) ON DELETE CASCADE ON UPDATE CASCADE");
        }

        // 2. Vérifier si la colonne fk_id_UE existe et la supprimer si elle n'est pas utilisée ou est un doublon
        // Based on your SQL dump, 'fk_id_UE' exists but is not linked as a FK.
        // If it's not actually used for a valid relationship, it might be a remnant or typo.
        // For safety, I'm not auto-deleting it here unless you explicitly confirm its redundancy.
        // If 'id_UE' is the intended FK, 'fk_id_UE' might be dropped.
        // $stmt = $pdo->query("SHOW COLUMNS FROM ecue LIKE 'fk_id_UE'");
        // if ($stmt->rowCount() > 0) {
        //     // Check if it has any actual foreign key constraint or is being used
        //     // This part is complex without full DB schema knowledge, so commenting out auto-drop
        // }

        return true;
    } catch (Exception $e) {
        error_log("Erreur lors de l'adaptation de la structure BD: " . $e->getMessage());
        return false;
    }
}

// Adapter la structure de la base de données si nécessaire
$structureOK = adapterStructureBD($pdo);

// Fonction pour générer le code ECUE automatiquement
function genererCodeECUE($libelleECUE) {
    $mots = explode(' ', trim($libelleECUE));
    $code = '';
    
    foreach ($mots as $mot) {
        // Nettoyer le mot (enlever les apostrophes, etc.)
        $mot = preg_replace('/[^a-zA-ZÀ-ÿ]/', '', $mot);
        if (mb_strlen($mot, 'UTF-8') >= 3) { // Use mb_strlen for multi-byte characters
            $code .= strtoupper(mb_substr($mot, 0, 3, 'UTF-8'));
        } elseif (mb_strlen($mot, 'UTF-8') > 0) {
            $code .= strtoupper($mot);
        }
    }
    
    return empty($code) ? 'ECUE' : $code;
}

// Fonction pour obtenir les crédits d'une UE
function getCreditUE($pdo, $idUE) {
    try {
        $stmt = $pdo->prepare("SELECT credit_UE FROM ue WHERE id_UE = ?");
        $stmt->execute([$idUE]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? intval($result['credit_UE']) : 0;
    } catch (Exception $e) {
        error_log("Error getting UE credits: " . $e->getMessage());
        return 0;
    }
}

// Fonction pour calculer le total des crédits ECUE pour une UE (excluant une ECUE spécifique lors de la modification)
function getTotalCreditsECUE($pdo, $idUE, $excludeECUE = null) {
    try {
        $sql = "SELECT SUM(credit_ECUE) as total FROM ecue WHERE id_UE = ?";
        $params = [$idUE];
        
        if ($excludeECUE) {
            $sql .= " AND id_ECUE != ?";
            $params[] = $excludeECUE;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? intval($result['total']) : 0;
    } catch (Exception $e) {
        return 0;
    }
}

// Traitement AJAX pour les opérations CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();

        // Re-fetch active academic year and UEs for validation in POST requests
        $anneeActive_post = null;
        $stmtAnneeActive_post = $pdo->prepare("SELECT * FROM année_academique WHERE statut = 'active' LIMIT 1");
        $stmtAnneeActive_post->execute();
        $anneeActive_post = $stmtAnneeActive_post->fetch(PDO::FETCH_ASSOC);

        // Check if there's an active year and if it has UEs before proceeding with actions that require them
        $hasActiveYear = ($anneeActive_post !== false);
        $hasUEs = false;
        if ($hasActiveYear) {
            $stmtUEs_post = $pdo->prepare("SELECT COUNT(*) FROM ue WHERE id_Ac = ?");
            $stmtUEs_post->execute([$anneeActive_post['id_Ac']]);
            $hasUEs = ($stmtUEs_post->fetchColumn() > 0);
        }

        // Specific checks for 'create' and 'update' actions
        if (($action == 'create' || $action == 'update')) {
            if (!$hasActiveYear) {
                echo json_encode(['success' => false, 'message' => 'Aucune année académique active trouvée. Impossible de procéder.']);
                exit;
            }
            if (!$hasUEs) {
                echo json_encode(['success' => false, 'message' => 'Aucune UE trouvée pour l\'année académique active. Impossible de procéder.']);
                exit;
            }
        }
        
        switch ($action) {
            case 'create':
                $idUE = $_POST['id_UE'];
                $libelleECUE = trim($_POST['libelleECUE']);
                $creditECUE = intval($_POST['creditECUE']);
                
                // Validation des données
                if (empty($idUE)) {
                    throw new Exception("Veuillez sélectionner une UE");
                }
                
                if (empty($libelleECUE)) {
                    throw new Exception("Le libellé de l'ECUE est obligatoire");
                }
                
                if ($creditECUE < 1 || $creditECUE > 10) { // Max 10 as per previous code
                    throw new Exception("Le nombre de crédits doit être entre 1 et 10");
                }
                
                // Validation des crédits par rapport à l'UE
                $creditUE = getCreditUE($pdo, $idUE);
                $totalCreditsECUE = getTotalCreditsECUE($pdo, $idUE);
                
                if (($totalCreditsECUE + $creditECUE) > $creditUE) {
                    throw new Exception("Le total des crédits ECUE (" . ($totalCreditsECUE + $creditECUE) . ") dépasserait les crédits de l'UE (" . $creditUE . "). Crédits disponibles : " . ($creditUE - $totalCreditsECUE));
                }
                
                // Générer le code ECUE automatiquement
                $codeECUE = genererCodeECUE($libelleECUE);
                
                // Vérifier si le code ECUE existe déjà, ajouter un suffixe si nécessaire
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM ecue WHERE id_ECUE = ?");
                $checkStmt->execute([$codeECUE]);
                
                if ($checkStmt->fetchColumn() > 0) {
                    $counter = 1;
                    $originalCode = $codeECUE;
                    do {
                        $codeECUE = $originalCode . sprintf('%02d', $counter);
                        $checkStmt->execute([$codeECUE]);
                        $counter++;
                    } while ($checkStmt->fetchColumn() > 0);
                }
                
                // Insérer la nouvelle ECUE
                $stmt = $pdo->prepare("INSERT INTO ecue (id_ECUE, lib_ECUE, credit_ECUE, id_UE) VALUES (?, ?, ?, ?)");
                $stmt->execute([$codeECUE, $libelleECUE, $creditECUE, $idUE]);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'ECUE créée avec succès',
                    'data' => [
                        'id_ECUE' => $codeECUE,
                        'lib_ECUE' => $libelleECUE,
                        'credit_ECUE' => $creditECUE,
                        'id_UE' => $idUE
                    ]
                ]);
                break;
                
            case 'update':
                $idECUE = $_POST['id_ECUE'];
                $libelleECUE = trim($_POST['libelleECUE']);
                $creditECUE = intval($_POST['creditECUE']);
                $idUE = $_POST['id_UE'];
                
                // Validation
                if (empty($libelleECUE)) {
                    throw new Exception("Le libellé de l'ECUE est obligatoire");
                }
                
                if ($creditECUE < 1 || $creditECUE > 10) {
                    throw new Exception("Le nombre de crédits doit être entre 1 et 10");
                }
                
                // Validation des crédits (en excluant l'ECUE actuelle)
                $creditUE = getCreditUE($pdo, $idUE);
                $totalCreditsECUE = getTotalCreditsECUE($pdo, $idUE, $idECUE);
                
                if (($totalCreditsECUE + $creditECUE) > $creditUE) {
                    throw new Exception("Le total des crédits ECUE (" . ($totalCreditsECUE + $creditECUE) . ") dépasserait les crédits de l'UE (" . $creditUE . "). Crédits disponibles : " . ($creditUE - $totalCreditsECUE));
                }
                
                $stmt = $pdo->prepare("UPDATE ecue SET lib_ECUE = ?, credit_ECUE = ?, id_UE = ? WHERE id_ECUE = ?");
                $stmt->execute([$libelleECUE, $creditECUE, $idUE, $idECUE]);
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'ECUE modifiée avec succès']);
                break;
                
            case 'delete':
                $idsECUE = json_decode($_POST['ids_ecue'], true);
                
                if (empty($idsECUE)) {
                    throw new Exception('Aucune ECUE sélectionnée');
                }
                
                // Check if ECUEs are used in 'evaluer' table
                $placeholders = implode(',', array_fill(0, count($idsECUE), '?'));
                $checkUsageStmt = $pdo->prepare("SELECT COUNT(*) FROM evaluer WHERE fk_id_ECUE IN ($placeholders)");
                $checkUsageStmt->execute($idsECUE);
                if ($checkUsageStmt->fetchColumn() > 0) {
                    throw new Exception("Impossible de supprimer ces ECUEs car elles sont associées à des évaluations d'étudiants.");
                }

                $stmt = $pdo->prepare("DELETE FROM ecue WHERE id_ECUE IN ($placeholders)");
                $stmt->execute($idsECUE);
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'ECUE(s) supprimée(s) avec succès']);
                break;
                
            case 'get_all_ecues_for_year': // New action to fetch all ECUEs for a given academic year
                $idAc = $_POST['id_Ac'] ?? null;
                if (empty($idAc)) {
                    throw new Exception('ID de l\'année académique manquant.');
                }
                $sql = "SELECT e.*, u.lib_UE, u.credit_UE 
                        FROM ecue e 
                        JOIN ue u ON e.id_UE = u.id_UE 
                        WHERE u.id_Ac = ? 
                        ORDER BY e.id_UE, e.id_ECUE";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$idAc]);
                $ecues = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $ecues]);
                break;
                
            case 'get_ue_credits':
                $idUE = $_POST['id_UE'];
                
                $creditUE = getCreditUE($pdo, $idUE);
                $totalCreditsECUE = getTotalCreditsECUE($pdo, $idUE);
                
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'credit_UE' => $creditUE,
                        'total_credits_ECUE' => $totalCreditsECUE,
                        'credits_disponibles' => $creditUE - $totalCreditsECUE
                    ]
                ]);
                break;
                
            default:
                throw new Exception('Action non reconnue');
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Récupérer l'année académique active (statut = 'active')
$anneeActive = null;
$ues = [];
$ecues = [];

if ($structureOK) {
    try {
        // Récupérer l'année académique active
        $stmtAnneeActive = $pdo->prepare("SELECT * FROM année_academique WHERE statut = 'active' LIMIT 1");
        $stmtAnneeActive->execute();
        $anneeActive = $stmtAnneeActive->fetch(PDO::FETCH_ASSOC);

        // Récupérer les UE et ECUE de l'année active
        if ($anneeActive) {
            $stmtUEs = $pdo->prepare("SELECT * FROM ue WHERE id_Ac = ? ORDER BY id_UE");
            $stmtUEs->execute([$anneeActive['id_Ac']]);
            $ues = $stmtUEs->fetchAll(PDO::FETCH_ASSOC);
            
            $sql = "SELECT e.*, u.lib_UE, u.credit_UE 
                    FROM ecue e 
                    JOIN ue u ON e.id_UE = u.id_UE 
                    WHERE u.id_Ac = ? 
                    ORDER BY e.id_UE, e.id_ECUE";
            $stmtECUEs = $pdo->prepare($sql);
            $stmtECUEs->execute([$anneeActive['id_Ac']]);
            $ecues = $stmtECUEs->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Erreur lors de la récupération des données initiales: " . $e->getMessage());
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
    <title>SYGECOS - Gestion des ECUE</title>
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
            margin-left: auto; /* Pushes action buttons to the right */
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
            min-width: 800px;
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

        /* Infos crédit UE */
        .credits-info {
            background-color: var(--accent-50);
            border: 1px solid var(--accent-200);
            border-radius: var(--radius-md);
            padding: var(--space-3);
            margin-top: var(--space-2);
            font-size: var(--text-sm);
            color: var(--accent-700);
        }

        .credits-info strong {
            color: var(--accent-800);
        }

        /* Loading spinner */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Specific export button colors (optional, if you want different for each) */
        .btn-export.pdf { color: #e74c3c; }
        .btn-export.pdf:hover { background-color: #fcebeb; }
        .btn-export.csv { color: #27ae60; }
        .btn-export.csv:hover { background-color: #eaf7ed; }
        .btn-export.excel { color: #2ecc71; }
        .btn-export.excel:hover { background-color: #e6f9ed; }

        .btn-export i {
            font-size: var(--text-base);
        }

        /* Bouton filtre */
        .btn-filter {
            /* Now styled like btn-secondary */
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
            background-color: var(--gray-200); /* Consistent with btn-secondary */
            color: var(--gray-700); /* Consistent with btn-secondary */
        }

        .btn-filter:hover {
            background-color: var(--gray-300); /* Consistent with btn-secondary */
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
        
        /* Modal de message (unified for all alerts) */
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
                display: none;
            }
            .sidebar-toggle .fa-times {
                display: inline-block;
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
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main class="main-content" id="mainContent">
        <?php include 'topbar.php'; ?>  

        <div class="page-content">
            <div class="page-header">
                <h1 class="page-title-main">Gestion des Éléments Constitutifs d'UE (ECUE)</h1>
                <p class="page-subtitle">Créez et gérez les éléments constitutifs des unités d'enseignement.</p>
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

            <?php if (!$structureOK): ?>
            <div class="alert error" style="display: block;">
                <i class="fas fa-exclamation-triangle"></i>
                Erreur lors de l'initialisation de la base de données. Veuillez vérifier les permissions.
            </div>
            <?php elseif (!$anneeActive): ?>
            <div class="alert warning" id="noActiveYearAlert" style="display: block;">
                <i class="fas fa-info-circle"></i>
                Aucune année académique active trouvée. Veuillez d'abord activer une année académique dans le module de gestion des années académiques.
            </div>
            <?php elseif (empty($ues)): ?>
            <div class="alert warning" id="noUEsAlert" style="display: block;">
                <i class="fas fa-info-circle"></i>
                Aucune UE trouvée pour cette année académique. Veuillez d'abord créer des UE dans le module de gestion des UE.
            </div>
            <?php endif; ?>

            <div class="form-card">
                <h3 class="form-card-title">Créer une nouvelle ECUE</h3>
                <form id="ecueForm">
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
                            <label for="id_UE">Unité d'Enseignement (UE) <span style="color: var(--error-500);">*</span></label>
                            <select id="id_UE" name="id_UE" required <?php echo !$anneeActive || empty($ues) ? 'disabled' : ''; ?>>
                                <option value="">Sélectionnez une UE</option>
                                <?php if (!empty($ues)): ?>
                                    <?php foreach ($ues as $ue): ?>
                                        <option value="<?php echo htmlspecialchars($ue['id_UE']); ?>" data-credits="<?php echo htmlspecialchars($ue['credit_UE']); ?>">
                                            <?php echo htmlspecialchars($ue['id_UE'] . ' - ' . $ue['lib_UE'] . ' (' . $ue['credit_UE'] . ' crédits)'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div id="creditsInfo" class="credits-info" style="display: none;">
                                <strong>Crédits UE:</strong> <span id="creditUE">0</span> | 
                                <strong>Utilisés:</strong> <span id="creditsUtilises">0</span> | 
                                <strong>Disponibles:</strong> <span id="creditsDisponibles">0</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="libelleECUE">Libellé ECUE <span style="color: var(--error-500);">*</span></label>
                            <input type="text" id="libelleECUE" name="libelleECUE" placeholder="Ex: Algorithmique" required <?php echo !$anneeActive || empty($ues) ? 'disabled' : ''; ?>>
                        </div>
                        <div class="form-group">
                            <label for="creditECUE">Crédit ECUE <span style="color: var(--error-500);">*</span></label>
                            <input type="number" id="creditECUE" name="creditECUE" placeholder="Ex: 3"  <?php echo !$anneeActive || empty($ues) ? 'disabled' : ''; ?>>
                            <small id="creditWarning" style="color: var(--error-500); margin-top: var(--space-1); display: none;">
                                <i class="fas fa-exclamation-triangle"></i> Attention : Crédits insuffisants !
                            </small>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="submitBtn" <?php echo !$anneeActive || empty($ues) ? 'disabled' : ''; ?>>
                            <i class="fas fa-plus"></i> <span id="submitText">Ajouter ECUE</span>
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
                    <input type="text" id="searchInput" class="search-input" placeholder="Rechercher une ECUE...">
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
                        Liste des Éléments Constitutifs d'UE
                        <?php if ($anneeActive): ?>
                            <span class="annee-badge">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo htmlspecialchars(formatAnneeAcademique($anneeActive['date_deb'], $anneeActive['date_fin'])); ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <div class="table-actions">
                        <button class="btn btn-secondary" id="filterButton">
                            <i class="fas fa-filter"></i> <span id="filterButtonText">Filtres</span>
                        </button>
                        <button class="btn btn-secondary" id="modifierBtn" disabled>
                            <i class="fas fa-edit"></i> <span class="action-text">Modifier</span>
                        </button>
                        <button class="btn btn-secondary" id="supprimerBtn" disabled>
                            <i class="fas fa-trash-alt"></i> <span class="action-text">Supprimer</span>
                        </button>
                    </div>
                </div>
                <div class="table-container">
                    <table class="data-table" id="ecueTable">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Code ECUE</th>
                                <th>Libellé ECUE</th>
                                <th>Crédits ECUE</th>
                                <th>UE associée</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ecueTableBody">
                            <?php if (empty($ecues)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                    <i class="fas fa-puzzle-piece" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                    Aucune ECUE trouvée pour cette année académique. Créez votre première ECUE en utilisant le formulaire ci-dessus.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($ecues as $ecue): ?>
                                <tr data-id="<?php echo htmlspecialchars($ecue['id_ECUE']); ?>" data-ue-id="<?php echo htmlspecialchars($ecue['id_UE']); ?>">
                                    <td>
                                        <label class="checkbox-container">
                                            <input type="checkbox" value="<?php echo htmlspecialchars($ecue['id_ECUE']); ?>">
                                            <span class="checkmark"></span>
                                        </label>
                                    </td>
                                    <td><?php echo htmlspecialchars($ecue['id_ECUE']); ?></td>
                                    <td><?php echo htmlspecialchars($ecue['lib_ECUE']); ?></td>
                                    <td><?php echo htmlspecialchars($ecue['credit_ECUE']); ?></td>
                                    <td><?php echo htmlspecialchars($ecue['id_UE'] . ' - ' . $ecue['lib_UE']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-button edit" title="Modifier" onclick="modifierECUE('<?php echo htmlspecialchars($ecue['id_ECUE']); ?>')">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                            <button class="action-button delete" title="Supprimer" onclick="supprimerECUE('<?php echo htmlspecialchars($ecue['id_ECUE']); ?>')">
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

<div class="filter-modal" id="filterModal">
    <div class="filter-modal-content">
        <div class="filter-modal-header">
            <h3 class="filter-modal-title">Filtres et Tri des ECUEs</h3>
            <button class="filter-modal-close" id="closeFilterModal">&times;</button>
        </div>
        <div class="filter-group">
            <label>Trier par:</label>
            <div class="filter-option-group">
                <div class="filter-option radio-group">
                    <label>
                        <input type="radio" name="sort_radio" value="all" checked>
                        <i class="fas fa-list"></i> Toutes les ECUE (Ordre initial)
                    </label>
                </div>
                <div class="filter-option radio-group">
                    <label>
                        <input type="radio" name="sort_radio" value="code-asc">
                        <i class="fas fa-sort-alpha-down"></i> Code (A-Z)
                    </label>
                </div>
                <div class="filter-option radio-group">
                    <label>
                        <input type="radio" name="sort_radio" value="code-desc">
                        <i class="fas fa-sort-alpha-up"></i> Code (Z-A)
                    </label>
                </div>
                <div class="filter-option radio-group">
                    <label>
                        <input type="radio" name="sort_radio" value="libelle-asc">
                        <i class="fas fa-sort-alpha-down-alt"></i> Libellé (A-Z)
                    </label>
                </div>
                <div class="filter-option radio-group">
                    <label>
                        <input type="radio" name="sort_radio" value="libelle-desc">
                        <i class="fas fa-sort-alpha-up-alt"></i> Libellé (Z-A)
                    </label>
                </div>
                <div class="filter-option radio-group">
                    <label>
                        <input type="radio" name="sort_radio" value="credits-asc">
                        <i class="fas fa-sort-numeric-down"></i> Crédits (croissant)
                    </label>
                </div>
                <div class="filter-option radio-group">
                    <label>
                        <input type="radio" name="sort_radio" value="credits-desc">
                        <i class="fas fa-sort-numeric-up"></i> Crédits (décroissant)
                    </label>
                </div>
            </div>
        </div>
        
        <div class="filter-group">
            <label>Filtrer par UE:</label>
            <div class="filter-option-group" id="ueFilterRadioGroup">
                <div class="filter-option radio-group">
                    <label>
                        <input type="radio" name="ue_filter_radio" value="all_ues" checked>
                        Toutes les UE
                    </label>
                </div>
                <?php if (!empty($ues)): ?>
                    <?php foreach ($ues as $ue): ?>
                        <div class="filter-option radio-group">
                            <label>
                                <input type="radio" name="ue_filter_radio" value="<?php echo htmlspecialchars($ue['id_UE']); ?>">
                                <?php echo htmlspecialchars($ue['id_UE'] . ' - ' . $ue['lib_UE']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="filter-actions-dropdown">
            <button class="btn btn-secondary" id="resetFilterModalBtn">Réinitialiser</button>
            <button class="btn btn-primary" id="applyFilterModalBtn">Appliquer</button>
        </div>
    </div>
</div>


<div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
    // Initialiser jsPDF
    window.jsPDF = window.jspdf.jsPDF;

    // Variables globales
    let selectedECUEs = new Set();
    let editingECUE = null;
    const anneeActive = <?php echo $anneeActive ? json_encode($anneeActive) : 'null'; ?>;
    const ues = <?php echo json_encode($ues); ?>;
    let initialECUEsData = <?php echo json_encode($ecues); ?>; // Store initial data for filters
    let currentFilterUEValue = 'all_ues'; // Track current UE filter selection
    let currentSortType = 'all'; // Track current sort filter selection

    // Éléments DOM
    const ecueForm = document.getElementById('ecueForm');
    const idUEInput = document.getElementById('id_UE');
    const libelleECUEInput = document.getElementById('libelleECUE');
    const creditECUEInput = document.getElementById('creditECUE');
    const ecueTableBody = document.querySelector('#ecueTable tbody');
    const modifierBtn = document.getElementById('modifierBtn');
    const supprimerBtn = document.getElementById('supprimerBtn');
    const submitBtn = document.getElementById('submitBtn');
    const submitText = document.getElementById('submitText');
    const cancelBtn = document.getElementById('cancelBtn');
    
    const creditsInfo = document.getElementById('creditsInfo');
    const creditUESpan = document.getElementById('creditUE');
    const creditsUtilisesSpan = document.getElementById('creditsUtilises');
    const creditsDisponiblesSpan = document.getElementById('creditsDisponibles');
    const creditWarning = document.getElementById('creditWarning');

    // Éléments de recherche
    const searchInput = document.getElementById('searchInput');
    const searchButton = document.getElementById('searchButton');

    // Éléments d'export
    const exportPdfBtn = document.getElementById('exportPdfBtn');
    const exportExcelBtn = document.getElementById('exportExcelBtn');
    const exportCsvBtn = document.getElementById('exportCsvBtn');

    // Éléments de filtre
    const filterButton = document.getElementById('filterButton');
    const filterButtonText = document.getElementById('filterButtonText'); // Span for filter text
    const filterModal = document.getElementById('filterModal'); // The filter modal
    const closeFilterModalBtn = document.getElementById('closeFilterModal'); // Close button for filter modal
    const ueFilterRadioGroup = document.getElementById('ueFilterRadioGroup'); // The div containing UE radio buttons
    const sortRadioButtons = document.querySelectorAll('input[name="sort_radio"]'); // All sort radio buttons
    const ueFilterRadioButtons = document.querySelectorAll('input[name="ue_filter_radio"]'); // All UE filter radio buttons
    const applyFilterModalBtn = document.getElementById('applyFilterModalBtn');
    const resetFilterModalBtn = document.getElementById('resetFilterModalBtn');


    // Éléments du modal de message (unified for all alerts)
    const messageModal = document.getElementById('messageModal');
    const messageTitle = document.getElementById('messageTitle');
    const messageText = document.getElementById('messageText');
    const messageIcon = document.getElementById('messageIcon');
    const messageButton = document.getElementById('messageButton');
    const messageClose = document.getElementById('messageClose');

    // Sidebar and main content elements for responsiveness
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
    const noActiveYearAlert = document.getElementById('noActiveYearAlert');
    const noUEsAlert = document.getElementById('noUEsAlert');


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

    // Function to make AJAX request
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
            showAlert('Erreur de communication avec le serveur.', 'error');
            throw error;
        }
    }

    // Update credits information for selected UE
    async function updateCreditsInfo() {
        const idUE = idUEInput.value;
        if (!idUE) {
            creditsInfo.style.display = 'none';
            // Also reset the max attribute if no UE is selected
            creditECUEInput.max = 10; 
            validateCredits(); // Re-validate after changing UE
            return;
        }

        try {
            const result = await makeAjaxRequest({
                action: 'get_ue_credits',
                id_UE: idUE
            });

            if (result.success) {
                creditUESpan.textContent = result.data.credit_UE;
                creditsUtilisesSpan.textContent = result.data.total_credits_ECUE;
                creditsDisponiblesSpan.textContent = result.data.credits_disponibles;
                creditsInfo.style.display = 'block';
                
                // Dynamically set the max attribute for creditECUEInput
                const totalUECredits = parseInt(result.data.credit_UE);
                const currentECUECredits = editingECUE ? parseInt(creditECUEInput.getAttribute('data-current-credits') || 0) : 0;
                const availableCreditsForNewECUE = totalUECredits - parseInt(result.data.total_credits_ECUE);

                // When editing, the current ECUE's credits are 'freed up' from the 'total_credits_ECUE' calculation
                // so we add them back to the available amount for the purpose of the max validation.
                creditECUEInput.max = availableCreditsForNewECUE + currentECUECredits;
                
                validateCredits(); // Validate credits after updating info
            } else {
                console.error("Failed to get UE credits:", result.message);
                creditsInfo.style.display = 'none';
                showAlert("Impossible de récupérer les crédits de l'UE: " + result.message, 'error');
            }
        } catch (error) {
            console.error('Error fetching credits:', error);
            showAlert("Erreur lors de la récupération des crédits de l'UE.", 'error');
        }
    }

    // Validate credits in real-time
    function validateCredits() {
        const creditECUE = parseInt(creditECUEInput.value) || 0;
        const maxAllowed = parseInt(creditECUEInput.max); // Use the dynamically set max attribute
        const minAllowed = parseInt(creditECUEInput.min);

        if (isNaN(creditECUE) || creditECUE < minAllowed || creditECUE > maxAllowed) {
            creditWarning.style.display = 'block';
            creditWarning.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Les crédits doivent être entre ${minAllowed} et ${maxAllowed} !`;
            submitBtn.disabled = true;
            return false;
        } else {
            creditWarning.style.display = 'none';
            // Only enable submit if other conditions are also met (e.g., active year, UEs exist)
            const formInputsDisabled = idUEInput.disabled || libelleECUEInput.disabled || creditECUEInput.disabled;
            submitBtn.disabled = formInputsDisabled; 
            return true;
        }
    }

    // Update action buttons (Modifier/Supprimer) state
    function updateActionButtons() {
        if (selectedECUEs.size === 1) {
            modifierBtn.disabled = false;
            supprimerBtn.disabled = false;
        } else if (selectedECUEs.size > 1) {
            modifierBtn.disabled = true; // Disable modify for multiple selections
            supprimerBtn.disabled = false;
        } else {
            modifierBtn.disabled = true;
            supprimerBtn.disabled = true;
        }
    }

    // Add a row to the table
    function addRowToTable(ecue) {
        // Remove the "Aucune ECUE trouvée" message if it exists
        const emptyRow = ecueTableBody.querySelector('td[colspan="6"]');
        if (emptyRow) {
            emptyRow.closest('tr').remove();
        }

        const ueAssociee = ues.find(ue => ue.id_UE == ecue.id_UE) || {id_UE: '', lib_UE: 'N/A'}; // Use == for type coercion

        const newRow = ecueTableBody.insertRow();
        newRow.setAttribute('data-id', ecue.id_ECUE);
        newRow.setAttribute('data-ue-id', ecue.id_UE); // Add UE ID for filtering
        newRow.innerHTML = `
            <td>
                <label class="checkbox-container">
                    <input type="checkbox" value="${ecue.id_ECUE}">
                    <span class="checkmark"></span>
                </label>
            </td>
            <td>${ecue.id_ECUE}</td>
            <td>${ecue.lib_ECUE}</td>
            <td>${ecue.credit_ECUE}</td>
            <td>${ueAssociee.id_UE} - ${ueAssociee.lib_UE}</td>
            <td>
                <div class="action-buttons">
                    <button class="action-button edit" title="Modifier" onclick="modifierECUE('${ecue.id_ECUE}')">
                        <i class="fas fa-pencil-alt"></i>
                    </button>
                    <button class="action-button delete" title="Supprimer" onclick="supprimerECUE('${ecue.id_ECUE}')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        `;
        attachEventListenersToRow(newRow);
    }

    // Attach event listeners to rows (checkboxes)
    function attachEventListenersToRow(row) {
        const checkbox = row.querySelector('input[type="checkbox"]');
        
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                selectedECUEs.add(this.value);
            } else {
                selectedECUEs.delete(this.value);
            }
            updateActionButtons();
        });
    }

    // Form submission
    ecueForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Re-check conditions that might disable the form
        if (!anneeActive) {
            showAlert('Aucune année académique active. Impossible de créer/modifier une ECUE.', 'error');
            return;
        }
        if (ues.length === 0) {
            showAlert('Aucune UE disponible pour l\'année académique active. Impossible de créer/modifier une ECUE.', 'error');
            return;
        }

        if (!validateCredits()) {
            showAlert('Erreur de validation des crédits. Veuillez corriger.', 'error');
            return;
        }

        const formData = new FormData(this);
        const data = {
            action: editingECUE ? 'update' : 'create',
            id_UE: formData.get('id_UE'),
            libelleECUE: formData.get('libelleECUE'),
            creditECUE: formData.get('creditECUE')
        };

        if (editingECUE) {
            data.id_ECUE = editingECUE;
        }

        try {
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;

            const result = await makeAjaxRequest(data);

            if (result.success) {
                showAlert(result.message, 'success');
                resetForm(); // Reset form after successful operation
                await loadECUEs(); // Reload the main table to reflect changes and re-attach listeners
                await updateCreditsInfo(); // Update credit info for the selected UE
            } else {
                showAlert(result.message, 'error');
            }
        } catch (error) {
            showAlert('Erreur lors de l\'enregistrement.', 'error');
        } finally {
            submitBtn.classList.remove('loading');
            // Re-enable based on form initial state if no error
            const formInputsDisabled = !anneeActive || ues.length === 0;
            submitBtn.disabled = formInputsDisabled; 
        }
    });

    // Reset form
    function resetForm() {
        editingECUE = null;
        submitText.textContent = 'Ajouter ECUE';
        submitBtn.innerHTML = '<i class="fas fa-plus"></i> Ajouter ECUE';
        ecueForm.reset();
        creditECUEInput.removeAttribute('data-current-credits');
        creditWarning.style.display = 'none';
        // Reset UE selection and hide credits info
        idUEInput.value = '';
        creditsInfo.style.display = 'none';
        // Re-enable submit button if conditions allow
        const formInputsDisabled = !anneeActive || ues.length === 0;
        submitBtn.disabled = formInputsDisabled;
        selectedECUEs.clear();
        updateActionButtons();
        // Uncheck all checkboxes
        document.querySelectorAll('#ecueTable tbody input[type="checkbox"]').forEach(cb => cb.checked = false);
    }

    // Cancel button
    cancelBtn.addEventListener('click', function() {
        resetForm();
    });

    // Modify ECUE
    function modifierECUE(idECUE) {
        const row = document.querySelector(`tr[data-id="${idECUE}"]`);
        if (row) {
            editingECUE = idECUE;
            libelleECUEInput.value = row.cells[2].textContent;
            const currentCredits = row.cells[3].textContent;
            creditECUEInput.value = currentCredits;
            creditECUEInput.setAttribute('data-current-credits', currentCredits);
            
            const ueId = row.getAttribute('data-ue-id'); // Get UE ID from data attribute
            idUEInput.value = ueId;
            
            submitText.textContent = 'Mettre à jour';
            submitBtn.innerHTML = '<i class="fas fa-edit"></i> Mettre à jour';
            
            updateCreditsInfo(); // Update credits info based on selected UE
            // Clear other selections and select only the current one
            selectedECUEs.clear();
            document.querySelectorAll('#ecueTable tbody input[type="checkbox"]').forEach(cb => cb.checked = false);
            row.querySelector('input[type="checkbox"]').checked = true;
            selectedECUEs.add(idECUE);
            updateActionButtons();

            document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
        }
    }

    // Delete ECUE (single)
    async function supprimerECUE(idECUE) {
        const row = document.querySelector(`tr[data-id="${idECUE}"]`);
        if (row) {
            const libelleECUE = row.cells[2].textContent;
            
            if (confirm(`Êtes-vous sûr de vouloir supprimer l'ECUE "${libelleECUE}" (${idECUE}) ?\n\nCette action ne peut être annulée et pourrait impacter les évaluations liées.`)) {
                try {
                    const result = await makeAjaxRequest({
                        action: 'delete',
                        ids_ecue: JSON.stringify([idECUE])
                    });

                    if (result.success) {
                        showAlert('ECUE supprimée avec succès', 'success');
                        await loadECUEs(); // Reload table after deletion
                        resetForm(); // Reset form and selections
                    } else {
                        showAlert(result.message, 'error');
                    }
                } catch (error) {
                    showAlert('Erreur lors de la suppression.', 'error');
                }
            }
        }
    }

    // Global modify button
    modifierBtn.addEventListener('click', function() {
        if (selectedECUEs.size === 1) {
            const idECUE = Array.from(selectedECUEs)[0];
            modifierECUE(idECUE);
        } else {
            showAlert('Veuillez sélectionner une seule ECUE à modifier.', 'warning');
        }
    });

    // Global delete button
    supprimerBtn.addEventListener('click', async function() {
        if (selectedECUEs.size === 0) {
            showAlert("Aucune ECUE sélectionnée pour la suppression.", 'warning');
            return;
        }

        const idsArray = Array.from(selectedECUEs);
        
        if (confirm(`Êtes-vous sûr de vouloir supprimer ${idsArray.length} ECUE(s) sélectionnée(s) ?\n\nCette action ne peut être annulée et pourrait impacter les évaluations liées.`)) {
            try {
                const result = await makeAjaxRequest({
                    action: 'delete',
                    ids_ecue: JSON.stringify(idsArray)
                });

                if (result.success) {
                    showAlert('ECUE(s) supprimée(s) avec succès', 'success');
                    await loadECUEs(); // Reload table after deletion
                    resetForm(); // Reset form and selections
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la suppression', 'error');
            }
        }
    });

    // Handle UE selection change (for form)
    idUEInput.addEventListener('change', async function() {
        await updateCreditsInfo();
        // Reset libelle and credit when UE changes
        libelleECUEInput.value = '';
        creditECUEInput.value = '';
        creditECUEInput.removeAttribute('data-current-credits');
        validateCredits(); // Re-validate after clearing fields
    });

    // Real-time credit validation
    creditECUEInput.addEventListener('input', validateCredits);

    // Search functionality
    searchButton.addEventListener('click', searchECUEs);
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchECUEs();
        }
    });
    searchInput.addEventListener('input', searchECUEs); // Live search

    function searchECUEs() {
        // When searching, clear any active filter selections visually and logically
        currentFilterUEValue = 'all_ues';
        currentSortType = 'all';

        // Reset radio buttons in the filter modal
        document.querySelector('input[name="sort_radio"][value="all"]').checked = true;
        document.querySelector('input[name="ue_filter_radio"][value="all_ues"]').checked = true;

        // Apply filters and search
        applyFiltersAndSort('all', 'all_ues'); // Pass initial "all" filters
        updateFilterButtonText(); // Update the filter button text
    }

    // Load all ECUEs for the current active academic year
    async function loadECUEs() {
        if (!anneeActive || !anneeActive.id_Ac) {
            console.log('No active academic year ID, cannot load ECUEs.');
            ecueTableBody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                        <i class="fas fa-puzzle-piece" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                        Aucune ECUE disponible car aucune année académique active.
                    </td>
                </tr>
            `;
            return;
        }
        try {
            const result = await makeAjaxRequest({
                action: 'get_all_ecues_for_year',
                id_Ac: anneeActive.id_Ac
            });
            
            if (result.success) {
                initialECUEsData = result.data; // Update cached data
                applyFiltersAndSort(currentSortType, currentFilterUEValue); // Render table with current filters applied
            } else {
                console.error("Failed to load all ECUEs:", result.message);
                showAlert("Erreur lors du chargement des ECUEs: " + result.message, 'error');
            }
        } catch (error) {
            console.error('Error loading ECUEs:', error);
            showAlert("Erreur réseau lors du chargement des ECUEs.", 'error');
        }
    }

    // Render table rows from an array of ECUE objects
    function renderTable(ecuesToRender) {
        ecueTableBody.innerHTML = ''; // Clear existing rows
        selectedECUEs.clear(); // Clear selections
        updateActionButtons(); // Update buttons

        if (ecuesToRender.length === 0) {
            ecueTableBody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                        <i class="fas fa-puzzle-piece" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                        Aucune ECUE trouvée pour cette année académique. Créez votre première ECUE en utilisant le formulaire ci-dessus.
                    </td>
                </tr>
            `;
        } else {
            ecuesToRender.forEach(ecue => {
                // Ensure UE data is correctly embedded or fetched
                const ueAssociee = ues.find(ueItem => ueItem.id_UE == ecue.id_UE) || {id_UE: '', lib_UE: 'N/A'};
                const newRow = ecueTableBody.insertRow();
                newRow.setAttribute('data-id', ecue.id_ECUE);
                newRow.setAttribute('data-ue-id', ecue.id_UE);
                newRow.innerHTML = `
                    <td>
                        <label class="checkbox-container">
                            <input type="checkbox" value="${ecue.id_ECUE}">
                            <span class="checkmark"></span>
                        </label>
                    </td>
                    <td>${ecue.id_ECUE}</td>
                    <td>${ecue.lib_ECUE}</td>
                    <td>${ecue.credit_ECUE}</td>
                    <td>${ueAssociee.id_UE} - ${ueAssociee.lib_UE}</td>
                    <td>
                        <div class="action-buttons">
                            <button class="action-button edit" title="Modifier" onclick="modifierECUE('${ecue.id_ECUE}')">
                                <i class="fas fa-pencil-alt"></i>
                            </button>
                            <button class="action-button delete" title="Supprimer" onclick="supprimerECUE('${ecue.id_ECUE}')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                `;
                attachEventListenersToRow(newRow);
            });
        }
    }


    // Get data for export
    function getExportData() {
        const headers = ['Code ECUE', 'Libellé ECUE', 'Crédits ECUE', 'UE associée'];
        const rows = [];
        
        const visibleRows = ecueTableBody.querySelectorAll('tr[data-id]');
        visibleRows.forEach(row => {
            if (row.style.display !== 'none') { // Only export visible rows (after search/filter)
                rows.push([
                    row.cells[1].textContent, // Code ECUE
                    row.cells[2].textContent, // Libellé ECUE
                    row.cells[3].textContent, // Crédits ECUE
                    row.cells[4].textContent  // UE associée
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
                showAlert("Aucune donnée visible à exporter.", 'warning');
                return;
            }
            
            const doc = new jsPDF();
            doc.setFontSize(18);
            doc.text('Liste des ECUE', 14, 15);
            doc.setFontSize(10);
            doc.text(`Exporté le: ${new Date().toLocaleDateString()}`, 14, 22);
            doc.autoTable({
                head: [headers],
                body: rows,
                startY: 25,
                styles: { fontSize: 8 },
                headStyles: { fillColor: [59, 130, 246] }
            });
            
            doc.save(`ECUE_${new Date().toISOString().slice(0,10)}.pdf`);
            showAlert("Export PDF réussi !", 'success');
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
                showAlert("Aucune donnée visible à exporter.", 'warning');
                return;
            }
            
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
            XLSX.utils.book_append_sheet(wb, ws, "ECUE");
            XLSX.writeFile(wb, `ECUE_${new Date().toISOString().slice(0,10)}.xlsx`);
            showAlert("Export Excel réussi !", 'success');
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
                showAlert("Aucune donnée visible à exporter.", 'warning');
                return;
            }
            
            let csvContent = headers.map(h => `"${h}"`).join(";") + "\n";
            rows.forEach(row => csvContent += row.map(cell => `"${cell}"`).join(";") + "\n");
            
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement("a");
            const url = URL.createObjectURL(blob);
            
            link.setAttribute("href", url);
            link.setAttribute("download", `ECUE_${new Date().toISOString().slice(0,10)}.csv`);
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

    // Filter modal handling
    filterButton.addEventListener('click', function(event) {
        event.stopPropagation(); // Prevent clicks inside dropdown from closing it immediately
        filterModal.style.display = 'flex'; // Show the modal
    });

    closeFilterModalBtn.addEventListener('click', function() {
        filterModal.style.display = 'none'; // Close the modal
    });

    filterModal.addEventListener('click', function(e) {
        if (e.target === filterModal) {
            filterModal.style.display = 'none'; // Close if click outside content
        }
    });

    // Store selected sort filter and UE filter from modal when Apply is clicked
    applyFilterModalBtn.addEventListener('click', function() {
        const selectedSortRadio = document.querySelector('input[name="sort_radio"]:checked');
        if (selectedSortRadio) {
            currentSortType = selectedSortRadio.value;
        }

        const selectedUEFilterRadio = document.querySelector('input[name="ue_filter_radio"]:checked');
        if (selectedUEFilterRadio) {
            currentFilterUEValue = selectedUEFilterRadio.value;
        }

        applyFiltersAndSort(currentSortType, currentFilterUEValue);
        filterModal.style.display = 'none'; // Close modal after applying
        updateFilterButtonText(); // Update filter button text
    });

    // Reset button inside filter modal
    resetFilterModalBtn.addEventListener('click', function() {
        currentSortType = 'all'; // Reset sort to default
        currentFilterUEValue = 'all_ues'; // Reset UE filter to all
        searchInput.value = ''; // Clear search input
        
        // Visually reset sort radio buttons
        document.querySelector('input[name="sort_radio"][value="all"]').checked = true;
        // Visually reset UE radio buttons
        document.querySelector('input[name="ue_filter_radio"][value="all_ues"]').checked = true;

        applyFiltersAndSort('all', 'all_ues'); // Apply reset filters
        filterModal.style.display = 'none'; // Close modal
        updateFilterButtonText(); // Update filter button text
        showAlert('Filtres et recherche réinitialisés.', 'info');
    });


    // Apply all filters and sort options
    function applyFiltersAndSort(sortType = 'all', ueFilterValue = '') {
        let filteredECUEs = [...initialECUEsData]; // Start with full data

        // Apply UE filter if selected
        if (ueFilterValue && ueFilterValue !== 'all_ues') {
            filteredECUEs = filteredECUEs.filter(ecue => ecue.id_UE == ueFilterValue);
        }

        // Apply search filter (if search input has value)
        const searchTerm = searchInput.value.toLowerCase();
        if (searchTerm) {
            filteredECUEs = filteredECUEs.filter(ecue => 
                ecue.id_ECUE.toLowerCase().includes(searchTerm) ||
                ecue.lib_ECUE.toLowerCase().includes(searchTerm) ||
                (ecue.lib_UE || '').toLowerCase().includes(searchTerm) || // Ensure lib_UE is not null
                (ecue.id_UE + ' - ' + (ecue.lib_UE || '')).toLowerCase().includes(searchTerm)
            );
        }

        // Apply sorting
        filteredECUEs.sort((a, b) => {
            switch (sortType) {
                case 'code-asc': return a.id_ECUE.localeCompare(b.id_ECUE);
                case 'code-desc': return b.id_ECUE.localeCompare(a.id_ECUE);
                case 'libelle-asc': return a.lib_ECUE.localeCompare(b.lib_ECUE);
                case 'libelle-desc': return b.lib_ECUE.localeCompare(a.lib_ECUE);
                case 'credits-asc': return parseInt(a.credit_ECUE) - parseInt(b.credit_ECUE);
                case 'credits-desc': return parseInt(b.credit_ECUE) - parseInt(a.credit_ECUE);
                default: return 0; // 'all' or no specific sort
            }
        });

        renderTable(filteredECUEs);
        updateFilterButtonText(); // Update the filter button text after applying
    }

    // Update filter button text based on active filters
    function updateFilterButtonText() {
        let activeFiltersCount = 0;
        if (currentSortType !== 'all') {
            activeFiltersCount++;
        }
        if (currentFilterUEValue !== 'all_ues') {
            activeFiltersCount++;
        }
        if (searchInput.value.trim() !== '') { // Check if search input has text
            activeFiltersCount++;
        }

        if (activeFiltersCount > 0) {
            filterButtonText.textContent = `Filtres (${activeFiltersCount} actifs)`;
            filterButton.classList.add('btn-active-filter'); // Add a class for active filter visual
            // Add custom style for active filter. It's usually a background/border change.
            filterButton.style.backgroundColor = 'var(--accent-200)'; 
            filterButton.style.color = 'var(--accent-800)';
        } else {
            filterButtonText.textContent = 'Filtres';
            filterButton.classList.remove('btn-active-filter'); // Remove class
            // Reset to default button style
            filterButton.style.backgroundColor = 'var(--gray-200)';
            filterButton.style.color = 'var(--gray-700)';
        }
    }


    // Initialisation
    document.addEventListener('DOMContentLoaded', async function() {
        // Initial check for active year and UEs to enable/disable form elements
        const hasActiveYearAndUEs = anneeActive && ues.length > 0;
        submitBtn.disabled = !hasActiveYearAndUEs;
        idUEInput.disabled = !hasActiveYearAndUEs;
        libelleECUEInput.disabled = !hasActiveYearAndUEs;
        creditECUEInput.disabled = !hasActiveYearAndUEs;

        // Display initial alerts if conditions not met
        if (!anneeActive) {
            noActiveYearAlert.style.display = 'block';
        } else {
            noActiveYearAlert.style.display = 'none';
            if (ues.length === 0) {
                noUEsAlert.style.display = 'block';
            } else {
                noUEsAlert.style.display = 'none';
            }
        }

        // Ensure ECUEs are loaded and table rendered
        await loadECUEs(); // This will also call renderTable

        // Attach event listeners to existing rows rendered by PHP or loadECUEs
        document.querySelectorAll('#ecueTable tbody tr[data-id]').forEach(row => {
            attachEventListenersToRow(row);
        });
        
        updateActionButtons();
        
        // Load credit info if an UE is initially selected
        if (idUEInput.value) {
            await updateCreditsInfo();
        }

        // Responsive sidebar handling
        handleResponsiveLayout();
        window.addEventListener('resize', handleResponsiveLayout);

        // Set initial active filter option for sorting (default to "all")
        document.querySelector('input[name="sort_radio"][value="all"]').checked = true;
        // Set initial active filter option for UE (default to "all_ues")
        document.querySelector('input[name="ue_filter_radio"][value="all_ues"]').checked = true;

        updateFilterButtonText(); // Set initial filter button text
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
                if (barsIcon) barsIcon.style.display = 'inline-block';
                if (timesIcon) timesIcon.style.display = 'none';
            } else {
                if (barsIcon) barsIcon.style.display = 'inline-block'; // Or 'none' if sidebar is always open on desktop
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

    // Expose functions to global scope for inline onclicks
    window.modifierECUE = modifierECUE;
    window.supprimerECUE = supprimerECUE;
</script>
</body>
</html>