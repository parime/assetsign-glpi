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
 * Destruction de materiel (issue #78, "fin de vie structuree") : meme
 * declenchement manuel que Don/Vente (cf. DonType/VenteType,
 * Assetsign::createManual()). Prestataire stocke dans DestructionDetails
 * (table dediee 1-vers-1) ; le certificat de destruction lui-meme (fichier)
 * reutilise le Document/Document_Item natif GLPI attache a la fiche.
 */
final class DestructionType implements WorkflowTypeInterface
{
   public function getId(): int {
       return Assetsign::TYPE_DESTRUCTION;
   }

   public function getLabel(): string {
       return __('Destruction', 'assetsign');
   }

   public function getCanonicalLabel(): string {
       return 'Destruction';
   }

   public function getPdfHeadings(): array {
       return [
           'page_title'       => __('Fiche de destruction de matériel', 'assetsign'),
           'material_heading' => __('Matériel détruit', 'assetsign'),
       ];
   }

   public function getDefaultTemplateContent(): array {
       // Pas de charte informatique par defaut : comme pour le Don/la Vente, le
       // materiel sort definitivement du parc.
       return [
           'content'         => TwigBlocks::render('workflow/default_texts.html.twig', 'destruction_content'),
           'charter_content' => '',
       ];
   }
}
