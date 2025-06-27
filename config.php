<?php
// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'sygecos');
define('DB_USER', 'root');
define('DB_PASS', '');

// Configuration de l'application
define('APP_NAME', 'SYGECOS');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/sygecos');

// Configuration des sessions
define('SESSION_TIMEOUT', 3600); // 1 heure en secondes

// Configuration de sécurité
define('PASSWORD_MIN_LENGTH', 6);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 300); // 5 minutes en secondes

try {
    // Connexion à la base de données avec PDO
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Fonction pour vérifier si l'utilisateur est connecté
function isLoggedIn() {
    return isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === TRUE;
}

// Fonction pour vérifier le rôle de l'utilisateur
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Fonction pour rediriger
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Fonction pour nettoyer les données d'entrée
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Fonction pour générer un token CSRF
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Fonction pour vérifier le token CSRF
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>