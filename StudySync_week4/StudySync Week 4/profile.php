<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
}

requireLogin();
$uid = (int) $_SESSION['user_id'];

if (isset($_GET['mark_notif'])) {
    $notificationId = cleanInt($_GET['mark_notif'] ?? 0, 1);
    if ($notificationId > 0) {
        markNotificationRead($conn, $uid, $notificationId);
    }
    redirectTo('profile.php?tab=notifications');
}

if (isset($_GET['mark_all_notifs'])) {
    markAllNotificationsRead($conn, $uid);
    redirectTo('profile.php?tab=notifications');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    $first = cleanNameText($_POST['first_name'] ?? '', 80);
    $last  = cleanNameText($_POST['last_name'] ?? '', 80);
    $email = cleanEmail($_POST['email'] ?? '');
    $sid   = cleanStudentId($_POST['student_id'] ?? '', 30);
    $year  = cleanText($_POST['year'] ?? '', 40);
    $prog  = cleanText($_POST['programme'] ?? '', 120);
    $bio   = cleanMultilineText($_POST['bio'] ?? '', 1000);

    if ($first === '' || $last === '' || $email === '') {
        $profileError = 'First name, last name, and email are required.';
    } elseif (!validEmailAddress($email)) {
        $profileError = 'Please enter a valid email address.';
    } else {
        $check = $conn->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $check->bind_param('si', $email, $uid);
        $existing = fetchSingleRow($check);
        if ($existing) {
            $profileError = 'That email is already in use.';
        } else {
            $stmt = $conn->prepare(
                'UPDATE users SET first_name = ?, last_name = ?, email = ?, student_id = ?, year = ?, programme = ?, bio = ? WHERE id = ?'
            );
            $stmt->bind_param('sssssssi', $first, $last, $email, $sid, $year, $prog, $bio, $uid);
            $stmt->execute();
            $stmt->close();
            $profileSuccess = 'Profile updated successfully!';
            $_SESSION['user_name'] = $first . ' ' . $last;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = (string) ($_POST['current_pw'] ?? '');
    $newpw   = (string) ($_POST['new_pw'] ?? '');
    $confirm = (string) ($_POST['confirm_pw'] ?? '');

    $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $uid);
    $row = fetchSingleRow($stmt);

    if (!$row || !password_verify($current, $row['password_hash'])) {
        $pwError = 'Current password is incorrect.';
    } elseif (strlen($newpw) < 8) {
        $pwError = 'New password must be at least 8 characters.';
    } elseif ($newpw !== $confirm) {
        $pwError = 'New passwords do not match.';
    } else {
        $hash = password_hash($newpw, PASSWORD_BCRYPT);
        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $uid);
        $stmt->execute();
        $stmt->close();
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
        'weekly_summary' => isset($_POST['weekly_summary']) ? 1 : 0,
    ];
    $notifSuccess = 'Notification preferences saved for this session.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_course') {
    $code = cleanCourseCode($_POST['course_code'] ?? '', 30);
    $name = cleanText($_POST['course_name'] ?? '', 120);
    $cred = cleanInt($_POST['credits'] ?? 3, 0, 12);
    $lec  = cleanText($_POST['lecturer'] ?? '', 120);
    $col  = normalizeHexColor($_POST['color'] ?? '#4f8ef7');

    if ($code !== '' && $name !== '') {
        $stmt = $conn->prepare('INSERT INTO courses (user_id, course_code, course_name, credits, lecturer, color) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ississ', $uid, $code, $name, $cred, $lec, $col);
        $stmt->execute();
        $stmt->close();
        $courseSuccess = "Course '$code' added!";
    } else {
        $courseError = 'Course code and course name are required.';
    }
}

if (isset($_GET['del_course'])) {
    $cid = cleanInt($_GET['del_course'] ?? 0, 1);
    if ($cid > 0) {
        $stmt = $conn->prepare('DELETE FROM courses WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $cid, $uid);
        $stmt->execute();
        $stmt->close();
    }
    redirectTo('profile.php?tab=courses');
}

$user = getUser($conn);
$init = initials($user['first_name'], $user['last_name']);
$courses = $conn->query("SELECT * FROM courses WHERE user_id = $uid ORDER BY course_code");
$urgent = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id = $uid AND status = 'pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];

$activeTab = cleanEnum($_GET['tab'] ?? 'personal', ['personal', 'security', 'notifications', 'courses'], 'personal');
$notificationPrefs = $_SESSION['notification_preferences'] ?? [
    'email_reminders' => 1,
    'remind_3_days' => 1,
    'remind_1_day' => 1,
    'group_messages' => 1,
    'resource_shared' => 1,
    'weekly_summary' => 1,
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
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-profile">
<aside class="sidebar">
  <a class="sidebar-logo" href="dashboard.php" aria-label="Go to dashboard"><img src="assets/Studysync.png" alt="StudySync logo"></a>
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

  <!-- HERO -->
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

  <!-- TABS -->
  <div class="tab-bar">
    <a href="?tab=personal"      class="tab <?=$activeTab==='personal'     ?'active':''?>">Personal Info</a>
    <a href="?tab=courses"       class="tab <?=$activeTab==='courses'      ?'active':''?>">My Courses</a>
    <a href="?tab=notifications" class="tab <?=$activeTab==='notifications'?'active':''?>">Notifications</a>
    <a href="?tab=security"      class="tab <?=$activeTab==='security'     ?'active':''?>">Security</a>
  </div>

  <!-- ── PERSONAL INFO ──────────────────────────────────────── -->
  <?php if($activeTab==='personal'): ?>
  <div class="card">
    <div class="card-title">Personal Information</div>
    <?php if(isset($profileSuccess)): ?><div class="success-msg">✅ <?=htmlspecialchars($profileSuccess)?></div><?php endif; ?>
    <?php if(isset($profileError)):   ?><div class="error-msg">⚠ <?=htmlspecialchars($profileError)?></div><?php endif; ?>
    <form method="POST" action="profile.php?tab=personal">
      <?= csrfField() ?>
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

  <!-- ── COURSES ────────────────────────────────────────────── -->
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
      <?= csrfField() ?>
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

  <!-- ── NOTIFICATIONS ─────────────────────────────────────── -->
  <?php elseif($activeTab==='notifications'): ?>
  <div class="card">
    <div class="card-title">Notification Preferences</div>
    <form method="POST" action="profile.php?tab=notifications">
      <?= csrfField() ?>
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
          <input type="checkbox" name="<?=$p['name']?>" checked>
          <span class="toggle-ui"></span>
        </label>
      </div>
      <?php endforeach; ?>
      <div class="save-row"><button type="submit" class="btn-primary">Save Preferences</button></div>
    </form>
  </div>

  <!-- ── SECURITY ──────────────────────────────────────────── -->
  <?php elseif($activeTab==='security'): ?>
  <div class="card">
    <div class="card-title">Change Password</div>
    <?php if(isset($pwSuccess)): ?><div class="success-msg">✅ <?=htmlspecialchars($pwSuccess)?></div><?php endif; ?>
    <?php if(isset($pwError)):   ?><div class="error-msg">⚠ <?=htmlspecialchars($pwError)?></div><?php endif; ?>
    <form method="POST" action="profile.php?tab=security" style="max-width:420px">
      <?= csrfField() ?>
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
