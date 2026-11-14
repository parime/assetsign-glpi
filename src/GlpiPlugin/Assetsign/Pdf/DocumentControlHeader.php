<?php

namespace GlpiPlugin\Assetsign\Pdf;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Template;

/**
 * Issue #274 : en-tête de maîtrise documentaire (cartouche ISO) répété sur chaque page des PDF.
 *
 * Réglage par entité (Config::doc_header_enabled, désactivé par défaut : aucun document existant
 * ne change sans décision de l'administrateur). Le code du formulaire, la révision et les dates
 * viennent du modèle de fiche (Template::doc_code, doc_revision, doc_revision_date, date de
 * création), jamais d'une saisie à chaque fiche.
 *
 * Cette classe ne fournit que des données : les libellés sont écrits dans le gabarit Twig
 * (pdf/_doc_header.html.twig), donc traduits dans la langue du PDF (issue #208).
 *
 * « Page X sur Y » : Dompdf numérote les pages (counter(page)) mais ne connaît pas leur total
 * (counter(pages) vaut 0). Le gabarit écrit donc PAGE_COUNT_TOKEN, remplacé par le nombre réel
 * de pages après un premier rendu (cf. PdfRenderingHelpers::renderPdf()). La hauteur du cartouche
 * est fixe : le nombre de pages ne change pas la mise en page du contenu entre les deux rendus.
 */
final class DocumentControlHeader
{
   public const PAGE_COUNT_TOKEN = 'ASSETSIGNPAGECOUNTTOKEN';

   /** Longueur maximale du type de document, du code et de la révision. */
   public const MAX_LENGTH = 100;

    /**
     * Données du cartouche pour une entité, ou null si le réglage est désactivé.
     *
     * @param array<string, mixed>|null $templateFields champs du modèle de fiche, s'il y en a un
     * @return array{doc_type: string, organization: string, code: string, revision: string, revision_date: string, created_date: string, preview: bool}|null
     */
   public static function build(array $configFields, string $entityName, ?array $templateFields = null, bool $preview = false): ?array {
      if (empty($configFields['doc_header_enabled'])) {
          return null;
      }

       $templateFields ??= [];

       $organization = trim((string) ($configFields['company_name'] ?? ''));

       return [
           'doc_type'      => self::clip((string) ($configFields['doc_header_doc_type'] ?? '')),
           'organization'  => $organization !== '' ? $organization : $entityName,
           'code'          => self::clip((string) ($templateFields['doc_code'] ?? '')),
           'revision'      => self::clip((string) ($templateFields['doc_revision'] ?? '')),
           'revision_date' => self::formatDate($templateFields['doc_revision_date'] ?? null),
           'created_date'  => self::formatDate($templateFields['date_creation'] ?? null),
           'preview'       => $preview,
       ];
   }

    /**
     * Cartouche d'une entité (configuration effective, héritée comme le reste des réglages).
     *
     * @return array{doc_type: string, organization: string, code: string, revision: string, revision_date: string, created_date: string, preview: bool}|null
     */
   public static function forEntity(int $entities_id, ?Template $template = null): ?array {
       return self::build(
           Config::getForEntity($entities_id)->fields,
           self::entityName($entities_id),
           $template?->fields
       );
   }

   public static function entityName(int $entities_id): string {
       $entity = new \Entity();
       return $entity->getFromDB($entities_id) ? (string) $entity->fields['name'] : '';
   }

    /** Remplace le total de pages dans le HTML déjà rendu. */
   public static function fillPageCount(string $html, int $pageCount): string {
       return str_replace(self::PAGE_COUNT_TOKEN, (string) max(1, $pageCount), $html);
   }

   public static function hasPageCountToken(string $html): bool {
       return str_contains($html, self::PAGE_COUNT_TOKEN);
   }

   private static function formatDate(mixed $value): string {
      if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $value) || str_starts_with($value, '0000')) {
          return '';
      }
       $timestamp = strtotime($value);
       return $timestamp === false ? '' : date('d/m/Y', $timestamp);
   }

   private static function clip(string $value): string {
       return mb_substr(trim($value), 0, self::MAX_LENGTH);
   }
}
