<?php
require_once 'config.php';
requireLogin();
$uid  = $_SESSION['user_id'];
$user = getUser($conn);
$init = initials($user['first_name'], $user['last_name']);

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grades') {
     
    $semester = trim($_POST['semester'] ?? 'Semester 1');
    $semesterEscaped = $conn->real_escape_string($semester);
    $conn->query("DELETE FROM grades WHERE user_id=$uid AND semester='$semesterEscaped'");

    $course_ids = $_POST['course_id']   ?? [];
    $scores     = $_POST['score']       ?? [];
    $weights    = $_POST['weight']      ?? [];
    $names      = $_POST['item_name']   ?? [];

    $stmt = $conn->prepare(
        "INSERT INTO grades (user_id,course_id,item_name,score,weight,semester) VALUES (?,?,?,?,?,?)"
    );
    foreach ($scores as $i => $score) {
        if ($score === '' || $score === null) continue;
        $cid  = intval($course_ids[$i] ?? 0);
        $s    = floatval($score);
        $w    = floatval($weights[$i] ?? 0);
        $name = $names[$i] ?? 'Grade ' . ($i + 1);
        $stmt->bind_param("iisdds", $uid, $cid, $name, $s, $w, $semester);
        $stmt->execute();
    }
    header("Location: gpa.php?saved=1");
    exit();
}

 
$semester = $_GET['semester'] ?? 'Semester 1';
$sem_esc  = $conn->real_escape_string($semester);

$courses = $conn->query("SELECT * FROM courses WHERE user_id=$uid ORDER BY course_code");
$grades  = $conn->query(
    "SELECT g.*, c.course_code, c.color
     FROM grades g LEFT JOIN courses c ON g.course_id=c.id
     WHERE g.user_id=$uid AND g.semester='$sem_esc'"
);
$gradeRows = [];
while ($g = $grades->fetch_assoc()) $gradeRows[] = $g;

 
$gpaVal = calculateCurrentGpa($conn, $uid, $semester);
$gpaPct = $gpaVal ? round(($gpaVal / 4) * 100) : 0;

$urgent = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id=$uid AND status='pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GPA Calculator — StudySync</title>
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
  .main{margin-left:240px;flex:1;padding:2.5rem;max-width:1100px;}
  .page-header{margin-bottom:2rem;}
  .page-header h1{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;margin-bottom:0.3rem;}
  .page-header p{color:var(--muted);font-size:0.9rem;}
  .success-msg{background:rgba(56,217,169,0.1);border:1px solid rgba(56,217,169,0.3);border-radius:8px;padding:0.75rem 1rem;font-size:0.875rem;color:var(--accent2);margin-bottom:1.25rem;}
  .gpa-hero{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:2.5rem;margin-bottom:2rem;display:flex;align-items:center;gap:3rem;position:relative;overflow:hidden;}
  .gpa-hero::before{content:'';position:absolute;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(56,217,169,0.1),transparent 70%);right:-80px;top:-80px;}
  .gpa-circle{width:140px;height:140px;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative;flex-shrink:0;background:conic-gradient(var(--accent2) calc(<?=$gpaPct?>%*1),var(--surface2) 0);}
  .gpa-inner{width:110px;height:110px;border-radius:50%;background:var(--surface);display:flex;flex-direction:column;align-items:center;justify-content:center;}
  .gpa-value{font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:var(--accent2);line-height:1;}
  .gpa-label{font-size:0.7rem;color:var(--muted);}
  .gpa-info h2{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;margin-bottom:0.3rem;}
  .gpa-info p{color:var(--muted);font-size:0.9rem;}
  .content-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:1.5rem;}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.5rem;}
  .card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;}
  .card-title{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;}
  .grade-table{width:100%;border-collapse:collapse;}
  .grade-table th{font-size:0.72rem;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--muted);padding:0.6rem 0.5rem;text-align:left;border-bottom:1px solid var(--border);}
  .grade-table td{padding:0.65rem 0.5rem;border-bottom:1px solid var(--border);font-size:0.875rem;vertical-align:middle;}
  .grade-table tr:last-child td{border-bottom:none;}
  .grade-input{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:0.3rem 0.5rem;color:var(--text);font-size:0.875rem;width:70px;outline:none;transition:all .2s;}
  .grade-input:focus{border-color:var(--accent);}
  .grade-select{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:0.3rem 0.5rem;color:var(--text);font-size:0.8rem;outline:none;max-width:120px;}
  .letter-badge{display:inline-block;padding:0.2rem 0.5rem;border-radius:6px;font-size:0.75rem;font-weight:700;min-width:30px;text-align:center;}
  .gpa-pts{font-weight:600;color:var(--accent2);}
  .btn-add-row{width:100%;padding:0.6rem;background:var(--surface2);border:1px dashed var(--border);border-radius:8px;color:var(--muted);font-size:0.85rem;cursor:pointer;transition:all .2s;margin-top:0.75rem;}
  .btn-add-row:hover{border-color:var(--accent);color:var(--accent);}
  .btn-calc{width:100%;padding:0.75rem;border-radius:10px;background:linear-gradient(135deg,var(--accent2),#2ab080);color:#0b1a14;font-weight:700;font-size:0.95rem;border:none;cursor:pointer;margin-top:0.75rem;transition:all .2s;box-shadow:0 0 20px rgba(56,217,169,0.2);}
  .btn-calc:hover{transform:translateY(-1px);}
  .projection-item{padding:0.85rem;background:var(--surface2);border-radius:10px;margin-bottom:0.65rem;}
  .proj-header{display:flex;align-items:center;gap:1rem;margin-bottom:0.4rem;}
  .proj-target{font-size:1.1rem;font-family:'Syne',sans-serif;font-weight:800;width:36px;}
  .proj-info strong{font-size:0.875rem;display:block;}
  .proj-info span{font-size:0.75rem;color:var(--muted);}
  .proj-bar-wrap{height:4px;background:var(--surface3);border-radius:4px;}
  .proj-bar{height:100%;border-radius:4px;}
  .scale-row{display:flex;align-items:center;gap:1rem;padding:0.55rem 0;border-bottom:1px solid var(--border);font-size:0.85rem;}
  .scale-row:last-child{border-bottom:none;}
  .scale-letter{font-weight:700;width:28px;}
  .scale-range{color:var(--muted);flex:1;}
  .scale-pts{color:var(--accent2);font-weight:600;}
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
          <option value="<?=$s?>" <?= $semester===$s?'selected':'' ?>><?=$s?></option>
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
        <input type="hidden" name="action" value="save_grades">
        <input type="hidden" name="semester" value="<?=htmlspecialchars($semester)?>">
        <table class="grade-table">
          <thead><tr><th>Item</th><th>Course</th><th>Score %</th><th>Weight %</th><th>Letter</th><th>Points</th></tr></thead>
          <tbody id="gradeBody">
          <?php
           
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
