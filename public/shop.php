<?php
// public/shop.php
require_once __DIR__ . '/../src/Core/Database.php';
$db = Database::getInstance();

$slug = $_GET['store'] ?? '';
$store = $db->fetch("SELECT * FROM stores WHERE slug = ? AND status = 'active'", [$slug]);

if (!$store) {
    die("Store not found");
}

$featured_products = $db->fetchAll("SELECT * FROM store_products WHERE store_id = ? AND status = 'active' AND is_featured = 1 ORDER BY id DESC", [$store['id']]);
$all_products = $db->fetchAll("SELECT * FROM store_products WHERE store_id = ? AND status = 'active' ORDER BY id DESC", [$store['id']]);

$categories = [];
foreach ($all_products as $p) {
    $cat = $p['category'] ?: 'General';
    if (!in_array($cat, $categories)) $categories[] = $cat;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($store['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/all.min.css">
    <style>
        :root { --primary-color: #0f172a; --accent-color: #3b82f6; }
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #334155; background: #f8fafc; }
        .hero-section { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; padding: 80px 0 100px; text-align: center; position: relative; }
        .hero-section::after { content: ''; position: absolute; bottom: -50px; left: 0; width: 100%; height: 100px; background: #f8fafc; transform: skewY(-2deg); }
        .product-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; transition: all 0.3s ease; height: 100%; display: flex; flex-direction: column; overflow: hidden; }
        .product-card:hover { transform: translateY(-8px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); border-color: var(--accent-color); }
        .product-img-wrapper { position: relative; padding-top: 60%; background: #f1f5f9; overflow: hidden; }
        .product-img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
        .product-card:hover .product-img { transform: scale(1.05); }
        .price-tag { font-size: 1.5rem; font-weight: 800; color: #0f172a; }
        .footer { background: #fff; padding: 60px 0 30px; border-top: 1px solid #e2e8f0; margin-top: 80px; }
    </style>
</head>
<body>

<div class="hero-section">
    <div class="container">
        <?php if (!empty($store['logo_url'])): ?>
            <div class="mb-4">
                <img src="<?php echo htmlspecialchars($store['logo_url']); ?>" alt="logo" style="max-height:56px;max-width:220px;object-fit:contain;">
            </div>
        <?php endif; ?>
        <h1 class="display-4 fw-bold mb-3"><?php echo htmlspecialchars($store['name']); ?></h1>
        <p class="lead text-light opacity-75 mb-4" style="max-width: 600px; margin: 0 auto;"><?php echo nl2br(htmlspecialchars($store['description'])); ?></p>
        <a href="#products" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold border-0" style="background: var(--accent-color);">Browse Products</a>
    </div>
</div>

<div class="container" style="margin-top: -40px; position: relative; z-index: 10;">
    <?php if(!empty($featured_products)): ?>
    <div class="mb-5">
        <div class="row g-4">
            <?php foreach($featured_products as $p): ?>
            <div class="col-md-6 col-lg-4">
                <div class="product-card border-0 shadow-lg">
                    <div class="product-img-wrapper">
                        <?php if($p['image_url']): ?><img src="<?php echo htmlspecialchars($p['image_url']); ?>" class="product-img"><?php endif; ?>
                        <div class="position-absolute top-0 end-0 m-3"><span class="badge bg-warning text-dark fw-bold shadow-sm"><i class="fas fa-star me-1"></i> Featured</span></div>
                    </div>
                    <div class="p-4 d-flex flex-column flex-grow-1">
                        <div class="mb-2"><span class="badge bg-primary bg-opacity-10 text-primary"><?php echo htmlspecialchars($p['category'] ?: 'General'); ?></span></div>
                        <h5 class="fw-bold mb-2"><?php echo htmlspecialchars($p['name']); ?></h5>
                        <p class="text-secondary small mb-4 flex-grow-1"><?php echo mb_strimwidth(strip_tags($p['description']), 0, 100, '...'); ?></p>
                        <div class="d-flex justify-content-between align-items-center mt-auto pt-3 border-top">
                            <div class="price-tag"><?php echo number_format($p['price'], 2); ?> <span class="fs-6 text-muted fw-normal">USDT</span></div>
                            <a href="shop_product.php?id=<?php echo $p['id']; ?>" class="btn btn-primary rounded-pill px-4 fw-bold">Buy Now</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div id="products" class="py-5">
        <h2 class="fw-bold text-center mb-5">All Products</h2>
        
        <?php if(count($categories) > 1): ?>
        <ul class="nav nav-pills justify-content-center mb-5" id="pills-tab">
            <li class="nav-item"><button class="nav-link active rounded-pill px-4" data-bs-toggle="pill" data-bs-target="#all">All</button></li>
            <?php foreach($categories as $i => $cat): ?>
            <li class="nav-item"><button class="nav-link rounded-pill px-4" data-bs-toggle="pill" data-bs-target="#cat-<?php echo $i; ?>"><?php echo htmlspecialchars($cat); ?></button></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        
        <div class="tab-content">
            <div class="tab-pane fade show active" id="all">
                <div class="row g-4">
                    <?php foreach($all_products as $p): ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="product-card">
                            <div class="product-img-wrapper" style="padding-top: 56.25%;">
                                <?php if($p['image_url']): ?><img src="<?php echo htmlspecialchars($p['image_url']); ?>" class="product-img"><?php endif; ?>
                            </div>
                            <div class="p-3 d-flex flex-column flex-grow-1">
                                <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($p['name']); ?></h6>
                                <div class="mt-auto d-flex justify-content-between align-items-center pt-2">
                                    <div class="fw-bold">$<?php echo number_format($p['price'], 2); ?></div>
                                    <a href="shop_product.php?id=<?php echo $p['id']; ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">View</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php foreach($categories as $i => $cat): ?>
            <div class="tab-pane fade" id="cat-<?php echo $i; ?>">
                <div class="row g-4">
                    <?php foreach($all_products as $p): if(($p['category']?:'General')===$cat): ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="product-card">
                            <div class="product-img-wrapper" style="padding-top: 56.25%;">
                                <?php if($p['image_url']): ?><img src="<?php echo htmlspecialchars($p['image_url']); ?>" class="product-img"><?php endif; ?>
                            </div>
                            <div class="p-3 d-flex flex-column flex-grow-1">
                                <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($p['name']); ?></h6>
                                <div class="mt-auto d-flex justify-content-between align-items-center pt-2">
                                    <div class="fw-bold">$<?php echo number_format($p['price'], 2); ?></div>
                                    <a href="shop_product.php?id=<?php echo $p['id']; ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">View</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="py-5 border-top mt-5">
        <h3 class="fw-bold text-center mb-4">Common Questions</h3>
        <div class="accordion w-75 mx-auto" id="faq">
            <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                <h2 class="accordion-header"><button class="accordion-button fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#q1">How do I receive my product?</button></h2>
                <div id="q1" class="accordion-collapse collapse show" data-bs-parent="#faq"><div class="accordion-body text-secondary">Instant delivery via email after payment confirmation.</div></div>
            </div>
            <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                <h2 class="accordion-header"><button class="accordion-button collapsed fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#q2">What payments are accepted?</button></h2>
                <div id="q2" class="accordion-collapse collapse" data-bs-parent="#faq"><div class="accordion-body text-secondary">We accept USDT (TRC20, BSC, Polygon, ERC20).</div></div>
            </div>
        </div>
    </div>
</div>

<footer class="footer text-center">
    <div class="container">
        <h5 class="fw-bold"><?php echo htmlspecialchars($store['name']); ?></h5>
        <p class="text-muted small">&copy; <?php echo date('Y'); ?> All rights reserved. Powered by UAPI.</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
