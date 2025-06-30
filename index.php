<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Plateforme de Gestion des Soutenances </title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="index.css" rel="stylesheet">
</head>
<body>
    <div class="page-background"></div>

    <nav class="navbar">
        <div class="nav-container">
            <a href="#" class="logo">
                <div class="logo-icon">
                    <img src="WhatsApp Image 2025-05-15 à 00.54.47_42b83ab0.jpg" alt="SYGECOS">
                </div>
                <span>SYGECOS</span>
            </a>
            
            <ul class="nav-menu">
                <li><a href="#accueil" class="nav-link">Accueil</a></li>
                <li><a href="#universite" class="nav-link">Université</a></li>
                <li><a href="#processus" class="nav-link">Processus</a></li>
                <li><a href="#contact" class="nav-link">Contact</a></li>
            </ul>

            <a href="loginForm.php" class="login-btn"> 
                <i class="fas fa-sign-in-alt"></i>
                Se connecter
            </a>
        </div>
    </nav>

    <section class="hero-slider" id="accueil">
        <div class="slide active" style="background-image: url('images.jpeg')">
            <div class="slide-content">
                <h1>Bienvenue à SYGECOS</h1>
                <p>Système de Gestion des Soutenances M2 - UFR Mathématiques et Informatique</p>
                <div class="hero-cta">
                    <a href="loginForm.php" class="btn-primary-hero"> 
                        <i class="fas fa-sign-in-alt"></i>
                        Accéder à la plateforme
                    </a>
                    <a href="#processus" class="btn-secondary-hero">
                        <i class="fas fa-info-circle"></i>
                        Découvrir le processus
                    </a>
                </div>
            </div>
        </div>

        <div class="slide" style="background-image: url('unnamed.webp')">
            <div class="slide-content">
                <h1>Excellence Académique</h1>
                <p>Une tradition d'excellence dans la formation des futurs cadres</p>
                <div class="hero-cta">
                    <a href="loginForm.php" class="btn-primary-hero"> 
                        <i class="fas fa-graduation-cap"></i>
                        Rejoindre SYGECOS
                    </a>
                </div>
            </div>
        </div>

        <div class="slide" style="background-image: url('images (1).jpeg')">
            <div class="slide-content">
                <h1>Campus Moderne</h1>
                <p>Des infrastructures de pointe pour une formation d'excellence</p>
                <div class="hero-cta">
                    <a href="#universite" class="btn-secondary-hero">
                        <i class="fas fa-university"></i>
                        Découvrir l'université
                    </a>
                </div>
            </div>
        </div>

        <div class="slider-pagination">
            <div class="pagination-thumb active" data-slide="0" style="background-image: url('images.jpeg')"></div>
            <div class="pagination-thumb" data-slide="1" style="background-image: url('unnamed.webp')"></div>
            <div class="pagination-thumb" data-slide="2" style="background-image: url('images (1).jpeg')"></div>
        </div>
    </section>

    <section class="university-section" id="universite">
        <div class="university-container">
            <h2 class="section-title university-title-centered scroll-reveal">UNIVERSITE</h2>
            
            <div class="university-feature reversed-layout">
                <div class="university-image-wrapper scroll-reveal">
                    <img src="jj.jpeg" alt="Campus de l'UFHB" class="university-feature-img">
                </div>
                <div class="university-text-content scroll-reveal">
                    <h3>Une institution d'excellence</h3>
                    <p>
                        L'Université Félix Houphouët-Boigny (UFHB), située à Abidjan, Côte d'Ivoire, est un pilier de l'enseignement supérieur en Afrique de l'Ouest. Fondée en 1964, elle s'est imposée comme un centre de formation et de recherche de premier plan, attirant des étudiants et des chercheurs de toute la sous-région. L'UFHB offre un large éventail de disciplines, des sciences humaines et sociales aux sciences exactes et techniques.
                    </p>
                    <p>
                        Son engagement envers la qualité académique et l'innovation est au cœur de sa mission, formant des générations de professionnels et de leaders qui contribuent au développement de la Côte d'Ivoire et au-delà.
                    </p>
                </div>
            </div>

            <div class="university-feature">
                <div class="university-image-wrapper scroll-reveal">
                    <img src="qq.jpeg" alt="UFR Mathématiques et Informatique" class="university-feature-img">
                </div>
                <div class="university-text-content scroll-reveal">
                    <h3>L'UFR Mathématiques et Informatique (UFR MI)</h3>
                    <p>
                        Au sein de l'UFHB, l'UFR Mathématiques et Informatique (UFR MI) est un département dynamique et à la pointe de la technologie. Elle est dédiée à la formation de spécialistes en mathématiques appliquées, en informatique, en statistiques et en modélisation. Les programmes sont conçus pour répondre aux exigences du monde professionnel et aux avancées scientifiques.
                    </p>
                    <p>
                        L'UFR MI dispose d'infrastructures modernes, de laboratoires équipés et d'un corps professoral expérimenté, garantissant un environnement d'apprentissage stimulant et propice à la réussite. Elle est un acteur clé dans la promotion de l'innovation numérique et de la recherche scientifique en Côte d'Ivoire.
                    </p>
                </div>
            </div>

        </div>
    </section>

    <section class="process" id="processus">
        <div class="process-container">
            <h2 class="section-title scroll-reveal">Processus de Gestion</h2>
            <p class="section-subtitle scroll-reveal">Un workflow complet et automatisé pour accompagner chaque étudiant de Master 2</p>

            <div class="process-grid">
                <div class="process-card scroll-reveal">
                    <div class="process-icon">
                        <i class="fas fa-upload"></i>
                    </div>
                    <h3>Dépôt</h3>
                    <p>Dépôt du rapport de stage sur la plateforme</p>
                    <div class="process-steps-mini">
                        <div class="mini-step">
                            <div class="mini-step-icon"><i class="fas fa-check"></i></div>
                            <span>Stage validé</span>
                        </div>
                        <div class="mini-step">
                            <div class="mini-step-icon"><i class="fas fa-check"></i></div>
                            <span>Scolarité à jour</span>
                        </div>
                    </div>
                </div>

                <div class="process-card scroll-reveal">
                    <div class="process-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3>Validation</h3>
                    <p>Double vérification administrative</p>
                    <div class="process-steps-mini">
                        <div class="mini-step">
                            <div class="mini-step-icon">N1</div>
                            <span>Contrôle initial</span>
                        </div>
                        <div class="mini-step">
                            <div class="mini-step-icon">N2</div>
                            <span>Validation finale</span>
                        </div>
                    </div>
                </div>

                <div class="process-card scroll-reveal">
                    <div class="process-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3>Commission</h3>
                    <p>Évaluation par commission d'experts</p>
                    <div class="process-steps-mini">
                        <div class="mini-step">
                            <div class="mini-step-icon">3</div>
                            <span>Enseignants</span>
                        </div>
                        <div class="mini-step">
                            <div class="mini-step-icon">1</div>
                            <span>Responsable</span>
                        </div>
                    </div>
                </div>

                <div class="process-card scroll-reveal">
                    <div class="process-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <h3>Finalisation</h3>
                    <p>Attribution des encadrants et notification</p>
                    <div class="process-steps-mini">
                        <div class="mini-step">
                            <div class="mini-step-icon"><i class="fas fa-edit"></i></div>
                            <span>Compte-rendu</span>
                        </div>
                        <div class="mini-step">
                            <div class="mini-step-icon"><i class="fas fa-bell"></i></div>
                            <span>Notification</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="final-image-section">
        <div class="final-image-container">
            <div class="final-image scroll-reveal" style="background-image: url('téléchargement.jpeg')">
                <div class="final-image-content">
                    <h2>SYGECOS</h2>
                    <p>Votre plateforme de gestion des soutenances M2</p>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>SYGECOS - UFHB UFR MI</h3>
                    <p>Université Félix Houphouët-Boigny<br>
                    UFR Mathématiques et Informatique<br>
                    Abidjan, Côte d'Ivoire</p>
                </div>
                
                <div class="footer-section">
                    <h3>Plateforme</h3>
                    <p><a href="/login">Connexion</a><br>
                    <a href="/help">Documentation</a><br>
                    <a href="/support">Support technique</a></p>
                </div>
                
                <div class="footer-section">
                    <h3>Contact</h3>
                    <p><a href="mailto:ufr-mi@ufhb.edu.ci">ufr-mi@ufhb.edu.ci</a><br>
                    <a href="tel:+2252522000000">+225 25 22 00 00 00</a><br>
                    Cocody, Abidjan</p>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; 2025 SYGECOS - UFHB UFR Mathématiques et Informatique. Tous droits réservés.</p>
            </div>
        </div>
    </footer>

    <script src="index.js"></script>
</body>
</html>