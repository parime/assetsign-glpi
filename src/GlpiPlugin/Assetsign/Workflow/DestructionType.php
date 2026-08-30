<?php

namespace GlpiPlugin\Assetsign\Workflow;

use GlpiPlugin\Assetsign\Assetsign;

/**
 * Destruction de materiel (issue #78, "fin de vie structuree") : meme
 * declenchement manuel que Don/Vente (cf. DonType/VenteType,
 * Assetsign::createManual()). Prestataire stocke dans DestructionDetails
 * (table dediee 1-vers-1) ; le certificat de destruction lui-meme (fichier)
 * reutilise le Document/Document_Item natif GLPI attache a la fiche.
 */
final class DestructionType implements WorkflowTypeInterface
{
   private const DEFAULT_CONTENT = '<p>Je certifie que le matériel décrit ci-dessus a été détruit, conformément aux '
        . 'obligations réglementaires en vigueur (traçabilité, protection des données, environnement), par le '
        . 'prestataire mentionné ci-dessus.</p>';

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
           'page_title'       => 'Fiche de destruction de matériel',
           'material_heading' => 'Matériel détruit',
       ];
   }

   public function getDefaultTemplateContent(): array {
       // Pas de charte informatique par defaut : comme pour le Don/la Vente, le
       // materiel sort definitivement du parc.
       return ['content' => self::DEFAULT_CONTENT, 'charter_content' => ''];
   }
}
