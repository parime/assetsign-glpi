<?php

namespace GlpiPlugin\Remise\Workflow;

use GlpiPlugin\Remise\Remise;

/**
 * Don de materiel : contrairement a Remise/Restitution, jamais declenche
 * automatiquement par un hook GLPI (rien ne signale naturellement "ce
 * materiel est donne") — toujours cree via Remise::createManual(), depuis
 * un formulaire sur l'onglet Remise du materiel (remise_tab.html.twig).
 */
final class DonType implements WorkflowTypeInterface
{
    private const DEFAULT_CONTENT = '<p>Je soussigné(e) reconnais avoir reçu, à titre gratuit, le matériel décrit ci-dessus, '
        . 'sans garantie ni contrepartie de la part de l\'organisation qui me le remet.</p>';

    public function getId(): int
    {
        return Remise::TYPE_DON;
    }

    public function getLabel(): string
    {
        return __('Don', 'remise');
    }

    public function getCanonicalLabel(): string
    {
        return 'Don';
    }

    public function getPdfHeadings(): array
    {
        return [
            'page_title'       => 'Fiche de don de matériel',
            'material_heading' => 'Matériel donné',
        ];
    }

    public function getDefaultTemplateContent(): array
    {
        // Pas de charte informatique par defaut : un don sort definitivement
        // le materiel du parc, l'usage futur n'engage plus l'organisation.
        return ['content' => self::DEFAULT_CONTENT, 'charter_content' => ''];
    }
}
