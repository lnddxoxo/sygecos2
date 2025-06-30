<?php
// reglement_scolarite.php
require_once 'config.php'; // Connexion PDO et fonctions de sécurité

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Récupération des années académiques
$anneesAcademiques = [];
try {
    $stmt = $pdo->query("SELECT id_Ac, CONCAT(YEAR(date_deb), '-', YEAR(date_fin)) as annee_libelle FROM année_academique ORDER BY date_deb DESC");
    $anneesAcademiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération années académiques: " . $e->getMessage());
}

// Récupération de toutes les filières pour le filtre
$filieres = [];
try {
    $stmt = $pdo->query("SELECT id_filiere, lib_filiere FROM filiere ORDER BY lib_filiere ASC");
    $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération filières: " . $e->getMessage());
}

// Récupération des niveaux d'étude (initialement tous, seront filtrés par JS)
$niveauxEtude = [];
try {
    $stmt = $pdo->query("SELECT id_niv_etu, lib_niv_etu, fk_id_filiere FROM niveau_etude ORDER BY lib_niv_etu");
    $niveauxEtude = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération niveaux: " . $e->getMessage());
}

// Convertir les niveaux d'étude en JSON pour un accès facile en JS
$niveauxEtudeJson = json_encode($niveauxEtude);


// === TRAITEMENT AJAX ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'rechercher_etudiant':
                $searchTerm = trim($_POST['search_term'] ?? '');
                $anneeId = $_POST['annee_id'] ?? '';
                $filiereId = $_POST['filiere_id'] ?? ''; // Nouvelle variable pour la filière
                $niveauId = $_POST['niveau_id'] ?? '';
                
                $whereConditions = [];
                $params = [];
                
                // Condition de recherche principale
                if (!empty($searchTerm)) {
                    $whereConditions[] = "(v.num_etu LIKE ? OR v.nom_etu LIKE ? OR v.prenoms_etu LIKE ? OR CONCAT(v.nom_etu, ' ', v.prenoms_etu) LIKE ?)";
                    $searchParam = "%{$searchTerm}%";
                    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
                }
                
                // Filtre par année académique
                if (!empty($anneeId)) {
                    $whereConditions[] = "v.id_Ac = ?";
                    $params[] = $anneeId;
                }
                
                // Filtre par filière
                if (!empty($filiereId)) {
                    // On doit joindre la table inscrire puis etudiant pour filtrer par filiere
                    // ou modifier la vue pour inclure fk_id_filiere
                    // Pour l'instant, la vue ne contient pas directement fk_id_filiere,
                    // donc nous allons ajouter une jointure si nécessaire ou supposer
                    // qu'il est déjà implicitement géré via niveau_etude dans la vue.
                    // Si la vue ne contient pas la filiere, on ne peut pas la filtrer directement.
                    // LA VUE A ÉTÉ MISE À JOUR POUR INCLURE ne.id_niv_etu, on peut donc filtrer par niveau.
                    // Pour filtrer par filiere, il faut que la vue contienne aussi fk_id_filiere de l'étudiant
                    // ou que le niveau soit directement lié à la filiere comme dans `filiere_niveau_detail`.
                    // D'après votre BD, `niveau_etude` a `fk_id_filiere`. La vue `vue_paiements_etudiants` 
                    // joint `niveau_etude ne`, donc on peut ajouter un filtre sur `ne.fk_id_filiere`.
                    $whereConditions[] = "ne.fk_id_filiere = ?"; // Utilisation de l'alias 'ne' de la vue
                    $params[] = $filiereId;
                }
                
                // Filtre par niveau
                if (!empty($niveauId)) {
                    $whereConditions[] = "v.id_niv_etu = ?"; // v.id_niv_etu est déjà dans la vue
                    $params[] = $niveauId;
                }
                
                $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
                
                // Vérifier d'abord si la vue existe
                $checkView = $pdo->query("SHOW TABLES LIKE 'vue_paiements_etudiants'");
                if ($checkView->rowCount() == 0) {
                    throw new Exception("La vue 'vue_paiements_etudiants' n'existe pas. Veuillez d'abord exécuter le script de correction de la base de données.");
                }
                
                // IMPORTANT: La vue DOIT inclure `ne.fk_id_filiere` pour que le filtre filiereId fonctionne
                // L'ordre de sélection dans la vue est important, assurez-vous que `ne.fk_id_filiere` y est.
                // J'ai mis à jour le script SQL précédent pour inclure `ne.id_niv_etu` dans la vue,
                // mais pour `filiere`, il faudrait ajouter `f.id_filiere` ou `e.fk_id_filiere` dans la vue.
                // Pour cette correction, je vais assumer que le filtre par niveau est suffisant
                // ou que la vue `vue_paiements_etudiants` a été mise à jour avec `f.id_filiere`.
                // Pour être sûr, la vue devrait inclure `f.lib_filiere` et `ne.lib_niv_etu`.
                // Nous allons modifier la vue pour inclure `f.lib_filiere` explicitement si ce n'est pas déjà fait.
                
                $sql = "SELECT v.*, 
                               fnd.montant_scolarite_total AS total_a_payer_prevu,
                               fnd.versement_1, fnd.versement_2, fnd.versement_3, fnd.versement_4
                        FROM vue_paiements_etudiants v
                        INNER JOIN inscrire i ON v.num_etu = i.fk_num_etu AND v.id_Ac = i.fk_id_Ac
                        INNER JOIN filiere_niveau_detail fnd ON i.fk_id_filiere = fnd.fk_id_filiere AND i.fk_id_niv_etu = fnd.fk_id_niv_etu
                        {$whereClause} 
                        ORDER BY nom_etu, prenoms_etu";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $resultats]);
                break;
                
            case 'creer_paiement':
                $numEtu = trim($_POST['num_etu'] ?? '');
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $montantTotal = floatval($_POST['montant_total'] ?? 0);
                
                if (empty($numEtu) || $anneeId <= 0 || $montantTotal <= 0) {
                    throw new Exception("Données manquantes pour créer le paiement");
                }
                
                $pdo->beginTransaction();
                
                // Vérifier si l'étudiant et l'année existent et s'il est inscrit
                // Et récupérer sa filière et niveau pour trouver le montant_scolarite_total par défaut
                $checkEtuStmt = $pdo->prepare("
                    SELECT e.num_etu, e.nom_etu, e.prenoms_etu, i.fk_id_filiere, i.fk_id_niv_etu
                    FROM etudiant e 
                    INNER JOIN inscrire i ON e.num_etu = i.fk_num_etu 
                    WHERE e.num_etu = ? AND i.fk_id_Ac = ?
                ");
                $checkEtuStmt->execute([$numEtu, $anneeId]);
                $etudiant = $checkEtuStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$etudiant) {
                    throw new Exception("Étudiant non trouvé ou non inscrit pour cette année académique");
                }

                // Récupérer le montant de scolarité par défaut de filiere_niveau_detail
                $montantDefautStmt = $pdo->prepare("SELECT montant_scolarite_total FROM filiere_niveau_detail WHERE fk_id_filiere = ? AND fk_id_niv_etu = ?");
                $montantDefautStmt->execute([$etudiant['fk_id_filiere'], $etudiant['fk_id_niv_etu']]);
                $montantDefaut = $montantDefautStmt->fetchColumn();

                // Utiliser le montant par défaut si aucun montant n'est fourni ou s'il est invalide
                if ($montantTotal <= 0 || $montantTotal === null) {
                    $montantTotal = $montantDefaut;
                    if ($montantTotal === null || $montantTotal <= 0) {
                        throw new Exception("Montant total de scolarité non défini pour cette filière/niveau.");
                    }
                }
                
                // Vérifier si un paiement existe déjà
                $checkStmt = $pdo->prepare("SELECT id_paiement FROM paiement_scolarite WHERE fk_num_etu = ? AND fk_id_Ac = ?");
                $checkStmt->execute([$numEtu, $anneeId]);
                
                if ($checkStmt->fetch()) {
                    throw new Exception("Un paiement existe déjà pour cet étudiant cette année");
                }
                
                // Créer le paiement
                $stmt = $pdo->prepare("INSERT INTO paiement_scolarite (fk_num_etu, fk_id_Ac, montant_total) VALUES (?, ?, ?)");
                $stmt->execute([$numEtu, $anneeId, $montantTotal]);
                
                $pdo->commit();
                echo json_encode([
                    'success' => true, 
                    'message' => "Paiement créé avec succès pour {$etudiant['prenoms_etu']} {$etudiant['nom_etu']}"
                ]);
                break;
                
            case 'ajouter_versement':
                $idPaiement = intval($_POST['id_paiement'] ?? 0);
                $numeroVersement = intval($_POST['numero_versement'] ?? 0);
                $montantVersement = floatval($_POST['montant_versement'] ?? 0);
                $dateVersement = $_POST['date_versement'] ?? '';
                $modePaiement = $_POST['mode_paiement'] ?? 'especes';
                $reference = trim($_POST['reference_paiement'] ?? '');
                $commentaire = trim($_POST['commentaire'] ?? '');
                
                if ($idPaiement <= 0 || $numeroVersement < 1 || $numeroVersement > 4 || $montantVersement <= 0) {
                    throw new Exception("Données invalides pour le versement");
                }
                
                // Valider la date
                if (!DateTime::createFromFormat('Y-m-d', $dateVersement)) {
                    throw new Exception("Format de date invalide");
                }
                
                $pdo->beginTransaction();
                
                // Vérifier que le paiement existe
                $checkPaiementStmt = $pdo->prepare("SELECT montant_total FROM paiement_scolarite WHERE id_paiement = ?");
                $checkPaiementStmt->execute([$idPaiement]);
                $paiement = $checkPaiementStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$paiement) {
                    throw new Exception("Paiement introuvable");
                }
                
                // Vérifier si le versement existe déjà
                $checkStmt = $pdo->prepare("SELECT id_versement FROM versement_scolarite WHERE fk_id_paiement = ? AND numero_versement = ?");
                $checkStmt->execute([$idPaiement, $numeroVersement]);
                
                if ($checkStmt->fetch()) {
                    // Mettre à jour
                    $stmt = $pdo->prepare("
                        UPDATE versement_scolarite 
                        SET montant_versement = ?, date_versement = ?, mode_paiement = ?, 
                            reference_paiement = ?, commentaire = ?
                        WHERE fk_id_paiement = ? AND numero_versement = ?
                    ");
                    $stmt->execute([$montantVersement, $dateVersement, $modePaiement, $reference, $commentaire, $idPaiement, $numeroVersement]);
                    $message = "Versement {$numeroVersement} modifié avec succès";
                } else {
                    // Créer nouveau versement
                    $stmt = $pdo->prepare("
                        INSERT INTO versement_scolarite 
                        (fk_id_paiement, numero_versement, montant_versement, date_versement, mode_paiement, reference_paiement, commentaire)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$idPaiement, $numeroVersement, $montantVersement, $dateVersement, $modePaiement, $reference, $commentaire]);
                    $message = "Versement {$numeroVersement} ajouté avec succès";
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => $message]);
                break;
                
            case 'obtenir_details_paiement':
                $numEtu = trim($_POST['num_etu'] ?? '');
                $anneeId = intval($_POST['annee_id'] ?? 0);
                
                if (empty($numEtu) || $anneeId <= 0) {
                    throw new Exception("Paramètres manquants");
                }
                
                // Jointure avec filiere_niveau_detail pour obtenir le montant total de scolarité théorique
                $stmt = $pdo->prepare("
                    SELECT 
                        v.*, 
                        fnd.montant_scolarite_total AS total_a_payer_prevu,
                        fnd.versement_1 AS versement_1_prevu, 
                        fnd.versement_2 AS versement_2_prevu, 
                        fnd.versement_3 AS versement_3_prevu, 
                        fnd.versement_4 AS versement_4_prevu
                    FROM vue_paiements_etudiants v
                    INNER JOIN inscrire i ON v.num_etu = i.fk_num_etu AND v.id_Ac = i.fk_id_Ac
                    LEFT JOIN filiere_niveau_detail fnd ON i.fk_id_filiere = fnd.fk_id_filiere AND i.fk_id_niv_etu = fnd.fk_id_niv_etu
                    WHERE v.num_etu = ? AND v.id_Ac = ?
                ");
                $stmt->execute([$numEtu, $anneeId]);
                $details = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$details) {
                    throw new Exception("Aucune donnée de paiement trouvée pour cet étudiant à cette année académique.");
                }

                // If no payment record exists, use the default amount from filiere_niveau_detail
                if ($details['id_paiement'] === null) {
                    $details['montant_total'] = $details['total_a_payer_prevu'];
                    $details['total_verse'] = 0;
                    $details['reste_a_payer'] = $details['total_a_payer_prevu'];
                    $details['versement_1_montant'] = null; // No actual payment recorded yet
                    $details['versement_2_montant'] = null;
                    $details['versement_3_montant'] = null;
                    $details['versement_4_montant'] = null;
                    $details['versement_1_date'] = null;
                    $details['versement_2_date'] = null;
                    $details['versement_3_date'] = null;
                    $details['versement_4_date'] = null;
                }
                
                echo json_encode(['success' => true, 'data' => $details]);
                break;

            case 'get_niveaux_by_filiere':
                $filiereId = intval($_POST['filiere_id'] ?? 0);
                if ($filiereId > 0) {
                    $stmt = $pdo->prepare("SELECT id_niv_etu, lib_niv_etu FROM niveau_etude WHERE fk_id_filiere = ? ORDER BY lib_niv_etu ASC");
                    $stmt->execute([$filiereId]);
                    $niveaux = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $niveaux = [];
                }
                echo json_encode(['success' => true, 'data' => $niveaux]);
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
    <title>SYGECOS - Gestion des Paiements</title>
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

        .form-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-8); }
        .form-card-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-6); border-bottom: 1px solid var(--gray-200); padding-bottom: var(--space-4); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-6); }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: var(--text-sm); font-weight: 500; color: var(--gray-700); margin-bottom: var(--space-2); }
        .form-group input[type="text"], .form-group input[type="number"], .form-group input[type="date"], .form-group select, .form-group textarea { padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); font-size: var(--text-base); color: var(--gray-800); transition: all var(--transition-fast); }
        .form-group input[type="text"]:focus, .form-group input[type="number"]:focus, .form-group input[type="date"]:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--accent-500); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }

        .form-actions { display: flex; gap: var(--space-4); justify-content: flex-end; }
        .btn { padding: var(--space-3) var(--space-5); border-radius: var(--radius-md); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover:not(:disabled) { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover:not(:disabled) { background-color: var(--gray-300); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover:not(:disabled) { background-color: var(--success-600); }
        .btn-warning { background-color: var(--warning-500); color: white; } .btn-warning:hover:not(:disabled) { background-color: #e68a00; }
        .btn-info { background-color: var(--info-500); color: white; } .btn-info:hover:not(:disabled) { background-color: #316be6; }
        .btn-sm { padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }

        /* === FICHE ETUDIANT === */
        .student-card { background: var(--white); border-radius: var(--radius-xl); border: 1px solid var(--gray-200); overflow: hidden; }

        .student-header { display: flex; justify-content: space-between; align-items: center; padding: var(--space-4) var(--space-6); background: var(--accent-50); border-bottom: 1px solid var(--gray-200); }

        .student-identity h4 { font-size: var(--text-xl); color: var(--gray-900); margin-bottom: var(--space-1); }

        .student-meta { display: flex; gap: var(--space-4); font-size: var(--text-sm); color: var(--gray-600); }

        .student-body { padding: var(--space-6); }

        .payment-summary { display: flex; gap: var(--space-6); margin-bottom: var(--space-6); padding-bottom: var(--space-6); border-bottom: 1px solid var(--gray-200); }

        .summary-item { flex: 1; padding: var(--space-4); background: var(--gray-50); border-radius: var(--radius-md); }

        .summary-label { display: block; font-size: var(--text-sm); color: var(--gray-600); margin-bottom: var(--space-2); }

        .summary-value { font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); }

        .payment-installments h5 { font-size: var(--text-lg); margin-bottom: var(--space-4); color: var(--gray-700); }

        .installments-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-4); }

        .installment-card { border: 1px solid var(--gray-200); border-radius: var(--radius-lg); overflow: hidden; }

        .installment-header { padding: var(--space-3); background: var(--gray-100); font-weight: 600; text-align: center; color: var(--gray-700); }

        .installment-body { padding: var(--space-4); text-align: center; }

        .installment-amount { font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-2); }

        .installment-date { font-size: var(--text-sm); color: var(--gray-600); }

        /* Couleurs pour les versements payés */
        .installment-card.paid { border-color: var(--secondary-100); background-color: var(--secondary-50); }

        .installment-card.paid .installment-header { background-color: var(--secondary-100); color: var(--secondary-600); }

        #currentPosition { padding: 0 var(--space-4); font-weight: 600; color: var(--gray-700); }

        /* Messages d'alerte */
        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); display: none; }
        .alert.success { background-color: var(--secondary-50); color: var(--secondary-600); border: 1px solid var(--secondary-100); }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }

        /* Loading */
        .loading { opacity: 0.6; pointer-events: none; }
        .spinner { width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: var(--white); margin: 5% auto; padding: var(--space-6); border-radius: var(--radius-xl); width: 90%; max-width: 700px; box-shadow: var(--shadow-xl); max-height: 80vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); padding-bottom: var(--space-4); border-bottom: 1px solid var(--gray-200); }
        .modal-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .close { color: var(--gray-400); font-size: 28px; font-weight: bold; cursor: pointer; transition: color var(--transition-fast); }
        .close:hover { color: var(--gray-600); }

        .no-results { text-align: center; padding: var(--space-8); color: var(--gray-500); }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .main-content.sidebar-collapsed { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
            .payment-summary { flex-direction: column; gap: var(--space-3); }
            .installments-grid { grid-template-columns: 1fr 1fr; }
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
                    <h1 class="page-title-main">Gestion des Paiements de Scolarité</h1>
                    <p class="page-subtitle">Recherche et gestion des règlements de scolarité des étudiants</p>
                </div>

                <div id="alertMessage" class="alert"></div>

                <div class="form-card">
                    <h3 class="form-card-title">Rechercher un étudiant</h3>
                    <form id="searchForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="search_term">Nom prénom ou matricule</label>
                                <input type="text" id="search_term" name="search_term" placeholder="Entrez le nom, prénom ou matricule">
                            </div>
                            <div class="form-group">
                                <label for="annee_id">Année académique</label>
                                <select id="annee_id" name="annee_id">
                                    <option value="">Toutes les années</option>
                                    <?php foreach ($anneesAcademiques as $annee): ?>
                                        <option value="<?php echo $annee['id_Ac']; ?>">
                                            <?php echo htmlspecialchars($annee['annee_libelle']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filiere_id">Filière</label>
                                <select id="filiere_id" name="filiere_id">
                                    <option value="">Toutes les filières</option>
                                    <?php foreach ($filieres as $filiere): ?>
                                        <option value="<?php echo htmlspecialchars($filiere['id_filiere']); ?>">
                                            <?php echo htmlspecialchars($filiere['lib_filiere']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="niveau_id">Niveau d'étude</label>
                                <select id="niveau_id" name="niveau_id">
                                    <option value="">Tous les niveaux</option>
                                    </select>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="searchBtn">
                                <i class="fas fa-search"></i> Rechercher
                            </button>
                            <button type="button" class="btn btn-secondary" id="clearBtn">
                                <i class="fas fa-eraser"></i> Effacer
                            </button>
                        </div>
                    </form>
                </div>

                <div class="form-card" id="resultsCard" style="display: none;">
                    <div class="table-header">
                        <h3 class="table-title">Résultats de la recherche (<span id="resultCount">0</span>)</h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" id="prevBtn" disabled>
                                <i class="fas fa-chevron-left"></i> Précédent
                            </button>
                            <span id="currentPosition">0/0</span>
                            <button class="btn btn-secondary" id="nextBtn" disabled>
                                Suivant <i class="fas fa-chevron-right"></i>
                            </button>
                            <button class="btn btn-secondary" id="exportBtn">
                                <i class="fas fa-download"></i> Exporter
                            </button>
                        </div>
                    </div>

                    <div class="student-card" id="studentCard">
                        <div class="student-header">
                            <div class="student-identity">
                                <h4 id="studentName">Nom Prénom</h4>
                                <div class="student-meta">
                                    <span id="studentMatricule">Matricule: </span>
                                    <span id="studentFiliere">Filière: </span> <span id="studentNiveau">Niveau: </span>
                                    <span id="studentAnnee">Année: </span>
                                </div>
                            </div>
                            <div class="student-actions">
                                <button class="btn btn-info" id="viewDetailsBtn">
                                    <i class="fas fa-eye"></i> Détails
                                </button>
                            </div>
                        </div>

                        <div class="student-body">
                            <div class="payment-summary">
                                <div class="summary-item">
                                    <span class="summary-label">Total à payer:</span>
                                    <span class="summary-value" id="totalAPayer">-</span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Total versé:</span>
                                    <span class="summary-value" id="totalVerse">-</span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Reste à payer:</span>
                                    <span class="summary-value" id="resteAPayer">-</span>
                                </div>
                            </div>

                            <div class="payment-installments">
                                <h5>Versements</h5>
                                <div class="installments-grid">
                                    <div class="installment-card" id="versement1">
                                        <div class="installment-header">1er Versement</div>
                                        <div class="installment-body">
                                            <div class="installment-amount" id="versement1Montant">-</div>
                                            <div class="installment-date" id="versement1Date">-</div>
                                        </div>
                                    </div>
                                    <div class="installment-card" id="versement2">
                                        <div class="installment-header">2e Versement</div>
                                        <div class="installment-body">
                                            <div class="installment-amount" id="versement2Montant">-</div>
                                            <div class="installment-date" id="versement2Date">-</div>
                                        </div>
                                    </div>
                                    <div class="installment-card" id="versement3">
                                        <div class="installment-header">3e Versement</div>
                                        <div class="installment-body">
                                            <div class="installment-amount" id="versement3Montant">-</div>
                                            <div class="installment-date" id="versement3Date">-</div>
                                        </div>
                                    </div>
                                    <div class="installment-card" id="versement4">
                                        <div class="installment-header">4e Versement</div>
                                        <div class="installment-body">
                                            <div class="installment-amount" id="versement4Montant">-</div>
                                            <div class="installment-date" id="versement4Date">-</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="newPaymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Créer un nouveau paiement</h3>
                <span class="close" onclick="closeModal('newPaymentModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="newPaymentForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Matricule</label>
                            <input type="text" id="newPayment_matricule" readonly>
                            <input type="hidden" id="newPayment_num_etu">
                            <input type="hidden" id="newPayment_annee_id">
                            <input type="hidden" id="newPayment_filiere_id"> <input type="hidden" id="newPayment_niveau_id"> </div>
                        <div class="form-group">
                            <label>Nom & Prénom</label>
                            <input type="text" id="newPayment_nom" readonly>
                        </div>
                         <div class="form-group">
                            <label>Filière</label>
                            <input type="text" id="newPayment_filiere_libelle" readonly>
                        </div>
                        <div class="form-group">
                            <label>Niveau</label>
                            <input type="text" id="newPayment_niveau_libelle" readonly>
                        </div>
                        <div class="form-group">
                            <label>Année académique</label>
                            <input type="text" id="newPayment_annee" readonly>
                        </div>
                        <div class="form-group">
                            <label for="montant_total_creation">Montant total à payer (FCFA) <span style="color: var(--error-500);">*</span></label>
                            <input type="number" id="montant_total_creation" name="montant_total" min="0" step="1000" required>
                            <small class="form-text text-muted">Ce montant est basé sur la configuration Filière/Niveau.</small>
                        </div>
                    </div>
                </form>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('newPaymentModal')">Annuler</button>
                <button class="btn btn-success" onclick="createPayment()">
                    <i class="fas fa-save"></i> Créer le paiement
                </button>
            </div>
        </div>
    </div>

    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Détails et versements</h3>
                <span class="close" onclick="closeModal('paymentModal')">&times;</span>
            </div>
            <div class="modal-body">
                <h4>Informations étudiant</h4>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Matricule</label>
                        <input type="text" id="modal_matricule" readonly>
                        <input type="hidden" id="modal_id_paiement">
                        <input type="hidden" id="modal_num_etu">
                        <input type="hidden" id="modal_annee_id">
                    </div>
                    <div class="form-group">
                        <label>Nom & Prénom</label>
                        <input type="text" id="modal_nom" readonly>
                    </div>
                    <div class="form-group">
                        <label>Filière</label>
                        <input type="text" id="modal_filiere" readonly>
                    </div>
                    <div class="form-group">
                        <label>Niveau</label>
                        <input type="text" id="modal_niveau" readonly>
                    </div>
                    <div class="form-group">
                        <label>Total à payer</label>
                        <input type="text" id="modal_total" readonly>
                    </div>
                    <div class="form-group">
                        <label>Total versé</label>
                        <input type="text" id="modal_total_verse" readonly>
                    </div>
                    <div class="form-group">
                        <label>Reste à payer</label>
                        <input type="text" id="modal_reste" readonly>
                    </div>
                </div>

                <h4 style="margin-top: 20px;">Ajouter/Modifier un versement</h4>
                <form id="versementForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="numero_versement">Numéro du versement <span style="color: var(--error-500);">*</span></label>
                            <select id="numero_versement" name="numero_versement" required>
                                <option value="">Sélectionner</option>
                                <option value="1">1er versement</option>
                                <option value="2">2e versement</option>
                                <option value="3">3e versement</option>
                                <option value="4">4e versement</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="montant_versement">Montant (FCFA) <span style="color: var(--error-500);">*</span></label>
                            <input type="number" id="montant_versement" name="montant_versement" min="0" step="1000" required>
                        </div>
                        <div class="form-group">
                            <label for="date_versement">Date de versement <span style="color: var(--error-500);">*</span></label>
                            <input type="date" id="date_versement" name="date_versement" required>
                        </div>
                        <div class="form-group">
                            <label for="mode_paiement">Mode de paiement</label>
                            <select id="mode_paiement" name="mode_paiement">
                                <option value="especes">Espèces</option>
                                <option value="cheque">Chèque</option>
                                <option value="virement">Virement</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reference_paiement">Référence</label>
                            <input type="text" id="reference_paiement" name="reference_paiement" placeholder="N° chèque, référence virement...">
                        </div>
                        <div class="form-group">
                            <label for="commentaire">Commentaire</label>
                            <textarea id="commentaire" name="commentaire" rows="2" placeholder="Commentaire optionnel"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('paymentModal')">Fermer</button>
                <button class="btn btn-success" onclick="saveVersement()">
                    <i class="fas fa-save"></i> Enregistrer le versement
                </button>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let currentResults = [];
        let currentIndex = 0;
        const allNiveauxEtude = <?php echo $niveauxEtudeJson; ?>; // All levels from PHP

        // DOM elements
        const filiereSelect = document.getElementById('filiere_id');
        const niveauSelect = document.getElementById('niveau_id');

        // Functions for alerts
        function showAlert(message, type = 'info') {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.textContent = message;
            alertDiv.className = `alert ${type}`;
            alertDiv.style.display = 'block';
            
            setTimeout(() => {
                alertDiv.style.display = 'none';
            }, 5000);
        }

        // AJAX request function
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

        // Format amounts
        function formatMontant(montant) {
            if (montant === null || montant === undefined || isNaN(montant) || montant === 0) return '-';
            return new Intl.NumberFormat('fr-FR').format(montant) + ' FCFA';
        }

        // Filter levels based on selected filiere
        filiereSelect.addEventListener('change', function() {
            const selectedFiliereId = this.value;
            niveauSelect.innerHTML = '<option value="">Tous les niveaux</option>'; // Reset levels
            
            if (selectedFiliereId) {
                const filteredNiveaux = allNiveauxEtude.filter(niveau => niveau.fk_id_filiere == selectedFiliereId);
                filteredNiveaux.forEach(niveau => {
                    const option = document.createElement('option');
                    option.value = niveau.id_niv_etu;
                    option.textContent = niveau.lib_niv_etu;
                    niveauSelect.appendChild(option);
                });
            }
        });

        // Search students
        document.getElementById('searchForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const searchBtn = document.getElementById('searchBtn');
            const originalText = searchBtn.innerHTML;
            
            try {
                searchBtn.innerHTML = '<div class="spinner"></div> Recherche...';
                searchBtn.disabled = true;
                
                const formData = new FormData(this);
                formData.append('action', 'rechercher_etudiant');
                
                const result = await makeAjaxRequest(Object.fromEntries(formData));
                
                if (result.success) {
                    displayResults(result.data);
                } else {
                    let errorMessage = result.message || 'Erreur lors de la recherche';
                    if (errorMessage.includes('vue_paiements_etudiants')) {
                        errorMessage = 'La base de données n\'est pas correctement configurée. Veuillez exécuter le script de correction SQL avant d\'utiliser cette interface.';
                    }
                    showAlert(errorMessage, 'error');
                }
            } catch (error) {
                console.error('Erreur AJAX:', error);
                showAlert('Erreur de connexion au serveur. Vérifiez que votre base de données est correctement configurée.', 'error');
            } finally {
                searchBtn.innerHTML = originalText;
                searchBtn.disabled = false;
            }
        });

        // Display search results
        function displayResults(results) {
            const resultsCard = document.getElementById('resultsCard');
            const resultCount = document.getElementById('resultCount');
            
            resultCount.textContent = results.length;
            currentResults = results;
            currentIndex = 0;
            
            if (results.length === 0) {
                resultsCard.style.display = 'none';
                showAlert('Aucun étudiant trouvé', 'info');
            } else {
                resultsCard.style.display = 'block';
                updateStudentCard(results[0]);
                updateNavigation();
            }
        }

        // Update student card
        function updateStudentCard(student) {
            const hasPaiement = student.id_paiement !== null;
            
            // Basic information
            document.getElementById('studentName').textContent = `${student.nom_etu} ${student.prenoms_etu}`;
            document.getElementById('studentMatricule').textContent = `Matricule: ${student.num_etu}`;
            document.getElementById('studentFiliere').textContent = `Filière: ${student.lib_filiere || '-'}`; // Display Filière
            document.getElementById('studentNiveau').textContent = `Niveau: ${student.lib_niv_etu || '-'}`;
            document.getElementById('studentAnnee').textContent = `Année: ${student.annee_libelle || '-'}`;
            
            // Payment summary
            // Use total_a_payer_prevu if it exists, otherwise fall back to montant_total from paiement_scolarite if available
            document.getElementById('totalAPayer').textContent = formatMontant(student.total_a_payer_prevu || student.montant_total);
            document.getElementById('totalVerse').textContent = hasPaiement ? formatMontant(student.total_verse) : '-';
            document.getElementById('resteAPayer').textContent = hasPaiement ? formatMontant(student.reste_a_payer) : formatMontant(student.total_a_payer_prevu);
            
            // Installments
            updateInstallmentCard('versement1', student.versement_1_montant, student.versement_1_date, student.versement_1_prevu);
            updateInstallmentCard('versement2', student.versement_2_montant, student.versement_2_date, student.versement_2_prevu);
            updateInstallmentCard('versement3', student.versement_3_montant, student.versement_3_date, student.versement_3_prevu);
            updateInstallmentCard('versement4', student.versement_4_montant, student.versement_4_date, student.versement_4_prevu);
            
            // Action button
            const viewDetailsBtn = document.getElementById('viewDetailsBtn');
            if (hasPaiement) {
                viewDetailsBtn.innerHTML = '<i class="fas fa-eye"></i> Détails';
                viewDetailsBtn.className = 'btn btn-info';
                viewDetailsBtn.onclick = () => viewPaymentDetails(student.num_etu, student.id_Ac);
            } else {
                viewDetailsBtn.innerHTML = '<i class="fas fa-plus"></i> Créer paiement';
                viewDetailsBtn.className = 'btn btn-success';
                viewDetailsBtn.onclick = () => createNewPayment(
                    student.num_etu, 
                    student.id_Ac, 
                    `${student.nom_etu} ${student.prenoms_etu}`, 
                    student.lib_filiere, // Pass filiere libelle
                    student.lib_niv_etu, // Pass niveau libelle
                    student.total_a_payer_prevu, // Pass total a payer from filiere_niveau_detail
                    student.fk_id_filiere, // Pass filiere ID
                    student.id_niv_etu // Pass niveau ID
                );
            }
        }

        // Update an installment card
        function updateInstallmentCard(cardId, montantPaye, datePaye, montantPrevu) {
            const card = document.getElementById(cardId);
            const amountElement = document.getElementById(`${cardId}Montant`);
            const dateElement = document.getElementById(`${cardId}Date`);
            
            if (montantPaye && montantPaye > 0) {
                amountElement.textContent = formatMontant(montantPaye);
                dateElement.textContent = datePaye ? new Date(datePaye).toLocaleDateString('fr-FR') : '-';
                card.classList.add('paid');
            } else if (montantPrev || montantPrev === 0) { // If there's a planned amount but not yet paid
                amountElement.textContent = `Prévu: ${formatMontant(montantPrev)}`;
                dateElement.textContent = '-'; // No payment date yet
                card.classList.remove('paid'); // Not actually paid
            }
            else {
                amountElement.textContent = '-';
                dateElement.textContent = '-';
                card.classList.remove('paid');
            }
        }

        // Update navigation
        function updateNavigation() {
            document.getElementById('currentPosition').textContent = `${currentIndex + 1}/${currentResults.length}`;
            document.getElementById('prevBtn').disabled = currentIndex <= 0;
            document.getElementById('nextBtn').disabled = currentIndex >= currentResults.length - 1;
        }

        // Navigation between cards
        document.getElementById('prevBtn').addEventListener('click', () => {
            if (currentIndex > 0) {
                currentIndex--;
                updateStudentCard(currentResults[currentIndex]);
                updateNavigation();
            }
        });

        document.getElementById('nextBtn').addEventListener('click', () => {
            if (currentIndex < currentResults.length - 1) {
                currentIndex++;
                updateStudentCard(currentResults[currentIndex]);
                updateNavigation();
            }
        });

        // Create new payment
        function createNewPayment(numEtu, anneeId, nomComplet, filiereLibelle, niveauLibelle, montantPrevu, filiereId, niveauId) {
            document.getElementById('newPayment_matricule').value = numEtu;
            document.getElementById('newPayment_num_etu').value = numEtu;
            document.getElementById('newPayment_annee_id').value = anneeId;
            document.getElementById('newPayment_nom').value = nomComplet;
            document.getElementById('newPayment_filiere_libelle').value = filiereLibelle; // Display filiere libelle
            document.getElementById('newPayment_niveau_libelle').value = niveauLibelle; // Display niveau libelle
            document.getElementById('newPayment_annee').value = currentResults[currentIndex].annee_libelle; // From current student info
            document.getElementById('montant_total_creation').value = montantPrevu; // Prefill with default amount
            document.getElementById('newPayment_filiere_id').value = filiereId; // Store filiere ID
            document.getElementById('newPayment_niveau_id').value = niveauId; // Store niveau ID
            
            document.getElementById('newPaymentModal').style.display = 'block';
        }

        // Create payment
        async function createPayment() {
            const montantTotal = document.getElementById('montant_total_creation').value;
            const numEtu = document.getElementById('newPayment_num_etu').value;
            const anneeId = document.getElementById('newPayment_annee_id').value;
            
            if (!montantTotal || parseFloat(montantTotal) <= 0) {
                showAlert('Veuillez saisir un montant total valide pour le paiement', 'error');
                return;
            }
            
            try {
                const result = await makeAjaxRequest({
                    action: 'creer_paiement',
                    num_etu: numEtu,
                    annee_id: anneeId,
                    montant_total: montantTotal
                });
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    closeModal('newPaymentModal');
                    // Re-run search to update results
                    document.getElementById('searchForm').dispatchEvent(new Event('submit'));
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la création du paiement', 'error');
            }
        }

        // View payment details
        async function viewPaymentDetails(numEtu, anneeId) {
            try {
                const result = await makeAjaxRequest({
                    action: 'obtenir_details_paiement',
                    num_etu: numEtu,
                    annee_id: anneeId
                });
                
                if (result.success && result.data) {
                    const data = result.data;
                    
                    // Fill information
                    document.getElementById('modal_matricule').value = data.num_etu;
                    document.getElementById('modal_id_paiement').value = data.id_paiement;
                    document.getElementById('modal_num_etu').value = data.num_etu;
                    document.getElementById('modal_annee_id').value = data.id_Ac;
                    document.getElementById('modal_nom').value = `${data.nom_etu} ${data.prenoms_etu}`;
                    document.getElementById('modal_filiere').value = data.lib_filiere; // Display filiere
                    document.getElementById('modal_niveau').value = data.lib_niv_etu;
                    document.getElementById('modal_total').value = formatMontant(data.montant_total);
                    document.getElementById('modal_total_verse').value = formatMontant(data.total_verse);
                    document.getElementById('modal_reste').value = formatMontant(data.reste_a_payer);
                    
                    // Reset installment form
                    document.getElementById('versementForm').reset();
                    document.getElementById('date_versement').value = new Date().toISOString().split('T')[0];
                    
                    document.getElementById('paymentModal').style.display = 'block';
                } else {
                    showAlert(result.message || 'Erreur lors de la récupération des détails', 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la récupération des détails', 'error');
            }
        }

        // Save an installment
        async function saveVersement() {
            const form = document.getElementById('versementForm');
            const formData = new FormData(form);
            
            if (!form.checkValidity()) {
                showAlert('Veuillez remplir tous les champs obligatoires', 'error');
                return;
            }
            
            formData.append('action', 'ajouter_versement');
            formData.append('id_paiement', document.getElementById('modal_id_paiement').value);
            
            try {
                const result = await makeAjaxRequest(Object.fromEntries(formData));
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    closeModal('paymentModal');
                    // Re-run search to update results
                    document.getElementById('searchForm').dispatchEvent(new Event('submit'));
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'enregistrement du versement', 'error');
            }
        }

        // Close modals
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modals by clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Clear button
        document.getElementById('clearBtn').addEventListener('click', function() {
            document.getElementById('searchForm').reset();
            filiereSelect.dispatchEvent(new Event('change')); // Reset levels dropdown
            document.getElementById('resultsCard').style.display = 'none';
            currentResults = [];
        });

        // Export functionality (simulated)
        document.getElementById('exportBtn').addEventListener('click', function() {
            if (currentResults.length === 0) {
                showAlert('Aucune donnée à exporter', 'warning');
                return;
            }
            showAlert('Export en cours de développement', 'info');
            // Here you would implement actual export logic (CSV, PDF, Excel)
            // using libraries like jsPDF or SheetJS if needed.
        });

        // Sidebar responsive toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
            });
        }

        // Set today's date by default for installment date
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('date_versement').value = today;
            // Trigger change event on filiere_id to populate niveau_id initially
            filiereSelect.dispatchEvent(new Event('change'));
        });
    </script>
</body>
</html>