<?php

namespace GlpiPlugin\Assetsign\Workflow;

use GlpiPlugin\Assetsign\Assetsign;

final class HandoverType implements WorkflowTypeInterface
{
   private const DEFAULT_CONTENT = '<p>Je reconnais avoir reçu le matériel décrit ci-dessus, en bon état de fonctionnement, '
        . 'et m\'engage à en assurer la garde, l\'usage raisonnable et la restitution en cas de départ ou de demande '
        . 'de l\'équipe informatique.</p>';

   private const DEFAULT_CHARTER_CONTENT = '<p>L\'utilisation du matériel informatique doit se conformer à la charte informatique en vigueur '
        . 'dans l\'entreprise. Toute anomalie ou dysfonctionnement doit être signalé sans délai au service informatique.</p>';

   public function getId(): int {
       return Assetsign::TYPE_HANDOVER;
   }

   public function getLabel(): string {
       return __('Remise', 'assetsign');
   }

   public function getCanonicalLabel(): string {
       return 'Remise';
   }

   public function getPdfHeadings(): array {
       return [
           'page_title'       => 'Fiche de remise de matériel',
           'material_heading' => 'Matériel remis',
       ];
   }

   public function getDefaultTemplateContent(): array {
       return ['content' => self::DEFAULT_CONTENT, 'charter_content' => self::DEFAULT_CHARTER_CONTENT];
   }
}
