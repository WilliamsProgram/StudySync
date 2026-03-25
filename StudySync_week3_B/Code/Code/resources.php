<?php
require_once 'config.php';
requireLogin();
$uid  = $_SESSION['user_id'];
$user = getUser($conn);
$init = initials($user['first_name'], $user['last_name']);

 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'add_resource') {
    $title     = trim($_POST['title']     ?? '');
    $type      = $_POST['res_type']       ?? 'other';
    $cid       = intval($_POST['course_id'] ?? 0);
    $gid       = intval($_POST['group_id']  ?? 0);
    $url       = trim($_POST['url']       ?? '');
    $is_priv   = isset($_POST['is_private']) ? 1 : 0;
    $file_path = '';

     
    if (!empty($_FILES['file']['name'])) {
        $uploadDir = 'uploads/resources/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext       = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $allowed   = ['pdf','doc','docx','ppt','pptx','xls','xlsx','jpg','jpeg','png','gif','zip','txt'];

        if (!in_array($ext, $allowed)) {
            $uploadError = "File type .$ext is not allowed.";
        } elseif ($_FILES['file']['size'] > 20 * 1024 * 1024) {
            $uploadError = "File is too large (max 20MB).";
        } else {
            $safeName  = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['file']['name']);
            $dest      = $uploadDir . $safeName;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                $file_path = $dest;
                 
                $extMap = ['pdf'=>'pdf','doc'=>'doc','docx'=>'doc','ppt'=>'slides','pptx'=>'slides','jpg'=>'image','jpeg'=>'image','png'=>'image','gif'=>'image'];
                $type   = $extMap[$ext] ?? 'other';
            } else {
                $uploadError = "Upload failed. Check folder permissions.";
            }
        }
    }

    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        $uploadError = 'Please enter a valid link.';
    }

    if ($title && !$file_path && !$url && !isset($uploadError)) {
        $uploadError = 'Please upload a file or provide a link.';
    }

    if ($title && !isset($uploadError)) {
        $stmt = $conn->prepare(
            "INSERT INTO resources (user_id,group_id,course_id,title,type,file_path,url,is_private)
             VALUES (?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iiissssi", $uid,$gid,$cid,$title,$type,$file_path,$url,$is_priv);
        $stmt->execute();
        $resourceId = $conn->insert_id;
        $stmt->close();

        if ($gid > 0 && !$is_priv) {
            $notifyStmt = $conn->prepare("SELECT user_id FROM group_members WHERE group_id=? AND user_id<>?");
            $notifyStmt->bind_param("ii", $gid, $uid);
            $notifyStmt->execute();
            $notifyRes = $notifyStmt->get_result();
            while ($member = $notifyRes->fetch_assoc()) {
                createNotification($conn, (int) $member['user_id'], 'A new resource was shared: ' . $title . '.', 'resource', 'resource_' . $resourceId . '_u' . $member['user_id'], 'resource', $resourceId);
            }
            $notifyStmt->close();
        }

        header("Location: resources.php?added=1");
        exit();
    }
}

 
if (isset($_GET['delete'])) {
    $rid = intval($_GET['delete']);
    $stmt = $conn->prepare("SELECT file_path FROM resources WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $rid, $uid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
        if ($res['file_path'] && file_exists($res['file_path'])) {
            unlink($res['file_path']);
        }
        $delStmt = $conn->prepare("DELETE FROM resources WHERE id=? AND user_id=?");
        $delStmt->bind_param("ii", $rid, $uid);
        $delStmt->execute();
        $delStmt->close();
    }
    header("Location: resources.php");
    exit();
}

 
$filterCourse = intval($_GET['course'] ?? 0);
$filterType   = $_GET['type'] ?? '';
$search       = trim($_GET['search'] ?? '');
$mine         = isset($_GET['mine']);

$where = "WHERE (r.user_id=$uid OR r.is_private=0)";
if ($filterCourse) $where .= " AND r.course_id=$filterCourse";
if ($filterType)   $where .= " AND r.type='".($conn->real_escape_string($filterType))."'";
if ($mine)         $where .= " AND r.user_id=$uid";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (r.title LIKE '%$s%' OR c.course_code LIKE '%$s%')";
}

$resources = $conn->query(
    "SELECT r.*, c.course_code, c.color,
            u.first_name, u.last_name,
            sg.name AS group_name
     FROM resources r
     LEFT JOIN courses c ON r.course_id=c.id
     LEFT JOIN users u ON r.user_id=u.id
     LEFT JOIN study_groups sg ON r.group_id=sg.id
     $where
     ORDER BY r.uploaded_at DESC"
);
$resourceRows = [];
while ($r = $resources->fetch_assoc()) $resourceRows[] = $r;

$courses   = $conn->query("SELECT * FROM courses WHERE user_id=$uid ORDER BY course_code");
$myGroups  = $conn->query("SELECT sg.* FROM study_groups sg JOIN group_members gm ON sg.id=gm.group_id WHERE gm.user_id=$uid");
$urgent    = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id=$uid AND status='pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];

 
$typeIcons = ['pdf'=>'📄','doc'=>'📝','slides'=>'📊','image'=>'🖼️','link'=>'🔗','other'=>'📦'];
$typeLabels= ['pdf'=>'PDF','doc'=>'Document','slides'=>'Slides','image'=>'Image','link'=>'Link','other'=>'File'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resources — StudySync</title>
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

  .main{margin-left:240px;flex:1;padding:2.5rem;}
  .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.75rem;}
  .page-header h1{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;}
  .btn-primary{padding:0.6rem 1.3rem;border-radius:8px;background:linear-gradient(135deg,var(--accent),#3a6fd4);color:#fff;font-size:0.875rem;font-weight:600;border:none;cursor:pointer;transition:all .2s;box-shadow:0 0 16px var(--glow);text-decoration:none;}
  .btn-primary:hover{transform:translateY(-1px);}

  
  .upload-zone{border:2px dashed rgba(79,142,247,0.3);border-radius:14px;padding:2rem;text-align:center;margin-bottom:1.75rem;transition:all .3s;cursor:pointer;background:rgba(79,142,247,0.02);}
  .upload-zone:hover,.upload-zone.drag{border-color:var(--accent);background:rgba(79,142,247,0.07);}
  .upload-zone .icon{font-size:2.2rem;margin-bottom:0.6rem;}
  .upload-zone h3{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;margin-bottom:0.3rem;}
  .upload-zone p{font-size:0.82rem;color:var(--muted);}

  
  .filter-row{display:flex;gap:0.65rem;margin-bottom:1.5rem;flex-wrap:wrap;align-items:center;}
  .chip{padding:0.38rem 0.9rem;border-radius:100px;border:1px solid var(--border);background:var(--surface);color:var(--muted);font-size:0.8rem;cursor:pointer;transition:all .2s;text-decoration:none;}
  .chip:hover,.chip.active{background:rgba(79,142,247,0.1);border-color:var(--accent);color:var(--accent);}
  .search-form{margin-left:auto;display:flex;gap:0.5rem;}
  .search-input{padding:0.38rem 0.9rem;border-radius:100px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:0.8rem;outline:none;min-width:200px;}
  .search-input:focus{border-color:var(--accent);}
  .search-input::placeholder{color:var(--muted);}

  
  .success-msg{background:rgba(56,217,169,0.1);border:1px solid rgba(56,217,169,0.3);border-radius:8px;padding:0.75rem 1rem;font-size:0.875rem;color:var(--accent2);margin-bottom:1.25rem;}
  .error-msg{background:rgba(247,122,79,0.1);border:1px solid rgba(247,122,79,0.3);border-radius:8px;padding:0.75rem 1rem;font-size:0.875rem;color:var(--red);margin-bottom:1.25rem;}

  
  .resource-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.25rem;}
  .rc{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.25rem;transition:all .2s;display:flex;flex-direction:column;}
  .rc:hover{transform:translateY(-3px);border-color:rgba(79,142,247,0.35);box-shadow:0 10px 28px rgba(0,0,0,0.3);}
  .rc-icon{font-size:2rem;margin-bottom:0.75rem;}
  .rc-title{font-weight:600;font-size:0.95rem;margin-bottom:0.3rem;line-height:1.35;}
  .rc-meta{font-size:0.75rem;color:var(--muted);margin-bottom:0.75rem;line-height:1.55;flex:1;}
  .rc-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.4rem;}
  .course-tag{font-size:0.72rem;padding:0.2rem 0.6rem;border-radius:20px;font-weight:600;}
  .rc-actions{display:flex;gap:0.4rem;}
  .rc-btn{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:0.22rem 0.55rem;font-size:0.72rem;cursor:pointer;color:var(--muted);transition:all .2s;text-decoration:none;}
  .rc-btn:hover{color:var(--text);border-color:var(--accent);}
  .rc-btn.danger:hover{color:var(--red);border-color:var(--red);}
  .shared-badge{font-size:0.68rem;padding:0.15rem 0.5rem;border-radius:20px;background:rgba(56,217,169,0.1);color:var(--accent2);display:inline-block;margin-top:0.3rem;}
  .mine-badge{font-size:0.68rem;padding:0.15rem 0.5rem;border-radius:20px;background:rgba(79,142,247,0.1);color:var(--accent);display:inline-block;margin-top:0.3rem;}
  .empty-state{text-align:center;padding:3rem;color:var(--muted);}

  
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
  .drop-area{border:2px dashed rgba(79,142,247,0.3);border-radius:10px;padding:1.5rem;text-align:center;cursor:pointer;transition:all .2s;margin-bottom:0.5rem;}
  .drop-area:hover,.drop-area.drag{border-color:var(--accent);background:rgba(79,142,247,0.05);}
  .drop-area p{font-size:0.85rem;color:var(--muted);}
  #fileInput{display:none;}
  .file-chosen{font-size:0.8rem;color:var(--accent2);margin-top:0.4rem;}
  .divider{display:flex;align-items:center;gap:0.75rem;color:var(--muted);font-size:0.78rem;margin:0.75rem 0;}
  .divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border);}
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
  <a class="sidebar-item active" href="resources.php"><span class="icon">📁</span> Resources</a>
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
    <h1>Resources 📁</h1>
    <button class="btn-primary" onclick="openModal()">+ Upload / Add Resource</button>
  </div>

  <?php if(isset($_GET['added'])): ?>
    <div class="success-msg">✅ Resource added successfully!</div>
  <?php endif; ?>
  <?php if(isset($uploadError)): ?>
    <div class="error-msg">⚠ <?=htmlspecialchars($uploadError)?></div>
  <?php endif; ?>

  
  <div class="upload-zone" onclick="openModal()"
       ondragover="event.preventDefault();this.classList.add('drag')"
       ondragleave="this.classList.remove('drag')"
       ondrop="event.preventDefault();this.classList.remove('drag');openModal()">
    <div class="icon">☁️</div>
    <h3>Drop files here or click to upload</h3>
    <p>Share notes, PDFs, slides, links, and more with your study groups</p>
  </div>

  
  <div class="filter-row">
    <a href="resources.php"       class="chip <?=!$filterCourse&&!$filterType&&!$mine?'active':''?>">All</a>
    <a href="?mine=1"             class="chip <?=$mine?'active':''?>">My Uploads</a>
    <a href="?type=pdf"           class="chip <?=$filterType==='pdf'?'active':''?>">📄 PDFs</a>
    <a href="?type=slides"        class="chip <?=$filterType==='slides'?'active':''?>">📊 Slides</a>
    <a href="?type=link"          class="chip <?=$filterType==='link'?'active':''?>">🔗 Links</a>
    <a href="?type=image"         class="chip <?=$filterType==='image'?'active':''?>">🖼️ Images</a>
    <?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?>
      <a href="?course=<?=$c['id']?>" class="chip <?=$filterCourse==$c['id']?'active':''?>"><?=htmlspecialchars($c['course_code'])?></a>
    <?php endwhile; ?>
    <form class="search-form" method="GET">
      <input class="search-input" name="search" placeholder="🔍 Search..." value="<?=htmlspecialchars($search)?>">
    </form>
  </div>

  
  <?php if(empty($resourceRows)): ?>
    <div class="empty-state">
      <div style="font-size:3rem;margin-bottom:1rem">📂</div>
      <strong>No resources found</strong><br>
      <span style="font-size:0.875rem">Upload files or share links to get started.</span><br><br>
      <button class="btn-primary" onclick="openModal()">+ Add First Resource</button>
    </div>
  <?php else: ?>
  <div class="resource-grid">
    <?php foreach($resourceRows as $r):
      $icon   = $typeIcons[$r['type']]  ?? '📦';
      $isOwn  = $r['user_id'] == $uid;
      $uploader = $isOwn ? 'You' : htmlspecialchars($r['first_name'].' '.$r['last_name']);
      $when   = date('M j, Y', strtotime($r['uploaded_at']));
    ?>
    <div class="rc">
      <div class="rc-icon"><?=$icon?></div>
      <div class="rc-title"><?=htmlspecialchars($r['title'])?></div>
      <div class="rc-meta">
        Uploaded by <?=$uploader?> · <?=$typeLabels[$r['type']]??'File'?> · <?=$when?>
        <?php if($r['group_name']): ?>
          <br><?=$isOwn?'Shared':'In'?> group: <?=htmlspecialchars($r['group_name'])?>
        <?php endif; ?>
        <?php if($isOwn): ?><br><span class="mine-badge">My Upload</span><?php endif; ?>
        <?php if($r['group_name'] && !$r['is_private']): ?><br><span class="shared-badge">👥 Group Shared</span><?php endif; ?>
      </div>
      <div class="rc-footer">
        <?php if($r['course_code']): ?>
          <span class="course-tag" style="background:<?=htmlspecialchars($r['color']??'#4f8ef7')?>22;color:<?=htmlspecialchars($r['color']??'#4f8ef7')?>"><?=htmlspecialchars($r['course_code'])?></span>
        <?php else: ?>
          <span class="course-tag" style="background:rgba(120,120,120,0.1);color:var(--muted)">General</span>
        <?php endif; ?>
        <div class="rc-actions">
          <?php if($r['file_path'] && file_exists($r['file_path'])): ?>
            <a href="<?=htmlspecialchars($r['file_path'])?>" class="rc-btn" download>⬇ Download</a>
            <a href="<?=htmlspecialchars($r['file_path'])?>" class="rc-btn" target="_blank">👁 View</a>
          <?php elseif($r['url']): ?>
            <a href="<?=htmlspecialchars($r['url'])?>" class="rc-btn" target="_blank" rel="noopener">🔗 Open</a>
          <?php endif; ?>
          <?php if($isOwn): ?>
            <a href="?delete=<?=$r['id']?>" class="rc-btn danger" onclick="return confirm('Delete this resource?')">🗑</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>


<div class="modal-overlay" id="addModal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h3>Add Resource</h3>
    <form method="POST" action="resources.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add_resource">

      <div class="fg"><label>Title *</label><input type="text" name="title" placeholder="e.g. Week 7 Lecture Notes" required></div>

      
      <div class="fg">
        <label>Upload a File</label>
        <div class="drop-area" onclick="document.getElementById('fileInput').click()"
             ondragover="event.preventDefault();this.classList.add('drag')"
             ondragleave="this.classList.remove('drag')"
             ondrop="event.preventDefault();this.classList.remove('drag');document.getElementById('fileInput').files=event.dataTransfer.files;updateFileName()">
          <p>📎 Click to browse or drag & drop<br><span style="font-size:0.72rem">PDF, DOC, PPT, images, ZIP — max 20MB</span></p>
        </div>
        <input type="file" id="fileInput" name="file" onchange="updateFileName()" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt">
        <div class="file-chosen" id="fileChosen"></div>
      </div>

      <div class="divider">or add a link</div>

      <div class="fg"><label>URL / Link</label><input type="url" name="url" placeholder="https://..."></div>

      <div class="fg-row">
        <div class="fg"><label>Course</label>
          <select name="course_id">
            <option value="0">No course</option>
            <?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?>
              <option value="<?=$c['id']?>"><?=htmlspecialchars($c['course_code'].' — '.$c['course_name'])?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="fg"><label>Share With Group</label>
          <select name="group_id">
            <option value="0">No group</option>
            <?php while($g=$myGroups->fetch_assoc()): ?>
              <option value="<?=$g['id']?>"><?=htmlspecialchars($g['name'])?></option>
            <?php endwhile; ?>
          </select>
        </div>
      </div>

      <div class="fg">
        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer">
          <input type="checkbox" name="is_private" style="width:auto">
          🔒 Make private (only visible to me)
        </label>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-primary">Add Resource</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openModal(){document.getElementById('addModal').classList.add('open');}
  function closeModal(){document.getElementById('addModal').classList.remove('open');}
  function updateFileName(){
    const f=document.getElementById('fileInput').files[0];
    document.getElementById('fileChosen').textContent = f ? '✅ '+f.name : '';
  }
</script>
</body>
</html>
