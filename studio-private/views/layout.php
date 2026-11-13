<?php use function Studio\{e,url,asset}; use Studio\{Auth,Security,IntegrationSettings}; $viewer = isset($_SESSION['admin_id']) ? Auth::user() : null; $active = $_GET['page'] ?? 'dashboard'; ?>
<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= e($title ?? 'Studio') ?> · Hybrid AI Auto Post</title><link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('studio.css')) ?>"><script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>" defer></script><script src="<?= e(asset('studio.js')) ?>" defer></script></head>
<body>
<?php if ($viewer): ?>
<aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="เมนูหลัก">
 <button type="button" class="btn-close btn-close-white d-lg-none align-self-end mb-2" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="ปิดเมนู"></button>
 <a class="brand" href="<?= e(url()) ?>"><span class="brand-mark">H</span><span>HYBRID<span class="brand-sub">AUTO POST STUDIO</span></span></a>
 <div class="workspace-tag"><span class="status-dot"></span>พื้นที่ทำงานของคุณ <span>V1.0</span></div>
 <div class="nav-label">WORKSPACE</div>
 <nav class="nav flex-column">
 <?php foreach (['dashboard'=>['ภาพรวม','01'],'contents'=>['คอนเทนต์ทั้งหมด','02'],'library'=>['คลังวิดีโอ','03'],'manual'=>['Facebook ส่วนตัว','04'],'accounts'=>['บัญชีและเพจ','05'],'queue'=>['คิวเผยแพร่','06'],'integrations'=>['การเชื่อมต่อ API','07']] as $key=>$nav): ?>
 <a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= e(url($key)) ?>"><span class="nav-number"><?= e($nav[1]) ?></span><?= e($nav[0]) ?></a>
 <?php endforeach; ?>
 </nav><div class="nav-label mt-4">SYSTEM</div><nav class="nav flex-column"><a class="nav-link <?= $active==='audit'?'active':'' ?>" href="<?= e(url('audit')) ?>"><span class="nav-number">06</span>ประวัติการทำงาน</a><a class="nav-link <?= $active==='settings'?'active':'' ?>" href="<?= e(url('settings')) ?>"><span class="nav-number">07</span>ตั้งค่าระบบ</a></nav>
 <div class="sidebar-footer"><div class="phase-label">PHASE 2 · HYBRID PUBLISHING</div><p>เตรียมคอนเทนต์ ตรวจอนุมัติ<br>และโพสต์ส่วนตัวด้วยตนเอง</p><small>OAuth · n8n · แยกสถานะแต่ละปลายทาง</small></div>
</aside>
<div class="app-main"><header class="topbar"><button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar">เมนู</button><div class="topbar-path">WORKSPACE <span>/</span> <?= e($title ?? 'ภาพรวม') ?></div><div class="admin-menu"><span class="avatar"><?= e(mb_substr($viewer['display_name'],0,1)) ?></span><span class="d-none d-sm-inline"><?= e($viewer['display_name']) ?></span><form method="post" action="<?= e(url('logout')) ?>"><?= Security::field() ?><input type="hidden" name="action" value="logout"><button class="btn btn-sm btn-light" type="submit">ออกจากระบบ</button></form></div></header><main class="page-container">
<?php else: ?><main class="auth-main"><?php endif; ?>
<?php foreach ($_SESSION['flashes'] ?? [] as [$type,$message]): ?><div class="alert alert-<?= e($type) ?>" role="alert"><?= e($message) ?></div><?php endforeach; unset($_SESSION['flashes']); ?>
<?= $body ?>
</main><?php if ($viewer): ?><footer class="app-footer">Hybrid AI Auto Post Studio <span>เวลาแสดงผล Asia/Bangkok · Phase 2</span></footer></div><?php endif; ?>
<div id="copy-feedback" class="copy-feedback" role="status" aria-live="polite" hidden>คัดลอกข้อความแล้ว</div>
</body></html>

