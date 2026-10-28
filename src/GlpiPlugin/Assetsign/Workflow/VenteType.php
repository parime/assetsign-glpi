<?php

namespace GlpiPlugin\Assetsign\Workflow;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\TwigBlocks;

/**
 * Vente de materiel : meme declenchement manuel que le Don (cf. DonType,
 * Assetsign::createManual()). Vendeur (users_id_tech) et acheteur (users_id)
 * reutilisent les champs deja portes par Assetsign ; seuls le prix et la date
 * de vente sont specifiques, stockes dans VenteDetails (table dediee 1-vers-1).
 */
final class VenteType implements WorkflowTypeInterface
{
   public function getId(): int {
       return Assetsign::TYPE_VENTE;
   }

   public function getLabel(): string {
       return __('Vente', 'assetsign');
   }

   public function getCanonicalLabel(): string {
       return 'Vente';
   }

   public function getPdfHeadings(): array {
       return [
           'page_title'       => __('Fiche de vente de matériel', 'assetsign'),
           'material_heading' => __('Matériel vendu', 'assetsign'),
       ];
   }

   public function getDefaultTemplateContent(): array {
       // Pas de charte informatique par defaut : comme pour le Don, le
       // materiel sort definitivement du parc.
       return [
           'content'         => TwigBlocks::render('workflow/default_texts.html.twig', 'vente_content'),
           'charter_content' => '',
       ];
   }
}
