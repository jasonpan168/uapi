<?php
require_once __DIR__ . '/inc/bootstrap.php';

$db = Database::getInstance();
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) {
    $cfg[$s['key_name']] = $s['value'];
}

$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = trim((string)($cfg['site_logo'] ?? ''));
$lang = I18n::getLang();
$is_en = ($lang === 'en');
$tt = static function (string $zh, string $en) use ($is_en): string {
    return $is_en ? $en : $zh;
};
?>
<!DOCTYPE html>
<html lang="<?php echo match ($lang) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tt('页面未找到', 'Page Not Found')); ?> | <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($tt('您访问的页面不存在，返回首页继续浏览。', 'The page you are looking for does not exist. Return to homepage.')); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    colors: {
                        primary: {
                            50: '#f0f9ff', 100: '#e0f2fe', 500: '#0ea5e9', 600: '#0284c7', 900: '#0c4a6e',
                        },
                        dark: {
                            800: '#1e293b', 900: '#0f172a',
                        }
                    },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'pulse-slow': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-20px)' },
                        }
                    }
                }
            }
        };
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap');

        .crypto-grid {
            background-image: radial-gradient(rgba(14, 165, 233, 0.1) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .dark .glass-card {
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .blob {
            position: absolute;
            filter: blur(80px);
            opacity: 0.4;
            z-index: -1;
        }

        .brand-logo {
            max-height: 32px;
            width: auto;
            display: block;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-dark-900 text-gray-900 dark:text-white transition-colors duration-300 min-h-screen flex flex-col font-sans overflow-hidden relative">

    <div class="absolute inset-0 crypto-grid z-0 opacity-50 pointer-events-none"></div>
    <div class="blob bg-blue-400 w-96 h-96 rounded-full top-[-100px] left-[-100px] animate-pulse-slow"></div>
    <div class="blob bg-purple-400 w-80 h-80 rounded-full bottom-[-50px] right-[-50px] animate-pulse-slow" style="animation-delay: 2s;"></div>

    <nav class="relative z-50 px-6 py-6 flex justify-between items-center max-w-7xl mx-auto w-full">
        <a href="/" class="flex items-center gap-2 group">
            <?php if ($site_logo !== ''): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_name); ?>" class="brand-logo">
            <?php else: ?>
                <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center text-white font-bold text-lg group-hover:scale-105 transition-transform">U</div>
                <span class="font-bold text-xl tracking-tight"><?php echo htmlspecialchars($site_name); ?></span>
            <?php endif; ?>
        </a>

        <div class="flex items-center gap-3">
            <?php include __DIR__ . '/includes/lang_switcher.php'; ?>
            <button onclick="toggleTheme()" class="p-2 rounded-full hover:bg-gray-200 dark:hover:bg-gray-800 transition-colors" aria-label="Toggle Theme">
                <i class="fa-solid fa-moon dark:hidden"></i>
                <i class="fa-solid fa-sun hidden dark:block text-yellow-400"></i>
            </button>
        </div>
    </nav>

    <main class="flex-grow flex items-center justify-center relative z-10 px-4">
        <div class="max-w-6xl w-full grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">

            <div class="space-y-8 order-2 lg:order-1 text-center lg:text-left">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 text-xs font-mono font-medium border border-blue-200 dark:border-blue-800">
                    <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                    ERROR 404
                </div>

                <h1 class="text-5xl lg:text-7xl font-bold tracking-tight leading-tight">
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-purple-600 dark:from-blue-400 dark:to-purple-400"><?php echo htmlspecialchars($tt('未找到页面', 'Page Not Found')); ?></span>
                </h1>

                <p class="text-lg text-gray-600 dark:text-gray-400 max-w-md mx-auto lg:mx-0 leading-relaxed">
                    <?php echo htmlspecialchars($tt('您查找的页面似乎不存在或已被移动。', 'The page you are looking for may have been moved or removed.')); ?>
                </p>

                <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                    <a href="/" class="px-8 py-3.5 rounded-xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 font-medium hover:opacity-90 transition-all transform hover:-translate-y-0.5 shadow-lg shadow-gray-200 dark:shadow-none flex items-center justify-center gap-2">
                        <i class="fa-solid fa-house"></i>
                        <?php echo htmlspecialchars($tt('返回首页', 'Back Home')); ?>
                    </a>
                    <a href="/doc.php" class="px-8 py-3.5 rounded-xl glass-card text-gray-700 dark:text-gray-200 font-medium hover:bg-gray-50 dark:hover:bg-white/5 transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-book"></i>
                        <?php echo htmlspecialchars($tt('开发文档', 'Developer Docs')); ?>
                    </a>
                </div>
            </div>

            <div class="order-1 lg:order-2 relative h-[400px] lg:h-[600px] flex items-center justify-center">
                <div class="relative z-20 w-48 h-48 lg:w-64 lg:h-64 bg-gradient-to-br from-white to-gray-100 dark:from-gray-800 dark:to-gray-900 rounded-3xl shadow-2xl flex items-center justify-center border border-gray-100 dark:border-gray-700 animate-float transform rotate-6">
                    <div class="text-center">
                        <div class="text-6xl mb-2">⛓️</div>
                        <div class="font-mono text-xs text-gray-400">0x00...000</div>
                    </div>

                    <div class="absolute -top-12 -right-8 w-16 h-16 bg-white dark:bg-gray-800 rounded-2xl shadow-lg flex items-center justify-center animate-float" style="animation-delay: 1s;">
                        <i class="fa-brands fa-bitcoin text-3xl text-orange-500"></i>
                    </div>
                    <div class="absolute -bottom-8 -left-12 w-20 h-20 bg-white dark:bg-gray-800 rounded-2xl shadow-lg flex items-center justify-center animate-float" style="animation-delay: 2s;">
                        <i class="fa-brands fa-ethereum text-4xl text-purple-600"></i>
                    </div>
                    <div class="absolute top-1/2 -right-24 w-14 h-14 bg-white dark:bg-gray-800 rounded-xl shadow-lg flex items-center justify-center animate-float" style="animation-delay: 1.5s;">
                        <span class="text-green-500 font-bold font-mono">API</span>
                    </div>
                </div>

                <div class="absolute z-10 w-full max-w-md p-6 glass-card rounded-2xl transform -rotate-3 scale-90 lg:scale-100 opacity-60 dark:opacity-40 blur-[1px]">
                    <div class="flex gap-2 mb-4">
                        <div class="w-3 h-3 rounded-full bg-red-500"></div>
                        <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                        <div class="w-3 h-3 rounded-full bg-green-500"></div>
                    </div>
                    <div class="font-mono text-xs space-y-2 text-gray-600 dark:text-gray-300">
                        <p><span class="text-purple-500">const</span> <span class="text-blue-500">response</span> = <span class="text-purple-500">await</span> api.<span class="text-yellow-600">getBlock</span>(404);</p>
                        <p><span class="text-purple-500">if</span> (!response.exists) {</p>
                        <p class="pl-4"><span class="text-purple-500">throw</span> <span class="text-purple-500">new</span> <span class="text-yellow-600">Error</span>(<span class="text-green-500"><?php echo $is_en ? "'Page not found'" : "'页面不存在'"; ?></span>);</p>
                        <p>}</p>
                        <p class="text-gray-400"><?php echo htmlspecialchars($tt('// 错误：区块高度 404 不存在', '// Error: Block height 404 does not exist')); ?></p>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <footer class="relative z-10 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_name); ?>.</p>
    </footer>

    <script>
        function toggleTheme() {
            const html = document.documentElement;
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                html.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
        }

        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
</body>
</html>
