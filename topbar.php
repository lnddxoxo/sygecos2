<?php
// topbar.php

// Assurez-vous que la session est déjà démarrée avant d'inclure ce fichier
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== TRUE) {
    // Si non connecté, rediriger vers la page de connexion
    header("Location: loginForm.php");
    exit;
}

// Récupérer les informations de l'utilisateur depuis la session
$user_id = $_SESSION['id_util'] ?? null;
$user_name = $_SESSION['nom_prenom'] ?? 'Utilisateur'; // Nom complet (prénom NOM)
$user_role = $_SESSION['role'] ?? 'Non défini'; // Rôle de l'utilisateur
$user_type = $_SESSION['user_type'] ?? null; // Type d'utilisateur (personnel_admin, enseignant, etudiant)

// --- Configuration de la base de données avec PDO (pour les notifications) ---
// Ces informations devraient idéalement être chargées depuis un fichier de configuration sécurisé.
$host = "localhost";
$db = "sygecos";
$user = "root";
$pass = "";
$charset = "utf8mb4";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = null; // Initialisation de $pdo à null
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // En cas d'erreur de connexion à la DB, loggez l'erreur (ne l'affichez pas directement à l'utilisateur)
    error_log("Erreur de connexion à la base de données dans topbar.php: " . $e->getMessage());
    // Vous pouvez définir un message d'erreur ou simplement ignorer les fonctionnalités dépendant de la DB
}

// --- Fonction pour obtenir le nom affiché dans la topbar ---
function getDisplayName($full_name, $role) {
    // Votre base de données stocke nom_prenom comme "Prénom Nom".
    // Si le rôle est "Responsable de filière", nous voulons "Admin Prénom".
    // Sinon, nous affichons le nom complet.
    if (strtolower($role) === 'responsable de filière' || strtolower($role) === 'responsable de filiere') {
        $parts = explode(' ', trim($full_name));
        $prenom = $parts[0] ?? ''; // Prend le premier mot comme prénom
        return "Admin " . $prenom;
    }
    return $full_name;
}

// --- Calculer le nombre de notifications non lues ---
$notification_count = 0;
if ($pdo && $user_id) { // Assurez-vous que la connexion PDO est établie et l'ID utilisateur est disponible
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id_util = :user_id AND is_read = 0");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $notification_count = $stmt->fetchColumn();
    } catch (\PDOException $e) {
        error_log("Erreur de récupération des notifications: " . $e->getMessage());
        // $notification_count reste 0 en cas d'erreur
    }
}

$display_name = getDisplayName($user_name, $user_role);
?>

<header class="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" id="sidebarToggle" title="Ouvrir/Fermer la barre latérale">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="page-title">Tableau de bord</h1>
    </div>
        
    <div class="topbar-right">
        <button class="topbar-button" title="Notifications">
            <i class="fas fa-bell"></i>
            <?php if ($notification_count > 0): ?>
                <span class="notification-badge"><?php echo $notification_count; ?></span>
            <?php endif; ?>
        </button>
                
        <div class="user-menu" title="Menu utilisateur">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($display_name); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
            </div>
            <i class="fas fa-chevron-down" style="color: var(--primary-500); margin-left: var(--space-2);"></i>
            <div class="dropdown-content">
                <a href="profile.php"><i class="fas fa-user-circle"></i> Mon Profil</a>
                <a href="settings.php"><i class="fas fa-sliders-h"></i> Paramètres</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
            </div>
        </div>
    </div>
</header>

<style>
/* Variables et styles généraux (assurez-vous qu'ils sont définis globalement ou ici si nécessaire) */
:root {
    /* Reprendre les variables de couleurs et d'espacement de votre CSS global */
    --primary-50: #f8fafc;
    --primary-100: #f1f5f9;
    --primary-200: #e2e8f0;
    --primary-500: #64748b;
    --primary-600: #475569;
    --primary-700: #334155;
    --primary-800: #1e293b;

    --accent-100: #dbeafe;
    --accent-600: #2563eb;
    --accent-700: #1d4ed8;

    --error-500: #ef4444;

    --space-2: 0.5rem;
    --space-3: 0.75rem;
    --space-4: 1rem;
    --space-6: 1.5rem;

    --radius-md: 0.5rem;

    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);

    --transition-normal: 250ms ease-in-out;
}

/* Styles de la Topbar */
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-4) var(--space-6);
    background-color: white;
    border-bottom: 1px solid var(--primary-200);
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: 0;
    z-index: 1000;
    height: 60px;
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: var(--space-4);
}

.sidebar-toggle {
    background: none;
    border: none;
    font-size: 1.2rem;
    color: var(--primary-600);
    cursor: pointer;
    padding: var(--space-2);
    border-radius: var(--radius-md);
    transition: all var(--transition-normal);
}

.sidebar-toggle:hover {
    background-color: var(--primary-100);
    color: var(--accent-600);
}

.page-title {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--primary-800);
    margin: 0;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: var(--space-4); /* Maintenu pour l'espacement entre le bouton de notif et le menu utilisateur */
}

.topbar-button {
    background: none;
    border: none;
    font-size: 1.1rem;
    color: var(--primary-500);
    cursor: pointer;
    padding: var(--space-2);
    border-radius: var(--radius-md);
    transition: all var(--transition-normal);
    position: relative; /* Pour le badge de notification */
}

.topbar-button:hover {
    background-color: var(--primary-100);
    color: var(--accent-600);
}

.notification-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background-color: var(--error-500); /* Rouge pour les notifications */
    color: white;
    font-size: 0.7rem;
    font-weight: bold;
    border-radius: 50%;
    padding: 3px 7px;
    line-height: 1;
    min-width: 20px; /* Assure une taille minimale même pour un seul chiffre */
    text-align: center;
    box-shadow: var(--shadow-sm);
}

.user-menu {
    display: flex;
    align-items: center;
    cursor: pointer;
    position: relative;
    padding: var(--space-2);
    border-radius: var(--radius-md);
    transition: background-color var(--transition-normal);
}

.user-menu:hover {
    background-color: var(--primary-100);
}

.user-info {
    display: flex;
    flex-direction: column;
    margin-right: var(--space-2);
}

.user-name {
    font-weight: 600;
    color: var(--primary-700);
    font-size: 0.95rem;
}

.user-role {
    font-size: 0.8rem;
    color: var(--primary-500);
}

/* Styles pour le menu déroulant */
.user-menu .dropdown-content {
    display: none;
    position: absolute;
    background-color: white;
    min-width: 180px;
    box-shadow: var(--shadow-md);
    z-index: 1;
    top: 100%;
    right: 0;
    border-radius: var(--radius-md);
    overflow: hidden;
    border: 1px solid var(--primary-200);
    margin-top: var(--space-2);
    opacity: 0;
    transform: translateY(-10px);
    transition: opacity 0.3s ease-out, transform 0.3s ease-out;
}

.user-menu:hover .dropdown-content {
    display: block;
    opacity: 1;
    transform: translateY(0);
}

.dropdown-content a {
    color: var(--primary-700);
    padding: var(--space-3) var(--space-4);
    text-decoration: none;
    display: block;
    font-size: 0.9rem;
    transition: background-color var(--transition-normal), color var(--transition-normal);
    display: flex;
    align-items: center;
    gap: var(--space-2);
}

.dropdown-content a:hover {
    background-color: var(--accent-100);
    color: var(--accent-700);
}

/* Media Queries pour le responsive */
@media (max-width: 768px) {
    .topbar {
        padding: var(--space-3);
    }
    .page-title {
        font-size: 1rem;
    }
    .topbar-right {
        gap: var(--space-2);
    }
    .user-info {
        display: none;
    }
    .user-menu .fa-chevron-down {
        margin-left: 0;
    }
    .user-menu .dropdown-content {
        min-width: unset;
        left: unset;
        right: 0;
    }
}
</style>