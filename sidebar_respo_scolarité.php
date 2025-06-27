<?php
// sidebar_responsable_scolarite.php
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
                <a href="dashboard_responsable.php" class="nav-link active">
                    <div class="nav-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <span class="nav-text">Tableau de bord</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Gestion Personnelle</div>
            <div class="nav-item">
                <a href="informations_personnelles.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <span class="nav-text">Informations personnelles</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Gestion Étudiants</div>
            <div class="nav-item">
                <a href="inscription_etudiant.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <span class="nav-text">Inscription étudiant</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="liste_etudiants.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <span class="nav-text">Liste des étudiants</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Scolarité</div>
            <div class="nav-item">
                <a href="reglement_scolarite.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <span class="nav-text">Règlement scolarité</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="historique_paiements.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <span class="nav-text">Historique des paiements</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Évaluations</div>
            <div class="nav-item">
                <a href="gestion_evaluations.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <span class="nav-text">Gestion des évaluations</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="resultats_evaluations.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <span class="nav-text">Résultats des évaluations</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Éligibilité</div>
            <div class="nav-item">
                <a href="verification_eligibilite.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <span class="nav-text">Vérification éligibilité</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="liste_eligibles.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-list-ol"></i>
                    </div>
                    <span class="nav-text">Liste des éligibles</span>
                </a>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Rapports</div>
            <div class="nav-item">
                <a href="rapports_scolarite.php" class="nav-link">
                    <div class="nav-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <span class="nav-text">Rapports de stage</span>
                </a>
            </div>
        </div>
    </nav>
</aside>