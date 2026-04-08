<?php
require_once 'config.php';
requireLogin();

$user = getUser($conn);
$uid  = (int) $_SESSION['user_id'];
$init = initials($user['first_name'], $user['last_name']);

if (isset($_GET['mark_notif'])) {
    $notificationId = cleanInt($_GET['mark_notif'] ?? 0, 1);
    if ($notificationId > 0) {
        markNotificationRead($conn, $uid, $notificationId);
    }
    redirectTo('dashboard.php');
}

if (isset($_GET['mark_all_notifs'])) {
    markAllNotificationsRead($conn, $uid);
    redirectTo('dashboard.php');
}

$recentNotifications = getRecentNotifications($conn, $uid, 5);
$notificationCount = getUnreadNotificationCount($conn, $uid);

 
$urgent = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id=$uid AND status='pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];
$pending= $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id=$uid AND status='pending'")->fetch_assoc()['c'];
$done   = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id=$uid AND status='completed'")->fetch_assoc()['c'];

 
$gpaCalc = calculateCurrentGpa($conn, $uid);
$gpaVal = $gpaCalc !== null ? number_format($gpaCalc, 2) : 'N/A';

 
$tasks = $conn->query(
    "SELECT a.*, c.course_code, c.color
     FROM assignments a
     LEFT JOIN courses c ON a.course_id = c.id
     WHERE a.user_id = $uid AND a.status != 'completed'
     ORDER BY a.due_date ASC LIMIT 8"
);

 
$today_events = $conn->query(
    "SELECT e.*, c.course_code
     FROM schedule_events e
     LEFT JOIN courses c ON e.course_id = c.id
     WHERE e.user_id = $uid
       AND DATE(e.start_time) = CURDATE()
     ORDER BY e.start_time ASC"
);

 
$courses = $conn->query("SELECT * FROM courses WHERE user_id = $uid");

 
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — StudySync</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-dashboard">

<aside class="sidebar">
  <a class="sidebar-logo" href="dashboard.php" aria-label="Go to dashboard"><img src="assets/Studysync.png" alt="StudySync logo"></a>
  <div class="sidebar-section">Main</div>
  <a class="sidebar-item active" href="dashboard.php"><span class="icon">🏠</span> Dashboard</a>
  <a class="sidebar-item" href="assignments.php"><span class="icon">📚</span> Assignments <?php if($urgent>0): ?><span class="sidebar-badge"><?=$urgent?></span><?php endif; ?></a>
  <a class="sidebar-item" href="schedule.php"><span class="icon">🗓️</span> Schedule</a>
  <div style="margin-top:1rem"></div>
  <div class="sidebar-section">Collaborate</div>
  <a class="sidebar-item" href="study-groups.php"><span class="icon">👥</span> Study Groups</a>
  <a class="sidebar-item" href="resources.php"><span class="icon">📁</span> Resources</a>
  <div style="margin-top:1rem"></div>
  <div class="sidebar-section">Tools</div>
  <a class="sidebar-item" href="gpa.php"><span class="icon">🎯</span> GPA Calculator</a>
  <a class="sidebar-item" href="profile.php"><span class="icon">👤</span> Profile</a>
  <a class="logout-link" href="logout.php"><span class="icon">🚪</span> Log Out</a>
  <div class="sidebar-footer">
    <div class="user-mini">
      <div class="avatar"><?= $init ?></div>
      <div class="user-info">
        <strong><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></strong>
        <span><?= htmlspecialchars($user['year'] ?? 'Student') ?></span>
      </div>
    </div>
  </div>
</aside>

<main class="main">
  <div class="top-bar">
    <div>
      <h1><?= $greeting ?>, <?= htmlspecialchars($user['first_name']) ?> 👋</h1>
      <span><?= date('l, F j, Y') ?></span>
    </div>
    <a href="assignments.php?new=1" class="btn-primary">+ Add Task</a>
  </div>

  <div class="stat-cards">
    <div class="stat-card">
      <div class="stat-icon">⚠️</div>
      <div class="stat-value" style="color:var(--red)"><?= $urgent ?></div>
      <div class="stat-label">Urgent / Due Soon</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon">📋</div>
      <div class="stat-value" style="color:var(--yellow)"><?= $pending ?></div>
      <div class="stat-label">Pending Assignments</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon">✅</div>
      <div class="stat-value" style="color:var(--green)"><?= $done ?></div>
      <div class="stat-label">Completed</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon">📊</div>
      <div class="stat-value" style="color:var(--accent)"><?= $gpaVal ?></div>
      <div class="stat-label">Current GPA</div>
    </div>
  </div>

  <div class="dash-grid">
    <!-- TASKS -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">Upcoming Tasks</span>
        <a href="assignments.php" class="card-action">View all →</a>
      </div>
      <?php $tasks->data_seek(0); $count=0; while ($t = $tasks->fetch_assoc()): $count++; $urg = urgencyInfo($t['due_date']); ?>
      <div class="task-item">
        <div class="task-dot" style="background:<?= $urg['color'] ?>"></div>
        <div class="task-body">
          <strong><?= htmlspecialchars($t['title']) ?></strong>
          <div class="meta"><?= htmlspecialchars($t['course_code'] ?? 'No course') ?> · Due <?= date('M j', strtotime($t['due_date'])) ?></div>
        </div>
        <span class="task-urgency <?= $urg['class'] ?>"><?= $urg['label'] ?></span>
      </div>
      <?php endwhile; ?>
      <?php if ($count === 0): ?>
        <div class="empty-state">🎉 No upcoming tasks! You're all caught up.</div>
      <?php endif; ?>
    </div>

    <div style="display:flex;flex-direction:column;gap:1.5rem;">
      <!-- TODAY'S SCHEDULE -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">Today's Schedule</span>
          <a href="schedule.php" class="card-action">Full Schedule →</a>
        </div>
        <?php $ev_count=0; while ($ev = $today_events->fetch_assoc()): $ev_count++; ?>
        <div class="sched-item">
          <div class="sched-time"><?= date('g:i A', strtotime($ev['start_time'])) ?></div>
          <div class="sched-bar" style="background:<?= htmlspecialchars($ev['color']) ?>"></div>
          <div class="sched-detail">
            <strong><?= htmlspecialchars($ev['title']) ?></strong>
            <span><?= htmlspecialchars($ev['location'] ?? '') ?> <?= $ev['course_code'] ? '· '.$ev['course_code'] : '' ?></span>
          </div>
        </div>
        <?php endwhile; ?>
        <?php if ($ev_count === 0): ?>
          <div class="empty-state">No events scheduled for today.</div>
        <?php endif; ?>
      </div>

      <!-- QUICK ACCESS -->
      <div class="card">
        <div class="card-header"><span class="card-title">Quick Access</span></div>
        <div class="quick-grid">
          <a class="quick-card" href="study-groups.php"><span class="qicon">👥</span><strong>Study Groups</strong><span>Collaborate</span></a>
          <a class="quick-card" href="resources.php"><span class="qicon">📁</span><strong>Resources</strong><span>Shared files</span></a>
          <a class="quick-card" href="gpa.php"><span class="qicon">🎯</span><strong>GPA Calc</strong><span>Current: <?= $gpaVal ?></span></a>
          <a class="quick-card" href="schedule.php"><span class="qicon">🗓️</span><strong>Timetable</strong><span>Your schedule</span></a>
        </div>
      </div>
    </div>
  </div>

  <!-- COURSE PROGRESS -->
  <?php
  $courses->data_seek(0);
  $courseList = [];
  while ($c = $courses->fetch_assoc()) $courseList[] = $c;
  if (count($courseList) > 0):
  ?>
  <div class="card" style="margin-top:1.5rem">
    <div class="card-header"><span class="card-title">My Courses</span><a href="assignments.php" class="card-action">Assignments →</a></div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;">
      <?php foreach($courseList as $c):
        // count completed vs total for this course
        $tot = $conn->query("SELECT COUNT(*) AS n FROM assignments WHERE course_id={$c['id']} AND user_id=$uid")->fetch_assoc()['n'];
        $com = $conn->query("SELECT COUNT(*) AS n FROM assignments WHERE course_id={$c['id']} AND user_id=$uid AND status='completed'")->fetch_assoc()['n'];
        $pct = $tot > 0 ? round(($com/$tot)*100) : 0;
      ?>
      <div class="progress-item">
        <div class="progress-header">
          <span><?= htmlspecialchars($c['course_code'].' — '.$c['course_name']) ?></span>
          <span><?= $pct ?>%</span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" style="width:<?=$pct?>%;background:<?= htmlspecialchars($c['color']) ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</main>
</body>
</html>
