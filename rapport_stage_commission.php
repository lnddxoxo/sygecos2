<?php
// rapports_stage.php
require_once 'config.php';

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

// Récupération des enseignants (pour directeurs de stage)
$enseignants = [];
try {
    $stmt = $pdo->query("SELECT id_ens, nom_ens, prenom_ens FROM enseignant ORDER BY nom_ens, prenom_ens");
    $enseignants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération enseignants: " . $e->getMessage());
}

// === TRAITEMENT AJAX ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'charger_rapports':
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $statutFiltre = $_POST['statut_filtre'] ?? 'tous';
                $searchTerm = trim($_POST['search_term'] ?? '');
                
                if ($anneeId <= 0) {
                    throw new Exception("Année académique non spécifiée");
                }
                
                // Construction de la requête
                $whereConditions = ["d.fk_id_Ac = ?"];
                $params = [$anneeId];
                
                if (!empty($searchTerm)) {
                    $whereConditions[] = "(e.num_etu LIKE ? OR e.nom_etu LIKE ? OR e.prenoms_etu LIKE ? OR r.nom_rapport LIKE ?)";
                    $searchParam = "%{$searchTerm}%";
                    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
                }
                
                $whereClause = implode(' AND ', $whereConditions);
                
                $sql = "
                    SELECT 
                        r.id_rapport,
                        r.nom_rapport,
                        r.dte_rapport,
                        r.theme_rapport,
                        d.dte_rapport as date_depot,
                        d.com_appr as commentaire_depot,
                        e.num_etu,
                        e.nom_etu,
                        e.prenoms_etu,
                        e.email_etu,
                        ne.lib_niv_etu,
                        f.lib_filiere,
                        
                        -- Directeur de stage (depuis affecter)
                        ens.nom_ens as directeur_nom,
                        ens.prenom_ens as directeur_prenom,
                        aff.directeur_mem,
                        
                        -- Validation (depuis valider)
                        v.dte_val as date_validation,
                        v.com_val as commentaire_validation,
                        ens_val.nom_ens as validateur_nom,
                        ens_val.prenom_ens as validateur_prenom,
                        
                        -- Statut calculé
                        CASE 
                            WHEN v.dte_val IS NOT NULL THEN 'VALIDE'
                            WHEN d.dte_rapport IS NOT NULL AND v.dte_val IS NULL THEN 'EN_ATTENTE'
                            WHEN d.dte_rapport IS NULL THEN 'NON_DEPOSE'
                            ELSE 'INCONNU'
                        END as statut_rapport,
                        
                        -- Analyse automatique (simulation)
                        CASE 
                            WHEN r.nom_rapport IS NOT NULL THEN 
                                CASE 
                                    WHEN LENGTH(r.theme_rapport) > 20 AND r.theme_rapport IS NOT NULL THEN 'CONFORME'
                                    WHEN LENGTH(r.theme_rapport) BETWEEN 10 AND 20 THEN 'A_VERIFIER'
                                    ELSE 'NON_CONFORME'
                                END
                            ELSE 'NON_ANALYSE'
                        END as analyse_auto,
                        
                        -- Temps depuis dépôt
                        CASE 
                            WHEN d.dte_rapport IS NOT NULL THEN DATEDIFF(NOW(), d.dte_rapport)
                            ELSE NULL
                        END as jours_depuis_depot
                        
                    FROM deposer d
                    INNER JOIN etudiant e ON d.fk_num_etu = e.num_etu
                    LEFT JOIN rapport_etudiant r ON d.fk_id_rapport = r.id_rapport
                    LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                    LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                    LEFT JOIN affecter aff ON aff.fk_id_rapport = r.id_rapport AND aff.directeur_mem = 'O'
                    LEFT JOIN enseignant ens ON aff.fk_id_ens = ens.id_ens
                    LEFT JOIN valider v ON v.fk_id_rapport = r.id_rapport
                    LEFT JOIN enseignant ens_val ON v.fk_id_ens = ens_val.id_ens
                    WHERE {$whereClause}
                ";
                
                // Filtre par statut
                if ($statutFiltre !== 'tous') {
                    $sql .= " HAVING statut_rapport = ?";
                    $params[] = strtoupper($statutFiltre);
                }
                
                $sql .= " ORDER BY d.dte_rapport DESC, e.nom_etu, e.prenoms_etu";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $resultats]);
                break;
                
            case 'analyser_rapport':
                $idRapport = intval($_POST['id_rapport'] ?? 0);
                
                if ($idRapport <= 0) {
                    throw new Exception("ID rapport non spécifié");
                }
                
                // Simulation d'analyse automatique avancée
                $stmt = $pdo->prepare("
                    SELECT r.*, e.nom_etu, e.prenoms_etu 
                    FROM rapport_etudiant r 
                    INNER JOIN deposer d ON r.id_rapport = d.fk_id_rapport
                    INNER JOIN etudiant e ON d.fk_num_etu = e.num_etu
                    WHERE r.id_rapport = ?
                ");
                $stmt->execute([$idRapport]);
                $rapport = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$rapport) {
                    throw new Exception("Rapport non trouvé");
                }
                
                // Critères d'analyse (simulation)
                $analyse = [
                    'titre_valide' => !empty($rapport['nom_rapport']) && strlen($rapport['nom_rapport']) >= 10,
                    'theme_detaille' => !empty($rapport['theme_rapport']) && strlen($rapport['theme_rapport']) >= 20,
                    'date_coherente' => $rapport['dte_rapport'] && strtotime($rapport['dte_rapport']) <= time(),
                    'format_respecte' => true, // Simulation
                    'contenu_suffisant' => strlen($rapport['theme_rapport'] ?? '') > 50,
                ];
                
                // Score global
                $score = array_sum($analyse) / count($analyse) * 100;
                
                // Recommandation
                $recommandation = 'ACCEPTER';
                if ($score < 60) {
                    $recommandation = 'REJETER';
                } elseif ($score < 80) {
                    $recommandation = 'A_CORRIGER';
                }
                
                $analyseDatilee = [
                    'score_global' => round($score, 1),
                    'recommandation' => $recommandation,
                    'criteres' => $analyse,
                    'points_forts' => [],
                    'points_amelioration' => []
                ];
                
                // Points forts et améliorations
                if ($analyse['titre_valide']) {
                    $analyseDatilee['points_forts'][] = "Titre bien formulé";
                } else {
                    $analyseDatilee['points_amelioration'][] = "Titre trop court ou manquant";
                }
                
                if ($analyse['theme_detaille']) {
                    $analyseDatilee['points_forts'][] = "Thème suffisamment détaillé";
                } else {
                    $analyseDatilee['points_amelioration'][] = "Thème insuffisamment développé";
                }
                
                if (!$analyse['contenu_suffisant']) {
                    $analyseDatilee['points_amelioration'][] = "Contenu trop succinct";
                }
                
                echo json_encode(['success' => true, 'analyse' => $analyseDatilee]);
                break;
                
            case 'valider_rapport':
                $idRapport = intval($_POST['id_rapport'] ?? 0);
                $commentaire = trim($_POST['commentaire'] ?? '');
                $decision = $_POST['decision'] ?? 'ACCEPTER';
                $idEnseignant = intval($_POST['id_enseignant'] ?? 1); // À adapter selon l'auth
                
                if ($idRapport <= 0) {
                    throw new Exception("ID rapport non spécifié");
                }
                
                $pdo->beginTransaction();
                
                try {
                    // Vérifier si une validation existe déjà
                    $checkStmt = $pdo->prepare("SELECT id_validation FROM valider WHERE fk_id_rapport = ?");
                    $checkStmt->execute([$idRapport]);
                    
                    if ($checkStmt->fetch()) {
                        // Mettre à jour
                        $stmt = $pdo->prepare("
                            UPDATE valider 
                            SET dte_val = NOW(), com_val = ?, fk_id_ens = ?
                            WHERE fk_id_rapport = ?
                        ");
                        $stmt->execute([$commentaire, $idEnseignant, $idRapport]);
                    } else {
                        // Créer une nouvelle validation
                        $stmt = $pdo->prepare("
                            INSERT INTO valider (fk_id_ens, fk_id_rapport, dte_val, com_val)
                            VALUES (?, ?, NOW(), ?)
                        ");
                        $stmt->execute([$idEnseignant, $idRapport, $commentaire]);
                    }
                    
                    $pdo->commit();
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => "Rapport {$decision} avec succès"
                    ]);
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;
                
            case 'affecter_directeur':
                $idRapport = intval($_POST['id_rapport'] ?? 0);
                $idEnseignant = intval($_POST['id_enseignant'] ?? 0);
                
                if ($idRapport <= 0 || $idEnseignant <= 0) {
                    throw new Exception("Paramètres manquants");
                }
                
                $pdo->beginTransaction();
                
                try {
                    // Supprimer ancienne affectation
                    $deleteStmt = $pdo->prepare("DELETE FROM affecter WHERE fk_id_rapport = ? AND directeur_mem = 'O'");
                    $deleteStmt->execute([$idRapport]);
                    
                    // Nouvelle affectation
                    $insertStmt = $pdo->prepare("
                        INSERT INTO affecter (fk_id_ens, fk_id_rapport, directeur_mem)
                        VALUES (?, ?, 'O')
                    ");
                    $insertStmt->execute([$idEnseignant, $idRapport]);
                    
                    $pdo->commit();
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => "Directeur de stage affecté avec succès"
                    ]);
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;
                
            case 'telecharger_rapport':
                $idRapport = intval($_POST['id_rapport'] ?? 0);
                
                // Simulation du téléchargement
                $stmt = $pdo->prepare("SELECT nom_rapport FROM rapport_etudiant WHERE id_rapport = ?");
                $stmt->execute([$idRapport]);
                $rapport = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$rapport) {
                    throw new Exception("Rapport non trouvé");
                }
                
                $filename = sanitize_filename($rapport['nom_rapport']) . '_' . $idRapport . '.pdf';
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Téléchargement préparé',
                    'filename' => $filename,
                    'download_url' => "rapports/{$filename}"
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

function sanitize_filename($filename) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Gestion des Rapports de Stage</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Styles de base identiques à l'exemple des membres de commission */
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

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--font-primary); background-color: var(--gray-50); color: var(--gray-800); overflow-x: hidden; }
        .admin-layout { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-width); transition: margin-left var(--transition-normal); }
        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed-width); }

        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-width); height: 100vh; background: linear-gradient(180deg, var(--primary-800) 0%, var(--primary-900) 100%); color: white; z-index: 1000; transition: all var(--transition-normal); overflow-y: auto; overflow-x: hidden; }
        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .topbar { height: var(--topbar-height); background: var(--white); border-bottom: 1px solid var(--gray-200); padding: 0 var(--space-6); display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; }

        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); display: flex; justify-content: space-between; align-items: center; }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--space-2); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); }

        .form-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-6); }
        .form-card-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-6); border-bottom: 1px solid var(--gray-200); padding-bottom: var(--space-4); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-6); }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: var(--text-sm); font-weight: 500; color: var(--gray-700); margin-bottom: var(--space-2); }
        .form-group input, .form-group select, .form-group textarea { padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); font-size: var(--text-base); color: var(--gray-800); transition: all var(--transition-fast); }
        .form-group input:disabled { background-color: var(--gray-100); color: var(--gray-500); cursor: not-allowed; }

        .btn { padding: var(--space-3) var(--space-5); border-radius: var(--radius-md); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover { background-color: var(--gray-300); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover { background-color: var(--success-600); }
        .btn-warning { background-color: var(--warning-500); color: white; }
        .btn-info { background-color: var(--info-500); color: white; }
        .btn-danger { background-color: var(--error-500); color: white; }
        .btn-sm { padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }
        .btn-outline { background: transparent; border: 1px solid var(--gray-300); color: var(--gray-700); }
        .btn-outline:hover { background: var(--gray-100); }

        /* === DASHBOARD RAPPORTS === */
        .dashboard-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-8); }
        .dashboard-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); text-align: center; position: relative; }
        .dashboard-card.deposés { border-left: 4px solid var(--info-500); }
        .dashboard-card.en-attente { border-left: 4px solid var(--warning-500); }
        .dashboard-card.validés { border-left: 4px solid var(--success-500); }
        .dashboard-card.rejetés { border-left: 4px solid var(--error-500); }

        .dashboard-icon { font-size: var(--text-2xl); margin-bottom: var(--space-3); }
        .dashboard-value { font-size: var(--text-3xl); font-weight: 700; margin: var(--space-2) 0; }
        .dashboard-label { color: var(--gray-600); font-size: var(--text-base); }

        .deposés .dashboard-icon, .deposés .dashboard-value { color: var(--info-500); }
        .en-attente .dashboard-icon, .en-attente .dashboard-value { color: var(--warning-500); }
        .validés .dashboard-icon, .validés .dashboard-value { color: var(--success-500); }
        .rejetés .dashboard-icon, .rejetés .dashboard-value { color: var(--error-500); }

        /* === TABLEAU RAPPORTS === */
        .reports-table { width: 100%; border-collapse: collapse; }
        .reports-table th, .reports-table td { padding: var(--space-3); border-bottom: 1px solid var(--gray-200); text-align: left; }
        .reports-table th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); font-size: var(--text-sm); position: sticky; top: 0; }
        .reports-table tbody tr:hover { background-color: var(--gray-50); }

        .status-badge { padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; text-align: center; text-transform: uppercase; }
        .status-badge.en_attente { background: #fef3c7; color: #92400e; }
        .status-badge.valide { background: var(--secondary-100); color: var(--secondary-800); }
        .status-badge.non_depose { background: var(--gray-100); color: var(--gray-600); }
        .status-badge.rejete { background: #fecaca; color: #dc2626; }

        .analysis-badge { padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm); font-size: var(--text-xs); font-weight: 600; }
        .analysis-badge.conforme { background: var(--secondary-100); color: var(--secondary-800); }
        .analysis-badge.a_verifier { background: #fef3c7; color: #92400e; }
        .analysis-badge.non_conforme { background: #fecaca; color: #dc2626; }
        .analysis-badge.non_analyse { background: var(--gray-100); color: var(--gray-600); }

        /* === DÉTAILS RAPPORT === */
        .rapport-details { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-6); }
        .rapport-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--space-6); }
        .rapport-info { flex: 1; }
        .rapport-actions { display: flex; gap: var(--space-3); }

        .rapport-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-2); }
        .rapport-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-4); margin-bottom: var(--space-4); }
        .meta-item { }
        .meta-label { font-size: var(--text-sm); color: var(--gray-600); margin-bottom: var(--space-1); }
        .meta-value { font-weight: 500; color: var(--gray-900); }

        /* === ANALYSE AUTOMATIQUE === */
        .analyse-section { background: var(--gray-50); border-radius: var(--radius-lg); padding: var(--space-6); margin-bottom: var(--space-6); }
        .analyse-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); }
        .analyse-title { font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); }
        .score-circle { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: var(--text-lg); color: white; }
        .score-circle.high { background: var(--success-500); }
        .score-circle.medium { background: var(--warning-500); }
        .score-circle.low { background: var(--error-500); }

        .critères-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--space-4); margin-bottom: var(--space-4); }
        .critère-item { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); background: var(--white); border-radius: var(--radius-md); }
        .critère-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .critère-icon.valid { background: var(--success-500); color: white; }
        .critère-icon.invalid { background: var(--error-500); color: white; }

        .recommandation { padding: var(--space-4); border-radius: var(--radius-lg); font-weight: 600; text-align: center; }
        .recommandation.accepter { background: var(--secondary-100); color: var(--secondary-800); }
        .recommandation.a_corriger { background: #fef3c7; color: #92400e; }
        .recommandation.rejeter { background: #fecaca; color: #dc2626; }

        /* === MODALS === */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal { background: var(--white); border-radius: var(--radius-xl); width: 90%; max-width: 800px; box-shadow: var(--shadow-xl); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: var(--space-6); border-bottom: 1px solid var(--gray-200); }
        .modal-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .modal-content { padding: var(--space-6); }
        .modal-actions { padding: var(--space-6); border-top: 1px solid var(--gray-200); display: flex; justify-content: flex-end; gap: var(--space-3); }
        .modal-close { color: var(--gray-400); font-size: 28px; font-weight: bold; cursor: pointer; transition: color var(--transition-fast); }
        .modal-close:hover { color: var(--gray-600); }

        /* Messages d'alerte */
        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); display: none; }
        .alert.success { background-color: var(--secondary-50); color: var(--secondary-600); border: 1px solid var(--secondary-100); }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }
        .alert.warning { background-color: #fffbeb; color: #92400e; border: 1px solid #fed7aa; }

        .loading { opacity: 0.6; pointer-events: none; }
        .spinner { width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
            .dashboard-stats { grid-template-columns: 1fr; }
            .rapport-header { flex-direction: column; gap: var(--space-3); }
            .rapport-actions { width: 100%; }
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
                        <h1 class="page-title-main">Gestion des Rapports de Stage</h1>
                        <p class="page-subtitle">Suivi et validation des rapports avec analyse automatique</p>
                    </div>
                    <div>
                        <button class="btn btn-primary" id="refreshBtn">
                            <i class="fas fa-sync-alt"></i> Actualiser
                        </button>
                    </div>
                </div>

                <!-- Message d'alerte -->
                <div id="alertMessage" class="alert"></div>

                <!-- Filtres -->
                <div class="form-card">
                    <h3 class="form-card-title">Filtres et recherche</h3>
                    <form id="filterForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="annee_id">Année académique</label>
                                <input type="text" id="annee_display" value="<?php echo htmlspecialchars($anneeActive['annee_libelle'] ?? 'Non définie'); ?>" disabled>
                                <input type="hidden" id="annee_id" value="<?php echo $anneeActive['id_Ac'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="statut_filtre">Statut du rapport</label>
                                <select id="statut_filtre" name="statut_filtre" class="form-control">
                                    <option value="tous">Tous les statuts</option>
                                    <option value="non_depose">Non déposés</option>
                                    <option value="en_attente">En attente de validation</option>
                                    <option value="valide">Validés</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="search_term">Recherche libre</label>
                                <input type="text" id="search_term" name="search_term" placeholder="Nom, matricule, titre..." class="form-control">
                            </div>
                            <div class="form-group" style="display: flex; align-items: end;">
                                <button type="submit" class="btn btn-primary" id="rechercherBtn">
                                    <i class="fas fa-search"></i> Rechercher
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Dashboard statistiques -->
                <div class="dashboard-stats" id="dashboardSection" style="display: none;">
                    <div class="dashboard-card deposés">
                        <div class="dashboard-icon"><i class="fas fa-file-upload"></i></div>
                        <div class="dashboard-value" id="totalDeposes">0</div>
                        <div class="dashboard-label">Rapports déposés</div>
                    </div>
                    <div class="dashboard-card en-attente">
                        <div class="dashboard-icon"><i class="fas fa-clock"></i></div>
                        <div class="dashboard-value" id="totalEnAttente">0</div>
                        <div class="dashboard-label">En attente</div>
                    </div>
                    <div class="dashboard-card validés">
                        <div class="dashboard-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="dashboard-value" id="totalValides">0</div>
                        <div class="dashboard-label">Validés</div>
                    </div>
                    <div class="dashboard-card rejetés">
                        <div class="dashboard-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="dashboard-value" id="totalRejetes">0</div>
                        <div class="dashboard-label">À corriger</div>
                    </div>
                </div>

                <!-- Tableau des rapports -->
                <div class="form-card" id="rapportsSection" style="display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6);">
                        <h3>Rapports de stage (<span id="resultCount">0</span>)</h3>
                        <div>
                            <button class="btn btn-info btn-sm" id="analyseGlobaleBtn">
                                <i class="fas fa-chart-line"></i> Analyse globale
                            </button>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="reports-table" id="reportsTable">
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th>Titre du rapport</th>
                                    <th>Date dépôt</th>
                                    <th>Directeur</th>
                                    <th>Statut</th>
                                    <th>Analyse auto</th>
                                    <th>Délai</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Contenu généré dynamiquement -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal détails rapport -->
    <div id="detailsModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Détails du rapport</h3>
                <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
            </div>
            <div class="modal-content" id="detailsContent">
                <!-- Contenu généré dynamiquement -->
            </div>
        </div>
    </div>

    <!-- Modal validation -->
    <div id="validationModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Validation du rapport</h3>
                <button class="modal-close" onclick="closeModal('validationModal')">&times;</button>
            </div>
            <div class="modal-content">
                <div class="form-group">
                    <label for="decisionValidation">Décision</label>
                    <select id="decisionValidation" class="form-control">
                        <option value="ACCEPTER">Accepter le rapport</option>
                        <option value="A_CORRIGER">Demander des corrections</option>
                        <option value="REJETER">Rejeter le rapport</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="commentaireValidation">Commentaire</label>
                    <textarea id="commentaireValidation" rows="4" placeholder="Commentaires sur le rapport..." class="form-control"></textarea>
                </div>
                <input type="hidden" id="rapportIdValidation">
            </div>
            <div class="modal-actions">
                <button class="btn btn-outline" onclick="closeModal('validationModal')">Annuler</button>
                <button class="btn btn-primary" id="confirmerValidationBtn">
                    <i class="fas fa-check"></i> Confirmer
                </button>
            </div>
        </div>
    </div>

    <!-- Modal affectation directeur -->
    <div id="directeurModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Affecter un directeur de stage</h3>
                <button class="modal-close" onclick="closeModal('directeurModal')">&times;</button>
            </div>
            <div class="modal-content">
                <div class="form-group">
                    <label for="enseignantDirecteur">Enseignant</label>
                    <select id="enseignantDirecteur" class="form-control">
                        <option value="">Sélectionner un enseignant</option>
                        <?php foreach ($enseignants as $ens): ?>
                            <option value="<?php echo $ens['id_ens']; ?>">
                                <?php echo htmlspecialchars($ens['nom_ens'] . ' ' . $ens['prenom_ens']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" id="rapportIdDirecteur">
            </div>
            <div class="modal-actions">
                <button class="btn btn-outline" onclick="closeModal('directeurModal')">Annuler</button>
                <button class="btn btn-primary" id="confirmerDirecteurBtn">
                    <i class="fas fa-user-plus"></i> Affecter
                </button>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let currentData = [];
        let currentRapportId = null;

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

        // Recherche des rapports
        document.getElementById('filterForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            await rechercherRapports();
        });

        document.getElementById('refreshBtn').addEventListener('click', async function() {
            await rechercherRapports();
        });

        async function rechercherRapports() {
            const btn = document.getElementById('rechercherBtn');
            const originalText = btn.innerHTML;
            
            try {
                btn.innerHTML = '<div class="spinner"></div> Recherche...';
                btn.disabled = true;
                
                const formData = new FormData(document.getElementById('filterForm'));
                formData.append('action', 'charger_rapports');
                
                const result = await makeAjaxRequest(Object.fromEntries(formData));
                
                if (result.success) {
                    currentData = result.data;
                    afficherRapports(currentData);
                } else {
                    showAlert(result.message, 'error');
                }
                
            } catch (error) {
                showAlert('Erreur lors de la recherche', 'error');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        // Affichage des rapports
        function afficherRapports(data) {
            // Calcul des statistiques
            const total = data.length;
            const deposes = data.filter(r => r.statut_rapport !== 'NON_DEPOSE').length;
            const enAttente = data.filter(r => r.statut_rapport === 'EN_ATTENTE').length;
            const valides = data.filter(r => r.statut_rapport === 'VALIDE').length;
            const rejetes = 0; // À adapter selon votre logique
            
            document.getElementById('totalDeposes').textContent = deposes;
            document.getElementById('totalEnAttente').textContent = enAttente;
            document.getElementById('totalValides').textContent = valides;
            document.getElementById('totalRejetes').textContent = rejetes;
            
            // Tableau
            const tbody = document.querySelector('#reportsTable tbody');
            tbody.innerHTML = '';
            
            data.forEach(rapport => {
                const row = document.createElement('tr');
                
                const statutClass = rapport.statut_rapport.toLowerCase();
                const statutText = {
                    'EN_ATTENTE': 'En attente',
                    'VALIDE': 'Validé',
                    'NON_DEPOSE': 'Non déposé',
                    'REJETE': 'Rejeté'
                }[rapport.statut_rapport] || rapport.statut_rapport;
                
                const analyseClass = rapport.analyse_auto.toLowerCase();
                const analyseText = {
                    'CONFORME': 'Conforme',
                    'A_VERIFIER': 'À vérifier',
                    'NON_CONFORME': 'Non conforme',
                    'NON_ANALYSE': 'Non analysé'
                }[rapport.analyse_auto] || rapport.analyse_auto;
                
                const delai = rapport.jours_depuis_depot ? 
                    (rapport.jours_depuis_depot > 7 ? `${rapport.jours_depuis_depot}j (⚠️)` : `${rapport.jours_depuis_depot}j`) : '-';
                
                const directeur = rapport.directeur_nom ? 
                    `${rapport.directeur_prenom} ${rapport.directeur_nom}` : '-';
                
                row.innerHTML = `
                    <td>
                        <div><strong>${rapport.nom_etu} ${rapport.prenoms_etu}</strong></div>
                        <div style="font-size: var(--text-xs); color: var(--gray-600);">
                            ${rapport.num_etu} • ${rapport.lib_niv_etu || '-'}
                        </div>
                    </td>
                    <td>
                        <div style="max-width: 200px;">
                            <strong>${rapport.nom_rapport || 'Titre non défini'}</strong>
                            ${rapport.theme_rapport ? `<div style="font-size: var(--text-xs); color: var(--gray-600); margin-top: var(--space-1);">${rapport.theme_rapport.substring(0, 50)}...</div>` : ''}
                        </div>
                    </td>
                    <td>${rapport.date_depot ? new Date(rapport.date_depot).toLocaleDateString('fr-FR') : '-'}</td>
                    <td>${directeur}</td>
                    <td><span class="status-badge ${statutClass}">${statutText}</span></td>
                    <td><span class="analysis-badge ${analyseClass}">${analyseText}</span></td>
                    <td>${delai}</td>
                    <td>
                        <div style="display: flex; gap: var(--space-2);">
                            <button class="btn btn-info btn-sm" onclick="voirDetails(${rapport.id_rapport})" title="Voir détails">
                                <i class="fas fa-eye"></i>
                            </button>
                            ${rapport.id_rapport ? `
                                <button class="btn btn-success btn-sm" onclick="validerRapport(${rapport.id_rapport})" title="Valider">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-warning btn-sm" onclick="affecterDirecteur(${rapport.id_rapport})" title="Directeur">
                                    <i class="fas fa-user-plus"></i>
                                </button>
                                <button class="btn btn-secondary btn-sm" onclick="telechargerRapport(${rapport.id_rapport})" title="Télécharger">
                                    <i class="fas fa-download"></i>
                                </button>
                            ` : ''}
                        </div>
                    </td>
                `;
                tbody.appendChild(row);
            });
            
            document.getElementById('resultCount').textContent = total;
            
            // Afficher les sections
            document.getElementById('dashboardSection').style.display = 'grid';
            document.getElementById('rapportsSection').style.display = 'block';
        }

        // Voir détails avec analyse
        async function voirDetails(idRapport) {
            try {
                const result = await makeAjaxRequest({
                    action: 'analyser_rapport',
                    id_rapport: idRapport
                });
                
                if (result.success) {
                    afficherDetailsAvecAnalyse(idRapport, result.analyse);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'analyse', 'error');
            }
        }

        function afficherDetailsAvecAnalyse(idRapport, analyse) {
            const rapport = currentData.find(r => r.id_rapport == idRapport);
            if (!rapport) return;
            
            const content = document.getElementById('detailsContent');
            
            // Classe CSS pour le score
            let scoreClass = 'low';
            if (analyse.score_global >= 80) scoreClass = 'high';
            else if (analyse.score_global >= 60) scoreClass = 'medium';
            
            content.innerHTML = `
                <div class="rapport-details">
                    <div class="rapport-header">
                        <div class="rapport-info">
                            <h3 class="rapport-title">${rapport.nom_rapport || 'Titre non défini'}</h3>
                            <div class="rapport-meta">
                                <div class="meta-item">
                                    <div class="meta-label">Étudiant</div>
                                    <div class="meta-value">${rapport.nom_etu} ${rapport.prenoms_etu}</div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Matricule</div>
                                    <div class="meta-value">${rapport.num_etu}</div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Date de dépôt</div>
                                    <div class="meta-value">${rapport.date_depot ? new Date(rapport.date_depot).toLocaleDateString('fr-FR') : '-'}</div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Directeur</div>
                                    <div class="meta-value">${rapport.directeur_nom ? rapport.directeur_prenom + ' ' + rapport.directeur_nom : 'Non affecté'}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    ${rapport.theme_rapport ? `
                        <div style="margin-bottom: var(--space-6);">
                            <h4 style="margin-bottom: var(--space-3);">Thème du rapport</h4>
                            <p style="background: var(--gray-50); padding: var(--space-4); border-radius: var(--radius-md); line-height: 1.6;">
                                ${rapport.theme_rapport}
                            </p>
                        </div>
                    ` : ''}
                    
                    <div class="analyse-section">
                        <div class="analyse-header">
                            <h4 class="analyse-title">Analyse automatique du rapport</h4>
                            <div class="score-circle ${scoreClass}">
                                ${analyse.score_global}%
                            </div>
                        </div>
                        
                        <div class="critères-grid">
                            ${Object.entries(analyse.criteres).map(([critere, valide]) => `
                                <div class="critère-item">
                                    <div class="critère-icon ${valide ? 'valid' : 'invalid'}">
                                        <i class="fas fa-${valide ? 'check' : 'times'}"></i>
                                    </div>
                                    <span>${getCritereLabel(critere)}</span>
                                </div>
                            `).join('')}
                        </div>
                        
                        ${analyse.points_forts.length > 0 ? `
                            <div style="margin-bottom: var(--space-4);">
                                <h5 style="color: var(--success-500); margin-bottom: var(--space-2);">
                                    <i class="fas fa-plus-circle"></i> Points forts
                                </h5>
                                <ul style="list-style: none; padding: 0;">
                                    ${analyse.points_forts.map(point => `<li style="padding: var(--space-1) 0; color: var(--gray-700);">• ${point}</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                        
                        ${analyse.points_amelioration.length > 0 ? `
                            <div style="margin-bottom: var(--space-4);">
                                <h5 style="color: var(--warning-500); margin-bottom: var(--space-2);">
                                    <i class="fas fa-exclamation-triangle"></i> Points à améliorer
                                </h5>
                                <ul style="list-style: none; padding: 0;">
                                    ${analyse.points_amelioration.map(point => `<li style="padding: var(--space-1) 0; color: var(--gray-700);">• ${point}</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                        
                        <div class="recommandation ${analyse.recommandation.toLowerCase()}">
                            <i class="fas fa-${getRecommandationIcon(analyse.recommandation)}"></i>
                            Recommandation : ${getRecommandationText(analyse.recommandation)}
                        </div>
                    </div>
                    
                    ${rapport.commentaire_validation ? `
                        <div style="margin-top: var(--space-6); padding: var(--space-4); background: var(--accent-50); border-radius: var(--radius-md);">
                            <h5 style="margin-bottom: var(--space-2);">Commentaire du validateur</h5>
                            <p>${rapport.commentaire_validation}</p>
                            <small style="color: var(--gray-600);">
                                Par ${rapport.validateur_nom ? rapport.validateur_prenom + ' ' + rapport.validateur_nom : 'Système'} 
                                le ${rapport.date_validation ? new Date(rapport.date_validation).toLocaleDateString('fr-FR') : '-'}
                            </small>
                        </div>
                    ` : ''}
                </div>
            `;
            
            document.getElementById('detailsModal').style.display = 'flex';
        }

        function getCritereLabel(critere) {
            const labels = {
                'titre_valide': 'Titre approprié',
                'theme_detaille': 'Thème détaillé',
                'date_coherente': 'Date cohérente',
                'format_respecte': 'Format respecté',
                'contenu_suffisant': 'Contenu suffisant'
            };
            return labels[critere] || critere;
        }

        function getRecommandationIcon(recommandation) {
            const icons = {
                'ACCEPTER': 'check-circle',
                'A_CORRIGER': 'edit',
                'REJETER': 'times-circle'
            };
            return icons[recommandation] || 'question-circle';
        }

        function getRecommandationText(recommandation) {
            const texts = {
                'ACCEPTER': 'Accepter le rapport',
                'A_CORRIGER': 'Demander des corrections',
                'REJETER': 'Rejeter le rapport'
            };
            return texts[recommandation] || recommandation;
        }

        // Validation du rapport
        function validerRapport(idRapport) {
            currentRapportId = idRapport;
            document.getElementById('rapportIdValidation').value = idRapport;
            document.getElementById('validationModal').style.display = 'flex';
        }

        document.getElementById('confirmerValidationBtn').addEventListener('click', async function() {
            const decision = document.getElementById('decisionValidation').value;
            const commentaire = document.getElementById('commentaireValidation').value;
            const idRapport = document.getElementById('rapportIdValidation').value;
            
            try {
                const result = await makeAjaxRequest({
                    action: 'valider_rapport',
                    id_rapport: idRapport,
                    decision: decision,
                    commentaire: commentaire,
                    id_enseignant: 1 // À adapter selon votre auth
                });
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    closeModal('validationModal');
                    await rechercherRapports(); // Recharger
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la validation', 'error');
            }
        });

        // Affectation directeur
        function affecterDirecteur(idRapport) {
            currentRapportId = idRapport;
            document.getElementById('rapportIdDirecteur').value = idRapport;
            document.getElementById('directeurModal').style.display = 'flex';
        }

        document.getElementById('confirmerDirecteurBtn').addEventListener('click', async function() {
            const idEnseignant = document.getElementById('enseignantDirecteur').value;
            const idRapport = document.getElementById('rapportIdDirecteur').value;
            
            if (!idEnseignant) {
                showAlert('Veuillez sélectionner un enseignant', 'error');
                return;
            }
            
            try {
                const result = await makeAjaxRequest({
                    action: 'affecter_directeur',
                    id_rapport: idRapport,
                    id_enseignant: idEnseignant
                });
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    closeModal('directeurModal');
                    await rechercherRapports(); // Recharger
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'affectation', 'error');
            }
        });

        // Téléchargement
        async function telechargerRapport(idRapport) {
            try {
                const result = await makeAjaxRequest({
                    action: 'telecharger_rapport',
                    id_rapport: idRapport
                });
                
                if (result.success) {
                    showAlert(`Téléchargement: ${result.filename}`, 'success');
                    // Ici vous déclencheriez le téléchargement réel
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du téléchargement', 'error');
            }
        }

        // Fermer modals
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            
            if (modalId === 'validationModal') {
                document.getElementById('commentaireValidation').value = '';
                document.getElementById('decisionValidation').selectedIndex = 0;
            }
            
            if (modalId === 'directeurModal') {
                document.getElementById('enseignantDirecteur').selectedIndex = 0;
            }
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            const anneeId = document.getElementById('annee_id').value;
            if (!anneeId) {
                showAlert('Aucune année académique active trouvée.', 'warning');
            } else {
                // Charger automatiquement au démarrage
                rechercherRapports();
            }
        });
    </script>
</body>
</html>