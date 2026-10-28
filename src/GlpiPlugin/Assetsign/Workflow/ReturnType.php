<?php

namespace GlpiPlugin\Assetsign\Workflow;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\TwigBlocks;

final class ReturnType implements WorkflowTypeInterface
{
   public function getId(): int {
       return Assetsign::TYPE_RETURN;
   }

   public function getLabel(): string {
       return __('Restitution', 'assetsign');
   }

   public function getCanonicalLabel(): string {
       return 'Restitution';
   }

   public function getPdfHeadings(): array {
       return [
           'page_title'       => __('Fiche de restitution de matériel', 'assetsign'),
           'material_heading' => __('Matériel restitué', 'assetsign'),
       ];
   }

   public function getDefaultTemplateContent(): array {
       // Pas de charte par defaut pour une restitution : la charte informatique
       // concerne l'USAGE du materiel, deja couverte par le gabarit de remise
       // signe au moment de l'affectation initiale.
       return [
           'content'         => TwigBlocks::render('workflow/default_texts.html.twig', 'return_content'),
           'charter_content' => '',
       ];
   }
}
