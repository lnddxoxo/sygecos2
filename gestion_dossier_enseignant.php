<?php
// gestion_dossier_enseignant.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Fonction pour générer un login unique pour un enseignant
function genererLoginEnseignant($nom, $prenom, $pdo) {
    // Nettoyer et préparer le login de base
    $nom = strtolower(trim($nom));
    $prenom = strtolower(trim($prenom));
    $loginBase = substr($prenom, 0, 1) . $nom;
    
    // Enlever les accents et caractères spéciaux
    $loginBase = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $loginBase);
    $loginBase = preg_replace('/[^a-z0-9]/', '', $loginBase);
    
    $login = $loginBase;
    $counter = 1;
    
    // Vérifier l'unicité
    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE login_util = ?");
        $stmt->execute([$login]);
        if ($stmt->fetchColumn() == 0) {
            break;
        }
        $login = $loginBase . $counter;
        $counter++;
    }
    
    return $login;
}

// Fonction pour générer un mot de passe temporaire
function genererMotDePasseTemporaire() {
    return 'Enseignant' . rand(1000, 9999);
}

// Traitement AJAX pour les opérations CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        switch ($action) {
            case 'create':
                $nom = trim($_POST['nom_ens']);
                $prenom = trim($_POST['prenom_ens']);
                $email = trim($_POST['email']);
                $grade = $_POST['grade'] ?? null;
                $fonction = $_POST['fonction'] ?? null;
                
                // Validation
                if (empty($nom) || empty($prenom) || empty($email)) {
                    throw new Exception("Tous les champs obligatoires doivent être remplis");
                }
                
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Format d'email invalide");
                }
                
                // Vérifier si l'email existe déjà
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM enseignant WHERE email = ?");
                $checkStmt->execute([$email]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Un enseignant avec cet email existe déjà");
                }
                
                // Générer login et mot de passe
                $login = genererLoginEnseignant($nom, $prenom, $pdo);
                $motDePasse = genererMotDePasseTemporaire();
                $motDePasseHash = hash('sha256', $motDePasse);
                
                // Générer les IDs
                $stmtMaxUtil = $pdo->query("SELECT COALESCE(MAX(id_util), 0) + 1 FROM utilisateur");
                $idUtil = $stmtMaxUtil->fetchColumn();
                
                $stmtMaxEns = $pdo->query("SELECT COALESCE(MAX(id_ens), 0) + 1 FROM enseignant");
                $idEns = $stmtMaxEns->fetchColumn();
                
                // 1. Créer l'utilisateur
                $stmtUser = $pdo->prepare("INSERT INTO utilisateur (id_util, login_util, mdp_util, last_activity) VALUES (?, ?, ?, NOW())");
                $stmtUser->execute([$idUtil, $login, $motDePasseHash]);
                
                // 2. Créer l'enseignant
                $stmtEns = $pdo->prepare("INSERT INTO enseignant (id_ens, fk_id_util, nom_ens, prenom_ens, email) VALUES (?, ?, ?, ?, ?)");
                $stmtEns->execute([$idEns, $idUtil, $nom, $prenom, $email]);
                
                // 3. Ajouter au groupe "Commission de validation" (id_GU = 4)
                $stmtMaxPoss = $pdo->query("SELECT COALESCE(MAX(id_poss), 0) + 1 FROM posseder");
                $idPoss = $stmtMaxPoss->fetchColumn();
                
                $stmtPoss = $pdo->prepare("INSERT INTO posseder (id_poss, fk_id_util, fk_id_GU, dte_poss) VALUES (?, ?, 4, CURDATE())");
                $stmtPoss->execute([$idPoss, $idUtil]);
                
                // 4. Ajouter grade si fourni
                if ($grade) {
                    // Vérifier si le grade existe
                    $checkGrade = $pdo->prepare("SELECT id_grd FROM grade WHERE nom_grd = ?");
                    $checkGrade->execute([$grade]);
                    $gradeId = $checkGrade->fetchColumn();
                    
                    if ($gradeId) {
                        $stmtMaxAvoir = $pdo->query("SELECT COALESCE(MAX(id_avoir), 0) + 1 FROM avoir");
                        $idAvoir = $stmtMaxAvoir->fetchColumn();
                        
                        $stmtAvoir = $pdo->prepare("INSERT INTO avoir (id_avoir, fk_id_grd, fk_id_ens, dte_grd) VALUES (?, ?, ?, CURDATE())");
                        $stmtAvoir->execute([$idAvoir, $gradeId, $idEns]);
                    }
                }
                
                // 5. Ajouter fonction si fournie
                if ($fonction) {
                    // Vérifier si la fonction existe
                    $checkFonction = $pdo->prepare("SELECT id_fonction FROM fonction WHERE nom_fonction = ?");
                    $checkFonction->execute([$fonction]);
                    $fonctionId = $checkFonction->fetchColumn();
                    
                    if ($fonctionId) {
                        $stmtMaxOccuper = $pdo->query("SELECT COALESCE(MAX(id_occuper), 0) + 1 FROM occuper");
                        $idOccuper = $stmtMaxOccuper->fetchColumn();
                        
                        $stmtOccuper = $pdo->prepare("INSERT INTO occuper (id_occuper, fk_id_fonc, fk_id_ens, dte_occup) VALUES (?, ?, ?, CURDATE())");
                        $stmtOccuper->execute([$idOccuper, $fonctionId, $idEns]);
                    }
                }
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Enseignant créé avec succès',
                    'data' => [
                        'id_ens' => $idEns,
                        'nom_ens' => $nom,
                        'prenom_ens' => $prenom,
                        'email' => $email,
                        'login' => $login,
                        'motdepasse_temp' => $motDePasse,
                        'grade' => $grade,
                        'fonction' => $fonction
                    ]
                ]);
                break;
                
            case 'update':
                $idEns = $_POST['id_ens'];
                $nom = trim($_POST['nom_ens']);
                $prenom = trim($_POST['prenom_ens']);
                $email = trim($_POST['email']);
                $grade = $_POST['grade'] ?? null;
                $fonction = $_POST['fonction'] ?? null;
                
                // Validation
                if (empty($nom) || empty($prenom) || empty($email) || empty($grade) || empty($fonction)) {
                    throw new Exception("Tous les champs obligatoires doivent être remplis");
                }
                
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Format d'email invalide");
                }
                
                // Vérifier si l'email existe déjà pour un autre enseignant
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM enseignant WHERE email = ? AND id_ens != ?");
                $checkStmt->execute([$email, $idEns]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Un autre enseignant avec cet email existe déjà");
                }
                
                // Mettre à jour l'enseignant
                $stmtUpdate = $pdo->prepare("UPDATE enseignant SET nom_ens = ?, prenom_ens = ?, email = ? WHERE id_ens = ?");
                $stmtUpdate->execute([$nom, $prenom, $email, $idEns]);
                
                // Mettre à jour grade si fourni
                if ($grade) {
                    // Supprimer l'ancien grade
                    $pdo->prepare("DELETE FROM avoir WHERE fk_id_ens = ?")->execute([$idEns]);
                    
                    // Ajouter le nouveau grade
                    $checkGrade = $pdo->prepare("SELECT id_grd FROM grade WHERE nom_grd = ?");
                    $checkGrade->execute([$grade]);
                    $gradeId = $checkGrade->fetchColumn();
                    
                    if ($gradeId) {
                        $stmtMaxAvoir = $pdo->query("SELECT COALESCE(MAX(id_avoir), 0) + 1 FROM avoir");
                        $idAvoir = $stmtMaxAvoir->fetchColumn();
                        
                        $stmtAvoir = $pdo->prepare("INSERT INTO avoir (id_avoir, fk_id_grd, fk_id_ens, dte_grd) VALUES (?, ?, ?, CURDATE())");
                        $stmtAvoir->execute([$idAvoir, $gradeId, $idEns]);
                    }
                }
                
                // Mettre à jour fonction si fournie
                if ($fonction) {
                    // Supprimer l'ancienne fonction
                    $pdo->prepare("DELETE FROM occuper WHERE fk_id_ens = ?")->execute([$idEns]);
                    
                    // Ajouter la nouvelle fonction
                    $checkFonction = $pdo->prepare("SELECT id_fonction FROM fonction WHERE nom_fonction = ?");
                    $checkFonction->execute([$fonction]);
                    $fonctionId = $checkFonction->fetchColumn();
                    
                    if ($fonctionId) {
                        $stmtMaxOccuper = $pdo->query("SELECT COALESCE(MAX(id_occuper), 0) + 1 FROM occuper");
                        $idOccuper = $stmtMaxOccuper->fetchColumn();
                        
                        $stmtOccuper = $pdo->prepare("INSERT INTO occuper (id_occuper, fk_id_fonc, fk_id_ens, dte_occup) VALUES (?, ?, ?, CURDATE())");
                        $stmtOccuper->execute([$idOccuper, $fonctionId, $idEns]);
                    }
                }
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Enseignant modifié avec succès']);
                break;
                
            case 'delete':
                $idsEnseignants = json_decode($_POST['ids_enseignants'], true);
                
                foreach ($idsEnseignants as $idEns) {
                    // Récupérer l'ID utilisateur associé
                    $stmtGetUser = $pdo->prepare("SELECT fk_id_util FROM enseignant WHERE id_ens = ?");
                    $stmtGetUser->execute([$idEns]);
                    $idUtil = $stmtGetUser->fetchColumn();
                    
                    if ($idUtil) {
                        // Supprimer les relations
                        $pdo->prepare("DELETE FROM avoir WHERE fk_id_ens = ?")->execute([$idEns]);
                        $pdo->prepare("DELETE FROM occuper WHERE fk_id_ens = ?")->execute([$idEns]);
                        $pdo->prepare("DELETE FROM posseder WHERE fk_id_util = ?")->execute([$idUtil]);
                        
                        // Supprimer l'enseignant
                        $pdo->prepare("DELETE FROM enseignant WHERE id_ens = ?")->execute([$idEns]);
                        
                        // Supprimer l'utilisateur
                        $pdo->prepare("DELETE FROM utilisateur WHERE id_util = ?")->execute([$idUtil]);
                    }
                }
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Enseignant(s) supprimé(s) avec succès']);
                break;
                
            case 'get_grades':
                $stmt = $pdo->query("SELECT * FROM grade ORDER BY nom_grd");
                $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $grades]);
                break;
                
            case 'get_fonctions':
                $stmt = $pdo->query("SELECT * FROM fonction ORDER BY nom_fonction");
                $fonctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $fonctions]);
                break;
                
            case 'reset_password':
                $idEns = $_POST['id_ens'];
                
                // Récupérer l'ID utilisateur
                $stmtGetUser = $pdo->prepare("SELECT fk_id_util FROM enseignant WHERE id_ens = ?");
                $stmtGetUser->execute([$idEns]);
                $idUtil = $stmtGetUser->fetchColumn();
                
                if ($idUtil) {
                    $nouveauMdp = genererMotDePasseTemporaire();
                    $mdpHash = hash('sha256', $nouveauMdp);
                    
                    $stmtUpdate = $pdo->prepare("UPDATE utilisateur SET mdp_util = ? WHERE id_util = ?");
                    $stmtUpdate->execute([$mdpHash, $idUtil]);
                    
                    $pdo->commit();
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Mot de passe réinitialisé avec succès',
                        'nouveau_mdp' => $nouveauMdp
                    ]);
                } else {
                    throw new Exception("Enseignant non trouvé");
                }
                break;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Récupérer les enseignants avec leurs informations
$enseignants = [];
$grades = [];
$fonctions = [];

try {
    // Récupérer les enseignants avec leurs grades et fonctions
    $stmtEnseignants = $pdo->query("
        SELECT 
            e.id_ens,
            e.nom_ens,
            e.prenom_ens,
            e.email,
            u.login_util,
            g.nom_grd as grade,
            f.nom_fonction as fonction
        FROM enseignant e
        LEFT JOIN utilisateur u ON e.fk_id_util = u.id_util
        LEFT JOIN avoir a ON e.id_ens = a.fk_id_ens
        LEFT JOIN grade g ON a.fk_id_grd = g.id_grd
        LEFT JOIN occuper o ON e.id_ens = o.fk_id_ens
        LEFT JOIN fonction f ON o.fk_id_fonc = f.id_fonction
        ORDER BY e.nom_ens, e.prenom_ens
    ");
    $enseignants = $stmtEnseignants->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les grades disponibles
    $stmtGrades = $pdo->query("SELECT * FROM grade ORDER BY nom_grd");
    $grades = $stmtGrades->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les fonctions disponibles
    $stmtFonctions = $pdo->query("SELECT * FROM fonction ORDER BY nom_fonction");
    $fonctions = $stmtFonctions->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Erreur lors de la récupération des données: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Dossier Enseignant</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Vos styles CSS existants restent identiques */
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
        }

        .action-button.view {
            background-color: var(--info-500);
        }
        .action-button.view:hover {
            background-color: #316be6;
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

        .action-button.reset {
            background-color: var(--secondary-500);
        }
        .action-button.reset:hover {
            background-color: var(--secondary-600);
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

        .alert.info {
            background-color: var(--accent-50);
            color: var(--accent-700);
            border: 1px solid var(--accent-200);
        }

        /* Loading spinner */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Modal pour affichage des informations */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: var(--white);
            margin: 5% auto;
            padding: var(--space-6);
            border-radius: var(--radius-xl);
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-xl);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-4);
            border-bottom: 1px solid var(--gray-200);
        }

        .modal-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
        }

        .close {
            color: var(--gray-400);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color var(--transition-fast);
        }

        .close:hover {
            color: var(--gray-600);
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
                    <h1 class="page-title-main">Gestion des Dossiers Enseignants</h1>
                    <p class="page-subtitle">Gérez les informations et les comptes des enseignants membres de la commission de validation.</p>
                </div>

                <!-- Message d'alerte -->
                <div id="alertMessage" class="alert"></div>

                <div class="form-card">
                    <h3 class="form-card-title">Ajouter un nouvel Enseignant</h3>
                    <form id="teacherForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="nom_ens">Nom <span style="color: var(--error-500);">*</span></label>
                                <input type="text" id="nom_ens" name="nom_ens" placeholder="Ex: Dupont" required>
                            </div>
                            <div class="form-group">
                                <label for="prenom_ens">Prénom <span style="color: var(--error-500);">*</span></label>
                                <input type="text" id="prenom_ens" name="prenom_ens" placeholder="Ex: Jean" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email <span style="color: var(--error-500);">*</span></label>
                                <input type="email" id="email" name="email" placeholder="Ex: jean.dupont@univ.com" required>
                            </div>
                            <div class="form-group">
                                <label for="grade">Grade<span style="color: var(--error-500);">*</span></label>
                                <select id="grade" name="grade">
                                    <option value="">Sélectionner un grade</option>
                                    <?php foreach ($grades as $grade): ?>
                                        <option value="<?php echo htmlspecialchars($grade['nom_grd']); ?>">
                                            <?php echo htmlspecialchars($grade['nom_grd']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="fonction">Fonction<span style="color: var(--error-500);">*</span></label>
                                <select id="fonction" name="fonction">
                                    <option value="">Sélectionner une fonction</option>
                                    <?php foreach ($fonctions as $fonction): ?>
                                        <option value="<?php echo htmlspecialchars($fonction['nom_fonction']); ?>">
                                            <?php echo htmlspecialchars($fonction['nom_fonction']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-user-plus"></i> <span id="submitText">Ajouter Enseignant</span>
                            </button>
                            <button type="reset" class="btn btn-secondary" id="cancelBtn">
                                <i class="fas fa-redo"></i> Annuler
                            </button>
                        </div>
                    </form>
                </div>

                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">Liste des Enseignants</h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" id="modifierTeacherBtn" disabled>
                                <i class="fas fa-edit"></i> Modifier
                            </button>
                            <button class="btn btn-secondary" id="supprimerTeacherBtn" disabled>
                                <i class="fas fa-trash-alt"></i> Supprimer
                            </button>
                            <button class="btn btn-secondary" id="exporterTeacherBtn">
                                <i class="fas fa-file-export"></i> Exporter
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="teacherTable">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Nom</th>
                                    <th>Prénom</th>
                                    <th>Email</th>
                                    <th>Login</th>
                                    <th>Grade</th>
                                    <th>Fonction</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($enseignants)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                        <i class="fas fa-users" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucun enseignant trouvé. Ajoutez votre premier enseignant en utilisant le formulaire ci-dessus.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($enseignants as $enseignant): ?>
                                    <tr data-id="<?php echo htmlspecialchars($enseignant['id_ens']); ?>">
                                        <td>
                                            <label class="checkbox-container">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($enseignant['id_ens']); ?>">
                                                <span class="checkmark"></span>
                                            </label>
                                        </td>
                                        <td><?php echo htmlspecialchars($enseignant['nom_ens']); ?></td>
                                        <td><?php echo htmlspecialchars($enseignant['prenom_ens']); ?></td>
                                        <td><?php echo htmlspecialchars($enseignant['email']); ?></td>
                                        <td><?php echo htmlspecialchars($enseignant['login_util'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($enseignant['grade'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($enseignant['fonction'] ?? 'N/A'); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button view" title="Voir Dossier" onclick="voirDossierEnseignant('<?php echo htmlspecialchars($enseignant['id_ens']); ?>')">
                                                    <i class="fas fa-folder-open"></i>
                                                </button>
                                                <button class="action-button edit" title="Modifier" onclick="modifierEnseignant('<?php echo htmlspecialchars($enseignant['id_ens']); ?>')">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="action-button reset" title="Réinitialiser mot de passe" onclick="resetPassword('<?php echo htmlspecialchars($enseignant['id_ens']); ?>')">
                                                    <i class="fas fa-key"></i>
                                                </button>
                                                <button class="action-button delete" title="Supprimer" onclick="supprimerEnseignant('<?php echo htmlspecialchars($enseignant['id_ens']); ?>')">
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

    <!-- Modal pour afficher les informations de l'enseignant -->
    <div id="teacherModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Dossier Enseignant</h2>
                <span class="close" onclick="fermerModal()">&times;</span>
            </div>
            <div id="modalBody">
                <!-- Contenu dynamique -->
            </div>
        </div>
    </div>

    <!-- Modal pour afficher le nouveau mot de passe -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Nouveau Mot de Passe</h2>
                <span class="close" onclick="fermerPasswordModal()">&times;</span>
            </div>
            <div id="passwordModalBody">
                <!-- Contenu dynamique -->
            </div>
            <div style="text-align: center; margin-top: var(--space-4);">
                <button class="btn btn-primary" onclick="fermerPasswordModal()">
                    <i class="fas fa-check"></i> Compris
                </button>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let selectedTeachers = new Set();
        let editingTeacher = null;
        const grades = <?php echo json_encode($grades); ?>;
        const fonctions = <?php echo json_encode($fonctions); ?>;

        // Éléments DOM
        const teacherForm = document.getElementById('teacherForm');
        const nomEnsInput = document.getElementById('nom_ens');
        const prenomEnsInput = document.getElementById('prenom_ens');
        const emailInput = document.getElementById('email');
        const gradeInput = document.getElementById('grade');
        const fonctionInput = document.getElementById('fonction');
        const teacherTableBody = document.querySelector('#teacherTable tbody');
        const modifierTeacherBtn = document.getElementById('modifierTeacherBtn');
        const supprimerTeacherBtn = document.getElementById('supprimerTeacherBtn');
        const exporterTeacherBtn = document.getElementById('exporterTeacherBtn');
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const cancelBtn = document.getElementById('cancelBtn');
        const alertMessage = document.getElementById('alertMessage');
        const teacherModal = document.getElementById('teacherModal');
        const passwordModal = document.getElementById('passwordModal');

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
            if (selectedTeachers.size === 1) {
                modifierTeacherBtn.disabled = false;
                supprimerTeacherBtn.disabled = false;
            } else if (selectedTeachers.size > 1) {
                modifierTeacherBtn.disabled = true;
                supprimerTeacherBtn.disabled = false;
            } else {
                modifierTeacherBtn.disabled = true;
                supprimerTeacherBtn.disabled = true;
            }
        }

        // Fonction pour ajouter une ligne dans le tableau
        function addRowToTable(enseignant) {
            // Supprimer le message "Aucun enseignant trouvé" s'il existe
            const emptyRow = teacherTableBody.querySelector('td[colspan="8"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }

            const newRow = teacherTableBody.insertRow();
            newRow.setAttribute('data-id', enseignant.id_ens);
            newRow.innerHTML = `
                <td>
                    <label class="checkbox-container">
                        <input type="checkbox" value="${enseignant.id_ens}">
                        <span class="checkmark"></span>
                    </label>
                </td>
                <td>${enseignant.nom_ens}</td>
                <td>${enseignant.prenom_ens}</td>
                <td>${enseignant.email}</td>
                <td>${enseignant.login}</td>
                <td>${enseignant.grade || 'N/A'}</td>
                <td>${enseignant.fonction || 'N/A'}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-button view" title="Voir Dossier" onclick="voirDossierEnseignant('${enseignant.id_ens}')">
                            <i class="fas fa-folder-open"></i>
                        </button>
                        <button class="action-button edit" title="Modifier" onclick="modifierEnseignant('${enseignant.id_ens}')">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button class="action-button reset" title="Réinitialiser mot de passe" onclick="resetPassword('${enseignant.id_ens}')">
                            <i class="fas fa-key"></i>
                        </button>
                        <button class="action-button delete" title="Supprimer" onclick="supprimerEnseignant('${enseignant.id_ens}')">
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
                    selectedTeachers.add(this.value);
                } else {
                    selectedTeachers.delete(this.value);
                }
                updateActionButtons();
            });
        }

        // Soumission du formulaire
        teacherForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = {
                action: editingTeacher ? 'update' : 'create',
                nom_ens: formData.get('nom_ens'),
                prenom_ens: formData.get('prenom_ens'),
                email: formData.get('email'),
                grade: formData.get('grade'),
                fonction: formData.get('fonction')
            };

            if (editingTeacher) {
                data.id_ens = editingTeacher;
            }

            try {
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;

                const result = await makeAjaxRequest(data);

                if (result.success) {
                    if (editingTeacher) {
                        // Mettre à jour la ligne existante
                        const row = document.querySelector(`tr[data-id="${editingTeacher}"]`);
                        if (row) {
                            row.cells[1].textContent = data.nom_ens;
                            row.cells[2].textContent = data.prenom_ens;
                            row.cells[3].textContent = data.email;
                            row.cells[5].textContent = data.grade || 'N/A';
                            row.cells[6].textContent = data.fonction || 'N/A';
                        }
                        showAlert('Enseignant modifié avec succès');
                        resetForm();
                    } else {
                        // Ajouter une nouvelle ligne
                        addRowToTable(result.data);
                        showAlert(`Enseignant "${data.prenom_ens} ${data.nom_ens}" créé avec succès. Login: ${result.data.login}, Mot de passe temporaire: ${result.data.motdepasse_temp}`, 'info');
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
            editingTeacher = null;
            submitText.textContent = 'Ajouter Enseignant';
            submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Ajouter Enseignant';
            teacherForm.reset();
        }

        // Bouton Annuler
        cancelBtn.addEventListener('click', function() {
            resetForm();
        });

        // Fonction pour voir le dossier d'un enseignant
        function voirDossierEnseignant(idEns) {
            const row = document.querySelector(`tr[data-id="${idEns}"]`);
            if (row) {
                const enseignantData = {
                    id: idEns,
                    nom: row.cells[1].textContent,
                    prenom: row.cells[2].textContent,
                    email: row.cells[3].textContent,
                    login: row.cells[4].textContent,
                    grade: row.cells[5].textContent,
                    fonction: row.cells[6].textContent
                };

                const modalBody = document.getElementById('modalBody');
                modalBody.innerHTML = `
                    <div style="display: grid; gap: var(--space-4);">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-3); background: var(--gray-50); border-radius: var(--radius-md);">
                            <strong>ID Enseignant:</strong>
                            <span>${enseignantData.id}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-3); background: var(--gray-50); border-radius: var(--radius-md);">
                            <strong>Nom complet:</strong>
                            <span>${enseignantData.prenom} ${enseignantData.nom}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-3); background: var(--gray-50); border-radius: var(--radius-md);">
                            <strong>Email:</strong>
                            <span>${enseignantData.email}</span>
                        </div>
                       
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-3); background: var(--gray-50); border-radius: var(--radius-md);">
                            <strong>Grade:</strong>
                            <span>${enseignantData.grade}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-3); background: var(--gray-50); border-radius: var(--radius-md);">
                            <strong>Fonction:</strong>
                            <span>${enseignantData.fonction}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-3); background: var(--accent-50); border: 1px solid var(--accent-200); border-radius: var(--radius-md);">
                            <strong>Groupe utilisateur:</strong>
                            <span style="color: var(--accent-700);">Commission de validation</span>
                        </div>
                    </div>
                `;
                
                teacherModal.style.display = 'block';
            }
        }

        // Fonction pour modifier un enseignant
        function modifierEnseignant(idEns) {
            const row = document.querySelector(`tr[data-id="${idEns}"]`);
            if (row) {
                editingTeacher = idEns;
                nomEnsInput.value = row.cells[1].textContent;
                prenomEnsInput.value = row.cells[2].textContent;
                emailInput.value = row.cells[3].textContent;
                
                // Sélectionner le grade et la fonction dans les selects
                const gradeText = row.cells[5].textContent;
                const fonctionText = row.cells[6].textContent;
                
                gradeInput.value = gradeText !== 'N/A' ? gradeText : '';
                fonctionInput.value = fonctionText !== 'N/A' ? fonctionText : '';
                
                submitText.textContent = 'Mettre à jour';
                submitBtn.innerHTML = '<i class="fas fa-edit"></i> Mettre à jour';
                
                // Faire défiler vers le formulaire
                document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Fonction pour supprimer un enseignant
        async function supprimerEnseignant(idEns) {
            const row = document.querySelector(`tr[data-id="${idEns}"]`);
            if (row) {
                const nomComplet = `${row.cells[2].textContent} ${row.cells[1].textContent}`;
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer l'enseignant ${nomComplet} ?\n\nCette action supprimera également son compte utilisateur et ne peut être annulée.`)) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'delete',
                            ids_enseignants: JSON.stringify([idEns])
                        });

                        if (result.success) {
                            row.remove();
                            selectedTeachers.delete(idEns);
                            updateActionButtons();
                            showAlert('Enseignant supprimé avec succès');
                            
                            // Si plus d'enseignants, afficher le message vide
                            if (teacherTableBody.children.length === 0) {
                                teacherTableBody.innerHTML = `
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                            <i class="fas fa-users" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                            Aucun enseignant trouvé. Ajoutez votre premier enseignant en utilisant le formulaire ci-dessus.
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

        // Fonction pour réinitialiser le mot de passe
        async function resetPassword(idEns) {
            const row = document.querySelector(`tr[data-id="${idEns}"]`);
            if (row) {
                const nomComplet = `${row.cells[2].textContent} ${row.cells[1].textContent}`;
                
                if (confirm(`Voulez-vous réinitialiser le mot de passe de ${nomComplet} ?\n\nUn nouveau mot de passe temporaire sera généré.`)) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'reset_password',
                            id_ens: idEns
                        });

                        if (result.success) {
                            // Afficher le nouveau mot de passe dans un modal
                            const passwordModalBody = document.getElementById('passwordModalBody');
                            passwordModalBody.innerHTML = `
                                <div style="text-align: center;">
                                    <div style="background: var(--success-50); border: 1px solid var(--success-200); border-radius: var(--radius-md); padding: var(--space-4); margin-bottom: var(--space-4);">
                                        <i class="fas fa-check-circle" style="color: var(--success-500); font-size: 2rem; margin-bottom: var(--space-2);"></i>
                                        <h3 style="color: var(--success-700); margin-bottom: var(--space-2);">Mot de passe réinitialisé</h3>
                                        <p style="color: var(--success-600);">Le mot de passe de <strong>${nomComplet}</strong> a été réinitialisé avec succès.</p>
                                    </div>
                                    <div style="background: var(--accent-50); border: 1px solid var(--accent-200); border-radius: var(--radius-md); padding: var(--space-4);">
                                        <h4 style="color: var(--accent-700); margin-bottom: var(--space-2);">Nouveau mot de passe temporaire :</h4>
                                        <div style="background: var(--white); border: 2px solid var(--accent-300); border-radius: var(--radius-md); padding: var(--space-3); margin: var(--space-2) 0;">
                                            <code style="font-size: var(--text-lg); font-weight: bold; color: var(--accent-800);">${result.nouveau_mdp}</code>
                                        </div>
                                        <p style="color: var(--accent-600); font-size: var(--text-sm);">
                                            <i class="fas fa-info-circle"></i> 
                                            Veuillez communiquer ce mot de passe à l'enseignant. Il devra le changer lors de sa première connexion.
                                        </p>
                                    </div>
                                </div>
                            `;
                            passwordModal.style.display = 'block';
                            showAlert('Mot de passe réinitialisé avec succès');
                        } else {
                            showAlert(result.message, 'error');
                        }
                    } catch (error) {
                        showAlert('Erreur lors de la réinitialisation', 'error');
                    }
                }
            }
        }

        // Bouton Modifier global
        modifierTeacherBtn.addEventListener('click', function() {
            if (selectedTeachers.size === 1) {
                const idEns = Array.from(selectedTeachers)[0];
                modifierEnseignant(idEns);
            }
        });

        // Bouton Supprimer global
        supprimerTeacherBtn.addEventListener('click', async function() {
            if (selectedTeachers.size > 0) {
                const idsArray = Array.from(selectedTeachers);
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer ${idsArray.length} enseignant(s) sélectionné(s) ?\n\nCette action supprimera également leurs comptes utilisateur et ne peut être annulée.`)) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'delete',
                            ids_enseignants: JSON.stringify(idsArray)
                        });

                        if (result.success) {
                            idsArray.forEach(id => {
                                const row = document.querySelector(`tr[data-id="${id}"]`);
                                if (row) row.remove();
                            });
                            selectedTeachers.clear();
                            updateActionButtons();
                            showAlert('Enseignant(s) supprimé(s) avec succès');
                            
                            // Si plus d'enseignants, afficher le message vide
                            if (teacherTableBody.children.length === 0) {
                                teacherTableBody.innerHTML = `
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                            <i class="fas fa-users" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                            Aucun enseignant trouvé. Ajoutez votre premier enseignant en utilisant le formulaire ci-dessus.
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
        exporterTeacherBtn.addEventListener('click', function() {
            // Vérifier s'il y a des enseignants à exporter
            const rows = document.querySelectorAll('#teacherTable tbody tr');
            if (rows.length === 1 && rows[0].querySelector('td[colspan="8"]')) {
                showAlert('Aucun enseignant à exporter', 'warning');
                return;
            }

            // Créer les données CSV
            const csvRows = [['Nom', 'Prénom', 'Email', 'Login', 'Grade', 'Fonction']];
            
            document.querySelectorAll('#teacherTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="8"]')) {
                    csvRows.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent,
                        row.cells[4].textContent,
                        row.cells[5].textContent,
                        row.cells[6].textContent
                    ]);
                }
            });

            // Créer le contenu CSV
            const csvContent = csvRows.map(row => row.join(',')).join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            
            // Télécharger le fichier
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `enseignants_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showAlert('Exportation terminée');
        });

        // Fonctions pour fermer les modals
        function fermerModal() {
            teacherModal.style.display = 'none';
        }

        function fermerPasswordModal() {
            passwordModal.style.display = 'none';
        }

        // Fermer les modals en cliquant en dehors
        window.onclick = function(event) {
            if (event.target === teacherModal) {
                fermerModal();
            }
            if (event.target === passwordModal) {
                fermerPasswordModal();
            }
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Attacher les événements aux lignes existantes
            document.querySelectorAll('#teacherTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="8"]')) {
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