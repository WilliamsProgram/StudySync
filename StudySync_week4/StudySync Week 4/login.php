<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();
}

if (isset($_SESSION['user_id'])) {
    redirectTo('dashboard.php');
}

$error = '';
$oldEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = cleanEmail($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);
    $oldEmail = $email;

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } elseif (!validEmailAddress($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare('SELECT id, first_name, last_name, password_hash FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $userRow = fetchSingleRow($stmt);

        if ($userRow && password_verify($password, $userRow['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $userRow['id'];
            $_SESSION['user_name'] = $userRow['first_name'] . ' ' . $userRow['last_name'];
            $_SESSION['remember_me'] = $remember ? 1 : 0;
            redirectTo('dashboard.php');
        }

        $error = 'Invalid email or password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log In — StudySync</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-login">
<div class="page-wrap">
  <div class="left-panel">
    <a href="dashboard.php" class="panel-logo" aria-label="Go to dashboard"><img src="assets/Studysync.png" alt="StudySync logo"></a>
    <div class="panel-tagline">Welcome<br>Back to your <em>academic hub</em></div>
    <p class="panel-sub">Pick up right where you left off. Your assignments, schedule, and study groups are all waiting.</p>
    <div class="features-mini">
      <div class="feat-item"><span>📚</span><div><strong>Track assignments</strong> across all courses</div></div>
      <div class="feat-item"><span>🗓️</span><div><strong>Smart schedule</strong> built around your deadlines</div></div>
      <div class="feat-item"><span>💬</span><div><strong>Study groups</strong> for every course</div></div>
    </div>
  </div>

  <div class="right-panel">
    <div class="form-box">
      <h2>Log In</h2>
      <p class="subtitle">New to StudySync? <a href="register.php">Create a free account</a></p>

      <?php if (!empty($error)): ?>
        <div class="error-msg">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if (isset($_GET['registered'])): ?>
        <div class="success-msg">✅ Account created! You can now log in.</div>
      <?php endif; ?>

      <form method="POST" action="login.php">
      <?= csrfField() ?>
        <div class="form-group">
          <label for="email">Email Address</label>
          <div class="input-wrap">
            <span class="input-icon">✉️</span>
            <input type="email" id="email" name="email" placeholder="student@ncu.edu.jm"
                   value="<?= e($oldEmail) ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-wrap">
            <span class="input-icon">🔒</span>
            <input type="password" id="password" name="password" placeholder="Enter your password" required>
            <button class="toggle-pw" onclick="togglePw()" type="button">Show</button>
          </div>
        </div>

        <div class="form-options">
          <label class="checkbox-wrap"><input type="checkbox" name="remember"> Remember me</label>
          <a href="#" class="forgot-link">Forgot password?</a>
        </div>

        <button type="submit" class="btn-submit">Log In to StudySync</button>
      </form>

      <div class="divider">or</div>
      <div class="register-link">Don't have an account? <a href="register.php">Sign up free</a></div>
    </div>
  </div>
</div>

<script>
  function togglePw(){
    const pw=document.getElementById('password'),btn=document.querySelector('.toggle-pw');
    pw.type=pw.type==='password'?'text':'password';
    btn.textContent=pw.type==='password'?'Show':'Hide';
  }
</script>
</body>
</html>
