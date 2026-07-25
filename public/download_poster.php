<?php
// public/download_poster.php
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = Database::getInstance();
$code = $db->fetch("SELECT * FROM qr_codes WHERE id = ?", [$id]);

if (!$code) die(__('front.poster.error.not_found'));

// Configs (Same as qr_codes.php)
$templates = [
  "t1" => ["theme" => ["bg1" => "#e9fff4", "bg2" => "#ffffff", "accent" => "#16a34a", "accent2" => "#0f766e"], "style" => "clean"],
  "t2" => ["theme" => ["bg1" => "#061a16", "bg2" => "#0b3a2c", "accent" => "#22c55e", "accent2" => "#14b8a6"], "style" => "neon"],
  "t3" => ["theme" => ["bg1" => "#e6f5ff", "bg2" => "#ffffff", "accent" => "#0ea5e9", "accent2" => "#22c55e"], "style" => "tech"],
  "t4" => ["theme" => ["bg1" => "#ffffff", "bg2" => "#f6f7fb", "accent" => "#16a34a", "accent2" => "#111827"], "style" => "minimal"],
  "t5" => ["theme" => ["bg1" => "#f0fff7", "bg2" => "#ffffff", "accent" => "#15803d", "accent2" => "#22c55e"], "style" => "glossy"],
  "t6" => ["theme" => ["bg1" => "#f4f6f8", "bg2" => "#ffffff", "accent" => "#0f766e", "accent2" => "#16a34a"], "style" => "business"],
];

$tplId = $code['style'] ?? 't1';
if (!isset($templates[$tplId])) $tplId = 't1';
$tpl = $templates[$tplId];
$th = $tpl['theme'];

// Generate QR Data
$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$pay_url = $base_url . '/qr_pay.php?id=' . $code['id'];
$qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&margin=0&data=" . urlencode($pay_url);

$isPreview = isset($_GET['preview']);
$current_lang = I18n::getLang();
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang === 'en' ? 'en' : 'zh-CN'; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('front.poster.title'); ?> - <?php echo htmlspecialchars($code['name']); ?></title>
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    <style>
        body { margin: 0; background: <?php echo $isPreview ? 'transparent' : '#333'; ?>; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        
        /* Exact Copy from style_picker.php with dynamic vars */
        :root{ --radius: 36px; --shadow: 0 20px 50px rgba(0,0,0,.3); }
        .poster {
            width: 640px; aspect-ratio: 320 / 520; /* Fixed Aspect Ratio */
            border-radius: var(--radius);
            position: relative; overflow: hidden; box-shadow: var(--shadow);
            background: linear-gradient(160deg, var(--bg1), var(--bg2));
            transform-origin: top center;
            /* For preview scaling */
            <?php if($isPreview): ?>transform: scale(0.5);<?php endif; ?>
        }
        
        .glass {
            position:absolute; inset:-80px -120px auto auto; width:440px; height:440px; border-radius: 999px;
            background: radial-gradient(circle at 30% 30%, rgba(255,255,255,.6), rgba(255,255,255,0) 65%);
            opacity:.55; transform: rotate(12deg); pointer-events: none;
        }
        .top {
            position:absolute; left:36px; right:36px; top:36px; padding:28px; border-radius: 32px;
            background: linear-gradient(135deg, rgba(0,0,0,.06), rgba(0,0,0,.0)); border: 1px solid rgba(0,0,0,.06);
        }
        .logoRow { display:flex; align-items:center; gap:20px; }
        .logo { 
            width:80px; height:80px; border-radius:999px; overflow:hidden; 
            display:grid; place-items:center;
            border: 2px solid rgba(255,255,255,.45);
            box-shadow: 0 20px 44px rgba(0,0,0,.10);
        }
        .logo img { width:100%; height:100%; object-fit:cover; transform: scale(1.18); }
        .usdtText { font-size:56px; font-weight:900; letter-spacing:1px; color: rgba(10,15,20,.92); }
        .sub { margin-top:16px; font-size:30px; font-weight:800; color: rgba(10,15,20,.65); }
        .line { height:2px; background: rgba(0,0,0,.08); margin-top:20px; }
        
        .qr-slot {
            position:absolute; 
            left: calc(var(--qr-left) * 1%);
            top: calc(var(--qr-top) * 1%);
            width: calc(var(--qr-size) * 1%);
            aspect-ratio: 1/1;
            border-radius: 36px; background: rgba(255,255,255,.92); border: 4px solid rgba(0,0,0,.10);
            box-shadow: 0 36px 60px rgba(0,0,0,.12); display:grid; place-items:center;
        }
        .qr-inner { 
            width: 92%; height: 92%; border-radius: 24px; 
            border: 4px dashed rgba(15,23,42,.22); position: relative; 
            background: linear-gradient(180deg, rgba(255,255,255,.88), rgba(255,255,255,.98));
            display: flex; align-items: center; justify-content: center;
        }
        .qr-img { width: 90%; height: 90%; object-fit: contain; mix-blend-mode: multiply; }
        
        .corner {
            position:absolute; width:36px; height:36px; border:8px solid var(--accent);
            border-right:none; border-bottom:none; border-radius:12px 0 0 0; opacity:.9;
        }
        .c1{ left:20px; top:20px; }
        .c2{ right:20px; top:20px; transform: rotate(90deg); }
        .c3{ left:20px; bottom:20px; transform: rotate(270deg); }
        .c4{ right:20px; bottom:20px; transform: rotate(180deg); }
        
        .qr-label {
            position:absolute; left:0; right:0; top:-64px;
            text-align:center; font-weight:900; font-size:28px;
            color: rgba(10,15,20,.75);
        }
        
        .bottom {
            position:absolute; left:36px; right:36px; bottom:36px; border-radius: 32px; padding:24px;
            background: rgba(255,255,255,.72); border: 1px solid rgba(0,0,0,.06); 
        }
        .trust {
            display:flex; align-items:center; justify-content:space-between; gap:10px;
            font-weight:900; color: rgba(10,15,20,.78); font-size: 24px;
        }
        .pill {
            background: linear-gradient(135deg, var(--accent), var(--accent2)); color:white; font-weight:900;
            border-radius: 999px; padding:20px; font-size: 24px; margin-top: 20px;
            text-align:center; box-shadow: 0 24px 48px rgba(0,0,0,.16); letter-spacing:.5px;
        }
        .thank { margin-top:20px; text-align:center; font-weight:900; color: rgba(10,15,20,.65); font-size: 24px; }
        
        /* Neon Overrides */
        .poster.neon {
            background: radial-gradient(circle at 30% 20%, rgba(34,197,94,.25), rgba(0,0,0,0) 45%),
                        radial-gradient(circle at 70% 80%, rgba(20,184,166,.22), rgba(0,0,0,0) 45%),
                        linear-gradient(150deg, var(--bg1), var(--bg2));
        }
        .poster.neon .top { background: rgba(255,255,255,.10); border-color: rgba(255,255,255,.10); }
        .poster.neon .usdtText, .poster.neon .sub { color: rgba(255,255,255,.92); }
        .poster.neon .line { background: rgba(255,255,255,.15); }
        .poster.neon .bottom { background: rgba(255,255,255,.10); border-color: rgba(255,255,255,.10); }
        .poster.neon .trust, .poster.neon .thank { color: rgba(255,255,255,.86); }
        .poster.neon .qr-slot { border-color: rgba(34,197,94,.55); box-shadow: 0 0 0 6px rgba(34,197,94,.18), 0 44px 70px rgba(0,0,0,.22); }
        
        .poster.tech {
            background: radial-gradient(circle at 20% 15%, rgba(14,165,233,.22), rgba(0,0,0,0) 52%),
                        radial-gradient(circle at 85% 70%, rgba(34,197,94,.16), rgba(0,0,0,0) 55%),
                        linear-gradient(155deg, var(--bg1), var(--bg2));
        }
        .poster.minimal .top, .poster.minimal .bottom { background: rgba(255,255,255,.85); border-color: rgba(0,0,0,.06); }
        .poster.minimal .glass { display:none; }
        .poster.glossy::before {
            content:""; position:absolute; inset:0;
            background: radial-gradient(circle at 25% 10%, rgba(255,255,255,.55), rgba(255,255,255,0) 45%),
                        radial-gradient(circle at 80% 60%, rgba(34,197,94,.16), rgba(255,255,255,0) 55%);
            pointer-events:none;
        }
        .poster.business {
            background: radial-gradient(circle at 25% 15%, rgba(15,118,110,.16), rgba(0,0,0,0) 50%),
                        linear-gradient(160deg, var(--bg1), var(--bg2));
        }

        .controls { position: fixed; bottom: 30px; display: flex; gap: 20px; z-index: 100; }
        .btn { padding: 15px 40px; border: none; border-radius: 50px; font-size: 18px; font-weight: bold; cursor: pointer; transition: transform 0.2s; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .btn:hover { transform: scale(1.05); }
        .btn-primary { background: #0d6efd; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
    </style>
</head>
<body>

<?php
$qr = $code['style_config'] ?? ["left" => 16.5, "top" => 30.0, "size" => 67.0];
// Fallback if not saved in DB (using template defaults)
if (empty($code['style_config'])) {
    $defaultQRs = [
        "t1" => ["left" => 16.5, "top" => 30.0, "size" => 67.0],
        "t2" => ["left" => 15.5, "top" => 31.0, "size" => 69.0],
        "t3" => ["left" => 16.0, "top" => 29.5, "size" => 68.0],
        "t4" => ["left" => 18.0, "top" => 31.0, "size" => 64.0],
        "t5" => ["left" => 15.8, "top" => 30.5, "size" => 68.4],
        "t6" => ["left" => 16.8, "top" => 30.0, "size" => 66.4],
    ];
    $qr = $defaultQRs[$tplId] ?? $defaultQRs['t1'];
}
?>

<div id="capture" class="poster <?php echo $tpl['style']; ?>" style="
    --bg1: <?php echo $th['bg1']; ?>;
    --bg2: <?php echo $th['bg2']; ?>;
    --accent: <?php echo $th['accent']; ?>;
    --accent2: <?php echo $th['accent2']; ?>;
    --qr-left: <?php echo $qr['left']; ?>;
    --qr-top: <?php echo $qr['top']; ?>;
    --qr-size: <?php echo $qr['size']; ?>;
">
    <div class="glass"></div>
    
    <div class="top">
        <div class="logoRow">
            <div class="logo"><img src="assets/usdt.svg"></div>
            <div>
                <div class="usdtText">USDT</div>
                <div class="sub"><?php echo __('front.poster.secure_pay'); ?></div>
            </div>
        </div>
        <div class="line"></div>
        <div class="sub" style="margin-top:20px; font-size:28px; font-weight:900; opacity:.9;">
            <?php echo __('front.poster.scan_pay'); ?>
        </div>
    </div>
    
    <div class="qr-slot">
        <div class="qr-inner">
            <div class="qr-label"><?php echo __('front.poster.qr_area'); ?></div>
            <div class="corner c1"></div><div class="corner c2"></div><div class="corner c3"></div><div class="corner c4"></div>
            <img src="<?php echo $qr_api; ?>" class="qr-img">
        </div>
    </div>
    
    <div class="bottom">
        <div class="trust">
            <span><?php echo __('front.poster.safe'); ?></span><span>•</span><span><?php echo __('front.poster.fast'); ?></span><span>•</span><span><?php echo __('front.poster.reliable'); ?></span>
        </div>
        <div class="pill"><?php echo __('front.poster.instant_settlement'); ?></div>
        <div class="thank"><?php echo __('front.poster.thank_you'); ?></div>
    </div>
</div>

<?php if(!$isPreview): ?>
<div class="controls">
    <button class="btn btn-secondary" onclick="window.close()"><?php echo __('front.poster.close'); ?></button>
    <button class="btn btn-primary" onclick="downloadImage()"><?php echo __('front.poster.download_hd'); ?></button>
</div>

<script>
function downloadImage() {
    const btn = document.querySelector('.btn-primary');
    btn.innerText = <?php echo json_encode(__('front.poster.generating')); ?>;
    btn.disabled = true;
    
    html2canvas(document.querySelector("#capture"), {
        scale: 2, 
        useCORS: true,
        backgroundColor: null
    }).then(canvas => {
        const link = document.createElement('a');
        link.download = '<?php echo jsesc(__('front.poster.file_prefix')); ?>_<?php echo jsesc($code['name']); ?>.png';
        link.href = canvas.toDataURL("image/png");
        link.click();
        
        btn.innerText = <?php echo json_encode(__('front.poster.download_hd')); ?>;
        btn.disabled = false;
    });
}
</script>
<?php endif; ?>

</body>
</html>
