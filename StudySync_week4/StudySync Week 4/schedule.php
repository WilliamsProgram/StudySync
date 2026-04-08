<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
}

requireLogin();
$uid  = (int) $_SESSION['user_id'];
$user = getUser($conn);
$init = initials($user['first_name'], $user['last_name']);
$scheduleError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_event') {
    $title = cleanText($_POST['title'] ?? '', 160);
    $cid = cleanInt($_POST['course_id'] ?? 0, 0);
    $loc = cleanText($_POST['location'] ?? '', 120);
    $type = cleanEnum($_POST['event_type'] ?? 'class', ['class', 'study', 'exam', 'meeting', 'other'], 'class');
    $start = normalizeDateTimeInput($_POST['start_time'] ?? '');
    $end = normalizeDateTimeInput($_POST['end_time'] ?? '');
    $color = normalizeHexColor($_POST['color'] ?? '#4f8ef7');

    if ($title !== '' && validDateTimeRange($start, $end) && userOwnsCourse($conn, $uid, $cid)) {
        $stmt = $conn->prepare(
            'INSERT INTO schedule_events (user_id, title, course_id, location, event_type, start_time, end_time, color)
             VALUES (?, ?, NULLIF(?,0), ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isisssss', $uid, $title, $cid, $loc, $type, $start, $end, $color);
        $stmt->execute();
        $stmt->close();
        redirectTo('schedule.php');
    }
    $scheduleError = 'Please enter a title, valid course, and valid time range.';
}

if (isset($_GET['delete'])) {
    $id = cleanInt($_GET['delete'] ?? 0, 1);
    if ($id > 0) {
        $stmt = $conn->prepare('DELETE FROM schedule_events WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $stmt->close();
    }
    redirectTo('schedule.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_event') {
    $id = cleanInt($_POST['event_id'] ?? 0, 1);
    $title = cleanText($_POST['title'] ?? '', 160);
    $cid = cleanInt($_POST['course_id'] ?? 0, 0);
    $loc = cleanText($_POST['location'] ?? '', 120);
    $type = cleanEnum($_POST['event_type'] ?? 'class', ['class', 'study', 'exam', 'meeting', 'other'], 'class');
    $start = normalizeDateTimeInput($_POST['start_time'] ?? '');
    $end = normalizeDateTimeInput($_POST['end_time'] ?? '');
    $color = normalizeHexColor($_POST['color'] ?? '#4f8ef7');

    if ($id > 0 && $title !== '' && validDateTimeRange($start, $end)) {
        $stmt = $conn->prepare(
            'UPDATE schedule_events
             SET title = ?, course_id = NULLIF(?,0), location = ?, event_type = ?, start_time = ?, end_time = ?, color = ?
             WHERE id = ? AND user_id = ?'
        );
        $stmt->bind_param('sisssssii', $title, $cid, $loc, $type, $start, $end, $color, $id, $uid);
        $stmt->execute();
        $stmt->close();
        redirectTo('schedule.php');
    }
    $scheduleError = 'Please enter a title, valid course, and valid time range.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_timetable') {
    $generated = createSmartTimetable($conn, $uid);
    redirectTo('schedule.php?week=0&generated=' . cleanInt($generated, 0));
}

$weekOffset = cleanInt($_GET['week'] ?? 0, -52, 52);
$monday = new DateTime();
$monday->setISODate((int) $monday->format('Y'), (int) $monday->format('W'));
$monday->modify(($weekOffset >= 0 ? '+' : '') . $weekOffset . ' week');
$weekStart = clone $monday;
$weekEnd = clone $monday;
$weekEnd->modify('+6 days');
$ws = $weekStart->format('Y-m-d');
$we = $weekEnd->format('Y-m-d');

$stmt = $conn->prepare(
    'SELECT e.*, c.course_code
     FROM schedule_events e
     LEFT JOIN courses c ON e.course_id = c.id
     WHERE e.user_id = ?
       AND DATE(e.start_time) BETWEEN ? AND ?
     ORDER BY e.start_time ASC'
);
$stmt->bind_param('iss', $uid, $ws, $we);
$eventRows = fetchAllRows($stmt);

$eventsByDay = [0 => [], 1 => [], 2 => [], 3 => [], 4 => []];
foreach ($eventRows as $ev) {
    $dow = (int) date('N', strtotime($ev['start_time'])) - 1;
    if ($dow >= 0 && $dow <= 4) {
        $eventsByDay[$dow][] = $ev;
    }
}

$days = [];
for ($i = 0; $i < 5; $i++) {
    $d = clone $weekStart;
    $d->modify('+' . $i . ' days');
    $days[$i] = $d;
}

$courses = $conn->query("SELECT * FROM courses WHERE user_id = $uid ORDER BY course_code");
$urgent = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id = $uid AND status = 'pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];
$today = date('Y-m-d');

$editEv = null;
if (isset($_GET['edit'])) {
    $eid = cleanInt($_GET['edit'] ?? 0, 1);
    if ($eid > 0) {
        $stmt = $conn->prepare('SELECT * FROM schedule_events WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $eid, $uid);
        $editEv = fetchSingleRow($stmt);
    }
}

$hours = range(7, 19);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Schedule — StudySync</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-schedule">

<aside class="sidebar">
  <a class="sidebar-logo" href="dashboard.php" aria-label="Go to dashboard"><img src="assets/Studysync.png" alt="StudySync logo"></a>
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
      <button class="btn-secondary" onclick="openModal('add')">+ Add Event</button>
    </div>
  </div>

  <!-- WEEK NAV -->
  <div class="week-nav">
    <a class="week-arrow" href="?week=<?=$weekOffset-1?>">←</a>
    <div class="week-label">
      <?= $weekStart->format('M j') ?> – <?= $weekEnd->format('M j, Y') ?>
      <?= $weekOffset===0 ? ' <span style="font-size:0.75rem;color:var(--accent);margin-left:0.5rem">(This Week)</span>' : '' ?>
    </div>
    <a class="week-arrow" href="?week=<?=$weekOffset+1?>">→</a>
  </div>

  <!-- TIMETABLE -->
  <div class="timetable">
    <!-- Header row -->
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

    <!-- Hour rows -->
    <?php foreach($hours as $h):
      $timeLabel = date('g A', mktime($h,0,0));
    ?>
    <div class="t-row">
      <div class="time-col"><?=$timeLabel?></div>
      <?php for($d=0; $d<5; $d++):
        // Find events that START in this hour slot for this day
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

  <!-- UPCOMING DEADLINES REMINDER -->
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
      <button class="btn-secondary" style="font-size:0.8rem">Dismiss</button>
      <button class="btn-primary" style="font-size:0.8rem" onclick="openModal('add')">Add Study Block ✓</button>
    </div>
  </div>
  <?php endif; ?>
</main>

<!-- ADD EVENT MODAL -->
<div class="modal-overlay" id="addModal" onclick="if(event.target===this)closeModal('add')">
  <div class="modal">
    <h3>Add Event</h3>
    <form method="POST" action="schedule.php?week=<?=$weekOffset?>
      <?= csrfField() ?>">
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

<!-- EDIT EVENT MODAL -->
<?php if($editEv): ?>
<div class="modal-overlay open" id="editModal" onclick="if(event.target===this)window.location='schedule.php?week=<?=$weekOffset?>'">
  <div class="modal">
    <h3>Edit Event</h3>
    <form method="POST" action="schedule.php?week=<?=$weekOffset?>
      <?= csrfField() ?>">
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
