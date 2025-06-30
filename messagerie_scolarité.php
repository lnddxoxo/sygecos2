<?php
// messagerie.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

// Récupération des informations utilisateur connecté
$currentUser = getCurrentUser(); // Fonction à adapter selon votre système d'auth

// Récupération des contacts (enseignants, personnel, étudiants selon les droits)
$contacts = [];
try {
    // Enseignants
    $stmt = $pdo->query("
        SELECT 'enseignant' as type, id_ens as id, CONCAT(nom_ens, ' ', prenom_ens) as nom, email, 'Enseignant' as role
        FROM enseignant 
        ORDER BY nom_ens, prenom_ens
    ");
    $enseignants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Personnel administratif
    $stmt = $pdo->query("
        SELECT 'personnel' as type, id_pers as id, CONCAT(nom_pers, ' ', prenoms_pers) as nom, email_pers as email, poste as role
        FROM personnel_admin 
        ORDER BY nom_pers, prenoms_pers
    ");
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Étudiants (selon les droits)
    $stmt = $pdo->query("
        SELECT 'etudiant' as type, num_etu as id, CONCAT(nom_etu, ' ', prenoms_etu) as nom, email_etu as email, 
               CONCAT(ne.lib_niv_etu, ' - ', f.lib_filiere) as role
        FROM etudiant e
        LEFT JOIN niveau_etude ne ON e.fk_id_niv_etu = ne.id_niv_etu
        LEFT JOIN filiere f ON e.fk_id_filiere = f.id_filiere
        ORDER BY nom_etu, prenoms_etu
        LIMIT 100
    ");
    $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $contacts = array_merge($enseignants, $personnel, $etudiants);
    
} catch (PDOException $e) {
    error_log("Erreur récupération contacts: " . $e->getMessage());
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
                $limite = 20;
                $offset = ($page - 1) * $limite;
                
                // Simulation de messages (à adapter avec votre vraie table messages)
                $messages = [
                    [
                        'id' => 1,
                        'expediteur_nom' => 'KOUA Brou',
                        'expediteur_email' => 'bkoua@sygecos.edu',
                        'destinataire_nom' => 'Vous',
                        'sujet' => 'Convocation réunion pédagogique',
                        'apercu' => 'Vous êtes convoqué(e) à la réunion pédagogique qui se tiendra le vendredi 30 juin...',
                        'date_envoi' => '2025-06-28 14:30:00',
                        'lu' => false,
                        'important' => true,
                        'piece_jointe' => true,
                        'type_message' => 'officiel'
                    ],
                    [
                        'id' => 2,
                        'expediteur_nom' => 'Durand Komenan',
                        'expediteur_email' => 'kdurand@sygecos.edu',
                        'destinataire_nom' => 'Vous',
                        'sujet' => 'Mise à jour liste étudiants éligibles M2',
                        'apercu' => 'Bonjour, Veuillez trouver en pièce jointe la liste mise à jour des étudiants éligibles...',
                        'date_envoi' => '2025-06-28 10:15:00',
                        'lu' => true,
                        'important' => false,
                        'piece_jointe' => true,
                        'type_message' => 'travail'
                    ],
                    [
                        'id' => 3,
                        'expediteur_nom' => 'LOGBO Jamil',
                        'expediteur_email' => 'jlogbo@etudiant.sygecos.edu',
                        'destinataire_nom' => 'Vous',
                        'sujet' => 'Demande de rendez-vous - Rapport de stage',
                        'apercu' => 'Bonjour Madame/Monsieur, Je souhaiterais solliciter un rendez-vous concernant...',
                        'date_envoi' => '2025-06-27 16:45:00',
                        'lu' => true,
                        'important' => false,
                        'piece_jointe' => false,
                        'type_message' => 'etudiant'
                    ]
                ];
                
                // Filtrer selon le dossier
                if ($dossier === 'non_lus') {
                    $messages = array_filter($messages, function($m) { return !$m['lu']; });
                } elseif ($dossier === 'importants') {
                    $messages = array_filter($messages, function($m) { return $m['important']; });
                }
                
                echo json_encode([
                    'success' => true, 
                    'messages' => array_slice($messages, $offset, $limite),
                    'total' => count($messages),
                    'page' => $page,
                    'pages_total' => ceil(count($messages) / $limite)
                ]);
                break;
                
            case 'lire_message':
                $messageId = intval($_POST['message_id'] ?? 0);
                
                // Simulation du contenu complet du message
                $messageComplet = [
                    'id' => $messageId,
                    'expediteur_nom' => 'KOUA Brou',
                    'expediteur_email' => 'bkoua@sygecos.edu',
                    'expediteur_avatar' => 'KB',
                    'destinataires' => ['Vous'],
                    'sujet' => 'Convocation réunion pédagogique',
                    'date_envoi' => '2025-06-28 14:30:00',
                    'contenu' => "
                        <p>Bonjour,</p>
                        <p>Vous êtes convoqué(e) à la <strong>réunion pédagogique</strong> qui se tiendra :</p>
                        <ul>
                            <li><strong>Date :</strong> Vendredi 30 juin 2025</li>
                            <li><strong>Heure :</strong> 14h00 - 17h00</li>
                            <li><strong>Lieu :</strong> Salle de conférence - Bâtiment principal</li>
                        </ul>
                        <p><strong>Ordre du jour :</strong></p>
                        <ol>
                            <li>Bilan de l'année académique 2024-2025</li>
                            <li>Préparation de la rentrée 2025-2026</li>
                            <li>Mise à jour des programmes pédagogiques</li>
                            <li>Questions diverses</li>
                        </ol>
                        <p>Votre présence est <em>obligatoire</em>. Merci de confirmer votre participation.</p>
                        <p>Cordialement,<br>
                        <strong>KOUA Brou</strong><br>
                        Responsable pédagogique</p>
                    ",
                    'pieces_jointes' => [
                        ['nom' => 'Ordre_du_jour_reunion.pdf', 'taille' => '245 KB'],
                        ['nom' => 'Planning_rentree_2025.xlsx', 'taille' => '87 KB']
                    ],
                    'important' => true,
                    'lu' => false
                ];
                
                echo json_encode(['success' => true, 'message' => $messageComplet]);
                break;
                
            case 'envoyer_message':
                $destinataires = $_POST['destinataires'] ?? [];
                $sujet = trim($_POST['sujet'] ?? '');
                $contenu = trim($_POST['contenu'] ?? '');
                $priorite = $_POST['priorite'] ?? 'normale';
                $typeMessage = $_POST['type_message'] ?? 'personnel';
                
                if (empty($destinataires) || empty($sujet) || empty($contenu)) {
                    throw new Exception("Tous les champs sont obligatoires");
                }
                
                // Simulation de l'envoi
                // Ici vous implémenteriez l'insertion en base de données
                
                $messageId = rand(1000, 9999); // Simulation d'un ID
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Message envoyé avec succès',
                    'message_id' => $messageId,
                    'destinataires_count' => count($destinataires)
                ]);
                break;
                
            case 'marquer_lu':
                $messageIds = $_POST['message_ids'] ?? [];
                $statut = $_POST['statut'] ?? 'lu'; // 'lu' ou 'non_lu'
                
                // Simulation de la mise à jour
                echo json_encode([
                    'success' => true,
                    'message' => count($messageIds) . ' message(s) marqué(s) comme ' . $statut,
                    'updated_count' => count($messageIds)
                ]);
                break;
                
            case 'supprimer_messages':
                $messageIds = $_POST['message_ids'] ?? [];
                
                // Simulation de la suppression (déplacer vers corbeille)
                echo json_encode([
                    'success' => true,
                    'message' => count($messageIds) . ' message(s) supprimé(s)',
                    'deleted_count' => count($messageIds)
                ]);
                break;
                
            case 'rechercher_messages':
                $query = trim($_POST['query'] ?? '');
                $filtres = $_POST['filtres'] ?? [];
                
                // Simulation de recherche
                $resultats = [
                    [
                        'id' => 1,
                        'expediteur_nom' => 'KOUA Brou',
                        'sujet' => 'Convocation réunion pédagogique',
                        'apercu' => 'Réunion pédagogique vendredi...',
                        'date_envoi' => '2025-06-28 14:30:00',
                        'pertinence' => 95
                    ]
                ];
                
                echo json_encode([
                    'success' => true,
                    'resultats' => $resultats,
                    'total' => count($resultats),
                    'query' => $query
                ]);
                break;
                
            default:
                throw new Exception("Action non reconnue");
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

function getCurrentUser() {
    // Simulation - à adapter selon votre système d'auth
    return [
        'id' => 1,
        'nom' => 'Utilisateur',
        'email' => 'user@sygecos.edu',
        'type' => 'personnel',
        'role' => 'Responsable scolarité'
    ];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Messagerie</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Styles de base identiques */
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
        body { font-family: var(--font-primary); background-color: var(--gray-50); color: var(--gray-800); overflow: hidden; }
        .admin-layout { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: var(--sidebar-width); transition: margin-left var(--transition-normal); }
        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed-width); }

        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-width); height: 100vh; background: linear-gradient(180deg, var(--primary-800) 0%, var(--primary-900) 100%); color: white; z-index: 1000; transition: all var(--transition-normal); overflow-y: auto; overflow-x: hidden; }
        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .topbar { height: var(--topbar-height); background: var(--white); border-bottom: 1px solid var(--gray-200); padding: 0 var(--space-6); display: flex; align-items: center; justify-content: space-between; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; }

        /* === LAYOUT MESSAGERIE === */
        .messagerie-container { display: flex; height: calc(100vh - var(--topbar-height)); }
        
        /* Sidebar messagerie */
        .mail-sidebar { width: 280px; background: var(--white); border-right: 1px solid var(--gray-200); display: flex; flex-direction: column; }
        .mail-sidebar-header { padding: var(--space-6); border-bottom: 1px solid var(--gray-200); }
        .compose-btn { width: 100%; padding: var(--space-3) var(--space-4); background: var(--accent-600); color: white; border: none; border-radius: var(--radius-lg); font-weight: 600; cursor: pointer; transition: all var(--transition-fast); display: flex; align-items: center; justify-content: center; gap: var(--space-2); }
        .compose-btn:hover { background: var(--accent-700); transform: translateY(-1px); }

        .mail-folders { flex: 1; padding: var(--space-4) 0; }
        .folder-item { display: flex; align-items: center; padding: var(--space-3) var(--space-6); cursor: pointer; transition: all var(--transition-fast); position: relative; }
        .folder-item:hover { background: var(--gray-50); }
        .folder-item.active { background: var(--accent-50); color: var(--accent-700); border-right: 3px solid var(--accent-600); }
        .folder-icon { width: 20px; margin-right: var(--space-3); }
        .folder-name { flex: 1; }
        .folder-count { background: var(--gray-300); color: var(--gray-700); padding: var(--space-1) var(--space-2); border-radius: var(--radius-sm); font-size: var(--text-xs); font-weight: 600; }
        .folder-item.active .folder-count { background: var(--accent-200); color: var(--accent-800); }

        /* Zone principale */
        .mail-main { flex: 1; display: flex; flex-direction: column; background: var(--white); }
        .mail-toolbar { padding: var(--space-4) var(--space-6); border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; gap: var(--space-4); }
        .toolbar-left { display: flex; align-items: center; gap: var(--space-3); }
        .toolbar-right { display: flex; align-items: center; gap: var(--space-3); }

        .search-box { position: relative; flex: 1; max-width: 400px; }
        .search-box input { width: 100%; padding: var(--space-3) var(--space-3) var(--space-3) 40px; border: 1px solid var(--gray-300); border-radius: var(--radius-lg); font-size: var(--text-sm); }
        .search-box .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--gray-400); }

        .btn { padding: var(--space-2) var(--space-4); border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 500; cursor: pointer; transition: all var(--transition-fast); border: none; display: inline-flex; align-items: center; gap: var(--space-2); }
        .btn-primary { background-color: var(--accent-600); color: white; } .btn-primary:hover { background-color: var(--accent-700); }
        .btn-secondary { background-color: var(--gray-200); color: var(--gray-700); } .btn-secondary:hover { background-color: var(--gray-300); }
        .btn-icon { padding: var(--space-2); width: 36px; height: 36px; }

        /* Liste des messages */
        .mail-list { flex: 1; overflow-y: auto; }
        .message-item { display: flex; align-items: center; padding: var(--space-4) var(--space-6); border-bottom: 1px solid var(--gray-100); cursor: pointer; transition: all var(--transition-fast); position: relative; }
        .message-item:hover { background: var(--gray-50); }
        .message-item.selected { background: var(--accent-50); border-right: 3px solid var(--accent-600); }
        .message-item.unread { background: var(--white); font-weight: 600; }
        .message-item.unread::before { content: ''; position: absolute; left: var(--space-3); top: 50%; transform: translateY(-50%); width: 8px; height: 8px; background: var(--accent-600); border-radius: 50%; }

        .message-checkbox { margin-right: var(--space-3); }
        .message-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--accent-500); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; margin-right: var(--space-4); }
        .message-content { flex: 1; min-width: 0; }
        .message-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-1); }
        .message-sender { font-weight: 600; color: var(--gray-900); }
        .message-date { font-size: var(--text-xs); color: var(--gray-500); }
        .message-subject { font-weight: 500; color: var(--gray-900); margin-bottom: var(--space-1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .message-preview { font-size: var(--text-sm); color: var(--gray-600); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .message-meta { display: flex; align-items: center; gap: var(--space-2); margin-left: var(--space-3); }
        .message-important { color: var(--warning-500); }
        .message-attachment { color: var(--gray-500); }

        /* Zone de lecture */
        .mail-reader { flex: 1; background: var(--white); border-left: 1px solid var(--gray-200); display: none; flex-direction: column; }
        .mail-reader.active { display: flex; }
        .reader-header { padding: var(--space-6); border-bottom: 1px solid var(--gray-200); }
        .reader-subject { font-size: var(--text-2xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--space-4); }
        .reader-meta { display: flex; align-items: center; justify-content: space-between; }
        .reader-sender { display: flex; align-items: center; gap: var(--space-3); }
        .reader-sender-avatar { width: 48px; height: 48px; border-radius: 50%; background: var(--accent-500); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; }
        .reader-sender-info h4 { font-weight: 600; color: var(--gray-900); margin-bottom: var(--space-1); }
        .reader-sender-info p { font-size: var(--text-sm); color: var(--gray-600); }
        .reader-actions { display: flex; gap: var(--space-2); }

        .reader-content { flex: 1; padding: var(--space-6); overflow-y: auto; }
        .reader-content p { margin-bottom: var(--space-4); line-height: 1.6; }
        .reader-content ul, .reader-content ol { margin: var(--space-4) 0; padding-left: var(--space-6); }
        .reader-content li { margin-bottom: var(--space-2); }

        .attachments { margin-top: var(--space-6); padding: var(--space-4); background: var(--gray-50); border-radius: var(--radius-lg); }
        .attachments h5 { margin-bottom: var(--space-3); color: var(--gray-700); }
        .attachment-item { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); background: var(--white); border-radius: var(--radius-md); margin-bottom: var(--space-2); }
        .attachment-icon { color: var(--error-500); }
        .attachment-info { flex: 1; }
        .attachment-name { font-weight: 500; color: var(--gray-900); }
        .attachment-size { font-size: var(--text-xs); color: var(--gray-500); }

        /* Modal de composition */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: var(--white); margin: 2% auto; border-radius: var(--radius-xl); width: 90%; max-width: 800px; box-shadow: var(--shadow-xl); max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: var(--space-6); border-bottom: 1px solid var(--gray-200); }
        .modal-title { font-size: var(--text-xl); font-weight: 600; color: var(--gray-900); }
        .close { color: var(--gray-400); font-size: 24px; font-weight: bold; cursor: pointer; transition: color var(--transition-fast); }
        .close:hover { color: var(--gray-600); }

        .compose-form { flex: 1; display: flex; flex-direction: column; }
        .compose-fields { padding: var(--space-6); border-bottom: 1px solid var(--gray-200); }
        .compose-field { display: flex; align-items: center; margin-bottom: var(--space-4); }
        .compose-field label { width: 100px; font-weight: 500; color: var(--gray-700); }
        .compose-field input, .compose-field select { flex: 1; padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); }

        .compose-editor { flex: 1; padding: var(--space-6); }
        .compose-editor textarea { width: 100%; height: 300px; border: 1px solid var(--gray-300); border-radius: var(--radius-md); padding: var(--space-4); resize: vertical; font-family: inherit; line-height: 1.5; }

        .compose-footer { padding: var(--space-6); border-top: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; }
        .compose-actions { display: flex; gap: var(--space-3); }

        /* Contact picker */
        .contact-picker { position: relative; }
        .contact-suggestions { position: absolute; top: 100%; left: 0; right: 0; background: var(--white); border: 1px solid var(--gray-300); border-top: none; border-radius: 0 0 var(--radius-md) var(--radius-md); max-height: 200px; overflow-y: auto; z-index: 10; display: none; }
        .contact-suggestion { padding: var(--space-3); cursor: pointer; display: flex; align-items: center; gap: var(--space-3); }
        .contact-suggestion:hover { background: var(--gray-50); }
        .contact-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--accent-500); color: white; display: flex; align-items: center; justify-content: center; font-size: var(--text-sm); font-weight: 600; }
        .contact-info { flex: 1; }
        .contact-name { font-weight: 500; color: var(--gray-900); }
        .contact-role { font-size: var(--text-xs); color: var(--gray-500); }

        /* Messages d'alerte */
        .alert { padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-4); display: none; }
        .alert.success { background-color: var(--secondary-50); color: var(--secondary-600); border: 1px solid var(--secondary-100); }
        .alert.error { background-color: #fef2f2; color: var(--error-500); border: 1px solid #fecaca; }
        .alert.info { background-color: var(--accent-50); color: var(--accent-700); border: 1px solid var(--accent-200); }

        .loading { opacity: 0.6; pointer-events: none; }
        .spinner { width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid var(--accent-500); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Messages vides */
        .empty-state { text-align: center; padding: var(--space-16); color: var(--gray-500); }
        .empty-state .empty-icon { font-size: 4rem; margin-bottom: var(--space-4); }
        .empty-state h3 { font-size: var(--text-xl); margin-bottom: var(--space-2); }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .messagerie-container { flex-direction: column; }
            .mail-sidebar { width: 100%; max-height: 200px; }
            .mail-main { height: calc(100vh - var(--topbar-height) - 200px); }
            .mail-reader { position: fixed; top: var(--topbar-height); left: 0; right: 0; bottom: 0; z-index: 100; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_respo_scolarité.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <!-- Message d'alerte -->
            <div id="alertMessage" class="alert" style="margin: var(--space-4);"></div>

            <div class="messagerie-container">
                <!-- Sidebar messagerie -->
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
                            <span class="folder-count" id="countReception">3</span>
                        </div>
                        <div class="folder-item" data-folder="non_lus">
                            <i class="fas fa-envelope folder-icon"></i>
                            <span class="folder-name">Non lus</span>
                            <span class="folder-count" id="countNonLus">1</span>
                        </div>
                        <div class="folder-item" data-folder="importants">
                            <i class="fas fa-star folder-icon"></i>
                            <span class="folder-name">Importants</span>
                            <span class="folder-count" id="countImportants">1</span>
                        </div>
                        <div class="folder-item" data-folder="envoyes">
                            <i class="fas fa-paper-plane folder-icon"></i>
                            <span class="folder-name">Envoyés</span>
                            <span class="folder-count">7</span>
                        </div>
                        <div class="folder-item" data-folder="brouillons">
                            <i class="fas fa-edit folder-icon"></i>
                            <span class="folder-name">Brouillons</span>
                            <span class="folder-count">2</span>
                        </div>
                        <div class="folder-item" data-folder="corbeille">
                            <i class="fas fa-trash folder-icon"></i>
                            <span class="folder-name">Corbeille</span>
                            <span class="folder-count">12</span>
                        </div>
                    </div>
                </div>

                <!-- Zone principale -->
                <div class="mail-main">
                    <!-- Barre d'outils -->
                    <div class="mail-toolbar">
                        <div class="toolbar-left">
                            <label style="display: flex; align-items: center; gap: var(--space-2);">
                                <input type="checkbox" id="selectAll">
                                Tout sélectionner
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
                            <input type="text" id="searchInput" placeholder="Rechercher dans les messages...">
                        </div>

                        <div class="toolbar-right">
                            <span id="messagesPagination">1-20 sur 45</span>
                            <button class="btn btn-icon" id="prevPageBtn" disabled>
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="btn btn-icon" id="nextPageBtn">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Liste des messages -->
                    <div class="mail-list" id="mailList">
                        <!-- Messages chargés dynamiquement -->
                    </div>
                </div>

                <!-- Zone de lecture -->
                <div class="mail-reader" id="mailReader">
                    <div class="reader-header">
                        <div class="reader-subject" id="readerSubject">Sélectionnez un message</div>
                        <div class="reader-meta">
                            <div class="reader-sender">
                                <div class="reader-sender-avatar" id="readerAvatar">?</div>
                                <div class="reader-sender-info">
                                    <h4 id="readerSenderName">Expéditeur</h4>
                                    <p id="readerSenderEmail">email@example.com</p>
                                    <p id="readerDate">Date</p>
                                </div>
                            </div>
                            <div class="reader-actions">
                                <button class="btn btn-secondary" id="replyBtn">
                                    <i class="fas fa-reply"></i> Répondre
                                </button>
                                <button class="btn btn-secondary" id="forwardBtn">
                                    <i class="fas fa-share"></i> Transférer
                                </button>
                                <button class="btn btn-icon" id="closeReaderBtn">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="reader-content" id="readerContent">
                        <!-- Contenu du message -->
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal de composition -->
    <div id="composeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Nouveau message</h3>
                <span class="close" onclick="closeModal('composeModal')">&times;</span>
            </div>
            <form class="compose-form" id="composeForm">
                <div class="compose-fields">
                    <div class="compose-field">
                        <label>À :</label>
                        <div class="contact-picker">
                            <input type="text" id="recipientsInput" placeholder="Nom ou email des destinataires..." autocomplete="off">
                            <div class="contact-suggestions" id="contactSuggestions">
                                <!-- Suggestions générées dynamiquement -->
                            </div>
                        </div>
                    </div>
                    <div class="compose-field">
                        <label>Sujet :</label>
                        <input type="text" id="subjectInput" placeholder="Objet du message" required>
                    </div>
                    <div class="compose-field">
                        <label>Priorité :</label>
                        <select id="prioritySelect">
                            <option value="normale">Normale</option>
                            <option value="importante">Importante</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                    <div class="compose-field">
                        <label>Type :</label>
                        <select id="typeSelect">
                            <option value="personnel">Personnel</option>
                            <option value="officiel">Officiel</option>
                            <option value="diffusion">Diffusion</option>
                        </select>
                    </div>
                </div>
                <div class="compose-editor">
                    <textarea id="messageContent" placeholder="Tapez votre message ici..." required></textarea>
                </div>
                <div class="compose-footer">
                    <div>
                        <button type="button" class="btn btn-secondary" id="attachBtn">
                            <i class="fas fa-paperclip"></i> Joindre un fichier
                        </button>
                        <input type="file" id="attachmentInput" multiple style="display: none;">
                    </div>
                    <div class="compose-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('composeModal')">Annuler</button>
                        <button type="button" class="btn btn-secondary" id="saveDraftBtn">
                            <i class="fas fa-save"></i> Brouillon
                        </button>
                        <button type="submit" class="btn btn-primary" id="sendBtn">
                            <i class="fas fa-paper-plane"></i> Envoyer
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Variables globales
        let currentFolder = 'reception';
        let currentPage = 1;
        let selectedMessages = new Set();
        let contacts = <?php echo json_encode($contacts); ?>;
        let selectedContacts = [];

        // Fonctions utilitaires
        function showAlert(message, type = 'info') {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.textContent = message;
            alertDiv.className = `alert ${type}`;
            alertDiv.style.display = 'block';
            
            setTimeout(() => {
                alertDiv.style.display = 'none';
            }, 5000);
        }

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

        // Chargement des messages
        async function chargerMessages(folder = currentFolder, page = 1) {
            try {
                const result = await makeAjaxRequest({
                    action: 'charger_messages',
                    dossier: folder,
                    page: page
                });
                
                if (result.success) {
                    afficherMessages(result.messages);
                    mettreAJourPagination(result.page, result.pages_total, result.total);
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors du chargement des messages', 'error');
            }
        }

        // Affichage des messages
        function afficherMessages(messages) {
            const mailList = document.getElementById('mailList');
            
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
            
            mailList.innerHTML = '';
            
            messages.forEach(message => {
                const messageEl = document.createElement('div');
                messageEl.className = `message-item ${!message.lu ? 'unread' : ''}`;
                messageEl.dataset.messageId = message.id;
                
                const avatar = message.expediteur_nom ? message.expediteur_nom.split(' ').map(n => n[0]).join('').toUpperCase() : '?';
                const dateFormatee = new Date(message.date_envoi).toLocaleDateString('fr-FR', {
                    day: '2-digit',
                    month: 'short',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                messageEl.innerHTML = `
                    <input type="checkbox" class="message-checkbox" data-message-id="${message.id}">
                    <div class="message-avatar">${avatar}</div>
                    <div class="message-content">
                        <div class="message-header">
                            <span class="message-sender">${message.expediteur_nom}</span>
                            <span class="message-date">${dateFormatee}</span>
                        </div>
                        <div class="message-subject">${message.sujet}</div>
                        <div class="message-preview">${message.apercu}</div>
                    </div>
                    <div class="message-meta">
                        ${message.important ? '<i class="fas fa-star message-important"></i>' : ''}
                        ${message.piece_jointe ? '<i class="fas fa-paperclip message-attachment"></i>' : ''}
                    </div>
                `;
                
                messageEl.addEventListener('click', (e) => {
                    if (!e.target.classList.contains('message-checkbox')) {
                        lireMessage(message.id);
                    }
                });
                
                mailList.appendChild(messageEl);
            });
        }

        // Lecture d'un message
        async function lireMessage(messageId) {
            try {
                const result = await makeAjaxRequest({
                    action: 'lire_message',
                    message_id: messageId
                });
                
                if (result.success) {
                    afficherMessageComplet(result.message);
                    // Marquer comme lu visuellement
                    const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
                    if (messageEl) {
                        messageEl.classList.remove('unread');
                    }
                } else {
                    showAlert(result.message, 'error');
                }
            } catch (error) {
                showAlert('Erreur lors de la lecture du message', 'error');
            }
        }

        // Affichage du message complet
        function afficherMessageComplet(message) {
            const reader = document.getElementById('mailReader');
            
            document.getElementById('readerSubject').textContent = message.sujet;
            document.getElementById('readerAvatar').textContent = message.expediteur_avatar;
            document.getElementById('readerSenderName').textContent = message.expediteur_nom;
            document.getElementById('readerSenderEmail').textContent = message.expediteur_email;
            
            const dateFormatee = new Date(message.date_envoi).toLocaleDateString('fr-FR', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            document.getElementById('readerDate').textContent = dateFormatee;
            
            let contenuHtml = message.contenu;
            
            // Ajouter les pièces jointes si présentes
            if (message.pieces_jointes && message.pieces_jointes.length > 0) {
                contenuHtml += `
                    <div class="attachments">
                        <h5><i class="fas fa-paperclip"></i> Pièces jointes (${message.pieces_jointes.length})</h5>
                        ${message.pieces_jointes.map(pj => `
                            <div class="attachment-item">
                                <i class="fas fa-file-pdf attachment-icon"></i>
                                <div class="attachment-info">
                                    <div class="attachment-name">${pj.nom}</div>
                                    <div class="attachment-size">${pj.taille}</div>
                                </div>
                                <button class="btn btn-sm btn-secondary">
                                    <i class="fas fa-download"></i>
                                </button>
                            </div>
                        `).join('')}
                    </div>
                `;
            }
            
            document.getElementById('readerContent').innerHTML = contenuHtml;
            reader.classList.add('active');
        }

        // Gestion des dossiers
        document.querySelectorAll('.folder-item').forEach(item => {
            item.addEventListener('click', function() {
                // Retirer la classe active de tous les dossiers
                document.querySelectorAll('.folder-item').forEach(f => f.classList.remove('active'));
                
                // Ajouter la classe active au dossier cliqué
                this.classList.add('active');
                
                currentFolder = this.dataset.folder;
                currentPage = 1;
                chargerMessages(currentFolder, currentPage);
                
                // Fermer le lecteur de message
                document.getElementById('mailReader').classList.remove('active');
            });
        });

        // Composition de message
        document.getElementById('composeBtn').addEventListener('click', function() {
            document.getElementById('composeModal').style.display = 'block';
            document.getElementById('recipientsInput').focus();
        });

        // Autocomplétion des contacts
        const recipientsInput = document.getElementById('recipientsInput');
        const contactSuggestions = document.getElementById('contactSuggestions');

        recipientsInput.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            
            if (query.length < 2) {
                contactSuggestions.style.display = 'none';
                return;
            }
            
            const suggestions = contacts.filter(contact => 
                contact.nom.toLowerCase().includes(query) || 
                contact.email.toLowerCase().includes(query)
            ).slice(0, 5);
            
            if (suggestions.length > 0) {
                contactSuggestions.innerHTML = suggestions.map(contact => {
                    const avatar = contact.nom.split(' ').map(n => n[0]).join('').toUpperCase();
                    return `
                        <div class="contact-suggestion" data-contact='${JSON.stringify(contact)}'>
                            <div class="contact-avatar">${avatar}</div>
                            <div class="contact-info">
                                <div class="contact-name">${contact.nom}</div>
                                <div class="contact-role">${contact.role}</div>
                            </div>
                        </div>
                    `;
                }).join('');
                contactSuggestions.style.display = 'block';
            } else {
                contactSuggestions.style.display = 'none';
            }
        });

        // Sélection d'un contact
        contactSuggestions.addEventListener('click', function(e) {
            const suggestion = e.target.closest('.contact-suggestion');
            if (suggestion) {
                const contact = JSON.parse(suggestion.dataset.contact);
                
                if (!selectedContacts.find(c => c.email === contact.email)) {
                    selectedContacts.push(contact);
                    updateRecipientsDisplay();
                }
                
                recipientsInput.value = '';
                contactSuggestions.style.display = 'none';
            }
        });

        function updateRecipientsDisplay() {
            recipientsInput.placeholder = selectedContacts.length > 0 ? 
                `${selectedContacts.length} destinataire(s) sélectionné(s)` : 
                'Nom ou email des destinataires...';
        }

        // Envoi de message
        document.getElementById('composeForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (selectedContacts.length === 0) {
                showAlert('Veuillez sélectionner au moins un destinataire', 'error');
                return;
            }
            
            const sendBtn = document.getElementById('sendBtn');
            const originalText = sendBtn.innerHTML;
            
            try {
                sendBtn.innerHTML = '<div class="spinner"></div> Envoi...';
                sendBtn.disabled = true;
                
                const result = await makeAjaxRequest({
                    action: 'envoyer_message',
                    destinataires: selectedContacts.map(c => c.email),
                    sujet: document.getElementById('subjectInput').value,
                    contenu: document.getElementById('messageContent').value,
                    priorite: document.getElementById('prioritySelect').value,
                    type_message: document.getElementById('typeSelect').value
                });
                
                if (result.success) {
                    showAlert(`Message envoyé à ${result.destinataires_count} destinataire(s)`, 'success');
                    closeModal('composeModal');
                    
                    // Vider le formulaire
                    document.getElementById('composeForm').reset();
                    selectedContacts = [];
                    updateRecipientsDisplay();
                } else {
                    showAlert(result.message, 'error');
                }
                
            } catch (error) {
                showAlert('Erreur lors de l\'envoi', 'error');
            } finally {
                sendBtn.innerHTML = originalText;
                sendBtn.disabled = false;
            }
        });

        // Gestion des sélections
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.message-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
                const messageId = cb.dataset.messageId;
                if (this.checked) {
                    selectedMessages.add(messageId);
                } else {
                    selectedMessages.delete(messageId);
                }
            });
            updateToolbarButtons();
        });

        document.addEventListener('change', function(e) {
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

        // Actions sur les messages
        document.getElementById('markReadBtn').addEventListener('click', async function() {
            if (selectedMessages.size === 0) return;
            
            try {
                const result = await makeAjaxRequest({
                    action: 'marquer_lu',
                    message_ids: Array.from(selectedMessages),
                    statut: 'lu'
                });
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    chargerMessages(currentFolder, currentPage);
                    selectedMessages.clear();
                    updateToolbarButtons();
                }
            } catch (error) {
                showAlert('Erreur lors de la mise à jour', 'error');
            }
        });

        document.getElementById('deleteBtn').addEventListener('click', async function() {
            if (selectedMessages.size === 0) return;
            
            if (!confirm(`Supprimer ${selectedMessages.size} message(s) ?`)) return;
            
            try {
                const result = await makeAjaxRequest({
                    action: 'supprimer_messages',
                    message_ids: Array.from(selectedMessages)
                });
                
                if (result.success) {
                    showAlert(result.message, 'success');
                    chargerMessages(currentFolder, currentPage);
                    selectedMessages.clear();
                    updateToolbarButtons();
                }
            } catch (error) {
                showAlert('Erreur lors de la suppression', 'error');
            }
        });

        // Actualiser
        document.getElementById('refreshBtn').addEventListener('click', function() {
            chargerMessages(currentFolder, currentPage);
        });

        // Fermer le lecteur
        document.getElementById('closeReaderBtn').addEventListener('click', function() {
            document.getElementById('mailReader').classList.remove('active');
        });

        // Pagination
        function mettreAJourPagination(page, pagesTotal, total) {
            document.getElementById('messagesPagination').textContent = 
                `${((page - 1) * 20) + 1}-${Math.min(page * 20, total)} sur ${total}`;
            
            document.getElementById('prevPageBtn').disabled = page <= 1;
            document.getElementById('nextPageBtn').disabled = page >= pagesTotal;
        }

        document.getElementById('prevPageBtn').addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                chargerMessages(currentFolder, currentPage);
            }
        });

        document.getElementById('nextPageBtn').addEventListener('click', function() {
            currentPage++;
            chargerMessages(currentFolder, currentPage);
        });

        // Fermer modals
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Raccourcis clavier
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('mailReader').classList.remove('active');
                closeModal('composeModal');
            }
            
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                document.getElementById('composeBtn').click();
            }
        });

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            chargerMessages('reception', 1);
        });
    </script>
</body>
</html>