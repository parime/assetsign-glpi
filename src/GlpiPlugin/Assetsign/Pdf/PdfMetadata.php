<?php

namespace GlpiPlugin\Assetsign\Pdf;

use GlpiPlugin\Assetsign\Config;

/**
 * Issue #280 : métadonnées des PDF (titre, auteur, sujet, créateur), lues par les GED pour le
 * classement, et titre GLPI du signataire imprimé sous chaque signature.
 *
 * Auteur = émetteur du document (nom de l'entreprise de la configuration, à défaut nom de
 * l'entité) ; sujet = signataire ; créateur = « GLPI x - AssetSign y ».
 */
final class PdfMetadata
{
   /** Longueur maximale d'une valeur (les noms de document GLPI font 255 caractères). */
   private const MAX_LENGTH = 255;

    /**
     * @return array{Title: string, Author: string, Subject: string, Creator: string}
     */
   public static function build(string $title, int $entities_id, string $subject = ''): array {
       return [
           'Title'   => self::clean($title),
           'Author'  => self::clean(self::issuer($entities_id)),
           'Subject' => self::clean($subject),
           'Creator' => self::creator(),
       ];
   }

   public static function creator(): string {
       return sprintf(
           'GLPI %s - AssetSign %s',
           defined('GLPI_VERSION') ? GLPI_VERSION : '?',
           defined('PLUGIN_ASSETSIGN_VERSION') ? PLUGIN_ASSETSIGN_VERSION : '?'
       );
   }

    /** Émetteur : nom de l'entreprise de la configuration, à défaut nom de l'entité. */
   public static function issuer(int $entities_id): string {
       $company = trim((string) (Config::getForEntity($entities_id)->fields['company_name'] ?? ''));
       return $company !== '' ? $company : DocumentControlHeader::entityName($entities_id);
   }

    /**
     * Titre GLPI (champ « Titre » de l'utilisateur, ex. « Technicien support ») d'un signataire,
     * ou chaîne vide : le gabarit affiche alors « Technicien » ou « Utilisateur », traduit dans la
     * langue du PDF.
     *
     * @param array<string, mixed>|null $userFields champs glpi_users du signataire
     */
   public static function signerTitle(?array $userFields): string {
       $titleId = (int) ($userFields['usertitles_id'] ?? 0);
      if ($titleId <= 0) {
          return '';
      }
       $title = new \UserTitle();
       return $title->getFromDB($titleId) ? trim((string) $title->fields['name']) : '';
   }

    /** Nom affichable d'un signataire (mêmes règles que le reste des PDF). */
   public static function personName(?array $userFields): string {
       return trim(\formatUserName(
           0,
           (string) ($userFields['name'] ?? ''),
           (string) ($userFields['realname'] ?? ''),
           (string) ($userFields['firstname'] ?? '')
       ));
   }

   private static function clean(string $value): string {
       $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
       return mb_substr($value, 0, self::MAX_LENGTH);
   }
}
