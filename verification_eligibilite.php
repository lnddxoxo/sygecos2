<?php
// verification_eligibilite.php
require_once 'config.php'; // Assurez-vous que ce fichier inclut votre connexion PDO et les fonctions isLoggedIn/redirect

if (!isLoggedIn()) {
    redirect('loginForm.php'); // Redirige si l'utilisateur n'est pas connecté
}

// Récupération des données pour les filtres et l'affichage initial
$filieres = [];
$niveauxEtude = [];
$anneesAcademiques = [];
$anneeActiveInfo = null;

try {
    // Récupérer l'année académique active (pour affichage par défaut)
    $stmtActiveAnnee = $pdo->query("SELECT id_Ac, date_deb, date_fin, CONCAT(YEAR(date_deb), '-', YEAR(date_fin)) as annee_libelle FROM année_academique WHERE statut = 'active' OR est_courante = 1 LIMIT 1");
    $anneeActiveInfo = $stmtActiveAnnee->fetch(PDO::FETCH_ASSOC);

    // Récupérer toutes les filières
    $stmtFilieres = $pdo->query("SELECT id_filiere, lib_filiere FROM filiere ORDER BY lib_filiere ASC");
    $filieres = $stmtFilieres->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer tous les niveaux d'étude
    $stmtNiveaux = $pdo->query("SELECT id_niv_etu, lib_niv_etu FROM niveau_etude ORDER BY lib_niv_etu ASC");
    $niveauxEtude = $stmtNiveaux->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer toutes les années académiques (pour le filtre)
    $stmtAnnees = $pdo->query("SELECT id_Ac, date_deb, date_fin FROM année_academique ORDER BY date_deb DESC");
    $anneesAcademiques = $stmtAnnees->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur de récupération des données initiales: " . $e->getMessage());
}

// Fonction pour formater l'affichage de l'année académique
function formatAnneeAcademique($dateDeb, $dateFin) {
    $anneeDebut = date('Y', strtotime($dateDeb));
    $anneeFin = date('Y', strtotime($dateFin));
    return $anneeDebut . '-' . $anneeFin;
}

// Fonction pour obtenir les détails d'éligibilité (sans seuils dynamiques du formulaire)
// Les seuils (crédits requis, tolérance de paiement) sont ici codés en dur ou à définir par votre logique métier
// Si ces seuils doivent être dynamiques, il faudrait une table de configuration ou les passer en paramètre.
// Pour l'exercice, nous allons fixer des valeurs typiques.
function getEligibilityDetails($pdo, $anneeId, $niveauId) {
    // Définissez vos seuils ici. Par exemple, 60 crédits et 0 FCFA de tolérance
    $creditsRequis = 60;
    $tolerancePaiement = 0; // Remettre à 0 ou à une valeur si le paiement est strict

    // Vérifier l'existence de la vue paiements_etudiants
    $checkView = $pdo->query("SHOW TABLES LIKE 'vue_paiements_etudiants'");
    $vueExists = ($checkView->rowCount() > 0);

    // Requête principale pour l'éligibilité
    $sql = "
        SELECT 
            e.num_etu,
            e.nom_etu,
            e.prenoms_etu,
            e.email_etu,
            f.lib_filiere,
            e.fk_id_filiere, -- Pour les filtres
            ne.lib_niv_etu,
            ne.id_niv_etu, -- Pour les filtres
            aa.id_Ac, -- Pour les filtres
            CONCAT(YEAR(aa.date_deb), '-', YEAR(aa.date_fin)) as annee_academique,
            
            -- Calcul des crédits obtenus
            COALESCE(SUM(
                CASE 
                    WHEN ev.note >= 10 THEN ec.credit_ECUE 
                    ELSE 0 
                END
            ), 0) as credits_obtenus,
            
            -- Informations de paiement (si la vue existe)
            " . ($vueExists ? "
            vp.montant_total,
            vp.total_verse,
            vp.reste_a_payer,
            " : "
            NULL as montant_total,
            NULL as total_verse,
            NULL as reste_a_payer,
            ") . "
            
            -- Détermination de l'éligibilité
            CASE 
                WHEN COALESCE(SUM(
                    CASE 
                        WHEN ev.note >= 10 THEN ec.credit_ECUE 
                        ELSE 0 
                    END
                ), 0) >= :credits_requis
                " . ($vueExists ? "AND (vp.reste_a_payer IS NULL OR vp.reste_a_payer <= :tolerance_paiement)" : "") . "
                THEN 'ELIGIBLE'
                ELSE 'NON_ELIGIBLE'
            END as statut_eligibilite,
            
            -- Raisons de non-éligibilité
            CASE 
                WHEN COALESCE(SUM(
                    CASE 
                        WHEN ev.note >= 10 THEN ec.credit_ECUE 
                        ELSE 0 
                    END
                ), 0) < :credits_requis THEN CONCAT('Crédits insuffisants (', COALESCE(SUM(
                    CASE 
                        WHEN ev.note >= 10 THEN ec.credit_ECUE 
                        ELSE 0 
                    END
                ), 0), '/', :credits_requis_reason, ')')
                " . ($vueExists ? "
                WHEN vp.reste_a_payer > :tolerance_paiement_reason THEN CONCAT('Scolarité en retard (', COALESCE(vp.reste_a_payer, 0), ' FCFA)')
                " : "") . "
                ELSE ''
            END as raisons_non_eligibilite
            
        FROM etudiant e
        INNER JOIN inscrire i ON e.num_etu = i.fk_num_etu
        LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
        LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
        LEFT JOIN année_academique aa ON i.fk_id_Ac = aa.id_Ac
        LEFT JOIN evaluer ev ON e.num_etu = ev.fk_num_etu AND ev.fk_id_Ac = i.fk_id_Ac
        LEFT JOIN ecue ec ON ev.fk_id_ECUE = ec.id_ECUE
        " . ($vueExists ? "LEFT JOIN vue_paiements_etudiants vp ON e.num_etu = vp.num_etu AND vp.id_Ac = i.fk_id_Ac" : "") . "
        WHERE i.fk_id_Ac = :annee_id AND e.fk_id_niv_etu = :niveau_id
        GROUP BY e.num_etu, e.nom_etu, e.prenoms_etu, f.lib_filiere, e.fk_id_filiere, ne.lib_niv_etu, ne.id_niv_etu, aa.id_Ac, aa.date_deb, aa.date_fin
        " . ($vueExists ? ", vp.montant_total, vp.total_verse, vp.reste_a_payer" : "") . "
        ORDER BY e.nom_etu, e.prenoms_etu
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':credits_requis', $creditsRequis, PDO::PARAM_INT);
    $stmt->bindValue(':credits_requis_reason', $creditsRequis, PDO::PARAM_INT); // Pour la raison
    if ($vueExists) {
        $stmt->bindValue(':tolerance_paiement', $tolerancePaiement, PDO::PARAM_STR);
        $stmt->bindValue(':tolerance_paiement_reason', $tolerancePaiement, PDO::PARAM_STR); // Pour la raison
    }
    $stmt->bindValue(':annee_id', $anneeId, PDO::PARAM_INT);
    $stmt->bindValue(':niveau_id', $niveauId, PDO::PARAM_INT);
    
    $stmt->execute();
    $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['data' => $resultats, 'vue_paiements_exists' => $vueExists, 'credits_requis' => $creditsRequis];
}


// === TRAITEMENT AJAX ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'get_eligibilite_data':
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $niveauId = intval($_POST['niveau_id'] ?? 0);
                
                if ($anneeId <= 0 || $niveauId <= 0) {
                    throw new Exception("Paramètres manquants");
                }
                
                $eligibilityInfo = getEligibilityDetails($pdo, $anneeId, $niveauId);
                $resultats = $eligibilityInfo['data'];
                $vueExists = $eligibilityInfo['vue_paiements_exists'];
                $creditsRequis = $eligibilityInfo['credits_requis']; // Get fixed credits required

                // Calcul des statistiques
                $totalEtudiants = count($resultats);
                $eligibles = array_filter($resultats, function($r) { return $r['statut_eligibilite'] === 'ELIGIBLE'; });
                $nonEligibles = array_filter($resultats, function($r) { return $r['statut_eligibilite'] === 'NON_ELIGIBLE'; });
                
                $stats = [
                    'total_etudiants' => $totalEtudiants,
                    'eligibles' => count($eligibles),
                    'non_eligibles' => count($nonEligibles),
                    'taux_eligibilite' => $totalEtudiants > 0 ? round((count($eligibles) / $totalEtudiants) * 100, 1) : 0
                ];
                
                echo json_encode([
                    'success' => true, 
                    'data' => $resultats,
                    'stats' => $stats,
                    'vue_paiements_exists' => $vueExists,
                    'credits_requis' => $creditsRequis // Send credits required to JS for progress bar
                ]);
                break;
                
            case 'valider_eligibilite_manuelle':
                $matricules = $_POST['matricules'] ?? [];
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $commentaire = trim($_POST['commentaire'] ?? '');
                
                if (empty($matricules) || $anneeId <= 0) {
                    throw new Exception("Paramètres manquants pour la validation.");
                }
                
                $pdo->beginTransaction();
                
                // Ici, vous auriez besoin d'une table pour enregistrer ces validations manuelles.
                // Par exemple : table `eligibilite_manuelle` (num_etu, id_Ac, date_validation, commentaire, id_utilisateur_validation)
                // Pour cet exemple, nous allons juste simuler l'action.
                // Si vous voulez implémenter cela, créez la table et décommentez/adaptez le code SQL ci-dessous.

                // Exemple de SQL pour une table `eligibilite_manuelle`:
                /*
                $stmtInsertValidation = $pdo->prepare("
                    INSERT INTO eligibilite_manuelle (num_etu, id_Ac, date_validation, commentaire, id_utilisateur_validation)
                    VALUES (?, ?, NOW(), ?, ?)
                    ON DUPLICATE KEY UPDATE date_validation = NOW(), commentaire = ?, id_utilisateur_validation = ?
                ");
                $userId = $_SESSION['user_id'] ?? null; // Assurez-vous d'avoir l'ID de l'utilisateur connecté
                foreach ($matricules as $matricule) {
                    $stmtInsertValidation->execute([$matricule, $anneeId, $commentaire, $userId, $commentaire, $userId]);
                }
                */
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => count($matricules) . ' étudiant(s) validé(s) manuellement comme éligible(s).'
                ]);
                break;
                
            case 'supprimer_eligibilite_validation': // Pour annuler une validation manuelle
                $matricules = $_POST['matricules'] ?? [];
                $anneeId = intval($_POST['annee_id'] ?? 0);
                
                if (empty($matricules) || $anneeId <= 0) {
                    throw new Exception("Paramètres manquants pour la suppression de validation.");
                }
                
                $pdo->beginTransaction();
                
                // Supprimer les entrées de validation manuelle pour les étudiants et l'année
                // Par exemple : DELETE FROM eligibilite_manuelle WHERE num_etu = ? AND id_Ac = ?
                /*
                $stmtDeleteValidation = $pdo->prepare("DELETE FROM eligibilite_manuelle WHERE num_etu = ? AND id_Ac = ?");
                foreach ($matricules as $matricule) {
                    $stmtDeleteValidation->execute([$matricule, $anneeId]);
                }
                */
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => count($matricules) . ' validation(s) d\'éligibilité supprimée(s).'
                ]);
                break;

            case 'get_etudiant_details': // Pour la modal de détails (comme dans liste_etudiants.php)
                $numEtu = $_POST['num_etu'];
                $query = "
                    SELECT 
                        e.num_etu,
                        e.nom_etu,
                        e.prenoms_etu,
                        e.dte_naiss_etu,
                        e.email_etu,
                        e.lieu_naissance,
                        e.telephone,
                        u.login_util,
                        ne.lib_niv_etu,
                        f.lib_filiere,
                        aa.date_deb,
                        aa.date_fin,
                        CONCAT(YEAR(aa.date_deb), '-', YEAR(aa.date_fin)) as annee_academique,
                        i.dte_insc,
                        i.montant_insc,
                        -- Ajout des données d'éligibilité pour la modal de détails
                        COALESCE(SUM(CASE WHEN ev.note >= 10 THEN ec.credit_ECUE ELSE 0 END), 0) as credits_obtenus,
                        vp.montant_total,
                        vp.total_verse,
                        vp.reste_a_payer,
                        CASE 
                            WHEN COALESCE(SUM(CASE WHEN ev.note >= 10 THEN ec.credit_ECUE ELSE 0 END), 0) >= 60 AND (vp.reste_a_payer IS NULL OR vp.reste_a_payer <= 0)
                            THEN 'ELIGIBLE' ELSE 'NON_ELIGIBLE'
                        END as statut_eligibilite,
                        CASE 
                            WHEN COALESCE(SUM(CASE WHEN ev.note >= 10 THEN ec.credit_ECUE ELSE 0 END), 0) < 60 THEN CONCAT('Crédits insuffisants (', COALESCE(SUM(CASE WHEN ev.note >= 10 THEN ec.credit_ECUE ELSE 0 END), 0), '/', 60, ')')
                            WHEN vp.reste_a_payer > 0 THEN CONCAT('Scolarité en retard (', COALESCE(vp.reste_a_payer, 0), ' FCFA)')
                            ELSE ''
                        END as raisons_non_eligibilite,
                        CASE 
                            WHEN e.fk_id_util IS NOT NULL AND u.login_util IS NOT NULL AND u.login_util != '' THEN 'Oui'
                            ELSE 'Non'
                        END as a_identifiants
                    FROM etudiant e
                    LEFT JOIN utilisateur u ON e.fk_id_util = u.id_util
                    INNER JOIN inscrire i ON e.num_etu = i.fk_num_etu
                    LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
                    LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
                    LEFT JOIN année_academique aa ON i.fk_id_Ac = aa.id_Ac
                    LEFT JOIN evaluer ev ON e.num_etu = ev.fk_num_etu AND ev.fk_id_Ac = i.fk_id_Ac
                    LEFT JOIN ecue ec ON ev.fk_id_ECUE = ec.id_ECUE
                    LEFT JOIN vue_paiements_etudiants vp ON e.num_etu = vp.num_etu AND vp.id_Ac = i.fk_id_Ac
                    WHERE e.num_etu = ?
                    GROUP BY e.num_etu, e.nom_etu, e.prenoms_etu, e.dte_naiss_etu, e.email_etu, e.lieu_naissance, e.telephone, u.login_util, ne.lib_niv_etu, f.lib_filiere, aa.date_deb, aa.date_fin, aa.id_Ac, i.dte_insc, i.montant_insc, vp.montant_total, vp.total_verse, vp.reste_a_payer
                ";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$numEtu]);
                $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
    
                if ($etudiant) {
                    echo json_encode(['success' => true, 'data' => $etudiant]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Étudiant non trouvé']);
                }
            break;

            case 'exporter_data': // Export générique (PDF, Excel, CSV)
                $format = $_POST['format'] ?? 'excel';
                $numEtudiants = json_decode($_POST['num_etudiants'] ?? '[]', true); // Peut être vide pour "tous"
                $anneeId = intval($_POST['annee_id'] ?? 0);
                $niveauId = intval($_POST['niveau_id'] ?? 0);
                $statutFiltre = $_POST['statut_filtre'] ?? 'tous'; // "ELIGIBLE", "NON_ELIGIBLE", "tous"

                if ($anneeId <= 0 || $niveauId <= 0) {
                    throw new Exception("Paramètres d'année et de niveau manquants pour l'export.");
                }

                $eligibilityInfo = getEligibilityDetails($pdo, $anneeId, $niveauId);
                $dataToExport = $eligibilityInfo['data'];
                $creditsRequis = $eligibilityInfo['credits_requis']; // Use this value for exports

                // Apply status filter if specified
                if ($statutFiltre !== 'tous') {
                    $dataToExport = array_filter($dataToExport, function($r) use ($statutFiltre) {
                        return $r['statut_eligibilite'] === $statutFiltre;
                    });
                }
                
                // Apply selection filter if specific students were chosen
                if (!empty($numEtudiants)) {
                    $dataToExport = array_filter($dataToExport, function($r) use ($numEtudiants) {
                        return in_array($r['num_etu'], $numEtudiants);
                    });
                }
                
                // Data transformation for export
                $exportableData = [];
                $headers = [
                    'Matricule', 'Nom', 'Prénom', 'Filière', 'Niveau', 'Année Académique',
                    'Crédits Obtenus', 'Crédits Requis', 'Statut Crédits',
                    'Montant Total Scolarité', 'Total Payé', 'Reste à Payer', 'Statut Paiement',
                    'Statut Eligibilité', 'Raisons Non-Eligibilité'
                ];

                foreach ($dataToExport as $row) {
                    $creditsStatus = ($row['credits_obtenus'] >= $creditsRequis) ? 'Suffisants' : 'Insuffisants';
                    $paymentStatus = 'N/A';
                    if ($eligibilityInfo['vue_paiements_exists']) {
                        $paymentStatus = (isset($row['reste_a_payer']) && $row['reste_a_payer'] <= 0) ? 'À jour' : 'En retard';
                    }

                    $exportableData[] = [
                        (string)($row['num_etu'] ?? 'N/A'),
                        (string)($row['nom_etu'] ?? ''),
                        (string)($row['prenoms_etu'] ?? ''),
                        (string)($row['lib_filiere'] ?? 'N/A'),
                        (string)($row['lib_niv_etu'] ?? 'N/A'),
                        (string)($row['annee_academique'] ?? 'N/A'),
                        (string)($row['credits_obtenus'] ?? 0),
                        (string)($creditsRequis),
                        (string)($creditsStatus),
                        (string)(isset($row['montant_total']) ? number_format($row['montant_total'], 2, ',', ' ') . ' FCFA' : 'N/A'),
                        (string)(isset($row['total_verse']) ? number_format($row['total_verse'], 2, ',', ' ') . ' FCFA' : 'N/A'),
                        (string)(isset($row['reste_a_payer']) ? number_format($row['reste_a_payer'], 2, ',', ' ') . ' FCFA' : 'N/A'),
                        (string)($paymentStatus),
                        (string)($row['statut_eligibilite'] === 'ELIGIBLE' ? 'Éligible' : 'Non éligible'),
                        (string)($row['raisons_non_eligibilite'] ?? '')
                    ];
                }

                // Instead of direct file generation, return data or a flag to trigger client-side export
                echo json_encode([
                    'success' => true,
                    'message' => "Données prêtes pour l'export " . strtoupper($format),
                    'export_data' => ['headers' => $headers, 'rows' => $exportableData],
                    'format' => $format,
                    'filename' => "eligibilite_{$anneeId}_{$niveauId}_{$statutFiltre}_" . date('Ymd_His')
                ]);
            break;
                
            default:
                throw new Exception("Action non reconnue");
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
    <title>SYGECOS - Vérification d'éligibilité</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Reprise des styles de base identiques */
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
        .topbar { height: var(--topbar-height); background: var(--white); border-bottom: 1px solid var(--gray-200); padding: 0 var(--space-6); display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; }

        .page-content { padding: var(--space-6); }
        .page-header { margin-bottom: var(--space-8); }
        .page-title-main { font-size: var(--text-3xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--space-2); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-lg); }

        .form-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); margin-bottom: var(--space-6); }
        .form-card-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-6); border-bottom: 1px solid var(--gray-200); padding-bottom: var(--space-4); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-6); }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: var(--text-sm); font-weight: 500; color: var(--gray-700); margin-bottom: var(--space-2); }
        .form-group input, .form-group select { padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); font-size: var(--text-base); color: var(--gray-800); transition: all var(--transition-fast); }
        .form-group input:disabled { background-color: var(--gray-100); color: var(--gray-500); cursor: not-allowed; }

        .btn { padding: var(--space-3) var(--space-5); border-radius: var(--radius-md); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover { background-color: var(--gray-300); }
        .btn-success { background-color: var(--success-500); color: white; } .btn-success:hover { background-color: var(--success-600); }
        .btn-warning { background-color: var(--warning-500); color: white; } 
        .btn-sm { padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }

        /* === DASHBOARD ÉLIGIBILITÉ === */
        .eligibility-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-6); margin-bottom: var(--space-8); }
        .eligibility-card { background: var(--white); border-radius: var(--radius-xl); padding: var(--space-6); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); text-align: center; position: relative; }
        .eligibility-card.eligible { border-left: 4px solid var(--success-500); }
        .eligibility-card.non-eligible { border-left: 4px solid var(--error-500); }
        .eligibility-card.total { border-left: 4px solid var(--accent-500); }
        .eligibility-card.taux { border-left: 4px solid var(--warning-500); }

        .eligibility-icon { font-size: var(--text-2xl); margin-bottom: var(--space-3); }
        .eligibility-value { font-size: var(--text-3xl); font-weight: 700; margin: var(--space-2) 0; }
        .eligibility-label { color: var(--gray-600); font-size: var(--text-base); }

        .eligible .eligibility-icon, .eligible .eligibility-value { color: var(--success-500); }
        .non-eligible .eligibility-icon, .non-eligible .eligibility-value { color: var(--error-500); }
        .total .eligibility-icon, .total .eligibility-value { color: var(--accent-500); }
        .taux .eligibility-icon, .taux .eligibility-value { color: var(--warning-500); }

        /* === TABLEAU ÉLIGIBILITÉ === */
        .eligibility-table { width: 100%; border-collapse: collapse; margin-top: var(--space-4); }
        .eligibility-table th, .eligibility-table td { padding: var(--space-3); border-bottom: 1px solid var(--gray-200); text-align: left; }
        .eligibility-table th { background-color: var(--gray-50); font-weight: 600; color: var(--gray-700); font-size: var(--text-sm); position: sticky; top: 0; }
        .eligibility-table tbody tr:hover { background-color: var(--gray-50); }

        .status-badge { padding: var(--space-1) var(--space-3); border-radius: var(--radius-md); font-size: var(--text-xs); font-weight: 600; text-align: center; text-transform: uppercase; }
        .status-badge.eligible { background: var(--secondary-100); color: var(--secondary-800); }
        .status-badge.non-eligible { background: #fecaca; color: #dc2626; }

        .credits-bar { width: 100%; height: 20px; background: var(--gray-200); border-radius: var(--radius-sm); overflow: hidden; position: relative; }
        .credits-progress { height: 100%; transition: width 0.3s ease; }
        .credits-progress.sufficient { background: var(--success-500); }
        .credits-progress.insufficient { background: var(--error-500); }
        .credits-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: var(--text-xs); font-weight: 600; color: white; text-shadow: 1px 1px 1px rgba(0,0,0,0.5); }

        .payment-status { display: flex; align-items: center; gap: var(--space-2); }
        .payment-indicator { width: 12px; height: 12px; border-radius: 50%; }
        .payment-indicator.ok { background: var(--success-500); }
        .payment-indicator.late { background: var(--error-500); }
        .payment-indicator.unknown { background: var(--gray-400); }

        /* === FILTRES === */
        /* Updated from original, similar to liste_etudiants.php */
        .filter-bar { background: var(--gray-50); padding: var(--space-4); border-radius: var(--radius-lg); margin-bottom: var(--space-4); display: flex; justify-content: space-between; align-items: center; gap: var(--space-4); }
        .filter-group { display: flex; align-items: center; gap: var(--space-3); }
        .filter-group label { font-size: var(--text-sm); font-weight: 500; color: var(--gray-700); white-space: nowrap; }
        .filter-group select { padding: var(--space-2); border: 1px solid var(--gray-300); border-radius: var(--radius-sm); font-size: var(--text-sm); }
        
        /* === ACTIONS === */
        .actions-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-4); flex-wrap: wrap; gap: var(--space-3); }
        .bulk-actions { display: flex; align-items: center; gap: var(--space-3); }
        .export-actions { display: flex; gap: var(--space-2); flex-wrap: wrap; }

        /* Checkbox personnalisé */
        .checkbox-container { display: block; position: relative; padding-left: 25px; cursor: pointer; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
        .checkbox-container input { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
        .checkmark { position: absolute; top: 50%; left: 0; transform: translateY(-50%); height: 18px; width: 18px; background-color: var(--gray-200); border-radius: var(--radius-sm); transition: all var(--transition-fast); border: 1px solid var(--gray-300); }
        .checkbox-container input:checked ~ .checkmark { background-color: var(--accent-600); border-color: var(--accent-600); }
        .checkmark:after { content: ""; position: absolute; display: none; }
        .checkbox-container input:checked ~ .checkmark:after { display: block; }
        .checkbox-container .checkmark:after { left: 6px; top: 2px; width: 5px; height: 10px; border: solid white; border-width: 0 3px 3px 0; transform: rotate(45deg); }

        /* Messages d'alerte */
        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); display: none; }
        .alert.success { background-color: var(--secondary-50); color: var(--secondary-600); border: 1px solid var(--secondary-100); }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }
        .alert.warning { background-color: #fffbeb; color: #92400e; border: 1px solid #fed7aa; }

        .loading { opacity: 0.6; pointer-events: none; }
        .spinner { width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Unified Message Modal Styles (from gestion_Ecue.php) */
        .message-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .message-modal-content {
            background: var(--white);
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

        .message-icon.success { color: var(--success-500); }
        .message-icon.error { color: var(--error-500); }
        .message-icon.warning { color: var(--warning-500); }
        .message-icon.info { color: var(--info-500); }

        .message-title {
            font-size: var(--text-xl);
            font-weight: 600;
            margin-bottom: var(--space-2);
        }

        .message-text {
            font-size: var(--text-base);
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

        /* Modal Details */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 10000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.6); /* Darker overlay */
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background-color: #fefefe;
            padding: var(--space-8); /* More padding */
            border-radius: var(--radius-2xl); /* More rounded corners */
            box-shadow: var(--shadow-xl);
            width: 95%; /* Wider on smaller screens */
            max-width: 700px;
            max-height: 90vh; /* Limit height to viewport */
            overflow-y: auto; /* Scroll if content overflows */
            position: relative;
            transform: translateY(-50px);
            transition: transform 0.3s ease;
            box-sizing: border-box; /* Include padding in width/height */
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: var(--space-4);
            margin-bottom: var(--space-6);
        }

        .modal-header h2 {
            font-size: var(--text-2xl);
            color: var(--gray-900);
            font-weight: 700;
        }

        .modal-close {
            color: var(--gray-500);
            font-size: var(--text-3xl); /* Larger close icon */
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s ease;
            line-height: 1; /* Align vertically */
        }

        .modal-close:hover,
        .modal-close:focus {
            color: var(--gray-800);
            text-decoration: none;
        }

        .modal-body {
            padding-bottom: var(--space-6);
        }

        .detail-group {
            margin-bottom: var(--space-6); /* Space between groups */
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
            background-color: var(--gray-50);
        }

        .detail-group-title {
            font-size: var(--text-lg);
            font-weight: 600;
            color: var(--primary-700);
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-2);
            border-bottom: 1px dashed var(--gray-300);
        }

        .detail-item {
            display: flex;
            flex-wrap: wrap; /* Allow wrapping on small screens */
            margin-bottom: var(--space-3); /* More space between items */
            font-size: var(--text-base);
            align-items: baseline;
        }

        .detail-item strong {
            flex-basis: 180px; /* Wider label column */
            color: var(--gray-700);
            font-weight: 600;
            padding-right: var(--space-2);
            line-height: 1.5;
        }

        .detail-item span {
            flex: 1;
            color: var(--gray-800);
            line-height: 1.5;
        }
        
        @media (max-width: 500px) {
            .detail-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .detail-item strong {
                flex-basis: auto;
                width: 100%;
                margin-bottom: var(--space-1);
            }
        }

        .modal-footer {
            border-top: 1px solid var(--gray-200);
            padding-top: var(--space-4);
            text-align: right;
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
        }

        #etudiantDetailsContent {
            /* No direct padding here, handled by .detail-group */
            /* Removed background-color: #fefefe; as it's set on modal-content already */
        }
        
        /* Filter Modal */
        .filter-modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.5); /* Black w/ opacity */
            align-items: center; /* Center vertically */
            justify-content: center; /* Center horizontally */
        }

        .filter-modal-content {
            background-color: var(--white);
            margin: auto; /* Auto margin to center it */
            padding: var(--space-6);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            width: 90%; /* Responsive width */
            max-width: 500px; /* Max width for larger screens */
            position: relative; /* For closing button positioning */
        }

        .filter-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
            border-bottom: 1px solid var(--gray-200); /* Separator */
            padding-bottom: var(--space-3);
        }

        .filter-modal-title {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
        }

        .filter-modal-close {
            color: var(--gray-500);
            font-size: var(--text-2xl);
            font-weight: bold;
            cursor: pointer;
            border: none;
            background: none;
            padding: 0;
        }

        .filter-modal-close:hover,
        .filter-modal-close:focus {
            color: var(--gray-700);
            text-decoration: none;
            cursor: pointer;
        }

        .filter-group {
            margin-bottom: var(--space-4);
        }

        .filter-group label {
            display: block;
            margin-bottom: var(--space-2);
            font-weight: 500;
            color: var(--gray-700);
        }
        
        /* Specific styles for radio groups within filter dropdown */
        .filter-option-group {
            padding: var(--space-2) 0;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            max-height: 200px; /* Limit height for scrollable content */
            overflow-y: auto;
            background-color: var(--gray-50); /* Light background for the scrollable area */
        }

        .filter-option-group label {
            display: flex;
            align-items: center;
            padding: var(--space-2) var(--space-3);
            cursor: pointer;
            width: 100%;
            margin-bottom: 0; /* Override default label margin */
            font-weight: normal; /* Override default label font-weight */
            color: var(--gray-700);
        }
        .filter-option-group label:hover {
            background-color: var(--gray-100);
        }
        .filter-option-group input[type="radio"] {
            margin-right: var(--space-2); /* Space between radio and text */
            flex-shrink: 0; /* Prevent radio button from shrinking */
        }

        .filter-actions-dropdown {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
            margin-top: var(--space-6);
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
            .sidebar-title,
            .nav-text,
            .nav-section-title {
                opacity: 0;
                pointer-events: none;
            }
            .nav-link {
                justify-content: center;
            }
            /* Adjust topbar toggle icon */
            .sidebar-toggle .fa-bars {
                display: inline-block; /* Show bars icon by default on desktop for toggle */
            }
            .sidebar-toggle .fa-times {
                display: none;
            }
            .sidebar.collapsed {
                width: var(--sidebar-collapsed-width);
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
            /* When sidebar is open on mobile, show times */
            .sidebar.mobile-open + .topbar .sidebar-toggle .fa-bars {
                display: none;
            }
            .sidebar.mobile-open + .topbar .sidebar-toggle .fa-times {
                display: inline-block;
            }

            .eligibility-stats { grid-template-columns: 1fr; }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-4);
            }
            
            .actions-bar {
                width: 100%;
                justify-content: flex-start; /* Adjust to flex-start for stacking */
                flex-wrap: wrap; /* Allow wrapping */
                margin-top: var(--space-4); /* Add some space if it wraps */
            }
            
            .export-actions { /* Adjust export buttons for mobile */
                width: 100%;
                justify-content: space-around; /* Distribute buttons */
                margin-top: var(--space-3);
            }
            .export-actions .btn-sm {
                flex: 1; /* Make buttons take equal width */
            }

            /* Hide action text on smaller screens for action buttons */
            .action-text {
                display: none;
            }

            .form-grid {
                grid-template-columns: 1fr; /* Stack form inputs on mobile */
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: var(--space-4);
            }
            
            .form-card { /* Adjust padding for forms on smaller screens */
                padding: var(--space-4);
            }

            .eligibility-table th, .eligibility-table td {
                padding: var(--space-2); /* Smaller padding for table cells */
            }
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
                    <div>
                        <h1 class="page-title-main">Vérification d'éligibilité</h1>
                        <p class="page-subtitle">Contrôle des conditions d'éligibilité des étudiants (crédits & scolarité)</p>
                    </div>
                </div>

                <div id="alertMessage" class="alert"></div>

                <div class="form-card">
                    <h3 class="form-card-title">Sélectionner l'Année et le Niveau</h3>
                    <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
                        <div class="form-group">
                            <label for="annee_id">Année académique (active par défaut)</label>
                            <input type="text" id="annee_display" value="<?php echo htmlspecialchars($anneeActiveInfo['annee_libelle'] ?? 'Non définie'); ?>" disabled>
                            <input type="hidden" id="annee_id_hidden" value="<?php echo htmlspecialchars($anneeActiveInfo['id_Ac'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="niveau_id">Niveau d'étude <span style="color: var(--error-500);">*</span></label>
                            <select id="niveau_id" name="niveau_id" required>
                                <option value="">Sélectionner un niveau</option>
                                <?php foreach ($niveauxEtude as $niveau): ?>
                                    <option value="<?php echo htmlspecialchars($niveau['id_niv_etu']); ?>">
                                        <?php echo htmlspecialchars($niveau['lib_niv_etu']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: span 2; display: flex; justify-content: flex-end; align-items: end;">
                            <button type="button" class="btn btn-primary" id="verifierBtn">
                                <i class="fas fa-search"></i> Afficher les résultats
                            </button>
                        </div>
                    </div>
                </div>

                <div id="statsSection" class="eligibility-stats" style="display: none;">
                    <div class="eligibility-card total">
                        <div class="eligibility-icon"><i class="fas fa-users"></i></div>
                        <div class="eligibility-value" id="totalEtudiants">0</div>
                        <div class="eligibility-label">Total étudiants</div>
                    </div>
                    <div class="eligibility-card eligible">
                        <div class="eligibility-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="eligibility-value" id="totalEligibles">0</div>
                        <div class="eligibility-label">Éligibles</div>
                    </div>
                    <div class="eligibility-card non-eligible">
                        <div class="eligibility-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="eligibility-value" id="totalNonEligibles">0</div>
                        <div class="eligibility-label">Non éligibles</div>
                    </div>
                    <div class="eligibility-card taux">
                        <div class="eligibility-icon"><i class="fas fa-percentage"></i></div>
                        <div class="eligibility-value" id="tauxEligibilite">0%</div>
                        <div class="eligibility-label">Taux d'éligibilité</div>
                    </div>
                </div>

                <div class="form-card" id="resultsSection" style="display: none;">
                    <div class="table-header">
                        <h3 class="table-title">Résultats d'éligibilité</h3>
                        <div class="table-actions">
                            <input type="text" id="searchFilter" class="search-input" placeholder="Rechercher par nom, matricule..." style="min-width: 250px;">
                            <button class="btn btn-secondary" id="filterButton">
                                <i class="fas fa-filter"></i> <span id="filterButtonText">Filtres</span>
                            </button>
                            <button class="btn btn-primary" id="validateSelectedBtn" disabled>
                                <i class="fas fa-check"></i> Valider sélection (<span id="selectedCount">0</span>)
                            </button>
                            <button class="btn btn-danger" id="unvalidateSelectedBtn" disabled>
                                <i class="fas fa-times"></i> Annuler validation (<span id="unselectedCount">0</span>)
                            </button>
                        </div>
                    </div>

                    <div class="actions-bar" style="border-bottom: 1px solid var(--gray-200); padding-bottom: var(--space-4); margin-bottom: var(--space-4);">
                        <div class="bulk-actions">
                            <label class="checkbox-container">
                                <input type="checkbox" id="selectAll">
                                <span class="checkmark"></span>
                                Tout sélectionner
                            </label>
                        </div>
                        <div class="export-actions">
                            <button class="btn btn-success btn-sm" id="exportEligiblesExcelBtn" title="Exporter les éligibles en Excel">
                                <i class="fas fa-file-excel"></i> Éligibles
                            </button>
                            <button class="btn btn-warning btn-sm" id="exportNonEligiblesExcelBtn" title="Exporter les non éligibles en Excel">
                                <i class="fas fa-file-excel"></i> Non Éligibles
                            </button>
                            <button class="btn btn-secondary btn-sm" id="exportFullPdfBtn" title="Exporter le rapport complet en PDF">
                                <i class="fas fa-file-pdf"></i> Rapport PDF
                            </button>
                        </div>
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table class="eligibility-table" id="eligibilityTable">
                            <thead>
                                <tr>
                                    <th width="50px">
                                        <label class="checkbox-container">
                                            <input type="checkbox" id="selectAllHeader">
                                            <span class="checkmark"></span>
                                        </label>
                                    </th>
                                    <th>Matricule</th>
                                    <th>Nom & Prénom</th>
                                    <th>Filière</th>
                                    <th>Niveau</th>
                                    <th>Crédits Obtenus</th>
                                    <th>Statut Paiement</th>
                                    <th>Reste à Payer</th>
                                    <th>Statut Eligibilité</th>
                                    <th>Raisons Non-Eligibilité</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="etudiantDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Détails de l'Étudiant</h2>
                <span class="modal-close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="etudiantDetailsContent">
                <div class="detail-group">
                    <h3 class="detail-group-title">Informations Personnelles</h3>
                    <div class="detail-item">
                        <strong>N° Étudiant:</strong> <span id="detailNumEtu"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Nom & Prénoms:</strong> <span id="detailNomPrenoms"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Email:</strong> <span id="detailEmail"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Date de Naissance:</strong> <span id="detailDateNaissance"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Lieu de Naissance:</strong> <span id="detailLieuNaissance"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Téléphone:</strong> <span id="detailTelephone"></span>
                    </div>
                </div>

                <div class="detail-group">
                    <h3 class="detail-group-title">Informations Académiques & Eligibilité</h3>
                    <div class="detail-item">
                        <strong>Niveau d'Étude:</strong> <span id="detailNiveau"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Filière:</strong> <span id="detailFiliere"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Année Académique:</strong> <span id="detailAnneeAcademique"></span>
                    </div>
                     <div class="detail-item">
                        <strong>Crédits Obtenus:</strong> <span id="detailCreditsObtenus"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Statut Paiement:</strong> <span id="detailStatutPaiement"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Reste à Payer:</strong> <span id="detailResteAPayer"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Statut Éligibilité:</strong> <span id="detailStatutEligibilite"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Raisons Non-Eligibilité:</strong> <span id="detailRaisonsNonEligibilite"></span>
                    </div>
                </div>

                <div class="detail-group">
                    <h3 class="detail-group-title">Informations d'Inscription & Connexion</h3>
                    <div class="detail-item">
                        <strong>Date d'Inscription:</strong> <span id="detailDateInscription"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Montant Inscription:</strong> <span id="detailMontantInscription"></span>
                    </div>
                    <div class="detail-item">
                        <strong>Identifiants de Connexion:</strong> <span id="detailIdentifiants"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal()" class="btn btn-secondary">
                    Fermer
                </button>
                <button onclick="downloadStudentDetailsPdf()" class="btn btn-primary">
                    <i class="fas fa-file-pdf"></i> Télécharger PDF
                </button>
            </div>
        </div>
    </div>

    <div class="filter-modal" id="filterModal">
        <div class="filter-modal-content">
            <div class="filter-modal-header">
                <h3 class="filter-modal-title">Filtres et Tri des Étudiants</h3>
                <button class="filter-modal-close" id="closeFilterModal">&times;</button>
            </div>
            <div class="filter-group">
                <label>Trier par:</label>
                <div class="filter-option-group">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="default" checked>
                            <i class="fas fa-list"></i> Ordre initial
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="name-asc">
                            <i class="fas fa-sort-alpha-down"></i> Nom (A-Z)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="name-desc">
                            <i class="fas fa-sort-alpha-up"></i> Nom (Z-A)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="num-asc">
                            <i class="fas fa-sort-numeric-down"></i> Matricule (croissant)
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="sort_radio" value="num-desc">
                            <i class="fas fa-sort-numeric-up"></i> Matricule (décroissant)
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="filter-group">
                <label>Filtrer par Filière:</label>
                <div class="filter-option-group" id="filiereFilterRadioGroup">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="filiere_filter_radio" value="all_filieres" checked>
                            Toutes les Filières
                        </label>
                    </div>
                    <?php foreach ($filieres as $filiere): ?>
                        <div class="filter-option radio-group">
                            <label>
                                <input type="radio" name="filiere_filter_radio" value="<?php echo htmlspecialchars($filiere['id_filiere']); ?>">
                                <?php echo htmlspecialchars($filiere['lib_filiere']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="filter-group">
                <label>Filtrer par Niveau d'Étude:</label>
                <div class="filter-option-group" id="niveauFilterRadioGroup">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="niveau_filter_radio" value="all_niveaux" checked>
                            Tous les Niveaux
                        </label>
                    </div>
                    <?php foreach ($niveauxEtude as $niveau): ?>
                        <div class="filter-option radio-group">
                            <label>
                                <input type="radio" name="niveau_filter_radio" value="<?php echo htmlspecialchars($niveau['id_niv_etu']); ?>">
                                <?php echo htmlspecialchars($niveau['lib_niv_etu']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="filter-group">
                <label>Filtrer par Année Académique:</label>
                <div class="filter-option-group" id="anneeFilterRadioGroup">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="annee_filter_radio" value="all_annees" checked>
                            Toutes les Années
                        </label>
                    </div>
                    <?php foreach ($anneesAcademiques as $annee): ?>
                        <div class="filter-option radio-group">
                            <label>
                                <input type="radio" name="annee_filter_radio" value="<?php echo htmlspecialchars($annee['id_Ac']); ?>">
                                <?php echo htmlspecialchars(formatAnneeAcademique($annee['date_deb'], $annee['date_fin'])); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="filter-group">
                <label>Filtrer par Statut d'Éligibilité:</label>
                <div class="filter-option-group" id="eligibilityFilterRadioGroup">
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="eligibility_filter_radio" value="all_status" checked>
                            Tous les Statuts
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="eligibility_filter_radio" value="ELIGIBLE">
                            Éligibles seulement
                        </label>
                    </div>
                    <div class="filter-option radio-group">
                        <label>
                            <input type="radio" name="eligibility_filter_radio" value="NON_ELIGIBLE">
                            Non éligibles seulement
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="filter-actions-dropdown">
                <button class="btn btn-secondary" id="resetFilterModalBtn">Réinitialiser</button>
                <button class="btn btn-primary" id="applyFilterModalBtn">Appliquer</button>
            </div>
        </div>
    </div>


    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        // Initialiser jsPDF
        window.jsPDF = window.jspdf.jsPDF;

        let allEligibilityData = []; // Full dataset from the server with eligibility info
        let selectedEtudiants = new Set(); // Stores num_etu of selected students for bulk actions
        let currentStudentDetails = null; // Store details of the currently viewed student for PDF export
        let currentCreditsRequis = 60; // Default or fetched from backend if configured

        // Filter and Sort states
        let currentSortType = 'default';
        let currentFiliereFilter = 'all_filieres';
        let currentNiveauFilter = 'all_niveaux';
        let currentAnneeFilter = 'all_annees';
        let currentEligibilityStatusFilter = 'all_status'; // New filter for eligibility status

        // DOM Elements
        const anneeIdHidden = document.getElementById('annee_id_hidden');
        const niveauIdSelect = document.getElementById('niveau_id');
        const verifierBtn = document.getElementById('verifierBtn');
        const statsSection = document.getElementById('statsSection');
        const resultsSection = document.getElementById('resultsSection');
        const eligibilityTableBody = document.querySelector('#eligibilityTable tbody');

        const selectAllCheckbox = document.getElementById('selectAll');
        const selectAllHeaderCheckbox = document.getElementById('selectAllHeader');
        const validateSelectedBtn = document.getElementById('validateSelectedBtn');
        const unvalidateSelectedBtn = document.getElementById('unvalidateSelectedBtn');
        const selectedCountSpan = document.getElementById('selectedCount');
        const unselectedCountSpan = document.getElementById('unselectedCount');

        const searchFilterInput = document.getElementById('searchFilter');
        const filterButton = document.getElementById('filterButton');
        const filterButtonText = document.getElementById('filterButtonText');

        const exportEligiblesExcelBtn = document.getElementById('exportEligiblesExcelBtn');
        const exportNonEligiblesExcelBtn = document.getElementById('exportNonEligiblesExcelBtn');
        const exportFullPdfBtn = document.getElementById('exportFullPdfBtn');

        // Modals
        const messageModal = document.getElementById('messageModal');
        const messageTitle = document.getElementById('messageTitle');
        const messageText = document.getElementById('messageText');
        const messageIcon = document.getElementById('messageIcon');
        const messageButton = document.getElementById('messageButton');
        const messageClose = document.getElementById('messageClose');

        const etudiantDetailsModal = document.getElementById('etudiantDetailsModal');
        const filterModal = document.getElementById('filterModal');
        const closeFilterModalBtn = document.getElementById('closeFilterModal');
        const applyFilterModalBtn = document.getElementById('applyFilterModalBtn');
        const resetFilterModalBtn = document.getElementById('resetFilterModalBtn');

        // Sidebar elements for responsiveness (from previous code)
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');


        // --- Utility Functions ---

        function showAlert(message, type = 'info', title = null) {
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

        function showLoading(show) {
            const overlay = document.getElementById('loadingOverlay');
            overlay.style.display = show ? 'flex' : 'none';
        }

        function formatDate(dateStr) {
            if (!dateStr || dateStr === '0000-00-00' || dateStr.includes('N/A')) return 'N/A';
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return 'N/A';
            return date.toLocaleDateString('fr-FR');
        }

        function formatMontant(montant) {
            return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(montant) + ' FCFA';
        }


        // --- Main Eligibility Logic ---

        verifierBtn.addEventListener('click', async function() {
            const anneeId = anneeIdHidden.value;
            const niveauId = niveauIdSelect.value;
            
            if (!anneeId || !niveauId) {
                showAlert('Veuillez sélectionner une année académique et un niveau d\'étude.', 'error');
                return;
            }
            
            const btn = this;
            const originalText = btn.innerHTML;
            
            try {
                btn.innerHTML = '<div class="spinner"></div> Chargement...';
                btn.disabled = true;
                
                const result = await makeAjaxRequest({
                    action: 'get_eligibilite_data',
                    annee_id: anneeId,
                    niveau_id: niveauId
                });
                
                if (result.success) {
                    allEligibilityData = result.data; // Store the full dataset
                    currentCreditsRequis = result.credits_requis; // Store credits required from backend
                    
                    afficherStatistiques(result.stats);
                    applyFiltersAndSort(); // Apply current filters and display table
                    
                    statsSection.style.display = 'grid'; // Display as grid if data present
                    resultsSection.style.display = 'block';
                    
                    if (!result.vue_paiements_exists) {
                        showAlert('Attention: La vue `vue_paiements_etudiants` n\'existe pas. Les informations de paiement seront indisponibles.', 'warning');
                    }
                    if (allEligibilityData.length === 0) {
                         showAlert('Aucun étudiant trouvé pour l\'année et le niveau sélectionnés.','info');
                    }
                } else {
                    showAlert(result.message, 'error');
                    statsSection.style.display = 'none';
                    resultsSection.style.display = 'none';
                }
                
            } catch (error) {
                showAlert('Erreur lors de la récupération des données d\'éligibilité.', 'error');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        });

        function afficherStatistiques(stats) {
            document.getElementById('totalEtudiants').textContent = stats.total_etudiants;
            document.getElementById('totalEligibles').textContent = stats.eligibles;
            document.getElementById('totalNonEligibles').textContent = stats.non_eligibles;
            document.getElementById('tauxEligibilite').textContent = stats.taux_eligibilite + '%';
        }

        function renderEligibilityTable(dataToRender) {
            eligibilityTableBody.innerHTML = '';
            selectedEtudiants.clear();
            updateSelectionCounts();

            if (dataToRender.length === 0) {
                eligibilityTableBody.innerHTML = `
                    <tr>
                        <td colspan="11" class="empty-state">
                            <i class="fas fa-graduation-cap"></i>
                            <p>Aucun étudiant trouvé pour les critères sélectionnés.</p>
                        </td>
                    </tr>
                `;
                return;
            }

            dataToRender.forEach(etudiant => {
                const row = document.createElement('tr');
                row.setAttribute('data-num-etu', etudiant.num_etu);
                
                const creditsObtenus = parseInt(etudiant.credits_obtenus) || 0;
                const creditsProgress = Math.min((creditsObtenus / currentCreditsRequis) * 100, 100);
                const creditsClass = creditsObtenus >= currentCreditsRequis ? 'sufficient' : 'insufficient';
                
                let paymentStatusClass = 'unknown';
                let paymentStatusText = 'N/A';
                let resteAPayerText = 'N/A';

                if (etudiant.montant_total !== null && etudiant.montant_total !== undefined) {
                    const resteAPayer = parseFloat(etudiant.reste_a_payer) || 0;
                    paymentStatusClass = resteAPayer <= 0 ? 'ok' : 'late';
                    paymentStatusText = resteAPayer <= 0 ? 'À jour' : 'En retard';
                    resteAPayerText = formatMontant(resteAPayer);
                }

                const eligibilityBadgeClass = etudiant.statut_eligibilite === 'ELIGIBLE' ? 'eligible' : 'non-eligible';
                const eligibilityBadgeText = etudiant.statut_eligibilite === 'ELIGIBLE' ? 'Éligible' : 'Non éligible';

                row.innerHTML = `
                    <td>
                        <label class="checkbox-container">
                            <input type="checkbox" class="etudiant-checkbox" value="${etudiant.num_etu}">
                            <span class="checkmark"></span>
                        </label>
                    </td>
                    <td><strong>${etudiant.num_etu || 'N/A'}</strong></td>
                    <td>${etudiant.nom_etu || ''} ${etudiant.prenoms_etu || ''}</td>
                    <td>${etudiant.lib_filiere || 'N/A'}</td>
                    <td>${etudiant.lib_niv_etu || 'N/A'}</td>
                    <td>
                        <div class="credits-bar">
                            <div class="credits-progress ${creditsClass}" style="width: ${creditsProgress}%"></div>
                            <div class="credits-text">${creditsObtenus}/${currentCreditsRequis}</div>
                        </div>
                    </td>
                    <td>
                        <div class="payment-status">
                            <div class="payment-indicator ${paymentStatusClass}"></div>
                            <span>${paymentStatusText}</span>
                        </div>
                    </td>
                    <td>${resteAPayerText}</td>
                    <td>
                        <span class="status-badge ${eligibilityBadgeClass}">
                            ${eligibilityBadgeText}
                        </span>
                    </td>
                    <td style="max-width: 250px; font-size: var(--text-sm); color: var(--gray-600);">
                        ${etudiant.raisons_non_eligibilite || '-'}
                    </td>
                    <td>
                        <button onclick="showEtudiantDetails('${etudiant.num_etu}')" class="btn btn-sm btn-outline" title="Voir détails">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button onclick="validerIndividuel('${etudiant.num_etu}')" class="btn btn-sm btn-success" title="Valider manuellement">
                            <i class="fas fa-check"></i>
                        </button>
                        <button onclick="annulerValidationIndividuelle('${etudiant.num_etu}')" class="btn btn-sm btn-danger" title="Annuler validation manuelle">
                            <i class="fas fa-times"></i>
                        </button>
                    </td>
                `;
                eligibilityTableBody.appendChild(row);
            });
        }


        // --- Filters, Search, Sort ---

        searchFilterInput.addEventListener('input', applyFiltersAndSort);

        filterButton.addEventListener('click', function(event) {
            event.stopPropagation();
            filterModal.style.display = 'flex';
        });

        closeFilterModalBtn.addEventListener('click', function() {
            filterModal.style.display = 'none';
        });

        filterModal.addEventListener('click', function(e) {
            if (e.target === filterModal) {
                filterModal.style.display = 'none';
            }
        });

        applyFilterModalBtn.addEventListener('click', function() {
            currentSortType = document.querySelector('input[name="sort_radio"]:checked').value;
            currentFiliereFilter = document.querySelector('input[name="filiere_filter_radio"]:checked').value;
            currentNiveauFilter = document.querySelector('input[name="niveau_filter_radio"]:checked').value;
            currentAnneeFilter = document.querySelector('input[name="annee_filter_radio"]:checked').value;
            currentEligibilityStatusFilter = document.querySelector('input[name="eligibility_filter_radio"]:checked').value;
            
            searchFilterInput.value = ''; // Clear search when applying modal filters
            applyFiltersAndSort();
            filterModal.style.display = 'none';
        });

        resetFilterModalBtn.addEventListener('click', function() {
            currentSortType = 'default';
            currentFiliereFilter = 'all_filieres';
            currentNiveauFilter = 'all_niveaux';
            currentAnneeFilter = 'all_annees';
            currentEligibilityStatusFilter = 'all_status';
            searchFilterInput.value = '';

            document.querySelector('input[name="sort_radio"][value="default"]').checked = true;
            document.querySelector('input[name="filiere_filter_radio"][value="all_filieres"]').checked = true;
            document.querySelector('input[name="niveau_filter_radio"][value="all_niveaux"]').checked = true;
            document.querySelector('input[name="annee_filter_radio"][value="all_annees"]').checked = true;
            document.querySelector('input[name="eligibility_filter_radio"][value="all_status"]').checked = true;

            applyFiltersAndSort();
            filterModal.style.display = 'none';
            showAlert('Filtres et recherche réinitialisés.', 'info');
        });

        function applyFiltersAndSort() {
            let filteredAndSortedData = [...allEligibilityData];
            const searchTerm = searchFilterInput.value.toLowerCase();

            // 1. Apply Search
            if (searchTerm) {
                filteredAndSortedData = filteredAndSortedData.filter(etudiant => {
                    return (
                        (etudiant.nom_etu && etudiant.nom_etu.toLowerCase().includes(searchTerm)) ||
                        (etudiant.prenoms_etu && etudiant.prenoms_etu.toLowerCase().includes(searchTerm)) ||
                        (etudiant.num_etu && etudiant.num_etu.toString().toLowerCase().includes(searchTerm))
                    );
                });
            }

            // 2. Apply Filiere Filter
            if (currentFiliereFilter !== 'all_filieres') {
                filteredAndSortedData = filteredAndSortedData.filter(etudiant => etudiant.fk_id_filiere == currentFiliereFilter);
            }

            // 3. Apply Niveau Filter
            if (currentNiveauFilter !== 'all_niveaux') {
                filteredAndSortedData = filteredAndSortedData.filter(etudiant => etudiant.id_niv_etu == currentNiveauFilter);
            }

            // 4. Apply Annee Academique Filter (already filtered by backend query, but kept for consistency if needed in future for broader filtering)
            if (currentAnneeFilter !== 'all_annees') {
                 // This filter is mostly redundant if the initial data fetch is already for a specific year.
                 // However, if the main data fetch was for ALL years, this would be crucial.
                 // For now, it will filter within the already year-specific data.
                filteredAndSortedData = filteredAndSortedData.filter(etudiant => etudiant.id_Ac == currentAnneeFilter);
            }

            // 5. Apply Eligibility Status Filter
            if (currentEligibilityStatusFilter !== 'all_status') {
                filteredAndSortedData = filteredAndSortedData.filter(etudiant => etudiant.statut_eligibilite === currentEligibilityStatusFilter);
            }

            // 6. Apply Sorting
            filteredAndSortedData.sort((a, b) => {
                switch (currentSortType) {
                    case 'name-asc': 
                        return (a.nom_etu + ' ' + a.prenoms_etu).localeCompare(b.nom_etu + ' ' + b.prenoms_etu);
                    case 'name-desc':
                        return (b.nom_etu + ' ' + b.prenoms_etu).localeCompare(a.nom_etu + ' ' + a.prenoms_etu);
                    case 'num-asc':
                        return a.num_etu.localeCompare(b.num_etu); // Use localeCompare for string matricules
                    case 'num-desc':
                        return b.num_etu.localeCompare(a.num_etu);
                    case 'default':
                    default:
                        // No specific sort, maintain order from data fetch
                        return 0;
                }
            });

            renderEligibilityTable(filteredAndSortedData);
            updateFilterButtonTextDisplay();
        }

        function updateFilterButtonTextDisplay() {
            let activeFiltersCount = 0;
            if (currentSortType !== 'default') activeFiltersCount++;
            if (currentFiliereFilter !== 'all_filieres') activeFiltersCount++;
            if (currentNiveauFilter !== 'all_niveaux') activeFiltersCount++;
            if (currentAnneeFilter !== 'all_annees') activeFiltersCount++;
            if (currentEligibilityStatusFilter !== 'all_status') activeFiltersCount++;
            if (searchFilterInput.value.trim() !== '') activeFiltersCount++;

            if (activeFiltersCount > 0) {
                filterButtonText.textContent = `Filtres (${activeFiltersCount} actifs)`;
                filterButton.classList.add('btn-active-filter'); 
            } else {
                filterButtonText.textContent = 'Filtres';
                filterButton.classList.remove('btn-active-filter');
            }
        }


        // --- Selection & Bulk Actions ---

        // Attach event listeners to newly rendered rows
        eligibilityTableBody.addEventListener('change', function(event) {
            if (event.target.classList.contains('etudiant-checkbox')) {
                const checkbox = event.target;
                if (checkbox.checked) {
                    selectedEtudiants.add(checkbox.value);
                } else {
                    selectedEtudiants.delete(checkbox.value);
                }
                updateSelectionCounts();
            }
        });

        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('#eligibilityTable .etudiant-checkbox');
            checkboxes.forEach(cb => {
                if (!cb.disabled) { // Only toggle non-disabled checkboxes
                    cb.checked = this.checked;
                    if (this.checked) {
                        selectedEtudiants.add(cb.value);
                    } else {
                        selectedEtudiants.delete(cb.value);
                    }
                }
            });
            selectAllHeaderCheckbox.checked = this.checked;
            updateSelectionCounts();
        });

        selectAllHeaderCheckbox.addEventListener('change', function() {
            selectAllCheckbox.checked = this.checked;
            selectAllCheckbox.dispatchEvent(new Event('change')); // Trigger full select/deselect logic
        });

        function updateSelectionCounts() {
            const numSelected = selectedEtudiants.size;
            selectedCountSpan.textContent = numSelected;
            unselectedCountSpan.textContent = numSelected; // For now, same count for both
            
            validateSelectedBtn.disabled = numSelected === 0;
            unvalidateSelectedBtn.disabled = numSelected === 0;

            const totalVisibleCheckboxes = document.querySelectorAll('#eligibilityTable .etudiant-checkbox:not(:disabled)').length;
            selectAllCheckbox.indeterminate = numSelected > 0 && numSelected < totalVisibleCheckboxes;
            selectAllCheckbox.checked = numSelected === totalVisibleCheckboxes && totalVisibleCheckboxes > 0;
            selectAllHeaderCheckbox.indeterminate = selectAllCheckbox.indeterminate;
            selectAllHeaderCheckbox.checked = selectAllCheckbox.checked;
        }

        // --- Individual and Bulk Validation/Unvalidation ---

        async function validerIndividuel(matricule) {
            if (confirm(`Confirmer la validation manuelle d'éligibilité pour l'étudiant ${matricule} ?`)) {
                await sendValidationRequest([matricule], 'valider_eligibilite_manuelle', 'Éligibilité validée.');
            }
        }

        async function annulerValidationIndividuelle(matricule) {
             if (confirm(`Confirmer l'annulation de la validation manuelle d'éligibilité pour l'étudiant ${matricule} ?`)) {
                await sendValidationRequest([matricule], 'supprimer_eligibilite_validation', 'Validation annulée.');
            }
        }

        validateSelectedBtn.addEventListener('click', async function() {
            const selected = Array.from(selectedEtudiants);
            if (selected.length === 0) {
                showAlert('Aucun étudiant sélectionné à valider.', 'warning');
                return;
            }
            if (confirm(`Confirmer la validation manuelle d'éligibilité pour ${selected.length} étudiant(s) ?`)) {
                await sendValidationRequest(selected, 'valider_eligibilite_manuelle', 'Éligibilité validée en masse.');
            }
        });

        unvalidateSelectedBtn.addEventListener('click', async function() {
            const selected = Array.from(selectedEtudiants);
            if (selected.length === 0) {
                showAlert('Aucun étudiant sélectionné pour annuler la validation.', 'warning');
                return;
            }
            if (confirm(`Confirmer l'annulation de la validation manuelle d'éligibilité pour ${selected.length} étudiant(s) ?`)) {
                await sendValidationRequest(selected, 'supprimer_eligibilite_validation', 'Validation annulée en masse.');
            }
        });

        async function sendValidationRequest(matricules, actionType, successMessage) {
            const anneeId = anneeIdHidden.value;
            if (!anneeId) {
                showAlert('Année académique non définie.', 'error');
                return;
            }

            try {
                showLoading(true);
                const response = await makeAjaxRequest({
                    action: actionType,
                    matricules: JSON.stringify(matricules),
                    annee_id: anneeId,
                    commentaire: `Action depuis SYGECOS - ${new Date().toLocaleDateString()}`
                });

                if (response.success) {
                    showAlert(successMessage, 'success');
                    // Re-fetch and re-render data to reflect changes (e.g., if a manual validation flag is set in DB)
                    verifierBtn.click(); // Simulate click to reload data
                } else {
                    showAlert(response.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'opération.', 'error');
            } finally {
                showLoading(false);
            }
        }

        // --- Export Functions ---

        exportEligiblesExcelBtn.addEventListener('click', () => exportData('excel', [], 'ELIGIBLE'));
        exportNonEligiblesExcelBtn.addEventListener('click', () => exportData('excel', [], 'NON_ELIGIBLE'));
        exportFullPdfBtn.addEventListener('click', () => exportData('pdf', [], 'tous'));

        async function exportData(format, selectedMatricules, statutFiltre) {
            const anneeId = anneeIdHidden.value;
            const niveauId = niveauIdSelect.value;

            if (!anneeId || !niveauId) {
                showAlert('Veuillez d\'abord sélectionner une année et un niveau pour générer les données à exporter.', 'warning');
                return;
            }
            if (allEligibilityData.length === 0) {
                showAlert('Aucune donnée à exporter. Veuillez lancer une vérification d\'éligibilité d\'abord.', 'warning');
                return;
            }

            try {
                showLoading(true);
                const response = await makeAjaxRequest({
                    action: 'exporter_data',
                    format: format,
                    num_etudiants: JSON.stringify(selectedMatricules),
                    annee_id: anneeId,
                    niveau_id: niveauId,
                    statut_filtre: statutFiltre
                });

                if (response.success && response.export_data) {
                    const { headers, rows } = response.export_data;
                    const filename = response.filename;

                    if (rows.length === 0) {
                        showAlert('Aucune donnée à exporter avec les filtres spécifiés.', 'warning');
                        return;
                    }

                    if (format === 'excel') {
                        const wb = XLSX.utils.book_new();
                        const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
                        XLSX.utils.book_append_sheet(wb, ws, "Eligibilite");
                        XLSX.writeFile(wb, `${filename}.xlsx`);
                    } else if (format === 'pdf') {
                        const doc = new jsPDF('landscape');
                        doc.setFontSize(14);
                        doc.text(`Rapport d'éligibilité - ${anneeIdHidden.nextElementSibling.value} - Niveau ${niveauIdSelect.options[niveauIdSelect.selectedIndex].text}`, 14, 15);
                        doc.setFontSize(10);
                        doc.text(`Généré le: ${new Date().toLocaleDateString('fr-FR')}`, 14, 22);

                        doc.autoTable({
                            head: [headers],
                            body: rows,
                            startY: 25,
                            styles: { fontSize: 7, cellPadding: 1, overflow: 'linebreak' },
                            headStyles: { fillColor: [59, 130, 246], textColor: 255 },
                            margin: { left: 10, right: 10 },
                            didDrawPage: function (data) {
                                doc.setFontSize(8);
                                const pageCount = doc.internal.getNumberOfPages();
                                doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                            }
                        });
                        doc.save(`${filename}.pdf`);
                    } else if (format === 'csv') {
                        let csvContent = headers.map(h => `"${h.replace(/"/g, '""')}"`).join(";") + "\n";
                        rows.forEach(row => {
                            csvContent += row.map(cell => `"${(cell + '').replace(/"/g, '""')}"`).join(";") + "\n";
                        });
                        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                        const link = document.createElement("a");
                        link.setAttribute("href", URL.createObjectURL(blob));
                        link.setAttribute("download", `${filename}.csv`);
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    }
                    showAlert(`Export ${format.toUpperCase()} réussi !`, 'success');

                } else {
                    showAlert(response.message || 'Erreur lors de la préparation de l\'export.', 'error');
                }
            } catch (error) {
                console.error("Erreur d'export:", error);
                showAlert("Erreur lors de l'export des données.", 'error');
            } finally {
                showLoading(false);
            }
        }

        // --- Details Modal (adapted from liste_etudiants.php) ---

        const etudiantDetailsModalElement = document.getElementById('etudiantDetailsModal');

        async function showEtudiantDetails(numEtu) {
            try {
                showLoading(true);
                // Use a dedicated AJAX call to get detailed info for the modal
                // This call includes all fields, similar to liste_etudiants.php
                const response = await makeAjaxRequest({
                    action: 'get_etudiant_details',
                    num_etu: numEtu
                });
                const result = await response.json();

                if (result.success && result.data) {
                    currentStudentDetails = result.data; // Store for PDF export
                    const etudiant = currentStudentDetails;

                    document.getElementById('modalTitle').textContent = `Détails de ${etudiant.nom_etu || ''} ${etudiant.prenoms_etu || ''}`;
                    document.getElementById('detailNumEtu').textContent = etudiant.num_etu || 'N/A';
                    document.getElementById('detailNomPrenoms').textContent = `${etudiant.nom_etu || ''} ${etudiant.prenoms_etu || ''}`;
                    document.getElementById('detailEmail').textContent = etudiant.email_etu || 'N/A';
                    document.getElementById('detailDateNaissance').textContent = formatDate(etudiant.dte_naiss_etu);
                    document.getElementById('detailLieuNaissance').textContent = etudiant.lieu_naissance || 'N/A';
                    document.getElementById('detailTelephone').textContent = etudiant.telephone || 'N/A';
                    document.getElementById('detailNiveau').textContent = etudiant.lib_niv_etu || 'N/A';
                    document.getElementById('detailFiliere').textContent = etudiant.lib_filiere || 'N/A';
                    document.getElementById('detailAnneeAcademique').textContent = etudiant.annee_academique || 'N/A';
                    document.getElementById('detailDateInscription').textContent = formatDate(etudiant.dte_insc);
                    document.getElementById('detailMontantInscription').textContent = etudiant.montant_insc ? formatMontant(etudiant.montant_insc) : 'N/A';
                    document.getElementById('detailIdentifiants').textContent = etudiant.a_identifiants || 'N/A';

                    // Eligibility specific details in modal
                    document.getElementById('detailCreditsObtenus').textContent = `${etudiant.credits_obtenus}/${currentCreditsRequis}`;
                    document.getElementById('detailStatutPaiement').textContent = (etudiant.montant_total !== null && etudiant.montant_total !== undefined) ? (parseFloat(etudiant.reste_a_payer) <= 0 ? 'À jour' : 'En retard') : 'N/A';
                    document.getElementById('detailResteAPayer').textContent = (etudiant.montant_total !== null && etudiant.montant_total !== undefined) ? formatMontant(etudiant.reste_a_payer) : 'N/A';
                    document.getElementById('detailStatutEligibilite').textContent = etudiant.statut_eligibilite === 'ELIGIBLE' ? 'Éligible' : 'Non éligible';
                    document.getElementById('detailRaisonsNonEligibilite').textContent = etudiant.raisons_non_eligibilite || '-';


                    etudiantDetailsModalElement.classList.add('show');
                } else {
                    showAlert(result.message || 'Erreur lors du chargement des détails de l\'étudiant', 'error');
                }
            } catch (error) {
                console.error('Erreur AJAX pour les détails:', error);
                showAlert('Erreur de connexion lors du chargement des détails', 'error');
            } finally {
                showLoading(false);
            }
        }

        function closeModal() {
            etudiantDetailsModalElement.classList.remove('show');
            currentStudentDetails = null; // Clear details on close
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target == etudiantDetailsModalElement) {
                closeModal();
            }
        }

        function downloadStudentDetailsPdf() {
            if (!currentStudentDetails) {
                showAlert("Aucune donnée d'étudiant à exporter en PDF.", "warning");
                return;
            }

            showLoading(true);
            const etudiant = currentStudentDetails;
            const doc = new jsPDF();
            let currentY = 20;

            // Header
            doc.setFontSize(18);
            doc.text('Fiche Détaillée de l\'Étudiant', doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' });
            currentY += 10;
            doc.setFontSize(10);
            doc.text(`SYGECOS - Système de Gestion de Scolarité`, doc.internal.pageSize.getWidth() / 2, currentY, { align: 'center' });
            currentY += 15;

            // Student Name in a prominent spot
            doc.setFontSize(16);
            doc.setTextColor(59, 130, 246); // Accent color
            doc.text(`${etudiant.nom_etu || ''} ${etudiant.prenoms_etu || ''}`, 15, currentY);
            doc.line(15, currentY + 1, doc.internal.pageSize.getWidth() - 15, currentY + 1); // Underline
            currentY += 10;
            doc.setTextColor(0, 0, 0); // Reset color
            
            // Personal Information
            doc.setFontSize(14);
            doc.text('Informations Personnelles', 15, currentY);
            currentY += 7;

            doc.autoTable({
                startY: currentY,
                body: [
                    ['N° Étudiant:', etudiant.num_etu || 'N/A'],
                    ['Nom & Prénoms:', `${etudiant.nom_etu || ''} ${etudiant.prenoms_etu || ''}`],
                    ['Email:', etudiant.email_etu || 'N/A'],
                    ['Date de Naissance:', formatDate(etudiant.dte_naiss_etu)],
                    ['Lieu de Naissance:', etudiant.lieu_naissance || 'N/A'],
                    ['Téléphone:', etudiant.telephone || 'N/A']
                ],
                theme: 'grid',
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 50 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 },
                didDrawPage: function (data) {
                    // Footer
                    doc.setFontSize(8);
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            currentY = doc.autoTable.previous.finalY + 10;

            // Academic & Eligibility Information
            doc.setFontSize(14);
            doc.text('Informations Académiques & Eligibilité', 15, currentY);
            currentY += 7;

            doc.autoTable({
                startY: currentY,
                body: [
                    ['Niveau d\'Étude:', etudiant.lib_niv_etu || 'N/A'],
                    ['Filière:', etudiant.lib_filiere || 'N/A'],
                    ['Année Académique:', etudiant.annee_academique || 'N/A'],
                    ['Crédits Obtenus:', `${etudiant.credits_obtenus}/${currentCreditsRequis}`],
                    ['Statut Paiement:', (etudiant.montant_total !== null && etudiant.montant_total !== undefined) ? (parseFloat(etudiant.reste_a_payer) <= 0 ? 'À jour' : 'En retard') : 'N/A'],
                    ['Reste à Payer:', (etudiant.montant_total !== null && etudiant.montant_total !== undefined) ? formatMontant(etudiant.reste_a_payer) : 'N/A'],
                    ['Statut Éligibilité:', etudiant.statut_eligibilite === 'ELIGIBLE' ? 'Éligible' : 'Non éligible'],
                    ['Raisons Non-Eligibilité:', etudiant.raisons_non_eligibilite || '-']
                ],
                theme: 'grid',
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 50 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 },
                didDrawPage: function (data) {
                    // Footer
                    doc.setFontSize(8);
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            currentY = doc.autoTable.previous.finalY + 10;

            // Registration & Login Information
            doc.setFontSize(14);
            doc.text('Informations d\'Inscription & Connexion', 15, currentY);
            currentY += 7;

            doc.autoTable({
                startY: currentY,
                body: [
                    ['Date d\'Inscription:', formatDate(etudiant.dte_insc)],
                    ['Montant Inscription:', etudiant.montant_insc ? formatMontant(etudiant.montant_insc) : 'N/A'],
                    ['Identifiants de Connexion:', etudiant.a_identifiants || 'N/A']
                ],
                theme: 'grid',
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 50 }, 1: { cellWidth: 'auto' } },
                margin: { left: 15, right: 15 },
                didDrawPage: function (data) {
                    // Footer
                    doc.setFontSize(8);
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.text('Page ' + data.pageNumber + ' sur ' + pageCount, data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });

            doc.save(`fiche_etudiant_${etudiant.nom_etu.replace(/\s/g, '_')}_${etudiant.prenoms_etu.replace(/\s/g, '_')}.pdf`);
            showLoading(false);
            showAlert("Fiche étudiant PDF générée avec succès !", 'success');
        }


        // --- Initialization ---
        document.addEventListener('DOMContentLoaded', function() {
            initSidebar(); // Initialize sidebar responsiveness

            // Initial check if active year is defined
            if (!anneeIdHidden.value) {
                showAlert('Aucune année académique active trouvée. Veuillez en définir une dans la gestion des années académiques.', 'warning');
            }

            // Expose functions to global scope for inline onclicks
            window.showEtudiantDetails = showEtudiantDetails;
            window.validerIndividuel = validerIndividuel;
            window.annulerValidationIndividuelle = annulerValidationIndividuelle;
            window.closeModal = closeModal;
            window.downloadStudentDetailsPdf = downloadStudentDetailsPdf;
        });

        // Responsive Sidebar setup (copied from gestion_Ecue.php for consistency)
        function initSidebar() {
            if (sidebarToggle && sidebar && mainContent) {
                handleResponsiveLayout(); // Initial state based on window width
                
                sidebarToggle.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        sidebar.classList.toggle('mobile-open');
                        mobileMenuOverlay.classList.toggle('active');
                        // Toggle icon
                        const barsIcon = sidebarToggle.querySelector('.fa-bars');
                        const timesIcon = sidebarToggle.querySelector('.fa-times');
                        if (sidebar.classList.contains('mobile-open')) {
                            if (barsIcon) barsIcon.style.display = 'none';
                            if (timesIcon) timesIcon.style.display = 'inline-block';
                        } else {
                            if (barsIcon) barsIcon.style.display = 'inline-block';
                            if (timesIcon) timesIcon.style.display = 'none';
                        }
                    } else {
                        sidebar.classList.toggle('collapsed');
                        mainContent.classList.toggle('sidebar-collapsed');
                    }
                });
            }

            if (mobileMenuOverlay) {
                mobileMenuOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('mobile-open');
                    mobileMenuOverlay.classList.remove('active');
                    // Reset icon
                    const barsIcon = sidebarToggle.querySelector('.fa-bars');
                    const timesIcon = sidebarToggle.querySelector('.fa-times');
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                });
            }

            window.addEventListener('resize', handleResponsiveLayout);
        }

        // Responsive layout adjustments
        function handleResponsiveLayout() {
            const actionTexts = document.querySelectorAll('.action-text');
            const isMobile = window.innerWidth < 768;

            actionTexts.forEach(text => {
                text.style.display = isMobile ? 'none' : 'inline';
            });

            // Adjust sidebar state
            if (isMobile) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('sidebar-collapsed');
                sidebar.classList.remove('mobile-open'); // Ensure it's closed on resize to mobile
                mobileMenuOverlay.classList.remove('active'); // Hide overlay
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('sidebar-collapsed');
                sidebar.classList.remove('mobile-open'); // Ensure it's closed if was open on mobile and resized to desktop
                mobileMenuOverlay.classList.remove('active'); // Hide overlay
            }
            
            // Adjust sidebar toggle icon for mobile
            if (sidebarToggle) {
                const barsIcon = sidebarToggle.querySelector('.fa-bars');
                const timesIcon = sidebarToggle.querySelector('.fa-times');
                if (isMobile) {
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                } else {
                    if (barsIcon) barsIcon.style.display = 'inline-block'; // Or 'none' if sidebar is always open on desktop
                    if (timesIcon) timesIcon.style.display = 'none';
                }
                // Specific override if sidebar is actually open (mobile-open class)
                if (sidebar.classList.contains('mobile-open')) {
                    if (barsIcon) barsIcon.style.display = 'none';
                    if (timesIcon) timesIcon.style.display = 'inline-block';
                }
            }
        }
    </script>
</body>
</html>