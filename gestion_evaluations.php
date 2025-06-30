<?php
// gestion_notes.php
require_once 'config.php'; // Connexion PDO et fonctions de sécurité

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Récupération de l'année académique active
$anneeActive = null;
try {
    $stmt = $pdo->query("SELECT id_Ac, CONCAT(YEAR(date_deb), '-', YEAR(date_fin)) as annee_libelle FROM année_academique WHERE statut = 'active' OR est_courante = 1 LIMIT 1");
    $anneeActive = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération année active: " . $e->getMessage());
}

// Récupération des filières
$filieres = [];
try {
    $stmt = $pdo->query("SELECT id_filiere, lib_filiere FROM filiere ORDER BY lib_filiere");
    $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération filières: " . $e->getMessage());
}

// === TRAITEMENT AJAX ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'charger_filieres': // NEW ACTION: Charger les filières
                $stmt = $pdo->query("SELECT id_filiere, lib_filiere FROM filiere ORDER BY lib_filiere");
                $filieresData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $filieresData]);
                break;
            
            case 'charger_niveaux_par_filiere': // NEW ACTION: Charger les niveaux d'étude pour une filière
                $filiereId = intval($_POST['filiere_id'] ?? 0);
                if ($filiereId <= 0) {
                    throw new Exception("ID de filière manquant");
                }
                $stmt = $pdo->prepare("SELECT id_niv_etu, lib_niv_etu FROM niveau_etude WHERE fk_id_filiere = ? ORDER BY lib_niv_etu");
                $stmt->execute([$filiereId]);
                $niveauxData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $niveauxData]);
                break;
                
            case 'charger_etudiants_niveau':
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $niveauId = intval($_POST['niveau_id'] ?? 0);
                
                if ($anneeId <= 0 || $niveauId <= 0) {
                    throw new Exception("Paramètres manquants");
                }
                
                // Récupérer tous les étudiants du niveau inscrit pour cette année
                $stmt = $pdo->prepare("
                    SELECT DISTINCT 
                        e.num_etu, 
                        e.nom_etu, 
                        e.prenoms_etu, 
                        e.dte_naiss_etu,
                        e.email_etu,
                        f.lib_filiere,
                        ne.lib_niv_etu
                    FROM etudiant e
                    INNER JOIN inscrire i ON e.num_etu = i.fk_num_etu
                    LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                    LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                    WHERE i.fk_id_Ac = ? 
                    AND e.fk_id_niv_etu = ?
                    ORDER BY e.nom_etu, e.prenoms_etu
                ");
                $stmt->execute([$anneeId, $niveauId]);
                $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $etudiants]);
                break;
                
            case 'charger_ue':
                $anneeId = intval($_POST['annee_id'] ?? 0);
                
                if ($anneeId <= 0) {
                    throw new Exception("Année académique non spécifiée");
                }
                
                $stmt = $pdo->prepare("
                    SELECT id_UE, lib_UE, credit_UE 
                    FROM ue 
                    WHERE id_Ac = ? 
                    ORDER BY lib_UE
                ");
                $stmt->execute([$anneeId]);
                $ues = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $ues]);
                break;
                
            case 'charger_ecue':
                $ueId = intval($_POST['ue_id'] ?? 0);
                
                if ($ueId <= 0) {
                    throw new Exception("UE non spécifiée");
                }
                
                $stmt = $pdo->prepare("
                    SELECT id_ECUE, lib_ECUE, credit_ECUE 
                    FROM ecue 
                    WHERE fk_id_UE = ? 
                    ORDER BY lib_ECUE
                ");
                $stmt->execute([$ueId]);
                $ecues = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $ecues]);
                break;
                
            case 'charger_notes_ecue':
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $niveauId = intval($_POST['niveau_id'] ?? 0);
                $ecueId = intval($_POST['ecue_id'] ?? 0);
                
                if ($anneeId <= 0 || $niveauId <= 0 || $ecueId <= 0) {
                    throw new Exception("Paramètres manquants");
                }
                
                // Récupérer les notes pour cette ECUE pour tous les étudiants du niveau
                $stmt = $pdo->prepare("
                    SELECT DISTINCT 
                        e.num_etu,
                        ev.note, 
                        ev.dte_eval, 
                        ev.id_eval
                    FROM etudiant e
                    INNER JOIN inscrire i ON e.num_etu = i.fk_num_etu
                    LEFT JOIN evaluer ev ON (ev.fk_num_etu = e.num_etu AND ev.fk_id_ECUE = ? AND ev.fk_id_Ac = ?)
                    WHERE i.fk_id_Ac = ? 
                    AND e.fk_id_niv_etu = ?
                    ORDER BY e.nom_etu, e.prenoms_etu
                ");
                $stmt->execute([$ecueId, $anneeId, $anneeId, $niveauId]);
                $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Organiser par matricule
                $notesParEtudiant = [];
                foreach ($notes as $note) {
                    $notesParEtudiant[$note['num_etu']] = $note;
                }
                
                echo json_encode(['success' => true, 'data' => $notesParEtudiant]);
                break;
                
            case 'sauvegarder_note':
                $numEtu = trim($_POST['num_etu'] ?? '');
                $ecueId = intval($_POST['ecue_id'] ?? 0);
                $note = floatval($_POST['note'] ?? 0);
                $dateEval = $_POST['date_eval'] ?? '';
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $idEval = intval($_POST['id_eval'] ?? 0);
                
                // Validation
                if (empty($numEtu) || $ecueId <= 0 || $anneeId <= 0) {
                    throw new Exception("Données manquantes");
                }
                
                if ($note < 0 || $note > 20) {
                    throw new Exception("La note doit être comprise entre 0 et 20");
                }
                
                if (!DateTime::createFromFormat('Y-m-d', $dateEval)) {
                    throw new Exception("Format de date invalide");
                }
                
                $pdo->beginTransaction();
                
                // ID enseignant (à adapter selon votre système d'auth)
                $idEnseignant = 1;
                
                if ($idEval > 0) {
                    // Mise à jour
                    $stmt = $pdo->prepare("
                        UPDATE evaluer 
                        SET note = ?, dte_eval = ?, fk_id_ens = ?
                        WHERE id_eval = ?
                    ");
                    $stmt->execute([$note, $dateEval, $idEnseignant, $idEval]);
                    $message = "Note mise à jour avec succès";
                } else {
                    // Création
                    $checkStmt = $pdo->prepare("
                        SELECT id_eval FROM evaluer 
                        WHERE fk_num_etu = ? AND fk_id_ECUE = ? AND fk_id_Ac = ?
                    ");
                    $checkStmt->execute([$numEtu, $ecueId, $anneeId]);
                    
                    if ($checkStmt->fetch()) {
                        throw new Exception("Une note existe déjà pour cet étudiant dans cette ECUE");
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO evaluer (fk_num_etu, fk_id_ECUE, fk_id_ens, dte_eval, note, fk_id_Ac)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$numEtu, $ecueId, $idEnseignant, $dateEval, $note, $anneeId]);
                    $message = "Note enregistrée avec succès";
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => $message]);
                break;
                
            case 'obtenir_bulletin':
                $numEtu = trim($_POST['num_etu'] ?? '');
                $anneeId = intval($_POST['annee_id'] ?? 0);
                
                if (empty($numEtu) || $anneeId <= 0) {
                    throw new Exception("Paramètres manquants");
                }
                
                // Récupérer les infos de l'étudiant
                $stmtEtu = $pdo->prepare("
                    SELECT e.*, f.lib_filiere, ne.lib_niv_etu,
                           CONCAT(YEAR(aa.date_deb), '-', YEAR(aa.date_fin)) as annee_libelle
                    FROM etudiant e
                    LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                    LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                    LEFT JOIN inscrire i ON e.num_etu = i.fk_num_etu
                    LEFT JOIN année_academique aa ON i.fk_id_Ac = aa.id_Ac
                    WHERE e.num_etu = ? AND aa.id_Ac = ?
                ");
                $stmtEtu->execute([$numEtu, $anneeId]);
                $etudiant = $stmtEtu->fetch(PDO::FETCH_ASSOC);
                
                if (!$etudiant) {
                    throw new Exception("Étudiant non trouvé");
                }
                
                // Récupérer toutes les notes de l'étudiant
                $stmtNotes = $pdo->prepare("
                    SELECT u.lib_UE, ec.lib_ECUE, ec.credit_ECUE, ev.note, ev.dte_eval
                    FROM evaluer ev
                    INNER JOIN ecue ec ON ev.fk_id_ECUE = ec.id_ECUE
                    INNER JOIN ue u ON ec.fk_id_UE = u.id_UE
                    WHERE ev.fk_num_etu = ? AND ev.fk_id_Ac = ?
                    ORDER BY u.lib_UE, ec.lib_ECUE
                ");
                $stmtNotes->execute([$numEtu, $anneeId]);
                $notes = $stmtNotes->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true, 
                    'etudiant' => $etudiant,
                    'notes' => $notes
                ]);
                break;
                
            default:
                throw new Exception("Action non reconnue");
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
    <title>SYGECOS - Gestion des Notes</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* === VARIABLES CSS === */
        :root {
            /* Couleurs Primaires */
            --primary-50: #f8fafc; --primary-100: #f1f5f9; --primary-200: #e2e8f0; --primary-300: #cbd5e1; --primary-400: #94a3b8; --primary-500: #64748b; --primary-600: #475569; --primary-700: #334155; --primary-800: #1e293b; --primary-900: #0f172a;
            /* Couleurs d'Accent Bleu */
            --accent-50: #eff6ff; --accent-100: #dbeafe; --accent-200: #bfdbfe; --accent-300: #93c5fd; --accent-400: #60a5fa; --accent-500: #3b82f6; --accent-600: #2563eb; --accent-700: #1d4ed8; --accent-800: #1e40af; --accent-900: #1e3a8a;
            /* Couleurs Secondaires */
            --secondary-50: #f0fdf4; --secondary-100: #dcfce7; --secondary-500: #22c55e; --secondary-600: #16a34a;
            /* Couleurs Sémantiques */
            --success-500: #22c55e; --warning-500: #f59e0b; --error-500: #ef4444; --info-500: #3b82f6;
            /* Couleurs Neutres */
            --white: #ffffff; --gray-50: #f9fafb; --gray-100: #f3f4f6; --gray-200: #e5e7eb; --gray-300: #d1d5db; --gray-400: #9ca3af; --gray-500: #6b7280; --gray-600: #4b5563; --gray-700: #374151; --gray-800: #1f2937; --gray-900: #111827;
            /* Layout */
            --sidebar-width: 280px; --sidebar-collapsed-width: 80px; --topbar-height: 70px;
            /* Typographie */
            --font-primary: 'Segoe UI', system-ui, -apple-system, sans-serif;
            --text-xs: 0.75rem; --text-sm: 0.875rem; --text-base: 1rem; --text-lg: 1.125rem; --text-xl: 1.25rem; --text-2xl: 1.5rem; --text-3xl: 1.875rem;
            /* Espacement */
            --space-1: 0.25rem; --space-2: 0.5rem; --space-3: 0.75rem; --space-4: 1rem; --space-5: 1.25rem; --space-6: 1.5rem; --space-8: 2rem; --space-10: 2.5rem; --space-12: 3rem; --space-16: 4rem;
            /* Bordures */
            --radius-sm: 0.25rem; --radius-md: 0.5rem; --radius-lg: 0.75rem; --radius-xl: 1rem; --radius-2xl: 1.5rem; --radius-3xl: 2rem;
            /* Ombres */
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.05); --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            /* Transitions */
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

        /* === TOPBAR === */
        .topbar { height: var(--topbar-height); background: var(--white); border-bottom: 1px solid var(--gray-200); padding: 0 var(--space-6); display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; }
        .topbar-left { display: flex; align-items: center; gap: var(--space-4); }
        .sidebar-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); } .sidebar-toggle:hover { background: var(--gray-200); color: var(--gray-800); }
        .page-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-800); }

        /* === PAGE SPECIFIC STYLES === */
        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--space-2); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); }

        .form-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-6); }
        .form-card-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-6); border-bottom: 1px solid var(--gray-200); padding-bottom: var(--space-4); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-6); }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: var(--text-sm); font-weight: 500; color: var(--gray-700); margin-bottom: var(--space-2); }
        .form-group input[type="text"], .form-group input[type="number"], .form-group input[type="date"], .form-group select, .form-group textarea { padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); font-size: var(--text-base); color: var(--gray-800); transition: all var(--transition-fast); }
        .form-group input[type="text"]:focus, .form-group input[type="number"]:focus, .form-group input[type="date"]:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--accent-500); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
        .form-group input:disabled, .form-group select:disabled { background-color: var(--gray-100); color: var(--gray-500); cursor: not-allowed; }

        .btn { padding: var(--space-3) var(--space-5); border-radius: var(--radius-md); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover:not(:disabled) { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover:not(:disabled) { background-color: var(--gray-300); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover:not(:disabled) { background-color: var(--success-600); }
        .btn-warning { background-color: var(--warning-500); color: white; } .btn-warning:hover:not(:disabled) { background-color: #e68a00; }
        .btn-info { background-color: var(--info-500); color: white; } .btn-info:hover:not(:disabled) { background-color: #316be6; }
        .btn-sm { padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }

        /* === TABLEAU DES ÉTUDIANTS === */
        .students-table { width: 100%; border-collapse: collapse; margin-top: var(--space-4); }
        .students-table th, .students-table td { padding: var(--space-3); border-bottom: 1px solid var(--gray-200); text-align: left; }
        .students-table th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); font-size: var(--text-sm); position: sticky; top: 0; }
        .students-table tbody tr:hover { background-color: var(--gray-50); }

        .note-input { width: 80px; padding: var(--space-2); border: 1px solid var(--gray-300); border-radius: var(--radius-sm); text-align: center; }
        .note-input:focus { border-color: var(--accent-500); box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); }

        .note-badge { padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm); font-size: var(--text-xs); font-weight: 600; text-align: center; }
        .note-badge.excellent { background: var(--secondary-100); color: var(--secondary-800); }
        .note-badge.bien { background: var(--accent-100); color: var(--accent-800); }
        .note-badge.passable { background: #fef3c7; color: #92400e; }
        .note-badge.insuffisant { background: #fecaca; color: #dc2626; }

        /* Section sélection matière */
        .matiere-selection { background: var(--accent-50); border: 2px solid var(--accent-200); border-radius: var(--radius-lg); padding: var(--space-4); margin-bottom: var(--space-4); }
        .matiere-selection h4 { color: var(--accent-800); margin-bottom: var(--space-3); font-size: var(--text-lg); }

        /* Messages d'alerte */
        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); display: none; }
        .alert.success { background-color: var(--secondary-50); color: var(--secondary-600); border: 1px solid var(--secondary-100); }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }

        /* Loading */
        .loading { opacity: 0.6; pointer-events: none; }
        .spinner { width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Modal pour bulletin */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: var(--white); margin: 2% auto; padding: 0; border-radius: var(--radius-xl); width: 90%; max-width: 900px; box-shadow: var(--shadow-xl); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: var(--space-6); border-bottom: 1px solid var(--gray-200); }
        .modal-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .close { color: var(--gray-400); font-size: 28px; font-weight: bold; cursor: pointer; transition: color var(--transition-fast); }
        .close:hover { color: var(--gray-600); }

        /* Bulletin étudiant format A4 */
        .bulletin { padding: var(--space-6); font-family: 'Times New Roman', serif; }
        .bulletin-header { text-align: center; margin-bottom: var(--space-8); border-bottom: 2px solid var(--gray-900); padding-bottom: var(--space-4); }
        .bulletin-title { font-size: var(--text-2xl); font-weight: bold; color: var(--gray-900); margin-bottom: var(--space-2); }
        .bulletin-subtitle { font-size: var(--text-lg); color: var(--gray-600); }
        .bulletin-student-info { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-6); margin-bottom: var(--space-8); }
        .bulletin-notes-table { width: 100%; border-collapse: collapse; margin-bottom: var(--space-6); }
        .bulletin-notes-table th, .bulletin-notes-table td { padding: var(--space-3); border: 1px solid var(--gray-900); text-align: left; }
        .bulletin-notes-table th { background-color: var(--gray-100); font-weight: bold; }
        .bulletin-summary { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-6); margin-top: var(--space-6); }

        .info-section { background: var(--gray-50); padding: var(--space-4); border-radius: var(--radius-md); }
        .info-section h4 { font-size: var(--text-lg); margin-bottom: var(--space-3); color: var(--gray-900); }
        .info-item { display: flex; justify-content: space-between; margin-bottom: var(--space-2); }
        .info-label { font-weight: 600; }

        /* Pagination styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: var(--space-2);
            margin-top: var(--space-4);
        }
        .pagination button {
            background-color: var(--gray-100);
            border: 1px solid var(--gray-300);
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        .pagination button:hover:not(:disabled) {
            background-color: var(--gray-200);
        }
        .pagination button.active {
            background-color: var(--accent-600);
            color: white;
            border-color: var(--accent-600);
        }
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }


        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .main-content.sidebar-collapsed { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
            .bulletin-student-info { grid-template-columns: 1fr; }
            .bulletin-summary { grid-template-columns: 1fr; }
        }

        /* Print styles pour le bulletin */
        @media print {
            .modal-header, .btn, .pagination { display: none !important; } /* Hide print and pagination controls */
            .modal-content { width: 100% !important; max-width: none !important; margin: 0 !important; box-shadow: none !important; }
            .bulletin { padding: 1cm !important; }
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
                    <h1 class="page-title-main">Gestion des Notes</h1>
                    <p class="page-subtitle">Sélectionnez une filière, un niveau puis choisissez la matière à noter</p>
                </div>

                <div id="alertMessage" class="alert"></div>

                <div class="form-card">
                    <h3 class="form-card-title">Sélection du contexte académique</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="annee_id">Année académique</label>
                            <input type="text" id="annee_display" value="<?php echo htmlspecialchars($anneeActive['annee_libelle'] ?? 'Non définie'); ?>" disabled>
                            <input type="hidden" id="annee_id" value="<?php echo $anneeActive['id_Ac'] ?? ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="filiere_id">Filière <span style="color: var(--error-500);">*</span></label>
                            <select id="filiere_id" name="filiere_id" required>
                                <option value="">Sélectionner une filière</option>
                                <?php foreach ($filieres as $filiere): ?>
                                    <option value="<?php echo $filiere['id_filiere']; ?>">
                                        <?php echo htmlspecialchars($filiere['lib_filiere']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="niveau_id">Niveau d'étude <span style="color: var(--error-500);">*</span></label>
                            <select id="niveau_id" name="niveau_id" required disabled>
                                <option value="">Sélectionner un niveau</option>
                                </select>
                        </div>
                    </div>
                </div>

                <div class="form-card matiere-selection" id="matiereSelectionCard" style="display: none;">
                    <h3 class="form-card-title"><i class="fas fa-book"></i> Sélection de la matière à noter</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="ue_id">Unité d'Enseignement (UE) <span style="color: var(--error-500);">*</span></label>
                            <select id="ue_id" name="ue_id" required>
                                <option value="">Sélectionner une UE</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="ecue_id">Élément Constitutif d'UE (ECUE) <span style="color: var(--error-500);">*</span></label>
                            <select id="ecue_id" name="ecue_id" required disabled>
                                <option value="">Sélectionner une ECUE</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-card" id="studentsCard" style="display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6);">
                        <h3 id="studentsTitle">Liste des étudiants</h3>
                        <div>
                            <span id="studentsCount" style="color: var(--gray-600); font-size: var(--text-sm);">0 étudiant(s)</span>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="students-table" id="studentsTable">
                            <thead>
                                <tr>
                                    <th>Matricule</th>
                                    <th>Nom</th>
                                    <th>Prénom</th>
                                    <th>Date naissance</th>
                                    <th>Filière</th>
                                    <th>Note (/20)</th>
                                    <th>Date évaluation</th>
                                    <th>Appréciation</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                </tbody>
                        </table>
                    </div>

                    <div id="paginationControls" class="pagination" style="display: none;">
                        <button id="prevPage" class="btn btn-secondary">Précédent</button>
                        <div id="pageNumbers" style="display: flex; gap: 5px;"></div>
                        <button id="nextPage" class="btn btn-secondary">Suivant</button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="bulletinModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Bulletin de l'étudiant</h3>
                <div>
                    <button class="btn btn-primary btn-sm" onclick="imprimerBulletin()">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                    <span class="close" onclick="closeModal('bulletinModal')">&times;</span>
                </div>
            </div>
            <div id="bulletinContent" class="bulletin">
                </div>
        </div>
    </div>

    <script>
        // Variables globales
        let allEtudiants = []; // Stores all students for the selected level
        let currentEcueId = 0;
        let currentAnneeId = 0;
        let currentNiveauId = 0;
        let currentFiliereId = 0;

        const studentsPerPage = 30; // Max students per page
        let currentPage = 1; // Current page number

        // DOM Elements
        const studentsCard = document.getElementById('studentsCard');
        const matiereSelectionCard = document.getElementById('matiereSelectionCard');
        const studentsTableBody = document.querySelector('#studentsTable tbody');
        const studentsCountSpan = document.getElementById('studentsCount');
        const studentsTitle = document.getElementById('studentsTitle');
        const paginationControls = document.getElementById('paginationControls');
        const prevPageBtn = document.getElementById('prevPage');
        const nextPageBtn = document.getElementById('nextPage');
        const pageNumbersDiv = document.getElementById('pageNumbers');


        // Fonctions utilitaires
        function showAlert(message, type = 'info') {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.textContent = message;
            alertDiv.className = `alert ${type}`;
            alertDiv.style.display = 'block';
            
            setTimeout(() => {
                alertDiv.style.display = 'none';
            }, 5000);
        }

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

        // --- Event Listeners ---

        document.getElementById('filiere_id').addEventListener('change', async function() {
            const filiereId = this.value;
            const niveauSelect = document.getElementById('niveau_id');
            
            niveauSelect.innerHTML = '<option value="">Sélectionner un niveau</option>';
            niveauSelect.disabled = true;
            studentsCard.style.display = 'none'; // Hide student card
            matiereSelectionCard.style.display = 'none'; // Hide UE/ECUE card
            viderColonnesNotes(); // Clear notes columns
            allEtudiants = []; // Clear student data
            updatePaginationControls(); // Hide pagination

            if (filiereId) {
                currentFiliereId = filiereId;
                try {
                    const result = await makeAjaxRequest({
                        action: 'charger_niveaux_par_filiere',
                        filiere_id: filiereId
                    });
                    
                    if (result.success) {
                        result.data.forEach(niveau => {
                            const option = document.createElement('option');
                            option.value = niveau.id_niv_etu;
                            option.textContent = htmlspecialchars(niveau.lib_niv_etu);
                            niveauSelect.appendChild(option);
                        });
                        niveauSelect.disabled = false;
                    } else {
                        showAlert(result.message, 'error');
                    }
                } catch (error) {
                    showAlert('Erreur lors du chargement des niveaux d\'étude', 'error');
                }
            } else {
                currentFiliereId = 0;
                currentNiveauId = 0;
                currentEcueId = 0; // Reset ECUE as well
            }
        });

        document.getElementById('niveau_id').addEventListener('change', async function() {
            const niveauId = this.value;
            const anneeId = document.getElementById('annee_id').value;
            
            // Reset UE/ECUE selections and notes display when niveau changes
            document.getElementById('ue_id').innerHTML = '<option value="">Sélectionner une UE</option>';
            document.getElementById('ecue_id').innerHTML = '<option value="">Sélectionner une ECUE</option>';
            document.getElementById('ecue_id').disabled = true;
            viderColonnesNotes();
            allEtudiants = []; // Clear student data
            studentsCard.style.display = 'none'; // Hide student card
            matiereSelectionCard.style.display = 'none'; // Hide UE/ECUE card
            updatePaginationControls(); // Hide pagination


            if (niveauId && anneeId) {
                currentNiveauId = niveauId;
                currentAnneeId = anneeId;
                
                await chargerEtudiants(anneeId, niveauId); // This now populates allEtudiants
                await chargerUE(anneeId); // UE loading still depends on active year
                matiereSelectionCard.style.display = 'block'; // Show UE/ECUE card
                studentsCard.style.display = 'block'; // Show students card
            } else {
                currentNiveauId = 0;
                currentEcueId = 0; // Reset ECUE as well
            }
        });

        document.getElementById('ue_id').addEventListener('change', async function() {
            const ueId = this.value;
            const ecueSelect = document.getElementById('ecue_id');
            
            ecueSelect.innerHTML = '<option value="">Sélectionner une ECUE</option>';
            ecueSelect.disabled = true;
            
            viderColonnesNotes(); // Reinitialize notes in the table
            
            if (ueId) {
                try {
                    const result = await makeAjaxRequest({
                        action: 'charger_ecue',
                        ue_id: ueId
                    });
                    
                    if (result.success) {
                        result.data.forEach(ecue => {
                            const option = document.createElement('option');
                            option.value = ecue.id_ECUE;
                            option.textContent = `${htmlspecialchars(ecue.lib_ECUE)} (${ecue.credit_ECUE} crédits)`;
                            ecueSelect.appendChild(option);
                        });
                        ecueSelect.disabled = false;
                    }
                } catch (error) {
                    showAlert('Erreur lors du chargement des ECUE', 'error');
                }
            } else {
                currentEcueId = 0; // Reset ECUE
            }
        });

        document.getElementById('ecue_id').addEventListener('change', async function() {
            const ecueId = this.value;
            
            if (ecueId) {
                currentEcueId = ecueId;
                // We need to re-render the *current page* of students with their notes
                await chargerNotesEcue(currentAnneeId, currentNiveauId, ecueId);
            } else {
                viderColonnesNotes();
            }
        });

        prevPageBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                displayStudentsPage();
                // If an ECUE is selected, reload notes for the new page
                if (currentEcueId > 0) {
                    chargerNotesEcue(currentAnneeId, currentNiveauId, currentEcueId);
                }
            }
        });

        nextPageBtn.addEventListener('click', () => {
            const totalPages = Math.ceil(allEtudiants.length / studentsPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                displayStudentsPage();
                // If an ECUE is selected, reload notes for the new page
                if (currentEcueId > 0) {
                    chargerNotesEcue(currentAnneeId, currentNiveauId, currentEcueId);
                }
            }
        });


        // --- Core Functions ---

        async function chargerEtudiants(anneeId, niveauId) {
            try {
                const result = await makeAjaxRequest({
                    action: 'charger_etudiants_niveau',
                    annee_id: anneeId,
                    niveau_id: niveauId
                });
                
                if (result.success) {
                    allEtudiants = result.data; // Store all students
                    currentPage = 1; // Reset to first page
                    displayStudentsPage(); // Display the first page
                    updatePaginationControls();
                } else {
                    showAlert(result.message, 'error');
                    allEtudiants = []; // Clear data on error
                    studentsTableBody.innerHTML = ''; // Clear table
                    studentsCountSpan.textContent = '0 étudiant(s)';
                    updatePaginationControls();
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des étudiants', 'error');
                allEtudiants = []; // Clear data on error
                studentsTableBody.innerHTML = ''; // Clear table
                studentsCountSpan.textContent = '0 étudiant(s)';
                updatePaginationControls();
            }
        }

        async function chargerUE(anneeId) {
            try {
                const result = await makeAjaxRequest({
                    action: 'charger_ue',
                    annee_id: anneeId
                });
                
                if (result.success) {
                    const ueSelect = document.getElementById('ue_id');
                    ueSelect.innerHTML = '<option value="">Sélectionner une UE</option>';
                    
                    result.data.forEach(ue => {
                        const option = document.createElement('option');
                        option.value = ue.id_UE;
                        option.textContent = `${htmlspecialchars(ue.lib_UE)} (${ue.credit_UE} crédits)`;
                        ueSelect.appendChild(option);
                    });
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des UE', 'error');
            }
        }

        async function chargerNotesEcue(anneeId, niveauId, ecueId) {
            try {
                const result = await makeAjaxRequest({
                    action: 'charger_notes_ecue',
                    annee_id: anneeId,
                    niveau_id: niveauId,
                    ecue_id: ecueId
                });
                
                if (result.success) {
                    // Update notes only for the currently displayed students
                    remplirColonnesNotes(result.data);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des notes', 'error');
            }
        }

        // --- Pagination & Display Functions ---

        function displayStudentsPage() {
            const startIndex = (currentPage - 1) * studentsPerPage;
            const endIndex = startIndex + studentsPerPage;
            const studentsToDisplay = allEtudiants.slice(startIndex, endIndex);

            studentsTableBody.innerHTML = ''; // Clear current table content

            const filiereText = document.getElementById('filiere_id').selectedOptions[0]?.textContent || '';
            const niveauText = document.getElementById('niveau_id').selectedOptions[0]?.textContent || '';
            studentsTitle.textContent = `Étudiants de la filière ${filiereText}, niveau ${niveauText}`;
            studentsCountSpan.textContent = `${allEtudiants.length} étudiant(s) (Page ${currentPage} sur ${Math.ceil(allEtudiants.length / studentsPerPage)})`;

            if (studentsToDisplay.length === 0) {
                studentsTableBody.innerHTML = '<tr><td colspan="9" style="text-align: center;">Aucun étudiant trouvé pour ce niveau.</td></tr>';
            } else {
                studentsToDisplay.forEach(etudiant => {
                    const row = document.createElement('tr');
                    
                    row.innerHTML = `
                        <td><strong>${htmlspecialchars(etudiant.num_etu)}</strong></td>
                        <td>${htmlspecialchars(etudiant.nom_etu)}</td>
                        <td>${htmlspecialchars(etudiant.prenoms_etu)}</td>
                        <td>${etudiant.dte_naiss_etu ? new Date(etudiant.dte_naiss_etu).toLocaleDateString('fr-FR') : '-'}</td>
                        <td>${htmlspecialchars(etudiant.lib_filiere || '-')}</td>
                        <td class="note-cell">
                            <span class="note-placeholder">Choisir une matière</span>
                        </td>
                        <td class="date-cell">
                            <span class="date-placeholder">-</span>
                        </td>
                        <td class="appreciation-cell">
                            <span class="note-badge">-</span>
                        </td>
                        <td>
                            <button class="btn btn-success btn-sm save-note-btn" onclick="sauvegarderNote('${etudiant.num_etu}', 0)" title="Sauvegarder" style="display: none;">
                                <i class="fas fa-save"></i>
                            </button>
                            <button class="btn btn-info btn-sm" onclick="voirBulletin('${etudiant.num_etu}')" title="Voir bulletin">
                                <i class="fas fa-file-alt"></i>
                            </button>
                        </td>
                    `;
                    studentsTableBody.appendChild(row);
                });
            }

            // After displaying students, if an ECUE is already selected, re-fetch notes for this page
            if (currentEcueId > 0) {
                chargerNotesEcue(currentAnneeId, currentNiveauId, currentEcueId);
            }
        }

        function updatePaginationControls() {
            const totalPages = Math.ceil(allEtudiants.length / studentsPerPage);

            if (allEtudiants.length === 0 || totalPages <= 1) {
                paginationControls.style.display = 'none';
                return;
            }

            paginationControls.style.display = 'flex';
            prevPageBtn.disabled = (currentPage === 1);
            nextPageBtn.disabled = (currentPage === totalPages);

            pageNumbersDiv.innerHTML = '';
            for (let i = 1; i <= totalPages; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.textContent = i;
                pageBtn.classList.add('btn', 'btn-secondary', 'btn-sm');
                if (i === currentPage) {
                    pageBtn.classList.add('active');
                }
                pageBtn.addEventListener('click', () => {
                    currentPage = i;
                    displayStudentsPage();
                    if (currentEcueId > 0) {
                        chargerNotesEcue(currentAnneeId, currentNiveauId, currentEcueId);
                    }
                    updatePaginationControls();
                });
                pageNumbersDiv.appendChild(pageBtn);
            }
        }

        function remplirColonnesNotes(notesData) {
            const rows = document.querySelectorAll('#studentsTable tbody tr');
            
            rows.forEach(row => {
                const matricule = row.cells[0].textContent.trim();
                const noteData = notesData[matricule] || {};
                
                const note = noteData.note || '';
                const dateEval = noteData.dte_eval || '';
                const idEval = noteData.id_eval || 0;
                const appreciation = getAppreciation(noteData.note);
                
                // Colonne note
                const noteCell = row.querySelector('.note-cell');
                noteCell.innerHTML = `
                    <input type="number" 
                           class="note-input" 
                           value="${note}" 
                           min="0" 
                           max="20" 
                           step="0.25"
                           data-student="${matricule}"
                           data-eval-id="${idEval}"
                           placeholder="0.00">
                `;
                
                // Colonne date
                const dateCell = row.querySelector('.date-cell');
                dateCell.innerHTML = `
                    <input type="date" 
                           value="${dateEval}" 
                           data-student="${matricule}"
                           style="padding: var(--space-2); border: 1px solid var(--gray-300); border-radius: var(--radius-sm);">
                `;
                
                // Colonne appréciation
                const appreciationCell = row.querySelector('.appreciation-cell');
                appreciationCell.innerHTML = `<span class="note-badge ${appreciation.class}">${appreciation.text}</span>`;
                
                // Bouton sauvegarder
                const saveBtn = row.querySelector('.save-note-btn');
                saveBtn.style.display = 'inline-flex';
                saveBtn.setAttribute('onclick', `sauvegarderNote('${matricule}', ${idEval})`);
            });
        }

        function viderColonnesNotes() {
            const rows = document.querySelectorAll('#studentsTable tbody tr');
            
            rows.forEach(row => {
                const noteCell = row.querySelector('.note-cell');
                const dateCell = row.querySelector('.date-cell');
                const appreciationCell = row.querySelector('.appreciation-cell');
                const saveBtn = row.querySelector('.save-note-btn');
                
                noteCell.innerHTML = '<span class="note-placeholder">Choisir une matière</span>';
                dateCell.innerHTML = '<span class="date-placeholder">-</span>';
                appreciationCell.innerHTML = '<span class="note-badge">-</span>';
                saveBtn.style.display = 'none';
            });
        }

        function getAppreciation(note) {
            if (note === null || note === '') return { text: '-', class: '' }; // Handle null or empty notes
            
            const numericNote = parseFloat(note);
            if (isNaN(numericNote)) return { text: '-', class: '' };

            if (numericNote >= 16) return { text: 'Excellent', class: 'excellent' };
            if (numericNote >= 14) return { text: 'Bien', class: 'bien' };
            if (numericNote >= 10) return { text: 'Passable', class: 'passable' };
            return { text: 'Insuffisant', class: 'insuffisant' };
        }

        async function sauvegarderNote(numEtu, idEval) {
            const noteInput = document.querySelector(`input[data-student="${numEtu}"].note-input`);
            const dateInput = document.querySelector(`input[data-student="${numEtu}"][type="date"]`);
            
            const note = parseFloat(noteInput.value);
            const dateEval = dateInput.value;
            
            if (isNaN(note) || note < 0 || note > 20) {
                showAlert('La note doit être comprise entre 0 et 20', 'error');
                return;
            }
            
            if (!dateEval) {
                showAlert('Veuillez sélectionner une date d\'évaluation', 'error');
                return;
            }
            
            if (!currentEcueId) {
                showAlert('Veuillez sélectionner une ECUE', 'error');
                return;
            }
            
            try {
                const result = await makeAjaxRequest({
                    action: 'sauvegarder_note',
                    num_etu: numEtu,
                    ecue_id: currentEcueId,
                    note: note,
                    date_eval: dateEval,
                    annee_id: currentAnneeId,
                    id_eval: idEval
                });
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    // Recharger les notes pour cette ECUE pour mettre à jour l'ID d'évaluation si c'est une création
                    await chargerNotesEcue(currentAnneeId, currentNiveauId, currentEcueId);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la sauvegarde', 'error');
            }
        }

        async function voirBulletin(numEtu) {
            try {
                const result = await makeAjaxRequest({
                    action: 'obtenir_bulletin',
                    num_etu: numEtu,
                    annee_id: currentAnneeId
                });
                
                if (result.success) {
                    afficherBulletin(result.etudiant, result.notes);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement du bulletin', 'error');
            }
        }

        function afficherBulletin(etudiant, notes) {
            const content = document.getElementById('bulletinContent');
            
            let totalNotes = 0;
            let totalCredits = 0;
            let notesCount = 0;
            
            notes.forEach(note => {
                if (note.note !== null && note.note !== '') { 
                    totalNotes += parseFloat(note.note) * parseFloat(note.credit_ECUE);
                    totalCredits += parseFloat(note.credit_ECUE);
                    notesCount++;
                }
            });
            
            const moyenne = totalCredits > 0 ? (totalNotes / totalCredits).toFixed(2) : '0.00';
            
            content.innerHTML = `
                <div class="bulletin-header">
                    <h1 class="bulletin-title">BULLETIN DE NOTES</h1>
                    <p class="bulletin-subtitle">Année académique ${htmlspecialchars(etudiant.annee_libelle)}</p>
                </div>
                
                <div class="bulletin-student-info">
                    <div class="info-section">
                        <h4>Informations étudiant</h4>
                        <div class="info-item">
                            <span class="info-label">Matricule :</span>
                            <span>${htmlspecialchars(etudiant.num_etu)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Nom :</span>
                            <span>${htmlspecialchars(etudiant.nom_etu)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Prénom(s) :</span>
                            <span>${htmlspecialchars(etudiant.prenoms_etu)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Date naissance :</span>
                            <span>${etudiant.dte_naiss_etu ? new Date(etudiant.dte_naiss_etu).toLocaleDateString('fr-FR') : '-'}</span>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h4>Informations académiques</h4>
                        <div class="info-item">
                            <span class="info-label">Niveau :</span>
                            <span>${htmlspecialchars(etudiant.lib_niv_etu)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Filière :</span>
                            <span>${htmlspecialchars(etudiant.lib_filiere || '-')}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Email :</span>
                            <span>${htmlspecialchars(etudiant.email_etu)}</span>
                        </div>
                    </div>
                </div>
                
                <table class="bulletin-notes-table">
                    <thead>
                        <tr>
                            <th>Unité d'Enseignement</th>
                            <th>ECUE</th>
                            <th>Crédits</th>
                            <th>Note (/20)</th>
                            <th>Date évaluation</th>
                            <th>Appréciation</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${notes.map(note => {
                            const appreciation = getAppreciation(note.note);
                            return `
                                <tr>
                                    <td><strong>${htmlspecialchars(note.lib_UE)}</strong></td>
                                    <td>${htmlspecialchars(note.lib_ECUE)}</td>
                                    <td>${htmlspecialchars(note.credit_ECUE)}</td>
                                    <td>${note.note !== null ? parseFloat(note.note).toFixed(2) : '-'}</td>
                                    <td>${note.dte_eval ? new Date(note.dte_eval).toLocaleDateString('fr-FR') : '-'}</td>
                                    <td><span class="note-badge ${appreciation.class}">${appreciation.text}</span></td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
                
                <div class="bulletin-summary">
                    <div class="info-section">
                        <h4>Résumé</h4>
                        <div class="info-item">
                            <span class="info-label">Nombre d'ECUE :</span>
                            <span>${notes.length}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ECUE notées :</span>
                            <span>${notesCount}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Crédits obtenus :</span>
                            <span>${totalCredits}</span>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h4>Moyenne générale</h4>
                        <div style="text-align: center; padding: var(--space-4);">
                            <div style="font-size: var(--text-3xl); font-weight: bold; color: var(--accent-600);">
                                ${moyenne}/20
                            </div>
                            <div style="margin-top: var(--space-2);">
                                <span class="note-badge ${getAppreciation(parseFloat(moyenne)).class}">
                                    ${getAppreciation(parseFloat(moyenne)).text}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: var(--space-8); text-align: right; font-size: var(--text-sm); color: var(--gray-600);">
                    <p>Document généré le ${new Date().toLocaleDateString('fr-FR')}</p>
                </div>
            `;
            
            document.getElementById('bulletinModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function imprimerBulletin() {
            window.print();
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // HTML Escape Function for Security
        function htmlspecialchars(str) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return str.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            const anneeId = document.getElementById('annee_id').value;
            if (!anneeId) {
                showAlert('Aucune année académique active trouvée. Veuillez configurer une année active.', 'warning');
            }
        });

        // Gestion responsive de la sidebar
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
            });
        }
    </script>
</body>
</html>