<?php
declare(strict_types=1);
/** Draws MaluMail's brand images with GD (the logo, the abbreviated mark, the favicon, the PWA icons): php bin/make_brand_images.php. Rerun after changing the mark. */
$dir = dirname(__DIR__) . '/html/assets/images';
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$indigo = [13, 110, 140];                                        // MaluMail's teal (the variable keeps the kit's name)
function mark(int $size, bool $rounded = true): GdImage
{
    global $indigo;
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    $bg = imagecolorallocate($im, ...$indigo);
    $r = $rounded ? (int) round($size * 0.22) : 0;
    imagefilledrectangle($im, $r, 0, $size - 1 - $r, $size - 1, $bg);
    imagefilledrectangle($im, 0, $r, $size - 1, $size - 1 - $r, $bg);
    foreach ([[$r, $r], [$size - 1 - $r, $r], [$r, $size - 1 - $r], [$size - 1 - $r, $size - 1 - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $bg);
    }
    // an envelope: a white letter with its flap folded down — mail
    $white = imagecolorallocate($im, 255, 255, 255);
    $ex0 = (int) round($size * 0.18); $ex1 = $size - 1 - $ex0;      // the envelope's sides
    $ey0 = (int) round($size * 0.28); $ey1 = $size - 1 - $ey0;      // its top and bottom
    imagefilledrectangle($im, $ex0, $ey0, $ex1, $ey1, $white);
    $t = max(2, (int) round($size * 0.05));
    imagesetthickness($im, $t);
    $cx = (int) round($size / 2); $cy = (int) round($ey0 + ($ey1 - $ey0) * 0.58);
    imageline($im, $ex0 + $t, $ey0 + $t, $cx, $cy, $bg);             // the flap: from both top corners down to the middle
    imageline($im, $ex1 - $t, $ey0 + $t, $cx, $cy, $bg);
    imagesetthickness($im, 1);
    return $im;
}
foreach (['favicon.png' => 64, 'logo-abbr.png' => 128, 'apple-touch-icon.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512] as $file => $size) {
    $im = mark($size, $file !== 'apple-touch-icon.png');
    if ($file === 'apple-touch-icon.png') {                                   // iOS rounds it itself: an opaque square
        $flat = imagecreatetruecolor($size, $size);
        imagefill($flat, 0, 0, imagecolorallocate($flat, ...$indigo));
        imagecopy($flat, $im, 0, 0, 0, 0, $size, $size);
        $im = $flat;
    }
    imagepng($im, $dir . '/' . $file);
}
// the full logo: the mark and the name
$w = 420; $h = 90;
$im = imagecreatetruecolor($w, $h);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);
$m = mark(70);
imagecopy($im, $m, 4, 10, 0, 0, 70, 70);
$dark = imagecolorallocate($im, 33, 37, 41);
imagettftext($im, 21, 0, 86, 55, $dark, $font, 'MaluMail');
imagepng($im, $dir . '/logo-full.png');
echo "brand images drawn in $dir\n";
