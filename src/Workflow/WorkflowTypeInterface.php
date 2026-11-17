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

/**
 * Contrat que doit remplir tout type de fiche gere par le plugin (Assetsign,
 * Restitution, et les types a venir : Don, Vente...). Chaque type vit dans
 * sa propre classe, enregistree une fois aupres de WorkflowTypeRegistry -
 * ajouter un type ne modifie donc jamais Assetsign.php, Template.php ni
 * HandoverPdfBuilder.php, seulement ce fichier + une ligne d'enregistrement
 * dans hook.php (meme convention que Provider\ProviderFactory).
 */
interface WorkflowTypeInterface
{
   public function getId(): int;

    /** Libelle traduit (session courante) : listes, formulaires, tableau de bord. */
   public function getLabel(): string;

    /**
     * Libelle fixe, JAMAIS traduit via __() : utilise pour le nom des
     * Documents GLPI, identifiant interne de classement qui ne doit pas
     * dependre de la langue de qui a declenche sa creation (deja constate en
     * conditions reelles, cf. Assetsign::getCanonicalTypeLabel()).
     */
   public function getCanonicalLabel(): string;

    /**
     * Titres du PDF, traduits : appele pendant Pdf\PdfLanguage::run(), donc dans la langue du
     * beneficiaire (issue #208), pas dans celle de la session.
     *
     * @return array{page_title: string, material_heading: string}
     */
   public function getPdfHeadings(): array;

    /** @return array{content: string, charter_content: string} */
   public function getDefaultTemplateContent(): array;
}
