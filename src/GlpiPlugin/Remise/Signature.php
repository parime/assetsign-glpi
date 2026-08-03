<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use Migration;

/**
 * Preuve de signature, independante du prestataire utilise.
 * Une ligne par remise signee (ou par fiche de maintenance dont le technicien a
 * signe, cf. getForMaintenance()/recordProofForMaintenance()) : horodatage,
 * empreinte du PDF final, metadonnees de preuve fournies par le prestataire
 * (certificat, journal de consultation...).
 */
class Signature extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_REMISE;

   public static function recordProof(Remise $remise, array $proof): int {
       global $DB;

       return $DB->insert(self::getTable(), [
           'plugin_remise_remises_id' => $remise->getID(),
           'signer_name'              => $proof['signer_name'] ?? null,
           'signer_email'             => $proof['signer_email'] ?? null,
           'ip_address'               => $proof['ip_address'] ?? null,
           'user_agent'               => $proof['user_agent'] ?? null,
           'document_hash'            => $proof['document_hash'] ?? null,
           'signed_at'                => $proof['signed_at'] ?? date('Y-m-d H:i:s'),
           'date_creation'            => date('Y-m-d H:i:s'),
       ]) ? $DB->insertId() : 0;
   }

    /** Preuve de signature la plus recente pour une remise, ou null si non signee. */
   public static function getForRemise(int $remises_id): ?array {
       global $DB;
       $rows = iterator_to_array($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['plugin_remise_remises_id' => $remises_id],
           'ORDER' => 'date_creation DESC',
           'LIMIT' => 1,
       ]));
       return count($rows) ? reset($rows) : null;
   }

    /**
     * Enregistre la preuve de signature du technicien pour une fiche de
     * maintenance (colonne sœur nullable de plugin_remise_remises_id, meme
     * schema que DamageMarker pour les memes raisons — une ligne appartient
     * soit a une Remise, soit a une Maintenance, jamais les deux).
     */
   public static function recordProofForMaintenance(int $maintenances_id, array $proof): int {
       global $DB;

       return $DB->insert(self::getTable(), [
           'plugin_remise_maintenances_id' => $maintenances_id,
           'signer_name'                   => $proof['signer_name'] ?? null,
           'signer_email'                  => $proof['signer_email'] ?? null,
           'ip_address'                    => $proof['ip_address'] ?? null,
           'user_agent'                    => $proof['user_agent'] ?? null,
           'document_hash'                 => $proof['document_hash'] ?? null,
           'signed_at'                     => $proof['signed_at'] ?? date('Y-m-d H:i:s'),
           'date_creation'                 => date('Y-m-d H:i:s'),
       ]) ? $DB->insertId() : 0;
   }

    /** Preuve de signature la plus recente pour une fiche de maintenance, ou null si non signee. */
   public static function getForMaintenance(int $maintenances_id): ?array {
       global $DB;
       $rows = iterator_to_array($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['plugin_remise_maintenances_id' => $maintenances_id],
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
          // plugin_remise_remises_id NULLABLE des la creation (pas seulement
          // sur les installations mises a jour ci-dessous) : une ligne peut
          // desormais appartenir a une Maintenance a la place, meme schema que
          // DamageMarker::install() pour la meme raison.
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_remise_remises_id` int unsigned DEFAULT NULL,
                `plugin_remise_maintenances_id` int unsigned DEFAULT NULL,
                `signer_name` varchar(255) DEFAULT NULL,
                `signer_email` varchar(255) DEFAULT NULL,
                `ip_address` varchar(46) DEFAULT NULL,
                `user_agent` varchar(512) DEFAULT NULL,
                `document_hash` char(64) DEFAULT NULL,
                `signed_at` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `plugin_remise_remises_id` (`plugin_remise_remises_id`),
                KEY `plugin_remise_maintenances_id` (`plugin_remise_maintenances_id`),
                CONSTRAINT `fk_signature_remise` FOREIGN KEY (`plugin_remise_remises_id`) REFERENCES `glpi_plugin_remise_remises` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_signature_maintenance` FOREIGN KEY (`plugin_remise_maintenances_id`) REFERENCES `glpi_plugin_remise_maintenances` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      } else {
          // Audit code mort : 'provider'/'provider_reference'/'proof_data'
          // n'etaient jamais lus (seuls signer_name/signer_email/ip_address/
          // user_agent/document_hash/signed_at sont affiches, cf.
          // remise_form.html.twig) — provider_reference en particulier ne
          // recevait jamais que null, CanvasProvider (seul fournisseur reel)
          // ne renseignant pas de reference externe.
         foreach (['provider', 'provider_reference', 'proof_data'] as $obsoleteField) {
            if ($DB->fieldExists($table, $obsoleteField)) {
               $migration->dropField($table, $obsoleteField);
            }
         }

          // Installation existante (mise a jour depuis une version qui ne
          // connaissait que la signature de Remise) : assouplit la contrainte
          // NOT NULL d'origine puis ajoute la nouvelle colonne + sa cle
          // etrangere. SQL brut execute immediatement (pas via
          // Migration::addField(), qui met en FILE D'ATTENTE jusqu'a
          // executeMigration() en toute fin d'installation) : la contrainte
          // ci-dessous reference plugin_remise_maintenances_id, qui doit deja
          // exister au moment ou cette ligne s'execute — meme piege deja
          // rencontre et documente sur DamageMarker::install().
         if (!$DB->fieldExists($table, 'plugin_remise_maintenances_id')) {
             $migration->displayMessage("Mise à jour de $table pour la signature de Maintenance");
             $DB->doQuery("ALTER TABLE `$table` MODIFY `plugin_remise_remises_id` int unsigned DEFAULT NULL");
             $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `plugin_remise_maintenances_id` int unsigned DEFAULT NULL AFTER `plugin_remise_remises_id`");
             $DB->doQuery("ALTER TABLE `$table` ADD KEY `plugin_remise_maintenances_id` (`plugin_remise_maintenances_id`)");
             $DB->doQuery("ALTER TABLE `$table` ADD CONSTRAINT `fk_signature_maintenance` FOREIGN KEY (`plugin_remise_maintenances_id`) REFERENCES `glpi_plugin_remise_maintenances` (`id`) ON DELETE CASCADE");
         }
      }
   }
}
