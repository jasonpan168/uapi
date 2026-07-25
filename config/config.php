<?php
// UAPI Configuration Loader

// Path to generated database configuration
$configFile = __DIR__ . '/db.php';

require_once __DIR__ . '/../src/Env.php';
Env::load(__DIR__ . '/../.env');

// Check if installed (必须同时存在配置文件与安装锁)
// Since we removed install folder and lock file, we assume it is installed if db.php exists
if (file_exists($configFile)) {
    require_once $configFile;
}
define('UAPI_INSTALLED', true);

// Allow environment variables to override DB config
$envHost = Env::get('DB_HOST');
$envName = Env::get('DB_NAME');
$envUser = Env::get('DB_USER');
$envPass = Env::get('DB_PASS');
if ($envHost !== null && !defined('DB_HOST')) { define('DB_HOST', $envHost); }
if ($envName !== null && !defined('DB_NAME')) { define('DB_NAME', $envName); }
if ($envUser !== null && !defined('DB_USER')) { define('DB_USER', $envUser); }
if ($envPass !== null && !defined('DB_PASS')) { define('DB_PASS', $envPass); }

// API Keys (可以后续移入数据库设置表)
if (!defined('TRONSCAN_API_KEY')) define('TRONSCAN_API_KEY', '');
if (!defined('ETHERSCAN_API_KEY')) define('ETHERSCAN_API_KEY', '');

// Chain Configuration
global $chains_config;
if (!isset($chains_config)) {
    $chains_config = [
        'trc20' => [
            'name' => 'Tron',
            'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', // USDT
            'usdc' => ['TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8'], // USDC
            'decimals' => 6
        ],
        'bsc' => [
            'chain_id' => 56,
            'name' => 'BSC',
            'usdt' => ['0x55d398326f99059fF775485246999027B3197955'], // Binance-Peg BSC-USD
            'usdc' => ['0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d'], // Binance-Peg USDC
            'decimals' => 18
        ],
        'eth' => [
            'chain_id' => 1,
            'name' => 'Ethereum',
            'usdt' => ['0xdac17f958d2ee523a2206206994597c13d831ec7'], // Tether USD
            'decimals' => 6
        ],
        'polygon' => [
            'chain_id' => 137,
            'name' => 'Polygon',
            'usdt' => ['0xc2132d05d31c914a87c6611c10748aeb04b58e8f'], // Tether USD (PoS)
            'decimals' => 6
        ],
        'optimism' => [
            'chain_id' => 10,
            'name' => 'Optimism',
            'usdt' => ['0x94b008aa00579c1307b0ef2c499ad98a8ce58e58'], // Native USDT
            'decimals' => 6
        ],
        'arbitrum' => [
            'chain_id' => 42161,
            'name' => 'Arbitrum One',
            'usdt' => [
                '0xfd086bc7cd5c481dcc9c85ebe478a1c0b69fcbb9', // USDT (Arbitrum One)
            ],
            'usdc' => [
                '0xaf88d065e77c8cc2239327c5edb3a432268e5831', // Native USDC
                '0xff970a61a04b1ca14834a43f5de4533ebddb5cc8'  // Bridged USDC.e
            ],
            'decimals' => 6
        ],
        'base' => [
            'chain_id' => 8453,
            'name' => 'Base',
            'usdt' => ['0xfde4c96c8593536e31f229ea8f37b2ada2699bb2'], // Bridged USDT (Base)
            'decimals' => 6
        ],
        'avalanche' => [
            'chain_id' => 43114,
            'name' => 'Avalanche',
            'usdt' => ['0x9702230a8ea53601f5cd2dc00fdbc13d4df4a8c7'], // Tether USD
            'decimals' => 6
        ]
    ];
}
