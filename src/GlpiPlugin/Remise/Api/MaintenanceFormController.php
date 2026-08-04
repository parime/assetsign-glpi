<?php

namespace GlpiPlugin\Remise\Api;

use CommonDBTM;
use GlpiPlugin\Remise\Maintenance;

/**
 * Logique partagee par l'action "create" de front/maintenance.form.php :
 * validation de l'itemtype/du materiel cible (auparavant inline dans le
 * front, cf. TROUBLESHOOTING.md) puis delegation a
 * Maintenance::createWithChecklist() — meme motivation que
 * Api\SignController/RemiseFormController.
 *
 * InvalidArgumentException distinct des exceptions "metier" deja levees par
 * createWithChecklist() (ex: signature technicien manquante) : le front doit
 * reagir differemment (404 franc, pas un message d'erreur avec retour au
 * formulaire) selon lequel des deux cas se produit.
 */
final class MaintenanceFormController
{
    /**
     * @throws \InvalidArgumentException si l'itemtype est invalide, le materiel
     *         introuvable, ou l'utilisateur courant n'y a pas droit en lecture.
     * @throws \RuntimeException si la creation echoue cote metier (ex:
     *         signature technicien requise mais absente/invalide).
     */
   public function createWithChecklist(array $post): int {
       $itemtype = (string) ($post['itemtype'] ?? '');
       $items_id = (int) ($post['items_id'] ?? 0);

      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
          throw new \InvalidArgumentException('Invalid itemtype.');
      }

       $target = new $itemtype();
       // !can($items_id, READ) : meme controle cross-entite que
       // Remise::createManual(), cf. TROUBLESHOOTING.md.
      if (!$target->getFromDB($items_id) || !$target->can($items_id, READ)) {
          throw new \InvalidArgumentException('Target item not found or not readable.');
      }

       $checklist = is_array($post['checklist'] ?? null) ? $post['checklist'] : [];

       // Marqueurs d'etat des lieux : decodage defensif, cf. commentaire
       // equivalent dans l'ancien front/maintenance.form.php.
       $damageMarkers = json_decode((string) ($post['damage_markers'] ?? '[]'), true);
      if (!is_array($damageMarkers)) {
          $damageMarkers = [];
      }

       return Maintenance::createWithChecklist(
           $itemtype,
           $items_id,
           (int) $target->fields['entities_id'],
           $checklist,
           (string) ($post['comment'] ?? ''),
           $damageMarkers,
           (string) ($post['technician_signature'] ?? '') ?: null,
           [
               'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
               'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
           ]
       );
   }
}
