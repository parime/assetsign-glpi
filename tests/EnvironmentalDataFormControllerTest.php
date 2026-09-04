<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\EnvironmentalDataFormController;
use GlpiPlugin\Assetsign\EnvironmentalData;
use InvalidArgumentException;

/**
 * Couvre le dispatch de front/environmentaldata.form.php (saisie manuelle de
 * l'empreinte environnementale, issue #80) - même motivation/structure que
 * ResidualValueFormControllerTest.
 */
class EnvironmentalDataFormControllerTest extends AssetsignTestCase
{
    public function testRunStoresManualValue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit EnvironmentalDataFormController Store');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC EnvironmentalDataFormController Store');

        (new EnvironmentalDataFormController())->run([
            'itemtype'                        => 'Computer',
            'items_id'                        => $computer->getID(),
            'carbon_footprint_manufacturing'  => '181,50',
            'source'                          => EnvironmentalData::SOURCE_MANUFACTURER,
            'confidence_level'                => EnvironmentalData::CONFIDENCE_HIGH,
        ]);

        $data = EnvironmentalData::getForItem('Computer', $computer->getID());
        $this->assertNotNull($data);
        $this->assertSame(181.50, (float) $data->fields['carbon_footprint_manufacturing']);
        $this->assertSame(EnvironmentalData::SOURCE_MANUFACTURER, $data->fields['source']);
        $this->assertSame(EnvironmentalData::CONFIDENCE_HIGH, $data->fields['confidence_level']);
    }

    public function testRunWithEmptyValueClearsExistingData(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit EnvironmentalDataFormController Clear');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC EnvironmentalDataFormController Clear');
        EnvironmentalData::upsertForItem('Computer', $computer->getID(), 50.0, EnvironmentalData::SOURCE_MANUAL, EnvironmentalData::CONFIDENCE_LOW);

        (new EnvironmentalDataFormController())->run([
            'itemtype'                       => 'Computer',
            'items_id'                       => $computer->getID(),
            'carbon_footprint_manufacturing' => '',
        ]);

        $data = EnvironmentalData::getForItem('Computer', $computer->getID());
        $this->assertNotNull($data);
        $this->assertNull($data->fields['carbon_footprint_manufacturing']);
        $this->assertNull($data->fields['source']);
        $this->assertNull($data->fields['confidence_level']);
    }

    public function testRunRejectsNegativeValue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit EnvironmentalDataFormController Negative');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC EnvironmentalDataFormController Negative');

        $this->expectException(InvalidArgumentException::class);
        (new EnvironmentalDataFormController())->run([
            'itemtype'                       => 'Computer',
            'items_id'                       => $computer->getID(),
            'carbon_footprint_manufacturing' => '-10',
            'source'                         => EnvironmentalData::SOURCE_MANUAL,
            'confidence_level'               => EnvironmentalData::CONFIDENCE_LOW,
        ]);
    }

    public function testRunRejectsInvalidSource(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit EnvironmentalDataFormController BadSource');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC EnvironmentalDataFormController BadSource');

        $this->expectException(InvalidArgumentException::class);
        (new EnvironmentalDataFormController())->run([
            'itemtype'                       => 'Computer',
            'items_id'                       => $computer->getID(),
            'carbon_footprint_manufacturing' => '10',
            'source'                         => 'not-a-real-source',
            'confidence_level'               => EnvironmentalData::CONFIDENCE_LOW,
        ]);
    }

    public function testRunRejectsInvalidConfidenceLevel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit EnvironmentalDataFormController BadConfidence');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC EnvironmentalDataFormController BadConfidence');

        $this->expectException(InvalidArgumentException::class);
        (new EnvironmentalDataFormController())->run([
            'itemtype'                       => 'Computer',
            'items_id'                       => $computer->getID(),
            'carbon_footprint_manufacturing' => '10',
            'source'                         => EnvironmentalData::SOURCE_MANUAL,
            'confidence_level'               => 'not-a-real-level',
        ]);
    }

    public function testRunThrowsForInvalidItemtype(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EnvironmentalDataFormController())->run([
            'itemtype' => 'NotARealClass',
            'items_id' => 1,
            'carbon_footprint_manufacturing' => '10',
        ]);
    }

    public function testRunThrowsWhenItemNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EnvironmentalDataFormController())->run([
            'itemtype' => 'Computer',
            'items_id' => 999999999,
            'carbon_footprint_manufacturing' => '10',
        ]);
    }
}
