<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('loginForm.php');
}
?>