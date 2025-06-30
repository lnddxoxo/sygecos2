<?php
// reglement_scolarite.php
require_once 'config.php'; // Connexion PDO et fonctions de sécurité

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Récupération des années académiques
$anneesAcademiques = [];
try {
    $stmt = $pdo->query("SELECT id_Ac, CONCAT(YEAR(date_deb), '-', YEAR(date_fin)) as annee_libelle FROM année_academique ORDER BY date_deb DESC");
    $anneesAcademiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération années académiques: " . $e->getMessage());
}

// Récupération de toutes les filières pour le filtre
$filieres = [];
try {
    $stmt = $pdo->query("SELECT id_filiere, lib_filiere FROM filiere ORDER BY lib_filiere ASC");
    $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération filières: " . $e->getMessage());
}

// Récupération des niveaux d'étude (initialement tous, seront filtrés par JS)
$niveauxEtude = [];
try {
    $stmt = $pdo->query("SELECT id_niv_etu, lib_niv_etu, fk_id_filiere FROM niveau_etude ORDER BY lib_niv_etu");
    $niveauxEtude = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération niveaux: " . $e->getMessage());
}

// Convertir les niveaux d'étude en JSON pour un accès facile en JS
$niveauxEtudeJson = json_encode($niveauxEtude);


// === TRAITEMENT AJAX ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'rechercher_etudiant':
                $searchTerm = trim($_POST['search_term'] ?? '');
                $anneeId = $_POST['annee_id'] ?? '';
                $filiereId = $_POST['filiere_id'] ?? '';
                $niveauId = $_POST['niveau_id'] ?? '';
                
                $whereConditions = [];
                $params = [];
                
                // Condition de recherche principale
                if (!empty($searchTerm)) {
                    $whereConditions[] = "(v.num_etu LIKE ? OR v.nom_etu LIKE ? OR v.prenoms_etu LIKE ? OR CONCAT(v.nom_etu, ' ', v.prenoms_etu) LIKE ?)";
                    $searchParam = "%{$searchTerm}%";
                    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
                }
                
                // Filtre par année académique
                if (!empty($anneeId)) {
                    $whereConditions[] = "v.id_Ac = ?";
                    $params[] = $anneeId;
                }
                
                // Filtre par filière
                if (!empty($filiereId)) {
                    $whereConditions[] = "v.fk_id_filiere = ?";
                    $params[] = $filiereId;
                }
                
                // Filtre par niveau
                if (!empty($niveauId)) {
                    $whereConditions[] = "v.id_niv_etu = ?";
                    $params[] = $niveauId;
                }
                
                $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
                
                // Vérifier d'abord si la vue existe
                $checkView = $pdo->query("SHOW TABLES LIKE 'vue_paiements_etudiants'");
                if ($checkView->rowCount() == 0) {
                    throw new Exception("La vue 'vue_paiements_etudiants' n'existe pas. Veuillez d'abord exécuter le script de correction de la base de données.");
                }
                
                $sql = "SELECT v.*, 
                               fnd.montant_scolarite_total AS total_a_payer_prevu,
                               fnd.versement_1 AS versement_1_prevu, 
                               fnd.versement_2 AS versement_2_prevu, 
                               fnd.versement_3 AS versement_3_prevu, 
                               fnd.versement_4 AS versement_4_prevu
                        FROM vue_paiements_etudiants v
                        INNER JOIN inscrire i ON v.num_etu = i.fk_num_etu AND v.id_Ac = i.fk_id_Ac
                        LEFT JOIN filiere_niveau_detail fnd ON v.fk_id_filiere = fnd.fk_id_filiere AND v.id_niv_etu = fnd.fk_id_niv_etu
                        {$whereClause} 
                        ORDER BY nom_etu, prenoms_etu";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $resultats]);
                break;
                
            case 'creer_paiement':
                $numEtu = trim($_POST['num_etu'] ?? '');
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $montantTotalPrevu = floatval($_POST['montant_total_prevu'] ?? 0); // This is the expected total
                
                // New fields for initial payment
                $montantVersementInitial = floatval($_POST['montant_versement_initial'] ?? 0);
                $dateVersementInitial = $_POST['date_versement_initial'] ?? null;
                $modePaiementInitial = $_POST['mode_paiement_initial'] ?? 'especes';
                $referencePaiementInitial = trim($_POST['reference_paiement_initial'] ?? '');
                $commentaireInitial = trim($_POST['commentaire_initial'] ?? '');

                if (empty($numEtu) || $anneeId <= 0 || $montantTotalPrevu <= 0) {
                    throw new Exception("Données manquantes pour créer le paiement initial ou montant total invalide.");
                }

                // Validate initial payment data if provided
                if ($montantVersementInitial > 0 && empty($dateVersementInitial)) {
                    throw new Exception("Veuillez spécifier la date du premier versement si un montant est versé.");
                }
                if ($montantVersementInitial > 0 && !DateTime::createFromFormat('Y-m-d', $dateVersementInitial)) {
                    throw new Exception("Format de date de versement initial invalide.");
                }
                
                $pdo->beginTransaction(); // Start transaction
                
                // Vérifier si l'étudiant et l'année existent et s'il est inscrit
                $checkEtuStmt = $pdo->prepare("
                    SELECT e.num_etu, e.nom_etu, e.prenoms_etu, i.fk_id_filiere, i.fk_id_niv_etu
                    FROM etudiant e 
                    INNER JOIN inscrire i ON e.num_etu = i.fk_num_etu 
                    WHERE e.num_etu = ? AND i.fk_id_Ac = ?
                ");
                $checkEtuStmt->execute([$numEtu, $anneeId]);
                $etudiant = $checkEtuStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$etudiant) {
                    throw new Exception("Étudiant non trouvé ou non inscrit pour cette année académique");
                }

                // Vérifier si un paiement existe déjà
                $checkStmt = $pdo->prepare("SELECT id_paiement FROM paiement_scolarite WHERE fk_num_etu = ? AND fk_id_Ac = ?");
                $checkStmt->execute([$numEtu, $anneeId]);
                
                if ($checkStmt->fetch()) {
                    throw new Exception("Un paiement existe déjà pour cet étudiant cette année");
                }
                
                // Créer le paiement (montant_total = montantTotalPrevu, total_verse = montantVersementInitial)
                $stmt = $pdo->prepare("INSERT INTO paiement_scolarite (fk_num_etu, fk_id_Ac, montant_total, total_verse) VALUES (?, ?, ?, ?)");
                $stmt->execute([$numEtu, $anneeId, $montantTotalPrevu, $montantVersementInitial]);
                $idPaiement = $pdo->lastInsertId(); // Get the ID of the newly created payment

                // If an initial payment amount is provided, add it as the first installment
                if ($montantVersementInitial > 0) {
                    $insertVersementStmt = $pdo->prepare("
                        INSERT INTO versement_scolarite 
                        (fk_id_paiement, numero_versement, montant_versement, date_versement, mode_paiement, reference_paiement, commentaire)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insertVersementStmt->execute([
                        $idPaiement, 
                        1, // This is the first installment
                        $montantVersementInitial, 
                        $dateVersementInitial, 
                        $modePaiementInitial, 
                        $referencePaiementInitial, 
                        $commentaireInitial
                    ]);
                }
                
                $pdo->commit(); // Commit transaction
                echo json_encode([
                    'success' => true, 
                    'message' => "Paiement créé avec succès pour {$etudiant['prenoms_etu']} {$etudiant['nom_etu']}"
                ]);
                break;
                
            case 'ajouter_versement':
                $idPaiement = intval($_POST['id_paiement'] ?? 0);
                $numeroVersement = intval($_POST['numero_versement'] ?? 0);
                $montantVerseActuel = floatval($_POST['montant_verse_actuel'] ?? 0);
                $dateVersement = $_POST['date_versement'] ?? '';
                $modePaiement = $_POST['mode_paiement'] ?? 'especes';
                $reference = trim($_POST['reference_paiement'] ?? '');
                $commentaire = trim($_POST['commentaire'] ?? '');
                
                if ($idPaiement <= 0 || $numeroVersement < 1 || $numeroVersement > 4 || $montantVerseActuel <= 0) {
                    throw new Exception("Données invalides pour le versement");
                }
                
                // Valider la date
                if (!DateTime::createFromFormat('Y-m-d', $dateVersement)) {
                    throw new Exception("Format de date invalide");
                }
                
                $pdo->beginTransaction(); // Start transaction
                
                // Récupérer les détails de paiement et les montants prévus
                $stmtPaiement = $pdo->prepare("
                    SELECT ps.montant_total, i.fk_id_filiere, i.fk_id_niv_etu, ps.fk_num_etu, ps.fk_id_Ac
                    FROM paiement_scolarite ps
                    INNER JOIN inscrire i ON ps.fk_num_etu = i.fk_num_etu AND ps.fk_id_Ac = i.fk_id_Ac
                    WHERE ps.id_paiement = ?
                ");
                $stmtPaiement->execute([$idPaiement]);
                $paiementDetails = $stmtPaiement->fetch(PDO::FETCH_ASSOC);

                if (!$paiementDetails) {
                    throw new Exception("Paiement introuvable.");
                }

                $fk_id_filiere = $paiementDetails['fk_id_filiere'];
                $fk_id_niv_etu = $paiementDetails['fk_id_niv_etu'];
                // $currentNumEtu = $paiementDetails['fk_num_etu']; // Not directly used in this logic block
                // $currentAnneeId = $paiementDetails['fk_id_Ac']; // Not directly used in this logic block
                // $totalScolariteDefaut = $paiementDetails['montant_total']; // Not directly used in this logic block

                // Get planned installment amounts for this filiere/niveau
                $stmtFND = $pdo->prepare("SELECT versement_1, versement_2, versement_3, versement_4 FROM filiere_niveau_detail WHERE fk_id_filiere = ? AND fk_id_niv_etu = ?");
                $stmtFND->execute([$fk_id_filiere, $fk_id_niv_etu]);
                $fndDetails = $stmtFND->fetch(PDO::FETCH_ASSOC);

                $versementsPrevus = [];
                for ($i = 1; $i <= 4; $i++) {
                    $versementsPrevus[$i] = floatval($fndDetails["versement_{$i}"] ?? 0);
                }

                // Get current actual payments
                $currentVersements = [];
                $stmtCurrentVers = $pdo->prepare("SELECT numero_versement, montant_versement FROM versement_scolarite WHERE fk_id_paiement = ?");
                $stmtCurrentVers->execute([$idPaiement]);
                foreach ($stmtCurrentVers->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $currentVersements[$row['numero_versement']] = floatval($row['montant_versement']);
                }

                // Apply the new payment and carry over excess
                $remainingAmountToPay = $montantVerseActuel;
                $message = "";

                for ($i = $numeroVersement; $i <= 4 && $remainingAmountToPay > 0; $i++) {
                    $montantPrevuPourCeVersement = $versementsPrevus[$i];
                    $montantDejaPayePourCeVersement = $currentVersements[$i] ?? 0;
                    
                    // If no planned amount, or already fully paid, skip this installment for new application
                    if ($montantPrevuPourCeVersement <= 0 || $montantDejaPayePourCeVersement >= $montantPrevuPourCeVersement) {
                        continue; 
                    }

                    $amountNeededForThisInstallment = $montantPrevuPourCeVersement - $montantDejaPayePourCeVersement;

                    if ($amountNeededForThisInstallment > 0) {
                        $amountToApply = min($remainingAmountToPay, $amountNeededForThisInstallment);
                        $newMontantVersement = $montantDejaPayePourCeVersement + $amountToApply;

                        // Check if versement already exists for this installment
                        $checkVersementStmt = $pdo->prepare("SELECT id_versement FROM versement_scolarite WHERE fk_id_paiement = ? AND numero_versement = ?");
                        $checkVersementStmt->execute([$idPaiement, $i]);
                        
                        if ($checkVersementStmt->fetch()) {
                            // Update existing versement
                            $updateStmt = $pdo->prepare("
                                UPDATE versement_scolarite 
                                SET montant_versement = ?, date_versement = ?, mode_paiement = ?, 
                                    reference_paiement = ?, commentaire = ?
                                WHERE fk_id_paiement = ? AND numero_versement = ?
                            ");
                            $updateStmt->execute([$newMontantVersement, $dateVersement, $modePaiement, $reference, $commentaire, $idPaiement, $i]);
                            if ($i == $numeroVersement) {
                                $message = "Versement {$numeroVersement} mis à jour. ";
                            } else {
                                $message .= "Excédent appliqué au versement {$i}. ";
                            }
                        } else {
                            // Insert new versement
                            $insertStmt = $pdo->prepare("
                                INSERT INTO versement_scolarite 
                                (fk_id_paiement, numero_versement, montant_versement, date_versement, mode_paiement, reference_paiement, commentaire)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $insertStmt->execute([$idPaiement, $i, $newMontantVersement, $dateVersement, $modePaiement, $reference, $commentaire]);
                            if ($i == $numeroVersement) {
                                $message = "Versement {$numeroVersement} créé. ";
                            } else {
                                $message .= "Excédent créé comme versement {$i}. ";
                            }
                        }
                        $remainingAmountToPay -= $amountToApply;
                    }
                }
                
                // Update total_verse in paiement_scolarite
                $stmtUpdateTotalVerse = $pdo->prepare("UPDATE paiement_scolarite SET total_verse = (SELECT SUM(montant_versement) FROM versement_scolarite WHERE fk_id_paiement = ?) WHERE id_paiement = ?");
                $stmtUpdateTotalVerse->execute([$idPaiement, $idPaiement]);

                $pdo->commit(); // Commit transaction
                echo json_encode(['success' => true, 'message' => $message]);
                break;
                
            case 'obtenir_details_paiement':
                $numEtu = trim($_POST['num_etu'] ?? '');
                $anneeId = intval($_POST['annee_id'] ?? 0);
                
                if (empty($numEtu) || $anneeId <= 0) {
                    throw new Exception("Paramètres manquants");
                }
                
                // Jointure avec filiere_niveau_detail pour obtenir le montant total de scolarité théorique
                $stmt = $pdo->prepare("
                    SELECT 
                        v.*, 
                        fnd.montant_scolarite_total AS total_a_payer_prevu,
                        fnd.versement_1 AS versement_1_prevu, 
                        fnd.versement_2 AS versement_2_prevu, 
                        fnd.versement_3 AS versement_3_prevu, 
                        fnd.versement_4 AS versement_4_prevu
                    FROM vue_paiements_etudiants v
                    INNER JOIN inscrire i ON v.num_etu = i.fk_num_etu AND v.id_Ac = i.fk_id_Ac
                    LEFT JOIN filiere_niveau_detail fnd ON v.fk_id_filiere = fnd.fk_id_filiere AND v.id_niv_etu = fnd.fk_id_niv_etu
                    WHERE v.num_etu = ? AND v.id_Ac = ?
                ");
                $stmt->execute([$numEtu, $anneeId]);
                $details = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$details) {
                    throw new Exception("Aucune donnée de paiement trouvée pour cet étudiant à cette année académique.");
                }

                // If no payment record exists, use the default amount from filiere_niveau_detail
                if ($details['id_paiement'] === null) {
                    $details['montant_total'] = $details['total_a_payer_prevu'];
                    $details['total_verse'] = 0;
                    $details['reste_a_payer'] = $details['total_a_payer_prevu'];
                    $details['versement_1_montant'] = null; // No actual payment recorded yet
                    $details['versement_2_montant'] = null;
                    $details['versement_3_montant'] = null;
                    $details['versement_4_montant'] = null;
                    $details['versement_1_date'] = null;
                    $details['versement_2_date'] = null;
                    $details['versement_3_date'] = null;
                    $details['versement_4_date'] = null;
                     $details['versement_1_mode'] = null;
                    $details['versement_2_mode'] = null;
                    $details['versement_3_mode'] = null;
                    $details['versement_4_mode'] = null;
                     $details['versement_1_ref'] = null;
                    $details['versement_2_ref'] = null;
                    $details['versement_3_ref'] = null;
                    $details['versement_4_ref'] = null;
                    $details['versement_1_com'] = null;
                    $details['versement_2_com'] = null;
                    $details['versement_3_com'] = null;
                    $details['versement_4_com'] = null;
                }
                
                echo json_encode(['success' => true, 'data' => $details]);
                break;

            case 'get_niveaux_by_filiere':
                $filiereId = intval($_POST['filiere_id'] ?? 0);
                if ($filiereId > 0) {
                    $stmt = $pdo->prepare("SELECT id_niv_etu, lib_niv_etu FROM niveau_etude WHERE fk_id_filiere = ? ORDER BY lib_niv_etu ASC");
                    $stmt->execute([$filiereId]);
                    $niveaux = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $niveaux = [];
                }
                echo json_encode(['success' => true, 'data' => $niveaux]);
                break;
                
            default:
                throw new Exception("Action non reconnue");
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack(); // Rollback transaction on error
        }
        error_log("Erreur AJAX: " . $e->getMessage()); // Log error for debugging
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Gestion des Paiements</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* === VARIABLES CSS === */
        :root {
            /* Couleurs Primaires */
            --primary-50: #f8fafc; --primary-100: #f1f5f9; --primary-200: #e2e8f0; --primary-300: #cbd5e1; --primary-400: #94a3b8; --primary-500: #64748b; --primary-600: #475569; --primary-700: #334155; --primary-800: #1e293b; --primary-900: #0f172a;
            /* Couleurs d'Accent Bleu */
            --accent-50: #eff6ff; --accent-100: #dbeafe; --accent-200: #bfdbfe; --accent-300: #93c5fd; --accent-400: #60a5fa; --accent-500: #3b82f6; --accent-600: #2563eb; --accent-700: #1d4ed8; --accent-800: #1e40af; --accent-900: #1e3a8a;
            /* Couleurs Secondaires */
            --secondary-50: #f0fdf4; --secondary-100: #dcfce7; --secondary-500: #22c55e; --secondary-600: #16a34a;
            /* Couleurs Sémantiques */
            --success-500: #22c55e; --warning-500: #f59e0b; --error-500: #ef4444; --info-500: #3b82f6;
            /* Couleurs Neutres */
            --white: #ffffff; --gray-50: #f9fafb; --gray-100: #f3f4f6; --gray-200: #e5e7eb; --gray-300: #d1d5db; --gray-400: #9ca3af; --gray-500: #6b7280; --gray-600: #4b5563; --gray-700: #374151; --gray-800: #1f2937; --gray-900: #111827;
            /* Layout */
            --sidebar-width: 280px; --sidebar-collapsed-width: 80px; --topbar-height: 70px;
            /* Typographie */
            --font-primary: 'Segoe UI', system-ui, -apple-system, sans-serif;
            --text-xs: 0.75rem; --text-sm: 0.875rem; --text-base: 1rem; --text-lg: 1.125rem; --text-xl: 1.25rem; --text-2xl: 1.5rem; --text-3xl: 1.875rem;
            /* Espacement */
            --space-1: 0.25rem; --space-2: 0.5rem; --space-3: 0.75rem; --space-4: 1rem; --space-5: 1.25rem; --space-6: 1.5rem; --space-8: 2rem; --space-10: 2.5rem; --space-12: 3rem; --space-16: 4rem;
            /* Bordures */
            --radius-sm: 0.25rem; --radius-md: 0.5rem; --radius-lg: 0.75rem; --radius-xl: 1rem; --radius-2xl: 1.5rem; --radius-3xl: 2rem;
            /* Ombres */
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.05); --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            /* Transitions */
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

        /* === TOPBAR === */
        .topbar { height: var(--topbar-height); background: var(--white); border-bottom: 1px solid var(--gray-200); padding: 0 var(--space-6); display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; }
        .topbar-left { display: flex; align-items: center; gap: var(--space-4); }
        .sidebar-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition-fast); color: var(--gray-600); } .sidebar-toggle:hover { background: var(--gray-200); color: var(--gray-800); }
        .page-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-800); }

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
        .form-group input[type="text"], .form-group input[type="number"], .form-group input[type="date"], .form-group select, .form-group textarea { padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); font-size: var(--text-base); color: var(--gray-800); transition: all var(--transition-fast); }
        .form-group input[type="text"]:focus, .form-group input[type="number"]:focus, .form-group input[type="date"]:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--accent-500); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }

        .form-actions { display: flex; gap: var(--space-4); justify-content: flex-end; }
        .btn { padding: var(--space-3) var(--space-5); border-radius: var(--radius-md); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover:not(:disabled) { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover:not(:disabled) { background-color: var(--gray-300); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover:not(:disabled) { background-color: var(--success-600); }
        .btn-warning { background-color: var(--warning-500); color: white; } .btn-warning:hover:not(:disabled) { background-color: #e68a00; }
        .btn-info { background-color: var(--info-500); color: white; } .btn-info:hover:not(:disabled) { background-color: #316be6; }
        .btn-sm { padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }

        /* === FICHE ETUDIANT === */
        .student-card { background: var(--white); border-radius: var(--radius-xl); border: 1px solid var(--gray-200); overflow: hidden; }

        .student-header { display: flex; justify-content: space-between; align-items: center; padding: var(--space-4) var(--space-6); background: var(--accent-50); border-bottom: 1px solid var(--gray-200); }

        .student-identity h4 { font-size: var(--text-xl); color: var(--gray-900); margin-bottom: var(--space-1); }

        .student-meta { display: flex; gap: var(--space-4); font-size: var(--text-sm); color: var(--gray-600); }

        .student-body { padding: var(--space-6); }

        .payment-summary { display: flex; gap: var(--space-6); margin-bottom: var(--space-6); padding-bottom: var(--space-6); border-bottom: 1px solid var(--gray-200); }

        .summary-item { flex: 1; padding: var(--space-4); background: var(--gray-50); border-radius: var(--radius-md); }

        .summary-label { display: block; font-size: var(--text-sm); color: var(--gray-600); margin-bottom: var(--space-2); }

        .summary-value { font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); }

        .payment-installments h5 { font-size: var(--text-lg); margin-bottom: var(--space-4); color: var(--gray-700); }

        .installments-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-4); }

        .installment-card { border: 1px solid var(--gray-200); border-radius: var(--radius-lg); overflow: hidden; }

        .installment-header { padding: var(--space-3); background: var(--gray-100); font-weight: 600; text-align: center; color: var(--gray-700); }

        .installment-body { padding: var(--space-4); text-align: center; }

        .installment-amount { font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-2); }

        .installment-date { font-size: var(--text-sm); color: var(--gray-600); }

        /* Couleurs pour les versements payés */
        .installment-card.paid { border-color: var(--secondary-100); background-color: var(--secondary-50); }

        .installment-card.paid .installment-header { background-color: var(--secondary-100); color: var(--secondary-600); }

        /* Custom styles for installment amounts (paid vs planned) */
        .installment-paid-amount { font-size: var(--text-base); font-weight: 600; color: var(--secondary-600); }
        .installment-planned-amount { font-size: var(--text-xs); color: var(--gray-500); margin-top: var(--space-1); }


        #currentPosition { padding: 0 var(--space-4); font-weight: 600; color: var(--gray-700); }

        /* Messages d'alerte */
        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); display: none; }
        .alert.success { background-color: var(--secondary-50); color: var(--secondary-600); border: 1px solid var(--secondary-100); }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }

        /* Loading */
        .loading { opacity: 0.6; pointer-events: none; }
        .spinner { width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: var(--white); margin: 5% auto; padding: var(--space-6); border-radius: var(--radius-xl); width: 90%; max-width: 700px; box-shadow: var(--shadow-xl); max-height: 80vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); padding-bottom: var(--space-4); border-bottom: 1px solid var(--gray-200); }
        .modal-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .close { color: var(--gray-400); font-size: 28px; font-weight: bold; cursor: pointer; transition: color var(--transition-fast); }
        .close:hover { color: var(--gray-600); }

        /* Added for installment sections in modal */
        .installment-section {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: var(--space-4);
            margin-bottom: var(--space-6);
            background-color: var(--gray-50);
        }

        .installment-section h5 {
            margin-top: 0;
            margin-bottom: var(--space-4);
            color: var(--gray-800);
            font-size: var(--text-lg);
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: var(--space-2);
        }

        .no-results { text-align: center; padding: var(--space-8); color: var(--gray-500); }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .main-content.sidebar-collapsed { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
            .payment-summary { flex-direction: column; gap: var(--space-3); }
            .installments-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_respo_scolarité.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Gestion des Paiements de Scolarité</h1>
                    <p class="page-subtitle">Recherche et gestion des règlements de scolarité des étudiants</p>
                </div>

                <div id="alertMessage" class="alert"></div>

                <div class="form-card">
                    <h3 class="form-card-title">Rechercher un étudiant</h3>
                    <form id="searchForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="search_term">Nom prénom ou matricule</label>
                                <input type="text" id="search_term" name="search_term" placeholder="Entrez le nom, prénom ou matricule">
                            </div>
                            <div class="form-group">
                                <label for="annee_id">Année académique</label>
                                <select id="annee_id" name="annee_id">
                                    <option value="">Toutes les années</option>
                                    <?php foreach ($anneesAcademiques as $annee): ?>
                                        <option value="<?php echo htmlspecialchars($annee['id_Ac']); ?>">
                                            <?php echo htmlspecialchars($annee['annee_libelle']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="filiere_id">Filière</label>
                                <select id="filiere_id" name="filiere_id">
                                    <option value="">Toutes les filières</option>
                                    <?php foreach ($filieres as $filiere): ?>
                                        <option value="<?php echo htmlspecialchars($filiere['id_filiere']); ?>">
                                            <?php echo htmlspecialchars($filiere['lib_filiere']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="niveau_id">Niveau d'étude</label>
                                <select id="niveau_id" name="niveau_id">
                                    <option value="">Tous les niveaux</option>
                                    </select>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="searchBtn">
                                <i class="fas fa-search"></i> Rechercher
                            </button>
                            <button type="button" class="btn btn-secondary" id="clearBtn">
                                <i class="fas fa-eraser"></i> Effacer
                            </button>
                        </div>
                    </form>
                </div>

                <div class="form-card" id="resultsCard" style="display: none;">
                    <div class="table-header">
                        <h3 class="table-title">Résultats de la recherche (<span id="resultCount">0</span>)</h3>
                        <div class="table-actions">
                            <button class="btn btn-secondary" id="prevBtn" disabled>
                                <i class="fas fa-chevron-left"></i> Précédent
                            </button>
                            <span id="currentPosition">0/0</span>
                            <button class="btn btn-secondary" id="nextBtn" disabled>
                                Suivant <i class="fas fa-chevron-right"></i>
                            </button>
                            <button class="btn btn-secondary" id="exportBtn">
                                <i class="fas fa-download"></i> Exporter
                            </button>
                        </div>
                    </div>

                    <div class="student-card" id="studentCard">
                        <div class="student-header">
                            <div class="student-identity">
                                <h4 id="studentName">Nom Prénom</h4>
                                <div class="student-meta">
                                    <span id="studentMatricule">Matricule: </span>
                                    <span id="studentFiliere">Filière: </span> <span id="studentNiveau">Niveau: </span>
                                    <span id="studentAnnee">Année: </span>
                                </div>
                            </div>
                            <div class="student-actions">
                                <button class="btn btn-info" id="viewDetailsBtn">
                                    <i class="fas fa-eye"></i> Détails
                                </button>
                            </div>
                        </div>

                        <div class="student-body">
                            <div class="payment-summary">
                                <div class="summary-item">
                                    <span class="summary-label">Total à payer:</span>
                                    <span class="summary-value" id="totalAPayer">-</span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Total versé:</span>
                                    <span class="summary-value" id="totalVerse">-</span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Reste à payer:</span>
                                    <span class="summary-value" id="resteAPayer">-</span>
                                </div>
                            </div>

                            <div class="payment-installments">
                                <h5>Versements</h5>
                                <div class="installments-grid">
                                    <div class="installment-card" id="versement1">
                                        <div class="installment-header">1er Versement</div>
                                        <div class="installment-body">
                                            <div class="installment-amount" id="versement1Montant">-</div>
                                            <div class="installment-date" id="versement1Date">-</div>
                                        </div>
                                    </div>
                                    <div class="installment-card" id="versement2">
                                        <div class="installment-header">2e Versement</div>
                                        <div class="installment-body">
                                            <div class="installment-amount" id="versement2Montant">-</div>
                                            <div class="installment-date" id="versement2Date">-</div>
                                        </div>
                                    </div>
                                    <div class="installment-card" id="versement3">
                                        <div class="installment-header">3e Versement</div>
                                        <div class="installment-body">
                                            <div class="installment-amount" id="versement3Montant">-</div>
                                            <div class="installment-date" id="versement3Date">-</div>
                                        </div>
                                    </div>
                                    <div class="installment-card" id="versement4">
                                        <div class="installment-header">4e Versement</div>
                                        <div class="installment-body">
                                            <div class="installment-amount" id="versement4Montant">-</div>
                                            <div class="installment-date" id="versement4Date">-</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="newPaymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Créer un nouveau paiement</h3>
                <span class="close" onclick="closeModal('newPaymentModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="newPaymentForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Matricule</label>
                            <input type="text" id="newPayment_matricule" readonly>
                            <input type="hidden" id="newPayment_num_etu">
                            <input type="hidden" id="newPayment_annee_id">
                            <input type="hidden" id="newPayment_filiere_id">
                            <input type="hidden" id="newPayment_niveau_id">
                        </div>
                        <div class="form-group">
                            <label>Nom & Prénom</label>
                            <input type="text" id="newPayment_nom" readonly>
                        </div>
                         <div class="form-group">
                            <label>Filière</label>
                            <input type="text" id="newPayment_filiere_libelle" readonly>
                        </div>
                        <div class="form-group">
                            <label>Niveau</label>
                            <input type="text" id="newPayment_niveau_libelle" readonly>
                        </div>
                        <div class="form-group">
                            <label>Année académique</label>
                            <input type="text" id="newPayment_annee" readonly>
                        </div>
                        <div class="form-group">
                            <label for="montant_total_creation">Montant total à payer (Prévu) (FCFA)</label>
                            <input type="text" id="montant_total_creation" name="montant_total_prevu" readonly>
                            <small class="form-text text-muted">Ce montant est basé sur la configuration Filière/Niveau.</small>
                        </div>
                        <div class="form-group">
                            <label>1er Versement Prévu</label>
                            <input type="text" id="newPayment_versement1_prevu" readonly>
                        </div>
                        <div class="form-group">
                            <label>2ème Versement Prévu</label>
                            <input type="text" id="newPayment_versement2_prevu" readonly>
                        </div>
                        <div class="form-group">
                            <label>3ème Versement Prévu</label>
                            <input type="text" id="newPayment_versement3_prevu" readonly>
                        </div>
                        <div class="form-group">
                            <label>4ème Versement Prévu</label>
                            <input type="text" id="newPayment_versement4_prevu" readonly>
                        </div>
                    </div>

                    <h4 style="margin-top: 20px;">Premier Versement (Optionnel)</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="newPayment_montant_verse_initial">Montant versé aujourd'hui (FCFA)</label>
                            <input type="number" id="newPayment_montant_verse_initial" name="montant_versement_initial" min="0" step="1000" value="0">
                        </div>
                        <div class="form-group">
                            <label for="newPayment_date_versement_initial">Date du versement</label>
                            <input type="date" id="newPayment_date_versement_initial" name="date_versement_initial">
                        </div>
                        <div class="form-group">
                            <label for="newPayment_mode_paiement_initial">Mode de paiement</label>
                            <select id="newPayment_mode_paiement_initial" name="mode_paiement_initial">
                                <option value="especes">Espèces</option>
                                <option value="cheque">Chèque</option>
                                <option value="virement">Virement</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="newPayment_reference_paiement_initial">Référence</label>
                            <input type="text" id="newPayment_reference_paiement_initial" name="reference_paiement_initial" placeholder="N° chèque, référence virement...">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="newPayment_commentaire_initial">Commentaire</label>
                            <textarea id="newPayment_commentaire_initial" name="commentaire_initial" rows="2" placeholder="Commentaire optionnel"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('newPaymentModal')">Annuler</button>
                <button class="btn btn-success" onclick="createPayment()">
                    <i class="fas fa-save"></i> Créer le paiement
                </button>
            </div>
        </div>
    </div>

    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Détails et versements</h3>
                <span class="close" onclick="closeModal('paymentModal')">&times;</span>
            </div>
            <div class="modal-body">
                <h4>Informations étudiant</h4>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Matricule</label>
                        <input type="text" id="modal_matricule" readonly>
                        <input type="hidden" id="modal_id_paiement">
                        <input type="hidden" id="modal_num_etu">
                        <input type="hidden" id="modal_annee_id">
                    </div>
                    <div class="form-group">
                        <label>Nom & Prénom</label>
                        <input type="text" id="modal_nom" readonly>
                    </div>
                    <div class="form-group">
                        <label>Filière</label>
                        <input type="text" id="modal_filiere" readonly>
                    </div>
                    <div class="form-group">
                        <label>Niveau</label>
                        <input type="text" id="modal_niveau" readonly>
                    </div>
                    <div class="form-group">
                        <label>Total à payer</label>
                        <input type="text" id="modal_total" readonly>
                    </div>
                    <div class="form-group">
                        <label>Total versé</label>
                        <input type="text" id="modal_total_verse" readonly>
                    </div>
                    <div class="form-group">
                        <label>Reste à payer</label>
                        <input type="text" id="modal_reste" readonly>
                    </div>
                </div>

                <h4 style="margin-top: 20px;">Détails des Versements</h4>
                
                <div class="installment-section">
                    <h5>1er Versement</h5>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Montant à payer (Prévu)</label>
                            <input type="text" id="modal_versement1_prevu" readonly>
                        </div>
                        <div class="form-group">
                            <label>Montant déjà réglé</label>
                            <input type="text" id="modal_versement1_regle" readonly>
                        </div>
                        <div class="form-group">
                            <label for="montant_verse_actuel_1">Montant versé aujourd'hui (FCFA) <span style="color: var(--error-500);">*</span></label>
                            <input type="number" id="montant_verse_actuel_1" min="0" step="1000" value="0">
                        </div>
                        <div class="form-group">
                            <label for="date_versement_1">Date de versement <span style="color: var(--error-500);">*</span></label>
                            <input type="date" id="date_versement_1" required>
                        </div>
                        <div class="form-group">
                            <label for="mode_paiement_1">Mode de paiement</label>
                            <select id="mode_paiement_1">
                                <option value="especes">Espèces</option>
                                <option value="cheque">Chèque</option>
                                <option value="virement">Virement</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reference_paiement_1">Référence</label>
                            <input type="text" id="reference_paiement_1" placeholder="N° chèque, référence virement...">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="commentaire_1">Commentaire</label>
                            <textarea id="commentaire_1" rows="2" placeholder="Commentaire optionnel"></textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-success" onclick="saveSingleVersement(1)">
                            <i class="fas fa-save"></i> Enregistrer Versement 1
                        </button>
                    </div>
                </div>

                <div class="installment-section">
                    <h5>2e Versement</h5>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Montant à payer (Prévu)</label>
                            <input type="text" id="modal_versement2_prevu" readonly>
                        </div>
                        <div class="form-group">
                            <label>Montant déjà réglé</label>
                            <input type="text" id="modal_versement2_regle" readonly>
                        </div>
                        <div class="form-group">
                            <label for="montant_verse_actuel_2">Montant versé aujourd'hui (FCFA) <span style="color: var(--error-500);">*</span></label>
                            <input type="number" id="montant_verse_actuel_2" min="0" step="1000" value="0">
                        </div>
                        <div class="form-group">
                            <label for="date_versement_2">Date de versement <span style="color: var(--error-500);">*</span></label>
                            <input type="date" id="date_versement_2" required>
                        </div>
                        <div class="form-group">
                            <label for="mode_paiement_2">Mode de paiement</label>
                            <select id="mode_paiement_2">
                                <option value="especes">Espèces</option>
                                <option value="cheque">Chèque</option>
                                <option value="virement">Virement</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reference_paiement_2">Référence</label>
                            <input type="text" id="reference_paiement_2" placeholder="N° chèque, référence virement...">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="commentaire_2">Commentaire</label>
                            <textarea id="commentaire_2" rows="2" placeholder="Commentaire optionnel"></textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-success" onclick="saveSingleVersement(2)">
                            <i class="fas fa-save"></i> Enregistrer Versement 2
                        </button>
                    </div>
                </div>

                <div class="installment-section">
                    <h5>3e Versement</h5>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Montant à payer (Prévu)</label>
                            <input type="text" id="modal_versement3_prevu" readonly>
                        </div>
                        <div class="form-group">
                            <label>Montant déjà réglé</label>
                            <input type="text" id="modal_versement3_regle" readonly>
                        </div>
                        <div class="form-group">
                            <label for="montant_verse_actuel_3">Montant versé aujourd'hui (FCFA) <span style="color: var(--error-500);">*</span></label>
                            <input type="number" id="montant_verse_actuel_3" min="0" step="1000" value="0">
                        </div>
                        <div class="form-group">
                            <label for="date_versement_3">Date de versement <span style="color: var(--error-500);">*</span></label>
                            <input type="date" id="date_versement_3" required>
                        </div>
                        <div class="form-group">
                            <label for="mode_paiement_3">Mode de paiement</label>
                            <select id="mode_paiement_3">
                                <option value="especes">Espèces</option>
                                <option value="cheque">Chèque</option>
                                <option value="virement">Virement</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reference_paiement_3">Référence</label>
                            <input type="text" id="reference_paiement_3" placeholder="N° chèque, référence virement...">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="commentaire_3">Commentaire</label>
                            <textarea id="commentaire_3" rows="2" placeholder="Commentaire optionnel"></textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-success" onclick="saveSingleVersement(3)">
                            <i class="fas fa-save"></i> Enregistrer Versement 3
                        </button>
                    </div>
                </div>

                <div class="installment-section">
                    <h5>4e Versement</h5>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Montant à payer (Prévu)</label>
                            <input type="text" id="modal_versement4_prevu" readonly>
                        </div>
                        <div class="form-group">
                            <label>Montant déjà réglé</label>
                            <input type="text" id="modal_versement4_regle" readonly>
                        </div>
                        <div class="form-group">
                            <label for="montant_verse_actuel_4">Montant versé aujourd'hui (FCFA) <span style="color: var(--error-500);">*</span></label>
                            <input type="number" id="montant_verse_actuel_4" min="0" step="1000" value="0">
                        </div>
                        <div class="form-group">
                            <label for="date_versement_4">Date de versement <span style="color: var(--error-500);">*</span></label>
                            <input type="date" id="date_versement_4" required>
                        </div>
                        <div class="form-group">
                            <label for="mode_paiement_4">Mode de paiement</label>
                            <select id="mode_paiement_4">
                                <option value="especes">Espèces</option>
                                <option value="cheque">Chèque</option>
                                <option value="virement">Virement</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reference_paiement_4">Référence</label>
                            <input type="text" id="reference_paiement_4" placeholder="N° chèque, référence virement...">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="commentaire_4">Commentaire</label>
                            <textarea id="commentaire_4" rows="2" placeholder="Commentaire optionnel"></textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-success" onclick="saveSingleVersement(4)">
                            <i class="fas fa-save"></i> Enregistrer Versement 4
                        </button>
                    </div>
                </div>

            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('paymentModal')">Fermer la fenêtre</button>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let currentResults = [];
        let currentIndex = 0;
        const allNiveauxEtude = <?php echo $niveauxEtudeJson; ?>; // All levels from PHP

        // DOM elements
        const filiereSelect = document.getElementById('filiere_id');
        const niveauSelect = document.getElementById('niveau_id');

        // Functions for alerts
        function showAlert(message, type = 'info') {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.textContent = message;
            alertDiv.className = `alert ${type}`;
            alertDiv.style.display = 'block';
            
            setTimeout(() => {
                alertDiv.style.display = 'none';
            }, 5000);
        }

        // AJAX request function
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

        // Format amounts
        function formatMontant(montant) {
            if (montant === null || montant === undefined || isNaN(montant) || montant === '') return '-';
            return new Intl.NumberFormat('fr-FR').format(parseFloat(montant)) + ' FCFA';
        }

        // Filter levels based on selected filiere
        filiereSelect.addEventListener('change', function() {
            const selectedFiliereId = this.value;
            niveauSelect.innerHTML = '<option value="">Tous les niveaux</option>'; // Reset levels
            
            if (selectedFiliereId) {
                const filteredNiveaux = allNiveauxEtude.filter(niveau => niveau.fk_id_filiere == selectedFiliereId);
                filteredNiveaux.forEach(niveau => {
                    const option = document.createElement('option');
                    option.value = niveau.id_niv_etu;
                    option.textContent = niveau.lib_niv_etu;
                    niveauSelect.appendChild(option);
                });
            }
        });

        // Search students
        document.getElementById('searchForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const searchBtn = document.getElementById('searchBtn');
            const originalText = searchBtn.innerHTML;
            
            try {
                searchBtn.innerHTML = '<div class="spinner"></div> Recherche...';
                searchBtn.disabled = true;
                
                const formData = new FormData(this);
                formData.append('action', 'rechercher_etudiant');
                
                const result = await makeAjaxRequest(Object.fromEntries(formData));
                
                if (result.success) {
                    displayResults(result.data);
                } else {
                    let errorMessage = result.message || 'Erreur lors de la recherche';
                    if (errorMessage.includes('vue_paiements_etudiants')) {
                        errorMessage = 'La base de données n\'est pas correctement configurée. Veuillez exécuter le script de correction SQL avant d\'utiliser cette interface.';
                    }
                    showAlert(errorMessage, 'error');
                }
            } catch (error) {
                console.error('Erreur AJAX:', error);
                showAlert('Erreur de connexion au serveur. Vérifiez que votre base de données est correctement configurée.', 'error');
            } finally {
                searchBtn.innerHTML = originalText;
                searchBtn.disabled = false;
            }
        });

        // Display search results
        function displayResults(results) {
            const resultsCard = document.getElementById('resultsCard');
            const resultCount = document.getElementById('resultCount');
            
            resultCount.textContent = results.length;
            currentResults = results;
            currentIndex = 0;
            
            if (results.length === 0) {
                resultsCard.style.display = 'none';
                showAlert('Aucun étudiant trouvé', 'info');
            } else {
                resultsCard.style.display = 'block';
                updateStudentCard(results[0]);
                updateNavigation();
            }
        }

        // Update student card
        function updateStudentCard(student) {
            const hasPaiement = student.id_paiement !== null;
            
            // Basic information
            document.getElementById('studentName').textContent = `${student.nom_etu} ${student.prenoms_etu}`;
            document.getElementById('studentMatricule').textContent = `Matricule: ${student.num_etu}`;
            document.getElementById('studentFiliere').textContent = `Filière: ${student.lib_filiere || '-'}`; // Display Filière
            document.getElementById('studentNiveau').textContent = `Niveau: ${student.lib_niv_etu || '-'}`;
            document.getElementById('studentAnnee').textContent = `Année: ${student.annee_libelle || '-'}`;
            
            // Payment summary
            document.getElementById('totalAPayer').textContent = formatMontant(student.total_a_payer_prevu || student.montant_total);
            document.getElementById('totalVerse').textContent = hasPaiement ? formatMontant(student.total_verse) : '-';
            document.getElementById('resteAPayer').textContent = hasPaiement ? formatMontant(student.montant_total - student.total_verse) : formatMontant(student.total_a_payer_prevu);
            
            // Installments - This is the core logic for "Payé" vs "Prévu"
            const plannedInstallments = [
                student.versement_1_prevu,
                student.versement_2_prevu,
                student.versement_3_prevu,
                student.versement_4_prevu
            ].map(val => parseFloat(val) || 0); // Ensure numbers, default to 0

            const actualInstallments = [
                student.versement_1_montant,
                student.versement_2_montant,
                student.versement_3_montant,
                student.versement_4_montant
            ].map(val => parseFloat(val) || 0);

            for (let i = 1; i <= 4; i++) {
                const cardId = `versement${i}`;
                const plannedAmount = plannedInstallments[i - 1];
                const actualPaidAmount = actualInstallments[i - 1];
                const actualPaidDate = student[`versement_${i}_date`];

                updateInstallmentCard(
                    cardId,
                    actualPaidAmount,
                    actualPaidDate,
                    plannedAmount
                );
            }
            
            // Action button
            const viewDetailsBtn = document.getElementById('viewDetailsBtn');
            if (hasPaiement) {
                viewDetailsBtn.innerHTML = '<i class="fas fa-eye"></i> Détails';
                viewDetailsBtn.className = 'btn btn-info';
                viewDetailsBtn.onclick = () => viewPaymentDetails(student.num_etu, student.id_Ac);
            } else {
                viewDetailsBtn.innerHTML = '<i class="fas fa-plus"></i> Créer paiement';
                viewDetailsBtn.className = 'btn btn-success';
                viewDetailsBtn.onclick = () => createNewPayment(
                    student.num_etu, 
                    student.id_Ac, 
                    `${student.nom_etu} ${student.prenoms_etu}`, 
                    student.lib_filiere, 
                    student.lib_niv_etu, 
                    student.total_a_payer_prevu, // Pass total a payer from filiere_niveau_detail
                    student.fk_id_filiere, 
                    student.id_niv_etu,
                    student.versement_1_prevu, // Pass planned installment amounts
                    student.versement_2_prevu,
                    student.versement_3_prevu,
                    student.versement_4_prevu
                );
            }
        }

        // Update an installment card with planned and paid details
        function updateInstallmentCard(cardId, actualPaidAmount, actualPaidDate, plannedAmount) {
            const card = document.getElementById(cardId);
            const amountElement = document.getElementById(`${cardId}Montant`);
            const dateElement = document.getElementById(`${cardId}Date`);
            
            // Clear previous states
            card.classList.remove('paid');
            amountElement.innerHTML = '';
            dateElement.textContent = '';

            const divPaid = document.createElement('div');
            divPaid.classList.add('installment-paid-amount');
            const divPlanned = document.createElement('div');
            divPlanned.classList.add('installment-planned-amount');

            if (actualPaidAmount !== null && parseFloat(actualPaidAmount) > 0) {
                divPaid.textContent = `Payé: ${formatMontant(actualPaidAmount)}`;
                dateElement.textContent = actualPaidDate ? new Date(actualPaidDate).toLocaleDateString('fr-FR') : '';
                
                // Determine if fully paid based on actual vs planned
                if (plannedAmount !== null && parseFloat(plannedAmount) > 0 && parseFloat(actualPaidAmount) >= parseFloat(plannedAmount)) {
                    card.classList.add('paid');
                } else if (actualPaidAmount > 0) {
                     card.classList.remove('paid'); // Partially paid
                }
            } else {
                divPaid.textContent = 'Non payé';
            }

            if (plannedAmount !== null && parseFloat(plannedAmount) > 0) {
                divPlanned.textContent = `Prévu: ${formatMontant(plannedAmount)}`;
            } else {
                divPlanned.textContent = `Prévu: -`;
            }
            
            amountElement.appendChild(divPaid);
            amountElement.appendChild(divPlanned);
        }


        // Update navigation
        function updateNavigation() {
            document.getElementById('currentPosition').textContent = `${currentIndex + 1}/${currentResults.length}`;
            document.getElementById('prevBtn').disabled = currentIndex <= 0;
            document.getElementById('nextBtn').disabled = currentIndex >= currentResults.length - 1;
        }

        // Navigation between cards
        document.getElementById('prevBtn').addEventListener('click', () => {
            if (currentIndex > 0) {
                currentIndex--;
                updateStudentCard(currentResults[currentIndex]);
                updateNavigation();
            }
        });

        document.getElementById('nextBtn').addEventListener('click', () => {
            if (currentIndex < currentResults.length - 1) {
                currentIndex++;
                updateStudentCard(currentResults[currentIndex]);
                updateNavigation();
            }
        });

        // Create new payment
        function createNewPayment(numEtu, anneeId, nomComplet, filiereLibelle, niveauLibelle, montantPrevu, filiereId, niveauId, versement1Prevu, versement2Prevu, versement3Prevu, versement4Prevu) {
            document.getElementById('newPayment_matricule').value = numEtu;
            document.getElementById('newPayment_num_etu').value = numEtu;
            document.getElementById('newPayment_annee_id').value = anneeId;
            document.getElementById('newPayment_nom').value = nomComplet;
            document.getElementById('newPayment_filiere_libelle').value = filiereLibelle;
            document.getElementById('newPayment_niveau_libelle').value = niveauLibelle;
            document.getElementById('newPayment_annee').value = currentResults[currentIndex].annee_libelle;
            
            // Set the read-only total expected amount
            document.getElementById('montant_total_creation').value = formatMontant(montantPrevu); 
            
            // Set hidden IDs
            document.getElementById('newPayment_filiere_id').value = filiereId;
            document.getElementById('newPayment_niveau_id').value = niveauId;

            // Fill planned installment amounts (read-only)
            document.getElementById('newPayment_versement1_prevu').value = formatMontant(versement1Prevu);
            document.getElementById('newPayment_versement2_prevu').value = formatMontant(versement2Prevu);
            document.getElementById('newPayment_versement3_prevu').value = formatMontant(versement3Prevu);
            document.getElementById('newPayment_versement4_prevu').value = formatMontant(versement4Prevu);

            // Set today's date for initial payment
            document.getElementById('newPayment_date_versement_initial').value = new Date().toISOString().split('T')[0];
            document.getElementById('newPayment_montant_verse_initial').value = ''; // Clear previous input

            document.getElementById('newPaymentModal').style.display = 'block';
        }

        // Create payment
        async function createPayment() {
            // Get the planned total from the read-only field
            const montantTotalPrevuRaw = document.getElementById('montant_total_creation').value;
            const montantTotalPrevu = parseFloat(montantTotalPrevuRaw.replace(/[^0-9,-]+/g, "").replace(",", ".")) || 0;

            const numEtu = document.getElementById('newPayment_num_etu').value;
            const anneeId = document.getElementById('newPayment_annee_id').value;
            
            // Get initial payment details
            const montantVersementInitial = parseFloat(document.getElementById('newPayment_montant_verse_initial').value) || 0;
            const dateVersementInitial = document.getElementById('newPayment_date_versement_initial').value;
            const modePaiementInitial = document.getElementById('newPayment_mode_paiement_initial').value;
            const referencePaiementInitial = document.getElementById('newPayment_reference_paiement_initial').value;
            const commentaireInitial = document.getElementById('newPayment_commentaire_initial').value;
            
            if (montantTotalPrevu <= 0) {
                showAlert('Le montant total à payer est invalide.', 'error');
                return;
            }

            if (montantVersementInitial > 0 && !dateVersementInitial) {
                showAlert('Veuillez saisir la date du premier versement si un montant est versé.', 'error');
                return;
            }

            try {
                const result = await makeAjaxRequest({
                    action: 'creer_paiement',
                    num_etu: numEtu,
                    annee_id: anneeId,
                    montant_total_prevu: montantTotalPrevu, // Send the expected total
                    montant_versement_initial: montantVersementInitial, // Send the actual initial payment
                    date_versement_initial: dateVersementInitial,
                    mode_paiement_initial: modePaiementInitial,
                    reference_paiement_initial: referencePaiementInitial,
                    commentaire_initial: commentaireInitial
                });
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    closeModal('newPaymentModal');
                    // Refresh search results to show the newly created payment
                    document.getElementById('searchForm').dispatchEvent(new Event('submit')); 
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                console.error('Erreur lors de la création du paiement:', error);
                showAlert('Erreur lors de la création du paiement', 'error');
            }
        }

        // View payment details
        async function viewPaymentDetails(numEtu, anneeId) {
            try {
                const result = await makeAjaxRequest({
                    action: 'obtenir_details_paiement',
                    num_etu: numEtu,
                    annee_id: anneeId
                });
                
                if (result.success && result.data) {
                    const data = result.data;
                    
                    document.getElementById('modal_matricule').value = data.num_etu;
                    document.getElementById('modal_id_paiement').value = data.id_paiement;
                    document.getElementById('modal_num_etu').value = data.num_etu;
                    document.getElementById('modal_annee_id').value = data.id_Ac;
                    document.getElementById('modal_nom').value = `${data.nom_etu} ${data.prenoms_etu}`;
                    document.getElementById('modal_filiere').value = data.lib_filiere;
                    document.getElementById('modal_niveau').value = data.lib_niv_etu;
                    document.getElementById('modal_total').value = formatMontant(data.montant_total);
                    document.getElementById('modal_total_verse').value = formatMontant(data.total_verse);
                    document.getElementById('modal_reste').value = formatMontant(data.reste_a_payer);
                    
                    // Populate each installment section
                    for (let i = 1; i <= 4; i++) {
                        document.getElementById(`modal_versement${i}_prevu`).value = formatMontant(data[`versement_${i}_prevu`]);
                        document.getElementById(`modal_versement${i}_regle`).value = formatMontant(data[`versement_${i}_montant`]);
                        document.getElementById(`montant_verse_actuel_${i}`).value = ''; // Clear input for current payment
                        document.getElementById(`date_versement_${i}`).value = data[`versement_${i}_date`] || new Date().toISOString().split('T')[0]; // Pre-fill with existing date or today
                        document.getElementById(`mode_paiement_${i}`).value = data[`versement_${i}_mode`] || 'especes';
                        document.getElementById(`reference_paiement_${i}`).value = data[`versement_${i}_ref`] || '';
                        document.getElementById(`commentaire_${i}`).value = data[`versement_${i}_com`] || '';

                         // Disable inputs if installment is fully paid or beyond remaining balance
                        const plannedAmount = parseFloat(data[`versement_${i}_prevu`]) || 0;
                        const actualPaidAmount = parseFloat(data[`versement_${i}_montant`]) || 0;
                        const totalResteAPayer = parseFloat(data.reste_a_payer) || 0;

                        const currentMontantActuelInput = document.getElementById(`montant_verse_actuel_${i}`);
                        const currentDateInput = document.getElementById(`date_versement_${i}`);
                        const currentModeInput = document.getElementById(`mode_paiement_${i}`);
                        const currentRefInput = document.getElementById(`reference_paiement_${i}`);
                        const currentComInput = document.getElementById(`commentaire_${i}`);
                        const currentSaveBtn = document.querySelector(`.installment-section:nth-child(${i}) .btn-success`);
                        
                        if (actualPaidAmount >= plannedAmount && plannedAmount > 0) {
                            // Fully paid
                            currentMontantActuelInput.disabled = true;
                            currentMontantActuelInput.value = '0';
                            currentDateInput.disabled = true;
                            currentModeInput.disabled = true;
                            currentRefInput.disabled = true;
                            currentComInput.disabled = true;
                            currentSaveBtn.disabled = true;
                            currentSaveBtn.textContent = 'Versement réglé';
                        } else if (totalResteAPayer <= 0 && data.id_paiement !== null) {
                            // If overall payment is complete (and payment record exists)
                            currentMontantActuelInput.disabled = true;
                            currentMontantActuelInput.value = '0';
                            currentDateInput.disabled = true;
                            currentModeInput.disabled = true;
                            currentRefInput.disabled = true;
                            currentComInput.disabled = true;
                            currentSaveBtn.disabled = true;
                            currentSaveBtn.textContent = 'Paiement Complet';
                        }
                        else {
                            // Partially paid or not paid yet, enable for current payment
                            currentMontantActuelInput.disabled = false;
                            currentDateInput.disabled = false;
                            currentModeInput.disabled = false;
                            currentRefInput.disabled = false;
                            currentComInput.disabled = false;
                            currentSaveBtn.disabled = false;
                             currentSaveBtn.textContent = `Enregistrer Versement ${i}`;
                        }
                    }
                    
                    document.getElementById('paymentModal').style.display = 'block';
                } else {
                    showAlert(result.message || 'Erreur lors de la récupération des détails', 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la récupération des détails', 'error');
            }
        }

        // Save a single installment
        async function saveSingleVersement(numeroVersement) {
            const montantVerseActuel = parseFloat(document.getElementById(`montant_verse_actuel_${numeroVersement}`).value) || 0;
            const dateVersement = document.getElementById(`date_versement_${numeroVersement}`).value;
            const modePaiement = document.getElementById(`mode_paiement_${numeroVersement}`).value;
            const reference = document.getElementById(`reference_paiement_${numeroVersement}`).value;
            const commentaire = document.getElementById(`commentaire_${numeroVersement}`).value;

            if (montantVerseActuel <= 0 || !dateVersement) {
                showAlert('Veuillez saisir un montant versé et une date valides.', 'error');
                return;
            }

            const id_paiement = document.getElementById('modal_id_paiement').value;

            try {
                const result = await makeAjaxRequest({
                    action: 'ajouter_versement',
                    id_paiement: id_paiement,
                    numero_versement: numeroVersement,
                    montant_verse_actuel: montantVerseActuel,
                    date_versement: dateVersement,
                    mode_paiement: modePaiement,
                    reference_paiement: reference,
                    commentaire: commentaire
                });
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    closeModal('paymentModal');
                    // Refresh search results to reflect updated payments
                    document.getElementById('searchForm').dispatchEvent(new Event('submit'));
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'enregistrement du versement', 'error');
            }
        }

        // Close modals
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modals by clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Clear button
        document.getElementById('clearBtn').addEventListener('click', function() {
            document.getElementById('searchForm').reset();
            filiereSelect.dispatchEvent(new Event('change')); // Reset levels dropdown
            document.getElementById('resultsCard').style.display = 'none';
            currentResults = [];
        });

        // Export functionality (simulated)
        document.getElementById('exportBtn').addEventListener('click', function() {
            if (currentResults.length === 0) {
                showAlert('Aucune donnée à exporter', 'warning');
                return;
            }
            showAlert('Export en cours de développement', 'info');
        });

        // Sidebar responsive toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
            });
        }

        // Set today's date by default for installment date fields when modal opens
        document.addEventListener('DOMContentLoaded', function() {
            // Trigger change event on filiere_id to populate niveau_id initially
            filiereSelect.dispatchEvent(new Event('change'));
        });
    </script>
</body>
</html>