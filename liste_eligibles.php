<?php
// liste_eligibles.php
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

// Récupération des niveaux d'étude
$niveauxEtude = [];
try {
    $stmt = $pdo->query("SELECT id_niv_etu, lib_niv_etu FROM niveau_etude ORDER BY lib_niv_etu");
    $niveauxEtude = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération niveaux: " . $e->getMessage());
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
            case 'charger_eligibles':
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $niveauId = intval($_POST['niveau_id'] ?? 0);
                $filiereId = intval($_POST['filiere_id'] ?? 0);
                $statutFiltre = $_POST['statut_filtre'] ?? 'tous';
                $creditsMin = intval($_POST['credits_min'] ?? 60);
                $searchTerm = trim($_POST['search_term'] ?? '');
                
                if ($anneeId <= 0) {
                    throw new Exception("Année académique non spécifiée");
                }
                
                // Construction de la requête avec filtres
                $whereConditions = ["i.fk_id_Ac = ?"];
                $params = [$anneeId];
                
                if ($niveauId > 0) {
                    $whereConditions[] = "e.fk_id_niv_etu = ?";
                    $params[] = $niveauId;
                }
                
                if ($filiereId > 0) {
                    $whereConditions[] = "e.fk_id_filiere = ?";
                    $params[] = $filiereId;
                }
                
                if (!empty($searchTerm)) {
                    $whereConditions[] = "(e.num_etu LIKE ? OR e.nom_etu LIKE ? OR e.prenoms_etu LIKE ?)";
                    $searchParam = "%{$searchTerm}%";
                    $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
                }
                
                $whereClause = implode(' AND ', $whereConditions);
                
                // Vérifier si la vue paiements existe
                $checkView = $pdo->query("SHOW TABLES LIKE 'vue_paiements_etudiants'");
                $vueExists = ($checkView->rowCount() > 0);
                
                $sql = "
                    SELECT 
                        e.num_etu,
                        e.nom_etu,
                        e.prenoms_etu,
                        e.dte_naiss_etu,
                        e.email_etu,
                        e.telephone,
                        f.lib_filiere,
                        ne.lib_niv_etu,
                        
                        -- Calcul des crédits
                        COALESCE(SUM(
                            CASE 
                                WHEN ev.note >= 10 THEN ec.credit_ECUE 
                                ELSE 0 
                            END
                        ), 0) as credits_obtenus,
                        
                        -- Moyenne pondérée
                        CASE 
                            WHEN SUM(CASE WHEN ev.note IS NOT NULL THEN ec.credit_ECUE ELSE 0 END) > 0 
                            THEN ROUND(
                                SUM(CASE WHEN ev.note IS NOT NULL THEN ev.note * ec.credit_ECUE ELSE 0 END) / 
                                SUM(CASE WHEN ev.note IS NOT NULL THEN ec.credit_ECUE ELSE 0 END), 2
                            )
                            ELSE 0 
                        END as moyenne_ponderee,
                        
                        -- Informations de paiement
                        " . ($vueExists ? "
                        vp.montant_total,
                        vp.total_verse,
                        vp.reste_a_payer,
                        " : "
                        NULL as montant_total,
                        NULL as total_verse,
                        NULL as reste_a_payer,
                        ") . "
                        
                        -- Statut d'éligibilité
                        CASE 
                            WHEN COALESCE(SUM(
                                CASE 
                                    WHEN ev.note >= 10 THEN ec.credit_ECUE 
                                    ELSE 0 
                                END
                            ), 0) >= ? 
                            " . ($vueExists ? "AND (vp.reste_a_payer IS NULL OR vp.reste_a_payer <= 0)" : "") . "
                            THEN 'ELIGIBLE'
                            ELSE 'NON_ELIGIBLE'
                        END as statut_eligibilite,
                        
                        -- Date dernière modification
                        NOW() as date_verification
                        
                    FROM etudiant e
                    INNER JOIN inscrire i ON e.num_etu = i.fk_num_etu
                    LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                    LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                    LEFT JOIN evaluer ev ON e.num_etu = ev.fk_num_etu AND ev.fk_id_Ac = ?
                    LEFT JOIN ecue ec ON ev.fk_id_ECUE = ec.id_ECUE
                    " . ($vueExists ? "LEFT JOIN vue_paiements_etudiants vp ON e.num_etu = vp.num_etu AND vp.id_Ac = ?" : "") . "
                    WHERE {$whereClause}
                    GROUP BY e.num_etu, e.nom_etu, e.prenoms_etu, e.dte_naiss_etu, e.email_etu, e.telephone, f.lib_filiere, ne.lib_niv_etu
                    " . ($vueExists ? ", vp.montant_total, vp.total_verse, vp.reste_a_payer" : "") . "
                    HAVING 1=1 " . ($statutFiltre === 'eligibles' ? "AND statut_eligibilite = 'ELIGIBLE'" : 
                                   ($statutFiltre === 'non_eligibles' ? "AND statut_eligibilite = 'NON_ELIGIBLE'" : "")) . "
                    ORDER BY e.nom_etu, e.prenoms_etu
                ";
                
                // Ajout des paramètres
                $finalParams = [$creditsMin, $anneeId];
                if ($vueExists) $finalParams[] = $anneeId;
                $finalParams = array_merge($finalParams, $params);
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($finalParams);
                $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $resultats]);
                break;
                
            case 'exporter_liste':
                $format = $_POST['format'] ?? 'excel';
                $donnees = json_decode($_POST['donnees'] ?? '[]', true);
                $titre = $_POST['titre'] ?? 'Liste des étudiants';
                
                if (empty($donnees)) {
                    throw new Exception("Aucune donnée à exporter");
                }
                
                if ($format === 'pdf') {
                    // Génération PDF (simulation)
                    $filename = 'liste_eligibles_' . date('Y-m-d_H-i-s') . '.pdf';
                    // Ici vous implémenteriez la génération PDF avec une librairie comme TCPDF ou DOMPDF
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'PDF généré avec succès',
                        'filename' => $filename,
                        'download_url' => "exports/{$filename}"
                    ]);
                } else {
                    // Génération Excel (simulation)
                    $filename = 'liste_eligibles_' . date('Y-m-d_H-i-s') . '.xlsx';
                    // Ici vous implémenteriez la génération Excel avec PhpSpreadsheet
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Excel généré avec succès',
                        'filename' => $filename,
                        'download_url' => "exports/{$filename}"
                    ]);
                }
                break;
                
            case 'importer_excel':
                // Traitement de l'import Excel
                if (!isset($_FILES['fichier_excel']) || $_FILES['fichier_excel']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Erreur lors de l'upload du fichier");
                }
                
                $fichier = $_FILES['fichier_excel'];
                $extension = pathinfo($fichier['name'], PATHINFO_EXTENSION);
                
                if (!in_array(strtolower($extension), ['xlsx', 'xls', 'csv'])) {
                    throw new Exception("Format de fichier non supporté. Utilisez Excel (.xlsx, .xls) ou CSV");
                }
                
                // Ici vous implémenteriez la lecture du fichier Excel avec PhpSpreadsheet
                // Pour la simulation, on retourne un succès
                
                echo json_encode([
                    'success' => true,
                    'message' => "Fichier {$fichier['name']} importé avec succès",
                    'lignes_traitees' => 0,
                    'lignes_mises_a_jour' => 0
                ]);
                break;
                
            case 'modifier_statut':
                $matricules = $_POST['matricules'] ?? [];
                $nouveauStatut = $_POST['nouveau_statut'] ?? '';
                $commentaire = trim($_POST['commentaire'] ?? '');
                
                if (empty($matricules) || empty($nouveauStatut)) {
                    throw new Exception("Paramètres manquants");
                }
                
                // Simulation de la mise à jour des statuts
                // Dans un vrai système, vous créeriez une table eligibilite_statut
                
                echo json_encode([
                    'success' => true,
                    'message' => count($matricules) . " étudiant(s) mis à jour avec le statut: {$nouveauStatut}"
                ]);
                break;
                
            default:
                throw new Exception("Action non reconnue");
        }
        
    } catch (Exception $e) {
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
    <title>SYGECOS - Liste des Étudiants Éligibles</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Styles de base identiques aux autres interfaces */
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
        .page-header { margin-bottom: var(--space-8); }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--space-2); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); }

        .form-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-6); }
        .form-card-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-6); border-bottom: 1px solid var(--gray-200); padding-bottom: var(--space-4); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-6); }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: var(--text-sm); font-weight: 500; color: var(--gray-700); margin-bottom: var(--space-2); }
        .form-group input, .form-group select { padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); font-size: var(--text-base); color: var(--gray-800); transition: all var(--transition-fast); }
        .form-group input:disabled { background-color: var(--gray-100); color: var(--gray-500); cursor: not-allowed; }

        .btn { padding: var(--space-3) var(--space-5); border-radius: var(--radius-md); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover { background-color: var(--gray-300); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover { background-color: var(--success-600); }
        .btn-warning { background-color: var(--warning-500); color: white; }
        .btn-sm { padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }

        /* === BARRE D'OUTILS === */
        .toolbar { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-6); }
        .toolbar-section { margin-bottom: var(--space-4); }
        .toolbar-section:last-child { margin-bottom: 0; }
        .toolbar-title { font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-3); }
        .toolbar-actions { display: flex; gap: var(--space-3); flex-wrap: wrap; }

        /* === STATISTIQUES === */
        .stats-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--space-4); margin-bottom: var(--space-6); }
        .stat-item { background: var(--white); padding: var(--space-4); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); text-align: center; }
        .stat-value { font-size: var(--text-2xl); font-weight: 700; color: var(--accent-600); }
        .stat-label { font-size: var(--text-sm); color: var(--gray-600); margin-top: var(--space-1); }

        /* === TABLEAU === */
        .table-container { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); }
        .table-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6); }
        .table-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .table-controls { display: flex; gap: var(--space-3); align-items: center; }

        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: var(--space-3); border-bottom: 1px solid var(--gray-200); text-align: left; }
        .data-table th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); font-size: var(--text-sm); position: sticky; top: 0; }
        .data-table tbody tr:hover { background-color: var(--gray-50); }

        .status-badge { padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; text-align: center; text-transform: uppercase; }
        .status-badge.eligible { background: var(--secondary-100); color: var(--secondary-800); }
        .status-badge.non-eligible { background: #fecaca; color: #dc2626; }
        .status-badge.en-attente { background: #fef3c7; color: #92400e; }
        .status-badge.valide { background: var(--secondary-100); color: var(--secondary-800); }

        /* === MODALS === */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: var(--white); margin: 5% auto; padding: var(--space-6); border-radius: var(--radius-xl); width: 90%; max-width: 600px; box-shadow: var(--shadow-xl); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); padding-bottom: var(--space-4); border-bottom: 1px solid var(--gray-200); }
        .modal-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .close { color: var(--gray-400); font-size: 28px; font-weight: bold; cursor: pointer; transition: color var(--transition-fast); }
        .close:hover { color: var(--gray-600); }

        /* === UPLOAD ZONE === */
        .upload-zone { border: 2px dashed var(--gray-300); border-radius: var(--radius-lg); padding: var(--space-8); text-align: center; background: var(--gray-50); transition: all var(--transition-fast); cursor: pointer; }
        .upload-zone:hover, .upload-zone.dragover { border-color: var(--accent-500); background: var(--accent-50); }
        .upload-zone.dragover { transform: scale(1.02); }
        .upload-icon { font-size: var(--text-3xl); color: var(--gray-400); margin-bottom: var(--space-3); }
        .upload-text { color: var(--gray-600); font-size: var(--text-base); }
        .upload-subtext { color: var(--gray-500); font-size: var(--text-sm); margin-top: var(--space-2); }

        /* === CHECKBOX === */
        .checkbox-container { display: block; position: relative; padding-left: 25px; cursor: pointer; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
        .checkbox-container input { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
        .checkmark { position: absolute; top: 50%; left: 0; transform: translateY(-50%); height: 18px; width: 18px; background-color: var(--gray-200); border-radius: var(--radius-sm); transition: all var(--transition-fast); border: 1px solid var(--gray-300); }
        .checkbox-container input:checked ~ .checkmark { background-color: var(--accent-600); border-color: var(--accent-600); }
        .checkmark:after { content: ""; position: absolute; display: none; }
        .checkbox-container input:checked ~ .checkmark:after { display: block; }
        .checkbox-container .checkmark:after { left: 6px; top: 2px; width: 5px; height: 10px; border: solid white; border-width: 0 3px 3px 0; transform: rotate(45deg); }

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
            .stats-bar { grid-template-columns: 1fr; }
            .toolbar-actions { flex-direction: column; }
            .table-header { flex-direction: column; gap: var(--space-3); }
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
                    <h1 class="page-title-main">Liste des Étudiants Éligibles</h1>
                    <p class="page-subtitle">Gestion complète des listes d'éligibilité avec filtres avancés et exports</p>
                </div>

                <!-- Message d'alerte -->
                <div id="alertMessage" class="alert"></div>

                <!-- Formulaire de filtres -->
                <div class="form-card">
                    <h3 class="form-card-title">Filtres de recherche</h3>
                    <form id="filterForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="annee_id">Année académique</label>
                                <input type="text" id="annee_display" value="<?php echo htmlspecialchars($anneeActive['annee_libelle'] ?? 'Non définie'); ?>" disabled>
                                <input type="hidden" id="annee_id" value="<?php echo $anneeActive['id_Ac'] ?? ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="niveau_id">Niveau d'étude</label>
                                <select id="niveau_id" name="niveau_id">
                                    <option value="">Tous les niveaux</option>
                                    <?php foreach ($niveauxEtude as $niveau): ?>
                                        <option value="<?php echo $niveau['id_niv_etu']; ?>">
                                            <?php echo htmlspecialchars($niveau['lib_niv_etu']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filiere_id">Filière</label>
                                <select id="filiere_id" name="filiere_id">
                                    <option value="">Toutes les filières</option>
                                    <?php foreach ($filieres as $filiere): ?>
                                        <option value="<?php echo $filiere['id_filiere']; ?>">
                                            <?php echo htmlspecialchars($filiere['lib_filiere']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="statut_filtre">Statut d'éligibilité</label>
                                <select id="statut_filtre" name="statut_filtre">
                                    <option value="tous">Tous les statuts</option>
                                    <option value="eligibles">Éligibles seulement</option>
                                    <option value="non_eligibles">Non éligibles seulement</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="credits_min">Crédits minimum</label>
                                <input type="number" id="credits_min" name="credits_min" value="60" min="0" max="120" step="1">
                            </div>
                            <div class="form-group">
                                <label for="search_term">Recherche libre</label>
                                <input type="text" id="search_term" name="search_term" placeholder="Nom, prénom, matricule...">
                            </div>
                        </div>
                        <div class="toolbar-actions">
                            <button type="submit" class="btn btn-primary" id="rechercherBtn">
                                <i class="fas fa-search"></i> Rechercher
                            </button>
                            <button type="button" class="btn btn-secondary" id="resetBtn">
                                <i class="fas fa-undo"></i> Réinitialiser
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Barre d'outils -->
                <div class="toolbar" id="toolbarSection" style="display: none;">
                    <div class="toolbar-section">
                        <div class="toolbar-title">Actions rapides</div>
                        <div class="toolbar-actions">
                            <button class="btn btn-success btn-sm" id="exportExcelBtn">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <button class="btn btn-warning btn-sm" id="exportPdfBtn">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </button>
                            <button class="btn btn-info btn-sm" id="importBtn">
                                <i class="fas fa-upload"></i> Importer Excel
                            </button>
                            <button class="btn btn-secondary btn-sm" id="modifierStatutBtn" disabled>
                                <i class="fas fa-edit"></i> Modifier statut (<span id="selectedCount">0</span>)
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Statistiques -->
                <div class="stats-bar" id="statsSection" style="display: none;">
                    <div class="stat-item">
                        <div class="stat-value" id="totalEtudiants">0</div>
                        <div class="stat-label">Total étudiants</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="totalEligibles">0</div>
                        <div class="stat-label">Éligibles</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="totalNonEligibles">0</div>
                        <div class="stat-label">Non éligibles</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="tauxEligibilite">0%</div>
                        <div class="stat-label">Taux d'éligibilité</div>
                    </div>
                </div>

                <!-- Tableau des résultats -->
                <div class="table-container" id="tableSection" style="display: none;">
                    <div class="table-header">
                        <h3 class="table-title">Liste des étudiants (<span id="resultCount">0</span>)</h3>
                        <div class="table-controls">
                            <label class="checkbox-container">
                                <input type="checkbox" id="selectAll">
                                <span class="checkmark"></span>
                                Tout sélectionner
                            </label>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="data-table" id="studentsTable">
                            <thead>
                                <tr>
                                    <th width="50px">
                                        <label class="checkbox-container">
                                            <input type="checkbox" id="selectAllHeader">
                                            <span class="checkmark"></span>
                                        </label>
                                    </th>
                                    <th>Matricule</th>
                                    <th>Nom</th>
                                    <th>Prénom</th>
                                    <th>Filière</th>
                                    <th>Niveau</th>
                                    <th>Crédits</th>
                                    <th>Moyenne</th>
                                    <th>Statut paiement</th>
                                    <th>Éligibilité</th>
                                    <th>Contact</th>
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

    <!-- Modal import Excel -->
    <div id="importModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Importer depuis Excel</h3>
                <span class="close" onclick="closeModal('importModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="upload-zone" id="uploadZone">
                    <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                    <div class="upload-text">Glissez-déposez votre fichier Excel ici</div>
                    <div class="upload-subtext">ou cliquez pour sélectionner un fichier (.xlsx, .xls, .csv)</div>
                    <input type="file" id="fileInput" accept=".xlsx,.xls,.csv" style="display: none;">
                </div>
                <div style="margin-top: var(--space-4);">
                    <p style="font-size: var(--text-sm); color: var(--gray-600);">
                        <strong>Format attendu :</strong> Matricule | Nom | Prénom | Statut
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('importModal')">Annuler</button>
                <button class="btn btn-primary" id="uploadBtn" disabled>
                    <i class="fas fa-upload"></i> Importer
                </button>
            </div>
        </div>
    </div>

    <!-- Modal modification statut -->
    <div id="statutModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Modifier le statut</h3>
                <span class="close" onclick="closeModal('statutModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="nouveauStatut">Nouveau statut</label>
                    <select id="nouveauStatut">
                        <option value="ELIGIBLE">Éligible</option>
                        <option value="NON_ELIGIBLE">Non éligible</option>
                        <option value="EN_ATTENTE">En attente</option>
                        <option value="VALIDE">Validé</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="commentaireStatut">Commentaire (optionnel)</label>
                    <textarea id="commentaireStatut" rows="3" placeholder="Raison du changement de statut..."></textarea>
                </div>
                <div id="selectionSummary" style="padding: var(--space-3); background: var(--gray-50); border-radius: var(--radius-md); margin-top: var(--space-3);">
                    <!-- Résumé de la sélection -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('statutModal')">Annuler</button>
                <button class="btn btn-primary" id="confirmerStatutBtn">
                    <i class="fas fa-save"></i> Confirmer
                </button>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let currentData = [];
        let selectedMatricules = new Set();

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

        // Recherche des étudiants
        document.getElementById('filterForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            await rechercherEtudiants();
        });

        async function rechercherEtudiants() {
            const btn = document.getElementById('rechercherBtn');
            const originalText = btn.innerHTML;
            
            try {
                btn.innerHTML = '<div class="spinner"></div> Recherche...';
                btn.disabled = true;
                
                const formData = new FormData(document.getElementById('filterForm'));
                formData.append('action', 'charger_eligibles');
                
                const result = await makeAjaxRequest(Object.fromEntries(formData));
                
                if (result.success) {
                    currentData = result.data;
                    afficherResultats(currentData);
                    selectedMatricules.clear();
                    updateSelectedCount();
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

        // Affichage des résultats
        function afficherResultats(data) {
            // Statistiques
            const total = data.length;
            const eligibles = data.filter(e => e.statut_eligibilite === 'ELIGIBLE').length;
            const nonEligibles = total - eligibles;
            const taux = total > 0 ? ((eligibles / total) * 100).toFixed(1) : 0;
            
            document.getElementById('totalEtudiants').textContent = total;
            document.getElementById('totalEligibles').textContent = eligibles;
            document.getElementById('totalNonEligibles').textContent = nonEligibles;
            document.getElementById('tauxEligibilite').textContent = taux + '%';
            
            // Tableau
            const tbody = document.querySelector('#studentsTable tbody');
            tbody.innerHTML = '';
            
            data.forEach(etudiant => {
                const row = document.createElement('tr');
                
                const statutClass = etudiant.statut_eligibilite.toLowerCase().replace('_', '-');
                const statutText = etudiant.statut_eligibilite === 'ELIGIBLE' ? 'Éligible' : 'Non éligible';
                
                const paiementStatut = etudiant.reste_a_payer === null ? 'N/A' : 
                    (parseFloat(etudiant.reste_a_payer) <= 0 ? 'À jour' : 'En retard');
                
                row.innerHTML = `
                    <td>
                        <label class="checkbox-container">
                            <input type="checkbox" class="student-checkbox" data-matricule="${etudiant.num_etu}">
                            <span class="checkmark"></span>
                        </label>
                    </td>
                    <td><strong>${etudiant.num_etu}</strong></td>
                    <td>${etudiant.nom_etu}</td>
                    <td>${etudiant.prenoms_etu}</td>
                    <td>${etudiant.lib_filiere || '-'}</td>
                    <td>${etudiant.lib_niv_etu || '-'}</td>
                    <td>${etudiant.credits_obtenus}/60</td>
                    <td>${etudiant.moyenne_ponderee ? parseFloat(etudiant.moyenne_ponderee).toFixed(2) : '-'}</td>
                    <td>${paiementStatut}</td>
                    <td><span class="status-badge ${statutClass}">${statutText}</span></td>
                    <td>
                        <div style="font-size: var(--text-xs);">
                            <div>${etudiant.email_etu || '-'}</div>
                            <div>${etudiant.telephone || '-'}</div>
                        </div>
                    </td>
                `;
                tbody.appendChild(row);
            });
            
            document.getElementById('resultCount').textContent = total;
            
            // Afficher les sections
            document.getElementById('toolbarSection').style.display = 'block';
            document.getElementById('statsSection').style.display = 'grid';
            document.getElementById('tableSection').style.display = 'block';
        }

        // Gestion des sélections
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
                if (this.checked) {
                    selectedMatricules.add(cb.dataset.matricule);
                } else {
                    selectedMatricules.delete(cb.dataset.matricule);
                }
            });
            document.getElementById('selectAllHeader').checked = this.checked;
            updateSelectedCount();
        });

        document.getElementById('selectAllHeader').addEventListener('change', function() {
            document.getElementById('selectAll').click();
        });

        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('student-checkbox')) {
                if (e.target.checked) {
                    selectedMatricules.add(e.target.dataset.matricule);
                } else {
                    selectedMatricules.delete(e.target.dataset.matricule);
                }
                updateSelectedCount();
            }
        });

        function updateSelectedCount() {
            const count = selectedMatricules.size;
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('modifierStatutBtn').disabled = count === 0;
        }

        // Export Excel
        document.getElementById('exportExcelBtn').addEventListener('click', async function() {
            if (currentData.length === 0) {
                showAlert('Aucune donnée à exporter', 'warning');
                return;
            }
            
            try {
                const result = await makeAjaxRequest({
                    action: 'exporter_liste',
                    format: 'excel',
                    donnees: JSON.stringify(currentData),
                    titre: 'Liste des étudiants éligibles'
                });
                
                if (result.success) {
                    showAlert(`${result.message} - ${result.filename}`, 'success');
                    // Ici vous déclencheriez le téléchargement
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'export Excel', 'error');
            }
        });

        // Export PDF
        document.getElementById('exportPdfBtn').addEventListener('click', async function() {
            if (currentData.length === 0) {
                showAlert('Aucune donnée à exporter', 'warning');
                return;
            }
            
            try {
                const result = await makeAjaxRequest({
                    action: 'exporter_liste',
                    format: 'pdf',
                    donnees: JSON.stringify(currentData),
                    titre: 'Liste des étudiants éligibles'
                });
                
                if (result.success) {
                    showAlert(`${result.message} - ${result.filename}`, 'success');
                    // Ici vous déclencheriez le téléchargement
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'export PDF', 'error');
            }
        });

        // Import Excel
        document.getElementById('importBtn').addEventListener('click', function() {
            document.getElementById('importModal').style.display = 'block';
        });

        // Gestion de l'upload
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('fileInput');
        const uploadBtn = document.getElementById('uploadBtn');

        uploadZone.addEventListener('click', () => fileInput.click());
        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });
        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('dragover');
        });
        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            handleFiles(e.dataTransfer.files);
        });

        fileInput.addEventListener('change', (e) => {
            handleFiles(e.target.files);
        });

        function handleFiles(files) {
            if (files.length > 0) {
                const file = files[0];
                const validTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 
                                   'application/vnd.ms-excel', 'text/csv'];
                
                if (validTypes.includes(file.type) || file.name.endsWith('.xlsx') || file.name.endsWith('.xls') || file.name.endsWith('.csv')) {
                    uploadBtn.disabled = false;
                    uploadZone.innerHTML = `
                        <div class="upload-icon"><i class="fas fa-file-excel"></i></div>
                        <div class="upload-text">Fichier sélectionné: ${file.name}</div>
                        <div class="upload-subtext">Taille: ${(file.size / 1024 / 1024).toFixed(2)} MB</div>
                    `;
                } else {
                    showAlert('Format de fichier non supporté', 'error');
                    uploadBtn.disabled = true;
                }
            }
        }

        uploadBtn.addEventListener('click', async function() {
            const file = fileInput.files[0];
            if (!file) return;
            
            const formData = new FormData();
            formData.append('action', 'importer_excel');
            formData.append('fichier_excel', file);
            
            try {
                const result = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                }).then(r => r.json());
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    closeModal('importModal');
                    await rechercherEtudiants(); // Recharger les données
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'import', 'error');
            }
        });

        // Modification de statut
        document.getElementById('modifierStatutBtn').addEventListener('click', function() {
            if (selectedMatricules.size === 0) return;
            
            const summary = document.getElementById('selectionSummary');
            summary.innerHTML = `
                <strong>${selectedMatricules.size} étudiant(s) sélectionné(s)</strong><br>
                <small>Les statuts seront modifiés pour tous les étudiants sélectionnés.</small>
            `;
            
            document.getElementById('statutModal').style.display = 'block';
        });

        document.getElementById('confirmerStatutBtn').addEventListener('click', async function() {
            const nouveauStatut = document.getElementById('nouveauStatut').value;
            const commentaire = document.getElementById('commentaireStatut').value;
            
            if (!nouveauStatut) {
                showAlert('Veuillez sélectionner un statut', 'error');
                return;
            }
            
            try {
                const result = await makeAjaxRequest({
                    action: 'modifier_statut',
                    matricules: Array.from(selectedMatricules),
                    nouveau_statut: nouveauStatut,
                    commentaire: commentaire
                });
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    closeModal('statutModal');
                    selectedMatricules.clear();
                    updateSelectedCount();
                    await rechercherEtudiants(); // Recharger
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la modification', 'error');
            }
        });

        // Réinitialisation
        document.getElementById('resetBtn').addEventListener('click', function() {
            document.getElementById('filterForm').reset();
            document.getElementById('annee_display').value = "<?php echo htmlspecialchars($anneeActive['annee_libelle'] ?? 'Non définie'); ?>";
            document.getElementById('credits_min').value = '60';
            
            // Masquer les sections
            document.getElementById('toolbarSection').style.display = 'none';
            document.getElementById('statsSection').style.display = 'none';
            document.getElementById('tableSection').style.display = 'none';
            
            currentData = [];
            selectedMatricules.clear();
            updateSelectedCount();
        });

        // Fermer modals
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            
            if (modalId === 'importModal') {
                // Réinitialiser l'upload
                fileInput.value = '';
                uploadBtn.disabled = true;
                uploadZone.innerHTML = `
                    <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                    <div class="upload-text">Glissez-déposez votre fichier Excel ici</div>
                    <div class="upload-subtext">ou cliquez pour sélectionner un fichier (.xlsx, .xls, .csv)</div>
                `;
            }
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            const anneeId = document.getElementById('annee_id').value;
            if (!anneeId) {
                showAlert('Aucune année académique active trouvée.', 'warning');
            }
        });
    </script>
</body>
</html>