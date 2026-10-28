<?php

namespace GlpiPlugin\Assetsign\Workflow;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\TwigBlocks;

final class HandoverType implements WorkflowTypeInterface
{
   public function getId(): int {
       return Assetsign::TYPE_HANDOVER;
   }

   public function getLabel(): string {
       return __('Attribution', 'assetsign');
   }

   public function getCanonicalLabel(): string {
       return 'Attribution';
   }

   public function getPdfHeadings(): array {
       return [
           'page_title'       => __('Fiche d\'attribution de matériel', 'assetsign'),
           'material_heading' => __('Matériel attribué', 'assetsign'),
       ];
   }

   public function getDefaultTemplateContent(): array {
       return [
           'content'         => TwigBlocks::render('workflow/default_texts.html.twig', 'handover_content'),
           'charter_content' => TwigBlocks::render('workflow/default_texts.html.twig', 'handover_charter_content'),
       ];
   }
}
