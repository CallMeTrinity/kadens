<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le stockage de l'image d'un message programmé, le plus souvent un GIF animé.
 * Mêmes partis pris que `GoalPhotoStorage` (hors de `public/`, nom aléatoire
 * revérifié, aucune extension d'image côté serveur), à une différence près :
 * **rien n'est ré-encodé**. Un GIF passé par le canvas du navigateur perdrait
 * son animation, l'upload part donc tel quel, et la limite de poids le dit.
 *
 * Un JPEG garde le nettoyage EXIF de `GoalPhotoStorage::stripJpegMetadata()` :
 * le fichier finit sous les yeux d'autres comptes.
 */
final class MessageMediaStorage
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** Garde-fou contre une image aux dimensions absurdes. */
    public const MAX_PIXELS = 25_000_000;

    private const EXTENSIONS = [
        \IMAGETYPE_GIF => 'gif',
        \IMAGETYPE_JPEG => 'jpg',
        \IMAGETYPE_PNG => 'png',
        \IMAGETYPE_WEBP => 'webp',
    ];

    private const FILE_PATTERN = '/^[a-f0-9]{32}\.(gif|jpg|png|webp)$/';

    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * Range l'image téléversée et renvoie son nom de fichier.
     *
     * @throws \InvalidArgumentException message lisible par l'utilisateur
     */
    public function store(UploadedFile $upload): string
    {
        if (!$upload->isValid()) {
            throw new \InvalidArgumentException(\in_array($upload->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? 'Image trop lourde pour le serveur.' : 'L\'envoi de l\'image a échoué.');
        }

        if ($upload->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Image trop lourde (8 Mo au plus).');
        }

        $info = @getimagesize($upload->getPathname());
        if (false === $info || !isset(self::EXTENSIONS[$info[2]])) {
            throw new \InvalidArgumentException('Format non reconnu : GIF, JPEG, PNG ou WebP.');
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('Dimensions d\'image invalides.');
        }

        $file = bin2hex(random_bytes(16)).'.'.self::EXTENSIONS[$info[2]];
        $this->ensureDirectory();
        $upload->move($this->directory, $file);

        if (\IMAGETYPE_JPEG === $info[2]) {
            $path = $this->path($file);
            $bytes = (string) file_get_contents($path);
            $stripped = GoalPhotoStorage::stripJpegMetadata($bytes);
            if ($stripped !== $bytes) {
                file_put_contents($path, $stripped);
            }
        }

        return $file;
    }

    /** Chemin absolu ; le nom est revérifié, rien d'autre qu'un nom de `store()` ne sort d'ici. */
    public function path(string $file): string
    {
        if (1 !== preg_match(self::FILE_PATTERN, $file)) {
            throw new \InvalidArgumentException('Nom d\'image invalide.');
        }

        return $this->directory.'/'.$file;
    }

    public function exists(string $file): bool
    {
        return 1 === preg_match(self::FILE_PATTERN, $file) && is_file($this->path($file));
    }

    /**
     * Null quand le fichier manque. Le nom est aléatoire et change à chaque
     * remplacement : longue durée de cache, et le téléphone peut le garder.
     */
    public function response(string $file): ?BinaryFileResponse
    {
        if (!$this->exists($file)) {
            return null;
        }

        $response = new BinaryFileResponse($this->path($file));
        $response->setMaxAge(30 * 24 * 3600);
        $response->setPrivate();
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

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Dossier d\'images impossible à créer : %s', $this->directory));
        }
    }
}
