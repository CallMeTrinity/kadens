<?php

namespace App\Service;

use App\Entity\PairingCode;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

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
 */
final class PairingQr
{
    /**
     * Côté du dessin, en pixels. Le CSS le contraint ensuite (`max-width`) : ce
     * qui compte ici, c'est qu'un module reste assez gros pour être décodé sur un
     * écran d'ordinateur filmé de biais.
     */
    private const int SIZE = 240;

    /**
     * Marge blanche autour du motif — la « quiet zone » de la norme, quatre
     * modules. Ce n'est pas de l'espacement décoratif : sans elle, un décodeur ne
     * trouve pas les motifs de repérage. Elle ne se remplace donc pas par du
     * padding CSS, qui ne serait pas dans l'image que la caméra voit.
     */
    private const int MARGIN = 16;

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
     * Le SVG à insérer **dans** la page, d'où l'absence de déclaration XML :
     * `<?xml … ?>` en plein HTML n'est pas du HTML. Rendu côté serveur, donc
     * aucune bibliothèque JavaScript à faire passer par l'importmap, et un QR
     * qui s'affiche même si le JS ne se charge pas.
     *
     * Couleurs laissées au noir sur blanc du writer : un QR est une cible
     * optique avant d'être un élément de l'identité, et son contraste ne se
     * négocie pas (§5 règle 1 vise les templates et composants, pas une image
     * dont le décodage dépend du contraste).
     *
     * @param array{url: string, code: string, exp: string} $payload
     */
    public function svg(array $payload): string
    {
        return (new Builder(
            writer: new SvgWriter(),
            writerOptions: [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true],
            data: json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            // Medium et pas High : un écran est une surface propre, et un niveau
            // de correction plus haut ne ferait que densifier les modules.
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::SIZE,
            margin: self::MARGIN,
        ))->build()->getString();
    }
}
