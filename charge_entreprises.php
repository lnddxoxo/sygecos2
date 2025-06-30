<?php
// Display all errors for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// charge_entreprises.php
require_once 'config.php'; // Assurez-vous que ce fichier contient les fonctions isLoggedIn() et redirect() ainsi que la connexion PDO

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Traitement AJAX pour les opérations CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    try {
        $pdo->beginTransaction(); // Start transaction for atomicity

        switch ($action) {
            case 'create':
                $nom = trim($_POST['nom']);
                $secteur = trim($_POST['secteur'] ?? ''); // Use empty string if not set
                $adresse = trim($_POST['adresse'] ?? '');
                $ville = trim($_POST['ville'] ?? '');
                $pays = trim($_POST['pays'] ?? '');
                $telephone = trim($_POST['telephone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $siteWeb = trim($_POST['site_web'] ?? '');
                $contactNom = trim($_POST['contact_nom'] ?? '');
                $contactPoste = trim($_POST['contact_poste'] ?? '');

                // Validation
                if (empty($nom)) {
                    throw new Exception("Le nom de l'entreprise est obligatoire.");
                }

                // Vérifier si une entreprise avec le même nom existe déjà
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM entreprise WHERE lib_entr = ?");
                $stmt->execute([$nom]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("Une entreprise avec ce nom existe déjà.");
                }

                // Générer l'ID pour la nouvelle entreprise (si id_entr n'est pas AUTO_INCREMENT)
                // Si id_entr est AUTO_INCREMENT, cette partie est inutile et $id sera récupéré par lastInsertId()
                // Assurez-vous que `id_entr` est configuré comme AUTO_INCREMENT dans votre DB pour éviter des conflits d'ID.
                // Sinon, cette logique `MAX(id_entr) + 1` doit être sécurisée contre les accès concurrents.
                $stmtMaxId = $pdo->query("SELECT COALESCE(MAX(id_entr), 0) + 1 FROM entreprise");
                $id_entr = $stmtMaxId->fetchColumn();

                // Insérer la nouvelle entreprise
                $stmt = $pdo->prepare("INSERT INTO entreprise (id_entr, lib_entr, secteur, adresse, ville, pays, telephone, email, site_web, contact_nom, contact_poste)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id_entr, $nom, $secteur, $adresse, $ville, $pays, $telephone, $email, $siteWeb, $contactNom, $contactPoste]);

                $pdo->commit(); // Commit transaction on success

                echo json_encode([
                    'success' => true,
                    'message' => 'Entreprise créée avec succès',
                    'data' => [
                        'id_entr' => $id_entr, // Return the generated ID
                        'lib_entr' => $nom,
                        'secteur' => $secteur,
                        'ville' => $ville,
                        'contact_nom' => $contactNom,
                        'contact_poste' => $contactPoste
                    ]
                ]);
                break;

            case 'update':
                $id_entr = intval($_POST['id_entr']); // Use id_entr
                $nom = trim($_POST['nom']);
                $secteur = trim($_POST['secteur'] ?? '');
                $adresse = trim($_POST['adresse'] ?? '');
                $ville = trim($_POST['ville'] ?? '');
                $pays = trim($_POST['pays'] ?? '');
                $telephone = trim($_POST['telephone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $siteWeb = trim($_POST['site_web'] ?? '');
                $contactNom = trim($_POST['contact_nom'] ?? '');
                $contactPoste = trim($_POST['contact_poste'] ?? '');

                // Validation
                if (empty($nom)) {
                    throw new Exception("Le nom de l'entreprise est obligatoire.");
                }

                // Vérifier si le nom existe déjà pour une autre ID
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM entreprise WHERE lib_entr = ? AND id_entr != ?");
                $checkStmt->execute([$nom, $id_entr]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Une autre entreprise avec ce nom existe déjà.");
                }

                $stmt = $pdo->prepare("UPDATE entreprise SET
                    lib_entr = ?, secteur = ?, adresse = ?, ville = ?, pays = ?,
                    telephone = ?, email = ?, site_web = ?, contact_nom = ?, contact_poste = ?
                    WHERE id_entr = ?");
                $stmt->execute([$nom, $secteur, $adresse, $ville, $pays, $telephone, $email, $siteWeb, $contactNom, $contactPoste, $id_entr]);

                $pdo->commit();

                echo json_encode(['success' => true, 'message' => 'Entreprise modifiée avec succès']);
                break;

            case 'delete':
                $ids = json_decode($_POST['ids'], true);
                if (!is_array($ids) || empty($ids)) {
                    throw new Exception('Aucun ID fourni pour la suppression.');
                }
                
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $stmt = $pdo->prepare("DELETE FROM entreprise WHERE id_entr IN ($placeholders)"); // Use id_entr
                $stmt->execute($ids);

                $pdo->commit();

                echo json_encode(['success' => true, 'message' => 'Entreprise(s) supprimée(s) avec succès']);
                break;

            case 'get_entreprises':
                $stmt = $pdo->prepare("SELECT * FROM entreprise ORDER BY lib_entr"); // Use entreprise and lib_entr
                $stmt->execute();
                $entreprises = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'data' => $entreprises]);
                break;

            case 'get_entreprise_by_id':
                $id_entr = intval($_POST['id']); // Requesting with 'id', but it's id_entr in DB
                $stmt = $pdo->prepare("SELECT * FROM entreprise WHERE id_entr = ?");
                $stmt->execute([$id_entr]);
                $entreprise = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($entreprise) {
                    echo json_encode(['success' => true, 'data' => $entreprise]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Entreprise non trouvée.']);
                }
                break;
        }
    } catch (Exception $e) {
        $pdo->rollBack(); // Rollback transaction on error
        error_log("Erreur dans le traitement AJAX (charge_entreprises.php): " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Récupérer les entreprises existantes pour l'affichage initial
$entreprises = [];
try {
    // Vérifiez si la table 'entreprise' existe avant d'essayer de la lire
    $stmt = $pdo->query("SHOW TABLES LIKE 'entreprise'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->query("SELECT * FROM entreprise ORDER BY lib_entr"); // Use entreprise and lib_entr
        $entreprises = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        error_log("Table 'entreprise' n'existe pas. Veuillez la créer ou vérifier sa connexion.");
        // Initialiser $entreprises à un tableau vide ou afficher un message d'erreur plus visible
    }
} catch (Exception $e) {
    error_log("Erreur lors de la récupération des entreprises (initial load): " . $e->getMessage());
    // Gérer l'erreur, par exemple en affichant un message à l'utilisateur
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Gestion des Entreprises Partenaires</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Your CSS styles here (already provided and assumed correct) */
        /* ... (Your provided CSS is included here) ... */
        :root {
            /* Couleurs Primaires */
            --primary-50: #f8fafc;
            --primary-100: #f1f5f9;
            --primary-200: #e2e8f0;
            --primary-300: #cbd5e1;
            --primary-400: #94a3b8;
            --primary-500: #64748b;
            --primary-600: #475569;
            --primary-700: #334155;
            --primary-800: #1e293b;
            --primary-900: #0f172a;

            /* Couleurs d'Accent Bleu */
            --accent-50: #eff6ff;
            --accent-100: #dbeafe;
            --accent-200: #bfdbfe;
            --accent-300: #93c5fd;
            --accent-400: #60a5fa;
            --accent-500: #3b82f6;
            --accent-600: #2563eb;
            --accent-700: #1d4ed8;
            --accent-800: #1e40af;
            --accent-900: #1e3a8a;

            /* Couleurs Secondaires */
            --secondary-50: #f0fdf4;
            --secondary-100: #dcfce7;
            --secondary-500: #22c55e;
            --secondary-600: #16a34a;

            /* Couleurs Sémantiques */
            --success-500: #22c55e;
            --warning-500: #f59e0b;
            --error-500: #ef4444;
            --info-500: #3b82f6;

            /* Couleurs Neutres */
            --white: #ffffff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;

            /* Layout */
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --topbar-height: 70px;

            /* Typographie */
            --font-primary: 'Segoe UI', system-ui, -apple-system, sans-serif;
            --text-xs: 0.75rem;
            --text-sm: 0.875rem;
            --text-base: 1rem;
            --text-lg: 1.125rem;
            --text-xl: 1.25rem;
            --text-2xl: 1.5rem;
            --text-3xl: 1.875rem;

            /* Espacement */
            --space-1: 0.25rem;
            --space-2: 0.5rem;
            --space-3: 0.75rem;
            --space-4: 1rem;
            --space-5: 1.25rem;
            --space-6: 1.5rem;
            --space-8: 2rem;
            --space-10: 2.5rem;
            --space-12: 3rem;
            --space-16: 4rem;

            /* Bordures */
            --radius-sm: 0.25rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
            --radius-3xl: 2rem;

            /* Ombres */
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);

            /* Transitions */
            --transition-fast: 150ms ease-in-out;
            --transition-normal: 250ms ease-in-out;
            --transition-slow: 350ms ease-in-out;
        }

        /* === RESET === */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-primary);
            background-color: var(--gray-50);
            color: var(--gray-800);
            overflow-x: hidden;
        }

        /* === LAYOUT PRINCIPAL === */
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition-normal);
        }

        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
        }

        /* === PAGE CONTENT === */
        .page-content {
            padding: var(--space-6);
        }

        .page-header {
            margin-bottom: var(--space-8);
        }

        .page-title-main {
            font-size: var(--text-3xl);
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: var(--space-2);
        }

        .page-subtitle {
            color: var(--gray-600);
            font-size: var(--text-lg);
        }

        .form-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: var(--space-8);
        }

        .form-card-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: var(--space-6);
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: var(--space-4);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--space-6);
            margin-bottom: var(--space-6);
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: var(--text-sm);
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: var(--space-2);
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="url"],
        .form-group select,
        .form-group textarea {
            padding: var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            color: var(--gray-800);
            transition: all var(--transition-fast);
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="email"]:focus,
        .form-group input[type="tel"]:focus,
        .form-group input[type="url"]:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .form-group select {
            background-color: white;
            cursor: pointer;
        }

        .form-group input:disabled,
        .form-group select:disabled {
            background-color: var(--gray-100);
            color: var(--gray-500);
            cursor: not-allowed;
        }

        .form-actions {
            display: flex;
            gap: var(--space-4);
            justify-content: flex-end;
        }

        .btn {
            padding: var(--space-3) var(--space-5);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-primary {
            background-color: var(--accent-600);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background-color: var(--accent-700);
        }

        .btn-secondary {
            background-color: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-secondary:hover:not(:disabled) {
            background-color: var(--gray-300);
        }

        .table-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-6);
        }

        .table-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
        }

        .table-actions {
            display: flex;
            gap: var(--space-3);
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--text-sm);
            color: var(--gray-800);
        }

        .data-table th,
        .data-table td {
            padding: var(--space-4);
            border-bottom: 1px solid var(--gray-200);
            text-align: left;
        }

        .data-table th {
            background-color: var(--gray-50);
            font-weight: 600;
            color: var(--gray-700);
            text-transform: uppercase;
            font-size: var(--text-xs);
            letter-spacing: 0.05em;
        }

        .data-table tbody tr:hover {
            background-color: var(--gray-100);
        }

        .action-buttons {
            display: flex;
            gap: var(--space-2);
        }

        .action-button {
            padding: var(--space-2);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            cursor: pointer;
            transition: all var(--transition-fast);
            border: none;
            color: white;
        }

        .action-button.edit {
            background-color: var(--warning-500);
        }
        .action-button.edit:hover {
            background-color: #e68a00;
        }

        .action-button.delete {
            background-color: var(--error-500);
        }
        .action-button.delete:hover {
            background-color: #cc3131;
        }

        .action-button.view {
            background-color: var(--accent-500);
        }
        .action-button.view:hover {
            background-color: var(--accent-600);
        }

        /* Checkbox styling */
        .checkbox-container {
            display: block;
            position: relative;
            padding-left: 25px;
            cursor: pointer;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        .checkbox-container input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .checkmark {
            position: absolute;
            top: 50%;
            left: 0;
            transform: translateY(-50%);
            height: 18px;
            width: 18px;
            background-color: var(--gray-200);
            border-radius: var(--radius-sm);
            transition: all var(--transition-fast);
            border: 1px solid var(--gray-300);
        }

        .checkbox-container input:checked ~ .checkmark {
            background-color: var(--accent-600);
            border-color: var(--accent-600);
        }

        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }

        .checkbox-container input:checked ~ .checkmark:after {
            display: block;
        }

        .checkbox-container .checkmark:after {
            left: 6px;
            top: 2px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 3px 3px 0;
            -webkit-transform: rotate(45deg);
            -ms-transform: rotate(45deg);
            transform: rotate(45deg);
        }

        /* Messages d'alerte */
        .alert {
            padding: var(--space-4);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-4);
            display: none;
        }

        .alert.success {
            background-color: var(--secondary-50);
            color: var(--secondary-600);
            border: 1px solid var(--secondary-100);
        }

        .alert.error {
            background-color: #fef2f2;
            color: var(--error-500);
            border: 1px solid #fecaca;
        }

        .alert.warning {
            background-color: #fffbeb;
            color: #92400e;
            border: 1px solid #fed7aa;
        }

        /* Loading spinner */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Badge secteur */
        .secteur-badge {
            display: inline-block;
            padding: var(--space-1) var(--space-2);
            background-color: var(--accent-100);
            color: var(--accent-800);
            border-radius: var(--radius-md);
            font-size: var(--text-xs);
            font-weight: 600;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal {
            background-color: var(--white);
            padding: var(--space-6);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 600px;
            position: relative;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: var(--space-3);
        }

        .modal-title {
            font-size: var(--text-2xl);
            font-weight: 600;
            color: var(--gray-900);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: var(--text-2xl);
            cursor: pointer;
            color: var(--gray-500);
        }

        .modal-close:hover {
            color: var(--gray-700);
        }

        .modal-content {
            margin-bottom: var(--space-6);
        }

        .modal-content .detail-item {
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-4);
            border-bottom: 1px dashed var(--gray-100);
        }

        .modal-content .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .modal-content .detail-item h4 {
            font-size: var(--text-lg);
            color: var(--gray-800);
        }

        .modal-content .detail-item h5 {
            font-size: var(--text-base);
            color: var(--gray-700);
        }

        .modal-content .detail-item p {
            font-size: var(--text-sm);
            color: var(--gray-600);
            line-height: 1.5;
        }

        .modal-content .detail-item p i {
            margin-right: var(--space-2);
            color: var(--accent-500);
        }

        .modal-content .detail-item a {
            color: var(--accent-600);
            text-decoration: none;
        }

        .modal-content .detail-item a:hover {
            text-decoration: underline;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
        }

        .btn-outline:hover {
            background-color: var(--gray-100);
        }


        /* Responsive */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-4);
            }

            .table-actions {
                width: 100%;
                justify-content: flex-end;
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
                    <h1 class="page-title-main">Gestion des Entreprises Partenaires</h1>
                    <p class="page-subtitle">Gérez les entreprises partenaires de l'établissement</p>
                </div>

                <div id="alertMessage" class="alert"></div>

                <div class="form-card">
                    <h3 class="form-card-title">Ajouter une entreprise partenaire</h3>
                    <form id="entrepriseForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="nom">Nom de l'entreprise *</label>
                                <input type="text" id="nom" name="nom" placeholder="Ex: MTN CI" required>
                            </div>
                            <div class="form-group">
                                <label for="secteur">Secteur d'activité *</label>
                                <input type="text" id="secteur" name="secteur" placeholder="Ex: Télécommunications, Agro-alimentaire" required>
                            </div>
                            <div class="form-group">
                                <label for="adresse">Adresse</label>
                                <input type="text" id="adresse" name="adresse" placeholder="Ex: Cocody Riviera, Abidjan">
                            </div>
                            <div class="form-group">
                                <label for="ville">Ville</label>
                                <input type="text" id="ville" name="ville" placeholder="Ex: Abidjan">
                            </div>
                            <div class="form-group">
                                <label for="pays">Pays</label>
                                <input type="text" id="pays" name="pays" placeholder="Ex: Côte d'Ivoire">
                            </div>
                            <div class="form-group">
                                <label for="telephone">Téléphone</label>
                                <input type="tel" id="telephone" name="telephone" placeholder="Ex: +225 07 00 00 00 00">
                            </div>
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" placeholder="Ex: contact@mtn.ci">
                            </div>
                            <div class="form-group">
                                <label for="site_web">Site Web</label>
                                <input type="url" id="site_web" name="site_web" placeholder="Ex: https://www.mtn.ci">
                            </div>
                            <div class="form-group">
                                <label for="contact_nom">Nom du contact</label>
                                <input type="text" id="contact_nom" name="contact_nom" placeholder="Ex: M. Jean Konan">
                            </div>
                            <div class="form-group">
                                <label for="contact_poste">Poste du contact</label>
                                <input type="text" id="contact_poste" name="contact_poste" placeholder="Ex: Responsable RH">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-save"></i> <span id="submitText">Enregistrer</span>
                            </button>
                            <button type="reset" class="btn btn-secondary" id="cancelBtn">
                                <i class="fas fa-redo"></i> Annuler
                            </button>
                        </div>
                    </form>
                </div>

                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">Liste des entreprises partenaires</h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" id="modifierBtn" disabled>
                                <i class="fas fa-edit"></i> Modifier
                            </button>
                            <button class="btn btn-secondary" id="supprimerBtn" disabled>
                                <i class="fas fa-trash-alt"></i> Supprimer
                            </button>
                            <button class="btn btn-secondary" id="exporterBtn">
                                <i class="fas fa-file-export"></i> Exporter
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="entrepriseTable">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Nom</th>
                                    <th>Secteur</th>
                                    <th>Ville</th>
                                    <th>Contact</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($entreprises)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                        <i class="fas fa-building" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucune entreprise partenaire enregistrée. Ajoutez votre première entreprise en utilisant le formulaire ci-dessus.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($entreprises as $entreprise): ?>
                                    <tr data-id="<?php echo htmlspecialchars($entreprise['id_entr'] ?? ''); ?>">
                                        <td>
                                            <label class="checkbox-container">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($entreprise['id_entr'] ?? ''); ?>">
                                                <span class="checkmark"></span>
                                            </label>
                                        </td>
                                        <td><?php echo htmlspecialchars($entreprise['lib_entr'] ?? ''); ?></td>
                                        <td>
                                            <span class="secteur-badge"><?php echo htmlspecialchars($entreprise['secteur'] ?? ''); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($entreprise['ville'] ?? ''); ?></td>
                                        <td>
                                            <?php if (!empty($entreprise['contact_nom'])): ?>
                                                <?php echo htmlspecialchars($entreprise['contact_nom'] ?? ''); ?>
                                                <?php if (!empty($entreprise['contact_poste'])): ?>
                                                    <br><small><?php echo htmlspecialchars($entreprise['contact_poste'] ?? ''); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <small style="color: var(--gray-500);">Non renseigné</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button view" title="Voir détails" onclick="voirEntreprise(<?php echo htmlspecialchars($entreprise['id_entr'] ?? '0'); ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="action-button edit" title="Modifier" onclick="modifierEntreprise(<?php echo htmlspecialchars($entreprise['id_entr'] ?? '0'); ?>)">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="action-button delete" title="Supprimer" onclick="supprimerEntreprise(<?php echo htmlspecialchars($entreprise['id_entr'] ?? '0'); ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
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

    <div class="modal-overlay" id="messageModal" style="display: none;">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="messageTitle"></h3>
                <button class="modal-close" id="messageClose">&times;</button>
            </div>
            <div class="modal-content" style="text-align: center;">
                <div class="message-icon" id="messageIcon"></div>
                <p class="message-text" id="messageText" style="margin-top: 15px;"></p>
            </div>
            <div class="modal-actions" style="justify-content: center;">
                <button class="btn btn-primary" id="messageButton">OK</button>
            </div>
        </div>
    </div>


    <div class="modal-overlay" id="detailsModal" style="display: none;">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Détails de l'entreprise</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-content" id="modalContent">
                </div>
            <div class="modal-actions">
                <button class="btn btn-outline" onclick="closeModal()">Fermer</button>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let selectedEntreprises = new Set();
        let editingEntreprise = null;

        // Éléments DOM
        const entrepriseForm = document.getElementById('entrepriseForm');
        const entrepriseTableBody = document.querySelector('#entrepriseTable tbody');
        const modifierBtn = document.getElementById('modifierBtn');
        const supprimerBtn = document.getElementById('supprimerBtn');
        const exporterBtn = document.getElementById('exporterBtn');
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const cancelBtn = document.getElementById('cancelBtn');
        // const alertMessage = document.getElementById('alertMessage'); // Old alert div, might be removed if using modal alert
        const detailsModal = document.getElementById('detailsModal');

        // New modal alert elements
        const messageModal = document.getElementById('messageModal'); 
        const messageTitle = document.getElementById('messageTitle'); 
        const messageText = document.getElementById('messageText');   
        const messageIcon = document.getElementById('messageIcon');   
        const messageButton = document.getElementById('messageButton'); 
        const messageClose = document.getElementById('messageClose'); 

        // Fonction pour afficher les messages dans une modal (comme gestion_grade_enseignant.php)
        function showAlert(message, type = 'success', title = null) {
            // Define default title based on type
            if (!title) {
                switch (type) {
                    case 'success':
                        title = 'Succès';
                        break;
                    case 'error':
                        title = 'Erreur';
                        break;
                    case 'warning':
                        title = 'Attention';
                        break;
                    case 'info':
                        title = 'Information';
                        break;
                    default:
                        title = 'Message';
                }
            }

            // Define icon based on type
            messageIcon.className = 'message-icon'; // Reset classes
            switch (type) {
                case 'success':
                    messageIcon.classList.add('success');
                    messageIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
                    break;
                case 'error':
                    messageIcon.classList.add('error');
                    messageIcon.innerHTML = '<i class="fas fa-times-circle"></i>'; 
                    break;
                case 'warning':
                    messageIcon.classList.add('warning');
                    messageIcon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                    break;
                case 'info':
                    messageIcon.classList.add('info');
                    messageIcon.innerHTML = '<i class="fas fa-info-circle"></i>';
                    break;
                default:
                    messageIcon.innerHTML = '<i class="fas fa-bell"></i>';
            }

            messageTitle.textContent = title;
            messageText.textContent = message;
            messageModal.style.display = 'flex'; // Use flex to center the modal
        }

        // Close message modal
        function closeMessageModal() {
            messageModal.style.display = 'none';
        }

        // Event listeners for message modal
        if (messageButton) messageButton.addEventListener('click', closeMessageModal);
        if (messageClose) messageClose.addEventListener('click', closeMessageModal);
        if (messageModal) {
            messageModal.addEventListener('click', function(e) {
                if (e.target === messageModal) {
                    closeMessageModal();
                }
            });
        }


        // Function to make an AJAX request
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
                showAlert('Erreur de communication avec le serveur. Vérifiez votre connexion.', 'error');
                throw error;
            }
        }

        // Function to update button states
        function updateActionButtons() {
            if (selectedEntreprises.size === 1) {
                modifierBtn.disabled = false;
                supprimerBtn.disabled = false;
            } else if (selectedEntreprises.size > 1) {
                modifierBtn.disabled = true; // Cannot modify multiple
                supprimerBtn.disabled = false;
            } else {
                modifierBtn.disabled = true;
                supprimerBtn.disabled = true;
            }
        }

        // Function to add a row to the table
        function addRowToTable(entreprise) {
            // Remove "Aucune entreprise" message if exists
            const emptyRow = entrepriseTableBody.querySelector('td[colspan="6"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }

            const newRow = entrepriseTableBody.insertRow();
            // Use 'id_entr' from the data, which is the actual primary key from DB
            newRow.setAttribute('data-id', entreprise.id_entr); 
            newRow.innerHTML = `
                <td>
                    <label class="checkbox-container">
                        <input type="checkbox" value="${entreprise.id_entr}">
                        <span class="checkmark"></span>
                    </label>
                </td>
                <td>${htmlspecialchars(entreprise.lib_entr)}</td>
                <td><span class="secteur-badge">${htmlspecialchars(entreprise.secteur)}</span></td>
                <td>${htmlspecialchars(entreprise.ville)}</td>
                <td>
                    ${entreprise.contact_nom ?
                        `${htmlspecialchars(entreprise.contact_nom)}${entreprise.contact_poste ? '<br><small>' + htmlspecialchars(entreprise.contact_poste) + '</small>' : ''}` :
                        '<small style="color: var(--gray-500);">Non renseigné</small>'}
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="action-button view" title="Voir détails" onclick="voirEntreprise(${entreprise.id_entr})">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="action-button edit" title="Modifier" onclick="modifierEntreprise(${entreprise.id_entr})">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button class="action-button delete" title="Supprimer" onclick="supprimerEntreprise(${entreprise.id_entr})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            `;
            attachEventListenersToRow(newRow);
        }

        // Helper to escape HTML characters
        function htmlspecialchars(str) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            // Ensure str is treated as a string, handling null or undefined
            return String(str ?? '').replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Function to attach event listeners to rows
        function attachEventListenersToRow(row) {
            const checkbox = row.querySelector('input[type="checkbox"]');

            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    selectedEntreprises.add(this.value);
                } else {
                    selectedEntreprises.delete(this.value);
                }
                updateActionButtons();
            });
        }

        // Form submission
        entrepriseForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = {
                action: editingEntreprise ? 'update' : 'create'
            };

            // Add all form fields, using 'nom' for lib_entr
            data.nom = formData.get('nom'); // This maps to lib_entr
            data.secteur = formData.get('secteur');
            data.adresse = formData.get('adresse');
            data.ville = formData.get('ville');
            data.pays = formData.get('pays');
            data.telephone = formData.get('telephone');
            data.email = formData.get('email');
            data.site_web = formData.get('site_web');
            data.contact_nom = formData.get('contact_nom');
            data.contact_poste = formData.get('contact_poste');
            

            if (editingEntreprise) {
                data.id_entr = editingEntreprise; // Pass id_entr for update
            }

            try {
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
                cancelBtn.disabled = true; 

                const result = await makeAjaxRequest(data);

                if (result.success) {
                    if (editingEntreprise) {
                        // Update existing row
                        const row = document.querySelector(`tr[data-id="${editingEntreprise}"]`);
                        if (row) {
                            row.cells[1].textContent = data.nom; // Update 'Nom' column (lib_entr)
                            row.cells[2].innerHTML = `<span class="secteur-badge">${htmlspecialchars(data.secteur)}</span>`;
                            row.cells[3].textContent = data.ville;
                            row.cells[4].innerHTML =
                                data.contact_nom ?
                                `${htmlspecialchars(data.contact_nom)}${data.contact_poste ? '<br><small>' + htmlspecialchars(data.contact_poste) + '</small>' : ''}` :
                                '<small style="color: var(--gray-500);">Non renseigné</small>';
                        }
                        showAlert('Entreprise modifiée avec succès', 'success');
                        resetForm();
                    } else {
                        // Add new row. Note: result.data uses id_entr and lib_entr
                        addRowToTable(result.data); 
                        showAlert(`Entreprise "${data.nom}" créée avec succès`, 'success');
                        this.reset(); 
                    }
                    // Reset selections
                    selectedEntreprises.clear();
                    document.querySelectorAll('#entrepriseTable tbody input[type="checkbox"]').forEach(cb => cb.checked = false);
                    updateActionButtons();
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'enregistrement : ' + error.message, 'error');
            } finally {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
                cancelBtn.disabled = false; 
            }
        });

        // Function to reset the form
        function resetForm() {
            editingEntreprise = null;
            submitText.textContent = 'Enregistrer';
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Enregistrer';
            entrepriseForm.reset();
            // Deselect all checkboxes
            document.querySelectorAll('#entrepriseTable tbody input[type="checkbox"]').forEach(cb => cb.checked = false);
            selectedEntreprises.clear();
            updateActionButtons();
        }

        // Cancel button
        cancelBtn.addEventListener('click', function() {
            resetForm();
        });

        // Function to view enterprise details
        async function voirEntreprise(id) {
            try {
                const result = await makeAjaxRequest({
                    action: 'get_entreprise_by_id',
                    id: id // Send as 'id', PHP will map to 'id_entr'
                });

                if (result.success) {
                    const entreprise = result.data;
                    // Build HTML content
                    let html = `
                        <div class="detail-item">
                            <h4 style="margin-bottom: var(--space-2);">${htmlspecialchars(entreprise.lib_entr)}</h4>
                            <span class="secteur-badge">${htmlspecialchars(entreprise.secteur)}</span>
                        </div>

                        <div class="detail-item">
                            <h5 style="margin: var(--space-4) 0 var(--space-2);">Coordonnées</h5>
                            <p>
                                ${entreprise.adresse ? `${htmlspecialchars(entreprise.adresse)}<br>` : ''}
                                ${entreprise.ville ? `${htmlspecialchars(entreprise.ville)}, ` : ''}${htmlspecialchars(entreprise.pays) || ''}
                            </p>
                            ${entreprise.telephone ? `<p><i class="fas fa-phone"></i> ${htmlspecialchars(entreprise.telephone)}</p>` : ''}
                            ${entreprise.email ? `<p><i class="fas fa-envelope"></i> ${htmlspecialchars(entreprise.email)}</p>` : ''}
                            ${entreprise.site_web ? `<p><i class="fas fa-globe"></i> <a href="${htmlspecialchars(entreprise.site_web)}" target="_blank">${htmlspecialchars(entreprise.site_web)}</a></p>` : ''}
                        </div>
                    `;

                    if (entreprise.contact_nom || entreprise.contact_poste) {
                        html += `
                            <div class="detail-item">
                                <h5 style="margin: var(--space-4) 0 var(--space-2);">Contact</h5>
                                <p>
                                    ${htmlspecialchars(entreprise.contact_nom) || ''}
                                    ${entreprise.contact_poste ? `<br><small>${htmlspecialchars(entreprise.contact_poste)}</small>` : ''}
                                </p>
                            </div>
                        `;
                    }

                    // Display content in the modal
                    document.getElementById('modalContent').innerHTML = html;
                    detailsModal.style.display = 'flex'; 
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des détails', 'error');
            }
        }

        // Function to modify an enterprise
        async function modifierEntreprise(id) {
            try {
                const result = await makeAjaxRequest({
                    action: 'get_entreprise_by_id',
                    id: id // Send as 'id', PHP will map to 'id_entr'
                });

                if (result.success) {
                    const entreprise = result.data;
                    // Populate form with enterprise data
                    document.getElementById('nom').value = entreprise.lib_entr ?? ''; // Use lib_entr for 'nom' field
                    document.getElementById('secteur').value = entreprise.secteur ?? '';
                    document.getElementById('adresse').value = entreprise.adresse ?? '';
                    document.getElementById('ville').value = entreprise.ville ?? '';
                    document.getElementById('pays').value = entreprise.pays ?? '';
                    document.getElementById('telephone').value = entreprise.telephone ?? '';
                    document.getElementById('email').value = entreprise.email ?? '';
                    document.getElementById('site_web').value = entreprise.site_web ?? '';
                    document.getElementById('contact_nom').value = entreprise.contact_nom ?? '';
                    document.getElementById('contact_poste').value = entreprise.contact_poste ?? '';

                    // Update form state
                    editingEntreprise = id;
                    submitText.textContent = 'Mettre à jour';
                    submitBtn.innerHTML = '<i class="fas fa-save"></i> Mettre à jour';

                    // Scroll to form
                    document.getElementById('nom').scrollIntoView({ behavior: 'smooth' });

                    // Deselect all other checkboxes and select this one
                    document.querySelectorAll('#entrepriseTable tbody input[type="checkbox"]').forEach(cb => {
                        if (cb.value != id) { 
                            cb.checked = false;
                        }
                    });
                    selectedEntreprises.clear();
                    selectedEntreprises.add(String(id)); 
                    updateActionButtons();
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des données', 'error');
            }
        }

        // Function to delete an enterprise
        async function supprimerEntreprise(idToDelete) {
            const ids = Array.isArray(idToDelete) ? idToDelete : [idToDelete];
            if (!confirm(`Êtes-vous sûr de vouloir supprimer ${ids.length > 1 ? 'ces entreprises' : 'cette entreprise'} ?`)) {
                return;
            }

            try {
                const result = await makeAjaxRequest({
                    action: 'delete',
                    ids: JSON.stringify(ids)
                });

                if (result.success) {
                    // Remove table rows
                    ids.forEach(id => {
                        const row = document.querySelector(`tr[data-id="${id}"]`);
                        if (row) row.remove();
                    });

                    // Check if table is empty
                    if (document.querySelectorAll('#entrepriseTable tbody tr').length === 0) {
                        entrepriseTableBody.innerHTML = `
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                    <i class="fas fa-building" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                    Aucune entreprise partenaire enregistrée. Ajoutez votre première entreprise en utilisant le formulaire ci-dessus.
                                </td>
                            </tr>
                        `;
                    }

                    showAlert(`${ids.length} entreprise(s) supprimée(s) avec succès`, 'success');
                    selectedEntreprises.clear(); 
                    updateActionButtons();
                    resetForm(); 
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la suppression', 'error');
            }
        }

        // Function to close the modal
        function closeModal() {
            detailsModal.style.display = 'none';
        }

        // Handle multiple action buttons
        modifierBtn.addEventListener('click', function() {
            if (selectedEntreprises.size === 1) {
                const id = Array.from(selectedEntreprises)[0];
                modifierEntreprise(parseInt(id)); 
            } else {
                showAlert('Veuillez sélectionner une seule entreprise à modifier.', 'warning');
            }
        });

        supprimerBtn.addEventListener('click', async function() {
            if (selectedEntreprises.size === 0) {
                showAlert('Veuillez sélectionner au moins une entreprise à supprimer.', 'warning');
                return;
            }
            // Convert Set to Array for supprimerEntreprise function
            const idsToDelete = Array.from(selectedEntreprises).map(id => parseInt(id));
            await supprimerEntreprise(idsToDelete);
        });

        // Export button
        exporterBtn.addEventListener('click', function() {
            // This export function needs the full data from the DB to work properly,
            // as the table only displays a subset of columns.
            // A more robust implementation would fetch all enterprise data first.
            showAlert('Fonctionnalité d\'exportation en développement. Veuillez recharger la page après cet avertissement.', 'info');
            // For a basic CSV export of visible columns:
            let csvContent = "data:text/csv;charset=utf-8,";
            const headers = ["Nom", "Secteur", "Ville", "Contact Nom", "Contact Poste"]; // Headers for visible columns
            csvContent += headers.join(";") + "\n";

            document.querySelectorAll('#entrepriseTable tbody tr[data-id]').forEach(row => {
                const nom = row.cells[1].textContent;
                const secteur = row.cells[2].textContent;
                const ville = row.cells[3].textContent;
                // For contact, we need to parse the innerHTML, or better, fetch full data
                const contactCell = row.cells[4].innerHTML;
                const contactNomMatch = contactCell.match(/(.+?)<br>/); // Extract name before <br>
                const contactNom = contactNomMatch ? contactNomMatch[1].trim() : contactCell.replace(/<.*?>/g, '').trim(); // Remove tags
                const contactPosteMatch = contactCell.match(/<small>(.+)<\/small>/);
                const contactPoste = contactPosteMatch ? contactPosteMatch[1].trim() : '';


                const rowData = [
                    nom,
                    secteur,
                    ville,
                    contactNom,
                    contactPoste
                ].map(field => `"${String(field || '').replace(/"/g, '""')}"`).join(";"); 
                csvContent += rowData + "\n";
            });

            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "entreprises_partenaires.csv");
            document.body.appendChild(link); 
            link.click();
            document.body.removeChild(link);
            showAlert('Exportation CSV des colonnes visibles lancée.', 'info');
        });


        // Attach event listeners to existing rows on load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#entrepriseTable tbody tr').forEach(row => {
                if (row.querySelector('td[colspan="6"]') === null) { // Only attach to data rows
                    attachEventListenersToRow(row);
                }
            });
            updateActionButtons();

            // Set up initial message modal event listeners
            if (messageButton) messageButton.addEventListener('click', closeMessageModal);
            if (messageClose) messageClose.addEventListener('click', closeMessageModal);
            if (messageModal) {
                messageModal.addEventListener('click', function(e) {
                    if (e.target === messageModal) {
                        closeMessageModal();
                    }
                });
            }
        });
        
        // Expose functions to global scope for inline onclicks in PHP generated HTML
        window.voirEntreprise = voirEntreprise;
        window.modifierEntreprise = modifierEntreprise;
        window.supprimerEntreprise = supprimerEntreprise;
    </script>
</body>
</html>