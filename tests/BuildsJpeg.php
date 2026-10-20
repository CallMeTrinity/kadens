<?php

namespace App\Tests;

/**
 * Fabrique un JPEG minimal à la main, segment par segment, sans GD : la suite
 * de tests ne doit pas exiger une extension que l'app elle-même n'exige pas.
 *
 * Le fichier n'est pas décodable en pixels (données d'image factices), mais il
 * a tout ce que lisent `getimagesize()` et `GoalPhotoStorage::stripJpegMetadata()` :
 * SOI, APP0 (JFIF), un APP1 « Exif » optionnel portant un marqueur repérable,
 * SOF0 (dimensions), SOS, puis des octets d'image et EOI.
 */
trait BuildsJpeg
{
    /** Chaîne posée dans l'APP1 : sa présence dans un fichier trahit un EXIF resté. */
    private const EXIF_MARKER = 'GPS-48.8584N-2.2945E';

    private static function jpeg(int $width = 4, int $height = 3, bool $withExif = true): string
    {
        $segment = static fn (string $marker, string $payload): string => "\xFF".$marker.pack('n', \strlen($payload) + 2).$payload;

        $bytes = "\xFF\xD8";
        $bytes .= $segment("\xE0", "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00");
        if ($withExif) {
            $bytes .= $segment("\xE1", "Exif\x00\x00".self::EXIF_MARKER);
        }
        $bytes .= $segment("\xC0", "\x08".pack('n', $height).pack('n', $width)."\x03\x01\x11\x00\x02\x11\x01\x03\x11\x01");
        $bytes .= $segment("\xDA", "\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00");
        $bytes .= "\x12\x34\x56\x78";

        return $bytes."\xFF\xD9";
    }
}
