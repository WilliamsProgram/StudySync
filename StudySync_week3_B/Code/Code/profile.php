<?php
require_once 'config.php';
requireLogin();
$uid  = $_SESSION['user_id'];

if (isset($_GET['mark_notif'])) {
    markNotificationRead($conn, $uid, intval($_GET['mark_notif']));
    redirectTo('profile.php?tab=notifications');
}

if (isset($_GET['mark_all_notifs'])) {
    markAllNotificationsRead($conn, $uid);
    redirectTo('profile.php?tab=notifications');
}

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    $first = trim($_POST['first_name'] ?? '');
    $last  = trim($_POST['last_name']  ?? '');
    $email = cleanEmail($_POST['email'] ?? '');
    $sid   = trim($_POST['student_id'] ?? '');
    $year  = trim($_POST['year']       ?? '');
    $prog  = trim($_POST['programme']  ?? '');
    $bio   = trim($_POST['bio']        ?? '');

    if ($first && $last && $email) {
         
        $check = $conn->prepare("SELECT id FROM users WHERE email=? AND id!=?");
        $check->bind_param("si", $email, $uid);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $profileError = 'That email is already in use.';
        } else {
            $stmt = $conn->prepare(
                "UPDATE users SET first_name=?,last_name=?,email=?,student_id=?,year=?,programme=?,bio=? WHERE id=?"
            );
            $stmt->bind_param("sssssssi", $first,$last,$email,$sid,$year,$prog,$bio,$uid);
            $stmt->execute();
            $profileSuccess = 'Profile updated successfully!';
            $_SESSION['user_name'] = $first.' '.$last;
        }
    } else {
        $profileError = 'First name, last name, and email are required.';
    }
}

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = $_POST['current_pw']  ?? '';
    $newpw   = $_POST['new_pw']      ?? '';
    $confirm = $_POST['confirm_pw']  ?? '';

    $row = $conn->query("SELECT password_hash FROM users WHERE id=$uid")->fetch_assoc();
    if (!password_verify($current, $row['password_hash'])) {
        $pwError = 'Current password is incorrect.';
    } elseif (strlen($newpw) < 8) {
        $pwError = 'New password must be at least 8 characters.';
    } elseif ($newpw !== $confirm) {
        $pwError = 'New passwords do not match.';
    } else {
        $hash = password_hash($newpw, PASSWORD_BCRYPT);
        $stmt = $conn->prepare('UPDATE users SET password_hash=? WHERE id=?');
        $stmt->bind_param('si', $hash, $uid);
        $stmt->execute();
        $pwSuccess = 'Password changed successfully!';
    }
}

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notifications') {
    $_SESSION['notification_preferences'] = [
        'email_reminders' => isset($_POST['email_reminders']) ? 1 : 0,
        'remind_3_days' => isset($_POST['remind_3_days']) ? 1 : 0,
        'remind_1_day' => isset($_POST['remind_1_day']) ? 1 : 0,
        'group_messages' => isset($_POST['group_messages']) ? 1 : 0,
        'resource_shared' => isset($_POST['resource_shared']) ? 1 : 0,
        'weekly_summary' => isset($_POST['weekly_summary']) ? 1 : 0
    ];
    $notifSuccess = 'Notification preferences saved for this session.';
}

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_course') {
    $code = trim($_POST['course_code'] ?? '');
    $name = trim($_POST['course_name'] ?? '');
    $cred = intval($_POST['credits'] ?? 3);
    $lec  = trim($_POST['lecturer']   ?? '');
    $col  = trim($_POST['color']      ?? '#4f8ef7');
    if ($code && $name) {
        $stmt = $conn->prepare("INSERT INTO courses (user_id,course_code,course_name,credits,lecturer,color) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("ississ", $uid,$code,$name,$cred,$lec,$col);
        $stmt->execute();
        $courseSuccess = "Course '$code' added!";
    }
}

 
if (isset($_GET['del_course'])) {
    $cid = intval($_GET['del_course']);
    $conn->query("DELETE FROM courses WHERE id=$cid AND user_id=$uid");
    header("Location: profile.php?tab=courses");
    exit();
}

 
$user    = getUser($conn);
$init    = initials($user['first_name'], $user['last_name']);
$courses = $conn->query("SELECT * FROM courses WHERE user_id=$uid ORDER BY course_code");
$urgent  = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id=$uid AND status='pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];

$activeTab = $_GET['tab'] ?? 'personal';
$notificationPrefs = $_SESSION['notification_preferences'] ?? [
    'email_reminders' => 1,
    'remind_3_days' => 1,
    'remind_1_day' => 1,
    'group_messages' => 1,
    'resource_shared' => 1,
    'weekly_summary' => 1
];
$recentNotifications = getRecentNotifications($conn, $uid, 20);
$notificationCount = getUnreadNotificationCount($conn, $uid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile — StudySync</title>
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
  .logout-link{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1.5rem;text-decoration:none;color:var(--muted);font-size:0.9rem;transition:all .2s;margin-top:0.5rem;}
  .logout-link:hover{color:#f77a4f;}
  .main{margin-left:240px;flex:1;padding:2.5rem;max-width:960px;}
  .page-header{margin-bottom:2rem;}
  .page-header h1{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;}

  
  .profile-hero{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:2rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:2rem;position:relative;overflow:hidden;}
  .profile-hero::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(79,142,247,0.05),rgba(56,217,169,0.03));pointer-events:none;}
  .avatar-large{width:88px;height:88px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;flex-shrink:0;}
  .profile-info h2{font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;margin-bottom:0.3rem;}
  .profile-info .sub{color:var(--muted);font-size:0.9rem;margin-bottom:0.75rem;}
  .p-badges{display:flex;gap:0.5rem;flex-wrap:wrap;}
  .p-badge{font-size:0.75rem;padding:0.25rem 0.7rem;border-radius:20px;font-weight:600;}
  .profile-stats{display:flex;gap:0;margin-left:auto;flex-shrink:0;}
  .p-stat{text-align:center;padding:0 1.5rem;border-right:1px solid var(--border);}
  .p-stat:last-child{border-right:none;}
  .p-stat strong{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;display:block;color:var(--accent);}
  .p-stat span{font-size:0.72rem;color:var(--muted);}

  
  .tab-bar{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:1.75rem;}
  .tab{padding:0.75rem 1.5rem;font-size:0.9rem;font-weight:500;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;transition:all .2s;text-decoration:none;}
  .tab:hover{color:var(--text);}
  .tab.active{color:var(--accent);border-bottom-color:var(--accent);}

  
  .card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.75rem;margin-bottom:1.25rem;}
  .card-title{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;margin-bottom:1.5rem;}
  .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;}
  .fg{margin-bottom:0;}
  .fg.full{grid-column:1/-1;}
  .fg label{display:block;font-size:0.82rem;color:var(--muted);margin-bottom:0.45rem;}
  .fg input,.fg select,.fg textarea{width:100%;padding:0.75rem 1rem;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--text);font-family:'DM Sans',sans-serif;font-size:0.875rem;outline:none;transition:all .2s;}
  .fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--accent);}
  .fg textarea{resize:vertical;min-height:80px;}
  .save-row{display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;}
  .btn-primary{padding:0.65rem 1.5rem;border-radius:8px;background:linear-gradient(135deg,var(--accent),#3a6fd4);color:#fff;font-size:0.875rem;font-weight:600;border:none;cursor:pointer;transition:all .2s;box-shadow:0 0 16px var(--glow);}
  .btn-primary:hover{transform:translateY(-1px);}
  .btn-secondary{padding:0.65rem 1.5rem;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:0.875rem;cursor:pointer;transition:all .2s;text-decoration:none;}
  .success-msg{background:rgba(56,217,169,0.1);border:1px solid rgba(56,217,169,0.3);border-radius:8px;padding:0.75rem 1rem;font-size:0.875rem;color:var(--accent2);margin-bottom:1.25rem;}
  .error-msg{background:rgba(247,122,79,0.1);border:1px solid rgba(247,122,79,0.3);border-radius:8px;padding:0.75rem 1rem;font-size:0.875rem;color:var(--red);margin-bottom:1.25rem;}

  
  .course-item{display:flex;align-items:center;gap:1rem;padding:0.85rem 1rem;background:var(--surface2);border:1px solid var(--border);border-radius:10px;margin-bottom:0.65rem;}
  .c-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
  .c-info{flex:1;}
  .c-info strong{font-size:0.9rem;display:block;}
  .c-info span{font-size:0.78rem;color:var(--muted);}
  .del-btn{background:none;border:none;color:var(--muted);cursor:pointer;font-size:1rem;padding:0.25rem;transition:color .2s;text-decoration:none;}
  .del-btn:hover{color:var(--red);}

  
  .notif-row{display:flex;align-items:center;justify-content:space-between;padding:0.85rem 0;border-bottom:1px solid var(--border);}
  .notif-row:last-child{border-bottom:none;}
  .notif-label strong{font-size:0.875rem;display:block;}
  .notif-label span{font-size:0.78rem;color:var(--muted);}
  .toggle-wrap{display:flex;align-items:center;}
  .toggle-wrap input[type=checkbox]{display:none;}
  .toggle-ui{width:44px;height:24px;border-radius:12px;background:var(--surface2);border:1px solid var(--border);position:relative;cursor:pointer;transition:all .3s;display:block;}
  .toggle-wrap input:checked+.toggle-ui{background:linear-gradient(135deg,var(--accent2),#2ab080);border-color:transparent;}
  .toggle-ui::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:all .3s;}
  .toggle-wrap input:checked+.toggle-ui::after{left:23px;}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-logo">StudySync</div>
  <div class="sidebar-section">Main</div>
  <a class="sidebar-item" href="dashboard.php"><span class="icon">🏠</span> Dashboard</a>
  <a class="sidebar-item" href="assignments.php"><span class="icon">📚</span> Assignments <?php if($urgent>0): ?><span class="sidebar-badge"><?=$urgent?></span><?php endif; ?></a>
  <a class="sidebar-item" href="schedule.php"><span class="icon">🗓️</span> Schedule</a>
  <div style="margin-top:1rem"></div>
  <div class="sidebar-section">Collaborate</div>
  <a class="sidebar-item" href="study-groups.php"><span class="icon">👥</span> Study Groups</a>
  <a class="sidebar-item" href="resources.php"><span class="icon">📁</span> Resources</a>
  <div style="margin-top:1rem"></div>
  <div class="sidebar-section">Tools</div>
  <a class="sidebar-item" href="gpa.php"><span class="icon">🎯</span> GPA Calculator</a>
  <a class="sidebar-item active" href="profile.php"><span class="icon">👤</span> Profile</a>
  <a class="logout-link" href="logout.php"><span class="icon">🚪</span> Log Out</a>
  <div class="sidebar-footer">
    <div class="user-mini"><div class="avatar"><?=$init?></div><div class="user-info"><strong><?=htmlspecialchars($user['first_name'].' '.$user['last_name'])?></strong><span><?=htmlspecialchars($user['year']??'Student')?></span></div></div>
  </div>
</aside>

<main class="main">
  <div class="page-header"><h1>Profile & Settings</h1></div>

  
  <?php
  $totalAssign = $conn->query("SELECT COUNT(*) AS n FROM assignments WHERE user_id=$uid")->fetch_assoc()['n'];
  $doneAssign  = $conn->query("SELECT COUNT(*) AS n FROM assignments WHERE user_id=$uid AND status='completed'")->fetch_assoc()['n'];
  $groupCount  = $conn->query("SELECT COUNT(*) AS n FROM group_members WHERE user_id=$uid")->fetch_assoc()['n'];
  $courseCount = $conn->query("SELECT COUNT(*) AS n FROM courses WHERE user_id=$uid")->fetch_assoc()['n'];
  ?>
  <div class="profile-hero">
    <div class="avatar-large"><?=$init?></div>
    <div class="profile-info">
      <h2><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></h2>
      <div class="sub"><?= htmlspecialchars($user['email']) ?> · <?= htmlspecialchars($user['student_id'] ?? 'No ID') ?></div>
      <div class="p-badges">
        <?php if($user['year']): ?><span class="p-badge" style="background:rgba(79,142,247,0.15);color:var(--accent)"><?=htmlspecialchars($user['year'])?></span><?php endif; ?>
        <?php if($user['programme']): ?><span class="p-badge" style="background:rgba(56,217,169,0.12);color:var(--accent2)"><?=htmlspecialchars($user['programme'])?></span><?php endif; ?>
        <span class="p-badge" style="background:rgba(254,188,46,0.12);color:#febc2e"><?=$courseCount?> Courses</span>
      </div>
    </div>
    <div class="profile-stats">
      <div class="p-stat"><strong><?=$doneAssign?></strong><span>Tasks Done</span></div>
      <div class="p-stat"><strong style="color:var(--accent2)"><?=$groupCount?></strong><span>Study Groups</span></div>
    </div>
  </div>

  
  <div class="tab-bar">
    <a href="?tab=personal"      class="tab <?=$activeTab==='personal'     ?'active':''?>">Personal Info</a>
    <a href="?tab=courses"       class="tab <?=$activeTab==='courses'      ?'active':''?>">My Courses</a>
    <a href="?tab=notifications" class="tab <?=$activeTab==='notifications'?'active':''?>">Notifications</a>
    <a href="?tab=security"      class="tab <?=$activeTab==='security'     ?'active':''?>">Security</a>
  </div>

  
  <?php if($activeTab==='personal'): ?>
  <div class="card">
    <div class="card-title">Personal Information</div>
    <?php if(isset($profileSuccess)): ?><div class="success-msg">✅ <?=htmlspecialchars($profileSuccess)?></div><?php endif; ?>
    <?php if(isset($profileError)):   ?><div class="error-msg">⚠ <?=htmlspecialchars($profileError)?></div><?php endif; ?>
    <form method="POST" action="profile.php?tab=personal">
      <input type="hidden" name="action" value="save_profile">
      <div class="form-grid">
        <div class="fg"><label>First Name *</label><input type="text" name="first_name" value="<?=htmlspecialchars($user['first_name'])?>" required></div>
        <div class="fg"><label>Last Name *</label><input type="text" name="last_name" value="<?=htmlspecialchars($user['last_name'])?>" required></div>
        <div class="fg"><label>Email Address *</label><input type="email" name="email" value="<?=htmlspecialchars($user['email'])?>" required></div>
        <div class="fg"><label>Student ID</label><input type="text" name="student_id" value="<?=htmlspecialchars($user['student_id']??'')?>"></div>
        <div class="fg"><label>Year of Study</label>
          <select name="year">
            <option value="">Select year</option>
            <?php foreach(['Year 1','Year 2','Year 3','Year 4','Postgraduate'] as $y): ?>
              <option <?=($user['year']===$y?'selected':'')?>>><?=$y?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>Programme</label>
          <select name="programme">
            <option value="">Select programme</option>
            <?php foreach(['B.Sc. Information Technology','B.Sc. Computer Science','B.Sc. Nursing','B.Sc. Business','B.Ed. Education','Other'] as $p): ?>
              <option value="<?=$p?>" <?=($user['programme']===$p?'selected':'')?>><?=$p?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg full"><label>Bio / Study Preferences</label><textarea name="bio"><?=htmlspecialchars($user['bio']??'')?></textarea></div>
      </div>
      <div class="save-row"><button type="submit" class="btn-primary">Save Changes</button></div>
    </form>
  </div>

  
  <?php elseif($activeTab==='courses'): ?>
  <div class="card">
    <div class="card-title">Current Courses</div>
    <?php if(isset($courseSuccess)): ?><div class="success-msg">✅ <?=htmlspecialchars($courseSuccess)?></div><?php endif; ?>
    <?php while($c=$courses->fetch_assoc()): ?>
    <div class="course-item">
      <div class="c-dot" style="background:<?=htmlspecialchars($c['color'])?>"></div>
      <div class="c-info">
        <strong><?=htmlspecialchars($c['course_code'].' — '.$c['course_name'])?></strong>
        <span><?=$c['credits']?> Credits <?= $c['lecturer']?'· '.$c['lecturer']:'' ?></span>
      </div>
      <a href="?tab=courses&del_course=<?=$c['id']?>" class="del-btn"
         onclick="return confirm('Remove this course?')">🗑</a>
    </div>
    <?php endwhile; ?>
    <?php if($courses->num_rows===0): ?><p style="color:var(--muted);font-size:0.9rem;margin-bottom:1rem">No courses added yet.</p><?php endif; ?>
    <div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--border)">
      <div class="card-title">Add New Course</div>
      <form method="POST" action="profile.php?tab=courses">
        <input type="hidden" name="action" value="add_course">
        <div class="form-grid">
          <div class="fg"><label>Course Code *</label><input type="text" name="course_code" placeholder="e.g. COMP3001" required></div>
          <div class="fg"><label>Course Name *</label><input type="text" name="course_name" placeholder="e.g. Algorithms" required></div>
          <div class="fg"><label>Credits</label><input type="number" name="credits" value="3" min="1" max="6"></div>
          <div class="fg"><label>Lecturer</label><input type="text" name="lecturer" placeholder="e.g. Dr. Brown"></div>
          <div class="fg"><label>Colour</label><input type="color" name="color" value="#4f8ef7" style="height:42px;padding:0.3rem;"></div>
        </div>
        <div class="save-row"><button type="submit" class="btn-primary">+ Add Course</button></div>
      </form>
    </div>
  </div>

  
  <?php elseif($activeTab==='notifications'): ?>
  <div class="card">
    <div class="card-title">Notification Preferences</div>
    <?php if(isset($notifSuccess)): ?><div class="success-msg">✅ <?=htmlspecialchars($notifSuccess)?></div><?php endif; ?>
    <form method="POST" action="profile.php?tab=notifications">
      <input type="hidden" name="action" value="save_notifications">
      <?php
      $notifPrefs = [
        ['name'=>'email_reminders',   'label'=>'Email Reminders',          'sub'=>'Receive deadline reminders by email'],
        ['name'=>'remind_3_days',     'label'=>'3 Days Before Deadline',   'sub'=>'Alert 3 days before each assignment'],
        ['name'=>'remind_1_day',      'label'=>'1 Day Before Deadline',    'sub'=>'Urgent reminder the day before'],
        ['name'=>'group_messages',    'label'=>'Study Group Messages',     'sub'=>'Notify me of new group chat messages'],
        ['name'=>'resource_shared',   'label'=>'New Resource Shared',      'sub'=>'Alert when someone shares a file in your groups'],
        ['name'=>'weekly_summary',    'label'=>'Weekly Progress Summary',  'sub'=>'Receive a weekly summary every Sunday'],
      ];
      foreach($notifPrefs as $p): ?>
      <div class="notif-row">
        <div class="notif-label">
          <strong><?=$p['label']?></strong>
          <span><?=$p['sub']?></span>
        </div>
        <label class="toggle-wrap">
          <input type="checkbox" name="<?=$p['name']?>" <?=$notificationPrefs[$p['name']] ? 'checked' : ''?>>
          <span class="toggle-ui"></span>
        </label>
      </div>
      <?php endforeach; ?>
      <div class="save-row"><button type="submit" class="btn-primary">Save Preferences</button></div>
    </form>
  </div>

  <div class="card" style="margin-top:1.5rem">
    <div class="card-title">Recent Alerts <?= $notificationCount > 0 ? '<span style="color:var(--accent);font-size:0.85rem">(' . $notificationCount . ' unread)</span>' : '' ?></div>
    <div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
      <?php if($notificationCount > 0): ?><a href="profile.php?tab=notifications&mark_all_notifs=1" style="color:var(--accent);text-decoration:none;font-size:0.85rem">Mark all as read</a><?php endif; ?>
    </div>
    <?php if(count($recentNotifications) === 0): ?>
      <p style="color:var(--muted);font-size:0.9rem">No alerts yet.</p>
    <?php else: ?>
      <?php foreach($recentNotifications as $note): ?>
        <div class="notif-row">
          <div class="notif-label">
            <strong><?=htmlspecialchars($note['message'])?></strong>
            <span><?=date('M j, Y g:i A', strtotime($note['created_at']))?> <?= !$note['is_read'] ? '· unread' : '' ?></span>
          </div>
          <?php if(!$note['is_read']): ?>
            <a href="profile.php?tab=notifications&mark_notif=<?=$note['id']?>" style="color:var(--accent);text-decoration:none;font-size:0.82rem">Read</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  
  <?php elseif($activeTab==='security'): ?>
  <div class="card">
    <div class="card-title">Change Password</div>
    <?php if(isset($pwSuccess)): ?><div class="success-msg">✅ <?=htmlspecialchars($pwSuccess)?></div><?php endif; ?>
    <?php if(isset($pwError)):   ?><div class="error-msg">⚠ <?=htmlspecialchars($pwError)?></div><?php endif; ?>
    <form method="POST" action="profile.php?tab=security" style="max-width:420px">
      <input type="hidden" name="action" value="change_password">
      <div style="display:flex;flex-direction:column;gap:1rem;">
        <div class="fg"><label>Current Password</label><input type="password" name="current_pw" required></div>
        <div class="fg"><label>New Password (min. 8 chars)</label><input type="password" name="new_pw" required></div>
        <div class="fg"><label>Confirm New Password</label><input type="password" name="confirm_pw" required></div>
      </div>
      <div class="save-row"><button type="submit" class="btn-primary">Update Password</button></div>
    </form>
  </div>
  <div class="card" style="border-color:rgba(247,122,79,0.2)">
    <div class="card-title" style="color:var(--red)">Danger Zone</div>
    <p style="font-size:0.875rem;color:var(--muted);margin-bottom:1.25rem">Deleting your account is permanent. All data will be removed.</p>
    <button style="padding:0.65rem 1.5rem;border-radius:8px;background:rgba(247,122,79,0.1);border:1px solid rgba(247,122,79,0.3);color:var(--red);font-size:0.875rem;cursor:pointer;"
            onclick="return confirm('Are you sure? This cannot be undone.')">Delete My Account</button>
  </div>
  <?php endif; ?>
</main>
</body>
</html>
