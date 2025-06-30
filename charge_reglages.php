<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Paramètres Chargé Communication</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Copier exactement le même CSS que parametres_doyen.php */
        :root {
            --primary-50: #f8fafc; --primary-100: #f1f5f9; --primary-200: #e2e8f0; --primary-300: #cbd5e1; --primary-400: #94a3b8; --primary-500: #64748b; --primary-600: #475569; --primary-700: #334155; --primary-800: #1e293b; --primary-900: #0f172a;
            --accent-50: #eff6ff; --accent-100: #dbeafe; --accent-200: #bfdbfe; --accent-300: #93c5fd; --accent-400: #60a5fa; --accent-500: #3b82f6; --accent-600: #2563eb; --accent-700: #1d4ed8; --accent-800: #1e40af; --accent-900: #1e3a8a;
            --success-500: #22c55e; --warning-500: #f59e0b; --error-500: #ef4444; --info-500: #3b82f6;
            --white: #ffffff; --gray-50: #f9fafb; --gray-100: #f3f4f6; --gray-200: #e5e7eb; --gray-300: #d1d5db; --gray-400: #9ca3af; --gray-500: #6b7280; --gray-600: #4b5563; --gray-700: #374151; --gray-800: #1f2937; --gray-900: #111827;
        }

        .settings-container {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .settings-tabs {
            display: flex;
            border-bottom: 1px solid var(--gray-200);
        }

        .settings-tab {
            padding: 1rem 1.5rem;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray-600);
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }

        .settings-tab.active {
            color: var(--accent-600);
            border-bottom-color: var(--accent-600);
            background: var(--accent-50);
        }

        .settings-tab:hover:not(.active) {
            background: var(--gray-50);
        }

        .settings-content {
            padding: 2rem;
        }

        .settings-section {
            margin-bottom: 2rem;
            display: none;
        }

        .settings-section.active {
            display: block;
        }

        .settings-section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--gray-700);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--gray-300);
            border-radius: 0.375rem;
            font-family: inherit;
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: var(--accent-600);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background-color: var(--accent-700);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
        }

        .btn-outline:hover {
            background-color: var(--gray-100);
        }

        .notification-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-icon {
            margin-right: 1rem;
            font-size: 1.25rem;
            color: var(--accent-500);
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .notification-desc {
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--gray-300);
            transition: .4s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--accent-500);
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        .security-log {
            border: 1px solid var(--gray-200);
            border-radius: 0.5rem;
            overflow: hidden;
        }

        .log-item {
            display: grid;
            grid-template-columns: 150px 1fr 120px;
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.875rem;
        }

        .log-item:last-child {
            border-bottom: none;
        }

        .log-time {
            color: var(--gray-600);
            font-weight: 500;
        }

        .log-action {
            color: var(--gray-800);
        }

        .log-ip {
            color: var(--gray-500);
            text-align: right;
        }

        .log-success {
            color: var(--success-500);
        }

        .log-warning {
            color: var(--warning-500);
        }

        .avatar-upload {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .avatar-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--gray-200);
        }

        .avatar-actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
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
                        <h1 class="page-title-main">Paramètres</h1>
                        <p class="page-subtitle">Gérez les préférences de votre compte</p>
                    </div>
                </div>

                <div class="settings-container">
                    <div class="settings-tabs">
                        <div class="settings-tab active" data-tab="profile">Profil</div>
                        <div class="settings-tab" data-tab="security">Sécurité</div>
                        <div class="settings-tab" data-tab="notifications">Notifications</div>
                        <div class="settings-tab" data-tab="communication">Communication</div>
                    </div>

                    <div class="settings-content">
                        <!-- Onglet Profil -->
                        <div class="settings-section active" id="profile-section">
                            <h3 class="settings-section-title">Informations du profil</h3>
                            
                            <div class="avatar-upload">
                                <img src="assets/images/avatar_charge.jpg" alt="Photo de profil" class="avatar-preview">
                                <div class="avatar-actions">
                                    <button class="btn btn-outline">
                                        <i class="fas fa-camera"></i> Changer la photo
                                    </button>
                                    <button class="btn btn-outline">
                                        <i class="fas fa-trash"></i> Supprimer
                                    </button>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="first-name">Prénom</label>
                                    <input type="text" id="first-name" value="Marie">
                                </div>
                                <div class="form-group">
                                    <label for="last-name">Nom</label>
                                    <input type="text" id="last-name" value="Durand">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="title">Poste</label>
                                <input type="text" id="title" value="Chargé de Communication">
                            </div>

                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" value="marie.durand@universite.fr">
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="phone">Téléphone</label>
                                    <input type="tel" id="phone" value="+33 1 23 45 67 89">
                                </div>
                                <div class="form-group">
                                    <label for="office">Bureau</label>
                                    <input type="text" id="office" value="Bâtiment B, Bureau 105">
                                </div>
                            </div>

                            <div class="form-actions">
                                <button class="btn btn-outline">Annuler</button>
                                <button class="btn btn-primary">Enregistrer</button>
                            </div>
                        </div>

                        <!-- Onglet Sécurité -->
                        <div class="settings-section" id="security-section">
                            <h3 class="settings-section-title">Sécurité du compte</h3>
                            
                            <form method="POST">
                                <input type="hidden" name="change_password" value="1">
                                
                                <div class="form-group">
                                    <label>Mot de passe</label>
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <span>********</span>
                                        <button type="button" class="btn btn-outline" id="showPasswordForm">Changer</button>
                                    </div>
                                </div>

                                <div id="passwordForm" style="display: none;">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="current_password">Mot de passe actuel</label>
                                            <input type="password" id="current_password" name="current_password" required>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="new_password">Nouveau mot de passe</label>
                                            <input type="password" id="new_password" name="new_password" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="confirm_password">Confirmer le mot de passe</label>
                                            <input type="password" id="confirm_password" name="confirm_password" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="2fa">Authentification à deux facteurs</label>
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <label class="switch">
                                            <input type="checkbox" id="2fa" name="2fa" checked>
                                            <span class="slider"></span>
                                        </label>
                                        <span>Activée</span>
                                    </div>
                                    <p style="font-size: 0.875rem; color: var(--gray-600); margin-top: 0.5rem;">
                                        Une vérification supplémentaire sera requise lors de la connexion.
                                    </p>
                                </div>

                                <div class="form-actions">
                                    <button type="reset" class="btn btn-outline">Annuler</button>
                                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                                </div>
                            </form>
                        </div>

                        <!-- Onglet Notifications -->
                        <div class="settings-section" id="notifications-section">
                            <h3 class="settings-section-title">Préférences de notifications</h3>
                            
                            <form method="POST">
                                <input type="hidden" name="update_notifications" value="1">
                                
                                <div class="notification-item">
                                    <div class="notification-icon">
                                        <i class="fas fa-envelope"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">Email</div>
                                        <div class="notification-desc">Recevoir les notifications par email</div>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" name="notif_email" checked>
                                        <span class="slider"></span>
                                    </label>
                                </div>

                                <div class="notification-item">
                                    <div class="notification-icon">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">Notifications système</div>
                                        <div class="notification-desc">Afficher les notifications sur le site</div>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" name="notif_system" checked>
                                        <span class="slider"></span>
                                    </label>
                                </div>

                                <h3 class="settings-section-title" style="margin-top: 2rem;">Types de notifications</h3>
                                
                                <div class="notification-item">
                                    <div class="notification-icon">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">Nouveaux comptes rendus</div>
                                        <div class="notification-desc">Alertes lorsqu'un nouveau compte rendu est disponible</div>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" name="notif_rapports" checked>
                                        <span class="slider"></span>
                                    </label>
                                </div>

                                <div class="notification-item">
                                    <div class="notification-icon">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">Nouvelles entreprises</div>
                                        <div class="notification-desc">Alertes lorsqu'une nouvelle entreprise est enregistrée</div>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" name="notif_entreprises">
                                        <span class="slider"></span>
                                    </label>
                                </div>

                                <div class="form-actions">
                                    <button type="reset" class="btn btn-outline">Réinitialiser</button>
                                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                                </div>
                            </form>
                        </div>

                        <!-- Onglet Communication -->
                        <div class="settings-section" id="communication-section">
                            <h3 class="settings-section-title">Paramètres de communication</h3>
                            
                            <form>
                                <div class="form-group">
                                    <label for="signature">Signature email</label>
                                    <textarea id="signature" name="signature" rows="4">Chargé de Communication
Université de Paris
Tél: +33 1 23 45 67 89</textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="email_template">Modèle d'email standard</label>
                                    <textarea id="email_template" name="email_template" rows="6">Bonjour,

Veuillez trouver ci-joint les informations demandées.

Cordialement,</textarea>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="reset" class="btn btn-outline">Annuler</button>
                                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Gestion des onglets
        document.querySelectorAll('.settings-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Désactiver tous les onglets
                document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.settings-section').forEach(s => s.classList.remove('active'));
                
                // Activer l'onglet courant
                this.classList.add('active');
                document.getElementById(`${this.dataset.tab}-section`).classList.add('active');
            });
        });

        // Gestion du changement d'avatar
        document.querySelector('.avatar-upload button:first-child').addEventListener('click', function() {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.onchange = e => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = event => {
                        document.querySelector('.avatar-preview').src = event.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            };
            input.click();
        });

        // Gestion de la suppression d'avatar
        document.querySelector('.avatar-upload button:last-child').addEventListener('click', function() {
            if (confirm("Voulez-vous vraiment supprimer votre photo de profil ?")) {
                document.querySelector('.avatar-preview').src = 'assets/images/avatar_default.png';
            }
        });

        // Afficher/masquer le formulaire de mot de passe
        document.getElementById('showPasswordForm').addEventListener('click', function() {
            const form = document.getElementById('passwordForm');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
            this.textContent = form.style.display === 'none' ? 'Changer' : 'Masquer';
        });
    </script>
</body>
</html>