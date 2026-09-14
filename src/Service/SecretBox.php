<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement symétrique d'un secret qu'on doit pouvoir **relire** (une clé
 * d'API tierce), à la différence d'`ApiToken` et `PairingCode` qui ne gardent
 * qu'une empreinte. Une empreinte suffit pour vérifier un secret qu'on reçoit ;
 * elle ne permet pas de présenter un secret à quelqu'un d'autre.
 *
 * `sodium_crypto_secretbox` (XSalsa20-Poly1305) : chiffré **et** authentifié, un
 * octet altéré en base fait échouer le déchiffrement au lieu de rendre une clé
 * fausse. Le nonce est aléatoire à chaque chiffrement et voyage avec le message.
 *
 * La clé vient de `APP_SECRET_BOX_KEY` (64 caractères hexadécimaux) et n'est
 * **pas** `APP_SECRET` : changer le secret du noyau invalide les sessions et les
 * jetons CSRF, ce qui est anodin ; il ne doit pas rendre illisibles des données
 * en base. Elle n'est validée qu'à l'usage, pour qu'un environnement sans import
 * d'activités démarre sans elle.
 */
final class SecretBox
{
    public function __construct(
        #[Autowire(env: 'default::APP_SECRET_BOX_KEY')]
        private readonly ?string $hexKey,
    ) {
    }

    public function seal(string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /**
     * @throws \RuntimeException si le message est illisible (clé changée, donnée altérée)
     */
    public function open(string $sealed): string
    {
        $raw = base64_decode($sealed, true);

        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Secret chiffré illisible.');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );

        if (false === $plain) {
            throw new \RuntimeException('Secret chiffré illisible.');
        }

        return $plain;
    }

    private function key(): string
    {
        $key = null === $this->hexKey || '' === $this->hexKey ? false : @hex2bin($this->hexKey);

        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \LogicException('APP_SECRET_BOX_KEY doit contenir 64 caractères hexadécimaux (php -r \'echo bin2hex(random_bytes(32));\').');
        }

        return $key;
    }
}
