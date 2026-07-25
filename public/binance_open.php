<?php
$orderNo = trim((string)($_GET['order'] ?? ''));
$checkout = trim((string)($_GET['checkout'] ?? ''));
$deeplink = trim((string)($_GET['deeplink'] ?? ''));
if ($checkout === '' && $deeplink === '') {
    http_response_code(400);
    echo 'Missing payment url';
    exit;
}
$openTarget = $deeplink !== '' ? $deeplink : $checkout;
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>打开 Binance Pay</title>
  <style>
    * { box-sizing: border-box; }
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; background:#0b0e11; color:#fff; }
    .wrap { max-width:540px; margin:0 auto; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
    .card { width:100%; background:#1e2329; border:1px solid #2b3139; border-radius:16px; padding:24px; }
    .title-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
    .logo { width:26px; height:26px; flex:0 0 26px; display:block; }
    .title { font-size:22px; font-weight:700; margin:0; line-height:1.2; }
    .sub { color:#b7bdc6; font-size:14px; margin:0 0 16px; }
    .btn { display:block; width:100%; max-width:100%; text-align:center; border-radius:12px; padding:11px 12px; text-decoration:none; font-weight:700; margin-top:10px; font-size:16px; line-height:1.2; }
    .btn-main { background:#f0b90b; color:#111; }
    .btn-alt { background:#2b3139; color:#fff; border:1px solid #3a4149; }
    .mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; color:#d8dee6; font-size:12px; word-break:break-all; margin-top:12px; }
    @media (max-width: 520px) {
      .wrap { padding:16px; align-items:flex-end; }
      .card { padding:16px; border-radius:14px; }
      .title { font-size:18px; }
      .sub { font-size:13px; margin-bottom:12px; }
      .btn { padding:10px 10px; border-radius:10px; font-size:14px; margin-top:8px; }
      .mono { margin-top:10px; font-size:11px; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="title-row">
        <svg class="logo" viewBox="0 0 128 128" aria-hidden="true">
          <rect width="128" height="128" rx="18" fill="#F0B90B"/>
          <path fill="#111" d="M64 18l14.9 14.9-10.2 10.2L64 38.4 59.3 43l-10.2-10.2L64 18zm25.6 25.6l14.9 14.9-14.9 14.9-14.9-14.9 14.9-14.9zM38.4 43.6L53.3 58.5 38.4 73.4 23.5 58.5l14.9-14.9zM64 54.5l10.5 10.5L64 75.5 53.5 65 64 54.5zm-25.6 25.6l14.9 14.9-14.9 14.9L23.5 95l14.9-14.9zM89.6 80.1L104.5 95l-14.9 14.9L74.7 95l14.9-14.9zM64 91.6l4.7-4.7 10.2 10.2L64 112 49.1 97.1l10.2-10.2 4.7 4.7z"/>
        </svg>
        <h1 class="title">正在打开 Binance App</h1>
      </div>
      <p class="sub">如果没有自动跳转，请手动点击下方按钮继续支付。</p>
      <a class="btn btn-main" href="<?php echo htmlspecialchars($openTarget); ?>">打开 Binance App</a>
      <a class="btn btn-alt" href="<?php echo htmlspecialchars($checkout !== '' ? $checkout : $openTarget); ?>">改用网页支付</a>
      <?php if ($orderNo !== ''): ?>
        <div class="mono">订单号：<?php echo htmlspecialchars($orderNo); ?></div>
      <?php endif; ?>
    </div>
  </div>
  <script>
    (function() {
      var target = <?php echo json_encode($openTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      var fallback = <?php echo json_encode($checkout !== '' ? $checkout : $openTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      if (target) {
        window.location.href = target;
      }
      setTimeout(function() {
        if (document.visibilityState === 'visible' && fallback) {
          window.location.href = fallback;
        }
      }, 1400);
    })();
  </script>
</body>
</html>
