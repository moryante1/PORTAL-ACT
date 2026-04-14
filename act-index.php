<?php
// ============================================================
//  act-index.php  —  Client Activation Page
//  Place this file on each client server: /var/www/html/act-index.php
// ============================================================

define('ACT_KEY',  getenv('ACT_KEY')  ?: 'my-secret-activation-key');
define('ACT_LOG',  __DIR__ . '/activation_log.json');

$ip     = $_SERVER['REMOTE_ADDR'] ?? '?';
$host   = gethostname();
$status = 'inactive';
$msg    = '';

$log = json_decode(@file_get_contents(ACT_LOG), true) ?: [];

// Activation action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim($_POST['key'] ?? '');
    if ($key === ACT_KEY) {
        $entry = [
            'ip'         => $ip,
            'hostname'   => $host,
            'activated'  => date('Y-m-d H:i:s'),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'status'     => 'active',
        ];
        $log[$ip] = $entry;
        file_put_contents(ACT_LOG, json_encode($log, JSON_PRETTY_PRINT));
        $status = 'active';
        $msg    = 'تم التفعيل بنجاح!';
    } else {
        $status = 'error';
        $msg    = 'مفتاح التفعيل غير صحيح';
    }
}

// Check if already activated
if (isset($log[$ip])) {
    $status = $log[$ip]['status'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تفعيل النظام</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#0a0c10; --surface:#111318; --card:#161a22;
  --border:#1e2330; --border2:#2a3040;
  --text:#e2e8f0; --muted:#6b7a96;
  --accent:#3b82f6; --green:#22c55e; --red:#ef4444; --amber:#f59e0b;
  --sans:'IBM Plex Sans Arabic',sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;display:flex;align-items:center;justify-content:center;}
.box{background:var(--surface);border:1px solid var(--border2);border-radius:20px;padding:2.5rem;width:440px;max-width:95vw;}
.icon{font-size:48px;text-align:center;margin-bottom:1rem;}
h1{font-size:22px;font-weight:600;text-align:center;margin-bottom:.3rem;}
.sub{font-size:13px;color:var(--muted);text-align:center;margin-bottom:1.8rem;}
.info-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px;}
.info-row .k{color:var(--muted);}
.info-row .v{font-family:monospace;color:var(--amber);}
.status-badge{display:flex;align-items:center;justify-content:center;gap:8px;margin:1.5rem 0;padding:12px;border-radius:10px;font-weight:500;}
.status-badge.active  {background:#052e16;color:var(--green);border:1px solid #14532d;}
.status-badge.inactive{background:#2d1f00;color:var(--amber);border:1px solid #78350f;}
.status-badge.error   {background:#2d0a0a;color:var(--red)  ;border:1px solid #7f1d1d;}
.form-group{margin-top:1rem;}
label{font-size:13px;color:var(--muted);display:block;margin-bottom:6px;}
input[type=text],input[type=password]{width:100%;background:var(--card);border:1px solid var(--border2);border-radius:8px;padding:10px 14px;color:var(--text);font-family:monospace;font-size:14px;direction:ltr;}
input:focus{outline:none;border-color:var(--accent);}
.btn-act{width:100%;margin-top:12px;background:var(--accent);border:none;border-radius:10px;padding:12px;color:#fff;font-family:var(--sans);font-size:16px;font-weight:500;cursor:pointer;transition:background .2s;}
.btn-act:hover{background:#1d4ed8;}
.dot{width:10px;height:10px;border-radius:50%;background:currentColor;}
</style>
</head>
<body>
<div class="box">
  <div class="icon">
    <?php if ($status === 'active') echo '✅';
    elseif ($status === 'error')  echo '❌';
    else echo '🔑'; ?>
  </div>
  <h1>تفعيل النظام</h1>
  <p class="sub">أدخل مفتاح التفعيل لتفعيل هذا الجهاز</p>

  <div class="info-row"><span class="k">عنوان IP</span><span class="v"><?= htmlspecialchars($ip) ?></span></div>
  <div class="info-row"><span class="k">اسم الجهاز</span><span class="v"><?= htmlspecialchars($host) ?></span></div>
  <div class="info-row"><span class="k">التاريخ</span><span class="v"><?= date('Y-m-d H:i') ?></span></div>

  <?php if ($msg): ?>
  <div class="status-badge <?= $status ?>">
    <div class="dot"></div>
    <?= htmlspecialchars($msg) ?>
  </div>
  <?php endif; ?>

  <?php if ($status === 'active'): ?>
  <div class="status-badge active">
    <div class="dot"></div>
    الجهاز مفعّل بنجاح
  </div>
  <?php else: ?>
  <form method="POST" class="form-group">
    <label>مفتاح التفعيل</label>
    <input type="password" name="key" placeholder="أدخل المفتاح هنا" autofocus>
    <button type="submit" class="btn-act">تفعيل الآن</button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
