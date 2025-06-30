<?php
// messagerie_chargee_communication.php
require_once 'config.php'; // Ensure this path is correct for your setup

// Start session if not already started in config.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    header('Location: loginForm.php');
    exit();
}

// Récupération des informations utilisateur connecté (Assumé pour le chargé de communication)
$loggedInUserId = $_SESSION['user_id'] ?? 1; // ID de l'utilisateur chargé de communication (ex: 1 pour 'Yah Christine')
$loggedInUserType = $_SESSION['user_type'] ?? 'personnel_admin';
$currentUserEmail = $_SESSION['user_email'] ?? 'mkoffi@sygecos.edu'; // Email du chargé de communication
$currentUserName = $_SESSION['user_name'] ?? 'Koffi Marie'; // Nom du chargé de communication

// Récupération des contacts spécifiques au chargé de communication
$contacts = [];
try {
    // Étudiants (now the ONLY recipients for new messages from Chargé de Communication)
    $stmt = $pdo->query("
        SELECT 'etudiant' as type, num_etu as id_ref, fk_id_util as id_util, CONCAT(nom_etu, ' ', prenoms_etu) as nom_complet, email_etu as email,
               CONCAT(ne.lib_niv_etu, ' - ', f.lib_filiere) as role
        FROM etudiant e
        LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
        LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
        ORDER BY nom_etu, prenoms_etu
    ");
    $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // This array will hold the student contacts for the dropdown
    $specificRecipientsForDropdown = [];
    foreach ($etudiants as $etudiant) {
        if (!empty($etudiant['id_util'])) { // Only allow students with a user account
            $specificRecipientsForDropdown[] = [
                'id_util' => $etudiant['id_util'],
                'name' => trim($etudiant['nom_complet']),
                'role' => 'Étudiant', // Ensure role is consistently 'Étudiant'
                'email' => $etudiant['email']
            ];
        }
    }

} catch (PDOException $e) {
    error_log("Erreur récupération contacts: " . $e->getMessage());
    $specificRecipientsForDropdown = [];
}

// Fetch academic years for report type
$anneesAcademiques = [];
try {
    $stmt = $pdo->query("SELECT id_Ac, annee_libelle FROM année_academique ORDER BY annee_libelle DESC");
    $anneesAcademiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération années académiques: " . $e->getMessage());
    $anneesAcademiques = [];
}


// === TRAITEMENT AJAX ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'charger_messages':
                $dossier = $_POST['dossier'] ?? 'reception';
                $page = intval($_POST['page'] ?? 1);
                $limite = 20; // 20 messages par page
                $offset = ($page - 1) * $limite;

                // --- LOGIQUE DE RÉCUPÉRATION DES MESSAGES DEPUIS LA BDD ---
                // Le chargé de communication reçoit des messages de tout le monde et en envoie.
                // Donc on récupère les messages où l'utilisateur connecté est expéditeur OU destinataire.
                $sql = "
                    SELECT
                        m.id_message,
                        m.subject,
                        m.body,
                        m.sent_at,
                        m.is_read,
                        m.message_type,
                        s.login_util AS sender_login,
                        s.id_util AS sender_id_util,
                        COALESCE(e_sender.nom_ens, pa_sender.nom_pers, et_sender.nom_etu, ent_sender.lib_entr) AS expediteur_nom,
                        COALESCE(e_sender.prenom_ens, pa_sender.prenoms_pers, et_sender.prenoms_etu, '') AS expediteur_prenom,
                        COALESCE(e_sender.email, pa_sender.email_pers, et_sender.email_etu, ent_sender.email_contact) AS expediteur_email,
                        r.id_util AS receiver_id_util,
                        COALESCE(e_receiver.nom_ens, pa_receiver.nom_pers, et_receiver.nom_etu, ent_receiver.lib_entr) AS destinataire_nom,
                        COALESCE(e_receiver.prenom_ens, pa_receiver.prenoms_pers, et_receiver.prenoms_etu, '') AS destinataire_prenom,
                        m.related_report_id,
                        rf.description AS report_description,
                        aa.annee_libelle AS report_annee_academique_libelle
                    FROM
                        messages m
                    JOIN
                        utilisateur s ON m.sender_id_util = s.id_util
                    JOIN
                        utilisateur r ON m.receiver_id_util = r.id_util
                    LEFT JOIN enseignant e_sender ON s.id_util = e_sender.fk_id_util
                    LEFT JOIN personnel_admin pa_sender ON s.id_util = pa_sender.fk_id_util
                    LEFT JOIN etudiant et_sender ON s.id_util = et_sender.fk_id_util
                    LEFT JOIN entreprise ent_sender ON s.id_util = ent_sender.fk_id_util_contact
                    LEFT JOIN enseignant e_receiver ON r.id_util = e_receiver.fk_id_util
                    LEFT JOIN personnel_admin pa_receiver ON r.id_util = pa_receiver.fk_id_util
                    LEFT JOIN etudiant et_receiver ON r.id_util = et_receiver.fk_id_util
                    LEFT JOIN entreprise ent_receiver ON r.id_util = ent_receiver.fk_id_util_contact
                    LEFT JOIN rapports_etudiant_files rf ON m.related_report_id = rf.id_report_file
                    LEFT JOIN année_academique aa ON rf.fk_id_Ac = aa.id_Ac
                    WHERE
                        (m.sender_id_util = :loggedInUserId OR m.receiver_id_util = :loggedInUserId)
                ";
                $params = ['loggedInUserId' => $loggedInUserId];

                // Filtrage selon le dossier
                if ($dossier === 'reception') {
                    $sql .= " AND m.receiver_id_util = :loggedInUserId";
                } elseif ($dossier === 'envoyes') {
                    $sql .= " AND m.sender_id_util = :loggedInUserId";
                } elseif ($dossier === 'non_lus') {
                    $sql .= " AND m.receiver_id_util = :loggedInUserId AND m.is_read = 0";
                } elseif ($dossier === 'rapports') { // Only fetch reports received by current user (Chargé de com)
                    $sql .= " AND m.receiver_id_util = :loggedInUserId AND m.message_type = 'rapport'";
                }
                // For other folders (important, drafts, trash), an 'is_important', 'is_draft', 'is_deleted' column would be needed in the `messages` table.
                // For now, we'll filter in memory for simulation.

                $sql .= " ORDER BY m.sent_at DESC LIMIT :limite OFFSET :offset";

                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':loggedInUserId', $loggedInUserId, PDO::PARAM_INT);
                $stmt->bindParam(':limite', $limite, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // To simulate "important", "drafts", "trash" if not handled in DB
                $allMessagesForCounts = [];
                // This part needs to fetch all messages relevant to the current user, across all folders, to get accurate counts.
                $stmtAll = $pdo->prepare("
                    SELECT m.id_message, m.is_read, m.message_type, m.subject, m.receiver_id_util, m.sender_id_util
                    FROM messages m
                    WHERE (m.sender_id_util = :loggedInUserId OR m.receiver_id_util = :loggedInUserId)
                ");
                $stmtAll->bindParam(':loggedInUserId', $loggedInUserId, PDO::PARAM_INT);
                $stmtAll->execute();
                $allMessagesForCounts = $stmtAll->fetchAll(PDO::FETCH_ASSOC);


                $totalMessagesInCurrentFolder = count(array_filter($allMessagesForCounts, function($m) use ($loggedInUserId, $dossier) {
                    if ($dossier === 'reception') return $m['receiver_id_util'] == $loggedInUserId;
                    if ($dossier === 'envoyes') return $m['sender_id_util'] == $loggedInUserId;
                    if ($dossier === 'non_lus') return $m['receiver_id_util'] == $loggedInUserId && $m['is_read'] == 0;
                    if ($dossier === 'rapports') return $m['receiver_id_util'] == $loggedInUserId && $m['message_type'] === 'rapport';
                    // For 'brouillons' and 'corbeille', assumed 0 for now as no DB columns exist
                    return true; // Should not happen for specific folders
                }));


                $nonLusCount = count(array_filter($allMessagesForCounts, function($m) use ($loggedInUserId) { return $m['is_read'] == 0 && $m['receiver_id_util'] == $loggedInUserId; }));
                $rapportsCount = count(array_filter($allMessagesForCounts, function($m) use ($loggedInUserId) { return $m['message_type'] === 'rapport' && $m['receiver_id_util'] == $loggedInUserId; }));
                $sentCount = count(array_filter($allMessagesForCounts, function($m) use ($loggedInUserId) { return $m['sender_id_util'] == $loggedInUserId; }));
                $receptionCount = count(array_filter($allMessagesForCounts, function($m) use ($loggedInUserId) { return $m['receiver_id_util'] == $loggedInUserId; }));

                $pagesTotal = ceil($totalMessagesInCurrentFolder / $limite);

                echo json_encode([
                    'success' => true,
                    'messages' => $messages,
                    'total' => $totalMessagesInCurrentFolder,
                    'page' => $page,
                    'pages_total' => $pagesTotal,
                    'counts' => [
                        'reception' => $receptionCount,
                        'non_lus' => $nonLusCount,
                        'rapports' => $rapportsCount,
                        'envoyes' => $sentCount,
                        'brouillons' => 0, // Placeholder
                        'corbeille' => 0, // Placeholder
                    ]
                ]);
                break;

            case 'lire_message':
                $messageId = intval($_POST['message_id'] ?? 0);

                // Update read status
                $updateStmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id_message = ? AND receiver_id_util = ?");
                $updateStmt->execute([$messageId, $loggedInUserId]);

                // Retrieve the full message (with necessary joins)
                $stmt = $pdo->prepare("
                    SELECT
                        m.id_message,
                        m.subject,
                        m.body,
                        m.sent_at,
                        m.is_read,
                        m.message_type,
                        s.id_util AS sender_id_util,
                        COALESCE(e_sender.nom_ens, pa_sender.nom_pers, et_sender.nom_etu, ent_sender.lib_entr) AS expediteur_nom,
                        COALESCE(e_sender.prenom_ens, pa_sender.prenoms_pers, et_sender.prenoms_etu, '') AS expediteur_prenom,
                        COALESCE(e_sender.email, pa_sender.email_pers, et_sender.email_etu, ent_sender.email_contact) AS expediteur_email,
                        r.id_util AS receiver_id_util,
                        COALESCE(e_receiver.nom_ens, pa_receiver.nom_pers, et_receiver.nom_etu, ent_receiver.lib_entr) AS destinataire_nom,
                        COALESCE(e_receiver.prenom_ens, pa_receiver.prenoms_pers, et_receiver.prenoms_etu, '') AS destinataire_prenom,
                        m.related_report_id,
                        rf.description AS report_description,
                        aa.annee_libelle AS report_annee_academique_libelle
                    FROM
                        messages m
                    JOIN
                        utilisateur s ON m.sender_id_util = s.id_util
                    JOIN
                        utilisateur r ON m.receiver_id_util = r.id_util
                    LEFT JOIN enseignant e_sender ON s.id_util = e_sender.fk_id_util
                    LEFT JOIN personnel_admin pa_sender ON s.id_util = pa_sender.fk_id_util
                    LEFT JOIN etudiant et_sender ON s.id_util = et_sender.fk_id_util
                    LEFT JOIN entreprise ent_sender ON s.id_util = ent_sender.fk_id_util_contact
                    LEFT JOIN enseignant e_receiver ON r.id_util = e_receiver.fk_id_util
                    LEFT JOIN personnel_admin pa_receiver ON r.id_util = pa_receiver.fk_id_util
                    LEFT JOIN etudiant et_receiver ON r.id_util = et_receiver.fk_id_util
                    LEFT JOIN entreprise ent_receiver ON r.id_util = ent_receiver.fk_id_util_contact
                    LEFT JOIN rapports_etudiant_files rf ON m.related_report_id = rf.id_report_file
                    LEFT JOIN année_academique aa ON rf.fk_id_Ac = aa.id_Ac
                    WHERE
                        m.id_message = ? AND (m.sender_id_util = ? OR m.receiver_id_util = ?)
                ");
                $stmt->execute([$messageId, $loggedInUserId, $loggedInUserId]);
                $messageComplet = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$messageComplet) {
                    throw new Exception("Message introuvable ou non autorisé.");
                }

                // For display, add default values if some fields are NULL
                $messageComplet['expediteur_avatar'] = !empty($messageComplet['expediteur_nom']) ? strtoupper(substr($messageComplet['expediteur_nom'], 0, 1) . (!empty($messageComplet['expediteur_prenom']) ? substr($messageComplet['expediteur_prenom'], 0, 1) : '')) : '?';
                $messageComplet['pieces_jointes'] = []; // No real attachments for now
                $messageComplet['important'] = false; // No 'important' column for now

                echo json_encode(['success' => true, 'message' => $messageComplet]);
                break;

            case 'envoyer_message':
                $destinataireId = $_POST['destinataire_id'] ?? null; // Single recipient ID
                $sujet = trim($_POST['sujet'] ?? '');
                $contenu = trim($_POST['contenu'] ?? '');
                $messageType = 'rapport'; // Message type is ALWAYS 'rapport' when sending to student
                $reportDescription = $_POST['report_description'] ?? null;
                $academicYearId = intval($_POST['academic_year_id'] ?? 0);
                $fkNumEtu = $_POST['fk_num_etu'] ?? null; // The student's num_etu

                if (empty($destinataireId) || empty($sujet) || empty($contenu) || empty($reportDescription) || $academicYearId <= 0 || empty($fkNumEtu)) {
                    throw new Exception("Tous les champs (destinataire, sujet, contenu, description du rapport, année académique, et numéro étudiant) sont obligatoires pour le dépôt d'un rapport.");
                }

                $pdo->beginTransaction();
                $sentCount = 0;

                // Logic to insert into rapports_etudiant_files
                $stmtReport = $pdo->prepare("
                    INSERT INTO rapports_etudiant_files (fk_num_etu, file_name, file_path, fk_id_Ac, description)
                    VALUES (:fk_num_etu, :file_name, :file_path, :fk_id_Ac, :description)
                ");
                // Dummy values for file_name and file_path as no actual file is uploaded
                $dummyFileName = "Rapport_Conceptuel_" . uniqid() . ".pdf";
                $dummyFilePath = "conceptual_reports/" . $dummyFileName;
                $stmtReport->execute([
                    'fk_num_etu' => $fkNumEtu,
                    'file_name' => $dummyFileName,
                    'file_path' => $dummyFilePath,
                    'fk_id_Ac' => $academicYearId,
                    'description' => $reportDescription
                ]);
                $relatedReportId = $pdo->lastInsertId();


                $stmt = $pdo->prepare("
                    INSERT INTO messages (sender_id_util, receiver_id_util, subject, body, message_type, related_report_id)
                    VALUES (:sender_id, :receiver_id, :subject, :body, :message_type, :related_report_id)
                ");
                $stmt->execute([
                    'sender_id' => $loggedInUserId,
                    'receiver_id' => $destinataireId,
                    'subject' => $sujet,
                    'body' => $contenu,
                    'message_type' => $messageType,
                    'related_report_id' => $relatedReportId
                ]);
                $sentCount++;

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Rapport déposé et message envoyé avec succès',
                    'sent_count' => $sentCount
                ]);
                break;

            case 'marquer_lu':
                $messageIds = $_POST['message_ids'] ?? [];
                $statut = $_POST['statut'] ?? 'lu'; // 'lu' or 'non_lu'
                $isReadValue = ($statut === 'lu') ? 1 : 0;

                if (empty($messageIds)) {
                    throw new Exception("No message selected.");
                }

                $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                $stmt = $pdo->prepare("UPDATE messages SET is_read = ? WHERE id_message IN ($placeholders) AND receiver_id_util = ?");
                $params = array_merge([$isReadValue], $messageIds, [$loggedInUserId]);
                $stmt->execute($params);

                echo json_encode([
                    'success' => true,
                    'message' => $stmt->rowCount() . ' message(s) marked as ' . $statut,
                    'updated_count' => $stmt->rowCount()
                ]);
                break;

            case 'supprimer_messages':
                $messageIds = $_POST['message_ids'] ?? [];

                if (empty($messageIds)) {
                    throw new Exception("No message selected.");
                }

                $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                // For actual deletion, implement DELETE. For trash, update an 'is_deleted' flag.
                $stmt = $pdo->prepare("DELETE FROM messages WHERE id_message IN ($placeholders) AND (sender_id_util = ? OR receiver_id_util = ?)");
                $params = array_merge($messageIds, [$loggedInUserId, $loggedInUserId]);
                $stmt->execute($params);

                echo json_encode([
                    'success' => true,
                    'message' => $stmt->rowCount() . ' message(s) deleted',
                    'deleted_count' => $stmt->rowCount()
                ]);
                break;

            case 'rechercher_messages':
                $query = trim($_POST['query'] ?? '');

                $sql = "
                    SELECT
                        m.id_message,
                        m.subject,
                        m.body,
                        m.sent_at,
                        m.is_read,
                        m.message_type,
                        s.login_util AS sender_login,
                        COALESCE(e_sender.nom_ens, pa_sender.nom_pers, et_sender.nom_etu, ent_sender.lib_entr) AS expediteur_nom,
                        COALESCE(e_sender.prenom_ens, pa_sender.prenoms_pers, et_sender.prenoms_etu, '') AS expediteur_prenom
                    FROM
                        messages m
                    JOIN
                        utilisateur s ON m.sender_id_util = s.id_util
                    LEFT JOIN enseignant e_sender ON s.id_util = e_sender.fk_id_util
                    LEFT JOIN personnel_admin pa_sender ON s.id_util = pa_sender.fk_id_util
                    LEFT JOIN etudiant et_sender ON s.id_util = et_sender.fk_id_util
                    LEFT JOIN entreprise ent_sender ON s.id_util = ent_sender.fk_id_util_contact
                    WHERE
                        (m.sender_id_util = :loggedInUserId OR m.receiver_id_util = :loggedInUserId)
                        AND (m.subject LIKE :query OR m.body LIKE :query OR COALESCE(e_sender.nom_ens, pa_sender.nom_pers, et_sender.nom_etu, ent_sender.lib_entr) LIKE :query)
                    ORDER BY m.sent_at DESC
                ";
                $stmt = $pdo->prepare($sql);
                $searchTerm = '%' . $query . '%';
                $stmt->bindParam(':loggedInUserId', $loggedInUserId, PDO::PARAM_INT);
                $stmt->bindParam(':query', $searchTerm, PDO::PARAM_STR);
                $stmt->execute();
                $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Format results for front-end display
                $formattedResults = [];
                foreach ($resultats as $msg) {
                    $formattedResults[] = [
                        'id_message' => $msg['id_message'],
                        'expediteur_nom' => trim($msg['expediteur_prenom'] . ' ' . $msg['expediteur_nom']),
                        'subject' => $msg['subject'],
                        'body' => substr(strip_tags($msg['body']), 0, 100) . (strlen(strip_tags($msg['body'])) > 100 ? '...' : ''),
                        'sent_at' => $msg['sent_at'],
                        'is_read' => (bool)$msg['is_read'],
                        'important' => false,
                        'piece_jointe' => false,
                        'message_type' => $msg['message_type']
                    ];
                }

                echo json_encode([
                    'success' => true,
                    'messages' => $formattedResults,
                    'total' => count($formattedResults),
                    'query' => $query
                ]);
                break;

            case 'get_student_details_by_id': // New AJAX endpoint to get student details
                $userId = $_POST['user_id'] ?? null;
                if (!$userId) {
                    throw new Exception("User ID missing.");
                }
                $stmt = $pdo->prepare("
                    SELECT e.num_etu, CONCAT(e.nom_etu, ' ', e.prenoms_etu) as nom_complet
                    FROM etudiant e
                    WHERE e.fk_id_util = ?
                ");
                $stmt->execute([$userId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($student) {
                    echo json_encode(['success' => true, 'data' => $student]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Student not found for this user ID.']);
                }
                exit();

            default:
                throw new Exception("Action not recognized");
        }

    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage()); // Log error for debugging
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
    <title>SYGECOS - Messagerie Communication</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Base styles and variables */
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
        body { font-family: var(--font-primary); background-color: var(--gray-50); color: var(--gray-800); overflow: hidden; /* Hide body overflow to manage inner scroll */ }
        .admin-layout { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-width); transition: margin-left var(--transition-normal); overflow: hidden; /* Prevent horizontal scroll from main content */ }
        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed-width); }

        /* Sidebar navigation (admin layout) */
        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-width); height: 100vh; background: linear-gradient(180deg, var(--primary-800) 0%, var(--primary-900) 100%); color: white; z-index: 1000; transition: all var(--transition-normal); overflow-y: auto; overflow-x: hidden; }
        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .topbar { height: var(--topbar-height); background: var(--white); border-bottom: 1px solid var(--gray-200); padding: 0 var(--space-6); display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; }

        /* === MESSAGING LAYOUT - GMAIL STYLE === */
        .messagerie-container {
            display: flex;
            height: calc(100vh - var(--topbar-height)); /* Full height minus topbar */
            overflow: hidden; /* Prevents container from overflowing */
            background-color: var(--white); /* Ensure background is consistent */
        }

        /* Mail Sidebar (Folders) */
        .mail-sidebar {
            width: 250px; /* Adjust width as needed */
            flex-shrink: 0;
            background: var(--white);
            border-right: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            padding: var(--space-4);
        }
        .mail-sidebar-header { padding: var(--space-2) 0 var(--space-4); border-bottom: none; /* No border bottom */ }
        .compose-btn {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            background: var(--accent-100); /* Lighter accent background */
            color: var(--accent-700); /* Darker accent text */
            border: none;
            border-radius: var(--radius-lg);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            box-shadow: var(--shadow-sm); /* Subtle shadow */
        }
        .compose-btn:hover { background: var(--accent-200); box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .compose-btn i { font-size: var(--text-lg); }

        .mail-folders { flex: 1; padding: var(--space-4) 0; }
        .folder-item {
            display: flex; align-items: center; padding: var(--space-2) var(--space-4); /* Adjusted padding */
            margin-bottom: var(--space-1); /* Space between items */
            border-radius: var(--radius-md); /* Rounded folders */
            cursor: pointer; transition: all var(--transition-fast); position: relative;
        }
        .folder-item:hover { background: var(--gray-100); }
        .folder-item.active {
            background: var(--accent-100);
            color: var(--accent-800);
            font-weight: 600;
        }
        .folder-icon { width: 20px; margin-right: var(--space-3); color: var(--gray-500); }
        .folder-item.active .folder-icon { color: var(--accent-700); }
        .folder-name { flex: 1; font-size: var(--text-sm); }
        .folder-count {
            background: var(--gray-200); color: var(--gray-600);
            padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm);
            font-size: var(--text-xs); font-weight: 600; min-width: 25px; text-align: center;
        }
        .folder-item.active .folder-count { background: var(--accent-200); color: var(--accent-800); }

        /* Mail Main Area (List + Reader/Composer) */
        .mail-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            position: relative; /* For stacking mail-list and mail-reader */
            background: var(--white);
        }

        /* Mail Toolbar */
        .mail-toolbar {
            padding: var(--space-3) var(--space-4); /* Adjusted padding */
            border-bottom: 1px solid var(--gray-200);
            display: flex; align-items: center; justify-content: space-between; gap: var(--space-4);
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
            min-height: 50px; /* Ensure a minimum height */
        }
        .toolbar-left { display: flex; align-items: center; gap: var(--space-2); }
        .toolbar-left .btn-icon { width: 32px; height: 32px; padding: var(--space-1); border-radius: 50%; } /* Round icons */
        .toolbar-left .btn-icon:hover { background: var(--gray-100); }

        .search-box { position: relative; flex: 1; max-width: 500px; }
        .search-box input { width: 100%; padding: var(--space-2) var(--space-3) var(--space-2) 40px; border: 1px solid var(--gray-200); border-radius: var(--radius-lg); font-size: var(--text-sm); background-color: var(--gray-50); }
        .search-box input:focus { outline: none; border-color: var(--accent-300); box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1); background-color: var(--white); }
        .search-box .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--gray-400); }

        .toolbar-right { display: flex; align-items: center; gap: var(--space-2); }
        .toolbar-right .btn-icon { width: 32px; height: 32px; padding: var(--space-1); border-radius: 50%; }
        .toolbar-right .btn-icon:hover { background: var(--gray-100); }
        .messages-pagination-info { font-size: var(--text-sm); color: var(--gray-600); margin-right: var(--space-2); }


        /* Mail List & Mail Reader - Side by side behavior */
        .mail-content-area {
            display: flex;
            flex-grow: 1; /* Takes remaining vertical space */
            position: relative;
            overflow: hidden; /* For sliding views */
        }

        .mail-list-view {
            width: 100%; /* Occupy full width initially */
            flex-shrink: 0;
            overflow-y: auto;
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            transition: transform 0.3s ease-out;
            background-color: var(--white);
        }

        .mail-reader-view {
            width: 100%; /* Occupy full width initially */
            flex-shrink: 0;
            overflow-y: auto;
            position: absolute;
            top: 0;
            left: 100%; /* Starts off-screen to the right */
            height: 100%;
            transition: transform 0.3s ease-out;
            background-color: var(--white);
            display: flex;
            flex-direction: column;
        }

        /* Active state for views */
        .mail-content-area.show-reader .mail-list-view { transform: translateX(-100%); }
        .mail-content-area.show-reader .mail-reader-view { transform: translateX(-100%); }
        /* The composer will be a floating window, not part of mail-content-area's sliding */


        /* Message Item Styles */
        .message-item {
            display: flex; align-items: center; padding: var(--space-3) var(--space-4); /* Adjusted padding */
            border-bottom: 1px solid var(--gray-100); cursor: pointer; transition: background var(--transition-fast), border-left var(--transition-fast);
            position: relative;
            /* Removed left border for active/unread, using a dot */
        }
        .message-item:hover { background: var(--gray-100); }
        .message-item.selected { background: var(--accent-50); } /* Selected in list, not necessarily read */
        .message-item.unread { font-weight: 600; color: var(--gray-900); }
        .message-item.unread .message-subject { color: var(--gray-900); }

        .message-checkbox { margin-right: var(--space-3); width: 16px; height: 16px; flex-shrink: 0; }
        .message-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--accent-500); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: var(--text-sm); margin-right: var(--space-3); flex-shrink: 0; }
        .message-content { flex: 1; min-width: 0; /* Allows content to shrink */ }
        .message-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-1); }
        .message-sender { font-weight: inherit; color: inherit; font-size: var(--text-sm); } /* Inherit from parent unread/read state */
        .message-date { font-size: var(--text-xs); color: var(--gray-500); white-space: nowrap; }
        .message-subject { font-weight: inherit; color: inherit; margin-bottom: var(--space-1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: var(--text-sm); }
        .message-preview { font-size: var(--text-xs); color: var(--gray-600); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .message-meta { display: flex; align-items: center; gap: var(--space-2); margin-left: var(--space-3); flex-shrink: 0; } /* Icons on right */
        .message-important, .message-attachment { color: var(--gray-500); font-size: var(--text-sm); }
        .message-item.unread .message-important { color: var(--warning-500); } /* Highlight important unread */
        .message-type { padding: 0 var(--space-1); border-radius: var(--radius-sm); font-size: var(--text-xs); font-weight: 600; }
        .message-type-partenariat { background: var(--secondary-100); color: var(--secondary-600); }
        .message-type-rapport { background: var(--accent-100); color: var(--accent-600); }
        .message-type-evaluation { background: #fef3c7; color: #d97706; }
        .message-type-coordination { background: #f3e8ff; color: #7c3aed; }
        .message-type-demande { background: var(--gray-100); color: var(--gray-600); }
        .message-type-general, .message-type-personnel, .message-type-officiel { display: none; /* Hide generic types */ }


        /* Mail Reader View (Message Content) */
        .mail-reader-view {
            padding: var(--space-4) var(--space-6);
        }
        .reader-header {
            padding: var(--space-2) 0 var(--space-4); /* Adjusted padding */
            border-bottom: 1px solid var(--gray-200);
            margin-bottom: var(--space-4); /* Space below header */
        }
        .reader-subject { font-size: var(--text-2xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--space-3); }
        .reader-meta-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-3); }
        .reader-sender-info-box { display: flex; align-items: center; gap: var(--space-3); }
        .reader-sender-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--accent-500); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: var(--text-base); }
        .reader-sender-name { font-weight: 600; color: var(--gray-900); font-size: var(--text-base); }
        .reader-sender-email { font-size: var(--text-sm); color: var(--gray-600); }
        .reader-date { font-size: var(--text-sm); color: var(--gray-500); }
        .reader-actions { display: flex; gap: var(--space-2); } /* Buttons like reply/forward */

        .reader-content { flex: 1; padding: var(--space-4) 0; overflow-y: auto; }
        .reader-content p { margin-bottom: var(--space-3); line-height: 1.6; }

        /* Mail Composer View - FLOATING WINDOW STYLE */
        .mail-composer-view {
            display: none; /* Hidden by default */
            position: fixed;
            bottom: 20px; /* Adjust as needed */
            right: 20px; /* Adjust as needed */
            width: 600px; /* Width of the compose window */
            height: 500px; /* Height of the compose window */
            background-color: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl); /* Stronger shadow for floating window */
            z-index: 1000; /* Above other content */
            flex-direction: column; /* Ensure content stacks vertically */
            overflow: hidden; /* For rounded corners */
            resize: both; /* Allow resizing */
            min-width: 400px;
            min-height: 300px;
            padding: 0; /* Remove internal padding to manage sections */
        }
        .mail-composer-view.show {
            display: flex; /* Show when 'show' class is added */
        }
        .compose-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-2) var(--space-4); /* Reduced padding to match Gmail */
            background-color: var(--gray-100); /* Header background */
            border-bottom: 1px solid var(--gray-200);
            cursor: grab; /* Indicate draggable */
            min-height: 40px; /* Smaller header height */
        }
        .compose-header h2 {
            font-size: var(--text-sm); /* Smaller title font size */
            color: var(--gray-800);
            font-weight: 500;
        }
        .compose-header-actions {
            display: flex;
            gap: var(--space-1);
        }
        .compose-header-actions .btn-icon {
            background: none;
            border: none;
            font-size: var(--text-base); /* Standard icon size */
            color: var(--gray-600);
            cursor: pointer;
            width: 28px; /* Square clickable area */
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
        }
        .compose-header-actions .btn-icon:hover {
            background-color: var(--gray-200);
        }
        .compose-close { /* Specific style for close button */
            font-size: var(--text-lg); /* Slightly larger for close */
        }


        .compose-form {
            flex-grow: 1; /* Takes available space */
            display: flex;
            flex-direction: column;
        }

        .composer-fields {
            /* Removed bottom border, individual fields will have borders */
            padding: 0; /* No padding here, fields have their own */
        }
        .composer-field {
            display: flex;
            align-items: center;
            border-bottom: 1px solid var(--gray-200); /* Border for each field */
            padding: var(--space-1) var(--space-4); /* Padding for fields */
            min-height: 38px; /* Mimic Gmail input height */
        }
        .composer-field:last-child {
            border-bottom: none; /* No border for the last field before editor */
        }
        .composer-field label {
            width: auto; /* Let content dictate width */
            padding-right: var(--space-2); /* Space after label */
            font-weight: normal; /* Regular weight */
            color: var(--gray-700);
            font-size: var(--text-sm);
            flex-shrink: 0;
            white-space: nowrap;
        }
        .composer-field input,
        .composer-field select,
        .composer-field textarea {
            flex: 1;
            padding: 0; /* Remove padding here, controlled by parent .composer-field */
            border: none;
            font-size: var(--text-sm);
            outline: none;
            background-color: transparent;
        }
        .composer-field input:focus,
        .composer-field select:focus,
        .composer-field textarea:focus {
            box-shadow: none;
        }

        /* New styles for the student search/select dropdown (using select2-like approach) */
        .select-recipient-container {
            flex: 1;
            position: relative;
            z-index: 10; /* Ensure dropdown is above other fields */
        }

        .select-recipient-input {
            width: 100%;
            padding: var(--space-1) var(--space-2); /* Match composer-field padding */
            border: none; /* No border as it's within composer-field */
            background-color: transparent;
            font-size: var(--text-sm);
            color: var(--gray-800);
            outline: none;
        }
        
        .select-recipient-input:focus + .recipient-dropdown {
            display: block;
        }

        .recipient-dropdown {
            display: none;
            position: absolute;
            top: 100%; /* Position below the input */
            left: 0;
            right: 0;
            background-color: var(--white);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1001; /* Above other composer elements */
        }

        .recipient-dropdown-item {
            padding: var(--space-2) var(--space-3);
            font-size: var(--text-sm);
            color: var(--gray-800);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }

        .recipient-dropdown-item:hover {
            background-color: var(--gray-100);
        }

        .recipient-dropdown-item.selected {
            background-color: var(--accent-100);
            color: var(--accent-800);
            font-weight: 600;
        }

        .recipient-dropdown-item-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background-color: var(--accent-500);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-xs);
            flex-shrink: 0;
        }

        .recipient-dropdown-item.no-results {
            color: var(--gray-500);
            text-align: center;
            padding: var(--space-3);
            font-style: italic;
        }

        /* Removed Tag input related styles */
        .cc-cci-toggle {
            display: none;
        }

        .compose-editor {
            flex-grow: 1; /* Takes remaining height */
            padding: var(--space-4); /* Original padding for message body */
            /* Removed bottom border, handled by actions below */
            display: flex;
        }
        .compose-editor textarea {
            width: 100%;
            border: none;
            resize: none;
            font-family: var(--font-primary);
            font-size: var(--text-base);
            outline: none;
            padding: 0;
        }

        #reportSpecificFields {
            padding: var(--space-3) var(--space-4);
            border-top: 1px solid var(--gray-200); /* Border above report fields */
            background-color: var(--gray-50);
            display: block !important; /* Ensure it's always visible for this user role */
        }
        #reportSpecificFields .composer-field {
            border-bottom: 1px solid var(--gray-200); /* Add borders within report fields */
            padding: var(--space-1) 0; /* Adjust padding */
        }
        #reportSpecificFields .composer-field:last-of-type {
            border-bottom: none;
        }
        #reportSpecificFields label { width: 100px; } /* Adjust label width for report fields */
        #reportSpecificFields textarea, #reportSpecificFields select {
            border: 1px solid var(--gray-300); /* Keep borders for these inputs */
            padding: var(--space-1); /* Smaller padding */
            border-radius: var(--radius-md);
            background-color: var(--white);
        }

        .compose-actions {
            padding: var(--space-2) var(--space-4); /* Adjusted padding for bottom bar */
            display: flex;
            align-items: center; /* Align items vertically */
            justify-content: space-between; /* Space between send button and icons */
            border-top: 1px solid var(--gray-200); /* Top border for action bar */
            min-height: 48px; /* Minimum height for the action bar */
        }
        .compose-actions .btn {
            padding: var(--space-2) var(--space-4);
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            transition: background var(--transition-fast);
            display: flex; /* Allow icon inside button */
            align-items: center;
            gap: var(--space-1);
        }
        /* Styling for the new icon buttons in the toolbar */
        .compose-toolbar-icons {
            display: flex;
            gap: var(--space-1);
        }
        .compose-toolbar-icons .btn-icon {
            background: none;
            border: none;
            font-size: var(--text-base);
            color: var(--gray-600);
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%; /* Round icons */
        }
        .compose-toolbar-icons .btn-icon:hover {
            background-color: var(--gray-100);
        }

        /* Specific styling for the deposit button when it moves out of compose-actions */
        #depositReportBtn {
            padding: var(--space-2) var(--space-4);
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            background-color: var(--secondary-600);
            color: var(--white);
            border: 1px solid var(--secondary-600);
            transition: background var(--transition-fast);
            display: flex;
            align-items: center;
            gap: var(--space-1);
        }
        #depositReportBtn:hover {
            background-color: var(--secondary-700);
        }

        /* Message/Report Preview Modal */
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
            align-items: center; /* Center vertically */
            justify-content: center; /* Center horizontally */
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background-color: var(--white);
            padding: var(--space-8); /* More padding */
            border-radius: var(--radius-2xl); /* More rounded corners */
            box-shadow: var(--shadow-xl);
            width: 95%; /* Wider on smaller screens */
            max-width: 700px;
            max-height: 90vh; /* Limit height to viewport */
            overflow-y: auto; /* Scroll if content overflows */
            position: relative;
            box-sizing: border-box; /* Include padding in width/height */
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

        .modal-close-btn { /* Renamed to avoid conflict with .message-close */
            color: var(--gray-500);
            font-size: var(--text-3xl); /* Larger close icon */
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s ease;
            line-height: 1; /* Align vertically */
            background: none;
            border: none;
            padding: 0;
        }

        .modal-close-btn:hover,
        .modal-close-btn:focus {
            color: var(--gray-800);
            text-decoration: none;
        }

        .report-print-preview {
            display: grid;
            grid-template-columns: 2fr 1fr; /* Preview on left, options on right */
            gap: var(--space-6);
            padding: var(--space-4);
            background-color: var(--gray-50);
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray-200);
        }

        .preview-options-panel {
            background-color: var(--white);
            padding: var(--space-4);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
        }
        .preview-options-panel h3 {
            font-size: var(--text-lg);
            margin-bottom: var(--space-4);
            color: var(--gray-800);
        }
        .preview-options-panel .form-group {
            margin-bottom: var(--space-4);
        }
        .preview-options-panel label {
            display: block;
            margin-bottom: var(--space-2);
            font-weight: 500;
            color: var(--gray-700);
        }
        .preview-options-panel .form-control {
            width: 100%;
            padding: var(--space-2);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            background-color: var(--gray-100);
        }

        .preview-content-area {
            background-color: var(--white);
            padding: var(--space-6);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            min-height: 400px;
            overflow-y: auto;
            line-height: 1.6;
            color: var(--gray-800);
        }
        .preview-content-area .section-title {
            font-size: var(--text-base);
            font-weight: 600;
            color: var(--primary-700);
            margin-top: var(--space-6);
            margin-bottom: var(--space-3);
            padding-bottom: var(--space-1);
            border-bottom: 1px dashed var(--gray-300);
        }
        .preview-content-area .preview-item {
            margin-bottom: var(--space-2);
            font-size: var(--text-sm);
        }
        .preview-content-area .preview-footer {
            text-align: center;
            margin-top: var(--space-8);
            padding-top: var(--space-4);
            border-top: 1px solid var(--gray-200);
            font-size: var(--text-xs);
            color: var(--gray-500);
        }

        .form-actions {
            margin-top: var(--space-6);
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
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

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .mail-main { flex-direction: column; } /* Stack list and reader */
            .mail-list-view, .mail-reader-view {
                position: relative; /* Remove absolute positioning for stacking */
                transform: translateX(0) !important; /* Reset transforms */
                width: 100%;
                height: auto;
                min-height: 40vh; /* Give some height when stacked */
                border-bottom: 1px solid var(--gray-200);
            }
            .mail-content-area.show-reader .mail-list-view { display: none; }

            .messagerie-container { flex-direction: column; }
            .mail-sidebar { width: 100%; max-height: 100px; /* Keep sidebar compact */ overflow-x: auto; flex-direction: row; align-items: center; padding: var(--space-2) var(--space-4); justify-content: space-between;}
            .mail-sidebar-header { padding: 0; border-bottom: none; margin-right: var(--space-3);}
            .compose-btn { width: auto; padding: var(--space-2) var(--space-3); font-size: var(--text-sm); }
            .mail-folders { flex-direction: row; padding: 0; white-space: nowrap; }
            .folder-item { padding: var(--space-2) var(--space-3); margin: 0 var(--space-1); font-size: var(--text-sm); }
            .folder-icon { margin-right: var(--space-1); }
            .folder-count { display: none; } /* Hide counts on small screens for brevity */
            .folder-special { display: flex; border-top: none; margin-top: 0; padding-top: 0; }
            .folder-special-title { display: none; }

            .search-box { max-width: none; }
            .mail-toolbar { flex-direction: column; align-items: stretch; }
            .toolbar-left, .toolbar-right { justify-content: center; width: 100%; margin-bottom: var(--space-2); }

            /* Mobile composer view needs to be full screen overlay */
            .mail-composer-view {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                border-radius: 0;
                resize: none; /* Disable resize on mobile */
            }
            .report-print-preview {
                grid-template-columns: 1fr; /* Stack options and preview */
            }
            .preview-options-panel { order: 2; } /* Put options below preview on mobile */
            .preview-content-area { order: 1; height: 60vh; }
        }

        @media (max-width: 768px) {
            .mail-sidebar { max-height: fit-content; }
            .mail-toolbar { padding: var(--space-3); }
            .message-item { flex-wrap: wrap; padding: var(--space-3); }
            .message-checkbox { order: 1; }
            .message-avatar { order: 2; margin-right: var(--space-2); }
            .message-content { order: 4; flex-basis: 100%; margin-top: var(--space-2); }
            .message-header { order: 3; flex-basis: calc(100% - 40px - var(--space-2)); justify-content: flex-start; }
            .message-sender { flex-grow: 1; }
            .message-date { margin-left: auto; }
            .message-subject { order: 5; flex-basis: 100%; }
            .message-preview { order: 6; flex-basis: 100%; }
            .message-meta { order: 7; flex-basis: 100%; justify-content: flex-end; margin-top: var(--space-2); margin-left: 0; }

            .mail-reader-view {
                position: fixed; /* Overlay on mobile */
                top: 0; left: 0; right: 0; bottom: 0;
                z-index: 100;
                background-color: var(--white);
            }

            /* Responsive adjustments for composer */
            .compose-header {
                padding: var(--space-3) var(--space-4); /* Revert to larger padding on mobile */
            }
            .compose-header h2 {
                font-size: var(--text-lg); /* Revert title size on mobile */
            }
            .compose-actions {
                flex-direction: column-reverse; /* Stack send button below icons on mobile */
                align-items: stretch;
                padding: var(--space-3);
                gap: var(--space-3);
            }
            .compose-actions .btn {
                width: 100%;
                justify-content: center;
            }
            .compose-toolbar-icons {
                justify-content: space-around; /* Distribute icons */
                width: 100%;
            }
            #reportSpecificFields {
                padding: var(--space-4);
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_chargee_communication.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div id="alertMessage" class="alert" style="margin: var(--space-4);"></div>

            <div class="messagerie-container">
                <div class="mail-sidebar">
                    <div class="mail-sidebar-header">
                        <button class="compose-btn" id="composeBtn">
                            <i class="fas fa-pen"></i>
                            Nouveau message
                        </button>
                    </div>

                    <div class="mail-folders">
                        <div class="folder-item active" data-folder="reception">
                            <i class="fas fa-inbox folder-icon"></i>
                            <span class="folder-name">Boîte de réception</span>
                            <span class="folder-count" id="countReception"></span>
                        </div>
                        <div class="folder-item" data-folder="non_lus">
                            <i class="fas fa-envelope folder-icon"></i>
                            <span class="folder-name">Non lus</span>
                            <span class="folder-count" id="countNonLus"></span>
                        </div>
                        <div class="folder-item" data-folder="rapports">
                            <i class="fas fa-file-alt folder-icon"></i>
                            <span class="folder-name">Rapports de stage</span>
                            <span class="folder-count" id="countRapports"></span>
                        </div>
                        <div class="folder-item" data-folder="envoyes">
                            <i class="fas fa-paper-plane folder-icon"></i>
                            <span class="folder-name">Envoyés</span>
                            <span class="folder-count" id="countEnvoyes"></span>
                        </div>
                         <div class="folder-item" data-folder="brouillons">
                            <i class="fas fa-edit folder-icon"></i>
                            <span class="folder-name">Brouillons</span>
                            <span class="folder-count" id="countBrouillons"></span>
                        </div>
                        <div class="folder-item" data-folder="corbeille">
                            <i class="fas fa-trash folder-icon"></i>
                            <span class="folder-name">Corbeille</span>
                            <span class="folder-count" id="countCorbeille"></span>
                        </div>
                        </div>
                </div>

                <div class="mail-main">
                    <div class="mail-toolbar">
                        <div class="toolbar-left">
                            <label style="display: flex; align-items: center; gap: var(--space-2);">
                                <input type="checkbox" id="selectAllMessagesCheckbox">
                            </label>
                            <button class="btn btn-icon" id="refreshBtn" title="Actualiser">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <button class="btn btn-icon" id="markReadBtn" title="Marquer comme lu" disabled>
                                <i class="fas fa-envelope-open"></i>
                            </button>
                            <button class="btn btn-icon" id="deleteBtn" title="Supprimer" disabled>
                                <i class="fas fa-trash"></i>
                            </button>
                            </div>

                        <div class="search-box">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="searchInput" placeholder="Rechercher des messages...">
                        </div>

                        <div class="toolbar-right">
                            <span class="messages-pagination-info" id="messagesPagination"></span>
                            <button class="btn btn-icon" id="prevPageBtn" disabled>
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="btn btn-icon" id="nextPageBtn" disabled>
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mail-content-area" id="mailContentArea">
                        <div class="mail-list-view" id="mailListView">
                            <div class="mail-list" id="mailList">
                                </div>
                        </div>

                        <div class="mail-reader-view" id="mailReaderView">
                            <div class="reader-header">
                                <div class="reader-subject" id="readerSubject"></div>
                                <div class="reader-meta-row">
                                    <div class="reader-sender-info-box">
                                        <div class="reader-sender-avatar" id="readerAvatar"></div>
                                        <div>
                                            <div class="reader-sender-name" id="readerSenderName"></div>
                                            <div class="reader-sender-email" id="readerSenderEmail"></div>
                                        </div>
                                    </div>
                                    <div class="reader-actions">
                                        <span class="reader-date" id="readerDate"></span>
                                        <button class="btn btn-secondary btn-sm hidden" id="replyBtn">
                                            <i class="fas fa-reply"></i> Répondre
                                        </button>
                                        <button class="btn btn-secondary btn-sm" id="forwardBtn">
                                            <i class="fas fa-share"></i> Transférer
                                        </button>
                                        <button class="btn btn-secondary btn-sm" id="downloadPdfBtn" style="display: none;">
                                            <i class="fas fa-download"></i> Télécharger PDF
                                        </button>
                                        <button class="btn btn-secondary btn-sm" id="closeReaderBtn">
                                            <i class="fas fa-arrow-left"></i> Retour
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="reader-content" id="readerContent">
                                </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mail-composer-view" id="mailComposerView">
                <div class="compose-header" id="composeHeader">
                    <h2>Nouveau rapport de stage</h2>
                    <div class="compose-header-actions">
                        <button class="btn-icon" title="Réduire"><i class="fas fa-minus"></i></button>
                        <button class="btn-icon" title="Agrandir"><i class="fas fa-expand-alt"></i></button>
                        <button class="modal-close-btn btn-icon" id="closeComposerBtn" title="Fermer">&times;</button>
                    </div>
                </div>
                <form class="compose-form" id="mainComposeForm">
                    <div class="composer-fields">
                        <div class="composer-field">
                            <label>À</label>
                            <div class="select-recipient-container">
                                <input type="text" id="recipientSearchInput" class="select-recipient-input" placeholder="Rechercher un étudiant..." autocomplete="off">
                                <div id="recipientDropdown" class="recipient-dropdown">
                                    </div>
                                <input type="hidden" id="selectedRecipientId" name="destinataire_id" required>
                                <input type="hidden" id="selectedRecipientNumEtu" name="fk_num_etu">
                            </div>
                        </div>
                        <div class="composer-field">
                            <label>Objet</label>
                            <input type="text" id="composeSubjectInput" required value="Dépôt de Rapport de Stage">
                        </div>
                    </div>
                    <div class="compose-editor">
                        <textarea id="composeMessageContent" required placeholder="Contenu du message d'accompagnement du rapport."></textarea>
                    </div>
                    <div id="reportSpecificFields">
                        <div class="composer-field">
                            <label>Description:</label>
                            <textarea id="reportDescription" name="report_description" rows="3" placeholder="Brève description du rapport" required></textarea>
                        </div>
                        <div class="composer-field">
                            <label>Année Acad.:</label>
                            <select id="academicYearSelect" name="academic_year_id" required>
                                <option value="">Sélectionner</option>
                                <?php foreach ($anneesAcademiques as $annee): ?>
                                    <option value="<?php echo htmlspecialchars($annee['id_Ac']); ?>">
                                        <?php echo htmlspecialchars($annee['annee_libelle']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="text-align: right; padding: var(--space-3) var(--space-4);">
                            <button type="button" class="btn btn-success" id="depositReportBtn">
                                <i class="fas fa-file-export"></i> Prévisualiser & Déposer
                            </button>
                        </div>
                    </div>
                    <div class="compose-actions">
                        <button type="submit" class="btn btn-primary" id="sendMailBtn" style="display:none;">Envoyer</button> <div class="compose-toolbar-icons">
                            <button type="button" class="btn-icon" title="Pièces jointes (non disponible)"><i class="fas fa-paperclip"></i></button>
                            <button type="button" class="btn-icon" title="Insérer un lien"><i class="fas fa-link"></i></button>
                            <button type="button" class="btn-icon" title="Insérer une image"><i class="fas fa-image"></i></button>
                            <button type="button" class="btn-icon" title="Signatures"><i class="fas fa-signature"></i></button>
                            <button type="button" class="btn-icon" title="Autres options"><i class="fas fa-ellipsis-v"></i></button>
                            <button type="button" class="btn-icon" title="Supprimer brouillon" id="discardDraftBtn"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                </form>
            </div>


    <div id="reportPreviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Aperçu et Dépôt du Rapport</h2>
                <button class="modal-close-btn" onclick="closeModal('reportPreviewModal')">&times;</button>
            </div>
            <div class="report-print-preview">
                <div class="preview-options-panel">
                    <h3>Options de Dépôt</h3>
                    <div class="form-group">
                        <label for="previewAcademicYear">Année Académique:</label>
                        <select id="previewAcademicYear" class="form-control" disabled>
                            </select>
                    </div>
                    <div class="form-group">
                        <label>Description:</label>
                        <textarea id="previewReportDescription" class="form-control" rows="4" disabled></textarea>
                    </div>
                    <div class="form-group">
                        <label>Destinataire(s):</label>
                        <input type="text" id="previewRecipients" class="form-control" disabled>
                    </div>
                    <p style="font-size: var(--text-xs); color: var(--gray-600); margin-top: var(--space-4);">
                        Ceci est une simulation de dépôt de document.
                    </p>
                </div>
                <div class="preview-content-area" id="reportPreviewContent">
                    </div>
            </div>
            <div class="form-actions" style="margin-top: var(--space-6);">
                <button type="button" class="btn btn-secondary" onclick="closeModal('reportPreviewModal')">Annuler</button>
                <button type="button" class="btn btn-primary" id="confirmSendReportBtn">
                    <i class="fas fa-check-circle"></i> Confirmer l'Envoi
                </button>
            </div>
        </div>
    </div>

    <div class="message-modal" id="messageAlertModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="messageAlertTitle"></h3>
                <button class="modal-close-btn" onclick="closeModal('messageAlertModal')">&times;</button>
            </div>
            <div class="modal-body" style="padding: var(--space-6);">
                <p id="messageAlertText"></p>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-primary" onclick="closeModal('messageAlertModal')">OK</button>
            </div>
        </div>
    </div>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>
    <div class="mobile-menu-overlay" id="mobileMenuOverlay"></div>

    <script>
        // Global PHP variables (passed from PHP to JS)
        const loggedInUserId = <?php echo json_encode($loggedInUserId); ?>;
        const loggedInUserType = <?php echo json_encode($loggedInUserType); ?>;
        const currentUserName = <?php echo json_encode($currentUserName); ?>;
        const currentUserEmail = <?php echo json_encode($currentUserEmail); ?>;
        const academicYears = <?php echo json_encode($anneesAcademiques); ?>;
        const studentRecipientsForDropdown = <?php echo json_encode($specificRecipientsForDropdown); ?>; // Renamed for clarity

        // DOM elements
        const mailContentArea = document.getElementById('mailContentArea');
        const mailListView = document.getElementById('mailListView');
        const mailReaderView = document.getElementById('mailReaderView');
        const mailComposerView = document.getElementById('mailComposerView');

        const composeBtn = document.getElementById('composeBtn');
        const closeComposerBtn = document.getElementById('closeComposerBtn');
        const mainComposeForm = document.getElementById('mainComposeForm');

        const recipientSearchInput = document.getElementById('recipientSearchInput'); // New search input
        const recipientDropdown = document.getElementById('recipientDropdown');       // New dropdown
        const selectedRecipientIdInput = document.getElementById('selectedRecipientId'); // Hidden input for user_id
        const selectedRecipientNumEtuInput = document.getElementById('selectedRecipientNumEtu'); // Hidden input for num_etu

        const composeSubjectInput = document.getElementById('composeSubjectInput');
        const composeMessageContent = document.getElementById('composeMessageContent');
        const reportSpecificFields = document.getElementById('reportSpecificFields');
        const reportDescription = document.getElementById('reportDescription');
        const academicYearSelect = document.getElementById('academicYearSelect');
        const depositReportBtn = document.getElementById('depositReportBtn');
        const sendMailBtn = document.getElementById('sendMailBtn'); // Kept, but hidden
        const discardDraftBtn = document.getElementById('discardDraftBtn');

        const mailList = document.getElementById('mailList');
        const selectAllMessagesCheckbox = document.getElementById('selectAllMessagesCheckbox'); // Renamed checkbox
        const readerSubject = document.getElementById('readerSubject');
        const readerAvatar = document.getElementById('readerAvatar');
        const readerSenderName = document.getElementById('readerSenderName');
        const readerSenderEmail = document.getElementById('readerSenderEmail');
        const readerDate = document.getElementById('readerDate');
        const readerContent = document.getElementById('readerContent');
        const replyBtn = document.getElementById('replyBtn');
        const forwardBtn = document.getElementById('forwardBtn');
        const closeReaderBtn = document.getElementById('closeReaderBtn');
        const downloadPdfBtn = document.getElementById('downloadPdfBtn');

        const reportPreviewModal = document.getElementById('reportPreviewModal');
        const previewAcademicYearSelect = document.getElementById('previewAcademicYear');
        const previewReportDescriptionTextarea = document.getElementById('previewReportDescription');
        const previewRecipientsInput = document.getElementById('previewRecipients');
        const reportPreviewContent = document.getElementById('reportPreviewContent');
        const confirmSendReportBtn = document.getElementById('confirmSendReportBtn');

        const messageAlertModal = document.getElementById('messageAlertModal');
        const messageAlertTitle = document.getElementById('messageAlertTitle');
        const messageAlertText = document.getElementById('messageAlertText');

        let currentFolder = 'reception';
        let currentPage = 1;
        let selectedMessages = new Set();
        let currentMessageBeingRead = null;

        function htmlspecialchars(str) {
            if (typeof str !== 'string') return str;
            return str.replace(/&/g, '&amp;')
                      .replace(/</g, '&lt;')
                      .replace(/>/g, '&gt;')
                      .replace(/"/g, '&quot;')
                      .replace(/'/g, '&#039;');
        }

        function showLoading(show) {
            document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
        }

        function showAlert(message, type = 'info', title = null) {
            messageAlertTitle.textContent = title || (type === 'success' ? 'Succès' : type === 'error' ? 'Erreur' : type === 'warning' ? 'Attention' : 'Information');
            messageAlertText.textContent = message;
            messageAlertModal.classList.add('show');
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('show');
            }
            if (modalId === 'reportPreviewModal') {
                // Not relevant for sendMailBtn here as it's hidden for this workflow
            }
            if (modalId === 'mailComposerView') {
                mainComposeForm.reset();
                // Ensure default state for report fields after closing composer
                reportDescription.setAttribute('required', 'required'); // Always required for this role's composer
                academicYearSelect.setAttribute('required', 'required'); // Always required for this role's composer
                composeSubjectInput.value = "Dépôt de Rapport de Stage"; // Reset default subject
                
                selectedRecipientIdInput.value = '';
                selectedRecipientNumEtuInput.value = '';
                recipientSearchInput.value = '';
                recipientDropdown.innerHTML = '';
            }
        }

        async function makeAjaxRequest(data) {
            const response = await fetch('messagerie_chargée_communication.php', { // Corrected filename here
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(data).toString()
            });
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        }

        function showMailListView() {
            mailContentArea.classList.remove('show-reader');
            mailComposerView.classList.remove('show');
            updateFolderCounts();
        }

        function showMailReaderView() {
            mailContentArea.classList.add('show-reader');
            mailComposerView.classList.remove('show');
        }

        function showMailComposerView() {
            mailComposerView.classList.add('show');
            mainComposeForm.reset();
            // Pre-fill subject for reports
            composeSubjectInput.value = "Dépôt de Rapport de Stage";
            composeMessageContent.value = ""; // Clear content
            
            // Ensure report-specific fields are always visible and required for this role
            reportSpecificFields.style.display = 'block'; // Or remove 'hidden' class from HTML directly
            reportDescription.setAttribute('required', 'required');
            academicYearSelect.setAttribute('required', 'required');

            // Reset recipient selection
            selectedRecipientIdInput.value = '';
            selectedRecipientNumEtuInput.value = '';
            recipientSearchInput.value = '';
            recipientDropdown.innerHTML = '';
            recipientSearchInput.focus();

            makeDraggable(mailComposerView, document.getElementById('composeHeader'));
        }

        function getAvatarInitials(name, email) {
            if (name) {
                const parts = name.split(' ');
                if (parts.length > 1) return (parts[0][0] + parts[1][0]).toUpperCase();
                return parts[0][0].toUpperCase();
            }
            if (email) return email[0].toUpperCase();
            return '?';
        }

        function formatDateTime(dateTimeStr) {
            if (!dateTimeStr) return 'N/A';
            const date = new Date(dateTimeStr);
            if (isNaN(date.getTime())) return 'N/A';
            const options = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
            return date.toLocaleDateString('fr-FR', options);
        }

        function getMessageTypeClass(type) {
            const typeClasses = {
                'partenariat': 'message-type-partenariat',
                'rapport': 'message-type-rapport',
                'evaluation': 'message-type-evaluation',
                'coordination': 'message-type-coordination',
                'demande': 'message-type-demande',
                'general': 'message-type-general',
                'personnel': 'message-type-personnel',
                'officiel': 'message-type-officiel'
            };
            return typeClasses[type] || '';
        }
        function getMessageTypeIcon(type) {
            const types = {
                'partenariat': 'fas fa-handshake',
                'rapport': 'fas fa-file-alt',
                'evaluation': 'fas fa-chart-line',
                'coordination': 'fas fa-users',
                'demande': 'fas fa-question-circle',
                'general': 'fas fa-envelope',
                'personnel': 'fas fa-user',
                'officiel': 'fas fa-gavel'
            };
            return types[type] || 'fas fa-envelope';
        }

        async function chargerMessages(folder = currentFolder, page = 1) {
            showLoading(true);
            try {
                const result = await makeAjaxRequest({
                    action: 'charger_messages',
                    dossier: folder,
                    page: page
                });

                if (result.success) {
                    displayMessagesInList(result.messages);
                    updatePaginationControls(result.page, result.pages_total, result.total);
                    updateSidebarCounts(result.counts);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des messages', 'error');
            } finally {
                showLoading(false);
            }
        }

        function displayMessagesInList(messages) {
            mailList.innerHTML = '';
            selectedMessages.clear();
            selectAllMessagesCheckbox.checked = false;
            updateToolbarButtons();

            if (messages.length === 0) {
                mailList.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                        <h3>Aucun message</h3>
                        <p>Cette boîte est vide.</p>
                    </div>
                `;
                return;
            }

            messages.forEach(message => {
                const messageEl = document.createElement('div');
                messageEl.className = `message-item ${message.is_read == 0 ? 'unread' : ''}`;
                messageEl.dataset.messageId = message.id_message;

                const senderNameDisplay = (message.sender_id_util == loggedInUserId) ? 'Moi' : (message.expediteur_prenom ? `${message.expediteur_prenom} ${message.expediteur_nom}` : message.expediteur_nom);
                const avatarInitials = getAvatarInitials(message.expediteur_nom, message.expediteur_email);
                const dateFormatee = new Date(message.sent_at).toLocaleDateString('fr-FR', { month: 'short', day: 'numeric' });

                const hasAttachmentIcon = (message.message_type === 'rapport' && message.related_report_id);

                messageEl.innerHTML = `
                    <input type="checkbox" class="message-checkbox" data-message-id="${message.id_message}">
                    <div class="message-avatar">${avatarInitials}</div>
                    <div class="message-content">
                        <div class="message-header">
                            <span class="message-sender">${htmlspecialchars(senderNameDisplay)}</span>
                            <span class="message-date">${dateFormatee}</span>
                        </div>
                        <div class="message-subject">${htmlspecialchars(message.subject)}</div>
                        <div class="message-preview">${htmlspecialchars(message.body).substring(0, 70)}...</div>
                    </div>
                    <div class="message-meta">
                        <span class="message-type ${getMessageTypeClass(message.message_type)}" title="${message.message_type}">
                            <i class="${getMessageTypeIcon(message.message_type)}"></i>
                        </span>
                        ${message.important ? '<i class="fas fa-star message-important"></i>' : ''}
                        ${hasAttachmentIcon ? '<i class="fas fa-paperclip message-attachment"></i>' : ''}
                    </div>
                `;

                messageEl.addEventListener('click', (e) => {
                    if (!e.target.classList.contains('message-checkbox') && !e.target.closest('.message-meta')) {
                        document.querySelectorAll('.message-item.selected').forEach(item => item.classList.remove('selected'));
                        messageEl.classList.add('selected');
                        lireMessage(message.id_message);
                    }
                });

                mailList.appendChild(messageEl);
            });
        }

        async function lireMessage(messageId) {
            showLoading(true);
            try {
                const result = await makeAjaxRequest({
                    action: 'lire_message',
                    message_id: messageId
                });

                if (result.success) {
                    currentMessageBeingRead = result.message;
                    displayFullMessage(result.message);
                    const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
                    if (messageEl) {
                        messageEl.classList.remove('unread');
                    }
                    updateFolderCounts();
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la lecture du message', 'error');
            } finally {
                showLoading(false);
            }
        }

        function displayFullMessage(message) {
            readerSubject.textContent = message.subject;
            readerAvatar.textContent = getAvatarInitials(message.expediteur_nom, message.expediteur_email);
            readerSenderName.textContent = message.expediteur_prenom ? `${message.expediteur_prenom} ${message.expediteur_nom}` : message.expediteur_nom;
            readerSenderEmail.textContent = `<${message.expediteur_email}>`;
            readerDate.textContent = formatDateTime(message.sent_at);

            let contentHtml = `<p>${htmlspecialchars(message.body).replace(/\n/g, '<br>')}</p>`;

            if (message.message_type === 'rapport' && message.report_description) {
                contentHtml += `
                    <div class="report-details-box">
                        <p><strong>Détails du Rapport Associé:</strong></p>
                        <p>Année Académique: ${htmlspecialchars(message.report_annee_academique_libelle) || 'N/A'}</p>
                        <p>Description: ${htmlspecialchars(message.report_description).replace(/\n/g, '<br>')}</p>
                        <p><em>(Ce rapport a été déposé via la messagerie.)</em></p>
                    </div>
                `;
                downloadPdfBtn.style.display = 'inline-block';
            } else {
                downloadPdfBtn.style.display = 'none';
            }

            readerContent.innerHTML = contentHtml;
            showMailReaderView();

            if (message.receiver_id_util == loggedInUserId) {
                replyBtn.classList.remove('hidden');
            } else {
                replyBtn.classList.add('hidden');
            }
        }

        // --- Compose Message Logic ---
        mainComposeForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            // Submission is now handled by depositReportBtn.
            // This event listener can be removed or left empty.
            // The actual send happens via confirmSendReportBtn.
        });

        // Send message function (called by submit or from report preview modal)
        async function sendFinalMessage() {
            showLoading(true);

            const recipientUserId = selectedRecipientIdInput.value;
            const recipientNumEtu = selectedRecipientNumEtuInput.value; // Get num_etu
            const recipientName = recipientSearchInput.value; // Get display name from search input

            try {
                const dataToSend = {
                    action: 'envoyer_message',
                    destinataire_id: recipientUserId, // Use single ID
                    fk_num_etu: recipientNumEtu, // Pass student's num_etu
                    sujet: composeSubjectInput.value,
                    contenu: composeMessageContent.value,
                    message_type: 'rapport', // Always 'rapport' for this flow
                    report_description: reportDescription.value,
                    academic_year_id: academicYearSelect.value
                };

                const result = await makeAjaxRequest(dataToSend);

                if (result.success) {
                    showAlert(`Rapport déposé et message envoyé à ${recipientName}.`, 'success');
                    closeModal('mailComposerView'); // Close composer after sending
                    chargerMessages(currentFolder, currentPage);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de l\'envoi du message.', 'error');
            } finally {
                showLoading(false);
            }
        }

        // --- Report Deposit Logic (Windows Print Dialog Style) ---
        let currentReportPreviewData = null;

        depositReportBtn.addEventListener('click', async function() {
            const selectedUserId = selectedRecipientIdInput.value;
            const subject = composeSubjectInput.value.trim();
            const reportDesc = reportDescription.value.trim();
            const academicYearId = academicYearSelect.value;
            const selectedAnneeText = academicYearSelect.options[academicYearSelect.selectedIndex]?.text;
            const recipientName = recipientSearchInput.value.trim();

            if (!selectedUserId) {
                showAlert('Veuillez sélectionner un étudiant destinataire.', 'warning');
                return;
            }
            if (!subject) {
                showAlert('Veuillez entrer un sujet pour le rapport.', 'warning');
                return;
            }
            if (!academicYearId) {
                showAlert('Veuillez sélectionner l\'année académique du rapport.', 'warning');
                return;
            }
            if (!reportDesc) {
                showAlert('Veuillez entrer une description pour le rapport.', 'warning');
                return;
            }
            if (!composeMessageContent.value.trim()) {
                 showAlert('Veuillez écrire un contenu de message d\'accompagnement pour le rapport.', 'warning');
                 return;
            }

            // Fetch student's num_etu using the user_id
            showLoading(true);
            let studentNumEtu = null;
            try {
                const studentDetailsResult = await makeAjaxRequest({
                    action: 'get_student_details_by_id',
                    user_id: selectedUserId
                });

                if (studentDetailsResult.success && studentDetailsResult.data) {
                    studentNumEtu = studentDetailsResult.data.num_etu;
                } else {
                    showAlert(studentDetailsResult.message || "Impossible de récupérer le numéro étudiant pour le destinataire sélectionné.", "error");
                    showLoading(false);
                    return;
                }
            } catch (error) {
                console.error('Error fetching student num_etu:', error);
                showAlert("Erreur lors de la récupération des détails de l'étudiant.", "error");
                showLoading(false);
                return;
            } finally {
                showLoading(false);
            }

            currentReportPreviewData = {
                subject: subject,
                body: composeMessageContent.value,
                academicYearText: selectedAnneeText,
                reportDescription: reportDesc,
                academicYearId: academicYearId,
                recipientUserId: selectedUserId,
                recipientNumEtu: studentNumEtu, // Store num_etu here
                recipientName: recipientName,
                messageType: 'rapport'
            };

            previewAcademicYearSelect.innerHTML = `<option value="${academicYearId}">${htmlspecialchars(selectedAnneeText)}</option>`;
            previewReportDescriptionTextarea.value = reportDesc;
            previewRecipientsInput.value = recipientName;

            reportPreviewContent.innerHTML = `
                <div style="text-align: center; margin-bottom: var(--space-8);">
                    <h3 style="color: var(--primary-800);">RAPPORT DE DÉPÔT</h3>
                    <p style="font-size: var(--text-sm); color: var(--gray-600);">Référence: ${new Date().getTime()}</p>
                </div>

                <div class="section-title">Informations Générales</div>
                <p class="preview-item"><strong>Date de Dépôt:</strong> ${new Date().toLocaleDateString('fr-FR')}</p>
                <p class="preview-item"><strong>Expéditeur:</strong> ${htmlspecialchars(currentUserName)} (${htmlspecialchars(currentUserEmail)})</p>
                <p class="preview-item"><strong>Destinataire:</strong> ${htmlspecialchars(recipientName)} (${htmlspecialchars(studentNumEtu)})</p>

                <div class="section-title">Détails du Rapport</div>
                <p class="preview-item"><strong>Sujet du Message:</strong> ${htmlspecialchars(subject)}</p>
                <p class="preview-item"><strong>Année Académique:</strong> ${htmlspecialchars(selectedAnneeText)}</p>
                <p class="preview-item"><strong>Description:</strong> ${htmlspecialchars(reportDesc).replace(/\n/g, '<br>')}</p>
                <p class="preview-item"><strong>Contenu du Message (si fourni):</strong><br>${htmlspecialchars(composeMessageContent.value || 'Aucun contenu de message spécifique fourni.').replace(/\n/g, '<br>')}</p>

                <div class="section-title">Note Importante</div>
                <p style="margin-top: var(--space-3); color: var(--gray-600); font-style: italic;">
                    Ce document confirme le dépôt conceptuel de votre rapport. Aucun fichier physique n'a été transféré via cette messagerie.
                    Le service de communication a été informé de ce dépôt.
                </p>
                <div class="preview-footer">
                    <p>Généré par SYGECOS - Le ${new Date().toLocaleDateString('fr-FR')}</p>
                </div>
            `;

            reportPreviewModal.classList.add('show');
        });

        confirmSendReportBtn.addEventListener('click', async function() {
            if (currentReportPreviewData) {
                showLoading(true);
                try {
                    const dataToSend = {
                        action: 'envoyer_message',
                        destinataire_id: currentReportPreviewData.recipientUserId,
                        fk_num_etu: currentReportPreviewData.recipientNumEtu, // Pass num_etu here
                        sujet: currentReportPreviewData.subject,
                        contenu: currentReportPreviewData.body,
                        message_type: currentReportPreviewData.messageType,
                        report_description: currentReportPreviewData.reportDescription,
                        academic_year_id: currentReportPreviewData.academicYearId
                    };
                    const result = await makeAjaxRequest(dataToSend);

                    if (result.success) {
                        showAlert('Rapport déposé et message envoyé avec succès.', 'success', 'Dépôt Réussi');
                        closeModal('mailComposerView');
                        closeModal('reportPreviewModal');
                        chargerMessages(currentFolder, currentPage);
                    } else {
                        showAlert(result.message, 'error', 'Erreur de Dépôt');
                    }
                } catch (error) {
                    showAlert('Erreur lors de la confirmation du dépôt de rapport.', 'error', 'Erreur Système');
                } finally {
                    showLoading(false);
                }
            } else {
                showAlert("Aucun rapport à envoyer. Veuillez recommencer le processus de dépôt.", "error");
                closeModal('reportPreviewModal');
            }
        });

        // --- PDF Download Functionality ---
        downloadPdfBtn.addEventListener('click', function() {
            if (currentMessageBeingRead && currentMessageBeingRead.message_type === 'rapport') {
                const pdfUrl = `generate_message_pdf.php?message_id=${currentMessageBeingRead.id_message}`; // Assuming this script exists
                window.open(pdfUrl, '_blank');
            } else {
                showAlert("Ce message n'est pas un rapport de stage ou les détails du message ne sont pas disponibles.", "warning");
            }
        });

        // --- Other UI interactions ---
        closeReaderBtn.addEventListener('click', showMailListView);

        function updateSidebarCounts(counts) {
            document.getElementById('countReception').textContent = counts.reception;
            document.getElementById('countNonLus').textContent = counts.non_lus;
            document.getElementById('countRapports').textContent = counts.rapports;
            document.getElementById('countEnvoyes').textContent = counts.envoyes;
            document.getElementById('countBrouillons').textContent = counts.brouillons;
            document.getElementById('countCorbeille').textContent = counts.corbeille;
        }

        function populateAcademicYearSelects() {
            academicYearSelect.innerHTML = '<option value="">Sélectionner</option>';
            // Also populate the preview modal's academic year select, though it will be disabled
            previewAcademicYearSelect.innerHTML = '<option value="">Sélectionner</option>';
            academicYears.forEach(year => {
                const option = document.createElement('option');
                option.value = year.id_Ac;
                option.textContent = year.annee_libelle;
                academicYearSelect.appendChild(option);

                const previewOption = document.createElement('option');
                previewOption.value = year.id_Ac;
                previewOption.textContent = year.annee_libelle;
                previewAcademicYearSelect.appendChild(previewOption);
            });
        }


        // --- New Recipient Search & Select Logic ---
        function populateRecipientDropdown(query = '') {
            recipientDropdown.innerHTML = '';
            const lowerCaseQuery = query.toLowerCase();
            const filteredRecipients = studentRecipientsForDropdown.filter(recipient =>
                recipient.name.toLowerCase().includes(lowerCaseQuery)
            );

            if (filteredRecipients.length === 0) {
                const noResultsDiv = document.createElement('div');
                noResultsDiv.className = 'recipient-dropdown-item no-results';
                noResultsDiv.textContent = 'Aucun étudiant trouvé.';
                recipientDropdown.appendChild(noResultsDiv);
                return;
            }

            filteredRecipients.forEach(recipient => {
                const item = document.createElement('div');
                item.className = 'recipient-dropdown-item';
                item.dataset.userId = recipient.id_util;
                item.dataset.numEtu = recipient.id_ref; // Assuming id_ref holds num_etu for students
                item.innerHTML = `
                    <div class="recipient-dropdown-item-avatar">${getAvatarInitials(recipient.name, recipient.email)}</div>
                    <div>${htmlspecialchars(recipient.name)} <span style="color: var(--gray-500); font-size: var(--text-xs);">(${htmlspecialchars(recipient.role)})</span></div>
                `;
                item.addEventListener('click', () => {
                    recipientSearchInput.value = recipient.name;
                    selectedRecipientIdInput.value = recipient.id_util;
                    selectedRecipientNumEtuInput.value = recipient.id_ref; // Set num_etu here
                    recipientDropdown.style.display = 'none';
                    composeSubjectInput.focus(); // Move focus to subject
                });
                recipientDropdown.appendChild(item);
            });
        }

        recipientSearchInput.addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length > 1) { // Show dropdown after 2 characters
                populateRecipientDropdown(query);
                recipientDropdown.style.display = 'block';
            } else {
                recipientDropdown.style.display = 'none';
                selectedRecipientIdInput.value = ''; // Clear selection if input is cleared
                selectedRecipientNumEtuInput.value = '';
            }
        });

        // Hide dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!recipientDropdown.contains(e.target) && e.target !== recipientSearchInput) {
                recipientDropdown.style.display = 'none';
            }
        });

        // --- Event Listeners (Rest of the script) ---
        document.addEventListener('DOMContentLoaded', function() {
            populateAcademicYearSelects();
            // populateRecipientSelect(); // Removed as replaced by search input
            populateRecipientDropdown(); // Initial population for search
            chargerMessages('reception', 1);

            document.querySelectorAll('.folder-item').forEach(item => {
                item.addEventListener('click', function() {
                    document.querySelectorAll('.folder-item').forEach(f => f.classList.remove('active'));
                    this.classList.add('active');
                    currentFolder = this.dataset.folder;
                    currentPage = 1;
                    chargerMessages(currentFolder, currentPage);
                    showMailListView();
                });
            });

            // No change event listener for recipientSelect as it's replaced by search input
            // reportSpecificFields is always visible for this user role

            document.getElementById('refreshBtn').addEventListener('click', () => chargerMessages(currentFolder, currentPage));
            document.getElementById('markReadBtn').addEventListener('click', async function() {
                if (selectedMessages.size === 0) return;
                await makeAjaxRequest({ action: 'marquer_lu', message_ids: Array.from(selectedMessages), statut: 'lu' });
                chargerMessages(currentFolder, currentPage);
                showAlert('Messages marqués comme lus.', 'success');
            });
            document.getElementById('deleteBtn').addEventListener('click', async function() {
                if (selectedMessages.size === 0) return;
                if (!confirm(`Supprimer ${selectedMessages.size} message(s) sélectionné(s) ?`)) return;
                await makeAjaxRequest({ action: 'supprimer_messages', message_ids: Array.from(selectedMessages) });
                chargerMessages(currentFolder, currentPage);
                showAlert('Messages supprimés.', 'success');
            });

            selectAllMessagesCheckbox.addEventListener('change', function() { // Corrected ID
                const checkboxes = document.querySelectorAll('.message-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = this.checked;
                    if (this.checked) {
                        selectedMessages.add(cb.dataset.messageId);
                    } else {
                        selectedMessages.delete(cb.dataset.messageId);
                    }
                });
                updateToolbarButtons();
            });

            mailList.addEventListener('change', function(e) {
                if (e.target.classList.contains('message-checkbox')) {
                    const messageId = e.target.dataset.messageId;
                    if (e.target.checked) {
                        selectedMessages.add(messageId);
                    } else {
                        selectedMessages.delete(messageId);
                    }
                    updateToolbarButtons();
                }
            });

            function updateToolbarButtons() {
                const hasSelection = selectedMessages.size > 0;
                document.getElementById('markReadBtn').disabled = !hasSelection;
                document.getElementById('deleteBtn').disabled = !hasSelection;
            }

            document.getElementById('prevPageBtn').addEventListener('click', function() { if (currentPage > 1) { currentPage--; chargerMessages(currentFolder, currentPage); } });
            document.getElementById('nextPageBtn').addEventListener('click', function() { currentPage++; chargerMessages(currentFolder, currentPage); } );
            function updatePaginationControls(page, pagesTotal, total) {
                document.getElementById('messagesPagination').textContent =
                    `${((page - 1) * 20) + 1}-${Math.min(page * 20, total)} sur ${total}`;
                document.getElementById('prevPageBtn').disabled = page <= 1;
                document.getElementById('nextPageBtn').disabled = page >= pagesTotal;
                if (document.getElementById('searchInput').value.trim().length > 0) {
                     document.getElementById('prevPageBtn').disabled = true;
                     document.getElementById('nextPageBtn').disabled = true;
                }
            }

            let searchTimeout;
            document.getElementById('searchInput').addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                if (query.length === 0) {
                    chargerMessages(currentFolder, 1);
                    return;
                }
                searchTimeout = setTimeout(async () => {
                    const result = await makeAjaxRequest({ action: 'rechercher_messages', query: query });
                    if (result.success) {
                        displayMessagesInList(result.messages);
                        document.getElementById('messagesPagination').textContent = `${result.total} résultat(s) pour "${htmlspecialchars(query)}"`;
                        document.getElementById('prevPageBtn').disabled = true;
                        document.getElementById('nextPageBtn').disabled = true;
                    } else {
                        showAlert(result.message, 'error');
                    }
                }, 300);
            });

            replyBtn.addEventListener('click', async function() {
                if (!currentMessageBeingRead) {
                    showAlert("Veuillez sélectionner un message pour y répondre.", "warning");
                    return;
                }
                const originalMessage = currentMessageBeingRead;

                showMailComposerView();
                const senderUserId = originalMessage.sender_id_util;
                if (senderUserId) {
                    // Try to find the sender in the studentRecipientsForDropdown
                    const foundRecipient = studentRecipientsForDropdown.find(rec => rec.id_util == senderUserId);
                    if (foundRecipient) {
                        recipientSearchInput.value = foundRecipient.name;
                        selectedRecipientIdInput.value = foundRecipient.id_util;
                        selectedRecipientNumEtuInput.value = foundRecipient.id_ref; // Assuming id_ref for num_etu
                    } else {
                        showAlert("Impossible de trouver l'expéditeur original dans la liste des étudiants (réponse non supportée pour les non-étudiants).", "warning");
                        recipientSearchInput.value = ''; // Clear selection if not a student
                        selectedRecipientIdInput.value = '';
                        selectedRecipientNumEtuInput.value = '';
                    }
                } else {
                    showAlert("L'expéditeur original n'a pas d'ID utilisateur associé, impossible de répondre via ce système.", "warning");
                    recipientSearchInput.value = '';
                    selectedRecipientIdInput.value = '';
                    selectedRecipientNumEtuInput.value = '';
                }

                let replySubject = originalMessage.subject;
                if (!replySubject.startsWith('Re: ')) {
                    replySubject = `Re: ${replySubject}`;
                }
                composeSubjectInput.value = replySubject;

                const originalBodyPreview = originalMessage.body.split('\n').map(line => `> ${line}`).join('\n');
                composeMessageContent.value = `\n\n---------- Message Original ---------\nDe: ${originalMessage.expediteur_prenom ? `${originalMessage.expediteur_prenom} ${originalMessage.expediteur_nom}` : originalMessage.expediteur_nom} <${originalMessage.expediteur_email}>\nDate: ${formatDateTime(originalMessage.sent_at)}\nSujet: ${originalMessage.subject}\n\n${originalBodyPreview}`;
                composeMessageContent.focus();
            });

            forwardBtn.addEventListener('click', function() {
                if (!currentMessageBeingRead) {
                    showAlert("Veuillez sélectionner un message à transférer.", "warning");
                    return;
                }
                const originalMessage = currentMessageBeingRead;

                showMailComposerView();
                recipientSearchInput.value = ''; // Clear recipient for forwarding
                selectedRecipientIdInput.value = '';
                selectedRecipientNumEtuInput.value = '';

                let forwardSubject = originalMessage.subject;
                if (!forwardSubject.startsWith('Fwd: ')) {
                    forwardSubject = `Fwd: ${forwardSubject}`;
                }
                composeSubjectInput.value = forwardSubject;

                const originalBodyPreview = originalMessage.body.split('\n').map(line => `> ${line}`).join('\n');
                composeMessageContent.value = `\n\n---------- Message Transféré ---------\nDe: ${originalMessage.expediteur_prenom ? `${originalMessage.expediteur_prenom} ${originalMessage.expediteur_nom}` : originalMessage.expediteur_nom} <${originalMessage.expediteur_email}>\nDate: ${formatDateTime(originalMessage.sent_at)}\nSujet: ${originalMessage.subject}\n\n${originalBodyPreview}`;
                recipientSearchInput.focus();
            });

            composeBtn.addEventListener('click', showMailComposerView);
            closeComposerBtn.addEventListener('click', () => closeModal('mailComposerView'));
            discardDraftBtn.addEventListener('click', () => closeModal('mailComposerView'));


            chargerMessages('reception', 1);
            setTimeout(() => {
                showAlert('Messagerie chargée - Chargé de Communication', 'success');
            }, 1000);
        });

        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

        if (sidebarToggle && sidebar && mainContent) {
            handleResponsiveLayout();
            sidebarToggle.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.toggle('mobile-open');
                    mobileMenuOverlay.classList.toggle('active');
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
                const barsIcon = sidebarToggle.querySelector('.fa-bars');
                const timesIcon = sidebarToggle.querySelector('.fa-times');
                if (barsIcon) barsIcon.style.display = 'inline-block';
                if (timesIcon) timesIcon.style.display = 'none';
            });
        }
        window.addEventListener('resize', handleResponsiveLayout);
        function handleResponsiveLayout() {
            const isMobile = window.innerWidth < 768;
            if (isMobile) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('sidebar-collapsed');
                sidebar.classList.remove('mobile-open');
                mobileMenuOverlay.classList.remove('active');
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('sidebar-collapsed');
                sidebar.classList.remove('mobile-open');
                mobileMenuOverlay.classList.remove('active');
            }
            if (sidebarToggle) {
                const barsIcon = sidebarToggle.querySelector('.fa-bars');
                const timesIcon = sidebarToggle.querySelector('.fa-times');
                if (isMobile) {
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                } else {
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                }
                if (sidebar.classList.contains('mobile-open')) {
                    if (barsIcon) barsIcon.style.display = 'none';
                    if (timesIcon) timesIcon.style.display = 'inline-block';
                }
            }
        }

        function makeDraggable(element, handle) {
            let pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;

            if (handle) {
                handle.onmousedown = dragMouseDown;
            } else {
                element.onmousedown = dragMouseDown;
            }

            function dragMouseDown(e) {
                e = e || window.event;
                e.preventDefault();
                pos3 = e.clientX;
                pos4 = e.clientY;
                document.onmouseup = closeDragElement;
                document.onmousemove = elementDrag;
            }

            function elementDrag(e) {
                e = e || window.event;
                e.preventDefault();
                pos1 = pos3 - e.clientX;
                pos2 = pos4 - e.clientY;
                pos3 = e.clientX;
                pos4 = e.clientY;

                let newTop = element.offsetTop - pos2;
                let newLeft = element.offsetLeft - pos1;

                const headerHeight = document.querySelector('.topbar').offsetHeight;
                const windowWidth = window.innerWidth;
                const windowHeight = window.innerHeight;

                newTop = Math.max(headerHeight, newTop);
                newLeft = Math.max(0, newLeft);

                newTop = Math.min(windowHeight - element.offsetHeight, newTop);
                newLeft = Math.min(windowWidth - element.offsetWidth, newLeft);

                element.style.top = newTop + "px";
                element.style.left = newLeft + "px";
                element.style.right = "auto";
                element.style.bottom = "auto";
            }

            function closeDragElement() {
                document.onmouseup = null;
                document.onmousemove = null;
            }
        }
    </script>
</body>
</html>