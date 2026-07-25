<?php
session_start();
$code = '';
for ($i = 0; $i < 4; $i++) {
    $code .= random_int(0, 9);
}
$_SESSION['captcha'] = $code;

$width = 100;
$height = 40;
$image = imagecreate($width, $height);
$bg = imagecolorallocate($image, 255, 255, 255);
$text_color = imagecolorallocate($image, 0, 0, 0);
$line_color = imagecolorallocate($image, 200, 200, 200);

// Add noise
for ($i = 0; $i < 5; $i++) {
    imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $line_color);
}

// Add text
imagestring($image, 5, 30, 12, $code, $text_color);

header('Content-Type: image/jpeg');
imagejpeg($image);
imagedestroy($image);
?>