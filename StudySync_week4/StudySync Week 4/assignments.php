<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
}

requireLogin();
$user = getUser($conn);
$uid  = (int) $_SESSION['user_id'];
$init = initials($user['first_name'], $user['last_name']);
$assignmentError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $title    = cleanText($_POST['title'] ?? '', 160);
    $cid      = cleanInt($_POST['course_id'] ?? 0, 0);
    $due      = normalizeDateTimeInput($_POST['due_date'] ?? '');
    $weight   = cleanFloat($_POST['grade_weight'] ?? 0, 0, 100);
    $priority = cleanEnum($_POST['priority'] ?? 'medium', ['high', 'medium', 'low'], 'medium');
    $desc     = cleanMultilineText($_POST['description'] ?? '', 2000);

    if ($title === '' || $due === '') {
        $assignmentError = 'Title and due date are required.';
    } elseif (!userOwnsCourse($conn, $uid, $cid)) {
        $assignmentError = 'Please select a valid course.';
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO assignments (user_id, course_id, title, description, due_date, grade_weight, priority)
             VALUES (?, NULLIF(?,0), ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iisssds', $uid, $cid, $title, $desc, $due, $weight, $priority);
        $stmt->execute();
        $stmt->close();
        redirectTo('assignments.php');
    }
}

if (isset($_GET['delete'])) {
    $id = cleanInt($_GET['delete'] ?? 0, 1);
    if ($id > 0) {
        $stmt = $conn->prepare('DELETE FROM assignments WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $stmt->close();
    }
    redirectTo('assignments.php');
}

if (isset($_GET['toggle'])) {
    $id = cleanInt($_GET['toggle'] ?? 0, 1);
    if ($id > 0) {
        $stmt = $conn->prepare('SELECT status FROM assignments WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $uid);
        $row = fetchSingleRow($stmt);
        if ($row) {
            $newStatus = $row['status'] === 'completed' ? 'pending' : 'completed';
            $update = $conn->prepare('UPDATE assignments SET status = ? WHERE id = ? AND user_id = ?');
            $update->bind_param('sii', $newStatus, $id, $uid);
            $update->execute();
            $update->close();
        }
    }
    redirectTo('assignments.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id       = cleanInt($_POST['edit_id'] ?? 0, 1);
    $title    = cleanText($_POST['title'] ?? '', 160);
    $cid      = cleanInt($_POST['course_id'] ?? 0, 0);
    $due      = normalizeDateTimeInput($_POST['due_date'] ?? '');
    $weight   = cleanFloat($_POST['grade_weight'] ?? 0, 0, 100);
    $priority = cleanEnum($_POST['priority'] ?? 'medium', ['high', 'medium', 'low'], 'medium');
    $desc     = cleanMultilineText($_POST['description'] ?? '', 2000);

    if ($id > 0 && $title !== '' && $due !== '' && userOwnsCourse($conn, $uid, $cid)) {
        $stmt = $conn->prepare(
            'UPDATE assignments
             SET title = ?, course_id = NULLIF(?,0), due_date = ?, grade_weight = ?, priority = ?, description = ?
             WHERE id = ? AND user_id = ?'
        );
        $stmt->bind_param('sisdssii', $title, $cid, $due, $weight, $priority, $desc, $id, $uid);
        $stmt->execute();
        $stmt->close();
        redirectTo('assignments.php');
    }
    $assignmentError = 'Please provide a valid title, course, and due date.';
}

$filter = cleanEnum($_GET['filter'] ?? 'all', ['all', 'urgent', 'upcoming', 'done'], 'all');
$search = cleanSearchTerm($_GET['search'] ?? '', 100);

$sql = "SELECT a.*, c.course_code, c.color
        FROM assignments a
        LEFT JOIN courses c ON a.course_id = c.id
        WHERE a.user_id = ?";
$types = 'i';
$params = [$uid];

if ($filter === 'urgent') {
    $sql .= " AND a.due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY) AND a.status = 'pending'";
} elseif ($filter === 'upcoming') {
    $sql .= " AND a.due_date > DATE_ADD(NOW(), INTERVAL 3 DAY) AND a.status = 'pending'";
} elseif ($filter === 'done') {
    $sql .= " AND a.status = 'completed'";
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $sql .= ' AND (a.title LIKE ? OR c.course_code LIKE ?)';
    $types .= 'ss';
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY a.due_date ASC';
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$assignmentsRows = fetchAllRows($stmt);

$stmt = $conn->prepare('SELECT * FROM courses WHERE user_id = ? ORDER BY course_code');
$stmt->bind_param('i', $uid);
$courseRows = fetchAllRows($stmt);

$editData = null;
if (isset($_GET['edit'])) {
    $eid = cleanInt($_GET['edit'] ?? 0, 1);
    if ($eid > 0) {
        $stmt = $conn->prepare('SELECT * FROM assignments WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $eid, $uid);
        $editData = fetchSingleRow($stmt);
    }
}

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM assignments WHERE user_id = ? AND status = 'pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)");
$stmt->bind_param('i', $uid);
$urgent = (int) fetchScalar($stmt, 'c', 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assignments — StudySync</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-assignments">
<aside class="sidebar">
  <a class="sidebar-logo" href="dashboard.php" aria-label="Go to dashboard"><img src="assets/Studysync.png" alt="StudySync logo"></a>
  <div class="sidebar-section">Main</div>
  <a class="sidebar-item" href="dashboard.php"><span class="icon">🏠</span> Dashboard</a>
  <a class="sidebar-item active" href="assignments.php"><span class="icon">📚</span> Assignments <?php if($urgent>0): ?><span class="sidebar-badge"><?=$urgent?></span><?php endif; ?></a>
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
    <div class="user-mini"><div class="avatar"><?=$init?></div><div class="user-info"><strong><?=htmlspecialchars($user['first_name'].' '.$user['last_name'])?></strong><span><?=htmlspecialchars($user['year']??'Student')?></span></div></div>
  </div>
</aside>

<main class="main">
  <div class="page-header">
    <h1>Assignments</h1>
    <button class="btn-primary" onclick="openModal('add')">+ New Assignment</button>
  </div>

  <div class="filters">
    <a href="?filter=all"     class="filter-btn <?= $filter==='all'    ?'active':'' ?>">All</a>
    <a href="?filter=urgent"  class="filter-btn <?= $filter==='urgent' ?'active':'' ?>">🔴 Urgent</a>
    <a href="?filter=upcoming"class="filter-btn <?= $filter==='upcoming'?'active':'' ?>">🟡 Upcoming</a>
    <a href="?filter=done"    class="filter-btn <?= $filter==='done'   ?'active':'' ?>">✅ Completed</a>
    <form class="search-form" method="GET">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <input class="search-input" name="search" placeholder="🔍 Search..." value="<?= htmlspecialchars($search) ?>">
    </form>
  </div>

  <div class="table-wrap">
    <div class="table-head">
      <span>Assignment</span><span>Course</span><span>Due Date</span><span>Status</span><span>Actions</span>
    </div>
    <?php $count=0; foreach ($assignmentsRows as $t): $count++;
      $urg = $t['status']==='completed' ? ['label'=>'Done ✓','class'=>'urg-done','color'=>'#4f8ef7'] : urgencyInfo($t['due_date']);
      $isDone = $t['status']==='completed';
    ?>
    <div class="table-row <?= $isDone?'done-row':'' ?>">
      <div class="task-name">
        <a href="assignments.php?toggle=<?=$t['id']?>" class="complete-btn"
           style="background:<?= $urg['color'] ?>;opacity:<?=$isDone?'0.5':'1'?>"
           title="<?=$isDone?'Mark pending':'Mark complete'?>">
           <?= $isDone?'✓':'' ?>
        </a>
        <div>
          <div class="task-title"><?= htmlspecialchars($t['title']) ?></div>
          <div class="task-course-label"><?= $t['grade_weight']>0 ? $t['grade_weight'].'% of grade' : '' ?></div>
        </div>
      </div>
      <div>
        <?php if($t['course_code']): ?>
          <span class="course-tag" style="background:rgba(79,142,247,0.12);color:var(--accent)">
            <?= htmlspecialchars($t['course_code']) ?>
          </span>
        <?php else: ?>
          <span style="color:var(--muted);font-size:0.8rem">—</span>
        <?php endif; ?>
      </div>
      <div class="due-date" style="color:<?= $urg['color'] ?>"><?= date('M j, Y', strtotime($t['due_date'])) ?></div>
      <div><span class="badge <?= $urg['class'] ?>"><?= $urg['label'] ?></span></div>
      <div class="actions">
        <a href="?edit=<?=$t['id']?>" class="act-btn">✏️ Edit</a>
        <a href="?delete=<?=$t['id']?>" class="act-btn danger"
           onclick="return confirm('Delete this assignment?')">🗑</a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if($count===0): ?><div class="empty-state">No assignments found. <a href="#" onclick="openModal('add')" style="color:var(--accent)">Add one!</a></div><?php endif; ?>
  </div>
</main>

<!-- ADD MODAL -->
<div class="modal-overlay" id="addModal" onclick="if(event.target===this)closeModal('add')">
  <div class="modal">
    <h3>Add New Assignment</h3>
    <form method="POST" action="assignments.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <div class="fg"><label>Title *</label><input type="text" name="title" placeholder="Assignment title" required></div>
      <div class="fg-row">
        <div class="fg"><label>Course</label>
          <select name="course_id">
            <option value="">No course</option>
            <?php foreach ($courseRows as $c): ?>
              <option value="<?=$c['id']?>"><?= htmlspecialchars($c['course_code'].' — '.$c['course_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>Due Date *</label><input type="datetime-local" name="due_date" required></div>
      </div>
      <div class="fg-row">
        <div class="fg"><label>Grade Weight (%)</label><input type="number" name="grade_weight" placeholder="10" min="0" max="100" step="0.5"></div>
        <div class="fg"><label>Priority</label>
          <select name="priority"><option value="high">High</option><option value="medium" selected>Medium</option><option value="low">Low</option></select>
        </div>
      </div>
      <div class="fg"><label>Notes</label><textarea name="description" placeholder="Any notes..."></textarea></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('add')">Cancel</button>
        <button type="submit" class="btn-primary">Add Assignment</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeModal('edit')">
  <div class="modal">
    <h3>Edit Assignment</h3>
    <form method="POST" action="assignments.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="edit_id" id="edit_id" value="<?= $editData['id'] ?? '' ?>">
      <div class="fg"><label>Title *</label><input type="text" name="title" id="edit_title" value="<?= htmlspecialchars($editData['title'] ?? '') ?>" required></div>
      <div class="fg-row">
        <div class="fg"><label>Course</label>
          <select name="course_id" id="edit_course">
            <option value="">No course</option>
            <?php foreach ($courseRows as $c): ?>
              <option value="<?=$c['id']?>" <?= ($editData['course_id']??'')==$c['id']?'selected':'' ?>>
                <?= htmlspecialchars($c['course_code'].' — '.$c['course_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>Due Date *</label>
          <input type="datetime-local" name="due_date" id="edit_due"
                 value="<?= $editData ? date('Y-m-d\TH:i', strtotime($editData['due_date'])) : '' ?>" required>
        </div>
      </div>
      <div class="fg-row">
        <div class="fg"><label>Grade Weight (%)</label><input type="number" name="grade_weight" id="edit_weight" value="<?= $editData['grade_weight'] ?? '' ?>" min="0" max="100" step="0.5"></div>
        <div class="fg"><label>Priority</label>
          <select name="priority" id="edit_priority">
            <option value="high" <?= ($editData['priority']??'')==='high'?'selected':'' ?>>High</option>
            <option value="medium" <?= ($editData['priority']??'')==='medium'?'selected':'' ?>>Medium</option>
            <option value="low" <?= ($editData['priority']??'')==='low'?'selected':'' ?>>Low</option>
          </select>
        </div>
      </div>
      <div class="fg"><label>Notes</label><textarea name="description" id="edit_desc"><?= htmlspecialchars($editData['description'] ?? '') ?></textarea></div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal('edit')">Cancel</button>
        <button type="submit" class="btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openModal(t){document.getElementById(t+'Modal').classList.add('open');}
  function closeModal(t){document.getElementById(t+'Modal').classList.remove('open');}
  <?php if($editData): ?>
  document.addEventListener('DOMContentLoaded',()=>openModal('edit'));
  <?php endif; ?>
  <?php if(isset($_GET['new'])): ?>
  document.addEventListener('DOMContentLoaded',()=>openModal('add'));
  <?php endif; ?>
</script>
</body>
</html>
