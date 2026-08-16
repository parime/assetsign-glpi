<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Migration;

/**
 * Gabarit de contrat / charte, editable dans Configuration > Assetsign > Gabarits.
 * Le contenu est du HTML brut insere tel quel dans le PDF (balise Twig |raw).
 */
class Template extends CommonDBTM
{
   public static $rightname = Profile::RIGHT_TEMPLATE;

    /**
     * Texte pre-rempli propose a l'administrateur pour un NOUVEAU gabarit — pas
     * seulement pour le gabarit seme automatiquement a l'installation (cf.
     * install()). Sans cela, un administrateur qui cree son propre gabarit
     * partait d'un champ entierement vide, avec un risque reel de fiche de
     * remise/restitution sans aucune condition generale ni charte affichee au
     * beneficiaire — constate en conditions reelles. Le texte reste entierement
     * modifiable (ou effaçable) via le formulaire, ce n'est qu'une valeur de
     * depart. Chaque type porte son propre texte par defaut (cf.
     * Workflow\WorkflowTypeInterface::getDefaultTemplateContent()).
     */
   public static function getDefaultContentFor(int $type): array {
       return Workflow\WorkflowTypeRegistry::get($type)->getDefaultTemplateContent();
   }

   public static function getTypeName($nb = 0): string {
       return _n('Gabarit de remise', 'Gabarits de remise', $nb, 'assetsign');
   }

    /**
     * Rattache au fil d'Ariane de Assetsign (menu 'tools'), pas au secteur
     * generique 'config' des intitules — cf. Assetsign::getSectorizedDetails()/
     * getMenuContent() et le commentaire equivalent dans ROADMAP.md.
     */
   public static function getSectorizedDetails(): array {
       return ['tools', Assetsign::class, self::class];
   }

    // rawSearchOptions() (pas getSearchOptions(), `final` dans CommonDBTM) :
    // meme correctif que Assetsign::rawSearchOptions(), meme cause, meme
    // symptome (liste "Gabarits de remise" sans colonnes ni en-tetes).
   public function rawSearchOptions(): array {
       return [
           ['id' => 'common', 'name' => self::getTypeName(1)],
           ['id' => 1, 'table' => self::getTable(), 'field' => 'name', 'name' => __('Nom'), 'datatype' => 'itemlink'],
           ['id' => 2, 'table' => self::getTable(), 'field' => 'type', 'name' => __('Type de remise', 'assetsign'), 'datatype' => 'specific'],
           ['id' => 3, 'table' => self::getTable(), 'field' => 'is_default', 'name' => __('Par défaut', 'assetsign'), 'datatype' => 'bool'],
           ['id' => 4, 'table' => self::getTable(), 'field' => 'is_active', 'name' => __('Actif'), 'datatype' => 'bool'],
       ];
   }

   public static function getSpecificValueToDisplay($field, $values, array $options = []) {
      if (!is_array($values)) {
          $values = [$field => $values];
      }
      if ($field === 'type') {
          return Assetsign::getTypes()[$values['type']] ?? $values['type'];
      }
       return parent::getSpecificValueToDisplay($field, $values, $options);
   }

   public function showForm($ID, array $options = []): bool {
       $this->initForm($ID, $options);

       // Pour un NOUVEAU gabarit (pas encore en base), pre-selectionne le type
       // passe en parametre (lien "+ Nouveau gabarit" depuis l'onglet de
       // configuration d'un type, cf. config_form.html.twig) — a defaut,
       // TYPE_HANDOVER (premiere option du select).
       $newTemplateType = (int) ($_GET['type'] ?? $options['type'] ?? Assetsign::TYPE_HANDOVER);
      if ($this->isNewID($ID)) {
          $this->fields['type'] = $newTemplateType;
      }

       // Pre-remplit avec un texte par defaut raisonnable plutot que de laisser
       // les champs vides — cf. getDefaultContentFor().
       $defaultContent = $this->isNewID($ID)
           ? self::getDefaultContentFor($newTemplateType)
           : ['content' => $this->fields['content'] ?? '', 'charter_content' => $this->fields['charter_content'] ?? ''];

       // Apercu initial (avant toute interaction JS, cf. live-preview.js) : reprend
       // exactement ce que le formulaire affiche par defaut, pour un nouveau
       // gabarit comme pour un existant.
       $previewType = $this->isNewID($ID) ? $newTemplateType : (int) $this->fields['type'];
       $previewHtml = (new Pdf\HandoverPdfBuilder())->renderPreview((int) ($this->fields['entities_id'] ?? 0), $previewType, [
           'content'         => $defaultContent['content'],
           'charter_content' => $defaultContent['charter_content'],
           'include_content' => $this->isNewID($ID) ? true : (bool) $this->fields['include_content'],
           'include_charter' => $this->isNewID($ID) ? true : (bool) $this->fields['include_charter'],
       ]);

       \Glpi\Application\View\TemplateRenderer::getInstance()->display('@assetsign/template_form.html.twig', [
           'item'            => $this,
           'types'           => Assetsign::getTypes(),
           'csrf_token'      => \Session::getNewCSRFToken(),
           // Jeton DEDIE a l'apercu en direct, independant de celui du
           // formulaire lui-meme — cf. commentaire de Config::showConfigForm().
           // `true` (standalone) est OBLIGATOIRE ici : sans lui,
           // getNewCSRFToken() renvoie le meme jeton que l'appel precedent
           // (variable globale $CURRENTCSRFTOKEN du coeur GLPI), pas un jeton
           // independant.
           'preview_csrf_token' => \Session::getNewCSRFToken(true),
           'default_content' => $defaultContent,
           'preview_html'    => $previewHtml,
       ]);

       return true;
   }

   public static function getDefaultFor(int $type, int $entities_id): ?self {
       global $DB;

      foreach ([$entities_id, 0] as $tryEntity) {
          $rows = iterator_to_array($DB->request([
              'FROM'  => self::getTable(),
              'WHERE' => ['type' => $type, 'is_default' => 1, 'is_active' => 1, 'entities_id' => $tryEntity],
              'LIMIT' => 1,
          ]));
         if (count($rows) > 0) {
            $template = new self();
            $template->getFromDB(reset($rows)['id']);
            return $template;
         }
      }
       return null;
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
                `type` tinyint NOT NULL DEFAULT 0,
                `content` text,
                `charter_content` text,
                `include_content` tinyint NOT NULL DEFAULT 1,
                `include_charter` tinyint NOT NULL DEFAULT 1,
                `is_default` tinyint NOT NULL DEFAULT 0,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `type` (`type`),
                KEY `is_default` (`is_default`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

          self::seedDefaultTemplate(\GlpiPlugin\Assetsign\Assetsign::TYPE_HANDOVER, 'Gabarit de remise par défaut');
          self::seedDefaultTemplate(\GlpiPlugin\Assetsign\Assetsign::TYPE_RETURN, 'Gabarit de restitution par défaut');
          self::seedDefaultTemplate(\GlpiPlugin\Assetsign\Assetsign::TYPE_DON, 'Gabarit de don par défaut');
          self::seedDefaultTemplate(\GlpiPlugin\Assetsign\Assetsign::TYPE_VENTE, 'Gabarit de vente par défaut');
      } else {
          // Montee de version : desactive les gabarits de l'ancien type "Echange"
          // (valeur 2, retiree — cf. Assetsign::TYPE_EXCHANGE) sans les supprimer,
          // pour ne pas perdre l'historique tout en les sortant de la liste active.
          $DB->update($table, ['is_active' => 0], ['type' => 2]);

         if (!$DB->fieldExists($table, 'include_content')) {
             // Valeur par defaut 1 (pas 0) sur les DEUX colonnes : sans cela, tous
             // les gabarits DEJA EN PRODUCTION verraient leurs mentions legales
             // disparaitre silencieusement du PDF des le prochain document genere
             // (cf. le commentaire equivalent sur getDefaultContentFor()).
             $migration->addField($table, 'include_content', 'bool', ['value' => 1, 'after' => 'charter_content']);
             $migration->addField($table, 'include_charter', 'bool', ['value' => 1, 'after' => 'include_content']);
             $migration->migrationOneTable($table);
         }

          // Montee de version : seme le gabarit par defaut de tout type ajoute
          // apres l'installation initiale d'une instance existante (sans cela,
          // Template::getDefaultFor(...) renverrait null indefiniment pour ce
          // type, et toute fiche serait generee sans aucune condition affichee).
          self::seedIfMissing($table, \GlpiPlugin\Assetsign\Assetsign::TYPE_DON, 'Gabarit de don par défaut');
          self::seedIfMissing($table, \GlpiPlugin\Assetsign\Assetsign::TYPE_VENTE, 'Gabarit de vente par défaut');
      }

       self::seedDefaultDisplayPreferences();
   }

    /**
     * Meme raison que Assetsign::seedDefaultDisplayPreferences() : sans ca, la
     * liste "Gabarits de remise" n'affiche par defaut que la colonne ID.
     * Seme une seule fois (jamais si une ligne existe deja pour cet itemtype).
     */
   private static function seedDefaultDisplayPreferences(): void {
       global $DB;

       $alreadySeeded = $DB->request([
           'FROM'  => 'glpi_displaypreferences',
           'WHERE' => ['itemtype' => self::class],
           'LIMIT' => 1,
       ])->count() > 0;

      if ($alreadySeeded) {
          return;
      }

       $rank = 1;
      foreach ([1, 2, 3, 4] as $searchOptionId) {
          $DB->insert('glpi_displaypreferences', [
              'itemtype'  => self::class,
              'num'       => $searchOptionId,
              'rank'      => $rank++,
              'users_id'  => 0,
              'interface' => 'central',
          ]);
      }
   }

   private static function seedIfMissing(string $table, int $type, string $name): void {
      if (countElementsInTable($table, ['type' => $type]) === 0) {
          self::seedDefaultTemplate($type, $name);
      }
   }

    /**
     * Seme un gabarit par defaut (entite racine, actif, par defaut pour son
     * type) avec le texte fourni par le type lui-meme (cf.
     * getDefaultContentFor()) — reutilise a l'installation initiale pour
     * chaque type existant, et lors d'une montee de version pour tout type
     * ajoute apres coup (cf. le seed differe du Don ci-dessus).
     */
   private static function seedDefaultTemplate(int $type, string $name): void {
       global $DB;

       $defaults = self::getDefaultContentFor($type);
       $DB->insert(self::getTable(), [
           'entities_id'     => 0,
           'is_recursive'    => 1,
           'name'            => $name,
           'type'            => $type,
           'content'         => $defaults['content'],
           'charter_content' => $defaults['charter_content'],
           'is_default'      => 1,
           'is_active'       => 1,
           'date_creation'   => date('Y-m-d H:i:s'),
       ]);
   }
}
