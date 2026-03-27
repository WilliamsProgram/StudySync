<?php
require_once 'config.php';

if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    redirectTo('login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Logout</title>
<script>
    window.onload = function () {
        var answer = confirm("Are you sure you want to log out?");

        if (answer) {
            window.location.href = "logout.php?confirm=yes";
        } else {
            window.location.href = "dashboard.php";
        }
    };
</script>
</head>
<body>
</body>
</html>
