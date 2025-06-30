<?php
// informations_personnelles.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}


?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Mes Informations Personnelles</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-8); }
        .stat-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); }
        .stat-number { font-size: var(--text-3xl); font-weight: 700; color: var(--accent-600); }
        .stat-label { color: var(--gray-600); font-size: var(--text-sm); margin-top: var(--space-2); }

        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-8); }
        .card { background: var(--white); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); overflow: hidden; }
        .card-header { padding: var(--space-4) var(--space-6); border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); }
        .card-body { padding: var(--space-6); }
        .card-footer { padding: var(--space-4) var(--space-6); border-top: 1px solid var(--gray-200); background: var(--gray-50); }

        .task-list { list-style: none; }
        .task-item { padding: var(--space-3) 0; border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; gap: var(--space-3); }
        .task-item:last-child { border-bottom: none; }
        .task-checkbox { width: 16px; height: 16px; accent-color: var(--accent-500); }
        .task-content { flex: 1; }
        .task-title { font-weight: 600; color: var(--gray-800); margin-bottom: var(--space-1); }
        .task-meta { font-size: var(--text-xs); color: var(--gray-500); display: flex; gap: var(--space-3); }
        .task-priority { font-size: var(--text-xs); padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm); }
        .priority-high { background: #fee2e2; color: #dc2626; }
        .priority-medium { background: #fef3c7; color: #d97706; }
        .priority-low { background: #ecfdf5; color: #059669; }

        .recent-reports { width: 100%; border-collapse: collapse; }
        .recent-reports th, .recent-reports td { padding: var(--space-3) var(--space-4); text-align: left; border-bottom: 1px solid var(--gray-200); font-size: var(--text-sm); }
        .recent-reports th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); }
        .recent-reports tbody tr:hover { background-color: var(--gray-50); }
        .report-status { padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; }
        .status-pending { background-color: #fef3c7; color: #d97706; }
        .status-approved { background-color: #ecfdf5; color: #059669; }
        .status-rejected { background-color: #fee2e2; color: #dc2626; }

        .btn { padding: var(--space-2) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); text-decoration: none; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover:not(:disabled) { background-color: var(--accent-700); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover:not(:disabled) { background-color: var(--secondary-600); }
        .btn-outline { background-color: transparent; color: var(--accent-600); border: 1px solid var(--accent-600); } .btn-outline:hover { background-color: var(--accent-50); }
        .btn-sm { padding: var(--space-1) var(--space-2); font-size: var(--text-xs); }

        .empty-state { text-align: center; padding: var(--space-16); color: var(--gray-500); }
        .empty-state i { font-size: 3rem; margin-bottom: var(--space-4); }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .main-content.sidebar-collapsed { margin-left: 0; }
            .stats-grid { grid-template-columns: 1fr; }
            .card-grid { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; gap: var(--space-4); }
        }
        .role-badge {
            background-color: var(--accent-100);
            color: var(--accent-800);
            padding: var(--space-2) var(--space-4);
            border-radius: var(--radius-lg);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .form-control {
            width: 100%;
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-family: var(--font-primary);
            transition: all var(--transition-fast);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--accent-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .password-toggle {
            position: relative;
        }
        
        .password-toggle input {
            padding-right: 40px;
        }
        
        .password-toggle-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-500);
            cursor: pointer;
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
                    <div>
                        <h1 class="page-title-main">Mes Informations Personnelles</h1>
                        <p class="page-subtitle">Gérez vos informations et vos paramètres de compte</p>
                    </div>
                    <div>
                        <button id="saveBtn" class="btn btn-primary">
                            <i class="fas fa-save"></i> Enregistrer les modifications
                        </button>
                    </div>
                </div>

                <!-- Message d'alerte -->
                <div id="alertMessage" class="alert" style="display: none;"></div>

                <div class="student-profile">
                    <!-- Carte de profil -->
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                
                                    <img src="" alt="Photo de profil">
                                
                                    <i class="fas fa-user" style="font-size: 3rem; color: var(--gray-500);"></i>
                                
                            </div>
                            <h3 class="profile-name"></h3>
                            <div class="profile-id">Membre #</div>
                            <div class="role-badge">
                                <i class="fas fa-user-shield"></i>
                                
                            </div>
                        </div>
                        
                        <div class="profile-details">
                            <div class="detail-item">
                                <span class="detail-label">Date d'adhésion:</span>
                                <span class="detail-value">
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Dernière connexion:</span>
                                <span class="detail-value"></span>
                            </div>
                        </div>
                        
                        <button class="btn btn-outline" style="width: 100%; margin-top: var(--space-4);" onclick="document.getElementById('avatarInput').click()">
                            <i class="fas fa-camera"></i> Changer la photo
                        </button>
                        <input type="file" id="avatarInput" accept="image/*" style="display: none;">
                    </div>

                    <!-- Informations principales -->
                    <div>
                        <!-- Informations personnelles -->
                        <div class="info-card" style="margin-bottom: var(--space-6);">
                            <div class="info-card-header">
                                <h3 class="info-card-title">Informations Personnelles</h3>
                                <div class="info-card-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                            </div>
                            <div class="info-grid">
                                <div>
                                    <div class="detail-item">
                                        <span class="detail-label">Nom:</span>
                                        <input type="text" class="form-control" id="nom" value="">
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Prénom:</span>
                                        <input type="text" class="form-control" id="prenom" value="">
                                    </div>
                                </div>
                                <div>
                                    <div class="detail-item">
                                        <span class="detail-label">Date de naissance:</span>
                                        <input type="date" class="form-control" id="date_naissance" value="">
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Genre:</span>
                                        <select class="form-control" id="genre">
                                            <option value="M" >Masculin</option>
                                            <option value="F">Féminin</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Coordonnées -->
                        <div class="info-card" style="margin-bottom: var(--space-6);">
                            <div class="info-card-header">
                                <h3 class="info-card-title">Coordonnées</h3>
                                <div class="info-card-icon">
                                    <i class="fas fa-address-book"></i>
                                </div>
                            </div>
                            <div class="info-grid">
                                <div>
                                    <div class="detail-item">
                                        <span class="detail-label">Email:</span>
                                        <input type="email" class="form-control" id="email" value="">
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Téléphone:</span>
                                        <input type="tel" class="form-control" id="telephone" value="">
                                    </div>
                                </div>
                                <div>
                                    <div class="detail-item">
                                        <span class="detail-label">Adresse:</span>
                                        <textarea class="form-control" id="adresse"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sécurité -->
                        <div class="info-card">
                            <div class="info-card-header">
                                <h3 class="info-card-title">Sécurité du compte</h3>
                                <div class="info-card-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                            </div>
                            <div class="info-grid">
                                <div>
                                    <div class="detail-item">
                                        <span class="detail-label">Nom d'utilisateur:</span>
                                        <input type="text" class="form-control" id="username" value="" disabled>
                                    </div>
                                    <div class="detail-item password-toggle">
                                        <span class="detail-label">Mot de passe:</span>
                                        <input type="password" class="form-control" id="password" value="********" disabled>
                                        <button class="password-toggle-btn" onclick="togglePasswordVisibility()">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <div class="detail-item">
                                        <span class="detail-label">Changer le mot de passe:</span>
                                        <button class="btn btn-outline" onclick="showChangePasswordModal()">
                                            <i class="fas fa-key"></i> Modifier le mot de passe
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal pour changer le mot de passe -->
    <div class="modal-overlay" id="passwordModal" style="display: none;">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Changer le mot de passe</h3>
                <button class="modal-close" onclick="closePasswordModal()">&times;</button>
            </div>
            <div class="modal-content">
                <div class="detail-item password-toggle">
                    <span class="detail-label">Mot de passe actuel:</span>
                    <input type="password" class="form-control" id="currentPassword">
                    <button class="password-toggle-btn" onclick="togglePasswordVisibility(this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="detail-item password-toggle">
                    <span class="detail-label">Nouveau mot de passe:</span>
                    <input type="password" class="form-control" id="newPassword">
                    <button class="password-toggle-btn" onclick="togglePasswordVisibility(this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="detail-item password-toggle">
                    <span class="detail-label">Confirmer le mot de passe:</span>
                    <input type="password" class="form-control" id="confirmPassword">
                    <button class="password-toggle-btn" onclick="togglePasswordVisibility(this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <p style="margin-top: var(--space-4); font-size: var(--text-sm); color: var(--gray-600);">
                    <i class="fas fa-info-circle"></i> 
                    Le mot de passe doit contenir au moins 8 caractères, dont une majuscule, un chiffre et un caractère spécial.
                </p>
            </div>
            <div class="modal-actions">
                <button class="btn btn-outline" onclick="closePasswordModal()">Annuler</button>
                <button class="btn btn-primary" onclick="changePassword()">Enregistrer</button>
            </div>
        </div>
    </div>

    <script>
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            initSidebar();
            
            // Écouteur pour le bouton d'enregistrement
            document.getElementById('saveBtn').addEventListener('click', saveChanges);
        });

        // Fonction pour afficher/masquer le mot de passe
        function togglePasswordVisibility(btn) {
            const input = btn ? btn.parentElement.querySelector('input') : document.getElementById('password');
            if (input.type === 'password') {
                input.type = 'text';
                btn ? btn.innerHTML = '<i class="fas fa-eye-slash"></i>' : null;
            } else {
                input.type = 'password';
                btn ? btn.innerHTML = '<i class="fas fa-eye"></i>' : null;
            }
        }

        // Fonction pour afficher la modal de changement de mot de passe
        function showChangePasswordModal() {
            document.getElementById('passwordModal').style.display = 'flex';
        }

        // Fonction pour fermer la modal
        function closePasswordModal() {
            document.getElementById('passwordModal').style.display = 'none';
            // Réinitialiser les champs
            document.getElementById('currentPassword').value = '';
            document.getElementById('newPassword').value = '';
            document.getElementById('confirmPassword').value = '';
        }

        // Fonction pour changer le mot de passe
        function changePassword() {
            const currentPassword = document.getElementById('currentPassword').value;
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            // Validation simple
            if (!currentPassword || !newPassword || !confirmPassword) {
                showAlert('Veuillez remplir tous les champs', 'error');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                showAlert('Les nouveaux mots de passe ne correspondent pas', 'error');
                return;
            }
            
            if (newPassword.length < 8) {
                showAlert('Le mot de passe doit contenir au moins 8 caractères', 'error');
                return;
            }
            
            // Ici, vous devriez faire un appel AJAX pour changer le mot de passe
            showLoading(true);
            
            // Simulation d'un appel AJAX
            setTimeout(() => {
                showLoading(false);
                showAlert('Mot de passe changé avec succès', 'success');
                closePasswordModal();
            }, 1500);
        }

        // Fonction pour enregistrer les modifications
        function saveChanges() {
            const formData = {
                nom: document.getElementById('nom').value,
                prenom: document.getElementById('prenom').value,
                date_naissance: document.getElementById('date_naissance').value,
                genre: document.getElementById('genre').value,
                email: document.getElementById('email').value,
                telephone: document.getElementById('telephone').value,
                adresse: document.getElementById('adresse').value
            };
            
            // Validation simple
            if (!formData.nom || !formData.prenom || !formData.email) {
                showAlert('Veuillez remplir les champs obligatoires', 'error');
                return;
            }
            
            showLoading(true);
            
            // Ici, vous devriez faire un appel AJAX pour sauvegarder les données
            // Exemple avec fetch:
            fetch('update_membre_info.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                showLoading(false);
                if (data.success) {
                    showAlert('Informations mises à jour avec succès', 'success');
                } else {
                    showAlert(data.message || 'Erreur lors de la mise à jour', 'error');
                }
            })
            .catch(error => {
                showLoading(false);
                showAlert('Erreur de connexion', 'error');
                console.error('Error:', error);
            });
        }

        // Fonction pour afficher les alertes
        function showAlert(message, type = 'info') {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.textContent = message;
            alertDiv.className = `alert ${type}`;
            alertDiv.style.display = 'block';
            setTimeout(() => {
                alertDiv.style.display = 'none';
            }, 5000);
        }

        // Fonction pour afficher/cacher le loading
        function showLoading(show) {
            const overlay = document.getElementById('loadingOverlay');
            overlay.style.display = show ? 'flex' : 'none';
        }

        // [Conserver les autres fonctions existantes comme initSidebar()]
    </script>
</body>
</html>