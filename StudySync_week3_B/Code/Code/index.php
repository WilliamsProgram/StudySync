<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    redirectTo('dashboard.php');
}

redirectTo('login.php');
