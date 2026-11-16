<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

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
