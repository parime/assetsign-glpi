<?php

namespace GlpiPlugin\Assetsign\Api;

use CommonDBTM;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\WipeAttestation;

/**
 * Issue #322 : actions de front/wipeattestation.form.php, testables sans passer par le front (même
 * motivation que MaintenanceFormController).
 *
 * InvalidArgumentException : matériel invalide, introuvable ou hors de portée (404 franc) ;
 * RuntimeException : saisie refusée, message pour l'utilisateur.
 */
final class WipeAttestationFormController
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
   public function create(array $post, array $files = []): WipeAttestation {
       $itemtype = (string) ($post['itemtype'] ?? '');
       $itemsId = (int) ($post['items_id'] ?? 0);
      if (!in_array($itemtype, Config::getAllManageableItemtypes(), true) || !is_subclass_of($itemtype, CommonDBTM::class)) {
          throw new \InvalidArgumentException('Invalid itemtype.');
      }
       $item = new $itemtype();
       // can(READ) : droit sur le type de matériel ET entité accessible (cf. Assetsign::createManual()).
      if (!$item->getFromDB($itemsId) || !$item->can($itemsId, READ)) {
          throw new \InvalidArgumentException('Target item not found or not readable.');
      }

       return WipeAttestation::create(
           $item,
           [
               'date_wipe'           => (string) ($post['date_wipe'] ?? ''),
               'method'              => (string) ($post['method'] ?? ''),
               'nist_level'          => (string) ($post['nist_level'] ?? ''),
               'verification_result' => (int) ($post['verification_result'] ?? WipeAttestation::RESULT_NOT_VERIFIED),
               'tool'                => (string) ($post['tool'] ?? ''),
               'comment'             => (string) ($post['comment'] ?? ''),
               'media'               => is_array($post['media'] ?? null) ? array_values(array_map('strval', $post['media'])) : [],
               'media_other'         => (string) ($post['media_other'] ?? ''),
           ],
           (string) ($post['technician_signature'] ?? ''),
           is_array($files['report'] ?? null) ? $files['report'] : null,
           [
               'ip'         => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
               'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
           ]
       );
   }
}
