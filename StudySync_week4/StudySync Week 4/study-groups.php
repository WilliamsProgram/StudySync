<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
}

requireLogin();
$uid  = (int) $_SESSION['user_id'];
$user = getUser($conn);
$init = initials($user['first_name'], $user['last_name']);
$groupError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_group') {
    $name = cleanText($_POST['name'] ?? '', 120);
    $code = cleanCourseCode($_POST['course_code'] ?? '', 30);
    $desc = cleanMultilineText($_POST['description'] ?? '', 1000);

    if ($name !== '') {
        $stmt = $conn->prepare('INSERT INTO study_groups (name, course_code, description, created_by) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('sssi', $name, $code, $desc, $uid);
        $stmt->execute();
        $gid = (int) $conn->insert_id;
        $stmt->close();

        $joinStmt = $conn->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)');
        $joinStmt->bind_param('ii', $gid, $uid);
        $joinStmt->execute();
        $joinStmt->close();

        createNotification($conn, $uid, 'Study group created: ' . $name . '.', 'group', 'group_created_' . $gid, 'group', $gid);
        redirectTo('study-groups.php?g=' . $gid);
    }
    $groupError = 'Please enter a group name.';
}

if (isset($_GET['join'])) {
    $gid = cleanInt($_GET['join'] ?? 0, 1);
    if ($gid > 0 && groupExists($conn, $gid)) {
        $existsStmt = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
        $existsStmt->bind_param('ii', $gid, $uid);
        $exists = fetchSingleRow($existsStmt);

        if (!$exists) {
            $joinStmt = $conn->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)');
            $joinStmt->bind_param('ii', $gid, $uid);
            $joinStmt->execute();
            $joinStmt->close();

            $groupStmt = $conn->prepare('SELECT name, created_by FROM study_groups WHERE id = ? LIMIT 1');
            $groupStmt->bind_param('i', $gid);
            $group = fetchSingleRow($groupStmt);
            if ($group) {
                createNotification($conn, $uid, 'You joined the study group ' . $group['name'] . '.', 'group', 'group_join_' . $gid . '_' . $uid, 'group', $gid);
                if ((int) $group['created_by'] !== $uid) {
                    createNotification($conn, (int) $group['created_by'], $user['first_name'] . ' ' . $user['last_name'] . ' joined ' . $group['name'] . '.', 'group', 'group_member_join_' . $gid . '_' . $uid, 'group', $gid);
                }
            }
        }
    }
    redirectTo('study-groups.php?g=' . $gid);
}

if (isset($_GET['leave'])) {
    $gid = cleanInt($_GET['leave'] ?? 0, 1);
    if ($gid > 0 && groupExists($conn, $gid)) {
        $leaveStmt = $conn->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?');
        $leaveStmt->bind_param('ii', $gid, $uid);
        $leaveStmt->execute();
        $leaveStmt->close();
    }
    redirectTo('study-groups.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $gid = cleanInt($_POST['group_id'] ?? 0, 1);
    $msg = cleanMultilineText($_POST['message'] ?? '', 2000);

    $memberStmt = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $memberStmt->bind_param('ii', $gid, $uid);
    $isMember = fetchSingleRow($memberStmt);

    if ($gid > 0 && $msg !== '' && $isMember) {
        $stmt = $conn->prepare('INSERT INTO group_messages (group_id, user_id, message) VALUES (?, ?, ?)');
        $stmt->bind_param('iis', $gid, $uid, $msg);
        $stmt->execute();
        $messageId = (int) $conn->insert_id;
        $stmt->close();

        $recipients = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ? AND user_id <> ?');
        $recipients->bind_param('ii', $gid, $uid);
        $recipientRows = fetchAllRows($recipients);
        foreach ($recipientRows as $row) {
            createNotification($conn, (int) $row['user_id'], 'New study group message from ' . $user['first_name'] . ' ' . $user['last_name'] . '.', 'group', 'group_message_' . $messageId . '_u' . $row['user_id'], 'group', $gid);
        }
    }
    redirectTo('study-groups.php?g=' . $gid);
}

$activeGid = cleanInt($_GET['g'] ?? 0, 0);
$tab = cleanEnum($_GET['tab'] ?? 'my', ['my', 'discover'], 'my');

$myGroups = $conn->query(
    "SELECT sg.*, COUNT(gm2.id) AS member_count
     FROM study_groups sg
     JOIN group_members gm ON sg.id = gm.group_id AND gm.user_id = $uid
     LEFT JOIN group_members gm2 ON sg.id = gm2.group_id
     GROUP BY sg.id
     ORDER BY sg.created_at DESC"
);
$myGroupRows = [];
while ($g = $myGroups->fetch_assoc()) {
    $myGroupRows[] = $g;
}

$discoverGroups = $conn->query(
    "SELECT sg.*, COUNT(gm.id) AS member_count, u.first_name, u.last_name
     FROM study_groups sg
     LEFT JOIN group_members gm ON sg.id = gm.group_id
     LEFT JOIN users u ON sg.created_by = u.id
     WHERE sg.id NOT IN (SELECT group_id FROM group_members WHERE user_id = $uid)
     GROUP BY sg.id
     ORDER BY member_count DESC, sg.created_at DESC"
);
$discoverRows = [];
while ($g = $discoverGroups->fetch_assoc()) {
    $discoverRows[] = $g;
}

if (!$activeGid && count($myGroupRows) > 0) {
    $activeGid = (int) $myGroupRows[0]['id'];
}

$activeGroup = null;
$messages = [];
$members = [];
$isMember = false;

if ($activeGid > 0) {
    $stmt = $conn->prepare('SELECT sg.*, u.first_name, u.last_name FROM study_groups sg JOIN users u ON sg.created_by = u.id WHERE sg.id = ? LIMIT 1');
    $stmt->bind_param('i', $activeGid);
    $activeGroup = fetchSingleRow($stmt);

    if ($activeGroup) {
        $stmt = $conn->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $activeGid, $uid);
        $isMember = fetchSingleRow($stmt) ? true : false;

        $stmt = $conn->prepare(
            'SELECT m.*, u.first_name, u.last_name
             FROM group_messages m
             JOIN users u ON m.user_id = u.id
             WHERE m.group_id = ?
             ORDER BY m.sent_at ASC
             LIMIT 50'
        );
        $stmt->bind_param('i', $activeGid);
        $messages = fetchAllRows($stmt);

        $stmt = $conn->prepare(
            'SELECT u.id, u.first_name, u.last_name, u.year
             FROM group_members gm
             JOIN users u ON gm.user_id = u.id
             WHERE gm.group_id = ?'
        );
        $stmt->bind_param('i', $activeGid);
        $members = fetchAllRows($stmt);
    }
}

$urgent = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id = $uid AND status = 'pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];
$iconMap = ['COMP' => '💻', 'MATH' => '📐', 'ENGL' => '📝', 'BIOL' => '🔬', 'HIST' => '📜', 'PHYS' => '⚛️', 'CHEM' => '🧪', 'NURS' => '🏥'];
function groupIcon($code) {
    global $iconMap;
    foreach ($iconMap as $prefix => $icon) {
        if (stripos($code ?? '', $prefix) === 0) {
            return $icon;
        }
    }
    return '📚';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Study Groups — StudySync</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-study-groups">

<aside class="sidebar">
  <a class="sidebar-logo" href="dashboard.php" aria-label="Go to dashboard"><img src="assets/Studysync.png" alt="StudySync logo"></a>
  <div class="sidebar-section">Main</div>
  <a class="sidebar-item" href="dashboard.php"><span class="icon">🏠</span> Dashboard</a>
  <a class="sidebar-item" href="assignments.php"><span class="icon">📚</span> Assignments <?php if($urgent>0): ?><span class="sidebar-badge"><?=$urgent?></span><?php endif; ?></a>
  <a class="sidebar-item" href="schedule.php"><span class="icon">🗓️</span> Schedule</a>
  <div style="margin-top:1rem"></div>
  <div class="sidebar-section">Collaborate</div>
  <a class="sidebar-item active" href="study-groups.php"><span class="icon">👥</span> Study Groups</a>
  <a class="sidebar-item" href="resources.php"><span class="icon">📁</span> Resources</a>
  <div style="margin-top:1rem"></div>
  <div class="sidebar-section">Tools</div>
  <a class="sidebar-item" href="gpa.php"><span class="icon">🎯</span> GPA Calculator</a>
  <a class="sidebar-item" href="profile.php"><span class="icon">👤</span> Profile</a>
  <a class="logout-link" href="logout.php"><span class="icon">🚪</span> Log Out</a>
  <div class="sidebar-footer">
    <div class="user-mini">
      <div class="avatar"><?=$init?></div>
      <div class="user-info"><strong><?=htmlspecialchars($user['first_name'].' '.$user['last_name'])?></strong><span><?=htmlspecialchars($user['year']??'Student')?></span></div>
    </div>
  </div>
</aside>

<main class="main">
  <div class="page-top">
    <div class="page-top-row">
      <h1>Study Groups</h1>
      <button class="btn-primary" onclick="openModal()">+ Create Group</button>
    </div>
    <div class="tab-row">
      <a href="?tab=my<?=$activeGid?'&g='.$activeGid:''?>"    class="tab-btn <?=$tab==='my'?'active':''?>">My Groups (<?=count($myGroupRows)?>)</a>
      <a href="?tab=discover<?=$activeGid?'&g='.$activeGid:''?>" class="tab-btn <?=$tab==='discover'?'active':''?>">Discover (<?=count($discoverRows)?>)</a>
      <input class="search-input" placeholder="🔍 Search groups..." oninput="filterGroups(this.value)">
    </div>
  </div>

  <div class="content-area">

    <!-- ── GROUPS LIST ── -->
    <div class="groups-list" id="groupsList">
      <?php if($tab==='my'): ?>
        <?php if(empty($myGroupRows)): ?>
          <div class="empty-state">
            <div style="font-size:2.5rem;margin-bottom:0.75rem">👥</div>
            <strong>No groups yet</strong><br>
            Create one or discover groups to join!
          </div>
        <?php endif; ?>
        <?php foreach($myGroupRows as $g):
          $icon = groupIcon($g['course_code']);
          $isActive = $g['id'] == $activeGid;
          // get last message
          $lastMsg = $conn->query("SELECT m.message, u.first_name FROM group_messages m JOIN users u ON m.user_id=u.id WHERE m.group_id={$g['id']} ORDER BY m.sent_at DESC LIMIT 1")->fetch_assoc();
        ?>
        <a href="?tab=my&g=<?=$g['id']?>" class="group-card <?=$isActive?'active':''?>">
          <div class="gc-top">
            <div class="gc-icon"><?=$icon?></div>
            <div style="flex:1">
              <div class="gc-name"><?=htmlspecialchars($g['name'])?></div>
              <div class="gc-course"><?=htmlspecialchars($g['course_code']??'General')?></div>
            </div>
          </div>
          <div class="gc-meta">
            <span>👥 <?=$g['member_count']?> members</span>
          </div>
          <?php if($lastMsg): ?>
          <div class="last-msg"><?=htmlspecialchars($lastMsg['first_name'].': '.$lastMsg['message'])?></div>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>

      <?php else: /* discover */ ?>
        <?php if(empty($discoverRows)): ?>
          <div class="empty-state">No other groups available right now.</div>
        <?php endif; ?>
        <?php foreach($discoverRows as $g): ?>
        <div class="discover-card">
          <div class="gc-top">
            <div class="gc-icon"><?=groupIcon($g['course_code'])?></div>
            <div>
              <h4><?=htmlspecialchars($g['name'])?></h4>
              <div class="gc-course"><?=htmlspecialchars($g['course_code']??'')?> · <?=$g['member_count']?> members</div>
            </div>
          </div>
          <?php if($g['description']): ?>
            <p><?=htmlspecialchars($g['description'])?></p>
          <?php endif; ?>
          <a href="?join=<?=$g['id']?>" class="join-btn">Join Group →</a>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ── CHAT / RIGHT PANEL ── -->
    <?php if($activeGroup && $isMember): ?>
    <div class="chat-panel">
      <div class="chat-header">
        <div class="chat-header-left">
          <div class="chat-group-icon"><?=groupIcon($activeGroup['course_code'])?></div>
          <div>
            <div class="chat-group-name"><?=htmlspecialchars($activeGroup['name'])?></div>
            <div class="chat-group-sub"><?=count($members)?> members · <?=htmlspecialchars($activeGroup['course_code']??'General')?> · Created by <?=htmlspecialchars($activeGroup['first_name'].' '.$activeGroup['last_name'])?></div>
          </div>
        </div>
        <div class="chat-actions">
          <a href="resources.php" class="icon-btn" title="Resources">📁</a>
          <a href="?tab=my&g=<?=$activeGid?>&members=1" class="icon-btn" title="Members">👥</a>
          <a href="?leave=<?=$activeGid?>" class="leave-link" onclick="return confirm('Leave this group?')">Leave group</a>
        </div>
      </div>

      <!-- MESSAGES -->
      <div class="chat-messages" id="chatMessages">
        <?php if(empty($messages)): ?>
          <div class="no-messages">No messages yet. Say hello! 👋</div>
        <?php endif; ?>
        <?php
        $prevDate = '';
        foreach($messages as $m):
          $msgDate = date('M j, Y', strtotime($m['sent_at']));
          $isMine  = $m['user_id'] == $uid;
          $mInit   = initials($m['first_name'], $m['last_name']);
          // Colour from hash
          $colours = ['rgba(79,142,247,0.3)','rgba(56,217,169,0.25)','rgba(247,147,79,0.25)','rgba(167,142,247,0.25)','rgba(254,188,46,0.2)'];
          $colIdx  = crc32($m['user_id']) % count($colours);
          if(abs($colIdx) >= count($colours)) $colIdx = 0;
          $avColour = $colours[abs($colIdx)];
        ?>
          <?php if($msgDate !== $prevDate): $prevDate=$msgDate; ?>
            <div class="system-msg"><?=$msgDate?></div>
          <?php endif; ?>
          <div class="msg <?=$isMine?'mine':''?>">
            <div class="msg-av" style="background:<?=$isMine?'linear-gradient(135deg,var(--accent),var(--accent2))':$avColour?>"><?=$mInit?></div>
            <div class="msg-content">
              <div class="msg-sender"><?=$isMine?'You':htmlspecialchars($m['first_name'].' '.$m['last_name'])?></div>
              <div class="msg-bubble"><?=nl2br(htmlspecialchars($m['message']))?></div>
              <div class="msg-time"><?=date('g:i A', strtotime($m['sent_at']))?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- MEMBERS BAR -->
      <div class="members-bar">
        <span class="members-label">Members:</span>
        <?php foreach($members as $mem):
          $mInit2 = initials($mem['first_name'], $mem['last_name']);
        ?>
          <div class="member-av" title="<?=htmlspecialchars($mem['first_name'].' '.$mem['last_name'])?>"><?=$mInit2?></div>
        <?php endforeach; ?>
      </div>

      <!-- INPUT -->
      <div class="chat-input-area">
        <form method="POST" action="study-groups.php" id="chatForm">
      <?= csrfField() ?>
          <input type="hidden" name="action" value="send_message">
          <input type="hidden" name="group_id" value="<?=$activeGid?>">
          <div class="chat-input-row">
            <textarea class="chat-input" name="message" rows="1" placeholder="Type a message..." id="msgInput"
                      onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();document.getElementById('chatForm').submit();}"></textarea>
            <button type="submit" class="send-btn">➤</button>
          </div>
        </form>
      </div>
    </div>

    <?php elseif($activeGroup && !$isMember): ?>
      <!-- Not a member — show join prompt -->
      <div class="chat-panel">
        <div class="no-group">
          <div class="big-icon"><?=groupIcon($activeGroup['course_code'])?></div>
          <strong><?=htmlspecialchars($activeGroup['name'])?></strong>
          <p><?=count($members)?> members · <?=htmlspecialchars($activeGroup['course_code']??'')?></p>
          <a href="?join=<?=$activeGid?>" class="btn-primary">Join This Group</a>
        </div>
      </div>
    <?php else: ?>
      <div class="chat-panel">
        <div class="no-group">
          <div class="big-icon">👥</div>
          <strong>Select a group to start chatting</strong>
          <p>Or create a new study group to collaborate with classmates.</p>
          <button class="btn-primary" onclick="openModal()">+ Create Group</button>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- CREATE GROUP MODAL -->
<div class="modal-overlay" id="createModal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h3>Create Study Group</h3>
    <form method="POST" action="study-groups.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_group">
      <div class="fg"><label>Group Name *</label><input type="text" name="name" placeholder="e.g. COMP3001 Study Squad" required></div>
      <div class="fg"><label>Course Code</label><input type="text" name="course_code" placeholder="e.g. COMP3001"></div>
      <div class="fg"><label>Description (optional)</label><textarea name="description" placeholder="What will this group focus on?"></textarea></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-primary">Create Group</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openModal(){document.getElementById('createModal').classList.add('open');}
  function closeModal(){document.getElementById('createModal').classList.remove('open');}

  // Auto-scroll chat to bottom
  const chat = document.getElementById('chatMessages');
  if(chat) chat.scrollTop = chat.scrollHeight;

  // Filter groups by name
  function filterGroups(q){
    document.querySelectorAll('.group-card, .discover-card').forEach(card=>{
      const name = card.querySelector('.gc-name, h4')?.textContent.toLowerCase()||'';
      card.style.display = name.includes(q.toLowerCase()) ? '' : 'none';
    });
  }

  <?php if(isset($_GET['new'])): ?>
  document.addEventListener('DOMContentLoaded', openModal);
  <?php endif; ?>
</script>
</body>
</html>
