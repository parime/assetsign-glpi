<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Marqueur de dommage depose sur l'une des 3 vues de reference generiques
 * (public/images/damage-views/, illustrations fournies par l'utilisateur —
 * pas des croquis maison) : "etat des lieux visuel". Coordonnees stockees en
 * POURCENTAGE de l'image (pas en pixels) : independant de la taille
 * d'affichage a l'ecran ET de la taille de rendu dans le PDF, qui ne sont
 * jamais identiques (cf. HandoverPdfBuilder).
 *
 * Rattache a EXACTEMENT UNE fiche parente parmi deux possibles (colonnes
 * `plugin_assetsign_assetsigns_id`/`plugin_assetsign_maintenances_id`, toutes deux
 * nullables plutot qu'un couple itemtype/items_id polymorphe generique —
 * seulement deux types de parent possibles, un vrai polymorphisme aurait ete
 * une abstraction superflue ici) :
 * - Assetsign (Assetsign/Restitution/Don/Vente) : marqueurs modifiables par AJAX
 *   tant que la fiche est `isStillEditable()` (front/damagemarker.php,
 *   public/js/sign/damage-annotation.js), et reportes sur le PDF genere
 *   (HandoverPdfBuilder).
 * - Maintenance : fiche immuable des sa creation (cf. Maintenance.php) - les
 *   marqueurs sont donc deposes cote client AVANT meme que la fiche existe
 *   (public/js/sign/damage-annotation-local.js, purement local, aucun appel
 *   serveur), puis soumis d'un bloc avec le reste du formulaire et enregistres
 *   par createMarkersForMaintenance() ; jamais modifiables ensuite (affichage
 *   lecture seule uniquement, pas de PDF - Maintenance n'en genere aucun).
 */
class DamageMarker extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

   public const SEVERITY_MINOR = 0;
   public const SEVERITY_MAJOR = 1;

    /** Nombre de vues de reference disponibles (cf. public/images/damage-views/). */
   public const VIEW_COUNT = 3;

   public static function getViewLabels(): array {
       return [
           0 => __('Vue arrière', 'assetsign'),
           1 => __('Vue de face', 'assetsign'),
           2 => __('Dessous', 'assetsign'),
       ];
   }

    /**
     * Libelles fixes (toujours en francais), pour le PDF (reel ou apercu)
     * UNIQUEMENT — jamais getViewLabels() (traduit) ici, meme principe que
     * Assetsign::getCanonicalItemtypeLabel() : un PDF est genere soit pendant
     * le hook d'affectation (session du technicien), soit pendant la
     * signature (session du beneficiaire) — le contenu archive ne doit pas
     * dependre de la langue de qui l'a declenche.
     */
   public static function getCanonicalViewLabels(): array {
       return [
           0 => 'Vue arrière',
           1 => 'Vue de face',
           2 => 'Dessous',
       ];
   }

   public static function getViewImageFilenames(): array {
       return [
           0 => 'arriere.jpg',
           1 => 'avant.jpg',
           2 => 'dessous.jpg',
       ];
   }

    /** @return array<int, array<string, mixed>> Lignes brutes (pas des instances self), une entree par marqueur. */
   public static function getForAssetsign(int $assetsigns_id): array {
       global $DB;

       $markers = [];
      foreach ($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['plugin_assetsign_assetsigns_id' => $assetsigns_id],
           'ORDER' => 'view_index, id',
       ]) as $row) {
          $markers[] = $row;
      }
       return $markers;
   }

   public static function addMarker(int $assetsigns_id, int $viewIndex, float $x, float $y, string $description, int $severity): int {
       $marker = new self();
       return (int) $marker->add([
           'plugin_assetsign_assetsigns_id' => $assetsigns_id,
           'view_index'               => $viewIndex,
           'x_percent'                => $x,
           'y_percent'                => $y,
           'description'              => $description,
           'severity'                 => $severity,
       ]);
   }

    /**
     * Met a jour la position (glisser-deposer) et/ou la description/gravite
     * d'un marqueur existant. Meme verification d'appartenance que deleteMarker().
     */
   public static function updateMarker(int $id, int $assetsigns_id, array $changes): bool {
       $marker = new self();
      if (!$marker->getFromDB($id) || (int) $marker->fields['plugin_assetsign_assetsigns_id'] !== $assetsigns_id) {
          return false;
      }
       return (bool) $marker->update(['id' => $id] + $changes);
   }

    /** @return array<int, array<string, mixed>> Lignes brutes (pas des instances self), une entree par marqueur. */
   public static function getForMaintenance(int $maintenances_id): array {
       global $DB;

       $markers = [];
      foreach ($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['plugin_assetsign_maintenances_id' => $maintenances_id],
           'ORDER' => 'view_index, id',
       ]) as $row) {
          $markers[] = $row;
      }
       return $markers;
   }

    /**
     * Enregistre en bloc les marqueurs deposes cote client PENDANT la
     * creation d'une fiche de maintenance (avant meme que son id existe,
     * contrairement au flux Assetsign qui modifie une fiche deja existante par
     * AJAX) - appelee une seule fois par Maintenance::createWithChecklist(),
     * juste apres l'insertion de la fiche elle-meme. $markers est le tableau
     * deja decode du JSON soumis par damage-annotation-local.js (une entree
     * par marqueur : view_index/x/y/description/severity) : defensif face a
     * une entree malformee (index invalide, coordonnee hors bornes...) plutot
     * que de faire planter toute la creation de la fiche pour un seul
     * marqueur invalide - ignore silencieusement l'entree en cause.
     * @param array<int, array<string, mixed>> $markers
     */
   public static function createMarkersForMaintenance(int $maintenances_id, array $markers): void {
      foreach ($markers as $marker) {
          $viewIndex = (int) ($marker['view_index'] ?? -1);
         if ($viewIndex < 0 || $viewIndex >= self::VIEW_COUNT) {
             continue;
         }
          $severity = (int) ($marker['severity'] ?? self::SEVERITY_MINOR) === self::SEVERITY_MAJOR
              ? self::SEVERITY_MAJOR
              : self::SEVERITY_MINOR;

          $instance = new self();
          $instance->add([
              'plugin_assetsign_maintenances_id' => $maintenances_id,
              'view_index'                    => $viewIndex,
              'x_percent'                      => min(100.0, max(0.0, (float) ($marker['x'] ?? 0))),
              'y_percent'                      => min(100.0, max(0.0, (float) ($marker['y'] ?? 0))),
              'description'                    => (string) ($marker['description'] ?? ''),
              'severity'                       => $severity,
          ]);
      }
   }

   public static function deleteMarker(int $id, int $assetsigns_id): bool {
       $marker = new self();
       // Verifie l'appartenance a CETTE remise avant de supprimer : sans cela,
       // un id de marqueur devine (numerotation auto-incrementee) permettrait
       // de supprimer le marqueur d'une AUTRE assetsign.
      if (!$marker->getFromDB($id) || (int) $marker->fields['plugin_assetsign_assetsigns_id'] !== $assetsigns_id) {
          return false;
      }
       return $marker->delete(['id' => $id]);
   }

    /**
     * Traite une action d'ajout/modification/suppression de repere a partir
     * des donnees POST brutes — partage par front/damagemarker.php
     * (technicien, droit RIGHT_ASSETSIGN) et les actions de repere de
     * front/sign.php (beneficiaire, jeton de signature) : ces deux points
     * d'entree ne different QUE par la maniere dont $assetsign est autorisee,
     * geree par l'appelant avant cet appel (ici, $assetsign est deja supposee
     * verifiee et editable). Auparavant recopie a l'identique dans les deux
     * front controllers ; extrait ici apres l'avoir remarque en cherchant
     * du code mort/duplique dans tout le plugin.
     *
     * @return array{success: bool, error?: string, id?: int}
     */
   public static function handleMutationRequest(Assetsign $assetsign, array $post): array {
      if (isset($post['add'])) {
          $viewIndex = (int) ($post['view_index'] ?? -1);
         if ($viewIndex < 0 || $viewIndex >= self::VIEW_COUNT) {
            return ['success' => false, 'error' => 'Vue invalide.'];
         }
          $id = self::addMarker(
              $assetsign->getID(),
              $viewIndex,
              (float) ($post['x'] ?? 0),
              (float) ($post['y'] ?? 0),
              (string) ($post['description'] ?? ''),
              (int) ($post['severity'] ?? self::SEVERITY_MINOR)
          );
         if ($id > 0) {
            $assetsign->refreshDamageAnnotationPdf();
         }
          return ['success' => $id > 0, 'id' => $id];
      }

      if (isset($post['update'])) {
          $changes = [];
         if (isset($post['x']) && isset($post['y'])) {
             $changes['x_percent'] = (float) $post['x'];
             $changes['y_percent'] = (float) $post['y'];
         }
         if (isset($post['description'])) {
             $changes['description'] = (string) $post['description'];
         }
         if (isset($post['severity'])) {
             $changes['severity'] = (int) $post['severity'];
         }
          $success = self::updateMarker((int) ($post['id'] ?? 0), $assetsign->getID(), $changes);
         if ($success) {
             $assetsign->refreshDamageAnnotationPdf();
         }
          return ['success' => $success];
      }

      if (isset($post['delete'])) {
          $success = self::deleteMarker((int) ($post['id'] ?? 0), $assetsign->getID());
         if ($success) {
             $assetsign->refreshDamageAnnotationPdf();
         }
          return ['success' => $success];
      }

       return ['success' => false, 'error' => 'Action inconnue.'];
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          // plugin_assetsign_assetsigns_id NULLABLE des la creation (pas seulement
          // sur les installations mises a jour ci-dessous) : une ligne
          // n'appartient plus forcement a une Assetsign, cf. commentaire de
          // classe. Meme schema, que l'installation soit neuve ou mise a jour.
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_assetsigns_id` int unsigned DEFAULT NULL,
                `plugin_assetsign_maintenances_id` int unsigned DEFAULT NULL,
                `view_index` tinyint unsigned NOT NULL DEFAULT 0,
                `x_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
                `y_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
                `description` varchar(255) DEFAULT NULL,
                `severity` tinyint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `plugin_assetsign_assetsigns_id` (`plugin_assetsign_assetsigns_id`),
                KEY `plugin_assetsign_maintenances_id` (`plugin_assetsign_maintenances_id`),
                CONSTRAINT `fk_dm_assetsign` FOREIGN KEY (`plugin_assetsign_assetsigns_id`) REFERENCES `glpi_plugin_assetsign_assetsigns` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_dm_maintenance` FOREIGN KEY (`plugin_assetsign_maintenances_id`) REFERENCES `glpi_plugin_assetsign_maintenances` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }

      // Installation existante (mise a jour depuis une version qui ne
      // connaissait que les marqueurs de Assetsign) : assouplit la contrainte
      // NOT NULL d'origine (une ligne peut desormais appartenir a une
      // Maintenance a la place), puis ajoute la nouvelle colonne + sa cle
      // etrangere. Tout en SQL brut, EXECUTE IMMEDIATEMENT (pas via
      // Migration::addField()/changeField(), qui se contentent de FILE
      // D'ATTENTE la modification jusqu'a l'appel de executeMigration() en
      // toute fin de plugin_assetsign_install(), cf. hook.php) : la contrainte
      // ci-dessous reference plugin_assetsign_maintenances_id, qui doit deja
      // exister au moment ou CETTE ligne s'execute, pas seulement a la toute
      // fin de l'installation - piege reel rencontre en testant (la colonne
      // n'apparaissait jamais, la contrainte echouait silencieusement contre
      // une colonne pas encore creee).
      if (!$DB->fieldExists($table, 'plugin_assetsign_maintenances_id')) {
          $migration->displayMessage("Mise à jour de $table pour les marqueurs de Maintenance");
          $DB->doQuery("ALTER TABLE `$table` MODIFY `plugin_assetsign_assetsigns_id` int unsigned DEFAULT NULL");
          $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `plugin_assetsign_maintenances_id` int unsigned DEFAULT NULL AFTER `plugin_assetsign_assetsigns_id`");
          $DB->doQuery("ALTER TABLE `$table` ADD KEY `plugin_assetsign_maintenances_id` (`plugin_assetsign_maintenances_id`)");
          $DB->doQuery("ALTER TABLE `$table` ADD CONSTRAINT `fk_dm_maintenance` FOREIGN KEY (`plugin_assetsign_maintenances_id`) REFERENCES `glpi_plugin_assetsign_maintenances` (`id`) ON DELETE CASCADE");
      }
   }
}
