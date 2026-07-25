<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();

$db = Database::getInstance();
$settings = $db->fetchAll('SELECT * FROM system_settings');
$cfg = [];
foreach ($settings as $s) {
    $cfg[$s['key_name']] = $s['value'];
}

$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';
$page_title = I18n::getLang() === 'en' ? 'Chain Lookup' : '链上查查';
$isEn = I18n::getLang() === 'en';
$tt = static function (string $zh, string $en) use ($isEn): string {
    return $isEn ? $en : $zh;
};
?>
<!DOCTYPE html>
<html lang="<?php echo $isEn ? 'en' : 'zh-CN'; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Exo+2:wght@400;500;600;700&family=Orbitron:wght@500;600;700&display=swap');

        .chain-shell { font-family: 'Exo 2', system-ui, sans-serif; }
        .chain-top {
            background: linear-gradient(125deg, #0f172a 0%, #111827 52%, #0b1220 100%);
            border: 1px solid #1f2937;
            color: #f8fafc;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 18px 40px rgba(2, 6, 23, 0.28);
        }
        .chain-title {
            font-family: 'Orbitron', 'Exo 2', sans-serif;
            font-weight: 700;
            letter-spacing: 0.02em;
            margin: 0;
            font-size: 1.18rem;
        }
        .chain-sub { opacity: .78; font-size: .9rem; margin-top: 2px; }

        .search-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: .65rem;
            margin-top: 14px;
        }
        .search-main-input {
            min-height: 48px !important;
            font-size: .95rem !important;
            border-width: 1px;
        }
        .search-actions {
            display: flex;
            gap: .5rem;
        }
        .search-grid .form-control,
        .search-grid .form-select {
            min-height: 40px;
            border-radius: 12px;
            border-color: #334155;
            background: rgba(15, 23, 42, 0.8);
            color: #e5e7eb;
            font-size: .88rem;
            padding-top: .32rem;
            padding-bottom: .32rem;
        }
        .search-grid .form-control::placeholder { color: #94a3b8; }
        .search-grid .btn {
            min-height: 40px;
            border-radius: 12px;
            font-weight: 600;
            background: #22c55e;
            border-color: #22c55e;
            color: #052e16;
            font-size: .88rem;
        }
        .quick-chains {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: .45rem;
        }
        .market-card {
            margin-top: 12px;
            border: 1px solid #273449;
            border-radius: 13px;
            background: rgba(2, 6, 23, 0.4);
            padding: 12px;
        }
        .market-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .market-time {
            font-size: .74rem;
            color: #94a3b8;
        }
        .market-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
        }
        .market-item {
            border: 1px solid #334155;
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.85);
            padding: 8px;
        }
        .market-top {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }
        .market-logo {
            width: 18px;
            height: 18px;
            border-radius: 999px;
            object-fit: contain;
            background: #fff;
            border: 1px solid #334155;
        }
        .market-symbol {
            font-size: .74rem;
            color: #cbd5e1;
            line-height: 1;
            font-weight: 600;
        }
        .market-name {
            font-size: .68rem;
            color: #94a3b8;
            line-height: 1;
            margin-top: 2px;
        }
        .market-price {
            font-size: .9rem;
            color: #f8fafc;
            font-weight: 700;
            line-height: 1.2;
        }
        .market-change {
            margin-top: 3px;
            font-size: .72rem;
            font-weight: 600;
        }
        .market-up { color: #22c55e; }
        .market-down { color: #ef4444; }
        .market-flat { color: #94a3b8; }
        .market-empty {
            font-size: .78rem;
            color: #94a3b8;
            padding: 3px 2px;
        }
        .quick-chip {
            border: 1px solid rgba(148, 163, 184, 0.45);
            color: #cbd5e1;
            background: rgba(15, 23, 42, .72);
            border-radius: 999px;
            padding: 4px 10px;
            font-size: .78rem;
            cursor: pointer;
            transition: all .16s;
        }
        .quick-chip:hover,
        .quick-chip.active {
            border-color: #22c55e;
            color: #dcfce7;
            transform: translateY(-1px);
        }

        .tools-grid {
            margin-top: 14px;
            display: grid;
            grid-template-columns: 1.2fr 1fr 1fr;
            gap: 12px;
        }
        .tool-card {
            border: 1px solid #273449;
            border-radius: 13px;
            background: rgba(2, 6, 23, 0.4);
            padding: 12px;
        }
        .tool-title {
            margin: 0 0 9px;
            font-size: .82rem;
            font-weight: 700;
            color: #cbd5e1;
            text-transform: uppercase;
            letter-spacing: .03em;
        }
        .tool-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .45rem;
        }
        .tool-actions a,
        .tool-actions button {
            border: 1px solid #334155;
            background: rgba(15, 23, 42, 0.85);
            color: #e2e8f0;
            border-radius: 999px;
            font-size: .75rem;
            padding: 4px 10px;
            text-decoration: none;
        }
        .tool-actions button:hover,
        .tool-actions a:hover {
            border-color: #22c55e;
            color: #dcfce7;
        }
        .history-list {
            display: flex;
            flex-direction: column;
            gap: .45rem;
            max-height: 130px;
            overflow: auto;
        }
        .history-item {
            font-size: .78rem;
            color: #cbd5e1;
            cursor: pointer;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 5px 8px;
            background: rgba(15, 23, 42, 0.85);
        }
        .history-item:hover { border-color: #22c55e; }
        .decode-output {
            font-size: .76rem;
            color: #e2e8f0;
            background: rgba(2, 6, 23, 0.75);
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 7px;
            min-height: 74px;
            white-space: pre-wrap;
            word-break: break-all;
            margin-top: .45rem;
        }
        .status-card {
            border: 1px solid #273449;
            border-radius: 13px;
            background: rgba(2, 6, 23, 0.4);
            padding: 12px;
            margin-top: 12px;
        }
        .status-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .status-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }
        .status-item {
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 8px 10px;
            background: rgba(15, 23, 42, 0.8);
        }
        .status-item .c {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: .8rem;
            color: #dbeafe;
            margin-bottom: 5px;
        }
        .status-item .v {
            font-size: .76rem;
            color: #cbd5e1;
            line-height: 1.35;
        }
        .status-item .v .mono { word-break: break-all; }
        .dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            display: inline-block;
        }
        .dot-ok { background: #22c55e; }
        .dot-bad { background: #ef4444; }

        .result-wrap {
            margin-top: 16px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }
        .result-card {
            border: 1px solid var(--border-color);
            border-radius: 14px;
            background: var(--card-bg);
            box-shadow: var(--card-shadow);
            overflow: hidden;
        }
        .result-head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .result-head h6 {
            margin: 0;
            font-weight: 700;
            font-size: .95rem;
        }
        .kpi-grid {
            padding: 14px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }
        .kpi {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 10px;
            background: rgba(148, 163, 184, 0.08);
        }
        .kpi-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
        }
        .kpi-copy {
            border: 1px solid #334155;
            background: rgba(15, 23, 42, 0.8);
            color: #cbd5e1;
            border-radius: 6px;
            font-size: .68rem;
            padding: 1px 6px;
            cursor: pointer;
        }
        .kpi-copy:hover { border-color: #22c55e; color: #dcfce7; }
        .kpi .k {
            font-size: .74rem;
            color: var(--text-secondary);
        }
        .kpi .v {
            font-weight: 700;
            font-size: .9rem;
            color: var(--text-primary);
            word-break: break-all;
        }
        .json-box {
            padding: 14px;
            background: #020617;
            color: #e2e8f0;
            font-size: .78rem;
            line-height: 1.45;
            overflow: auto;
            max-height: 360px;
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .json-box .json-key { color: #93c5fd; }
        .json-box .json-string { color: #86efac; }
        .json-box .json-number { color: #fcd34d; }
        .json-box .json-boolean { color: #fda4af; }
        .json-box .json-null { color: #c4b5fd; }
        .table-wrap { padding: 12px 14px 14px; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .panel-grid {
            margin-top: 14px;
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 12px;
        }
        .watch-form {
            display: grid;
            grid-template-columns: 140px 1fr auto;
            gap: 8px;
            margin-bottom: 10px;
        }
        .watch-list { max-height: 250px; overflow: auto; }
        .watch-item {
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 8px;
            margin-bottom: 8px;
            background: rgba(15, 23, 42, 0.75);
        }
        .watch-meta {
            font-size: .74rem;
            color: #94a3b8;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
        }
        .tag-chip {
            border: 1px solid #2dd4bf;
            color: #99f6e4;
            border-radius: 999px;
            padding: 1px 8px;
            font-size: .72rem;
        }
        .diag-box {
            border: 1px solid #334155;
            border-radius: 8px;
            background: rgba(2, 6, 23, 0.75);
            padding: 10px;
            font-size: .76rem;
            color: #e2e8f0;
            max-height: 250px;
            overflow: auto;
            white-space: pre-wrap;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 8px;
            padding: 10px 14px 0;
        }
        .filter-grid .form-control,
        .filter-grid .form-select {
            min-height: 34px;
            font-size: .78rem;
            border-radius: 8px;
        }
        .mini-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .chain-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #334155;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: .78rem;
            color: #dbeafe;
            background: rgba(15, 23, 42, 0.75);
        }
        .dot-chain {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            display: inline-block;
        }
        .dot-bsc { background: #f0b90b; }
        .dot-eth { background: #627eea; }
        .dot-arbitrum { background: #28a0f0; }
        .dot-optimism { background: #ff0420; }
        .dot-base { background: #0052ff; }
        .dot-polygon { background: #8247e5; }
        .dot-trc20 { background: #ef0027; }
        .dot-solana { background: #14f195; }
        .dot-btc { background: #f7931a; }
        .dot-default { background: #94a3b8; }

        @media (max-width: 1200px) {
            .tools-grid { grid-template-columns: 1fr; }
            .market-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .status-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .panel-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 992px) {
            .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .search-grid { grid-template-columns: 1fr; }
            .search-actions { width: 100%; }
            .search-actions .btn { flex: 1; }
            .status-grid { grid-template-columns: 1fr; }
            .watch-form { grid-template-columns: 1fr; }
            .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .market-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        /* ─── Multi-chain balance scanner ─────────────────────────────── */
        .multibal-card {
            margin-top: 12px;
            border: 1px solid #1d4ed8;
            border-radius: 13px;
            background: linear-gradient(135deg, rgba(29,78,216,0.15) 0%, rgba(2,6,23,0.6) 100%);
            padding: 14px;
        }
        .multibal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .multibal-title {
            font-size: .82rem;
            font-weight: 700;
            color: #93c5fd;
            text-transform: uppercase;
            letter-spacing: .03em;
            margin: 0;
        }
        .multibal-input-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            margin-bottom: 12px;
        }
        .multibal-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }
        .multibal-item {
            border: 1px solid #334155;
            border-radius: 10px;
            background: rgba(15,23,42,0.85);
            padding: 10px;
            transition: border-color .2s;
        }
        .multibal-item.has-balance {
            border-color: rgba(34,197,94,0.5);
        }
        .multibal-item-head {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
        }
        .multibal-dot {
            width: 8px; height: 8px;
            border-radius: 999px;
            flex-shrink: 0;
        }
        .multibal-chain-name {
            font-size: .78rem;
            font-weight: 700;
            color: #e2e8f0;
        }
        .multibal-token-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-top: 3px;
        }
        .multibal-token-sym {
            font-size: .7rem;
            color: #94a3b8;
        }
        .multibal-token-val {
            font-size: .82rem;
            font-weight: 700;
            color: #f8fafc;
            font-family: ui-monospace, monospace;
        }
        .multibal-token-val.nonzero { color: #22c55e; }
        .multibal-token-val.zero    { color: #475569; }
        .multibal-error { font-size: .7rem; color: #ef4444; margin-top: 3px; }
        .multibal-total-bar {
            margin-top: 12px;
            border: 1px solid #334155;
            border-radius: 10px;
            background: rgba(15,23,42,0.6);
            padding: 10px 14px;
            display: flex;
            gap: 24px;
            align-items: center;
            flex-wrap: wrap;
        }
        .multibal-total-label { font-size: .78rem; color: #94a3b8; }
        .multibal-total-val   { font-size: 1rem; font-weight: 700; color: #22c55e; font-family: ui-monospace, monospace; }
        @media (max-width: 1200px) { .multibal-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 576px)  { .multibal-grid { grid-template-columns: 1fr; } .multibal-input-row { grid-template-columns: 1fr; } }

        /* ─── Gas tracker ─────────────────────────────────────────────── */
        .gas-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
            margin-top: 8px;
        }
        .gas-item {
            border: 1px solid #334155;
            border-radius: 8px;
            background: rgba(15,23,42,0.85);
            padding: 7px 9px;
            text-align: center;
        }
        .gas-chain { font-size: .7rem; color: #94a3b8; margin-bottom: 3px; }
        .gas-gwei  { font-size: .88rem; font-weight: 700; color: #fbbf24; font-family: ui-monospace, monospace; }
        .gas-usd   { font-size: .68rem; color: #94a3b8; margin-top: 1px; }
        @media (max-width: 992px) { .gas-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }

        /* ─── Watch edit modal ────────────────────────────────────────── */
        .watch-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.65);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .watch-modal-overlay.active { display: flex; }
        .watch-modal-box {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 14px;
            padding: 20px;
            width: 380px;
            max-width: 95vw;
        }
        .watch-modal-title { font-size: .9rem; font-weight: 700; color: #e2e8f0; margin: 0 0 14px; }
        .watch-modal-field { margin-bottom: 10px; }
        .watch-modal-field label { display: block; font-size: .75rem; color: #94a3b8; margin-bottom: 4px; }
        .watch-modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px; }
    </style>
</head>
<body>
<div class="container-fluid g-0 chain-shell">
    <div class="row g-0">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div class="col-md-9 col-lg-10 main-content">
            <?php include __DIR__ . '/includes/user_topbar.php'; ?>

            <div class="chain-top">
                <h1 class="chain-title"><?php echo $tt('链上查查', 'Chain Lookup'); ?></h1>
                <div class="chain-sub"><?php echo $tt('输入地址 / 交易哈希 / 区块号后直接查询。', 'Enter address / tx hash / block number and search directly.'); ?></div>

                <div class="search-grid">
                    <input id="queryInput" class="form-control search-main-input" placeholder="<?php echo $tt('输入地址 / Tx Hash / 区块高度 / Token 合约', 'Enter address / tx hash / block height / token contract'); ?>" />
                    <div class="search-actions">
                        <button id="searchBtn" class="btn btn-success"><?php echo $tt('查询', 'Search'); ?></button>
                    </div>
                </div>

            </div>

            <div class="result-wrap" id="resultWrap">
                <div class="result-card">
                    <div class="result-head">
                        <h6><?php echo $tt('查询结果', 'Lookup Result'); ?></h6>
                        <span id="statusBadge" class="badge bg-secondary"><?php echo $tt('等待输入', 'Waiting'); ?></span>
                    </div>
                    <div class="p-4 text-muted" id="emptyHint"><?php echo $tt('输入地址、Tx Hash 或区块后点击查询。', 'Enter address, tx hash, or block and click search.'); ?></div>
                    <div id="resultBody" style="display:none;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>
<script>
const esc = (s) => String(s == null ? '' : s)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

const CHAIN_GROUPS = {
    main: [
        { key: 'bsc', label: 'BSC', base: 'https://bscscan.com' },
        { key: 'eth', label: 'Ethereum', base: 'https://etherscan.io' },
        { key: 'arbitrum', label: 'Arbitrum', base: 'https://arbiscan.io' },
        { key: 'optimism', label: 'Optimism', base: 'https://optimistic.etherscan.io' },
        { key: 'base', label: 'Base', base: 'https://basescan.org' },
        { key: 'polygon', label: 'Polygon', base: 'https://polygonscan.com' },
        { key: 'solana', label: 'Solana', base: 'https://solscan.io' },
        { key: 'trc20', label: 'TRON', base: 'https://tronscan.org' },
        { key: 'btc', label: 'Bitcoin', base: 'https://mempool.space' },
    ],
    niche: [
        { key: 'linea', label: 'Linea', base: 'https://lineascan.build' },
        { key: 'opbnb', label: 'opBNB', base: 'https://opbnbscan.com' },
        { key: 'zksync', label: 'zkSync', base: 'https://explorer.zksync.io' },
        { key: 'fantom', label: 'Fantom', base: 'https://ftmscan.com' },
        { key: 'gnosis', label: 'Gnosis', base: 'https://gnosisscan.io' },
    ],
};

let currentLookupResp = null;
let currentLookupQuery = '';
let currentLookupChain = 'auto';
let flatTransferRows = [];
let filteredTransferRows = [];
let watchItems = [];
const watchFpKey = 'chain_watch_fingerprint_v1';
const marketCacheKey = 'chain_market_cache_v1';
const MARKET_COINS = [
    'bitcoin',
    'ethereum',
    'binancecoin',
    'tether',
    'usd-coin',
    'solana',
    'ripple',
    'dogecoin',
    'tron',
    'arbitrum'
];

async function parseApiJson(resp) {
    const raw = await resp.text();
    let data = null;
    try {
        data = JSON.parse(raw);
    } catch (_) {
        const snippet = String(raw || '').replace(/\s+/g, ' ').slice(0, 120);
        throw new Error(snippet ? `API returned non-JSON: ${snippet}` : 'API returned empty response');
    }
    return data;
}

function getWatchFpMap() {
    try { return JSON.parse(localStorage.getItem(watchFpKey) || '{}'); } catch (_) { return {}; }
}

function setWatchFpMap(map) {
    localStorage.setItem(watchFpKey, JSON.stringify(map || {}));
}

function getExplorerUrl(chain, query, typeHint) {
    const meta = [...CHAIN_GROUPS.main, ...CHAIN_GROUPS.niche].find((c) => c.key === chain);
    if (!meta) return '';
    const q = query || '';
    const t = typeHint || detectQueryType(q);

    if (chain === 'solana') {
        if (t === 'tx') return `${meta.base}/tx/${q}`;
        if (t === 'address') return `${meta.base}/account/${q}`;
        return meta.base;
    }
    if (chain === 'trc20') {
        if (t === 'tx') return `${meta.base}/#/transaction/${q}`;
        if (t === 'address') return `${meta.base}/#/address/${q}`;
        return meta.base;
    }
    if (chain === 'btc') {
        if (t === 'tx') return `${meta.base}/tx/${q}`;
        if (t === 'address') return `${meta.base}/address/${q}`;
        if (t === 'block') return `${meta.base}/block/${q}`;
        return meta.base;
    }

    if (t === 'tx') return `${meta.base}/tx/${q}`;
    if (t === 'address') return `${meta.base}/address/${q}`;
    if (t === 'block') return `${meta.base}/block/${q}`;
    return meta.base;
}

function detectQueryType(q) {
    if (/^0x[a-fA-F0-9]{64}$/.test(q)) return 'tx';
    if (/^0x[a-fA-F0-9]{40}$/.test(q)) return 'address';
    if (/^[0-9]+$/.test(q)) return 'block';
    if (/^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(q)) return 'address';
    if (/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{20,90}$/.test(q)) return 'address';
    if (/^[1-9A-HJ-NP-Za-km-z]{32,90}$/.test(q)) return 'tx';
    return 'unknown';
}

function setStatus(text, cls) {
    const badge = document.getElementById('statusBadge');
    badge.textContent = text;
    badge.className = 'badge ' + cls;
}

function normalizeApiErrorMessage(message) {
    const raw = String(message || '');
    const low = raw.toLowerCase();
    if (low.includes('etherscan_api_key') || low.includes('eth_api_key') || low.includes('api key') || low.includes('not configured')) {
        return '<?php echo $tt('EVM 查询配置未就绪，请联系管理员检查系统设置。', 'EVM lookup config is not ready. Please contact admin.'); ?>';
    }
    if (low.includes('502 bad gateway') || low.includes('504 gateway')) {
        return '<?php echo $tt('链上节点繁忙或网关超时，请稍后重试。', 'Gateway timeout. Please retry shortly.'); ?>';
    }
    if (low.includes('unauthorized')) {
        return '<?php echo $tt('登录状态已失效，请刷新页面后重试。', 'Session expired, please refresh and retry.'); ?>';
    }
    return raw || '<?php echo $tt('查询失败，请稍后重试。', 'Lookup failed, please retry later.'); ?>';
}

const I18N_FIELD_LABELS = {
    hash: '<?php echo $tt('交易哈希', 'Hash'); ?>',
    txid: '<?php echo $tt('交易哈希', 'Txid'); ?>',
    block_number: '<?php echo $tt('区块高度', 'Block Number'); ?>',
    block_height: '<?php echo $tt('区块高度', 'Block Height'); ?>',
    block_time: '<?php echo $tt('出块时间', 'Block Time'); ?>',
    timestamp: '<?php echo $tt('时间戳', 'Timestamp'); ?>',
    from: '<?php echo $tt('付款地址', 'From'); ?>',
    to: '<?php echo $tt('收款地址', 'To'); ?>',
    value_native: '<?php echo $tt('主币金额', 'Native Value'); ?>',
    nonce: '<?php echo $tt('交易序号', 'Nonce'); ?>',
    gas: '<?php echo $tt('Gas 限额', 'Gas Limit'); ?>',
    gas_used: '<?php echo $tt('Gas 已用', 'Gas Used'); ?>',
    gas_limit: '<?php echo $tt('Gas 上限', 'Gas Limit'); ?>',
    gas_price: '<?php echo $tt('Gas 价格(Gwei)', 'Gas Price(Gwei)'); ?>',
    tx_fee_native: '<?php echo $tt('交易手续费', 'Transaction Fee'); ?>',
    status: '<?php echo $tt('交易状态', 'Status'); ?>',
    confirmations: '<?php echo $tt('确认数', 'Confirmations'); ?>',
    address: '<?php echo $tt('地址', 'Address'); ?>',
    native_balance: '<?php echo $tt('主币余额', 'Native Balance'); ?>',
    native_symbol: '<?php echo $tt('主币符号', 'Native Symbol'); ?>',
    scan_window_blocks: '<?php echo $tt('扫描区块窗口', 'Scan Window'); ?>',
    slot: '<?php echo $tt('区块槽位', 'Slot'); ?>',
    fee_lamports: '<?php echo $tt('手续费(Lamports)', 'Fee(Lamports)'); ?>',
    success: '<?php echo $tt('执行成功', 'Success'); ?>',
    confirmed: '<?php echo $tt('已确认', 'Confirmed'); ?>',
    result: '<?php echo $tt('执行结果', 'Result'); ?>',
    trx_balance: '<?php echo $tt('TRX 余额', 'TRX Balance'); ?>',
    token_count: '<?php echo $tt('代币数量', 'Token Count'); ?>',
    fee_sats: '<?php echo $tt('手续费(Sats)', 'Fee(Sats)'); ?>',
    vsize: '<?php echo $tt('虚拟大小(vB)', 'Virtual Size(vB)'); ?>',
    height: '<?php echo $tt('区块高度', 'Height'); ?>',
    tx_count: '<?php echo $tt('交易数量', 'Tx Count'); ?>',
    first_seen_block: '<?php echo $tt('首次出现区块', 'First Seen Block'); ?>',
    last_active_block: '<?php echo $tt('最后活跃区块', 'Last Active Block'); ?>',
    risk_level: '<?php echo $tt('风险等级', 'Risk Level'); ?>',
    contract: '<?php echo $tt('合约地址', 'Contract'); ?>',
    symbol: '<?php echo $tt('代币符号', 'Symbol'); ?>',
    name: '<?php echo $tt('代币名称', 'Name'); ?>',
    decimals: '<?php echo $tt('精度', 'Decimals'); ?>',
    total_supply: '<?php echo $tt('总供应量', 'Total Supply'); ?>',
    holders: '<?php echo $tt('持有人', 'Holders'); ?>',
    transfers: '<?php echo $tt('转账数', 'Transfers'); ?>',
    method_id: 'Method ID',
    size: '<?php echo $tt('区块大小', 'Block Size'); ?>',
};

function formatUnixTs(v) {
    const n = Number(v);
    if (!Number.isFinite(n) || n <= 0) return String(v ?? '');
    const ms = n > 1e12 ? n : (n * 1000);
    const d = new Date(ms);
    if (Number.isNaN(d.getTime())) return String(v ?? '');
    return d.toLocaleString();
}

function normalizeFieldValue(key, value) {
    if (value == null) return '';
    if (key === 'status') {
        const s = String(value).toLowerCase();
        if (s === 'success' || s === '1' || s === 'true') return '<?php echo $tt('成功', 'Success'); ?>';
        if (s === 'failed' || s === '0' || s === 'false') return '<?php echo $tt('失败', 'Failed'); ?>';
    }
    if (['timestamp', 'block_time'].includes(key)) {
        return formatUnixTs(value);
    }
    return String(value);
}

function mapFieldLabel(key) {
    if (I18N_FIELD_LABELS[key]) return I18N_FIELD_LABELS[key];
    return String(key).replaceAll('_', ' ');
}

function mapQueryTypeLabel(v) {
    const x = String(v || '').toLowerCase();
    if (x === 'tx') return '<?php echo $tt('交易', 'Transaction'); ?>';
    if (x === 'address') return '<?php echo $tt('地址', 'Address'); ?>';
    if (x === 'block') return '<?php echo $tt('区块', 'Block'); ?>';
    if (x === 'token') return '<?php echo $tt('代币', 'Token'); ?>';
    return x || '-';
}

function renderKpis(data) {
    const rows = [];
    Object.keys(data || {}).forEach((k) => {
        const v = data[k];
        if (v == null || typeof v === 'object') return;
        rows.push(`<div class="kpi"><div class="k">${esc(mapFieldLabel(k))}</div><div class="v">${esc(normalizeFieldValue(k, v))}</div></div>`);
    });
    return rows.length ? `<div class="kpi-grid">${rows.join('')}</div>` : '';
}

function renderTransfers(transfers) {
    if (!Array.isArray(transfers) || !transfers.length) return '';
    const body = transfers.slice(0, 50).map((t) => `
        <tr>
            <td class="mono">${esc(t.symbol || '-')}</td>
            <td class="mono">${esc(t.amount || t.amount_raw || '-')}</td>
            <td class="mono">${t.from ? `<a href="${esc(getExplorerUrl(currentLookupChain, t.from, 'address'))}" target="_blank" rel="noopener">${esc(t.from)}</a>` : '-'}</td>
            <td class="mono">${t.to ? `<a href="${esc(getExplorerUrl(currentLookupChain, t.to, 'address'))}" target="_blank" rel="noopener">${esc(t.to)}</a>` : '-'}</td>
            <td class="mono">${t.contract ? `<a href="${esc(getExplorerUrl(currentLookupChain, t.contract, 'address'))}" target="_blank" rel="noopener">${esc(t.contract)}</a>` : '-'}</td>
        </tr>
    `).join('');
    return `
    <div class="result-card">
        <div class="result-head"><h6><?php echo $tt('Token 转账', 'Token Transfers'); ?></h6></div>
        <div class="table-wrap table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th><?php echo $tt('代币', 'Symbol'); ?></th><th><?php echo $tt('金额', 'Amount'); ?></th><th><?php echo $tt('付款地址', 'From'); ?></th><th><?php echo $tt('收款地址', 'To'); ?></th><th><?php echo $tt('合约地址', 'Contract'); ?></th></tr></thead>
                <tbody>${body}</tbody>
            </table>
        </div>
    </div>`;
}

function renderRecentTransfers(transfers) {
    if (!Array.isArray(transfers) || !transfers.length) return '';
    const body = transfers.slice(0, 40).map((t) => `
        <tr>
            <td class="mono">${t.tx_hash ? `<a href="${esc(getExplorerUrl(currentLookupChain, t.tx_hash, 'tx'))}" target="_blank" rel="noopener">${esc(t.tx_hash)}</a>` : '-'}</td>
            <td class="mono">${t.from ? `<a href="${esc(getExplorerUrl(currentLookupChain, t.from, 'address'))}" target="_blank" rel="noopener">${esc(t.from)}</a>` : '-'}</td>
            <td class="mono">${t.to ? `<a href="${esc(getExplorerUrl(currentLookupChain, t.to, 'address'))}" target="_blank" rel="noopener">${esc(t.to)}</a>` : '-'}</td>
            <td class="mono">${esc(t.amount_raw || '-')}</td>
            <td>${esc(t.block_number || '-')}</td>
        </tr>
    `).join('');
    return `
    <div class="result-card">
        <div class="result-head"><h6><?php echo $tt('近期转账（窗口扫描）', 'Recent Transfers (window scan)'); ?></h6></div>
        <div class="table-wrap table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th><?php echo $tt('交易哈希', 'Tx'); ?></th><th><?php echo $tt('付款地址', 'From'); ?></th><th><?php echo $tt('收款地址', 'To'); ?></th><th><?php echo $tt('原始金额', 'Amount Raw'); ?></th><th><?php echo $tt('区块', 'Block'); ?></th></tr></thead>
                <tbody>${body}</tbody>
            </table>
        </div>
    </div>`;
}

function flattenTransferRows(resp) {
    const rows = [];
    const addr = String(resp?.data?.address || '').toLowerCase();
    (resp?.data?.token_transfers || []).forEach((t) => {
        const from = String(t.from || '').toLowerCase();
        const to = String(t.to || '').toLowerCase();
        let direction = 'unknown';
        if (addr) {
            if (to === addr) direction = 'in';
            else if (from === addr) direction = 'out';
        }
        rows.push({
            source: 'token_transfers',
            tx_hash: String(resp?.data?.hash || ''),
            symbol: String(t.symbol || ''),
            amount: String(t.amount || t.amount_raw || ''),
            amount_num: Number.parseFloat(String(t.amount || t.amount_raw || '0')) || 0,
            from,
            to,
            contract: String(t.contract || '').toLowerCase(),
            block_number: Number(resp?.data?.block_number || 0) || 0,
            direction,
        });
    });
    (resp?.data?.recent_token_transfers || []).forEach((t) => {
        const from = String(t.from || '').toLowerCase();
        const to = String(t.to || '').toLowerCase();
        let direction = 'unknown';
        if (addr) {
            if (to === addr) direction = 'in';
            else if (from === addr) direction = 'out';
        }
        rows.push({
            source: 'recent_token_transfers',
            tx_hash: String(t.tx_hash || ''),
            symbol: '',
            amount: String(t.amount_raw || ''),
            amount_num: Number.parseFloat(String(t.amount_raw || '0')) || 0,
            from,
            to,
            contract: String(t.contract || '').toLowerCase(),
            block_number: Number(t.block_number || 0) || 0,
            direction,
        });
    });
    return rows;
}

function renderAdvancedFilterPanel() {
    if (!flatTransferRows.length) return '';
    return `
        <div class="result-card">
            <div class="result-head">
                <h6><?php echo $tt('高级筛选 + CSV 导出', 'Advanced Filters + CSV Export'); ?></h6>
                <div class="mini-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="applyFilterBtn"><?php echo $tt('应用筛选', 'Apply Filters'); ?></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="exportCsvBtn"><?php echo $tt('导出 CSV', 'Export CSV'); ?></button>
                    <span class="badge bg-secondary" id="filterCountBadge">0</span>
                </div>
            </div>
            <div class="filter-grid">
                <input class="form-control form-control-sm" id="fKeyword" placeholder="<?php echo $tt('关键字（地址/Tx/合约）', 'Keyword (addr/tx/contract)'); ?>" />
                <select class="form-select form-select-sm" id="fDirection">
                    <option value="any"><?php echo $tt('方向：全部', 'Direction: Any'); ?></option>
                    <option value="in"><?php echo $tt('流入', 'In'); ?></option>
                    <option value="out"><?php echo $tt('流出', 'Out'); ?></option>
                    <option value="unknown"><?php echo $tt('未知', 'Unknown'); ?></option>
                </select>
                <input class="form-control form-control-sm" id="fMinAmount" placeholder="<?php echo $tt('最小金额', 'Min Amount'); ?>" />
                <input class="form-control form-control-sm" id="fMaxAmount" placeholder="<?php echo $tt('最大金额', 'Max Amount'); ?>" />
                <input class="form-control form-control-sm" id="fMinBlock" placeholder="<?php echo $tt('最小区块', 'Min Block'); ?>" />
                <input class="form-control form-control-sm" id="fMaxBlock" placeholder="<?php echo $tt('最大区块', 'Max Block'); ?>" />
            </div>
            <div class="table-wrap table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th><?php echo $tt('来源', 'Source'); ?></th>
                            <th><?php echo $tt('交易哈希', 'Tx'); ?></th>
                            <th><?php echo $tt('方向', 'Direction'); ?></th>
                            <th><?php echo $tt('金额', 'Amount'); ?></th>
                            <th><?php echo $tt('付款地址', 'From'); ?></th>
                            <th><?php echo $tt('收款地址', 'To'); ?></th>
                            <th><?php echo $tt('合约', 'Contract'); ?></th>
                            <th><?php echo $tt('区块', 'Block'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="filteredTransfersTbody"></tbody>
                </table>
            </div>
        </div>
    `;
}

function applyAdvancedFilters() {
    if (!flatTransferRows.length) return;
    const kw = String(document.getElementById('fKeyword')?.value || '').trim().toLowerCase();
    const direction = String(document.getElementById('fDirection')?.value || 'any');
    const minAmountRaw = String(document.getElementById('fMinAmount')?.value || '').trim();
    const maxAmountRaw = String(document.getElementById('fMaxAmount')?.value || '').trim();
    const minBlockRaw = String(document.getElementById('fMinBlock')?.value || '').trim();
    const maxBlockRaw = String(document.getElementById('fMaxBlock')?.value || '').trim();
    const minAmount = minAmountRaw === '' ? null : Number(minAmountRaw);
    const maxAmount = maxAmountRaw === '' ? null : Number(maxAmountRaw);
    const minBlock = minBlockRaw === '' ? null : Number(minBlockRaw);
    const maxBlock = maxBlockRaw === '' ? null : Number(maxBlockRaw);

    filteredTransferRows = flatTransferRows.filter((r) => {
        if (kw) {
            const hay = `${r.tx_hash} ${r.from} ${r.to} ${r.contract} ${r.symbol}`.toLowerCase();
            if (!hay.includes(kw)) return false;
        }
        if (direction !== 'any' && r.direction !== direction) return false;
        if (Number.isFinite(minAmount) && r.amount_num < minAmount) return false;
        if (Number.isFinite(maxAmount) && r.amount_num > maxAmount) return false;
        if (Number.isFinite(minBlock) && r.block_number < minBlock) return false;
        if (Number.isFinite(maxBlock) && r.block_number > maxBlock) return false;
        return true;
    });

    const tbody = document.getElementById('filteredTransfersTbody');
    if (!tbody) return;
    if (!filteredTransferRows.length) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-secondary"><?php echo $tt('无匹配数据', 'No matched rows'); ?></td></tr>`;
    } else {
        tbody.innerHTML = filteredTransferRows.slice(0, 300).map((r) => `
            <tr>
                <td>${esc(r.source)}</td>
                <td class="mono">${esc(r.tx_hash || '-')}</td>
                <td>${esc(r.direction)}</td>
                <td class="mono">${esc(r.amount)}</td>
                <td class="mono">${esc(r.from || '-')}</td>
                <td class="mono">${esc(r.to || '-')}</td>
                <td class="mono">${esc(r.contract || '-')}</td>
                <td>${esc(r.block_number || '-')}</td>
            </tr>
        `).join('');
    }
    const badge = document.getElementById('filterCountBadge');
    if (badge) badge.textContent = String(filteredTransferRows.length);
}

function exportFilteredCsv() {
    if (!filteredTransferRows.length) return;
    const head = ['source','tx_hash','direction','amount','from','to','contract','block_number'];
    const escapeCsv = (v) => `"${String(v == null ? '' : v).replaceAll('"', '""')}"`;
    const lines = [head.join(',')];
    filteredTransferRows.forEach((r) => {
        lines.push(head.map((k) => escapeCsv(r[k])).join(','));
    });
    const blob = new Blob(["\uFEFF" + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `chain_lookup_${Date.now()}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(a.href);
}

function copyText(text) {
    navigator.clipboard.writeText(String(text || '')).catch(() => {});
}

function syntaxHighlightJson(obj) {
    const json = typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2);
    return json.replace(
        /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+\-]?\d+)?)/g,
        (match) => {
            let cls = 'json-number';
            if (/^"/.test(match)) cls = /:$/.test(match) ? 'json-key' : 'json-string';
            else if (/true|false/.test(match)) cls = 'json-boolean';
            else if (/null/.test(match)) cls = 'json-null';
            return `<span class="${cls}">${esc(match)}</span>`;
        }
    );
}

function buildSummaryCards(resp) {
    const d = resp?.data || {};
    const qt = String(resp?.query_type || '');
    if (qt === 'tx') {
        return [
            ['hash', d.hash],
            ['block_number', d.block_number],
            ['timestamp', d.timestamp],
            ['from', d.from],
            ['to', d.to],
            ['value_native', `${d.value_native || '-'} ${d.native_symbol || ''}`.trim()],
            ['gas_price', d.gas_price ? `${d.gas_price} Gwei` : null],
            ['gas_used', d.gas_used],
            ['gas_limit', d.gas_limit],
            ['tx_fee_native', `${d.tx_fee_native || '-'} ${d.native_symbol || ''}`.trim()],
            ['status', d.status],
            ['confirmations', d.confirmations],
        ];
    }
    if (qt === 'address') {
        const tokenCount = (d.token_count == null) ? '-' : d.token_count;
        const txCount = (d.tx_count == null) ? '-' : d.tx_count;
        return [
            ['address', d.address],
            ['native_balance', `${d.native_balance || '-'} ${d.native_symbol || ''}`.trim()],
            ['token_count', tokenCount],
            ['tx_count', txCount],
            ['first_seen_block', d.first_seen_block],
            ['last_active_block', d.last_active_block],
            ['risk_level', d.risk_level || 'N/A'],
        ];
    }
    if (qt === 'token') {
        return [
            ['contract', d.contract],
            ['symbol', d.symbol],
            ['name', d.name],
            ['decimals', d.decimals],
            ['total_supply', d.total_supply],
            ['holders', d.holders ?? 'N/A'],
            ['transfers', d.transfers ?? 'N/A'],
        ];
    }
    return Object.entries(d || {}).filter(([,v]) => typeof v !== 'object').map(([k,v]) => [k,v]);
}

function renderOverviewCards(resp) {
    const rows = buildSummaryCards(resp).filter(([,v]) => v != null && v !== '');
    if (!rows.length) return '';
    return `<div class="kpi-grid">${
        rows.map(([k,v]) => {
            const val = normalizeFieldValue(k, v);
            return `<div class="kpi">
                <div class="kpi-top">
                    <div class="k">${esc(mapFieldLabel(k))}</div>
                    <button class="kpi-copy js-copy-field" data-copy="${esc(val)}"><?php echo $tt('复制', 'Copy'); ?></button>
                </div>
                <div class="v">${esc(val)}</div>
            </div>`;
        }).join('')
    }</div>`;
}

function renderTokenInfoTab(resp) {
    const d = resp?.data || {};
    if (String(resp?.query_type || '') !== 'token') return '';
    const logo = d.token_logo_url
        ? `<img src="${esc(d.token_logo_url)}" alt="logo" style="width:28px;height:28px;border-radius:999px;border:1px solid #334155;background:#fff;object-fit:contain;" onerror="this.style.display='none'">`
        : `<span style="display:inline-flex;width:28px;height:28px;border-radius:999px;background:#334155;color:#e2e8f0;align-items:center;justify-content:center;font-size:.75rem;">${esc((d.symbol || '?').slice(0,1).toUpperCase())}</span>`;
    return `
        <div class="table-wrap table-responsive">
            <div class="d-flex align-items-center gap-2 mb-2">${logo}<strong>${esc(d.symbol || 'TOKEN')}</strong><span class="text-secondary">${esc(d.name || '')}</span></div>
            <table class="table table-sm align-middle">
                <tbody>
                    <tr><th><?php echo $tt('合约地址', 'Contract'); ?></th><td class="mono">${d.contract ? `<a href="${esc(getExplorerUrl(currentLookupChain, d.contract, 'address'))}" target="_blank" rel="noopener">${esc(d.contract)}</a>` : '-'}</td></tr>
                    <tr><th><?php echo $tt('代币符号', 'Symbol'); ?></th><td>${esc(d.symbol || '-')}</td></tr>
                    <tr><th><?php echo $tt('代币名称', 'Name'); ?></th><td>${esc(d.name || '-')}</td></tr>
                    <tr><th><?php echo $tt('精度', 'Decimals'); ?></th><td>${esc(d.decimals ?? '-')}</td></tr>
                    <tr><th><?php echo $tt('总供应量', 'Total Supply'); ?></th><td class="mono">${esc(d.total_supply || '-')}</td></tr>
                    <tr><th><?php echo $tt('持有人', 'Holders'); ?></th><td>${esc(d.holders ?? 'N/A')}</td></tr>
                    <tr><th><?php echo $tt('转账数', 'Transfers'); ?></th><td>${esc(d.transfers ?? 'N/A')}</td></tr>
                </tbody>
            </table>
        </div>
    `;
}

function renderContractCall(resp) {
    const d = resp?.data || {};
    const input = String(d.input || '');
    if (!input || input === '0x') {
        return `<div class="p-3 text-secondary"><?php echo $tt('无合约调用数据', 'No contract call data'); ?></div>`;
    }
    const decoded = decodeEvmInput(input) || '<?php echo $tt('暂不支持完整参数解析', 'Detailed parameter decode is not yet supported'); ?>';
    return `
        <div class="p-3">
            <div class="mb-2"><strong>Method:</strong> <span class="mono">${esc(d.method_id || '-')}</span></div>
            <pre class="json-box">${esc(decoded)}</pre>
        </div>
    `;
}

function renderAddressTokenBalances(resp) {
    const d = resp?.data || {};
    const list = Array.isArray(d.token_balances) ? d.token_balances.filter((x) => {
        const n = Number(x?.balance ?? 0);
        return Number.isFinite(n) && n > 0;
    }) : [];
    if (String(resp?.query_type || '') !== 'address' || !list.length) return '';
    return `
        <div class="result-card">
            <div class="result-head"><h6><?php echo $tt('常见稳定币余额（快速探测）', 'Common Stablecoin Balances (Quick Probe)'); ?></h6></div>
            <div class="table-wrap table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th><?php echo $tt('币种', 'Token'); ?></th><th><?php echo $tt('余额', 'Balance'); ?></th><th><?php echo $tt('合约', 'Contract'); ?></th></tr></thead>
                    <tbody>
                        ${list.map((t) => `<tr>
                            <td>${esc(t.symbol || '-')}</td>
                            <td class="mono">${esc(t.balance || '-')}</td>
                            <td class="mono">${t.contract ? `<a href="${esc(getExplorerUrl(currentLookupChain, t.contract, 'address'))}" target="_blank" rel="noopener">${esc(t.contract)}</a>` : '-'}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

async function loadAutoMultiBalance(address) {
    const box = document.getElementById('autoMultiBalanceBox');
    if (!box) return;
    box.innerHTML = `<div class="p-3 text-secondary"><?php echo $tt('正在汇总多链余额...', 'Loading multi-chain balances...'); ?></div>`;
    try {
        const resp = await fetch('/api/v1/chain/multibalance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ address }),
        });
        const data = await parseApiJson(resp);
        if (!resp.ok || data.status !== 'success') throw new Error(data.message || 'multi balance failed');

        const chains = Array.isArray(data.chains) ? data.chains : [];
        const rows = chains.map((c) => {
            const native = c?.tokens?.NATIVE ?? '0';
            const usdt = c?.tokens?.USDT ?? '0';
            const usdc = c?.tokens?.USDC ?? '0';
            return `<tr>
                <td>${esc(c.label || c.chain || '-')}</td>
                <td class="mono">${esc(native)}</td>
                <td class="mono">${esc(usdt)}</td>
                <td class="mono">${esc(usdc)}</td>
            </tr>`;
        }).join('');

        box.innerHTML = `
            <div class="result-card">
                <div class="result-head"><h6><?php echo $tt('地址多链余额汇总', 'Address Multi-chain Balance Summary'); ?></h6></div>
                <div class="table-wrap table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th><?php echo $tt('链', 'Chain'); ?></th><th><?php echo $tt('主币余额', 'Native'); ?></th><th>USDT</th><th>USDC</th></tr></thead>
                        <tbody>${rows || `<tr><td colspan="4" class="text-secondary"><?php echo $tt('暂无数据', 'No data'); ?></td></tr>`}</tbody>
                    </table>
                    <div class="mt-2 small text-secondary mono">${esc(data.address || address)}</div>
                </div>
            </div>
        `;
    } catch (e) {
        box.innerHTML = `<div class="p-3 text-secondary"><?php echo $tt('多链余额暂不可用，已返回基础查询结果。', 'Multi-chain balance is temporarily unavailable. Base lookup result is still shown.'); ?><div class="small mt-1">${esc(normalizeApiErrorMessage(e?.message || 'multi balance failed'))}</div></div>`;
    }
}

async function loadWalletIntel(chain, address) {
    const box = document.getElementById('walletIntelBox');
    if (!box) return;
    box.innerHTML = `<div class="p-3 text-secondary"><?php echo $tt('正在分析钱包关系与风险...', 'Analyzing wallet relations and risk...'); ?></div>`;
    try {
        const resp = await fetch('/api/v1/chain/intel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chain, address, window: '7d', mode: 'time' }),
        });
        const data = await parseApiJson(resp);
        if (!resp.ok || data.status !== 'success') throw new Error(data.message || 'intel failed');

        const risk = data.risk || {};
        const assoc = data.association || {};
        const profile = data.profile || {};
        const path = data.fund_path || {};
        const topLinks = Array.isArray(assoc.top_links) ? assoc.top_links.slice(0, 8) : [];
        const hours = Array.isArray(profile.active_hours) ? profile.active_hours : [];
        const timeRows = Array.isArray(path.time_mode) ? path.time_mode.slice(0, 10) : [];
        const gasRows = Array.isArray(path.gas_mode) ? path.gas_mode.slice(0, 10) : [];

        const riskCls = risk.level === 'critical' || risk.level === 'high'
            ? 'bg-danger'
            : (risk.level === 'medium' ? 'bg-warning text-dark' : 'bg-success');
        const graphId = `intelGraph_${Date.now()}`;
        const cmpId = `intelPeerCmp_${Date.now()}`;

        box.innerHTML = `
            <div class="result-card">
                <div class="result-head">
                    <h6><?php echo $tt('钱包关联分析 / 风险评分 / 资金路径', 'Wallet Association / Risk Score / Fund Path'); ?></h6>
                    <span class="badge ${riskCls}">${esc(String(risk.level || 'unknown').toUpperCase())} · ${esc(risk.score ?? '-')}</span>
                </div>
                <div class="p-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="kpi">
                                <div class="k"><?php echo $tt('关联最频繁地址', 'Top Related Wallets'); ?></div>
                                <div class="table-responsive mt-2">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead><tr><th><?php echo $tt('地址', 'Address'); ?></th><th><?php echo $tt('次数', 'Count'); ?></th><th><?php echo $tt('关联分', 'Score'); ?></th></tr></thead>
                                        <tbody>
                                            ${topLinks.length ? topLinks.map((x) => `<tr><td class="mono">${esc(String(x.address || '')).slice(0, 12)}...${esc(String(x.address || '')).slice(-6)}</td><td>${esc(x.interaction_count ?? 0)}</td><td>${esc(x.relation_score ?? 0)}</td></tr>`).join('') : `<tr><td colspan="3" class="text-secondary"><?php echo $tt('暂无', 'No data'); ?></td></tr>`}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="kpi">
                                <div class="k"><?php echo $tt('钱包画像', 'Wallet Profile'); ?></div>
                                <div class="mt-2 small">${esc(profile.summary || '-')}</div>
                                <div class="mt-2 d-flex flex-wrap gap-2">
                                    ${(Array.isArray(profile.tags) ? profile.tags : []).map((t) => `<span class="badge bg-primary">${esc(t)}</span>`).join('')}
                                </div>
                                <div class="mt-3 small text-secondary"><?php echo $tt('活跃时段', 'Active Hours'); ?>:
                                    ${hours.length ? hours.map((h) => `${esc(h.hour)}:00(${esc(h.tx_count)})`).join(' / ') : '-'}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-12">
                            <div class="kpi">
                                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <div class="k"><?php echo $tt('钱包关系网络', 'Wallet Relationship Network'); ?></div>
                                    <div class="d-flex gap-2 align-items-center" style="min-width: 280px;">
                                        <input type="text" id="${cmpId}_input" class="form-control form-control-sm mono" placeholder="<?php echo $tt('输入 B 钱包地址做 A/B 对比', 'Input peer wallet for A/B compare'); ?>" />
                                        <button type="button" id="${cmpId}_btn" class="btn btn-sm btn-outline-primary"><?php echo $tt('对比', 'Compare'); ?></button>
                                    </div>
                                </div>
                                <div id="${cmpId}_result" class="small text-secondary mt-2"><?php echo $tt('可分析 A 钱包与 B 钱包互动频繁度。', 'Compare interaction frequency between wallet A and wallet B.'); ?></div>
                                <div class="small text-secondary mt-1"><?php echo $tt('可点击图中节点，直接跳转查询该地址。', 'Click a node to query that wallet directly.'); ?></div>
                                <div id="${graphId}" style="height: 300px; margin-top: 10px;"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="kpi">
                                <div class="k"><?php echo $tt('资金路径（时间模式）', 'Fund Path (Time Mode)'); ?></div>
                                <div class="table-responsive mt-2">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead><tr><th><?php echo $tt('时间', 'Time'); ?></th><th><?php echo $tt('方向', 'Dir'); ?></th><th><?php echo $tt('对手方', 'Counterparty'); ?></th></tr></thead>
                                        <tbody>
                                            ${timeRows.length ? timeRows.map((r) => `<tr><td class="small">${esc(r.time || '-')}</td><td>${esc(r.direction || '-')}</td><td class="mono">${esc((r.direction === 'in' ? r.from : r.to) || '-').slice(0, 10)}...${esc((r.direction === 'in' ? r.from : r.to) || '-').slice(-6)}</td></tr>`).join('') : `<tr><td colspan="3" class="text-secondary"><?php echo $tt('暂无', 'No data'); ?></td></tr>`}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="kpi">
                                <div class="k"><?php echo $tt('资金路径（Gas 模式）', 'Fund Path (Gas Mode)'); ?></div>
                                <div class="table-responsive mt-2">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead><tr><th>Tx</th><th>Gwei</th><th><?php echo $tt('手续费', 'Fee'); ?></th></tr></thead>
                                        <tbody>
                                            ${gasRows.length ? gasRows.map((r) => `<tr><td class="mono">${esc(String(r.tx_hash || '')).slice(0, 10)}...</td><td>${esc(r.gas_price_gwei ?? '-')}</td><td>${esc(r.fee_native ?? '-')}</td></tr>`).join('') : `<tr><td colspan="3" class="text-secondary"><?php echo $tt('暂无', 'No data'); ?></td></tr>`}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 small">
                        <strong><?php echo $tt('风险判断', 'Risk Flags'); ?>:</strong>
                        ${Array.isArray(risk.reasons) && risk.reasons.length ? risk.reasons.map((x) => esc(x)).join(' / ') : '<?php echo $tt('未发现明显风险', 'No obvious risk found'); ?>'}
                    </div>
                </div>
            </div>
        `;

        if (window.echarts) {
            const graphEl = document.getElementById(graphId);
            if (graphEl) {
                const nodes = [{ id: address, name: 'A (Target)', symbolSize: 54, category: 0 }];
                const links = [];
                topLinks.forEach((x, i) => {
                    const addr = String(x.address || '');
                    if (!addr) return;
                    nodes.push({
                        id: addr,
                        name: `${addr.slice(0, 8)}...${addr.slice(-4)}`,
                        symbolSize: Math.max(18, Math.min(46, 12 + Number(x.interaction_count || 0) * 2)),
                        category: 1 + (i % 3),
                    });
                    links.push({
                        source: address,
                        target: addr,
                        value: Number(x.interaction_count || 0),
                    });
                });
                const chart = echarts.init(graphEl);
                chart.setOption({
                    backgroundColor: 'transparent',
                    tooltip: { trigger: 'item' },
                    series: [{
                        type: 'graph',
                        layout: 'force',
                        roam: true,
                        draggable: true,
                        force: { repulsion: 220, edgeLength: [80, 160] },
                        label: { show: true, color: '#334155', fontSize: 11 },
                        lineStyle: { color: '#94a3b8', width: 1.2, opacity: 0.8 },
                        edgeLabel: { show: true, formatter: (p) => String(p.data?.value ?? ''), fontSize: 10 },
                        categories: [
                            { name: 'target' },
                            { name: 'counterparty-a' },
                            { name: 'counterparty-b' },
                            { name: 'counterparty-c' },
                        ],
                        data: nodes,
                        links,
                    }],
                });
                chart.on('click', (params) => {
                    const nodeId = String(params?.data?.id || '');
                    if (!/^0x[a-fA-F0-9]{40}$/.test(nodeId)) return;
                    const q = document.getElementById('queryInput');
                    if (!q) return;
                    q.value = nodeId;
                    setStatus('<?php echo $tt('已切换到节点地址并开始查询', 'Switched to node wallet and querying'); ?>', 'bg-info text-dark');
                    doLookup();
                });
                window.addEventListener('resize', () => chart.resize(), { passive: true });
            }
        }

        document.getElementById(`${cmpId}_btn`)?.addEventListener('click', async () => {
            const out = document.getElementById(`${cmpId}_result`);
            const peer = String(document.getElementById(`${cmpId}_input`)?.value || '').trim().toLowerCase();
            if (!/^0x[a-f0-9]{40}$/.test(peer)) {
                if (out) out.textContent = '<?php echo $tt('请输入有效的 EVM 地址（0x...）。', 'Please input a valid EVM address (0x...).'); ?>';
                return;
            }
            if (out) out.textContent = '<?php echo $tt('对比中...', 'Comparing...'); ?>';
            try {
                const cmpResp = await fetch('/api/v1/chain/intel.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ chain, address, peer, window: '7d', mode: 'time' }),
                });
                const cmpData = await parseApiJson(cmpResp);
                if (!cmpResp.ok || cmpData.status !== 'success') throw new Error(cmpData.message || 'compare failed');
                const pr = cmpData?.association?.peer_relation || {};
                if (out) {
                    out.innerHTML = `A ↔ B: <strong>${esc(pr.interaction_count ?? 0)}</strong> <?php echo $tt('次交互', 'interactions'); ?>, `
                        + `<?php echo $tt('关联分', 'score'); ?> <strong>${esc(pr.relation_score ?? 0)}</strong>, `
                        + `<?php echo $tt('最近互动时间戳', 'last interaction ts'); ?>: <span class="mono">${esc(pr.last_interaction ?? '-')}</span>`;
                }
            } catch (err) {
                if (out) out.textContent = String(err?.message || 'compare failed');
            }
        });
    } catch (e) {
        box.innerHTML = `<div class="p-3 text-secondary"><?php echo $tt('钱包关系/风险分析暂不可用，已返回基础查询结果。', 'Wallet relation/risk analysis is temporarily unavailable. Base lookup result is still shown.'); ?><div class="small mt-1">${esc(normalizeApiErrorMessage(e?.message || 'intel failed'))}</div></div>`;
    }
}

function renderResult(resp) {
    const wrap = document.getElementById('resultBody');
    currentLookupResp = resp;
    currentLookupChain = String(resp.chain || '');
    currentLookupQuery = String(resp.query || '');
    flatTransferRows = flattenTransferRows(resp);
    filteredTransferRows = [...flatTransferRows];
    const explorerBtn = resp.explorer_url
        ? `<a href="${esc(resp.explorer_url)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><?php echo $tt('打开浏览器', 'Open Explorer'); ?></a>`
        : `<span></span>`;
    const copyLinkBtn = `<button type="button" class="btn btn-sm btn-outline-primary" id="copyResultLinkBtn"><?php echo $tt('复制链接', 'Copy Link'); ?></button>`;
    const shareUrl = `${location.origin}${location.pathname}?chain=${encodeURIComponent(resp.chain || 'auto')}&query=${encodeURIComponent(resp.query || '')}`;

    let extra = '';
    if (Array.isArray(resp.data?.token_transfers) && resp.data.token_transfers.length) {
        extra += renderTransfers(resp.data.token_transfers);
    }
    if (Array.isArray(resp.data?.recent_token_transfers) && resp.data.recent_token_transfers.length) {
        extra += renderRecentTransfers(resp.data.recent_token_transfers);
    }
    const transferTabBody = String(resp?.query_type || '') === 'token'
        ? renderTokenInfoTab(resp)
        : (extra || `<div class="p-3 text-secondary"><?php echo $tt('无 Token 转账数据', 'No token transfer data'); ?></div>`);
    const chainPill = `<span class="chain-pill"><span class="dot-chain ${esc(mapChainDotClass(resp.chain))}"></span>${esc(mapChainLabel(resp.chain))}</span>`;

    wrap.innerHTML = `
        <div class="result-card">
            <div class="result-head">
                <h6>${esc(mapQueryTypeLabel(resp.query_type))} / ${chainPill}</h6>
                <div class="d-flex gap-2 align-items-center">${copyLinkBtn}${explorerBtn}</div>
            </div>
            <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabOverview" type="button"><?php echo $tt('概览', 'Overview'); ?></button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabTransfers" type="button"><?php echo $tt('Token 转账', 'Token Transfers'); ?></button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCall" type="button"><?php echo $tt('合约调用', 'Contract Call'); ?></button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRaw" type="button"><?php echo $tt('原始 JSON', 'Raw JSON'); ?></button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tabOverview">${renderOverviewCards(resp)}</div>
                <div class="tab-pane fade" id="tabTransfers">${transferTabBody}</div>
                <div class="tab-pane fade" id="tabCall">${renderContractCall(resp)}</div>
                <div class="tab-pane fade" id="tabRaw">
                    <div class="p-3 d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="copyRawJsonBtn"><?php echo $tt('复制 JSON', 'Copy JSON'); ?></button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="toggleRawBtn"><?php echo $tt('折叠/展开', 'Collapse/Expand'); ?></button>
                    </div>
                    <div id="rawJsonWrap" class="collapse show">
                        <pre class="json-box">${syntaxHighlightJson(resp)}</pre>
                    </div>
                </div>
            </div>
        </div>
        <div id="autoMultiBalanceBox"></div>
        <div id="walletIntelBox"></div>
        ${renderAddressTokenBalances(resp)}
        ${renderAdvancedFilterPanel()}
    `;

    document.getElementById('copyResultLinkBtn')?.addEventListener('click', () => {
        copyText(shareUrl);
        setStatus('<?php echo $tt('链接已复制', 'Link copied'); ?>', 'bg-success');
    });
    document.querySelectorAll('.js-copy-field').forEach((btn) => {
        btn.addEventListener('click', () => {
            copyText(btn.getAttribute('data-copy') || '');
            setStatus('<?php echo $tt('字段已复制', 'Field copied'); ?>', 'bg-success');
        });
    });
    document.getElementById('copyRawJsonBtn')?.addEventListener('click', () => {
        copyText(JSON.stringify(resp, null, 2));
        setStatus('<?php echo $tt('JSON 已复制', 'JSON copied'); ?>', 'bg-success');
    });
    document.getElementById('toggleRawBtn')?.addEventListener('click', () => {
        const el = document.getElementById('rawJsonWrap');
        if (el) bootstrap.Collapse.getOrCreateInstance(el).toggle();
    });
    document.getElementById('applyFilterBtn')?.addEventListener('click', applyAdvancedFilters);
    document.getElementById('exportCsvBtn')?.addEventListener('click', exportFilteredCsv);
    ['fKeyword','fDirection','fMinAmount','fMaxAmount','fMinBlock','fMaxBlock'].forEach((id) => {
        document.getElementById(id)?.addEventListener('input', applyAdvancedFilters);
        document.getElementById(id)?.addEventListener('change', applyAdvancedFilters);
    });
    applyAdvancedFilters();

    if (String(resp?.query_type || '') === 'address') {
        const addr = String(resp?.data?.address || resp?.query || '').trim();
        if (/^0x[a-fA-F0-9]{40}$/.test(addr) || /^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(addr)) {
            loadAutoMultiBalance(addr);
        }
        if (/^0x[a-fA-F0-9]{40}$/.test(addr)) {
            const chain = String(resp?.chain || '').toLowerCase();
            const nonEvmSet = new Set(['trc20', 'btc', 'solana']);
            if (!nonEvmSet.has(chain)) loadWalletIntel(chain || 'eth', addr);
        }
    }
}

function persistHistory(query, chain) {
    const key = 'chain_lookup_history_v1';
    const old = JSON.parse(localStorage.getItem(key) || '[]');
    const fresh = [{ query, chain, ts: Date.now() }, ...old.filter((x) => !(x.query === query && x.chain === chain))].slice(0, 12);
    localStorage.setItem(key, JSON.stringify(fresh));
    renderHistory();
}

function renderHistory() {
    const key = 'chain_lookup_history_v1';
    const list = JSON.parse(localStorage.getItem(key) || '[]');
    const root = document.getElementById('historyList');
    if (!list.length) {
        root.innerHTML = `<div class="text-secondary" style="font-size:.78rem;"><?php echo $tt('暂无历史记录', 'No history'); ?></div>`;
        return;
    }
    root.innerHTML = list.map((it) => `<div class="history-item" data-chain="${esc(it.chain)}" data-query="${esc(it.query)}">[${esc(it.chain)}] ${esc(it.query)}</div>`).join('');
    root.querySelectorAll('.history-item').forEach((el) => {
        el.addEventListener('click', () => {
            document.getElementById('queryInput').value = el.getAttribute('data-query') || '';
            doLookup();
        });
    });
}

/* ─── Gas tracker ────────────────────────────────────────────────────────── */
const GAS_CHAINS = [
    { key: 'bsc',      label: 'BSC',      rpc: 'https://bsc-dataseed1.binance.org/',    usdt_gas: 80000, eth_price: 0 },
    { key: 'eth',      label: 'ETH',      rpc: 'https://eth.llamarpc.com',              usdt_gas: 65000, eth_price: 0 },
    { key: 'polygon',  label: 'Polygon',  rpc: 'https://polygon-rpc.com',               usdt_gas: 65000, eth_price: 0 },
    { key: 'arbitrum', label: 'Arbitrum', rpc: 'https://arb1.arbitrum.io/rpc',          usdt_gas: 300000, eth_price: 0 },
    { key: 'base',     label: 'Base',     rpc: 'https://mainnet.base.org',              usdt_gas: 65000, eth_price: 0 },
];
let gasPriceCache = {};

async function fetchGasPrice(chain) {
    try {
        const resp = await fetch(chain.rpc, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'eth_gasPrice', params: [] }),
        });
        const data = await resp.json();
        const hex = data.result;
        if (!hex) return null;
        const wei = parseInt(hex, 16);
        return wei / 1e9; // gwei
    } catch (_) { return null; }
}

async function loadGasPrices() {
    const grid = document.getElementById('gasGrid');
    if (!grid) return;
    grid.innerHTML = GAS_CHAINS.map((c) => `<div class="gas-item" id="gas_${c.key}"><div class="gas-chain">${c.label}</div><div class="gas-gwei">…</div><div class="gas-usd">…</div></div>`).join('');

    // Get ETH price for USD estimate
    let ethPrice = 3000; // fallback
    try {
        const cache = JSON.parse(localStorage.getItem('chain_market_cache_v1') || '{}');
        const items = cache.items || [];
        const eth = items.find((x) => x.id === 'ethereum');
        if (eth?.current_price) ethPrice = eth.current_price;
    } catch (_) {}

    const nativePrices = { bsc: 600, eth: ethPrice, polygon: 0.9, arbitrum: ethPrice, base: ethPrice };

    await Promise.all(GAS_CHAINS.map(async (c) => {
        const gwei = await fetchGasPrice(c);
        const el = document.getElementById(`gas_${c.key}`);
        if (!el) return;
        if (gwei === null) {
            el.querySelector('.gas-gwei').textContent = '–';
            el.querySelector('.gas-usd').textContent = '–';
            return;
        }
        const native = nativePrices[c.key] || 0;
        const fee_native = (gwei * 1e-9) * c.usdt_gas;
        const fee_usd = fee_native * native;
        el.querySelector('.gas-gwei').textContent = gwei.toFixed(2) + ' Gwei';
        el.querySelector('.gas-usd').textContent = fee_usd > 0 ? `≈ $${fee_usd.toFixed(4)}` : '–';
        gasPriceCache[c.key] = gwei;
    }));
}

/* ─── Watch edit modal ───────────────────────────────────────────────────── */
let watchEditTarget = null;

function openWatchEditModal(item) {
    watchEditTarget = item;
    document.getElementById('watchEditId').value = item.id;
    document.getElementById('watchEditTag').value = item.private_tag || '';
    document.getElementById('watchEditNote').value = item.private_note || '';
    document.getElementById('watchEditNotify').checked = !!Number(item.notify_enabled);
    document.getElementById('watchEditOverlay').classList.add('active');
}

function closeWatchEditModal() {
    document.getElementById('watchEditOverlay').classList.remove('active');
    watchEditTarget = null;
}

document.getElementById('watchEditCancel')?.addEventListener('click', closeWatchEditModal);
document.getElementById('watchEditOverlay')?.addEventListener('click', (e) => {
    if (e.target === document.getElementById('watchEditOverlay')) closeWatchEditModal();
});
document.getElementById('watchEditSave')?.addEventListener('click', async () => {
    const id = Number(document.getElementById('watchEditId').value || 0);
    if (!id) return;
    try {
        await saveWatchItem({
            action: 'update', id,
            private_tag: document.getElementById('watchEditTag').value.trim(),
            private_note: document.getElementById('watchEditNote').value.trim(),
            notify_enabled: document.getElementById('watchEditNotify').checked ? 1 : 0,
        });
        closeWatchEditModal();
        await loadWatchlist();
        setStatus('<?php echo $tt('已保存', 'Saved'); ?>', 'bg-success');
    } catch (err) {
        setStatus(String(err?.message || 'save failed'), 'bg-danger');
    }
});

/* ─── EVM input decoder (extended) ──────────────────────────────────────── */
function decodeEvmInput(hex) {
    const clean = (hex || '').trim().toLowerCase();
    if (!/^0x[0-9a-f]+$/.test(clean) || clean.length < 10) return null;
    const method = clean.slice(2, 10);
    const payload = clean.slice(10);
    const word = (i) => payload.slice(i * 64, i * 64 + 64);
    const toAddr = (w) => '0x' + w.slice(24, 64);
    const toDec = (w) => BigInt('0x' + w).toString(10);

    // ERC-20 / ERC-721
    if (method === 'a9059cbb' && payload.length >= 128)
        return `method: transfer(address,uint256)\nto: ${toAddr(word(0))}\namount(raw): ${toDec(word(1))}`;
    if (method === '095ea7b3' && payload.length >= 128)
        return `method: approve(address,uint256)\nspender: ${toAddr(word(0))}\namount(raw): ${toDec(word(1))}`;
    if (method === '23b872dd' && payload.length >= 192)
        return `method: transferFrom(address,address,uint256)\nfrom: ${toAddr(word(0))}\nto: ${toAddr(word(1))}\namount(raw): ${toDec(word(2))}`;
    if (method === '40c10f19' && payload.length >= 128)
        return `method: mint(address,uint256)\nto: ${toAddr(word(0))}\namount(raw): ${toDec(word(1))}`;
    if (method === '42966c68' && payload.length >= 64)
        return `method: burn(uint256)\namount(raw): ${toDec(word(0))}`;
    if (method === '79cc6790' && payload.length >= 128)
        return `method: burnFrom(address,uint256)\nfrom: ${toAddr(word(0))}\namount(raw): ${toDec(word(1))}`;
    // WETH
    if (method === 'd0e30db0')
        return `method: deposit() [WETH wrap]\n<?php echo $tt('ETH 包装成 WETH（通过 msg.value 传入）', 'Wrap ETH → WETH via msg.value'); ?>`;
    if (method === '2e1a7d4d' && payload.length >= 64)
        return `method: withdraw(uint256) [WETH unwrap]\namount(raw): ${toDec(word(0))}`;
    // Uniswap v2 / v3 swap
    if (method === '38ed1739' && payload.length >= 320)
        return `method: swapExactTokensForTokens\namountIn(raw): ${toDec(word(0))}\namountOutMin(raw): ${toDec(word(1))}\n<?php echo $tt('路径 & 接收地址见原始数据', 'Path & recipient in raw data'); ?>`;
    if (method === '8803dbee' && payload.length >= 320)
        return `method: swapTokensForExactTokens\namountOut(raw): ${toDec(word(0))}\namountInMax(raw): ${toDec(word(1))}`;
    if (method === '7ff36ab5')
        return `method: swapExactETHForTokens\n<?php echo $tt('用 ETH 换 Token（Uniswap V2）', 'Swap ETH for Token (Uniswap V2)'); ?>`;
    if (method === '18cbafe5' && payload.length >= 320)
        return `method: swapExactTokensForETH\namountIn(raw): ${toDec(word(0))}\namountOutMin(raw): ${toDec(word(1))}`;
    // Multicall
    if (method === 'ac9650d8')
        return `method: multicall(bytes[])\n<?php echo $tt('批量调用，需解析 bytes[] 参数', 'Batch call – decode bytes[] for details'); ?>`;
    if (method === '5ae401dc')
        return `method: multicall(uint256 deadline, bytes[])\n<?php echo $tt('Uniswap V3 multicall', 'Uniswap V3 multicall'); ?>`;
    // ERC-721
    if (method === '42842e0e' && payload.length >= 192)
        return `method: safeTransferFrom(address,address,uint256)\nfrom: ${toAddr(word(0))}\nto: ${toAddr(word(1))}\ntokenId: ${toDec(word(2))}`;
    if (method === 'b88d4fde')
        return `method: safeTransferFrom(address,address,uint256,bytes)\n<?php echo $tt('NFT 转账（带 data）', 'NFT transfer with data'); ?>`;
    // Ownable / Pausable
    if (method === 'f2fde38b' && payload.length >= 64)
        return `method: transferOwnership(address)\nnewOwner: ${toAddr(word(0))}`;
    if (method === '8456cb59')
        return `method: pause()`;
    if (method === '3f4ba83a')
        return `method: unpause()`;
    return `methodId: 0x${method}\n<?php echo $tt('未知方法 – 可到 4byte.directory 查询签名', 'Unknown method – check 4byte.directory for signature'); ?>`;
}

function buildExplorerLinkButtons() {
    const mainRoot = document.getElementById('mainExplorerLinks');
    const nicheRoot = document.getElementById('nicheExplorerLinks');
    const selected = document.getElementById('chainSelect').value;
    const query = document.getElementById('queryInput').value.trim();

    const renderSet = (items) => items.map((c) => {
        const href = query ? getExplorerUrl(c.key, query) : c.base;
        return `<a href="${esc(href)}" target="_blank" rel="noopener">${esc(c.label)}</a>`;
    }).join('');

    mainRoot.innerHTML = renderSet(CHAIN_GROUPS.main);
    nicheRoot.innerHTML = renderSet(CHAIN_GROUPS.niche);

    if (selected !== 'auto') {
        const focus = [...CHAIN_GROUPS.main, ...CHAIN_GROUPS.niche].find((x) => x.key === selected);
        if (focus && query) {
            mainRoot.innerHTML = `<a href="${esc(getExplorerUrl(focus.key, query))}" target="_blank" rel="noopener">${esc(focus.label)} · Query</a>` + mainRoot.innerHTML;
        }
    }
}

function mapChainLabel(chain) {
    const c = String(chain || '').toLowerCase();
    const map = {
        bsc: 'BSC',
        eth: 'Ethereum',
        arbitrum: 'Arbitrum',
        base: 'Base',
        polygon: 'Polygon',
        solana: 'Solana',
        trc20: 'TRON',
        btc: 'Bitcoin'
    };
    return map[c] || c.toUpperCase();
}

function mapChainDotClass(chain) {
    const c = String(chain || '').toLowerCase();
    const known = new Set(['bsc','eth','arbitrum','optimism','base','polygon','trc20','solana','btc']);
    return known.has(c) ? `dot-${c}` : 'dot-default';
}

function formatUsdPrice(v) {
    const n = Number(v);
    if (!Number.isFinite(n)) return '-';
    if (n >= 1000) return `$${n.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
    if (n >= 1) return `$${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 })}`;
    return `$${n.toLocaleString(undefined, { minimumFractionDigits: 4, maximumFractionDigits: 8 })}`;
}

function formatPercent(v) {
    const n = Number(v);
    if (!Number.isFinite(n)) return '--';
    const sign = n > 0 ? '+' : '';
    return `${sign}${n.toFixed(2)}%`;
}

function formatMarketUpdatedAt(ts) {
    const n = Number(ts);
    if (!Number.isFinite(n) || n <= 0) return '<?php echo $tt('刚刚', 'Just now'); ?>';
    const ms = n > 1e12 ? n : n * 1000;
    const d = new Date(ms);
    if (Number.isNaN(d.getTime())) return '<?php echo $tt('刚刚', 'Just now'); ?>';
    return d.toLocaleString();
}

function renderMarketPrices(items, updatedAtText) {
    const grid = document.getElementById('marketGrid');
    const tsEl = document.getElementById('marketUpdatedAt');
    if (!grid || !tsEl) return;
    if (!Array.isArray(items) || !items.length) {
        grid.innerHTML = `<div class="market-empty"><?php echo $tt('暂无币价数据', 'No market data'); ?></div>`;
        tsEl.textContent = '<?php echo $tt('暂不可用', 'Unavailable'); ?>';
        return;
    }
    grid.innerHTML = items.map((it) => {
        const ch = Number(it.price_change_percentage_24h);
        const cls = Number.isFinite(ch) ? (ch > 0 ? 'market-up' : (ch < 0 ? 'market-down' : 'market-flat')) : 'market-flat';
        const nm = String(it.name || '').trim();
        return `
            <div class="market-item">
                <div class="market-top">
                    <img src="${esc(it.image || '')}" alt="${esc(it.symbol || '')}" class="market-logo" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display='none'">
                    <div>
                        <div class="market-symbol">${esc(String(it.symbol || '').toUpperCase())}</div>
                        <div class="market-name">${esc(nm || '-')}</div>
                    </div>
                </div>
                <div class="market-price mono">${esc(formatUsdPrice(it.current_price))}</div>
                <div class="market-change ${cls}">${esc(formatPercent(it.price_change_percentage_24h))}</div>
            </div>
        `;
    }).join('');
    tsEl.textContent = updatedAtText || '<?php echo $tt('刚刚更新', 'Updated just now'); ?>';
}

async function loadMarketPrices() {
    const grid = document.getElementById('marketGrid');
    const tsEl = document.getElementById('marketUpdatedAt');
    if (!grid || !tsEl) return;
    tsEl.textContent = '<?php echo $tt('加载中...', 'Loading...'); ?>';
    try {
        const endpoint = `https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&ids=${encodeURIComponent(MARKET_COINS.join(','))}&order=market_cap_desc&per_page=20&page=1&sparkline=false&price_change_percentage=24h`;
        const resp = await fetch(endpoint, { method: 'GET', cache: 'no-store' });
        const data = await resp.json();
        if (!resp.ok || !Array.isArray(data)) throw new Error('market request failed');
        const normalized = data.filter((x) => x && x.id).slice(0, 10);
        localStorage.setItem(marketCacheKey, JSON.stringify({ ts: Date.now(), items: normalized }));
        renderMarketPrices(normalized, `${formatMarketUpdatedAt(Date.now())} · <?php echo $tt('实时', 'Live'); ?>`);
    } catch (_) {
        try {
            const cache = JSON.parse(localStorage.getItem(marketCacheKey) || '{}');
            if (Array.isArray(cache.items) && cache.items.length) {
                renderMarketPrices(cache.items, `${formatMarketUpdatedAt(cache.ts)} · <?php echo $tt('缓存', 'Cached'); ?>`);
                return;
            }
        } catch (_) {}
        renderMarketPrices([], '');
    }
}

function renderChainStatus(payload) {
    const root = document.getElementById('chainStatusGrid');
    const arr = Array.isArray(payload?.chains) ? payload.chains : [];
    if (!arr.length) {
        root.innerHTML = `<div class="text-danger" style="font-size:.82rem;"><?php echo $tt('状态读取失败', 'Failed to load status'); ?></div>`;
        return;
    }
    root.innerHTML = arr.map((it) => {
        const ok = !!it.ok;
        const dot = ok ? 'dot-ok' : 'dot-bad';
        const okText = ok ? '<?php echo $tt('正常', 'Online'); ?>' : '<?php echo $tt('异常', 'Error'); ?>';
        const bn = it.latest_block != null ? it.latest_block : '-';
        const gas = it.gas_gwei != null
            ? `${it.gas_gwei} Gwei`
            : (it.gas_text ? String(it.gas_text) : '<?php echo $tt('不适用/未知', 'N/A'); ?>');
        const rpc = it.rpc ? String(it.rpc) : '-';
        const err = !ok && it.error ? String(it.error) : '';
        return `
        <div class="status-item">
            <div class="c">
                <strong>${esc(mapChainLabel(it.chain))}</strong>
                <span><span class="dot ${dot}"></span> ${okText}</span>
            </div>
            <div class="v"><?php echo $tt('最新区块', 'Latest Block'); ?>: <span class="mono">${esc(bn)}</span></div>
            <div class="v">Gas: <span class="mono">${esc(gas)}</span></div>
            <div class="v"><?php echo $tt('节点', 'RPC'); ?>: <span class="mono">${esc(rpc)}</span></div>
            ${err ? `<div class="v text-danger"><?php echo $tt('原因', 'Reason'); ?>: <span class="mono">${esc(err)}</span></div>` : ''}
        </div>`;
    }).join('');
}

async function loadChainStatus() {
    const root = document.getElementById('chainStatusGrid');
    root.innerHTML = `<div class="text-secondary" style="font-size:.82rem;"><?php echo $tt('加载中...', 'Loading...'); ?></div>`;
    try {
        const resp = await fetch('/api/v1/chain/status.php', { method: 'GET', cache: 'no-store' });
        const data = await parseApiJson(resp);
        if (!resp.ok || data.status !== 'success') throw new Error(data.message || 'status failed');
        renderChainStatus(data);
    } catch (e) {
        root.innerHTML = `<div class="text-danger" style="font-size:.82rem;">${esc(e.message || '<?php echo $tt('状态读取失败', 'Failed to load status'); ?>')}</div>`;
    }
}

async function loadWatchlist() {
    const root = document.getElementById('watchList');
    root.innerHTML = `<div class="text-secondary"><?php echo $tt('加载中...', 'Loading...'); ?></div>`;
    try {
        const resp = await fetch('/api/v1/chain/watchlist.php', { method: 'GET', cache: 'no-store' });
        const data = await parseApiJson(resp);
        if (!resp.ok || data.status !== 'success') throw new Error(data.message || 'watchlist failed');
        watchItems = Array.isArray(data.items) ? data.items : [];
        if (!watchItems.length) {
            root.innerHTML = `<div class="text-secondary"><?php echo $tt('暂无关注项目', 'No watch items yet'); ?></div>`;
            return;
        }
        root.innerHTML = watchItems.map((it) => `
            <div class="watch-item" data-id="${esc(it.id)}">
                <div class="d-flex justify-content-between gap-2 align-items-center">
                    <div>
                        <strong>${esc((it.chain || '').toUpperCase())}</strong>
                        <span class="mono">${esc(it.query_value || '')}</span>
                        ${it.private_tag ? `<span class="tag-chip">${esc(it.private_tag)}</span>` : ''}
                    </div>
                    <div class="mini-actions">
                        <button class="btn btn-sm btn-outline-light watch-open"><?php echo $tt('打开', 'Open'); ?></button>
                        <button class="btn btn-sm btn-outline-light watch-edit"><?php echo $tt('编辑', 'Edit'); ?></button>
                        <button class="btn btn-sm btn-outline-danger watch-del"><?php echo $tt('删除', 'Delete'); ?></button>
                    </div>
                </div>
                <div class="watch-meta">
                    <span><?php echo $tt('备注', 'Note'); ?>: ${esc(it.private_note || '-')}</span>
                    <span><?php echo $tt('提醒', 'Notify'); ?>: ${it.notify_enabled ? 'ON' : 'OFF'}</span>
                </div>
            </div>
        `).join('');
    } catch (e) {
        root.innerHTML = `<div class="text-danger">${esc(e.message || 'watchlist failed')}</div>`;
    }
}

async function saveWatchItem(payload) {
    const resp = await fetch('/api/v1/chain/watchlist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const data = await parseApiJson(resp);
    if (!resp.ok || data.status !== 'success') throw new Error(data.message || 'watch save failed');
}

async function addCurrentToWatchlist() {
    const chain = (currentLookupChain || document.getElementById('chainSelect').value || 'auto').toLowerCase();
    const query = currentLookupQuery || document.getElementById('queryInput').value.trim();
    if (!query || chain === 'auto') {
        setStatus('<?php echo $tt('先完成一次明确链查询，再加入关注', 'Run a chain-specific lookup before adding to watchlist'); ?>', 'bg-warning text-dark');
        return;
    }
    const tag = document.getElementById('watchTagInput').value.trim();
    const note = document.getElementById('watchNoteInput').value.trim();
    try {
        await saveWatchItem({ action: 'add', chain, query, private_tag: tag, private_note: note, notify_enabled: 1 });
        await loadWatchlist();
        setStatus('<?php echo $tt('已加入关注', 'Added to watchlist'); ?>', 'bg-success');
    } catch (e) {
        setStatus((e && e.message) ? e.message : 'watch add failed', 'bg-danger');
    }
}

function lookupFingerprint(ret) {
    const qType = String(ret?.query_type || '');
    const d = ret?.data || {};
    if (qType === 'tx') return JSON.stringify([d.hash || d.txid || '', d.status || d.result || '', d.confirmations || d.block_height || d.block || 0]);
    if (qType === 'address') return JSON.stringify([d.address || '', d.native_balance || d.balance_sol || d.trx_balance || '', d.nonce || 0, d.token_count || 0]);
    if (qType === 'block') return JSON.stringify([d.hash || d.id || '', d.number || d.height || d.slot || 0, d.tx_count || 0]);
    return JSON.stringify(ret?.data || {});
}

async function checkWatchAlerts(showIfChanged) {
    const stat = document.getElementById('watchAlertStat');
    if (!watchItems.length) {
        stat.textContent = '0';
        return;
    }
    const fpMap = getWatchFpMap();
    const changed = [];
    for (const it of watchItems.slice(0, 30)) {
        if (!Number(it.notify_enabled)) continue;
        try {
            const resp = await fetch('/api/v1/chain/lookup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: it.query_value, chain: it.chain }),
            });
            const data = await parseApiJson(resp);
            if (!resp.ok || data.status !== 'success') continue;
            const nextFp = lookupFingerprint(data);
            const key = String(it.id);
            if (fpMap[key] && fpMap[key] !== nextFp) {
                changed.push(`${String(it.chain).toUpperCase()} ${it.query_value}`);
            }
            fpMap[key] = nextFp;
        } catch (_) {}
    }
    setWatchFpMap(fpMap);
    stat.textContent = String(changed.length);
    stat.className = changed.length ? 'badge bg-danger' : 'badge bg-secondary';
    if (changed.length && showIfChanged) {
        alert(`<?php echo $tt('关注项有变更：', 'Watchlist changed: '); ?>\n` + changed.join('\n'));
    }
}

async function runDiagnostics() {
    const out = document.getElementById('diagOutput');
    const query = currentLookupQuery || document.getElementById('queryInput').value.trim();
    const chain = (currentLookupChain || document.getElementById('chainSelect').value || '').toLowerCase();
    const evmSet = new Set(['bsc','eth','arbitrum','optimism','base','polygon','avalanche','linea','opbnb','zksync','fantom','gnosis']);
    if (!query || !evmSet.has(chain)) {
        out.textContent = '<?php echo $tt('请先查询 EVM 链结果，再执行诊断。', 'Please lookup an EVM chain first, then run diagnostics.'); ?>';
        return;
    }
    out.textContent = '<?php echo $tt('诊断中...', 'Diagnosing...'); ?>';
    try {
        const resp = await fetch('/api/v1/chain/diagnostics.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chain, query }),
        });
        const data = await parseApiJson(resp);
        if (!resp.ok || data.status !== 'success') throw new Error(data.message || 'diagnostics failed');
        const summary = [
            `chain: ${data.chain}`,
            `query_type: ${data.query_type}`,
            `healthy_nodes: ${data.healthy_nodes}/${data.total_nodes}`,
            `consistent_probe: ${data.consistent_probe ? 'YES' : 'NO'}`,
            `max_block: ${data.max_block ?? '-'}`,
            `lagging_nodes: ${(data.lagging_nodes || []).map((x) => `${x.rpc}(-${x.lag})`).join(', ') || '-'}`,
            '',
            ...((data.nodes || []).map((n) => `${n.ok ? '[OK]' : '[BAD]'} ${n.rpc}\n  latest_block=${n.latest_block ?? '-'}\n  probe=${JSON.stringify(n.probe || {})}\n  error=${n.error || '-'}`)),
        ];
        out.textContent = summary.join('\n');
    } catch (e) {
        out.textContent = String(e?.message || 'diagnostics failed');
    }
}

async function doLookup() {
    const rawQ = document.getElementById('queryInput').value.trim();
    const q = rawQ;
    const chain = 'auto';

    if (!q) {
        setStatus('<?php echo $tt('请输入查询内容', 'Enter query'); ?>', 'bg-warning text-dark');
        return;
    }

    setStatus('<?php echo $tt('查询中...', 'Searching...'); ?>', 'bg-info text-dark');
    document.getElementById('emptyHint').style.display = 'none';
    document.getElementById('resultBody').style.display = 'block';
    document.getElementById('resultBody').innerHTML = `<div class="p-4 text-muted"><?php echo $tt('正在请求链上数据，请稍候...', 'Fetching on-chain data...'); ?></div>`;

    try {
        const resp = await fetch('/api/v1/chain/lookup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ query: q, chain })
        });
        const data = await parseApiJson(resp);
        if (!resp.ok || data.status !== 'success') {
            throw new Error(data.message || 'Lookup failed');
        }
        setStatus('<?php echo $tt('查询成功', 'Success'); ?>', 'bg-success');
        renderResult(data);
    } catch (e) {
        setStatus('<?php echo $tt('未找到', 'Not Found'); ?>', 'bg-danger');
        document.getElementById('resultBody').innerHTML = `<div class="p-4 text-danger">${esc(normalizeApiErrorMessage(e.message || 'Lookup failed'))}</div>`;
    }
}

document.getElementById('searchBtn').addEventListener('click', doLookup);
document.getElementById('queryInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') doLookup();
});

const qp = new URLSearchParams(location.search);
const initQ = (qp.get('query') || '').trim();
if (initQ) {
    document.getElementById('queryInput').value = initQ;
    doLookup();
}
</script>
</body>
</html>
