<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\DocumentReferenceFormat;
use PHPUnit\Framework\TestCase;

/**
 * Issue #273 : format de la reference de document (validation et rendu, sans base de donnees).
 */
final class DocumentReferenceFormatTest extends TestCase
{
   public function testEmptyFormatKeepsHistoricalBehaviour(): void {
       $this->assertTrue(DocumentReferenceFormat::isDefault(''));
       $this->assertTrue(DocumentReferenceFormat::isDefault('   '));
       $this->assertNull(DocumentReferenceFormat::validate(''));
   }

   public function testRendersEveryMarker(): void {
       $timestamp = (int) mktime(10, 0, 0, 3, 7, 2026);
       $this->assertSame(
           'RM-2026-000124',
           DocumentReferenceFormat::render('{prefixe}-{annee}-{seq}', 'RM', Assetsign::TYPE_HANDOVER, 124, 6, $timestamp, '')
       );
       $this->assertSame(
           'RES-20260307-42-AB23CD',
           DocumentReferenceFormat::render('{type}-{annee}{mois}{jour}-{seq}-{aleatoire}', '', Assetsign::TYPE_RETURN, 42, 1, $timestamp, 'AB23CD')
       );
       $this->assertSame('DON', DocumentReferenceFormat::typeCode(Assetsign::TYPE_DON));
       $this->assertSame('VEN', DocumentReferenceFormat::typeCode(Assetsign::TYPE_VENTE));
       $this->assertSame('DES', DocumentReferenceFormat::typeCode(Assetsign::TYPE_DESTRUCTION));
   }

   public function testFormatMustContainAUniquePart(): void {
       $this->assertSame(DocumentReferenceFormat::ERROR_NO_UNIQUE_PART, DocumentReferenceFormat::validate('{prefixe}-{annee}'));
       $this->assertNull(DocumentReferenceFormat::validate('{prefixe}-{seq}', 'RM'));
       $this->assertNull(DocumentReferenceFormat::validate('DOC-{aleatoire}'));
   }

   /**
    * La reference finit sur le PDF, dans le commentaire du document et dans les listes : ni balise,
    * ni chemin, ni formule de tableur, ni marqueur inconnu.
    */
   public function testRejectsInjectionAttempts(): void {
       foreach (['<b>{seq}</b>', '{seq}"onload=x', '../{seq}', '{seq}/x', 'a\\{seq}', '={seq}', '+{seq}', '@{seq}', '{seq} x', 'É{seq}', '{seq', '{seq}{x}'] as $format) {
           $this->assertNotNull(DocumentReferenceFormat::validate($format), $format);
       }
       $this->assertSame(DocumentReferenceFormat::ERROR_UNKNOWN_MARKER, DocumentReferenceFormat::validate('{seq}-{id}'));
       $this->assertSame(DocumentReferenceFormat::ERROR_LENGTH, DocumentReferenceFormat::validate(str_repeat('A', 65) . '{seq}'));
       foreach (['<svg>', 'R/M', '-RM', '=1', 'Ré', str_repeat('A', 21)] as $prefix) {
           $this->assertSame(DocumentReferenceFormat::ERROR_PREFIX, DocumentReferenceFormat::validate('{prefixe}{seq}', $prefix), $prefix);
       }
       $this->assertTrue(DocumentReferenceFormat::isValidPrefix(''));
       $this->assertTrue(DocumentReferenceFormat::isValidPrefix('RM_2'));
   }

   public function testRenderNeverProducesUnsafeCharactersNorLeadingDash(): void {
       // Prefixe vide devant un tiret : la reference ne commence jamais par un signe de formule.
       $this->assertSame('000007', DocumentReferenceFormat::render('{prefixe}-{seq}', '', 0, 7, 6, time(), ''));
       // Valeur ecrite directement en base, hors validation : filtree au rendu.
       $this->assertSame('svgonloadalert1-1', DocumentReferenceFormat::render('{prefixe}-{seq}', '<svg/onload=alert(1)>', 0, 1, 1, time(), ''));
   }

   public function testYearlyResetRequiresTheYear(): void {
       $this->assertSame(DocumentReferenceFormat::ERROR_YEARLY_RESET_WITHOUT_YEAR, DocumentReferenceFormat::validate('{prefixe}-{seq}', 'RM', true));
       $this->assertNull(DocumentReferenceFormat::validate('{prefixe}-{annee}-{seq}', 'RM', true));
       $this->assertSame(2026, DocumentReferenceFormat::period(true, (int) mktime(0, 0, 0, 1, 1, 2026)));
       $this->assertSame(0, DocumentReferenceFormat::period(false, (int) mktime(0, 0, 0, 1, 1, 2026)));
   }

   public function testSeqDigitsAndRandomPart(): void {
       $this->assertSame(1, DocumentReferenceFormat::normalizeSeqDigits(0));
       $this->assertSame(12, DocumentReferenceFormat::normalizeSeqDigits(40));
       $this->assertSame('1234567', DocumentReferenceFormat::render('{seq}', '', 0, 1234567, 3, time(), ''), 'Le numero n\'est jamais tronque.');
       $random = DocumentReferenceFormat::randomPart();
       $this->assertMatchesRegularExpression('/^[' . DocumentReferenceFormat::RANDOM_ALPHABET . ']{' . DocumentReferenceFormat::RANDOM_LENGTH . '}$/', $random);
   }
}
