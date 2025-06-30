<?php
// resultats_evaluations.php
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

// === TRAITEMENT AJAX ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'obtenir_statistiques_niveau':
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $niveauId = intval($_POST['niveau_id'] ?? 0);
                
                if ($anneeId <= 0 || $niveauId <= 0) {
                    throw new Exception("Paramètres manquants");
                }
                
                // Statistiques générales du niveau
                $stmtStats = $pdo->prepare("
                    SELECT 
                        COUNT(DISTINCT e.num_etu) as total_etudiants,
                        COUNT(DISTINCT ev.fk_id_ECUE) as ecues_evaluees,
                        COUNT(ev.note) as total_notes,
                        AVG(ev.note) as moyenne_generale,
                        MIN(ev.note) as note_min,
                        MAX(ev.note) as note_max
                    FROM etudiant e
                    INNER JOIN inscrire i ON e.num_etu = i.fk_num_etu
                    LEFT JOIN evaluer ev ON e.num_etu = ev.fk_num_etu AND ev.fk_id_Ac = ?
                    WHERE i.fk_id_Ac = ? AND e.fk_id_niv_etu = ?
                ");
                $stmtStats->execute([$anneeId, $anneeId, $niveauId]);
                $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
                
                // Répartition des notes
                $stmtRepartition = $pdo->prepare("
                    SELECT 
                        CASE 
                            WHEN ev.note >= 16 THEN 'excellent'
                            WHEN ev.note >= 14 THEN 'bien'
                            WHEN ev.note >= 10 THEN 'passable'
                            ELSE 'insuffisant'
                        END as categorie,
                        COUNT(*) as nombre
                    FROM evaluer ev
                    INNER JOIN etudiant e ON ev.fk_num_etu = e.num_etu
                    WHERE ev.fk_id_Ac = ? AND e.fk_id_niv_etu = ?
                    GROUP BY categorie
                ");
                $stmtRepartition->execute([$anneeId, $niveauId]);
                $repartition = $stmtRepartition->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true, 
                    'stats' => $stats,
                    'repartition' => $repartition
                ]);
                break;
                
            case 'obtenir_resultats_detailles':
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $niveauId = intval($_POST['niveau_id'] ?? 0);
                $ecueId = intval($_POST['ecue_id'] ?? 0);
                
                if ($anneeId <= 0 || $niveauId <= 0) {
                    throw new Exception("Paramètres manquants");
                }
                
                $whereEcue = $ecueId > 0 ? "AND ev.fk_id_ECUE = ?" : "";
                $params = [$anneeId, $niveauId];
                if ($ecueId > 0) $params[] = $ecueId;
                
                $stmt = $pdo->prepare("
                    SELECT 
                        e.num_etu,
                        e.nom_etu,
                        e.prenoms_etu,
                        u.lib_UE,
                        ec.lib_ECUE,
                        ev.note,
                        ev.dte_eval,
                        CASE 
                            WHEN ev.note >= 16 THEN 'Excellent'
                            WHEN ev.note >= 14 THEN 'Bien'
                            WHEN ev.note >= 10 THEN 'Passable'
                            WHEN ev.note IS NOT NULL THEN 'Insuffisant'
                            ELSE 'Non évalué'
                        END as appreciation
                    FROM etudiant e
                    INNER JOIN inscrire i ON e.num_etu = i.fk_num_etu
                    LEFT JOIN evaluer ev ON e.num_etu = ev.fk_num_etu AND ev.fk_id_Ac = ?
                    LEFT JOIN ecue ec ON ev.fk_id_ECUE = ec.id_ECUE
                    LEFT JOIN ue u ON ec.fk_id_UE = u.id_UE
                    WHERE i.fk_id_Ac = ? AND e.fk_id_niv_etu = ? {$whereEcue}
                    ORDER BY e.nom_etu, e.prenoms_etu, u.lib_UE, ec.lib_ECUE
                ");
                $stmt->execute($params);
                $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $resultats]);
                break;
                
            case 'exporter_resultats':
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $niveauId = intval($_POST['niveau_id'] ?? 0);
                $format = $_POST['format'] ?? 'excel';
                
                // Logique d'export (à implémenter selon vos besoins)
                echo json_encode([
                    'success' => true, 
                    'message' => "Export {$format} en cours de préparation...",
                    'download_url' => "#" // URL du fichier généré
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
    <title>SYGECOS - Résultats d'évaluations</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Reprise des styles de base */
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

        /* Styles sidebar et topbar identiques */
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
        .btn-success { background-color: var(--success-500); color: white; }
        .btn-sm { padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }

        /* === DASHBOARD CARDS === */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-8); }
        .stat-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); text-align: center; }
        .stat-icon { font-size: var(--text-2xl); margin-bottom: var(--space-3); }
        .stat-value { font-size: var(--text-3xl); font-weight: 700; margin: var(--space-2) 0; }
        .stat-label { color: var(--gray-600); font-size: var(--text-base); }

        .stat-card.primary { border-left: 4px solid var(--accent-500); }
        .stat-card.primary .stat-icon { color: var(--accent-500); }
        .stat-card.primary .stat-value { color: var(--accent-600); }

        .stat-card.success { border-left: 4px solid var(--success-500); }
        .stat-card.success .stat-icon { color: var(--success-500); }
        .stat-card.success .stat-value { color: var(--success-500); }

        .stat-card.warning { border-left: 4px solid var(--warning-500); }
        .stat-card.warning .stat-icon { color: var(--warning-500); }
        .stat-card.warning .stat-value { color: var(--warning-500); }

        .stat-card.error { border-left: 4px solid var(--error-500); }
        .stat-card.error .stat-icon { color: var(--error-500); }
        .stat-card.error .stat-value { color: var(--error-500); }

        /* === GRAPHIQUES === */
        .chart-container { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-6); }
        .chart-title { font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-4); }
        .chart-placeholder { height: 300px; background: var(--gray-50); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: var(--gray-500); }

        /* === TABLEAU RESULTATS === */
        .results-table { width: 100%; border-collapse: collapse; margin-top: var(--space-4); }
        .results-table th, .results-table td { padding: var(--space-3); border-bottom: 1px solid var(--gray-200); text-align: left; }
        .results-table th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); font-size: var(--text-sm); }
        .results-table tbody tr:hover { background-color: var(--gray-50); }

        .appreciation-badge { padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm); font-size: var(--text-xs); font-weight: 600; text-align: center; }
        .appreciation-badge.excellent { background: var(--secondary-100); color: var(--secondary-800); }
        .appreciation-badge.bien { background: var(--accent-100); color: var(--accent-800); }
        .appreciation-badge.passable { background: #fef3c7; color: #92400e; }
        .appreciation-badge.insuffisant { background: #fecaca; color: #dc2626; }
        .appreciation-badge.non-evalue { background: var(--gray-100); color: var(--gray-600); }

        /* Messages d'alerte */
        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); display: none; }
        .alert.success { background-color: var(--secondary-50); color: var(--secondary-600); border: 1px solid var(--secondary-100); }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }

        .loading { opacity: 0.6; pointer-events: none; }
        .spinner { width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
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
                    <h1 class="page-title-main">Résultats d'évaluations</h1>
                    <p class="page-subtitle">Analyse et consultation des résultats académiques</p>
                </div>

                <!-- Message d'alerte -->
                <div id="alertMessage" class="alert"></div>

                <!-- Formulaire de sélection -->
                <div class="form-card">
                    <h3 class="form-card-title">Sélection des critères</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="annee_id">Année académique</label>
                            <input type="text" id="annee_display" value="<?php echo htmlspecialchars($anneeActive['annee_libelle'] ?? 'Non définie'); ?>" disabled>
                            <input type="hidden" id="annee_id" value="<?php echo $anneeActive['id_Ac'] ?? ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="niveau_id">Niveau d'étude <span style="color: var(--error-500);">*</span></label>
                            <select id="niveau_id" name="niveau_id" required>
                                <option value="">Sélectionner un niveau</option>
                                <?php foreach ($niveauxEtude as $niveau): ?>
                                    <option value="<?php echo $niveau['id_niv_etu']; ?>">
                                        <?php echo htmlspecialchars($niveau['lib_niv_etu']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="vue_type">Type de vue</label>
                            <select id="vue_type" name="vue_type">
                                <option value="synthese">Vue synthèse</option>
                                <option value="detaillee">Vue détaillée</option>
                                <option value="statistiques">Statistiques avancées</option>
                            </select>
                        </div>
                        <div class="form-group" style="display: flex; align-items: end;">
                            <button type="button" class="btn btn-primary" id="analyserBtn">
                                <i class="fas fa-chart-bar"></i> Analyser
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Dashboard statistiques -->
                <div id="dashboardSection" style="display: none;">
                    <div class="stats-grid">
                        <div class="stat-card primary">
                            <div class="stat-icon"><i class="fas fa-users"></i></div>
                            <div class="stat-value" id="totalEtudiants">0</div>
                            <div class="stat-label">Étudiants inscrits</div>
                        </div>
                        <div class="stat-card success">
                            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                            <div class="stat-value" id="moyenneGenerale">0.00</div>
                            <div class="stat-label">Moyenne générale</div>
                        </div>
                        <div class="stat-card warning">
                            <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
                            <div class="stat-value" id="totalNotes">0</div>
                            <div class="stat-label">Notes saisies</div>
                        </div>
                        <div class="stat-card error">
                            <div class="stat-icon"><i class="fas fa-book"></i></div>
                            <div class="stat-value" id="ecuesEvaluees">0</div>
                            <div class="stat-label">ECUE évaluées</div>
                        </div>
                    </div>

                    <!-- Graphique répartition -->
                    <div class="chart-container">
                        <h3 class="chart-title">Répartition des appréciations</h3>
                        <div class="chart-placeholder" id="chartRepartition">
                            <i class="fas fa-chart-pie" style="font-size: 3rem; color: var(--gray-400);"></i>
                            <span style="margin-left: var(--space-4);">Graphique de répartition des notes</span>
                        </div>
                    </div>
                </div>

                <!-- Résultats détaillés -->
                <div class="form-card" id="resultsSection" style="display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-6);">
                        <h3>Résultats détaillés</h3>
                        <div>
                            <button class="btn btn-success btn-sm" id="exportExcelBtn">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <button class="btn btn-secondary btn-sm" id="exportPdfBtn">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </button>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="results-table" id="resultsTable">
                            <thead>
                                <tr>
                                    <th>Matricule</th>
                                    <th>Nom</th>
                                    <th>Prénom</th>
                                    <th>UE</th>
                                    <th>ECUE</th>
                                    <th>Note</th>
                                    <th>Date évaluation</th>
                                    <th>Appréciation</th>
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

    <script>
        // Variables globales
        let currentAnneeId = 0;
        let currentNiveauId = 0;

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

        // Analyser les résultats
        document.getElementById('analyserBtn').addEventListener('click', async function() {
            const anneeId = document.getElementById('annee_id').value;
            const niveauId = document.getElementById('niveau_id').value;
            const vueType = document.getElementById('vue_type').value;
            
            if (!anneeId || !niveauId) {
                showAlert('Veuillez sélectionner une année et un niveau', 'error');
                return;
            }
            
            currentAnneeId = anneeId;
            currentNiveauId = niveauId;
            
            const btn = this;
            const originalText = btn.innerHTML;
            
            try {
                btn.innerHTML = '<div class="spinner"></div> Analyse...';
                btn.disabled = true;
                
                // Charger les statistiques
                await chargerStatistiques(anneeId, niveauId);
                
                if (vueType === 'detaillee') {
                    await chargerResultatsDetailles(anneeId, niveauId);
                }
                
            } catch (error) {
                showAlert('Erreur lors de l\'analyse', 'error');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        });

        // Charger les statistiques
        async function chargerStatistiques(anneeId, niveauId) {
            try {
                const result = await makeAjaxRequest({
                    action: 'obtenir_statistiques_niveau',
                    annee_id: anneeId,
                    niveau_id: niveauId
                });
                
                if (result.success) {
                    const stats = result.stats;
                    
                    document.getElementById('totalEtudiants').textContent = stats.total_etudiants || '0';
                    document.getElementById('moyenneGenerale').textContent = stats.moyenne_generale ? parseFloat(stats.moyenne_generale).toFixed(2) : '0.00';
                    document.getElementById('totalNotes').textContent = stats.total_notes || '0';
                    document.getElementById('ecuesEvaluees').textContent = stats.ecues_evaluees || '0';
                    
                    // Afficher le dashboard
                    document.getElementById('dashboardSection').style.display = 'block';
                    
                    // Afficher la répartition (simulation)
                    if (result.repartition && result.repartition.length > 0) {
                        afficherRepartition(result.repartition);
                    }
                    
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des statistiques', 'error');
            }
        }

        // Afficher la répartition (version simplifiée)
        function afficherRepartition(repartition) {
            const container = document.getElementById('chartRepartition');
            let total = 0;
            repartition.forEach(item => total += parseInt(item.nombre));
            
            let html = '<div style="display: flex; justify-content: space-around; align-items: center; height: 100%;">';
            
            repartition.forEach(item => {
                const pourcentage = total > 0 ? ((item.nombre / total) * 100).toFixed(1) : 0;
                const couleur = {
                    'excellent': 'var(--success-500)',
                    'bien': 'var(--accent-500)', 
                    'passable': 'var(--warning-500)',
                    'insuffisant': 'var(--error-500)'
                }[item.categorie] || 'var(--gray-500)';
                
                html += `
                    <div style="text-align: center;">
                        <div style="width: 80px; height: 80px; border-radius: 50%; background: ${couleur}; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.2rem; margin: 0 auto var(--space-2);">
                            ${item.nombre}
                        </div>
                        <div style="font-weight: 600; text-transform: capitalize;">${item.categorie}</div>
                        <div style="color: var(--gray-600); font-size: var(--text-sm);">${pourcentage}%</div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }

        // Charger les résultats détaillés
        async function chargerResultatsDetailles(anneeId, niveauId) {
            try {
                const result = await makeAjaxRequest({
                    action: 'obtenir_resultats_detailles',
                    annee_id: anneeId,
                    niveau_id: niveauId
                });
                
                if (result.success) {
                    afficherTableauResultats(result.data);
                    document.getElementById('resultsSection').style.display = 'block';
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des résultats détaillés', 'error');
            }
        }

        // Afficher le tableau des résultats
        function afficherTableauResultats(resultats) {
            const tbody = document.querySelector('#resultsTable tbody');
            tbody.innerHTML = '';
            
            resultats.forEach(resultat => {
                const row = document.createElement('tr');
                const appreciationClass = resultat.appreciation.toLowerCase().replace(' ', '-');
                
                row.innerHTML = `
                    <td><strong>${resultat.num_etu}</strong></td>
                    <td>${resultat.nom_etu}</td>
                    <td>${resultat.prenoms_etu}</td>
                    <td>${resultat.lib_UE || '-'}</td>
                    <td>${resultat.lib_ECUE || '-'}</td>
                    <td>${resultat.note ? parseFloat(resultat.note).toFixed(2) : '-'}</td>
                    <td>${resultat.dte_eval ? new Date(resultat.dte_eval).toLocaleDateString('fr-FR') : '-'}</td>
                    <td><span class="appreciation-badge ${appreciationClass}">${resultat.appreciation}</span></td>
                `;
                tbody.appendChild(row);
            });
        }

        // Export fonctions
        document.getElementById('exportExcelBtn').addEventListener('click', function() {
            if (!currentAnneeId || !currentNiveauId) {
                showAlert('Veuillez d\'abord effectuer une analyse', 'warning');
                return;
            }
            
            makeAjaxRequest({
                action: 'exporter_resultats',
                annee_id: currentAnneeId,
                niveau_id: currentNiveauId,
                format: 'excel'
            }).then(result => {
                if (result.success) {
                    showAlert(result.message, 'success');
                } else {
                    showAlert(result.message, 'error');
                }
            });
        });

        document.getElementById('exportPdfBtn').addEventListener('click', function() {
            if (!currentAnneeId || !currentNiveauId) {
                showAlert('Veuillez d\'abord effectuer une analyse', 'warning');
                return;
            }
            
            makeAjaxRequest({
                action: 'exporter_resultats',
                annee_id: currentAnneeId,
                niveau_id: currentNiveauId,
                format: 'pdf'
            }).then(result => {
                if (result.success) {
                    showAlert(result.message, 'success');
                } else {
                    showAlert(result.message, 'error');
                }
            });
        });

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