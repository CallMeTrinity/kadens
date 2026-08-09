<?php

namespace App\Service;

use App\Http\BackTarget;
use App\Repository\PlanTemplateRepository;
use App\Security\Voter\PlanTemplateVoter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Résout l'ancre de contexte (`?from=`, cf. `BackTarget`) en un lien de retour
 * affichable : une URL, un libellé structurel, et éventuellement un nom saisi.
 *
 * Trois règles portent le service :
 *
 * - **Le repli n'est jamais une erreur.** Jeton absent, illisible, entité
 *   supprimée ou hors droits : on rend le retour d'origine de la page. Aucun
 *   chemin ne mène à un lien mort.
 * - **Les droits se vérifient avant le libellé, pas après.** Le titre d'un plan
 *   est une donnée privée : afficher « ← Plan Prépa marathon » sur un id deviné
 *   dans la query fuiterait le contenu d'autrui sans jamais ouvrir la page. Le
 *   voter tranche d'abord, et son refus se lit comme une absence de jeton.
 * - **Le libellé se rend en deux morceaux** (`kicker` + `name`) parce que la
 *   règle 4 du design system l'exige : `.kd-wk__back` est en capitales, et un
 *   titre saisi par l'utilisateur ne se met pas en capitales. Le kicker est
 *   structurel (« Plan », « Trame », « Mes séances »), le `name` est du contenu
 *   et reste en casse normale.
 */
final class BackLink
{
    /**
     * Mois en français, en dur : `twig/intl-extra` n'est pas installé et l'app
     * est monolingue — même choix que les templates du calendrier.
     */
    private const MONTHS = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
        5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
        9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PlanTemplateRepository $planTemplateRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * Reconduit le jeton courant sur un lien sortant, à fusionner dans les
     * paramètres de route : `path('app_workout_edit', {id: x}|merge(back_carry()))`.
     *
     * C'est ce qui fait tenir la chaîne au-delà du premier saut : sans lui, le
     * bouton « Éditer » d'une séance ouverte depuis un plan repartirait sans
     * contexte et le retour redeviendrait « Mes séances ».
     *
     * @return array{}|array{from: string}
     */
    public function carry(): array
    {
        $token = $this->currentToken();

        return null === $token ? [] : ['from' => $token];
    }

    /**
     * Pose un contexte sur un lien sortant : `back_to('plan', template.id)`.
     *
     * @return array{from: string}
     */
    public function to(string $kind, string|int $value): array
    {
        return ['from' => BackTarget::token($kind, $value)];
    }

    /**
     * Le lien de retour à afficher. `$fallbackKicker` / `$fallbackRoute` sont le
     * retour d'origine de la page, celui d'avant cette fonctionnalité.
     *
     * @param array<string, scalar> $fallbackParams
     *
     * @return array{url: string, kicker: string, name: string|null}
     */
    public function resolve(string $fallbackRoute, string $fallbackKicker, array $fallbackParams = []): array
    {
        $resolved = $this->fromToken(BackTarget::parse($this->currentToken()));

        return $resolved ?? [
            'url' => $this->urlGenerator->generate($fallbackRoute, $fallbackParams),
            'kicker' => $fallbackKicker,
            'name' => null,
        ];
    }

    private function currentToken(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        $token = $request->query->getString('from');

        return '' === $token ? null : $token;
    }

    /**
     * @return array{url: string, kicker: string, name: string|null}|null
     */
    private function fromToken(?BackTarget $target): ?array
    {
        if (null === $target) {
            return null;
        }

        return match ($target->kind) {
            BackTarget::PLAN => $this->plan($target->value, PlanTemplateVoter::VIEW, 'app_plan_template_show', 'Plan'),
            BackTarget::PLAN_EDIT => $this->plan($target->value, PlanTemplateVoter::EDIT, 'app_plan_template_edit', 'Trame'),
            BackTarget::CAL_WEEK => $this->calendarWeek($target->value),
            BackTarget::CAL_MONTH => $this->calendarMonth($target->value),
            default => null,
        };
    }

    /**
     * @return array{url: string, kicker: string, name: string|null}|null
     */
    private function plan(string $id, string $attribute, string $route, string $kicker): ?array
    {
        $template = $this->planTemplateRepository->find((int) $id);

        if (null === $template || !$this->authorizationChecker->isGranted($attribute, $template)) {
            return null;
        }

        return [
            'url' => $this->urlGenerator->generate($route, ['id' => $template->getId()]),
            'kicker' => $kicker,
            'name' => $template->getTitle(),
        ];
    }

    /**
     * @return array{url: string, kicker: string, name: string|null}|null
     */
    private function calendarWeek(string $date): ?array
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (false === $day) {
            return null;
        }

        // Le lundi de la semaine, pas le jour cliqué : le libellé doit nommer la
        // vue vers laquelle on repart, qui est bornée par sa semaine.
        $monday = $day->modify('monday this week');

        return [
            'url' => $this->urlGenerator->generate('app_calendar_week', ['date' => $date]),
            'kicker' => \sprintf('Semaine du %d %s', (int) $monday->format('j'), self::MONTHS[(int) $monday->format('n')]),
            'name' => null,
        ];
    }

    /**
     * @return array{url: string, kicker: string, name: string|null}|null
     */
    private function calendarMonth(string $month): ?array
    {
        $first = \DateTimeImmutable::createFromFormat('!Y-m', $month);

        if (false === $first) {
            return null;
        }

        return [
            'url' => $this->urlGenerator->generate('app_calendar_month', [
                'year' => (int) $first->format('Y'),
                'month' => (int) $first->format('n'),
            ]),
            'kicker' => self::MONTHS[(int) $first->format('n')].' '.$first->format('Y'),
            'name' => null,
        ];
    }
}
