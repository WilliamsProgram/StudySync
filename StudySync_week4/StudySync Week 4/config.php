<?php

$sessionSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $sessionSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

$localConfigFile = __DIR__ . '/config.local.php';
if (file_exists($localConfigFile)) {
    require_once $localConfigFile;
}

if (!defined('DB_HOST')) define('DB_HOST', getenv('STUDYSYNC_DB_HOST') ?: 'localhost');
if (!defined('DB_USER')) define('DB_USER', getenv('STUDYSYNC_DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('STUDYSYNC_DB_PASS') ?: '');
if (!defined('DB_NAME')) define('DB_NAME', getenv('STUDYSYNC_DB_NAME') ?: 'studysync_db');
if (!defined('APP_ENV')) define('APP_ENV', getenv('STUDYSYNC_APP_ENV') ?: 'local');

if (!class_exists('mysqli')) {
    die('MySQLi is not enabled in this PHP environment. Enable the mysqli extension in PHP/XAMPP, start MySQL, then import studysync_db.sql into the studysync_db database.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error . ' Import studysync_db.sql from this project into the studysync_db database before running the project.');
}
$conn->set_charset('utf8mb4');

function getTableColumns($conn, $tableName) {
    static $cache = [];

    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $tableName);
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
        $result->free();
    }

    $cache[$tableName] = $columns;
    return $cache[$tableName];
}

function hasTableColumn($conn, $tableName, $columnName) {
    $columns = getTableColumns($conn, $tableName);
    return isset($columns[$columnName]);
}

function redirectTo($location) {
    header('Location: ' . $location);
    exit();
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        redirectTo('login.php');
    }
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrfOrFail() {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid form request. Please refresh the page and try again.');
    }
}


function normalizeWhitespace($value) {
    $value = (string) $value;
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim($value);
}

function cleanText($value, $maxLength = 255) {
    $value = strip_tags((string) $value);
    $value = normalizeWhitespace($value);
    if ($maxLength > 0) {
        $value = mb_substr($value, 0, $maxLength);
    }
    return $value;
}

function cleanMultilineText($value, $maxLength = 2000) {
    $value = strip_tags((string) $value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value);
    $value = preg_replace('/\n{3,}/u', "\n\n", $value);
    $value = trim($value);
    if ($maxLength > 0) {
        $value = mb_substr($value, 0, $maxLength);
    }
    return $value;
}

function cleanEmail($value) {
    return strtolower(trim((string) $value));
}

function cleanNameText($value, $maxLength = 80) {
    $value = cleanText($value, $maxLength);
    return preg_replace('/[^\p{L}\p{N}\s\-\'.]/u', '', $value);
}

function cleanStudentId($value, $maxLength = 30) {
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/[^A-Z0-9\-]/', '', $value);
    return mb_substr($value, 0, $maxLength);
}

function cleanCourseCode($value, $maxLength = 30) {
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/[^A-Z0-9\- ]/', '', $value);
    return mb_substr($value, 0, $maxLength);
}

function cleanEnum($value, array $allowed, $default) {
    $value = strtolower(trim((string) $value));
    return in_array($value, $allowed, true) ? $value : $default;
}

function cleanInt($value, $min = 0, $max = null) {
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === '' || $value === null || !is_numeric($value)) {
        return $min;
    }
    $value = (int) $value;
    if ($value < $min) {
        $value = $min;
    }
    if ($max !== null && $value > $max) {
        $value = $max;
    }
    return $value;
}

function cleanFloat($value, $min = 0, $max = null) {
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === '' || $value === null || !is_numeric($value)) {
        return $min;
    }
    $value = (float) $value;
    if ($value < $min) {
        $value = $min;
    }
    if ($max !== null && $value > $max) {
        $value = $max;
    }
    return $value;
}

function cleanUrl($value) {
    $value = trim((string) $value);
    return $value === '' ? '' : filter_var($value, FILTER_SANITIZE_URL);
}

function cleanSearchTerm($value, $maxLength = 100) {
    $value = cleanText($value, $maxLength);
    return preg_replace('/[%_]+/', ' ', $value);
}

function validEmailAddress($value) {
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function validDateTimeRange($start, $end) {
    return $start !== '' && $end !== '' && strtotime($end) > strtotime($start);
}


function userOwnsCourse($conn, $userId, $courseId) {
    $courseId = (int) $courseId;
    if ($courseId <= 0) {
        return true;
    }
    $stmt = $conn->prepare('SELECT id FROM courses WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $courseId, $userId);
    return fetchSingleRow($stmt) ? true : false;
}

function userIsGroupMember($conn, $userId, $groupId) {
    $groupId = (int) $groupId;
    if ($groupId <= 0) {
        return true;
    }
    $stmt = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $groupId, $userId);
    return fetchSingleRow($stmt) ? true : false;
}

function groupExists($conn, $groupId) {
    $groupId = (int) $groupId;
    if ($groupId <= 0) {
        return false;
    }
    $stmt = $conn->prepare('SELECT id FROM study_groups WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $groupId);
    return fetchSingleRow($stmt) ? true : false;
}

function safeUploadedResourcePath($relativePath) {
    $relativePath = str_replace('\\', '/', (string) $relativePath);
    $relativePath = ltrim($relativePath, '/');
    $base = realpath(__DIR__ . '/uploads/resources');
    if ($base === false) {
        return null;
    }
    $fullPath = realpath(__DIR__ . '/' . $relativePath);
    if ($fullPath === false) {
        return null;
    }
    return strpos($fullPath, $base) === 0 ? $fullPath : null;
}

function fetchSingleRow($stmt) {
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function fetchAllRows($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function fetchScalar($stmt, $key = null, $default = 0) {
    $row = fetchSingleRow($stmt);
    if (!$row) {
        return $default;
    }
    if ($key === null) {
        return reset($row);
    }
    return $row[$key] ?? $default;
}

function normalizeDateTimeInput($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function normalizeHexColor($value, $default = '#4f8ef7') {
    $value = trim((string) $value);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return strtolower($value);
    }
    return $default;
}

function ensureDirectory($path) {
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    return is_dir($path) && is_writable($path);
}

function protectUploadDirectory($path) {
    $htaccess = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        $rules = 'Options -Indexes' . "
"
            . '<FilesMatch "\.(php|phtml|php3|php4|php5|phar|cgi|pl|asp|aspx|jsp|sh)$">' . "
"
            . 'Require all denied' . "
"
            . '</FilesMatch>' . "
";
        @file_put_contents($htaccess, $rules);
    }
}

function getUser($conn) {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $id = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        redirectTo('login.php');
    }

    return $user;
}

function initials($first, $last) {
    return strtoupper(substr((string) $first, 0, 1) . substr((string) $last, 0, 1));
}

function urgencyInfo($due_date) {
    $diff = (strtotime($due_date) - time()) / 86400;
    if ($diff < 0) {
        return ['label' => 'Overdue', 'class' => 'urg-red', 'color' => 'var(--red)'];
    }
    if ($diff <= 1) {
        return ['label' => 'Due Today', 'class' => 'urg-red', 'color' => 'var(--red)'];
    }
    if ($diff <= 3) {
        return ['label' => 'Urgent', 'class' => 'urg-red', 'color' => 'var(--red)'];
    }
    if ($diff <= 7) {
        return ['label' => round($diff) . ' days left', 'class' => 'urg-yellow', 'color' => 'var(--yellow)'];
    }

    return ['label' => 'On Track', 'class' => 'urg-green', 'color' => 'var(--green)'];
}

function gradeToLetter($score) {
    $score = (float) $score;

    if ($score >= 90) return ['A',  4.0, 'rgba(56,217,169,0.2)', '#38d9a9'];
    if ($score >= 87) return ['A-', 3.7, 'rgba(56,217,169,0.2)', '#38d9a9'];
    if ($score >= 84) return ['B+', 3.3, 'rgba(56,217,169,0.15)', '#38d9a9'];
    if ($score >= 80) return ['B',  3.0, 'rgba(56,217,169,0.15)', '#38d9a9'];
    if ($score >= 77) return ['B-', 2.7, 'rgba(79,142,247,0.2)', '#4f8ef7'];
    if ($score >= 74) return ['C+', 2.3, 'rgba(79,142,247,0.15)', '#4f8ef7'];
    if ($score >= 70) return ['C',  2.0, 'rgba(254,188,46,0.15)', '#febc2e'];
    if ($score >= 60) return ['D',  1.0, 'rgba(247,122,79,0.15)', '#f77a4f'];
    return ['F', 0.0, 'rgba(247,122,79,0.2)', '#f77a4f'];
}

function calculateCurrentGpa($conn, $userId, $semester = null) {
    $sql = 'SELECT score, weight FROM grades WHERE user_id = ?';
    $types = 'i';
    $params = [$userId];

    if ($semester !== null && $semester !== '') {
        $sql .= ' AND semester = ?';
        $types .= 's';
        $params[] = $semester;
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $totalPts = 0;
    $totalW = 0;
    while ($row = $res->fetch_assoc()) {
        if ((float) $row['weight'] > 0) {
            $scale = gradeToLetter($row['score']);
            $totalPts += ((float) $scale[1]) * ((float) $row['weight']);
            $totalW += (float) $row['weight'];
        }
    }
    $stmt->close();

    return $totalW > 0 ? round($totalPts / $totalW, 2) : null;
}

function syncAssignmentStatuses($conn, $userId) {
    $stmt = $conn->prepare("UPDATE assignments SET status = 'overdue' WHERE user_id = ? AND status = 'pending' AND due_date < NOW()");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function createNotification($conn, $userId, $message, $type, $notificationKey, $relatedType = null, $relatedId = null) {
    $hasNotificationKey = hasTableColumn($conn, 'notifications', 'notification_key');
    $hasRelatedType = hasTableColumn($conn, 'notifications', 'related_item_type');
    $hasRelatedId = hasTableColumn($conn, 'notifications', 'related_item_id');

    if ($hasNotificationKey && $hasRelatedType && $hasRelatedId) {
        $stmt = $conn->prepare(
            'INSERT INTO notifications (user_id, message, is_read, type, notification_key, related_item_type, related_item_id, created_at)
             VALUES (?, ?, 0, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE message = VALUES(message), type = VALUES(type)'
        );
        $stmt->bind_param('issssi', $userId, $message, $type, $notificationKey, $relatedType, $relatedId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $check = $conn->prepare('SELECT id FROM notifications WHERE user_id = ? AND type = ? AND message = ? LIMIT 1');
    $check->bind_param('iss', $userId, $type, $message);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if ($exists) {
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO notifications (user_id, message, is_read, type, created_at)
         VALUES (?, ?, 0, ?, NOW())'
    );
    $stmt->bind_param('iss', $userId, $message, $type);
    $stmt->execute();
    $stmt->close();
}

function syncDeadlineNotifications($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT a.id, a.title, a.due_date, a.status, c.course_code
         FROM assignments a
         LEFT JOIN courses c ON a.course_id = c.id
         WHERE a.user_id = ?
           AND a.status IN ('pending', 'overdue')
           AND a.due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)
         ORDER BY a.due_date ASC"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $course = $row['course_code'] ? ' (' . $row['course_code'] . ')' : '';
        $formatted = date('M j, Y g:i A', strtotime($row['due_date']));

        if ($row['status'] === 'overdue') {
            $message = $row['title'] . $course . ' is overdue. It was due ' . $formatted . '.';
            createNotification($conn, $userId, $message, 'deadline', 'deadline_overdue_' . $row['id'], 'assignment', (int) $row['id']);
        } else {
            $message = $row['title'] . $course . ' is due by ' . $formatted . '.';
            createNotification($conn, $userId, $message, 'deadline', 'deadline_3day_' . $row['id'], 'assignment', (int) $row['id']);
        }
    }

    $stmt->close();
}

function getUnreadNotificationCount($conn, $userId) {
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0);
}

function getRecentNotifications($conn, $userId, $limit = 5) {
    $limit = (int) $limit;
    if ($limit < 1) {
        $limit = 5;
    }

    $sql = 'SELECT * FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC LIMIT ' . $limit;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    return $items;
}

function markNotificationRead($conn, $userId, $notificationId) {
    $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $notificationId, $userId);
    $stmt->execute();
    $stmt->close();
}

function markAllNotificationsRead($conn, $userId) {
    $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function hasScheduleConflict($conn, $userId, $startTime, $endTime) {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM schedule_events
         WHERE user_id = ?
           AND NOT (end_time <= ? OR start_time >= ?)'
    );
    $stmt->bind_param('iss', $userId, $startTime, $endTime);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int) ($row['total'] ?? 0)) > 0;
}

function createStudyEvent($conn, $userId, $assignmentId, $courseId, $title, $startTime, $endTime, $color) {
    $eventTitle = 'Study: ' . $title;
    $location = 'Auto-generated study block';
    $eventType = 'study';

    if (hasTableColumn($conn, 'schedule_events', 'is_generated') && hasTableColumn($conn, 'schedule_events', 'generated_from_assignment_id')) {
        $generated = 1;
        $stmt = $conn->prepare(
            'INSERT INTO schedule_events
             (user_id, title, course_id, location, event_type, start_time, end_time, color, is_generated, generated_from_assignment_id)
             VALUES (?, ?, NULLIF(?,0), ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isisssssii', $userId, $eventTitle, $courseId, $location, $eventType, $startTime, $endTime, $color, $generated, $assignmentId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO schedule_events
         (user_id, title, course_id, location, event_type, start_time, end_time, color)
         VALUES (?, ?, NULLIF(?,0), ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isisssss', $userId, $eventTitle, $courseId, $location, $eventType, $startTime, $endTime, $color);
    $stmt->execute();
    $stmt->close();
}

function getCandidateSlotsForDate($dateString) {
    $dayNumber = (int) date('N', strtotime($dateString));

    if ($dayNumber >= 6) {
        return [
            ['10:00:00', '11:30:00'],
            ['13:00:00', '14:30:00'],
            ['16:00:00', '17:30:00']
        ];
    }

    return [
        ['16:00:00', '17:30:00'],
        ['18:00:00', '19:30:00'],
        ['19:45:00', '21:15:00']
    ];
}

function buildStudyDates($dueDate) {
    $dates = [];
    $today = new DateTime(date('Y-m-d'));
    $end = new DateTime(date('Y-m-d', strtotime($dueDate)));
    $end->modify('-1 day');

    if ($end < $today) {
        return $dates;
    }

    $cursor = clone $today;
    while ($cursor <= $end) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }

    usort($dates, function ($a, $b) use ($dueDate) {
        $diffA = abs(strtotime($dueDate) - strtotime($a . ' 00:00:00'));
        $diffB = abs(strtotime($dueDate) - strtotime($b . ' 00:00:00'));
        return $diffA <=> $diffB;
    });

    return $dates;
}

function determineStudyBlockCount($priority, $gradeWeight, $daysLeft) {
    $blocks = 1;

    if ($priority === 'high' || $gradeWeight >= 15) {
        $blocks = 3;
    } elseif ($priority === 'medium' || $gradeWeight >= 8) {
        $blocks = 2;
    }

    if ($daysLeft <= 2 && $blocks < 2) {
        $blocks = 2;
    }

    if ($daysLeft >= 8 && $blocks < 3 && ($priority === 'high' || $gradeWeight >= 20)) {
        $blocks = 3;
    }

    return $blocks;
}

function createSmartTimetable($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT a.id, a.course_id, a.title, a.due_date, a.priority, a.grade_weight,
                COALESCE(c.color, '#38d9a9') AS color
         FROM assignments a
         LEFT JOIN courses c ON a.course_id = c.id
         WHERE a.user_id = ?
           AND a.status = 'pending'
           AND a.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 10 DAY)
         ORDER BY a.due_date ASC"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    $created = 0;

    while ($assignment = $res->fetch_assoc()) {
        $daysLeft = (int) floor((strtotime($assignment['due_date']) - time()) / 86400);
        $needed = determineStudyBlockCount($assignment['priority'], (float) $assignment['grade_weight'], $daysLeft);

        if (hasTableColumn($conn, 'schedule_events', 'is_generated') && hasTableColumn($conn, 'schedule_events', 'generated_from_assignment_id')) {
            $countStmt = $conn->prepare(
                'SELECT COUNT(*) AS total FROM schedule_events WHERE user_id = ? AND is_generated = 1 AND generated_from_assignment_id = ?'
            );
            $countStmt->bind_param('ii', $userId, $assignment['id']);
        } else {
            $autoTitle = 'Study: ' . $assignment['title'];
            $countStmt = $conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM schedule_events
                 WHERE user_id = ?
                   AND event_type = ?
                   AND title = ?
                   AND start_time < ?'
            );
            $eventType = 'study';
            $dueDate = $assignment['due_date'];
            $countStmt->bind_param('isss', $userId, $eventType, $autoTitle, $dueDate);
        }
        $countStmt->execute();
        $existing = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();

        $missing = $needed - (int) ($existing['total'] ?? 0);
        if ($missing <= 0) {
            continue;
        }

        $candidateDates = buildStudyDates($assignment['due_date']);
        foreach ($candidateDates as $dateString) {
            if ($missing <= 0) {
                break;
            }

            $slots = getCandidateSlotsForDate($dateString);
            foreach ($slots as $slot) {
                if ($missing <= 0) {
                    break;
                }

                $startTime = $dateString . ' ' . $slot[0];
                $endTime = $dateString . ' ' . $slot[1];

                if (strtotime($endTime) >= strtotime($assignment['due_date'])) {
                    continue;
                }

                if (!hasScheduleConflict($conn, $userId, $startTime, $endTime)) {
                    createStudyEvent(
                        $conn,
                        $userId,
                        (int) $assignment['id'],
                        (int) $assignment['course_id'],
                        $assignment['title'],
                        $startTime,
                        $endTime,
                        $assignment['color']
                    );
                    $missing--;
                    $created++;
                }
            }
        }

        if ($created > 0) {
            createNotification(
                $conn,
                $userId,
                'Smart timetable updated for ' . $assignment['title'] . '.',
                'system',
                'studyplan_' . $assignment['id'],
                'assignment',
                (int) $assignment['id']
            );
        }
    }

    $stmt->close();
    return $created;
}

if (isset($_SESSION['user_id'])) {
    $bootUserId = (int) $_SESSION['user_id'];
    syncAssignmentStatuses($conn, $bootUserId);
    syncDeadlineNotifications($conn, $bootUserId);
}
?>
