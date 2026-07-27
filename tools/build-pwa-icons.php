<?php

declare(strict_types=1);

/*
 * Kadens — génération des visuels PWA (icônes + écrans de démarrage iOS).
 *
 * Usage :  php tools/build-pwa-icons.php
 *
 * Source unique : assets/icons/kadens.png (lockup complet, fond transparent).
 * Sortie        : public/pwa/ — versionné, ce script sert à REgénérer, pas à
 *                 générer au déploiement (l'hébergement mutualisé n'a pas de
 *                 build). Même logique que tools/fetch-fonts.sh pour les polices.
 *
 * Pourquoi /pwa et pas /icons : Apache expose par défaut un Alias /icons/ vers
 * ses propres icônes d'autoindex. Sur mutualisé on ne peut pas le retirer, donc
 * tout fichier de public/icons/ serait inatteignable en prod.
 *
 * Deux découpes de la marque :
 *   - le K seul (traits de vitesse retirés) pour les icônes : le lockup complet
 *     fait 1,57 de rapport, il tombe à ~50 % de hauteur dans une tuile carrée et
 *     devient illisible sous 48 px. Les traits sont isolés par composantes
 *     connexes, pas par un recadrage en dur : ils chevauchent le K en abscisse.
 *   - le lockup complet pour les écrans de démarrage, où la place ne manque pas.
 */

const SRC = __DIR__ . '/../assets/icons/kadens.png';
const OUT = __DIR__ . '/../public/pwa';

/** Fond opaque des visuels : --kd-paper-0. Transparent interdit — iOS compose sur du noir. */
const BG = [0xFF, 0xFF, 0xFF];

/** Devices iOS couverts par un écran de démarrage : [largeur CSS, hauteur CSS, dpr]. */
const DEVICES = [
    // iPhone
    [375, 667, 2],   // SE 2/3, 8
    [414, 736, 3],   // 8 Plus
    [375, 812, 3],   // X, XS, 11 Pro, 12/13 mini
    [414, 896, 2],   // XR, 11
    [414, 896, 3],   // XS Max, 11 Pro Max
    [390, 844, 3],   // 12, 13, 14
    [428, 926, 3],   // 12/13 Pro Max, 14 Plus
    [393, 852, 3],   // 14 Pro, 15, 16
    [430, 932, 3],   // 14 Pro Max, 15 Plus/Pro Max, 16 Plus
    [402, 874, 3],   // 16 Pro
    [440, 956, 3],   // 16 Pro Max
    // iPad
    [768, 1024, 2],  // iPad 9.7, mini 4/5
    [744, 1133, 2],  // iPad mini 6
    [810, 1080, 2],  // iPad 10.2
    [820, 1180, 2],  // iPad Air 10.9
    [834, 1194, 2],  // iPad Pro 11
    [1024, 1366, 2], // iPad Pro 12.9
];

function fail(string $message): never
{
    fwrite(STDERR, "✗ {$message}\n");
    exit(1);
}

/**
 * Retire les composantes connexes marginales (les traits de vitesse) et renvoie
 * l'image nettoyée + la boîte englobante de ce qui reste.
 *
 * @return array{0: GdImage, 1: array{int, int, int, int}}
 */
function isolateGlyph(GdImage $src): array
{
    $w = imagesx($src);
    $h = imagesy($src);

    // -1 = encre non encore étiquetée, 0 = transparent, >0 = numéro de composante.
    $labels = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $labels[$y * $w + $x] = ((imagecolorat($src, $x, $y) >> 24) & 0x7F) < 64 ? -1 : 0;
        }
    }

    $label = 0;
    $areas = [];
    for ($i = 0, $n = $w * $h; $i < $n; $i++) {
        if ($labels[$i] !== -1) {
            continue;
        }
        $label++;
        $area = 0;
        $stack = [$i];
        $labels[$i] = $label;
        while ($stack) {
            $p = array_pop($stack);
            $px = $p % $w;
            $py = intdiv($p, $w);
            $area++;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $px + $dx;
                $ny = $py + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                    continue;
                }
                $q = $ny * $w + $nx;
                if ($labels[$q] === -1) {
                    $labels[$q] = $label;
                    $stack[] = $q;
                }
            }
        }
        $areas[$label] = $area;
    }

    if (!$areas) {
        fail('source vide : aucune encre détectée dans ' . SRC);
    }

    // Les trois masses du K pèsent chacune > 30 % de la plus grosse, les six
    // traits de vitesse < 3 %. Le seuil à 8 % tranche largement entre les deux.
    $threshold = max($areas) * 0.08;
    $keep = array_fill(0, $label + 1, false);
    foreach ($areas as $l => $a) {
        $keep[$l] = $a > $threshold;
    }

    $glyph = imagecreatetruecolor($w, $h);
    imagealphablending($glyph, false);
    imagesavealpha($glyph, true);
    imagefill($glyph, 0, 0, imagecolorallocatealpha($glyph, 0, 0, 0, 127));

    $minX = $w;
    $minY = $h;
    $maxX = -1;
    $maxY = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if (!$keep[$labels[$y * $w + $x]]) {
                continue;
            }
            imagesetpixel($glyph, $x, $y, imagecolorat($src, $x, $y));
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        }
    }

    return [$glyph, [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1]];
}

/** Boîte englobante de l'encre d'une image. @return array{int, int, int, int} */
function boundingBox(GdImage $im): array
{
    $w = imagesx($im);
    $h = imagesy($im);
    $minX = $w;
    $minY = $h;
    $maxX = -1;
    $maxY = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) >= 64) {
                continue;
            }
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        }
    }

    return [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1];
}

/**
 * Compose la marque centrée sur un fond opaque.
 *
 * @param array{int, int, int, int} $box boîte source à utiliser
 * @param float $coverage part de la plus petite dimension du canevas occupée par
 *                       la marque (0.55 pour un maskable : zone sûre à 80 %)
 * @param bool $palette   réduit à 255 couleurs. La marque n'en compte que trois,
 *                        le reste n'est que de l'antialiasing : sur un écran de
 *                        démarrage 2048×2732 c'est ~110 Ko économisés par fichier
 *                        (3,7 Mo → 300 Ko sur l'ensemble). Jamais sur les icônes,
 *                        déjà minuscules et sensibles au moindre écart de teinte.
 */
function compose(GdImage $mark, array $box, int $width, int $height, float $coverage, string $path, bool $palette = false): void
{
    [$sx, $sy, $sw, $sh] = $box;

    $canvas = imagecreatetruecolor($width, $height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, BG[0], BG[1], BG[2]));
    imagealphablending($canvas, true);
    imagesavealpha($canvas, false);

    $budget = min($width, $height) * $coverage;
    $scale = min($budget / $sw, $budget / $sh);
    $dw = max(1, (int) round($sw * $scale));
    $dh = max(1, (int) round($sh * $scale));

    imagecopyresampled(
        $canvas,
        $mark,
        intdiv($width - $dw, 2),
        intdiv($height - $dh, 2),
        $sx,
        $sy,
        $dw,
        $dh,
        $sw,
        $sh
    );

    if ($palette) {
        imagetruecolortopalette($canvas, false, 255);
    }

    if (!imagepng($canvas, $path, 9)) {
        fail("écriture impossible : {$path}");
    }
}

// ---------------------------------------------------------------------------

if (!extension_loaded('gd')) {
    fail('extension GD requise');
}
if (!is_file(SRC)) {
    fail('source introuvable : ' . SRC);
}

$src = imagecreatefrompng(SRC);
if (!$src) {
    fail('source illisible : ' . SRC);
}

$lockup = boundingBox($src);
[$glyph, $glyphBox] = isolateGlyph($src);

@mkdir(OUT, 0o755, true);
@mkdir(OUT . '/splash', 0o755, true);

// 1. Icônes carrées « any » — le K seul, généreux dans la tuile.
$icons = [
    'favicon-16.png' => [16, 0.88],
    'favicon-32.png' => [32, 0.88],
    'favicon-48.png' => [48, 0.88],
    'icon-192.png' => [192, 0.80],
    'icon-512.png' => [512, 0.80],
    // iOS ne masque pas mais rogne les angles : un peu plus de marge.
    'apple-touch-icon.png' => [180, 0.74],
];
foreach ($icons as $name => [$size, $coverage]) {
    compose($glyph, $glyphBox, $size, $size, $coverage, OUT . '/' . $name);
    echo "  ✓ pwa/{$name} ({$size}×{$size})\n";
}

// 2. Icônes « maskable » — la marque doit tenir dans le disque à 80 % du côté,
//    donc 0,55 de couverture pour absorber le rognage le plus agressif.
foreach ([192, 512] as $size) {
    compose($glyph, $glyphBox, $size, $size, 0.55, OUT . "/icon-maskable-{$size}.png");
    echo "  ✓ pwa/icon-maskable-{$size}.png ({$size}×{$size})\n";
}

// 3. Écrans de démarrage iOS — lockup complet, portrait et paysage.
//    iOS exige une correspondance EXACTE de la media query : pas de mise à
//    l'échelle, une taille non listée donne un écran blanc.
$count = 0;
$links = [];
foreach (DEVICES as [$cssW, $cssH, $dpr]) {
    // iOS rapporte toujours device-width/height dans l'orientation portrait du
    // device : seul `orientation` et l'image changent entre les deux entrées.
    foreach (['portrait' => [$cssW * $dpr, $cssH * $dpr], 'landscape' => [$cssH * $dpr, $cssW * $dpr]] as $orientation => [$w, $h]) {
        compose($src, $lockup, $w, $h, 0.42, OUT . "/splash/splash-{$w}x{$h}.png", true);
        $links[] = sprintf(
            '<link rel="apple-touch-startup-image" href="/pwa/splash/splash-%dx%d.png"'
            . ' media="(device-width: %dpx) and (device-height: %dpx)'
            . ' and (-webkit-device-pixel-ratio: %d) and (orientation: %s)">',
            $w,
            $h,
            $cssW,
            $cssH,
            $dpr,
            $orientation
        );
        $count++;
    }
}
echo "  ✓ pwa/splash/ ({$count} écrans de démarrage)\n";

// Le fragment Twig est généré ici pour que la liste des <link> ne puisse pas
// diverger de DEVICES : une media query iOS doit correspondre EXACTEMENT, un
// fichier sans lien (ou un lien sans fichier) donne un écran blanc au lancement.
$partial = __DIR__ . '/../templates/components/_pwa_splash.html.twig';
file_put_contents($partial, implode("\n", [
    '{# ---------------------------------------------------------------------',
    '   GÉNÉRÉ par tools/build-pwa-icons.php — ne pas éditer à la main.',
    '',
    '   Écrans de démarrage iOS. Safari ne redimensionne pas : il faut une image',
    '   par couple (résolution, orientation), sinon le lancement depuis l\'écran',
    '   d\'accueil affiche une page blanche. Android n\'en a pas besoin, il compose',
    '   son splash depuis le manifest (name + background_color + icône 512).',
    '   --------------------------------------------------------------------- #}',
    ...$links,
    '',
]));
echo "  ✓ templates/components/_pwa_splash.html.twig (" . count($links) . " liens)\n";

echo "\nVisuels PWA régénérés dans public/pwa/.\n";
