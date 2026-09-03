<?php

namespace GlpiPlugin\Assetsign\Api;

use GlpiPlugin\Assetsign\Assetsign;

/**
 * Logique partagee par front/assetsign.form.php : une methode par action POST,
 * chacune ne faisant qu'extraire les parametres et deleguer a Assetsign — meme
 * motivation que Api\SignController pour front/sign.php (rendre ce dispatch
 * testable en PHPUnit, sans passer par le vrai front/*.php qui appelle
 * Html::back()/exit()).
 */
final class AssetsignFormController
{
    /**
     * $files : $_FILES du formulaire de creation manuelle (justificatif de don,
     * certificat de destruction) — parametre distinct de $post plutot qu'un
     * simple merge, meme convention que la separation native $_POST/$_FILES de
     * PHP, transmis tel quel jusqu'a Assetsign::createManual()/attachDocument().
     */
   public function createManual(array $post, array $files = []): string {
       Assetsign::createManual(
           (string) ($post['itemtype'] ?? ''),
           (int) ($post['items_id'] ?? 0),
           (int) ($post['type'] ?? -1),
           (int) ($post['users_id'] ?? 0),
           [
               'price'            => $post['price'] ?? 0,
               // '?:' et non '??' : cf. commentaire equivalent dans l'ancien
               // front/assetsign.form.php (champ <input type="date"> present mais
               // vide, pas absent).
               'sale_date'        => $post['sale_date'] ?: date('Y-m-d'),
               'beneficiary_type' => (int) ($post['beneficiary_type'] ?? 0),
               'external_name'    => (string) ($post['external_name'] ?? ''),
               'external_contact' => (string) ($post['external_contact'] ?? ''),
               'organization_name' => (string) ($post['organization_name'] ?? ''),
               'justificatif'     => $files['justificatif'] ?? null,
               'provider_name'    => (string) ($post['provider_name'] ?? ''),
               'certificat'       => $files['certificat'] ?? null,
           ]
       );

       return __('Fiche créée.', 'assetsign');
   }

   public function sendReminder(Assetsign $assetsign): string {
       $assetsign->sendReminderNow();

       return __('Relance envoyée.', 'assetsign');
   }

   public function cancelRequest(Assetsign $assetsign): string {
       $assetsign->cancelRequest();

       return __('Demande annulée.', 'assetsign');
   }

    /** Delegation par un technicien/admin depuis la fiche (issue #115). */
   public function delegateSignature(Assetsign $assetsign, array $post): string {
       $assetsign->delegateSignatureTo(
           (int) ($post['delegate_users_id'] ?? 0),
           (string) ($post['delegation_reason'] ?? ''),
           (int) \Session::getLoginUserID()
       );

       return __('Signature déléguée.', 'assetsign');
   }

   public function revokeDelegation(Assetsign $assetsign): string {
       $assetsign->revokeDelegation();

       return __('Délégation révoquée : le bénéficiaire d\'origine peut de nouveau signer.', 'assetsign');
   }

   public function addAccessory(Assetsign $assetsign, array $post): void {
       $assetsign->addAccessory(
           (int) ($post['plugin_assetsign_accessories_id'] ?? 0),
           (int) ($post['quantity'] ?? 1),
           (string) ($post['comment'] ?? '')
       );
   }

   public function removeAccessory(Assetsign $assetsign, array $post): void {
       $assetsign->removeAccessory((int) ($post['plugin_assetsign_accessories_id'] ?? 0));
   }

   public function updateObservations(Assetsign $assetsign, array $post): void {
       $assetsign->updateObservations((string) ($post['observations'] ?? ''));
   }

   public function updateVenteDetails(Assetsign $assetsign, array $post): void {
       // '?:' et non '??' : meme raison que createManual() ci-dessus.
       $saleDate = (string) ($post['sale_date'] ?: date('Y-m-d'));
       $assetsign->updateVenteDetails((float) ($post['price'] ?? 0), $saleDate);
   }

   public function updateDonDetails(Assetsign $assetsign, array $post): void {
       $assetsign->updateDonDetails((string) ($post['organization_name'] ?? ''));
   }

   public function updateDestructionDetails(Assetsign $assetsign, array $post): void {
       $assetsign->updateDestructionDetails((string) ($post['provider_name'] ?? ''));
   }

    /** Assigne/change/retire le Kit d'accessoires de cette remise (cf. ROADMAP.md V3, issue #83). */
   public function updateKit(Assetsign $assetsign, array $post): void {
       $assetsign->updateKit((int) ($post['plugin_assetsign_kits_id'] ?? 0));
   }

    /**
     * $post['checklist'] : tableau brut $_POST (id du ChecklistItem => valeur
     * soumise) - cf. ROADMAP.md V1, issue #74. Absent si aucune case/menu
     * n'a ete soumis (formulaire sans aucun point applicable) : traite comme
     * un tableau vide, jamais une erreur.
     */
   public function updateChecklist(Assetsign $assetsign, array $post): void {
       $assetsign->setChecklistValues((array) ($post['checklist'] ?? []));
   }
}
