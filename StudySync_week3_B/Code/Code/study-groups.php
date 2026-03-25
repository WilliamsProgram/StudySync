<?php
require_once 'config.php';
requireLogin();
$uid  = $_SESSION['user_id'];
$user = getUser($conn);
$init = initials($user['first_name'], $user['last_name']);

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'create_group') {
    $name   = trim($_POST['name'] ?? '');
    $code   = trim($_POST['course_code'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    if ($name) {
        $stmt = $conn->prepare("INSERT INTO study_groups (name,course_code,description,created_by) VALUES (?,?,?,?)");
        $stmt->bind_param("sssi", $name, $code, $desc, $uid);
        $stmt->execute();
        $gid = $conn->insert_id;
        $stmt->close();

        $joinStmt = $conn->prepare("INSERT INTO group_members (group_id,user_id) VALUES (?,?)");
        $joinStmt->bind_param("ii", $gid, $uid);
        $joinStmt->execute();
        $joinStmt->close();

        createNotification($conn, $uid, 'Study group created: ' . $name . '.', 'group', 'group_created_' . $gid, 'group', $gid);
        header("Location: study-groups.php?g=$gid");
        exit();
    }
}

 
if (isset($_GET['join'])) {
    $gid = intval($_GET['join']);

    $existsStmt = $conn->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=?");
    $existsStmt->bind_param("ii", $gid, $uid);
    $existsStmt->execute();
    $exists = $existsStmt->get_result()->num_rows;
    $existsStmt->close();

    if (!$exists) {
        $joinStmt = $conn->prepare("INSERT INTO group_members (group_id,user_id) VALUES (?,?)");
        $joinStmt->bind_param("ii", $gid, $uid);
        $joinStmt->execute();
        $joinStmt->close();

        $groupStmt = $conn->prepare("SELECT name, created_by FROM study_groups WHERE id=?");
        $groupStmt->bind_param("i", $gid);
        $groupStmt->execute();
        $group = $groupStmt->get_result()->fetch_assoc();
        $groupStmt->close();

        if ($group) {
            createNotification($conn, $uid, 'You joined the study group ' . $group['name'] . '.', 'group', 'group_join_' . $gid . '_' . $uid, 'group', $gid);
            if ((int) $group['created_by'] !== $uid) {
                createNotification($conn, (int) $group['created_by'], $user['first_name'] . ' ' . $user['last_name'] . ' joined ' . $group['name'] . '.', 'group', 'group_member_join_' . $gid . '_' . $uid, 'group', $gid);
            }
        }
    }

    header("Location: study-groups.php?g=$gid");
    exit();
}

 
if (isset($_GET['leave'])) {
    $gid = intval($_GET['leave']);
    $leaveStmt = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=?");
    $leaveStmt->bind_param("ii", $gid, $uid);
    $leaveStmt->execute();
    $leaveStmt->close();

    header("Location: study-groups.php");
    exit();
}

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'send_message') {
    $gid = intval($_POST['group_id']);
    $msg = trim($_POST['message'] ?? '');

    $memberStmt = $conn->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=?");
    $memberStmt->bind_param("ii", $gid, $uid);
    $memberStmt->execute();
    $isMember = $memberStmt->get_result()->num_rows;
    $memberStmt->close();

    if ($msg && $isMember) {
        $stmt = $conn->prepare("INSERT INTO group_messages (group_id,user_id,message) VALUES (?,?,?)");
        $stmt->bind_param("iis", $gid, $uid, $msg);
        $stmt->execute();
        $messageId = $conn->insert_id;
        $stmt->close();

        $recipients = $conn->prepare("SELECT user_id FROM group_members WHERE group_id=? AND user_id<>?");
        $recipients->bind_param("ii", $gid, $uid);
        $recipients->execute();
        $res = $recipients->get_result();
        while ($row = $res->fetch_assoc()) {
            createNotification($conn, (int) $row['user_id'], 'New study group message from ' . $user['first_name'] . ' ' . $user['last_name'] . '.', 'group', 'group_message_' . $messageId . '_u' . $row['user_id'], 'group', $gid);
        }
        $recipients->close();
    }

    header("Location: study-groups.php?g=$gid");
    exit();
}

 
$activeGid = intval($_GET['g'] ?? 0);
$tab       = $_GET['tab'] ?? 'my';    

 
$myGroups = $conn->query(
    "SELECT sg.*, COUNT(gm2.id) AS member_count
     FROM study_groups sg
     JOIN group_members gm ON sg.id=gm.group_id AND gm.user_id=$uid
     LEFT JOIN group_members gm2 ON sg.id=gm2.group_id
     GROUP BY sg.id
     ORDER BY sg.created_at DESC"
);
$myGroupRows = [];
while ($g = $myGroups->fetch_assoc()) $myGroupRows[] = $g;

 
$discoverGroups = $conn->query(
    "SELECT sg.*, COUNT(gm.id) AS member_count,
            u.first_name, u.last_name
     FROM study_groups sg
     LEFT JOIN group_members gm ON sg.id=gm.group_id
     LEFT JOIN users u ON sg.created_by=u.id
     WHERE sg.id NOT IN (
         SELECT group_id FROM group_members WHERE user_id=$uid
     )
     GROUP BY sg.id
     ORDER BY member_count DESC, sg.created_at DESC"
);
$discoverRows = [];
while ($g = $discoverGroups->fetch_assoc()) $discoverRows[] = $g;

 
if (!$activeGid && count($myGroupRows) > 0) {
    $activeGid = $myGroupRows[0]['id'];
}

 
$activeGroup = null;
$messages    = [];
$members     = [];
$isMember    = false;

if ($activeGid) {
    $activeGroup = $conn->query("SELECT sg.*, u.first_name, u.last_name FROM study_groups sg JOIN users u ON sg.created_by=u.id WHERE sg.id=$activeGid")->fetch_assoc();
    if ($activeGroup) {
        $isMember = $conn->query("SELECT id FROM group_members WHERE group_id=$activeGid AND user_id=$uid")->num_rows > 0;

         
        $msgRes = $conn->query(
            "SELECT m.*, u.first_name, u.last_name
             FROM group_messages m JOIN users u ON m.user_id=u.id
             WHERE m.group_id=$activeGid
             ORDER BY m.sent_at ASC
             LIMIT 50"
        );
        while ($m = $msgRes->fetch_assoc()) $messages[] = $m;

         
        $memRes = $conn->query(
            "SELECT u.id, u.first_name, u.last_name, u.year
             FROM group_members gm JOIN users u ON gm.user_id=u.id
             WHERE gm.group_id=$activeGid"
        );
        while ($m = $memRes->fetch_assoc()) $members[] = $m;

         
    }
}

$urgent  = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id=$uid AND status='pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];

 
$iconMap = ['COMP'=>'💻','MATH'=>'📐','ENGL'=>'📝','BIOL'=>'🔬','HIST'=>'📜','PHYS'=>'⚛️','CHEM'=>'🧪','NURS'=>'🏥'];
function groupIcon($code) {
    global $iconMap;
    foreach ($iconMap as $prefix => $icon) {
        if (stripos($code ?? '', $prefix) === 0) return $icon;
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
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<style>
  :root{--bg:#0b0f1a;--surface:#111827;--surface2:#1a2235;--surface3:#202c40;--accent:#4f8ef7;--accent2:#38d9a9;--accent3:#f7934f;--text:#e8edf7;--muted:#7a8ba8;--border:rgba(79,142,247,0.15);--glow:rgba(79,142,247,0.2);--red:#f77a4f;}
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
  .logout-link{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1.5rem;text-decoration:none;color:var(--muted);font-size:0.9rem;font-weight:500;transition:all .2s;margin-top:0.5rem;}
  .logout-link:hover{color:#f77a4f;}

  
  .main{margin-left:240px;flex:1;display:flex;flex-direction:column;height:100vh;overflow:hidden;}
  .page-top{padding:1.5rem 2rem 1rem;border-bottom:1px solid var(--border);flex-shrink:0;}
  .page-top-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;}
  .page-top h1{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;}
  .btn-primary{padding:0.55rem 1.2rem;border-radius:8px;background:linear-gradient(135deg,var(--accent),#3a6fd4);color:#fff;font-size:0.875rem;font-weight:600;border:none;cursor:pointer;transition:all .2s;box-shadow:0 0 16px var(--glow);text-decoration:none;}
  .btn-primary:hover{transform:translateY(-1px);}
  .tab-row{display:flex;gap:0.5rem;align-items:center;}
  .tab-btn{padding:0.4rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);font-size:0.82rem;cursor:pointer;transition:all .2s;text-decoration:none;}
  .tab-btn.active{background:rgba(79,142,247,0.1);border-color:var(--accent);color:var(--accent);}
  .search-input{padding:0.45rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:0.82rem;outline:none;min-width:200px;margin-left:auto;transition:all .2s;}
  .search-input:focus{border-color:var(--accent);}
  .search-input::placeholder{color:var(--muted);}

  
  .content-area{flex:1;display:grid;grid-template-columns:320px 1fr;overflow:hidden;}

  
  .groups-list{border-right:1px solid var(--border);overflow-y:auto;padding:1rem;}
  .group-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1rem;margin-bottom:0.65rem;cursor:pointer;transition:all .2s;text-decoration:none;display:block;color:var(--text);}
  .group-card:hover,.group-card.active{border-color:rgba(79,142,247,0.35);background:var(--surface2);}
  .group-card.active{border-left:3px solid var(--accent);}
  .gc-top{display:flex;align-items:center;gap:0.75rem;margin-bottom:0.5rem;}
  .gc-icon{width:40px;height:40px;border-radius:10px;background:var(--surface2);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
  .gc-name{font-weight:600;font-size:0.9rem;}
  .gc-course{font-size:0.72rem;color:var(--muted);}
  .gc-meta{display:flex;gap:0.75rem;font-size:0.72rem;color:var(--muted);}
  .last-msg{font-size:0.75rem;color:var(--muted);margin-top:0.35rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .unread-badge{margin-left:auto;background:var(--accent);color:#fff;font-size:0.65rem;padding:0.1rem 0.45rem;border-radius:20px;font-weight:700;}

  
  .discover-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1rem;margin-bottom:0.65rem;}
  .discover-card h4{font-size:0.9rem;font-weight:600;margin-bottom:0.2rem;}
  .discover-card p{font-size:0.78rem;color:var(--muted);margin-bottom:0.75rem;}
  .join-btn{padding:0.4rem 1rem;border-radius:6px;background:rgba(79,142,247,0.1);border:1px solid rgba(79,142,247,0.3);color:var(--accent);font-size:0.8rem;cursor:pointer;text-decoration:none;display:inline-block;transition:all .2s;}
  .join-btn:hover{background:rgba(79,142,247,0.2);}
  .empty-state{text-align:center;padding:2rem 1rem;color:var(--muted);font-size:0.875rem;}

  
  .chat-panel{display:flex;flex-direction:column;overflow:hidden;}
  .chat-header{padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
  .chat-header-left{display:flex;align-items:center;gap:0.75rem;}
  .chat-group-icon{width:44px;height:44px;border-radius:12px;background:var(--surface2);display:flex;align-items:center;justify-content:center;font-size:1.3rem;}
  .chat-group-name{font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:700;}
  .chat-group-sub{font-size:0.75rem;color:var(--muted);}
  .chat-actions{display:flex;gap:0.5rem;}
  .icon-btn{width:34px;height:34px;border-radius:8px;background:var(--surface2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:0.9rem;transition:all .2s;text-decoration:none;}
  .icon-btn:hover{border-color:var(--accent);}

  .chat-messages{flex:1;overflow-y:auto;padding:1.5rem;display:flex;flex-direction:column;gap:1.1rem;}
  .msg{display:flex;gap:0.75rem;max-width:85%;}
  .msg.mine{flex-direction:row-reverse;align-self:flex-end;}
  .msg-av{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;flex-shrink:0;}
  .msg-sender{font-size:0.7rem;color:var(--muted);margin-bottom:0.25rem;}
  .msg.mine .msg-sender{text-align:right;}
  .msg-bubble{padding:0.65rem 0.9rem;border-radius:12px;font-size:0.875rem;line-height:1.55;word-break:break-word;}
  .msg .msg-bubble{background:var(--surface2);border-radius:4px 12px 12px 12px;}
  .msg.mine .msg-bubble{background:linear-gradient(135deg,rgba(79,142,247,0.25),rgba(58,111,212,0.15));border:1px solid rgba(79,142,247,0.2);border-radius:12px 4px 12px 12px;}
  .msg-time{font-size:0.65rem;color:var(--muted);margin-top:0.2rem;}
  .msg.mine .msg-time{text-align:right;}
  .system-msg{text-align:center;font-size:0.72rem;color:var(--muted);background:var(--surface2);border-radius:20px;padding:0.25rem 1rem;align-self:center;}
  .no-messages{text-align:center;color:var(--muted);font-size:0.875rem;margin:auto;}

  .chat-input-area{padding:1rem 1.5rem;border-top:1px solid var(--border);flex-shrink:0;}
  .chat-input-row{display:flex;gap:0.75rem;align-items:flex-end;}
  .chat-input{flex:1;padding:0.75rem 1rem;border-radius:10px;background:var(--surface);border:1px solid var(--border);color:var(--text);font-family:'DM Sans',sans-serif;font-size:0.9rem;outline:none;resize:none;transition:all .2s;max-height:100px;}
  .chat-input:focus{border-color:var(--accent);}
  .send-btn{padding:0.75rem 1.1rem;border-radius:10px;background:linear-gradient(135deg,var(--accent),#3a6fd4);color:#fff;border:none;cursor:pointer;font-size:1.1rem;transition:all .2s;flex-shrink:0;}
  .send-btn:hover{transform:translateY(-1px);}
  .members-bar{padding:0.5rem 1.5rem;border-top:1px solid var(--border);display:flex;gap:0.5rem;align-items:center;flex-shrink:0;background:var(--surface2);}
  .member-av{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:0.65rem;font-weight:700;flex-shrink:0;title-attr:'';}
  .members-label{font-size:0.75rem;color:var(--muted);margin-right:0.5rem;}
  .leave-link{margin-left:auto;font-size:0.75rem;color:var(--muted);text-decoration:none;transition:color .2s;}
  .leave-link:hover{color:var(--red);}

  
  .no-group{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:var(--muted);gap:1rem;}
  .no-group .big-icon{font-size:3rem;}

  
  .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:200;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);opacity:0;pointer-events:none;transition:opacity .2s;}
  .modal-overlay.open{opacity:1;pointer-events:all;}
  .modal{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:2rem;width:100%;max-width:480px;transform:scale(0.95);transition:transform .2s;}
  .modal-overlay.open .modal{transform:scale(1);}
  .modal h3{font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;margin-bottom:1.5rem;}
  .fg{margin-bottom:1rem;}
  .fg label{display:block;font-size:0.82rem;color:var(--muted);margin-bottom:0.4rem;}
  .fg input,.fg textarea{width:100%;padding:0.75rem 1rem;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--text);font-family:'DM Sans',sans-serif;font-size:0.9rem;outline:none;transition:all .2s;}
  .fg input:focus,.fg textarea:focus{border-color:var(--accent);}
  .fg textarea{resize:vertical;min-height:70px;}
  .modal-actions{display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;}
  .btn-cancel{padding:0.6rem 1.3rem;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:0.875rem;cursor:pointer;}
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

      <?php else:   ?>
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

      
      <div class="members-bar">
        <span class="members-label">Members:</span>
        <?php foreach($members as $mem):
          $mInit2 = initials($mem['first_name'], $mem['last_name']);
        ?>
          <div class="member-av" title="<?=htmlspecialchars($mem['first_name'].' '.$mem['last_name'])?>"><?=$mInit2?></div>
        <?php endforeach; ?>
      </div>

      
      <div class="chat-input-area">
        <form method="POST" action="study-groups.php" id="chatForm">
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


<div class="modal-overlay" id="createModal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h3>Create Study Group</h3>
    <form method="POST" action="study-groups.php">
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
