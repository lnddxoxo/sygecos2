<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Informations Personnelles</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Styles CSS similaires à ceux du fichier depot_rapport.php */
        :root {
            --primary-50: #f8fafc; --primary-100: #f1f5f9; --primary-200: #e2e8f0; --primary-300: #cbd5e1; --primary-400: #94a3b8; --primary-500: #64748b; --primary-600: #475569; --primary-700: #334155; --primary-800: #1e293b; --primary-900: #0f172a;
            --accent-50: #eff6ff; --accent-100: #dbeafe; --accent-200: #bfdbfe; --accent-300: #93c5fd; --accent-400: #60a5fa; --accent-500: #3b82f6; --accent-600: #2563eb; --accent-700: #1d4ed8; --accent-800: #1e40af; --accent-900: #1e3a8a;
            --white: #ffffff; --gray-50: #f9fafb; --gray-100: #f3f4f6; --gray-200: #e5e7eb; --gray-300: #d1d5db; --gray-400: #9ca3af; --gray-500: #6b7280; --gray-600: #4b5563; --gray-700: #374151; --gray-800: #1f2937; --gray-900: #111827;
        }

        .profile-container {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .profile-header {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--accent-500);
            margin-right: 2rem;
        }

        .profile-info h2 {
            font-size: 1.75rem;
            color: var(--primary-800);
            margin-bottom: 0.5rem;
        }

        .profile-info p {
            color: var(--gray-600);
            margin-bottom: 0.25rem;
        }

        .profile-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .detail-card {
            background: var(--gray-50);
            border-radius: 0.5rem;
            padding: 1.5rem;
            border-left: 4px solid var(--accent-500);
        }

        .detail-card h3 {
            font-size: 1rem;
            color: var(--gray-600);
            margin-bottom: 0.75rem;
        }

        .detail-card p {
            font-size: 1.125rem;
            color: var(--gray-800);
            font-weight: 500;
        }

        .edit-btn {
            background-color: var(--accent-500);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .edit-btn:hover {
            background-color: var(--accent-600);
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_doyen.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1 class="page-title-main">Informations Personnelles</h1>
                        <p class="page-subtitle">Gérez votre profil et vos informations</p>
                    </div>
                </div>

                <div class="profile-container">
                    <div class="profile-header">
                        <img src="assets/images/avatar_doyen.jpg" alt="Photo de profil" class="profile-avatar">
                        <div class="profile-info">
                            <h2>Pr. Jean Dupont</h2>
                            <p><i class="fas fa-briefcase"></i> Doyen de la Faculté des Sciences</p>
                            <p><i class="fas fa-university"></i> Université de Paris</p>
                            <p><i class="fas fa-envelope"></i> jean.dupont@universite.fr</p>
                            <button class="edit-btn">Modifier le profil</button>
                        </div>
                    </div>

                    <div class="profile-details">
                        <div class="detail-card">
                            <h3>Informations académiques</h3>
                            <p><strong>Grade:</strong> Professeur des Universités</p>
                            <p><strong>Spécialité:</strong> Informatique</p>
                            <p><strong>Laboratoire:</strong> LIP6</p>
                        </div>

                        <div class="detail-card">
                            <h3>Coordonnées</h3>
                            <p><strong>Téléphone:</strong> +33 1 23 45 67 89</p>
                            <p><strong>Bureau:</strong> Bâtiment A, Bureau 203</p>
                            <p><strong>Disponibilités:</strong> Lundi - Vendredi, 9h-12h</p>
                        </div>

                        <div class="detail-card">
                            <h3>Statistiques</h3>
                            <p><strong>Comptes rendus à traiter:</strong> 5</p>
                            <p><strong>Messages non lus:</strong> 3</p>
                            <p><strong>Dernière connexion:</strong> Aujourd'hui, 09:42</p>
                        </div>

                        <div class="detail-card">
                            <h3>Sécurité</h3>
                            <p><strong>Dernière modification:</strong> 15/06/2023</p>
                            <p><strong>Mot de passe:</strong> *********</p>
                            <button class="edit-btn">Changer mot de passe</button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>