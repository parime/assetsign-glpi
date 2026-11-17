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

/**
 * Don de materiel : contrairement a Assetsign/Restitution, jamais declenche
 * automatiquement par un hook GLPI (rien ne signale naturellement "ce
 * materiel est donne") : toujours cree via Assetsign::createManual(), depuis
 * un formulaire sur l'onglet Assetsign du materiel (assetsign_tab.html.twig).
 */
final class DonType implements WorkflowTypeInterface
{
   public function getId(): int {
       return Assetsign::TYPE_DON;
   }

   public function getLabel(): string {
       return __('Don', 'assetsign');
   }

   public function getCanonicalLabel(): string {
       return 'Don';
   }

   public function getPdfHeadings(): array {
       return [
           'page_title'       => __('Fiche de don de matériel', 'assetsign'),
           'material_heading' => __('Matériel donné', 'assetsign'),
       ];
   }

   public function getDefaultTemplateContent(): array {
       // Pas de charte informatique par defaut : un don sort definitivement
       // le materiel du parc, l'usage futur n'engage plus l'organisation.
       return [
           'content'         => TwigBlocks::render('workflow/default_texts.html.twig', 'don_content'),
           'charter_content' => '',
       ];
   }
}
