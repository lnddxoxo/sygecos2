<?php
// inscription_etudiant.php
require_once 'config.php'; // Assurez-vous que ce fichier inclut votre connexion PDO et les fonctions isLoggedIn/redirect

if (!isLoggedIn()) {
    redirect('loginForm.php'); // Redirige si l'utilisateur n'est pas connecté
}

// Récupérer les années académiques pour la liste déroulante
$anneesAcademiques = [];
try {
    $stmt = $pdo->query("SELECT id_Ac, CONCAT(YEAR(date_deb), '-', YEAR(date_fin)) as annee_libelle, date_deb, date_fin FROM année_academique ORDER BY date_deb DESC");
    $anneesAcademiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de récupération des années académiques: " . $e->getMessage());
    // Gérer l'erreur, par exemple, afficher un message à l'utilisateur
}

// Récupérer les filières pour la liste déroulante (de la table 'filiere')
$filieres = [];
try {
    $stmt = $pdo->query("SELECT id_filiere, lib_filiere FROM filiere ORDER BY lib_filiere ASC");
    $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de récupération des filières: " . $e->getMessage());
}

// Récupérer les niveaux pour la liste déroulante (de la table 'niveau_etude')
$niveauxEtude = [];
try {
    $stmt = $pdo->query("SELECT id_niv_etu, lib_niv_etu FROM niveau_etude ORDER BY lib_niv_etu ASC");
    $niveauxEtude = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de récupération des niveaux d'étude: " . $e->getMessage());
}


// Traitement AJAX pour l'ajout d'un étudiant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_etudiant') {
    header('Content-Type: application/json');
    
    $nom = trim($_POST['nom']);
    $prenom = trim($_POST['prenom']);
    $dateNaissance = $_POST['date_naissance'];
    $lieuNaissance = trim($_POST['lieu_naissance']);
    $email = trim($_POST['email']);
    $telephone = trim($_POST['telephone']);
    $niveauId = trim($_POST['niveau']);   // Maintenant c'est l'ID du niveau
    $filiereId = trim($_POST['filiere']); // Maintenant c'est l'ID de la filière
    $anneeAcademiqueId = $_POST['annee_academique'];

    try {
        $pdo->beginTransaction();

        // --- Validation côté serveur ---
        if (empty($nom) || empty($prenom) || empty($dateNaissance) || empty($email) || empty($niveauId) || empty($filiereId) || empty($anneeAcademiqueId)) {
            throw new Exception("Tous les champs obligatoires (Nom, Prénom, Date de naissance, Email, Niveau, Filière, Année Académique) doivent être remplis.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Format d'email invalide.");
        }

        // Vérifier l'âge minimum de 14 ans
        $stmtAnnee = $pdo->prepare("SELECT date_deb FROM année_academique WHERE id_Ac = ?");
        $stmtAnnee->execute([$anneeAcademiqueId]);
        $anneeAcademiqueInfo = $stmtAnnee->fetch(PDO::FETCH_ASSOC);

        if (!$anneeAcademiqueInfo) {
            throw new Exception("Année académique sélectionnée introuvable.");
        }

        $anneeAcademiqueDebut = new DateTime($anneeAcademiqueInfo['date_deb']);
        $dateNaiss = new DateTime($dateNaissance);
        $diff = $dateNaiss->diff($anneeAcademiqueDebut);
        $age = $diff->y;

        if ($age < 14 || ($age == 14 && $dateNaiss->format('m-d') > $anneeAcademiqueDebut->format('m-d'))) {
            throw new Exception("L'étudiant doit avoir au moins 14 ans au début de l'année académique sélectionnée.");
        }
        
        // Vérifier si l'email existe déjà pour un autre utilisateur (étudiant, enseignant ou personnel)
        $checkEmailStmt = $pdo->prepare("
            SELECT COUNT(*) as total FROM (
                SELECT email_etu as email FROM etudiant WHERE email_etu = ?
                UNION ALL
                SELECT email FROM enseignant WHERE email = ?
                UNION ALL
                SELECT email_pers as email FROM personnel_admin WHERE email_pers = ?
            ) as emails
        ");
        $checkEmailStmt->execute([$email, $email, $email]);
        $emailExists = $checkEmailStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($emailExists > 0) {
            throw new Exception("Cet email est déjà utilisé par un autre utilisateur (étudiant, enseignant ou personnel).");
        }

        // Générer un numéro étudiant selon le format : 3 lettres nom + 3 lettres prénom + date (JJMMAA)
        $nomCode = strtoupper(substr(str_replace(' ', '', $nom), 0, 3));
        $prenomCode = strtoupper(substr(str_replace(' ', '', $prenom), 0, 3));
        $dateCode = date('dmy', strtotime($dateNaissance)); // JJMMAA
        $numEtu = $nomCode . $prenomCode . $dateCode;
        
        // Vérifier si ce numéro existe déjà, si oui ajouter un suffixe
        $counter = 1;
        $originalNumEtu = $numEtu;
        while (true) {
            $checkNumStmt = $pdo->prepare("SELECT COUNT(*) FROM etudiant WHERE num_etu = ?");
            $checkNumStmt->execute([$numEtu]);
            if ($checkNumStmt->fetchColumn() == 0) {
                break;
            }
            $numEtu = $originalNumEtu . $counter;
            $counter++;
        }

        // Récupérer le libellé du niveau et de la filière pour le message de succès
        $stmtLibNiveau = $pdo->prepare("SELECT lib_niv_etu FROM niveau_etude WHERE id_niv_etu = ?");
        $stmtLibNiveau->execute([$niveauId]);
        $libNiveau = $stmtLibNiveau->fetchColumn();

        $stmtLibFiliere = $pdo->prepare("SELECT lib_filiere FROM filiere WHERE id_filiere = ?");
        $stmtLibFiliere->execute([$filiereId]);
        $libFiliere = $stmtLibFiliere->fetchColumn();

        // ========== CORRECTION : GÉRER fk_id_util NOT NULL ==========
        // 1. GÉNÉRER LES IDs (comme dans votre code personnel admin)
        $stmtMaxUtil = $pdo->query("SELECT COALESCE(MAX(id_util), 0) + 1 FROM utilisateur");
        $idUtil = $stmtMaxUtil->fetchColumn();
        
        // 2. CRÉER L'UTILISATEUR D'ABORD (avec login et mot de passe null)
        // Ceci garantit que fk_id_util ne sera jamais null
        $stmtUser = $pdo->prepare("
            INSERT INTO utilisateur (id_util, login_util, mdp_util, last_activity) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmtUser->execute([$idUtil, null, null]);

        // 3. Insérer dans la table `etudiant` AVEC l'ID utilisateur
        $stmtEtu = $pdo->prepare("INSERT INTO etudiant (num_etu, fk_id_util, nom_etu, prenoms_etu, dte_naiss_etu, email_etu, lieu_naissance, telephone, fk_id_filiere, fk_id_niv_etu) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtEtu->execute([$numEtu, $idUtil, $nom, $prenom, $dateNaissance, $email, $lieuNaissance, $telephone, $filiereId, $niveauId]);

        // 4. Insérer l'inscription dans la table inscrire (optionnel, selon votre logique métier)
        $stmtMaxInsc = $pdo->query("SELECT COALESCE(MAX(id_insc), 0) + 1 as next_id FROM inscrire");
        $idInsc = $stmtMaxInsc->fetch(PDO::FETCH_ASSOC)['next_id'];
        
        $stmtInsc = $pdo->prepare("INSERT INTO inscrire (id_insc, fk_num_etu, fk_id_Ac, fk_id_niv_etu, dte_insc, montant_insc) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtInsc->execute([$idInsc, $numEtu, $anneeAcademiqueId, $niveauId, date('Y-m-d'), 0]);

        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Étudiant {$prenom} {$nom} enregistré avec succès ! Son numéro étudiant est : {$numEtu}.",
            'data' => [
                'num_etu' => $numEtu,
                'nom_etu' => $nom,
                'prenoms_etu' => $prenom,
                'email_etu' => $email,
                'annee_academique' => $anneeAcademiqueId,
                'niveau_id' => $niveauId,
                'lib_niveau' => $libNiveau,
                'filiere_id' => $filiereId,
                'lib_filiere' => $libFiliere,
                'has_credentials' => false // Indique que l'étudiant n'a pas encore d'identifiants générés
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'enregistrement : ' . $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Inscription Étudiant</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* === VARIABLES CSS === */
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
        .form-group input[type="text"], .form-group input[type="email"], .form-group input[type="tel"], .form-group input[type="date"], .form-group select { padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); font-size: var(--text-base); color: var(--gray-800); transition: all var(--transition-fast); }
        .form-group input[type="text"]:focus, .form-group input[type="email"]:focus, .form-group input[type="tel"]:focus, .form-group input[type="date"]:focus, .form-group select:focus { outline: none; border-color: var(--accent-500); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
        .form-actions { display: flex; gap: var(--space-4); justify-content: flex-end; }
        .btn { padding: var(--space-3) var(--space-5); border-radius: var(--radius-md); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover:not(:disabled) { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover:not(:disabled) { background-color: var(--gray-300); }

        /* Messages d'alerte */
        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); display: none; }
        .alert.success { background-color: var(--secondary-50); color: var(--secondary-600); border: 1px solid var(--secondary-100); }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }

        /* Loading spinner */
        .loading { opacity: 0.6; pointer-events: none; }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .main-content.sidebar-collapsed { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_respo_scolarité.php'; // Assurez-vous que le chemin est correct ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; // Assurez-vous que le chemin est correct ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Inscription Étudiant</h1>
                    <p class="page-subtitle">Formulaire d'inscription d'un nouvel étudiant</p>
                </div>

                <!-- Message d'alerte -->
                <div id="alertMessage" class="alert"></div>

                <div class="form-card">
                    <form id="inscriptionForm">
                        <div class="form-grid">
                            <!-- Année Académique -->
                            <div class="form-group">
                                <label for="annee_academique">Année Académique <span style="color: var(--error-500);">*</span></label>
                                <select id="annee_academique" name="annee_academique" required>
                                    <option value="">Sélectionner l'année académique</option>
                                    <?php foreach ($anneesAcademiques as $annee): ?>
                                        <option value="<?php echo htmlspecialchars($annee['id_Ac']); ?>" data-date-deb="<?php echo htmlspecialchars($annee['date_deb']); ?>">
                                            <?php echo htmlspecialchars($annee['annee_libelle']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="nom">Nom <span style="color: var(--error-500);">*</span></label>
                                <input type="text" id="nom" name="nom" placeholder="Ex: Logbo" required>
                            </div>
                            <div class="form-group">
                                <label for="prenom">Prénom <span style="color: var(--error-500);">*</span></label>
                                <input type="text" id="prenom" name="prenom" placeholder="Ex: David" required>
                            </div>
                            <div class="form-group">
                                <label for="date_naissance">Date de naissance <span style="color: var(--error-500);">*</span></label>
                                <input type="date" id="date_naissance" name="date_naissance" required>
                            </div>
                            <div class="form-group">
                                <label for="lieu_naissance">Lieu de naissance</label>
                                <input type="text" id="lieu_naissance" name="lieu_naissance" placeholder="Ex: Abidjan">
                            </div>
                            <div class="form-group">
                                <label for="email">Email <span style="color: var(--error-500);">*</span></label>
                                <input type="email" id="email" name="email" placeholder="Ex: david.logbo@etudiant.univ.ci" required>
                            </div>
                            <div class="form-group">
                                <label for="telephone">Téléphone</label>
                                <input type="tel" id="telephone" name="telephone" placeholder="Ex: 0707123456">
                            </div>
                            <div class="form-group">
                                <label for="niveau">Niveau d'étude <span style="color: var(--error-500);">*</span></label>
                                <select id="niveau" name="niveau" required>
                                    <option value="">Sélectionner un niveau</option>
                                    <?php foreach ($niveauxEtude as $niveau): ?>
                                        <option value="<?php echo htmlspecialchars($niveau['id_niv_etu']); ?>">
                                            <?php echo htmlspecialchars($niveau['lib_niv_etu']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filiere">Filière <span style="color: var(--error-500);">*</span></label>
                                <select id="filiere" name="filiere" required>
                                    <option value="">Sélectionner une filière</option>
                                    <?php foreach ($filieres as $filiere): ?>
                                        <option value="<?php echo htmlspecialchars($filiere['id_filiere']); ?>">
                                            <?php echo htmlspecialchars($filiere['lib_filiere']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-save"></i> Enregistrer
                            </button>
                            <button type="reset" class="btn btn-secondary" id="cancelBtn">
                                <i class="fas fa-redo"></i> Annuler
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
        // DOM elements
        const inscriptionForm = document.getElementById('inscriptionForm');
        const alertMessageDiv = document.getElementById('alertMessage');
        const submitBtn = document.getElementById('submitBtn');
        const anneeAcademiqueSelect = document.getElementById('annee_academique');
        const dateNaissanceInput = document.getElementById('date_naissance');
        const niveauSelect = document.getElementById('niveau');
        const filiereSelect = document.getElementById('filiere');

        // Fonctions pour gérer la sidebar (si elle existe)
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
            });
        }

        // Fonction pour afficher les messages d'alerte
        function showAlert(message, type = 'info') {
            alertMessageDiv.textContent = message;
            alertMessageDiv.className = `alert ${type}`;
            alertMessageDiv.style.display = 'block';
            setTimeout(() => {
                alertMessageDiv.style.display = 'none';
            }, 5000); // Durée d'affichage du message
        }

        // Fonction pour faire une requête AJAX
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

        // Pré-remplir la filière et le niveau au chargement de la page
        document.addEventListener('DOMContentLoaded', () => {
            // Trouver l'ID du niveau "M2"
            const m2Option = Array.from(niveauSelect.options).find(option => option.textContent === 'M2');
            if (m2Option) {
                niveauSelect.value = m2Option.value; // Pré-rempli à M2
            } else {
                console.warn("Le niveau 'M2' n'est pas disponible dans la base de données. Veuillez l'ajouter via l'interface de gestion des niveaux.");
            }
            
            // Trouver l'ID de la filière "MIAGE"
            const miageOption = Array.from(filiereSelect.options).find(option => option.textContent === 'MIAGE');
            if (miageOption) {
                filiereSelect.value = miageOption.value; // Pré-rempli à MIAGE
            } else {
                console.warn("La filière 'MIAGE' n'est pas disponible dans la base de données. Veuillez l'ajouter via l'interface de gestion des filières.");
            }
        });

        // Validation côté client et soumission du formulaire
        inscriptionForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Réinitialiser les messages d'alerte
            showAlert('', 'info');

            // Récupérer les données du formulaire
            const formData = new FormData(this);
            const nom = formData.get('nom');
            const prenom = formData.get('prenom');
            const dateNaissance = formData.get('date_naissance');
            const email = formData.get('email');
            const anneeAcademiqueId = formData.get('annee_academique');
            const niveauId = formData.get('niveau');   // ID du niveau sélectionné
            const filiereId = formData.get('filiere'); // ID de la filière sélectionnée

            // --- Validation côté client ---
            if (!anneeAcademiqueId) {
                showAlert('Veuillez sélectionner une année académique.', 'error');
                anneeAcademiqueSelect.focus();
                return;
            }
            if (!nom || !prenom || !dateNaissance || !email || !niveauId || !filiereId) {
                showAlert('Veuillez remplir tous les champs obligatoires.', 'error');
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showAlert('Format d\'email invalide.', 'error');
                document.getElementById('email').focus();
                return;
            }

            // Validation de l'âge
            const selectedOption = anneeAcademiqueSelect.options[anneeAcademiqueSelect.selectedIndex];
            const anneeAcademiqueDebutStr = selectedOption.dataset.dateDeb;

            if (anneeAcademiqueDebutStr) {
                const anneeAcademiqueDebut = new Date(anneeAcademiqueDebutStr);
                const dateNaiss = new Date(dateNaissance);

                let age = anneeAcademiqueDebut.getFullYear() - dateNaiss.getFullYear();
                const m = anneeAcademiqueDebut.getMonth() - dateNaiss.getMonth();
                if (m < 0 || (m === 0 && anneeAcademiqueDebut.getDate() < dateNaiss.getDate())) {
                    age--;
                }

                if (age < 14) {
                    showAlert('L\'étudiant doit avoir au moins 14 ans au début de l\'année académique sélectionnée.', 'error');
                    dateNaissanceInput.focus();
                    return;
                }
            } else {
                showAlert('Impossible de valider l\'âge : Année académique de début introuvable.', 'warning');
            }

            // Désactiver le bouton de soumission et afficher l'état de chargement
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement...';

            const dataToSend = {
                action: 'ajouter_etudiant',
                nom: nom,
                prenom: prenom,
                date_naissance: dateNaissance,
                lieu_naissance: formData.get('lieu_naissance'),
                email: email,
                telephone: formData.get('telephone'),
                niveau: niveauId,   // Envoie l'ID du niveau
                filiere: filiereId, // Envoie l'ID de la filière
                annee_academique: anneeAcademiqueId
            };

            try {
                const result = await makeAjaxRequest(dataToSend);

                if (result.success) {
                    showAlert(result.message, 'success');
                    inscriptionForm.reset();
                    // Réinitialiser les champs pré-remplis après le reset du formulaire
                    // Retrouver les options par leur texte pour les sélectionner à nouveau
                    const m2Option = Array.from(niveauSelect.options).find(option => option.textContent === 'M2');
                    if (m2Option) {
                        niveauSelect.value = m2Option.value;
                    } else {
                        niveauSelect.value = ''; // ou valeur par défaut si M2 n'existe pas
                    }
                    const miageOption = Array.from(filiereSelect.options).find(option => option.textContent === 'MIAGE');
                    if (miageOption) {
                        filiereSelect.value = miageOption.value;
                    } else {
                        filiereSelect.value = ''; // ou valeur par défaut si MIAGE n'existe pas
                    }
                    anneeAcademiqueSelect.value = ''; // Efface l'année académique sélectionnée
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Une erreur est survenue lors de l\'envoi des données au serveur.', 'error');
            } finally {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Enregistrer'; // Restaurer le texte du bouton
            }
        });

        // Gestionnaire du bouton Annuler/Reset
        const cancelBtn = document.getElementById('cancelBtn');
        cancelBtn.addEventListener('click', () => {
            inscriptionForm.reset();
            // Réinitialiser les champs pré-remplis
            const m2Option = Array.from(niveauSelect.options).find(option => option.textContent === 'M2');
            if (m2Option) {
                niveauSelect.value = m2Option.value;
            } else {
                niveauSelect.value = '';
            }
            const miageOption = Array.from(filiereSelect.options).find(option => option.textContent === 'MIAGE');
            if (miageOption) {
                filiereSelect.value = miageOption.value;
            } else {
                filiereSelect.value = '';
            }
            anneeAcademiqueSelect.value = '';
            showAlert('', 'info'); // Efface tout message d'alerte
        });


        // Responsive: Gestion mobile de la sidebar
        function handleResize() {
            if (window.innerWidth <= 768) {
                if (sidebar) sidebar.classList.add('mobile');
            } else {
                if (sidebar) {
                    sidebar.classList.remove('mobile');
                    sidebar.classList.remove('collapsed');
                }
                if (mainContent) mainContent.classList.remove('sidebar-collapsed');
            }
        }

        window.addEventListener('resize', handleResize);
        handleResize(); // Appel au chargement pour l'état initial
    </script>
</body>
</html>