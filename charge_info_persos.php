<?php
// charge_info_persos.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Ici vous devriez récupérer les informations de l'utilisateur connecté depuis la base de données
// $userData = getUserData($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Informations personnelles</title>
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

        /* === RESET === */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--font-primary); background-color: var(--gray-50); color: var(--gray-800); overflow-x: hidden; }

        /* === LAYOUT PRINCIPAL === */
        .admin-layout { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-width); transition: margin-left var(--transition-normal); }
        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed-width); }

        /* === PAGE CONTENT === */
        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); display: flex; justify-content: space-between; align-items: center; }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); margin-top: var(--space-2); }

        /* === PROFIL === */
        .profile-container { display: grid; grid-template-columns: 300px 1fr; gap: var(--space-6); }
        
        .profile-card { 
            background: var(--white); 
            border-radius: var(--radius-xl); 
            padding: var(--space-6); 
            box-shadow: var(--shadow-sm); 
            border: 1px solid var(--gray-200);
            text-align: center;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background-color: var(--gray-100);
            margin: 0 auto var(--space-4);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 3px solid var(--gray-200);
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-avatar .fa-user {
            font-size: 3rem;
            color: var(--gray-500);
        }
        
        .profile-name {
            font-size: var(--text-xl);
            font-weight: 600;
            margin-bottom: var(--space-1);
        }
        
        .profile-id {
            color: var(--gray-500);
            font-size: var(--text-sm);
            margin-bottom: var(--space-3);
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
        
        .profile-details {
            margin-top: var(--space-6);
            text-align: left;
        }
        
        .detail-item {
            margin-bottom: var(--space-3);
        }
        
        .detail-label {
            display: block;
            font-size: var(--text-xs);
            color: var(--gray-500);
            margin-bottom: var(--space-1);
        }
        
        .detail-value {
            font-weight: 500;
        }
        
        /* === FORMULAIRE === */
        .form-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
        }
        
        .form-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-6);
        }
        
        .form-card-title {
            font-size: var(--text-xl);
            font-weight: 600;
        }
        
        .form-card-icon {
            color: var(--accent-500);
            font-size: var(--text-2xl);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--space-6);
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
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-4);
            margin-top: var(--space-6);
            padding-top: var(--space-4);
            border-top: 1px solid var(--gray-200);
        }
        
        .btn {
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            text-decoration: none;
        }
        
        .btn-primary {
            background-color: var(--accent-600);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--accent-700);
        }
        
        .btn-outline {
            background-color: transparent;
            color: var(--accent-600);
            border: 1px solid var(--accent-600);
        }
        
        .btn-outline:hover {
            background-color: var(--accent-50);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_chargee_communication.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1 class="page-title-main">Informations personnelles</h1>
                        <p class="page-subtitle">Gérez vos informations et vos paramètres de compte</p>
                    </div>
                    <div>
                        <button id="saveBtn" class="btn btn-primary">
                            <i class="fas fa-save"></i> Enregistrer les modifications
                        </button>
                    </div>
                </div>

                <div class="profile-container">
                    <!-- Carte de profil -->
                    <div class="profile-card">
                        <div class="profile-avatar">
                            
                                <img src="" alt="Photo de profil">
                            
                                <i class="fas fa-user"></i>
                            
                        </div>
                        <h3 class="profile-name"></h3>
                        <div class="profile-id">Charge de communication</div>
                        <div class="role-badge">
                            <i class="fas fa-bullhorn"></i>
                            Communication
                        </div>
                        
                        <div class="profile-details">
                            <div class="detail-item">
                                <span class="detail-label">Date d'adhésion:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($userData['date_inscription'] ?? 'Non disponible'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Dernière connexion:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($userData['derniere_connexion'] ?? 'Non disponible'); ?></span>
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
                        <div class="form-card">
                            <div class="form-card-header">
                                <h3 class="form-card-title">Informations Personnelles</h3>
                                <div class="form-card-icon">
                                    <i class="fas fa-id-card"></i>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="prenom" class="detail-label">Prénom</label>
                                    <input type="text" class="form-control" id="prenom" name="prenom" value="<?php echo htmlspecialchars($userData['prenom'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="nom" class="detail-label">Nom</label>
                                    <input type="text" class="form-control" id="nom" name="nom" value="<?php echo htmlspecialchars($userData['nom'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Coordonnées -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <h3 class="form-card-title">Coordonnées</h3>
                                <div class="form-card-icon">
                                    <i class="fas fa-address-book"></i>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="email" class="detail-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="telephone" class="detail-label">Téléphone</label>
                                    <input type="tel" class="form-control" id="telephone" name="telephone" value="<?php echo htmlspecialchars($userData['telephone'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Sécurité -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <h3 class="form-card-title">Sécurité du compte</h3>
                                <div class="form-card-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="detail-label">Nom d'utilisateur</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($userData['username'] ?? ''); ?>" disabled>
                                </div>
                                <div class="form-group">
                                    <label class="detail-label">Mot de passe</label>
                                    <div style="display: flex; align-items: center;">
                                        <input type="password" class="form-control" value="********" disabled style="flex: 1;">
                                        <button class="btn btn-outline" style="margin-left: var(--space-2);" onclick="showChangePasswordModal()">
                                            <i class="fas fa-key"></i> Modifier
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
                <div class="form-group">
                    <label for="currentPassword" class="detail-label">Mot de passe actuel</label>
                    <input type="password" class="form-control" id="currentPassword">
                </div>
                <div class="form-group">
                    <label for="newPassword" class="detail-label">Nouveau mot de passe</label>
                    <input type="password" class="form-control" id="newPassword">
                </div>
                <div class="form-group">
                    <label for="confirmPassword" class="detail-label">Confirmer le mot de passe</label>
                    <input type="password" class="form-control" id="confirmPassword">
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
        // Fonction pour afficher la modal de changement de mot de passe
        function showChangePasswordModal() {
            document.getElementById('passwordModal').style.display = 'flex';
        }

        // Fonction pour fermer la modal
        function closePasswordModal() {
            document.getElementById('passwordModal').style.display = 'none';
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
                alert('Veuillez remplir tous les champs');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                alert('Les nouveaux mots de passe ne correspondent pas');
                return;
            }
            
            if (newPassword.length < 8) {
                alert('Le mot de passe doit contenir au moins 8 caractères');
                return;
            }
            
            // Ici, vous devriez faire un appel AJAX pour changer le mot de passe
            console.log('Changement de mot de passe en cours...');
            
            // Simulation d'un appel AJAX
            setTimeout(() => {
                alert('Mot de passe changé avec succès');
                closePasswordModal();
            }, 1500);
        }

        // Fonction pour enregistrer les modifications
        document.getElementById('saveBtn').addEventListener('click', function() {
            const formData = {
                prenom: document.getElementById('prenom').value,
                nom: document.getElementById('nom').value,
                email: document.getElementById('email').value,
                telephone: document.getElementById('telephone').value
            };
            
            // Validation simple
            if (!formData.prenom || !formData.nom || !formData.email) {
                alert('Veuillez remplir les champs obligatoires');
                return;
            }
            
            // Ici, vous devriez faire un appel AJAX pour sauvegarder les données
            console.log('Enregistrement des modifications...', formData);
            
            // Simulation d'un appel AJAX
            setTimeout(() => {
                alert('Informations mises à jour avec succès');
            }, 1500);
        });

        // Gestion du changement de photo de profil
        document.getElementById('avatarInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const avatar = document.querySelector('.profile-avatar');
                    avatar.innerHTML = `<img src="${event.target.result}" alt="Photo de profil">`;
                    
                    // Ici, vous devriez envoyer la photo au serveur
                    console.log('Envoi de la nouvelle photo...');
                };
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>