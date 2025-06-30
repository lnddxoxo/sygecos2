<?php
// doyen_compte_rendu.php
session_start();
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Ensure the logged-in user is a 'Doyen' or has appropriate permissions
// For simplicity, we'll assume user_id 1 is Doyen, or check user_role from session.
// You should adapt this to your actual role management system.
// For now, any logged-in user can access, but in production, apply strict role checks.
/*
if ($_SESSION['user_role'] !== 'doyen') {
    redirect('dashboard.php'); // Redirect to a more appropriate page if not doyen
}
*/

// Database connection
try {
    $pdo = new PDO('mysql:host=127.0.0.1:3306;dbname=sygecos', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Error connecting to database in doyen_compte_rendu.php: " . $e->getMessage());
    die("Erreur de connexion à la base de données.");
}

// AJAX handler for fetching compte rendus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'];

        if ($action === 'get_comptes_rendus') {
            $searchTerm = $_POST['search_term'] ?? '';
            $statutFilter = $_POST['statut_filter'] ?? 'all';
            $sortType = $_POST['sort_type'] ?? 'date-mod-desc'; // Default sort

            $whereConditions = [];
            $params = [];

            // The Doyen sees ALL *finalized* or *archived* reports from ANY user.
            $whereConditions[] = "cr.statut IN ('finalise', 'archive')";


            if ($statutFilter !== 'all') {
                // If a specific status is requested, override the default above
                $whereConditions = ["cr.statut = ?"];
                $params[] = $statutFilter;
            }

            if (!empty($searchTerm)) {
                $whereConditions[] = "(cr.titre_cr LIKE ? OR cr.president_seance LIKE ? OR cr.secretaire_reunion LIKE ?)";
                $params[] = "%{$searchTerm}%";
                $params[] = "%{$searchTerm}%";
                $params[] = "%{$searchTerm}%";
            }

            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
            if (empty($whereConditions)) {
                $whereClause = ''; // No where clause if no conditions
            }


            $sql = "
                SELECT
                    cr.id_CR,
                    cr.titre_cr,
                    cr.date_reunion,
                    cr.heure_reunion,
                    cr.duree_reunion,
                    cr.president_seance,
                    cr.secretaire_reunion,
                    cr.statut,
                    cr.date_creation,
                    cr.date_modification,
                    -- Conditional selection of author's name based on user type
                    COALESCE(
                        pa.nom_pers,    -- For Personnel Administratif
                        e.nom_ens,      -- For Enseignant
                        etu.nom_etu     -- For Etudiant
                    ) AS auteur_nom,
                    COALESCE(
                        pa.prenoms_pers, -- For Personnel Administratif
                        e.prenom_ens,    -- For Enseignant
                        etu.prenoms_etu  -- For Etudiant
                    ) AS auteur_prenom
                FROM compte_rendu cr
                LEFT JOIN posseder p ON cr.fk_id_util = p.fk_id_util
                LEFT JOIN groupe_utilisateur gu ON p.fk_id_GU = gu.id_GU
                LEFT JOIN type_groupe tg ON gu.id_GU = tg.id_GU
                LEFT JOIN type_utilisateur tu ON tg.id_type = tu.id_type
                LEFT JOIN personnel_admin pa ON cr.fk_id_util = pa.fk_id_util AND tu.lib_type = 'Personnel administratif'
                LEFT JOIN enseignant e ON cr.fk_id_util = e.fk_id_util AND tu.lib_type = 'Enseignant'
                LEFT JOIN etudiant etu ON cr.fk_id_util = etu.fk_id_util AND tu.lib_type = 'Etudiant'
                {$whereClause}
            ";

            // Add ORDER BY clause based on sortType
            switch ($sortType) {
                case 'titre-asc': $sql .= " ORDER BY cr.titre_cr ASC"; break;
                case 'titre-desc': $sql .= " ORDER BY cr.titre_cr DESC"; break;
                case 'date-mod-asc': $sql .= " ORDER BY cr.date_modification ASC"; break;
                case 'date-mod-desc':
                default: $sql .= " ORDER BY cr.date_modification DESC"; break;
            }


            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $comptesRendus = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Construct full name after fetching
            foreach ($comptesRendus as &$cr) {
                $cr['auteur_nom_complet'] = trim(($cr['auteur_nom'] ?? '') . ' ' . ($cr['auteur_prenom'] ?? ''));
                if (empty($cr['auteur_nom_complet'])) {
                    $cr['auteur_nom_complet'] = 'N/A';
                }
            }
            unset($cr); // Unset the reference

            echo json_encode(['success' => true, 'data' => $comptesRendus]);

        } elseif ($action === 'get_cr_details') {
            $crId = $_POST['cr_id'] ?? 0;
            if ($crId <= 0) {
                throw new Exception("ID du compte rendu manquant.");
            }

            $sql = "
                SELECT
                    cr.*,
                    -- Conditional selection of author's name based on user type
                    COALESCE(
                        pa.nom_pers,
                        e.nom_ens,
                        etu.nom_etu
                    ) AS auteur_nom,
                    COALESCE(
                        pa.prenoms_pers,
                        e.prenom_ens,
                        etu.prenoms_etu
                    ) AS auteur_prenom
                FROM compte_rendu cr
                LEFT JOIN posseder p ON cr.fk_id_util = p.fk_id_util
                LEFT JOIN groupe_utilisateur gu ON p.fk_id_GU = gu.id_GU
                LEFT JOIN type_groupe tg ON gu.id_GU = tg.id_GU
                LEFT JOIN type_utilisateur tu ON tg.id_type = tu.id_type
                LEFT JOIN personnel_admin pa ON cr.fk_id_util = pa.fk_id_util AND tu.lib_type = 'Personnel administratif'
                LEFT JOIN enseignant e ON cr.fk_id_util = e.fk_id_util AND tu.lib_type = 'Enseignant'
                LEFT JOIN etudiant etu ON cr.fk_id_util = etu.fk_id_util AND tu.lib_type = 'Etudiant'
                WHERE cr.id_CR = ?
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$crId]);
            $crDetails = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$crDetails) {
                throw new Exception("Compte rendu introuvable.");
            }

            // Construct full name
            $crDetails['auteur_nom_complet'] = trim(($crDetails['auteur_nom'] ?? '') . ' ' . ($crDetails['auteur_prenom'] ?? ''));
            if (empty($crDetails['auteur_nom_complet'])) {
                $crDetails['auteur_nom_complet'] = 'N/A';
            }

            echo json_encode(['success' => true, 'data' => $crDetails]);

        } elseif ($action === 'update_cr_status') { // Action to update status (e.g., to 'archive')
            $crId = $_POST['cr_id'] ?? 0;
            $newStatus = $_POST['new_status'] ?? '';

            if ($crId <= 0 || !in_array($newStatus, ['finalise', 'archive'])) { // Doyen can only finalise or archive
                throw new Exception("Données de statut invalides.");
            }

            $stmt = $pdo->prepare("UPDATE compte_rendu SET statut = :new_status, date_modification = NOW() WHERE id_CR = :id_cr");
            $stmt->bindParam(':new_status', $newStatus);
            $stmt->bindParam(':id_cr', $crId);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => "Statut du compte rendu mis à jour en '{$newStatus}' avec succès."]);
            } else {
                throw new Exception("Compte rendu introuvable ou statut déjà à jour.");
            }
        } else {
            throw new Exception("Action non reconnue.");
        }

    } catch (Exception $e) {
        error_log("Error in doyen_compte_rendu AJAX: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Comptes Rendus</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* CSS de base copié depuis mes_comptes_rendus.php pour consistance */
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
        <?php include 'sidebar_doyen.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1 class="page-title-main">Comptes Rendus de Réunion</h1>
                        <p class="page-subtitle">Consultez et gérez les comptes rendus envoyés par les commissions.</p>
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
                            <i class="fas fa-file-alt"></i> Liste des Comptes Rendus (<span id="crCount">0</span>)
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
                                    <th>Auteur</th>
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
                        <strong>Auteur:</strong> <span id="detailCrAuteur"></span>
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
                <div class="detail-group">
                    <h3 class="detail-group-title">Contenu du Compte Rendu</h3>
                    <div class="report-content-display" id="detailCrContenu" style="border: 1px solid #e0e0e0; padding: 15px; background-color: #f8f8f8; max-height: 300px; overflow-y: auto;">
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
                <button onclick="updateCrStatusToArchive()" class="btn btn-warning">
                    <i class="fas fa-archive"></i> Archiver
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

        // Éléments de la modale de message
        const messageModal = document.getElementById('messageModal'); // Modale de message
        const messageTitle = document.getElementById('messageTitle'); // Titre du message
        const messageText = document.getElementById('messageText'); // Texte du message
        const messageIcon = document.getElementById('messageIcon'); // Icône du message
        const messageButton = document.getElementById('messageButton'); // Bouton OK du message
        const messageClose = document.getElementById('messageClose'); // Bouton de fermeture du message
        const loadingOverlay = document.getElementById('loadingOverlay'); // Overlay de chargement

        // Éléments de la barre latérale pour la réactivité
        const sidebarToggle = document.getElementById('sidebarToggle'); // Bouton de bascule de la barre latérale
        const sidebar = document.getElementById('sidebar'); // Barre latérale
        const mainContent = document.getElementById('mainContent'); // Contenu principal
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay'); // Overlay du menu mobile


        // --- Fonctions utilitaires ---
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
            messageIcon.innerHTML = '';
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

        function closeMessageModal() {
            messageModal.style.display = 'none';
        }
        messageButton.addEventListener('click', closeMessageModal);
        messageClose.addEventListener('click', closeMessageModal);
        messageModal.addEventListener('click', function(e) {
            if (e.target === messageModal) {
                closeMessageModal();
            }
        });

        function showLoading(show) {
            loadingOverlay.style.display = show ? 'flex' : 'none';
        }

        async function makeAjaxRequest(data) {
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(data)
                });
                return await response.json();
            } catch (error) {
                console.error('Erreur AJAX:', error);
                throw error;
            }
        }

        function htmlspecialchars(str) {
            if (typeof str !== 'string') return str;
            var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return str.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        function formatDate(dateStr) {
            if (!dateStr || dateStr === '0000-00-00 00:00:00' || dateStr.includes('N/A')) return 'N/A';
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return 'N/A';
            return date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'});
        }

        function getStatusBadge(status) {
            let className = '';
            let text = '';
            switch (status) {
                case 'brouillon': className = 'badge-warning'; text = 'Brouillon'; break;
                case 'finalise': className = 'badge-success'; text = 'Finalisé'; break;
                case 'archive': className = 'badge-secondary'; text = 'Archivé'; break;
                default: className = 'badge-secondary'; text = 'Inconnu';
            }
            return `<span class="badge ${className}">${text}</span>`;
        }

        // --- Fonctions principales pour les Comptes Rendus ---
        async function fetchAndRenderComptesRendus() {
            showLoading(true);
            try {
                const result = await makeAjaxRequest({
                    action: 'get_comptes_rendus',
                    search_term: searchInput.value,
                    statut_filter: currentStatutFilter,
                    sort_type: currentSortType // Pass sort type to backend
                });

                if (result.success) {
                    allComptesRendus = result.data;
                    // Sorting is now primarily done in backend, but we keep applyFiltersAndSort
                    // to handle client-side search/filter updates without full re-fetch if desired.
                    applyFiltersAndSort(currentSortType);
                } else {
                    showAlert(result.message, 'error');
                    allComptesRendus = [];
                    currentDisplayedComptesRendus = [];
                    displayComptesRendusTable([]);
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des comptes rendus. Veuillez réessayer.', 'error');
                allComptesRendus = [];
                currentDisplayedComptesRendus = [];
                displayComptesRendusTable([]);
            } finally {
                showLoading(false);
            }
        }

        function displayComptesRendusTable(comptesRendus) {
            crTableBody.innerHTML = '';
            crCountSpan.textContent = comptesRendus.length;
            currentDisplayedComptesRendus = comptesRendus;

            if (comptesRendus.length === 0) {
                crTableBody.innerHTML = `
                    <tr>
                        <td colspan="11" class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <p>Aucun compte rendu trouvé avec ces critères.</p>
                        </td>
                    </tr>
                `;
                return;
            }

            comptesRendus.forEach(cr => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${htmlspecialchars(cr.titre_cr)}</td>
                    <td>${new Date(cr.date_reunion).toLocaleDateString('fr-FR')}</td>
                    <td>${cr.heure_reunion.substring(0, 5)}</td>
                    <td>${htmlspecialchars(cr.duree_reunion)}</td>
                    <td>${htmlspecialchars(cr.president_seance)}</td>
                    <td>${htmlspecialchars(cr.secretaire_reunion)}</td>
                    <td>${htmlspecialchars(cr.auteur_nom_complet || 'N/A')}</td>
                    <td>${getStatusBadge(cr.statut)}</td>
                    <td>${formatDate(cr.date_creation)}</td>
                    <td>${formatDate(cr.date_modification)}</td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="viewCr('${cr.id_CR}')" title="Voir les détails">
                            <i class="fas fa-eye"></i>
                        </button>
                        ${cr.statut === 'finalise' ? `
                        <button class="btn btn-sm btn-warning" onclick="updateCrStatus('${cr.id_CR}', 'archive')" title="Archiver">
                            <i class="fas fa-archive"></i>
                        </button>
                        ` : ''}
                    </td>
                `;
                crTableBody.appendChild(row);
            });
        }

        async function viewCr(crId) {
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ action: 'get_cr_details', cr_id: crId });
                if (result.success && result.data) {
                    currentCrDetails = result.data;
                    const data = currentCrDetails;

                    document.getElementById('detailCrTitre').textContent = htmlspecialchars(data.titre_cr);
                    document.getElementById('detailCrDateReunion').textContent = new Date(data.date_reunion).toLocaleDateString('fr-FR');
                    document.getElementById('detailCrHeureReunion').textContent = data.heure_reunion.substring(0, 5);
                    document.getElementById('detailCrDuree').textContent = htmlspecialchars(data.duree_reunion);
                    document.getElementById('detailCrPresident').textContent = htmlspecialchars(data.president_seance);
                    document.getElementById('detailCrSecretaire').textContent = htmlspecialchars(data.secretaire_reunion);
                    document.getElementById('detailCrParticipants').textContent = htmlspecialchars(data.participants);
                    document.getElementById('detailCrAbsents').textContent = htmlspecialchars(data.absents_excuses);
                    document.getElementById('detailCrAuteur').textContent = htmlspecialchars(data.auteur_nom_complet || 'N/A');
                    document.getElementById('detailCrStatut').innerHTML = getStatusBadge(data.statut);
                    document.getElementById('detailCrDateCreation').textContent = formatDate(data.date_creation);
                    document.getElementById('detailCrDateModification').textContent = formatDate(data.date_modification);
                    document.getElementById('detailCrContenu').innerHTML = data.contenu_cr; // Load HTML content

                    // Show/Hide archive button based on status
                    const archiveBtn = document.querySelector('#crDetailsModal .modal-footer .btn-warning');
                    if (archiveBtn) {
                        if (data.statut === 'finalise') {
                            archiveBtn.style.display = 'inline-flex';
                            archiveBtn.onclick = () => updateCrStatus(data.id_CR, 'archive');
                        } else {
                            archiveBtn.style.display = 'none';
                        }
                    }


                    document.getElementById('crDetailsModal').classList.add('show');
                } else {
                    showAlert(result.message || 'Impossible de charger les détails du compte rendu.', 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la récupération des détails du compte rendu.', 'error');
            } finally {
                showLoading(false);
            }
        }

        // Renommée de `updateCrStatusToArchive` pour être plus générique
        async function updateCrStatus(crId, newStatus) {
            if (!confirm(`Confirmez-vous le changement de statut pour ce compte rendu vers '${newStatus}' ?`)) {
                return;
            }
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ action: 'update_cr_status', cr_id: crId, new_status: newStatus });
                if (result.success) {
                    showAlert(result.message, 'success');
                    closeModal('crDetailsModal'); // Close details modal after update
                    fetchAndRenderComptesRendus(); // Reload the table
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert(`Erreur lors de la mise à jour du statut du compte rendu. ${error.message || ''}`, 'error');
            } finally {
                showLoading(false);
            }
        }


        // --- Fonctions d'exportation (Copied from mes_comptes_rendus.php) ---
        function getExportData() {
            const headers = [
                'Titre du CR', 'Date Réunion', 'Heure Réunion', 'Durée', 'Président', 'Secrétaire', 'Auteur', 'Statut', 'Date de Création', 'Date de Modification'
            ];
            const rows = currentDisplayedComptesRendus.map(cr => [
                cr.titre_cr,
                new Date(cr.date_reunion).toLocaleDateString('fr-FR'),
                cr.heure_reunion.substring(0, 5),
                cr.duree_reunion,
                cr.president_seance,
                cr.secretaire_reunion,
                cr.auteur_nom_complet || 'N/A',
                cr.statut,
                formatDate(cr.date_creation),
                formatDate(cr.date_modification)
            ]);
            return { headers, rows };
        }

        exportPdfBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) { showAlert("Aucune donnée visible à exporter.", 'warning'); return; }
                const doc = new jsPDF('landscape');
                doc.setFontSize(14);
                doc.text('Liste des Comptes Rendus de Réunion', 14, 15);
                doc.setFontSize(10);
                doc.text(`Exporté le: ${new Date().toLocaleDateString('fr-FR')}`, 14, 22);
                doc.autoTable({
                    head: [headers], body: rows, startY: 25, styles: { fontSize: 8 },
                    headStyles: { fillColor: [59, 130, 246] }, margin: { left: 10, right: 10 }
                });
                doc.save(`comptes_rendus_doyen_${new Date().toISOString().slice(0,10)}.pdf`);
                showAlert("Export PDF réussi !", 'success');
            } catch (error) { console.error("Erreur lors de l'export PDF:", error); showAlert("Erreur lors de l'export PDF", 'error'); }
        });

        exportExcelBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) { showAlert("Aucune donnée visible à exporter.", 'warning'); return; }
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
                XLSX.utils.book_append_sheet(wb, ws, "Comptes Rendus");
                XLSX.writeFile(wb, `comptes_rendus_doyen_${new Date().toISOString().slice(0,10)}.xlsx`);
                showAlert("Export Excel réussi !", 'success');
            } catch (error) { console.error("Erreur lors de l'export Excel:", error); showAlert("Erreur lors de l'export Excel", 'error'); }
        });

        exportCsvBtn.addEventListener('click', function() {
            try {
                const { headers, rows } = getExportData();
                if (rows.length === 0) { showAlert("Aucune donnée visible à exporter.", 'warning'); return; }
                let csvContent = headers.map(h => `"${h}"`).join(";") + "\n";
                rows.forEach(row => csvContent += row.map(cell => `"${cell}"`).join(";") + "\n");
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement("a");
                const url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", `comptes_rendus_doyen_${new Date().toISOString().slice(0,10)}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                showAlert("Export CSV réussi !", 'success');
            } catch (error) { console.error("Erreur lors de l'export CSV:", error); showAlert("Erreur lors de l'export CSV", 'error'); }
        });

        function downloadCrDetailsPdf() {
            if (!currentCrDetails) {
                showAlert("Aucune donnée de compte rendu à exporter en PDF.", "warning");
                return;
            }
            showLoading(true);
            const cr = currentCrDetails;
            const doc = new jsPDF();
            let currentY = 20;

            doc.setFontSize(18);
            doc.text('Fiche Détaillée du Compte Rendu', doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' });
            currentY += 10;
            doc.setFontSize(10);
            doc.text(`SYGECOS - Système de Gestion de Scolarité`, doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' });
            currentY += 15;

            doc.setFontSize(16);
            doc.setTextColor(59, 130, 246);
            doc.text(cr.titre_cr, 15, currentY);
            doc.line(15, currentY + 1, doc.internal.pageSize.getWidth() - 15, currentY + 1);
            currentY += 10;
            doc.setTextColor(0, 0, 0);

            doc.setFontSize(14);
            doc.text('Informations Générales', 15, currentY);
            currentY += 7;
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
                    ['Auteur:', cr.auteur_nom_complet || 'N/A'],
                    ['Statut:', cr.statut]
                ],
                theme: 'grid', styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 70 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 }
            });
            currentY = doc.autoTable.previous.finalY + 10;

            doc.setFontSize(14);
            doc.text('Dates', 15, currentY);
            currentY += 7;
            doc.autoTable({
                startY: currentY,
                body: [
                    ['Date de Création:', formatDate(cr.date_creation)],
                    ['Dernière Modification:', formatDate(cr.date_modification)]
                ],
                theme: 'grid', styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 70 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 }
            });
            currentY = doc.autoTable.previous.finalY + 10;

            // Adding content of the report. This will require more advanced jspdf plugins
            // or manual HTML to PDF conversion if the content is complex HTML.
            // For simplicity, we can just add it as raw text or use an HTML plugin.
            doc.setFontSize(14);
            doc.text('Contenu du Compte Rendu:', 15, currentY);
            currentY += 7;
            // A basic way to add HTML content is to convert it to plain text for PDF,
            // or use a library like html2canvas + jspdf-html2canvas or jspdf.html()
            // which are often heavy or complex for rich HTML.
            // For now, let's just add a placeholder or simple text from content.
            const contentText = document.getElementById('detailCrContenu').innerText; // Get plain text
            const textLines = doc.splitTextToSize(contentText, doc.internal.pageSize.getWidth() - 30);
            doc.text(textLines, 15, currentY);
            currentY += (textLines.length * doc.getLineHeight()) + 10; // Estimate space taken by text


            doc.save(`fiche_compte_rendu_${cr.titre_cr.replace(/\s/g, '_')}.pdf`);
            showLoading(false);
            showAlert("Fiche de compte rendu PDF générée avec succès !", 'success');
        }


        // --- Logique de recherche, filtre, tri ---
        function applyFiltersAndSort(sortType) {
            let filteredAndSortedData = [...allComptesRendus];

            // Applique la recherche
            const searchTerm = searchInput.value.toLowerCase();
            if (searchTerm) {
                filteredAndSortedData = filteredAndSortedData.filter(cr => {
                    return (
                        (cr.titre_cr && cr.titre_cr.toLowerCase().includes(searchTerm)) ||
                        (cr.president_seance && cr.president_seance.toLowerCase().includes(searchTerm)) ||
                        (cr.secretaire_reunion && cr.secretaire_reunion.toLowerCase().includes(searchTerm)) ||
                        (cr.auteur_nom_complet && cr.auteur_nom_complet.toLowerCase().includes(searchTerm))
                    );
                });
            }

            // Applique le filtre de statut
            if (currentStatutFilter !== 'all') {
                filteredAndSortedData = filteredAndSortedData.filter(cr => cr.statut === currentStatutFilter);
            }

            // Sorting is now done in PHP for `fetchAndRenderComptesRendus`
            // If you want client-side re-sorting for complex interactions, you can re-enable this.
            // For simplicity, assume server-side sorting is dominant.
            // If the data is re-fetched and already sorted, this step is redundant.
            // If filtering locally (e.g., after initial load), this sort is needed.
            filteredAndSortedData.sort((a, b) => {
                switch (sortType) {
                    case 'titre-asc': return (a.titre_cr || '').localeCompare(b.titre_cr || '');
                    case 'titre-desc': return (b.titre_cr || '').localeCompare(a.titre_cr || '');
                    case 'date-mod-asc': return new Date(a.date_modification) - new Date(b.date_modification);
                    case 'date-mod-desc': return new Date(b.date_modification) - new Date(a.date_modification);
                    default: return 0;
                }
            });

            displayComptesRendusTable(filteredAndSortedData);
            updateFilterButtonText();
        }

        // Event listeners for search and filter controls
        searchInput.addEventListener('input', () => {
            // Reset modal filters visual state but apply current search
            document.querySelector('input[name="sort_radio"][value="date-mod-desc"]').checked = true;
            document.querySelector('input[name="statut_filter_radio"][value="all"]').checked = true;
            currentSortType = 'date-mod-desc';
            currentStatutFilter = 'all';
            applyFiltersAndSort(currentSortType); // Apply client-side filter for responsiveness
            // fetchAndRenderComptesRendus(); // Optionally re-fetch from server for comprehensive results
        });
        searchButton.addEventListener('click', () => {
            // Force re-fetch from server with current search term and default sort/filters
            document.querySelector('input[name="sort_radio"][value="date-mod-desc"]').checked = true;
            document.querySelector('input[name="statut_filter_radio"][value="all"]').checked = true;
            currentSortType = 'date-mod-desc';
            currentStatutFilter = 'all';
            fetchAndRenderComptesRendus();
        });

        filterButton.addEventListener('click', function(event) {
            event.stopPropagation();
            filterModal.style.display = 'flex';
        });
        closeFilterModalBtn.addEventListener('click', () => filterModal.style.display = 'none');
        filterModal.addEventListener('click', (e) => { if (e.target === filterModal) filterModal.style.display = 'none'; });

        applyFilterModalBtn.addEventListener('click', function() {
            currentSortType = document.querySelector('input[name="sort_radio"]:checked').value;
            currentStatutFilter = document.querySelector('input[name="statut_filter_radio"]:checked').value;
            searchInput.value = ''; // Clear search when applying modal filters
            fetchAndRenderComptesRendus(); // Re-fetch from server with new filters
            filterModal.style.display = 'none';
        });

        resetFilterModalBtn.addEventListener('click', function() {
            currentSortType = 'date-mod-desc';
            currentStatutFilter = 'all';
            searchInput.value = '';

            document.querySelector('input[name="sort_radio"][value="date-mod-desc"]').checked = true;
            document.querySelector('input[name="statut_filter_radio"][value="all"]').checked = true;

            fetchAndRenderComptesRendus(); // Re-fetch from server with reset filters
            filterModal.style.display = 'none';
            showAlert('Filtres et recherche réinitialisés.', 'info');
        });

        function updateFilterButtonText() {
            let activeFiltersCount = 0;
            if (currentSortType !== 'date-mod-desc') activeFiltersCount++;
            if (currentStatutFilter !== 'all') activeFiltersCount++;
            if (searchInput.value.trim() !== '') activeFiltersCount++;

            if (activeFiltersCount > 0) {
                filterButtonText.textContent = `Filtres (${activeFiltersCount} actifs)`;
                filterButton.classList.add('btn-active-filter');
            } else {
                filterButtonText.textContent = 'Filtres';
                filterButton.classList.remove('btn-active-filter');
            }
        }

        // --- Initialisation ---
        document.addEventListener('DOMContentLoaded', function() {
            fetchAndRenderComptesRendus();
            initSidebar();
            updateFilterButtonText();

            // Expose functions to global scope for inline HTML event handlers
            window.viewCr = viewCr;
            window.updateCrStatus = updateCrStatus; // Make it globally accessible
            window.closeModal = closeModal;
            window.downloadCrDetailsPdf = downloadCrDetailsPdf;
        });

        // Sidebar and responsive functions
        function initSidebar() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (sidebarToggle && sidebar && mainContent) {
                handleResponsiveLayout();
                sidebarToggle.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
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
                    const barsIcon = sidebarToggle.querySelector('.fa-bars');
                    const timesIcon = sidebarToggle.querySelector('.fa-times');
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                });
            }
            window.addEventListener('resize', handleResponsiveLayout);
        }

        function handleResponsiveLayout() {
            const isMobile = window.innerWidth < 768;
            document.querySelectorAll('.action-text').forEach(text => { text.style.display = isMobile ? 'none' : 'inline'; });
            if (isMobile) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('sidebar-collapsed');
                sidebar.classList.remove('mobile-open');
                mobileMenuOverlay.classList.remove('active');
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('sidebar-collapsed');
                sidebar.classList.remove('mobile-open');
                mobileMenuOverlay.classList.remove('active');
            }
            if (sidebarToggle) {
                const barsIcon = sidebarToggle.querySelector('.fa-bars');
                const timesIcon = sidebarToggle.querySelector('.fa-times');
                if (isMobile) {
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                } else {
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                }
                if (sidebar.classList.contains('mobile-open')) {
                    if (barsIcon) barsIcon.style.display = 'none';
                    if (timesIcon) timesIcon.style.display = 'inline-block';
                }
            }
        }
    </script>
</body>
</html>