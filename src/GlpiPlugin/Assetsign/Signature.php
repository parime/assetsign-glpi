<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Preuve de signature, independante du prestataire utilise.
 * Une ligne par signature (Assetsign OU Maintenance) : horodatage, empreinte du
 * PDF final, metadonnees de preuve fournies par le prestataire (certificat,
 * journal de consultation...).
 *
 * Rattachee a EXACTEMENT UNE fiche parente parmi deux possibles (colonnes
 * `plugin_assetsign_assetsigns_id`/`plugin_assetsign_maintenances_id`, toutes deux
 * nullables), meme convention que DamageMarker (cf. sa docblock de classe) :
 * - Assetsign : beneficiaire, via le flux jeton + page de signature publique
 *   (front/sign.php), preuve enregistree par markSigned().
 * - Maintenance : technicien deja authentifie, signature directement sur le
 *   formulaire de creation (pas de jeton, pas d'email), preuve enregistree
 *   par Maintenance::createWithChecklist() quand la signature est activee
 *   pour l'entite (Config::enable_maintenance_signature).
 */
class Signature extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

   public static function recordProofForAssetsign(Assetsign $assetsign, array $proof): int {
       return self::insertProof(['plugin_assetsign_assetsigns_id' => $assetsign->getID()], $proof);
   }

   public static function recordProofForMaintenance(Maintenance $maintenance, array $proof): int {
       return self::insertProof(['plugin_assetsign_maintenances_id' => $maintenance->getID()], $proof);
   }

   private static function insertProof(array $parentColumn, array $proof): int {
       global $DB;

       return $DB->insert(self::getTable(), $parentColumn + [
           'signer_name'   => $proof['signer_name'] ?? null,
           'signer_email'  => $proof['signer_email'] ?? null,
           'ip_address'    => $proof['ip_address'] ?? null,
           'user_agent'    => $proof['user_agent'] ?? null,
           'document_hash' => $proof['document_hash'] ?? null,
           'signed_at'     => $proof['signed_at'] ?? date('Y-m-d H:i:s'),
           'date_creation' => date('Y-m-d H:i:s'),
       ]) ? $DB->insertId() : 0;
   }

    /** Preuve de signature la plus recente pour une remise, ou null si non signee. */
   public static function getForAssetsign(int $assetsigns_id): ?array {
       return self::getMostRecent(['plugin_assetsign_assetsigns_id' => $assetsigns_id]);
   }

    /** Preuve de signature la plus recente pour une fiche de maintenance, ou null si non signee. */
   public static function getForMaintenance(int $maintenances_id): ?array {
       return self::getMostRecent(['plugin_assetsign_maintenances_id' => $maintenances_id]);
   }

   private static function getMostRecent(array $criteria): ?array {
       global $DB;
       $rows = iterator_to_array($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => $criteria,
           'ORDER' => 'date_creation DESC',
           'LIMIT' => 1,
       ]));
       return count($rows) ? reset($rows) : null;
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          // plugin_assetsign_assetsigns_id NULLABLE des la creation (pas seulement
          // sur les installations mises a jour ci-dessous) : une ligne peut
          // desormais appartenir a une Maintenance a la place, cf. commentaire
          // de classe. Meme schema, que l'installation soit neuve ou mise a jour.
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_assetsigns_id` int unsigned DEFAULT NULL,
                `plugin_assetsign_maintenances_id` int unsigned DEFAULT NULL,
                `signer_name` varchar(255) DEFAULT NULL,
                `signer_email` varchar(255) DEFAULT NULL,
                `ip_address` varchar(46) DEFAULT NULL,
                `user_agent` varchar(512) DEFAULT NULL,
                `document_hash` char(64) DEFAULT NULL,
                `signed_at` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `plugin_assetsign_assetsigns_id` (`plugin_assetsign_assetsigns_id`),
                KEY `plugin_assetsign_maintenances_id` (`plugin_assetsign_maintenances_id`),
                CONSTRAINT `fk_signature_assetsign` FOREIGN KEY (`plugin_assetsign_assetsigns_id`) REFERENCES `glpi_plugin_assetsign_assetsigns` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_signature_maintenance` FOREIGN KEY (`plugin_assetsign_maintenances_id`) REFERENCES `glpi_plugin_assetsign_maintenances` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      } else {
          // Audit code mort : 'provider'/'provider_reference'/'proof_data'
          // n'etaient jamais lus (seuls signer_name/signer_email/ip_address/
          // user_agent/document_hash/signed_at sont affiches, cf.
          // assetsign_form.html.twig) — provider_reference en particulier ne
          // recevait jamais que null, CanvasProvider (seul fournisseur reel)
          // ne renseignant pas de reference externe.
         foreach (['provider', 'provider_reference', 'proof_data'] as $obsoleteField) {
            if ($DB->fieldExists($table, $obsoleteField)) {
               $migration->dropField($table, $obsoleteField);
            }
         }

          // Installation existante (mise a jour depuis une version qui ne
          // connaissait que les preuves de Assetsign) : meme demarche que
          // DamageMarker::install() (assouplir la contrainte NOT NULL
          // d'origine, puis ajouter la nouvelle colonne + sa cle etrangere),
          // en SQL brut execute IMMEDIATEMENT (pas via Migration::addField(),
          // qui met la modification en FILE D'ATTENTE jusqu'a la toute fin de
          // l'installation, cf. le commentaire equivalent dans DamageMarker::
          // install() pour le piege deja rencontre).
         if (!$DB->fieldExists($table, 'plugin_assetsign_maintenances_id')) {
             $migration->displayMessage("Mise à jour de $table pour les preuves de signature de Maintenance");
             $DB->doQuery("ALTER TABLE `$table` MODIFY `plugin_assetsign_assetsigns_id` int unsigned DEFAULT NULL");
             $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `plugin_assetsign_maintenances_id` int unsigned DEFAULT NULL AFTER `plugin_assetsign_assetsigns_id`");
             $DB->doQuery("ALTER TABLE `$table` ADD KEY `plugin_assetsign_maintenances_id` (`plugin_assetsign_maintenances_id`)");
             $DB->doQuery("ALTER TABLE `$table` ADD CONSTRAINT `fk_signature_maintenance` FOREIGN KEY (`plugin_assetsign_maintenances_id`) REFERENCES `glpi_plugin_assetsign_maintenances` (`id`) ON DELETE CASCADE");
         }
      }
   }
}
