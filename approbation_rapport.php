<?php
// approbation_rapport.php
session_start(); // Assurez-vous que la session est démarrée
require_once 'config.php'; // Votre fichier de connexion à la base de données et fonctions utilitaires

// Assurez-vous que isLoggedIn() et redirect() sont définies dans config.php
if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Assurez-vous que getPDO() est définie dans config.php
if (!function_exists('getPDO')) {
    function getPDO() {
        static $pdo = null;
        if ($pdo === null) {
            $host = '127.0.0.1:3306';
            $db   = 'sygecos';
            $user = 'root';
            $pass = '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                $pdo = new PDO($dsn, $user, $pass, $options);
            } catch (\PDOException $e) {
                error_log("Erreur de connexion à la base de données: " . $e->getMessage());
                die("Impossible de se connecter à la base de données.");
            }
        }
        return $pdo;
    }
}

$pdo = getPDO();

// --- Logique PHP pour récupérer les rapports et les détails (sera appelée via AJAX) ---
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        $action = $_POST['action'];

        if ($action === 'get_all_reports') {
            $sql = "
                SELECT 
                    r.id_rapport, 
                    r.theme_rapport, 
                    e.nom_etu, 
                    e.prenoms_etu, 
                    e.num_etu,
                    r.date_creation as date_depot,
                    ne.lib_niv_etu, 
                    f.lib_filiere,
                    r.statut, -- Statut global du rapport
                    SUM(CASE WHEN v.com_val = 'ACCEPTER' THEN 1 ELSE 0 END) as nb_approbations,
                    SUM(CASE WHEN v.com_val = 'REJETER' THEN 1 ELSE 0 END) as nb_rejets,
                    SUM(CASE WHEN v.com_val = 'A_CORRIGER' THEN 1 ELSE 0 END) as nb_corrections
                FROM rapports r
                INNER JOIN etudiant e ON r.fk_num_etu = e.num_etu
                LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                LEFT JOIN valider v ON r.id_rapport = v.fk_id_rapport -- Jointure sur la nouvelle table 'rapports'
                GROUP BY r.id_rapport
                ORDER BY r.date_creation DESC
            ";
            $stmt = $pdo->query($sql);
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $reports]);
            exit;
        } 
        
        if ($action === 'get_report_details_and_approvals') {
            $idRapport = filter_var($_POST['id_rapport'], FILTER_VALIDATE_INT);
            if (!$idRapport) {
                throw new Exception("ID du rapport invalide.");
            }

            // Détails du rapport
            $stmt = $pdo->prepare("
                SELECT 
                    r.*, 
                    e.nom_etu, e.prenoms_etu, e.num_etu,
                    ne.lib_niv_etu, 
                    f.lib_filiere
                FROM rapports r
                INNER JOIN etudiant e ON r.fk_num_etu = e.num_etu
                LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                WHERE r.id_rapport = :id_rapport
            ");
            $stmt->bindParam(':id_rapport', $idRapport, PDO::PARAM_INT);
            $stmt->execute();
            $rapportDetails = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rapportDetails) {
                throw new Exception("Rapport introuvable.");
            }

            // Décisions des membres
            $stmt = $pdo->prepare("
                SELECT 
                    v.dte_val, 
                    v.com_val, 
                    ens.nom_ens, 
                    ens.prenom_ens,
                    gu.lib_GU as role_enseignant -- Pourrait être le rôle assigné dans un groupe d'utilisateurs
                FROM valider v
                INNER JOIN enseignant ens ON v.fk_id_ens = ens.id_ens
                LEFT JOIN posseder p ON ens.fk_id_util = p.fk_id_util
                LEFT JOIN groupe_utilisateur gu ON p.fk_id_GU = gu.id_GU
                WHERE v.fk_id_rapport = :id_rapport
                ORDER BY v.dte_val DESC
            ");
            $stmt->bindParam(':id_rapport', $idRapport, PDO::PARAM_INT);
            $stmt->execute();
            $approbations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'reportDetails' => $rapportDetails, 'approbations' => $approbations]);
            exit;
        }

    } catch (Exception $e) {
        error_log("Erreur dans approbation_rapport.php (AJAX): " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Si ce n'est pas une requête AJAX, afficher la page HTML
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Approbation des Rapports</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-50: #f8fafc; --primary-100: #f1f5f9; --primary-200: #e2e8f0; --primary-300: #cbd5e1; --primary-400: #94a3b8; --primary-500: #64748b; --primary-600: #475569; --primary-700: #334155; --primary-800: #1e293b; --primary-900: #0f172a;
            --accent-50: #eff6ff; --accent-100: #dbeafe; --accent-200: #bfdbfe; --accent-300: #93c5fd; --accent-400: #60a5fa; --accent-500: #3b82f6; --accent-600: #2563eb; --accent-700: #1d4ed8; --accent-800: #1e40af; --accent-900: #1e3a8a;
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

        /* Tableau des rapports */
        .reports-table { width: 100%; border-collapse: collapse; min-width: 800px; /* Assurer un minimum pour les colonnes */ }
        .reports-table th, .reports-table td { padding: var(--space-3); border-bottom: 1px solid var(--gray-200); text-align: left; }
        .reports-table th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); font-size: var(--text-sm); position: sticky; top: 0; }
        .reports-table tbody tr:hover { background-color: var(--gray-50); cursor: pointer; }
        .reports-table tbody tr.selected { background-color: var(--accent-50); }

        /* Badges d'approbation */
        .approval-badge { display: inline-flex; align-items: center; gap: var(--space-2); padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; }
        .approval-badge.approved { background-color: var(--success-500); color: white; }
        .approval-badge.rejected { background-color: var(--error-500); color: white; }
        .approval-badge.correction { background-color: var(--warning-500); color: white; }
        .approval-badge.pending { background-color: var(--gray-300); color: var(--gray-800); }

        /* Carte de détail du rapport */
        .report-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-6); }
        .report-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-6); }
        .report-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-2); }
        .report-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-4); margin-bottom: var(--space-4); }
        .meta-item { }
        .meta-label { font-size: var(--text-sm); color: var(--gray-600); margin-bottom: var(--space-1); }
        .meta-value { font-weight: 500; color: var(--gray-900); }

        /* Liste des approbations */
        .approvals-list { margin-top: var(--space-6); }
        .approval-item { display: flex; align-items: center; padding: var(--space-4); border-bottom: 1px solid var(--gray-200); }
        .approval-avatar { width: 40px; height: 40px; border-radius: 50%; background-color: var(--gray-200); display: flex; align-items: center; justify-content: center; margin-right: var(--space-4); font-size: var(--text-base); color: var(--gray-600); }
        .approval-details { flex: 1; }
        .approval-member { font-weight: 600; }
        .approval-role { font-size: var(--text-sm); color: var(--gray-600); }
        .approval-comment { margin-top: var(--space-2); font-size: var(--text-sm); color: var(--gray-700); background-color: var(--gray-100); padding: var(--space-2); border-radius: var(--radius-sm); }
        .approval-date { font-size: var(--text-xs); color: var(--gray-500); margin-top: var(--space-1);}
        .approval-decision { margin-left: var(--space-4); flex-shrink: 0; }

        /* Résumé des approbations */
        .approval-summary { display: flex; gap: var(--space-4); margin-top: var(--space-6); border-top: 1px solid var(--gray-200); padding-top: var(--space-4); }
        .summary-item { flex: 1; text-align: center; padding: var(--space-4); border-radius: var(--radius-md); }
        .summary-item.approved { background-color: var(--success-50); color: var(--success-600); }
        .summary-item.rejected { background-color: #fef2f2; color: var(--error-500); }
        .summary-item.correction { background-color: #fffbeb; color: var(--warning-600); }
        .summary-count { font-size: var(--text-2xl); font-weight: 700; margin-bottom: var(--space-1); }
        .summary-label { font-size: var(--text-sm); }

        /* Boutons */
        .btn { padding: var(--space-3) var(--space-5); border-radius: var(--radius-md); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); text-decoration: none; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover { background-color: var(--gray-300); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover { background-color: var(--success-600); }
        .btn-warning { background-color: var(--warning-500); color: white; }
        .btn-info { background-color: var(--info-500); color: white; }
        .btn-danger { background-color: var(--error-500); color: white; }
        .btn-sm { padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            display: none; /* Hidden by default */
        }
        .loading-spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--accent-500);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .report-meta { grid-template-columns: 1fr; }
            .approval-summary { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_commision.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Approbation des Rapports</h1>
                    <p class="page-subtitle">Consulter et valider les rapports de stage</p>
                </div>

                <div id="alertContainer"></div>

                <div class="report-card">
                    <h3 style="margin-bottom: var(--space-4);">Rapports déposés</h3>
                    
                    <div style="overflow-x: auto;">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <th>Titre du rapport</th>
                                    <th>Étudiant</th>
                                    <th>Date dépôt</th>
                                    <th>Statut Global</th>
                                    <th>Approbations / Rejets</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="reportsTableBody">
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 20px;" id="noReportsPlaceholder">Chargement des rapports...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="reportDetailsSection" style="display: none;">
                    <div class="report-card">
                        <div class="report-header">
                            <div>
                                <h3 class="report-title" id="detail_report_title"></h3>
                                <div class="report-meta">
                                    <div class="meta-item">
                                        <div class="meta-label">Étudiant</div>
                                        <div class="meta-value" id="detail_student_name"></div>
                                    </div>
                                    <div class="meta-item">
                                        <div class="meta-label">Matricule</div>
                                        <div class="meta-value" id="detail_student_matricule"></div>
                                    </div>
                                    <div class="meta-item">
                                        <div class="meta-label">Date de dépôt</div>
                                        <div class="meta-value" id="detail_date_depot"></div>
                                    </div>
                                    <div class="meta-item">
                                        <div class="meta-label">Niveau/Filière</div>
                                        <div class="meta-value" id="detail_level_filiere"></div>
                                    </div>
                                    <div class="meta-item">
                                        <div class="meta-label">Entreprise</div>
                                        <div class="meta-value" id="detail_company"></div>
                                    </div>
                                    <div class="meta-item">
                                        <div class="meta-label">Période de Stage</div>
                                        <div class="meta-value" id="detail_period"></div>
                                    </div>
                                    <div class="meta-item">
                                        <div class="meta-label">Encadrant</div>
                                        <div class="meta-value" id="detail_supervisor"></div>
                                    </div>
                                    <div class="meta-item">
                                        <div class="meta-label">Statut Global</div>
                                        <div class="meta-value" id="detail_global_status"></div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <a href="#" id="download_report_btn" class="btn btn-primary" download style="display:none;">
                                    <i class="fas fa-download"></i> Télécharger le rapport
                                </a>
                            </div>
                        </div>

                        <div style="margin-bottom: var(--space-6);">
                            <h4 style="margin-bottom: var(--space-3);">Thème du rapport</h4>
                            <p style="background: var(--gray-50); padding: var(--space-4); border-radius: var(--radius-md); line-height: 1.6;" id="detail_theme_full">
                                </p>
                        </div>
                      
                        <div class="approval-summary">
                            <div class="summary-item approved">
                                <div class="summary-count" id="summary_approved">0</div>
                                <div class="summary-label">Approbations</div>
                            </div>
                            <div class="summary-item correction">
                                <div class="summary-count" id="summary_correction">0</div>
                                <div class="summary-label">Corrections</div>
                            </div>
                            <div class="summary-item rejected">
                                <div class="summary-count" id="summary_rejected">0</div>
                                <div class="summary-label">Rejets</div>
                            </div>
                        </div>

                        <div class="approvals-list">
                            <h4 style="margin-bottom: var(--space-4);">Décisions des membres</h4>
                            <div id="individualApprovalsList">
                                <div style="text-align: center; padding: var(--space-6); color: var(--gray-500);" id="noIndividualApprovals">
                                    <i class="fas fa-info-circle" style="font-size: var(--text-xl); margin-bottom: var(--space-2);"></i>
                                    <p>Aucune décision enregistrée pour ce rapport par les membres de la commission.</p>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top: var(--space-6); text-align: center;">
                            <a href="#" id="statuer_rapport_btn" class="btn btn-primary">
                                <i class="fas fa-gavel"></i> Statuer sur ce rapport
                            </a>
                        </div>
                    </div>
                </div>

                <div id="noReportSelectedPlaceholder" class="report-card" style="text-align: center; padding: var(--space-8);">
                    <i class="fas fa-file-alt" style="font-size: var(--text-3xl); color: var(--gray-400); margin-bottom: var(--space-3);"></i>
                    <h4 style="color: var(--gray-600);">Sélectionnez un rapport dans la liste ci-dessus pour voir les détails</h4>
                </div>
               
            </div>
        </main>
    </div>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>
    <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>


    <script>
        // Variables globales
        let allReportsData = []; // Stockera tous les rapports récupérés

        // DOM elements
        const reportsTableBody = document.getElementById('reportsTableBody');
        const noReportsPlaceholder = document.getElementById('noReportsPlaceholder');
        const reportDetailsSection = document.getElementById('reportDetailsSection');
        const noReportSelectedPlaceholder = document.getElementById('noReportSelectedPlaceholder');
        const alertContainer = document.getElementById('alertContainer');
        const loadingOverlay = document.getElementById('loadingOverlay');

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            fetchAllReports();
            initSidebar(); // Initialiser le comportement responsive de la sidebar
        });

        // --- Fonctions utilitaires ---
        function showLoading(show) {
            loadingOverlay.style.display = show ? 'flex' : 'none';
        }

        function showAlert(message, type) {
            const alert = document.createElement('div');
            alert.className = `alert ${type}`;
            alert.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span><i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i> ${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; font-size: 1.2em; cursor: pointer; color: inherit;">&times;</button>
                </div>
            `;
            alertContainer.appendChild(alert);
            setTimeout(() => {
                if (alert.parentElement) {
                    alert.remove();
                }
            }, 5000);
        }

        function formatDate(dateString) {
            if (!dateString || dateString === '0000-00-00' || dateString.includes('N/A')) return 'N/A';
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return 'N/A';
            return date.toLocaleDateString('fr-FR');
        }

        function getStatusBadge(status) {
            let className = '';
            let text = '';
            switch (status) {
                case 'brouillon': className = 'badge-warning'; text = 'Brouillon'; break;
                case 'soumis': className = 'badge-info'; text = 'Soumis'; break;
                case 'approuve': className = 'badge-success'; text = 'Approuvé'; break;
                case 'rejete': className = 'badge-danger'; text = 'Rejeté'; break;
                default: className = 'badge-secondary'; text = 'Inconnu';
            }
            return `<span class="approval-badge ${className}">${text}</span>`;
        }

        // --- Fonctions d'affichage des rapports ---

        async function fetchAllReports() {
            showLoading(true);
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'get_all_reports' })
                });
                const result = await response.json();

                if (result.success && result.data.length > 0) {
                    allReportsData = result.data;
                    renderReportsTable(allReportsData);
                    noReportsPlaceholder.style.display = 'none'; // Masquer le message de chargement
                } else {
                    reportsTableBody.innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px; color: var(--gray-500);">
                                <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                                Aucun rapport n'a été déposé pour le moment.
                            </td>
                        </tr>
                    `;
                    noReportsPlaceholder.style.display = 'none'; // S'assurer que le message de chargement est masqué
                    showAlert(result.message || "Aucun rapport n'a été trouvé.", 'info');
                }
            } catch (error) {
                console.error("Erreur lors du chargement des rapports:", error);
                showAlert("Erreur lors du chargement des rapports. Veuillez réessayer.", 'error');
                reportsTableBody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 20px; color: var(--error-500);">
                            <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                            Impossible de charger les rapports. Erreur de connexion.
                        </td>
                    </tr>
                `;
                 noReportsPlaceholder.style.display = 'none';
            } finally {
                showLoading(false);
            }
        }

        function renderReportsTable(reports) {
            reportsTableBody.innerHTML = '';
            if (reports.length === 0) {
                reportsTableBody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 20px; color: var(--gray-500);">
                            <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                            Aucun rapport n'a été déposé pour le moment.
                        </td>
                    </tr>
                `;
                return;
            }

            reports.forEach(report => {
                const row = document.createElement('tr');
                row.dataset.id = report.id_rapport;
                row.onclick = () => selectReport(report.id_rapport);

                let approvalSummaryHtml = '';
                if (report.nb_approbations > 0) {
                    approvalSummaryHtml += `<span class="approval-badge approved" title="Approbations"><i class="fas fa-check"></i> ${report.nb_approbations}</span>`;
                }
                if (report.nb_corrections > 0) {
                    approvalSummaryHtml += `<span class="approval-badge correction" title="Corrections demandées"><i class="fas fa-edit"></i> ${report.nb_corrections}</span>`;
                }
                if (report.nb_rejets > 0) {
                    approvalSummaryHtml += `<span class="approval-badge rejected" title="Rejets"><i class="fas fa-times"></i> ${report.nb_rejets}</span>`;
                }
                if (report.nb_approbations === 0 && report.nb_corrections === 0 && report.nb_rejets === 0) {
                     approvalSummaryHtml += `<span class="approval-badge pending" title="En attente de décision"><i class="fas fa-hourglass-half"></i> Aucun</span>`;
                }


                row.innerHTML = `
                    <td>
                        <div style="font-weight: 500;">${report.theme_rapport}</div>
                    </td>
                    <td>
                        <div>${report.prenoms_etu} ${report.nom_etu}</div>
                        <div style="font-size: var(--text-xs); color: var(--gray-600);">${report.num_etu}</div>
                    </td>
                    <td>${formatDate(report.date_depot)}</td>
                    <td>${getStatusBadge(report.statut)}</td>
                    <td>
                        <div style="display: flex; gap: var(--space-2);">
                            ${approvalSummaryHtml}
                        </div>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-info" onclick="event.stopPropagation(); selectReport(${report.id_rapport});">
                            <i class="fas fa-eye"></i> Voir
                        </button>
                    </td>
                `;
                reportsTableBody.appendChild(row);
            });
        }

        async function selectReport(reportId) {
            // Supprimer la classe 'selected' de toutes les lignes
            document.querySelectorAll('#reportsTableBody tr').forEach(row => {
                row.classList.remove('selected');
            });
            // Ajouter la classe 'selected' à la ligne cliquée
            const selectedRow = document.querySelector(`#reportsTableBody tr[data-id='${reportId}']`);
            if (selectedRow) {
                selectedRow.classList.add('selected');
            }

            showLoading(true);
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'get_report_details_and_approvals', id_rapport: reportId })
                });
                const result = await response.json();

                if (result.success) {
                    displayReportDetails(result.reportDetails, result.approbations);
                    reportDetailsSection.style.display = 'block';
                    noReportSelectedPlaceholder.style.display = 'none';
                } else {
                    showAlert(result.message || "Impossible de charger les détails du rapport.", 'error');
                    reportDetailsSection.style.display = 'none';
                    noReportSelectedPlaceholder.style.display = 'block';
                }
            } catch (error) {
                console.error("Erreur lors de la récupération des détails du rapport:", error);
                showAlert("Erreur lors de la récupération des détails du rapport.", 'error');
                reportDetailsSection.style.display = 'none';
                noReportSelectedPlaceholder.style.display = 'block';
            } finally {
                showLoading(false);
            }
        }

        function displayReportDetails(details, approvals) {
            document.getElementById('detail_report_title').textContent = details.theme_rapport;
            document.getElementById('detail_student_name').textContent = `${details.prenoms_etu} ${details.nom_etu}`;
            document.getElementById('detail_student_matricule').textContent = details.num_etu;
            document.getElementById('detail_date_depot').textContent = formatDate(details.date_creation); // Utiliser date_creation de la table rapports
            document.getElementById('detail_level_filiere').textContent = `${details.lib_niv_etu || 'N/A'} / ${details.lib_filiere || 'N/A'}`;
            document.getElementById('detail_company').textContent = details.entreprise || 'N/A';
            document.getElementById('detail_period').textContent = details.periode_stage || 'N/A';
            document.getElementById('detail_supervisor').textContent = details.nom_encadrant || 'N/A';
            document.getElementById('detail_global_status').innerHTML = getStatusBadge(details.statut);
            document.getElementById('detail_theme_full').textContent = details.theme_rapport; // Afficher le thème complet ici

            // Mettre à jour le lien "Statuer sur ce rapport"
            document.getElementById('statuer_rapport_btn').href = `statuer_rapport.php?id_rapport=${details.id_rapport}`;
            
            // Mise à jour du bouton de téléchargement (si tu as un chemin de fichier)
            const downloadBtn = document.getElementById('download_report_btn');
            if (details.file_path) { // Assumes 'file_path' might be added to 'rapports' or retrieved
                downloadBtn.href = details.file_path; // Replace with actual file path if available
                downloadBtn.style.display = 'inline-flex';
            } else {
                downloadBtn.style.display = 'none';
            }


            // Résumé des approbations
            let approvedCount = 0;
            let correctionCount = 0;
            let rejectedCount = 0;

            approvals.forEach(app => {
                if (app.com_val === 'ACCEPTER') approvedCount++;
                else if (app.com_val === 'A_CORRIGER') correctionCount++;
                else if (app.com_val === 'REJETER') rejectedCount++;
            });

            document.getElementById('summary_approved').textContent = approvedCount;
            document.getElementById('summary_correction').textContent = correctionCount;
            document.getElementById('summary_rejected').textContent = rejectedCount;

            // Liste des approbations individuelles
            const individualApprovalsList = document.getElementById('individualApprovalsList');
            individualApprovalsList.innerHTML = ''; // Nettoyer la liste existante
            if (approvals.length > 0) {
                approvals.forEach(app => {
                    const approvalItem = document.createElement('div');
                    approvalItem.classList.add('approval-item');

                    let decisionBadgeClass = '';
                    let decisionBadgeText = '';
                    switch (app.com_val) {
                        case 'ACCEPTER': decisionBadgeClass = 'approved'; decisionBadgeText = 'Accepté'; break;
                        case 'A_CORRIGER': decisionBadgeClass = 'correction'; decisionBadgeText = 'À corriger'; break;
                        case 'REJETER': decisionBadgeClass = 'rejected'; decisionBadgeText = 'Rejeté'; break;
                        default: decisionBadgeClass = 'pending'; decisionBadgeText = 'Non défini'; break; // Pour les cas où com_val n'est pas l'un de ceux-là
                    }

                    approvalItem.innerHTML = `
                        <div class="approval-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="approval-details">
                            <div class="approval-member">${app.prenom_ens} ${app.nom_ens || 'Membre inconnu'}</div>
                            <div class="approval-role">${app.role_enseignant || 'Commission'}</div>
                            ${app.com_val ? `<div class="approval-comment">"${app.com_val}"</div>` : ''}
                            <div class="approval-date">Décidé le: ${formatDate(app.dte_val)}</div>
                        </div>
                        <div class="approval-decision">
                            <span class="approval-badge ${decisionBadgeClass}">
                                <i class="fas fa-${decisionBadgeClass === 'approved' ? 'check' : decisionBadgeClass === 'rejected' ? 'times' : 'edit'}"></i> ${decisionBadgeText}
                            </span>
                        </div>
                    `;
                    individualApprovalsList.appendChild(approvalItem);
                });
            } else {
                individualApprovalsList.innerHTML = `
                    <div style="text-align: center; padding: var(--space-6); color: var(--gray-500);">
                        <i class="fas fa-info-circle" style="font-size: var(--text-xl); margin-bottom: var(--space-2);"></i>
                        <p>Aucune décision enregistrée pour ce rapport par les membres de la commission.</p>
                    </div>
                `;
            }
        }


        // Responsive Sidebar Logic (réutilisée depuis mes_rapports.php / statuer_rapport.php)
        function initSidebar() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay'); 

            if (sidebarToggle && sidebar && mainContent && mobileMenuOverlay) {
                handleResponsiveLayout(); // Vérification initiale
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
            window.addEventListener('resize', handleResponsiveLayout); // Réévaluer sur redimensionnement
        }

        function handleResponsiveLayout() {
            const isMobile = window.innerWidth < 768;
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (isMobile) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('sidebar-collapsed');
                sidebar.classList.remove('mobile-open'); // S'assurer qu'il est fermé par défaut
                mobileMenuOverlay.classList.remove('active');
                if (sidebarToggle) {
                    sidebarToggle.querySelector('.fa-bars').style.display = 'inline-block';
                    sidebarToggle.querySelector('.fa-times').style.display = 'none';
                }
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('sidebar-collapsed');
                sidebar.classList.remove('mobile-open');
                mobileMenuOverlay.classList.remove('active');
                if (sidebarToggle) {
                    sidebarToggle.querySelector('.fa-bars').style.display = 'inline-block';
                    sidebarToggle.querySelector('.fa-times').style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>