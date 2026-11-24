<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign\Api;

use CommonDBTM;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\CustodyCase;

/**
 * Issue #326 : actions de front/custodycase.form.php, testables sans passer par le front (même
 * motivation que WipeAttestationFormController).
 *
 * InvalidArgumentException : matériel invalide, introuvable ou hors de portée (404 franc) ;
 * RuntimeException : saisie refusée, message pour l'utilisateur.
 */
final class CustodyCaseFormController
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
   public function open(array $post, array $files = []): CustodyCase {
       [$itemtype, $itemsId] = array_pad(explode(':', (string) ($post['item'] ?? ''), 2), 2, '0');
      if (!in_array($itemtype, Config::getAllManageableItemtypes(), true) || !is_subclass_of($itemtype, CommonDBTM::class)) {
          throw new \InvalidArgumentException('Invalid itemtype.');
      }
       $item = new $itemtype();
       // can(READ) : droit sur le type de matériel ET entité accessible.
      if (!$item->getFromDB((int) $itemsId) || !$item->can((int) $itemsId, READ)) {
          throw new \InvalidArgumentException('Target item not found or not readable.');
      }

       return CustodyCase::open(
           $item,
           self::fields($post, ['event_date', 'reason', 'condition_note', 'seal_number', 'tickets_id', 'incident_id', 'users_id_from', 'name_from', 'users_id_to', 'name_to', 'locations_id', 'storage', 'comment']),
           (string) ($post['signature_from'] ?? ''),
           (string) ($post['signature_to'] ?? ''),
           self::photos($files['photos'] ?? null),
           self::meta()
       );
   }

    /**
     * @param array<string, mixed> $post
     */
   public function transfer(CustodyCase $case, array $post): void {
       $case->transfer(
           self::fields($post, ['event_date', 'users_id_to', 'name_to', 'locations_id', 'storage', 'comment']),
           (string) ($post['signature_from'] ?? ''),
           (string) ($post['signature_to'] ?? ''),
           self::meta()
       );
   }

    /**
     * @param array<string, mixed> $post
     */
   public function close(CustodyCase $case, array $post): void {
       $case->close(
           self::fields($post, ['closure', 'event_date', 'users_id_to', 'name_to', 'locations_id', 'storage', 'comment']),
           (string) ($post['signature_from'] ?? ''),
           (string) ($post['signature_to'] ?? ''),
           self::meta()
       );
   }

    /**
     * @param array<string, mixed> $post
     * @param list<string> $keys
     * @return array<string, string>
     */
   private static function fields(array $post, array $keys): array {
       $fields = [];
      foreach ($keys as $key) {
         if (array_key_exists($key, $post) && is_scalar($post[$key])) {
            $fields[$key] = (string) $post[$key];
         }
      }
       return $fields;
   }

    /**
     * Photos envoyées (champ multiple photos[]) : seuls les fichiers réellement téléversés.
     *
     * @return list<array{path: string}>
     * @throws \RuntimeException
     */
   private static function photos(mixed $files): array {
      if (!is_array($files) || !is_array($files['tmp_name'] ?? null)) {
          return [];
      }
       $photos = [];
      foreach ($files['tmp_name'] as $index => $tmpName) {
          $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
         if ($error === UPLOAD_ERR_NO_FILE) {
             continue;
         }
         if ($error !== UPLOAD_ERR_OK || !is_string($tmpName) || !is_uploaded_file($tmpName)) {
             throw new \RuntimeException(__('Échec de l\'envoi de la photo.', 'assetsign'));
         }
          $photos[] = ['path' => $tmpName];
      }
       return $photos;
   }

    /** @return array{ip: string, user_agent: string} */
   private static function meta(): array {
       return [
           'ip'         => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
           'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
       ];
   }
}
