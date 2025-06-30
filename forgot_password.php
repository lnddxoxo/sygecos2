<?php
session_start();

$step = $_GET['step'] ?? 'identify';
$message = '';
$error = '';

if (isset($_SESSION['reset_message'])) {
    $message = $_SESSION['reset_message'];
    unset($_SESSION['reset_message']);
}

if (isset($_SESSION['reset_error'])) {
    $error = $_SESSION['reset_error'];
    unset($_SESSION['reset_error']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Réinitialisation du mot de passe</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-50: #f8fafc;
            --primary-100: #f1f5f9;
            --primary-200: #e2e8f0;
            --primary-300: #cbd5e1;
            --primary-400: #94a3b8;
            --primary-500: #64748b;
            --primary-600: #475569;
            --primary-700: #334155;
            --primary-800: #1e293b;
            --primary-900: #0f172a;

            --accent-50: #eff6ff;
            --accent-100: #dbeafe;
            --accent-200: #bfdbfe;
            --accent-300: #93c5fd;
            --accent-400: #60a5fa;
            --accent-500: #3b82f6;
            --accent-600: #2563eb;
            --accent-700: #1d4ed8;
            --accent-800: #1e40af;
            --accent-900: #1e3a8a;

            --success-500: #22c55e;
            --error-500: #ef4444;
            --warning-500: #f59e0b;

            --font-primary: 'Segoe UI', system-ui, -apple-system, sans-serif;

            --space-2: 0.5rem;
            --space-3: 0.75rem;
            --space-4: 1rem;
            --space-5: 1.25rem;
            --space-6: 1.5rem;
            --space-8: 2rem;

            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;

            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);

            --glass-bg: rgba(255, 255, 255, 0.25);
            --glass-border: rgba(255, 255, 255, 0.18);
            --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
            --glass-backdrop: blur(40px);

            --transition-normal: 250ms ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-primary);
            background: linear-gradient(135deg, 
                var(--primary-100) 0%, 
                var(--accent-50) 25%, 
                var(--primary-50) 50%, 
                var(--accent-100) 75%, 
                var(--primary-100) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--space-4);
        }

        .reset-container {
            width: 100%;
            max-width: 500px;
            background: var(--glass-bg);
            backdrop-filter: var(--glass-backdrop);
            -webkit-backdrop-filter: var(--glass-backdrop);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-2xl);
            box-shadow: var(--glass-shadow);
            padding: var(--space-8);
            animation: slideUp 0.8s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .back-button {
            position: fixed;
            top: var(--space-4);
            left: var(--space-4);
            background: var(--glass-bg);
            backdrop-filter: var(--glass-backdrop);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-lg);
            padding: var(--space-2) var(--space-4);
            color: var(--primary-700);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all var(--transition-normal);
            box-shadow: var(--shadow-sm);
            z-index: 10;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.4);
            transform: translateX(-2px);
            box-shadow: var(--shadow-md);
        }

        .header {
            text-align: center;
            margin-bottom: var(--space-8);
        }

        .logo-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent-500), var(--accent-600));
            border-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto var(--space-4);
            color: white;
            font-size: 1.5rem;
        }

        .title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-800);
            margin-bottom: var(--space-2);
        }

        .subtitle {
            color: var(--primary-600);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .message {
            padding: var(--space-4);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-6);
            text-align: center;
            font-size: 0.9rem;
            animation: fadeIn 0.5s ease-out;
        }

        .message.success {
            background-color: #d1fae5;
            border: 1px solid #86efac;
            color: #065f46;
        }

        .message.error {
            background-color: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-group {
            margin-bottom: var(--space-5);
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: var(--primary-700);
            font-size: 0.9rem;
            margin-bottom: var(--space-2);
        }

        .form-input {
            width: 100%;
            padding: var(--space-4);
            border: 2px solid var(--primary-200);
            border-radius: var(--radius-lg);
            font-size: 0.95rem;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            transition: all var(--transition-normal);
            outline: none;
        }

        .form-input:focus {
            border-color: var(--accent-500);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-input::placeholder {
            color: var(--primary-400);
        }

        .submit-button {
            width: 100%;
            padding: var(--space-4) var(--space-6);
            background: linear-gradient(135deg, var(--accent-500), var(--accent-600));
            color: white;
            border: none;
            border-radius: var(--radius-lg);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-normal);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            box-shadow: var(--shadow-md);
        }

        .submit-button:hover {
            background: linear-gradient(135deg, var(--accent-600), var(--accent-700));
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .submit-button:active {
            transform: translateY(0);
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            gap: var(--space-2);
            margin-bottom: var(--space-6);
        }

        .step {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all var(--transition-normal);
        }

        .step.active {
            background: var(--accent-500);
            color: white;
        }

        .step.completed {
            background: var(--success-500);
            color: white;
        }

        .step.inactive {
            background: var(--primary-200);
            color: var(--primary-500);
        }

        .questions-container {
            display: none;
        }

        .questions-container.active {
            display: block;
        }

        .question-item {
            margin-bottom: var(--space-4);
            padding: var(--space-4);
            background: rgba(255, 255, 255, 0.6);
            border-radius: var(--radius-lg);
            border: 1px solid var(--primary-200);
        }

        .question-text {
            font-weight: 600;
            color: var(--primary-700);
            margin-bottom: var(--space-2);
            font-size: 0.9rem;
        }

        .divider {
            height: 1px;
            background: var(--primary-200);
            margin: var(--space-6) 0;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease-out;
        }

        .modal-content {
            background: white;
            padding: var(--space-8);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 400px;
            text-align: center;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-800);
            margin-bottom: var(--space-4);
        }

        .password-container {
            position: relative;
            margin-bottom: var(--space-4);
        }

        .password-toggle {
            position: absolute;
            right: var(--space-4);
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--primary-500);
            cursor: pointer;
            font-size: 1rem;
            transition: color var(--transition-normal);
        }

        .password-toggle:hover {
            color: var(--accent-600);
        }

        .password-strength {
            margin-top: var(--space-2);
            padding: var(--space-2);
            border-radius: var(--radius-md);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .password-strength.weak {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .password-strength.medium {
            background-color: #fef3c7;
            color: #92400e;
        }

        .password-strength.strong {
            background-color: #d1fae5;
            color: #065f46;
        }

        @media (max-width: 480px) {
            body {
                padding: var(--space-2);
            }

            .reset-container {
                padding: var(--space-6);
            }

            .title {
                font-size: 1.5rem;
            }

            .back-button {
                top: var(--space-2);
                left: var(--space-2);
                padding: var(--space-2) var(--space-3);
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <a href="loginForm.php" class="back-button">
        <i class="fas fa-arrow-left"></i>
        Retour à la connexion
    </a>

    <div class="reset-container">
        <div class="header">
            <div class="logo-icon">
                <i class="fas fa-key"></i>
            </div>

        <?php if ($step == 'identify'): ?>
            <!-- Étape 1: Identification -->
            <div class="step-indicator">
                <div class="step active">1</div>
                <div class="step inactive">2</div>
                <div class="step inactive">3</div>
            </div>

            <?php if ($message): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="process_forgot_password.php" method="POST">
                <input type="hidden" name="step" value="identify">
                
                <div class="form-group">
                    <label for="identifier" class="form-label">Email ou Identifiant</label>
                    <input 
                        type="text" 
                        id="identifier" 
                        name="identifier" 
                        class="form-input" 
                        placeholder="Entrez votre email ou identifiant"
                        required
                    >
                </div>

                <button type="submit" class="submit-button">
                    <i class="fas fa-search"></i>
                    Rechercher mon compte
                </button>
            </form>

        <?php elseif ($step == 'security'): ?>
            <!-- Étape 2: Questions de sécurité -->
            <div class="step-indicator">
                <div class="step completed">1</div>
                <div class="step active">2</div>
                <div class="step inactive">3</div>
            </div>

            <?php if ($error): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="questions-container active">
                <form action="process_forgot_password.php" method="POST">
                    <input type="hidden" name="step" value="security">
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($_SESSION['reset_user_id'] ?? ''); ?>">
                    
                    <p style="margin-bottom: var(--space-6); color: var(--primary-600); text-align: center; font-size: 0.9rem;">
                        Répondez aux questions de sécurité pour vérifier votre identité
                    </p>

                    <?php 
                    // Simuler les questions de sécurité pour la démo
                    $security_questions = [
                        ['id' => 1, 'question' => 'Quel est le nom de votre premier animal de compagnie ?'],
                        ['id' => 4, 'question' => 'Quel était le nom de votre école primaire ?']
                    ];
                    
                    foreach ($security_questions as $index => $question): 
                    ?>
                        <div class="question-item">
                            <div class="question-text">
                                <?php echo htmlspecialchars($question['question']); ?>
                            </div>
                            <input 
                                type="hidden" 
                                name="question_ids[]" 
                                value="<?php echo $question['id']; ?>"
                            >
                            <input 
                                type="text" 
                                name="answers[]" 
                                class="form-input" 
                                placeholder="Votre réponse..."
                                required
                                style="margin-top: var(--space-2);"
                            >
                        </div>
                    <?php endforeach; ?>

                    <div class="divider"></div>

                    <div class="form-group">
                        <label for="email_verification" class="form-label">
                            <i class="fas fa-envelope"></i>
                            Email de vérification
                        </label>
                        <input 
                            type="email" 
                            id="email_verification" 
                            name="email_verification" 
                            class="form-input" 
                            placeholder="Confirmez votre adresse email"
                            required
                        >
                        <small style="color: var(--primary-500); font-size: 0.8rem; margin-top: var(--space-1); display: block;">
                            Un email de vérification sera envoyé à cette adresse
                        </small>
                    </div>

                    <button type="submit" class="submit-button">
                        <i class="fas fa-check"></i>
                        Vérifier mes réponses
                    </button>
                </form>
            </div>

        <?php elseif ($step == 'verified'): ?>
            <!-- Étape 3: Compte vérifié, attente du nouveau mot de passe -->
            <div class="step-indicator">
                <div class="step completed">1</div>
                <div class="step completed">2</div>
                <div class="step active">3</div>
            </div>

            <div class="message success">
                <i class="fas fa-check-circle"></i>
                Vérification réussie ! Un email de confirmation a été envoyé.
            </div>

            <p style="text-align: center; color: var(--primary-600); margin-bottom: var(--space-6);">
                Cliquez sur le bouton ci-dessous pour définir votre nouveau mot de passe.
            </p>

            <button type="button" class="submit-button" onclick="showPasswordModal()">
                <i class="fas fa-key"></i>
                Définir un nouveau mot de passe
            </button>

        <?php endif; ?>
    </div>

    <!-- Modal pour le nouveau mot de passe -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <h3 class="modal-title">Nouveau mot de passe</h3>
            
            <form id="newPasswordForm" action="process_forgot_password.php" method="POST">
                <input type="hidden" name="step" value="reset">
                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($_SESSION['reset_user_id'] ?? ''); ?>">
                
                <div class="form-group">
                    <label for="new_password" class="form-label">Nouveau mot de passe</label>
                    <div class="password-container">
                        <input 
                            type="password" 
                            id="new_password" 
                            name="new_password" 
                            class="form-input" 
                            placeholder="Entrez votre nouveau mot de passe"
                            required
                            minlength="8"
                            onkeyup="checkPasswordStrength()"
                        >
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('new_password', 'toggle-icon-new')">
                            <i class="fas fa-eye" id="toggle-icon-new"></i>
                        </button>
                    </div>
                    <div id="password-strength" class="password-strength" style="display: none;"></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirmer le mot de passe</label>
                    <div class="password-container">
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="form-input" 
                            placeholder="Confirmez votre nouveau mot de passe"
                            required
                            onkeyup="checkPasswordMatch()"
                        >
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('confirm_password', 'toggle-icon-confirm')">
                            <i class="fas fa-eye" id="toggle-icon-confirm"></i>
                        </button>
                    </div>
                    <div id="password-match" style="margin-top: var(--space-2); font-size: 0.8rem; display: none;"></div>
                </div>

                <button type="submit" class="submit-button" id="submitPasswordBtn" disabled>
                    <i class="fas fa-save"></i>
                    Enregistrer le nouveau mot de passe
                </button>
            </form>
        </div>
    </div>

    <script>
        // Fonction pour afficher le modal
        function showPasswordModal() {
            document.getElementById('passwordModal').classList.add('show');
            document.getElementById('new_password').focus();
        }

        // Fermer le modal en cliquant à l'extérieur
        document.getElementById('passwordModal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('show');
            }
        });

        // Fonction pour basculer la visibilité du mot de passe
        function togglePasswordVisibility(inputId, iconId) {
            const passwordInput = document.getElementById(inputId);
            const toggleIcon = document.getElementById(iconId);
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Vérification de la force du mot de passe
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthDiv = document.getElementById('password-strength');
            const submitBtn = document.getElementById('submitPasswordBtn');
            
            if (password.length === 0) {
                strengthDiv.style.display = 'none';
                return;
            }
            
            strengthDiv.style.display = 'block';
            
            let score = 0;
            let feedback = [];
            
            // Critères de validation
            if (password.length >= 8) score++;
            else feedback.push('au moins 8 caractères');
            
            if (/[a-z]/.test(password)) score++;
            else feedback.push('une lettre minuscule');
            
            if (/[A-Z]/.test(password)) score++;
            else feedback.push('une lettre majuscule');
            
            if (/[0-9]/.test(password)) score++;
            else feedback.push('un chiffre');
            
            if (/[^a-zA-Z0-9]/.test(password)) score++;
            else feedback.push('un caractère spécial');
            
            // Affichage du niveau de sécurité
            if (score < 3) {
                strengthDiv.className = 'password-strength weak';
                strengthDiv.innerHTML = `<i class="fas fa-times-circle"></i> Faible - Ajoutez: ${feedback.join(', ')}`;
            } else if (score < 5) {
                strengthDiv.className = 'password-strength medium';
                strengthDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> Moyen - Améliorez: ${feedback.join(', ')}`;
            } else {
                strengthDiv.className = 'password-strength strong';
                strengthDiv.innerHTML = '<i class="fas fa-check-circle"></i> Fort - Mot de passe sécurisé';
            }
            
            // Vérifier la correspondance des mots de passe
            checkPasswordMatch();
        }

        // Vérification de la correspondance des mots de passe
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchDiv = document.getElementById('password-match');
            const submitBtn = document.getElementById('submitPasswordBtn');
            
            if (confirmPassword.length === 0) {
                matchDiv.style.display = 'none';
                submitBtn.disabled = true;
                return;
            }
            
            matchDiv.style.display = 'block';
            
            if (password === confirmPassword && password.length >= 8) {
                matchDiv.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success-500);"></i> Les mots de passe correspondent';
                matchDiv.style.color = 'var(--success-500)';
                
                // Vérifier aussi la force du mot de passe
                const strengthDiv = document.getElementById('password-strength');
                if (strengthDiv.classList.contains('strong') || strengthDiv.classList.contains('medium')) {
                    submitBtn.disabled = false;
                } else {
                    submitBtn.disabled = true;
                }
            } else {
                matchDiv.innerHTML = '<i class="fas fa-times-circle" style="color: var(--error-500);"></i> Les mots de passe ne correspondent pas';
                matchDiv.style.color = 'var(--error-500)';
                submitBtn.disabled = true;
            }
        }

        // Auto-focus sur le premier champ lors du chargement
        document.addEventListener('DOMContentLoaded', function() {
            const firstInput = document.querySelector('input[type="text"], input[type="email"]');
            if (firstInput) {
                firstInput.focus();
            }
        });

        // Gestion de la soumission du formulaire de nouveau mot de passe
        document.getElementById('newPasswordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                alert('Les mots de passe ne correspondent pas.');
                return;
            }
            
            if (password.length < 8) {
                alert('Le mot de passe doit contenir au moins 8 caractères.');
                return;
            }
            
            // Simuler la soumission réussie
            this.submit();
        });
    </script>
</body>
</html>
            <h1 class="title">Réinitialisation</h1>
            <p class="subtitle">Récupérez l'accès à votre compte SYGECOS</p>