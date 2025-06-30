<?php
session_start(); // Start the session to access session variables

require_once 'config.php'; // Include your database connection file (assuming it contains isLoggedIn() and redirect())

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Simulate a logged-in user (replace with actual session data)
// In a real application, you would get this from your authentication system
// For this 'compte_rendu' page, let's assume any logged-in user can create/manage CR.
// We'll use $_SESSION['user_id'] which should be set after a successful login.
if (!isset($_SESSION['user_id'])) {
    // Fallback for testing if not properly logged in, remove in production
    $_SESSION['user_id'] = 1; // Example: Assuming user_id 1 is an admin/staff who can create CR
    // Optionally set a default role for testing if needed
    // $_SESSION['user_role'] = 'secretaire';
}

// Database connection
try {
    $pdo = new PDO('mysql:host=127.0.0.1:3306;dbname=sygecos', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Error connecting to database in compte_rendu.php: " . $e->getMessage());
    die("Une erreur est survenue lors de la connexion à la base de données.");
}

// Initialize session variables for compte rendu if not set
if (!isset($_SESSION['current_cr_id'])) {
    $_SESSION['current_cr_id'] = null;
    $_SESSION['cr_metadata'] = [];
    $_SESSION['cr_content'] = ''; // Store the HTML content
    $_SESSION['show_editor'] = false;
}

// Try to load an existing compte rendu if one is specified via GET parameter (for editing)
// Or if there's a current one in session (e.g., after initial creation or last save)
$load_cr_id = $_GET['id'] ?? $_SESSION['current_cr_id'] ?? null;
$existingCR = null;

if ($load_cr_id) {
    $stmt = $pdo->prepare("SELECT * FROM comptes_rendus WHERE id_cr = :id_cr AND fk_id_util = :fk_id_util LIMIT 1");
    $stmt->bindParam(':id_cr', $load_cr_id);
    $stmt->bindParam(':fk_id_util', $_SESSION['user_id']); // Ensure user can only load their own CRs
    $stmt->execute();
    $existingCR = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingCR) {
        $_SESSION['current_cr_id'] = $existingCR['id_cr'];
        $_SESSION['cr_metadata'] = [
            'titre_cr' => $existingCR['titre_cr'],
            'type_reunion' => $existingCR['type_reunion'],
            'date_reunion' => $existingCR['date_reunion'],
            'heure_reunion' => $existingCR['heure_reunion'],
            'lieu_reunion' => $existingCR['lieu_reunion'],
            'duree_reunion' => $existingCR['duree_reunion'],
            'president_seance' => $existingCR['president_seance'],
            'secretaire_reunion' => $existingCR['secretaire_reunion'],
            'participants' => $existingCR['participants'],
            'absents_excuses' => $existingCR['absents_excuses'],
            'statut' => $existingCR['statut']
        ];
        $_SESSION['cr_content'] = $existingCR['contenu_cr'];
        $_SESSION['show_editor'] = true; // Show editor directly if CR exists
    } else {
        // If ID was provided but CR not found or not owned by user, reset session state
        $_SESSION['current_cr_id'] = null;
        $_SESSION['cr_metadata'] = [];
        $_SESSION['cr_content'] = '';
        $_SESSION['show_editor'] = false;
    }
}


// Handle form submission for metadata (server-side validation)
$errors = [];
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_metadata'])) {
        $titre_cr = $_POST['titre_cr'] ?? '';
        $type_reunion = $_POST['type_reunion'] ?? '';
        $date_reunion = $_POST['date_reunion'] ?? '';
        $heure_reunion = $_POST['heure_reunion'] ?? '';
        $lieu_reunion = $_POST['lieu_reunion'] ?? '';
        $duree_reunion = $_POST['duree_reunion'] ?? '';
        $president_seance = $_POST['president_seance'] ?? '';
        $secretaire_reunion = $_POST['secretaire_reunion'] ?? '';
        $participants = $_POST['participants'] ?? '';
        $absents_excuses = $_POST['absents_excuses'] ?? '';

        // Basic server-side validation
        if (empty($titre_cr)) { $errors[] = "Le titre du compte rendu est requis."; }
        if (empty($type_reunion)) { $errors[] = "Le type de réunion est requis."; }
        if (empty($date_reunion)) { $errors[] = "La date de la réunion est requise."; }
        if (empty($heure_reunion)) { $errors[] = "L'heure de la réunion est requise."; }
        if (empty($lieu_reunion)) { $errors[] = "Le lieu de la réunion est requis."; }
        if (empty($duree_reunion)) { $errors[] = "La durée de la réunion est requise."; }
        if (empty($president_seance)) { $errors[] = "Le président de séance est requis."; }
        if (empty($secretaire_reunion)) { $errors[] = "Le secrétaire est requis."; }
        if (empty($participants)) { $errors[] = "Les participants sont requis."; }


        if (empty($errors)) {
            $_SESSION['cr_metadata'] = [
                'titre_cr' => $titre_cr,
                'type_reunion' => $type_reunion,
                'date_reunion' => $date_reunion,
                'heure_reunion' => $heure_reunion,
                'lieu_reunion' => $lieu_reunion,
                'duree_reunion' => $duree_reunion,
                'president_seance' => $president_seance,
                'secretaire_reunion' => $secretaire_reunion,
                'participants' => $participants,
                'absents_excuses' => $absents_excuses,
                'statut' => 'brouillon' // Initial status or current status if editing
            ];
            $_SESSION['show_editor'] = true;
            // If this is a new CR creation after submission of metadata,
            // ensure current_cr_id is null so save_compte_rendu logic inserts.
            if (!isset($existingCR) || !$existingCR) {
                $_SESSION['current_cr_id'] = null;
                $_SESSION['cr_content'] = ''; // Start with empty content for new CR
            }
        }
    }
    // Handle AJAX actions for saving and deleting CR content
    elseif (isset($_POST['action'])) {
        header('Content-Type: application/json');
        try {
            if ($_POST['action'] === 'save_compte_rendu') {
                $crId = $_POST['cr_id'] ?? null;
                $metadata = json_decode($_POST['metadata'], true);
                $content = $_POST['content'];
                $status = $_POST['status'] ?? 'brouillon'; // Allow submitting 'finalise' status too

                // Ensure all required metadata fields are present for database insertion
                if (empty($metadata['titre_cr']) || empty($metadata['date_reunion']) || empty($metadata['heure_reunion']) ||
                    empty($metadata['lieu_reunion']) || empty($metadata['president_seance']) || empty($metadata['secretaire_reunion']) ||
                    empty($metadata['participants']) || empty($_SESSION['user_id'])) {
                    throw new Exception("Données du compte rendu incomplètes pour l'enregistrement.");
                }

                if ($crId) {
                    // Update existing compte rendu
                    $stmt = $pdo->prepare("UPDATE comptes_rendus SET
                                            titre_cr = :titre_cr,
                                            type_reunion = :type_reunion,
                                            date_reunion = :date_reunion,
                                            heure_reunion = :heure_reunion,
                                            lieu_reunion = :lieu_reunion,
                                            duree_reunion = :duree_reunion,
                                            president_seance = :president_seance,
                                            secretaire_reunion = :secretaire_reunion,
                                            participants = :participants,
                                            absents_excuses = :absents_excuses,
                                            contenu_cr = :contenu_cr,
                                            statut = :statut,
                                            date_modification = NOW()
                                            WHERE id_cr = :id_cr AND fk_id_util = :fk_id_util");
                    $stmt->bindParam(':id_cr', $crId);
                } else {
                    // Insert new compte rendu
                    $stmt = $pdo->prepare("INSERT INTO comptes_rendus (fk_id_util, titre_cr, type_reunion, date_reunion, heure_reunion, lieu_reunion, duree_reunion, president_seance, secretaire_reunion, participants, absents_excuses, contenu_cr, statut)
                                            VALUES (:fk_id_util, :titre_cr, :type_reunion, :date_reunion, :heure_reunion, :lieu_reunion, :duree_reunion, :president_seance, :secretaire_reunion, :participants, :absents_excuses, :contenu_cr, :statut)");
                    $stmt->bindParam(':fk_id_util', $_SESSION['user_id']);
                }

                $stmt->bindParam(':titre_cr', $metadata['titre_cr']);
                $stmt->bindParam(':type_reunion', $metadata['type_reunion']);
                $stmt->bindParam(':date_reunion', $metadata['date_reunion']);
                $stmt->bindParam(':heure_reunion', $metadata['heure_reunion']);
                $stmt->bindParam(':lieu_reunion', $metadata['lieu_reunion']);
                $stmt->bindParam(':duree_reunion', $metadata['duree_reunion']);
                $stmt->bindParam(':president_seance', $metadata['president_seance']);
                $stmt->bindParam(':secretaire_reunion', $metadata['secretaire_reunion']);
                $stmt->bindParam(':participants', $metadata['participants']);
                $stmt->bindParam(':absents_excuses', $metadata['absents_excuses']);
                $stmt->bindParam(':contenu_cr', $content);
                $stmt->bindParam(':statut', $status);
                $stmt->execute();

                if (!$crId) {
                    $crId = $pdo->lastInsertId();
                }
                $_SESSION['current_cr_id'] = $crId; // Update session with new CR ID

                echo json_encode(['success' => true, 'message' => 'Compte rendu enregistré avec succès!', 'cr_id' => $crId]);
            }
            elseif ($_POST['action'] === 'delete_compte_rendu') {
                $crId = $_POST['cr_id'] ?? null;

                if (!$crId) {
                    throw new Exception("ID du compte rendu manquant pour la suppression.");
                }

                $stmt = $pdo->prepare("DELETE FROM comptes_rendus WHERE id_cr = :id_cr AND fk_id_util = :fk_id_util");
                $stmt->bindParam(':id_cr', $crId);
                $stmt->bindParam(':fk_id_util', $_SESSION['user_id']); // Ensure only the owner can delete
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $_SESSION['current_cr_id'] = null; // Clear session CR ID
                    $_SESSION['cr_metadata'] = []; // Clear metadata
                    $_SESSION['cr_content'] = ''; // Clear content
                    $_SESSION['show_editor'] = false; // Go back to metadata form
                    echo json_encode(['success' => true, 'message' => 'Compte rendu supprimé avec succès!']);
                } else {
                    throw new Exception("Compte rendu introuvable ou non autorisé.");
                }
            }
            else {
                throw new Exception("Action non reconnue.");
            }
        } catch (Exception $e) {
            error_log("Compte rendu action error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Current metadata and content to display in the form/editor
$metadata = $_SESSION['cr_metadata'] ?? [];
$crContent = $_SESSION['cr_content'] ?? '<p><h1>Ordre du jour</h1><ol><li>Accueil et tour de table</li><li>Approval du compte rendu précédent</li><li>Points d\'action en cours</li><li>Nouveaux points à discuter</li><li>Divers</li><li>Date de la prochaine réunion</li></ol><h1>Détails de la réunion</h1><h2>1. Accueil et tour de table</h2><p>Commencez à taper les détails de la réunion ici...</p></p>'; // Default content for new CR or if empty

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Compte Rendu</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Your existing CSS for SYGECOS base layout and editor */
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

        .topbar { height: var(--topbar-height); background: var(--white); border-bottom: 1px solid var(--gray-200); padding: 0 var(--space-6); display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; }
        .topbar-left { display: flex; align-items: center; gap: var(--space-4); }
        .sidebar-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); } .sidebar-toggle:hover { background: var(--gray-200); color: var(--gray-800); }
        .page-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-800); }
        .topbar-right { display: flex; align-items: center; gap: var(--space-4); }
        .topbar-button { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); position: relative; } .topbar-button:hover { background: var(--gray-200); color: var(--gray-800); }
        .notification-badge { position: absolute; top: -2px; right: -2px; width: 18px; height: 18px; background: var(--error-500); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: white; }
        .user-menu { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-2) var(--space-3); border-radius: var(--radius-lg); cursor: pointer; transition: background var(--transition-fast); } .user-menu:hover { background: var(--gray-100); }
        .user-info { text-align: right; } .user-name { font-size: var(--text-sm); font-weight: 600; color: var(--gray-800); line-height: 1.2; } .user-role { font-size: var(--text-xs); color: var(--gray-500); }

        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); display: flex; justify-content: space-between; align-items: center; }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); margin-top: var(--space-2); }

        /* Styles pour le formulaire de métadonnées */
        .metadata-form {
            background: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-md);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            <?php echo isset($_SESSION['show_editor']) && $_SESSION['show_editor'] === true ? 'display: none;' : ''; ?>
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--space-4);
            margin-top: var(--space-4);
        }

        .form-group {
            margin-bottom: var(--space-4);
        }

        .form-group label {
            display: block;
            margin-bottom: var(--space-2);
            font-weight: 600;
            color: var(--gray-700);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-family: var(--font-primary);
            transition: border-color var(--transition-fast);
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-group input[readonly] {
            background-color: var(--gray-100);
            cursor: not-allowed;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
            margin-top: var(--space-6);
        }

        .btn {
            padding: var(--space-3) var(--space-4);
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            border: 1px solid transparent;
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
            border-color: var(--gray-300);
            color: var(--gray-700);
        }

        .btn-outline:hover {
            background-color: var(--gray-100);
        }

        /* Styles pour l'éditeur Word */
        .word-interface {
            background: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-top: var(--space-4);
            <?php echo isset($_SESSION['show_editor']) && $_SESSION['show_editor'] === true ? 'display: block;' : 'display: none;'; ?>
        }

        /* Ruban Word amélioré */
        .ribbon {
            background: #f3f3f3;
            padding: 0;
            position: sticky;
            top: var(--topbar-height);
            z-index: 50;
        }

        .ribbon-tabs {
            display: flex;
            border-bottom: 1px solid #ccc;
            background: #f3f3f3;
        }

        .ribbon-tab {
            padding: 8px 16px;
            cursor: pointer;
            border-right: 1px solid #e0e0e0;
            font-size: 12px;
            position: relative;
        }

        .ribbon-tab.active {
            background: white;
            border-bottom: 2px solid var(--accent-600);
            font-weight: 600;
        }

        .ribbon-content {
            padding: 10px;
            background: white;
            border-bottom: 1px solid #ddd;
            display: none;
        }

        .ribbon-content.active {
            display: block;
        }

        .ribbon-group {
            display: inline-block;
            vertical-align: top;
            margin-right: 15px;
            margin-bottom: 10px;
        }

        .ribbon-group-title {
            font-size: 11px;
            margin-bottom: 5px;
            color: #666;
            font-weight: 600;
            text-align: center;
        }

        .ribbon-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 3px;
        }

        .ribbon-button {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 6px;
            min-width: 60px;
            cursor: pointer;
            border-radius: 4px;
        }

        .ribbon-button:hover {
            background: #e6e6e6;
        }

        .ribbon-button i {
            font-size: 16px;
            margin-bottom: 3px;
        }

        .ribbon-button span {
            font-size: 11px;
            text-align: center;
        }

        /* Contenu éditable avec pagination */
        .document-container {
            background: #e2e2e2;
            padding: 20px 0;
            min-height: calc(100vh - 200px);
        }

        .page {
            background: white;
            width: 21cm;
            min-height: 29.7cm;
            margin: 0 auto 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            position: relative;
            page-break-after: always;
        }

        .page-content {
            padding: 2.5cm 3cm;
            height: 100%;
            outline: none;
        }

        .header, .footer {
            position: absolute;
            width: 100%;
            padding: 0.5cm 3cm;
            font-size: 10pt;
            color: #555;
        }

        .header {
            top: 0;
            border-bottom: 1px solid #eee;
        }

        .footer {
            bottom: 0;
            border-top: 1px solid #eee;
            text-align: center;
        }

        .page-number {
            position: absolute;
            right: 3cm;
        }

        /* Page de garde spéciale */
        .cover-page {
            background: white;
            width: 21cm;
            min-height: 29.7cm;
            margin: 0 auto 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 4cm;
        }

        .cover-title {
            font-size: 24pt;
            font-weight: bold;
            margin-bottom: 2cm;
            color: var(--primary-800);
        }
        
        .cover-type-reunion {
            font-size: 14pt;
            margin-bottom: 1cm;
            color: var(--primary-700);
        }

        .cover-university { /* Reused for 'Nom de l'entreprise' or 'Lieu de réunion' if applicable */
            font-size: 14pt;
            margin-bottom: 3cm;
        }

        .cover-meta {
            width: 100%;
            margin-top: 2cm;
        }

        .meta-top {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1cm;
        }

        .meta-bottom {
            display: flex;
            justify-content: space-between;
            margin-top: 3cm;
        }

        .meta-left {
            text-align: left;
        }

        .meta-right {
            text-align: right;
        }

        /* Styles pour les listes et la mise en forme */
        .page-content ul, .page-content ol {
            padding-left: 1.5cm;
        }

        .page-content ul {
            list-style-type: disc;
        }

        .page-content ol {
            list-style-type: decimal;
        }

        /* Styles pour les titres et le sommaire */
        h1, h2, h3 {
            color: var(--primary-800);
            margin-top: 18pt;
            margin-bottom: 6pt;
        }

        h1 {
            font-size: 16pt;
            page-break-after: avoid;
        }

        h2 {
            font-size: 14pt;
        }

        h3 {
            font-size: 12pt;
        }

        /* Panneau de révision */
        .review-panel {
            position: fixed;
            right: 0;
            top: var(--topbar-height);
            width: 300px;
            height: calc(100vh - var(--topbar-height));
            background: white;
            box-shadow: -2px 0 5px rgba(0,0,0,0.1);
            padding: 15px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }

        .review-panel h3 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        /* Modal general styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 200;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: var(--radius-md);
            width: 90%;
            max-width: 400px;
            box-shadow: var(--shadow-xl);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .close-modal {
            font-size: 1.5rem;
            cursor: pointer;
        }

        .modal-body {
            margin-bottom: 15px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-select, .form-input {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
        }

        /* Menu contextuel */
        .context-menu {
            display: none;
            position: absolute;
            z-index: 1000;
            width: 200px;
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            padding: 5px 0;
        }

        .context-menu-item {
            padding: 8px 15px;
            cursor: pointer;
            font-size: 14px;
        }

        .context-menu-item:hover {
            background-color: var(--gray-100);
        }

        .context-menu-divider {
            height: 1px;
            background-color: var(--gray-200);
            margin: 5px 0;
        }

        .context-submenu {
            position: relative;
        }

        .context-submenu .context-submenu-content {
            display: none;
            position: absolute;
            left: 100%;
            top: 0;
            width: 200px;
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            padding: 5px 0;
        }

        .context-submenu:hover .context-submenu-content {
            display: block;
        }
        
        .error-message {
            color: var(--error-500);
            font-size: var(--text-sm);
            margin-top: var(--space-1);
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none; /* Hidden by default */
            align-items: center;
            justify-content: center;
            z-index: 9999; /* Higher than other modals */
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--accent-500);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .page, .cover-page {
                width: 100%;
                min-height: auto;
                margin-bottom: 10px;
            }
            
            .page-content {
                padding: 1.5cm;
            }
            
            .header, .footer {
                padding: 0.5cm 1.5cm;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_commision.php'; /* Assumed to exist, adjust if needed for other user roles */ ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; /* Assumed to exist */ ?>

            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1 class="page-title-main">Gestion des Comptes Rendus de Réunion</h1>
                        <p class="page-subtitle">Rédigez et gérez vos comptes rendus professionnels.</p>
                    </div>
                </div>

                <div class="message-modal" id="messageModal">
                    <div class="message-modal-content">
                        <button class="message-close" id="messageClose">&times;</button>
                        <div class="message-icon" id="messageIcon"></div>
                        <h3 class="message-title" id="messageTitle"></h3>
                        <p class="message-text" id="messageText"></p>
                        <button class="message-button" id="messageButton">OK</button>
                    </div>
                </div>

                <div class="metadata-form" id="metadataForm">
                    <h2>Informations sur le compte rendu</h2>
                    <p>Veuillez remplir les informations suivantes avant de commencer la rédaction :</p>

                    <?php if (!empty($errors)): ?>
                        <div style="background-color: #fee2e2; color: #ef4444; padding: 10px; border-radius: 8px; margin-bottom: 15px;">
                            <ul>
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form id="crMetadataForm" method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="titre_cr">Titre du compte rendu*</label>
                                <input type="text" id="titre_cr" name="titre_cr" value="<?php echo htmlspecialchars($metadata['titre_cr'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="type_reunion">Type de réunion*</label>
                                <select id="type_reunion" name="type_reunion" required>
                                    <option value="">Sélectionnez...</option>
                                    <option value="comite_direction" <?php echo (isset($metadata['type_reunion']) && $metadata['type_reunion'] == 'comite_direction') ? 'selected' : ''; ?>>Comité de direction</option>
                                    <option value="reunion_projet" <?php echo (isset($metadata['type_reunion']) && $metadata['type_reunion'] == 'reunion_projet') ? 'selected' : ''; ?>>Réunion de projet</option>
                                    <option value="reunion_client" <?php echo (isset($metadata['type_reunion']) && $metadata['type_reunion'] == 'reunion_client') ? 'selected' : ''; ?>>Réunion client</option>
                                    <option value="reunion_equipe" <?php echo (isset($metadata['type_reunion']) && $metadata['type_reunion'] == 'reunion_equipe') ? 'selected' : ''; ?>>Réunion d'équipe</option>
                                    <option value="autre" <?php echo (isset($metadata['type_reunion']) && $metadata['type_reunion'] == 'autre') ? 'selected' : ''; ?>>Autre</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="date_reunion">Date de la réunion*</label>
                                <input type="date" id="date_reunion" name="date_reunion" value="<?php echo htmlspecialchars($metadata['date_reunion'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="heure_reunion">Heure de la réunion*</label>
                                <input type="time" id="heure_reunion" name="heure_reunion" value="<?php echo htmlspecialchars($metadata['heure_reunion'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="lieu_reunion">Lieu*</label>
                                <input type="text" id="lieu_reunion" name="lieu_reunion" value="<?php echo htmlspecialchars($metadata['lieu_reunion'] ?? ''); ?>" placeholder="Ex: Salle 204" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="duree_reunion">Durée*</label>
                                <input type="text" id="duree_reunion" name="duree_reunion" value="<?php echo htmlspecialchars($metadata['duree_reunion'] ?? ''); ?>" placeholder="Ex: 1h30" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="president_seance">Président de séance*</label>
                                <input type="text" id="president_seance" name="president_seance" value="<?php echo htmlspecialchars($metadata['president_seance'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="secretaire_reunion">Secrétaire*</label>
                                <input type="text" id="secretaire_reunion" name="secretaire_reunion" value="<?php echo htmlspecialchars($metadata['secretaire_reunion'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="participants">Participants (séparés par des virgules)*</label>
                                <textarea id="participants" name="participants" required><?php echo htmlspecialchars($metadata['participants'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="absents_excuses">Absents excusés (séparés par des virgules)</label>
                                <textarea id="absents_excuses" name="absents_excuses"><?php echo htmlspecialchars($metadata['absents_excuses'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-outline" id="saveDraftBtnForm">Enregistrer brouillon</button>
                            <button type="submit" name="submit_metadata" class="btn btn-primary">Commencer la rédaction</button>
                        </div>
                    </form>
                </div>

                <div class="word-interface" id="wordInterface">
                    <div class="ribbon">
                        <div class="ribbon-tabs">
                            <div class="ribbon-tab active" data-tab="accueil">Accueil</div>
                            <div class="ribbon-tab" data-tab="insertion">Insertion</div>
                            <div class="ribbon-tab" data-tab="creation">Création</div>
                            <div class="ribbon-tab" data-tab="mise-en-page">Mise en page</div>
                            <div class="ribbon-tab" data-tab="references">Références</div>
                            <div class="ribbon-tab" data-tab="revision">Révision</div>
                            <div class="ribbon-tab" data-tab="affichage">Affichage</div>
                            <div class="ribbon-tab" data-tab="document">Document</div> </div>

                        <div class="ribbon-content active" id="accueil-content">
                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Presse-papiers</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="formatText('cut')">
                                        <i class="fas fa-cut"></i>
                                        <span>Couper</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('copy')">
                                        <i class="fas fa-copy"></i>
                                        <span>Copier</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('paste')">
                                        <i class="fas fa-paste"></i>
                                        <span>Coller</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Police</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="formatText('bold')">
                                        <i class="fas fa-bold"></i>
                                        <span>Gras</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('italic')">
                                        <i class="fas fa-italic"></i>
                                        <span>Italique</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('underline')">
                                        <i class="fas fa-underline"></i>
                                        <span>Souligner</span>
                                    </div>
                                    <div class="ribbon-button" onclick="showFontModal()">
                                        <i class="fas fa-font"></i>
                                        <span>Police</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('fontSize', '3')">
                                        <i class="fas fa-text-height"></i>
                                        <span>Taille +</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('fontSize', '1')">
                                        <i class="fas fa-text-width"></i>
                                        <span>Taille -</span>
                                    </div>
                                    <div class="ribbon-button" onclick="showColorModal()">
                                        <i class="fas fa-palette"></i>
                                        <span>Couleur</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Paragraphe</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="formatText('insertUnorderedList')">
                                        <i class="fas fa-list-ul"></i>
                                        <span>Puces</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('insertOrderedList')">
                                        <i class="fas fa-list-ol"></i>
                                        <span>Numéros</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('justifyLeft')">
                                        <i class="fas fa-align-left"></i>
                                        <span>Aligner à gauche</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('justifyCenter')">
                                        <i class="fas fa-align-center"></i>
                                        <span>Centrer</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('justifyRight')">
                                        <i class="fas fa-align-right"></i>
                                        <span>Aligner à droite</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('justifyFull')">
                                        <i class="fas fa-align-justify"></i>
                                        <span>Justifier</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('outdent')">
                                        <i class="fas fa-outdent"></i>
                                        <span>Diminuer retrait</span>
                                    </div>
                                    <div class="ribbon-button" onclick="formatText('indent')">
                                        <i class="fas fa-indent"></i>
                                        <span>Augmenter retrait</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Styles</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="applyStyle('heading1')">
                                        <i class="fas fa-heading"></i>
                                        <span>Titre 1</span>
                                    </div>
                                    <div class="ribbon-button" onclick="applyStyle('heading2')">
                                        <i class="fas fa-heading"></i>
                                        <span>Titre 2</span>
                                    </div>
                                    <div class="ribbon-button" onclick="applyStyle('heading3')">
                                        <i class="fas fa-heading"></i>
                                        <span>Titre 3</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ribbon-content" id="insertion-content">
                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Pages</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="insertPageBreak()">
                                        <i class="fas fa-file-alt"></i>
                                        <span>Page vierge</span>
                                    </div>
                                    <div class="ribbon-button" onclick="insertPageBreak()">
                                        <i class="fas fa-file"></i>
                                        <span>Saut de page</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ribbon-group">
                                <div class="ribbon-group-title">En-tête/Pied</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="showHeaderModal()">
                                        <i class="fas fa-heading"></i>
                                        <span>En-tête</span>
                                    </div>
                                    <div class="ribbon-button" onclick="showFooterModal()">
                                        <i class="fas fa-walking"></i>
                                        <span>Pied de page</span>
                                    </div>
                                    <div class="ribbon-button" onclick="showPageNumberModal()">
                                        <i class="fas fa-hashtag"></i>
                                        <span>Numéro</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Tableaux</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="showTableModal()">
                                        <i class="fas fa-table"></i>
                                        <span>Tableau</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ribbon-content" id="creation-content">
                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Thèmes</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="applyTheme('default')">
                                        <i class="fas fa-palette"></i>
                                        <span>Thème Office</span>
                                    </div>
                                    <div class="ribbon-button" onclick="applyTheme('formal')">
                                        <i class="fas fa-palette"></i>
                                        <span>Thème Formel</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Mise en forme</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="applyWatermark()">
                                        <i class="fas fa-tint"></i>
                                        <span>Filigrane</span>
                                    </div>
                                    <div class="ribbon-button" onclick="changePageColor()">
                                        <i class="fas fa-fill-drip"></i>
                                        <span>Couleur de page</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ribbon-content" id="mise-en-page-content">
                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Mise en page</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="changeMargins('normal')">
                                        <i class="fas fa-ruler-horizontal"></i>
                                        <span>Marges Normales</span>
                                    </div>
                                    <div class="ribbon-button" onclick="changeMargins('narrow')">
                                        <i class="fas fa-ruler-horizontal"></i>
                                        <span>Marges Étroites</span>
                                    </div>
                                    <div class="ribbon-button" onclick="changeMargins('wide')">
                                        <i class="fas fa-ruler-horizontal"></i>
                                        <span>Marges Larges</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Orientation</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="changeOrientation('portrait')">
                                        <i class="fas fa-undo"></i>
                                        <span>Portrait</span>
                                    </div>
                                    <div class="ribbon-button" onclick="changeOrientation('landscape')">
                                        <i class="fas fa-redo"></i>
                                        <span>Paysage</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Taille</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="changePageSize('A4')">
                                        <i class="fas fa-file"></i>
                                        <span>A4</span>
                                    </div>
                                    <div class="ribbon-button" onclick="changePageSize('letter')">
                                        <i class="fas fa-file"></i>
                                        <span>Lettre</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ribbon-content" id="references-content">
                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Table des matières</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="generateSummary()">
                                        <i class="fas fa-list-ul"></i>
                                        <span>Ajouter Sommaire</span>
                                    </div>
                                    <div class="ribbon-button" onclick="updateSummary()">
                                        <i class="fas fa-sync-alt"></i>
                                        <span>Mettre à jour</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Notes</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="insertFootnote()">
                                        <i class="fas fa-sticky-note"></i>
                                        <span>Note de bas</span>
                                    </div>
                                    <div class="ribbon-button" onclick="insertEndnote()">
                                        <i class="fas fa-sticky-note"></i>
                                        <span>Note de fin</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ribbon-content" id="revision-content">
                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Vérification</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="toggleReviewPanel()">
                                        <i class="fas fa-spell-check"></i>
                                        <span>Vérification</span>
                                    </div>
                                </div>
                            </div>

                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Soumission</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="finalSubmit()">
                                        <i class="fas fa-check-circle"></i>
                                        <span>Soumettre</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ribbon-content" id="affichage-content">
                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Affichage</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="changeView('print')">
                                        <i class="fas fa-print"></i>
                                        <span>Mise en page</span>
                                    </div>
                                    <div class="ribbon-button" onclick="changeView('web')">
                                        <i class="fas fa-desktop"></i>
                                        <span>Web</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ribbon-content" id="document-content">
                            <div class="ribbon-group">
                                <div class="ribbon-group-title">Actions du Compte Rendu</div>
                                <div class="ribbon-buttons">
                                    <div class="ribbon-button" onclick="saveCompteRendu('brouillon')">
                                        <i class="fas fa-save"></i>
                                        <span>Enregistrer</span>
                                    </div>
                                    <div class="ribbon-button" onclick="deleteCompteRendu()">
                                        <i class="fas fa-trash-alt"></i>
                                        <span>Supprimer</span>
                                    </div>
                                    <div class="ribbon-button" onclick="saveCompteRendu('finalise')">
                                        <i class="fas fa-file-export"></i>
                                        <span>Finaliser</span>
                                    </div>
                                    <div class="ribbon-button" onclick="window.location.href='mes_comptes_rendus.php'">
                                        <i class="fas fa-folder-open"></i>
                                        <span>Mes Comptes Rendus</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="document-container" id="document-container">
                        <div class="cover-page" id="cover-page">
                            <div class="cover-university" id="lieu-reunion-display">Lieu de réunion</div>
                            <div class="cover-title" id="titre-cr-display">COMPTE RENDU DE RÉUNION</div>
                            <div class="cover-type-reunion" id="type-reunion-display">Type de réunion</div>
                            <div class="cover-meta">
                                <div class="meta-top">
                                    <div class="meta-left" id="date-heure-reunion-display">Date: JJ/MM/AAAA - Heure: HH:MM</div>
                                    <div class="meta-right" id="duree-reunion-display">Durée: XhYm</div>
                                </div>
                                <div class="meta-bottom">
                                    <div class="meta-left" id="president-display">Président: Prénom NOM</div>
                                    <div class="meta-right" id="secretaire-display">Secrétaire: Prénom NOM</div>
                                </div>
                                <div class="meta-participants" style="margin-top: 1cm; font-size: 10pt; color: #555;">
                                    <p>Participants: <span id="participants-display"></span></p>
                                    <p>Absents excusés: <span id="absents-display"></span></p>
                                </div>
                            </div>
                        </div>

                        <div class="page" id="page-1">
                            <div class="header" contenteditable="true">En-tête - Double-cliquez pour éditer</div>
                            <div class="page-content" contenteditable="true">
                                <?php echo $crContent; // Load existing content or default ?>
                            </div>
                            <div class="footer">
                                <span class="page-number">1</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="review-panel" id="reviewPanel">
        <h3><i class="fas fa-check-circle"></i> Outils de révision</h3>
        <div id="spellingErrors" style="margin-bottom: 20px;">
            <h4>Vérification orthographique</h4>
            <div id="spellingErrorsList"></div>
        </div>
        <div id="documentStats">
            <h4>Statistiques du document</h4>
            <div id="statsContent"></div>
        </div>
    </div>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <div id="fontModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Options de police</h3>
                <span class="close-modal" onclick="closeModal('fontModal')">&times;</span>
            </div>
            <div class="modal-body">
                <select id="fontFamily" class="form-select">
                    <option value="Arial">Arial</option>
                    <option value="Times New Roman">Times New Roman</option>
                    <option value="Courier New">Courier New</option>
                    <option value="Georgia">Georgia</option>
                    <option value="Verdana">Verdana</option>
                </select>
                
                <select id="fontSize" class="form-select">
                    <option value="1">8pt</option>
                    <option value="2">10pt</option>
                    <option value="3" selected>12pt</option>
                    <option value="4">14pt</option>
                    <option value="5">18pt</option>
                    <option value="6">24pt</option>
                    <option value="7">36pt</option>
                </select>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('fontModal')">Annuler</button>
                <button class="btn btn-primary" onclick="applyFontChanges()">Appliquer</button>
            </div>
        </div>
    </div>

    <div id="colorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Couleur du texte</h3>
                <span class="close-modal" onclick="closeModal('colorModal')">&times;</span>
            </div>
            <div class="modal-body">
                <select id="textColor" class="form-select">
                    <option value="black">Noir</option>
                    <option value="red">Rouge</option>
                    <option value="blue">Bleu</option>
                    <option value="green">Vert</option>
                    <option value="purple">Violet</option>
                    <option value="#2b579a">Bleu Office</option>
                </select>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('colorModal')">Annuler</button>
                <button class="btn btn-primary" onclick="applyColorChanges()">Appliquer</button>
            </div>
        </div>
    </div>

    <div id="headerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Définir l'en-tête</h3>
                <span class="close-modal" onclick="closeModal('headerModal')">&times;</span>
            </div>
            <div class="modal-body">
                <label for="headerText">Texte de l'en-tête:</label>
                <input type="text" id="headerText" class="form-input" placeholder="Entrez le texte de l'en-tête">
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('headerModal')">Annuler</button>
                <button class="btn btn-primary" onclick="applyHeader()">Appliquer</button>
            </div>
        </div>
    </div>

    <div id="footerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Définir le pied de page</h3>
                <span class="close-modal" onclick="closeModal('footerModal')">&times;</span>
            </div>
            <div class="modal-body">
                <label for="footerText">Texte du pied de page:</label>
                <input type="text" id="footerText" class="form-input" placeholder="Entrez le texte du pied de page">
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('footerModal')">Annuler</button>
                <button class="btn btn-primary" onclick="applyFooter()">Appliquer</button>
            </div>
        </div>
    </div>

    <div id="pageNumberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Numéros de page</h3>
                <span class="close-modal" onclick="closeModal('pageNumberModal')">&times;</span>
            </div>
            <div class="modal-body">
                <p>Où souhaitez-vous placer les numéros de page ?</p>
                <label><input type="radio" name="pageNumberPosition" value="bottom-left"> En bas, à gauche</label><br>
                <label><input type="radio" name="pageNumberPosition" value="bottom-center" checked> En bas, au centre</label><br>
                <label><input type="radio" name="pageNumberPosition" value="bottom-right"> En bas, à droite</label>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('pageNumberModal')">Annuler</button>
                <button class="btn btn-primary" onclick="applyPageNumber()">Appliquer</button>
            </div>
        </div>
    </div>

    <div id="tableModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Insérer un tableau</h3>
                <span class="close-modal" onclick="closeModal('tableModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="tableRows">Nombre de lignes:</label>
                    <input type="number" id="tableRows" class="form-input" value="3" min="1">
                </div>
                <div class="form-group">
                    <label for="tableCols">Nombre de colonnes:</label>
                    <input type="number" id="tableCols" class="form-input" value="3" min="1">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('tableModal')">Annuler</button>
                <button class="btn btn-primary" onclick="insertTable()">Insérer le tableau</button>
            </div>
        </div>
    </div>

    <div id="contextMenu" class="context-menu">
        <div class="context-menu-item" onclick="formatText('bold')"><i class="fas fa-bold"></i> Gras</div>
        <div class="context-menu-item" onclick="formatText('italic')"><i class="fas fa-italic"></i> Italique</div>
        <div class="context-menu-item" onclick="formatText('underline')"><i class="fas fa-underline"></i> Souligner</div>
        <div class="context-menu-divider"></div>
        <div class="context-submenu">
            <div class="context-menu-item"><i class="fas fa-font"></i> Police <i class="fas fa-chevron-right" style="float: right;"></i></div>
            <div class="context-submenu-content">
                <div class="context-menu-item" onclick="showFontModal()"><i class="fas fa-font"></i> Type de police</div>
                <div class="context-menu-item" onclick="showColorModal()"><i class="fas fa-palette"></i> Couleur</div>
                <div class="context-menu-item" onclick="formatText('fontSize', '3')"><i class="fas fa-text-height"></i> Taille +</div>
                <div class="context-menu-item" onclick="formatText('fontSize', '1')"><i class="fas fa-text-width"></i> Taille -</div>
            </div>
        </div>
        <div class="context-menu-divider"></div>
        <div class="context-menu-item" onclick="formatText('insertUnorderedList')"><i class="fas fa-list-ul"></i> Puces</div>
        <div class="context-menu-item" onclick="formatText('insertOrderedList')"><i class="fas fa-list-ol"></i> Numérotation</div>
        <div class="context-menu-divider"></div>
        <div class="context-menu-item" onclick="formatText('cut')"><i class="fas fa-cut"></i> Couper</div>
        <div class="context-menu-item" onclick="formatText('copy')"><i class="fas fa-copy"></i> Copier</div>
        <div class="context-menu-item" onclick="formatText('paste')"><i class="fas fa-paste"></i> Coller</div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variables globales
        let currentPage = 1;
        let totalPages = 1;
        let currentRange = null; // To store the current text selection range

        // Initialize metadata from PHP
        let metadata = <?php echo json_encode($metadata); ?>;
        let currentCrId = <?php echo json_encode($_SESSION['current_cr_id'] ?? null); ?>;

        // Message Modal elements
        const messageModal = document.getElementById('messageModal');
        const messageTitle = document.getElementById('messageTitle');
        const messageText = document.getElementById('messageText');
        const messageIcon = document.getElementById('messageIcon');
        const messageButton = document.getElementById('messageButton');
        const messageClose = document.getElementById('messageClose');
        const loadingOverlay = document.getElementById('loadingOverlay');

        // Function to show messages in the unified modal
        function showAlert(message, type = 'success', title = null) {
            if (!title) {
                switch (type) {
                    case 'success': title = 'Succès'; break;
                    case 'error': title = 'Erreur'; break;
                    case 'warning': title = 'Attention'; break;
                    case 'info': title = 'Information'; break;
                    default: title = 'Message';
                }
            }
            messageIcon.className = 'message-icon ' + type;
            messageIcon.innerHTML = ''; // Clear previous icon
            switch (type) {
                case 'success': messageIcon.innerHTML = '<i class="fas fa-check-circle"></i>'; break;
                case 'error': messageIcon.innerHTML = '<i class="fas fa-times-circle"></i>'; break;
                case 'warning': messageIcon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>'; break;
                case 'info': messageIcon.innerHTML = '<i class="fas fa-info-circle"></i>'; break;
                default: messageIcon.innerHTML = '<i class="fas fa-bell"></i>';
            }
            messageTitle.textContent = title;
            messageText.textContent = message;
            messageModal.style.display = 'flex';
        }

        function closeMessageModal() {
            messageModal.style.display = 'none';
        }
        messageButton.addEventListener('click', closeMessageModal);
        messageClose.addEventListener('click', closeMessageModal);
        messageModal.addEventListener('click', function(e) {
            if (e.target === messageModal) {
                closeMessageModal();
            }
        });

        // Function to show/hide loading overlay
        function showLoading(show) {
            loadingOverlay.style.display = show ? 'flex' : 'none';
        }

        // Initialisation du document
        document.addEventListener('DOMContentLoaded', function() {
            // Check if editor should be shown based on PHP session
            const showEditor = <?php echo json_encode(isset($_SESSION['show_editor']) ? $_SESSION['show_editor'] : false); ?>;
            if (showEditor) {
                document.getElementById('metadataForm').style.display = 'none';
                document.getElementById('wordInterface').style.display = 'block';
                updateCoverPage(); // Update cover page with loaded metadata
                updatePageNumbers();
                // Load CR content into the editor only if coming from an existing CR
                if (currentCrId) {
                    const firstPageContentDiv = document.querySelector('#page-1 .page-content');
                    if (firstPageContentDiv) {
                        firstPageContentDiv.innerHTML = <?php echo json_encode($crContent); ?>;
                    }
                }
            } else {
                 document.getElementById('metadataForm').style.display = 'block';
                 document.getElementById('wordInterface').style.display = 'none';
            }


            // Gestion du formulaire de métadonnées
            document.getElementById('crMetadataForm').addEventListener('submit', function(e) {
                e.preventDefault(); // Prevent default form submission

                // Client-side validation for period format (if any, copy from depot_rapport)
                // (No specific period format for CRs here, but keep structure if needed later)

                // Collect metadata for JavaScript use (cover page update)
                metadata = {
                    titre_cr: document.getElementById('titre_cr').value,
                    type_reunion: document.getElementById('type_reunion').value,
                    date_reunion: document.getElementById('date_reunion').value,
                    heure_reunion: document.getElementById('heure_reunion').value,
                    lieu_reunion: document.getElementById('lieu_reunion').value,
                    duree_reunion: document.getElementById('duree_reunion').value,
                    president_seance: document.getElementById('president_seance').value,
                    secretaire_reunion: document.getElementById('secretaire_reunion').value,
                    participants: document.getElementById('participants').value,
                    absents_excuses: document.getElementById('absents_excuses').value
                };

                // Server-side submission (simulated for client-side logic to proceed)
                // In a real scenario, you might do an AJAX call here to save metadata and get CR ID
                // For now, we update client-side and show editor
                updateCoverPage();
                document.getElementById('metadataForm').style.display = 'none';
                document.getElementById('wordInterface').style.display = 'block';
                updatePageNumbers();
                focusLastEditor();
            });
            
            // Gestion des onglets du ruban
            document.querySelectorAll('.ribbon-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.ribbon-tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.ribbon-content').forEach(c => c.classList.remove('active'));
                    
                    this.classList.add('active');
                    document.getElementById(`${this.dataset.tab}-content`).classList.add('active');
                });
            });
            
            // Gestion du bouton Enregistrer brouillon dans le formulaire de métadonnées
            document.getElementById('saveDraftBtnForm').addEventListener('click', () => saveCompteRendu('brouillon'));
            
            // Gestion du menu contextuel
            document.querySelectorAll('[contenteditable="true"]').forEach(editor => {
                editor.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    const contextMenu = document.getElementById('contextMenu');
                    contextMenu.style.display = 'block';
                    contextMenu.style.left = `${e.pageX}px`;
                    contextMenu.style.top = `${e.pageY}px`;
                    
                    // Store the current selection range for later use
                    const selection = window.getSelection();
                    if (selection.rangeCount > 0) {
                        currentRange = selection.getRangeAt(0);
                    }
                });

                editor.addEventListener('mouseup', function() {
                    // Update currentRange when selection changes
                    const selection = window.getSelection();
                    if (selection.rangeCount > 0) {
                        currentRange = selection.getRangeAt(0);
                    }
                });

                editor.addEventListener('keyup', function() {
                    // Update currentRange when typing, to ensure cursor position is remembered
                    const selection = window.getSelection();
                    if (selection.rangeCount > 0) {
                        currentRange = selection.getRangeAt(0);
                    }
                });
            });
            
            // Fermer le menu contextuel quand on clique ailleurs
            document.addEventListener('click', function() {
                document.getElementById('contextMenu').style.display = 'none';
            });
        });

        // Global function to restore selection, used after modal interactions
        function restoreSelection() {
            if (currentRange) {
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(currentRange);
            }
        }

        // General modal functions
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'flex'; // Use flex to center
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Modals for text formatting (font, color)
        function showFontModal() {
            showModal('fontModal');
        }

        function showColorModal() {
            showModal('colorModal');
        }

        function applyFontChanges() {
            restoreSelection(); // Restore previous selection before applying command
            const fontFamily = document.getElementById('fontFamily').value;
            const fontSize = document.getElementById('fontSize').value;
            
            document.execCommand('fontName', false, fontFamily);
            document.execCommand('fontSize', false, fontSize);
            closeModal('fontModal');
            focusLastEditor(); // Re-focus to keep the editor active
        }

        function applyColorChanges() {
            restoreSelection(); // Restore previous selection before applying command
            const color = document.getElementById('textColor').value;
            document.execCommand('foreColor', false, color);
            closeModal('colorModal');
            focusLastEditor(); // Re-focus to keep the editor active
        }

        // Modals for Header, Footer, Page Number, Table
        function showHeaderModal() {
            showModal('headerModal');
            // Pre-fill with current header text if available from the first page
            const firstPageHeader = document.querySelector('.page .header');
            if (firstPageHeader) {
                document.getElementById('headerText').value = firstPageHeader.innerText.replace('En-tête - Double-cliquez pour éditer', '').trim();
            }
        }

        function applyHeader() {
            const headerText = document.getElementById('headerText').value;
            document.querySelectorAll('.header').forEach(header => {
                header.innerHTML = headerText || 'En-tête - Double-cliquez pour éditer'; // Keep placeholder if empty
            });
            closeModal('headerModal');
            focusLastEditor();
        }

        function showFooterModal() {
            showModal('footerModal');
            // Pre-fill with current footer text if available from the first page
            const firstPageFooter = document.querySelector('.page .footer');
            if (firstPageFooter) {
                // Remove page number span for editing convenience
                let footerContent = firstPageFooter.innerText;
                const pageNumSpan = firstPageFooter.querySelector('.page-number');
                if (pageNumSpan) {
                    footerContent = footerContent.replace(pageNumSpan.textContent, '').trim();
                }
                document.getElementById('footerText').value = footerContent;
            }
        }

        function applyFooter() {
            const footerText = document.getElementById('footerText').value;
            document.querySelectorAll('.footer').forEach(footer => {
                const pageNumberSpan = footer.querySelector('.page-number');
                if (pageNumberSpan) {
                    footer.innerHTML = (footerText || '') + ' ' + pageNumberSpan.outerHTML;
                } else {
                    footer.innerHTML = footerText;
                }
            });
            closeModal('footerModal');
            focusLastEditor();
        }

        function showPageNumberModal() {
            showModal('pageNumberModal');
        }

        function applyPageNumber() {
            const position = document.querySelector('input[name="pageNumberPosition"]:checked').value;
            
            document.querySelectorAll('.footer').forEach(footer => {
                let pageNumberSpan = footer.querySelector('.page-number');
                if (!pageNumberSpan) {
                    pageNumberSpan = document.createElement('span');
                    pageNumberSpan.className = 'page-number';
                    footer.appendChild(pageNumberSpan);
                }
                
                // Reset text alignment for footer
                footer.style.textAlign = ''; // Clear previous alignment
                
                if (position === 'bottom-left') {
                    footer.style.textAlign = 'left';
                    pageNumberSpan.style.position = 'static'; // Reset position
                    pageNumberSpan.style.right = 'auto';
                    pageNumberSpan.style.left = '0';
                } else if (position === 'bottom-center') {
                    footer.style.textAlign = 'center';
                    pageNumberSpan.style.position = 'static';
                    pageNumberSpan.style.right = 'auto';
                    pageNumberSpan.style.left = 'auto';
                } else if (position === 'bottom-right') {
                    footer.style.textAlign = 'right';
                    pageNumberSpan.style.position = 'static';
                    pageNumberSpan.style.right = '0';
                    pageNumberSpan.style.left = 'auto';
                }
            });
            updatePageNumbers();
            closeModal('pageNumberModal');
            focusLastEditor();
        }

        function showTableModal() {
            showModal('tableModal');
            // Set default values in the modal input fields
            document.getElementById('tableRows').value = 3;
            document.getElementById('tableCols').value = 3;
        }

        function insertTable() {
            restoreSelection(); // Restore cursor position

            const rows = parseInt(document.getElementById('tableRows').value);
            const cols = parseInt(document.getElementById('tableCols').value);
            
            if (isNaN(rows) || isNaN(cols) || rows < 1 || cols < 1) {
                alert("Veuillez entrer des nombres valides pour les lignes et les colonnes.");
                return;
            }

            let tableHtml = '<table style="border-collapse: collapse; width: 100%;">';
            for (let i = 0; i < rows; i++) {
                tableHtml += '<tr>';
                for (let j = 0; j < cols; j++) {
                    tableHtml += `<td style="border: 1px solid #ddd; padding: 8px;">&nbsp;</td>`;
                }
                tableHtml += '</tr>';
            }
            tableHtml += '</table><p><br></p>'; // Add a paragraph after the table for easier editing

            // Insert HTML at the current cursor position
            document.execCommand('insertHTML', false, tableHtml);
            
            closeModal('tableModal');
            focusLastEditor();
        }

        // Mettre à jour la page de garde avec les métadonnées
        function updateCoverPage() {
            document.getElementById('titre-cr-display').textContent = (metadata.titre_cr || 'COMPTE RENDU DE RÉUNION').toUpperCase();
            document.getElementById('type-reunion-display').textContent = metadata.type_reunion ? `Type: ${metadata.type_reunion.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}` : 'Type de réunion';
            document.getElementById('lieu-reunion-display').textContent = metadata.lieu_reunion || 'Lieu de réunion';
            
            const formattedDate = metadata.date_reunion ? new Date(metadata.date_reunion).toLocaleDateString('fr-FR') : 'JJ/MM/AAAA';
            document.getElementById('date-heure-reunion-display').textContent = `Date: ${formattedDate} - Heure: ${metadata.heure_reunion || 'HH:MM'}`;
            document.getElementById('duree-reunion-display').textContent = `Durée: ${metadata.duree_reunion || 'XhYm'}`;
            document.getElementById('president-display').textContent = `Président: ${metadata.president_seance || 'Prénom NOM'}`;
            document.getElementById('secretaire-display').textContent = `Secrétaire: ${metadata.secretaire_reunion || 'Prénom NOM'}`;
            document.getElementById('participants-display').textContent = metadata.participants || 'Non spécifié';
            document.getElementById('absents-display').textContent = metadata.absents_excuses || 'Aucun';
        }

        // Fonctions de formatage
        function formatText(command, value = null) {
            restoreSelection(); // Ensure the command applies to the last active selection
            document.execCommand(command, false, value);
            focusLastEditor();
        }

        function applyStyle(style) {
            restoreSelection(); // Ensure the command applies to the last active selection
            const styles = {
                'heading1': 'font-size: 16pt; font-weight: bold; color: var(--primary-800);',
                'heading2': 'font-size: 14pt; font-weight: bold; color: var(--primary-800);',
                'heading3': 'font-size: 12pt; font-weight: bold; color: var(--primary-800);'
            };
            
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                const range = selection.getRangeAt(0);
                const newNode = document.createElement(style === 'heading1' ? 'h1' : (style === 'heading2' ? 'h2' : 'h3'));
                newNode.style.cssText = styles[style];
                newNode.appendChild(range.extractContents());
                range.insertNode(newNode);
                selection.selectAllChildren(newNode); // Select the new heading
            }
            focusLastEditor();
        }

        // Gestion des pages
        function insertPageBreak() {
            const container = document.getElementById('document-container');
            const newPage = document.createElement('div');
            newPage.className = 'page';
            newPage.id = `page-${++totalPages}`;
            
            // Copier l'en-tête et pied de page de la page précédente
            const prevPage = document.getElementById(`page-${totalPages-1}`);
            const prevHeader = prevPage ? prevPage.querySelector('.header').innerHTML : 'En-tête - Double-cliquez pour éditer';
            const prevFooter = prevPage ? prevPage.querySelector('.footer').innerHTML : '<span class="page-number"></span>';
            
            newPage.innerHTML = `
                <div class="header" contenteditable="true">${prevHeader}</div>
                <div class="page-content" contenteditable="true"><p><br></p></div>
                <div class="footer">${prevFooter}</div>
            `;
            
            container.appendChild(newPage);
            updatePageNumbers();
            focusLastEditor();
            
            // Faire défiler vers la nouvelle page
            newPage.scrollIntoView({ behavior: 'smooth' });
        }

        function updatePageNumbers() {
            document.querySelectorAll('.page').forEach((page, index) => {
                const pageNumber = page.querySelector('.page-number');
                if (pageNumber) {
                    pageNumber.textContent = index + 1;
                }
            });
            totalPages = document.querySelectorAll('.page').length;
        }

        // Mise en page
        function changeMargins(type) {
            const margins = {
                'normal': '2.5cm 3cm',
                'narrow': '1.5cm 2cm',
                'wide': '3.5cm 4cm'
            };
            
            document.querySelectorAll('.page-content').forEach(content => {
                content.style.padding = margins[type];
            });
        }

        function changeOrientation(orientation) {
            const pages = document.querySelectorAll('.page');
            pages.forEach(page => {
                if (orientation === 'landscape') {
                    page.style.width = '29.7cm';
                    page.style.minHeight = '21cm';
                } else {
                    page.style.width = '21cm';
                    page.style.minHeight = '29.7cm';
                }
            });
        }

        // Sommaire automatique
        function generateSummary() {
            const titles = [];
            // Remove existing IDs to prevent duplicates and re-assign fresh IDs
            document.querySelectorAll('.page-content h1, .page-content h2, .page-content h3').forEach(title => {
                title.removeAttribute('id');
            });

            document.querySelectorAll('.page-content h1, .page-content h2, .page-content h3').forEach(title => {
                const level = parseInt(title.tagName.substring(1));
                const indent = '&nbsp;'.repeat((level - 1) * 4);
                // Create a clean ID from the text content
                const titleId = title.textContent.trim().replace(/\s+/g, '-').replace(/[^\w-]/g, '').toLowerCase();
                title.id = titleId; // Assign the ID to the heading for linking
                titles.push(`${indent}<a href="#${titleId}" onclick="navigateToTitle('${titleId}')">${title.textContent}</a>`);
            });
            
            if (titles.length > 0) {
                const summary = `<h2>Table des matières</h2><div style="margin-left: 20px;">${titles.join('<br>')}</div>`;
                
                // Find the first editable content page
                const firstPageContent = document.querySelector('.page-content');
                // Check if a summary already exists (by checking if the first page content contains a specific class or ID for summary)
                const existingSummaryContainer = firstPageContent.querySelector('h2:contains("Table des matières") + div[style*="margin-left: 20px;"]');


                if (firstPageContent) {
                    if (existingSummaryContainer) {
                         // Replace existing summary container by replacing its parent's innerHTML
                        existingSummaryContainer.parentNode.innerHTML = summary; // Replace parent to ensure H2 and div are together
                    } else {
                        // Insert new summary at the beginning
                        firstPageContent.innerHTML = summary + firstPageContent.innerHTML;
                    }
                    focusLastEditor();
                }
            } else {
                showAlert("Aucun titre trouvé dans le document. Utilisez les styles Titre 1, Titre 2, etc.", 'warning');
            }
        }

        function navigateToTitle(id) {
            const element = document.getElementById(id);
            if (element) {
                element.scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Révision et vérification
        function toggleReviewPanel() {
            const panel = document.getElementById('reviewPanel');
            panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
            
            if (panel.style.display === 'block') {
                spellCheck();
                wordCount();
            }
        }

        function spellCheck() {
            // Simple spelling check simulation: highlight words not in a very basic dictionary
            const content = Array.from(document.querySelectorAll('.page-content')).map(div => div.innerText).join(' ');
            const words = content.split(/\s+|,|\.|\?|!|;|\(|\)|\[|\]|{|}|\/|\\|:|-/).filter(word => word.length > 1);
            const commonWords = new Set(["le", "la", "les", "un", "une", "des", "je", "tu", "il", "elle", "nous", "vous", "ils", "elles", "est", "sont", "ai", "as", "a", "avons", "avez", "ont", "être", "avoir", "dans", "sur", "avec", "pour", "par", "et", "ou", "mais", "où", "qui", "que", "ce", "cette", "ces", "mon", "ma", "mes", "ton", "ta", "tes", "son", "sa", "ses", "compte", "rendu", "réunion", "de", "la", "du", "les", "des", "nous", "avons", "discuté", "des", "points", "suivants"]); // Very limited dictionary for CR
            
            const errors = new Set();
            words.forEach(word => {
                const cleanedWord = word.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, ""); // Remove accents
                if (cleanedWord.length > 2 && !commonWords.has(cleanedWord)) { // A basic heuristic
                    errors.add(word); // Add original word to display
                }
            });
            
            const errorsList = document.getElementById('spellingErrorsList');
            if (errors.size > 0) {
                let errorHtml = '<ul>';
                errors.forEach(error => {
                    errorHtml += `<li>${htmlspecialchars(error)} - <a href="#" onclick="correctSpelling('${htmlspecialchars(error)}')">Corriger</a></li>`;
                });
                errorHtml += '</ul>';
                errorsList.innerHTML = errorHtml;
            } else {
                errorsList.innerHTML = '<p>Aucune erreur orthographique potentielle trouvée.</p>';
            }
        }

        function htmlspecialchars(str) {
            var map = {
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                "\"": "&quot;",
                "'": "&#039;"
            };
            return str.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        function correctSpelling(wordToCorrect) {
            const correction = prompt(`Corriger "${wordToCorrect}" par:`, wordToCorrect);
            if (correction !== null) { // If user didn't cancel
                document.querySelectorAll('.page-content').forEach(contentDiv => {
                    contentDiv.innerHTML = contentDiv.innerHTML.replace(new RegExp(wordToCorrect, 'g'), correction);
                });
                spellCheck(); // Re-run spell check after correction
            }
        }

        function wordCount() {
            const content = Array.from(document.querySelectorAll('.page-content')).map(div => div.innerText).join(' ');
            const words = content.split(/\s+/).filter(word => word.length > 0);
            const characters = content.length;
            const pages = document.querySelectorAll('.page').length;
            
            document.getElementById('statsContent').innerHTML = `
                <p>Mots: ${words.length}</p>
                <p>Caractères: ${characters}</p>
                <p>Pages: ${pages}</p>
            `;
        }

        // --- Compte Rendu Save/Delete/Finalize Functions ---
        async function saveCompteRendu(status = 'brouillon') {
            showLoading(true);
            const crContent = document.getElementById('page-1').parentNode.innerHTML; // Get all pages' HTML

            // Ensure metadata is up-to-date from form (even if form is hidden)
            const formMetadata = {
                titre_cr: document.getElementById('titre_cr').value,
                type_reunion: document.getElementById('type_reunion').value,
                date_reunion: document.getElementById('date_reunion').value,
                heure_reunion: document.getElementById('heure_reunion').value,
                lieu_reunion: document.getElementById('lieu_reunion').value,
                duree_reunion: document.getElementById('duree_reunion').value,
                president_seance: document.getElementById('president_seance').value,
                secretaire_reunion: document.getElementById('secretaire_reunion').value,
                participants: document.getElementById('participants').value,
                absents_excuses: document.getElementById('absents_excuses').value
            };

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'save_compte_rendu',
                        cr_id: currentCrId, // Will be null for first save, then populated
                        metadata: JSON.stringify(formMetadata),
                        content: crContent,
                        status: status
                    })
                });
                const result = await response.json();

                if (result.success) {
                    showAlert(result.message, 'success');
                    if (result.cr_id) {
                        currentCrId = result.cr_id; // Update global ID for subsequent saves
                    }
                    // If finalized, maybe disable further editing or redirect
                    if (status === 'finalise') {
                        // Optionally disable editor controls and inform user
                        // document.getElementById('wordInterface').contentEditable = false; // Example
                        // document.getElementById('saveCompteRenduBtn').disabled = true; // Example
                        // Redirect to a list of finalized CRs or similar
                         setTimeout(() => { window.location.href = 'mes_comptes_rendus.php'; }, 2000); // Redirect after 2 seconds
                    }
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                console.error('Error saving compte rendu:', error);
                showAlert('Erreur lors de l\'enregistrement du compte rendu. Veuillez réessayer.', 'error');
            } finally {
                showLoading(false);
            }
        }

        async function deleteCompteRendu() {
            if (!currentCrId) {
                showAlert('Aucun compte rendu à supprimer.', 'warning');
                return;
            }

            if (!confirm("Êtes-vous sûr de vouloir supprimer ce compte rendu ? Cette action est irréversible.")) {
                return;
            }

            showLoading(true);
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'delete_compte_rendu',
                        cr_id: currentCrId
                    })
                });
                const result = await response.json();

                if (result.success) {
                    showAlert(result.message, 'success');
                    currentCrId = null; // Clear ID
                    // Reset the form and editor to initial state
                    document.getElementById('crMetadataForm').reset();
                    document.getElementById('metadataForm').style.display = 'block';
                    document.getElementById('wordInterface').style.display = 'none';
                    // Clear editor content visually
                    document.querySelector('#page-1 .page-content').innerHTML = '<p><h1>Ordre du jour</h1><ol><li>Accueil et tour de table</li><li>Approval du compte rendu précédent</li><li>Points d\'action en cours</li><li>Nouveaux points à discuter</li><li>Divers</li><li>Date de la prochaine réunion</li></ol><h1>Détails de la réunion</h1><h2>1. Accueil et tour de table</h2><p>Commencez à taper les détails de la réunion ici...</p></p>';
                    updateCoverPage(); // Reset cover page
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                console.error('Error deleting compte rendu:', error);
                showAlert('Erreur lors de la suppression du compte rendu. Veuillez réessayer.', 'error');
            } finally {
                showLoading(false);
            }
        }

        // Example for "Soumettre" (Finaliser) button logic
        function finalSubmit() {
            // This button might trigger saveCompteRendu with status 'finalise'
            // or have its own specific logic if 'soumettre' has additional steps
            if (!currentCrId) {
                showAlert("Veuillez d'abord enregistrer le compte rendu.", "warning");
                return;
            }
            if (confirm("Êtes-vous sûr de vouloir soumettre définitivement ce compte rendu ? Il ne sera plus modifiable par la suite.")) {
                saveCompteRendu('finalise'); // Call save with status 'finalise'
            }
        }


        // Utilitaires
        function focusLastEditor() {
            const editors = document.querySelectorAll('[contenteditable="true"]');
            if (editors.length > 0) {
                // To properly set cursor at the end of the last editable content:
                const lastEditor = editors[editors.length - 1];
                const range = document.createRange();
                const selection = window.getSelection();
                
                // If last editor has children, try to set cursor after last child
                if (lastEditor.lastChild) {
                    range.setStartAfter(lastEditor.lastChild);
                    range.collapse(true);
                } else {
                    // If empty, set at the start
                    range.setStart(lastEditor, 0);
                    range.collapse(true);
                }
                
                selection.removeAllRanges();
                selection.addRange(range);
                lastEditor.focus();
            }
        }

        // Functions for other tabs (simulated)
        function applyTheme(theme) {
            const colors = {
                'default': { primary: '#1e293b', secondary: '#ffffff' }, // Using primary-800 for title, white for page
                'formal': { primary: '#1e3a8a', secondary: '#f8fafc' } // Using accent-900 for title, primary-50 for page
            };
            
            document.querySelectorAll('.page').forEach(page => {
                page.style.backgroundColor = colors[theme].secondary;
            });
            
            document.querySelectorAll('h1, h2, h3').forEach(title => {
                title.style.color = colors[theme].primary;
            });
            
            showAlert(`Thème ${theme} appliqué`, 'info');
        }

        function applyWatermark() {
            const watermarkText = prompt("Entrez le texte du filigrane:", "CONFIDENTIEL");
            if (watermarkText) {
                document.querySelectorAll('.page').forEach(page => {
                    page.style.position = 'relative';
                    const existingWatermark = page.querySelector('.watermark');
                    if (existingWatermark) {
                        existingWatermark.textContent = watermarkText;
                    } else {
                        const watermark = document.createElement('div');
                        watermark.className = 'watermark';
                        watermark.style.position = 'absolute';
                        watermark.style.top = '50%';
                        watermark.style.left = '50%';
                        watermark.style.transform = 'translate(-50%, -50%) rotate(-45deg)';
                        watermark.style.fontSize = '72px';
                        watermark.style.color = 'rgba(0,0,0,0.1)';
                        watermark.style.zIndex = '1000';
                        watermark.style.pointerEvents = 'none';
                        watermark.textContent = watermarkText;
                        page.appendChild(watermark);
                    }
                });
            }
        }

        function changePageColor() {
            const color = prompt("Entrez une couleur hexadécimale (ex: #f0f0f0):", "#ffffff");
            if (color) {
                document.querySelectorAll('.page').forEach(page => {
                    page.style.backgroundColor = color;
                });
            }
        }

        function changePageSize(size) {
            const sizes = {
                'A4': { width: '21cm', height: '29.7cm' },
                'letter': { width: '21.59cm', height: '27.94cm' }
            };
            
            document.querySelectorAll('.page').forEach(page => {
                page.style.width = sizes[size].width;
                page.style.minHeight = sizes[size].height;
            });
            
            showAlert(`Taille de page changée en ${size}`, 'info');
        }

        function insertFootnote() {
            restoreSelection();
            document.execCommand('insertHTML', false, '<sup>[1]</sup>');
            focusLastEditor();
        }

        function insertEndnote() {
            restoreSelection();
            document.execCommand('insertHTML', false, '<sup>[a]</sup>');
            focusLastEditor();
        }

        function updateSummary() {
            generateSummary();
            showAlert("Table des matières mise à jour", 'info');
        }

        function changeView(view) {
            if (view === 'print') {
                document.querySelectorAll('.page').forEach(page => {
                    page.style.boxShadow = '0 0 10px rgba(0,0,0,0.1)';
                    page.style.margin = '0 auto 20px';
                });
            } else {
                document.querySelectorAll('.page').forEach(page => {
                    page.style.boxShadow = 'none';
                    page.style.margin = '0';
                    page.style.width = '100%';
                    page.style.minHeight = 'auto';
                });
            }
        }
    </script>
</body>
</html>