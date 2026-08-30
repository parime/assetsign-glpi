<?php

namespace GlpiPlugin\Assetsign;

use CommonDropdown;
use Migration;

/**
 * Catalogue de KITS reutilisables (cf. ROADMAP.md V3, issue #83, "Kits/accessoires
 * avec controle automatique au retour") : un kit est un GROUPE nomme d'accessoires
 * (cf. Accessory) censes voyager ensemble avec une remise (ex: "Kit ordinateur
 * portable standard" = Chargeur + Sacoche + Souris) — permet de detecter
 * automatiquement, a la Restitution, qu'un des accessoires attendus n'est pas
 * revenu (cf. Assetsign::getKitCompleteness()), sans avoir a ressaisir a la main
 * la liste attendue a chaque remise.
 *
 * Meme motif que ChecklistItem (issue #74) : dropdown standard GLPI (CRUD/
 * recherche/droits generiques gratuits), une liste stockee en JSON directement
 * sur la ligne du catalogue (`accessories_id`, meme convention que
 * ChecklistItem::movement_types) — VOLONTAIREMENT pas une nouvelle table pivot :
 * un Kit n'a qu'une seule caracteristique propre (SA composition, une simple
 * liste d'identifiants Accessory), contrairement a AssetsignAccessory qui doit
 * en plus porter une quantite et un commentaire PAR REMISE (deux besoins
 * differents — cf. Kit::computeCompleteness(), la comparaison se fait par
 * simple PRESENCE d'un accessoire, jamais par quantite).
 */
class Kit extends CommonDropdown
{
   public static function getTypeName($nb = 0): string {
       return _n('Kit d\'accessoires', 'Kits d\'accessoires', $nb, 'assetsign');
   }

    /**
     * Rattache au fil d'Ariane de Assetsign (menu 'tools') — meme raison que
     * Accessory::getSectorizedDetails()/ChecklistItem::getSectorizedDetails().
     */
   public static function getSectorizedDetails(): array {
       return ['tools', Assetsign::class, self::class];
   }

    /** @return int[] Identifiants Accessory attendus dans ce kit. */
   public function getExpectedAccessoryIds(): array {
       return self::decodeAccessoryIds((string) ($this->fields['accessories_id'] ?? ''));
   }

    /**
     * Extrait, sans instancier de Kit ni faire de requete supplementaire — la
     * meme forme (JSON -> tableau d'entiers uniques) que getExpectedAccessoryIds(),
     * mais utilisable directement sur une ligne brute deja chargee en lot (cf.
     * PassportEvent::attachKitSummaries(), qui lit plusieurs kits en UNE requete
     * et ne doit surtout pas en refaire une par kit rien que pour decoder ce
     * champ).
     * @return int[]
     */
   public static function decodeAccessoryIds(string $raw): array {
       $decoded = json_decode($raw, true);
       return is_array($decoded) ? array_values(array_unique(array_map('intval', $decoded))) : [];
   }

    /** @return array<int, string> Nom des accessoires attendus, indexes par id Accessory (pour l'affichage). */
   public function getExpectedAccessoryNames(): array {
       $names = [];
      foreach ($this->getExpectedAccessoryIds() as $accessories_id) {
          $names[$accessories_id] = \Dropdown::getDropdownName(Accessory::getTable(), $accessories_id);
      }
       return $names;
   }

    /**
     * Comparaison PURE (aucun acces base) entre les accessoires ATTENDUS par un
     * kit et ceux REELLEMENT restitues sur une fiche donnee — coeur de la
     * detection automatique du V3 "Kits/accessoires avec controle automatique au
     * retour" (issue #83), volontairement extrait en fonction pure pour rester
     * facilement testable sans base de donnees (meme esprit que
     * ChecklistItem::parseOptions()). Comparaison par simple PRESENCE de
     * l'identifiant Accessory, jamais par quantite : un kit exprime un TYPE
     * d'accessoire attendu, pas un nombre precis (contrairement a
     * AssetsignAccessory::quantity, qui reste une donnee de tracabilite PROPRE A
     * CHAQUE remise, jamais une exigence du kit lui-meme).
     * @param int[] $expectedAccessoryIds
     * @param int[] $actualAccessoryIds
     * @return array{expected_total: int, returned_count: int, missing_ids: int[], complete: bool}
     */
   public static function computeCompleteness(array $expectedAccessoryIds, array $actualAccessoryIds): array {
       $expected = array_values(array_unique(array_map('intval', $expectedAccessoryIds)));
       $actual   = array_values(array_unique(array_map('intval', $actualAccessoryIds)));

       $missing = array_values(array_diff($expected, $actual));

       return [
           'expected_total' => count($expected),
           'returned_count' => count($expected) - count($missing),
           'missing_ids'    => $missing,
           'complete'       => $missing === [],
       ];
   }

    /**
     * Couleur de badge associee a un resultat de computeCompleteness() : vert si
     * complet, orange si partiellement revenu, rouge si RIEN n'est revenu (perte
     * totale) — severite volontairement distincte du gris "configure mais pas
     * encore rempli" utilise par ChecklistItem (cf.
     * PassportEvent::attachChecklistSummaries()) : une Restitution sans AUCUN
     * accessoire du kit revenu est un signal reel de perte de materiel, pas un
     * simple "pas encore renseigne" (qui, lui, reste normal pour une checklist
     * a peine ouverte). Extrait ici pour rester la SEULE source de cette regle,
     * partagee par Assetsign::getKitCompleteness() (fiche unique) et
     * PassportEvent::attachKitSummaries() (frise batchee).
     * @param array{expected_total: int, returned_count: int, missing_ids: int[], complete: bool} $completeness
     */
   public static function colorForCompleteness(array $completeness): string {
       return match (true) {
           $completeness['complete']           => '#2fb344',
           $completeness['returned_count'] > 0 => '#f76707',
           default                              => '#dc3545',
       };
   }

    // Champ 'accessories_id' (case a cocher par Accessory actif) place
    // volontairement en dehors des types reconnus nativement par
    // templates/dropdown_form.html.twig : tombe sur l'unique point d'extension
    // prevu par le coeur GLPI pour un champ personnalise, displaySpecificTypeField()
    // ci-dessous — meme mecanisme exact que ChecklistItem::movement_types.
   public function getAdditionalFields(): array {
       return [
           [
               'name'  => 'accessories_id',
               'label' => __('Accessoires attendus dans ce kit', 'assetsign'),
               'type'  => 'assetsign_kit_accessories',
           ],
       ];
   }

   public function displaySpecificTypeField($ID, $field = [], array $options = []) {
      if (($field['type'] ?? '') !== 'assetsign_kit_accessories') {
          return;
      }

       global $DB;
       // Nouveau kit : rien de pre-coche par defaut, meme convention que
       // ChecklistItem::movement_types (l'administrateur choisit explicitement).
       $selected = $this->isNewItem() ? [] : $this->getExpectedAccessoryIds();
      foreach ($DB->request(['FROM' => Accessory::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => 'name']) as $row) {
          $checked = in_array((int) $row['id'], $selected, true) ? ' checked' : '';
          echo '<label class="form-check form-check-inline">'
              . '<input type="checkbox" class="form-check-input" name="accessories_id[]" value="' . (int) $row['id'] . '"' . $checked . '>'
              . '<span class="form-check-label">' . htmlspecialchars($row['name']) . '</span>'
              . '</label>';
      }
       echo '<p class="text-muted">'
           . __('Ces accessoires seront automatiquement vérifiés à la restitution si ce kit est assigné à une remise.', 'assetsign')
           . '</p>';
   }

    /**
     * 'accessories_id' arrive du formulaire sous forme de tableau PHP
     * ($_POST['accessories_id'][]) : encode en JSON avant l'ecriture, meme
     * convention que ChecklistItem::encodeMovementTypes().
     */
   public function prepareInputForAdd($input) {
       $input = parent::prepareInputForAdd($input);
       return $this->encodeAccessoryIds($input);
   }

   public function prepareInputForUpdate($input) {
       $input = parent::prepareInputForUpdate($input);
       return $this->encodeAccessoryIds($input);
   }

   private function encodeAccessoryIds(array|false $input): array|false {
      if ($input === false || !array_key_exists('accessories_id', $input)) {
          return $input;
      }
       $input['accessories_id'] = json_encode(array_values(array_unique(array_map('intval', (array) $input['accessories_id']))));
       return $input;
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
                `accessories_id` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

          // Exemple de kit pret a l'emploi, compose des accessoires deja semes par
          // Accessory::install() (execute avant Kit::install(), cf. hook.php) :
          // simple confort pour l'administrateur, jamais suppose exister par le
          // reste du code (un nom introuvable est simplement ignore, tableau vide
          // -> getExpectedAccessoryIds() renvoie [], jamais une erreur).
          $seedAccessoryIds = [];
         foreach (['Chargeur', 'Sacoche', 'Souris'] as $name) {
             $row = $DB->request(['FROM' => Accessory::getTable(), 'WHERE' => ['name' => $name], 'LIMIT' => 1])->current();
            if ($row !== null) {
                $seedAccessoryIds[] = (int) $row['id'];
            }
         }
         if ($seedAccessoryIds !== []) {
             $DB->insert($table, [
               'entities_id'    => 0,
               'is_recursive'   => 1,
               'name'           => 'Kit ordinateur portable standard',
               'is_active'      => 1,
               'accessories_id' => json_encode($seedAccessoryIds),
               'date_creation'  => date('Y-m-d H:i:s'),
             ]);
         }
      }
   }
}
