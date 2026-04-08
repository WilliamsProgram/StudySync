<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
}

requireLogin();
$uid  = (int) $_SESSION['user_id'];
$user = getUser($conn);
$init = initials($user['first_name'], $user['last_name']);
$uploadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_resource') {
    $title = cleanText($_POST['title'] ?? '', 160);
    $type = cleanEnum($_POST['res_type'] ?? 'other', ['pdf', 'image', 'link', 'doc', 'slides', 'other'], 'other');
    $cid = cleanInt($_POST['course_id'] ?? 0, 0);
    $gid = cleanInt($_POST['group_id'] ?? 0, 0);
    $url = cleanUrl($_POST['url'] ?? '');
    $is_priv = isset($_POST['is_private']) ? 1 : 0;
    $file_path = '';

    if (!empty($_FILES['file']['name'])) {
        $uploadDir = 'uploads/resources/';
        if (!ensureDirectory($uploadDir)) {
            $uploadError = 'Upload folder is not writable.';
        } else {
            protectUploadDirectory($uploadDir);
            $ext = strtolower(pathinfo((string) $_FILES['file']['name'], PATHINFO_EXTENSION));
            $allowed = [
                'pdf' => ['type' => 'pdf', 'mimes' => ['application/pdf']],
                'doc' => ['type' => 'doc', 'mimes' => ['application/msword', 'application/octet-stream']],
                'docx' => ['type' => 'doc', 'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
                'ppt' => ['type' => 'slides', 'mimes' => ['application/vnd.ms-powerpoint', 'application/octet-stream']],
                'pptx' => ['type' => 'slides', 'mimes' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']],
                'xls' => ['type' => 'other', 'mimes' => ['application/vnd.ms-excel', 'application/octet-stream']],
                'xlsx' => ['type' => 'other', 'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
                'jpg' => ['type' => 'image', 'mimes' => ['image/jpeg']],
                'jpeg' => ['type' => 'image', 'mimes' => ['image/jpeg']],
                'png' => ['type' => 'image', 'mimes' => ['image/png']],
                'gif' => ['type' => 'image', 'mimes' => ['image/gif']],
                'zip' => ['type' => 'other', 'mimes' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream']],
                'txt' => ['type' => 'other', 'mimes' => ['text/plain', 'application/octet-stream']],
            ];

            if (!isset($allowed[$ext])) {
                $uploadError = 'That file type is not allowed.';
            } elseif (cleanInt($_FILES['file']['size'] ?? 0, 0) > 20 * 1024 * 1024) {
                $uploadError = 'File is too large (max 20MB).';
            } else {
                $mime = '';
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) {
                        $mime = (string) finfo_file($finfo, (string) $_FILES['file']['tmp_name']);
                        finfo_close($finfo);
                    }
                }

                if ($mime !== '' && !in_array($mime, $allowed[$ext]['mimes'], true)) {
                    $uploadError = 'Uploaded file content does not match the selected file type.';
                } else {
                    $safeBase = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) $_FILES['file']['name']));
                    $dest = $uploadDir . $safeBase;
                    if (move_uploaded_file((string) $_FILES['file']['tmp_name'], $dest)) {
                        $file_path = $dest;
                        $type = $allowed[$ext]['type'];
                    } else {
                        $uploadError = 'Upload failed. Check folder permissions.';
                    }
                }
            }
        }
    }

    if (!userOwnsCourse($conn, $uid, $cid)) {
        $uploadError = 'Please select a valid course.';
    } elseif (!userIsGroupMember($conn, $uid, $gid)) {
        $uploadError = 'Please select a valid study group.';
    } elseif ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        $uploadError = 'Please enter a valid link.';
    }
    if ($title === '') {
        $uploadError = 'Title is required.';
    } elseif ($file_path === '' && $url === '' && $uploadError === '') {
        $uploadError = 'Please upload a file or provide a link.';
    }

    if ($uploadError === '') {
        $stmt = $conn->prepare(
            'INSERT INTO resources (user_id, group_id, course_id, title, type, file_path, url, is_private)
             VALUES (?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiissssi', $uid, $gid, $cid, $title, $type, $file_path, $url, $is_priv);
        $stmt->execute();
        $resourceId = (int) $conn->insert_id;
        $stmt->close();

        if ($gid > 0 && !$is_priv) {
            $notifyStmt = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ? AND user_id <> ?');
            $notifyStmt->bind_param('ii', $gid, $uid);
            $memberRows = fetchAllRows($notifyStmt);
            foreach ($memberRows as $member) {
                createNotification($conn, (int) $member['user_id'], 'A new resource was shared: ' . $title . '.', 'resource', 'resource_' . $resourceId . '_u' . $member['user_id'], 'resource', $resourceId);
            }
        }

        redirectTo('resources.php?added=1');
    }
}

if (isset($_GET['delete'])) {
    $rid = cleanInt($_GET['delete'] ?? 0, 1);
    if ($rid > 0) {
        $stmt = $conn->prepare('SELECT file_path FROM resources WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $rid, $uid);
        $res = fetchSingleRow($stmt);

        if ($res) {
            $safePath = safeUploadedResourcePath($res['file_path'] ?? '');
            if ($safePath && file_exists($safePath)) {
                @unlink($safePath);
            }
            $delStmt = $conn->prepare('DELETE FROM resources WHERE id = ? AND user_id = ?');
            $delStmt->bind_param('ii', $rid, $uid);
            $delStmt->execute();
            $delStmt->close();
        }
    }
    redirectTo('resources.php');
}

$filterCourse = cleanInt($_GET['course'] ?? 0, 0);
$filterType = cleanEnum($_GET['type'] ?? '', ['', 'pdf', 'image', 'link', 'doc', 'slides', 'other'], '');
$search = cleanSearchTerm($_GET['search'] ?? '', 100);
$mine = isset($_GET['mine']);

$sql = 'SELECT r.*, c.course_code, c.color, u.first_name, u.last_name, sg.name AS group_name
        FROM resources r
        LEFT JOIN courses c ON r.course_id = c.id
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN study_groups sg ON r.group_id = sg.id
        WHERE (r.user_id = ? OR r.is_private = 0)';
$types = 'i';
$params = [$uid];

if ($filterCourse > 0) {
    $sql .= ' AND r.course_id = ?';
    $types .= 'i';
    $params[] = $filterCourse;
}
if ($filterType !== '') {
    $sql .= ' AND r.type = ?';
    $types .= 's';
    $params[] = $filterType;
}
if ($mine) {
    $sql .= ' AND r.user_id = ?';
    $types .= 'i';
    $params[] = $uid;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $sql .= ' AND (r.title LIKE ? OR c.course_code LIKE ?)';
    $types .= 'ss';
    $params[] = $like;
    $params[] = $like;
}
$sql .= ' ORDER BY r.uploaded_at DESC';
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$resourceRows = fetchAllRows($stmt);

$stmt = $conn->prepare('SELECT * FROM courses WHERE user_id = ? ORDER BY course_code');
$stmt->bind_param('i', $uid);
$courseRows = fetchAllRows($stmt);
$stmt = $conn->prepare('SELECT sg.* FROM study_groups sg JOIN group_members gm ON sg.id = gm.group_id WHERE gm.user_id = ?');
$stmt->bind_param('i', $uid);
$groupRows = fetchAllRows($stmt);
$urgent = $conn->query("SELECT COUNT(*) AS c FROM assignments WHERE user_id = $uid AND status = 'pending' AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetch_assoc()['c'];

$typeIcons = ['pdf' => '📄', 'doc' => '📝', 'slides' => '📊', 'image' => '🖼️', 'link' => '🔗', 'other' => '📦'];
$typeLabels = ['pdf' => 'PDF', 'doc' => 'Document', 'slides' => 'Slides', 'image' => 'Image', 'link' => 'Link', 'other' => 'File'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resources — StudySync</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-resources">

<aside class="sidebar">
  <a class="sidebar-logo" href="dashboard.php" aria-label="Go to dashboard"><img src="assets/Studysync.png" alt="StudySync logo"></a>
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

  <!-- UPLOAD DROP ZONE (just opens modal) -->
  <div class="upload-zone" onclick="openModal()"
       ondragover="event.preventDefault();this.classList.add('drag')"
       ondragleave="this.classList.remove('drag')"
       ondrop="event.preventDefault();this.classList.remove('drag');openModal()">
    <div class="icon">☁️</div>
    <h3>Drop files here or click to upload</h3>
    <p>Share notes, PDFs, slides, links, and more with your study groups</p>
  </div>

  <!-- FILTERS -->
  <div class="filter-row">
    <a href="resources.php"       class="chip <?=!$filterCourse&&!$filterType&&!$mine?'active':''?>">All</a>
    <a href="?mine=1"             class="chip <?=$mine?'active':''?>">My Uploads</a>
    <a href="?type=pdf"           class="chip <?=$filterType==='pdf'?'active':''?>">📄 PDFs</a>
    <a href="?type=slides"        class="chip <?=$filterType==='slides'?'active':''?>">📊 Slides</a>
    <a href="?type=link"          class="chip <?=$filterType==='link'?'active':''?>">🔗 Links</a>
    <a href="?type=image"         class="chip <?=$filterType==='image'?'active':''?>">🖼️ Images</a>
    <?php foreach ($courseRows as $c): ?>
      <a href="?course=<?=$c['id']?>" class="chip <?=$filterCourse==$c['id']?'active':''?>"><?=htmlspecialchars($c['course_code'])?></a>
    <?php endforeach; ?>
    <form class="search-form" method="GET">
      <input class="search-input" name="search" placeholder="🔍 Search..." value="<?=htmlspecialchars($search)?>">
    </form>
  </div>

  <!-- RESOURCE GRID -->
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

<!-- ADD RESOURCE MODAL -->
<div class="modal-overlay" id="addModal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h3>Add Resource</h3>
    <form method="POST" action="resources.php" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_resource">

      <div class="fg"><label>Title *</label><input type="text" name="title" placeholder="e.g. Week 7 Lecture Notes" required></div>

      <!-- File Upload -->
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
            <?php foreach ($courseRows as $c): ?>
              <option value="<?=$c['id']?>"><?=htmlspecialchars($c['course_code'].' — '.$c['course_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>Share With Group</label>
          <select name="group_id">
            <option value="0">No group</option>
            <?php foreach ($groupRows as $g): ?>
              <option value="<?=$g['id']?>"><?=htmlspecialchars($g['name'])?></option>
            <?php endforeach; ?>
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
