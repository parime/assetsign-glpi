<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\FieldsPluginValues;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;
use GlpiPlugin\Assetsign\Template;

/**
 * Issue #229 : champs du plugin Fields sur la fiche, avec le vrai plugin Fields. Ignoré là où il
 * n'est pas installé (la CI de ce dépôt) ; les règles pures sont couvertes par FieldsPluginLogicTest.
 *
 * Créer un bloc Fields crée une table (DDL, hors transaction) : le bloc est créé une fois pour la
 * classe et supprimé à la fin ; les valeurs, fiches et réglages restent dans la transaction de
 * chaque test.
 */
final class FieldsPluginValuesTest extends AssetsignTestCase
{
   private static int $containersId = 0;

   /** @var array<string, string> libellé => nom système */
   private static array $names = [];

   public static function setUpBeforeClass(): void {
      if (!FieldsPluginValues::isAvailable()) {
          return;
      }
       $container = new \PluginFieldsContainer();
       self::$containersId = (int) $container->add([
           'label'        => 'PHPUnit achats',
           // Bloc en onglet : Fields n'accepte qu'un bloc « dom » par type d'élément, et l'instance
           // de test peut déjà en avoir un.
           'type'         => 'tab',
           'itemtypes'    => ['Computer'],
           'is_active'    => 1,
           'entities_id'  => 0,
           'is_recursive' => 1,
       ]);
      foreach ([['Bon de commande', 'text'], ['Sous garantie', 'yesno'], ['Fin de maintenance', 'date']] as [$label, $type]) {
          $field = new \PluginFieldsField();
          $field->getFromDB((int) $field->add([
              'label'                       => $label,
              'type'                        => $type,
              'plugin_fields_containers_id' => self::$containersId,
              'is_active'                   => 1,
          ]));
          self::$names[$label] = (string) $field->fields['name'];
      }
   }

   public static function tearDownAfterClass(): void {
      if (self::$containersId > 0) {
          (new \PluginFieldsContainer())->delete(['id' => self::$containersId], true);
      }
   }

   protected function setUp(): void {
       parent::setUp();
      if (self::$containersId === 0) {
          $this->markTestSkipped('Plugin Fields non installé.');
      }
   }

   private function computerWithValues(): \Computer {
       global $DB;

       $computer = $this->createTestComputer(0, 'PHPUNIT-PC-FIELDS');
       $classname = \PluginFieldsContainer::getClassname('Computer', $this->containerName());
       $DB->insert(getTableForItemType($classname), [
           'items_id'                    => $computer->getID(),
           'itemtype'                    => 'Computer',
           'plugin_fields_containers_id' => self::$containersId,
           self::$names['Bon de commande']    => 'BC-PHPUNIT-114',
           self::$names['Sous garantie']      => 1,
           self::$names['Fin de maintenance'] => '2027-12-31',
       ]);
       return $computer;
   }

   private function containerName(): string {
       $container = new \PluginFieldsContainer();
       $container->getFromDB(self::$containersId);
       return (string) $container->fields['name'];
   }

   private function selectContainers(array $ids): void {
       global $DB;

       $config = Config::getForEntity(0);
       $DB->update(Config::getTable(), ['fields_containers' => json_encode($ids)], ['id' => $config->getID()]);
   }

   public function testNothingIsReadUnlessTheContainerIsSelected(): void {
       $computer = $this->computerWithValues();

       $this->assertSame([], FieldsPluginValues::getValues('Computer', $computer->getID(), []));
       $this->assertSame([], FieldsPluginValues::getValues('Monitor', $computer->getID(), [self::$containersId]), 'autre type');

       $values = FieldsPluginValues::getValues('Computer', $computer->getID(), [self::$containersId]);
       $this->assertSame(
           // Oui/non dans la langue de la session (« Yes » en CI, « Oui » en français).
           ['BC-PHPUNIT-114', __('Yes'), '31/12/2027'],
           array_column($values, 'value')
       );
       $this->assertSame('Bon de commande', $values[0]['label']);
       $this->assertSame(self::$names['Bon de commande'], $values[0]['name']);
   }

   public function testThePdfShowsSelectedFieldsAndResolvesPlaceholders(): void {
       global $DB;

       $computer = $this->computerWithValues();
       $template = Template::getDefaultFor(Assetsign::TYPE_HANDOVER, 0);
       $this->assertNotNull($template);
       $sheet = new Assetsign();
       $sheet->getFromDB((int) $sheet->add([
           'entities_id'                   => 0,
           'itemtype'                      => 'Computer',
           'items_id'                      => $computer->getID(),
           'users_id'                      => 2,
           'type'                          => Assetsign::TYPE_HANDOVER,
           'status'                        => Assetsign::STATUS_PENDING,
           'plugin_assetsign_templates_id' => $template->getID(),
       ]));
       $DB->update(Template::getTable(), [
           'content'         => '<p>Commande {champ:' . self::$names['Bon de commande'] . '} - {champ:inconnu}</p>',
           'include_content' => 1,
       ], ['id' => $template->getID()]);

       $this->selectContainers([]);
       $html = (new HandoverPdfBuilder())->renderHtml($sheet);
       $this->assertStringNotContainsString('BC-PHPUNIT-114', $html, 'Bloc non retenu : rien sur la fiche.');

       $this->selectContainers([self::$containersId]);
       $html = (new HandoverPdfBuilder())->renderHtml($sheet);
       $this->assertStringContainsString('Fin de maintenance', $html);
       $this->assertStringContainsString('31/12/2027', $html);
       $this->assertStringContainsString('Commande BC-PHPUNIT-114', $html, 'Variable du gabarit remplacée.');
       $this->assertStringContainsString('{champ:inconnu}', $html, 'Une variable inconnue reste visible pour être corrigée.');
   }
}
