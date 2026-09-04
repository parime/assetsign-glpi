<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\EnvironmentalData;

/**
 * Couvre la table dédiée du passeport environnemental (issue #80) : 1-vers-1
 * avec n'importe quel matériel géré par le plugin (`itemtype`/`items_id`,
 * comme Movement/ResidualValue), les 3 champs (empreinte/source/confiance)
 * sont toujours écrits ou effacés ensemble - jamais un état incohérent type
 * "source renseignée mais aucune empreinte".
 */
class EnvironmentalDataTest extends AssetsignTestCase
{
    public function testGetForItemReturnsNullWhenNoRowExists(): void
    {
        $this->assertNull(EnvironmentalData::getForItem('Computer', 999999));
    }

    public function testUpsertForItemCreatesThenUpdatesTheSameRow(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit EnvironmentalData Upsert');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC EnvironmentalData Upsert');

        EnvironmentalData::upsertForItem(
            'Computer',
            $computer->getID(),
            181.0,
            EnvironmentalData::SOURCE_MANUFACTURER,
            EnvironmentalData::CONFIDENCE_HIGH
        );
        $data = EnvironmentalData::getForItem('Computer', $computer->getID());
        $this->assertNotNull($data);
        $this->assertSame(181.0, (float) $data->fields['carbon_footprint_manufacturing']);
        $this->assertSame(EnvironmentalData::SOURCE_MANUFACTURER, $data->fields['source']);
        $this->assertSame(EnvironmentalData::CONFIDENCE_HIGH, $data->fields['confidence_level']);
        $firstId = $data->getID();

        // Une deuxieme saisie sur le meme materiel met a jour la MEME ligne
        // (unicite itemtype/items_id), jamais une deuxieme ligne.
        EnvironmentalData::upsertForItem(
            'Computer',
            $computer->getID(),
            210.5,
            EnvironmentalData::SOURCE_MANUAL,
            EnvironmentalData::CONFIDENCE_LOW
        );
        $updated = EnvironmentalData::getForItem('Computer', $computer->getID());
        $this->assertSame($firstId, $updated->getID());
        $this->assertSame(210.5, (float) $updated->fields['carbon_footprint_manufacturing']);
        $this->assertSame(EnvironmentalData::SOURCE_MANUAL, $updated->fields['source']);
        $this->assertSame(EnvironmentalData::CONFIDENCE_LOW, $updated->fields['confidence_level']);
    }

    public function testUpsertForItemWithNullValueClearsAllThreeFieldsTogether(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit EnvironmentalData Clear');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC EnvironmentalData Clear');
        EnvironmentalData::upsertForItem(
            'Computer',
            $computer->getID(),
            50.0,
            EnvironmentalData::SOURCE_EXTERNAL_API,
            EnvironmentalData::CONFIDENCE_MEDIUM
        );

        EnvironmentalData::upsertForItem('Computer', $computer->getID(), null, null, null);

        $data = EnvironmentalData::getForItem('Computer', $computer->getID());
        $this->assertNotNull($data); // la ligne reste (cf. docblock upsertForItem()), seules les valeurs sont videes.
        $this->assertNull($data->fields['carbon_footprint_manufacturing']);
        $this->assertNull($data->fields['source']);
        $this->assertNull($data->fields['confidence_level']);
    }

    public function testUpsertForItemWithNullOnAMaterialNeverSeenBeforeDoesNothing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit EnvironmentalData Null Noop');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC EnvironmentalData Null Noop');

        EnvironmentalData::upsertForItem('Computer', $computer->getID(), null, null, null);

        $this->assertNull(EnvironmentalData::getForItem('Computer', $computer->getID()));
    }

    public function testGetConfidenceColorHasASensibleFallbackForUnknownValues(): void
    {
        $this->assertNotEmpty(EnvironmentalData::getConfidenceColor(null));
        $this->assertNotEmpty(EnvironmentalData::getConfidenceColor('not-a-real-level'));
    }
}
