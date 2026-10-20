<?php

namespace App\Tests\Service;

use App\Service\GoalPhotoStorage;
use App\Tests\BuildsJpeg;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * `GoalPhotoStorage` : le premier stockage de fichiers de l'app.
 *
 * Ce que les tests tiennent :
 * - aucune métadonnée EXIF ne survit au rangement d'un JPEG (position GPS sur
 *   un récit qui peut devenir public), sans casser le reste du fichier ;
 * - seul un format d'image reconnu est rangé ;
 * - rien d'autre qu'un nom produit par `store()` ne se résout en chemin.
 */
final class GoalPhotoStorageTest extends TestCase
{
    use BuildsJpeg;

    private string $directory;
    private GoalPhotoStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/kadens-photos-'.bin2hex(random_bytes(4));
        $this->storage = new GoalPhotoStorage($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testStripRemovesExifAndKeepsTheRestOfTheFile(): void
    {
        $stripped = GoalPhotoStorage::stripJpegMetadata(self::jpeg());

        self::assertStringNotContainsString(self::EXIF_MARKER, $stripped);
        // Le même fichier, sans son APP1 : JFIF, dimensions et données intacts.
        self::assertSame(self::jpeg(withExif: false), $stripped);
    }

    public function testStripLeavesAFileWithoutMetadataUntouched(): void
    {
        $clean = self::jpeg(withExif: false);

        self::assertSame($clean, GoalPhotoStorage::stripJpegMetadata($clean));
    }

    /** Mieux vaut une photo intacte qu'une photo cassée par un nettoyage hasardeux. */
    public function testStripReturnsUnreadableInputUnchanged(): void
    {
        self::assertSame('pas un jpeg', GoalPhotoStorage::stripJpegMetadata('pas un jpeg'));

        $truncated = substr(self::jpeg(), 0, 30);
        self::assertSame($truncated, GoalPhotoStorage::stripJpegMetadata($truncated));
    }

    public function testStoreKeepsDimensionsAndDropsExif(): void
    {
        $stored = $this->storage->store($this->upload(self::jpeg(640, 480)));

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $stored['file']);
        self::assertSame(640, $stored['width']);
        self::assertSame(480, $stored['height']);
        self::assertStringNotContainsString(self::EXIF_MARKER, (string) file_get_contents($this->storage->path($stored['file'])));
    }

    public function testStoreRefusesWhatIsNotAnImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->store($this->upload('<?php echo "bonjour";'));
    }

    public function testDeleteIsIdempotent(): void
    {
        $stored = $this->storage->store($this->upload(self::jpeg()));

        $this->storage->delete($stored['file']);
        $this->storage->delete($stored['file']);

        self::assertFalse($this->storage->exists($stored['file']));
    }

    public function testPathRefusesANameItDidNotProduce(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->path('../../.env.local');
    }

    private function upload(string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kd-upload');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'photo.jpg', null, null, true);
    }
}
