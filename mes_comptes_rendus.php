<?php
// mes_comptes_rendus.php
session_start(); // Démarre la session
require_once 'config.php'; // Inclut le fichier de configuration de la base de données

if (!isLoggedIn()) { // Vérifie si l'utilisateur est connecté
    redirect('loginForm.php'); // Redirige vers le formulaire de connexion si non connecté
}

$loggedInUserId = $_SESSION['user_id'] ?? null; // Récupère l'ID de l'utilisateur connecté

if (!$loggedInUserId) { // Si l'ID de l'utilisateur n'est pas défini
    redirect('logout.php'); // Redirige vers la page de déconnexion ou affiche une erreur
}

// Connexion à la base de données
try {
    $pdo = new PDO('mysql:host=127.0.0.1:3306;dbname=sygecos', 'root', ''); // Crée une nouvelle instance PDO
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Définit le mode d'erreur PDO sur exception
} catch (PDOException $e) {
    error_log("Error connecting to database in mes_comptes_rendus.php: " . $e->getMessage()); // Enregistre l'erreur de connexion
    die("Erreur de connexion à la base de données."); // Arrête le script avec un message d'erreur
}

// Gestionnaire AJAX pour la récupération des comptes rendus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) { // Si la requête est de type POST et que l'action est définie
    header('Content-Type: application/json'); // Définit le type de contenu de la réponse sur JSON

    try {
        $action = $_POST['action']; // Récupère l'action demandée

        if ($action === 'get_comptes_rendus') { // Si l'action est de récupérer les comptes rendus
            $searchTerm = $_POST['search_term'] ?? ''; // Récupère le terme de recherche
            $statutFilter = $_POST['statut_filter'] ?? 'all'; // Récupère le filtre de statut

            // CHANGEMENT IMPORTANT ICI : Utilisation de `cr.fk_id_util` et `id_CR`
            $whereConditions = ["cr.fk_id_util = ?"]; // Conditions WHERE pour la requête SQL
            $params = [$loggedInUserId]; // Paramètres pour la requête préparée

            if ($statutFilter !== 'all') { // Si un filtre de statut est appliqué
                $whereConditions[] = "cr.statut = ?"; // Ajoute la condition de statut
                $params[] = $statutFilter; // Ajoute le statut aux paramètres
            }

            if (!empty($searchTerm)) { // Si un terme de recherche est fourni
                $whereConditions[] = "(cr.titre_cr LIKE ? OR cr.president_seance LIKE ? OR cr.secretaire_reunion LIKE ?)"; // Ajoute les conditions de recherche
                $params[] = "%{$searchTerm}%"; // Ajoute le terme de recherche aux paramètres
                $params[] = "%{$searchTerm}%"; // Ajoute le terme de recherche aux paramètres
                $params[] = "%{$searchTerm}%"; // Ajoute le terme de recherche aux paramètres
            }

            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions); // Construit la clause WHERE

            // CHANGEMENT IMPORTANT ICI : Utilisation du nom de table `compte_rendu` et des noms de colonnes mis à jour
            $sql = "
                SELECT
                    cr.id_CR,             -- Utilise id_CR
                    cr.titre_cr,          -- Utilise titre_cr
                    cr.date_reunion,      -- Utilise date_reunion
                    cr.heure_reunion,
                    cr.duree_reunion,
                    cr.president_seance,
                    cr.secretaire_reunion,
                    cr.statut,
                    cr.date_creation,
                    cr.date_modification
                FROM compte_rendu cr      -- Utilise le nom de table 'compte_rendu'
                {$whereClause}
                ORDER BY cr.date_modification DESC
            "; // Requête SQL pour récupérer les comptes rendus

            $stmt = $pdo->prepare($sql); // Prépare la requête SQL
            $stmt->execute($params); // Exécute la requête avec les paramètres
            $comptesRendus = $stmt->fetchAll(PDO::FETCH_ASSOC); // Récupère tous les résultats sous forme de tableau associatif

            echo json_encode(['success' => true, 'data' => $comptesRendus]); // Encode les résultats en JSON et les affiche
        } elseif ($action === 'get_cr_details') { // Si l'action est de récupérer les détails d'un compte rendu
            $crId = $_POST['cr_id'] ?? 0; // Récupère l'ID du compte rendu
            if ($crId <= 0) { // Si l'ID est invalide
                throw new Exception("ID du compte rendu manquant."); // Lance une exception
            }

            // CHANGEMENT IMPORTANT ICI : Utilisation du nom de table `compte_rendu` et des noms de colonnes mis à jour
            $sql = "
                SELECT
                    cr.*,                 -- Sélectionne toutes les colonnes de 'compte_rendu'
                    u.login_util AS auteur_login
                FROM compte_rendu cr      -- Utilise le nom de table 'compte_rendu'
                JOIN utilisateur u ON cr.fk_id_util = u.id_util
                WHERE cr.id_CR = ? AND cr.fk_id_util = ? -- Utilise id_CR
            "; // Requête SQL pour récupérer les détails du compte rendu
            $stmt = $pdo->prepare($sql); // Prépare la requête SQL
            $stmt->execute([$crId, $loggedInUserId]); // Exécute la requête avec l'ID du CR et l'ID de l'utilisateur
            $crDetails = $stmt->fetch(PDO::FETCH_ASSOC); // Récupère les détails du CR

            if (!$crDetails) { // Si les détails ne sont pas trouvés ou non autorisés
                throw new Exception("Compte rendu introuvable ou non autorisé."); // Lance une exception
            }
            echo json_encode(['success' => true, 'data' => $crDetails]); // Encode les détails en JSON et les affiche
        } elseif ($action === 'delete_compte_rendu') { // Si l'action est de supprimer un compte rendu
            $crId = $_POST['cr_id'] ?? 0; // Récupère l'ID du compte rendu
            if ($crId <= 0) { // Si l'ID est invalide
                throw new Exception("ID du compte rendu manquant."); // Lance une exception
            }

            // CHANGEMENT IMPORTANT ICI : Utilisation du nom de table `compte_rendu` et `id_CR`
            $stmt = $pdo->prepare("DELETE FROM compte_rendu WHERE id_CR = ? AND fk_id_util = ?"); // Prépare la requête de suppression
            $stmt->execute([$crId, $loggedInUserId]); // Exécute la requête

            if ($stmt->rowCount() > 0) { // Si des lignes ont été affectées (supprimées)
                echo json_encode(['success' => true, 'message' => 'Compte rendu supprimé avec succès.']); // Affiche un message de succès
            } else {
                throw new Exception("Compte rendu introuvable ou non autorisé."); // Lance une exception si non trouvé
            }
        } else {
            throw new Exception("Action non reconnue."); // Lance une exception si l'action n'est pas reconnue
        }

    } catch (Exception $e) {
        error_log("Error in mes_comptes_rendus AJAX: " . $e->getMessage()); // Enregistre l'erreur AJAX
        echo json_encode(['success' => false, 'message' => $e->getMessage()]); // Affiche un message d'erreur
    }
    exit; // Termine l'exécution du script
}

// Pas de récupération initiale des données sur le chargement de la page, tout est fait via AJAX
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Mes Comptes Rendus</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
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
        .sidebar-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); position: relative; } .sidebar-toggle:hover { background: var(--gray-200); color: var(--gray-800); }
        .page-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-800); }
        .topbar-right { display: flex; align-items: center; gap: var(--space-4); }
        .topbar-button { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); position: relative; } .topbar-button:hover { background: var(--gray-200); color: var(--gray-800); }
        .notification-badge { position: absolute; top: -2px; right: -2px; width: 18px; height: 18px; background: var(--error-500); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: white; }

        /* === PAGE CONTENT === */
        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); display: flex; justify-content: space-between; align-items: center; }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); margin-top: var(--space-2); }

        .table-container { background: var(--white); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); overflow: hidden; margin-bottom: var(--space-8); }
        .table-header { padding: var(--space-6); border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-4); }
        .table-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .table-actions { display: flex; gap: var(--space-3); align-items: center; }

        .table-wrapper { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; min-width: 700px; }
        .data-table th, .data-table td { padding: var(--space-3); text-align: left; border-bottom: 1px solid var(--gray-200); font-size: var(--text-sm); }
        .data-table th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); }
        .data-table tbody tr:hover { background-color: var(--gray-50); }
        .data-table td { color: var(--gray-800); }

        .badge { padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; }
        .badge-success { background-color: var(--secondary-100); color: var(--secondary-600); }
        .badge-warning { background-color: #fef3c7; color: #d97706; }
        .badge-info { background-color: var(--accent-100); color: var(--accent-600); }
        .badge-danger { background-color: #fecaca; color: #dc2626; }
        .badge-primary { background-color: var(--accent-100); color: var(--accent-800); }


        /* Messages d'alerte */
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
            top: -2px;
            right: -2px;
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

        .empty-state { text-align: center; padding: var(--space-16); color: var(--gray-500); }
        .empty-state i { font-size: 3rem; margin-bottom: var(--space-4); }

        /* Modal Styles - from liste_etudiants.php */
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

        .btn-active-filter { /* Style for active filter button */
            background-color: var(--accent-200);
            color: var(--accent-800);
        }
        /* Filter Modal (from liste_etudiants.php) */
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
        <?php include 'sidebar_commision.php'; // Inclut le sidebar de la commission ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; // Inclut la barre supérieure ?>

            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1 class="page-title-main">Mes Comptes Rendus de Réunion</h1>
                        <p class="page-subtitle">Consultez et gérez les comptes rendus que vous avez créés.</p>
                    </div>
                    <div>
                        <a href="compte_rendu.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Nouveau Compte Rendu
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

                <div class="search-bar">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher par titre, président, secrétaire...">
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
                        <h3 class="table-title">
                            <i class="fas fa-file-alt"></i> Liste de mes Comptes Rendus (<span id="crCount">0</span>)
                        </h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" id="filterButton">
                                <i class="fas fa-filter"></i> <span id="filterButtonText">Filtres</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Titre du CR</th>
                                    <th>Date Réunion</th>
                                    <th>Heure Réunion</th>
                                    <th>Durée</th>
                                    <th>Président</th>
                                    <th>Secrétaire</th>
                                    <th>Statut</th>
                                    <th>Date de Création</th>
                                    <th>Date de Modification</th>
                                    <th style="width: 150px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="crTableBody">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="crDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="crModalTitle">Détails du Compte Rendu</h2>
                <span class="modal-close" onclick="closeModal('crDetailsModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="detail-group">
                    <h3 class="detail-group-title">Informations Générales</h3>
                    <div class="detail-item">
                        <strong>Titre du CR:</strong> <span id="detailCrTitre"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Date de Réunion:</strong> <span id="detailCrDateReunion"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Heure de Réunion:</strong> <span id="detailCrHeureReunion"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Durée:</strong> <span id="detailCrDuree"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Président de Séance:</strong> <span id="detailCrPresident"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Secrétaire:</strong> <span id="detailCrSecretaire"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Participants:</strong> <span id="detailCrParticipants"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Absents Excusés:</strong> <span id="detailCrAbsents"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Statut:</strong> <span id="detailCrStatut"></span>
                    </div>
                </div>
                <div class="detail-group">
                    <h3 class="detail-group-title">Dates</h3>
                    <div class="detail-item">
                        <strong>Date de Création:</strong> <span id="detailCrDateCreation"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Dernière Modification:</strong> <span id="detailCrDateModification"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('crDetailsModal')" class="btn btn-secondary">
                    Fermer
                </button>
                <button onclick="downloadCrDetailsPdf()" class="btn btn-primary">
                    <i class="fas fa-file-pdf"></i> Télécharger PDF
                </button>
            </div>
        </div>
    </div>

    <div class="filter-modal" id="filterModal">
        <div class="filter-modal-content">
            <div class="filter-modal-header">
                <h3 class="filter-modal-title">Filtres et Tri des Comptes Rendus</h3>
                <button class="filter-modal-close" id="closeFilterModal">&times;</button>
            </div>
            <div class="filter-group">
                <label>Trier par:</label>
                <div class="filter-option-group">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="date-mod-desc" checked>
                            <i class="fas fa-calendar-alt"></i> Date modif (plus récent)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="date-mod-asc">
                            <i class="fas fa-calendar-alt"></i> Date modif (plus ancien)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="titre-asc">
                            <i class="fas fa-sort-alpha-down"></i> Titre (A-Z)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="titre-desc">
                            <i class="fas fa-sort-alpha-up"></i> Titre (Z-A)
                        </label>
                    </div>
                </div>
            </div>

            <div class="filter-group">
                <label>Filtrer par Statut:</label>
                <div class="filter-option-group">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="statut_filter_radio" value="all" checked>
                            Tous les Statuts
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="statut_filter_radio" value="brouillon">
                            Brouillon
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="statut_filter_radio" value="finalise">
                            Finalisé
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="statut_filter_radio" value="archive">
                            Archivé
                        </label>
                    </div>
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

        let allComptesRendus = []; // Stocke tous les CR récupérés
        let currentDisplayedComptesRendus = []; // Stocke les CR actuellement affichés dans le tableau
        let currentCrDetails = null; // Stocke les détails du CR pour l'exportation PDF

        // États du filtre et du tri
        let currentSortType = 'date-mod-desc'; // Type de tri actuel (par date de modification descendante par défaut)
        let currentStatutFilter = 'all'; // Filtre de statut actuel (tous les statuts par défaut)

        // Éléments du DOM
        const crTableBody = document.getElementById('crTableBody'); // Corps du tableau des CR
        const crCountSpan = document.getElementById('crCount'); // Compteur de CR
        const searchInput = document.getElementById('searchInput'); // Champ de recherche
        const searchButton = document.getElementById('searchButton'); // Bouton de recherche
        const exportPdfBtn = document.getElementById('exportPdfBtn'); // Bouton d'export PDF
        const exportExcelBtn = document.getElementById('exportExcelBtn'); // Bouton d'export Excel
        const exportCsvBtn = document.getElementById('exportCsvBtn'); // Bouton d'export CSV
        const filterButton = document.getElementById('filterButton'); // Bouton de filtre
        const filterButtonText = document.getElementById('filterButtonText'); // Texte du bouton de filtre
        const filterModal = document.getElementById('filterModal'); // Modale de filtre
        const closeFilterModalBtn = document.getElementById('closeFilterModal'); // Bouton de fermeture de la modale de filtre
        const applyFilterModalBtn = document.getElementById('applyFilterModalBtn'); // Bouton d'application du filtre
        const resetFilterModalBtn = document.getElementById('resetFilterModalBtn'); // Bouton de réinitialisation du filtre

        // Éléments de la modale de message (copiés depuis `mes_rapports.php` / `depot_rapport.php`)
        const messageModal = document.getElementById('messageModal'); // Modale de message
        const messageTitle = document.getElementById('messageTitle'); // Titre du message
        const messageText = document.getElementById('messageText'); // Texte du message
        const messageIcon = document.getElementById('messageIcon'); // Icône du message
        const messageButton = document.getElementById('messageButton'); // Bouton OK du message
        const messageClose = document.getElementById('messageClose'); // Bouton de fermeture du message
        const loadingOverlay = document.getElementById('loadingOverlay'); // Overlay de chargement

        // Éléments de la barre latérale pour la réactivité (copiés depuis `mes_rapports.php`)
        const sidebarToggle = document.getElementById('sidebarToggle'); // Bouton de bascule de la barre latérale
        const sidebar = document.getElementById('sidebar'); // Barre latérale
        const mainContent = document.getElementById('mainContent'); // Contenu principal
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay'); // Overlay du menu mobile


        // --- Fonctions utilitaires (Copiées depuis `mes_rapports.php`) ---
        function showAlert(message, type = 'success', title = null) { // Affiche une alerte personnalisée
            if (!title) { // Si le titre n'est pas défini
                switch (type) { // Définit le titre en fonction du type d'alerte
                    case 'success': title = 'Succès'; break; // Titre pour le succès
                    case 'error': title = 'Erreur'; break; // Titre pour l'erreur
                    case 'warning': title = 'Attention'; break; // Titre pour l'avertissement
                    case 'info': title = 'Information'; break; // Titre pour l'information
                    default: title = 'Message'; // Titre par défaut
                }
            }
            messageIcon.className = 'message-icon ' + type; // Définit la classe de l'icône
            messageIcon.innerHTML = ''; // Efface l'icône précédente
            switch (type) { // Affiche l'icône appropriée en fonction du type
                case 'success': messageIcon.innerHTML = '<i class="fas fa-check-circle"></i>'; break; // Icône de succès
                case 'error': messageIcon.innerHTML = '<i class="fas fa-times-circle"></i>'; break; // Icône d'erreur
                case 'warning': messageIcon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>'; break; // Icône d'avertissement
                case 'info': messageIcon.innerHTML = '<i class="fas fa-info-circle"></i>'; break; // Icône d'information
                default: messageIcon.innerHTML = '<i class="fas fa-bell"></i>'; // Icône par défaut
            }
            messageTitle.textContent = title; // Définit le titre du message
            messageText.textContent = message; // Définit le texte du message
            messageModal.style.display = 'flex'; // Affiche la modale de message
        }

        function closeMessageModal() { // Ferme la modale de message
            messageModal.style.display = 'none'; // Cache la modale de message
        }
        messageButton.addEventListener('click', closeMessageModal); // Attache l'événement de clic au bouton OK
        messageClose.addEventListener('click', closeMessageModal); // Attache l'événement de clic au bouton de fermeture
        messageModal.addEventListener('click', function(e) { // Ferme la modale si l'utilisateur clique en dehors du contenu
            if (e.target === messageModal) { // Si l'élément cliqué est la modale elle-même
                closeMessageModal(); // Ferme la modale
            }
        });

        function showLoading(show) { // Affiche ou cache l'overlay de chargement
            loadingOverlay.style.display = show ? 'flex' : 'none'; // Définit l'affichage de l'overlay
        }

        async function makeAjaxRequest(data) { // Effectue une requête AJAX
            try {
                const response = await fetch(window.location.href, { // Envoie la requête fetch
                    method: 'POST', // Méthode POST
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, // En-têtes de la requête
                    body: new URLSearchParams(data) // Corps de la requête encodé en URLSearchParams
                });
                return await response.json(); // Retourne la réponse JSON
            } catch (error) {
                console.error('Erreur AJAX:', error); // Affiche l'erreur dans la console
                throw error; // Relance l'erreur
            }
        }

        function htmlspecialchars(str) { // Convertit les caractères spéciaux en entités HTML
            if (typeof str !== 'string') return str; // Retourne la chaîne telle quelle si ce n'est pas une chaîne
            var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }; // Mappe les caractères
            return str.replace(/[&<>"']/g, function(m) { return map[m]; }); // Remplace les caractères
        }

        function formatDate(dateStr) { // Formate une chaîne de date
            if (!dateStr || dateStr === '0000-00-00 00:00:00' || dateStr.includes('N/A')) return 'N/A'; // Gère les dates invalides
            const date = new Date(dateStr); // Crée un objet Date
            if (isNaN(date.getTime())) return 'N/A'; // Vérifie si la date est valide
            return date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'}); // Formate la date et l'heure
        }

        function getStatusBadge(status) { // Retourne le badge de statut HTML
            let className = ''; // Classe CSS du badge
            let text = ''; // Texte du badge
            switch (status) { // Définit la classe et le texte en fonction du statut
                case 'brouillon': className = 'badge-warning'; text = 'Brouillon'; break; // Statut brouillon
                case 'finalise': className = 'badge-success'; text = 'Finalisé'; break; // Statut finalisé
                case 'archive': className = 'badge-secondary'; text = 'Archivé'; break; // Statut archivé
                default: className = 'badge-secondary'; text = 'Inconnu'; // Statut inconnu
            }
            return `<span class="badge ${className}">${text}</span>`; // Retourne le HTML du badge
        }

        // --- Fonctions principales pour les Comptes Rendus ---
        async function fetchAndRenderComptesRendus() { // Récupère et affiche les comptes rendus
            showLoading(true); // Affiche l'overlay de chargement
            try {
                const result = await makeAjaxRequest({ // Effectue la requête AJAX pour récupérer les CR
                    action: 'get_comptes_rendus', // Action 'get_comptes_rendus'
                    search_term: searchInput.value, // Terme de recherche
                    statut_filter: currentStatutFilter // Filtre de statut
                });

                if (result.success) { // Si la requête a réussi
                    allComptesRendus = result.data; // Stocke tous les CR récupérés
                    applyFiltersAndSort(currentSortType); // Applique les filtres et le tri, puis affiche les résultats
                } else {
                    showAlert(result.message, 'error'); // Affiche une alerte d'erreur
                    allComptesRendus = []; // Réinitialise les données
                    currentDisplayedComptesRendus = []; // Réinitialise les données affichées
                    displayComptesRendusTable([]); // Affiche un tableau vide
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des comptes rendus. Veuillez réessayer.', 'error'); // Affiche une alerte d'erreur
                allComptesRendus = []; // Réinitialise les données
                currentDisplayedComptesRendus = []; // Réinitialise les données affichées
                displayComptesRendusTable([]); // Affiche un tableau vide
            } finally {
                showLoading(false); // Cache l'overlay de chargement
            }
        }

        function displayComptesRendusTable(comptesRendus) { // Affiche les comptes rendus dans le tableau
            crTableBody.innerHTML = ''; // Vide le corps du tableau
            crCountSpan.textContent = comptesRendus.length; // Met à jour le compteur de CR
            currentDisplayedComptesRendus = comptesRendus; // Met à jour les CR actuellement affichés

            if (comptesRendus.length === 0) { // S'il n'y a pas de comptes rendus
                crTableBody.innerHTML = `
                    <tr>
                        <td colspan="9" class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <p>Aucun compte rendu trouvé avec ces critères.</p>
                        </td>
                    </tr>
                `; // Affiche un message d'état vide
                return; // Termine la fonction
            }

            comptesRendus.forEach(cr => { // Pour chaque compte rendu
                const row = document.createElement('tr'); // Crée une nouvelle ligne de tableau
                row.innerHTML = `
                    <td>${htmlspecialchars(cr.titre_cr)}</td>
                    <td>${new Date(cr.date_reunion).toLocaleDateString('fr-FR')}</td>
                    <td>${cr.heure_reunion.substring(0, 5)}</td>
                    <td>${htmlspecialchars(cr.duree_reunion)}</td>
                    <td>${htmlspecialchars(cr.president_seance)}</td>
                    <td>${htmlspecialchars(cr.secretaire_reunion)}</td>
                    <td>${getStatusBadge(cr.statut)}</td>
                    <td>${formatDate(cr.date_creation)}</td>
                    <td>${formatDate(cr.date_modification)}</td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="viewCr('${cr.id_CR}')" title="Voir les détails">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-primary" onclick="editCr('${cr.id_CR}')" title="Modifier" ${cr.statut !== 'brouillon' ? 'disabled' : ''}>
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteCr('${cr.id_CR}', '${cr.titre_cr}')" title="Supprimer">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                `; // Définit le contenu HTML de la ligne
                crTableBody.appendChild(row); // Ajoute la ligne au corps du tableau
            });
        }

        async function viewCr(crId) { // Affiche les détails d'un compte rendu dans une modale
            showLoading(true); // Affiche l'overlay de chargement
            try {
                const result = await makeAjaxRequest({ action: 'get_cr_details', cr_id: crId }); // Effectue la requête AJAX pour les détails
                if (result.success && result.data) { // Si la requête a réussi et des données sont retournées
                    currentCrDetails = result.data; // Stocke les détails du CR
                    const data = currentCrDetails; // Récupère les données

                    document.getElementById('detailCrTitre').textContent = htmlspecialchars(data.titre_cr); // Affiche le titre
                    document.getElementById('detailCrDateReunion').textContent = new Date(data.date_reunion).toLocaleDateString('fr-FR'); // Affiche la date de réunion
                    document.getElementById('detailCrHeureReunion').textContent = data.heure_reunion.substring(0, 5); // Affiche l'heure de réunion
                    document.getElementById('detailCrDuree').textContent = htmlspecialchars(data.duree_reunion); // Affiche la durée
                    document.getElementById('detailCrPresident').textContent = htmlspecialchars(data.president_seance); // Affiche le président
                    document.getElementById('detailCrSecretaire').textContent = htmlspecialchars(data.secretaire_reunion); // Affiche le secrétaire
                    document.getElementById('detailCrParticipants').textContent = htmlspecialchars(data.participants); // Affiche les participants
                    document.getElementById('detailCrAbsents').textContent = htmlspecialchars(data.absents_excuses); // Affiche les absents excusés
                    document.getElementById('detailCrStatut').innerHTML = getStatusBadge(data.statut); // Affiche le statut avec un badge
                    document.getElementById('detailCrDateCreation').textContent = formatDate(data.date_creation); // Affiche la date de création
                    document.getElementById('detailCrDateModification').textContent = formatDate(data.date_modification); // Affiche la date de modification

                    document.getElementById('crDetailsModal').classList.add('show'); // Affiche la modale des détails
                } else {
                    showAlert(result.message || 'Impossible de charger les détails du compte rendu.', 'error'); // Affiche une alerte d'erreur
                }
            } catch (error) {
                showAlert('Erreur lors de la récupération des détails du compte rendu.', 'error'); // Affiche une alerte d'erreur
            } finally {
                showLoading(false); // Cache l'overlay de chargement
            }
        }

        function editCr(crId) { // Redirige vers la page d'édition du compte rendu
            window.location.href = `compte_rendu.php?id=${crId}`; // Redirige avec l'ID du CR
        }

        async function deleteCr(crId, titre) { // Supprime un compte rendu
            if (!confirm(`Êtes-vous sûr de vouloir supprimer le compte rendu "${titre}" ? Cette action est irréversible.`)) { // Demande confirmation
                return; // Annule si non confirmé
            }
            showLoading(true); // Affiche l'overlay de chargement
            try {
                const result = await makeAjaxRequest({ action: 'delete_compte_rendu', cr_id: crId }); // Effectue la requête AJAX de suppression
                if (result.success) { // Si la suppression a réussi
                    showAlert(result.message, 'success'); // Affiche un message de succès
                    fetchAndRenderComptesRendus(); // Recharge le tableau des comptes rendus
                } else {
                    showAlert(result.message, 'error'); // Affiche un message d'erreur
                }
            } catch (error) {
                showAlert('Erreur lors de la suppression du compte rendu.', 'error'); // Affiche une alerte d'erreur
            } finally {
                showLoading(false); // Cache l'overlay de chargement
            }
        }

        // --- Fonctions d'exportation (Adaptées pour les Comptes Rendus) ---
        function getExportData() { // Récupère les données à exporter
            const headers = [
                'Titre du CR', 'Date Réunion', 'Heure Réunion', 'Durée', 'Président', 'Secrétaire', 'Participants', 'Absents Excusés', 'Statut', 'Date de Création', 'Date de Modification'
            ]; // En-têtes pour l'exportation
            const rows = currentDisplayedComptesRendus.map(cr => [
                cr.titre_cr,
                new Date(cr.date_reunion).toLocaleDateString('fr-FR'),
                cr.heure_reunion.substring(0, 5),
                cr.duree_reunion,
                cr.president_seance,
                cr.secretaire_reunion,
                cr.participants,
                cr.absents_excuses,
                cr.statut,
                formatDate(cr.date_creation),
                formatDate(cr.date_modification)
            ]); // Lignes de données à exporter
            return { headers, rows }; // Retourne les en-têtes et les lignes
        }

        exportPdfBtn.addEventListener('click', function() { // Exporte les données en PDF
            try {
                const { headers, rows } = getExportData(); // Récupère les données
                if (rows.length === 0) { showAlert("Aucune donnée visible à exporter.", 'warning'); return; } // Affiche un avertissement si pas de données
                const doc = new jsPDF('landscape'); // Crée un nouveau document PDF en mode paysage
                doc.setFontSize(14); // Définit la taille de police
                doc.text('Liste de Mes Comptes Rendus de Réunion', 14, 15); // Ajoute le titre
                doc.setFontSize(10); // Définit la taille de police
                doc.text(`Exporté le: ${new Date().toLocaleDateString('fr-FR')}`, 14, 22); // Ajoute la date d'exportation
                doc.autoTable({
                    head: [headers], body: rows, startY: 25, styles: { fontSize: 8 },
                    headStyles: { fillColor: [59, 130, 246] }, margin: { left: 10, right: 10 }
                }); // Génère la table automatique
                doc.save(`mes_comptes_rendus_${new Date().toISOString().slice(0,10)}.pdf`); // Sauvegarde le fichier PDF
                showAlert("Export PDF réussi !", 'success'); // Affiche un message de succès
            } catch (error) { console.error("Erreur lors de l'export PDF:", error); showAlert("Erreur lors de l'export PDF", 'error'); } // Gère les erreurs
        });

        exportExcelBtn.addEventListener('click', function() { // Exporte les données en Excel
            try {
                const { headers, rows } = getExportData(); // Récupère les données
                if (rows.length === 0) { showAlert("Aucune donnée visible à exporter.", 'warning'); return; } // Affiche un avertissement si pas de données
                const wb = XLSX.utils.book_new(); // Crée un nouveau classeur Excel
                const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]); // Crée une feuille à partir des données
                XLSX.utils.book_append_sheet(wb, ws, "Mes Comptes Rendus"); // Ajoute la feuille au classeur
                XLSX.writeFile(wb, `mes_comptes_rendus_${new Date().toISOString().slice(0,10)}.xlsx`); // Sauvegarde le fichier Excel
                showAlert("Export Excel réussi !", 'success'); // Affiche un message de succès
            } catch (error) { console.error("Erreur lors de l'export Excel:", error); showAlert("Erreur lors de l'export Excel", 'error'); } // Gère les erreurs
        });

        exportCsvBtn.addEventListener('click', function() { // Exporte les données en CSV
            try {
                const { headers, rows } = getExportData(); // Récupère les données
                if (rows.length === 0) { showAlert("Aucune donnée visible à exporter.", 'warning'); return; } // Affiche un avertissement si pas de données
                let csvContent = headers.map(h => `"${h}"`).join(";") + "\n"; // Crée l'en-tête CSV
                rows.forEach(row => csvContent += row.map(cell => `"${cell}"`).join(";") + "\n"); // Ajoute les lignes de données au CSV
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' }); // Crée un objet Blob
                const link = document.createElement("a"); // Crée un élément de lien
                const url = URL.createObjectURL(blob); // Crée une URL pour le Blob
                link.setAttribute("href", url); // Définit l'attribut href du lien
                link.setAttribute("download", `mes_comptes_rendus_${new Date().toISOString().slice(0,10)}.csv`); // Définit l'attribut de téléchargement
                link.style.visibility = 'hidden'; // Cache le lien
                document.body.appendChild(link); // Ajoute le lien au corps du document
                link.click(); // Clique sur le lien pour déclencher le téléchargement
                document.body.removeChild(link); // Supprime le lien
                showAlert("Export CSV réussi !", 'success'); // Affiche un message de succès
            } catch (error) { console.error("Erreur lors de l'export CSV:", error); showAlert("Erreur lors de l'export CSV", 'error'); } // Gère les erreurs
        });

        function downloadCrDetailsPdf() { // Télécharge les détails d'un CR en PDF
            if (!currentCrDetails) { // Si aucun détail de CR n'est disponible
                showAlert("Aucune donnée de compte rendu à exporter en PDF.", "warning"); // Affiche un avertissement
                return; // Termine la fonction
            }
            showLoading(true); // Affiche l'overlay de chargement
            const cr = currentCrDetails; // Récupère les détails du CR
            const doc = new jsPDF(); // Crée un nouveau document PDF
            let currentY = 20; // Position Y actuelle

            doc.setFontSize(18); // Définit la taille de police
            doc.text('Fiche Détaillée du Compte Rendu', doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' }); // Ajoute le titre centré
            currentY += 10; // Incrémente la position Y
            doc.setFontSize(10); // Définit la taille de police
            doc.text(`SYGECOS - Système de Gestion de Scolarité`, doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' }); // Ajoute le sous-titre centré
            currentY += 15; // Incrémente la position Y

            doc.setFontSize(16); // Définit la taille de police
            doc.setTextColor(59, 130, 246); // Définit la couleur du texte
            doc.text(cr.titre_cr, 15, currentY); // Ajoute le titre du CR
            doc.line(15, currentY + 1, doc.internal.pageSize.getWidth() - 15, currentY + 1); // Ajoute une ligne
            currentY += 10; // Incrémente la position Y
            doc.setTextColor(0, 0, 0); // Réinitialise la couleur du texte

            doc.setFontSize(14); // Définit la taille de police
            doc.text('Informations Générales', 15, currentY); // Ajoute le titre de section
            currentY += 7; // Incrémente la position Y
            doc.autoTable({
                startY: currentY,
                body: [
                    ['Titre du CR:', cr.titre_cr],
                    ['Date de Réunion:', new Date(cr.date_reunion).toLocaleDateString('fr-FR')],
                    ['Heure de Réunion:', cr.heure_reunion.substring(0,5)],
                    ['Durée:', cr.duree_reunion],
                    ['Président de Séance:', cr.president_seance],
                    ['Secrétaire:', cr.secretaire_reunion],
                    ['Participants:', cr.participants],
                    ['Absents Excusés:', cr.absents_excuses],
                    ['Statut:', cr.statut]
                ],
                theme: 'grid', styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 70 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 }
            }); // Ajoute une table automatique pour les informations générales
            currentY = doc.autoTable.previous.finalY + 10; // Met à jour la position Y après la table

            doc.setFontSize(14); // Définit la taille de police
            doc.text('Dates', 15, currentY); // Ajoute le titre de section "Dates"
            currentY += 7; // Incrémente la position Y
            doc.autoTable({
                startY: currentY,
                body: [
                    ['Date de Création:', formatDate(cr.date_creation)],
                    ['Dernière Modification:', formatDate(cr.date_modification)]
                ],
                theme: 'grid', styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 70 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 }
            }); // Ajoute une table automatique pour les dates

            doc.save(`fiche_compte_rendu_${cr.titre_cr.replace(/\s/g, '_')}.pdf`); // Sauvegarde le fichier PDF
            showLoading(false); // Cache l'overlay de chargement
            showAlert("Fiche de compte rendu PDF générée avec succès !", 'success'); // Affiche un message de succès
        }


        // --- Logique de recherche, filtre, tri (Adaptée pour les Comptes Rendus) ---
        function applyFiltersAndSort(sortType) { // Applique les filtres et le tri aux données des comptes rendus
            let filteredAndSortedData = [...allComptesRendus]; // Crée une copie des données pour le filtrage et le tri

            // Applique la recherche
            const searchTerm = searchInput.value.toLowerCase(); // Récupère le terme de recherche en minuscules
            if (searchTerm) { // Si un terme de recherche est présent
                filteredAndSortedData = filteredAndSortedData.filter(cr => { // Filtre les données
                    return (
                        (cr.titre_cr && cr.titre_cr.toLowerCase().includes(searchTerm)) || // Recherche par titre
                        (cr.president_seance && cr.president_seance.toLowerCase().includes(searchTerm)) || // Recherche par président de séance
                        (cr.secretaire_reunion && cr.secretaire_reunion.toLowerCase().includes(searchTerm)) // Recherche par secrétaire
                    );
                });
            }

            // Applique le filtre de statut
            if (currentStatutFilter !== 'all') { // Si un filtre de statut est appliqué (pas 'all')
                filteredAndSortedData = filteredAndSortedData.filter(cr => cr.statut === currentStatutFilter); // Filtre par statut
            }

            // Applique le tri
            filteredAndSortedData.sort((a, b) => { // Trie les données
                switch (sortType) { // Selon le type de tri
                    case 'titre-asc': return (a.titre_cr || '').localeCompare(b.titre_cr || ''); // Tri par titre (A-Z)
                    case 'titre-desc': return (b.titre_cr || '').localeCompare(a.titre_cr || ''); // Tri par titre (Z-A)
                    case 'date-mod-asc': return new Date(a.date_modification) - new Date(b.date_modification); // Tri par date de modification (plus ancien)
                    case 'date-mod-desc': return new Date(b.date_modification) - new Date(a.date_modification); // Tri par date de modification (plus récent)
                    default: return 0; // Par défaut
                }
            });

            displayComptesRendusTable(filteredAndSortedData); // Affiche les données filtrées et triées
            updateFilterButtonText(); // Met à jour le texte du bouton de filtre
        }

        searchInput.addEventListener('input', () => { // Écoute l'événement 'input' sur le champ de recherche
            // Réinitialise les filtres dans la modale lors de la saisie dans le champ de recherche
            document.querySelector('input[name="sort_radio"][value="date-mod-desc"]').checked = true; // Réinitialise le tri
            document.querySelector('input[name="statut_filter_radio"][value="all"]').checked = true; // Réinitialise le filtre de statut
            currentSortType = 'date-mod-desc'; // Met à jour le type de tri actuel
            currentStatutFilter = 'all'; // Met à jour le filtre de statut actuel

            applyFiltersAndSort(currentSortType); // Applique les filtres et le tri
        });
        searchButton.addEventListener('click', () => applyFiltersAndSort(currentSortType)); // Attache l'événement de clic au bouton de recherche

        filterButton.addEventListener('click', function(event) { // Affiche la modale de filtre au clic du bouton
            event.stopPropagation(); // Empêche la propagation de l'événement
            filterModal.style.display = 'flex'; // Affiche la modale en mode flex
        });
        closeFilterModalBtn.addEventListener('click', () => filterModal.style.display = 'none'); // Ferme la modale de filtre au clic du bouton de fermeture
        filterModal.addEventListener('click', (e) => { if (e.target === filterModal) filterModal.style.display = 'none'; }); // Ferme la modale si cliqué en dehors

        applyFilterModalBtn.addEventListener('click', function() { // Applique les filtres au clic du bouton "Appliquer"
            currentSortType = document.querySelector('input[name="sort_radio"]:checked').value; // Récupère le type de tri sélectionné
            currentStatutFilter = document.querySelector('input[name="statut_filter_radio"]:checked').value; // Récupère le filtre de statut sélectionné
            searchInput.value = ''; // Efface le champ de recherche lors de l'application des filtres
            applyFiltersAndSort(currentSortType); // Applique les filtres et le tri
            filterModal.style.display = 'none'; // Cache la modale de filtre
        });

        resetFilterModalBtn.addEventListener('click', function() { // Réinitialise les filtres au clic du bouton "Réinitialiser"
            currentSortType = 'date-mod-desc'; // Réinitialise le type de tri
            currentStatutFilter = 'all'; // Réinitialise le filtre de statut
            searchInput.value = ''; // Efface le champ de recherche

            document.querySelector('input[name="sort_radio"][value="date-mod-desc"]').checked = true; // Coche l'option de tri par défaut
            document.querySelector('input[name="statut_filter_radio"][value="all"]').checked = true; // Coche l'option de statut par défaut

            applyFiltersAndSort(currentSortType); // Applique les filtres et le tri réinitialisés
            filterModal.style.display = 'none'; // Cache la modale de filtre
            showAlert('Filtres et recherche réinitialisés.', 'info'); // Affiche un message d'information
        });

        function updateFilterButtonText() { // Met à jour le texte du bouton de filtre
            let activeFiltersCount = 0; // Compteur de filtres actifs
            if (currentSortType !== 'date-mod-desc') activeFiltersCount++; // Incrémente si le tri est actif
            if (currentStatutFilter !== 'all') activeFiltersCount++; // Incrémente si le filtre de statut est actif
            if (searchInput.value.trim() !== '') activeFiltersCount++; // Incrémente si le champ de recherche est actif

            if (activeFiltersCount > 0) { // Si des filtres sont actifs
                filterButtonText.textContent = `Filtres (${activeFiltersCount} actifs)`; // Affiche le nombre de filtres actifs
                filterButton.classList.add('btn-active-filter'); // Ajoute la classe 'btn-active-filter'
            } else {
                filterButtonText.textContent = 'Filtres'; // Affiche "Filtres"
                filterButton.classList.remove('btn-active-filter'); // Supprime la classe 'btn-active-filter'
            }
        }

        // --- Initialisation ---
        document.addEventListener('DOMContentLoaded', function() { // Exécute lorsque le DOM est entièrement chargé
            fetchAndRenderComptesRendus(); // Récupère et affiche les comptes rendus au chargement
            initSidebar(); // Initialise la barre latérale
            updateFilterButtonText(); // Met à jour le texte du bouton de filtre

            // Expose les fonctions au scope global pour les gestionnaires d'événements HTML inline
            window.viewCr = viewCr;
            window.editCr = editCr;
            window.deleteCr = deleteCr;
            window.closeModal = closeModal;
            window.downloadCrDetailsPdf = downloadCrDetailsPdf;
        });

        // Fonctions de la barre latérale et de réactivité (Copiées depuis `mes_rapports.php`)
        function initSidebar() { // Initialise la barre latérale
            const sidebarToggle = document.getElementById('sidebarToggle'); // Bouton de bascule de la barre latérale
            const sidebar = document.getElementById('sidebar'); // Barre latérale
            const mainContent = document.getElementById('mainContent'); // Contenu principal
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay'); // Overlay du menu mobile

            if (sidebarToggle && sidebar && mainContent) { // Si les éléments existent
                handleResponsiveLayout(); // Gère la mise en page responsive
                sidebarToggle.addEventListener('click', function() { // Attache l'événement de clic au bouton de bascule
                    if (window.innerWidth <= 768) { // Si la largeur de la fenêtre est <= 768px (mobile)
                        sidebar.classList.toggle('mobile-open'); // Bascule la classe 'mobile-open' sur la barre latérale
                        mobileMenuOverlay.classList.toggle('active'); // Bascule la classe 'active' sur l'overlay mobile
                        const barsIcon = sidebarToggle.querySelector('.fa-bars'); // Icône des barres
                        const timesIcon = sidebarToggle.querySelector('.fa-times'); // Icône de la croix
                        if (sidebar.classList.contains('mobile-open')) { // Si la barre latérale est ouverte en mobile
                            if (barsIcon) barsIcon.style.display = 'none'; // Cache l'icône des barres
                            if (timesIcon) timesIcon.style.display = 'inline-block'; // Affiche l'icône de la croix
                        } else {
                            if (barsIcon) barsIcon.style.display = 'inline-block'; // Affiche l'icône des barres
                            if (timesIcon) timesIcon.style.display = 'none'; // Cache l'icône de la croix
                        }
                    } else { // Si la largeur de la fenêtre est > 768px (desktop)
                        sidebar.classList.toggle('collapsed'); // Bascule la classe 'collapsed' sur la barre latérale
                        mainContent.classList.toggle('sidebar-collapsed'); // Bascule la classe 'sidebar-collapsed' sur le contenu principal
                    }
                });
            }
            if (mobileMenuOverlay) { // Si l'overlay du menu mobile existe
                mobileMenuOverlay.addEventListener('click', function() { // Attache l'événement de clic à l'overlay
                    sidebar.classList.remove('mobile-open'); // Ferme la barre latérale mobile
                    mobileMenuOverlay.classList.remove('active'); // Désactive l'overlay mobile
                    const barsIcon = sidebarToggle.querySelector('.fa-bars'); // Icône des barres
                    const timesIcon = sidebarToggle.querySelector('.fa-times'); // Icône de la croix
                    if (barsIcon) barsIcon.style.display = 'inline-block'; // Affiche l'icône des barres
                    if (timesIcon) timesIcon.style.display = 'none'; // Cache l'icône de la croix
                });
            }
            window.addEventListener('resize', handleResponsiveLayout); // Gère la mise en page responsive au redimensionnement de la fenêtre
        }

        function handleResponsiveLayout() { // Gère la mise en page responsive
            const isMobile = window.innerWidth < 768; // Vérifie si l'écran est mobile
            document.querySelectorAll('.action-text').forEach(text => { text.style.display = isMobile ? 'none' : 'inline'; }); // Cache/affiche le texte d'action sur mobile
            if (isMobile) { // Si l'écran est mobile
                sidebar.classList.add('collapsed'); // Réduit la barre latérale
                mainContent.classList.add('sidebar-collapsed'); // Réduit le contenu principal
                sidebar.classList.remove('mobile-open'); // Ferme la barre latérale mobile
                mobileMenuOverlay.classList.remove('active'); // Désactive l'overlay mobile
            } else { // Si l'écran n'est pas mobile
                sidebar.classList.remove('collapsed'); // Étend la barre latérale
                mainContent.classList.remove('sidebar-collapsed'); // Étend le contenu principal
                sidebar.classList.remove('mobile-open'); // S'assure que la barre latérale mobile est fermée
                mobileMenuOverlay.classList.remove('active'); // Désactive l'overlay mobile
            }
            if (sidebarToggle) { // Si le bouton de bascule existe
                const barsIcon = sidebarToggle.querySelector('.fa-bars'); // Icône des barres
                const timesIcon = sidebarToggle.querySelector('.fa-times'); // Icône de la croix
                if (isMobile) { // Si l'écran est mobile
                    if (barsIcon) barsIcon.style.display = 'inline-block'; // Affiche l'icône des barres
                    if (timesIcon) timesIcon.style.display = 'none'; // Cache l'icône de la croix
                } else { // Si l'écran n'est pas mobile
                    if (barsIcon) barsIcon.style.display = 'inline-block'; // Affiche l'icône des barres
                    if (timesIcon) timesIcon.style.display = 'none'; // Cache l'icône de la croix
                }
                if (sidebar.classList.contains('mobile-open')) { // Si la barre latérale mobile est ouverte
                    if (barsIcon) barsIcon.style.display = 'none'; // Cache l'icône des barres
                    if (timesIcon) timesIcon.style.display = 'inline-block'; // Affiche l'icône de la croix
                }
            }
        }
    </script>
</body>
</html>