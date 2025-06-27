<?php
// gestion_annee_academique.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Traitement des actions AJAX
if (isset($_POST['action']) || isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        global $pdo;
        if (!isset($pdo)) {
            $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=sygecos;charset=utf8mb4", "root", "");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        
        $action = $_POST['action'] ?? $_GET['action'];
        
        switch ($action) {
            case 'create':
                handleCreateAjax($pdo);
                break;
            case 'delete':
                handleDeleteAjax($pdo);
                break;
            case 'activate':
                handleActivateAjax($pdo);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Action non valide']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Chargement initial des données
try {
    global $pdo;
    if (!isset($pdo)) {
        $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=sygecos;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    
    // Vérifier si la colonne statut existe
    $stmt = $pdo->query("DESCRIBE année_academique");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $hasStatut = in_array('statut', $columns);
    
    if ($hasStatut) {
        $stmt = $pdo->query("SELECT id_Ac as id_annee, YEAR(date_deb) as annee_debut, YEAR(date_fin) as annee_fin, statut FROM année_academique ORDER BY date_deb DESC");
    } else {
        $stmt = $pdo->query("SELECT id_Ac as id_annee, YEAR(date_deb) as annee_debut, YEAR(date_fin) as annee_fin, 'preparation' as statut FROM année_academique ORDER BY date_deb DESC");
    }
    $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $years = [];
    $error_message = "Erreur de chargement: " . $e->getMessage();
}

function handleCreateAjax($pdo) {
    try {
        $anneeDebut = intval($_POST['anneeDebut'] ?? 0);
        $anneeFin = intval($_POST['anneeFin'] ?? 0);
        
        if (empty($anneeDebut) || empty($anneeFin)) {
            echo json_encode(['success' => false, 'message' => 'Veuillez remplir tous les champs']);
            return;
        }
        
        if ($anneeDebut < 2025 || $anneeFin != ($anneeDebut + 1)) {
            echo json_encode(['success' => false, 'message' => 'Années invalides']);
            return;
        }
        
        $finShort = substr($anneeFin, -2);
        $debutShort = substr($anneeDebut, -2);
        $idAnnee = "2{$finShort}{$debutShort}";
        
        $stmt = $pdo->prepare("SELECT id_Ac FROM année_academique WHERE id_Ac = ? OR (YEAR(date_deb) = ? AND YEAR(date_fin) = ?)");
        $stmt->execute([$idAnnee, $anneeDebut, $anneeFin]);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Cette année académique existe déjà']);
            return;
        }
        
        $dateDebut = $anneeDebut . '-09-01';
        $dateFin = $anneeFin . '-08-31';
        
        // Vérifier si la colonne statut existe
        $stmt = $pdo->query("DESCRIBE année_academique");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $hasStatut = in_array('statut', $columns);
        
        if ($hasStatut) {
            $stmt = $pdo->prepare("INSERT INTO année_academique (id_Ac, date_deb, date_fin, statut) VALUES (?, ?, ?, 'preparation')");
        } else {
            $stmt = $pdo->prepare("INSERT INTO année_academique (id_Ac, date_deb, date_fin) VALUES (?, ?, ?)");
        }
        
        $result = $stmt->execute([$idAnnee, $dateDebut, $dateFin]);
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'enregistrement']);
            return;
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Année académique {$anneeDebut}-{$anneeFin} créée avec succès (ID: {$idAnnee})"
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
}

function handleActivateAjax($pdo) {
    try {
        $idAnnee = $_POST['id_annee'] ?? '';
        
        if (empty($idAnnee)) {
            echo json_encode(['success' => false, 'message' => 'ID année manquant']);
            return;
        }
        
        // Vérifier si la colonne statut existe, sinon l'ajouter
        $stmt = $pdo->query("DESCRIBE année_academique");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $hasStatut = in_array('statut', $columns);
        
        if (!$hasStatut) {
            $pdo->exec("ALTER TABLE année_academique ADD COLUMN statut ENUM('active', 'preparation', 'archivee') DEFAULT 'preparation'");
        }
        
        // Vérifier que l'année existe
        $stmt = $pdo->prepare("SELECT id_Ac FROM année_academique WHERE id_Ac = ?");
        $stmt->execute([$idAnnee]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Année académique introuvable']);
            return;
        }
        
        // Transaction pour activer
        $pdo->beginTransaction();
        
        try {
            // Désactiver toutes les autres années
            $pdo->exec("UPDATE année_academique SET statut = 'archivee' WHERE statut = 'active'");
            
            // Activer la nouvelle année
            $stmt = $pdo->prepare("UPDATE année_academique SET statut = 'active' WHERE id_Ac = ?");
            $stmt->execute([$idAnnee]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Année {$idAnnee} activée avec succès"
            ]);
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'activation: ' . $e->getMessage()]);
    }
}

function handleDeleteAjax($pdo) {
    try {
        $idsJson = $_POST['ids_annee'] ?? '';
        $ids = json_decode($idsJson, true);
        
        if (!is_array($ids) || empty($ids)) {
            echo json_encode(['success' => false, 'message' => 'Aucune année sélectionnée']);
            return;
        }
        
        // Vérifier qu'aucune année active n'est sélectionnée
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id_Ac FROM année_academique WHERE id_Ac IN ($placeholders) AND statut = 'active'");
        $stmt->execute($ids);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Impossible de supprimer une année active']);
            return;
        }
        
        $stmt = $pdo->prepare("DELETE FROM année_academique WHERE id_Ac IN ($placeholders)");
        $result = $stmt->execute($ids);
        $deletedCount = $stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'message' => "{$deletedCount} année(s) supprimée(s) avec succès"
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Gestion des Années Académiques</title>
    <link href="anneeacademique.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        .year-id { font-family: monospace; font-weight: bold; color: #2c3e50; }
        .badge { padding: 0.35em 0.65em; font-size: 0.75em; border-radius: 0.25rem; color: white; }
        .badge.bg-success { background-color: #28a745; }
        .badge.bg-info { background-color: #17a2b8; }
        .badge.bg-secondary { background-color: #6c757d; }
        .badge.bg-warning { background-color: #ffc107; color: #212529; }
        .toast { position: fixed; top: 20px; right: 20px; padding: 1rem; border-radius: 0.25rem; color: white; z-index: 1000; animation: fadeIn 0.3s; }
        .toast-success { background-color: #28a745; }
        .toast-error { background-color: #dc3545; }
        .toast-warning { background-color: #ffc107; color: #212529; }
        .toast-info { background-color: #17a2b8; }
        @keyframes fadeIn { from { opacity: 0; transform: translateX(100%); } to { opacity: 1; transform: translateX(0); } }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; }
        .modal-content { background: white; padding: 1.5rem; border-radius: 0.5rem; width: 90%; max-width: 500px; }
        .close-button { float: right; cursor: pointer; font-size: 1.5rem; }
        .btn-activate { background: #28a745; color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 0.25rem; cursor: pointer; margin-right: 0.25rem; }
        .btn-activate:hover { background: #1e7e34; }
        .btn-activate:disabled { background: #6c757d; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Gestion des Années Académiques</h1>
                    <p class="page-subtitle">Créez et gérez les périodes académiques de la plateforme.</p>
                </div>

                <div class="form-card">
                    <h3 class="form-card-title">Créer une Année Académique</h3>
                    <form id="academicYearForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="anneeDebut">Année de début</label>
                                <input type="number" id="anneeDebut" name="anneeDebut" placeholder="Ex: 2025" required min="2025" max="2100">
                            </div>
                            <div class="form-group">
                                <label for="anneeFin">Année de fin</label>
                                <input type="number" id="anneeFin" name="anneeFin" placeholder="Ex: 2026" required min="2026" max="2100">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Annuler
                            </button>
                        </div>
                    </form>
                </div>

                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">Liste des Années Académiques</h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" id="supprimerBtn" disabled>
                                <i class="fas fa-trash-alt"></i> Supprimer
                            </button>
                            <button class="btn btn-secondary" id="exporterBtn">
                                <i class="fas fa-file-export"></i> Exporter
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="academicYearsTable">
                            <thead>
                                <tr>
                                    <th width="40px"></th>
                                    <th>ID</th>
                                    <th>Année Académique</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($years)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 2rem; color: #6c757d;">
                                        Aucune année académique enregistrée
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($years as $year): ?>
                                <tr data-id="<?= htmlspecialchars($year['id_annee']) ?>">
                                    <td>
                                        <input type="checkbox" value="<?= htmlspecialchars($year['id_annee']) ?>" 
                                               <?= $year['statut'] === 'active' ? 'disabled' : '' ?>>
                                    </td>
                                    <td class="year-id"><?= htmlspecialchars($year['id_annee']) ?></td>
                                    <td><?= htmlspecialchars($year['annee_debut']) ?>-<?= htmlspecialchars($year['annee_fin']) ?></td>
                                    <td>
                                        <?php if ($year['statut'] === 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">Préparation</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($year['statut'] !== 'active'): ?>
                                        <button class="btn-activate" data-id="<?= htmlspecialchars($year['id_annee']) ?>">
                                            <i class="fas fa-power-off"></i> Activer
                                        </button>
                                        <?php else: ?>
                                        <span style="color: #28a745; font-weight: bold;">
                                            <i class="fas fa-check-circle"></i> En cours
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="confirmationModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h4 id="modalTitle">Confirmer l'action</h4>
            <p id="modalMessage">Êtes-vous sûr de vouloir effectuer cette action ?</p>
            <div class="modal-buttons">
                <button id="modalConfirmBtn" class="btn btn-primary">Confirmer</button>
                <button id="modalCancelBtn" class="btn btn-secondary">Annuler</button>
            </div>
        </div>
    </div>

    <script>
        class GestionAnneeAcademique {
            constructor() {
                this.selectedRows = new Set();
                this.initElements();
                this.initEventListeners();
            }

            initElements() {
                this.form = document.getElementById('academicYearForm');
                this.anneeDebutInput = document.getElementById('anneeDebut');
                this.anneeFinInput = document.getElementById('anneeFin');
                this.tableBody = document.querySelector('#academicYearsTable tbody');
                this.supprimerBtn = document.getElementById('supprimerBtn');
                this.exporterBtn = document.getElementById('exporterBtn');
                this.modal = document.getElementById('confirmationModal');
            }

            initEventListeners() {
                this.form.addEventListener('submit', (e) => this.handleFormSubmit(e));
                this.form.addEventListener('reset', () => setTimeout(() => this.resetForm(), 10));

                // Auto-complétion
                this.anneeDebutInput.addEventListener('input', (e) => {
                    const value = e.target.value;
                    if (value && value.length === 4) {
                        this.anneeFinInput.value = parseInt(value) + 1;
                        this.showToast('Année de fin complétée automatiquement', 'info');
                    }
                });

                // Boutons d'action
                this.supprimerBtn.addEventListener('click', () => this.handleDelete());
                this.exporterBtn.addEventListener('click', () => this.handleExport());

                // Checkboxes et boutons d'activation
                this.tableBody.addEventListener('change', (e) => {
                    if (e.target.type === 'checkbox') {
                        this.updateSelections();
                    }
                });

                this.tableBody.addEventListener('click', (e) => {
                    if (e.target.closest('.btn-activate')) {
                        const btn = e.target.closest('.btn-activate');
                        const id = btn.dataset.id;
                        this.activateYear(id);
                    }
                });
            }

            async handleFormSubmit(e) {
                e.preventDefault();
                
                const anneeDebut = parseInt(this.anneeDebutInput.value);
                const anneeFin = parseInt(this.anneeFinInput.value);

                if (!anneeDebut || !anneeFin) {
                    this.showToast('Veuillez remplir tous les champs', 'error');
                    return;
                }

                const formData = new FormData();
                formData.append('anneeDebut', anneeDebut);
                formData.append('anneeFin', anneeFin);
                formData.append('action', 'create');

                try {
                    this.showLoading(true);
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();

                    if (result.success) {
                        this.showToast(result.message, 'success');
                        this.resetForm();
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        this.showToast(result.message, 'error');
                    }
                } catch (error) {
                    this.showToast('Erreur de connexion: ' + error.message, 'error');
                } finally {
                    this.showLoading(false);
                }
            }

            async activateYear(id) {
                const confirmed = await this.showConfirmationModal(
                    `Voulez-vous vraiment activer l'année ${id} ?\nCela désactivera l'année actuellement active.`
                );
                
                if (!confirmed) return;

                try {
                    const formData = new FormData();
                    formData.append('action', 'activate');
                    formData.append('id_annee', id);

                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    this.showToast(result.message, result.success ? 'success' : 'error');
                    
                    if (result.success) {
                        setTimeout(() => location.reload(), 1500);
                    }
                } catch (error) {
                    this.showToast('Erreur lors de l\'activation: ' + error.message, 'error');
                }
            }

            async handleDelete() {
                const ids = Array.from(this.selectedRows);
                if (ids.length === 0) {
                    this.showToast('Veuillez sélectionner au moins une année', 'warning');
                    return;
                }

                const confirmed = await this.showConfirmationModal(
                    `Voulez-vous vraiment supprimer ${ids.length} année(s) ?`
                );
                
                if (!confirmed) return;

                try {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('ids_annee', JSON.stringify(ids));

                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    this.showToast(result.message, result.success ? 'success' : 'error');
                    
                    if (result.success) {
                        setTimeout(() => location.reload(), 1500);
                    }
                } catch (error) {
                    this.showToast('Erreur: ' + error.message, 'error');
                }
            }

            handleExport() {
                const rows = Array.from(this.tableBody.querySelectorAll('tr[data-id]'));
                if (rows.length === 0) {
                    this.showToast('Aucune donnée à exporter', 'warning');
                    return;
                }

                try {
                    const data = [['ID', 'Période', 'Statut']];
                    rows.forEach(row => {
                        const id = row.getAttribute('data-id');
                        const periode = row.querySelector('td:nth-child(3)').textContent.trim();
                        const statut = row.querySelector('td:nth-child(4)').textContent.trim();
                        data.push([id, periode, statut]);
                    });

                    const wb = XLSX.utils.book_new();
                    const ws = XLSX.utils.aoa_to_sheet(data);
                    XLSX.utils.book_append_sheet(wb, ws, "Années Académiques");
                    
                    const filename = `Annees_Academiques_${new Date().toISOString().split('T')[0]}.xlsx`;
                    XLSX.writeFile(wb, filename);
                    
                    this.showToast('Export Excel réussi', 'success');
                } catch (error) {
                    this.showToast('Erreur lors de l\'export', 'error');
                }
            }

            updateSelections() {
                this.selectedRows.clear();
                const checkboxes = this.tableBody.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)');
                checkboxes.forEach(cb => this.selectedRows.add(cb.value));
                this.supprimerBtn.disabled = this.selectedRows.size === 0;
            }

            resetForm() {
                this.form.reset();
                this.selectedRows.clear();
                this.supprimerBtn.disabled = true;
            }

            showLoading(show) {
                const btn = this.form.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = show;
                    btn.innerHTML = show 
                        ? '<i class="fas fa-spinner fa-spin"></i> Traitement...' 
                        : '<i class="fas fa-save"></i> Enregistrer';
                }
            }

            showToast(message, type = 'info') {
                const existingToasts = document.querySelectorAll('.toast');
                if (existingToasts.length >= 2) {
                    existingToasts[0].remove();
                }
                
                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;
                toast.innerHTML = `
                    <span>${message}</span>
                    <button onclick="this.parentElement.remove()" style="background: none; border: none; color: inherit; margin-left: 10px; cursor: pointer;">×</button>
                `;
                
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), type === 'error' ? 8000 : 5000);
            }

            showConfirmationModal(message, title = 'Confirmation') {
                return new Promise(resolve => {
                    const modal = this.modal;
                    modal.querySelector('#modalTitle').textContent = title;
                    modal.querySelector('#modalMessage').textContent = message;
                    modal.style.display = 'flex';

                    const cleanUp = () => {
                        modal.style.display = 'none';
                        modal.querySelector('#modalConfirmBtn').onclick = null;
                        modal.querySelector('#modalCancelBtn').onclick = null;
                        modal.querySelector('.close-button').onclick = null;
                    };

                    modal.querySelector('#modalConfirmBtn').onclick = () => {
                        cleanUp();
                        resolve(true);
                    };
                    
                    modal.querySelector('#modalCancelBtn').onclick = () => {
                        cleanUp();
                        resolve(false);
                    };
                    
                    modal.querySelector('.close-button').onclick = () => {
                        cleanUp();
                        resolve(false);
                    };
                });
            }
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', () => {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');

            if (sidebarToggle && sidebar && mainContent) {
                sidebarToggle.addEventListener('click', () => {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('sidebar-collapsed');
                });
            }

            if (document.getElementById('academicYearForm')) {
                window.gestionAnnee = new GestionAnneeAcademique();
                setTimeout(() => {
                    window.gestionAnnee.showToast('Interface de gestion prête', 'success');
                }, 500);
            }
        });
    </script>
</body>
</html>