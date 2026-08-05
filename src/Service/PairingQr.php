<?php

namespace App\Service;

use App\Entity\PairingCode;

/**
 * La charge utile du QR d'appairage, et son dessin (KL-47).
 *
 * Deux gestes séparés parce qu'ils ont deux publics : `payload()` est le
 * **contrat avec l'app mobile** (KL-48 lit `{url, code, exp}` et se configure
 * avec), `svg()` n'est qu'une façon de le donner à lire à une caméra. Le
 * contrat se teste donc sans passer par un décodeur d'image.
 *
 * Le QR **ne porte jamais de jeton** (§0.6 règle 1) : une photo de l'écran ne
 * vaut qu'un code de deux minutes, à usage unique. Il porte en revanche l'URL du
 * serveur, ce qui dispense de la saisir sur le téléphone — et règle au passage
 * l'IP LAN en développement.
 *
 * Le dessin lui-même vit dans `QrSvg` depuis KL-43, qui en a ajouté un second
 * (l'URL du dépôt TNTStore sur `/app`) : ce qui change d'un QR à l'autre, c'est
 * ce qu'il contient, jamais la façon de le tracer.
 */
final class PairingQr
{
    public function __construct(private readonly QrSvg $qr)
    {
    }

    /**
     * La charge utile lue par l'app mobile. Le code y voyage **en clair** :
     * c'est le seul endroit avec l'écran où il existe sous cette forme, la base
     * n'en a que l'empreinte.
     *
     * @return array{url: string, code: string, exp: string}
     */
    public function payload(PairingCode $pairingCode, string $plainCode, string $serverUrl): array
    {
        return [
            // Base du serveur, pas d'un endpoint : le mobile la garde comme
            // « URL de serveur » et la valide par `GET /api/ping` (KL-10).
            'url' => $serverUrl,
            'code' => $plainCode,
            'exp' => $pairingCode->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Le SVG à insérer dans la page. Le JSON part **compact et sans échappement
     * des barres obliques** : un QR encode des octets, chaque `\/` inutile en
     * densifie les modules.
     *
     * @param array{url: string, code: string, exp: string} $payload
     */
    public function svg(array $payload): string
    {
        return $this->qr->render(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }
}
