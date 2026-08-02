<?php

namespace GlpiPlugin\Remise\Workflow;

use GlpiPlugin\Remise\Remise;

final class ReturnType implements WorkflowTypeInterface
{
   private const DEFAULT_CONTENT = '<p>Je reconnais avoir restitué le matériel décrit ci-dessus au service informatique.</p>';

   public function getId(): int {
       return Remise::TYPE_RETURN;
   }

   public function getLabel(): string {
       return __('Restitution', 'remise');
   }

   public function getCanonicalLabel(): string {
       return 'Restitution';
   }

   public function getPdfHeadings(): array {
       return [
           'page_title'       => 'Fiche de restitution de matériel',
           'material_heading' => 'Matériel restitué',
       ];
   }

   public function getDefaultTemplateContent(): array {
       // Pas de charte par defaut pour une restitution : la charte informatique
       // concerne l'USAGE du materiel, deja couverte par le gabarit de remise
       // signe au moment de l'affectation initiale.
       return ['content' => self::DEFAULT_CONTENT, 'charter_content' => ''];
   }
}
