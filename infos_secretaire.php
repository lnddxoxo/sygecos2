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
    <title>SYGECOS - Informations personnelles</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .profile-card {
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .profile-pic {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border: 5px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php include 'sidebar_secretaire.php'; ?>
    
    <main class="main-content" id="mainContent">
        <?php include 'topbar.php'; ?>

        <div class="page-content">
            <div class="container-fluid py-4">
                <h2 class="page-title-main mb-4">Informations personnelles</h2>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="card profile-card text-center p-4">
                            <div class="card-body">
                                <img src="assets/images/profile-secretaire.jpg" class="profile-pic rounded-circle mb-3" alt="Photo de profil">
                                <h4>Marie Dubois</h4>
                                <p class="text-muted">Secrétaire administrative</p>
                                <button class="btn btn-outline-primary btn-sm mt-2">
                                    <i class="fas fa-camera me-2"></i>Changer photo
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Détails du compte</h5>
                            </div>
                            <div class="card-body">
                                <form>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Nom</label>
                                            <input type="text" class="form-control" value="Dubois">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Prénom</label>
                                            <input type="text" class="form-control" value="Marie">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" value="m.dubois@syegecos.edu">
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Téléphone</label>
                                            <input type="tel" class="form-control" value="+33 6 12 34 56 78">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Date d'embauche</label>
                                            <input type="text" class="form-control" value="15/03/2018" disabled>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Adresse</label>
                                        <textarea class="form-control" rows="2">12 Rue des Universités, 75005 Paris</textarea>
                                    </div>
                                    
                                    <div class="text-end">
                                        <button type="button" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Enregistrer les modifications
                                        </button>
                                    </div>
                                </form>
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