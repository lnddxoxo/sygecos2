<?php
// gestion_utilisateur.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Traitement AJAX pour les opérations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        switch ($action) {
            case 'filter':
                $idType = $_POST['id_type'] ?? null;
                $idGU = $_POST['id_GU'] ?? null;
                
                if (empty($idType) || empty($idGU)) {
                    echo json_encode(['success' => false, 'message' => 'Veuillez sélectionner un type et un groupe']);
                    exit;
                }
                
                // D'abord vérifier que ce type et groupe sont liés
                $checkTypeGroupe = $pdo->prepare("SELECT COUNT(*) FROM type_groupe WHERE id_type = ? AND id_GU = ?");
                $checkTypeGroupe->execute([$idType, $idGU]);
                if ($checkTypeGroupe->fetchColumn() == 0) {
                    echo json_encode(['success' => false, 'message' => 'Type et groupe non compatibles']);
                    exit;
                }
                
                // Récupérer les utilisateurs selon le type et groupe sélectionnés
                $sql = "
                    SELECT 
                        pa.id_pers as id_original,
                        'personnel' as type_table,
                        pa.nom_pers as nom,
                        pa.prenoms_pers as prenom,
                        pa.email_pers as email,
                        pa.telephone as telephone,
                        pa.fk_id_util,
                        u.login_util,
                        ? as lib_GU,
                        ? as lib_type
                    FROM personnel_admin pa
                    LEFT JOIN utilisateur u ON pa.fk_id_util = u.id_util
                    LEFT JOIN posseder p ON pa.fk_id_util = p.fk_id_util AND p.fk_id_GU = ?
                    WHERE p.fk_id_GU IS NOT NULL AND (u.login_util IS NULL OR u.id_util IS NULL)
                    
                    UNION
                    
                    SELECT 
                        e.id_ens as id_original,
                        'enseignant' as type_table,
                        e.nom_ens as nom,
                        e.prenom_ens as prenom,
                        e.email as email,
                        '' as telephone,
                        e.fk_id_util,
                        u.login_util,
                        ? as lib_GU,
                        ? as lib_type
                    FROM enseignant e
                    LEFT JOIN utilisateur u ON e.fk_id_util = u.id_util
                    LEFT JOIN posseder p ON e.fk_id_util = p.fk_id_util AND p.fk_id_GU = ?
                    WHERE p.fk_id_GU IS NOT NULL AND (u.login_util IS NULL OR u.id_util IS NULL)
                    
                    UNION
                    
                    SELECT 
                        et.num_etu as id_original,
                        'etudiant' as type_table,
                        et.nom_etu as nom,
                        et.prenoms_etu as prenom,
                        et.email_etu as email,
                        '' as telephone,
                        et.fk_id_util,
                        u.login_util,
                        ? as lib_GU,
                        ? as lib_type
                    FROM etudiant et
                    LEFT JOIN utilisateur u ON et.fk_id_util = u.id_util
                    LEFT JOIN posseder p ON et.fk_id_util = p.fk_id_util AND p.fk_id_GU = ?
                    WHERE p.fk_id_GU IS NOT NULL AND (u.login_util IS NULL OR u.id_util IS NULL)
                    
                    ORDER BY nom, prenom
                ";
                
                // Récupérer les libellés
                $stmtGU = $pdo->prepare("SELECT lib_GU FROM groupe_utilisateur WHERE id_GU = ?");
                $stmtGU->execute([$idGU]);
                $libGU = $stmtGU->fetchColumn();
                
                $stmtType = $pdo->prepare("SELECT lib_type FROM type_utilisateur WHERE id_type = ?");
                $stmtType->execute([$idType]);
                $libType = $stmtType->fetchColumn();
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$libGU, $libType, $idGU, $libGU, $libType, $idGU, $libGU, $libType, $idGU]);
                $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $utilisateurs]);
                break;
                
            case 'generate_credentials':
                $selectedUsers = json_decode($_POST['selected_users'], true);
                $idGU = $_POST['id_GU'] ?? null;
                
                if (empty($selectedUsers) || empty($idGU)) {
                    throw new Exception("Sélection invalide");
                }
                
                $generatedCount = 0;
                $generatedUsers = [];
                
                foreach ($selectedUsers as $user) {
                    $idOriginal = $user['id_original'];
                    $typeTable = $user['type_table'];
                    
                    // Générer un login unique
                    $baseLogin = strtolower(substr($user['prenom'], 0, 1) . $user['nom']);
                    $baseLogin = preg_replace('/[^a-z0-9]/', '', $baseLogin);
                    
                    // Vérifier l'unicité du login
                    $login = $baseLogin;
                    $counter = 1;
                    while (true) {
                        $checkLogin = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE login_util = ?");
                        $checkLogin->execute([$login]);
                        if ($checkLogin->fetchColumn() == 0) break;
                        $login = $baseLogin . $counter;
                        $counter++;
                    }
                    
                    // Générer un mot de passe aléatoire
                    $password = generateRandomPassword();
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Créer ou mettre à jour l'utilisateur
                    if ($user['fk_id_util']) {
                        // Mettre à jour l'utilisateur existant
                        $stmt = $pdo->prepare("UPDATE utilisateur SET login_util = ?, mdp_util = ?, temp_password = ? WHERE id_util = ?");
                        $stmt->execute([$login, $hashedPassword, $password, $user['fk_id_util']]);
                        $idUtil = $user['fk_id_util'];
                    } else {
                        // Créer un nouvel utilisateur
                        $stmtMaxId = $pdo->query("SELECT COALESCE(MAX(id_util), 0) + 1 as next_id FROM utilisateur");
                        $nextId = $stmtMaxId->fetchColumn();
                        
                        $stmt = $pdo->prepare("INSERT INTO utilisateur (id_util, login_util, mdp_util, temp_password) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$nextId, $login, $hashedPassword, $password]);
                        $idUtil = $nextId;
                        
                        // Mettre à jour la référence dans la table appropriée
                        switch ($typeTable) {
                            case 'personnel':
                                $stmt = $pdo->prepare("UPDATE personnel_admin SET fk_id_util = ? WHERE id_pers = ?");
                                $stmt->execute([$idUtil, $idOriginal]);
                                break;
                            case 'enseignant':
                                $stmt = $pdo->prepare("UPDATE enseignant SET fk_id_util = ? WHERE id_ens = ?");
                                $stmt->execute([$idUtil, $idOriginal]);
                                break;
                            case 'etudiant':
                                $stmt = $pdo->prepare("UPDATE etudiant SET fk_id_util = ? WHERE num_etu = ?");
                                $stmt->execute([$idUtil, $idOriginal]);
                                break;
                        }
                        
                        // Créer la liaison dans posseder si elle n'existe pas
                        $checkPosseder = $pdo->prepare("SELECT COUNT(*) FROM posseder WHERE fk_id_util = ?");
                        $checkPosseder->execute([$idUtil]);
                        if ($checkPosseder->fetchColumn() == 0) {
                            $stmtMaxPoss = $pdo->query("SELECT COALESCE(MAX(id_poss), 0) + 1 as next_id FROM posseder");
                            $nextPossId = $stmtMaxPoss->fetchColumn();
                            
                            $stmtPosseder = $pdo->prepare("INSERT INTO posseder (id_poss, fk_id_util, fk_id_GU, dte_poss) VALUES (?, ?, ?, CURDATE())");
                            $stmtPosseder->execute([$nextPossId, $idUtil, $idGU]);
                        }
                    }
                    
                    $generatedUsers[] = [
                        'nom' => $user['nom'],
                        'prenom' => $user['prenom'],
                        'email' => $user['email'],
                        'login' => $login,
                        'password' => $password
                    ];
                    
                    $generatedCount++;
                }
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => "$generatedCount identifiant(s) généré(s) avec succès",
                    'data' => $generatedUsers
                ]);
                break;
                
            case 'get_with_credentials':
                // Récupérer tous les utilisateurs qui ont déjà des identifiants
                $stmt = $pdo->query("
                    SELECT 
                        u.id_util,
                        u.login_util,
                        u.temp_password,
                        CASE 
                            WHEN pa.id_pers IS NOT NULL THEN pa.nom_pers
                            WHEN e.id_ens IS NOT NULL THEN e.nom_ens
                            WHEN et.num_etu IS NOT NULL THEN et.nom_etu
                        END as nom,
                        CASE 
                            WHEN pa.id_pers IS NOT NULL THEN pa.prenoms_pers
                            WHEN e.id_ens IS NOT NULL THEN e.prenom_ens
                            WHEN et.num_etu IS NOT NULL THEN et.prenoms_etu
                        END as prenom,
                        CASE 
                            WHEN pa.id_pers IS NOT NULL THEN pa.email_pers
                            WHEN e.id_ens IS NOT NULL THEN e.email
                            WHEN et.num_etu IS NOT NULL THEN et.email_etu
                        END as email,
                        gu.lib_GU,
                        tu.lib_type
                    FROM utilisateur u
                    LEFT JOIN personnel_admin pa ON u.id_util = pa.fk_id_util
                    LEFT JOIN enseignant e ON u.id_util = e.fk_id_util
                    LEFT JOIN etudiant et ON u.id_util = et.fk_id_util
                    LEFT JOIN posseder p ON u.id_util = p.fk_id_util
                    LEFT JOIN groupe_utilisateur gu ON p.fk_id_GU = gu.id_GU
                    LEFT JOIN type_groupe tg ON gu.id_GU = tg.id_GU
                    LEFT JOIN type_utilisateur tu ON tg.id_type = tu.id_type
                    WHERE u.login_util IS NOT NULL AND u.mdp_util IS NOT NULL
                    ORDER BY nom, prenom
                ");
                $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $utilisateurs]);
                break;
                
            case 'update_credentials':
                $idUtil = $_POST['id_util'] ?? null;
                $newLogin = trim($_POST['new_login']) ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                
                if (empty($idUtil) || empty($newLogin)) {
                    throw new Exception("Données manquantes pour la modification");
                }
                
                // Vérifier l'unicité du nouveau login
                $checkLogin = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE login_util = ? AND id_util != ?");
                $checkLogin->execute([$newLogin, $idUtil]);
                if ($checkLogin->fetchColumn() > 0) {
                    throw new Exception("Ce login existe déjà");
                }
                
                // Mettre à jour les identifiants
                if (!empty($newPassword)) {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE utilisateur SET login_util = ?, mdp_util = ?, temp_password = ? WHERE id_util = ?");
                    $stmt->execute([$newLogin, $hashedPassword, $newPassword, $idUtil]);
                } else {
                    $stmt = $pdo->prepare("UPDATE utilisateur SET login_util = ? WHERE id_util = ?");
                    $stmt->execute([$newLogin, $idUtil]);
                }
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Identifiants modifiés avec succès']);
                break;
                
            case 'delete_credentials':
                $idsUtilisateurs = json_decode($_POST['ids_utilisateurs'], true);
                
                foreach ($idsUtilisateurs as $idUtil) {
                    // Remettre à NULL les identifiants
                    $stmt = $pdo->prepare("UPDATE utilisateur SET login_util = NULL, mdp_util = NULL, temp_password = NULL WHERE id_util = ?");
                    $stmt->execute([$idUtil]);
                }
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Identifiant(s) supprimé(s) avec succès']);
                break;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Fonction pour générer un mot de passe aléatoire
function generateRandomPassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    return substr(str_shuffle($chars), 0, $length);
}

// Récupérer les types d'utilisateur et groupes pour les listes déroulantes
$typesUtilisateur = [];
$groupesUtilisateur = [];

try {
    // Vérifier si la colonne temp_password existe, sinon la créer
    $checkColumn = $pdo->query("SHOW COLUMNS FROM utilisateur LIKE 'temp_password'");
    if ($checkColumn->rowCount() == 0) {
        $pdo->exec("ALTER TABLE utilisateur ADD COLUMN temp_password VARCHAR(255) DEFAULT NULL");
    }
    
    // Récupérer tous les types d'utilisateur
    $stmtTypes = $pdo->query("SELECT id_type, lib_type FROM type_utilisateur ORDER BY lib_type");
    $typesUtilisateur = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer tous les groupes d'utilisateur
    $stmtGroupes = $pdo->query("SELECT id_GU, lib_GU FROM groupe_utilisateur ORDER BY lib_GU");
    $groupesUtilisateur = $stmtGroupes->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Erreur lors de la récupération des données: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Génération d'Identifiants</title>
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

        .filter-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: var(--space-6);
        }

        .filter-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: var(--space-6);
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: var(--space-4);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            align-items: end;
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

        .form-group select {
            padding: var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            color: var(--gray-800);
            transition: all var(--transition-fast);
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--accent-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
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

        .btn-success {
            background-color: var(--success-500);
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            background-color: var(--success-600);
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
            margin-bottom: var(--space-8);
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
            min-width: 900px; /* Adjusted for more columns */
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

        /* Section separator */
        .section-separator {
            margin: var(--space-16) 0;
            text-align: center;
            position: relative;
        }

        .section-separator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--gray-300);
        }

        .section-separator span {
            background: var(--gray-50);
            padding: 0 var(--space-4);
            color: var(--gray-500);
            font-weight: 600;
            text-transform: uppercase;
            font-size: var(--text-sm);
            letter-spacing: 0.05em;
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
            
            .filter-grid {
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
            
            .filter-card,
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
            
            .filter-grid .form-group:last-child {
                grid-column: span 1;
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
                    <h1 class="page-title-main">Génération d'Identifiants Utilisateur</h1>
                    <p class="page-subtitle">Sélectionnez un type et un groupe pour générer les identifiants des utilisateurs correspondants.</p>
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

                <div class="filter-card">
                    <h3 class="filter-title">Sélection Type et Groupe Utilisateur</h3>
                    <form id="selectionForm">
                        <div class="filter-grid">
                            <div class="form-group">
                                <label for="filter_type">Type d'Utilisateur <span style="color: var(--error-500);">*</span></label>
                                <select id="filter_type" name="filter_type" required>
                                    <option value="">Sélectionner un type</option>
                                    <?php foreach ($typesUtilisateur as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type['id_type']); ?>">
                                            <?php echo htmlspecialchars($type['lib_type']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filter_groupe">Groupe d'Utilisateur <span style="color: var(--error-500);">*</span></label>
                                <select id="filter_groupe" name="filter_groupe" required disabled>
                                    <option value="">Sélectionner d'abord un type</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Afficher la liste
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="search-bar" id="searchBarSansIdentifiants" style="display: none;">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInputSans" class="search-input" placeholder="Rechercher un utilisateur...">
                    </div>
                    <button class="search-button" id="searchButtonSans">
                        <i class="fas fa-search"></i> Rechercher
                    </button>
                    <div class="download-buttons">
                        <button class="download-button" id="exportPdfSansBtn">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button class="download-button" id="exportExcelSansBtn">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button class="download-button" id="exportCsvSansBtn">
                            <i class="fas fa-file-csv"></i> CSV
                        </button>
                    </div>
                </div>

                <div class="table-card" id="sansIdentifiantsCard" style="display: none;">
                    <div class="table-header">
                        <h3 class="table-title">Utilisateurs sans Identifiants</h3>
                        <div class="table-actions">
                            <div class="filter-dropdown">
                                <button class="filter-button" id="filterButtonSans">
                                    <i class="fas fa-filter"></i> Filtres
                                </button>
                                <div class="filter-dropdown-content" id="filterDropdownSans">
                                    <div class="filter-option" data-filter="all">
                                        <i class="fas fa-list"></i> Tous les utilisateurs
                                    </div>
                                    <div class="filter-option" data-filter="name-asc">
                                        <i class="fas fa-sort-alpha-down"></i> Tri par Nom (A-Z)
                                    </div>
                                    <div class="filter-option" data-filter="name-desc">
                                        <i class="fas fa-sort-alpha-up"></i> Tri par Nom (Z-A)
                                    </div>
                                    <div class="filter-option" data-filter="type-asc">
                                        <i class="fas fa-user-tag"></i> Tri par Type (A-Z)
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-success" id="genererIdentifiantsBtn" disabled>
                                <i class="fas fa-key"></i> Générer Identifiants
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="sansIdentifiantsTable">
                            <thead>
                                <tr>
                                    <th>
                                        <label class="checkbox-container">
                                            <input type="checkbox" id="selectAllSans">
                                            <span class="checkmark"></span>
                                        </label>
                                    </th>
                                    <th>Nom</th>
                                    <th>Prénom</th>
                                    <th>Email</th>
                                    <th>Type</th>
                                    <th>Groupe</th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="section-separator">
                    <span>Utilisateurs avec identifiants générés</span>
                </div>

                <div class="search-bar" id="searchBarAvecIdentifiants">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInputAvec" class="search-input" placeholder="Rechercher un utilisateur...">
                    </div>
                    <button class="search-button" id="searchButtonAvec">
                        <i class="fas fa-search"></i> Rechercher
                    </button>
                    <div class="download-buttons">
                        <button class="download-button" id="exportPdfAvecBtn">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button class="download-button" id="exportExcelAvecBtn">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button class="download-button" id="exportCsvAvecBtn">
                            <i class="fas fa-file-csv"></i> CSV
                        </button>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">Utilisateurs avec Identifiants</h3>
                        <div class="table-actions">
                            <div class="filter-dropdown">
                                <button class="filter-button" id="filterButtonAvec">
                                    <i class="fas fa-filter"></i> Filtres
                                </button>
                                <div class="filter-dropdown-content" id="filterDropdownAvec">
                                    <div class="filter-option" data-filter="all">
                                        <i class="fas fa-list"></i> Tous les utilisateurs
                                    </div>
                                    <div class="filter-option" data-filter="id-asc">
                                        <i class="fas fa-sort-numeric-down"></i> Tri par ID (croissant)
                                    </div>
                                    <div class="filter-option" data-filter="id-desc">
                                        <i class="fas fa-sort-numeric-up"></i> Tri par ID (décroissant)
                                    </div>
                                    <div class="filter-option" data-filter="name-asc">
                                        <i class="fas fa-sort-alpha-down"></i> Tri par Nom (A-Z)
                                    </div>
                                    <div class="filter-option" data-filter="name-desc">
                                        <i class="fas fa-sort-alpha-up"></i> Tri par Nom (Z-A)
                                    </div>
                                    <div class="filter-option" data-filter="type-asc">
                                        <i class="fas fa-user-tag"></i> Tri par Type (A-Z)
                                    </div>
                                    <div class="filter-option" data-filter="group-asc">
                                        <i class="fas fa-users"></i> Tri par Groupe (A-Z)
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-secondary" id="modifierAvecIdentBtn" disabled>
                                <i class="fas fa-edit"></i> <span class="action-text">Modifier</span>
                            </button>
                            <button class="btn btn-secondary" id="supprimerAvecIdentBtn" disabled>
                                <i class="fas fa-trash-alt"></i> <span class="action-text">Supprimer</span>
                            </button>
                            <button class="btn btn-secondary" id="rafraichirAvecIdentBtn">
                                <i class="fas fa-sync-alt"></i> <span class="action-text">Rafraîchir</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="avecIdentifiantsTable">
                            <thead>
                                <tr>
                                    <th>
                                        <label class="checkbox-container">
                                            <input type="checkbox" id="selectAllAvec">
                                            <span class="checkmark"></span>
                                        </label>
                                    </th>
                                    <th>Nom</th>
                                    <th>Prénom</th>
                                    <th>Email</th>
                                    <th>Login</th>
                                    <th>Mot de passe</th>
                                    <th>Groupe</th>
                                    <th>Type</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script>
        // Variables globales
        let selectedUsersSans = new Set(); // For users without credentials
        let selectedUsersAvec = new Set(); // For users with credentials
        let currentTypeGroupe = { type: null, groupe: null };
        const groupesParType = <?php
            $groupesParType = [];
            foreach ($groupesUtilisateur as $groupe) {
                // Ensure to fetch the id_type associated with each group_utilisateur
                // This assumes a 'type_groupe' table links id_type and id_GU.
                $stmt = $pdo->prepare("SELECT id_type FROM type_groupe WHERE id_GU = ?");
                $stmt->execute([$groupe['id_GU']]);
                $idType = $stmt->fetchColumn();
                if ($idType) {
                    if (!isset($groupesParType[$idType])) {
                        $groupesParType[$idType] = [];
                    }
                    $groupesParType[$idType][] = $groupe;
                }
            }
            echo json_encode($groupesParType);
        ?>;
        const { jsPDF } = window.jspdf;

        // Éléments DOM
        const selectionForm = document.getElementById('selectionForm');
        const filterType = document.getElementById('filter_type');
        const filterGroupe = document.getElementById('filter_groupe');
        const sansIdentifiantsCard = document.getElementById('sansIdentifiantsCard');
        const sansIdentifiantsTableBody = document.querySelector('#sansIdentifiantsTable tbody');
        const avecIdentifiantsTableBody = document.querySelector('#avecIdentifiantsTable tbody');
        const genererIdentifiantsBtn = document.getElementById('genererIdentifiantsBtn');
        const selectAllSans = document.getElementById('selectAllSans');
        const selectAllAvec = document.getElementById('selectAllAvec');
        
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
        const mainContent = document.getElementById('mainContent');

        const searchBarSansIdentifiants = document.getElementById('searchBarSansIdentifiants');
        const searchInputSans = document.getElementById('searchInputSans');
        const searchButtonSans = document.getElementById('searchButtonSans');
        const exportPdfSansBtn = document.getElementById('exportPdfSansBtn');
        const exportExcelSansBtn = document.getElementById('exportExcelSansBtn');
        const exportCsvSansBtn = document.getElementById('exportCsvSansBtn');
        const filterButtonSans = document.getElementById('filterButtonSans');
        const filterDropdownSans = document.getElementById('filterDropdownSans');
        const filterOptionsSans = document.querySelectorAll('#filterDropdownSans .filter-option');

        const searchBarAvecIdentifiants = document.getElementById('searchBarAvecIdentifiants');
        const searchInputAvec = document.getElementById('searchInputAvec');
        const searchButtonAvec = document.getElementById('searchButtonAvec');
        const exportPdfAvecBtn = document.getElementById('exportPdfAvecBtn');
        const exportExcelAvecBtn = document.getElementById('exportExcelAvecBtn');
        const exportCsvAvecBtn = document.getElementById('exportCsvAvecBtn');
        const filterButtonAvec = document.getElementById('filterButtonAvec');
        const filterDropdownAvec = document.getElementById('filterDropdownAvec');
        const filterOptionsAvec = document.querySelectorAll('#filterDropdownAvec .filter-option');

        const modifierAvecIdentBtn = document.getElementById('modifierAvecIdentBtn');
        const supprimerAvecIdentBtn = document.getElementById('supprimerAvecIdentBtn');
        const rafraichirAvecIdentBtn = document.getElementById('rafraichirAvecIdentBtn');

        const messageModal = document.getElementById('messageModal');
        const messageTitle = document.getElementById('messageTitle');
        const messageText = document.getElementById('messageText');
        const messageIcon = document.getElementById('messageIcon');
        const messageButton = document.getElementById('messageButton');
        const messageClose = document.getElementById('messageClose');

        // Function to show messages in a modal
        function showAlert(message, type = 'success', title = null) {
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

        // Close the modal
        function closeMessageModal() {
            messageModal.style.display = 'none';
        }

        // Events for the modal
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

        // AJAX Request Function
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

        // Update button state for users without credentials
        function updateGenerateButton() {
            genererIdentifiantsBtn.disabled = selectedUsersSans.size === 0;
        }

        // Update button state for users with credentials
        function updateActionButtonsAvecIdent() {
            if (selectedUsersAvec.size === 1) {
                modifierAvecIdentBtn.disabled = false;
                supprimerAvecIdentBtn.disabled = false;
            } else if (selectedUsersAvec.size > 1) {
                modifierAvecIdentBtn.disabled = true;
                supprimerAvecIdentBtn.disabled = false;
            } else {
                modifierAvecIdentBtn.disabled = true;
                supprimerAvecIdentBtn.disabled = true;
            }
        }

        // Handle type change to filter groups
        filterType.addEventListener('change', function() {
            const selectedType = this.value;
            
            filterGroupe.innerHTML = '<option value="">Sélectionner un groupe</option>';
            filterGroupe.disabled = !selectedType;
            
            sansIdentifiantsCard.style.display = 'none';
            searchBarSansIdentifiants.style.display = 'none';
            sansIdentifiantsTableBody.innerHTML = ''; // Clear table content
            selectedUsersSans.clear();
            updateGenerateButton();
            
            if (selectedType && groupesParType[selectedType]) {
                groupesParType[selectedType].forEach(groupe => {
                    const option = document.createElement('option');
                    option.value = groupe.id_GU;
                    option.textContent = groupe.lib_GU;
                    filterGroupe.appendChild(option);
                });
                filterGroupe.disabled = false;
            }
        });

        // Submit selection form
        selectionForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const type = filterType.value;
            const groupe = filterGroupe.value;

            if (!type || !groupe) {
                showAlert('Veuillez sélectionner un type et un groupe', 'error');
                return;
            }

            currentTypeGroupe = { type, groupe };

            try {
                const result = await makeAjaxRequest({
                    action: 'filter',
                    id_type: type,
                    id_GU: groupe
                });

                if (result.success) {
                    updateSansIdentifiantsTable(result.data);
                    sansIdentifiantsCard.style.display = 'block';
                    searchBarSansIdentifiants.style.display = 'flex'; // Show search bar
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la récupération des données', 'error');
            }
        });

        // Update users without credentials table
        function updateSansIdentifiantsTable(users) {
            sansIdentifiantsTableBody.innerHTML = '';
            selectedUsersSans.clear();

            if (users.length === 0) {
                sansIdentifiantsTableBody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-users" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucun utilisateur sans identifiant trouvé pour cette sélection.
                        </td>
                    </tr>
                `;
            } else {
                users.forEach(user => {
                    const row = sansIdentifiantsTableBody.insertRow();
                    row.setAttribute('data-id-original', user.id_original);
                    row.setAttribute('data-type-table', user.type_table);
                    row.innerHTML = `
                        <td>
                            <label class="checkbox-container">
                                <input type="checkbox" class="sans-ident-checkbox" value="${user.id_original}-${user.type_table}" data-user='${JSON.stringify(user)}'>
                                <span class="checkmark"></span>
                            </label>
                        </td>
                        <td>${user.nom}</td>
                        <td>${user.prenom}</td>
                        <td>${user.email}</td>
                        <td>${user.lib_type || 'N/A'}</td>
                        <td>${user.lib_GU || 'N/A'}</td>
                    `;

                    const checkbox = row.querySelector('.sans-ident-checkbox');
                    checkbox.addEventListener('change', function() {
                        if (this.checked) {
                            selectedUsersSans.add(this.value);
                        } else {
                            selectedUsersSans.delete(this.value);
                        }
                        updateGenerateButton();
                        updateSelectAllSansCheckbox();
                    });
                });
            }

            updateGenerateButton();
            selectAllSans.checked = false;
            updateSelectAllSansCheckbox();
        }

        // Handle "select all" for users without credentials
        selectAllSans.addEventListener('change', function() {
            const checkboxes = sansIdentifiantsTableBody.querySelectorAll('.sans-ident-checkbox');
            selectedUsersSans.clear();

            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                if (this.checked) {
                    selectedUsersSans.add(checkbox.value);
                }
            });

            updateGenerateButton();
        });

        // Update "select all" checkbox for users without credentials
        function updateSelectAllSansCheckbox() {
            const checkboxes = sansIdentifiantsTableBody.querySelectorAll('.sans-ident-checkbox');
            const checkedBoxes = sansIdentifiantsTableBody.querySelectorAll('.sans-ident-checkbox:checked');
            
            if (checkboxes.length === 0) {
                selectAllSans.indeterminate = false;
                selectAllSans.checked = false;
            } else if (checkedBoxes.length === checkboxes.length) {
                selectAllSans.indeterminate = false;
                selectAllSans.checked = true;
            } else if (checkedBoxes.length > 0) {
                selectAllSans.indeterminate = true;
                selectAllSans.checked = false;
            } else {
                selectAllSans.indeterminate = false;
                selectAllSans.checked = false;
            }
        }

        // Handle "select all" for users with credentials
        if (selectAllAvec) {
            selectAllAvec.addEventListener('change', function() {
                const checkboxes = avecIdentifiantsTableBody.querySelectorAll('.avec-ident-checkbox');
                selectedUsersAvec.clear();

                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                    if (this.checked) {
                        selectedUsersAvec.add(checkbox.value);
                    }
                });

                updateActionButtonsAvecIdent();
            });
        }

        // Update "select all" checkbox for users with credentials
        function updateSelectAllAvecCheckbox() {
            const selectAllAvec = document.getElementById('selectAllAvec');
            if (!selectAllAvec) return;
            
            const checkboxes = avecIdentifiantsTableBody.querySelectorAll('.avec-ident-checkbox');
            const checkedBoxes = avecIdentifiantsTableBody.querySelectorAll('.avec-ident-checkbox:checked');
            
            if (checkboxes.length === 0) {
                selectAllAvec.indeterminate = false;
                selectAllAvec.checked = false;
            } else if (checkedBoxes.length === checkboxes.length) {
                selectAllAvec.indeterminate = false;
                selectAllAvec.checked = true;
            } else if (checkedBoxes.length > 0) {
                selectAllAvec.indeterminate = true;
                selectAllAvec.checked = false;
            } else {
                selectAllAvec.indeterminate = false;
                selectAllAvec.checked = false;
            }
        }

        // Generate credentials
        genererIdentifiantsBtn.addEventListener('click', async function() {
            if (selectedUsersSans.size === 0) {
                showAlert('Veuillez sélectionner au moins un utilisateur pour générer des identifiants.', 'warning');
                return;
            }

            const selectedUsersData = [];
            selectedUsersSans.forEach(value => {
                const checkbox = sansIdentifiantsTableBody.querySelector(`input[value="${value}"]`);
                if (checkbox) {
                    selectedUsersData.push(JSON.parse(checkbox.dataset.user));
                }
            });

            try {
                this.classList.add('loading');
                this.disabled = true;

                const result = await makeAjaxRequest({
                    action: 'generate_credentials',
                    selected_users: JSON.stringify(selectedUsersData),
                    id_GU: currentTypeGroupe.groupe
                });

                if (result.success) {
                    showAlert(result.message, 'success');
                    
                    if (result.data && result.data.length > 0) {
                        let credentialsMessage = "Identifiants générés :\n\n";
                        result.data.forEach(user => {
                            credentialsMessage += `Nom: ${user.nom} ${user.prenom}\nLogin: ${user.login}\nMot de passe: ${user.password}\n\n`;
                        });
                        alert(credentialsMessage); // Consider using a custom modal for this instead of native alert
                    }
                    
                    // Refresh both tables
                    selectionForm.dispatchEvent(new Event('submit'));
                    loadUsersWithCredentials();
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la génération des identifiants', 'error');
            } finally {
                this.classList.remove('loading');
                this.disabled = false;
            }
        });

        // Load users with credentials
        async function loadUsersWithCredentials() {
            try {
                const result = await makeAjaxRequest({
                    action: 'get_with_credentials'
                });

                if (result.success) {
                    updateAvecIdentifiantsTable(result.data);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des utilisateurs avec identifiants', 'error');
            }
        }

        // Update users with credentials table
        function updateAvecIdentifiantsTable(users) {
            avecIdentifiantsTableBody.innerHTML = '';
            selectedUsersAvec.clear();

            if (users.length === 0) {
                avecIdentifiantsTableBody.innerHTML = `
                    <tr>
                        <td colspan="9" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-key" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucun utilisateur avec identifiants généré.
                        </td>
                    </tr>
                `;
            } else {
                users.forEach(user => {
                    const row = avecIdentifiantsTableBody.insertRow();
                    row.setAttribute('data-id', user.id_util);
                    row.innerHTML = `
                        <td>
                            <label class="checkbox-container">
                                <input type="checkbox" value="${user.id_util}" class="avec-ident-checkbox">
                                <span class="checkmark"></span>
                            </label>
                        </td>
                        <td>${user.nom || ''}</td>
                        <td>${user.prenom || ''}</td>
                        <td>${user.email || ''}</td>
                        <td><strong>${user.login_util}</strong></td>
                        <td><span style="background: var(--gray-100); padding: 2px 6px; border-radius: 4px; font-family: monospace;">${user.temp_password || '••••••••'}</span></td>
                        <td>${user.lib_GU || 'N/A'}</td>
                        <td>${user.lib_type || 'N/A'}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-button edit" title="Modifier" onclick="modifierUtilisateurAvecIdent('${user.id_util}')">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                                <button class="action-button delete" title="Supprimer" onclick="supprimerUtilisateurAvecIdent('${user.id_util}')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    `;
                });
                
                const checkboxes = avecIdentifiantsTableBody.querySelectorAll('.avec-ident-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        if (this.checked) {
                            selectedUsersAvec.add(this.value);
                        } else {
                            selectedUsersAvec.delete(this.value);
                        }
                        updateActionButtonsAvecIdent();
                        updateSelectAllAvecCheckbox();
                    });
                });
            }
            
            updateActionButtonsAvecIdent();
            selectAllAvec.checked = false;
            updateSelectAllAvecCheckbox();
        }

        // Modify user with credentials (single action button)
        function modifierUtilisateurAvecIdent(idUtil) {
            const row = document.querySelector(`tr[data-id="${idUtil}"]`);
            if (row) {
                const currentLogin = row.cells[4].textContent.trim();
                
                const newLogin = prompt(`Modifier le login pour cet utilisateur:\n\nLogin actuel: ${currentLogin}`, currentLogin);
                if (newLogin !== null) { // User clicked OK or entered something
                    if (newLogin === "") {
                        showAlert('Le login ne peut pas être vide.', 'warning');
                        return;
                    }
                    const newPassword = prompt('Nouveau mot de passe (laisser vide pour garder l\'actuel):');
                    
                    modifierCredentials(idUtil, newLogin, newPassword);
                }
            }
        }

        // Modify credentials AJAX call
        async function modifierCredentials(idUtil, newLogin, newPassword = '') {
            try {
                const result = await makeAjaxRequest({
                    action: 'update_credentials',
                    id_util: idUtil,
                    new_login: newLogin,
                    new_password: newPassword
                });

                if (result.success) {
                    showAlert(result.message);
                    loadUsersWithCredentials(); // Reload table after update
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la modification', 'error');
            }
        }

        // Delete user with credentials (single action button)
        async function supprimerUtilisateurAvecIdent(idUtil) {
            const row = document.querySelector(`tr[data-id="${idUtil}"]`);
            if (row) {
                const nom = row.cells[1].textContent;
                const prenom = row.cells[2].textContent;
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer les identifiants de ${prenom} ${nom} ?\n\nCela supprimera leur login et mot de passe et ils ne pourront plus se connecter.`)) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'delete_credentials',
                            ids_utilisateurs: JSON.stringify([idUtil])
                        });

                        if (result.success) {
                            showAlert(result.message);
                            loadUsersWithCredentials();
                            selectedUsersAvec.delete(idUtil);
                            updateActionButtonsAvecIdent();
                        } else {
                            showAlert(result.message, 'error');
                        }
                    } catch (error) {
                        showAlert('Erreur lors de la suppression', 'error');
                    }
                }
            }
        }

        // Global modify button for users with credentials
        modifierAvecIdentBtn.addEventListener('click', function() {
            if (selectedUsersAvec.size === 1) {
                const idUtil = Array.from(selectedUsersAvec)[0];
                modifierUtilisateurAvecIdent(idUtil);
            }
        });

        // Global delete button for users with credentials
        supprimerAvecIdentBtn.addEventListener('click', async function() {
            if (selectedUsersAvec.size > 0) {
                const idsArray = Array.from(selectedUsersAvec);
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer les identifiants de ${idsArray.length} utilisateur(s) sélectionné(s) ?\n\nCela supprimera leur login et mot de passe définitivement.`)) {
                    try {
                        this.classList.add('loading');
                        this.disabled = true;
                        
                        const result = await makeAjaxRequest({
                            action: 'delete_credentials',
                            ids_utilisateurs: JSON.stringify(idsArray)
                        });

                        if (result.success) {
                            showAlert(result.message);
                            loadUsersWithCredentials();
                            selectedUsersAvec.clear();
                            updateActionButtonsAvecIdent();
                        } else {
                            showAlert(result.message, 'error');
                        }
                    } catch (error) {
                        showAlert('Erreur lors de la suppression', 'error');
                    } finally {
                        this.classList.remove('loading');
                        this.disabled = false;
                    }
                }
            }
        });

        // Refresh button for users with credentials
        rafraichirAvecIdentBtn.addEventListener('click', loadUsersWithCredentials);

        // Search functionality for "sans identifiants" table
        searchButtonSans.addEventListener('click', searchUsersSans);
        searchInputSans.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                searchUsersSans();
            }
        });

        function searchUsersSans() {
            const searchTerm = searchInputSans.value.toLowerCase();
            const rows = sansIdentifiantsTableBody.querySelectorAll('tr');
            
            rows.forEach(row => {
                if (row.querySelector('td[colspan="6"]')) return; // Ignore empty message
                
                const nom = row.cells[1].textContent.toLowerCase();
                const prenom = row.cells[2].textContent.toLowerCase();
                const email = row.cells[3].textContent.toLowerCase();
                const type = row.cells[4].textContent.toLowerCase();
                const groupe = row.cells[5].textContent.toLowerCase();
                
                if (nom.includes(searchTerm) || prenom.includes(searchTerm) || email.includes(searchTerm) || type.includes(searchTerm) || groupe.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Filter functionality for "sans identifiants" table
        filterButtonSans.addEventListener('click', function() {
            filterDropdownSans.classList.toggle('show');
        });

        filterOptionsSans.forEach(option => {
            option.addEventListener('click', function() {
                const filterType = this.getAttribute('data-filter');
                applyFilterSans(filterType);
                filterDropdownSans.classList.remove('show');
            });
        });

        function applyFilterSans(filterType) {
            const rows = Array.from(sansIdentifiantsTableBody.querySelectorAll('tr'));
            
            const emptyRow = sansIdentifiantsTableBody.querySelector('td[colspan="6"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }
            
            rows.forEach(row => {
                if (!row.querySelector('td[colspan="6"]')) {
                    row.style.display = '';
                }
            });
            
            rows.sort((a, b) => {
                if (a.querySelector('td[colspan="6"]') || b.querySelector('td[colspan="6"]')) return 0;
                
                const nomA = a.cells[1].textContent.toLowerCase();
                const nomB = b.cells[1].textContent.toLowerCase();
                const typeA = a.cells[4].textContent.toLowerCase();
                const typeB = b.cells[4].textContent.toLowerCase();
                
                switch (filterType) {
                    case 'name-asc':
                        return nomA.localeCompare(nomB);
                    case 'name-desc':
                        return nomB.localeCompare(nomA);
                    case 'type-asc':
                        return typeA.localeCompare(typeB);
                    default:
                        return 0;
                }
            });
            
            rows.forEach(row => {
                sansIdentifiantsTableBody.appendChild(row);
            });
            
            if (rows.length === 0 || (rows.length === 1 && rows[0].querySelector('td[colspan="6"]'))) {
                sansIdentifiantsTableBody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-users" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucun utilisateur sans identifiant trouvé pour cette sélection.
                        </td>
                    </tr>
                `;
            }
        }

        // Search functionality for "avec identifiants" table
        searchButtonAvec.addEventListener('click', searchUsersAvec);
        searchInputAvec.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                searchUsersAvec();
            }
        });

        function searchUsersAvec() {
            const searchTerm = searchInputAvec.value.toLowerCase();
            const rows = avecIdentifiantsTableBody.querySelectorAll('tr');
            
            rows.forEach(row => {
                if (row.querySelector('td[colspan="9"]')) return; // Ignore empty message
                
                const nom = row.cells[1].textContent.toLowerCase();
                const prenom = row.cells[2].textContent.toLowerCase();
                const email = row.cells[3].textContent.toLowerCase();
                const login = row.cells[4].textContent.toLowerCase();
                const groupe = row.cells[6].textContent.toLowerCase();
                const type = row.cells[7].textContent.toLowerCase();
                
                if (nom.includes(searchTerm) || prenom.includes(searchTerm) || email.includes(searchTerm) || login.includes(searchTerm) || groupe.includes(searchTerm) || type.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Filter functionality for "avec identifiants" table
        filterButtonAvec.addEventListener('click', function() {
            filterDropdownAvec.classList.toggle('show');
        });

        filterOptionsAvec.forEach(option => {
            option.addEventListener('click', function() {
                const filterType = this.getAttribute('data-filter');
                applyFilterAvec(filterType);
                filterDropdownAvec.classList.remove('show');
            });
        });

        function applyFilterAvec(filterType) {
            const rows = Array.from(avecIdentifiantsTableBody.querySelectorAll('tr'));
            
            const emptyRow = avecIdentifiantsTableBody.querySelector('td[colspan="9"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }
            
            rows.forEach(row => {
                if (!row.querySelector('td[colspan="9"]')) {
                    row.style.display = '';
                }
            });
            
            rows.sort((a, b) => {
                if (a.querySelector('td[colspan="9"]') || b.querySelector('td[colspan="9"]')) return 0;
                
                const idA = parseInt(a.getAttribute('data-id'));
                const idB = parseInt(b.getAttribute('data-id'));
                const nomA = a.cells[1].textContent.toLowerCase();
                const nomB = b.cells[1].textContent.toLowerCase();
                const typeA = a.cells[7].textContent.toLowerCase();
                const typeB = b.cells[7].textContent.toLowerCase();
                const groupA = a.cells[6].textContent.toLowerCase();
                const groupB = b.cells[6].textContent.toLowerCase();
                
                switch (filterType) {
                    case 'id-asc':
                        return idA - idB;
                    case 'id-desc':
                        return idB - idA;
                    case 'name-asc':
                        return nomA.localeCompare(nomB);
                    case 'name-desc':
                        return nomB.localeCompare(nomA);
                    case 'type-asc':
                        return typeA.localeCompare(typeB);
                    case 'group-asc':
                        return groupA.localeCompare(groupB);
                    default:
                        return 0;
                }
            });
            
            rows.forEach(row => {
                avecIdentifiantsTableBody.appendChild(row);
            });
            
            if (rows.length === 0 || (rows.length === 1 && rows[0].querySelector('td[colspan="9"]'))) {
                avecIdentifiantsTableBody.innerHTML = `
                    <tr>
                        <td colspan="9" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-key" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucun utilisateur avec identifiants généré.
                        </td>
                    </tr>
                `;
            }
        }

        // Close dropdown if clicked outside
        window.addEventListener('click', function(e) {
            if (!e.target.matches('.filter-button') && !e.target.closest('.filter-dropdown')) {
                filterDropdownSans.classList.remove('show');
                filterDropdownAvec.classList.remove('show');
            }
        });

        // Export to PDF for "sans identifiants"
        exportPdfSansBtn.addEventListener('click', function() {
            const doc = new jsPDF();
            const title = "Utilisateurs sans Identifiants";
            const date = new Date().toLocaleDateString();
            
            doc.setFontSize(18);
            doc.text(title, 14, 20);
            
            doc.setFontSize(10);
            doc.text(`Exporté le: ${date}`, 14, 30);
            
            const headers = [['Nom', 'Prénom', 'Email', 'Type', 'Groupe']];
            const data = [];
            
            document.querySelectorAll('#sansIdentifiantsTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="6"]')) {
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent,
                        row.cells[4].textContent,
                        row.cells[5].textContent
                    ]);
                }
            });
            
            doc.autoTable({
                head: headers,
                body: data,
                startY: 40,
                styles: { fontSize: 10, cellPadding: 3, valign: 'middle' },
                headStyles: { fillColor: [59, 130, 246], textColor: 255, fontStyle: 'bold' },
                alternateRowStyles: { fillColor: [241, 245, 249] }
            });
            
            doc.save(`utilisateurs_sans_identifiants_${new Date().toISOString().split('T')[0]}.pdf`);
            showAlert('Exportation PDF terminée');
        });

        // Export to Excel for "sans identifiants"
        exportExcelSansBtn.addEventListener('click', function() {
            const rows = sansIdentifiantsTableBody.querySelectorAll('tr');
            if (rows.length === 1 && rows[0].querySelector('td[colspan="6"]')) {
                showAlert('Aucune donnée à exporter', 'warning');
                return;
            }

            const data = [['Nom', 'Prénom', 'Email', 'Type', 'Groupe']];
            
            rows.forEach(row => {
                if (!row.querySelector('td[colspan="6"]')) {
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent,
                        row.cells[4].textContent,
                        row.cells[5].textContent
                    ]);
                }
            });

            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Sans Identifiants");
            
            XLSX.writeFile(wb, `utilisateurs_sans_identifiants_${new Date().toISOString().split('T')[0]}.xlsx`);
            
            showAlert('Exportation Excel terminée');
        });

        // Export to CSV for "sans identifiants"
        exportCsvSansBtn.addEventListener('click', function() {
            let csv = "Nom,Prénom,Email,Type,Groupe\n";
            
            document.querySelectorAll('#sansIdentifiantsTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="6"]')) {
                    csv += `"${row.cells[1].textContent}","${row.cells[2].textContent}","${row.cells[3].textContent}","${row.cells[4].textContent}","${row.cells[5].textContent}"\n`;
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', `utilisateurs_sans_identifiants_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showAlert('Exportation CSV terminée');
        });

        // Export to PDF for "avec identifiants"
        exportPdfAvecBtn.addEventListener('click', function() {
            const doc = new jsPDF();
            const title = "Utilisateurs avec Identifiants";
            const date = new Date().toLocaleDateString();
            
            doc.setFontSize(18);
            doc.text(title, 14, 20);
            
            doc.setFontSize(10);
            doc.text(`Exporté le: ${date}`, 14, 30);
            
            const headers = [['Nom', 'Prénom', 'Email', 'Login', 'Mot de passe', 'Groupe', 'Type']];
            const data = [];
            
            document.querySelectorAll('#avecIdentifiantsTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="9"]')) {
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent,
                        row.cells[4].textContent,
                        row.cells[5].textContent.replace(/•/g, ''), // Clean bullets
                        row.cells[6].textContent,
                        row.cells[7].textContent
                    ]);
                }
            });
            
            doc.autoTable({
                head: headers,
                body: data,
                startY: 40,
                styles: { fontSize: 10, cellPadding: 3, valign: 'middle' },
                headStyles: { fillColor: [59, 130, 246], textColor: 255, fontStyle: 'bold' },
                alternateRowStyles: { fillColor: [241, 245, 249] }
            });
            
            doc.save(`utilisateurs_avec_identifiants_${new Date().toISOString().split('T')[0]}.pdf`);
            showAlert('Exportation PDF terminée');
        });

        // Export to Excel for "avec identifiants"
        exportExcelAvecBtn.addEventListener('click', function() {
            const rows = avecIdentifiantsTableBody.querySelectorAll('tr');
            if (rows.length === 1 && rows[0].querySelector('td[colspan="9"]')) {
                showAlert('Aucune donnée à exporter', 'warning');
                return;
            }

            const data = [['Nom', 'Prénom', 'Email', 'Login', 'Mot de passe', 'Groupe', 'Type']];
            
            rows.forEach(row => {
                if (!row.querySelector('td[colspan="9"]')) {
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent,
                        row.cells[4].textContent,
                        row.cells[5].textContent.replace(/•/g, ''), // Clean bullets
                        row.cells[6].textContent,
                        row.cells[7].textContent
                    ]);
                }
            });

            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Avec Identifiants");
            
            XLSX.writeFile(wb, `utilisateurs_avec_identifiants_${new Date().toISOString().split('T')[0]}.xlsx`);
            
            showAlert('Exportation Excel terminée');
        });

        // Export to CSV for "avec identifiants"
        exportCsvAvecBtn.addEventListener('click', function() {
            let csv = "Nom,Prénom,Email,Login,Mot de passe,Groupe,Type\n";
            
            document.querySelectorAll('#avecIdentifiantsTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="9"]')) {
                    csv += `"${row.cells[1].textContent}","${row.cells[2].textContent}","${row.cells[3].textContent}","${row.cells[4].textContent}","${row.cells[5].textContent.replace(/•/g, '')}","${row.cells[6].textContent}","${row.cells[7].textContent}"\n`;
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', `utilisateurs_avec_identifiants_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showAlert('Exportation CSV terminée');
        });


        // Initial load
        document.addEventListener('DOMContentLoaded', function() {
            loadUsersWithCredentials();
            updateActionButtonsAvecIdent();
            
            // Hide action text on small screens
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

        // Responsive handling
        function handleResize() {
            if (window.innerWidth >= 1024) {
                sidebar.classList.remove('mobile-open');
                mobileMenuOverlay.classList.remove('active');
            }
        }

        window.addEventListener('resize', handleResize);
    </script>
</body>
</html>