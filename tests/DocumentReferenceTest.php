<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\DocumentReference;
use GlpiPlugin\Assetsign\Pdf\HandoverPdfBuilder;

/**
 * Issue #273 : reference de document reservee a la creation de la fiche (compteur, registre).
 */
final class DocumentReferenceTest extends AssetsignTestCase
{
   private function configureReference(int $entityId, string $format, string $prefix = 'RM', array $extra = []): void {
       Config::upsertForEntity($entityId, array_merge([
           'reference_format'       => $format,
           'reference_prefix'       => $prefix,
           'reference_seq_digits'   => 6,
           'reference_yearly_reset' => 0,
       ], $extra));
   }

   public function testDefaultFormatKeepsTheInternalIdAsReference(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Reference Defaut');
       $assetsign = $this->createBareAssetsign($entityId);

       $this->assertSame((string) $assetsign->getID(), $assetsign->fields['reference']);
       $this->assertSame('Référence : ' . $assetsign->getID(), $assetsign->getDocumentComment());
   }

   public function testSequentialReferencesAreNeverDuplicated(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Reference Sequence');
       $this->configureReference($entityId, '{prefixe}-{annee}-{seq}');
       $year = date('Y');

       $references = [];
      for ($i = 0; $i < 5; $i++) {
          $references[] = $this->createBareAssetsign($entityId)->fields['reference'];
      }

       $this->assertSame(
           ["RM-$year-000001", "RM-$year-000002", "RM-$year-000003", "RM-$year-000004", "RM-$year-000005"],
           $references
       );
       $this->assertSame(6, DocumentReference::peekNextNumber($entityId, 0));
       // Chaque numero emis est au registre, avec sa fiche.
      foreach ($references as $reference) {
          $this->assertNotNull(DocumentReference::findInRegistry($reference), $reference);
      }
   }

   public function testLoweringTheNextNumberSkipsReferencesAlreadyIssued(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Reference Abaissee');
       $this->configureReference($entityId, 'X{seq}', '');
       $first = $this->createBareAssetsign($entityId)->fields['reference'];
       $second = $this->createBareAssetsign($entityId)->fields['reference'];

       DocumentReference::setNextNumber($entityId, 0, 1);
       $third = $this->createBareAssetsign($entityId)->fields['reference'];

       $this->assertSame(['X000001', 'X000002', 'X000003'], [$first, $second, $third]);
   }

   /**
    * Deux signatures simultanees lisent le meme compteur : seule la premiere mise a jour
    * conditionnelle reussit, l'autre doit relire et prendre le numero suivant.
    */
   public function testCompareAndSwapRefusesAStaleCounterValue(): void {
       global $DB;

       $entityId = $this->createTestEntity(0, 'PHPUnit Reference Concurrence');
       DocumentReference::setNextNumber($entityId, 0, 10);
       $counter = $DB->request(['FROM' => DocumentReference::COUNTERS_TABLE, 'WHERE' => ['entities_id' => $entityId]])->current();

       $this->assertTrue(DocumentReference::claim((int) $counter['id'], 10), 'Premiere signature : numero 10.');
       $this->assertFalse(DocumentReference::claim((int) $counter['id'], 10), 'Seconde signature avec la meme lecture : refusee.');
       $this->assertSame(11, DocumentReference::peekNextNumber($entityId, 0));
   }

   public function testYearlyResetUsesOneCounterPerYear(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Reference Annuelle');
       $this->configureReference($entityId, '{annee}-{seq}', '', ['reference_yearly_reset' => 1]);
       $config = Config::getForEntity($entityId);

       $ref2025 = DocumentReference::reserve($config, 0, 1, 'Computer', 1, $entityId, (int) mktime(12, 0, 0, 6, 1, 2025));
       $ref2026 = DocumentReference::reserve($config, 0, 2, 'Computer', 1, $entityId, (int) mktime(12, 0, 0, 6, 1, 2026));
       $ref2026b = DocumentReference::reserve($config, 0, 3, 'Computer', 1, $entityId, (int) mktime(12, 0, 0, 7, 1, 2026));

       $this->assertSame(['2025-000001', '2026-000001', '2026-000002'], [$ref2025, $ref2026, $ref2026b]);
   }

   public function testRandomReferencesAreUnique(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Reference Aleatoire');
       $this->configureReference($entityId, '{type}-{aleatoire}', '');

       $references = [];
      for ($i = 0; $i < 10; $i++) {
          $references[] = $this->createBareAssetsign($entityId, Assetsign::TYPE_RETURN)->fields['reference'];
      }
       $this->assertCount(10, array_unique($references));
       $this->assertMatchesRegularExpression('/^RES-[A-Z0-9]{6}$/', $references[0]);
   }

   public function testInvalidFormatIsRefusedAndPreviousSettingKept(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Reference Refusee');
       $this->configureReference($entityId, '{prefixe}-{seq}');
       $this->configureReference($entityId, '<script>{seq}</script>');
       $this->configureReference($entityId, '{prefixe}-{seq}', '../etc');

       $config = Config::getForEntity($entityId);
       $this->assertSame('{prefixe}-{seq}', $config->fields['reference_format']);
       $this->assertSame('RM', $config->fields['reference_prefix']);
   }

   public function testNextNumberFromTheConfigurationForm(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Reference Prochain');
       $this->configureReference($entityId, '{seq}', '', ['reference_next_number' => 124, 'reference_next_number_orig' => 1]);
       $this->assertSame(124, DocumentReference::peekNextNumber($entityId, 0));

       // Formulaire renvoye sans modification du numero : le compteur, qui a avance, est conserve.
       $this->assertSame('000124', $this->createBareAssetsign($entityId)->fields['reference']);
       $this->configureReference($entityId, '{seq}', '', ['reference_next_number' => 124, 'reference_next_number_orig' => 124]);
       $this->assertSame(125, DocumentReference::peekNextNumber($entityId, 0));
       $this->assertSame('000125', Config::getForEntity($entityId)->getReferencePreview()['example']);
   }

   public function testReferenceCannotBeChangedAfterCreation(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit Reference Figee');
       $this->configureReference($entityId, 'F{seq}', '');
       $assetsign = $this->createBareAssetsign($entityId);

       $assetsign->update(['id' => $assetsign->getID(), 'reference' => 'AUTRE', 'observations' => 'x']);
       $assetsign->getFromDB($assetsign->getID());

       $this->assertSame('F000001', $assetsign->fields['reference']);
   }

   public function testReferenceAppearsOnPdfDocumentAndAssetHistory(): void {
       global $DB;

       $entityId = $this->createTestEntity(0, 'PHPUnit Reference Partout');
       $this->configureReference($entityId, '{prefixe}-{seq}', 'AUD');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC Reference');

       $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
       $this->assertSame('AUD-000001', $assetsign->fields['reference']);

       $this->assertStringContainsString('AUD-000001', (new HandoverPdfBuilder())->renderHtml($assetsign));

       $document = new \Document();
       $this->assertTrue($document->getFromDB((int) $assetsign->fields['document_id_unsigned']));
       $this->assertStringContainsString('AUD-000001', (string) $document->fields['comment']);

       $history = $DB->request([
           'FROM'  => 'glpi_logs',
           'WHERE' => ['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'new_value' => ['LIKE', '%AUD-000001%']],
       ]);
       $this->assertCount(1, $history);
   }
}
