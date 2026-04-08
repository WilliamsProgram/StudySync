<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
}

requireLogin();
$uid  = (int) $_SESSION['user_id'];
$user = getUser($conn);
$init = initials($user['first_name'], $user['last_name']);
$allowedSemesters = ['Semester 1', 'Semester 2'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grades') {
    $semester = in_array($_POST['semester'] ?? 'Semester 1', $allowedSemesters, true) ? (string) $_POST['semester'] : 'Semester 1';

    $deleteStmt = $conn->prepare('DELETE FROM grades WHERE user_id = ? AND semester = ?');
    $deleteStmt->bind_param('is', $uid, $semester);
    $deleteStmt->execute();
    $deleteStmt->close();

    $courseIds = is_array($_POST['course_id'] ?? null) ? $_POST['course_id'] : [];
    $scores = is_array($_POST['score'] ?? null) ? $_POST['score'] : [];
    $weights = is_array($_POST['weight'] ?? null) ? $_POST['weight'] : [];
    $names = is_array($_POST['item_name'] ?? null) ? $_POST['item_name'] : [];

    $stmt = $conn->prepare('INSERT INTO grades (user_id, course_id, item_name, score, weight, semester) VALUES (?, NULLIF(?,0), ?, ?, ?, ?)');
    foreach ($scores as $i => $score) {
        if ($score === '' || $score === null) {
            continue;
        }
        $cid = cleanInt($courseIds[$i] ?? 0, 0);
        if (!userOwnsCourse($conn, $uid, $cid)) {
            $cid = 0;
        }
        $s = cleanFloat($score, 0, 100);
        $w = cleanFloat($weights[$i] ?? 0, 0, 100);
        $name = cleanText($names[$i] ?? ('Grade ' . ($i + 1)), 120);
        if ($name === '') {
            $name = 'Grade ' . ($i + 1);
        }
        $stmt->bind_param('iisdds', $uid, $cid, $name, $s, $w, $semester);
        $stmt->execute();
    }
    $stmt->close();
    redirectTo('gpa.php?saved=1&semester=' . urlencode($semester));
}

$semester = in_array($_GET['semester'] ?? 'Semester 1', $allowedSemesters, true) ? (string) $_GET['semester'] : 'Semester 1';

$courses = $conn->query("SELECT * FROM courses WHERE user_id = $uid ORDER BY course_code");
$stmt = $conn->prepare(
    'SELECT g.*, c.course_code, c.color
     FROM grades g
     LEFT JOIN courses c ON g.course_id = c.id
     WHERE g.user_id = ? AND g.semester = ?'
);
$stmt->bind_param('is', $uid, $semester);
$gradeRows = fetchAllRows($stmt);

$gpaVal = calculateCurrentGpa($conn, $uid, $semester);
$gpaPct = $gpaVal ? round(($gpaVal / 4) * 100) : 0;
$urgent = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id = $uid AND status = 'pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GPA Calculator — StudySync</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-gpa">
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
  <a class="sidebar-item active" href="gpa.php"><span class="icon">🎯</span> GPA Calculator</a>
  <a class="sidebar-item" href="profile.php"><span class="icon">👤</span> Profile</a>
  <a class="logout-link" href="logout.php"><span class="icon">🚪</span> Log Out</a>
  <div class="sidebar-footer">
    <div class="user-mini"><div class="avatar"><?=$init?></div><div class="user-info"><strong><?=htmlspecialchars($user['first_name'].' '.$user['last_name'])?></strong><span><?=htmlspecialchars($user['year']??'Student')?></span></div></div>
  </div>
</aside>

<main class="main">
  <div class="page-header">
    <h1>GPA Calculator 🎯</h1>
    <p>Track your grades, calculate your GPA, and project what you need to hit your target.</p>
  </div>

  <?php if(isset($_GET['saved'])): ?><div class="success-msg">✅ Grades saved successfully!</div><?php endif; ?>

  <div class="gpa-hero">
    <div class="gpa-circle">
      <div class="gpa-inner">
        <div class="gpa-value" id="gpaDisplay"><?= $gpaVal ?? '—' ?></div>
        <div class="gpa-label">GPA</div>
      </div>
    </div>
    <div class="gpa-info">
      <h2><?= $gpaVal ? ($gpaVal >= 3.5 ? 'Dean\'s List territory! 🏆' : ($gpaVal >= 3.0 ? 'Good Standing 👍' : 'Keep pushing! 💪')) : 'Enter grades to see your GPA' ?></h2>
      <p><?= $gpaVal ? "Current GPA: $gpaVal / 4.00 based on saved grades for $semester" : "Add your grades below and save to calculate your GPA." ?></p>
    </div>
    <div style="margin-left:auto">
      <select onchange="window.location='gpa.php?semester='+this.value" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:0.5rem 1rem;color:var(--text);outline:none;">
        <?php foreach(['Semester 1','Semester 2'] as $s): ?>
          <option value="<?=e($s)?>" <?= $semester===$s?'selected':'' ?>><?=e($s)?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="content-grid">
    <div class="card">
      <div class="card-header">
        <span class="card-title">Enter Your Grades — <?=$semester?></span>
      </div>
      <form method="POST" action="gpa.php">
      <?= csrfField() ?>
        <input type="hidden" name="action" value="save_grades">
        <input type="hidden" name="semester" value="<?=htmlspecialchars($semester)?>">
        <table class="grade-table">
          <thead><tr><th>Item</th><th>Course</th><th>Score %</th><th>Weight %</th><th>Letter</th><th>Points</th></tr></thead>
          <tbody id="gradeBody">
          <?php
          // Show saved grades, or 3 empty rows if none
          $rows = count($gradeRows) > 0 ? $gradeRows : array_fill(0, 3, null);
          foreach ($rows as $i => $g):
            $score  = $g ? $g['score']  : '';
            $weight = $g ? $g['weight'] : '';
            $name   = $g ? $g['item_name'] : '';
            $cid    = $g ? $g['course_id'] : '';
            [$letter,$pts,$bg,$fg] = $score !== '' ? gradeToLetter((float)$score) : ['—', 0, 'transparent','var(--muted)'];
          ?>
          <tr>
            <td><input class="grade-input" style="width:100px" type="text" name="item_name[]" placeholder="Assignment 1" value="<?=htmlspecialchars($name)?>"></td>
            <td>
              <select name="course_id[]" class="grade-select">
                <option value="0">None</option>
                <?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?>
                  <option value="<?=$c['id']?>" <?=$cid==$c['id']?'selected':''?>><?=$c['course_code']?></option>
                <?php endwhile; ?>
              </select>
            </td>
            <td><input class="grade-input" type="number" name="score[]" value="<?=htmlspecialchars($score)?>" min="0" max="100" step="0.5" oninput="updateRow(this)"></td>
            <td><input class="grade-input" type="number" name="weight[]" value="<?=htmlspecialchars($weight)?>" min="0" max="100" step="0.5"></td>
            <td><span class="letter-badge" style="background:<?=$bg?>;color:<?=$fg?>"><?=$letter?></span></td>
            <td class="gpa-pts"><?=$pts>0?number_format($pts,1):'—'?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <button type="button" class="btn-add-row" onclick="addRow()">+ Add Row</button>
        <button type="submit" class="btn-calc">💾 Save & Calculate GPA</button>
      </form>
    </div>

    <div style="display:flex;flex-direction:column;gap:1.5rem;">
      <div class="card">
        <div class="card-header"><span class="card-title">GPA Projections</span></div>
        <?php
        $targets = [[3.5,'Dean\'s List','var(--accent2)'], [3.7,'Honours Track','var(--accent)'], [3.0,'Good Standing','var(--yellow)']];
        foreach($targets as [$target,$label,$color]):
          $pct = $gpaVal ? min(100, round(($gpaVal/$target)*100)) : 0;
        ?>
        <div class="projection-item">
          <div class="proj-header">
            <div class="proj-target" style="color:<?=$color?>"><?=$target?></div>
            <div class="proj-info"><strong><?=$label?></strong><span>Target GPA</span></div>
          </div>
          <div class="proj-bar-wrap"><div class="proj-bar" style="width:<?=$pct?>%;background:<?=$color?>"></div></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <div class="card-header"><span class="card-title">NCU Grade Scale</span></div>
        <?php
        $scale=[['A','90–100%','4.0'],['A-','87–89%','3.7'],['B+','84–86%','3.3'],['B','80–83%','3.0'],
                ['B-','77–79%','2.7'],['C+','74–76%','2.3'],['C','70–73%','2.0'],['D','60–69%','1.0'],['F','Below 60%','0.0']];
        foreach($scale as [$l,$r,$p]): ?>
        <div class="scale-row"><span class="scale-letter"><?=$l?></span><span class="scale-range"><?=$r?></span><span class="scale-pts"><?=$p?> pts</span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</main>

<script>
const scale=(g)=>{
  if(g>=90)return{l:'A',p:4.0,bg:'rgba(56,217,169,0.2)',fg:'#38d9a9'};
  if(g>=87)return{l:'A-',p:3.7,bg:'rgba(56,217,169,0.2)',fg:'#38d9a9'};
  if(g>=84)return{l:'B+',p:3.3,bg:'rgba(56,217,169,0.15)',fg:'#38d9a9'};
  if(g>=80)return{l:'B',p:3.0,bg:'rgba(56,217,169,0.15)',fg:'#38d9a9'};
  if(g>=77)return{l:'B-',p:2.7,bg:'rgba(79,142,247,0.2)',fg:'#4f8ef7'};
  if(g>=74)return{l:'C+',p:2.3,bg:'rgba(79,142,247,0.15)',fg:'#4f8ef7'};
  if(g>=70)return{l:'C',p:2.0,bg:'rgba(254,188,46,0.15)',fg:'#febc2e'};
  if(g>=60)return{l:'D',p:1.0,bg:'rgba(247,122,79,0.15)',fg:'#f77a4f'};
  return{l:'F',p:0.0,bg:'rgba(247,122,79,0.2)',fg:'#f77a4f'};
};
function updateRow(inp){
  const row=inp.closest('tr');
  const v=parseFloat(inp.value);
  if(isNaN(v))return;
  const s=scale(v);
  row.querySelector('.letter-badge').textContent=s.l;
  row.querySelector('.letter-badge').style.background=s.bg;
  row.querySelector('.letter-badge').style.color=s.fg;
  row.querySelector('.gpa-pts').textContent=s.p.toFixed(1);
}
function addRow(){
  const tbody=document.getElementById('gradeBody');
  const tr=document.createElement('tr');
  tr.innerHTML=`<td><input class="grade-input" style="width:100px" type="text" name="item_name[]" placeholder="Item name"></td>
    <td><select name="course_id[]" class="grade-select"><?php $courses->data_seek(0); while($c=$courses->fetch_assoc()) echo "<option value='{$c['id']}'>{$c['course_code']}</option>"; ?></select></td>
    <td><input class="grade-input" type="number" name="score[]" min="0" max="100" step="0.5" oninput="updateRow(this)"></td>
    <td><input class="grade-input" type="number" name="weight[]" min="0" max="100" step="0.5"></td>
    <td><span class="letter-badge" style="background:transparent;color:var(--muted)">—</span></td>
    <td class="gpa-pts">—</td>`;
  tbody.appendChild(tr);
}
</script>
</body>
</html>
