<?php
// gestion_niveau.php
require_once 'config.php'; // Assurez-vous que ce fichier inclut votre connexion PDO et les fonctions isLoggedIn/redirect

if (!isLoggedIn()) {
    redirect('loginForm.php'); // Redirige si l'utilisateur n'est pas connecté
}

// Traitement AJAX pour l'ajout/suppression/modification de filiere_niveau_detail
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';

    try {
        $pdo->beginTransaction();

        switch ($action) {
            case 'create':
                $fk_id_filiere = $_POST['fk_id_filiere'];
                $fk_id_niv_etu = $_POST['fk_id_niv_etu'];
                $montant_scolarite_total = floatval(str_replace(',', '.', $_POST['montant_scolarite_total'])); // Handle comma as decimal separator
                $versement_1 = !empty($_POST['versement_1']) ? floatval(str_replace(',', '.', $_POST['versement_1'])) : null;
                $versement_2 = !empty($_POST['versement_2']) ? floatval(str_replace(',', '.', $_POST['versement_2'])) : null;
                $versement_3 = !empty($_POST['versement_3']) ? floatval(str_replace(',', '.', $_POST['versement_3'])) : null;
                $versement_4 = !empty($_POST['versement_4']) ? floatval(str_replace(',', '.', $_POST['versement_4'])) : null;

                // Validation
                if (empty($fk_id_filiere) || empty($fk_id_niv_etu) || !is_numeric($montant_scolarite_total) || $montant_scolarite_total <= 0) {
                    throw new Exception("Tous les champs obligatoires (Filière, Niveau, Montant total) doivent être remplis avec des valeurs valides.");
                }

                // Vérifier si la combinaison Filière-Niveau existe déjà
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM filiere_niveau_detail WHERE fk_id_filiere = ? AND fk_id_niv_etu = ?");
                $checkStmt->execute([$fk_id_filiere, $fk_id_niv_etu]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Les détails de scolarité pour cette combinaison Filière et Niveau existent déjà.");
                }

                // Insérer la nouvelle entrée dans filiere_niveau_detail
                $stmt = $pdo->prepare("INSERT INTO filiere_niveau_detail (fk_id_filiere, fk_id_niv_etu, montant_scolarite_total, versement_1, versement_2, versement_3, versement_4) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fk_id_filiere, $fk_id_niv_etu, $montant_scolarite_total, $versement_1, $versement_2, $versement_3, $versement_4]);
                $newId = $pdo->lastInsertId();

                // Fetch details to return for UI update
                $query = "SELECT
                            fnd.id_filiere_niveau,
                            f.lib_filiere,
                            ne.lib_niv_etu,
                            fnd.montant_scolarite_total,
                            fnd.versement_1,
                            fnd.versement_2,
                            fnd.versement_3,
                            fnd.versement_4,
                            f.id_filiere,
                            ne.id_niv_etu
                          FROM filiere_niveau_detail fnd
                          JOIN filiere f ON fnd.fk_id_filiere = f.id_filiere
                          JOIN niveau_etude ne ON fnd.fk_id_niv_etu = ne.id_niv_etu
                          WHERE fnd.id_filiere_niveau = ?";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$newId]);
                $newData = $stmt->fetch(PDO::FETCH_ASSOC);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Détails de scolarité ajoutés avec succès !', 'data' => $newData]);
                break;

            case 'update':
                $id_filiere_niveau = $_POST['id_filiere_niveau']; // This is the PK of filiere_niveau_detail
                $fk_id_filiere = $_POST['fk_id_filiere'];
                $fk_id_niv_etu = $_POST['fk_id_niv_etu'];
                $montant_scolarite_total = floatval(str_replace(',', '.', $_POST['montant_scolarite_total']));
                $versement_1 = !empty($_POST['versement_1']) ? floatval(str_replace(',', '.', $_POST['versement_1'])) : null;
                $versement_2 = !empty($_POST['versement_2']) ? floatval(str_replace(',', '.', $_POST['versement_2'])) : null;
                $versement_3 = !empty($_POST['versement_3']) ? floatval(str_replace(',', '.', $_POST['versement_3'])) : null;
                $versement_4 = !empty($_POST['versement_4']) ? floatval(str_replace(',', '.', $_POST['versement_4'])) : null;

                // Validation
                if (empty($id_filiere_niveau) || empty($fk_id_filiere) || empty($fk_id_niv_etu) || !is_numeric($montant_scolarite_total) || $montant_scolarite_total <= 0) {
                    throw new Exception("Tous les champs obligatoires (Filière, Niveau, Montant total) doivent être remplis avec des valeurs valides.");
                }

                // Vérifier si la combinaison Filière-Niveau existe déjà pour un autre ID (pour éviter les doublons)
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM filiere_niveau_detail WHERE fk_id_filiere = ? AND fk_id_niv_etu = ? AND id_filiere_niveau != ?");
                $checkStmt->execute([$fk_id_filiere, $fk_id_niv_etu, $id_filiere_niveau]);
                if ($checkStmt->fetchColumn() > 0) {
                    throw new Exception("Les détails de scolarité pour cette combinaison Filière et Niveau existent déjà.");
                }

                $stmt = $pdo->prepare("UPDATE filiere_niveau_detail SET fk_id_filiere = ?, fk_id_niv_etu = ?, montant_scolarite_total = ?, versement_1 = ?, versement_2 = ?, versement_3 = ?, versement_4 = ? WHERE id_filiere_niveau = ?");
                $stmt->execute([$fk_id_filiere, $fk_id_niv_etu, $montant_scolarite_total, $versement_1, $versement_2, $versement_3, $versement_4, $id_filiere_niveau]);

                if ($stmt->rowCount() > 0) {
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Détails de scolarité modifiés avec succès !']);
                } else {
                    throw new Exception("Détails de scolarité non trouvés ou aucune modification effectuée.");
                }
                break;

            case 'delete':
                $idsFiliereNiveau = json_decode($_POST['ids_filiere_niveau'], true);

                foreach ($idsFiliereNiveau as $idFiliereNiveau) {
                    if (empty($idFiliereNiveau)) {
                        throw new Exception("ID de détail de scolarité manquant pour la suppression.");
                    }

                    // Before deleting from filiere_niveau_detail, check if any students are directly linked
                    // This is complex as etudiant directly links to filiere AND niveau_etude.
                    // We need to fetch the filiere_id and niv_etu_id from filiere_niveau_detail first.
                    $getFiliereNiveauIdsStmt = $pdo->prepare("SELECT fk_id_filiere, fk_id_niv_etu FROM filiere_niveau_detail WHERE id_filiere_niveau = ?");
                    $getFiliereNiveauIdsStmt->execute([$idFiliereNiveau]);
                    $filiereNiveauPair = $getFiliereNiveauIdsStmt->fetch(PDO::FETCH_ASSOC);

                    if ($filiereNiveauPair) {
                        $filiereId = $filiereNiveauPair['fk_id_filiere'];
                        $nivEtuId = $filiereNiveauPair['fk_id_niv_etu'];

                        // Check if this specific filiere-niveau combination is used by any student
                        $checkUsageStmt = $pdo->prepare("SELECT COUNT(*) FROM etudiant WHERE fk_id_filiere = ? AND fk_id_niv_etu = ?");
                        $checkUsageStmt->execute([$filiereId, $nivEtuId]);
                        if ($checkUsageStmt->fetchColumn() > 0) {
                            $filiereLibStmt = $pdo->prepare("SELECT lib_filiere FROM filiere WHERE id_filiere = ?");
                            $filiereLibStmt->execute([$filiereId]);
                            $filiereName = $filiereLibStmt->fetchColumn();

                            $niveauLibStmt = $pdo->prepare("SELECT lib_niv_etu FROM niveau_etude WHERE id_niv_etu = ?");
                            $niveauLibStmt->execute([$nivEtuId]);
                            $niveauName = $niveauLibStmt->fetchColumn();

                            throw new Exception("Impossible de supprimer les détails de scolarité pour la filière '{$filiereName}' et le niveau '{$niveauName}'. Ils sont associés à des étudiants. Veuillez d'abord modifier ou supprimer les étudiants associés.");
                        }
                    } else {
                        // If filiere_niveau_detail record not found, it might have been already deleted or invalid ID
                        throw new Exception("Détail de scolarité ID {$idFiliereNiveau} non trouvé.");
                    }

                    // If no students are linked, proceed with deletion
                    $stmt = $pdo->prepare("DELETE FROM filiere_niveau_detail WHERE id_filiere_niveau = ?");
                    $stmt->execute([$idFiliereNiveau]);

                    if ($stmt->rowCount() === 0) {
                        throw new Exception("Les détails de scolarité ID {$idFiliereNiveau} non trouvés ou déjà supprimés.");
                    }
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Détail(s) de scolarité supprimé(s) avec succès !']);
                break;

            case 'get_details': // New action to get details for modification
                $id_filiere_niveau = $_POST['id_filiere_niveau'];
                $query = "SELECT 
                            fnd.id_filiere_niveau,
                            fnd.fk_id_filiere,
                            fnd.fk_id_niv_etu,
                            fnd.montant_scolarite_total,
                            fnd.versement_1,
                            fnd.versement_2,
                            fnd.versement_3,
                            fnd.versement_4,
                            f.lib_filiere,
                            ne.lib_niv_etu
                          FROM filiere_niveau_detail fnd
                          JOIN filiere f ON fnd.fk_id_filiere = f.id_filiere
                          JOIN niveau_etude ne ON fnd.fk_id_niv_etu = ne.id_niv_etu
                          WHERE fnd.id_filiere_niveau = ?";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$id_filiere_niveau]);
                $details = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($details) {
                    echo json_encode(['success' => true, 'data' => $details]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Détails non trouvés.']);
                }
                break;

            default:
                throw new Exception("Action non reconnue.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
    }
    exit;
}

// Récupérer les données existantes pour l'affichage (filiere_niveau_detail)
$filiereNiveauDetails = [];
try {
    $query = "SELECT 
                fnd.id_filiere_niveau,
                f.lib_filiere,
                ne.lib_niv_etu,
                fnd.montant_scolarite_total,
                fnd.versement_1,
                fnd.versement_2,
                fnd.versement_3,
                fnd.versement_4,
                f.id_filiere, -- Added for filtering/sorting
                ne.id_niv_etu  -- Added for filtering/sorting
              FROM filiere_niveau_detail fnd
              JOIN filiere f ON fnd.fk_id_filiere = f.id_filiere
              JOIN niveau_etude ne ON fnd.fk_id_niv_etu = ne.id_niv_etu
              ORDER BY f.lib_filiere ASC, ne.lib_niv_etu ASC";
    $stmt = $pdo->query($query);
    $filiereNiveauDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de récupération des détails filière-niveau: " . $e->getMessage());
}

// Récupérer toutes les filières et niveaux pour les dropdowns du formulaire
$filieres = [];
try {
    $stmt = $pdo->query("SELECT id_filiere, lib_filiere FROM filiere ORDER BY lib_filiere ASC");
    $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de récupération des filières: " . $e->getMessage());
}

$niveauxEtude = [];
try {
    $stmt = $pdo->query("SELECT id_niv_etu, lib_niv_etu FROM niveau_etude ORDER BY lib_niv_etu ASC");
    $niveauxEtude = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de récupération des niveaux d'étude: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Gestion des Niveaux par Filière</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Inclure votre CSS existant ici */
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
        .form-group input[type="text"],
        .form-group input[type="number"], /* Added for number inputs */
        .form-group select {
            padding: var(--space-3);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-base);
            color: var(--gray-800);
            transition: all var(--transition-fast);
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="number"]:focus, /* Added for number inputs */
        .form-group select:focus {
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
            min-width: 900px; /* Ensure table is wide enough for new columns */
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
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 30px;
            min-height: 30px;
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

        /* Loading spinner */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        /* Barre de recherche */
        .search-bar {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-4) var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            margin-bottom: var(--space-6);
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }

        .search-input-container {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: var(--space-3) var(--space-10);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-lg);
            font-size: var(--text-base);
            color: var(--gray-800);
            transition: all var(--transition-fast);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--accent-500);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        .search-icon {
            position: absolute;
            left: var(--space-3);
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }

        .search-button {
            padding: var(--space-3) var(--space-5);
            border-radius: var(--radius-lg);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            background-color: var(--accent-600);
            color: white;
        }

        .search-button:hover {
            background-color: var(--accent-700);
        }

        .download-buttons {
            display: flex;
            gap: var(--space-3);
        }

        .download-button {
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-fast);
            border: 1px solid var(--gray-300);
            background-color: var(--white);
            color: var(--gray-700);
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
        }

        .download-button:hover {
            background-color: var(--gray-100);
            border-color: var(--gray-400);
        }

        /* Filtre dropdown */
        .filter-dropdown {
            position: relative;
            display: inline-block;
        }

        .filter-button {
            padding: var(--space-3);
            border-radius: var(--radius-md);
            background-color: var(--gray-200);
            color: var(--gray-700);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: var(--space-2);
            transition: all var(--transition-fast);
        }

        .filter-button:hover {
            background-color: var(--gray-300);
        }

        .filter-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: var(--white);
            min-width: 200px;
            box-shadow: var(--shadow-md);
            border-radius: var(--radius-md);
            z-index: 100;
            padding: var(--space-2);
            border: 1px solid var(--gray-200);
        }

        .filter-dropdown-content.show {
            display: block;
        }

        .filter-option {
            padding: var(--space-3);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: var(--space-2);
            border-radius: var(--radius-sm);
            transition: background-color var(--transition-fast);
        }

        .filter-option:hover {
            background-color: var(--gray-100);
        }

        /* Modal de message */
        .message-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .message-modal-content {
            background-color: var(--white);
            padding: var(--space-6);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            max-width: 400px;
            width: 90%;
            text-align: center;
            position: relative;
        }

        .message-icon {
            font-size: 2.5rem;
            margin-bottom: var(--space-4);
        }

        .message-icon.success {
            color: var(--success-500);
        }

        .message-icon.error {
            color: var(--error-500);
        }

        .message-icon.warning {
            color: var(--warning-500);
        }

        .message-icon.info {
            color: var(--info-500);
        }

        .message-title {
            font-size: var(--text-xl);
            font-weight: 600;
            margin-bottom: var(--space-2);
        }

        .message-text {
            margin-bottom: var(--space-4);
            color: var(--gray-600);
        }

        .message-close {
            position: absolute;
            top: var(--space-3);
            right: var(--space-3);
            background: none;
            border: none;
            font-size: var(--text-lg);
            cursor: pointer;
            color: var(--gray-500);
        }

        .message-close:hover {
            color: var(--gray-700);
        }

        .message-button {
            padding: var(--space-3) var(--space-6);
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            border: none;
            background-color: var(--accent-600);
            color: white;
            transition: background-color var(--transition-fast);
        }

        .message-button:hover {
            background-color: var(--accent-700);
        }
        /* Mobile menu overlay */
        .mobile-menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }
        /* === RESPONSIVE === */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }
            
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }
            
            .sidebar-title,
            .nav-text,
            .nav-section-title {
                opacity: 0;
                pointer-events: none;
            }
            
            .nav-link {
                justify-content: center;
            }
            
            .sidebar-toggle .fa-bars {
                display: none;
            }
            
            .sidebar-toggle .fa-times {
                display: inline-block;
            }
        }

        @media (max-width: 768px) {
            .admin-layout {
                position: relative;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .sidebar {
                position: fixed;
                left: -100%;
                transition: left var(--transition-normal);
                z-index: 1000;
                height: 100vh;
                overflow-y: auto;
            }
            
            .sidebar.mobile-open {
                left: 0;
            }
            
            .mobile-menu-overlay.active {
                display: block;
            }
            
            .sidebar-toggle .fa-bars {
                display: inline-block;
            }
            
            .sidebar-toggle .fa-times {
                display: none;
            }
            
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
                margin-top: var(--space-4);
            }
            
            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .download-buttons {
                width: 100%;
                justify-content: flex-end;
            }
            
            .btn {
                padding: var(--space-2) var(--space-3);
                font-size: var(--text-sm);
            }

            .filter-dropdown-content {
                left: 0;
                right: auto;
            }
             .data-table {
                min-width: 700px; /* Adjust for smaller screens but still legible */
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: var(--space-4);
            }
            
            .form-card,
            .table-card,
            .search-bar {
                padding: var(--space-4);
            }
            
            .page-title-main {
                font-size: var(--text-2xl);
            }
            
            .page-subtitle {
                font-size: var(--text-base);
            }
            
            .form-actions {
                flex-direction: column;
                gap: var(--space-2);
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .table-actions {
                flex-wrap: wrap;
                gap: var(--space-2);
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            .search-button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_respo_scolarité.php'; // Assurez-vous que le chemin est correct ?>
        <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; // Assurez-vous que le chemin est correct ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Gestion des Niveaux par Filière (Scolarité)</h1>
                    <p class="page-subtitle">Définissez les montants de scolarité et les versements par combinaison Filière et Niveau d'étude.</p>
                </div>

                <div class="form-card">
                    <h3 class="form-card-title"><span id="formTitle">Ajouter de Nouveaux Détails de Scolarité</span></h3>
                    <form id="filiereNiveauForm">
                        <input type="hidden" id="id_filiere_niveau_hidden" name="id_filiere_niveau">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="fk_id_filiere">Filière <span style="color: var(--error-500);">*</span></label>
                                <select id="fk_id_filiere" name="fk_id_filiere" required>
                                    <option value="">Sélectionner une filière</option>
                                    <?php foreach ($filieres as $filiere): ?>
                                        <option value="<?php echo htmlspecialchars($filiere['id_filiere']); ?>"><?php echo htmlspecialchars($filiere['lib_filiere']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="fk_id_niv_etu">Niveau d'Étude <span style="color: var(--error-500);">*</span></label>
                                <select id="fk_id_niv_etu" name="fk_id_niv_etu" required>
                                    <option value="">Sélectionner un niveau</option>
                                    <?php foreach ($niveauxEtude as $niveau): ?>
                                        <option value="<?php echo htmlspecialchars($niveau['id_niv_etu']); ?>"><?php echo htmlspecialchars($niveau['lib_niv_etu']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="montant_scolarite_total">Montant Total de Scolarité (FCFA) <span style="color: var(--error-500);">*</span></label>
                                <input type="number" id="montant_scolarite_total" name="montant_scolarite_total" step="0.01" min="0" placeholder="Ex: 500000" required>
                            </div>
                            <div class="form-group">
                                <label for="versement_1">1er Versement (FCFA)</label>
                                <input type="number" id="versement_1" name="versement_1" step="0.01" min="0" placeholder="Ex: 200000">
                            </div>
                            <div class="form-group">
                                <label for="versement_2">2ème Versement (FCFA)</label>
                                <input type="number" id="versement_2" name="versement_2" step="0.01" min="0" placeholder="Ex: 150000">
                            </div>
                            <div class="form-group">
                                <label for="versement_3">3ème Versement (FCFA)</label>
                                <input type="number" id="versement_3" name="versement_3" step="0.01" min="0" placeholder="Ex: 100000">
                            </div>
                            <div class="form-group">
                                <label for="versement_4">4ème Versement (FCFA)</label>
                                <input type="number" id="versement_4" name="versement_4" step="0.01" min="0" placeholder="Ex: 50000">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-plus-circle"></i> <span id="submitText">Ajouter Détails</span>
                            </button>
                            <button type="reset" class="btn btn-secondary" id="cancelBtn">
                                <i class="fas fa-redo"></i> Annuler
                            </button>
                        </div>
                    </form>
                </div>

                <div class="search-bar">
                    <div class="search-input-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher par filière ou niveau...">
                    </div>
                    <button class="search-button" id="searchButton">
                        <i class="fas fa-search"></i> Rechercher
                    </button>
                    <div class="download-buttons">
                        <button class="download-button" id="exportPdfBtn">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button class="download-button" id="exportExcelBtn">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button class="download-button" id="exportCsvBtn">
                            <i class="fas fa-file-csv"></i> CSV
                        </button>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">Liste des Détails de Scolarité par Filière et Niveau</h3>
                        <div class="table-actions">
                             <div class="filter-dropdown">
                                <button class="filter-button" id="filterButton">
                                    <i class="fas fa-filter"></i> Filtres
                                </button>
                                <div class="filter-dropdown-content" id="filterDropdown">
                                    <div class="filter-option" data-filter="all">
                                        <i class="fas fa-list"></i> Tout
                                    </div>
                                    <div class="filter-option" data-filter="filiere-asc">
                                        <i class="fas fa-sort-alpha-down"></i> Filière (A-Z)
                                    </div>
                                    <div class="filter-option" data-filter="filiere-desc">
                                        <i class="fas fa-sort-alpha-up"></i> Filière (Z-A)
                                    </div>
                                    <div class="filter-option" data-filter="niveau-asc">
                                        <i class="fas fa-sort-alpha-down"></i> Niveau (A-Z)
                                    </div>
                                    <div class="filter-option" data-filter="niveau-desc">
                                        <i class="fas fa-sort-alpha-up"></i> Niveau (Z-A)
                                    </div>
                                    <div class="filter-option" data-filter="montant-asc">
                                        <i class="fas fa-sort-numeric-down"></i> Montant (croissant)
                                    </div>
                                    <div class="filter-option" data-filter="montant-desc">
                                        <i class="fas fa-sort-numeric-up"></i> Montant (décroissant)
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-secondary" id="modifierFiliereNiveauBtn" disabled>
                                <i class="fas fa-edit"></i> <span class="action-text">Modifier</span>
                            </button>
                            <button class="btn btn-secondary" id="supprimerFiliereNiveauBtn" disabled>
                                <i class="fas fa-trash-alt"></i> <span class="action-text">Supprimer</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="filiereNiveauTable">
                            <thead>
                                <tr>
                                    <th style="width: 50px;"></th>
                                    <th>ID</th>
                                    <th>Filière</th>
                                    <th>Niveau</th>
                                    <th>Montant Total (FCFA)</th>
                                    <th>1er Versement</th>
                                    <th>2ème Versement</th>
                                    <th>3ème Versement</th>
                                    <th>4ème Versement</th>
                                    <th style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($filiereNiveauDetails)): ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                        <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                        Aucun détail de scolarité trouvé. Ajoutez-en un ci-dessus.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($filiereNiveauDetails as $detail): ?>
                                    <tr data-id="<?php echo htmlspecialchars($detail['id_filiere_niveau']); ?>">
                                        <td>
                                            <label class="checkbox-container">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($detail['id_filiere_niveau']); ?>">
                                                <span class="checkmark"></span>
                                            </label>
                                        </td>
                                        <td><?php echo htmlspecialchars($detail['id_filiere_niveau']); ?></td>
                                        <td><?php echo htmlspecialchars($detail['lib_filiere']); ?></td>
                                        <td><?php echo htmlspecialchars($detail['lib_niv_etu']); ?></td>
                                        <td><?php echo number_format($detail['montant_scolarite_total'], 2, ',', ' '); ?></td>
                                        <td><?php echo $detail['versement_1'] !== null ? number_format($detail['versement_1'], 2, ',', ' ') : 'N/A'; ?></td>
                                        <td><?php echo $detail['versement_2'] !== null ? number_format($detail['versement_2'], 2, ',', ' ') : 'N/A'; ?></td>
                                        <td><?php echo $detail['versement_3'] !== null ? number_format($detail['versement_3'], 2, ',', ' ') : 'N/A'; ?></td>
                                        <td><?php echo $detail['versement_4'] !== null ? number_format($detail['versement_4'], 2, ',', ' ') : 'N/A'; ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button edit" title="Modifier" onclick="modifierFiliereNiveau('<?php echo htmlspecialchars($detail['id_filiere_niveau']); ?>')">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="action-button delete" title="Supprimer" onclick="supprimerFiliereNiveau('<?php echo htmlspecialchars($detail['id_filiere_niveau']); ?>')">
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

    <div class="message-modal" id="messageModal">
        <div class="message-modal-content">
            <button class="message-close" id="messageClose">&times;</button>
            <div class="message-icon" id="messageIcon"></div>
            <h3 class="message-title" id="messageTitle"></h3>
            <p class="message-text" id="messageText"></p>
            <button class="message-button" id="messageButton">OK</button>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script>
        // Variables globales
        let selectedDetails = new Set(); // Stores id_filiere_niveau
        let editingDetailId = null; // Stores id_filiere_niveau for the currently edited item
        const { jsPDF } = window.jspdf;

        // Éléments DOM
        const filiereNiveauForm = document.getElementById('filiereNiveauForm');
        const idFiliereNiveauHiddenInput = document.getElementById('id_filiere_niveau_hidden');
        const fkIdFiliereSelect = document.getElementById('fk_id_filiere');
        const fkIdNivEtuSelect = document.getElementById('fk_id_niv_etu');
        const montantScolariteTotalInput = document.getElementById('montant_scolarite_total');
        const versement1Input = document.getElementById('versement_1');
        const versement2Input = document.getElementById('versement_2');
        const versement3Input = document.getElementById('versement_3');
        const versement4Input = document.getElementById('versement_4');

        const filiereNiveauTableBody = document.querySelector('#filiereNiveauTable tbody');
        const modifierFiliereNiveauBtn = document.getElementById('modifierFiliereNiveauBtn');
        const supprimerFiliereNiveauBtn = document.getElementById('supprimerFiliereNiveauBtn');
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const cancelBtn = document.getElementById('cancelBtn');
        const formTitle = document.getElementById('formTitle');

        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
        const mainContent = document.getElementById('mainContent');
        const searchInput = document.getElementById('searchInput');
        const searchButton = document.getElementById('searchButton');
        const filterButton = document.getElementById('filterButton');
        const filterDropdown = document.getElementById('filterDropdown');
        const filterOptions = document.querySelectorAll('.filter-option');
        const messageModal = document.getElementById('messageModal');
        const messageTitle = document.getElementById('messageTitle');
        const messageText = document.getElementById('messageText');
        const messageIcon = document.getElementById('messageIcon');
        const messageButton = document.getElementById('messageButton');
        const messageClose = document.getElementById('messageClose');
        const exportPdfBtn = document.getElementById('exportPdfBtn');
        const exportExcelBtn = document.getElementById('exportExcelBtn');
        const exportCsvBtn = document.getElementById('exportCsvBtn');

        // Fonction pour afficher les messages dans une modal
        function showAlert(message, type = 'success', title = null) {
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

            messageIcon.className = 'message-icon';
            switch (type) {
                case 'success':
                    messageIcon.classList.add('success');
                    messageIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
                    break;
                case 'error':
                    messageIcon.classList.add('error');
                    messageIcon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
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

        // Gestion du toggle sidebar pour mobile
        function toggleSidebar() {
            sidebar.classList.toggle('mobile-open');
            mobileMenuOverlay.classList.toggle('active');
            
            const barsIcon = sidebarToggle.querySelector('.fa-bars');
            const timesIcon = sidebarToggle.querySelector('.fa-times');
            
            if (sidebar.classList.contains('mobile-open')) {
                barsIcon.style.display = 'none';
                timesIcon.style.display = 'inline-block';
            } else {
                barsIcon.style.display = 'inline-block';
                timesIcon.style.display = 'none';
            }
        }

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }

        if (mobileMenuOverlay) {
            mobileMenuOverlay.addEventListener('click', toggleSidebar);
        }

        // Fonction pour faire une requête AJAX
        async function makeAjaxRequest(data) {
            try {
                const formData = new FormData();
                for (const key in data) {
                    formData.append(key, data[key]);
                }

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                return await response.json();
            } catch (error) {
                console.error('Erreur AJAX:', error);
                throw error;
            }
        }

        // Fonction pour mettre à jour l'état des boutons
        function updateActionButtons() {
            if (selectedDetails.size === 1) {
                modifierFiliereNiveauBtn.disabled = false;
                supprimerFiliereNiveauBtn.disabled = false;
            } else if (selectedDetails.size > 1) {
                modifierFiliereNiveauBtn.disabled = true; // Can't modify multiple at once
                supprimerFiliereNiveauBtn.disabled = false;
            } else {
                modifierFiliereNiveauBtn.disabled = true;
                supprimerFiliereNiveauBtn.disabled = true;
            }
        }

        // Fonction pour formater les montants
        function formatMontant(value) {
            if (value === null || value === 'N/A' || isNaN(parseFloat(value))) {
                return 'N/A';
            }
            return parseFloat(value).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }


        // Fonction pour ajouter/mettre à jour une ligne dans le tableau
        function updateOrCreateRow(detail) {
            const existingRow = document.querySelector(`tr[data-id="${detail.id_filiere_niveau}"]`);
            if (existingRow) {
                // Update existing row
                existingRow.cells[2].textContent = detail.lib_filiere;
                existingRow.cells[3].textContent = detail.lib_niv_etu;
                existingRow.cells[4].textContent = formatMontant(detail.montant_scolarite_total);
                existingRow.cells[5].textContent = formatMontant(detail.versement_1);
                existingRow.cells[6].textContent = formatMontant(detail.versement_2);
                existingRow.cells[7].textContent = formatMontant(detail.versement_3);
                existingRow.cells[8].textContent = formatMontant(detail.versement_4);
            } else {
                // Remove the "no data" message if it exists
                const emptyRow = filiereNiveauTableBody.querySelector('td[colspan="10"]');
                if (emptyRow) {
                    emptyRow.closest('tr').remove();
                }

                // Add new row
                const newRow = filiereNiveauTableBody.insertRow();
                newRow.setAttribute('data-id', detail.id_filiere_niveau);
                newRow.innerHTML = `
                    <td>
                        <label class="checkbox-container">
                            <input type="checkbox" value="${detail.id_filiere_niveau}">
                            <span class="checkmark"></span>
                        </label>
                    </td>
                    <td>${detail.id_filiere_niveau}</td>
                    <td>${detail.lib_filiere}</td>
                    <td>${detail.lib_niv_etu}</td>
                    <td>${formatMontant(detail.montant_scolarite_total)}</td>
                    <td>${formatMontant(detail.versement_1)}</td>
                    <td>${formatMontant(detail.versement_2)}</td>
                    <td>${formatMontant(detail.versement_3)}</td>
                    <td>${formatMontant(detail.versement_4)}</td>
                    <td>
                        <div class="action-buttons">
                            <button class="action-button edit" title="Modifier" onclick="modifierFiliereNiveau('${detail.id_filiere_niveau}')">
                                <i class="fas fa-pencil-alt"></i>
                            </button>
                            <button class="action-button delete" title="Supprimer" onclick="supprimerFiliereNiveau('${detail.id_filiere_niveau}')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                `;
                attachEventListenersToRow(newRow);
            }
            // Re-apply current sorting after adding/updating row to maintain order
            const currentFilter = document.querySelector('.filter-option.active')?.getAttribute('data-filter') || 'all';
            applyFilter(currentFilter);
        }

        // Fonction pour attacher les événements aux lignes (checkbox)
        function attachEventListenersToRow(row) {
            const checkbox = row.querySelector('input[type="checkbox"]');
            
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    selectedDetails.add(this.value);
                } else {
                    selectedDetails.delete(this.value);
                }
                updateActionButtons();
            });
        }

        // Fonction de recherche
        function searchTable() {
            const searchTerm = searchInput.value.toLowerCase();
            const rows = filiereNiveauTableBody.querySelectorAll('tr');
            
            let foundRows = 0;
            rows.forEach(row => {
                if (row.querySelector('td[colspan="10"]')) { // This is the "no data" row
                    row.style.display = 'none'; // Hide it during search
                    return;
                }
                
                const filiereText = row.cells[2].textContent.toLowerCase();
                const niveauText = row.cells[3].textContent.toLowerCase();
                
                if (filiereText.includes(searchTerm) || niveauText.includes(searchTerm)) {
                    row.style.display = '';
                    foundRows++;
                } else {
                    row.style.display = 'none';
                }
            });

            const noDataRow = filiereNiveauTableBody.querySelector('td[colspan="10"]');
            if (foundRows === 0) {
                if (!noDataRow) {
                     filiereNiveauTableBody.innerHTML = `
                        <tr>
                            <td colspan="10" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                <i class="fas fa-search" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                Aucun détail de scolarité trouvé pour cette recherche.
                            </td>
                        </tr>
                    `;
                } else {
                    noDataRow.closest('tr').style.display = '';
                    noDataRow.innerHTML = `
                        <i class="fas fa-search" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                        Aucun détail de scolarité trouvé pour cette recherche.
                    `;
                }
            } else {
                if (noDataRow) {
                    noDataRow.closest('tr').remove();
                }
            }
        }

        // Fonction pour appliquer les filtres
        function applyFilter(filterType) {
            const rows = Array.from(filiereNiveauTableBody.querySelectorAll('tr'));
            
            const emptyRow = filiereNiveauTableBody.querySelector('td[colspan="10"]');
            if (emptyRow) {
                emptyRow.closest('tr').remove();
            }
            
            rows.forEach(row => {
                if (!row.querySelector('td[colspan="10"]')) {
                    row.style.display = '';
                }
            });
            
            rows.sort((a, b) => {
                if (a.querySelector('td[colspan="10"]') || b.querySelector('td[colspan="10"]')) return 0;
                
                const filiereA = a.cells[2].textContent.toLowerCase();
                const filiereB = b.cells[2].textContent.toLowerCase();
                const niveauA = a.cells[3].textContent.toLowerCase();
                const niveauB = b.cells[3].textContent.toLowerCase();
                const montantA = parseFloat(a.cells[4].textContent.replace(/\s/g, '').replace(',', '.'));
                const montantB = parseFloat(b.cells[4].textContent.replace(/\s/g, '').replace(',', '.'));
                
                switch (filterType) {
                    case 'filiere-asc': return filiereA.localeCompare(filiereB);
                    case 'filiere-desc': return filiereB.localeCompare(filiereA);
                    case 'niveau-asc': return niveauA.localeCompare(niveauB);
                    case 'niveau-desc': return niveauB.localeCompare(niveauA);
                    case 'montant-asc': return montantA - montantB;
                    case 'montant-desc': return montantB - montantA;
                    default: return 0; // 'all' or fallback
                }
            });
            
            rows.forEach(row => {
                filiereNiveauTableBody.appendChild(row);
            });
            
            if (filiereNiveauTableBody.children.length === 0 || (filiereNiveauTableBody.children.length === 1 && filiereNiveauTableBody.querySelector('td[colspan="10"]'))) {
                filiereNiveauTableBody.innerHTML = `
                    <tr>
                        <td colspan="10" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                            <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                            Aucun détail de scolarité trouvé. Ajoutez-en un ci-dessus.
                        </td>
                    </tr>
                `;
            }

            // Update active filter button style
            document.querySelectorAll('.filter-option').forEach(option => option.classList.remove('active'));
            document.querySelector(`.filter-option[data-filter="${filterType}"]`).classList.add('active');
        }


        // Soumission du formulaire d'ajout/modification
        filiereNiveauForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = {
                action: editingDetailId ? 'update' : 'create',
                fk_id_filiere: formData.get('fk_id_filiere'),
                fk_id_niv_etu: formData.get('fk_id_niv_etu'),
                montant_scolarite_total: formData.get('montant_scolarite_total'),
                versement_1: formData.get('versement_1'),
                versement_2: formData.get('versement_2'),
                versement_3: formData.get('versement_3'),
                versement_4: formData.get('versement_4')
            };

            if (editingDetailId) {
                data.id_filiere_niveau = editingDetailId;
            }

            submitBtn.classList.add('loading');
            submitBtn.disabled = true;

            try {
                const result = await makeAjaxRequest(data);

                if (result.success) {
                    showAlert(result.message, 'success');
                    if (editingDetailId) {
                        // For update, just need to re-render the row with fresh data or update manually
                        // Simplest is to fetch fresh data for the row if complex
                        // For this case, we can update directly as fields match inputs
                        const row = document.querySelector(`tr[data-id="${editingDetailId}"]`);
                        if (row) {
                            row.cells[2].textContent = fkIdFiliereSelect.options[fkIdFiliereSelect.selectedIndex].text;
                            row.cells[3].textContent = fkIdNivEtuSelect.options[fkIdNivEtuSelect.selectedIndex].text;
                            row.cells[4].textContent = formatMontant(montantScolariteTotalInput.value);
                            row.cells[5].textContent = formatMontant(versement1Input.value);
                            row.cells[6].textContent = formatMontant(versement2Input.value);
                            row.cells[7].textContent = formatMontant(versement3Input.value);
                            row.cells[8].textContent = formatMontant(versement4Input.value);
                        }
                        resetForm();
                    } else {
                        // For create, result.data contains the new row details including its ID
                        updateOrCreateRow(result.data);
                        filiereNiveauForm.reset(); // Reset form for next entry
                    }
                    // Re-apply filter/sort to ensure new/updated row is in correct place
                    const currentFilter = document.querySelector('.filter-option.active')?.getAttribute('data-filter') || 'all';
                    applyFilter(currentFilter);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'enregistrement des détails de scolarité.', 'error');
            } finally {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }
        });

        // Fonction pour réinitialiser le formulaire
        function resetForm() {
            editingDetailId = null;
            idFiliereNiveauHiddenInput.value = '';
            formTitle.textContent = 'Ajouter de Nouveaux Détails de Scolarité';
            submitText.textContent = 'Ajouter Détails';
            submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Ajouter Détails';
            filiereNiveauForm.reset();
            selectedDetails.clear();
            updateActionButtons();
            // Deselect all checkboxes manually as form.reset() doesn't affect them
            document.querySelectorAll('#filiereNiveauTable tbody input[type="checkbox"]').forEach(cb => cb.checked = false);
        }

        // Bouton Annuler/Reset
        cancelBtn.addEventListener('click', function() {
            resetForm();
            searchInput.value = '';
            searchTable();
            applyFilter('all');
        });

        // Fonction pour modifier un détail de scolarité
        async function modifierFiliereNiveau(id) {
            try {
                const result = await makeAjaxRequest({ action: 'get_details', id_filiere_niveau: id });
                if (result.success && result.data) {
                    const detail = result.data;
                    editingDetailId = detail.id_filiere_niveau;
                    idFiliereNiveauHiddenInput.value = detail.id_filiere_niveau;
                    fkIdFiliereSelect.value = detail.fk_id_filiere;
                    fkIdNivEtuSelect.value = detail.fk_id_niv_etu;
                    montantScolariteTotalInput.value = detail.montant_scolarite_total;
                    versement1Input.value = detail.versement_1;
                    versement2Input.value = detail.versement_2;
                    versement3Input.value = detail.versement_3;
                    versement4Input.value = detail.versement_4;

                    formTitle.textContent = `Modifier les Détails pour ${detail.lib_filiere} - ${detail.lib_niv_etu}`;
                    submitText.textContent = 'Mettre à jour';
                    submitBtn.innerHTML = '<i class="fas fa-edit"></i> Mettre à jour';
                    
                    document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
                    
                    // Uncheck all other checkboxes and select only this one
                    document.querySelectorAll('#filiereNiveauTable tbody input[type="checkbox"]').forEach(checkbox => {
                        checkbox.checked = false;
                    });
                    const currentCheckbox = document.querySelector(`tr[data-id="${id}"] input[type="checkbox"]`);
                    if(currentCheckbox) {
                        currentCheckbox.checked = true;
                    }
                    selectedDetails.clear();
                    selectedDetails.add(id);
                    updateActionButtons();
                } else {
                    showAlert(result.message || 'Erreur lors du chargement des détails pour modification.', 'error');
                }
            } catch (error) {
                showAlert('Erreur réseau lors du chargement des détails pour modification.', 'error');
            }
        }

        // Bouton de suppression de la sélection multiple ou d'un seul élément
        supprimerFiliereNiveauBtn.addEventListener('click', async function() {
            if (selectedDetails.size === 0) {
                showAlert('Aucun détail de scolarité sélectionné pour la suppression.', 'warning');
                return;
            }

            const idsArray = Array.from(selectedDetails);
            const confirmationMessage = idsArray.length === 1
                ? `Êtes-vous sûr de vouloir supprimer ce détail de scolarité (ID: ${idsArray[0]}) ?\n\nCette action est irréversible et pourrait échouer si des étudiants sont associés à cette combinaison Filière/Niveau.`
                : `Êtes-vous sûr de vouloir supprimer les ${idsArray.length} détail(s) de scolarité sélectionné(s) ?\n\nCette action est irréversible et pourrait échouer si des étudiants sont associés à ces combinaisons Filière/Niveau.`;

            if (confirm(confirmationMessage)) {
                let successCount = 0;
                let errorMessages = [];

                for (const idDetail of idsArray) {
                    try {
                        const result = await makeAjaxRequest({
                            action: 'delete',
                            ids_filiere_niveau: JSON.stringify([idDetail]) // Pass as array
                        });
                        if (result.success) {
                            successCount++;
                            document.querySelector(`tr[data-id="${idDetail}"]`).remove();
                        } else {
                            errorMessages.push(`Détail ID ${idDetail}: ${result.message}`);
                        }
                    } catch (error) {
                        errorMessages.push(`Détail ID ${idDetail}: Erreur réseau ou serveur.`);
                    }
                }

                selectedDetails.clear(); // Efface la sélection après le traitement
                updateActionButtons();
                resetForm(); // Reset form in case a deleted item was being edited

                if (successCount > 0) {
                    showAlert(`${successCount} détail(s) de scolarité supprimé(s) avec succès !`, 'success');
                }
                if (errorMessages.length > 0) {
                    showAlert(`Erreurs lors de la suppression de certains détails:\n${errorMessages.join('\n')}`, 'error');
                }
                
                // If no more data, display the empty message
                if (filiereNiveauTableBody.children.length === 0) {
                    filiereNiveauTableBody.innerHTML = `
                        <tr>
                            <td colspan="10" style="text-align: center; color: var(--gray-500); padding: var(--space-8);">
                                <i class="fas fa-graduation-cap" style="font-size: 2rem; margin-bottom: var(--space-2);"></i><br>
                                Aucun détail de scolarité trouvé. Ajoutez-en un ci-dessus.
                            </td>
                        </tr>
                    `;
                }
            }
        });

        // Bouton Modifier global
        modifierFiliereNiveauBtn.addEventListener('click', function() {
            if (selectedDetails.size === 1) {
                const idDetail = Array.from(selectedDetails)[0];
                modifierFiliereNiveau(idDetail);
            } else {
                showAlert("Veuillez sélectionner exactement un détail de scolarité à modifier.", "warning");
            }
        });

        // Fonction pour exporter en PDF
        function exportToPdf() {
            const doc = new jsPDF('landscape'); // Use landscape for wider tables
            const title = "Liste des Détails de Scolarité par Filière et Niveau";
            const date = new Date().toLocaleDateString('fr-FR');
            
            doc.setFontSize(18);
            doc.text(title, doc.internal.pageSize.getWidth() / 2, 20, { align: 'center' });
            doc.setFontSize(10);
            doc.text(`Exporté le: ${date}`, doc.internal.pageSize.getWidth() / 2, 30, { align: 'center' });
            
            const headers = [['ID', 'Filière', 'Niveau', 'Montant Total (FCFA)', '1er Versement', '2ème Versement', '3ème Versement', '4ème Versement']];
            const data = [];
            
            document.querySelectorAll('#filiereNiveauTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="10"]')) { // Exclude "no data" row
                    data.push([
                        row.cells[1].textContent, // ID
                        row.cells[2].textContent, // Filière
                        row.cells[3].textContent, // Niveau
                        row.cells[4].textContent, // Montant Total
                        row.cells[5].textContent, // 1er Versement
                        row.cells[6].textContent, // 2ème Versement
                        row.cells[7].textContent, // 3ème Versement
                        row.cells[8].textContent  // 4ème Versement
                    ]);
                }
            });
            
            doc.autoTable({
                head: headers,
                body: data,
                startY: 40,
                styles: {
                    fontSize: 8, // Smaller font for landscape
                    cellPadding: 2,
                    valign: 'middle'
                },
                headStyles: {
                    fillColor: [59, 130, 246],
                    textColor: 255,
                    fontStyle: 'bold'
                },
                alternateRowStyles: {
                    fillColor: [241, 245, 249]
                },
                margin: { left: 10, right: 10 }
            });
            
            doc.save(`details_scolarite_${new Date().toISOString().split('T')[0]}.pdf`);
            showAlert('Exportation PDF terminée', 'success');
        }

        // Fonction pour exporter en Excel
        function exportToExcel() {
            const data = [['ID', 'Filière', 'Niveau', 'Montant Total (FCFA)', '1er Versement', '2ème Versement', '3ème Versement', '4ème Versement']];
            
            document.querySelectorAll('#filiereNiveauTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="10"]')) { // Exclude "no data" row
                    data.push([
                        row.cells[1].textContent,
                        row.cells[2].textContent,
                        row.cells[3].textContent,
                        row.cells[4].textContent.replace(/\s/g, ''), // Remove spaces for excel if present
                        row.cells[5].textContent.replace(/\s/g, ''),
                        row.cells[6].textContent.replace(/\s/g, ''),
                        row.cells[7].textContent.replace(/\s/g, ''),
                        row.cells[8].textContent.replace(/\s/g, '')
                    ]);
                }
            });

            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Details Scolarite");
            
            XLSX.writeFile(wb, `details_scolarite_${new Date().toISOString().split('T')[0]}.xlsx`);
            
            showAlert('Exportation Excel terminée', 'success');
        }

        // Fonction pour exporter en CSV
        function exportToCsv() {
            let csv = "ID,Filière,Niveau,Montant Total (FCFA),1er Versement,2ème Versement,3ème Versement,4ème Versement\n";
            
            document.querySelectorAll('#filiereNiveauTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="10"]')) { // Exclude "no data" row
                    csv += `"${row.cells[1].textContent}","${row.cells[2].textContent}","${row.cells[3].textContent}","${row.cells[4].textContent}","${row.cells[5].textContent}","${row.cells[6].textContent}","${row.cells[7].textContent}","${row.cells[8].textContent}"\n`;
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', `details_scolarite_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showAlert('Exportation CSV terminée', 'success');
        }

        // Boutons d'export individuels
        exportPdfBtn.addEventListener('click', exportToPdf);
        exportExcelBtn.addEventListener('click', exportToExcel);
        exportCsvBtn.addEventListener('click', exportToCsv);

        // Recherche
        searchButton.addEventListener('click', searchTable);
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                searchTable();
            }
        });

        // Filtres
        filterButton.addEventListener('click', function() {
            filterDropdown.classList.toggle('show');
        });

        filterOptions.forEach(option => {
            option.addEventListener('click', function() {
                const filterType = this.getAttribute('data-filter');
                applyFilter(filterType);
                filterDropdown.classList.remove('show');
            });
        });

        // Close dropdown if clicked outside
        window.addEventListener('click', function(e) {
            if (!e.target.matches('.filter-button') && !e.target.closest('.filter-dropdown')) {
                filterDropdown.classList.remove('show');
            }
        });

        // Initialisation: attacher les événements aux lignes existantes au chargement
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#filiereNiveauTable tbody tr').forEach(row => {
                if (!row.querySelector('td[colspan="10"]')) { // Éviter le message "Aucune donnée"
                    attachEventListenersToRow(row);
                }
            });
            updateActionButtons();
            handleResponsiveActions();
        });

        // Responsive: Gestion mobile (réutilisée)
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
            handleResponsiveActions();
        }
        window.addEventListener('resize', handleResize);
        handleResize();

        function handleResponsiveActions() {
            const actionTexts = document.querySelectorAll('.action-text');
            if (window.innerWidth < 768) {
                actionTexts.forEach(text => {
                    text.style.display = 'none';
                });
            } else {
                actionTexts.forEach(text => {
                    text.style.display = 'inline';
                });
            }
        }
    </script>
</body>
</html>