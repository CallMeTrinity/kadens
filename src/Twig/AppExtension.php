<?php

namespace App\Twig;

use App\Enum\PrescriptionType;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonctions Twig transverses. `prescription_type_fields_map()` expose la carte
 * type -> champs pertinents au contrôleur Stimulus d'affichage dynamique, sans
 * dupliquer la logique définie sur l'enum PrescriptionType.
 */
final class AppExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('prescription_type_fields_map', [$this, 'prescriptionTypeFieldsMap']),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function prescriptionTypeFieldsMap(): array
    {
        return PrescriptionType::fieldsMap();
    }
}
