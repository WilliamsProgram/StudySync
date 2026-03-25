<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error   = '';
$success = '';
$old     = [];  

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    $first      = trim($_POST['first_name']  ?? '');
    $last       = trim($_POST['last_name']   ?? '');
    $email      = cleanEmail($_POST['email'] ?? '');
    $student_id = trim($_POST['student_id']  ?? '');
    $year       = trim($_POST['year']        ?? '');
    $programme  = trim($_POST['programme']   ?? '');
    $password   = $_POST['password']  ?? '';
    $confirm    = $_POST['confirm_pw'] ?? '';

     
    if (empty($first) || empty($last) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
         
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = 'An account with this email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare(
                "INSERT INTO users (first_name, last_name, email, student_id, year, programme, password_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("sssssss", $first, $last, $email, $student_id, $year, $programme, $hash);

            if ($stmt->execute()) {
                redirectTo('login.php?registered=1');
            } else {
                $error = 'Something went wrong. Please try again.';
            }
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
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<style>
  :root{--bg:#0b0f1a;--surface:#111827;--surface2:#1a2235;--accent:#4f8ef7;--accent2:#38d9a9;--text:#e8edf7;--muted:#7a8ba8;--border:rgba(79,142,247,0.15);--glow:rgba(79,142,247,0.25);--danger:#f77a4f;}
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;background-image:radial-gradient(ellipse 60% 50% at 70% 50%,rgba(56,217,169,0.08),transparent 60%),radial-gradient(ellipse 40% 40% at 20% 80%,rgba(79,142,247,0.06),transparent 60%);}
  .page-wrap{display:grid;grid-template-columns:1fr 1fr;min-height:100vh;width:100%;}
  .left-panel{background:linear-gradient(160deg,rgba(56,217,169,0.1),rgba(79,142,247,0.06));border-right:1px solid var(--border);display:flex;flex-direction:column;justify-content:center;padding:4rem;position:relative;overflow:hidden;}
  .left-panel::before{content:'';position:absolute;width:350px;height:350px;background:radial-gradient(circle,rgba(56,217,169,0.12),transparent 70%);top:-80px;left:-80px;border-radius:50%;}
  .panel-logo{font-family:'Syne',sans-serif;font-weight:800;font-size:2rem;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:3rem;text-decoration:none;display:inline-block;position:relative;z-index:1;}
  .panel-tagline{font-family:'Syne',sans-serif;font-size:2.2rem;font-weight:800;line-height:1.2;margin-bottom:1.25rem;position:relative;z-index:1;}
  .panel-tagline em{font-style:normal;color:var(--accent2);}
  .panel-sub{color:var(--muted);font-size:1rem;line-height:1.7;max-width:380px;position:relative;z-index:1;}
  .steps-mini{margin-top:3rem;display:flex;flex-direction:column;gap:1.25rem;position:relative;z-index:1;}
  .step-item{display:flex;align-items:flex-start;gap:1rem;}
  .step-dot{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--accent2),#2ab080);display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;flex-shrink:0;}
  .step-text strong{font-size:0.9rem;display:block;}.step-text span{font-size:0.8rem;color:var(--muted);}
  .right-panel{display:flex;align-items:center;justify-content:center;padding:3rem 4rem;overflow-y:auto;}
  .form-box{width:100%;max-width:460px;padding:2rem 0;}
  .form-box h2{font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;margin-bottom:0.4rem;}
  .subtitle{color:var(--muted);font-size:0.9rem;margin-bottom:2rem;}
  .subtitle a{color:var(--accent);text-decoration:none;}
  .form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
  .form-group{margin-bottom:1.15rem;}
  label{display:block;font-size:0.82rem;font-weight:500;color:var(--muted);margin-bottom:0.45rem;}
  input,select{width:100%;padding:0.8rem 1rem;border-radius:10px;background:var(--surface);border:1px solid var(--border);color:var(--text);font-family:'DM Sans',sans-serif;font-size:0.9rem;transition:all .2s;outline:none;-webkit-appearance:none;}
  input:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,142,247,0.1);}
  input.error{border-color:var(--danger);}
  input::placeholder{color:var(--muted);opacity:0.6;}
  select option{background:var(--surface);}
  .input-wrap{position:relative;}
  .input-icon{position:absolute;left:1rem;top:50%;transform:translateY(-50%);font-size:1rem;pointer-events:none;}
  .input-wrap input,.input-wrap select{padding-left:2.8rem;}
  .toggle-pw{position:absolute;right:1rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);font-size:0.82rem;}
  .pw-strength{height:3px;border-radius:3px;background:var(--surface2);margin-top:0.4rem;overflow:hidden;}
  .pw-bar{height:100%;width:0%;border-radius:3px;transition:all .3s;}
  .terms-wrap{display:flex;align-items:flex-start;gap:0.65rem;font-size:0.83rem;color:var(--muted);margin-bottom:1.5rem;}
  .terms-wrap input{width:auto;margin-top:2px;}
  .terms-wrap a{color:var(--accent);text-decoration:none;}
  .btn-submit{width:100%;padding:0.9rem;border-radius:10px;background:linear-gradient(135deg,var(--accent2),#2ab080);color:#0b1a14;font-family:'DM Sans',sans-serif;font-size:1rem;font-weight:700;border:none;cursor:pointer;transition:all .2s;box-shadow:0 0 24px rgba(56,217,169,0.2);}
  .btn-submit:hover{transform:translateY(-1px);}
  .login-link{text-align:center;font-size:0.9rem;color:var(--muted);margin-top:1.25rem;}
  .login-link a{color:var(--accent);text-decoration:none;font-weight:600;}
  .error-msg{background:rgba(247,122,79,0.1);border:1px solid rgba(247,122,79,0.3);border-radius:8px;padding:0.75rem 1rem;font-size:0.85rem;color:var(--danger);margin-bottom:1.25rem;}
  @media(max-width:768px){.page-wrap{grid-template-columns:1fr;}.left-panel{display:none;}.right-panel{padding:2rem;}.form-row{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="page-wrap">
  <div class="left-panel">
    <a href="index.php" class="panel-logo">StudySync</a>
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
