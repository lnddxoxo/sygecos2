<?php
// reunion_commission.php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

$currentUser = [
    'id' => null,
    'name' => 'Invité',
    'role' => 'Utilisateur',
    'avatar' => '', // Placeholder for actual avatar URL or empty if not used
    'is_online' => false // Initialize is_online for current user
];

// Assuming config.php sets $_SESSION['user_id'] upon login
if (isset($_SESSION['user_id'])) {
    $currentUserId = $_SESSION['user_id'];
    try {
        // Fetch current user's details for 'Moi' section
        $stmt = $pdo->prepare("
            SELECT
                u.id_util,
                u.login_util,
                COALESCE(e.nom_ens, pa.nom_pers) AS last_name,
                COALESCE(e.prenom_ens, pa.prenoms_pers) AS first_name,
                CASE
                    WHEN pa.poste IS NOT NULL THEN pa.poste
                    WHEN EXISTS (SELECT 1 FROM posseder ps JOIN groupe_utilisateur gu ON ps.fk_id_GU = gu.id_GU WHERE ps.fk_id_util = u.id_util AND gu.lib_GU = 'Enseignant') THEN 'Enseignant'
                    WHEN EXISTS (SELECT 1 FROM posseder ps JOIN groupe_utilisateur gu ON ps.fk_id_GU = gu.id_GU WHERE ps.fk_id_util = u.id_util AND gu.lib_GU = 'Etudiant') THEN 'Étudiant'
                    ELSE 'Inconnu'
                END AS role,
                u.last_activity
            FROM
                utilisateur u
            LEFT JOIN
                enseignant e ON u.id_util = e.fk_id_util
            LEFT JOIN
                personnel_admin pa ON u.id_util = pa.fk_id_util
            WHERE
                u.id_util = :user_id
        ");
        $stmt->bindParam(':user_id', $currentUserId);
        $stmt->execute();
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userData) {
            $currentUser['id'] = $userData['id_util'];
            $currentUser['name'] = trim($userData['first_name'] . ' ' . $userData['last_name']);
            if (empty($currentUser['name'])) { // Fallback if name is empty
                $currentUser['name'] = $userData['login_util'];
            }
            $currentUser['role'] = $userData['role'];
            // $currentUser['avatar'] = 'path/to/user_avatar.jpg'; // If you have user avatars
            
            // Calculate and set is_online for the current user
            $onlineThreshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));
            $currentUser['is_online'] = ($userData['last_activity'] && $userData['last_activity'] > $onlineThreshold);
        }
    } catch (PDOException $e) {
        error_log("Error fetching current user details: " . $e->getMessage());
    }
}


$commissionMembers = [];
try {
    // Fetch all members of 'Commission de validation' and 'Enseignant' roles
    // Adjust WHERE clause if 'Commission de validation' is the only group for this view
    $stmt = $pdo->prepare("
        SELECT
            u.id_util,
            COALESCE(e.nom_ens, pa.nom_pers) AS last_name,
            COALESCE(e.prenom_ens, pa.prenoms_pers) AS first_name,
            CASE
                WHEN pa.poste IS NOT NULL THEN pa.poste
                WHEN EXISTS (SELECT 1 FROM posseder ps JOIN groupe_utilisateur gu ON ps.fk_id_GU = gu.id_GU WHERE ps.fk_id_util = u.id_util AND gu.lib_GU = 'Enseignant') THEN 'Enseignant'
                ELSE 'Inconnu'
            END AS role,
            u.last_activity
        FROM
            utilisateur u
        LEFT JOIN
            enseignant e ON u.id_util = e.fk_id_util
        LEFT JOIN
            personnel_admin pa ON u.id_util = pa.fk_id_util
        JOIN
            posseder p ON u.id_util = p.fk_id_util
        JOIN
            groupe_utilisateur gu ON p.fk_id_GU = gu.id_GU
        WHERE
            gu.lib_GU = 'Commission de validation' OR gu.lib_GU = 'Responsable de filière' -- Adjust based on who should appear in the list
        GROUP BY
            u.id_util
        ORDER BY
            last_name, first_name
    ");
    $stmt->execute();
    $rawMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter out current user and determine online status
    $onlineThreshold = date('Y-m-d H:i:s', strtotime('-5 minutes')); // User is online if active in last 5 minutes

    foreach ($rawMembers as $member) {
        // Ensure 'is_online' is always set for each member
        $member['full_name'] = trim($member['first_name'] . ' ' . $member['last_name']);
        $member['is_online'] = ($member['last_activity'] && $member['last_activity'] > $onlineThreshold);
        $member['avatar'] = ''; // Placeholder for actual avatar URL

        if ($member['id_util'] != $currentUser['id']) { // Only add if not the current user
            $commissionMembers[] = $member;
        }
    }

} catch (PDOException $e) {
    error_log("Erreur récupération membres de la commission: " . $e->getMessage());
}

$chatMessages = [];
try {
    // Fetch historical chat messages for general discussion
    $stmt = $pdo->prepare("
        SELECT
            m.body AS text,
            m.sent_at,
            m.sender_id_util AS userId,
            COALESCE(e.prenom_ens, pa.prenoms_pers) AS sender_first_name,
            COALESCE(e.nom_ens, pa.nom_pers) AS sender_last_name
        FROM
            messages m
        JOIN
            utilisateur u ON m.sender_id_util = u.id_util
        LEFT JOIN
            enseignant e ON u.id_util = e.fk_id_util
        LEFT JOIN
            personnel_admin pa ON u.id_util = pa.fk_id_util
        WHERE
            m.message_type = 'general'
        ORDER BY
            m.sent_at ASC
        LIMIT 50 -- Limit to last 50 messages for example
    ");
    $stmt->execute();
    $rawMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawMessages as $msg) {
        $senderName = trim($msg['sender_first_name'] . ' ' . $msg['sender_last_name']);
        if (empty($senderName)) { // Fallback if name is empty
             $senderName = 'Utilisateur Inconnu';
        }
        $chatMessages[] = [
            'userId' => $msg['userId'],
            'userName' => $senderName,
            'userAvatar' => '', // Add logic to get avatar if available
            'text' => $msg['text'],
            'time' => (new DateTime($msg['sent_at']))->format('H:i'),
            'isMe' => ($msg['userId'] == $currentUser['id'])
        ];
    }

} catch (PDOException $e) {
    error_log("Erreur récupération messages de discussion: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Réunion de Commission</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-50: #f8fafc; --primary-100: #f1f5f9; --primary-200: #e2e8f0; --primary-300: #cbd5e1; --primary-400: #94a3b8; --primary-500: #64748b; --primary-600: #475569; --primary-700: #334155; --primary-800: #1e293b; --primary-900: #0f172a;
            --accent-50: #eff6ff; --accent-100: #dbeafe; --accent-200: #bfdbfe; --accent-300: #93c5fd; --accent-400: #60a5fa; --accent-500: #3b82f6; --accent-600: #2563eb; --accent-700: #1d4ed8; --accent-800: #1e40af; --accent-900: #1e3a8a;
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

        .page-content { padding: var(--space-6); display: flex; flex-direction: column; height: calc(100vh - var(--topbar-height)); }
        .page-header { margin-bottom: var(--space-4); }
        .page-title-main { font-size: var(--text-2xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--space-2); }
        .page-subtitle { color: var(--gray-600); font-size: var(--text-base); }

        /* Interface de réunion */
        .meeting-container { display: flex; flex: 1; gap: var(--space-6); height: calc(100% - 60px); }
        
        /* Liste des membres */
        .members-sidebar { width: 250px; background: var(--white); border-radius: var(--radius-xl); padding: var(--space-4); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); overflow-y: auto; }
        .members-header { font-weight: 600; margin-bottom: var(--space-4); padding-bottom: var(--space-2); border-bottom: 1px solid var(--gray-200); }
        .member-item { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3); border-radius: var(--radius-md); margin-bottom: var(--space-2); }
        .member-item:hover { background: var(--gray-50); }
        .member-avatar { width: 36px; height: 36px; border-radius: 50%; background-color: var(--gray-200); display: flex; align-items: center; justify-content: center; }
        .member-avatar img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
        .member-info { flex: 1; }
        .member-name { font-weight: 500; }
        .member-role { font-size: var(--text-xs); color: var(--gray-600); }
        .member-status { width: 10px; height: 10px; border-radius: 50%; background: var(--gray-300); }
        .member-status.online { background: var(--success-500); }
        
        /* Zone de discussion */
        .chat-container { flex: 1; display: flex; flex-direction: column; background: var(--white); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); overflow: hidden; }
        .chat-header { padding: var(--space-4); border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; }
        .chat-title { font-weight: 600; }
        .chat-actions { display: flex; gap: var(--space-3); }
        
        /* Messages */
        .messages-container { flex: 1; padding: var(--space-4); overflow-y: auto; background: var(--gray-50); }
        .message { display: flex; gap: var(--space-3); margin-bottom: var(--space-4); }
        .message-avatar { width: 32px; height: 32px; border-radius: 50%; background-color: var(--gray-200); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .message-avatar img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
        .message-content { flex: 1; }
        .message-header { display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-1); }
        .message-sender { font-weight: 600; font-size: var(--text-sm); }
        .message-time { font-size: var(--text-xs); color: var(--gray-500); }
        .message-text { background: var(--white); padding: var(--space-3); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); display: inline-block; max-width: 70%; word-wrap: break-word; } /* Added word-wrap */
        .message.me .message-text { background: var(--accent-500); color: white; }
        .message.me { flex-direction: row-reverse; }
        .message.me .message-content { text-align: right; }
        
        /* Zone de saisie */
        .chat-input { padding: var(--space-4); border-top: 1px solid var(--gray-200); background: var(--white); }
        .input-group { display: flex; gap: var(--space-3); }
        .message-input { flex: 1; padding: var(--space-3); border: 1px solid var(--gray-300); border-radius: var(--radius-md); resize: none; font-family: var(--font-primary); }
        .send-btn { padding: var(--space-3) var(--space-4); border-radius: var(--radius-md); background: var(--accent-500); color: white; border: none; cursor: pointer; }
        
        /* Outils de réunion */
        .meeting-tools { width: 250px; background: var(--white); border-radius: var(--radius-xl); padding: var(--space-4); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200); overflow-y: auto; }
        .tools-header { font-weight: 600; margin-bottom: var(--space-4); padding-bottom: var(--space-2); border-bottom: 1px solid var(--gray-200); }
        .tool-item { padding: var(--space-3); border-radius: var(--radius-md); margin-bottom: var(--space-2); cursor: pointer; }
        .tool-item:hover { background: var(--gray-50); }
        .tool-icon { margin-right: var(--space-2); color: var(--accent-500); }
        
        @media (max-width: 1024px) {
            .meeting-container { flex-direction: column; }
            .members-sidebar, .meeting-tools { width: 100%; }
            .members-sidebar { order: 2; }
            .chat-container { order: 1; }
            .meeting-tools { order: 3; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar_commision.php'; ?>

        <main class="main-content" id="mainContent">
            <?php include 'topbar.php'; ?>

            <div class="page-content">
                <div class="page-header">
                    <h1 class="page-title-main">Réunion de Commission</h1>
                    <p class="page-subtitle">Discussion en temps réel avec les membres</p>
                </div>

                <div class="meeting-container">
                    <div class="members-sidebar">
                        <div class="members-header">Membres en ligne (<?php echo count(array_filter($commissionMembers, function($member) { return $member['is_online']; })) + ($currentUser['is_online'] ? 1 : 0); ?>)</div>
                        
                        <div class="member-item">
                            <div class="member-avatar">
                                <?php if ($currentUser['avatar']): ?>
                                    <img src="<?php echo htmlspecialchars($currentUser['avatar']); ?>" alt="Photo profil">
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <div class="member-info">
                                <div class="member-name"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                                <div class="member-role"><?php echo htmlspecialchars($currentUser['role']); ?></div>
                            </div>
                            <div class="member-status <?php echo $currentUser['is_online'] ? 'online' : ''; ?>"></div>
                        </div>
                        
                        <?php foreach($commissionMembers as $member): ?>
                            <div class="member-item" data-member-id="<?php echo htmlspecialchars($member['id_util']); ?>">
                                <div class="member-avatar">
                                    <?php if ($member['avatar']): ?>
                                        <img src="<?php echo htmlspecialchars($member['avatar']); ?>" alt="Photo profil">
                                    <?php else: ?>
                                        <i class="fas fa-user"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="member-info">
                                    <div class="member-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                    <div class="member-role"><?php echo htmlspecialchars($member['role']); ?></div>
                                </div>
                                <div class="member-status <?php echo $member['is_online'] ? 'online' : ''; ?>"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="chat-container">
                        <div class="chat-header">
                            <div class="chat-title">Discussion générale</div>
                            <div class="chat-actions">
                                <button class="btn btn-sm btn-primary">
                                    <i class="fas fa-file-import"></i> Partager un document
                                </button>
                            </div>
                        </div>

                        <div class="messages-container" id="messagesContainer">
                            <?php if (empty($chatMessages)): ?>
                                <div style="text-align: center; padding: var(--space-6); color: var(--gray-500);">
                                    <i class="fas fa-comments" style="font-size: var(--text-xl); margin-bottom: var(--space-2);"></i>
                                    <p>Aucun message pour le moment. Soyez le premier à discuter !</p>
                                </div>
                            <?php else: ?>
                                <?php foreach($chatMessages as $message): ?>
                                    <div class="message <?php echo $message['isMe'] ? 'me' : ''; ?>">
                                        <div class="message-avatar">
                                            <?php if ($message['userAvatar']): ?>
                                                <img src="<?php echo htmlspecialchars($message['userAvatar']); ?>" alt="Photo profil">
                                            <?php else: ?>
                                                <i class="fas fa-user"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="message-content">
                                            <div class="message-header">
                                                <span class="message-sender"><?php echo $message['isMe'] ? 'Moi' : htmlspecialchars($message['userName']); ?></span>
                                                <span class="message-time"><?php echo htmlspecialchars($message['time']); ?></span>
                                            </div>
                                            <div class="message-text">
                                                <?php echo htmlspecialchars($message['text']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="chat-input">
                            <div class="input-group">
                                <textarea class="message-input" id="messageInput" placeholder="Écrivez votre message..." rows="1"></textarea>
                                <button class="send-btn" id="sendBtn">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="meeting-tools">
                        <div class="tools-header">Outils de réunion</div>
                        
                        <div class="tool-item">
                            <i class="fas fa-file-alt tool-icon"></i> Ordre du jour
                        </div>
                        <div class="tool-item">
                            <i class="fas fa-tasks tool-icon"></i> Liste des rapports
                        </div>
                        </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Variables globales
        const currentUser = {
            id: <?php echo json_encode($currentUser['id']); ?>, // Pass PHP variable to JS
            name: <?php echo json_encode($currentUser['name']); ?>,
            avatar: <?php echo json_encode($currentUser['avatar']); ?>
        };

        // WebSocket connection - NOTE: This requires a separate WebSocket server (Node.js, Python, etc.)
        // This client-side code will attempt to connect, but won't fully function without the server.
        // For local development, 'ws://localhost:8080' is a common default.
        const socket = new WebSocket('ws://localhost:8080'); // Adjust WebSocket server address as needed
        
        // Gestion des messages entrants
        socket.onmessage = function(event) {
            const message = JSON.parse(event.data);
            // Check if the message is for chat or presence update
            if (message.type === 'chat') {
                addMessage(message);
            } else if (message.type === 'presence_update') {
                updateMemberStatus(message.userId, message.status);
            }
        };

        // Handle WebSocket connection open
        socket.onopen = function() {
            console.log('WebSocket connected.');
            // Send initial presence to the server
            socket.send(JSON.stringify({
                type: 'presence',
                userId: currentUser.id,
                status: 'online'
            }));
            // Optionally, request past messages if the server provides them via WebSocket
            // socket.send(JSON.stringify({ type: 'get_history' }));
        };

        // Handle WebSocket errors
        socket.onerror = function(error) {
            console.error('WebSocket error:', error);
            // You might want to show a more user-friendly error message
            // showAlert('Erreur de connexion en temps réel. Le chat pourrait ne pas fonctionner correctement.', 'error');
        };

        // Handle WebSocket connection close
        socket.onclose = function() {
            console.log('WebSocket disconnected.');
            // showAlert('La connexion en temps réel a été perdue. Veuillez rafraîchir la page pour vous reconnecter.', 'warning');
            // Implement reconnect logic here if necessary for a production environment
        };

        // Envoyer un message
        document.getElementById('sendBtn').addEventListener('click', sendMessage);
        document.getElementById('messageInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) { // Send on Enter, new line on Shift+Enter
                e.preventDefault();
                sendMessage();
            }
        });

        function sendMessage() {
            const input = document.getElementById('messageInput');
            const text = input.value.trim();
            
            if (text) {
                const message = {
                    type: 'chat', // Explicitly define message type for WebSocket server
                    userId: currentUser.id,
                    userName: currentUser.name,
                    userAvatar: currentUser.avatar,
                    text: text,
                    time: new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})
                };
                
                // Envoyer via WebSocket
                if (socket.readyState === WebSocket.OPEN) {
                    socket.send(JSON.stringify(message));
                    // Also store in DB via AJAX if WebSocket server doesn't handle persistence
                    // This part would typically be handled by your WebSocket server.
                    // For a purely PHP backend without a dedicated WebSocket server,
                    // you would send an AJAX request here to save the message to DB.
                    // Example AJAX call (if not using WebSocket for persistence):
                    // saveMessageToDatabase(message);
                } else {
                    console.error("WebSocket is not open. Message not sent.");
                    // Fallback: If WebSocket is not open, try to save directly via AJAX
                    // saveMessageToDatabase(message);
                    // showAlert("Le chat en temps réel n'est pas disponible. Votre message ne sera peut-être pas envoyé immédiatement.", "warning");
                }
                
                // Add to own interface immediately (optimistic update)
                addMessage({
                    ...message,
                    isMe: true
                });
                
                // Vider le champ
                input.value = '';
                input.style.height = 'auto'; // Reset textarea height
            }
        }

        function addMessage(message) {
            const container = document.getElementById('messagesContainer');
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${message.isMe ? 'me' : ''}`;
            
            messageDiv.innerHTML = `
                <div class="message-avatar">
                    ${message.userAvatar ?
                        `<img src="${htmlspecialchars(message.userAvatar)}" alt="Photo profil">` :
                        `<i class="fas fa-user"></i>`}
                </div>
                <div class="message-content">
                    <div class="message-header">
                        <span class="message-sender">${message.isMe ? 'Moi' : htmlspecialchars(message.userName)}</span>
                        <span class="message-time">${htmlspecialchars(message.time)}</span>
                    </div>
                    <div class="message-text">
                        ${htmlspecialchars(message.text)}
                    </div>
                </div>
            `;
            
            container.appendChild(messageDiv);
            container.scrollTop = container.scrollHeight;
        }

        // Function to update member's online status indicator
        function updateMemberStatus(userId, status) {
            const memberItem = document.querySelector(`.member-item[data-member-id="${userId}"]`);
            if (memberItem) {
                const statusIndicator = memberItem.querySelector('.member-status');
                if (statusIndicator) {
                    // Remove existing status classes
                    statusIndicator.classList.remove('online');
                    statusIndicator.classList.remove('offline'); // If you add an offline class

                    if (status === 'online') {
                        statusIndicator.classList.add('online');
                    } else {
                        // statusIndicator.classList.add('offline'); // Optional: Add an offline class
                    }
                }
            }
            updateOnlineMembersCount(); // Recalculate count after status change
        }

        // Function to update the displayed count of online members
        function updateOnlineMembersCount() {
            const onlineMembers = document.querySelectorAll('.member-item .member-status.online');
            const membersHeader = document.querySelector('.members-header');
            if (membersHeader) {
                membersHeader.textContent = `Membres en ligne (${onlineMembers.length})`;
            }
        }

        // Auto-resize textarea
        document.getElementById('messageInput').addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        // HTML escaping helper (re-added for client-side dynamic content)
        function htmlspecialchars(str) {
            if (typeof str !== 'string') return str;
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return str.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Sidebar responsiveness (copied from previous files for consistency)
        document.addEventListener('DOMContentLoaded', function() {
            initSidebar();
            updateOnlineMembersCount(); // Initial count on load
        });

        function initSidebar() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (sidebarToggle && sidebar && mainContent) {
                handleResponsiveLayout(); // Initial state based on window width

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
                    const timesIcon = sidebarToggle.isplay ? sidebarToggle.querySelector('.fa-times') : null;
                    if (barsIcon) barsIcon.style.display = 'inline-block';
                    if (timesIcon) timesIcon.style.display = 'none';
                });
            }

            window.addEventListener('resize', handleResponsiveLayout);
        }

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
    </script>
</body>
</html>