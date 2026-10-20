<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GoalBlock;
use App\Enum\GoalBlockType;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le stockage des photos du récit d'un objectif. Premier fichier téléversé de
 * l'app, d'où trois partis pris :
 *
 * - **Hors de `public/`.** Les photos vivent sous `var/uploads/` et ne se
 *   servent que par un contrôleur, qui vérifie le droit de lecture (GoalVoter, ou
 *   la publication pour la page publique). Un objectif privé reste privé jusque
 *   dans ses images. Bonus de déploiement : `var/` est exclu du rsync, un
 *   déploiement ne les efface donc pas.
 * - **Aucune extension d'image requise.** Le mutualisé n'a pas à fournir GD ni
 *   Imagick : la réduction se fait dans le navigateur avant l'envoi
 *   (`photo_upload_controller.js`), le serveur ne fait que vérifier le format
 *   (`getimagesize`, natif) et ranger le fichier.
 * - **Les métadonnées EXIF ne passent pas.** Une photo de téléphone porte la
 *   position GPS de la prise de vue, et le récit peut devenir public. La
 *   réduction côté navigateur les perd d'office (ré-encodage canvas) ; pour un
 *   envoi qui l'aurait contournée, le serveur retire les segments APP1/APP13
 *   d'un JPEG (`stripJpegMetadata`). Prix assumé de ce seul chemin : l'EXIF
 *   portait aussi l'orientation, une photo envoyée sans JS peut donc s'afficher
 *   couchée.
 */
final class GoalPhotoStorage
{
    /** Au-delà, même réduite côté navigateur, la photo n'a rien à faire dans un récit. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Garde-fou contre une image aux dimensions absurdes (bombe de décompression chez le lecteur). */
    public const MAX_PIXELS = 50_000_000;

    private const EXTENSIONS = [
        \IMAGETYPE_JPEG => 'jpg',
        \IMAGETYPE_PNG => 'png',
        \IMAGETYPE_WEBP => 'webp',
    ];

    private const FILE_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * Range une photo téléversée et renvoie son nom de fichier et ses dimensions.
     *
     * @return array{file: string, width: int, height: int}
     *
     * @throws \InvalidArgumentException message lisible par l'utilisateur
     */
    public function store(UploadedFile $upload): array
    {
        if (!$upload->isValid()) {
            // UPLOAD_ERR_INI_SIZE / FORM_SIZE : la limite PHP a coupé l'envoi.
            throw new \InvalidArgumentException(\in_array($upload->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? 'Photo trop lourde pour le serveur.' : 'L\'envoi de la photo a échoué.');
        }

        if ($upload->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Photo trop lourde (10 Mo au plus).');
        }

        $info = @getimagesize($upload->getPathname());
        if (false === $info || !isset(self::EXTENSIONS[$info[2]])) {
            throw new \InvalidArgumentException('Format non reconnu : JPEG, PNG ou WebP.');
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('Dimensions de photo invalides.');
        }

        $file = bin2hex(random_bytes(16)).'.'.self::EXTENSIONS[$info[2]];
        $this->ensureDirectory();
        $upload->move($this->directory, $file);

        if (\IMAGETYPE_JPEG === $info[2]) {
            $path = $this->path($file);
            $bytes = (string) file_get_contents($path);
            $stripped = self::stripJpegMetadata($bytes);
            if ($stripped !== $bytes) {
                file_put_contents($path, $stripped);
            }
        }

        return ['file' => $file, 'width' => $width, 'height' => $height];
    }

    /**
     * Chemin absolu d'une photo rangée. Le nom vient de la base, mais il est
     * revérifié : rien d'autre qu'un nom produit par `store()` ne sort d'ici.
     */
    public function path(string $file): string
    {
        if (1 !== preg_match(self::FILE_PATTERN, $file)) {
            throw new \InvalidArgumentException('Nom de photo invalide.');
        }

        return $this->directory.'/'.$file;
    }

    public function exists(string $file): bool
    {
        return 1 === preg_match(self::FILE_PATTERN, $file) && is_file($this->path($file));
    }

    /**
     * Réponse fichier de la photo d'un bloc, commune à la fiche privée et à la
     * page publique (chacune pose ensuite sa portée de cache). Null quand le
     * bloc n'est pas une photo ou que le fichier manque. Le nom de fichier est
     * aléatoire et ne change jamais pour un bloc donné : longue durée de cache.
     */
    public function response(GoalBlock $block): ?BinaryFileResponse
    {
        $file = $block->getPhotoFile();
        if (GoalBlockType::PHOTO !== $block->getType() || null === $file || !$this->exists($file)) {
            return null;
        }

        $response = new BinaryFileResponse($this->path($file));
        $response->setMaxAge(30 * 24 * 3600);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** Idempotent : un fichier déjà parti n'est pas une erreur. */
    public function delete(string $file): void
    {
        if ($this->exists($file)) {
            @unlink($this->path($file));
        }
    }

    /**
     * Retire d'un JPEG les segments APP1 (EXIF, XMP : position GPS, appareil,
     * date) et APP13 (IPTC). Garde tout le reste à l'octet près, dont APP0
     * (JFIF) et APP2 (profil de couleur). Lecture des marqueurs jusqu'au début
     * des données d'image (SOS), recopiées telles quelles ensuite.
     *
     * Un fichier qui ne se lit pas comme attendu est rendu **inchangé** : mieux
     * vaut une photo intacte qu'une photo cassée par un nettoyage hasardeux.
     */
    public static function stripJpegMetadata(string $bytes): string
    {
        $length = \strlen($bytes);
        if ($length < 4 || "\xFF\xD8" !== substr($bytes, 0, 2)) {
            return $bytes;
        }

        $out = "\xFF\xD8";
        $offset = 2;

        while ($offset + 4 <= $length) {
            if ("\xFF" !== $bytes[$offset]) {
                return $bytes;
            }

            $marker = \ord($bytes[$offset + 1]);

            // Octets de bourrage 0xFF entre deux segments.
            if (0xFF === $marker) {
                ++$offset;
                continue;
            }

            // Début des données compressées : tout le reste se recopie.
            if (0xDA === $marker) {
                return $out.substr($bytes, $offset);
            }

            $segmentLength = (\ord($bytes[$offset + 2]) << 8) | \ord($bytes[$offset + 3]);
            if ($segmentLength < 2 || $offset + 2 + $segmentLength > $length) {
                return $bytes;
            }

            if (0xE1 !== $marker && 0xED !== $marker) {
                $out .= substr($bytes, $offset, 2 + $segmentLength);
            }

            $offset += 2 + $segmentLength;
        }

        return $bytes;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Dossier de photos impossible à créer : %s', $this->directory));
        }
    }
}
