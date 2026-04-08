<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
}

if (isset($_SESSION['user_id'])) {
    redirectTo('dashboard.php');
}

$error = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first      = cleanNameText($_POST['first_name'] ?? '', 80);
    $last       = cleanNameText($_POST['last_name'] ?? '', 80);
    $email      = cleanEmail($_POST['email'] ?? '');
    $student_id = cleanStudentId($_POST['student_id'] ?? '', 30);
    $year       = cleanText($_POST['year'] ?? '', 40);
    $programme  = cleanText($_POST['programme'] ?? '', 120);
    $password   = (string) ($_POST['password'] ?? '');
    $confirm    = (string) ($_POST['confirm_pw'] ?? '');

    $old = [
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'student_id' => $student_id,
        'year' => $year,
        'programme' => $programme,
    ];

    if ($first === '' || $last === '' || $email === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!validEmailAddress($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $check = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->bind_param('s', $email);
        $existing = fetchSingleRow($check);

        if ($existing) {
            $error = 'An account with this email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare(
                'INSERT INTO users (first_name, last_name, email, student_id, year, programme, password_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sssssss', $first, $last, $email, $student_id, $year, $programme, $hash);
            if ($stmt->execute()) {
                $stmt->close();
                redirectTo('login.php?registered=1');
            }
            $stmt->close();
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — StudySync</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-register">
<div class="page-wrap">
  <div class="left-panel">
    <a href="dashboard.php" class="panel-logo" aria-label="Go to dashboard"><img src="assets/Studysync.png" alt="StudySync logo"></a>
    <div class="panel-tagline">Join thousands of<br><em>smarter students</em></div>
    <p class="panel-sub">Create your free account and bring your entire academic life into one organised, intelligent platform.</p>
    <div class="steps-mini">
      <div class="step-item"><div class="step-dot">1</div><div class="step-text"><strong>Create Your Account</strong><span>Takes less than 2 minutes</span></div></div>
      <div class="step-item"><div class="step-dot">2</div><div class="step-text"><strong>Add Your Courses</strong><span>Import your semester schedule</span></div></div>
      <div class="step-item"><div class="step-dot">3</div><div class="step-text"><strong>Never Miss a Deadline</strong><span>Smart reminders keep you on track</span></div></div>
    </div>
  </div>

  <div class="right-panel">
    <div class="form-box">
      <h2>Create Account</h2>
      <p class="subtitle">Already have an account? <a href="login.php">Log in here</a></p>

      <?php if (!empty($error)): ?>
        <div class="error-msg">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="register.php">
      <?= csrfField() ?>
        <div class="form-row">
          <div class="form-group">
            <label>First Name *</label>
            <div class="input-wrap">
              <span class="input-icon">👤</span>
              <input type="text" name="first_name" placeholder="First name"
                     value="<?= htmlspecialchars($old['first_name'] ?? '') ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label>Last Name *</label>
            <input type="text" name="last_name" placeholder="Last name"
                   value="<?= htmlspecialchars($old['last_name'] ?? '') ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label>NCU Email Address *</label>
          <div class="input-wrap">
            <span class="input-icon">✉️</span>
            <input type="email" name="email" placeholder="student@ncu.edu.jm"
                   value="<?= htmlspecialchars($old['email'] ?? '') ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label>Student ID</label>
          <div class="input-wrap">
            <span class="input-icon">🎓</span>
            <input type="text" name="student_id" placeholder="e.g. 2023001234"
                   value="<?= htmlspecialchars($old['student_id'] ?? '') ?>">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Year of Study</label>
            <div class="input-wrap">
              <span class="input-icon">📅</span>
              <select name="year">
                <option value="">Select year</option>
                <?php foreach(['Year 1','Year 2','Year 3','Year 4','Postgraduate'] as $y): ?>
                  <option value="<?=$y?>" <?= ($old['year']??'')===$y?'selected':'' ?>><?=$y?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Programme</label>
            <select name="programme">
              <option value="">Select programme</option>
              <?php
              $programmes = ['B.Sc. Information Technology','B.Sc. Computer Science','B.Sc. Nursing','B.Sc. Business','B.Ed. Education','Other'];
              foreach($programmes as $p): ?>
                <option value="<?=$p?>" <?= ($old['programme']??'')===$p?'selected':'' ?>><?=$p?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>Password * (min. 8 characters)</label>
          <div class="input-wrap">
            <span class="input-icon">🔒</span>
            <input type="password" id="password" name="password"
                   placeholder="Create a strong password" oninput="checkStrength(this.value)" required>
            <button class="toggle-pw" onclick="togglePw()" type="button">Show</button>
          </div>
          <div class="pw-strength"><div class="pw-bar" id="pwBar"></div></div>
        </div>

        <div class="form-group">
          <label>Confirm Password *</label>
          <div class="input-wrap">
            <span class="input-icon">🔒</span>
            <input type="password" name="confirm_pw" placeholder="Confirm your password" required>
          </div>
        </div>

        <label class="terms-wrap">
          <input type="checkbox" required> I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>
        </label>

        <button type="submit" class="btn-submit">Create My Account →</button>
      </form>
      <div class="login-link">Already a member? <a href="login.php">Sign in</a></div>
    </div>
  </div>
</div>

<script>
  function togglePw(){const pw=document.getElementById('password'),btn=document.querySelector('.toggle-pw');pw.type=pw.type==='password'?'text':'password';btn.textContent=pw.type==='password'?'Show':'Hide';}
  function checkStrength(v){const bar=document.getElementById('pwBar');let s=0;if(v.length>6)s+=25;if(v.length>10)s+=25;if(/[A-Z]/.test(v)&&/[0-9]/.test(v))s+=25;if(/[^A-Za-z0-9]/.test(v))s+=25;bar.style.width=s+'%';bar.style.background=s<50?'#f77a4f':s<75?'#febc2e':'#38d9a9';}
</script>
</body>
</html>
