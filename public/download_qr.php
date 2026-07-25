<?php
// public/download_qr.php
require_once __DIR__ . '/../src/Core/Database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = Database::getInstance();
$code = $db->fetch("SELECT * FROM qr_codes WHERE id = ?", [$id]);

if (!$code) {
    die("QR Code not found");
}

$style = $code['style'] ?? 'green';
// Map old styles to new templates if needed, or just default to green
if (!in_array($style, ['green', 'blue', 'dark'])) $style = 'green';

$templatePath = __DIR__ . '/assets/templates/template_' . $style . '.png';
if (!file_exists($templatePath)) {
    die("Template not found. Please regenerate templates.");
}

// Load Template
$template = imagecreatefrompng($templatePath);
if (!$template) die("Failed to load template");

$width = imagesx($template);
$height = imagesy($template);

// Generate QR Code (using API for simplicity, but high res)
// Note: In production, better to use local phpqrcode library for speed and reliability.
$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$pay_url = $base_url . '/qr_pay.php?id=' . $code['id'];
$qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=600x600&margin=0&data=" . urlencode($pay_url);

$qr_source = imagecreatefromstring(file_get_contents($qr_api_url));
if (!$qr_source) die("Failed to load QR code from API");

// Overlay QR Code
// Template dimensions are 800x1200.
// Green/Dark/Blue templates all have a "card" area.
// We'll aim for center-ish.
// Based on generate_templates.php:
// Card is at roughly y=300 to y=1000.
// We want QR to be centered horizontally.
$qr_target_w = 400;
$qr_target_h = 400;
$qr_x = ($width - $qr_target_w) / 2;
$qr_y = 400; // Adjusted based on template visual center

imagecopyresampled($template, $qr_source, $qr_x, $qr_y, 0, 0, $qr_target_w, $qr_target_h, imagesx($qr_source), imagesy($qr_source));

// Add Store Name Text
// Since we don't have TTF, we use basic font but try to make it legible.
// For "Professional" results without TTF, we might skip text or use very basic labeling.
// However, the user complained about "messy text".
// The previous implementation used standard GD font 5 which is small and pixelated on large images.
// Solution: We will NOT draw ugly text. The templates already look like "USDT Payment".
// We will just add the Store Name in a clean, simple way if possible, or omit if it looks bad.
// Given the user wants "Professional", standard GD font is risky.
// Let's try to scale the font or just rely on the template's look.
// BUT, the user said "Use that picture + user's QR code combined".
// So maybe just the QR is enough if the template is good.
// Let's add the store name using the built-in font but zoomed? No, GD doesn't zoom built-in fonts well.
// We will skip adding text dynamically to avoid "messy text" complaints, 
// as the templates generated have no text (just blocks), 
// Wait, my `generate_templates.php` drew BLOCKS, not text.
// So the templates are blank colored cards.
// I need to add text to the templates or add text here.
// I will use `imagestring` but centered and maybe duplicate it to make it "bold".
// Or better: I will update `generate_templates.php` to include "USDT PAYMENT" text if I can find a way,
// or I will just use `imagestring` here carefully.

// Let's try to add the Store Name
$text_color = imagecolorallocate($template, 50, 50, 50);
$font = 5; // Largest built-in font
$text = mb_convert_encoding($code['name'], 'ISO-8859-1', 'UTF-8'); // GD built-in font only supports Latin-1 usually
// If the store name is Chinese, built-in font will fail (output garbage/messy text).
// THIS IS THE CAUSE OF "MESSY TEXT" (Chinese characters becoming mojibake).
// FIX: Without a TTF font file supporting Chinese, we CANNOT draw Chinese text on the image.
// Action: I will look for a font file or fallback to not drawing text if Chinese.
// Since I cannot upload a font file easily, I will SKIP drawing the store name on the image 
// to avoid the "messy text" issue completely. 
// The user will get a clean template + QR. 
// The "Store Name" is visible when scanning.
// To make it look professional, I will draw "SCAN TO PAY" in English which works with built-in fonts.

$en_text = "SCAN TO PAY";
$font_width = imagefontwidth($font) * strlen($en_text);
$x = ($width - $font_width) / 2;
imagestring($template, $font, $x, $qr_y + $qr_target_h + 30, $en_text, $text_color);

// Output
header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="payment_code_' . $code['id'] . '.png"');
imagepng($template);
imagedestroy($template);
imagedestroy($qr_source);
