<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\FieldsPluginLogic;
use PHPUnit\Framework\TestCase;

/**
 * Issue #229 : règles pures des champs du plugin Fields sur la fiche (aucune base de données).
 */
final class FieldsPluginLogicTest extends TestCase
{
   private const YES_NO = ['yes' => 'Oui', 'no' => 'Non'];

   public function testOnlyExistingContainersAreKept(): void {
       $this->assertSame('[2,5]', FieldsPluginLogic::normalizeContainerIds(['5', '2', '9', '2', 'x'], [2, 5, 7]));
       $this->assertSame('[]', FieldsPluginLogic::normalizeContainerIds(null, [2, 5]), 'rien coché');
       $this->assertSame('[]', FieldsPluginLogic::normalizeContainerIds('5', [5]), 'valeur non tableau');
       $this->assertSame([2, 5], FieldsPluginLogic::decodeContainerIds('[2,5]'));
       $this->assertSame([], FieldsPluginLogic::decodeContainerIds(null));
       $this->assertSame([], FieldsPluginLogic::decodeContainerIds('pas du json'));
   }

   public function testScalarValuesAreReadable(): void {
       $this->assertSame('BC-2026-114', FieldsPluginLogic::formatScalar('text', 'BC-2026-114', self::YES_NO));
       $this->assertSame("Ligne 1\nLigne 2", FieldsPluginLogic::formatScalar('richtext', '<p>Ligne 1</p><p>Ligne&nbsp;2</p>', self::YES_NO));
       $this->assertSame('Oui', FieldsPluginLogic::formatScalar('yesno', '1', self::YES_NO));
       $this->assertSame('Non', FieldsPluginLogic::formatScalar('yesno', 0, self::YES_NO));
       $this->assertSame('31/12/2027', FieldsPluginLogic::formatScalar('date', '2027-12-31', self::YES_NO));
       $this->assertSame('31/12/2027 08:30', FieldsPluginLogic::formatScalar('datetime', '2027-12-31 08:30:00', self::YES_NO));
       $this->assertSame('', FieldsPluginLogic::formatScalar('text', '', self::YES_NO), 'non renseigné');
       $this->assertSame('', FieldsPluginLogic::formatScalar('yesno', null, self::YES_NO), 'non renseigné');
       $this->assertNull(FieldsPluginLogic::formatScalar('dropdown', '3', self::YES_NO), 'résolu ailleurs');
   }

   public function testPlaceholderKey(): void {
       $this->assertSame('champ:bon_de_commande', FieldsPluginLogic::placeholderKey('bon_de_commande'));
   }
}
