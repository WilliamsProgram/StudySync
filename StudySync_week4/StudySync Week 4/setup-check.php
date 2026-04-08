<?php
require_once __DIR__ . '/deployment_checks.php';
$health = runDeploymentChecks();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>StudySync Setup Check</title>
<link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-setup-check">
<div class="wrap">
<h1>StudySync Setup Check</h1>
<p>Use this page after importing <strong>studysync_db.sql</strong> and setting database values in <strong>config.local.php</strong> if needed.</p>
<table>
<thead><tr><th>Check</th><th>Status</th><th>Value</th></tr></thead>
<tbody>
<?php foreach (($health['checks'] ?? []) as $name => $item): ?>
<tr>
<td><?= htmlspecialchars((string) $name) ?></td>
<td class="<?= !empty($item['ok']) ? 'ok' : 'bad' ?>"><?= !empty($item['ok']) ? 'PASS' : 'FAIL' ?></td>
<td><pre style="white-space:pre-wrap;margin:0;color:#e8edf7"><?= htmlspecialchars(is_array($item['value'] ?? null) ? json_encode($item['value']) : (string) ($item['value'] ?? '')) ?></pre></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<div class="note">Overall status: <strong class="<?= !empty($health['ok']) ? 'ok' : 'bad' ?>"><?= !empty($health['ok']) ? 'READY' : 'NOT READY' ?></strong></div>
</div>
</body>
</html>
