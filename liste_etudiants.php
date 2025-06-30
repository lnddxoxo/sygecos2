<?php
// liste_etudiant.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Traitement AJAX pour récupérer les étudiants ou un seul étudiant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'get_etudiants') {
            $query = "
                SELECT 
                    e.num_etu,
                    e.nom_etu,
                    e.prenoms_etu,
                    e.dte_naiss_etu,
                    e.email_etu,
                    e.lieu_naissance,
                    e.telephone,
                    u.login_util,
                    ne.lib_niv_etu,
                    ne.id_niv_etu, -- Ajouté pour le filtrage
                    f.lib_filiere,
                    f.id_filiere, -- Ajouté pour le filtrage
                    aa.date_deb,
                    aa.date_fin,
                    aa.id_Ac, -- Ajouté pour le filtrage
                    CONCAT(YEAR(aa.date_deb), '-', YEAR(aa.date_fin)) as annee_academique,
                    i.dte_insc,
                    i.montant_insc,
                    CASE 
                        WHEN e.fk_id_util IS NOT NULL AND u.login_util IS NOT NULL AND u.login_util != '' THEN 'Oui'
                        ELSE 'Non'
                    END as a_identifiants
                FROM etudiant e
                LEFT JOIN utilisateur u ON e.fk_id_util = u.id_util
                LEFT JOIN inscrire i ON e.num_etu = i.fk_num_etu
                LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                LEFT JOIN année_academique aa ON i.fk_id_Ac = aa.id_Ac
                ORDER BY e.num_etu DESC
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $etudiants]);
        } elseif ($_POST['action'] === 'get_etudiant_details') {
            $numEtu = $_POST['num_etu'];
            $query = "
                SELECT 
                    e.num_etu,
                    e.nom_etu,
                    e.prenoms_etu,
                    e.dte_naiss_etu,
                    e.email_etu,
                    e.lieu_naissance,
                    e.telephone,
                    u.login_util,
                    ne.lib_niv_etu,
                    f.lib_filiere,
                    aa.date_deb,
                    aa.date_fin,
                    CONCAT(YEAR(aa.date_deb), '-', YEAR(aa.date_fin)) as annee_academique,
                    i.dte_insc,
                    i.montant_insc,
                    CASE 
                        WHEN e.fk_id_util IS NOT NULL AND u.login_util IS NOT NULL AND u.login_util != '' THEN 'Oui'
                        ELSE 'Non'
                    END as a_identifiants
                FROM etudiant e
                LEFT JOIN utilisateur u ON e.fk_id_util = u.id_util
                LEFT JOIN inscrire i ON e.num_etu = i.fk_num_etu
                LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                LEFT JOIN année_academique aa ON i.fk_id_Ac = aa.id_Ac
                WHERE e.num_etu = ?
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$numEtu]);
            $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($etudiant) {
                echo json_encode(['success' => true, 'data' => $etudiant]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Étudiant non trouvé']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des données : ' . $e->getMessage()]);
    }
    exit;
}

// Traitement AJAX pour supprimer un étudiant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supprimer_etudiant') {
    header('Content-Type: application/json');
    
    $numEtu = $_POST['num_etu'];
    
    try {
        $pdo->beginTransaction();
        
        // Récupérer l'ID utilisateur associé
        $stmtUser = $pdo->prepare("SELECT fk_id_util FROM etudiant WHERE num_etu = ?");
        $stmtUser->execute([$numEtu]);
        $etudiant = $stmtUser->fetch(PDO::FETCH_ASSOC);
        
        // Supprimer les inscriptions
        $stmtDelInsc = $pdo->prepare("DELETE FROM inscrire WHERE fk_num_etu = ?");
        $stmtDelInsc->execute([$numEtu]);
        
        // Supprimer l'étudiant
        $stmtDelEtu = $pdo->prepare("DELETE FROM etudiant WHERE num_etu = ?");
        $stmtDelEtu->execute([$numEtu]);
        
        // Supprimer l'utilisateur associé si il existe
        if ($etudiant && $etudiant['fk_id_util']) {
            $stmtDelUser = $pdo->prepare("DELETE FROM utilisateur WHERE id_util = ?");
            $stmtDelUser->execute([$etudiant['fk_id_util']]);
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Étudiant supprimé avec succès']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression : ' . $e->getMessage()]);
    }
    exit;
}

// Traitement AJAX pour supprimer plusieurs étudiants
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supprimer_multiple') {
    header('Content-Type: application/json');
    
    $numEtudiants = json_decode($_POST['num_etudiants'], true);
    
    try {
        $pdo->beginTransaction();
        
        $supprimerCount = 0;
        foreach ($numEtudiants as $numEtu) {
            // Récupérer l'ID utilisateur associé
            $stmtUser = $pdo->prepare("SELECT fk_id_util FROM etudiant WHERE num_etu = ?");
            $stmtUser->execute([$numEtu]);
            $etudiant = $stmtUser->fetch(PDO::FETCH_ASSOC);
            
            // Supprimer les inscriptions
            $stmtDelInsc = $pdo->prepare("DELETE FROM inscrire WHERE fk_num_etu = ?");
            $stmtDelInsc->execute([$numEtu]);
            
            // Supprimer l'étudiant
            $stmtDelEtu = $pdo->prepare("DELETE FROM etudiant WHERE num_etu = ?");
            $stmtDelEtu->execute([$numEtu]);
            
            // Supprimer l'utilisateur associé si il existe
            if ($etudiant && $etudiant['fk_id_util']) {
                $stmtDelUser = $pdo->prepare("DELETE FROM utilisateur WHERE id_util = ?");
                $stmtDelUser->execute([$etudiant['fk_id_util']]);
            }
            
            $supprimerCount++;
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "$supprimerCount étudiant(s) supprimé(s) avec succès"]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression : ' . $e->getMessage()]);
    }
    exit;
}

// Récupérer les données initiales pour le tableau et les filtres
$initialEtudiantsData = [];
$filieres = [];
$niveauxEtude = [];
$anneesAcademiques = [];

try {
    // Récupérer tous les étudiants
    $queryEtudiants = "
        SELECT 
            e.num_etu,
            e.nom_etu,
            e.prenoms_etu,
            e.dte_naiss_etu,
            e.email_etu,
            e.lieu_naissance,
            e.telephone,
            u.login_util,
            ne.lib_niv_etu,
            ne.id_niv_etu, -- Ajouté pour le filtrage
            f.lib_filiere,
            f.id_filiere, -- Ajouté pour le filtrage
            aa.date_deb,
            aa.date_fin,
            aa.id_Ac, -- Ajouté pour le filtrage
            CONCAT(YEAR(aa.date_deb), '-', YEAR(aa.date_fin)) as annee_academique,
            i.dte_insc,
            i.montant_insc,
            CASE 
                WHEN e.fk_id_util IS NOT NULL AND u.login_util IS NOT NULL AND u.login_util != '' THEN 'Oui'
                        ELSE 'Non'
                    END as a_identifiants
                FROM etudiant e
                LEFT JOIN utilisateur u ON e.fk_id_util = u.id_util
                LEFT JOIN inscrire i ON e.num_etu = i.fk_num_etu
                LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                LEFT JOIN année_academique aa ON i.fk_id_Ac = aa.id_Ac
                ORDER BY e.num_etu DESC
            ";
            $stmtEtudiants = $pdo->prepare($queryEtudiants);
            $stmtEtudiants->execute();
            $initialEtudiantsData = $stmtEtudiants->fetchAll(PDO::FETCH_ASSOC);

            // Récupérer toutes les filières
            $stmtFilieres = $pdo->query("SELECT id_filiere, lib_filiere FROM filiere ORDER BY lib_filiere");
            $filieres = $stmtFilieres->fetchAll(PDO::FETCH_ASSOC);

            // Récupérer tous les niveaux d'étude
            $stmtNiveaux = $pdo->query("SELECT id_niv_etu, lib_niv_etu FROM niveau_etude ORDER BY lib_niv_etu");
            $niveauxEtude = $stmtNiveaux->fetchAll(PDO::FETCH_ASSOC);

            // Récupérer toutes les années académiques
            $stmtAnnees = $pdo->query("SELECT id_Ac, date_deb, date_fin FROM année_academique ORDER BY date_deb DESC");
            $anneesAcademiques = $stmtAnnees->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log("Erreur lors du chargement des données initiales: " . $e->getMessage());
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
    <title>SYGECOS - Liste des Étudiants</title>
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
            --secondary-100: #dcfce7; /* Specific for success badge */
            --secondary-600: #16a34a; /* Specific for success badge text */


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
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.05);
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
            padding: var(--space-6);
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title-main {
            font-size: var(--text-3xl);
            font-weight: 700;
            color: var(--gray-900);
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: var(--text-lg);
            margin-top: var(--space-2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .stat-number {
            font-size: var(--text-3xl);
            font-weight: 700;
            color: var(--accent-600);
        }

        .stat-label {
            color: var(--gray-600);
            font-size: var(--text-sm);
            margin-top: var(--space-2);
        }

        .table-container {
            background: var(--white);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .table-header {
            padding: var(--space-6);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: var(--space-4);
        }

        .table-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
        }

        .table-actions {
            display: flex;
            gap: var(--space-3);
            align-items: center;
            flex-wrap: wrap;
        }
        
        /* Barre de recherche (uniformisée) */
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
            padding: var(--space-3) var(--space-10); /* Increased padding for icon */
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

        .download-button i {
            font-size: var(--text-base);
        }

        .bulk-actions { 
            padding: var(--space-4) var(--space-6); 
            border-bottom: 1px solid var(--gray-200); 
            background: var(--gray-50); 
            display: none; 
            align-items: center; 
            gap: var(--space-4); 
        }
        .bulk-actions.show { 
            display: flex; 
        }
        .selected-count { 
            font-weight: 600; 
            color: var(--gray-700); 
        }

        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px; /* Ensure table is wide enough */
        }

        .data-table th, .data-table td {
            padding: var(--space-4);
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table th {
            background-color: var(--gray-50);
            font-weight: 600;
            color: var(--gray-700);
            font-size: var(--text-sm);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .data-table tbody tr:hover { background-color: var(--gray-50); }
        .data-table td { font-size: var(--text-sm); color: var(--gray-800); }

        .checkbox-cell { width: 40px; }
        .checkbox-cell input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--accent-500); }
        
        /* Checkbox styling (from gestion_Ecue.php for consistency) */
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


        .badge { padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; }
        .badge-success { background-color: var(--secondary-100); color: var(--secondary-600); }
        .badge-warning { background-color: #fef3c7; color: #d97706; }

        .action-buttons { display: flex; gap: var(--space-2); }
        .btn { padding: var(--space-2) var(--space-4); border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); text-decoration: none; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover:not(:disabled) { background-color: var(--accent-700); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover:not(:disabled) { background-color: var(--secondary-600); }
        .btn-warning { background-color: var(--warning-500); color: white; } .btn-warning:hover:not(:disabled) { background-color: #e68a00; } /* Adjusted hover */
        .btn-danger { background-color: var(--error-500); color: white; } .btn-danger:hover:not(:disabled) { background-color: #dc2626; }
        .btn-outline { background-color: transparent; color: var(--accent-600); border: 1px solid var(--accent-600); } .btn-outline:hover { background-color: var(--accent-50); }
        .btn-sm { padding: var(--space-1) var(--space-3); font-size: var(--text-xs); }
        .btn-secondary { /* Added for filter, modify, delete buttons */
            background-color: var(--gray-200);
            color: var(--gray-700);
        }
        .btn-secondary:hover:not(:disabled) {
            background-color: var(--gray-300);
        }
        .btn-active-filter { /* Style for active filter button */
            background-color: var(--accent-200);
            color: var(--accent-800);
        }

        .loading-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); display: none; align-items: center; justify-content: center; z-index: 9999; }
        .loading-spinner { width: 40px; height: 40px; border: 4px solid var(--gray-300); border-top-color: var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .empty-state { text-align: center; padding: var(--space-16); color: var(--gray-500); }
        .empty-state i { font-size: 3rem; margin-bottom: var(--space-4); }

        /* Unified Message Modal Styles (from gestion_Ecue.php) */
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

        /* Modal Details */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 10000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.6); /* Darker overlay */
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background-color: #fefefe;
            padding: var(--space-8); /* More padding */
            border-radius: var(--radius-2xl); /* More rounded corners */
            box-shadow: var(--shadow-xl);
            width: 95%; /* Wider on smaller screens */
            max-width: 700px;
            max-height: 90vh; /* Limit height to viewport */
            overflow-y: auto; /* Scroll if content overflows */
            position: relative;
            transform: translateY(-50px);
            transition: transform 0.3s ease;
            box-sizing: border-box; /* Include padding in width/height */
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: var(--space-4);
            margin-bottom: var(--space-6);
        }

        .modal-header h2 {
            font-size: var(--text-2xl);
            color: var(--gray-900);
            font-weight: 700;
        }

        .modal-close {
            color: var(--gray-500);
            font-size: var(--text-3xl); /* Larger close icon */
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s ease;
            line-height: 1; /* Align vertically */
        }

        .modal-close:hover,
        .modal-close:focus {
            color: var(--gray-800);
            text-decoration: none;
        }

        .modal-body {
            padding-bottom: var(--space-6);
        }

        .detail-group {
            margin-bottom: var(--space-6); /* Space between groups */
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            background-color: var(--gray-50);
        }

        .detail-group-title {
            font-size: var(--text-lg);
            font-weight: 600;
            color: var(--primary-700);
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-2);
            border-bottom: 1px dashed var(--gray-300);
        }

        .detail-item {
            display: flex;
            flex-wrap: wrap; /* Allow wrapping on small screens */
            margin-bottom: var(--space-3); /* More space between items */
            font-size: var(--text-base);
            align-items: baseline;
        }

        .detail-item strong {
            flex-basis: 180px; /* Wider label column */
            color: var(--gray-700);
            font-weight: 600;
            padding-right: var(--space-2);
            line-height: 1.5;
        }

        .detail-item span {
            flex: 1;
            color: var(--gray-800);
            line-height: 1.5;
        }
        
        @media (max-width: 500px) {
            .detail-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .detail-item strong {
                flex-basis: auto;
                width: 100%;
                margin-bottom: var(--space-1);
            }
        }

        .modal-footer {
            border-top: 1px solid var(--gray-200);
            padding-top: var(--space-4);
            text-align: right;
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
        }

        #etudiantDetailsContent {
            /* No direct padding here, handled by .detail-group */
            /* Removed background-color: #fefefe; as it's set on modal-content already */
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
                display: inline-block; /* Show bars icon by default on desktop for toggle */
            }
            .sidebar-toggle .fa-times {
                display: none;
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
            /* When sidebar is open on mobile, show times */
            .sidebar.mobile-open + .topbar .sidebar-toggle .fa-bars {
                display: none;
            }
            .sidebar.mobile-open + .topbar .sidebar-toggle .fa-times {
                display: inline-block;
            }

            .stats-grid { grid-template-columns: 1fr; }
            
            .page-header {
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

            /* Hide action text on smaller screens for action buttons */
            .action-text {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: var(--space-4);
            }
            
            .table-container {
                padding: var(--space-4);
            }
            
            .page-title-main {
                font-size: var(--text-2xl);
            }
            
            .page-subtitle {
                font-size: var(--text-base);
            }
            
            .table-actions {
                flex-wrap: wrap;
                gap: var(--space-2);
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_respo_scolarité.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1 class="page-title-main">Liste des Étudiants</h1>
                        <p class="page-subtitle">Gestion et consultation des étudiants inscrits</p>
                    </div>
                    <div>
                        <a href="inscription_etudiant.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Nouvel Étudiant
                        </a>
                    </div>
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

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number" id="totalEtudiants">0</div>
                        <div class="stat-label">Total Étudiants</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="avecIdentifiants">0</div>
                        <div class="stat-label">Avec Identifiants</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="sansIdentifiants">0</div>
                        <div class="stat-label">Sans Identifiants</div>
                    </div>
                </div>

                <div class="search-bar">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher un étudiant...">
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

                <div class="table-container">
                    <div class="table-header">
                        <h3 class="table-title">Liste des Étudiants</h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" id="filterButton">
                                <i class="fas fa-filter"></i> <span id="filterButtonText">Filtres</span>
                            </button>
                            <button class="btn btn-secondary" id="modifierEtudiantBtn" disabled>
                                <i class="fas fa-edit"></i> <span class="action-text">Modifier</span>
                            </button>
                            <button class="btn btn-secondary" id="supprimerEtudiantBtn" disabled>
                                <i class="fas fa-trash-alt"></i> <span class="action-text">Supprimer</span>
                            </button>
                        </div>
                    </div>

                    <div class="bulk-actions" id="bulkActions">
                        <span class="selected-count" id="selectedCount">0 étudiant(s) sélectionné(s)</span>
                        <button onclick="supprimerSelection()" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Supprimer sélection
                        </button>
                        <button onclick="exportSelection()" class="btn btn-success">
                            <i class="fas fa-file-excel"></i> Export sélection
                        </button>
                    </div>
                    
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="checkbox-cell">
                                        <label class="checkbox-container">
                                            <input type="checkbox" id="selectAll">
                                            <span class="checkmark"></span>
                                        </label>
                                    </th>
                                    <th>N° Étudiant</th>
                                    <th>Nom & Prénoms</th>
                                    <th>Niveau</th>
                                    <th>Filière</th>
                                    <th>Année Académique</th>
                                    <th>Identifiants</th>
                                    <th style="width: 150px;">Actions</th> </tr>
                            </thead>
                            <tbody id="etudiantsTableBody">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="etudiantDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Détails de l'Étudiant</h2>
                <span class="modal-close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="etudiantDetailsContent">
                <div class="detail-group">
                    <h3 class="detail-group-title">Informations Personnelles</h3>
                    <div class="detail-item">
                        <strong>N° Étudiant:</strong> <span id="detailNumEtu"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Nom & Prénoms:</strong> <span id="detailNomPrenoms"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Email:</strong> <span id="detailEmail"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Date de Naissance:</strong> <span id="detailDateNaissance"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Lieu de Naissance:</strong> <span id="detailLieuNaissance"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Téléphone:</strong> <span id="detailTelephone"></span>
                    </div>
                </div>

                <div class="detail-group">
                    <h3 class="detail-group-title">Informations Académiques</h3>
                    <div class="detail-item">
                        <strong>Niveau d'Étude:</strong> <span id="detailNiveau"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Filière:</strong> <span id="detailFiliere"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Année Académique:</strong> <span id="detailAnneeAcademique"></span>
                    </div>
                </div>

                <div class="detail-group">
                    <h3 class="detail-group-title">Informations d'Inscription</h3>
                    <div class="detail-item">
                        <strong>Date d'Inscription:</strong> <span id="detailDateInscription"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Montant Inscription:</strong> <span id="detailMontantInscription"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Identifiants de Connexion:</strong> <span id="detailIdentifiants"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal()" class="btn btn-secondary">
                    Fermer
                </button>
                <button onclick="downloadStudentDetailsPdf()" class="btn btn-primary">
                    <i class="fas fa-file-pdf"></i> Télécharger PDF
                </button>
            </div>
        </div>
    </div>

    <div class="filter-modal" id="filterModal">
        <div class="filter-modal-content">
            <div class="filter-modal-header">
                <h3 class="filter-modal-title">Filtres et Tri des Étudiants</h3>
                <button class="filter-modal-close" id="closeFilterModal">&times;</button>
            </div>
            <div class="filter-group">
                <label>Trier par:</label>
                <div class="filter-option-group">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="default" checked>
                            <i class="fas fa-list"></i> Ordre initial
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="name-asc">
                            <i class="fas fa-sort-alpha-down"></i> Nom (A-Z)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="name-desc">
                            <i class="fas fa-sort-alpha-up"></i> Nom (Z-A)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="num-asc">
                            <i class="fas fa-sort-numeric-down"></i> N° Étudiant (croissant)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="num-desc">
                            <i class="fas fa-sort-numeric-up"></i> N° Étudiant (décroissant)
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="filter-group">
                <label>Filtrer par Filière:</label>
                <div class="filter-option-group" id="filiereFilterRadioGroup">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="filiere_filter_radio" value="all_filieres" checked>
                            Toutes les Filières
                        </label>
                    </div>
                    <?php foreach ($filieres as $filiere): ?>
                        <div class="filter-option radio-group">
                            <label>
                                <input type="radio" name="filiere_filter_radio" value="<?php echo htmlspecialchars($filiere['id_filiere']); ?>">
                                <?php echo htmlspecialchars($filiere['lib_filiere']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="filter-group">
                <label>Filtrer par Niveau d'Étude:</label>
                <div class="filter-option-group" id="niveauFilterRadioGroup">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="niveau_filter_radio" value="all_niveaux" checked>
                            Tous les Niveaux
                        </label>
                    </div>
                    <?php foreach ($niveauxEtude as $niveau): ?>
                        <div class="filter-option radio-group">
                            <label>
                                <input type="radio" name="niveau_filter_radio" value="<?php echo htmlspecialchars($niveau['id_niv_etu']); ?>">
                                <?php echo htmlspecialchars($niveau['lib_niv_etu']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="filter-group">
                <label>Filtrer par Année Académique:</label>
                <div class="filter-option-group" id="anneeFilterRadioGroup">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="annee_filter_radio" value="all_annees" checked>
                            Toutes les Années
                        </label>
                    </div>
                    <?php foreach ($anneesAcademiques as $annee): ?>
                        <div class="filter-option radio-group">
                            <label>
                                <input type="radio" name="annee_filter_radio" value="<?php echo htmlspecialchars($annee['id_Ac']); ?>">
                                <?php echo htmlspecialchars(formatAnneeAcademique($annee['date_deb'], $annee['date_fin'])); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="filter-actions-dropdown">
                <button class="btn btn-secondary" id="resetFilterModalBtn">Réinitialiser</button>
                <button class="btn btn-primary" id="applyFilterModalBtn">Appliquer</button>
            </div>
        </div>
    </div>


    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        // Initialiser jsPDF
        window.jsPDF = window.jspdf.jsPDF;

        let etudiantsData = []; // Full dataset from the server
        let selectedEtudiants = new Set(); // Stores num_etu of selected students
        let currentStudentDetails = null; // Store details of the currently viewed student for PDF export

        // Filter and Sort states
        let currentSortType = 'default';
        let currentFiliereFilter = 'all_filieres';
        let currentNiveauFilter = 'all_niveaux';
        let currentAnneeFilter = 'all_annees';

        // DOM Elements
        const etudiantsTableBody = document.getElementById('etudiantsTableBody');
        const selectAllCheckbox = document.getElementById('selectAll');
        const bulkActionsDiv = document.getElementById('bulkActions');
        const selectedCountSpan = document.getElementById('selectedCount');
        const modifierEtudiantBtn = document.getElementById('modifierEtudiantBtn');
        const supprimerEtudiantBtn = document.getElementById('supprimerEtudiantBtn');
        const searchInput = document.getElementById('searchInput');
        const searchButton = document.getElementById('searchButton');
        const exportPdfBtn = document.getElementById('exportPdfBtn');
        const exportExcelBtn = document.getElementById('exportExcelBtn');
        const exportCsvBtn = document.getElementById('exportCsvBtn');
        const filterButton = document.getElementById('filterButton');
        const filterButtonText = document.getElementById('filterButtonText');
        const filterModal = document.getElementById('filterModal');
        const closeFilterModalBtn = document.getElementById('closeFilterModal');
        const applyFilterModalBtn = document.getElementById('applyFilterModalBtn');
        const resetFilterModalBtn = document.getElementById('resetFilterModalBtn');

        // Message Modal elements
        const messageModal = document.getElementById('messageModal');
        const messageTitle = document.getElementById('messageTitle');
        const messageText = document.getElementById('messageText');
        const messageIcon = document.getElementById('messageIcon');
        const messageButton = document.getElementById('messageButton');
        const messageClose = document.getElementById('messageClose');

        // Sidebar elements for responsiveness
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');


        // --- Utility Functions ---

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

        // Function to show/hide loading overlay
        function showLoading(show) {
            const overlay = document.getElementById('loadingOverlay');
            overlay.style.display = show ? 'flex' : 'none';
        }

        // Format date string to French locale
        function formatDate(dateStr) {
            if (!dateStr || dateStr === '0000-00-00' || dateStr.includes('N/A')) return 'N/A';
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return 'N/A'; // Check for invalid date
            return date.toLocaleDateString('fr-FR');
        }

        // --- Data Loading and Display ---

        // Function to load all students via AJAX
        async function loadEtudiants() {
            try {
                showLoading(true);
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'get_etudiants' })
                });
                const result = await response.json();
                if (result.success) {
                    etudiantsData = result.data; // Store the full dataset
                    applyFiltersAndSort(
                        currentSortType, 
                        currentFiliereFilter, 
                        currentNiveauFilter, 
                        currentAnneeFilter, 
                        searchInput.value
                    ); // Apply filters and display
                    updateStats(etudiantsData);
                } else {
                    showAlert('Erreur lors du chargement des données: ' + result.message, 'error');
                    displayEmptyState('Erreur lors du chargement des données');
                }
            } catch (error) {
                console.error('Erreur AJAX:', error);
                showAlert('Erreur de connexion au serveur.', 'error');
                displayEmptyState('Erreur de connexion');
            } finally {
                showLoading(false);
            }
        }

        // Render table rows from an array of student objects
        function renderTable(studentsToRender) {
            etudiantsTableBody.innerHTML = ''; // Clear existing rows
            selectedEtudiants.clear(); // Clear selections
            updateSelection(); // Update buttons state after clearing selections

            if (studentsToRender.length === 0) {
                displayEmptyState('Aucun étudiant trouvé correspondant à vos critères');
                return;
            }

            studentsToRender.forEach(etudiant => {
                const identifiantsBadge = etudiant.a_identifiants === 'Oui' 
                    ? '<span class="badge badge-success">Oui</span>' 
                    : '<span class="badge badge-warning">Non</span>';

                const newRow = etudiantsTableBody.insertRow();
                newRow.setAttribute('data-num-etu', etudiant.num_etu);
                newRow.innerHTML = `
                    <td class="checkbox-cell">
                        <label class="checkbox-container">
                            <input type="checkbox" class="etudiant-checkbox" value="${etudiant.num_etu}">
                            <span class="checkmark"></span>
                        </label>
                    </td>
                    <td><strong>${etudiant.num_etu || 'N/A'}</strong></td>
                    <td>
                        <div>
                            <strong>${etudiant.nom_etu || ''} ${etudiant.prenoms_etu || ''}</strong>
                        </div>
                    </td>
                    <td>${etudiant.lib_niv_etu || 'N/A'}</td>
                    <td>${etudiant.lib_filiere || 'N/A'}</td>
                    <td>${etudiant.annee_academique || 'N/A'}</td>
                    <td>${identifiantsBadge}</td>
                    <td>
                        <div class="action-buttons">
                            <button onclick="showEtudiantDetails('${etudiant.num_etu}')" class="btn btn-sm btn-outline" title="Voir les détails">
                                <i class="fas fa-folder-open"></i>
                            </button>
                            <button onclick="modifierEtudiant('${etudiant.num_etu}')" class="btn btn-sm btn-warning" title="Modifier">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="supprimerEtudiant('${etudiant.num_etu}', '${etudiant.nom_etu} ${etudiant.prenoms_etu}')" class="btn btn-sm btn-danger" title="Supprimer">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                `;
                attachEventListenersToRow(newRow);
            });
        }

        // Attach event listeners to rows (checkboxes)
        function attachEventListenersToRow(row) {
            const checkbox = row.querySelector('input[type="checkbox"]');
            
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    selectedEtudiants.add(this.value);
                } else {
                    selectedEtudiants.delete(this.value);
                }
                updateSelection();
            });
        }

        // Update statistics cards
        function updateStats(etudiants) {
            const total = etudiants.length;
            const avecIdentifiants = etudiants.filter(e => e.a_identifiants === 'Oui').length;
            const sansIdentifiants = total - avecIdentifiants;

            document.getElementById('totalEtudiants').textContent = total;
            document.getElementById('avecIdentifiants').textContent = avecIdentifiants;
            document.getElementById('sansIdentifiants').textContent = sansIdentifiants;
        }

        // Display empty state message in table
        function displayEmptyState(message) {
            etudiantsTableBody.innerHTML = `
                <tr>
                    <td colspan="8" class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>${message}</p>
                    </td>
                </tr>
            `;
        }

        // --- Selection and Bulk Actions ---

        // Toggle all checkboxes
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.etudiant-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                if (this.checked) {
                    selectedEtudiants.add(checkbox.value);
                } else {
                    selectedEtudiants.delete(checkbox.value);
                }
            });
            updateSelection();
        });

        // Update selection state and bulk action buttons
        function updateSelection() {
            const checkedCheckboxes = document.querySelectorAll('.etudiant-checkbox:checked');
            selectedEtudiants = new Set(Array.from(checkedCheckboxes).map(cb => cb.value));
            
            if (selectedEtudiants.size > 0) {
                bulkActionsDiv.classList.add('show');
                selectedCountSpan.textContent = `${selectedEtudiants.size} étudiant(s) sélectionné(s)`;
                supprimerEtudiantBtn.disabled = false;
                modifierEtudiantBtn.disabled = selectedEtudiants.size !== 1; // Only enable modify for single selection
            } else {
                bulkActionsDiv.classList.remove('show');
                supprimerEtudiantBtn.disabled = true;
                modifierEtudiantBtn.disabled = true;
            }
            
            // Update "Select All" checkbox state
            const totalCheckboxes = document.querySelectorAll('.etudiant-checkbox').length;
            selectAllCheckbox.indeterminate = selectedEtudiants.size > 0 && selectedEtudiants.size < totalCheckboxes;
            selectAllCheckbox.checked = selectedEtudiants.size === totalCheckboxes && totalCheckboxes > 0;
        }

        // --- Student Actions (Modify, Delete) ---

        // Redirect to modify page
        modifierEtudiantBtn.addEventListener('click', function() {
            if (selectedEtudiants.size === 1) {
                const numEtu = Array.from(selectedEtudiants)[0];
                window.location.href = `modifier_etudiant.php?etudiant=${numEtu}`;
            } else {
                showAlert('Veuillez sélectionner un seul étudiant à modifier.', 'warning');
            }
        });

        function modifierEtudiant(numEtu) {
            window.location.href = `modifier_etudiant.php?etudiant=${numEtu}`;
        }

        // Delete single student
        async function supprimerEtudiant(numEtu, nomComplet) {
            if (!confirm(`Êtes-vous sûr de vouloir supprimer l'étudiant ${nomComplet} ?\n\nCette action est irréversible et supprimera :\n- Toutes les inscriptions\n- Les identifiants de connexion\n- Toutes les données associées`)) {
                return;
            }

            try {
                showLoading(true);
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'supprimer_etudiant', num_etu: numEtu })
                });
                const result = await response.json();
                if (result.success) {
                    showAlert(result.message, 'success');
                    loadEtudiants(); // Reload the list
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                console.error('Erreur:', error);
                showAlert('Erreur lors de la suppression', 'error');
            } finally {
                showLoading(false);
            }
        }

        // Delete multiple selected students
        async function supprimerSelection() {
            if (selectedEtudiants.size === 0) {
                showAlert('Aucun étudiant sélectionné', 'warning');
                return;
            }

            if (!confirm(`Êtes-vous sûr de vouloir supprimer ${selectedEtudiants.size} étudiant(s) sélectionné(s) ?\n\nCette action est irréversible.`)) {
                return;
            }

            try {
                showLoading(true);
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'supprimer_multiple', num_etudiants: JSON.stringify(Array.from(selectedEtudiants)) })
                });
                const result = await response.json();
                if (result.success) {
                    showAlert(result.message, 'success');
                    selectedEtudiants.clear(); // Clear local selection
                    selectAllCheckbox.checked = false; // Uncheck "Select All"
                    loadEtudiants(); // Reload the list
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                console.error('Erreur:', error);
                showAlert('Erreur lors de la suppression', 'error');
            } finally {
                showLoading(false);
            }
        }

        // --- Export Functions ---

        // Get filtered and sorted data for export
        function getExportData(onlySelected = false) {
            const headers = [
                'N° Étudiant', 'Nom', 'Prénoms', 'Email', 'Date de naissance', 'Lieu de naissance',
                'Téléphone', 'Niveau', 'Filière', 'Année académique', 'Date inscription', 'Identifiants'
            ];
            
            let dataToExport = [];
            if (onlySelected) {
                dataToExport = Array.from(selectedEtudiants).map(numEtu => 
                    etudiantsData.find(e => e.num_etu === numEtu)
                ).filter(Boolean); // Remove any undefined if not found
            } else {
                // Get data from currently displayed table rows (after search/filter)
                const visibleRows = Array.from(etudiantsTableBody.querySelectorAll('tr[data-num-etu]'));
                const visibleNumEtus = visibleRows.map(row => row.getAttribute('data-num-etu'));
                dataToExport = etudiantsData.filter(e => visibleNumEtus.includes(e.num_etu));
            }

            const rows = dataToExport.map(etudiant => [
                etudiant.num_etu || 'N/A',
                etudiant.nom_etu || '',
                etudiant.prenoms_etu || '',
                etudiant.email_etu || 'N/A',
                formatDate(etudiant.dte_naiss_etu),
                etudiant.lieu_naissance || 'N/A',
                etudiant.telephone || 'N/A',
                etudiant.lib_niv_etu || 'N/A',
                etudiant.lib_filiere || 'N/A',
                etudiant.annee_academique || 'N/A',
                formatDate(etudiant.dte_insc),
                etudiant.a_identifiants || 'N/A'
            ]);
            
            return { headers, rows };
        }

        // Export to PDF
        exportPdfBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) {
                    showAlert("Aucune donnée visible à exporter.", 'warning');
                    return;
                }
                
                const doc = new jsPDF('landscape'); // Use landscape for more columns
                doc.setFontSize(14);
                doc.text('Liste des Étudiants', 14, 15);
                doc.setFontSize(10);
                doc.text(`Exporté le: ${new Date().toLocaleDateString('fr-FR')}`, 14, 22);
                doc.autoTable({
                    head: [headers],
                    body: rows,
                    startY: 25,
                    styles: { fontSize: 8 },
                    headStyles: { fillColor: [59, 130, 246] },
                    margin: { left: 10, right: 10 }
                });
                
                doc.save(`liste_etudiants_${new Date().toISOString().slice(0,10)}.pdf`);
                showAlert("Export PDF réussi !", 'success');
            } catch (error) {
                console.error("Erreur lors de l'export PDF:", error);
                showAlert("Erreur lors de l'export PDF", 'error');
            }
        });

        // Export to Excel
        exportExcelBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) {
                    showAlert("Aucune donnée visible à exporter.", 'warning');
                    return;
                }
                
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
                XLSX.utils.book_append_sheet(wb, ws, "Etudiants");
                XLSX.writeFile(wb, `liste_etudiants_${new Date().toISOString().slice(0,10)}.xlsx`);
                showAlert("Export Excel réussi !", 'success');
            } catch (error) {
                console.error("Erreur lors de l'export Excel:", error);
                showAlert("Erreur lors de l'export Excel", 'error');
            }
        });

        // Export to CSV
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
                link.setAttribute("download", `liste_etudiants_${new Date().toISOString().slice(0,10)}.csv`);
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

        // Export selected students to Excel (from bulk actions)
        function exportSelection() {
            if (selectedEtudiants.size === 0) {
                showAlert('Aucun étudiant sélectionné pour l\'export.', 'warning');
                return;
            }

            try {
                const { headers, rows } = getExportData(true); // true to get only selected
                
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
                XLSX.utils.book_append_sheet(wb, ws, "Etudiants Selection");
                XLSX.writeFile(wb, `selection_etudiants_${new Date().toISOString().slice(0,10)}.xlsx`);
                showAlert("Export Excel de la sélection réussi !", 'success');
            } catch (error) {
                console.error("Erreur lors de l'export de la sélection Excel:", error);
                showAlert("Erreur lors de l'export de la sélection Excel", 'error');
            }
        }

        // --- Search, Filter, Sort Logic ---

        // Global function to apply all filters and sorting
        function applyFiltersAndSort(sortType, filiereFilter, niveauFilter, anneeFilter, searchTerm) {
            let filteredAndSortedData = [...etudiantsData];

            // 1. Apply Search
            if (searchTerm) {
                const lowerCaseSearchTerm = searchTerm.toLowerCase();
                filteredAndSortedData = filteredAndSortedData.filter(etudiant => {
                    return (
                        (etudiant.nom_etu && etudiant.nom_etu.toLowerCase().includes(lowerCaseSearchTerm)) ||
                        (etudiant.prenoms_etu && etudiant.prenoms_etu.toLowerCase().includes(lowerCaseSearchTerm)) ||
                        (etudiant.email_etu && etudiant.email_etu.toLowerCase().includes(lowerCaseSearchTerm)) ||
                        (etudiant.num_etu && etudiant.num_etu.toString().includes(lowerCaseSearchTerm)) ||
                        (etudiant.lib_niv_etu && etudiant.lib_niv_etu.toLowerCase().includes(lowerCaseSearchTerm)) ||
                        (etudiant.lib_filiere && etudiant.lib_filiere.toLowerCase().includes(lowerCaseSearchTerm)) ||
                        (etudiant.annee_academique && etudiant.annee_academique.toLowerCase().includes(lowerCaseSearchTerm))
                    );
                });
            }

            // 2. Apply Filiere Filter
            if (filiereFilter !== 'all_filieres') {
                filteredAndSortedData = filteredAndSortedData.filter(etudiant => etudiant.id_filiere == filiereFilter);
            }

            // 3. Apply Niveau Filter
            if (niveauFilter !== 'all_niveaux') {
                filteredAndSortedData = filteredAndSortedData.filter(etudiant => etudiant.id_niv_etu == niveauFilter);
            }

            // 4. Apply Annee Academique Filter
            if (anneeFilter !== 'all_annees') {
                filteredAndSortedData = filteredAndSortedData.filter(etudiant => etudiant.id_Ac == anneeFilter);
            }

            // 5. Apply Sorting
            filteredAndSortedData.sort((a, b) => {
                switch (sortType) {
                    case 'name-asc': 
                        return (a.nom_etu + ' ' + a.prenoms_etu).localeCompare(b.nom_etu + ' ' + b.prenoms_etu);
                    case 'name-desc':
                        return (b.nom_etu + ' ' + b.prenoms_etu).localeCompare(a.nom_etu + ' ' + a.prenoms_etu);
                    case 'num-asc':
                        return parseInt(a.num_etu) - parseInt(b.num_etu);
                    case 'num-desc':
                        return parseInt(b.num_etu) - parseInt(a.num_etu);
                    case 'default':
                    default:
                        // No specific sort order, maintain the order from filtering (or initial if no filter)
                        // For stable sort, you might want to sort by a unique ID if available.
                        // Since DB query sorts by num_etu DESC, we can fallback to that if 'default' sort is truly needed.
                        return 0; 
                }
            });

            renderTable(filteredAndSortedData);
            updateFilterButtonText();
        }

        // Search event listeners
        searchInput.addEventListener('input', () => {
            // When searching, reset filter selections visually and logically
            document.querySelector('input[name="sort_radio"][value="default"]').checked = true;
            document.querySelector('input[name="filiere_filter_radio"][value="all_filieres"]').checked = true;
            document.querySelector('input[name="niveau_filter_radio"][value="all_niveaux"]').checked = true;
            document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true;

            currentSortType = 'default';
            currentFiliereFilter = 'all_filieres';
            currentNiveauFilter = 'all_niveaux';
            currentAnneeFilter = 'all_annees';

            applyFiltersAndSort(
                currentSortType, 
                currentFiliereFilter, 
                currentNiveauFilter, 
                currentAnneeFilter, 
                searchInput.value
            );
        });
        searchButton.addEventListener('click', () => {
            // Same logic as input for consistency when button is clicked
            document.querySelector('input[name="sort_radio"][value="default"]').checked = true;
            document.querySelector('input[name="filiere_filter_radio"][value="all_filieres"]').checked = true;
            document.querySelector('input[name="niveau_filter_radio"][value="all_niveaux"]').checked = true;
            document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true;

            currentSortType = 'default';
            currentFiliereFilter = 'all_filieres';
            currentNiveauFilter = 'all_niveaux';
            currentAnneeFilter = 'all_annees';

            applyFiltersAndSort(
                currentSortType, 
                currentFiliereFilter, 
                currentNiveauFilter, 
                currentAnneeFilter, 
                searchInput.value
            );
        });

        // Filter modal handling
        filterButton.addEventListener('click', function(event) {
            event.stopPropagation();
            filterModal.style.display = 'flex';
        });

        closeFilterModalBtn.addEventListener('click', function() {
            filterModal.style.display = 'none';
        });

        filterModal.addEventListener('click', function(e) {
            if (e.target === filterModal) {
                filterModal.style.display = 'none';
            }
        });

        applyFilterModalBtn.addEventListener('click', function() {
            const selectedSortRadio = document.querySelector('input[name="sort_radio"]:checked');
            if (selectedSortRadio) {
                currentSortType = selectedSortRadio.value;
            }

            const selectedFiliereFilterRadio = document.querySelector('input[name="filiere_filter_radio"]:checked');
            if (selectedFiliereFilterRadio) {
                currentFiliereFilter = selectedFiliereFilterRadio.value;
            }

            const selectedNiveauFilterRadio = document.querySelector('input[name="niveau_filter_radio"]:checked');
            if (selectedNiveauFilterRadio) {
                currentNiveauFilter = selectedNiveauFilterRadio.value;
            }

            const selectedAnneeFilterRadio = document.querySelector('input[name="annee_filter_radio"]:checked');
            if (selectedAnneeFilterRadio) {
                currentAnneeFilter = selectedAnneeFilterRadio.value;
            }
            
            // Clear search input when applying filters from modal
            searchInput.value = '';

            applyFiltersAndSort(
                currentSortType, 
                currentFiliereFilter, 
                currentNiveauFilter, 
                currentAnneeFilter, 
                searchInput.value
            );
            filterModal.style.display = 'none';
        });

        resetFilterModalBtn.addEventListener('click', function() {
            currentSortType = 'default';
            currentFiliereFilter = 'all_filieres';
            currentNiveauFilter = 'all_niveaux';
            currentAnneeFilter = 'all_annees';
            searchInput.value = '';

            document.querySelector('input[name="sort_radio"][value="default"]').checked = true;
            document.querySelector('input[name="filiere_filter_radio"][value="all_filieres"]').checked = true;
            document.querySelector('input[name="niveau_filter_radio"][value="all_niveaux"]').checked = true;
            document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true;

            applyFiltersAndSort(currentSortType, currentFiliereFilter, currentNiveauFilter, currentAnneeFilter, '');
            filterModal.style.display = 'none';
            showAlert('Filtres et recherche réinitialisés.', 'info');
        });

        // Update filter button text based on active filters
        function updateFilterButtonText() {
            let activeFiltersCount = 0;
            if (currentSortType !== 'default') {
                activeFiltersCount++;
            }
            if (currentFiliereFilter !== 'all_filieres') {
                activeFiltersCount++;
            }
            if (currentNiveauFilter !== 'all_niveaux') {
                activeFiltersCount++;
            }
            if (currentAnneeFilter !== 'all_annees') {
                activeFiltersCount++;
            }
            if (searchInput.value.trim() !== '') {
                activeFiltersCount++;
            }

            if (activeFiltersCount > 0) {
                filterButtonText.textContent = `Filtres (${activeFiltersCount} actifs)`;
                filterButton.classList.add('btn-active-filter'); 
            } else {
                filterButtonText.textContent = 'Filtres';
                filterButton.classList.remove('btn-active-filter');
            }
        }

        // --- Details Modal ---

        const etudiantDetailsModal = document.getElementById('etudiantDetailsModal');

        async function showEtudiantDetails(numEtu) {
            try {
                showLoading(true);
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'get_etudiant_details', num_etu: numEtu })
                });
                const result = await response.json();

                if (result.success && result.data) {
                    currentStudentDetails = result.data; // Store for PDF export
                    const etudiant = currentStudentDetails;

                    document.getElementById('modalTitle').textContent = `Détails de ${etudiant.nom_etu} ${etudiant.prenoms_etu}`;
                    document.getElementById('detailNumEtu').textContent = etudiant.num_etu || 'N/A';
                    document.getElementById('detailNomPrenoms').textContent = `${etudiant.nom_etu || ''} ${etudiant.prenoms_etu || ''}`;
                    document.getElementById('detailEmail').textContent = etudiant.email_etu || 'N/A';
                    document.getElementById('detailDateNaissance').textContent = formatDate(etudiant.dte_naiss_etu);
                    document.getElementById('detailLieuNaissance').textContent = etudiant.lieu_naissance || 'N/A';
                    document.getElementById('detailTelephone').textContent = etudiant.telephone || 'N/A';
                    document.getElementById('detailNiveau').textContent = etudiant.lib_niv_etu || 'N/A';
                    document.getElementById('detailFiliere').textContent = etudiant.lib_filiere || 'N/A';
                    document.getElementById('detailAnneeAcademique').textContent = etudiant.annee_academique || 'N/A';
                    document.getElementById('detailDateInscription').textContent = formatDate(etudiant.dte_insc);
                    document.getElementById('detailMontantInscription').textContent = etudiant.montant_insc ? `${parseInt(etudiant.montant_insc).toLocaleString('fr-FR')} FCFA` : 'N/A'; // Formatage monétaire
                    document.getElementById('detailIdentifiants').textContent = etudiant.a_identifiants || 'N/A';

                    etudiantDetailsModal.classList.add('show');
                } else {
                    showAlert(result.message || 'Erreur lors du chargement des détails de l\'étudiant', 'error');
                }
            } catch (error) {
                console.error('Erreur AJAX pour les détails:', error);
                showAlert('Erreur de connexion lors du chargement des détails', 'error');
            } finally {
                showLoading(false);
            }
        }

        function closeModal() {
            etudiantDetailsModal.classList.remove('show');
            currentStudentDetails = null; // Clear details on close
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target == etudiantDetailsModal) {
                closeModal();
            }
        }

        // NEW: Data-driven PDF generation for student details using jspdf-autotable
        function downloadStudentDetailsPdf() {
            if (!currentStudentDetails) {
                showAlert("Aucune donnée d'étudiant à exporter en PDF.", "warning");
                return;
            }

            showLoading(true);
            const etudiant = currentStudentDetails;
            const doc = new jsPDF();
            const startY = 20;
            let currentY = startY;

            // Header
            doc.setFontSize(18);
            doc.text('Fiche Détaillée de l\'Étudiant', doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' });
            currentY += 10;
            doc.setFontSize(10);
            doc.text(`SYGECOS - Système de Gestion de Scolarité`, doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' });
            currentY += 15;

            // Student Name in a prominent spot
            doc.setFontSize(16);
            doc.setTextColor(59, 130, 246); // Accent color
            doc.text(`${etudiant.nom_etu || ''} ${etudiant.prenoms_etu || ''}`, 15, currentY);
            doc.line(15, currentY + 1, doc.internal.pageSize.getWidth() - 15, currentY + 1); // Underline
            currentY += 10;
            doc.setTextColor(0, 0, 0); // Reset color
            
            // Personal Information
            doc.setFontSize(14);
            doc.text('Informations Personnelles', 15, currentY);
            currentY += 7;

            doc.autoTable({
                startY: currentY,
                body: [
                    ['N° Étudiant:', etudiant.num_etu || 'N/A'],
                    ['Nom & Prénoms:', `${etudiant.nom_etu || ''} ${etudiant.prenoms_etu || ''}`],
                    ['Email:', etudiant.email_etu || 'N/A'],
                    ['Date de Naissance:', formatDate(etudiant.dte_naiss_etu)],
                    ['Lieu de Naissance:', etudiant.lieu_naissance || 'N/A'],
                    ['Téléphone:', etudiant.telephone || 'N/A']
                ],
                theme: 'grid',
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 50 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 },
                didDrawPage: function (data) {
                    // Footer
                    doc.setFontSize(8);
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            currentY = doc.autoTable.previous.finalY + 10;

            // Academic Information
            doc.setFontSize(14);
            doc.text('Informations Académiques', 15, currentY);
            currentY += 7;

            doc.autoTable({
                startY: currentY,
                body: [
                    ['Niveau d\'Étude:', etudiant.lib_niv_etu || 'N/A'],
                    ['Filière:', etudiant.lib_filiere || 'N/A'],
                    ['Année Académique:', etudiant.annee_academique || 'N/A']
                ],
                theme: 'grid',
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 50 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 },
                didDrawPage: function (data) {
                    // Footer
                    doc.setFontSize(8);
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            currentY = doc.autoTable.previous.finalY + 10;

            // Registration Information
            doc.setFontSize(14);
            doc.text('Informations d\'Inscription', 15, currentY);
            currentY += 7;

            doc.autoTable({
                startY: currentY,
                body: [
                    ['Date d\'Inscription:', formatDate(etudiant.dte_insc)],
                    ['Montant Inscription:', etudiant.montant_insc ? `${parseInt(etudiant.montant_insc).toLocaleString('fr-FR')} FCFA` : 'N/A'],
                    ['Identifiants de Connexion:', etudiant.a_identifiants || 'N/A']
                ],
                theme: 'grid',
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 50 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 },
                didDrawPage: function (data) {
                    // Footer
                    doc.setFontSize(8);
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });

            doc.save(`fiche_etudiant_${etudiant.nom_etu.replace(/\s/g, '_')}_${etudiant.prenoms_etu.replace(/\s/g, '_')}.pdf`);
            showLoading(false);
            showAlert("Fiche étudiant PDF générée avec succès !", 'success');
        }

        // --- Initialization ---
        document.addEventListener('DOMContentLoaded', function() {
            loadEtudiants();
            initSidebar(); // Initialize sidebar responsiveness
            updateFilterButtonText(); // Set initial filter button text

            // Expose functions to global scope for inline onclicks
            window.showEtudiantDetails = showEtudiantDetails;
            window.modifierEtudiant = modifierEtudiant;
            window.supprimerEtudiant = supprimerEtudiant;
            window.supprimerSelection = supprimerSelection;
            window.exportSelection = exportSelection;
            window.closeModal = closeModal; // For details modal close button
            window.downloadStudentDetailsPdf = downloadStudentDetailsPdf; // For details modal PDF button
        });

        // Responsive Sidebar setup (copied from gestion_Ecue.php for consistency)
        function initSidebar() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (sidebarToggle && sidebar && mainContent) {
                // Initial state based on window width
                handleResponsiveLayout();
                
                sidebarToggle.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        sidebar.classList.toggle('mobile-open');
                        mobileMenuOverlay.classList.toggle('active');
                        // Toggle icon
                        const barsIcon = sidebarToggle.querySelector('.fa-bars');
                        const timesIcon = sidebarToggle.querySelector('.fa-times');
                        if (sidebar.classList.contains('mobile-open')) {
                            if (barsIcon) barsIcon.style.display = 'none';
                            if (timesIcon) timesIcon.style.display = 'inline-block';
                        } else {
                            if (barsIcon) barsIcon.style.display = 'inline-block';
                            if (timesIcon) timesIcon.style.display = 'none';
                        }
                    } else {
                        sidebar.classList.toggle('collapsed');
                        mainContent.classList.toggle('sidebar-collapsed');
                    }
                });
            }

            if (mobileMenuOverlay) {
                mobileMenuOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('mobile-open');
                    mobileMenuOverlay.classList.remove('active');
                    // Reset icon
                    const barsIcon = sidebarToggle.querySelector('.fa-bars');
                    const timesIcon = sidebarToggle.querySelector('.fa-times');
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                });
            }

            window.addEventListener('resize', handleResponsiveLayout);
        }

        // Responsive layout adjustments
        function handleResponsiveLayout() {
            const actionTexts = document.querySelectorAll('.action-text');
            const isMobile = window.innerWidth < 768;

            actionTexts.forEach(text => {
                text.style.display = isMobile ? 'none' : 'inline';
            });

            // Adjust sidebar state
            if (isMobile) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('sidebar-collapsed');
                sidebar.classList.remove('mobile-open'); // Ensure it's closed on resize to mobile
                mobileMenuOverlay.classList.remove('active'); // Hide overlay
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('sidebar-collapsed');
                sidebar.classList.remove('mobile-open'); // Ensure it's closed if was open on mobile and resized to desktop
                mobileMenuOverlay.classList.remove('active'); // Hide overlay
            }
            
            // Adjust sidebar toggle icon for mobile
            if (sidebarToggle) {
                const barsIcon = sidebarToggle.querySelector('.fa-bars');
                const timesIcon = sidebarToggle.CquerySelector('.fa-times');
                if (isMobile) {
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                } else {
                    if (barsIcon) barsIcon.style.display = 'inline-block'; // Or 'none' if sidebar is always open on desktop
                    if (timesIcon) timesIcon.style.display = 'none';
                }
                // Specific override if sidebar is actually open (mobile-open class)
                if (sidebar.classList.contains('mobile-open')) {
                    if (barsIcon) barsIcon.style.display = 'none';
                    if (timesIcon) timesIcon.style.display = 'inline-block';
                }
            }
        }
    </script>
</body>
</html>