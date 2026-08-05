<?php

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Le dessin d'un QR, et rien d'autre : une chaîne entre, un SVG sort (KL-43).
 *
 * Il y a désormais deux QR dans l'app — le code d'appairage (KL-47) et l'URL du
 * dépôt TNTStore sur `/app` — et ils sont lus dans les mêmes conditions : un
 * écran d'ordinateur, filmé de biais, par un téléphone qu'on tient d'une main.
 * Les réglages de dessin n'ont donc aucune raison de différer, et deux appels au
 * `Builder` finiraient par diverger sur la taille ou le niveau de correction. Ce
 * service est le seul endroit qui les fixe ; ce que le QR **contient**, lui,
 * reste l'affaire de l'appelant (`PairingQr::payload()` pour l'appairage).
 *
 * Writer SVG : aucune extension PHP à demander au mutualisé (`ext-gd` ne sert
 * qu'aux writers image), et le QR est dessiné côté serveur — pas une ligne de
 * JavaScript dans l'importmap.
 */
final class QrSvg
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
     * Le SVG à insérer **dans** la page, d'où l'absence de déclaration XML :
     * `<?xml … ?>` en plein HTML n'est pas du HTML.
     *
     * Couleurs laissées au noir sur blanc du writer : un QR est une cible
     * optique avant d'être un élément de l'identité, et son contraste ne se
     * négocie pas (§5 règle 1 vise les templates et composants, pas une image
     * dont le décodage dépend du contraste).
     */
    public function render(string $data): string
    {
        return (new Builder(
            writer: new SvgWriter(),
            writerOptions: [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true],
            data: $data,
            // Medium et pas High : un écran est une surface propre, et un niveau
            // de correction plus haut ne ferait que densifier les modules.
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::SIZE,
            margin: self::MARGIN,
        ))->build()->getString();
    }
}
