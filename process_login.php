<?php
session_start(); // Démarre la session pour stocker les informations de l'utilisateur

// 1. Configuration de la base de données avec PDO
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

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    $_SESSION['login_error'] = "Erreur de connexion à la base de données. Veuillez réessayer plus tard.";
    header("Location: login.php");
    exit;
}

// Vérifie si le formulaire a été soumis
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Récupère et sécurise les données du formulaire
    $identifier = trim($_POST['identifier'] ?? ''); // Email OU identifiant
    $password = trim($_POST['password'] ?? '');

    // Validation basique
    if (empty($identifier) || empty($password)) {
        $_SESSION['login_error'] = "Veuillez remplir tous les champs.";
        header("Location: login.php");
        exit;
    }

    // Variable pour stocker les informations de l'utilisateur trouvé
    $user_found = false;
    $user_data = null;
    $user_type = null;

    try {
        // 1. VÉRIFICATION DANS LA TABLE PERSONNEL_ADMIN (email OU login)
        $sql_admin = "SELECT
                        u.id_util,
                        u.login_util,
                        u.mdp_util,
                        p.nom_pers AS nom,
                        p.prenoms_pers AS prenom,
                        p.email_pers AS email,
                        p.poste,
                        gu.lib_GU AS role,
                        gu.id_GU AS role_id
                      FROM utilisateur u
                      JOIN personnel_admin p ON u.id_util = p.fk_id_util
                      JOIN posseder pos ON u.id_util = pos.fk_id_util
                      JOIN groupe_utilisateur gu ON pos.fk_id_GU = gu.id_GU
                      WHERE p.email_pers = :identifier OR u.login_util = :identifier2";

        $stmt = $pdo->prepare($sql_admin);
        $stmt->bindParam(':identifier', $identifier, PDO::PARAM_STR);
        $stmt->bindParam(':identifier2', $identifier, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch();

        if ($user) {
            $user_found = true;
            $user_data = $user;
            $user_type = 'personnel_admin';
        }

        // 2. SI PAS TROUVÉ, VÉRIFICATION DANS LA TABLE ENSEIGNANT (email OU login)
        if (!$user_found) {
            $sql_enseignant = "SELECT
                                u.id_util,
                                u.login_util,
                                u.mdp_util,
                                e.nom_ens AS nom,
                                e.prenom_ens AS prenom,
                                e.email,
                                gu.lib_GU AS role,
                                gu.id_GU AS role_id
                              FROM utilisateur u
                              JOIN enseignant e ON u.id_util = e.fk_id_util
                              JOIN posseder pos ON u.id_util = pos.fk_id_util
                              JOIN groupe_utilisateur gu ON pos.fk_id_GU = gu.id_GU
                              WHERE e.email = :identifier OR u.login_util = :identifier2";

            $stmt = $pdo->prepare($sql_enseignant);
            $stmt->bindParam(':identifier', $identifier, PDO::PARAM_STR);
            $stmt->bindParam(':identifier2', $identifier, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch();

            if ($user) {
                $user_found = true;
                $user_data = $user;
                $user_type = 'enseignant';
            }
        }

        // 3. SI PAS TROUVÉ, VÉRIFICATION DANS LA TABLE ETUDIANT (email OU login)
        if (!$user_found) {
            $sql_etudiant = "SELECT
                            u.id_util,
                            u.login_util,
                            u.mdp_util,
                            et.nom_etu AS nom,
                            et.prenoms_etu AS prenom,
                            et.email_etu AS email,
                            gu.lib_GU AS role,
                            gu.id_GU AS role_id
                          FROM utilisateur u
                          JOIN etudiant et ON u.id_util = et.fk_id_util
                          JOIN posseder pos ON u.id_util = pos.fk_id_util
                          JOIN groupe_utilisateur gu ON pos.fk_id_GU = gu.id_GU
                          WHERE et.email_etu = :identifier OR u.login_util = :identifier2";

            $stmt = $pdo->prepare($sql_etudiant);
            $stmt->bindParam(':identifier', $identifier, PDO::PARAM_STR);
            $stmt->bindParam(':identifier2', $identifier, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch();

            if ($user) {
                $user_found = true;
                $user_data = $user;
                $user_type = 'etudiant';
            }
        }

        // Vérifier si un utilisateur a été trouvé
        if (!$user_found) {
            $_SESSION['login_error'] = "Identifiant/email ou mot de passe incorrect.";
            header("Location: login.php");
            exit;
        }

        // VÉRIFICATION DU MOT DE PASSE
        $password_valid = false;

        // Vérifier d'abord avec le mot de passe haché dans la base (priorité)
        if (!empty($user_data['mdp_util'])) {
            // Vérifier avec password_verify (recommandé pour les mots de passe générés)
            if (password_verify($password, $user_data['mdp_util'])) {
                $password_valid = true;
            } else {
                // Fallback : vérifier avec SHA256 si c'est l'ancien format
                $hashed_password_input = hash('sha256', $password);
                $password_valid = ($hashed_password_input === $user_data['mdp_util']);
            }
        } else {
            // MOTS DE PASSE DE TEST UNIQUEMENT POUR LES COMPTES SANS MOT DE PASSE GÉNÉRÉ
            $test_passwords = [
                'brouKoua2004@gmail.com' => 'enseignant123',
                'yahchrist@gmail.com' => 'secretaire123',
                'seriMar@gmail.com' => 'communication123'
            ];
            
            // Chercher par email dans les mots de passe de test
            $test_email = $user_data['email'] ?? '';
            if (isset($test_passwords[$test_email]) && $test_passwords[$test_email] === $password) {
                $password_valid = true;
            }
        }

        if (!$password_valid) {
            $_SESSION['login_error'] = "Mot de passe incorrect.";
            header("Location: login.php");
            exit;
        }

        // AUTHENTIFICATION RÉUSSIE - CRÉATION DE LA SESSION
        $_SESSION['loggedin'] = TRUE;
        $_SESSION['id_util'] = $user_data['id_util'];
        $_SESSION['login_util'] = $user_data['login_util'];
        $_SESSION['nom_prenom'] = $user_data['prenom'] . ' ' . $user_data['nom'];
        $_SESSION['role'] = $user_data['role'];
        $_SESSION['role_id'] = $user_data['role_id'];
        $_SESSION['user_type'] = $user_type;
        $_SESSION['email'] = $user_data['email'];
        $_SESSION['login_time'] = time();

        // Ajouter des informations spécifiques selon le type d'utilisateur
        if ($user_type === 'personnel_admin' && isset($user_data['poste'])) {
            $_SESSION['poste'] = $user_data['poste'];
        }

        // Mise à jour de la dernière activité
        $update_sql = "UPDATE utilisateur SET last_activity = NOW() WHERE id_util = :id_util";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->bindParam(':id_util', $user_data['id_util'], PDO::PARAM_INT);
        $update_stmt->execute();

        // SYSTÈME DE REDIRECTION SELON LE RÔLE
        $redirect_url = 'main.php'; // Page par défaut

        switch ($user_data['role']) {
            case 'Secrétaire':
                $redirect_url = 'dashboard_secretaire.php';
                break;
            case 'Responsable scolarité':
                $redirect_url = 'dashboard_scolarite.php';
                break;
            case 'Chargé de communication':
                $redirect_url = 'dashboard_communication.php';
                break;
            case 'Responsable de filière':
                $redirect_url = 'dashHome.php';
                break;
            case 'Responsable de niveau':
                $redirect_url = 'dashboard_niveau.php';
                break;
            case 'Enseignant':
                $redirect_url = 'dashboard_enseignant.php';
                break;
            case 'Etudiant':
                $redirect_url = 'dashboard_etudiant.php';
                break;
            case 'Doyen':
                $redirect_url = 'dashboard_doyen.php';
                break;
            case 'Commission de validation':
                $redirect_url = 'dashboard_commission.php';
                break;
            default:
                $redirect_url = 'main.php';
        }

        // Message de succès
        $_SESSION['success_message'] = "Connexion réussie ! Bienvenue " . $_SESSION['nom_prenom'];

        // REDIRECTION FINALE
        header("Location: $redirect_url");
        exit;

    } catch (\PDOException $e) {
        // Erreur lors de l'exécution de la requête
        $_SESSION['login_error'] = "Une erreur s'est produite lors de la connexion. Veuillez réessayer.";
        error_log("Erreur login: " . $e->getMessage());
        header("Location: login.php");
        exit;
    }

} else {
    // Si le formulaire n'a pas été soumis via POST, redirige vers la page de connexion
    $_SESSION['login_error'] = "Accès non autorisé.";
    header("Location: login.php");
    exit;
}
?>