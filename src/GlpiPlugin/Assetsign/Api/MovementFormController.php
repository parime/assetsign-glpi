<?php

namespace GlpiPlugin\Assetsign\Api;

use CommonDBTM;
use GlpiPlugin\Assetsign\Movement;

/**
 * Logique partagée par l'action "create" de front/movement.form.php : validation
 * de l'itemtype/du matériel cible (même patron que Api\MaintenanceFormController,
 * même motivation) puis délégation à Movement::create().
 *
 * InvalidArgumentException distincte des exceptions "métier" déjà levées par
 * Movement::create() (ex: signature invalide) : le front doit réagir différemment
 * (404 franc, pas un message d'erreur avec retour au formulaire) selon lequel des
 * deux cas se produit.
 */
final class MovementFormController
{
    /**
     * @throws \InvalidArgumentException si l'itemtype est invalide, le matériel
     *         introuvable, ou l'utilisateur courant n'y a pas droit en lecture.
     * @throws \RuntimeException si la création échoue côté métier (ex: signature
     *         manquante/invalide).
     */
   public function create(array $post): int {
       $itemtype = (string) ($post['itemtype'] ?? '');
       $items_id = (int) ($post['items_id'] ?? 0);

      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
          throw new \InvalidArgumentException('Invalid itemtype.');
      }

       $target = new $itemtype();
      // !can($items_id, READ) : même contrôle cross-entité que
      // Assetsign::createManual()/Maintenance::createWithChecklist().
      if (!$target->getFromDB($items_id) || !$target->can($items_id, READ)) {
          throw new \InvalidArgumentException('Target item not found or not readable.');
      }

       return Movement::create(
           $itemtype,
           $items_id,
           (int) $target->fields['entities_id'],
           [
               'locations_id_from' => (int) ($post['locations_id_from'] ?? 0),
               'locations_id_to'   => (int) ($post['locations_id_to'] ?? 0),
               // 'T' -> ' ' : les champs <input type="datetime-local"> soumettent un
               // séparateur ISO 8601 ('2026-08-25T14:30'), pas toujours accepté tel
               // quel par les colonnes DATETIME/TIMESTAMP selon la version de MySQL/
               // MariaDB - normalisé ici plutôt que de dépendre du client.
               'date_from'         => str_replace('T', ' ', (string) ($post['date_from'] ?? '')) ?: null,
               'date_to'           => str_replace('T', ' ', (string) ($post['date_to'] ?? '')) ?: null,
               'status'            => (int) ($post['status'] ?? Movement::STATUS_PLANNED),
               'comment'           => (string) ($post['comment'] ?? ''),
           ],
           (string) ($post['technician_signature'] ?? '') ?: null,
           [
               'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
               'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
           ]
       );
   }
}
