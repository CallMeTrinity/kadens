<?php

declare(strict_types=1);

/*
 * Kadens — génération des visuels PWA et des visuels de l'app Android.
 *
 * Usage :  php tools/build-pwa-icons.php
 *
 * Deux sources, et c'est **voulu** :
 *   - assets/icons/kadens.png            — le lockup complet, fond transparent.
 *     C'est la marque du site : PWA, favicons, écrans de démarrage iOS.
 *   - assets/icons/kadens-red-black.png  — la variante rouge et noire, déjà
 *     réduite au K, sur fond blanc **opaque**. C'est la marque de l'app Android,
 *     et elle est différente pour être reconnaissable au milieu d'un écran
 *     d'accueil : deux icônes de la même famille au même endroit se confondent.
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
 *
 * Pourquoi les visuels ANDROID sortent d'ici, dans public/ (KL-37) : parce que
 * les préparer demande le même travail d'image que ci-dessus — détourage,
 * couverture, aplatissement sur l'alpha — et qu'une seconde chaîne dans le dépôt
 * mobile dériverait de celle-ci. Ils atterrissent donc dans public/pwa/android/
 * et `npm run sync:icons` va les y chercher, exactement comme public/fonts/*.ttf,
 * qui ne servent eux non plus jamais au web.
 */

const SRC = __DIR__ . '/../assets/icons/kadens.png';
const APP_SRC = __DIR__ . '/../assets/icons/kadens-red-black.png';
const OUT = __DIR__ . '/../public/pwa';

/** Fond opaque des visuels : --kd-paper-0. Transparent interdit — iOS compose sur du noir. */
const BG = [0xFF, 0xFF, 0xFF];

/** --color-text. La couche monochrome d'une icône adaptative Android. */
const INK = [0x0B, 0x0B, 0x0B];

/** Une icône de notification Android se dessine en blanc sur transparent. */
const WHITE = [0xFF, 0xFF, 0xFF];

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
 * Détoure une marque posée sur un fond blanc **opaque** et rend son alpha.
 *
 * `isolateGlyph()` ne sait pas faire ça : il raisonne sur l'alpha, et une source
 * sans canal alpha n'a qu'une seule composante — le canevas entier. La variante
 * rouge et noire est dans ce cas, et elle n'a de toute façon rien à isoler, les
 * traits de vitesse n'y sont pas.
 *
 * L'antialiasing se récupère **exactement**, pas au seuil. Un pixel de bord vaut
 * `c = a·F + (1-a)·255` ; les deux teintes de la marque ayant chacune au moins un
 * canal à zéro, ce canal-là vaut `255·(1-a)` et donne l'opacité :
 * `a = 1 − min(r,g,b)/255`. La teinte se retrouve ensuite en divisant par `a`.
 * Un seuil aurait dentelé les diagonales du K, qui sont tout ce que cette marque
 * a à montrer.
 *
 * @return array{0: GdImage, 1: array{int, int, int, int}} la marque et sa boîte
 */
function unmatte(GdImage $src): array
{
    $w = imagesx($src);
    $h = imagesy($src);

    $mark = imagecreatetruecolor($w, $h);
    imagealphablending($mark, false);
    imagesavealpha($mark, true);

    $minX = $w;
    $minY = $h;
    $maxX = -1;
    $maxY = -1;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($src, $x, $y);
            $rgb = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
            $matte = min($rgb);

            if ($matte === 0xFF) {
                imagesetpixel($mark, $x, $y, 0x7F000000);

                continue;
            }

            $opacity = 1 - $matte / 255;
            $out = 0;
            foreach ($rgb as $channel) {
                $value = (int) round(($channel - $matte) / $opacity);
                $out = ($out << 8) | max(0, min(255, $value));
            }

            imagesetpixel($mark, $x, $y, ((int) round(127 * (1 - $opacity)) << 24) | $out);

            // La boîte ne retient que l'encre franche : un liseré à 2 % d'opacité
            // sur tout le bord du canevas la ferait courir jusqu'aux angles, et la
            // marque se retrouverait composée avec une marge invisible.
            if ($opacity > 0.5) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
    }

    if ($maxX < 0) {
        fail('source vide : aucune encre détectée dans ' . APP_SRC);
    }

    return [$mark, [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1]];
}

/**
 * Aplatit une marque sur une seule couleur en **conservant son alpha**.
 *
 * Android ne lit que le canal alpha d'une icône de notification ou d'une couche
 * monochrome : il teinte le reste lui-même. Garder les deux teintes de la marque
 * n'y changerait rien à l'écran, mais rendrait le fichier trompeur à l'ouverture.
 *
 * @param array{int, int, int} $rgb
 */
function flatten(GdImage $src, array $rgb): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);

    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $alpha = (imagecolorat($src, $x, $y) >> 24) & 0x7F;
            imagesetpixel($out, $x, $y, ($alpha << 24) | ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2]);
        }
    }

    return $out;
}

/**
 * Compose la marque centrée sur un fond opaque, ou sur du transparent.
 *
 * @param array{int, int, int, int} $box boîte source à utiliser
 * @param float $coverage part de la plus petite dimension du canevas occupée par
 *                       la marque (0.55 pour un maskable : zone sûre à 80 %)
 * @param bool $palette   réduit à 255 couleurs. La marque n'en compte que trois,
 *                        le reste n'est que de l'antialiasing : sur un écran de
 *                        démarrage 2048×2732 c'est ~110 Ko économisés par fichier
 *                        (3,7 Mo → 300 Ko sur l'ensemble). Jamais sur les icônes,
 *                        déjà minuscules et sensibles au moindre écart de teinte.
 * @param array{int, int, int}|null $background `null` = fond transparent. Réservé
 *                        aux visuels **Android**, où la couleur de fond est
 *                        déclarée dans app.json et composée par le système : une
 *                        icône adaptative dont le fond serait cuit dans l'image
 *                        recouvrirait la couche de fond, et la déclaration
 *                        n'aurait plus aucun effet visible.
 */
function compose(GdImage $mark, array $box, int $width, int $height, float $coverage, string $path, bool $palette = false, ?array $background = BG): void
{
    [$sx, $sy, $sw, $sh] = $box;

    $canvas = imagecreatetruecolor($width, $height);

    if ($background === null) {
        // `imagealphablending(false)` sur la destination : sans lui, imagecopyresampled
        // MÉLANGE la marque avec le fond au lieu d'y écrire son alpha, et le résultat
        // ressort opaque noir.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    } else {
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $background[0], $background[1], $background[2]));
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);
    }

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
        // La réduction de palette perd l'alpha : les deux options s'excluent.
        if ($background === null) {
            fail("palette demandée sur un fond transparent : {$path}");
        }
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

// 4. Visuels de l'app Android (KL-37), récupérés par `npm run sync:icons` dans le
//    dépôt kadens-mobile. Ils sont **transparents**, à une exception près : sur
//    Android la couleur de fond se déclare (app.json) et se compose au moment de
//    l'affichage. La cuire dans l'image, c'est ce que faisait la reprise directe
//    des visuels PWA — le fond blanc recouvrait la couche déclarée, qui ne se
//    voyait donc jamais.
//
//    La marque est la variante rouge et noire, et non celle du site. Elle arrive
//    déjà réduite au K, sur fond blanc opaque : rien à isoler, tout à détourer.
if (!is_file(APP_SRC)) {
    fail('source introuvable : ' . APP_SRC);
}

$appSrc = imagecreatefrompng(APP_SRC);
if (!$appSrc) {
    fail('source illisible : ' . APP_SRC);
}

@mkdir(OUT . '/android', 0o755, true);

[$mark, $markBox] = unmatte($appSrc);
$ink = flatten($mark, INK);
$white = flatten($mark, WHITE);

$android = [
    // Icône de repli (Android < 8, magasins, notifications enrichies). Seule
    // opaque : rien ne compose de fond derrière elle. À la taille de la source —
    // l'agrandir ne créerait pas de détail, et le plus gros besoin d'Android est
    // la mipmap xxxhdpi, 192 px.
    'icon.png' => [$mark, 512, 0.80, BG],
    // Couche avant d'une icône adaptative. Le système rogne jusqu'à un cercle
    // inscrit dans 66 % du côté : même couverture que le maskable web, qui absorbe
    // déjà le rognage le plus agressif.
    'adaptive-foreground.png' => [$mark, 432, 0.55, null],
    // Icône thématisée (Android 13+). Seul l'alpha compte, cf. flatten().
    'adaptive-monochrome.png' => [$ink, 432, 0.55, null],
    // Barre de statut : 96 px, blanc sur transparent, c'est la règle Android.
    // Sans elle, le repos de KL-31 s'annonce avec la silhouette de l'icône d'app.
    'notification.png' => [$white, 96, 0.75, null],
    // Écran de démarrage. Couverture 1, donc la marque touche les deux bords en
    // largeur — c'est ce qui permet à `imageWidth` de dire la largeur du dessin,
    // et non celle d'une marge.
    'splash.png' => [$mark, 512, 1.0, null],
];

foreach ($android as $name => [$image, $size, $coverage, $background]) {
    compose($image, $markBox, $size, $size, $coverage, OUT . '/android/' . $name, false, $background);
    echo "  ✓ pwa/android/{$name} ({$size}×{$size})\n";
}

echo "\nVisuels PWA régénérés dans public/pwa/.\n";
