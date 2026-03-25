<?php
require_once 'config.php';
requireLogin();
$uid  = $_SESSION['user_id'];
$user = getUser($conn);
$init = initials($user['first_name'], $user['last_name']);

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_event') {
    $title   = trim($_POST['title']      ?? '');
    $cid     = intval($_POST['course_id'] ?? 0);
    $loc     = trim($_POST['location']   ?? '');
    $type    = $_POST['event_type']      ?? 'class';
    $start   = normalizeDateTimeInput($_POST['start_time'] ?? '');
    $end     = normalizeDateTimeInput($_POST['end_time'] ?? '');
    $color   = $_POST['color']           ?? '#4f8ef7';

    if ($title && $start && $end) {
        $stmt = $conn->prepare(
            "INSERT INTO schedule_events (user_id,title,course_id,location,event_type,start_time,end_time,color)
             VALUES (?, ?, NULLIF(?,0), ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("isisssss", $uid,$title,$cid,$loc,$type,$start,$end,$color);
        $stmt->execute();
    }
    header("Location: schedule.php");
    exit();
}

 
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM schedule_events WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $stmt->close();
    header("Location: schedule.php");
    exit();
}

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_event') {
    $id    = intval($_POST['event_id']);
    $title = trim($_POST['title']      ?? '');
    $cid   = intval($_POST['course_id'] ?? 0);
    $loc   = trim($_POST['location']   ?? '');
    $type  = $_POST['event_type']      ?? 'class';
    $start = normalizeDateTimeInput($_POST['start_time'] ?? '');
    $end   = normalizeDateTimeInput($_POST['end_time'] ?? '');
    $color = $_POST['color']           ?? '#4f8ef7';

    if ($title && $start && $end) {
        $stmt = $conn->prepare(
            "UPDATE schedule_events SET title=?, course_id=NULLIF(?,0), location=?, event_type=?, start_time=?, end_time=?, color=?
             WHERE id=? AND user_id=?"
        );
        $stmt->bind_param("sisssssii", $title,$cid,$loc,$type,$start,$end,$color,$id,$uid);
        $stmt->execute();
    }
    header("Location: schedule.php");
    exit();
}

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_timetable') {
    $generated = createSmartTimetable($conn, $uid);
    header("Location: schedule.php?week=0&generated=$generated");
    exit();
}

 
 
$weekOffset = intval($_GET['week'] ?? 0);
$monday = new DateTime();
$monday->setISODate($monday->format('Y'), $monday->format('W'));
$monday->modify("+{$weekOffset} week");
$weekStart = clone $monday;
$weekEnd   = clone $monday;
$weekEnd->modify('+6 days');

$ws = $weekStart->format('Y-m-d');
$we = $weekEnd->format('Y-m-d');

$events_raw = $conn->query(
    "SELECT e.*, c.course_code
     FROM schedule_events e
     LEFT JOIN courses c ON e.course_id = c.id
     WHERE e.user_id = $uid
       AND DATE(e.start_time) BETWEEN '$ws' AND '$we'
     ORDER BY e.start_time ASC"
);

 
$eventsByDay = [0=>[], 1=>[], 2=>[], 3=>[], 4=>[]];
while ($ev = $events_raw->fetch_assoc()) {
    $dow = (int)date('N', strtotime($ev['start_time'])) - 1;  
    if ($dow >= 0 && $dow <= 4) $eventsByDay[$dow][] = $ev;
}

 
$days = [];
for ($i = 0; $i < 5; $i++) {
    $d = clone $weekStart;
    $d->modify("+{$i} days");
    $days[$i] = $d;
}

$courses  = $conn->query("SELECT * FROM courses WHERE user_id=$uid ORDER BY course_code");
$urgent   = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id=$uid AND status='pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];
$today    = date('Y-m-d');

 
$editEv = null;
if (isset($_GET['edit'])) {
    $eid    = intval($_GET['edit']);
    $editEv = $conn->query("SELECT * FROM schedule_events WHERE id=$eid AND user_id=$uid")->fetch_assoc();
}

 
$hours = range(7, 19);  
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Schedule — StudySync</title>
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
  .logout-link{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1.5rem;text-decoration:none;color:var(--muted);font-size:0.9rem;font-weight:500;transition:all .2s;margin-top:0.5rem;}
  .logout-link:hover{color:#f77a4f;}
  
  .main{margin-left:240px;flex:1;padding:2.5rem;overflow-x:auto;}
  .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;}
  .page-header h1{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;}
  .header-actions{display:flex;gap:0.75rem;}
  .btn-primary{padding:0.6rem 1.3rem;border-radius:8px;background:linear-gradient(135deg,var(--accent),#3a6fd4);color:#fff;font-size:0.875rem;font-weight:600;border:none;cursor:pointer;transition:all .2s;box-shadow:0 0 16px var(--glow);text-decoration:none;display:inline-block;}
  .btn-primary:hover{transform:translateY(-1px);}
  .btn-secondary{padding:0.6rem 1.3rem;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--text);font-size:0.875rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block;}
  .btn-secondary:hover{border-color:var(--accent);}
  .success-msg{background:rgba(56,217,169,0.1);border:1px solid rgba(56,217,169,0.3);border-radius:8px;padding:0.75rem 1rem;font-size:0.875rem;color:var(--accent2);margin-bottom:1rem;}
  
  .week-nav{display:flex;align-items:center;justify-content:space-between;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:0.9rem 1.5rem;margin-bottom:1.25rem;}
  .week-arrow{background:var(--surface2);border:1px solid var(--border);border-radius:6px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;color:var(--text);font-size:0.9rem;transition:all .2s;}
  .week-arrow:hover{border-color:var(--accent);}
  .week-label{font-family:'Syne',sans-serif;font-weight:700;font-size:1rem;}
  
  .timetable{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;min-width:700px;}
  .t-row{display:grid;grid-template-columns:56px repeat(5,1fr);}
  .t-header-row{background:var(--surface2);border-bottom:1px solid var(--border);}
  .t-header{padding:0.75rem 0.5rem;text-align:center;font-size:0.78rem;font-weight:600;border-right:1px solid var(--border);}
  .t-header:last-child{border-right:none;}
  .t-header.day-col{display:flex;flex-direction:column;align-items:center;gap:0.15rem;}
  .t-header .day-num{font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800;}
  .t-header .day-name{font-size:0.68rem;color:var(--muted);}
  .t-header.today-col .day-num{color:var(--accent);}
  .t-header.today-col .day-name{color:var(--accent);opacity:0.7;}
  .time-col{padding:0.5rem 0.35rem 0;font-size:0.68rem;color:var(--muted);text-align:right;border-right:1px solid var(--border);min-height:64px;border-bottom:1px solid var(--border);}
  .day-cell{border-right:1px solid var(--border);border-bottom:1px solid var(--border);min-height:64px;padding:3px;position:relative;}
  .day-cell:last-child{border-right:none;}
  .event-block{border-radius:7px;padding:0.35rem 0.5rem;font-size:0.75rem;cursor:pointer;transition:all .2s;border-left:3px solid;margin-bottom:2px;position:relative;}
  .event-block:hover{filter:brightness(1.2);}
  .event-block strong{display:block;font-size:0.78rem;line-height:1.3;}
  .event-block span{font-size:0.68rem;opacity:0.8;}
  .event-del{position:absolute;top:3px;right:4px;background:none;border:none;color:rgba(255,255,255,0.5);cursor:pointer;font-size:0.8rem;line-height:1;padding:0;}
  .event-del:hover{color:#fff;}
  
  .ai-box{background:linear-gradient(135deg,rgba(79,142,247,0.1),rgba(56,217,169,0.06));border:1px solid rgba(79,142,247,0.25);border-radius:14px;padding:1.25rem 1.5rem;margin-top:1.5rem;display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;}
  .ai-text h3{font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;margin-bottom:0.2rem;}
  .ai-text p{font-size:0.82rem;color:var(--muted);}
  
  .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:200;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);opacity:0;pointer-events:none;transition:opacity .2s;}
  .modal-overlay.open{opacity:1;pointer-events:all;}
  .modal{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:2rem;width:100%;max-width:520px;transform:scale(0.95);transition:transform .2s;max-height:90vh;overflow-y:auto;}
  .modal-overlay.open .modal{transform:scale(1);}
  .modal h3{font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;margin-bottom:1.5rem;}
  .fg{margin-bottom:1rem;}
  .fg label{display:block;font-size:0.82rem;color:var(--muted);margin-bottom:0.4rem;}
  .fg input,.fg select,.fg textarea{width:100%;padding:0.75rem 1rem;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--text);font-family:'DM Sans',sans-serif;font-size:0.9rem;outline:none;transition:all .2s;}
  .fg input:focus,.fg select:focus{border-color:var(--accent);}
  .fg-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
  .modal-actions{display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;}
  .btn-cancel{padding:0.6rem 1.3rem;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:0.875rem;cursor:pointer;}
  .empty-cell{display:flex;align-items:center;justify-content:center;height:100%;opacity:0;}
  .day-cell:hover .empty-cell{opacity:1;}
  .add-slot{font-size:1.2rem;color:var(--accent);cursor:pointer;padding:0.5rem;border-radius:6px;transition:all .2s;background:none;border:none;width:100%;}
  .add-slot:hover{background:rgba(79,142,247,0.1);}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">StudySync</div>
  <div class="sidebar-section">Main</div>
  <a class="sidebar-item" href="dashboard.php"><span class="icon">🏠</span> Dashboard</a>
  <a class="sidebar-item" href="assignments.php"><span class="icon">📚</span> Assignments <?php if($urgent>0): ?><span class="sidebar-badge"><?=$urgent?></span><?php endif; ?></a>
  <a class="sidebar-item active" href="schedule.php"><span class="icon">🗓️</span> Schedule</a>
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
      <div class="avatar"><?=$init?></div>
      <div class="user-info"><strong><?=htmlspecialchars($user['first_name'].' '.$user['last_name'])?></strong><span><?=htmlspecialchars($user['year']??'Student')?></span></div>
    </div>
  </div>
</aside>

<main class="main">
  <div class="page-header">
    <h1>Weekly Schedule 🗓️</h1>
    <div class="header-actions">
      <form method="POST" action="schedule.php?week=<?=$weekOffset?>" style="display:inline">
        <input type="hidden" name="action" value="generate_timetable">
        <button type="submit" class="btn-primary">⚡ Generate Smart Study Plan</button>
      </form>
      <button class="btn-secondary" onclick="openModal('add')">+ Add Event</button>
    </div>
  </div>

  <?php if(isset($_GET['generated'])): ?>
    <div class="success-msg">✅ Smart timetable updated. <?=intval($_GET['generated'])?> study block(s) were added.</div>
  <?php endif; ?>

  
  <div class="week-nav">
    <a class="week-arrow" href="?week=<?=$weekOffset-1?>">←</a>
    <div class="week-label">
      <?= $weekStart->format('M j') ?> – <?= $weekEnd->format('M j, Y') ?>
      <?= $weekOffset===0 ? ' <span style="font-size:0.75rem;color:var(--accent);margin-left:0.5rem">(This Week)</span>' : '' ?>
    </div>
    <a class="week-arrow" href="?week=<?=$weekOffset+1?>">→</a>
  </div>

  
  <div class="timetable">
    
    <div class="t-row t-header-row">
      <div class="t-header"></div>
      <?php foreach($days as $i => $day):
        $isToday = $day->format('Y-m-d') === $today;
      ?>
      <div class="t-header day-col <?= $isToday?'today-col':'' ?>">
        <span class="day-num"><?= $day->format('j') ?></span>
        <span class="day-name"><?= strtoupper($day->format('D')) ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    
    <?php foreach($hours as $h):
      $timeLabel = date('g A', mktime($h,0,0));
    ?>
    <div class="t-row">
      <div class="time-col"><?=$timeLabel?></div>
      <?php for($d=0; $d<5; $d++):
         
        $slotEvents = array_filter($eventsByDay[$d], function($ev) use ($h) {
            return (int)date('H', strtotime($ev['start_time'])) === $h;
        });
      ?>
      <div class="day-cell" onclick="openAddForSlot('<?=$days[$d]->format('Y-m-d')?>', <?=$h?>)">
        <?php if(empty($slotEvents)): ?>
          <div class="empty-cell"><button class="add-slot" onclick="event.stopPropagation();openAddForSlot('<?=$days[$d]->format('Y-m-d')?>', <?=$h?>)">+</button></div>
        <?php endif; ?>
        <?php foreach($slotEvents as $ev): ?>
        <div class="event-block"
             style="background:<?=htmlspecialchars($ev['color'])?>22;border-color:<?=htmlspecialchars($ev['color'])?>"
             onclick="event.stopPropagation()">
          <button class="event-del" onclick="if(confirm('Delete this event?'))window.location='?delete=<?=$ev['id']?>&week=<?=$weekOffset?>'"  title="Delete">✕</button>
          <strong><?=htmlspecialchars($ev['title'])?></strong>
          <span>
            <?= date('g:i', strtotime($ev['start_time'])) ?>–<?= date('g:i A', strtotime($ev['end_time'])) ?>
            <?= $ev['location'] ? ' · '.$ev['location'] : '' ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endfor; ?>
    </div>
    <?php endforeach; ?>
  </div>

  
  <?php
  $upcoming = $conn->query(
      "SELECT a.title, a.due_date, c.course_code
       FROM assignments a LEFT JOIN courses c ON a.course_id=c.id
       WHERE a.user_id=$uid AND a.status='pending'
         AND a.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)
       ORDER BY a.due_date ASC LIMIT 1"
  )->fetch_assoc();
  if($upcoming):
  ?>
  <div class="ai-box">
    <div style="font-size:2rem">🤖</div>
    <div class="ai-text">
      <h3>Deadline Reminder</h3>
      <p><strong><?=htmlspecialchars($upcoming['title'])?></strong>
         <?= $upcoming['course_code'] ? '('.$upcoming['course_code'].')' : '' ?>
         is due <?= date('M j \a\t g:i A', strtotime($upcoming['due_date'])) ?>.
         Want to add a study block to prepare?
      </p>
    </div>
    <div style="margin-left:auto;display:flex;gap:0.75rem;flex-wrap:wrap;">
      <a class="btn-secondary" style="font-size:0.8rem" href="assignments.php?filter=urgent">View Urgent Tasks</a>
      <form method="POST" action="schedule.php?week=<?=$weekOffset?>" style="display:inline">
        <input type="hidden" name="action" value="generate_timetable">
        <button class="btn-primary" style="font-size:0.8rem" type="submit">Generate Study Blocks ✓</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</main>


<div class="modal-overlay" id="addModal" onclick="if(event.target===this)closeModal('add')">
  <div class="modal">
    <h3>Add Event</h3>
    <form method="POST" action="schedule.php?week=<?=$weekOffset?>">
      <input type="hidden" name="action" value="add_event">
      <div class="fg"><label>Event Title *</label><input type="text" name="title" id="add_title" placeholder="e.g. COMP3001 Lecture" required></div>
      <div class="fg-row">
        <div class="fg"><label>Event Type</label>
          <select name="event_type">
            <option value="class">📚 Class</option>
            <option value="study">📖 Study Session</option>
            <option value="group">👥 Group Session</option>
            <option value="personal">🎯 Personal</option>
          </select>
        </div>
        <div class="fg"><label>Course (optional)</label>
          <select name="course_id">
            <option value="0">No course</option>
            <?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?>
              <option value="<?=$c['id']?>"><?=htmlspecialchars($c['course_code'].' — '.$c['course_name'])?></option>
            <?php endwhile; ?>
          </select>
        </div>
      </div>
      <div class="fg"><label>Location</label><input type="text" name="location" placeholder="e.g. Room 204, Library 3rd Floor"></div>
      <div class="fg-row">
        <div class="fg"><label>Start Time *</label><input type="datetime-local" name="start_time" id="add_start" required></div>
        <div class="fg"><label>End Time *</label><input type="datetime-local" name="end_time" id="add_end" required></div>
      </div>
      <div class="fg"><label>Colour</label><input type="color" name="color" value="#4f8ef7" style="height:42px;padding:0.3rem;"></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('add')">Cancel</button>
        <button type="submit" class="btn-primary">Add Event</button>
      </div>
    </form>
  </div>
</div>


<?php if($editEv): ?>
<div class="modal-overlay open" id="editModal" onclick="if(event.target===this)window.location='schedule.php?week=<?=$weekOffset?>'">
  <div class="modal">
    <h3>Edit Event</h3>
    <form method="POST" action="schedule.php?week=<?=$weekOffset?>">
      <input type="hidden" name="action" value="edit_event">
      <input type="hidden" name="event_id" value="<?=$editEv['id']?>">
      <div class="fg"><label>Event Title *</label><input type="text" name="title" value="<?=htmlspecialchars($editEv['title'])?>" required></div>
      <div class="fg-row">
        <div class="fg"><label>Event Type</label>
          <select name="event_type">
            <?php foreach(['class'=>'📚 Class','study'=>'📖 Study Session','group'=>'👥 Group Session','personal'=>'🎯 Personal'] as $v=>$l): ?>
              <option value="<?=$v?>" <?=$editEv['event_type']===$v?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>Course</label>
          <select name="course_id">
            <option value="0">No course</option>
            <?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?>
              <option value="<?=$c['id']?>" <?=$editEv['course_id']==$c['id']?'selected':''?>><?=htmlspecialchars($c['course_code'])?></option>
            <?php endwhile; ?>
          </select>
        </div>
      </div>
      <div class="fg"><label>Location</label><input type="text" name="location" value="<?=htmlspecialchars($editEv['location']??'')?>"></div>
      <div class="fg-row">
        <div class="fg"><label>Start Time *</label><input type="datetime-local" name="start_time" value="<?=date('Y-m-d\TH:i',strtotime($editEv['start_time']))?>" required></div>
        <div class="fg"><label>End Time *</label><input type="datetime-local" name="end_time" value="<?=date('Y-m-d\TH:i',strtotime($editEv['end_time']))?>" required></div>
      </div>
      <div class="fg"><label>Colour</label><input type="color" name="color" value="<?=htmlspecialchars($editEv['color'])?>" style="height:42px;padding:0.3rem;"></div>
      <div class="modal-actions">
        <a href="schedule.php?week=<?=$weekOffset?>" class="btn-cancel">Cancel</a>
        <button type="submit" class="btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
  function openModal(t){document.getElementById(t+'Modal').classList.add('open');}
  function closeModal(t){document.getElementById(t+'Modal').classList.remove('open');}

  function openAddForSlot(date, hour) {
    openModal('add');
    const pad = n => String(n).padStart(2,'0');
    document.getElementById('add_start').value = `${date}T${pad(hour)}:00`;
    document.getElementById('add_end').value   = `${date}T${pad(hour+1)}:00`;
  }

  <?php if(isset($_GET['new'])): ?>
  document.addEventListener('DOMContentLoaded', () => openModal('add'));
  <?php endif; ?>
</script>
</body>
</html>
