<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYGECOS - Paramètres Secrétariat</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .settings-card {
            border-left: 4px solid #3b82f6;
        }
        .nav-pills .nav-link.active {
            background-color: #3b82f6;
        }
    </style>
</head>
<body>
    <?php include 'sidebar_secretaire.php'; ?>
    
    <main class="main-content" id="mainContent">
        <?php include 'topbar.php'; ?>

        <div class="page-content">
            <div class="container-fluid py-4">
                <h2 class="page-title-main mb-4">Paramètres du secrétariat</h2>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <ul class="nav nav-pills flex-column">
                                    <li class="nav-item">
                                        <a class="nav-link active" data-bs-toggle="pill" href="#compte">
                                            <i class="fas fa-user-cog me-2"></i>Compte
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="pill" href="#securite">
                                            <i class="fas fa-shield-alt me-2"></i>Sécurité
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="pill" href="#notifications">
                                            <i class="fas fa-bell me-2"></i>Notifications
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="pill" href="#signature">
                                            <i class="fas fa-pen-fancy me-2"></i>Signature
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-9">
                        <div class="tab-content">
                            <!-- Onglet Compte -->
                            <div class="tab-pane fade show active" id="compte">
                                <div class="card settings-card">
                                    <div class="card-header">
                                        <h5 class="mb-0">Paramètres du compte</h5>
                                    </div>
                                    <div class="card-body">
                                        <form>
                                            <div class="mb-3">
                                                <label class="form-label">Langue</label>
                                                <select class="form-select">
                                                    <option>Français</option>
                                                    <option>Anglais</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Fuseau horaire</label>
                                                <select class="form-select">
                                                    <option>Europe/Paris (UTC+1)</option>
                                                    <option>UTC</option>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Format de date</label>
                                                <select class="form-select">
                                                    <option>JJ/MM/AAAA</option>
                                                    <option>AAAA-MM-JJ</option>
                                                    <option>MM/JJ/AAAA</option>
                                                </select>
                                            </div>
                                            
                                            <div class="text-end">
                                                <button type="button" class="btn btn-primary">
                                                    <i class="fas fa-save me-2"></i>Enregistrer
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Onglet Sécurité -->
                            <div class="tab-pane fade" id="securite">
                                <div class="card settings-card">
                                    <div class="card-header">
                                        <h5 class="mb-0">Sécurité</h5>
                                    </div>
                                    <div class="card-body">
                                        <form>
                                            <div class="mb-3">
                                                <label class="form-label">Mot de passe actuel</label>
                                                <input type="password" class="form-control">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Nouveau mot de passe</label>
                                                <input type="password" class="form-control">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Confirmer le nouveau mot de passe</label>
                                                <input type="password" class="form-control">
                                            </div>
                                            
                                            <div class="text-end">
                                                <button type="button" class="btn btn-primary">
                                                    <i class="fas fa-save me-2"></i>Mettre à jour
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Onglet Notifications -->
                            <div class="tab-pane fade" id="notifications">
                                <div class="card settings-card">
                                    <div class="card-header">
                                        <h5 class="mb-0">Préférences de notification</h5>
                                    </div>
                                    <div class="card-body">
                                        <form>
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="emailNotif" checked>
                                                    <label class="form-check-label" for="emailNotif">Notifications par email</label>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="systemNotif" checked>
                                                    <label class="form-check-label" for="systemNotif">Notifications système</label>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Fréquence des rappels</label>
                                                <select class="form-select">
                                                    <option>Immédiat</option>
                                                    <option>Quotidien</option>
                                                    <option>Hebdomadaire</option>
                                                </select>
                                            </div>
                                            
                                            <div class="text-end">
                                                <button type="button" class="btn btn-primary">
                                                    <i class="fas fa-save me-2"></i>Enregistrer
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Onglet Signature -->
                            <div class="tab-pane fade" id="signature">
                                <div class="card settings-card">
                                    <div class="card-header">
                                        <h5 class="mb-0">Signature électronique</h5>
                                    </div>
                                    <div class="card-body">
                                        <form>
                                            <div class="mb-3">
                                                <label class="form-label">Signature pour les emails</label>
                                                <textarea class="form-control" rows="4">Cordialement,
Marie Dubois
Secrétaire administrative
SYGECOS - Université XYZ
Tél: +33 6 12 34 56 78</textarea>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Image de signature (optionnel)</label>
                                                <input type="file" class="form-control">
                                                <small class="text-muted">Format recommandé : PNG transparent (200x50px)</small>
                                            </div>
                                            
                                            <div class="text-end">
                                                <button type="button" class="btn btn-primary">
                                                    <i class="fas fa-save me-2"></i>Enregistrer
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>