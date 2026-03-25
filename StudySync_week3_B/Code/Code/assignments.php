<?php
require_once 'config.php';
requireLogin();
$user = getUser($conn);
$uid  = $_SESSION['user_id'];
$init = initials($user['first_name'], $user['last_name']);

 

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $title   = trim($_POST['title'] ?? '');
    $cid     = intval($_POST['course_id'] ?? 0);
    $due     = normalizeDateTimeInput($_POST['due_date'] ?? '');
    $weight  = floatval($_POST['grade_weight'] ?? 0);
    $priority= $_POST['priority'] ?? 'medium';
    $desc    = trim($_POST['description'] ?? '');

    if ($title && $due) {
        $stmt = $conn->prepare(
            "INSERT INTO assignments (user_id,course_id,title,description,due_date,grade_weight,priority)
             VALUES (?, NULLIF(?,0), ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iisssds", $uid, $cid, $title, $desc, $due, $weight, $priority);
        $stmt->execute();
    }
    header("Location: assignments.php");
    exit();
}

 
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM assignments WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $stmt->close();
    header("Location: assignments.php");
    exit();
}

 
if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    $stmt = $conn->prepare("SELECT status FROM assignments WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $newStatus = $row['status'] === 'completed' ? 'pending' : 'completed';
        $update = $conn->prepare("UPDATE assignments SET status=? WHERE id=? AND user_id=?");
        $update->bind_param("sii", $newStatus, $id, $uid);
        $update->execute();
        $update->close();
    }
    header("Location: assignments.php");
    exit();
}

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id     = intval($_POST['edit_id']);
    $title  = trim($_POST['title'] ?? '');
    $cid    = intval($_POST['course_id'] ?? 0);
    $due    = normalizeDateTimeInput($_POST['due_date'] ?? '');
    $weight = floatval($_POST['grade_weight'] ?? 0);
    $prio   = $_POST['priority'] ?? 'medium';
    $desc   = trim($_POST['description'] ?? '');

    $stmt = $conn->prepare(
        "UPDATE assignments SET title=?, course_id=NULLIF(?,0), due_date=?, grade_weight=?, priority=?, description=?
         WHERE id=? AND user_id=?"
    );
    $stmt->bind_param("sisdssii", $title, $cid, $due, $weight, $prio, $desc, $id, $uid);
    $stmt->execute();
    header("Location: assignments.php");
    exit();
}

 
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$whereExtra = '';
if ($filter === 'urgent')  $whereExtra = "AND a.due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY) AND a.status='pending'";
if ($filter === 'upcoming') $whereExtra = "AND a.due_date > DATE_ADD(NOW(), INTERVAL 3 DAY) AND a.status='pending'";
if ($filter === 'done')     $whereExtra = "AND a.status='completed'";

$searchSQL = '';
if ($search) {
    $s = $conn->real_escape_string($search);
    $searchSQL = "AND (a.title LIKE '%$s%' OR c.course_code LIKE '%$s%')";
}

$assignments = $conn->query(
    "SELECT a.*, c.course_code, c.color
     FROM assignments a
     LEFT JOIN courses c ON a.course_id = c.id
     WHERE a.user_id = $uid $whereExtra $searchSQL
     ORDER BY a.due_date ASC"
);

$courses = $conn->query("SELECT * FROM courses WHERE user_id=$uid ORDER BY course_code");

 
$editData = null;
if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $editData = $conn->query("SELECT * FROM assignments WHERE id=$eid AND user_id=$uid")->fetch_assoc();
}

$urgent = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id=$uid AND status='pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assignments — StudySync</title>
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
  .main{margin-left:240px;flex:1;padding:2.5rem;}
  .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;}
  .page-header h1{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;}
  .btn-primary{padding:0.6rem 1.3rem;border-radius:8px;background:linear-gradient(135deg,var(--accent),#3a6fd4);color:#fff;font-size:0.875rem;font-weight:600;border:none;cursor:pointer;transition:all .2s;box-shadow:0 0 16px var(--glow);text-decoration:none;}
  .btn-primary:hover{transform:translateY(-1px);}
  .filters{display:flex;gap:0.75rem;margin-bottom:1.75rem;flex-wrap:wrap;align-items:center;}
  .filter-btn{padding:0.45rem 1rem;border-radius:100px;border:1px solid var(--border);background:var(--surface);color:var(--muted);font-size:0.82rem;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none;}
  .filter-btn:hover,.filter-btn.active{background:rgba(79,142,247,0.1);border-color:var(--accent);color:var(--accent);}
  .search-form{display:flex;gap:0.5rem;margin-left:auto;}
  .search-input{padding:0.45rem 1rem;border-radius:100px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:0.82rem;outline:none;min-width:220px;transition:all .2s;}
  .search-input:focus{border-color:var(--accent);}
  .search-input::placeholder{color:var(--muted);}
  .table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;}
  .table-head{display:grid;grid-template-columns:2.5fr 1.2fr 1fr 1fr 1fr;padding:0.85rem 1.25rem;background:var(--surface2);border-bottom:1px solid var(--border);}
  .table-head span{font-size:0.75rem;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--muted);}
  .table-row{display:grid;grid-template-columns:2.5fr 1.2fr 1fr 1fr 1fr;padding:1rem 1.25rem;border-bottom:1px solid var(--border);align-items:center;transition:background .2s;}
  .table-row:last-child{border-bottom:none;}
  .table-row:hover{background:var(--surface2);}
  .table-row.done-row{opacity:0.55;}
  .task-name{display:flex;align-items:center;gap:0.75rem;}
  .complete-btn{width:20px;height:20px;border-radius:6px;flex-shrink:0;cursor:pointer;border:none;display:flex;align-items:center;justify-content:center;font-size:0.7rem;transition:all .2s;}
  .task-title{font-size:0.9rem;font-weight:500;}
  .done-row .task-title{text-decoration:line-through;}
  .task-course-label{font-size:0.75rem;color:var(--muted);margin-top:0.1rem;}
  .course-tag{font-size:0.75rem;padding:0.2rem 0.6rem;border-radius:20px;font-weight:600;}
  .due-date{font-size:0.85rem;}
  .badge{font-size:0.72rem;padding:0.2rem 0.65rem;border-radius:20px;font-weight:600;}
  .urg-red{background:rgba(247,122,79,0.15);color:#f77a4f;}
  .urg-yellow{background:rgba(254,188,46,0.15);color:#febc2e;}
  .urg-green{background:rgba(56,217,169,0.15);color:#38d9a9;}
  .urg-done{background:rgba(79,142,247,0.1);color:var(--muted);}
  .actions{display:flex;gap:0.5rem;}
  .act-btn{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:0.25rem 0.6rem;font-size:0.75rem;cursor:pointer;color:var(--muted);transition:all .2s;text-decoration:none;}
  .act-btn:hover{color:var(--text);border-color:var(--accent);}
  .act-btn.danger:hover{color:var(--red);border-color:var(--red);}
  .empty-state{text-align:center;padding:3rem;color:var(--muted);}
  
  .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:200;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);opacity:0;pointer-events:none;transition:opacity .2s;}
  .modal-overlay.open{opacity:1;pointer-events:all;}
  .modal{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:2rem;width:100%;max-width:520px;transform:scale(0.95);transition:transform .2s;}
  .modal-overlay.open .modal{transform:scale(1);}
  .modal h3{font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;margin-bottom:1.5rem;}
  .fg{margin-bottom:1rem;}
  .fg label{display:block;font-size:0.82rem;color:var(--muted);margin-bottom:0.4rem;}
  .fg input,.fg select,.fg textarea{width:100%;padding:0.75rem 1rem;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--text);font-family:'DM Sans',sans-serif;font-size:0.9rem;outline:none;transition:all .2s;}
  .fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--accent);}
  .fg textarea{resize:vertical;min-height:70px;}
  .fg-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
  .modal-actions{display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;}
  .btn-cancel{padding:0.6rem 1.3rem;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);font-size:0.875rem;cursor:pointer;}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="sidebar-logo">StudySync</div>
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
    <?php $count=0; while ($t = $assignments->fetch_assoc()): $count++;
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
    <?php endwhile; ?>
    <?php if($count===0): ?><div class="empty-state">No assignments found. <a href="#" onclick="openModal('add')" style="color:var(--accent)">Add one!</a></div><?php endif; ?>
  </div>
</main>


<div class="modal-overlay" id="addModal" onclick="if(event.target===this)closeModal('add')">
  <div class="modal">
    <h3>Add New Assignment</h3>
    <form method="POST" action="assignments.php">
      <input type="hidden" name="action" value="add">
      <div class="fg"><label>Title *</label><input type="text" name="title" placeholder="Assignment title" required></div>
      <div class="fg-row">
        <div class="fg"><label>Course</label>
          <select name="course_id">
            <option value="">No course</option>
            <?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?>
              <option value="<?=$c['id']?>"><?= htmlspecialchars($c['course_code'].' — '.$c['course_name']) ?></option>
            <?php endwhile; ?>
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


<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeModal('edit')">
  <div class="modal">
    <h3>Edit Assignment</h3>
    <form method="POST" action="assignments.php">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="edit_id" id="edit_id" value="<?= $editData['id'] ?? '' ?>">
      <div class="fg"><label>Title *</label><input type="text" name="title" id="edit_title" value="<?= htmlspecialchars($editData['title'] ?? '') ?>" required></div>
      <div class="fg-row">
        <div class="fg"><label>Course</label>
          <select name="course_id" id="edit_course">
            <option value="">No course</option>
            <?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?>
              <option value="<?=$c['id']?>" <?= ($editData['course_id']??'')==$c['id']?'selected':'' ?>>
                <?= htmlspecialchars($c['course_code'].' — '.$c['course_name']) ?>
              </option>
            <?php endwhile; ?>
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
