<?php

namespace GlpiPlugin\Assetsign;

use CommonDropdown;
use Migration;

/**
 * Catalogue des points de controle qualite reutilisables sur un mouvement
 * de materiel gere par Assetsign (attribution, restitution, don, vente) -
 * cf. ROADMAP.md, tableau V1, "Checklists de controle qualite configurables,
 * reutilisables" (issue #74). Meme motif que MaintenanceChecklistItem (dropdown
 * standard GLPI, type de saisie case a cocher/texte libre/menu deroulant,
 * ajout/suppression/reordonnancement par l'administrateur sans toucher au
 * code, Configuration > Intitulés) - VOLONTAIREMENT une classe distincte,
 * jamais fusionnee avec MaintenanceChecklistItem : la Maintenance reste un
 * sous-systeme separe d'Assetsign (cf. Maintenance.php), fusionner les deux
 * catalogues aurait recree exactement le couplage que cette separation
 * evite deja ailleurs dans le plugin.
 *
 * Seule vraie difference avec MaintenanceChecklistItem : chaque point
 * declare explicitement pour QUELS types de mouvement il s'applique
 * (`movement_types`, tableau JSON d'Assetsign::TYPE_*) - un meme catalogue
 * doit pouvoir proposer "Housse de protection presente" uniquement pour une
 * Attribution, et "Materiel efface/reinitialise" pour Restitution/Don/Vente,
 * sans que l'administrateur ait a dupliquer des points identiques sous
 * plusieurs listes deroulantes distinctes.
 */
class ChecklistItem extends CommonDropdown
{
   public const TYPE_CHECKBOX = 0;
   public const TYPE_TEXT     = 1;
   public const TYPE_SELECT   = 2;

   public static function getTypeName($nb = 0): string {
       return _n('Point de contrôle qualité', 'Points de contrôle qualité', $nb, 'assetsign');
   }

    /**
     * Rattache au fil d'Ariane d'Assetsign (menu 'tools'), pas au secteur
     * generique 'config' des intitules - meme raison que
     * MaintenanceChecklistItem::getSectorizedDetails(), mais sous Assetsign
     * plutot que Maintenance (ces points de controle sont specifiques aux
     * mouvements de materiel, pas a la maintenance interne).
     */
   public static function getSectorizedDetails(): array {
       return ['tools', Assetsign::class, self::class];
   }

    /** @return array<int, string> Libelles des types de saisie disponibles, par constante TYPE_*. */
   public static function getInputTypeLabels(): array {
       return [
           self::TYPE_CHECKBOX => __('Case à cocher', 'assetsign'),
           self::TYPE_TEXT     => __('Texte libre', 'assetsign'),
           self::TYPE_SELECT   => __('Menu déroulant', 'assetsign'),
       ];
   }

    /** @return string[] Options du menu deroulant (une par ligne dans le champ 'options'), sans lignes vides. */
   public static function parseOptions(string $raw): array {
       $lines = array_map('trim', explode("\n", str_replace("\r", '', $raw)));
       return array_values(array_filter($lines, static fn ($line) => $line !== ''));
   }

    /**
     * Types de mouvement (Assetsign::TYPE_*) pour lesquels ce point de
     * controle s'applique - stocke en JSON (meme convention que
     * Config::handover_states/return_states...), jamais en CSV brut.
     * @return int[]
     */
   public function getMovementTypes(): array {
       $decoded = json_decode((string) ($this->fields['movement_types'] ?? ''), true);
       return is_array($decoded) ? array_values(array_unique(array_map('intval', $decoded))) : [];
   }

    // Champs 'type'/'options' (meme raison que MaintenanceChecklistItem) et
    // 'movement_types' (case a cocher par type Assetsign::TYPE_*) places
    // volontairement en dehors des types reconnus nativement par
    // templates/dropdown_form.html.twig : tombent tous sur l'unique point
    // d'extension prevu par le cœur GLPI pour un champ personnalise,
    // displaySpecificTypeField() ci-dessous.
   public function getAdditionalFields(): array {
       return [
           [
               'name'  => 'type',
               'label' => __('Type de saisie', 'assetsign'),
               'type'  => 'assetsign_checklistitem_type',
           ],
           [
               'name'  => 'options',
               'label' => __('Options du menu déroulant (une par ligne)', 'assetsign'),
               'type'  => 'assetsign_checklistitem_options',
           ],
           [
               'name'  => 'movement_types',
               'label' => __('S\'applique aux mouvements', 'assetsign'),
               'type'  => 'assetsign_checklistitem_movement_types',
           ],
       ];
   }

   public function displaySpecificTypeField($ID, $field = [], array $options = []) {
      switch ($field['type'] ?? '') {
         case 'assetsign_checklistitem_type':
            \Dropdown::showFromArray('type', self::getInputTypeLabels(), [
                'value' => (int) ($this->fields['type'] ?? self::TYPE_CHECKBOX),
            ]);
              break;

         case 'assetsign_checklistitem_options':
             echo '<textarea name="options" class="form-control" rows="4">'
                 . htmlspecialchars((string) ($this->fields['options'] ?? ''))
                 . '</textarea>';
             echo '<p class="text-muted">'
                 . __('Utilisé uniquement si le type de saisie est "Menu déroulant".', 'assetsign')
                 . '</p>';
              break;

         case 'assetsign_checklistitem_movement_types':
             // Nouveau point : rien de pre-coche par defaut (l'administrateur choisit
             // explicitement), plutot que de supposer qu'il s'applique a tout.
             $selected = $this->isNewItem() ? [] : $this->getMovementTypes();
            foreach (Assetsign::getTypes() as $type => $label) {
                $checked = in_array($type, $selected, true) ? ' checked' : '';
                echo '<label class="form-check form-check-inline">'
                    . '<input type="checkbox" class="form-check-input" name="movement_types[]" value="' . (int) $type . '"' . $checked . '>'
                    . '<span class="form-check-label">' . htmlspecialchars($label) . '</span>'
                    . '</label>';
            }
             echo '<p class="text-muted">'
                 . __('Un point non rattaché à aucun mouvement n\'apparaît jamais sur aucune fiche.', 'assetsign')
                 . '</p>';
              break;
      }
   }

    /**
     * 'movement_types' arrive du formulaire sous forme de tableau PHP
     * ($_POST['movement_types'][]) : encode en JSON avant l'ecriture, meme
     * convention que Config::upsertForEntity() pour handover_states etc.
     */
   public function prepareInputForAdd($input) {
       $input = parent::prepareInputForAdd($input);
       return $this->encodeMovementTypes($input);
   }

   public function prepareInputForUpdate($input) {
       $input = parent::prepareInputForUpdate($input);
       return $this->encodeMovementTypes($input);
   }

   private function encodeMovementTypes(array|false $input): array|false {
      if ($input === false || !array_key_exists('movement_types', $input)) {
          return $input;
      }
       $input['movement_types'] = json_encode(array_map('intval', (array) $input['movement_types']));
       return $input;
   }

    /**
     * Catalogue des points actifs applicables a un type de mouvement donne
     * (Assetsign::TYPE_*), meme forme que Maintenance::getActiveChecklistItems()
     * pour rester interchangeable cote gabarit Twig.
     * @return array<int, array{name: string, type: int, options: string[]}>
     */
   public static function getActiveItemsForMovementType(int $movementType): array {
       global $DB;

       $items = [];
      foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => 'name']) as $row) {
          $decoded = json_decode((string) ($row['movement_types'] ?? ''), true);
          $applicable = is_array($decoded) ? array_map('intval', $decoded) : [];
         if (!in_array($movementType, $applicable, true)) {
             continue;
         }
          $items[(int) $row['id']] = [
              'name'    => $row['name'],
              'type'    => (int) $row['type'],
              'options' => self::parseOptions((string) ($row['options'] ?? '')),
          ];
      }
       return $items;
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
                `movement_types` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

         foreach ([
              'Accessoires complets',
              'Matériel nettoyé',
              'Housse/étui remis',
          ] as $name) {
            $DB->insert($table, [
              'entities_id'    => 0,
              'is_recursive'   => 1,
              'name'           => $name,
              'is_active'      => 1,
              'type'           => self::TYPE_CHECKBOX,
              'movement_types' => json_encode([Assetsign::TYPE_HANDOVER]),
              'date_creation'  => date('Y-m-d H:i:s'),
             ]);
         }
         foreach ([
              'Matériel effacé/réinitialisé',
              'Données personnelles supprimées',
          ] as $name) {
            $DB->insert($table, [
              'entities_id'    => 0,
              'is_recursive'   => 1,
              'name'           => $name,
              'is_active'      => 1,
              'type'           => self::TYPE_CHECKBOX,
              'movement_types' => json_encode([Assetsign::TYPE_RETURN, Assetsign::TYPE_DON, Assetsign::TYPE_VENTE]),
              'date_creation'  => date('Y-m-d H:i:s'),
             ]);
         }
      }
   }
}
