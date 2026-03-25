<?php
require_once 'config.php';

 
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = cleanEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, first_name, last_name, password_hash FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            redirectTo('dashboard.php');
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log In — StudySync</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<style>
  :root{--bg:#0b0f1a;--surface:#111827;--surface2:#1a2235;--accent:#4f8ef7;--accent2:#38d9a9;--text:#e8edf7;--muted:#7a8ba8;--border:rgba(79,142,247,0.15);--glow:rgba(79,142,247,0.25);--danger:#f77a4f;}
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;background-image:radial-gradient(ellipse 60% 50% at 30% 50%,rgba(79,142,247,0.08),transparent 60%),radial-gradient(ellipse 40% 40% at 70% 80%,rgba(56,217,169,0.06),transparent 60%);}
  .page-wrap{display:grid;grid-template-columns:1fr 1fr;min-height:100vh;width:100%;}
  .left-panel{background:linear-gradient(160deg,rgba(79,142,247,0.12),rgba(56,217,169,0.06));border-right:1px solid var(--border);display:flex;flex-direction:column;justify-content:center;padding:4rem;position:relative;overflow:hidden;}
  .left-panel::before{content:'';position:absolute;width:400px;height:400px;background:radial-gradient(circle,rgba(79,142,247,0.15),transparent 70%);top:-100px;right:-100px;border-radius:50%;}
  .left-panel::after{content:'';position:absolute;width:300px;height:300px;background:radial-gradient(circle,rgba(56,217,169,0.1),transparent 70%);bottom:-50px;left:-50px;border-radius:50%;}
  .panel-logo{font-family:'Syne',sans-serif;font-weight:800;font-size:2rem;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:3rem;text-decoration:none;display:inline-block;position:relative;z-index:1;}
  .panel-tagline{font-family:'Syne',sans-serif;font-size:2.4rem;font-weight:800;line-height:1.2;margin-bottom:1.25rem;position:relative;z-index:1;}
  .panel-tagline em{font-style:normal;color:var(--accent2);}
  .panel-sub{color:var(--muted);font-size:1rem;line-height:1.7;max-width:380px;position:relative;z-index:1;}
  .features-mini{margin-top:3rem;display:flex;flex-direction:column;gap:1rem;position:relative;z-index:1;}
  .feat-item{display:flex;align-items:center;gap:0.75rem;font-size:0.9rem;color:var(--muted);}
  .feat-item strong{color:var(--text);}
  .right-panel{display:flex;align-items:center;justify-content:center;padding:3rem 4rem;}
  .form-box{width:100%;max-width:420px;}
  .form-box h2{font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;margin-bottom:0.5rem;}
  .form-box .subtitle{color:var(--muted);font-size:0.9rem;margin-bottom:2rem;}
  .form-box .subtitle a{color:var(--accent);text-decoration:none;}
  .form-group{margin-bottom:1.25rem;}
  label{display:block;font-size:0.85rem;font-weight:500;color:var(--muted);margin-bottom:0.5rem;}
  input[type=email],input[type=password]{width:100%;padding:0.8rem 1rem;border-radius:10px;background:var(--surface);border:1px solid var(--border);color:var(--text);font-family:'DM Sans',sans-serif;font-size:0.95rem;transition:all .2s;outline:none;}
  input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,142,247,0.1);}
  input::placeholder{color:var(--muted);opacity:0.6;}
  .input-wrap{position:relative;}
  .input-icon{position:absolute;left:1rem;top:50%;transform:translateY(-50%);font-size:1rem;pointer-events:none;}
  .input-wrap input{padding-left:2.8rem;}
  .toggle-pw{position:absolute;right:1rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);font-size:0.85rem;}
  .form-options{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;}
  .checkbox-wrap{display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;color:var(--muted);}
  .forgot-link{font-size:0.85rem;color:var(--accent);text-decoration:none;}
  .btn-submit{width:100%;padding:0.9rem;border-radius:10px;background:linear-gradient(135deg,var(--accent),#3a6fd4);color:#fff;font-family:'DM Sans',sans-serif;font-size:1rem;font-weight:600;border:none;cursor:pointer;transition:all .2s;box-shadow:0 0 24px var(--glow);}
  .btn-submit:hover{transform:translateY(-1px);}
  .divider{display:flex;align-items:center;gap:1rem;margin:1.5rem 0;color:var(--muted);font-size:0.8rem;}
  .divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border);}
  .register-link{text-align:center;font-size:0.9rem;color:var(--muted);}
  .register-link a{color:var(--accent);text-decoration:none;font-weight:600;}
  .error-msg{background:rgba(247,122,79,0.1);border:1px solid rgba(247,122,79,0.3);border-radius:8px;padding:0.75rem 1rem;font-size:0.85rem;color:var(--danger);margin-bottom:1.25rem;}
  .success-msg{background:rgba(56,217,169,0.1);border:1px solid rgba(56,217,169,0.3);border-radius:8px;padding:0.75rem 1rem;font-size:0.85rem;color:var(--accent2);margin-bottom:1.25rem;}
  @media(max-width:768px){.page-wrap{grid-template-columns:1fr;}.left-panel{display:none;}.right-panel{padding:2rem;}}
</style>
</head>
<body>
<div class="page-wrap">
  <div class="left-panel">
    <a href="index.php" class="panel-logo">StudySync</a>
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
        <div class="form-group">
          <label for="email">Email Address</label>
          <div class="input-wrap">
            <span class="input-icon">✉️</span>
            <input type="email" id="email" name="email" placeholder="student@ncu.edu.jm"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
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
