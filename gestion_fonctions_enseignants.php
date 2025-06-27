<?php
// gestion_fonctions_enseignant.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Traitement AJAX pour les opérations CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        switch ($action) {
            case 'create':
                $nomFonction = trim($_POST['nom_fonction']);
                
                // Validation
                if (empty($nomFonction)) {
                    throw new Exception("Le nom de la fonction est obligatoire");
                }
                
                // Vérifier si la fonction existe déjà
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM fonction WHERE nom_fonction = ?");
                $checkStmt->execute([$nomFonction]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Cette fonction existe déjà");
                }
                
                // Générer l'ID
                $stmtMaxId = $pdo->query("SELECT COALESCE(MAX(id_fonction), 0) + 1 FROM fonction");
                $idFonction = $stmtMaxId->fetchColumn();
                
                // Insérer la nouvelle fonction
                $stmt = $pdo->prepare("INSERT INTO fonction (id_fonction, nom_fonction) VALUES (?, ?)");
                $stmt->execute([$idFonction, $nomFonction]);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Fonction créée avec succès',
                    'data' => [
                        'id_fonction' => $idFonction,
                        'nom_fonction' => $nomFonction
                    ]
                ]);
                break;
                
            case 'update':
                $idFonction = $_POST['id_fonction'];
                $nomFonction = trim($_POST['nom_fonction']);
                
                // Validation
                if (empty($nomFonction)) {
                    throw new Exception("Le nom de la fonction est obligatoire");
                }
                
                // Vérifier si la fonction existe déjà pour un autre ID
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM fonction WHERE nom_fonction = ? AND id_fonction != ?");
                $checkStmt->execute([$nomFonction, $idFonction]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Cette fonction existe déjà");
                }
                
                // Mettre à jour la fonction
                $stmt = $pdo->prepare("UPDATE fonction SET nom_fonction = ? WHERE id_fonction = ?");
                $stmt->execute([$nomFonction, $idFonction]);
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Fonction modifiée avec succès']);
                break;
                
            case 'delete':
                $idsFonctions = json_decode($_POST['ids_fonctions'], true);
                
                // Vérifier si des enseignants utilisent ces fonctions
                $placeholders = str_repeat('?,', count($idsFonctions) - 1) . '?';
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM occuper WHERE fk_id_fonc IN ($placeholders)");
                $checkStmt->execute($idsFonctions);
                $countUsage = $checkStmt->fetchColumn();
                
                if ($countUsage > 0) {
                    throw new Exception("Impossible de supprimer : $countUsage enseignant(s) utilisent ces fonctions");
                }
                
                // Supprimer les fonctions
                $stmt = $pdo->prepare("DELETE FROM fonction WHERE id_fonction IN ($placeholders)");
                $stmt->execute($idsFonctions);
                
                $pdo->commit();
                
                echo json_encode(['success' => true, 'message' => 'Fonction(s) supprimée(s) avec succès']);
                break;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Récupérer les fonctions existantes
$fonctions = [];
try {
    $stmt = $pdo->query("SELECT * FROM fonction ORDER BY nom_fonction");
    $fonctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erreur lors de la récupération des fonctions: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Fonctions Enseignants</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* === VARIABLES CSS === */
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

        /* === SIDEBAR === */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-800) 0%, var(--primary-900) 100%);
            color: white;
            z-index: 1000;
            transition: all var(--transition-normal);
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: var(--primary-900);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: var(--primary-600);
            border-radius: 2px;
        }

        .sidebar-header {
            padding: var(--space-6) var(--space-6);
            border-bottom: 1px solid var(--primary-700);
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }

        .sidebar-logo {
            width: 40px;
            height: 40px;
            background: var(--accent-500);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar-logo img {
            width: 28px;
            height: 28px;
            object-fit: contain;
            filter: brightness(0) invert(1);
        }

        .sidebar-title {
            font-size: var(--text-xl);
            font-weight: 700;
            white-space: nowrap;
            opacity: 1;
            transition: opacity var(--transition-normal);
        }

        .sidebar.collapsed .sidebar-title {
            opacity: 0;
        }

        .sidebar-nav {
            padding: var(--space-4) 0;
        }

        .nav-section {
            margin-bottom: var(--space-6);
        }

        .nav-section-title {
            padding: var(--space-2) var(--space-6);
            font-size: var(--text-xs);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--primary-400);
            white-space: nowrap;
            opacity: 1;
            transition: opacity var(--transition-normal);
        }

        .sidebar.collapsed .nav-section-title {
            opacity: 0;
        }

        .nav-item {
            margin-bottom: var(--space-1);
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: var(--space-3) var(--space-6);
            color: var(--primary-200);
            text-decoration: none;
            transition: all var(--transition-fast);
            position: relative;
            gap: var(--space-3);
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-link.active {
            background: var(--accent-600);
            color: white;
        }

        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--accent-300);
        }

        .nav-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .nav-text {
            white-space: nowrap;
            opacity: 1;
            transition: opacity var(--transition-normal);
        }

        .sidebar.collapsed .nav-text {
            opacity: 0;
        }

        .nav-submenu {
            margin-left: var(--space-8);
            margin-top: var(--space-2);
            border-left: 2px solid var(--primary-700);
            padding-left: var(--space-4);
        }

        .sidebar.collapsed .nav-submenu {
            display: none;
        }

        .nav-submenu .nav-link {
            padding: var(--space-2) var(--space-4);
            font-size: var(--text-sm);
        }

        /* === TOPBAR === */
        .topbar {
            height: var(--topbar-height);
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
            padding: 0 var(--space-6);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }

        .sidebar-toggle {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--gray-100);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            color: var(--gray-600);
        }

        .sidebar-toggle:hover {
            background: var(--gray-200);
            color: var(--gray-800);
        }

        .page-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-800);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }

        .topbar-button {
            width: 40px;
            height: 40px;
            border: none;
            background: var(--gray-100);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            color: var(--gray-600);
            position: relative;
        }

        .topbar-button:hover {
            background: var(--gray-200);
            color: var(--gray-800);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 18px;
            height: 18px;
            background: var(--error-500);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 600;
            color: white;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: background var(--transition-fast);
        }

        .user-menu:hover {
            background: var(--gray-100);
        }

        .user-info {
            text-align: right;
        }

        .user-name {
            font-size: var(--text-sm);
            font-weight: 600;
            color: var(--gray-800);
            line-height: 1.2;
        }

        .user-role {
            font-size: var(--text-xs);
            color: var(--gray-500);
        }

        /* === PAGE SPECIFIC STYLES === */
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .form-group input[type="text"] {
            padding: var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            color: var(--gray-800);
            transition: all var(--transition-fast);
        }

        .form-group input[type="text"]:focus {
            outline: none;
            border-color: var(--accent-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
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

        /* === RESPONSIVE === */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.mobile {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .main-content.sidebar-collapsed {
                margin-left: 0;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Gestion des Fonctions Enseignants</h1>
                    <p class="page-subtitle">Ajoutez, modifiez ou supprimez les fonctions des enseignants.</p>
                </div>

                <!-- Message d'alerte -->
                <div id="alertMessage" class="alert"></div>

                <div class="form-card">
                    <h3 class="form-card-title">Ajouter une nouvelle Fonction</h3>
                    <form id="functionForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="nom_fonction">Nom de la Fonction <span style="color: var(--error-500);">*</span></label>
                                <input type="text" id="nom_fonction" name="nom_fonction" placeholder="Ex: Professeur Assistant" required>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-plus-circle"></i> <span id="submitText">Ajouter Fonction</span>
                            </button>
                            <button type="reset" class="btn btn-secondary" id="cancelBtn">
                                <i class="fas fa-redo"></i> Annuler
                            </button>
                        </div>
                    </form>
                </div>

                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">Liste des Fonctions</h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" id="modifierFunctionBtn" disabled>
                                <i class="fas fa-edit"></i> Modifier
                            </button>
                            <button class="btn btn-secondary" id="supprimerFunctionBtn" disabled>
                                <i class="fas fa-trash-alt"></i> Supprimer
                            </button>
                            <button class="btn btn-secondary" id="exporterFunctionBtn">
                                <i class="fas fa-file-export"></i> Exporter
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="functionTable">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Nom de la Fonction</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($fonctions)): ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                        <i class="fas fa-briefcase" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucune fonction trouvée. Ajoutez votre première fonction en utilisant le formulaire ci-dessus.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($fonctions as $fonction): ?>
                                    <tr data-id="<?php echo htmlspecialchars($fonction['id_fonction']); ?>">
                                        <td>
                                            <label class="checkbox-container">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($fonction['id_fonction']); ?>">
                                                <span class="checkmark"></span>
                                            </label>
                                        </td>
                                        <td><?php echo htmlspecialchars($fonction['nom_fonction']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button edit" title="Modifier" onclick="modifierFonction('<?php echo htmlspecialchars($fonction['id_fonction']); ?>')">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="action-button delete" title="Supprimer" onclick="supprimerFonction('<?php echo htmlspecialchars($fonction['id_fonction']); ?>')">
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

    <script>
        // Variables globales
        let selectedFunctions = new Set();
        let editingFunction = null;

        // Éléments DOM
        const functionForm = document.getElementById('functionForm');
        const nomFonctionInput = document.getElementById('nom_fonction');
        const functionTableBody = document.querySelector('#functionTable tbody');
        const modifierFunctionBtn = document.getElementById('modifierFunctionBtn');
        const supprimerFunctionBtn = document.getElementById('supprimerFunctionBtn');
        const exporterFunctionBtn = document.getElementById('exporterFunctionBtn');
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const cancelBtn = document.getElementById('cancelBtn');
        const alertMessage = document.getElementById('alertMessage');

        // Gestion du toggle sidebar
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
            });
        }

        // Fonction pour afficher les messages
        function showAlert(message, type = 'success') {
            alertMessage.textContent = message;
            alertMessage.className = `alert ${type}`;
            alertMessage.style.display = 'block';
            setTimeout(() => {
                alertMessage.style.display = 'none';
            }, 5000);
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

        // Fonction pour mettre à jour l'état des boutons
        function updateActionButtons() {
            if (selectedFunctions.size === 1) {
                modifierFunctionBtn.disabled = false;
                supprimerFunctionBtn.disabled = false;
            } else if (selectedFunctions.size > 1) {
                modifierFunctionBtn.disabled = true;
                supprimerFunctionBtn.disabled = false;
            } else {
                modifierFunctionBtn.disabled = true;
                supprimerFunctionBtn.disabled = true;
            }
        }

        // Fonction pour ajouter une ligne dans le tableau
        function addRowToTable(fonction) {
            // Supprimer le message "Aucune fonction trouvée" s'il existe
            const emptyRow = functionTableBody.querySelector('td[colspan="3"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }

            const newRow = functionTableBody.insertRow();
            newRow.setAttribute('data-id', fonction.id_fonction);
            newRow.innerHTML = `
                <td>
                    <label class="checkbox-container">
                        <input type="checkbox" value="${fonction.id_fonction}">
                        <span class="checkmark"></span>
                    </label>
                </td>
                <td>${fonction.nom_fonction}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-button edit" title="Modifier" onclick="modifierFonction('${fonction.id_fonction}')">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button class="action-button delete" title="Supprimer" onclick="supprimerFonction('${fonction.id_fonction}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            `;
            attachEventListenersToRow(newRow);
        }

        // Fonction pour attacher les événements aux lignes
        function attachEventListenersToRow(row) {
            const checkbox = row.querySelector('input[type="checkbox"]');
            
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    selectedFunctions.add(this.value);
                } else {
                    selectedFunctions.delete(this.value);
                }
                updateActionButtons();
            });
        }

        // Soumission du formulaire
        functionForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = {
                action: editingFunction ? 'update' : 'create',
                nom_fonction: formData.get('nom_fonction')
            };

            if (editingFunction) {
                data.id_fonction = editingFunction;
            }

            try {
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;

                const result = await makeAjaxRequest(data);

                if (result.success) {
                    if (editingFunction) {
                        // Mettre à jour la ligne existante
                        const row = document.querySelector(`tr[data-id="${editingFunction}"]`);
                        if (row) {
                            row.cells[1].textContent = data.nom_fonction;
                        }
                        showAlert('Fonction modifiée avec succès');
                        resetForm();
                    } else {
                        // Ajouter une nouvelle ligne
                        addRowToTable(result.data);
                        showAlert(`Fonction "${data.nom_fonction}" créée avec succès`);
                    }
                    this.reset();
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'enregistrement', 'error');
            } finally {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }
        });

        // Fonction pour réinitialiser le formulaire
        function resetForm() {
            editingFunction = null;
            submitText.textContent = 'Ajouter Fonction';
            submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Ajouter Fonction';
            functionForm.reset();
        }

        // Bouton Annuler
        cancelBtn.addEventListener('click', function() {
            resetForm();
        });

        // Fonction pour modifier une fonction
        function modifierFonction(idFonction) {
            const row = document.querySelector(`tr[data-id="${idFonction}"]`);
            if (row) {
                editingFunction = idFonction;
                nomFonctionInput.value = row.cells[1].textContent;
                
                submitText.textContent = 'Mettre à jour';
                submitBtn.innerHTML = '<i class="fas fa-edit"></i> Mettre à jour';
                
                // Faire défiler vers le formulaire
                document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Fonction pour supprimer une fonction
        async function supprimerFonction(idFonction) {
            const row = document.querySelector(`tr[data-id="${idFonction}"]`);
            if (row) {
                const nomFonction = row.cells[1].textContent;
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer la fonction "${nomFonction}" ?\n\nAttention : Cette action supprimera aussi les associations avec les enseignants.`)) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'delete',
                            ids_fonctions: JSON.stringify([idFonction])
                        });

                        if (result.success) {
                            row.remove();
                            selectedFunctions.delete(idFonction);
                            updateActionButtons();
                            showAlert('Fonction supprimée avec succès');
                            
                            // Si plus de fonctions, afficher le message vide
                            if (functionTableBody.children.length === 0) {
                                functionTableBody.innerHTML = `
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                            <i class="fas fa-briefcase" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                            Aucune fonction trouvée. Ajoutez votre première fonction en utilisant le formulaire ci-dessus.
                                        </td>
                                    </tr>
                                `;
                            }
                        } else {
                            showAlert(result.message, 'error');
                        }
                    } catch (error) {
                        showAlert('Erreur lors de la suppression', 'error');
                    }
                }
            }
        }

        // Bouton Modifier global
        modifierFunctionBtn.addEventListener('click', function() {
            if (selectedFunctions.size === 1) {
                const idFonction = Array.from(selectedFunctions)[0];
                modifierFonction(idFonction);
            }
        });

        // Bouton Supprimer global
        supprimerFunctionBtn.addEventListener('click', async function() {
            if (selectedFunctions.size > 0) {
                const idsArray = Array.from(selectedFunctions);
                
                if (confirm(`Êtes-vous sûr de vouloir supprimer ${idsArray.length} fonction(s) sélectionnée(s) ?\n\nAttention : Cette action supprimera aussi les associations avec les enseignants.`)) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'delete',
                            ids_fonctions: JSON.stringify(idsArray)
                        });

                        if (result.success) {
                            idsArray.forEach(id => {
                                const row = document.querySelector(`tr[data-id="${id}"]`);
                                if (row) row.remove();
                            });
                            selectedFunctions.clear();
                            updateActionButtons();
                            showAlert('Fonction(s) supprimée(s) avec succès');
                            
                            // Si plus de fonctions, afficher le message vide
                            if (functionTableBody.children.length === 0) {
                                functionTableBody.innerHTML = `
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                            <i class="fas fa-briefcase" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                            Aucune fonction trouvée. Ajoutez votre première fonction en utilisant le formulaire ci-dessus.
                                        </td>
                                    </tr>
                                `;
                            }
                        } else {
                            showAlert(result.message, 'error');
                        }
                    } catch (error) {
                        showAlert('Erreur lors de la suppression', 'error');
                    }
                }
            }
        });

        // Bouton Exporter
        exporterFunctionBtn.addEventListener('click', function() {
            // Vérifier s'il y a des fonctions à exporter
            const rows = document.querySelectorAll('#functionTable tbody tr');
            if (rows.length === 1 && rows[0].querySelector('td[colspan="3"]')) {
                showAlert('Aucune fonction à exporter', 'warning');
                return;
            }

            // Créer les données CSV
            const csvRows = [['Nom de la Fonction']];
            
            document.querySelectorAll('#functionTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="3"]')) {
                    csvRows.push([row.cells[1].textContent]);
                }
            });

            // Créer le contenu CSV
            const csvContent = csvRows.map(row => row.join(',')).join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            
            // Télécharger le fichier
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `fonctions_enseignants_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showAlert('Exportation terminée');
        });

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Attacher les événements aux lignes existantes
            document.querySelectorAll('#functionTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="3"]')) {
                    attachEventListenersToRow(row);
                }
            });
            
            updateActionButtons();
        });

        // Responsive: Gestion mobile
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
        handleResize();
    </script>
</body>
</html>