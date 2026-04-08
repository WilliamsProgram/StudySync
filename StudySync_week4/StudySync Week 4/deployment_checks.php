<?php
function runDeploymentChecks() {
    $localConfigFile = __DIR__ . '/config.local.php';
    if (file_exists($localConfigFile)) {
        require_once $localConfigFile;
    }

    $dbHost = defined('DB_HOST') ? DB_HOST : (getenv('STUDYSYNC_DB_HOST') ?: 'localhost');
    $dbUser = defined('DB_USER') ? DB_USER : (getenv('STUDYSYNC_DB_USER') ?: 'root');
    $dbPass = defined('DB_PASS') ? DB_PASS : (getenv('STUDYSYNC_DB_PASS') ?: '');
    $dbName = defined('DB_NAME') ? DB_NAME : (getenv('STUDYSYNC_DB_NAME') ?: 'studysync_db');

    $checks = [];
    $checks['php'] = [
        'ok' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'value' => PHP_VERSION
    ];
    $checks['mysqli_extension'] = [
        'ok' => class_exists('mysqli'),
        'value' => class_exists('mysqli') ? 'enabled' : 'missing'
    ];
    $checks['uploads_directory'] = [
        'ok' => is_dir(__DIR__ . '/uploads/resources') && is_writable(__DIR__ . '/uploads/resources'),
        'value' => is_dir(__DIR__ . '/uploads/resources') ? 'present' : 'missing'
    ];
    $checks['database_connection'] = [
        'ok' => false,
        'value' => 'not checked'
    ];
    $checks['required_tables'] = [
        'ok' => false,
        'value' => []
    ];

    $requiredTables = ['users','courses','assignments','grades','schedule_events','study_groups','group_members','group_messages','resources','notifications'];

    if ($checks['mysqli_extension']['ok']) {
        $conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if (!$conn->connect_error) {
            $checks['database_connection'] = [
                'ok' => true,
                'value' => 'connected'
            ];

            $missing = [];
            foreach ($requiredTables as $table) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
                $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
                if (!$result || $result->num_rows === 0) {
                    $missing[] = $table;
                }
                if ($result) {
                    $result->free();
                }
            }

            $checks['required_tables'] = [
                'ok' => count($missing) === 0,
                'value' => count($missing) === 0 ? 'all present' : $missing
            ];
            $conn->close();
        } else {
            $checks['database_connection'] = [
                'ok' => false,
                'value' => $conn->connect_error
            ];
        }
    }

    $overall = true;
    foreach ($checks as $check) {
        if (!$check['ok']) {
            $overall = false;
            break;
        }
    }

    return [
        'app' => 'StudySync',
        'environment' => defined('APP_ENV') ? APP_ENV : (getenv('STUDYSYNC_APP_ENV') ?: 'local'),
        'ok' => $overall,
        'checks' => $checks,
        'database' => $dbName,
        'timestamp' => gmdate('c')
    ];
}
