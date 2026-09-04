<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Passeport environnemental (V3, cf. ROADMAP.md — "Passeport environnemental
 * (empreinte fabrication, source, niveau de confiance)", issue #80) : table
 * dédiée 1-vers-1 avec N'IMPORTE QUEL matériel géré par le plugin
 * (`itemtype`/`items_id`, même patron que `Movement`/`ResidualValue` — PAS
 * `plugin_assetsign_assetsigns_id` comme `VenteDetails`, un matériel peut
 * avoir une empreinte environnementale sans jamais avoir eu de fiche
 * Assetsign).
 *
 * **Décision de conception (recherche documentée dans le commentaire posté sur
 * l'issue #80 avant ce code)** : l'API publique Boavizta n'expose, pour les
 * types de matériel gérés par ce plugin (laptop/desktop), que 2 "archétypes"
 * génériques par catégorie (moyennes "pro"/"perso"), jamais une valeur propre
 * à un modèle réel — rien dans GLPI ne permet de trancher objectivement entre
 * les deux. Afficher un tel chiffre comme "l'empreinte de CE matériel"
 * reviendrait à inventer une donnée, même étiqueté "source : API externe" et
 * "confiance : faible" — ça contredirait le principe fondateur du plugin.
 * **Scope réduit à la saisie manuelle uniquement dans cette version** :
 * `source` garde ses 3 valeurs (constructeur/API externe dédiée/saisie
 * manuelle) pour ne pas fermer la porte à une vraie intégration future (base
 * de données par modèle exact), mais la valeur est TOUJOURS tapée par un
 * technicien dans cette version — `source` trace alors d'où il/elle tient le
 * chiffre (fiche constructeur, outil externe consulté à la main, estimation
 * propre), pas un mécanisme d'automatisation.
 *
 * Aucune valeur par défaut : `carbon_footprint_manufacturing` reste `null`
 * (rien affiché, jamais un 0 ni une estimation) tant qu'aucune saisie n'a eu
 * lieu — même principe que `ResidualValue::manual_value`.
 *
 * Table : `glpi_plugin_assetsign_environmentaldatas` (convention de
 * pluralisation GLPI standard, cf. `glpi_plugin_assetsign_residualvalues`).
 * Pas de front dédié : Api\EnvironmentalDataFormController
 * (front/environmentaldata.form.php).
 */
class EnvironmentalData extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_ASSETSIGN;

    public const SOURCE_MANUFACTURER = 'manufacturer';
    public const SOURCE_EXTERNAL_API = 'external_api';
    public const SOURCE_MANUAL       = 'manual';

    public const CONFIDENCE_HIGH   = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW    = 'low';

   public static function getForItem(string $itemtype, int $items_id): ?self {
       $data = new self();
       return $data->getFromDBByCrit(['itemtype' => $itemtype, 'items_id' => $items_id]) ? $data : null;
   }

    /**
     * @return array<string, string> Valeur => libellé traduit, ordre d'affichage stable.
     */
   public static function getSourceLabels(): array {
       return [
           self::SOURCE_MANUFACTURER => __('Constructeur', 'assetsign'),
           self::SOURCE_EXTERNAL_API => __('API externe dédiée', 'assetsign'),
           self::SOURCE_MANUAL       => __('Saisie manuelle', 'assetsign'),
       ];
   }

    /**
     * @return array<string, string> Valeur => libellé traduit, ordre d'affichage stable.
     */
   public static function getConfidenceLabels(): array {
       return [
           self::CONFIDENCE_HIGH   => __('Élevé', 'assetsign'),
           self::CONFIDENCE_MEDIUM => __('Moyen', 'assetsign'),
           self::CONFIDENCE_LOW    => __('Faible', 'assetsign'),
       ];
   }

    /** Couleur de badge par niveau de confiance — même convention que Movement::getStatusColor(). */
   public static function getConfidenceColor(?string $confidence): string {
       return match ($confidence) {
           self::CONFIDENCE_HIGH   => '#2fb344',
           self::CONFIDENCE_MEDIUM => '#f76707',
           self::CONFIDENCE_LOW    => '#d63939',
           default                 => '#6c757d',
       };
   }

    /**
     * Crée, met à jour, ou efface la ligne pour ce matériel. `$carbonFootprint`
     * à `null` efface explicitement les 3 champs (jamais un état incohérent
     * genre "source renseignée mais aucune valeur") — même convention que
     * `ResidualValue::upsertForItem()` : la ligne reste (pas de suppression),
     * seules les valeurs sont vidées.
     */
   public static function upsertForItem(
       string $itemtype,
       int $items_id,
       ?float $carbonFootprint,
       ?string $source,
       ?string $confidence
   ): void {
       $data = [
           'carbon_footprint_manufacturing' => $carbonFootprint,
           'source'                         => $carbonFootprint !== null ? $source : null,
           'confidence_level'               => $carbonFootprint !== null ? $confidence : null,
       ];

       $existing = self::getForItem($itemtype, $items_id);
      if ($existing !== null) {
          $existing->update(['id' => $existing->getID()] + $data);
          return;
      }
      if ($carbonFootprint === null) {
          return; // Rien a effacer, rien a creer.
      }
       (new self())->add(['itemtype' => $itemtype, 'items_id' => $items_id] + $data);
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(100) NOT NULL,
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `carbon_footprint_manufacturing` decimal(10,2) DEFAULT NULL,
                `source` varchar(32) DEFAULT NULL,
                `confidence_level` varchar(16) DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`itemtype`, `items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
