<?php
session_start(); // Démarre la session pour récupérer les messages d'erreur

$login_error = '';
if (isset($_SESSION['login_error'])) {
    $login_error = $_SESSION['login_error'];
    unset($_SESSION['login_error']); // Efface le message après l'avoir affiché
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Connexion</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* === VARIABLES CSS === */
        :root {
            /* Couleurs Primaires */
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

            /* Couleurs d'Accent Bleu */
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

            /* Couleurs Secondaires */
            --secondary-500: #22c55e;
            --secondary-600: #16a34a;

            /* Typographie */
            --font-primary: 'Segoe UI', system-ui, -apple-system, sans-serif;

            /* Espacement */
            --space-1: 0.25rem;
            --space-2: 0.5rem;
            --space-3: 0.75rem;
            --space-4: 1rem;
            --space-5: 1.25rem;
            --space-6: 1.5rem;
            --space-8: 2rem;
            --space-10: 2.5rem;
            --space-12: 3rem;

            /* Bordures */
            --radius-sm: 0.25rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
            --radius-3xl: 2rem;

            /* Ombres */
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --shadow-2xl: 0 25px 50px -12px rgb(0 0 0 / 0.25);

            /* Glassmorphism */
            --glass-bg: rgba(255, 255, 255, 0.25);
            --glass-border: rgba(255, 255, 255, 0.18);
            --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
            --glass-backdrop: blur(40px);

            /* Transitions */
            --transition-normal: 250ms ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-primary);
            overflow: hidden;
            height: 100vh;
        }

        /* === BACKGROUND === */
        .login-background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, 
                var(--primary-100) 0%, 
                var(--accent-50) 25%, 
                var(--primary-50) 50%, 
                var(--accent-100) 75%, 
                var(--primary-100) 100%);
            z-index: -1;
        }

        .login-background::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%2364748b' fill-opacity='0.03'%3E%3Ccircle cx='30' cy='30' r='4'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
            animation: float 20s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) translateX(0px); }
            25% { transform: translateY(-10px) translateX(5px); }
            50% { transform: translateY(0px) translateX(-5px); }
            75% { transform: translateY(5px) translateX(2px); }
        }

        /* === CONTAINER PRINCIPAL === */
        .login-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: var(--space-6);
        }

        .login-card {
            display: grid;
            grid-template-columns: 1fr 1fr;
            width: 100%;
            max-width: 900px;
            min-height: 600px;
            background: var(--glass-bg);
            backdrop-filter: var(--glass-backdrop);
            -webkit-backdrop-filter: var(--glass-backdrop);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-3xl);
            box-shadow: var(--glass-shadow);
            overflow: hidden;
        }

        /* === CÔTÉ GAUCHE GLASSMORPHISM === */
        .login-visual {
            position: relative;
            background: linear-gradient(135deg, 
                rgba(59, 130, 246, 0.8) 0%, 
                rgba(37, 99, 235, 0.9) 25%,
                rgba(29, 78, 216, 0.8) 50%,
                rgba(30, 64, 175, 0.9) 75%,
                rgba(30, 58, 138, 0.8) 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: var(--space-8);
            color: white;
            overflow: hidden;
        }

        .login-visual::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, 
                rgba(255, 255, 255, 0.1) 0%, 
                transparent 50%);
            animation: rotate 30s linear infinite;
        }

        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .login-visual::after {
            content: '';
            position: absolute;
            top: 20%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, 
                rgba(34, 197, 94, 0.3) 0%, 
                transparent 70%);
            border-radius: 50%;
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1) translateY(0px); }
            50% { transform: scale(1.1) translateY(-10px); }
        }

        .logo-section {
            position: relative;
            z-index: 2;
            text-align: center;
            margin-bottom: var(--space-8);
        }

        .logo-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: var(--radius-2xl);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto var(--space-4);
            backdrop-filter: blur(10px);
            transition: all var(--transition-normal);
        }

        .logo-icon:hover {
            transform: scale(1.05);
            background: rgba(255, 255, 255, 0.3);
        }

        .logo-icon img {
            width: 70%;
            height: 70%;
            object-fit: contain;
            filter: brightness(0) invert(1);
        }

        .brand-text {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: var(--space-2);
            letter-spacing: 2px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .brand-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 300;
            text-align: center;
            line-height: 1.5;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
        }

        .decorative-elements {
            position: absolute;
            width: 100%;
            height: 100%;
            pointer-events: none;
        }

        .floating-shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            animation: floatShape 6s ease-in-out infinite;
        }

        .floating-shape:nth-child(1) {
            width: 60px;
            height: 60px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }

        .floating-shape:nth-child(2) {
            width: 40px;
            height: 40px;
            top: 70%;
            left: 80%;
            animation-delay: 2s;
        }

        .floating-shape:nth-child(3) {
            width: 30px;
            height: 30px;
            top: 40%;
            left: 5%;
            animation-delay: 4s;
        }

        @keyframes floatShape {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        /* === CÔTÉ DROIT FORMULAIRE === */
        .login-form-container {
            padding: var(--space-8);
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
        }

        .form-header {
            margin-bottom: var(--space-8);
        }

        .form-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-800);
            margin-bottom: var(--space-2);
        }

        .form-subtitle {
            color: var(--primary-600);
            font-size: 0.95rem;
        }

        /* Style pour le message d'erreur */
        .error-message {
            color: #dc3545;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: var(--radius-md);
            padding: var(--space-3);
            margin-bottom: var(--space-4);
            font-size: 0.9rem;
            text-align: center;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: var(--space-6);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
        }

        .form-label {
            font-weight: 600;
            color: var(--primary-700);
            font-size: 0.9rem;
        }

        .form-input {
            padding: var(--space-4);
            border: 2px solid var(--primary-200);
            border-radius: var(--radius-lg);
            font-size: 1rem;
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

        .password-container {
            position: relative;
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
            font-size: 1.1rem;
            transition: color var(--transition-normal);
        }

        .password-toggle:hover {
            color: var(--accent-600);
        }

        .forgot-password {
            text-align: right;
            margin-top: var(--space-2);
        }

        .forgot-password a {
            color: var(--accent-600);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color var(--transition-normal);
        }

        .forgot-password a:hover {
            color: var(--accent-700);
            text-decoration: underline;
        }

        .login-button {
            padding: var(--space-4) var(--space-6);
            background: linear-gradient(135deg, var(--accent-500), var(--accent-600));
            color: white;
            border: none;
            border-radius: var(--radius-lg);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-normal);
            margin-top: var(--space-4);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            box-shadow: var(--shadow-md);
        }

        .login-button:hover {
            background: linear-gradient(135deg, var(--accent-600), var(--accent-700));
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .signup-link {
            text-align: center;
            margin-top: var(--space-6);
            color: var(--primary-600);
            font-size: 0.9rem;
        }

        .signup-link a {
            color: var(--accent-600);
            text-decoration: none;
            font-weight: 600;
            transition: color var(--transition-normal);
        }

        .signup-link a:hover {
            color: var(--accent-700);
            text-decoration: underline;
        }

        /* === RESPONSIVE === */
        @media (max-width: 768px) {
            .login-card {
                grid-template-columns: 1fr;
                max-width: 400px;
                min-height: auto;
            }

            .login-visual {
                min-height: 200px;
                padding: var(--space-6);
            }

            .brand-text {
                font-size: 2rem;
            }

            .brand-subtitle {
                font-size: 1rem;
            }

            .login-form-container {
                padding: var(--space-6);
            }

            .form-title {
                font-size: 1.75rem;
            }
        }

        /* === ANIMATIONS D'ENTRÉE === */
        .login-card {
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

        .form-group {
            animation: fadeInUp 0.6s ease-out forwards;
            opacity: 0;
        }

        .form-group:nth-child(1) { animation-delay: 0.1s; }
        .form-group:nth-child(2) { animation-delay: 0.2s; }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* === BOUTON DE RETOUR === */
        .back-button {
            position: fixed;
            top: var(--space-6);
            left: var(--space-6);
            background: var(--glass-bg);
            backdrop-filter: var(--glass-backdrop);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-lg);
            padding: var(--space-3) var(--space-4);
            color: var(--primary-700);
            text-decoration: none;
            font-weight: 500;
            transition: all var(--transition-normal);
            box-shadow: var(--shadow-sm);
            z-index: 10;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.4);
            transform: translateX(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Helper message */
        .login-help {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            color: #1565c0;
            padding: var(--space-3);
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            margin-bottom: var(--space-4);
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-background"></div>

    <a href="index.php" class="back-button">
        <i class="fas fa-arrow-left"></i>
        Retour à l'accueil
    </a>

    <div class="login-container">
        <div class="login-card">
            <div class="login-visual">
                <div class="decorative-elements">
                    <div class="floating-shape"></div>
                    <div class="floating-shape"></div>
                    <div class="floating-shape"></div>
                </div>

                <div class="logo-section">
                    <div class="logo-icon">
                        <i class="fas fa-graduation-cap" style="font-size: 2rem; color: white;"></i>
                    </div>
                    <h1 class="brand-text">SYGECOS</h1>
                    <p class="brand-subtitle">
                        Plateforme de Gestion<br>
                        des Soutenances M2<br>
                        UFR Mathématiques et Informatique
                    </p>
                </div>
            </div>

            <div class="login-form-container">
                <div class="form-header">
                    <h2 class="form-title">Connexion</h2>
                    <p class="form-subtitle">Connectez-vous avec votre email ou identifiant SYGECOS</p>
                </div>

                <?php if ($login_error): ?>
                    <div class="error-message">
                        <?php echo htmlspecialchars($login_error); ?>
                    </div>
                <?php endif; ?>

                

                <form class="login-form" action="process_login.php" method="POST">
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

                    <div class="form-group">
                        <label for="password" class="form-label">Mot de passe</label>
                        <div class="password-container">
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-input" 
                                placeholder="Entrez votre mot de passe"
                                required
                            >
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggle-icon"></i>
                            </button>
                        </div>
                        <div class="forgot-password">
                            <a href="/forgot-password">Mot de passe oublié ?</a>
                        </div>
                    </div>

                    <button type="submit" class="login-button">
                        <i class="fas fa-sign-in-alt"></i>
                        Se connecter
                    </button>
                </form>

                <div class="signup-link">
                    Pas encore de compte ? <a href="/register">Créer un compte</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggle-icon');
            
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

        // Animation d'entrée pour les champs du formulaire
        document.addEventListener('DOMContentLoaded', function() {
            const formGroups = document.querySelectorAll('.form-group');
            formGroups.forEach((group, index) => {
                setTimeout(() => {
                    group.style.opacity = '1';
                }, 100 * (index + 1));
            });
        });

        // Auto-focus sur le champ identifiant
        document.getElementById('identifier').focus();
    </script>
</body>
</html>