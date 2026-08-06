<?php

namespace App\Tests\Command;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:import-exercises` : la synchronisation de la bibliothèque avec son
 * fichier JSON.
 *
 * Ce que ces tests tiennent, et c'est toute la raison d'être de `Exercise.refKey` :
 * **un renommage ne recrée jamais une ligne**. `LoggedExercise.exercise`,
 * `PrescribedExercise.exercise` et la base locale du téléphone indexent sur
 * `Exercise.id` — un doublon coûterait l'historique, silencieusement.
 *
 * Ils tiennent aussi l'adoption (les lignes nées avant la clé sont reprises, pas
 * dupliquées), le refus d'une clé en double, et le fait que `--owner` ne pose
 * aucune clé.
 */
final class ImportExercisesCommandTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        $this->purgeDatabase($this->em);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];

        parent::tearDown();
    }

    public function testItCreatesTheLibraryAndStampsTheKeys(): void
    {
        $tester = $this->launch([$this->file([
            $this->row('developpe-couche', 'Développé couché', 'Bench press'),
            $this->row('squat', 'Squat', null),
        ])]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('2 créé(s)', $tester->getDisplay());

        $bench = $this->findByKey('developpe-couche');
        self::assertSame('Développé couché', $bench->getName());
        self::assertSame('Bench press', $bench->getNameEn());
        self::assertNull($bench->getOwner(), 'Sans --owner, l\'import alimente la bibliothèque globale.');

        // Un nom français qui EST déjà l'anglais n'a rien à porter : `nameEn`
        // reste null et l'affichage anglais retombe sur `name`.
        self::assertNull($this->findByKey('squat')->getNameEn());
    }

    /**
     * Le cœur du dispositif : renommer dans les DEUX langues met à jour la ligne
     * existante. Si ce test tombe, un renommage a coûté un identifiant, et avec
     * lui tout l'historique qui le référençait.
     */
    public function testRenamingBothNamesKeepsTheSameId(): void
    {
        $this->launch([$this->file([
            $this->row('traction-en-supination', 'Traction en supination', 'Chin-up'),
        ])]);

        $id = $this->findByKey('traction-en-supination')->getId();
        $this->em->clear();

        $tester = $this->launch([$this->file([
            $this->row('traction-en-supination', 'Traction supination', 'Underhand pull-up'),
        ])]);

        self::assertStringContainsString('0 créé(s)', $tester->getDisplay());
        self::assertStringContainsString('1 mis à jour', $tester->getDisplay());

        $this->em->clear();
        $reloaded = $this->findByKey('traction-en-supination');
        self::assertSame($id, $reloaded->getId(), 'Le renommage a recréé l\'exercice au lieu de le mettre à jour.');
        self::assertSame('Traction supination', $reloaded->getName());
        self::assertSame('Underhand pull-up', $reloaded->getNameEn());
    }

    public function testARerunChangesNothing(): void
    {
        $file = $this->file([$this->row('face-pull', 'Face pull à la poulie', 'Cable face pull')]);

        $this->launch([$file]);
        $this->em->clear();

        $tester = $this->launch([$file]);

        self::assertStringContainsString('0 créé(s), 0 adopté(s), 0 mis à jour, 1 inchangé(s)', $tester->getDisplay());
    }

    /**
     * L'adoption : une ligne née sans clé — importée avant que le champ
     * n'existe, ou créée dans l'app par un admin — est reprise sur son nom, pas
     * dupliquée.
     */
    public function testALineWithoutAKeyIsAdoptedOnItsName(): void
    {
        $legacy = $this->createExercise('Développé couché', null);
        $id = $legacy->getId();
        $this->em->clear();

        $tester = $this->launch([$this->file([
            $this->row('developpe-couche', 'Développé couché', 'Bench press'),
        ])]);

        self::assertStringContainsString('0 créé(s), 1 adopté(s)', $tester->getDisplay());

        $this->em->clear();
        $adopted = $this->findByKey('developpe-couche');
        self::assertSame($id, $adopted->getId());
        self::assertSame('Bench press', $adopted->getNameEn());
    }

    /**
     * L'ancienne convention, où l'anglais traînait entre parenthèses dans le nom
     * français. C'est la forme sous laquelle 99 des 301 entrées existaient.
     */
    public function testTheOldParenthesisedFormIsAdopted(): void
    {
        $legacy = $this->createExercise('Curl marteau (Hammer curl)', null);
        $id = $legacy->getId();
        $this->em->clear();

        $this->launch([$this->file([
            $this->row('curl-marteau', 'Curl marteau', 'Hammer curl'),
        ])]);

        $this->em->clear();
        self::assertSame($id, $this->findByKey('curl-marteau')->getId());
    }

    /**
     * Quand le nettoyage a inversé les deux langues, aucune dérivation ne
     * retrouve l'ancien libellé : l'entrée le déclare.
     */
    public function testADeclaredFormerNameIsAdopted(): void
    {
        $legacy = $this->createExercise('Front Squat (Squat avant)', null);
        $id = $legacy->getId();
        $this->em->clear();

        $row = $this->row('squat-avant', 'Squat avant', 'Front squat');
        $row['formerNames'] = ['Front Squat (Squat avant)'];

        $this->launch([$this->file([$row])]);

        $this->em->clear();
        self::assertSame($id, $this->findByKey('squat-avant')->getId());
    }

    /**
     * Une `refKey` n'est pas censée changer. Quand ça arrive quand même,
     * l'adoption par nom ne rattrape rien — elle refuse toute ligne déjà cléfée.
     * Sans `formerKeys`, le changement créerait un doublon et détacherait
     * l'historique, en silence.
     */
    public function testAFormerKeyRecoversTheSameLine(): void
    {
        $this->launch([$this->file([$this->row('squat', 'Squat', null)])]);

        $id = $this->findByKey('squat')->getId();
        $this->em->clear();

        $row = $this->row('barbell-squat', 'Squat à la barre', 'Barbell squat');
        $row['formerKeys'] = ['squat'];

        $tester = $this->launch([$this->file([$row])]);

        self::assertStringContainsString('0 créé(s), 1 adopté(s)', $tester->getDisplay());

        $this->em->clear();
        $reloaded = $this->findByKey('barbell-squat');
        self::assertSame($id, $reloaded->getId(), 'Le changement de clé a recréé l\'exercice.');
        self::assertSame('Squat à la barre', $reloaded->getName());
        self::assertCount(1, $this->em->getRepository(Exercise::class)->findAll());
    }

    /**
     * Sans la déclarer, un changement de clé est indétectable : c'est une
     * création, et c'est le comportement qu'on veut voir dans le résumé plutôt
     * qu'un appariement deviné.
     */
    public function testAnUndeclaredKeyChangeCreatesADuplicate(): void
    {
        $this->launch([$this->file([$this->row('squat', 'Squat', null)])]);
        $this->em->clear();

        $tester = $this->launch([$this->file([
            $this->row('barbell-squat', 'Squat à la barre', 'Barbell squat'),
        ])]);

        self::assertStringContainsString('1 créé(s)', $tester->getDisplay());
        self::assertCount(2, $this->em->getRepository(Exercise::class)->findAll());
    }

    /**
     * Une ligne qui porte déjà une clé appartient à une autre entrée : la lui
     * prendre ferait pointer deux entrées sur le même exercice.
     */
    public function testALineAlreadyKeyedIsNeverAdoptedByAnotherEntry(): void
    {
        $this->launch([$this->file([$this->row('dips', 'Dips', null)])]);
        $this->em->clear();

        $tester = $this->launch([$this->file([
            $this->row('dips', 'Dips', null),
            // Même nom, autre clé : l'adoption doit refuser, donc créer.
            $this->row('dips-machine', 'Dips', 'Machine dips'),
        ])]);

        self::assertStringContainsString('1 créé(s)', $tester->getDisplay());
        self::assertNotSame(
            $this->findByKey('dips')->getId(),
            $this->findByKey('dips-machine')->getId(),
        );
    }

    public function testADuplicateKeyInTheFileIsRefused(): void
    {
        $tester = $this->launch([$this->file([
            $this->row('squat', 'Squat', null),
            $this->row('squat', 'Squat avant', 'Front squat'),
        ])]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('déjà utilisée', $tester->getDisplay());
        self::assertSame([], $this->em->getRepository(Exercise::class)->findAll(), 'Le fichier refusé a quand même écrit.');
    }

    public function testAMissingKeyIsRefusedOnTheGlobalLibrary(): void
    {
        $row = $this->row('', 'Développé couché', 'Bench press');
        unset($row['key']);

        $tester = $this->launch([$this->file([$row])]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('"key" manquante', $tester->getDisplay());
    }

    public function testSimulationWritesNothing(): void
    {
        $tester = $this->launch([
            $this->file([$this->row('squat', 'Squat', null)]),
            '--dry-run' => true,
        ]);

        self::assertStringContainsString('Simulation', $tester->getDisplay());
        self::assertStringContainsString('1 créé(s)', $tester->getDisplay());

        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Exercise::class)->findAll());
    }

    /**
     * `refKey` est unique sur toute la table et ne vaut que pour la globale :
     * deux utilisateurs important le même fichier la violeraient.
     */
    public function testTheOwnerModeStampsNoKey(): void
    {
        $athlete = $this->createUser('athlete@example.com');

        $this->launch([
            $this->file([$this->row('squat', 'Squat', null)]),
            '--owner' => 'athlete@example.com',
        ]);

        $this->em->clear();
        $exercises = $this->em->getRepository(Exercise::class)->findAll();
        self::assertCount(1, $exercises);
        self::assertNull($exercises[0]->getRefKey());
        self::assertSame($athlete->getId(), $exercises[0]->getOwner()?->getId());
    }

    /**
     * L'import global ne doit pas adopter l'exercice perso de quelqu'un — c'était
     * le défaut du `findOneBy` non scopé d'avant, qui faisait *sauter* une entrée
     * globale parce qu'un utilisateur s'était créé le même nom.
     */
    public function testAPersonalExerciseIsNeverAdoptedByTheGlobalImport(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $mine = $this->createExercise('Ma variante maison', $athlete);

        $tester = $this->launch([$this->file([
            $this->row('ma-variante-maison', 'Ma variante maison', null),
        ])]);

        self::assertStringContainsString('1 créé(s)', $tester->getDisplay());

        $this->em->clear();
        self::assertNotNull($this->em->find(Exercise::class, $mine->getId())?->getOwner());
        self::assertNull($this->findByKey('ma-variante-maison')->getOwner());
    }

    public function testAnUnknownActivityIsReportedWithoutStoppingTheRun(): void
    {
        $bad = $this->row('vol-plane', 'Vol plané', null);
        $bad['activity'] = 'levitation';

        $tester = $this->launch([$this->file([
            $this->row('squat', 'Squat', null),
            $bad,
        ])]);

        self::assertSame(2, $tester->getStatusCode(), 'Command::INVALID attendu.');
        self::assertStringContainsString('activité inconnue', $tester->getDisplay());
        self::assertCount(1, $this->em->getRepository(Exercise::class)->findAll());
    }

    // --------------------------------------------------------- Fixtures

    /**
     * @param array<int, mixed> $rows
     */
    private function file(array $rows): string
    {
        $path = sys_get_temp_dir().'/kadens-exercises-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
        $this->files[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $key, string $name, ?string $nameEn): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'nameEn' => $nameEn,
            'description' => null,
            'activity' => 'gym',
            'targetAreas' => ['chest'],
            'mediaUrl' => null,
        ];
    }

    /**
     * @param array<int|string, bool|string> $arguments
     */
    private function launch(array $arguments): CommandTester
    {
        $file = $arguments[0] ?? null;
        unset($arguments[0]);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:import-exercises'));
        $tester->execute(null === $file ? $arguments : ['file' => $file] + $arguments);

        return $tester;
    }

    private function findByKey(string $key): Exercise
    {
        $exercise = $this->em->getRepository(Exercise::class)->findOneBy(['refKey' => $key]);
        self::assertNotNull($exercise, \sprintf('Aucun exercice pour la clé « %s ».', $key));

        return $exercise;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('peu-importe');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createExercise(string $name, ?User $owner): Exercise
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName($name)
            ->setActivity(ActivityType::GYM);

        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }
}
