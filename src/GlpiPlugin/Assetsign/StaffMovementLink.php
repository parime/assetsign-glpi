<?php

namespace GlpiPlugin\Assetsign;

use CommonITILObject;
use Config as CoreConfig;
use DropdownTranslation;
use ITILCategory;
use Plugin;
use TaskTemplate;
use Ticket;
use Ticket_User;

/**
 * Arrivées et départs (issue grcmanager #362) : réutilise ce que le plugin Configuration GLPI Auto
 * a installé pour ces mouvements de personnel, sans dépendre de son code.
 *
 * Configuration enregistre l'identifiant de ses gabarits de tâche et de ses catégories d'arrivée et
 * de départ dans la configuration de GLPI (contexte `plugin:configurationglpiauto`, clés
 * `{arrival|departure}_{tasktemplates|itilcategories}_id`). On les lit seulement si ce plugin est
 * actif ; sans lui, rien ne change. Aucun droit n'est élargi : les tickets restent créés dans
 * l'entité et avec les acteurs prévus.
 */
final class StaffMovementLink {

   public const CGA_CONTEXT = 'plugin:configurationglpiauto';

   /** Pour les tests : force la présence de Configuration GLPI Auto (null = détection réelle). */
   public static ?bool $forceCgaActive = null;

   /**
    * Catégorie et gabarit de tâche fournis par Configuration GLPI Auto pour ce mouvement, 0 si
    * absent (plugin inactif, objet supprimé).
    *
    * @param string $event 'arrival' ou 'departure'
    * @return array{itilcategories_id: int, tasktemplates_id: int}
    */
   public static function forEvent(string $event): array {
      $none = ['itilcategories_id' => 0, 'tasktemplates_id' => 0];
      $active = self::$forceCgaActive ?? Plugin::isPluginActive('configurationglpiauto');
      if (!$active || !in_array($event, ['arrival', 'departure'], true)) {
         return $none;
      }
      $keys = [$event . '_itilcategories_id', $event . '_tasktemplates_id'];
      $values = CoreConfig::getConfigurationValues(self::CGA_CONTEXT, $keys);
      $category = (int) ($values[$keys[0]] ?? 0);
      $template = (int) ($values[$keys[1]] ?? 0);

      return [
         'itilcategories_id' => $category > 0 && (new ITILCategory())->getFromDB($category) ? $category : 0,
         'tasktemplates_id'  => $template > 0 && (new TaskTemplate())->getFromDB($template) ? $template : 0,
      ];
   }

   /**
    * Ticket ouvert pour la même personne et le même mouvement (même catégorie), quel que soit son
    * auteur : Configuration GLPI Auto, un formulaire ou une saisie à la main. 0 si aucun.
    */
   public static function findOpenTicket(int $usersId, int $categoryId): int {
      global $DB;

      if ($usersId <= 0 || $categoryId <= 0) {
         return 0;
      }
      $tickets = Ticket::getTable();
      $actors = Ticket_User::getTable();
      $row = $DB->request([
         'SELECT'     => [$tickets . '.id'],
         'FROM'       => $tickets,
         'INNER JOIN' => [$actors => ['ON' => [$actors => 'tickets_id', $tickets => 'id']]],
         'WHERE'      => [
            $tickets . '.itilcategories_id' => $categoryId,
            $tickets . '.is_deleted'        => 0,
            'NOT'                           => [$tickets . '.status' => [CommonITILObject::SOLVED, CommonITILObject::CLOSED]],
            $actors . '.users_id'           => $usersId,
         ],
         'ORDER'      => $tickets . '.id DESC',
         'LIMIT'      => 1,
      ])->current();

      return $row !== null ? (int) $row['id'] : 0;
   }

   /**
    * Texte du gabarit de tâche, dans la langue de la session si Configuration l'a traduit.
    */
   public static function templateContent(int $templateId): string {
      $template = new TaskTemplate();
      if ($templateId <= 0 || !$template->getFromDB($templateId)) {
         return '';
      }
      $translated = DropdownTranslation::getTranslatedValue(
         $templateId,
         TaskTemplate::class,
         'content',
         $_SESSION['glpilanguage'] ?? '',
         (string) $template->fields['content']
      );

      return trim(strip_tags(html_entity_decode((string) $translated)));
   }
}
