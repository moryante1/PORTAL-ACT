<?php
// ============================================================
//  Server Control Portal - index.php
//  Tailscale auto-discovery + SSH/Web launch + activation
// ============================================================

// ---- CONFIG ------------------------------------------------
define('TS_API_KEY',   getenv('TS_API_KEY') ?: 'YOUR_TAILSCALE_API_KEY');
define('TS_TAILNET',   getenv('TS_TAILNET')  ?: '-');          // '-' = default tailnet
define('ADMIN_PASS',   getenv('PORTAL_PASS') ?: 'admin123');   // change this!
define('WETTY_PORT',   '3000');                                 // web SSH port
define('SSH_USER',     'root');
// ---- END CONFIG --------------------------------------------

session_start();

// Simple auth
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASS) {
        $_SESSION['auth'] = true;
    } else {
        $login_error = 'كلمة المرور غير صحيحة';
    }
}
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

// Rename action
if (isset($_SESSION['auth']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename'])) {
    $names = json_decode(file_get_contents('names.json'), true) ?: [];
    $names[$_POST['node_id']] = htmlspecialchars(trim($_POST['new_name']));
    file_put_contents('names.json', json_encode($names));
    header('Location: /');
    exit;
}

// Fetch Tailscale machines via API
function fetchMachines(): array {
    if (TS_API_KEY === 'YOUR_TAILSCALE_API_KEY') {
        // Demo data when no API key set
        return [
            ['id'=>'node1','hostname'=>'iptv-main',   'addresses'=>['100.64.0.1'],'lastSeen'=>date('c'),'online'=>true, 'os'=>'linux','clientVersion'=>'1.60'],
            ['id'=>'node2','hostname'=>'panel-server', 'addresses'=>['100.64.0.2'],'lastSeen'=>date('c'),'online'=>true, 'os'=>'linux','clientVersion'=>'1.60'],
            ['id'=>'node3','hostname'=>'db-server',    'addresses'=>['100.64.0.3'],'lastSeen'=>date('c'),'online'=>true, 'os'=>'linux','clientVersion'=>'1.58'],
            ['id'=>'node4','hostname'=>'backup-01',    'addresses'=>['100.64.0.4'],'lastSeen'=>'2024-01-01T00:00:00Z','online'=>false,'os'=>'linux','clientVersion'=>'1.55'],
        ];
    }
    $url = "https://api.tailscale.com/api/v2/tailnet/" . TS_TAILNET . "/devices";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . TS_API_KEY],
        CURLOPT_TIMEOUT        => 8,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return [];
    $data = json_decode($res, true);
    return $data['devices'] ?? [];
}

$machines   = isset($_SESSION['auth']) ? fetchMachines() : [];
$customNames = json_decode(@file_get_contents('names.json'), true) ?: [];

$online  = array_filter($machines, fn($m) => $m['online'] ?? false);
$offline = array_filter($machines, fn($m) => !($m['online'] ?? false));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Server Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:       #0a0c10;
  --surface:  #111318;
  --card:     #161a22;
  --border:   #1e2330;
  --border2:  #2a3040;
  --text:     #e2e8f0;
  --muted:    #6b7a96;
  --accent:   #3b82f6;
  --accent2:  #1d4ed8;
  --green:    #22c55e;
  --green-bg: #052e16;
  --red:      #ef4444;
  --red-bg:   #2d0a0a;
  --amber:    #f59e0b;
  --amber-bg: #2d1f00;
  --mono:     'JetBrains Mono', monospace;
  --sans:     'IBM Plex Sans Arabic', sans-serif;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: var(--sans); min-height: 100vh; }

/* ─── LOGIN ─── */
.login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.login-box { background: var(--surface); border: 1px solid var(--border2); border-radius: 16px; padding: 2.5rem; width: 360px; text-align: center; }
.login-box .logo { font-size: 36px; margin-bottom: 1rem; }
.login-box h2 { font-size: 20px; font-weight: 500; margin-bottom: .3rem; }
.login-box p  { font-size: 13px; color: var(--muted); margin-bottom: 1.8rem; }
.login-box input { width: 100%; background: var(--card); border: 1px solid var(--border2); border-radius: 8px; padding: 10px 14px; color: var(--text); font-family: var(--sans); font-size: 14px; margin-bottom: 12px; direction: ltr; }
.login-box input:focus { outline: none; border-color: var(--accent); }
.btn-primary { width: 100%; background: var(--accent); border: none; border-radius: 8px; padding: 11px; color: #fff; font-family: var(--sans); font-size: 15px; font-weight: 500; cursor: pointer; transition: background .2s; }
.btn-primary:hover { background: var(--accent2); }
.error { color: var(--red); font-size: 13px; margin-top: 8px; }

/* ─── LAYOUT ─── */
.shell { display: grid; grid-template-rows: 56px 1fr; min-height: 100vh; }
.topbar { background: var(--surface); border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 1.5rem; gap: 1rem; position: sticky; top: 0; z-index: 100; }
.topbar .brand { font-size: 15px; font-weight: 600; letter-spacing: .02em; flex: 1; }
.topbar .brand span { color: var(--accent); }
.ts-badge { font-size: 11px; background: var(--green-bg); color: var(--green); border: 1px solid #14532d; border-radius: 20px; padding: 3px 10px; display: flex; align-items: center; gap: 5px; }
.ts-badge::before { content:''; display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--green); animation: pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.logout-btn { background: transparent; border: 1px solid var(--border2); border-radius: 6px; color: var(--muted); font-family: var(--sans); font-size: 12px; padding: 5px 12px; cursor: pointer; }
.logout-btn:hover { color: var(--text); border-color: var(--border2); }

.main { padding: 1.5rem; max-width: 1100px; margin: 0 auto; }

/* ─── STATS ─── */
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 1.5rem; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 18px; }
.stat-card .label { font-size: 12px; color: var(--muted); margin-bottom: 6px; }
.stat-card .val { font-size: 26px; font-weight: 600; font-family: var(--mono); }
.val.green { color: var(--green); }
.val.red   { color: var(--red);   }
.val.amber { color: var(--amber); }

/* ─── SECTION ─── */
.section-hd { font-size: 12px; font-weight: 500; color: var(--muted); letter-spacing: .08em; text-transform: uppercase; margin-bottom: 10px; margin-top: 1.5rem; display: flex; align-items: center; gap: 8px; }
.section-hd::after { content:''; flex:1; height:1px; background:var(--border); }

/* ─── SERVER CARD ─── */
.srv-card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; gap: 14px; margin-bottom: 8px; transition: border-color .2s; }
.srv-card:hover { border-color: var(--border2); }
.srv-card.offline { opacity: .55; }
.indicator { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.indicator.on  { background: var(--green); box-shadow: 0 0 6px var(--green); }
.indicator.off { background: var(--red); }
.srv-body { flex: 1; min-width: 0; }
.srv-name { font-size: 15px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
.srv-name input.name-edit { background: var(--surface); border: 1px solid var(--accent); border-radius: 6px; color: var(--text); font-family: var(--sans); font-size: 14px; font-weight: 500; padding: 2px 8px; width: 180px; }
.srv-ip { font-size: 12px; color: var(--muted); font-family: var(--mono); margin-top: 3px; }
.srv-tags { display: flex; gap: 6px; margin-top: 7px; flex-wrap: wrap; }
.tag { font-size: 11px; padding: 2px 8px; border-radius: 20px; border: 1px solid var(--border2); color: var(--muted); }
.tag.os { background: #0f172a; color: #60a5fa; border-color: #1e3a5f; }
.srv-actions { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
.btn { font-size: 12px; padding: 6px 13px; border-radius: 7px; border: 1px solid var(--border2); background: transparent; color: var(--text); cursor: pointer; font-family: var(--sans); transition: all .15s; white-space: nowrap; }
.btn:hover:not(:disabled) { background: var(--surface); border-color: var(--border2); }
.btn:disabled { opacity: .35; cursor: not-allowed; }
.btn.ssh   { background: #0c2a1a; color: var(--green); border-color: #14532d; }
.btn.ssh:hover:not(:disabled) { background: #14532d; }
.btn.web   { background: #0c1f3a; color: #60a5fa; border-color: #1e3a5f; }
.btn.web:hover:not(:disabled) { background: #1e3a5f; }
.btn.act   { background: #2d1f00; color: var(--amber); border-color: #78350f; }
.btn.act:hover:not(:disabled) { background: #78350f; }
.btn.rename-save { background: var(--accent); color: #fff; border-color: var(--accent); }
.btn.putty { background: #1a0c2a; color: #c084fc; border-color: #4c1d95; }
.btn.putty:hover:not(:disabled) { background: #2e1065; }
.edit-icon { cursor: pointer; color: var(--muted); font-size: 13px; padding: 2px 5px; border-radius: 4px; }
.edit-icon:hover { color: var(--accent); }

/* ─── MODAL ─── */
.modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.7); z-index: 200; align-items: center; justify-content: center; }
.modal-bg.open { display: flex; }
.modal { background: var(--surface); border: 1px solid var(--border2); border-radius: 16px; padding: 1.5rem; width: 480px; max-width: 95vw; }
.modal h3 { font-size: 16px; font-weight: 500; margin-bottom: 1rem; }
.modal label { font-size: 13px; color: var(--muted); display: block; margin-bottom: 5px; margin-top: 12px; }
.modal input { width: 100%; background: var(--card); border: 1px solid var(--border2); border-radius: 8px; padding: 9px 12px; color: var(--text); font-family: var(--mono); font-size: 13px; direction: ltr; }
.modal input:focus { outline: none; border-color: var(--accent); }
.modal-actions { display: flex; gap: 8px; margin-top: 1.2rem; justify-content: flex-end; }
.btn.cancel { color: var(--muted); }

/* ─── TOAST ─── */
.toast { position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%) translateY(80px); background: var(--card); border: 1px solid var(--border2); border-radius: 10px; padding: 10px 20px; font-size: 13px; transition: transform .3s; z-index: 300; }
.toast.show { transform: translateX(-50%) translateY(0); }

@media (max-width: 640px) {
  .stats { grid-template-columns: repeat(2,1fr); }
  .srv-card { flex-wrap: wrap; }
  .srv-actions { width: 100%; }
}
</style>
</head>
<body>

<?php if (!isset($_SESSION['auth'])): ?>
<!-- ═══ LOGIN PAGE ═══ -->
<div class="login-wrap">
  <div class="login-box">
    <div class="logo">🖥️</div>
    <h2>Server Portal</h2>
    <p>لوحة تحكم السيرفرات الخاصة</p>
    <form method="POST">
      <input type="password" name="password" placeholder="كلمة المرور" autofocus>
      <?php if (isset($login_error)): ?>
        <div class="error"><?= $login_error ?></div>
      <?php endif; ?>
      <button type="submit" class="btn-primary" style="margin-top:8px;">دخول</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ═══ MAIN PORTAL ═══ -->
<div class="shell">
  <div class="topbar">
    <div class="brand">Server<span>Portal</span></div>
    <div class="ts-badge">Tailscale متصل</div>
    <form method="POST" style="margin:0;">
      <button name="logout" class="logout-btn">خروج</button>
    </form>
  </div>

  <div class="main">
    <!-- Stats -->
    <div class="stats">
      <div class="stat-card">
        <div class="label">إجمالي الأجهزة</div>
        <div class="val"><?= count($machines) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">متصل</div>
        <div class="val green"><?= count($online) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">غير متصل</div>
        <div class="val red"><?= count($offline) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">آخر تحديث</div>
        <div class="val amber" style="font-size:14px;line-height:1.8"><?= date('H:i:s') ?></div>
      </div>
    </div>

    <!-- Online -->
    <?php if ($online): ?>
    <div class="section-hd">متصل الآن</div>
    <?php foreach ($online as $m):
      $ip      = $m['addresses'][0] ?? '—';
      $id      = $m['id'];
      $defName = $m['hostname'] ?? $id;
      $name    = $customNames[$id] ?? $defName;
      $os      = $m['os'] ?? 'linux';
      $ver     = $m['clientVersion'] ?? '';
    ?>
    <div class="srv-card" id="card-<?= htmlspecialchars($id) ?>">
      <div class="indicator on"></div>
      <div class="srv-body">
        <div class="srv-name">
          <span class="name-display" id="name-<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($name) ?></span>
          <span class="edit-icon" onclick="startRename('<?= htmlspecialchars($id) ?>','<?= htmlspecialchars($name) ?>')" title="تعديل الاسم">✏️</span>
        </div>
        <div class="srv-ip"><?= htmlspecialchars($ip) ?></div>
        <div class="srv-tags">
          <span class="tag os"><?= htmlspecialchars($os) ?></span>
          <?php if ($ver): ?><span class="tag">v<?= htmlspecialchars($ver) ?></span><?php endif; ?>
          <span class="tag">Port 22</span>
        </div>
      </div>
      <div class="srv-actions">
        <button class="btn putty"  onclick="launchPutty('<?= htmlspecialchars($ip) ?>')">PuTTY</button>
        <button class="btn ssh"    onclick="launchWebSSH('<?= htmlspecialchars($ip) ?>')">Web SSH</button>
        <button class="btn web"    onclick="openWebsite('<?= htmlspecialchars($ip) ?>')">الموقع</button>
        <button class="btn act"    onclick="openActivate('<?= htmlspecialchars($ip) ?>')">تفعيل</button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Offline -->
    <?php if ($offline): ?>
    <div class="section-hd">غير متصل</div>
    <?php foreach ($offline as $m):
      $ip   = $m['addresses'][0] ?? '—';
      $id   = $m['id'];
      $name = $customNames[$id] ?? ($m['hostname'] ?? $id);
      $lastSeen = $m['lastSeen'] ? date('Y-m-d H:i', strtotime($m['lastSeen'])) : '—';
    ?>
    <div class="srv-card offline">
      <div class="indicator off"></div>
      <div class="srv-body">
        <div class="srv-name"><?= htmlspecialchars($name) ?></div>
        <div class="srv-ip"><?= htmlspecialchars($ip) ?> &nbsp;·&nbsp; آخر اتصال: <?= $lastSeen ?></div>
      </div>
      <div class="srv-actions">
        <button class="btn" disabled>PuTTY</button>
        <button class="btn" disabled>Web SSH</button>
        <button class="btn" disabled>الموقع</button>
        <button class="btn" disabled>تفعيل</button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!$machines): ?>
    <div style="text-align:center; padding:3rem; color:var(--muted);">
      <div style="font-size:36px;margin-bottom:1rem;">📡</div>
      <div>لا توجد أجهزة — تأكد من إعداد TS_API_KEY</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ RENAME MODAL ═══ -->
<div class="modal-bg" id="renameModal">
  <div class="modal">
    <h3>تعديل اسم الجهاز</h3>
    <form method="POST" id="renameForm">
      <input type="hidden" name="rename" value="1">
      <input type="hidden" name="node_id" id="rename_id">
      <label>الاسم الجديد</label>
      <input type="text" name="new_name" id="rename_input" placeholder="اسم الجهاز">
      <div class="modal-actions">
        <button type="button" class="btn cancel" onclick="closeModal('renameModal')">إلغاء</button>
        <button type="submit" class="btn rename-save">حفظ</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ ACTIVATE MODAL ═══ -->
<div class="modal-bg" id="actModal">
  <div class="modal">
    <h3>🔑 تفعيل النظام</h3>
    <label>عنوان IP</label>
    <input type="text" id="act_ip" readonly style="color:var(--amber)">
    <label>رابط التفعيل</label>
    <input type="text" id="act_url" readonly style="color:#60a5fa">
    <div class="modal-actions">
      <button class="btn cancel" onclick="closeModal('actModal')">إغلاق</button>
      <button class="btn web"    onclick="copyActUrl()">نسخ الرابط</button>
      <button class="btn act"    onclick="goActivate()">فتح صفحة التفعيل</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const SSH_USER = '<?= SSH_USER ?>';
const WETTY    = <?= WETTY_PORT ?>;

function launchPutty(ip) {
  // putty:// URI — works if PuTTY is installed and registered
  window.location.href = 'putty://' + SSH_USER + '@' + ip + ':22';
  toast('فتح PuTTY → ' + ip);
}

function launchWebSSH(ip) {
  // Opens wetty/shellinabox on same Tailscale IP
  window.open('http://' + ip + ':' + WETTY + '/?arg=' + SSH_USER + '@' + ip, '_blank');
}

function openWebsite(ip) {
  window.open('http://' + ip, '_blank');
}

function openActivate(ip) {
  const url = 'http://' + ip + '/act-index.php';
  document.getElementById('act_ip').value  = ip;
  document.getElementById('act_url').value = url;
  openModal('actModal');
}

function goActivate() {
  const url = document.getElementById('act_url').value;
  window.open(url, '_blank');
}

function copyActUrl() {
  const url = document.getElementById('act_url').value;
  navigator.clipboard.writeText(url).then(() => toast('تم نسخ الرابط'));
}

function startRename(id, current) {
  document.getElementById('rename_id').value   = id;
  document.getElementById('rename_input').value = current;
  openModal('renameModal');
  setTimeout(() => document.getElementById('rename_input').focus(), 100);
}

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-bg').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

// Auto-refresh every 30s
setTimeout(() => location.reload(), 30000);
</script>
<?php endif; ?>
</body>
</html>
