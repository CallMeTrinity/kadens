<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Le seul endroit qui parle à l'API d'Intervals.icu.
 *
 * Authentification HTTP Basic avec l'utilisateur **littéral** `API_KEY` et la clé
 * comme mot de passe, athlète `0` = titulaire de la clé. La clé est passée à
 * chaque appel, en clair, et ne vit que le temps de l'appel : ce service ne la
 * garde pas et ne sait pas la déchiffrer (c'est `IntervalsImporter` qui ouvre
 * la `SecretBox`).
 *
 * Deux échecs, et ils ne se traitent pas pareil :
 * - `IntervalsAuthException` (401/403) : la clé ne vaut plus rien, l'utilisateur
 *   doit en coller une autre. Réessayer ne changera rien.
 * - `IntervalsUnavailableException` : réseau, délai, 5xx, 429. Réessayer plus tard.
 *
 * Endpoints vérifiés dans la spécification OpenAPI publiée par Intervals
 * (`GET /api/v1/docs`) le 13/09/2026.
 */
final class IntervalsClient
{
    private const string ME = '0';

    public function __construct(private readonly HttpClientInterface $intervalsClient)
    {
    }

    /**
     * Le compte associé à la clé. Sert à la valider à la connexion.
     *
     * @return array{id: string, name: ?string}
     */
    public function athlete(string $apiKey): array
    {
        $data = $this->get($apiKey, 'athlete/'.self::ME);

        return [
            'id' => (string) ($data['id'] ?? ''),
            'name' => isset($data['name']) && '' !== $data['name'] ? (string) $data['name'] : null,
        ];
    }

    /**
     * Les activités d'une plage de dates **locales**, bornes comprises. L'API les
     * rend du plus récent au plus ancien, objets complets : aucun appel de détail
     * n'est nécessaire ensuite.
     *
     * @return list<array<string, mixed>>
     */
    public function activities(string $apiKey, \DateTimeImmutable $oldest, \DateTimeImmutable $newest): array
    {
        $data = $this->get($apiKey, 'athlete/'.self::ME.'/activities', [
            'oldest' => $oldest->format('Y-m-d'),
            'newest' => $newest->format('Y-m-d').'T23:59:59',
        ]);

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * Les séries temporelles demandées, indexées par type. Un type absent de la
     * réponse (pas de ceinture cardio, natation sans GPS) est simplement absent.
     *
     * @param list<string> $types
     *
     * @return array<string, list<int|float|null>>
     */
    public function streams(string $apiKey, string $activityId, array $types): array
    {
        $data = $this->get($apiKey, 'activity/'.rawurlencode($activityId).'/streams.json', [
            'types' => implode(',', $types),
        ]);

        $streams = [];
        foreach ($data as $stream) {
            if (\is_array($stream) && isset($stream['type'], $stream['data']) && \is_array($stream['data'])) {
                $streams[(string) $stream['type']] = array_values($stream['data']);
            }
        }

        return $streams;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<mixed>
     */
    private function get(string $apiKey, string $path, array $query = []): array
    {
        try {
            $response = $this->intervalsClient->request('GET', $path, [
                'auth_basic' => ['API_KEY', $apiKey],
                'query' => $query,
            ]);

            $status = $response->getStatusCode();

            if (401 === $status || 403 === $status) {
                throw new IntervalsAuthException('Clé Intervals.icu refusée.');
            }

            if ($status >= 400) {
                throw new IntervalsUnavailableException(sprintf('Intervals.icu a répondu %d.', $status));
            }

            return $response->toArray(false);
        } catch (IntervalsAuthException|IntervalsUnavailableException $e) {
            throw $e;
        } catch (TransportException|ExceptionInterface|\JsonException $e) {
            throw new IntervalsUnavailableException('Intervals.icu est injoignable.', previous: $e);
        }
    }
}
