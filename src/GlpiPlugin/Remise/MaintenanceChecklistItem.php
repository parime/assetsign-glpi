<?php

namespace GlpiPlugin\Remise;

use CommonDropdown;
use Migration;

/**
 * Catalogue des points de controle proposes sur une fiche de maintenance
 * (nettoyage, mise a jour, controle batterie...). Dropdown standard GLPI,
 * meme motif qu'Accessory.php : ajout/suppression/reordonnancement par
 * l'administrateur sans toucher au code (Configuration > Intitulés).
 *
 * Chaque point definit aussi son propre type de saisie (case a cocher /
 * texte libre / menu deroulant a options libres) : c'est l'administrateur,
 * pas le code, qui decide comment un point donne doit etre renseigne.
 */
class MaintenanceChecklistItem extends CommonDropdown
{
   public const TYPE_CHECKBOX = 0;
   public const TYPE_TEXT     = 1;
   public const TYPE_SELECT   = 2;

   public static function getTypeName($nb = 0): string {
       return _n('Point de contrôle de maintenance', 'Points de contrôle de maintenance', $nb, 'remise');
   }

    /**
     * Rattache au fil d'Ariane de Maintenance (menu 'tools'), pas au secteur
     * generique 'config' des intitules — meme raison que
     * Template::getSectorizedDetails(), mais sous Maintenance plutot que
     * Remise (les points de controle sont specifiques a la maintenance).
     */
   public static function getSectorizedDetails(): array {
       return ['tools', Maintenance::class, self::class];
   }

    /** @return array<int, string> Libelles des types de saisie disponibles, par constante TYPE_*. */
   public static function getInputTypeLabels(): array {
       return [
           self::TYPE_CHECKBOX => __('Case à cocher', 'remise'),
           self::TYPE_TEXT     => __('Texte libre', 'remise'),
           self::TYPE_SELECT   => __('Menu déroulant', 'remise'),
       ];
   }

    /** @return string[] Options du menu deroulant (une par ligne dans le champ 'options'), sans lignes vides. */
   public static function parseOptions(string $raw): array {
       $lines = array_map('trim', explode("\n", str_replace("\r", '', $raw)));
       return array_values(array_filter($lines, static fn ($line) => $line !== ''));
   }

    // Champs 'type' et 'options' places volontairement en dehors des types
    // reconnus nativement par templates/dropdown_form.html.twig (text,
    // dropdownValue, bool...) : cela fait tomber leur rendu sur l'unique
    // point d'extension prevu par le cœur GLPI pour un champ totalement
    // personnalise, displaySpecificTypeField() ci-dessous (meme mecanisme
    // que CommonDevice::displaySpecificTypeField() dans le cœur).
   public function getAdditionalFields(): array {
       return [
           [
               'name'  => 'type',
               'label' => __('Type de saisie', 'remise'),
               'type'  => 'remise_checklist_type',
           ],
           [
               'name'  => 'options',
               'label' => __('Options du menu déroulant (une par ligne)', 'remise'),
               'type'  => 'remise_checklist_options',
           ],
       ];
   }

   public function displaySpecificTypeField($ID, $field = [], array $options = []) {
      switch ($field['type'] ?? '') {
         case 'remise_checklist_type':
            \Dropdown::showFromArray('type', self::getInputTypeLabels(), [
                'value' => (int) ($this->fields['type'] ?? self::TYPE_CHECKBOX),
            ]);
              break;

         case 'remise_checklist_options':
             echo '<textarea name="options" class="form-control" rows="4">'
                 . htmlspecialchars((string) ($this->fields['options'] ?? ''))
                 . '</textarea>';
             echo '<p class="text-muted">'
                 . __('Utilisé uniquement si le type de saisie est "Menu déroulant".', 'remise')
                 . '</p>';
              break;
      }
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL DEFAULT '',
                `comment` text,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `type` tinyint NOT NULL DEFAULT 0,
                `options` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

         foreach ([
              'Nettoyage physique',
              'Mise à jour des pilotes',
              'Mise à jour du système',
              'Contrôle de la batterie',
              'Contrôle antivirus',
              'Vérification des ports/connectiques',
          ] as $name) {
            $DB->insert($table, [
              'entities_id'   => 0,
              'is_recursive'  => 1,
              'name'          => $name,
              'is_active'     => 1,
              'type'          => self::TYPE_CHECKBOX,
              'date_creation' => date('Y-m-d H:i:s'),
             ]);
         }
      }

       // Installation existante (mise a jour) : le type 'integer' (et non
       // 'tinyint' brut) est necessaire pour que Migration::addField() pose
       // vraiment un DEFAULT 0/NOT NULL — piege deja rencontre ailleurs dans
       // ce plugin (cf. TROUBLESHOOTING.md).
      if (!$DB->fieldExists($table, 'type')) {
          $migration->addField($table, 'type', 'integer', ['value' => self::TYPE_CHECKBOX]);
      }
      if (!$DB->fieldExists($table, 'options')) {
          $migration->addField($table, 'options', 'text');
      }
   }
}
