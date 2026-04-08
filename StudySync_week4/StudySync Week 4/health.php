<?php
require_once __DIR__ . '/deployment_checks.php';
header('Content-Type: application/json; charset=utf-8');
$health = runDeploymentChecks();
http_response_code(!empty($health['ok']) ? 200 : 503);
echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
