<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\ResidualValue;

/**
 * Couvre la table dédiée de saisie manuelle de la valeur résiduelle (issue
 * #77) : 1-vers-1 avec n'importe quel matériel géré par le plugin
 * (`itemtype`/`items_id`, comme Movement - pas comme VenteDetails), une
 * valeur nulle repassant explicitement en calcul automatique.
 */
class ResidualValueTest extends AssetsignTestCase
{
    public function testGetForItemReturnsNullWhenNoRowExists(): void
    {
        $this->assertNull(ResidualValue::getForItem('Computer', 999999));
    }

    public function testUpsertForItemCreatesThenUpdatesTheSameRow(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit ResidualValue Upsert');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC ResidualValue Upsert');

        ResidualValue::upsertForItem('Computer', $computer->getID(), 123.45);
        $residual = ResidualValue::getForItem('Computer', $computer->getID());
        $this->assertNotNull($residual);
        $this->assertSame(123.45, (float) $residual->fields['manual_value']);
        $firstId = $residual->getID();

        // Une deuxieme saisie sur le meme materiel met a jour la MEME ligne
        // (unicite itemtype/items_id), jamais une deuxieme ligne.
        ResidualValue::upsertForItem('Computer', $computer->getID(), 678.90);
        $updated = ResidualValue::getForItem('Computer', $computer->getID());
        $this->assertSame($firstId, $updated->getID());
        $this->assertSame(678.90, (float) $updated->fields['manual_value']);
    }

    public function testUpsertForItemWithNullClearsAnExistingManualValue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit ResidualValue Clear');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC ResidualValue Clear');
        ResidualValue::upsertForItem('Computer', $computer->getID(), 50.0);

        ResidualValue::upsertForItem('Computer', $computer->getID(), null);

        $residual = ResidualValue::getForItem('Computer', $computer->getID());
        $this->assertNotNull($residual); // la ligne reste (cf. docblock upsertForItem()), seule la valeur est videe.
        $this->assertNull($residual->fields['manual_value']);
    }

    public function testUpsertForItemWithNullOnAMaterialNeverSeenBeforeDoesNothing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit ResidualValue Null Noop');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC ResidualValue Null Noop');

        ResidualValue::upsertForItem('Computer', $computer->getID(), null);

        $this->assertNull(ResidualValue::getForItem('Computer', $computer->getID()));
    }
}
