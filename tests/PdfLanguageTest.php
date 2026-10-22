<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Maintenance;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;
use GlpiPlugin\Assetsign\Pdf\MaintenancePdfBuilder;
use GlpiPlugin\Assetsign\Pdf\PdfLanguage;

/**
 * Issue #208 : chaque PDF est rendu dans la langue de la personne a qui il appartient, quelle
 * que soit la langue de la session qui declenche sa generation, et cette session retrouve
 * ensuite ses propres traductions.
 */
final class PdfLanguageTest extends AssetsignTestCase
{
   private function createHandover(int $beneficiaryId): Assetsign {
       $entityId = $this->createTestEntity(0, 'PHPUnit PdfLanguage ' . random_int(100000, 999999));
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC PdfLanguage');
       $assetsign = new Assetsign();
       $assetsign->getFromDB((int) $assetsign->add([
           'entities_id'   => $entityId,
           'itemtype'      => 'Computer',
           'items_id'      => $computer->getID(),
           'users_id'      => $beneficiaryId,
           'users_id_tech' => 2,
           'type'          => Assetsign::TYPE_HANDOVER,
           'status'        => Assetsign::STATUS_SIGNED,
       ]));
       return $assetsign;
   }

   public function testHandoverPdfIsRenderedInTheBeneficiaryLanguage(): void {
       $sessionLabel = __('Fiche d\'attribution de matériel', 'assetsign');

       $german = $this->createHandover($this->createTestUser('Hans', 'Muller', ['language' => 'de_DE']));
       $html = (new HandoverPdfBuilder())->renderHtml($german);
       $this->assertStringContainsString('<html lang="de">', $html);
       $this->assertStringContainsString('Formular zur Zuweisung von Ausstattung', $html);
       $this->assertStringContainsString('Computer', $html, 'Type de materiel traduit lui aussi.');
       $this->assertStringNotContainsString('Fiche d&#039;attribution', $html);

       $french = $this->createHandover($this->createTestUser('Jeanne', 'Martin', ['language' => 'fr_FR']));
       $html = (new HandoverPdfBuilder())->renderHtml($french);
       $this->assertStringContainsString('<html lang="fr">', $html);
       $this->assertStringContainsString('Fiche d&#039;attribution de matériel', $html);
       $this->assertStringContainsString('Ordinateur', $html);

       $this->assertSame($sessionLabel, __('Fiche d\'attribution de matériel', 'assetsign'), 'La session doit retrouver sa langue apres le rendu.');
   }

   public function testBeneficiaryWithoutPreferenceGetsTheDefaultLanguage(): void {
       global $CFG_GLPI;
       $default = $CFG_GLPI['language'];
       $CFG_GLPI['language'] = 'it_IT';
      try {
          $html = (new HandoverPdfBuilder())->renderHtml($this->createHandover($this->createTestUser('Sans', 'Langue')));
      } finally {
          $CFG_GLPI['language'] = $default;
      }
       $this->assertStringContainsString('Scheda di attribuzione di materiale', $html);
   }

   public function testMaintenancePdfIsRenderedInTheTechnicianLanguage(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit PdfLanguage Maintenance');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC PdfLanguage Maintenance');
       $maintenance = new Maintenance();
       $maintenance->getFromDB(Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], ''));
       $maintenance->update(['id' => $maintenance->getID(), 'users_id_tech' => $this->createTestUser('Ana', 'Lopez', ['language' => 'es_ES'])]);
       $maintenance->getFromDB($maintenance->getID());

       $html = (new MaintenancePdfBuilder())->renderHtml($maintenance);

       $this->assertStringContainsString('Ficha de mantenimiento', $html);
       $this->assertStringContainsString('Ordenador', $html);
   }

   public function testRunRestoresTheSessionTranslationsEvenWhenRenderingFails(): void {
       global $TRANSLATE;
       $before = $TRANSLATE;
       $sessionLanguage = $TRANSLATE->getLocale();
       $other = $sessionLanguage === 'de_DE' ? 'it_IT' : 'de_DE';

       $this->assertSame($other, PdfLanguage::run($other, static fn (): string => $GLOBALS['TRANSLATE']->getLocale()));
      try {
          PdfLanguage::run($other, static function (): void {
              throw new \RuntimeException('rendu en echec');
          });
          $this->fail('L\'exception du rendu doit remonter.');
      } catch (\RuntimeException $e) {
          $this->assertSame('rendu en echec', $e->getMessage());
      }

       $this->assertSame($before, $TRANSLATE);
       $this->assertSame($sessionLanguage, $TRANSLATE->getLocale());
   }
}
