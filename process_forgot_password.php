<?php
session_start();

// Configuration de la base de données
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
    $_SESSION['reset_error'] = "Erreur de connexion à la base de données.";
    header("Location: forgot_password.php");
    exit;
}

// Fonction pour trouver un utilisateur par email ou identifiant
function findUserByIdentifier($pdo, $identifier) {
    // Recherche dans personnel_admin
    $sql_admin = "SELECT u.id_util, u.login_util, p.email_pers as email, p.nom_pers as nom, p.prenoms_pers as prenom, 'personnel_admin' as user_type
                  FROM utilisateur u
                  JOIN personnel_admin p ON u.id_util = p.fk_id_util
                  WHERE p.email_pers = :identifier OR u.login_util = :identifier2";
    
    $stmt = $pdo->prepare($sql_admin);
    $stmt->bindParam(':identifier', $identifier);
    $stmt->bindParam(':identifier2', $identifier);
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) return $user;
    
    // Recherche dans enseignant
    $sql_enseignant = "SELECT u.id_util, u.login_util, e.email, e.nom_ens as nom, e.prenom_ens as prenom, 'enseignant' as user_type
                       FROM utilisateur u
                       JOIN enseignant e ON u.id_util = e.fk_id_util
                       WHERE e.email = :identifier OR u.login_util = :identifier2";
    
    $stmt = $pdo->prepare($sql_enseignant);
    $stmt->bindParam(':identifier', $identifier);
    $stmt->bindParam(':identifier2', $identifier);
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) return $user;
    
    // Recherche dans etudiant
    $sql_etudiant = "SELECT u.id_util, u.login_util, et.email_etu as email, et.nom_etu as nom, et.prenoms_etu as prenom, 'etudiant' as user_type
                     FROM utilisateur u
                     JOIN etudiant et ON u.id_util = et.fk_id_util
                     WHERE et.email_etu = :identifier OR u.login_util = :identifier2";
    
    $stmt = $pdo->prepare($sql_etudiant);
    $stmt->bindParam(':identifier', $identifier);
    $stmt->bindParam(':identifier2', $identifier);
    $stmt->execute();
    $user = $stmt->fetch();
    
    return $user ?: null;
}

// Fonction pour récupérer les questions de sécurité d'un utilisateur
function getUserSecurityQuestions($pdo, $user_id) {
    $sql = "SELECT sq.id_question, sq.question_text 
            FROM security_questions sq 
            JOIN user_security_answers usa ON sq.id_question = usa.fk_id_question 
            WHERE usa.fk_id_util = :user_id AND sq.is_active = 1 
            ORDER BY RAND() 
            LIMIT 2";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    
    return $stmt->fetchAll();
}

// Fonction pour vérifier les réponses aux questions de sécurité
function verifySecurityAnswers($pdo, $user_id, $question_ids, $answers) {
    $correct_answers = 0;
    $total_questions = count($question_ids);
    
    for ($i = 0; $i < $total_questions; $i++) {
        $sql = "SELECT answer_hash FROM user_security_answers 
                WHERE fk_id_util = :user_id AND fk_id_question = :question_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':question_id', $question_ids[$i]);
        $stmt->execute();
        $result = $stmt->fetch();
        
        if ($result && password_verify(strtolower(trim($answers[$i])), $result['answer_hash'])) {
            $correct_answers++;
        }
    }
    
    return $correct_answers === $total_questions;
}

// Fonction pour envoyer un email de vérification (simulation)
function sendVerificationEmail($email, $name, $token) {
    // Dans un vrai système, vous utiliseriez une bibliothèque comme PHPMailer
    // Ici, nous simulons l'envoi
    
    $subject = "SYGECOS - Vérification de réinitialisation de mot de passe";
    $message = "
    Bonjour $name,
    
    Vous avez demandé la réinitialisation de votre mot de passe SYGECOS.
    
    Pour des raisons de sécurité, veuillez confirmer cette demande en utilisant le code de vérification suivant :
    
    Code de vérification : $token
    
    Ce code est valide pendant 15 minutes.
    
    Si vous n'avez pas demandé cette réinitialisation, veuillez ignorer cet email.
    
    Cordialement,
    L'équipe SYGECOS
    ";
    
    // Simulation de l'envoi d'email (retourne toujours true pour la démo)
    error_log("EMAIL SIMULÉ - To: $email, Subject: $subject, Message: $message");
    return true;
}

// Traitement selon l'étape
$step = $_POST['step'] ?? '';

switch ($step) {
    case 'identify':
        $identifier = trim($_POST['identifier'] ?? '');
        
        if (empty($identifier)) {
            $_SESSION['reset_error'] = "Veuillez entrer votre email ou identifiant.";
            header("Location: forgot_password.php");
            exit;
        }
        
        // Rechercher l'utilisateur
        $user = findUserByIdentifier($pdo, $identifier);
        
        if (!$user) {
            $_SESSION['reset_error'] = "Aucun compte trouvé avec cet email ou identifiant.";
            header("Location: forgot_password.php");
            exit;
        }
        
        // Vérifier si l'utilisateur a des questions de sécurité configurées
        $security_questions = getUserSecurityQuestions($pdo, $user['id_util']);
        
        if (empty($security_questions)) {
            // Si pas de questions de sécurité, créer des questions par défaut pour la démo
            // Dans un vrai système, on redirigerait vers une autre méthode de récupération
            $default_questions = [
                ['id_question' => 1, 'question_text' => 'Quel est le nom de votre premier animal de compagnie ?'],
                ['id_question' => 4, 'question_text' => 'Quel était le nom de votre école primaire ?']
            ];
            
            // Créer des réponses par défaut pour la démo (dans un vrai système, l'utilisateur les aurait configurées)
            foreach ($default_questions as $question) {
                $default_answer = ($question['id_question'] == 1) ? 'minou' : 'ecole primaire';
                $answer_hash = password_hash(strtolower($default_answer), PASSWORD_DEFAULT);
                
                $insert_sql = "INSERT IGNORE INTO user_security_answers (fk_id_util, fk_id_question, answer_hash) 
                              VALUES (:user_id, :question_id, :answer_hash)";
                $insert_stmt = $pdo->prepare($insert_sql);
                $insert_stmt->bindParam(':user_id', $user['id_util']);
                $insert_stmt->bindParam(':question_id', $question['id_question']);
                $insert_stmt->bindParam(':answer_hash', $answer_hash);
                $insert_stmt->execute();
            }
        }
        
        // Stocker les informations de l'utilisateur en session
        $_SESSION['reset_user_id'] = $user['id_util'];
        $_SESSION['reset_user_email'] = $user['email'];
        $_SESSION['reset_user_name'] = $user['prenom'] . ' ' . $user['nom'];
        
        // Rediriger vers l'étape des questions de sécurité
        header("Location: forgot_password.php?step=security");
        exit;
        break;
        
    case 'security':
        $user_id = $_POST['user_id'] ?? $_SESSION['reset_user_id'] ?? null;
        $question_ids = $_POST['question_ids'] ?? [];
        $answers = $_POST['answers'] ?? [];
        $email_verification = trim($_POST['email_verification'] ?? '');
        
        if (!$user_id || empty($question_ids) || empty($answers) || empty($email_verification)) {
            $_SESSION['reset_error'] = "Veuillez remplir tous les champs.";
            header("Location: forgot_password.php?step=security");
            exit;
        }
        
        // Vérifier l'email de vérification
        if ($email_verification !== $_SESSION['reset_user_email']) {
            $_SESSION['reset_error'] = "L'email de vérification ne correspond pas à votre compte.";
            header("Location: forgot_password.php?step=security");
            exit;
        }
        
        // Vérifier les réponses aux questions de sécurité
        if (!verifySecurityAnswers($pdo, $user_id, $question_ids, $answers)) {
            $_SESSION['reset_error'] = "Les réponses aux questions de sécurité sont incorrectes.";
            
            // Enregistrer la tentative échouée
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $history_sql = "INSERT INTO password_reset_history (fk_id_util, ip_address, user_agent, reset_method, success) 
                           VALUES (:user_id, :ip, :user_agent, 'security_questions', 0)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->bindParam(':user_id', $user_id);
            $history_stmt->bindParam(':ip', $ip_address);
            $history_stmt->bindParam(':user_agent', $user_agent);
            $history_stmt->execute();
            
            header("Location: forgot_password.php?step=security");
            exit;
        }
        
        // Générer un token de vérification
        $verification_token = strtoupper(substr(md5(uniqid() . time()), 0, 8));
        
        // Stocker le token en session pour la vérification
        $_SESSION['verification_token'] = $verification_token;
        $_SESSION['token_generated_at'] = time();
        
        // Envoyer l'email de vérification
        $email_sent = sendVerificationEmail(
            $_SESSION['reset_user_email'], 
            $_SESSION['reset_user_name'], 
            $verification_token
        );
        
        if ($email_sent) {
            // Enregistrer la tentative réussie
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $history_sql = "INSERT INTO password_reset_history (fk_id_util, ip_address, user_agent, reset_method, success) 
                           VALUES (:user_id, :ip, :user_agent, 'security_questions', 1)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->bindParam(':user_id', $user_id);
            $history_stmt->bindParam(':ip', $ip_address);
            $history_stmt->bindParam(':user_agent', $user_agent);
            $history_stmt->execute();
            
            $_SESSION['reset_message'] = "Vérification réussie ! Un code de vérification a été envoyé à votre email.";
            header("Location: forgot_password.php?step=verified");
        } else {
            $_SESSION['reset_error'] = "Erreur lors de l'envoi de l'email de vérification.";
            header("Location: forgot_password.php?step=security");
        }
        exit;
        break;
        
    case 'reset':
        $user_id = $_POST['user_id'] ?? $_SESSION['reset_user_id'] ?? null;
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!$user_id || empty($new_password) || empty($confirm_password)) {
            $_SESSION['reset_error'] = "Veuillez remplir tous les champs.";
            header("Location: forgot_password.php?step=verified");
            exit;
        }
        
        // Vérifier que les mots de passe correspondent
        if ($new_password !== $confirm_password) {
            $_SESSION['reset_error'] = "Les mots de passe ne correspondent pas.";
            header("Location: forgot_password.php?step=verified");
            exit;
        }
        
        // Vérifier la force du mot de passe
        if (strlen($new_password) < 8) {
            $_SESSION['reset_error'] = "Le mot de passe doit contenir au moins 8 caractères.";
            header("Location: forgot_password.php?step=verified");
            exit;
        }
        
        // Vérifier que le token de vérification est encore valide (15 minutes)
        $token_age = time() - ($_SESSION['token_generated_at'] ?? 0);
        if ($token_age > 900) { // 15 minutes
            $_SESSION['reset_error'] = "Le code de vérification a expiré. Veuillez recommencer le processus.";
            // Nettoyer la session
            unset($_SESSION['reset_user_id'], $_SESSION['reset_user_email'], $_SESSION['reset_user_name']);
            unset($_SESSION['verification_token'], $_SESSION['token_generated_at']);
            header("Location: forgot_password.php");
            exit;
        }
        
        try {
            // Hacher le nouveau mot de passe
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Mettre à jour le mot de passe dans la base de données
            $update_sql = "UPDATE utilisateur 
                          SET mdp_util = :password_hash, 
                              password_changed_at = NOW(),
                              failed_login_attempts = 0,
                              locked_until = NULL
                          WHERE id_util = :user_id";
            
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->bindParam(':password_hash', $password_hash);
            $update_stmt->bindParam(':user_id', $user_id);
            $update_stmt->execute();
            
            // Créer un token de réinitialisation pour l'historique
            $reset_token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 heure
            
            $token_sql = "INSERT INTO password_reset_tokens (fk_id_util, token, email, expires_at, used) 
                         VALUES (:user_id, :token, :email, :expires_at, 1)";
            $token_stmt = $pdo->prepare($token_sql);
            $token_stmt->bindParam(':user_id', $user_id);
            $token_stmt->bindParam(':token', $reset_token);
            $token_stmt->bindParam(':email', $_SESSION['reset_user_email']);
            $token_stmt->bindParam(':expires_at', $expires_at);
            $token_stmt->execute();
            
            // Nettoyer la session
            unset($_SESSION['reset_user_id'], $_SESSION['reset_user_email'], $_SESSION['reset_user_name']);
            unset($_SESSION['verification_token'], $_SESSION['token_generated_at']);
            
            // Message de succès et redirection vers la page de connexion
            $_SESSION['login_success'] = "Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.";
            header("Location: loginForm.php");
            exit;
            
        } catch (\PDOException $e) {
            $_SESSION['reset_error'] = "Erreur lors de la mise à jour du mot de passe.";
            error_log("Erreur reset password: " . $e->getMessage());
            header("Location: forgot_password.php?step=verified");
            exit;
        }
        break;
        
    default:
        $_SESSION['reset_error'] = "Action non valide.";
        header("Location: forgot_password.php");
        exit;
}
?> {
            $_SESSION['reset_error'] = "Veuillez entrer votre email ou identifiant.";
            header("Location: forgot_password.php");
            exit;
        }
        
        // Rechercher l'utilisateur
        $user = findUserByIdentifier($pdo, $identifier);
        
        if (!$user) {
            $_SESSION['reset_error'] = "Aucun compte trouvé avec cet email ou identifiant.";
            header("Location: forgot_password.php");
            exit;
        }
        
        // Vérifier si l'utilisateur a des questions de sécurité configurées
        $security_questions = getUserSecurityQuestions($pdo, $user['id_util']);
        
        if (empty($security_questions)) {
            // Si pas de questions de sécurité, créer des questions par défaut pour la démo
            // Dans un vrai système, on redirigerait vers une autre méthode de récupération
            $default_questions = [
                ['id_question' => 1, 'question_text' => 'Quel est le nom de votre premier animal de compagnie ?'],
                ['id_question' => 4, 'question_text' => 'Quel était le nom de votre école primaire ?']
            ];
            
            // Créer des réponses par défaut pour la démo (dans un vrai système, l'utilisateur les aurait configurées)
            foreach ($default_questions as $question) {
                $default_answer = ($question['id_question'] == 1) ? 'minou' : 'ecole primaire';
                $answer_hash = password_hash(strtolower($default_answer), PASSWORD_DEFAULT);
                
                $insert_sql = "INSERT IGNORE INTO user_security_answers (fk_id_util, fk_id_question, answer_hash) 
                              VALUES (:user_id, :question_id, :answer_hash)";
                $insert_stmt = $pdo->prepare($insert_sql);
                $insert_stmt->bindParam(':user_id', $user['id_util']);
                $insert_stmt->bindParam(':question_id', $question['id_question']);
                $insert_stmt->bindParam(':answer_hash', $answer_hash);
                $insert_stmt->execute();
            }
        }
        
        // Stocker les informations de l'utilisateur en session
        $_SESSION['reset_user_id'] = $user['id_util'];
        $_SESSION['reset_user_email'] = $user['email'];
        $_SESSION['reset_user_name'] = $user['prenom'] . ' ' . $user['nom'];
        
        // Rediriger vers l'étape des questions de sécurité
        header("Location: forgot_password.php?step=security");
        exit;
        break;
        
    case 'security':
        $user_id = $_POST['user_id'] ?? $_SESSION['reset_user_id'] ?? null;
        $question_ids = $_POST['question_ids'] ?? [];
        $answers = $_POST['answers'] ?? [];
        $email_verification = trim($_POST['email_verification'] ?? '');
        
        if (!$user_id || empty($question_ids) || empty($answers) || empty($email_verification)) {
            $_SESSION['reset_error'] = "Veuillez remplir tous les champs.";
            header("Location: forgot_password.php?step=security");
            exit;
        }
        
        // Vérifier l'email de vérification
        if ($email_verification !== $_SESSION['reset_user_email']) {
            $_SESSION['reset_error'] = "L'email de vérification ne correspond pas à votre compte.";
            header("Location: forgot_password.php?step=security");
            exit;
        }
        
        // Vérifier les réponses aux questions de sécurité
        if (!verifySecurityAnswers($pdo, $user_id, $question_ids, $answers)) {
            $_SESSION['reset_error'] = "Les réponses aux questions de sécurité sont incorrectes.";
            
            // Enregistrer la tentative échouée
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $history_sql = "INSERT INTO password_reset_history (fk_id_util, ip_address, user_agent, reset_method, success) 
                           VALUES (:user_id, :ip, :user_agent, 'security_questions', 0)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->bindParam(':user_id', $user_id);
            $history_stmt->bindParam(':ip', $ip_address);
            $history_stmt->bindParam(':user_agent', $user_agent);
            $history_stmt->execute();
            
            header("Location: forgot_password.php?step=security");
            exit;
        }
        
        // Générer un token de vérification
        $verification_token = strtoupper(substr(md5(uniqid() . time()), 0, 8));
        
        // Stocker le token en session pour la vérification
        $_SESSION['verification_token'] = $verification_token;
        $_SESSION['token_generated_at'] = time();
        
        // Envoyer l'email de vérification
        $email_sent = sendVerificationEmail(
            $_SESSION['reset_user_email'], 
            $_SESSION['reset_user_name'], 
            $verification_token
        );
        
        if ($email_sent) {
            // Enregistrer la tentative réussie
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $history_sql = "INSERT INTO password_reset_history (fk_id_util, ip_address, user_agent, reset_method, success) 
                           VALUES (:user_id, :ip, :user_agent, 'security_questions', 1)";
            $history_stmt = $pdo->prepare($history_sql);
            $history_stmt->bindParam(':user_id', $user_id);
            $history_stmt->bindParam(':ip', $ip_address);
            $history_stmt->bindParam(':user_agent', $user_agent);
            $history_stmt->execute();
            
            $_SESSION['reset_message'] = "Vérification réussie ! Un code de vérification a été envoyé à votre email.";
            header("Location: forgot_password.php?step=verified");
        } else {
            $_SESSION['reset_error'] = "Erreur lors de l'envoi de l'email de vérification.";
            header("Location: forgot_password.php?step=security");
        }
        exit;
        break;
        
    case 'reset':
        $user_id = $_POST['user_id'] ?? $_SESSION['reset_user_id'] ?? null;
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!$user_id || empty($new_password) || empty($confirm_password)) {
            $_SESSION['reset_error'] = "Veuillez remplir tous les champs.";
            header("Location: forgot_password.php?step=verified");
            exit;
        }
        
        // Vérifier que les mots de passe correspondent
        if ($new_password !== $confirm_password) {
            $_SESSION['reset_error'] = "Les mots de passe ne correspondent pas.";
            header("Location: forgot_password.php?step=verified");
            exit;
        }
        
        // Vérifier la force du mot de passe
        if (strlen($new_password) < 8) {
            $_SESSION['reset_error'] = "Le mot de passe doit contenir au moins 8 caractères.";
            header("Location: forgot_password.php?step=verified");
            exit;
        }
        
        // Vérifier que le token de vérification est encore valide (15 minutes)
        $token_age = time() - ($_SESSION['token_generated_at'] ?? 0);
        if ($token_age > 900) { // 15 minutes
            $_SESSION['reset_error'] = "Le code de vérification a expiré. Veuillez recommencer le processus.";
            // Nettoyer la session
            unset($_SESSION['reset_user_id'], $_SESSION['reset_user_email'], $_SESSION['reset_user_name']);
            unset($_SESSION['verification_token'], $_SESSION['token_generated_at']);
            header("Location: forgot_password.php");
            exit;
        }
        
        try {
            // Hacher le nouveau mot de passe
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Mettre à jour le mot de passe dans la base de données
            $update_sql = "UPDATE utilisateur 
                          SET mdp_util = :password_hash, 
                              password_changed_at = NOW(),
                              failed_login_attempts = 0,
                              locked_until = NULL
                          WHERE id_util = :user_id";
            
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->bindParam(':password_hash', $password_hash);
            $update_stmt->bindParam(':user_id', $user_id);
            $update_stmt->execute();
            
            // Créer un token de réinitialisation pour l'historique
            $reset_token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 heure
            
            $token_sql = "INSERT INTO password_reset_tokens (fk_id_util, token, email, expires_at, used) 
                         VALUES (:user_id, :token, :email, :expires_at, 1)";
            $token_stmt = $pdo->prepare($token_sql);
            $token_stmt->bindParam(':user_id', $user_id);
            $token_stmt->bindParam(':token', $reset_token);
            $token_stmt->bindParam(':email', $_SESSION['reset_user_email']);
            $token_stmt->bindParam(':expires_at', $expires_at);
            $token_stmt->execute();
            
            // Nettoyer la session
            unset($_SESSION['reset_user_id'], $_SESSION['reset_user_email'], $_SESSION['reset_user_name']);
            unset($_SESSION['verification_token'], $_SESSION['token_generated_at']);
            
            // Message de succès et redirection vers la page de connexion
            $_SESSION['login_success'] = "Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.";
            header("Location: loginForm.php");
            exit;
            
        } catch (\PDOException $e) {
            $_SESSION['reset_error'] = "Erreur lors de la mise à jour du mot de passe.";
            error_log("Erreur reset password: " . $e->getMessage());
            header("Location: forgot_password.php?step=verified");
            exit;
        }
        break;
        
    default:
        $_SESSION['reset_error'] = "Action non valide.";
        header("Location: forgot_password.php");
        exit;
}
?>