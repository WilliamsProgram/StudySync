<?php
require_once 'config.php';
requireLogin();

$user = getUser($conn);
$uid  = $_SESSION['user_id'];
$init = initials($user['first_name'], $user['last_name']);

if (isset($_GET['mark_notif'])) {
    markNotificationRead($conn, $uid, intval($_GET['mark_notif']));
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
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<style>
  :root{--bg:#0b0f1a;--surface:#111827;--surface2:#1a2235;--surface3:#202c40;--accent:#4f8ef7;--accent2:#38d9a9;--accent3:#f7934f;--text:#e8edf7;--muted:#7a8ba8;--border:rgba(79,142,247,0.15);--glow:rgba(79,142,247,0.2);--red:#f77a4f;--yellow:#febc2e;--green:#38d9a9;}
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;}
  .sidebar{width:240px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;padding:1.5rem 0;position:fixed;top:0;left:0;bottom:0;z-index:50;}
  .sidebar-logo{font-family:'Syne',sans-serif;font-weight:800;font-size:1.4rem;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;padding:0 1.5rem;margin-bottom:2.5rem;}
  .sidebar-section{font-size:0.68rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:var(--muted);padding:0 1.5rem;margin-bottom:0.6rem;}
  .sidebar-item{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1.5rem;text-decoration:none;color:var(--muted);font-size:0.9rem;font-weight:500;border-left:3px solid transparent;transition:all .2s;}
  .sidebar-item:hover{color:var(--text);background:var(--surface2);}
  .sidebar-item.active{color:var(--accent);background:rgba(79,142,247,0.08);border-left-color:var(--accent);}
  .sidebar-item .icon{font-size:1.1rem;width:22px;text-align:center;}
  .sidebar-badge{margin-left:auto;background:var(--red);color:#fff;font-size:0.68rem;padding:0.15rem 0.45rem;border-radius:20px;font-weight:700;}
  .sidebar-footer{margin-top:auto;padding:1.5rem;border-top:1px solid var(--border);}
  .user-mini{display:flex;align-items:center;gap:0.75rem;}
  .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;flex-shrink:0;}
  .user-info strong{font-size:0.875rem;display:block;}.user-info span{font-size:0.75rem;color:var(--muted);}
  .main{margin-left:240px;flex:1;padding:2rem 2.5rem;overflow-y:auto;}
  .top-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;}
  .top-bar h1{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;}
  .top-bar span{color:var(--muted);font-size:0.9rem;}
  .btn-primary{padding:0.5rem 1.1rem;border-radius:8px;font-size:0.85rem;font-weight:600;cursor:pointer;transition:all .2s;border:none;background:linear-gradient(135deg,var(--accent),#3a6fd4);color:#fff;box-shadow:0 0 16px var(--glow);text-decoration:none;}
  .btn-primary:hover{transform:translateY(-1px);}
  .stat-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:2rem;}
  .stat-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.4rem;position:relative;overflow:hidden;transition:all .2s;}
  .stat-card:hover{border-color:rgba(79,142,247,0.3);transform:translateY(-2px);}
  .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;}
  .stat-card:nth-child(1)::before{background:var(--red);}
  .stat-card:nth-child(2)::before{background:var(--yellow);}
  .stat-card:nth-child(3)::before{background:var(--green);}
  .stat-card:nth-child(4)::before{background:var(--accent);}
  .stat-icon{font-size:1.6rem;margin-bottom:0.75rem;}
  .stat-value{font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;margin-bottom:0.25rem;}
  .stat-label{font-size:0.8rem;color:var(--muted);}
  .dash-grid{display:grid;grid-template-columns:1.4fr 1fr;gap:1.5rem;}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.5rem;}
  .card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;}
  .card-title{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;}
  .card-action{font-size:0.8rem;color:var(--accent);text-decoration:none;}
  .task-item{display:flex;align-items:center;gap:0.85rem;padding:0.85rem;border-radius:10px;background:var(--surface2);margin-bottom:0.6rem;transition:all .2s;}
  .task-item:hover{background:var(--surface3);}
  .task-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
  .task-body{flex:1;}
  .task-body strong{font-size:0.875rem;display:block;}
  .task-body .meta{font-size:0.75rem;color:var(--muted);margin-top:0.1rem;}
  .task-urgency{font-size:0.72rem;padding:0.2rem 0.6rem;border-radius:20px;font-weight:600;flex-shrink:0;}
  .urg-red{background:rgba(247,122,79,0.15);color:#f77a4f;}
  .urg-yellow{background:rgba(254,188,46,0.15);color:#febc2e;}
  .urg-green{background:rgba(56,217,169,0.15);color:#38d9a9;}
  .empty-state{text-align:center;padding:2rem;color:var(--muted);font-size:0.9rem;}
  .sched-item{display:flex;gap:1rem;padding:0.75rem 0;border-bottom:1px solid var(--border);}
  .sched-item:last-child{border-bottom:none;}
  .sched-time{font-size:0.78rem;color:var(--muted);width:70px;flex-shrink:0;padding-top:2px;}
  .sched-bar{width:3px;border-radius:3px;flex-shrink:0;}
  .sched-detail strong{font-size:0.875rem;display:block;}
  .sched-detail span{font-size:0.75rem;color:var(--muted);}
  .progress-item{margin-bottom:1rem;}
  .progress-header{display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.4rem;}
  .progress-header span:last-child{color:var(--muted);font-size:0.78rem;}
  .progress-bar{height:6px;border-radius:6px;background:var(--surface2);}
  .progress-fill{height:100%;border-radius:6px;}
  .quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-top:1rem;}
  .quick-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:1rem;text-decoration:none;color:var(--text);transition:all .2s;display:block;}
  .quick-card:hover{border-color:rgba(79,142,247,0.4);background:var(--surface3);}
  .quick-card .qicon{font-size:1.5rem;margin-bottom:0.5rem;display:block;}
  .quick-card strong{font-size:0.85rem;display:block;}
  .quick-card span{font-size:0.75rem;color:var(--muted);}
  .logout-link{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1.5rem;text-decoration:none;color:var(--muted);font-size:0.9rem;font-weight:500;transition:all .2s;margin-top:0.5rem;}
  .logout-link:hover{color:#f77a4f;}
  .notif-card{margin-bottom:1.5rem;}
  .notif-item{display:flex;align-items:flex-start;gap:0.85rem;padding:0.85rem 0;border-bottom:1px solid var(--border);}
  .notif-item:last-child{border-bottom:none;}
  .notif-icon{font-size:1rem;line-height:1.2;padding-top:0.1rem;}
  .notif-body{flex:1;}
  .notif-body strong{font-size:0.85rem;display:block;margin-bottom:0.15rem;}
  .notif-body span{font-size:0.76rem;color:var(--muted);}
  .notif-unread{background:rgba(79,142,247,0.1);border-radius:8px;padding:0 0.35rem;color:var(--accent);font-size:0.68rem;font-weight:700;}
  .notif-link{font-size:0.76rem;color:var(--accent);text-decoration:none;}
  .notif-link:hover{text-decoration:underline;}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">StudySync</div>
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

  <?php if ($notificationCount > 0 || count($recentNotifications) > 0): ?>
  <div class="card notif-card">
    <div class="card-header">
      <span class="card-title">Reminders & Alerts</span>
      <?php if ($notificationCount > 0): ?>
        <a href="dashboard.php?mark_all_notifs=1" class="card-action">Mark all as read →</a>
      <?php endif; ?>
    </div>
    <?php foreach ($recentNotifications as $note): ?>
      <div class="notif-item">
        <div class="notif-icon"><?= $note['type']==='deadline' ? '⏰' : ($note['type']==='group' ? '💬' : ($note['type']==='resource' ? '📁' : '🤖')) ?></div>
        <div class="notif-body">
          <strong><?= htmlspecialchars($note['message']) ?></strong>
          <span><?= date('M j, Y g:i A', strtotime($note['created_at'])) ?></span>
        </div>
        <?php if (!$note['is_read']): ?>
          <span class="notif-unread">new</span>
          <a class="notif-link" href="dashboard.php?mark_notif=<?=$note['id']?>">Mark read</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

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
