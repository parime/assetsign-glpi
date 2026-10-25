<?php

namespace GlpiPlugin\Assetsign\Api;

use CommonDBTM;
use GlpiPlugin\Assetsign\Maintenance;

/**
 * Logique partagee par l'action "create" de front/maintenance.form.php :
 * validation de l'itemtype/du materiel cible (auparavant inline dans le
 * front, cf. TROUBLESHOOTING.md) puis delegation a
 * Maintenance::createWithChecklist() — meme motivation que
 * Api\SignController/AssetsignFormController.
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
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files $_FILES (issue #223 : photos[] multiples)
     */
   public function createWithChecklist(array $post, array $files = []): int {
       $itemtype = (string) ($post['itemtype'] ?? '');
       $items_id = (int) ($post['items_id'] ?? 0);

      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
          throw new \InvalidArgumentException('Invalid itemtype.');
      }

       $target = new $itemtype();
       // !can($items_id, READ) : meme controle cross-entite que
       // Assetsign::createManual(), cf. TROUBLESHOOTING.md.
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
           ],
           (int) ($post['kind'] ?? Maintenance::KIND_MAINTENANCE),
           (int) ($post['tickets_id'] ?? 0),
           self::normalizePhotos($files['photos'] ?? [])
       );
   }

    /**
     * $_FILES['photos'] (champ multiple) en liste de fichiers ; les champs laisses vides sont
     * ignores.
     *
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
   public static function normalizePhotos(mixed $raw): array {
      if (!is_array($raw) || !is_array($raw['name'] ?? null)) {
          return [];
      }
       $photos = [];
      foreach ($raw['name'] as $i => $name) {
         if ((int) ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
             continue;
         }
          $photos[] = [
              'name'     => (string) $name,
              'type'     => (string) ($raw['type'][$i] ?? ''),
              'tmp_name' => (string) ($raw['tmp_name'][$i] ?? ''),
              'error'    => (int) ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE),
              'size'     => (int) ($raw['size'][$i] ?? 0),
          ];
      }
       return $photos;
   }
}
