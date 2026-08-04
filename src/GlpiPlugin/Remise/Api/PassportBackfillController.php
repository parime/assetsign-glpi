<?php

namespace GlpiPlugin\Remise\Api;

use GlpiPlugin\Remise\PassportEvent;

/**
 * Logique partagee par front/passport_backfill.php (bouton "Forcer la
 * recherche" des onglets Passeport materiel/utilisateur) — meme motivation
 * que Api\RemiseFormController : rendre ce dispatch testable en PHPUnit,
 * sans passer par le vrai front/*.php qui appelle Html::back()/exit().
 */
final class PassportBackfillController
{
   public function runForItem(array $post): string {
       $itemtype = (string) ($post['itemtype'] ?? '');
       $items_id = (int) ($post['items_id'] ?? 0);

      if (!is_subclass_of($itemtype, \CommonDBTM::class)) {
          throw new \InvalidArgumentException(__('Type de matériel invalide.', 'remise'));
      }

       $item = new $itemtype();
      if (!$item->getFromDB($items_id) || !$item->can($items_id, READ)) {
          throw new \InvalidArgumentException(__('Matériel introuvable.', 'remise'));
      }

       return $this->formatResult(PassportEvent::backfillFromLogs($itemtype, $items_id, true));
   }

   public function runForUser(array $post): string {
       $users_id = (int) ($post['users_id'] ?? 0);

       $user = new \User();
      if (!$user->getFromDB($users_id) || !$user->can($users_id, READ)) {
          throw new \InvalidArgumentException(__('Utilisateur introuvable.', 'remise'));
      }

       return $this->formatResult(PassportEvent::backfillUserHistoryFromLogs($users_id, true));
   }

   private function formatResult(int $count): string {
      if ($count === 0) {
          return __('Aucun nouvel événement trouvé dans l\'historique.', 'remise');
      }

       return sprintf(
           _n('%d événement retrouvé dans l\'historique.', '%d événements retrouvés dans l\'historique.', $count, 'remise'),
           $count
       );
   }
}
