<?php
require_once 'config.php'; // Your database connection file

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Database connection
try {
    $pdo = new PDO('mysql:host=127.0.0.1:3306;dbname=sygecos', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Error connecting to database in planifications.php: " . $e->getMessage());
    die("Erreur de connexion à la base de données.");
}

// AJAX handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'];

        if ($action === 'get_soutenances') {
            // Fetch all planned soutenances for FullCalendar and the list
            $sql = "
                SELECT
                    s.id_soutenance,
                    s.date_soutenance,
                    s.heure_soutenance,
                    s.salle,
                    r.id_rapport,
                    r.theme_rapport,
                    CONCAT(e.nom_etu, ' ', e.prenoms_etu) AS etudiant_nom_complet,
                    GROUP_CONCAT(CONCAT(ens.nom_ens, ' ', ens.prenom_ens) ORDER BY js.role_jury SEPARATOR ', ') AS jury_membres
                FROM soutenance s
                JOIN rapports r ON s.fk_id_rapport = r.id_rapport
                JOIN etudiant e ON r.fk_num_etu = e.num_etu
                LEFT JOIN jury_soutenance js ON s.id_soutenance = js.fk_id_soutenance
                LEFT JOIN enseignant ens ON js.fk_id_ens = ens.id_ens
                WHERE s.statut_soutenance = 'planned' -- Only fetch active plans
                GROUP BY s.id_soutenance, s.date_soutenance, s.heure_soutenance, s.salle, r.id_rapport, r.theme_rapport, etudiant_nom_complet
                ORDER BY s.date_soutenance ASC, s.heure_soutenance ASC
            ";
            $stmt = $pdo->query($sql);
            $soutenances = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $soutenances]);
            exit;

        } elseif ($action === 'get_reports_for_planning') {
            // Fetch reports that are 'approuve' and have both a pedagogical supervisor and a thesis director attributed
            $sql = "
                SELECT
                    r.id_rapport,
                    r.theme_rapport,
                    CONCAT(e.nom_etu, ' ', e.prenoms_etu) AS etudiant_nom_complet,
                    MAX(CASE WHEN a.type_encadrement = 'pedagogical_supervisor' THEN a.date_attribution END) AS date_attribution_encadrant,
                    MAX(CASE WHEN a.type_encadrement = 'thesis_director' THEN a.date_attribution END) AS date_attribution_directeur
                FROM rapports r
                JOIN etudiant e ON r.fk_num_etu = e.num_etu
                WHERE r.statut = 'approuve'
                AND r.id_rapport NOT IN (SELECT fk_id_rapport FROM soutenance WHERE statut_soutenance = 'planned') -- Exclude already planned reports
                GROUP BY r.id_rapport, r.theme_rapport, etudiant_nom_complet
                HAVING COUNT(DISTINCT CASE WHEN a.type_encadrement = 'pedagogical_supervisor' THEN a.fk_id_ens END) > 0
                   AND COUNT(DISTINCT CASE WHEN a.type_encadrement = 'thesis_director' THEN a.fk_id_ens END) > 0
                ORDER BY r.theme_rapport ASC
            ";
            $stmt = $pdo->query($sql);
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $reports]);
            exit;

        } elseif ($action === 'get_enseignants') {
            // Fetch all teachers for jury selection
            $stmt = $pdo->query("SELECT id_ens, nom_ens, prenom_ens FROM enseignant ORDER BY nom_ens ASC");
            $enseignants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $enseignants]);
            exit;

        } elseif ($action === 'save_soutenance') {
            $soutenanceId = intval($_POST['id_soutenance'] ?? 0); // 0 for new, ID for edit
            $rapportId = intval($_POST['rapport_id']);
            $dateSoutenance = $_POST['date_soutenance'];
            $heureSoutenance = $_POST['heure_soutenance'];
            $salle = trim($_POST['salle']);
            $juryMembers = isset($_POST['jury_members']) ? $_POST['jury_members'] : [];

            if (empty($rapportId) || empty($dateSoutenance) || empty($heureSoutenance) || empty($salle) || empty($juryMembers)) {
                throw new Exception("Veuillez remplir tous les champs obligatoires (Rapport, Date, Heure, Salle, Membres du jury).");
            }

            // --- Business Rule Validations ---

            // 1. Date cannot be in the past (unless it's an existing soutenance being modified to a past date that already happened, but for new/future planning, it must be future)
            $currentDateTime = new DateTime();
            $soutenanceDateTime = new DateTime($dateSoutenance . ' ' . $heureSoutenance);

            if ($soutenanceId === 0 && $soutenanceDateTime < $currentDateTime) {
                throw new Exception("La date et l'heure de la soutenance ne peuvent pas être dans le passé.");
            }

            // 2. Date must be after attribution dates of encadrant and directeur
            $stmt = $pdo->prepare("
                SELECT
                    MAX(CASE WHEN type_encadrement = 'pedagogical_supervisor' THEN date_attribution END) AS date_encadrant_attribution,
                    MAX(CASE WHEN type_encadrement = 'thesis_director' THEN date_attribution END) AS date_directeur_attribution
                FROM affecter
                WHERE fk_id_rapport = ?
                GROUP BY fk_id_rapport
            ");
            $stmt->execute([$rapportId]);
            $attributionDates = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($attributionDates) {
                $earliestAttributionDate = null;
                if ($attributionDates['date_encadrant_attribution']) {
                    $earliestAttributionDate = new DateTime($attributionDates['date_encadrant_attribution']);
                }
                if ($attributionDates['date_directeur_attribution']) {
                    $directeurAttributionDate = new DateTime($attributionDates['date_directeur_attribution']);
                    if ($earliestAttributionDate === null || $directeurAttributionDate < $earliestAttributionDate) {
                        $earliestAttributionDate = $directeurAttributionDate;
                    }
                }

                if ($earliestAttributionDate && $soutenanceDateTime <= $earliestAttributionDate) {
                    throw new Exception("La date de soutenance doit être postérieure à la date d'attribution de l'encadrant et du directeur (au plus tôt le " . $earliestAttributionDate->format('d/m/Y H:i') . ").");
                }
            } else {
                throw new Exception("Le rapport n'a pas encore d'encadrant ou de directeur attribué. Impossible de planifier la soutenance.");
            }


            // 3. Max 2 defenses per day
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM soutenance WHERE date_soutenance = ? AND id_soutenance != ? AND statut_soutenance = 'planned'");
            $stmt->execute([$dateSoutenance, $soutenanceId]);
            $soutenancesToday = $stmt->fetchColumn();

            if ($soutenancesToday >= 2) {
                throw new Exception("Il ne peut y avoir que 2 soutenances par jour. Cette date est déjà pleine.");
            }

            // 4. No time conflict in the same room
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM soutenance WHERE date_soutenance = ? AND salle = ? AND heure_soutenance = ? AND id_soutenance != ? AND statut_soutenance = 'planned'");
            $stmt->execute([$dateSoutenance, $salle, $heureSoutenance, $soutenanceId]);
            $roomTimeConflict = $stmt->fetchColumn();

            if ($roomTimeConflict > 0) {
                throw new Exception("La salle '{$salle}' est déjà occupée à cette date et heure.");
            }

            // Start transaction for atomicity
            $pdo->beginTransaction();

            if ($soutenanceId === 0) {
                // New Soutenance
                $insertStmt = $pdo->prepare("
                    INSERT INTO soutenance (fk_id_rapport, date_soutenance, heure_soutenance, salle)
                    VALUES (?, ?, ?, ?)
                ");
                $insertStmt->execute([$rapportId, $dateSoutenance, $heureSoutenance, $salle]);
                $soutenanceId = $pdo->lastInsertId();
            } else {
                // Edit existing Soutenance
                $updateStmt = $pdo->prepare("
                    UPDATE soutenance
                    SET fk_id_rapport = ?, date_soutenance = ?, heure_soutenance = ?, salle = ?, date_modification = CURRENT_TIMESTAMP
                    WHERE id_soutenance = ?
                ");
                $updateStmt->execute([$rapportId, $dateSoutenance, $heureSoutenance, $salle, $soutenanceId]);

                // Clear existing jury members for this soutenance before re-inserting
                $deleteJuryStmt = $pdo->prepare("DELETE FROM jury_soutenance WHERE fk_id_soutenance = ?");
                $deleteJuryStmt->execute([$soutenanceId]);
            }

            // Insert jury members
            $insertJuryStmt = $pdo->prepare("
                INSERT INTO jury_soutenance (fk_id_soutenance, fk_id_ens)
                VALUES (?, ?)
            ");
            foreach ($juryMembers as $memberId) {
                $insertJuryStmt->execute([$soutenanceId, $memberId]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Planification enregistrée avec succès.', 'id_soutenance' => $soutenanceId]);
            exit;

        } elseif ($action === 'get_soutenance_details') {
            $soutenanceId = intval($_POST['soutenance_id']);
            $sql = "
                SELECT
                    s.id_soutenance,
                    s.fk_id_rapport,
                    s.date_soutenance,
                    s.heure_soutenance,
                    s.salle,
                    r.theme_rapport,
                    CONCAT(e.nom_etu, ' ', e.prenoms_etu) AS etudiant_nom_complet,
                    GROUP_CONCAT(js.fk_id_ens) AS jury_member_ids
                FROM soutenance s
                JOIN rapports r ON s.fk_id_rapport = r.id_rapport
                JOIN etudiant e ON r.fk_num_etu = e.num_etu
                LEFT JOIN jury_soutenance js ON s.id_soutenance = js.fk_id_soutenance
                WHERE s.id_soutenance = ?
                GROUP BY s.id_soutenance, s.fk_id_rapport, s.date_soutenance, s.heure_soutenance, s.salle, r.theme_rapport, etudiant_nom_complet
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$soutenanceId]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($details) {
                // Convert jury_member_ids string to array
                $details['jury_member_ids'] = $details['jury_member_ids'] ? explode(',', $details['jury_member_ids']) : [];
                echo json_encode(['success' => true, 'data' => $details]);
            } else {
                throw new Exception("Détails de la soutenance introuvables.");
            }
            exit;

        } elseif ($action === 'delete_soutenance') {
            $soutenanceId = intval($_POST['soutenance_id']);

            $pdo->beginTransaction();
            try {
                // Delete jury members first due to foreign key
                $deleteJuryStmt = $pdo->prepare("DELETE FROM jury_soutenance WHERE fk_id_soutenance = ?");
                $deleteJuryStmt->execute([$soutenanceId]);

                // Then delete the soutenance
                $deleteSoutenanceStmt = $pdo->prepare("DELETE FROM soutenance WHERE id_soutenance = ?");
                $deleteSoutenanceStmt->execute([$soutenanceId]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Soutenance annulée avec succès.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            exit;
        }

    } catch (Exception $e) {
        error_log("Error in planifications.php AJAX: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Planification</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <style>
        /* Your existing CSS (as provided in the initial attributions.php) should go here */
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
        .sidebar-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); } .sidebar-toggle:hover { background: var(--gray-200); color: var(--gray-800); }
        .page-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-800); }
        .topbar-right { display: flex; align-items: center; gap: var(--space-4); }
        .topbar-button { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); position: relative; } .topbar-button:hover { background: var(--gray-200); color: var(--gray-800); }
        .notification-badge { position: absolute; top: -2px; right: -2px; width: 18px; height: 18px; background: var(--error-500); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: white; }
        .user-menu { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-2) var(--space-3); border-radius: var(--radius-lg); cursor: pointer; transition: background var(--transition-fast); } .user-menu:hover { background: var(--gray-100); }
        .user-info { text-align: right; } .user-name { font-size: var(--text-sm); font-weight: 600; color: var(--gray-800); line-height: 1.2; } .user-role { font-size: var(--text-xs); color: var(--gray-500); }

        /* === PAGE CONTENT === */
        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); display: flex; justify-content: space-between; align-items: center; }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); margin-top: var(--space-2); }

        .student-profile { display: grid; grid-template-columns: 280px 1fr; gap: var(--space-8); margin-bottom: var(--space-8); }
        .profile-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); }
        .profile-header { display: flex; flex-direction: column; align-items: center; text-align: center; margin-bottom: var(--space-6); }
        .profile-avatar { width: 120px; height: 120px; border-radius: 50%; background-color: var(--gray-200); display: flex; align-items: center; justify-content: center; margin-bottom: var(--space-4); overflow: hidden; }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-name { font-size: var(--text-xl); font-weight: 700; margin-bottom: var(--space-1); }
        .profile-id { color: var(--gray-600); font-size: var(--text-sm); margin-bottom: var(--space-4); }
        .profile-badge { display: inline-block; padding: var(--space-1) var(--space-3); background-color: var(--secondary-100); color: var(--secondary-600); border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 600; }
        .profile-details { width: 100%; }
        .detail-item { display: flex; justify-content: space-between; padding: var(--space-3) 0; border-bottom: 1px solid var(--gray-200); }
        .detail-label { color: var(--gray-600); font-weight: 600; }
        .detail-value { color: var(--gray-800); text-align: right; }

        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: var(--space-6); }
        .info-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); }
        .info-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); }
        .info-card-title { font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); }
        .info-card-icon { width: 40px; height: 40px; background-color: var(--accent-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; color: var(--accent-600); }

        .table-container { background: var(--white); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); overflow: hidden; margin-bottom: var(--space-8); }
        .table-header { padding: var(--space-6); border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; }
        .table-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .table-actions { display: flex; gap: var(--space-3); align-items: center; }

        .table-wrapper { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: var(--space-3); text-align: left; border-bottom: 1px solid var(--gray-200); font-size: var(--text-sm); }
        .data-table th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); }
        .data-table tbody tr:hover { background-color: var(--gray-50); }
        .data-table td { color: var(--gray-800); }

        .badge { padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; }
        .badge-success { background-color: var(--secondary-100); color: var(--secondary-600); }
        .badge-warning { background-color: #fef3c7; color: #d97706; }
        .badge-info { background-color: var(--accent-100); color: var(--accent-600); }

        .action-buttons { display: flex; gap: var(--space-1); }
        .btn { padding: var(--space-2) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); text-decoration: none; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover:not(:disabled) { background-color: var(--accent-700); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover:not(:disabled) { background-color: var(--secondary-600); }
        .btn-warning { background-color: var(--warning-500); color: white; } .btn-warning:hover:not(:disabled) { background-color: #f59e0b; }
        .btn-danger { background-color: var(--error-500); color: white; } .btn-danger:hover:not(:disabled) { background-color: #dc2626; }
        .btn-outline { background-color: transparent; color: var(--accent-600); border: 1px solid var(--accent-600); } .btn-outline:hover { background-color: var(--accent-50); }
        .btn-sm { padding: var(--space-1) var(--space-2); font-size: var(--text-xs); }

        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); display: none; }
        .alert.success { background-color: var(--secondary-50); color: var(--secondary-600); border: 1px solid var(--secondary-100); }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }

        .loading-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); display: none; align-items: center; justify-content: center; z-index: 9999; }
        .loading-spinner { width: 40px; height: 40px; border: 4px solid var(--gray-300); border-top-color: var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .empty-state { text-align: center; padding: var(--space-16); color: var(--gray-500); }
        .empty-state i { font-size: 3rem; margin-bottom: var(--space-4); }

        /* Responsive */
        @media (max-width: 992px) {
            .student-profile { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .main-content.sidebar-collapsed { margin-left: 0; }
            .page-header { flex-direction: column; align-items: flex-start; gap: var(--space-4); }
            .info-grid { grid-template-columns: 1fr; }
        }
        .fc-event {
            cursor: pointer;
        }
        .badge-soutenance {
            background-color: #ecfdf5;
            color: #047857;
        }
        #calendar {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        /* Message Modal Styles (copied from attributions.php for consistency) */
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
    </style>
</head>
<body>
    <?php include 'sidebar_commision.php'; ?>

    <main class="main-content" id="mainContent">
        <?php include 'topbar.php'; ?>

        <div class="page-content">
            <div class="container-fluid py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="page-title-main mb-0">Planification des soutenances</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#planificationModal" id="newPlanificationBtn">
                        <i class="fas fa-plus me-2"></i>Nouvelle planification
                    </button>
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

                <div class="row">
                    <div class="col-md-8">
                        <div id="calendar" class="p-3"></div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Prochaines soutenances</h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group" id="upcomingSoutenancesList">
                                    <div class="empty-state" id="noUpcomingSoutenances" style="display: none;">
                                        <i class="fas fa-calendar-check"></i>
                                        <p>Aucune soutenance planifiée prochainement.</p>
                                    </div>
                                    </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="planificationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="planificationModalTitle">Planifier une soutenance</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="planificationForm">
                        <input type="hidden" id="editSoutenanceId">
                        <div class="mb-3">
                            <label for="selectRapport" class="form-label">Rapport à soutenir</label>
                            <select class="form-select" id="selectRapport" required>
                                <option value="">Sélectionner un rapport</option>
                                </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="soutenanceDate" class="form-label">Date</label>
                                <input type="date" class="form-control" id="soutenanceDate" required min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="soutenanceHeure" class="form-label">Heure</label>
                                <input type="time" class="form-control" id="soutenanceHeure" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="salle" class="form-label">Salle</label>
                            <input type="text" class="form-control" id="salle" placeholder="Ex: Amphithéâtre A" required>
                        </div>

                        <div class="mb-3">
                            <label for="juryMembers" class="form-label">Membres du jury</label>
                            <select class="form-select" id="juryMembers" multiple required>
                                </select>
                            <small class="form-text text-muted">Utilisez Ctrl/Cmd pour sélectionner plusieurs membres.</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-primary" id="savePlanificationBtn">Planifier</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="soutenanceDetailsModal" tabindex="-1" aria-labelledby="soutenanceDetailsModalTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="soutenanceDetailsModalTitle">Détails de la Soutenance</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Rapport:</strong> <span id="detailRapportTheme"></span></p>
                    <p><strong>Étudiant:</strong> <span id="detailEtudiantNom"></span></p>
                    <p><strong>Date:</strong> <span id="detailDateSoutenance"></span></p>
                    <p><strong>Heure:</strong> <span id="detailHeureSoutenance"></span></p>
                    <p><strong>Salle:</strong> <span id="detailSalle"></span></p>
                    <p><strong>Membres du jury:</strong> <span id="detailJuryMembres"></span></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="button" class="btn btn-primary" id="editSoutenanceBtn"><i class="fas fa-edit me-2"></i>Modifier</button>
                    <button type="button" class="btn btn-danger" id="cancelSoutenanceBtn"><i class="fas fa-times-circle me-2"></i>Annuler la soutenance</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/fr.min.js"></script>
    <script>
        // DOM Elements for Planning
        const newPlanificationBtn = document.getElementById('newPlanificationBtn');
        const planificationModal = new bootstrap.Modal(document.getElementById('planificationModal'));
        const planificationModalTitle = document.getElementById('planificationModalTitle');
        const planificationForm = document.getElementById('planificationForm');
        const editSoutenanceId = document.getElementById('editSoutenanceId');
        const selectRapport = document.getElementById('selectRapport');
        const soutenanceDate = document.getElementById('soutenanceDate');
        const soutenanceHeure = document.getElementById('soutenanceHeure');
        const salle = document.getElementById('salle');
        const juryMembersSelect = document.getElementById('juryMembers'); // Renamed to avoid conflict
        const savePlanificationBtn = document.getElementById('savePlanificationBtn');
        const upcomingSoutenancesList = document.getElementById('upcomingSoutenancesList');
        const noUpcomingSoutenances = document.getElementById('noUpcomingSoutenances');

        // Modals for details
        const soutenanceDetailsModal = new bootstrap.Modal(document.getElementById('soutenanceDetailsModal'));
        const detailRapportTheme = document.getElementById('detailRapportTheme');
        const detailEtudiantNom = document.getElementById('detailEtudiantNom');
        const detailDateSoutenance = document.getElementById('detailDateSoutenance');
        const detailHeureSoutenance = document.getElementById('detailHeureSoutenance');
        const detailSalle = document.getElementById('detailSalle');
        const detailJuryMembres = document.getElementById('detailJuryMembres');
        const editSoutenanceBtn = document.getElementById('editSoutenanceBtn');
        const cancelSoutenanceBtn = document.getElementById('cancelSoutenanceBtn');

        // Global variables for data
        let allReportsForPlanning = []; // Reports that are approved and attributed
        let allEnseignants = [];
        let currentSoutenanceId = null; // Used for edit/delete operations
        let calendar; // FullCalendar instance

        // Message Modal elements (from attributions.php)
        const messageModal = document.getElementById('messageModal');
        const messageTitle = document.getElementById('messageTitle');
        const messageText = document.getElementById('messageText');
        const messageIcon = document.getElementById('messageIcon');
        const messageButton = document.getElementById('messageButton');
        const messageClose = document.getElementById('messageClose');
        const loadingOverlay = document.getElementById('loadingOverlay');

        // Sidebar elements for responsiveness (from attributions.php)
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');


        // --- Utility Functions ---
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

        function formatDateTime(date, time) {
            if (!date || !time) return 'N/A';
            const dateTimeString = `${date}T${time}:00`;
            const d = new Date(dateTimeString);
            return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' à ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        }

        // --- Data Fetching and Rendering ---

        async function fetchAndRenderSoutenances() {
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ action: 'get_soutenances' });
                if (result.success) {
                    const soutenances = result.data;
                    renderCalendarEvents(soutenances);
                    renderUpcomingSoutenancesList(soutenances);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des soutenances. Veuillez réessayer.', 'error');
            } finally {
                showLoading(false);
            }
        }

        function renderCalendarEvents(soutenances) {
            const events = soutenances.map(s => ({
                id: s.id_soutenance,
                title: `${s.theme_rapport} - ${s.etudiant_nom_complet}`,
                start: `${s.date_soutenance}T${s.heure_soutenance}`,
                backgroundColor: '#3b82f6', // Accent color
                borderColor: '#3b82f6',
                extendedProps: { // Store additional data for modal
                    rapport: s.theme_rapport,
                    etudiant: s.etudiant_nom_complet,
                    salle: s.salle,
                    jury: s.jury_membres // This is a string, will need parsing for edit mode
                }
            }));
            calendar.removeAllEvents();
            calendar.addEventSource(events);
            calendar.render();
        }

        function renderUpcomingSoutenancesList(soutenances) {
            upcomingSoutenancesList.innerHTML = '';
            const now = new Date();
            const upcoming = soutenances.filter(s => new Date(`${s.date_soutenance}T${s.heure_soutenance}`) > now);

            if (upcoming.length === 0) {
                noUpcomingSoutenances.style.display = 'block';
                return;
            } else {
                noUpcomingSoutenances.style.display = 'none';
            }

            upcoming.slice(0, 5).forEach(s => { // Show up to 5 upcoming
                const listItem = document.createElement('div');
                listItem.classList.add('list-group-item', 'd-flex', 'flex-column');
                listItem.innerHTML = `
                    <div class="d-flex justify-content-between">
                        <strong>${htmlspecialchars(s.theme_rapport)}</strong>
                        <span class="badge badge-soutenance rounded-pill">
                            ${formatDateTime(s.date_soutenance, s.heure_soutenance)}
                        </span>
                    </div>
                    <small class="text-muted">Étudiant: ${htmlspecialchars(s.etudiant_nom_complet)}</small>
                    <div class="mt-2">
                        <small>
                            <i class="fas fa-map-marker-alt me-1"></i>
                            Salle: ${htmlspecialchars(s.salle)}
                        </small>
                        <br>
                        <small>
                            <i class="fas fa-users me-1"></i>
                            Jury: ${s.jury_membres ? htmlspecialchars(s.jury_membres) : 'Non défini'}
                        </small>
                    </div>
                    <div class="d-flex justify-content-end mt-2">
                        <button class="btn btn-sm btn-outline-primary" onclick="openSoutenanceDetailsModal('${s.id_soutenance}')">
                            <i class="fas fa-info-circle"></i> Détails
                        </button>
                    </div>
                `;
                upcomingSoutenancesList.appendChild(listItem);
            });
        }


        async function fetchReportsForPlanning() {
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ action: 'get_reports_for_planning' });
                if (result.success) {
                    allReportsForPlanning = result.data;
                    populateRapportDropdown();
                } else {
                    showAlert(result.message, 'error', 'Erreur chargement rapports');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des rapports éligibles.', 'error', 'Erreur réseau');
            } finally {
                showLoading(false);
            }
        }

        function populateRapportDropdown() {
            selectRapport.innerHTML = '<option value="">Sélectionner un rapport</option>';
            allReportsForPlanning.forEach(report => {
                const option = document.createElement('option');
                option.value = report.id_rapport;
                option.textContent = `${htmlspecialchars(report.theme_rapport)} - ${htmlspecialchars(report.etudiant_nom_complet)}`;
                option.dataset.dateAttributionEncadrant = report.date_attribution_encadrant; // Store attribution dates
                option.dataset.dateAttributionDirecteur = report.date_attribution_directeur;
                selectRapport.appendChild(option);
            });
        }

        async function fetchEnseignants() {
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ action: 'get_enseignants' });
                if (result.success) {
                    allEnseignants = result.data;
                    populateJuryMembersDropdown();
                } else {
                    showAlert(result.message, 'error', 'Erreur chargement enseignants');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des enseignants.', 'error', 'Erreur réseau');
            } finally {
                showLoading(false);
            }
        }

        function populateJuryMembersDropdown() {
            juryMembersSelect.innerHTML = ''; // Clear previous options
            allEnseignants.forEach(ens => {
                const option = document.createElement('option');
                option.value = ens.id_ens;
                option.textContent = `${htmlspecialchars(ens.nom_ens)} ${htmlspecialchars(ens.prenom_ens)}`;
                juryMembersSelect.appendChild(option);
            });
        }

        // --- Modal & Form Logic ---
        newPlanificationBtn.addEventListener('click', function() {
            planificationModalTitle.textContent = 'Planifier une nouvelle soutenance';
            planificationForm.reset();
            editSoutenanceId.value = ''; // Clear for new entry
            selectRapport.disabled = false; // Enable for new entry
            soutenanceDate.min = new Date().toISOString().split('T')[0]; // Set min date to today
            fetchReportsForPlanning(); // Reload available reports
            if (allEnseignants.length === 0) { // Only fetch if not already loaded
                fetchEnseignants();
            } else {
                populateJuryMembersDropdown();
            }
        });

        savePlanificationBtn.addEventListener('click', async function() {
            const rapportId = selectRapport.value;
            const date = soutenanceDate.value;
            const heure = soutenanceHeure.value;
            const room = salle.value;
            const selectedJuryMembers = Array.from(juryMembersSelect.selectedOptions).map(option => option.value);
            const currentEditId = editSoutenanceId.value;

            if (!rapportId || !date || !heure || !room || selectedJuryMembers.length === 0) {
                showAlert("Veuillez remplir tous les champs obligatoires et sélectionner au moins un membre du jury.", "warning");
                return;
            }

            // Client-side date validation (basic)
            const selectedDate = new Date(date);
            const today = new Date();
            today.setHours(0, 0, 0, 0); // Normalize today to start of day

            if (!currentEditId && selectedDate < today) { // Only for new entries, not editing past ones
                showAlert("La date de soutenance ne peut pas être dans le passé.", "warning");
                return;
            }

            showLoading(true);
            try {
                const result = await makeAjaxRequest({
                    action: 'save_soutenance',
                    id_soutenance: currentEditId,
                    rapport_id: rapportId,
                    date_soutenance: date,
                    heure_soutenance: heure,
                    salle: room,
                    jury_members: selectedJuryMembers
                });

                if (result.success) {
                    showAlert(result.message, 'success');
                    planificationModal.hide();
                    fetchAndRenderSoutenances(); // Refresh calendar and list
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'enregistrement de la planification.', 'error');
            } finally {
                showLoading(false);
            }
        });

        // Function to open modal for editing or viewing details
        async function openSoutenanceDetailsModal(soutenanceID) {
            currentSoutenanceId = soutenanceID; // Store ID for edit/delete actions
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ action: 'get_soutenance_details', soutenance_id: soutenanceID });
                if (result.success && result.data) {
                    const data = result.data;
                    detailRapportTheme.textContent = htmlspecialchars(data.theme_rapport);
                    detailEtudiantNom.textContent = htmlspecialchars(data.etudiant_nom_complet);
                    detailDateSoutenance.textContent = new Date(data.date_soutenance).toLocaleDateString('fr-FR');
                    detailHeureSoutenance.textContent = data.heure_soutenance.substring(0, 5); // Format HH:MM
                    detailSalle.textContent = htmlspecialchars(data.salle);

                    // Map jury member IDs back to names for display
                    const juryNames = data.jury_member_ids
                        .map(id => allEnseignants.find(ens => ens.id_ens == id))
                        .filter(Boolean) // Remove undefined if ID not found
                        .map(ens => `${ens.nom_ens} ${ens.prenom_ens}`);
                    detailJuryMembres.textContent = juryNames.join(', ');

                    soutenanceDetailsModal.show();
                } else {
                    showAlert(result.message || 'Détails de la soutenance introuvables.', 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la récupération des détails de la soutenance.', 'error');
            } finally {
                showLoading(false);
            }
        }

        // Edit button inside details modal
        editSoutenanceBtn.addEventListener('click', async function() {
            soutenanceDetailsModal.hide(); // Hide details modal
            // Fetch details again to populate the planning form for editing
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ action: 'get_soutenance_details', soutenance_id: currentSoutenanceId });
                if (result.success && result.data) {
                    const data = result.data;
                    planificationModalTitle.textContent = 'Modifier la planification';
                    editSoutenanceId.value = data.id_soutenance;
                    selectRapport.disabled = true; // Disable rapport selection when editing an existing soutenance

                    // Populate form fields
                    // Need to fetch ALL reports to ensure the selected one is in the dropdown for display,
                    // even if it's no longer 'eligible' for new planning.
                    // This can be simplified if backend `get_reports_for_planning` returns all approved reports,
                    // or if the selectRapport dropdown is always populated with all.
                    // For now, we will ensure it exists in the select and is selected.
                    if (allReportsForPlanning.length === 0) {
                         await fetchReportsForPlanning(); // Re-fetch to ensure all possible reports are available
                    }
                    // Add the current rapport if it's not already in the dropdown
                    if (!Array.from(selectRapport.options).some(option => option.value == data.fk_id_rapport)) {
                         const option = document.createElement('option');
                         option.value = data.fk_id_rapport;
                         option.textContent = `${htmlspecialchars(data.theme_rapport)} - ${htmlspecialchars(data.etudiant_nom_complet)}`;
                         selectRapport.appendChild(option);
                    }
                    selectRapport.value = data.fk_id_rapport;


                    soutenanceDate.value = data.date_soutenance;
                    soutenanceHeure.value = data.heure_soutenance.substring(0, 5); // HH:MM
                    salle.value = htmlspecialchars(data.salle);

                    // Populate jury members dropdown and select current ones
                    if (allEnseignants.length === 0) {
                        await fetchEnseignants();
                    }
                    // Select multiple options in the multiselect
                    Array.from(juryMembersSelect.options).forEach(option => {
                        option.selected = data.jury_member_ids.includes(option.value);
                    });

                    planificationModal.show(); // Show the planning modal for editing
                } else {
                    showAlert(result.message || 'Impossible de charger les données pour modification.', 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la préparation de la modification.', 'error');
            } finally {
                showLoading(false);
            }
        });

        // Cancel/Delete Soutenance button in details modal
        cancelSoutenanceBtn.addEventListener('click', async function() {
            if (!confirm("Êtes-vous sûr de vouloir annuler cette soutenance ? Cette action est irréversible.")) {
                return;
            }
            soutenanceDetailsModal.hide(); // Hide details modal immediately
            showLoading(true);
            try {
                const result = await makeAjaxRequest({ action: 'delete_soutenance', soutenance_id: currentSoutenanceId });
                if (result.success) {
                    showAlert(result.message, 'success');
                    fetchAndRenderSoutenances(); // Refresh calendar and list
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'annulation de la soutenance.', 'error');
            } finally {
                showLoading(false);
            }
        });

        // --- FullCalendar Initialization ---
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'fr',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                // Event click handler to show details modal
                eventClick: function(info) {
                    openSoutenanceDetailsModal(info.event.id);
                }
            });
            // Calendar rendering is now handled by fetchAndRenderSoutenances()
            // calendar.render(); // This line is no longer needed here

            // Initial data fetch
            fetchAndRenderSoutenances();
            fetchEnseignants(); // Fetch teachers once on load
            // reports for planning are fetched when newPlanificationBtn is clicked or edit is opened

            // Initialize sidebar responsiveness (copied from attributions.php)
            initSidebar();
        });

        // Sidebar responsiveness functions (copied from attributions.php for consistency)
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
            if (sidebar) {
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
                if (sidebar && sidebar.classList.contains('mobile-open')) {
                    if (barsIcon) barsIcon.style.display = 'none';
                    if (timesIcon) timesIcon.style.display = 'inline-block';
                }
            }
        }
    </script>
</body>
</html>