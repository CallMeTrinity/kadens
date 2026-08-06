<?php

namespace App\Tests\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\ExerciseLanguage;
use App\Service\ExerciseNaming;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * `ExerciseNaming` : le seul endroit qui décide sous quel libellé un exercice
 * s'affiche.
 *
 * Ce que les tests tiennent, ce sont ses trois replis — celui de la langue
 * manquante, celui de l'exercice supprimé, celui du lecteur anonyme. Chacun
 * existe pour qu'un écran n'affiche jamais un trou là où il y avait un nom.
 */
final class ExerciseNamingTest extends TestCase
{
    public function testItReadsTheFrenchNameByDefault(): void
    {
        $naming = $this->naming(ExerciseLanguage::FR);

        self::assertSame(
            'Tirage vertical poitrine',
            $naming->label($this->exercise('Tirage vertical poitrine', 'Lat pulldown')),
        );
    }

    public function testItReadsTheEnglishNameWhenAsked(): void
    {
        $naming = $this->naming(ExerciseLanguage::EN);

        self::assertSame(
            'Lat pulldown',
            $naming->label($this->exercise('Tirage vertical poitrine', 'Lat pulldown')),
        );
    }

    /**
     * `nameEn` est facultatif par construction : les mouvements dont le nom
     * français EST l'anglais n'en portent pas. Un trou serait pire qu'un nom
     * dans l'autre langue.
     */
    public function testEnglishFallsBackToFrenchWhenThereIsNoEnglishName(): void
    {
        $naming = $this->naming(ExerciseLanguage::EN);

        self::assertSame('Dips', $naming->label($this->exercise('Dips', null)));
        self::assertSame('Fartlek', $naming->label($this->exercise('Fartlek', '')));
    }

    /**
     * `LoggedExercise.exercise` est en SET NULL : l'historique d'un exercice
     * supprimé n'a plus que sa copie figée du nom.
     */
    public function testADeletedExerciseFallsBackToTheFrozenName(): void
    {
        $naming = $this->naming(ExerciseLanguage::EN);

        self::assertSame('Développé couché', $naming->label(null, 'Développé couché'));
    }

    public function testNothingAtAllStillRendersSomething(): void
    {
        $naming = $this->naming(ExerciseLanguage::EN);

        self::assertSame('—', $naming->label(null));
        self::assertSame('—', $naming->label(null, ''));
    }

    /**
     * Page publique de partage, flux ICS, export : un lecteur sans compte lit la
     * langue d'origine de la bibliothèque.
     */
    public function testAnAnonymousReaderGetsFrench(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $naming = new ExerciseNaming($security);

        self::assertSame(ExerciseLanguage::FR, $naming->language());
        self::assertSame(
            'Tirage vertical poitrine',
            $naming->label($this->exercise('Tirage vertical poitrine', 'Lat pulldown')),
        );
    }

    public function testTheAlternateNameIsTheOneNotShown(): void
    {
        $exercise = $this->exercise('Curl marteau', 'Hammer curl');

        self::assertSame('Hammer curl', $this->naming(ExerciseLanguage::FR)->alternate($exercise));
        self::assertSame('Curl marteau', $this->naming(ExerciseLanguage::EN)->alternate($exercise));
    }

    public function testThereIsNoAlternateWhenBothNamesWouldBeTheSame(): void
    {
        self::assertNull($this->naming(ExerciseLanguage::FR)->alternate($this->exercise('Dips', null)));
        self::assertNull($this->naming(ExerciseLanguage::FR)->alternate($this->exercise('Burpees', 'Burpees')));
        self::assertNull($this->naming(ExerciseLanguage::FR)->alternate(null));
    }

    /**
     * Le texte cherché porte les DEUX noms quelle que soit la langue : c'est ce
     * qui fait que « lat pulldown » et « tirage vertical » trouvent la même
     * carte.
     */
    public function testTheSearchTextCarriesBothNamesWhateverTheLanguage(): void
    {
        $exercise = $this->exercise('Tirage vertical poitrine', 'Lat pulldown');

        foreach ([ExerciseLanguage::FR, ExerciseLanguage::EN] as $language) {
            self::assertSame(
                'Tirage vertical poitrine Lat pulldown',
                $this->naming($language)->searchText($exercise),
            );
        }

        // Pas de répétition inutile quand il n'y a qu'un nom.
        self::assertSame('Dips', $this->naming(ExerciseLanguage::FR)->searchText($this->exercise('Dips', null)));
    }

    /**
     * `labelIn()` ignore l'utilisateur courant : c'est la porte des écritures qui
     * ne s'adressent à personne en particulier (export, flux, payload).
     */
    public function testTheForcedLanguageIgnoresTheCurrentUser(): void
    {
        $naming = $this->naming(ExerciseLanguage::FR);
        $exercise = $this->exercise('Curl marteau', 'Hammer curl');

        self::assertSame('Hammer curl', $naming->labelIn($exercise, ExerciseLanguage::EN));
    }

    // --------------------------------------------------------- Fixtures

    private function naming(ExerciseLanguage $language): ExerciseNaming
    {
        $user = (new User())->setEmail('athlete@example.com');
        $user->setExerciseLanguage($language);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new ExerciseNaming($security);
    }

    private function exercise(string $name, ?string $nameEn): Exercise
    {
        return (new Exercise())
            ->setName($name)
            ->setNameEn($nameEn)
            ->setActivity(ActivityType::GYM);
    }
}
