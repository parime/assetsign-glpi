<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Kit;

/**
 * Couvre le catalogue de kits d'accessoires reutilisables (cf. ROADMAP.md V3,
 * issue #83, docs/design/ADR-passeport-v1.md) : encodage JSON de accessories_id
 * (meme motif que ChecklistItem::movement_types), et surtout
 * Kit::computeCompleteness() — le coeur de la detection automatique de perte de
 * materiel au retour, volontairement une fonction PURE (aucun acces base) pour
 * rester testable sans fiche Assetsign reelle.
 */
class KitTest extends AssetsignTestCase
{
   public function testAddEncodesAccessoryIdsToJson(): void {
       $item = new Kit();
       $id = (int) $item->add([
           'entities_id'    => 0,
           'name'           => 'PHPUnit Kit Encodage',
           'is_active'      => 1,
           'accessories_id' => [3, 1, 2, 1],
       ]);
       $item->getFromDB($id);

       $this->assertSame('[3,1,2]', $item->fields['accessories_id']);
       $this->assertSame([3, 1, 2], $item->getExpectedAccessoryIds());
   }

   public function testDecodeAccessoryIdsHandlesInvalidOrEmptyJson(): void {
       $this->assertSame([], Kit::decodeAccessoryIds(''));
       $this->assertSame([], Kit::decodeAccessoryIds('not json'));
       $this->assertSame([1, 2], Kit::decodeAccessoryIds('[1,2]'));
   }

   public function testComputeCompletenessWhenEverythingCameBack(): void {
       $result = Kit::computeCompleteness([1, 2, 3], [1, 2, 3]);

       $this->assertSame(3, $result['expected_total']);
       $this->assertSame(3, $result['returned_count']);
       $this->assertSame([], $result['missing_ids']);
       $this->assertTrue($result['complete']);
       $this->assertSame('#2fb344', Kit::colorForCompleteness($result), 'Kit complet : badge vert attendu.');
   }

   public function testComputeCompletenessWhenOneAccessoryIsMissing(): void {
       $result = Kit::computeCompleteness([1, 2, 3], [1, 3]);

       $this->assertSame(3, $result['expected_total']);
       $this->assertSame(2, $result['returned_count']);
       $this->assertSame([2], $result['missing_ids']);
       $this->assertFalse($result['complete']);
       $this->assertSame('#f76707', Kit::colorForCompleteness($result), 'Manque partiellement : badge orange attendu.');
   }

   public function testComputeCompletenessWhenNothingCameBack(): void {
       $result = Kit::computeCompleteness([1, 2, 3], []);

       $this->assertSame(3, $result['expected_total']);
       $this->assertSame(0, $result['returned_count']);
       $this->assertSame([1, 2, 3], $result['missing_ids']);
       $this->assertFalse($result['complete']);
       $this->assertSame('#dc3545', Kit::colorForCompleteness($result), 'Rien de revenu : perte totale, badge rouge attendu.');
   }

   public function testComputeCompletenessIgnoresOrderAndDuplicates(): void {
       $result = Kit::computeCompleteness([1, 1, 2], [2, 1]);

       $this->assertSame(2, $result['expected_total']);
       $this->assertSame(2, $result['returned_count']);
       $this->assertTrue($result['complete']);
   }

   public function testComputeCompletenessIgnoresAccessoriesReturnedButNotExpected(): void {
       // Un accessoire rendu qui n'appartient pas au kit (ex: rajoute
       // manuellement) ne doit ni compter comme "revenu" pour le total du
       // kit, ni faire planter la comparaison.
       $result = Kit::computeCompleteness([1, 2], [1, 2, 99]);

       $this->assertSame(2, $result['expected_total']);
       $this->assertSame(2, $result['returned_count']);
       $this->assertTrue($result['complete']);
   }
}
