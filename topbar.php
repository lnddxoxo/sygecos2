<?php 
// topbar.php
// Récupérer les informations de l'utilisateur depuis la session
$user_name = $_SESSION['nom_prenom'] ?? 'Admin SYGECOS';
$user_role = $_SESSION['role'] ?? 'Administrateur';

// Fonction simple pour adapter le nom d'affichage
function getDisplayName($nom_prenom, $role) {
    // Dans votre BD: nom_prenom = "Brou KOUA" (prénom + nom)
    $parts = explode(' ', trim($nom_prenom));
    $prenom = $parts[0] ?? 'Admin'; // "Brou" sera le premier mot
    
    if (strtolower($role) === 'responsable de filière' || strtolower($role) === 'responsable de filiere') {
        return "Admin " . $prenom; // "Admin Brou"
    }
    return $nom_prenom;
}

// Calculer notifications (simple)
$notification_count = rand(1, 5); // Vous pouvez changer cette logique

$display_name = getDisplayName($user_name, $user_role);
?>

<header class="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="page-title">Tableau de bord</h1>
    </div>
        
    <div class="topbar-right">
        <button class="topbar-button">
            <i class="fas fa-search"></i>
        </button>
        <button class="topbar-button">
            <i class="fas fa-bell"></i>
            <span class="notification-badge"><?php echo $notification_count; ?></span>
        </button>
        <button class="topbar-button">
            <i class="fas fa-cog"></i>
        </button>
                
        <div class="user-menu">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($display_name); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
            </div>
            <i class="fas fa-chevron-down" style="color: var(--gray-500); margin-left: var(--space-2);"></i>
        </div>
    </div>
</header>