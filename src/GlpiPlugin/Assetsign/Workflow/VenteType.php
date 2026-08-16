<?php

namespace GlpiPlugin\Assetsign\Workflow;

use GlpiPlugin\Assetsign\Assetsign;

/**
 * Vente de materiel : meme declenchement manuel que le Don (cf. DonType,
 * Assetsign::createManual()). Vendeur (users_id_tech) et acheteur (users_id)
 * reutilisent les champs deja portes par Assetsign ; seuls le prix et la date
 * de vente sont specifiques, stockes dans VenteDetails (table dediee 1-vers-1).
 */
final class VenteType implements WorkflowTypeInterface
{
   private const DEFAULT_CONTENT = '<p>Je reconnais avoir acheté, aux conditions indiquées ci-dessus, le matériel décrit '
        . 'ci-dessus, cédé en l\'état par l\'organisation qui me le vend, sans garantie au-delà de ce qui serait légalement obligatoire.</p>';

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
           'page_title'       => 'Fiche de vente de matériel',
           'material_heading' => 'Matériel vendu',
       ];
   }

   public function getDefaultTemplateContent(): array {
       // Pas de charte informatique par defaut : comme pour le Don, le
       // materiel sort definitivement du parc.
       return ['content' => self::DEFAULT_CONTENT, 'charter_content' => ''];
   }
}
