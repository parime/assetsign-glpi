<?php

namespace GlpiPlugin\Remise\Api;

use GlpiPlugin\Remise\Remise;

/**
 * Logique partagee par front/remise.form.php : une methode par action POST,
 * chacune ne faisant qu'extraire les parametres et deleguer a Remise — meme
 * motivation que Api\SignController pour front/sign.php (rendre ce dispatch
 * testable en PHPUnit, sans passer par le vrai front/*.php qui appelle
 * Html::back()/exit()).
 */
final class RemiseFormController
{
   public function createManual(array $post): string {
       Remise::createManual(
           (string) ($post['itemtype'] ?? ''),
           (int) ($post['items_id'] ?? 0),
           (int) ($post['type'] ?? -1),
           (int) ($post['users_id'] ?? 0),
           [
               'price'            => $post['price'] ?? 0,
               // '?:' et non '??' : cf. commentaire equivalent dans l'ancien
               // front/remise.form.php (champ <input type="date"> present mais
               // vide, pas absent).
               'sale_date'        => $post['sale_date'] ?: date('Y-m-d'),
               'beneficiary_type' => (int) ($post['beneficiary_type'] ?? 0),
               'external_name'    => (string) ($post['external_name'] ?? ''),
               'external_contact' => (string) ($post['external_contact'] ?? ''),
           ]
       );

       return __('Fiche créée.', 'remise');
   }

   public function sendReminder(Remise $remise): string {
       $remise->sendReminderNow();

       return __('Relance envoyée.', 'remise');
   }

   public function cancelRequest(Remise $remise): string {
       $remise->cancelRequest();

       return __('Demande annulée.', 'remise');
   }

   public function addAccessory(Remise $remise, array $post): void {
       $remise->addAccessory(
           (int) ($post['plugin_remise_accessories_id'] ?? 0),
           (int) ($post['quantity'] ?? 1),
           (string) ($post['comment'] ?? '')
       );
   }

   public function removeAccessory(Remise $remise, array $post): void {
       $remise->removeAccessory((int) ($post['plugin_remise_accessories_id'] ?? 0));
   }

   public function updateObservations(Remise $remise, array $post): void {
       $remise->updateObservations((string) ($post['observations'] ?? ''));
   }

   public function updateVenteDetails(Remise $remise, array $post): void {
       // '?:' et non '??' : meme raison que createManual() ci-dessus.
       $saleDate = (string) ($post['sale_date'] ?: date('Y-m-d'));
       $remise->updateVenteDetails((float) ($post['price'] ?? 0), $saleDate);
   }
}
