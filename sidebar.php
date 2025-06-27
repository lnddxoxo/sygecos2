<?php
// sidebar.php
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M12 14l9-5-9-5-9 5 9 5z'/%3E%3Cpath d='M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z'/%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z'/%3E%3C/svg%3E" alt="SYGECOS">
        </div>
        <span class="sidebar-title">SYGECOS</span>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Navigation</div>
            <div class="nav-item">
                <a href="main.php" class="nav-link active">
                    <div class="nav-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <span class="nav-text">Tableau de bord</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Paramètres généraux</div>
            <div class="nav-item">
                <a href="gestion_type_util.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-user-tag"></i>
                    </div>
                    <span class="nav-text">Types utilisateur</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="gestion_groupes.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <span class="nav-text">Groupes</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="gestion_utilisateur.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <span class="nav-text">Utilisateurs</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="gestion_traitements.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-cogs"></i>
                    </div>
                    <span class="nav-text">Traitements</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Gestion académique</div>
            <div class="nav-item">
                <a href="gestion_annee_academique.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <span class="nav-text">Année académique</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="gestion_ue.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <span class="nav-text">UE</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="gestion_ecue.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-book-open"></i>
                    </div>
                    <span class="nav-text">ECUE</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Gestion Enseignant</div>
            <div class="nav-item">
                <a href="gestion_dossier_enseignant.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-address-card"></i>
                    </div>
                    <span class="nav-text">Dossier de l'enseignant</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="gestion_fonctions_enseignants.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-briefcase"></i>
                    </div>
                    <span class="nav-text">Fonctions</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="gestion_grade_enseignant.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <span class="nav-text">Grades</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Gestion Personnel Administratif</div>
            <div class="nav-item">
                <a href="gestion_personnel_admin.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <span class="nav-text">Dossier du personnel administratif</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Commission de Validation</div>
            <div class="nav-item">
                <a href="gestion_rapports.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <span class="nav-text">Rapports</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="gestion_decisions.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <span class="nav-text">Décisions</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="gestion_attributions.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-share-alt"></i>
                    </div>
                    <span class="nav-text">Attributions</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="gestion_compte_rendu.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <span class="nav-text">Compte rendu</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Configurations Système</div>
            <div class="nav-item">
                <a href="config_etats.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <span class="nav-text">Configuration des états</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="config_impressions.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-print"></i>
                    </div>
                    <span class="nav-text">Configuration des impressions</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Piste d'audit</div>
            <div class="nav-item">
                <a href="audit_tracabilite.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <span class="nav-text">Traçabilité</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="audit_historique.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-scroll"></i>
                    </div>
                    <span class="nav-text">Historique</span>
                </a>
            </div>
        </div>
    </nav>
</aside>