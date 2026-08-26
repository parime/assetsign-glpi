<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\ResidualValueFormController;
use GlpiPlugin\Assetsign\ResidualValue;
use InvalidArgumentException;

/**
 * Couvre le dispatch de front/residualvalue.form.php (saisie manuelle de la
 * valeur résiduelle, issue #77) désormais extrait dans
 * ResidualValueFormController — même motivation que
 * MaintenanceFormControllerTest/MovementFormControllerTest.
 */
class ResidualValueFormControllerTest extends AssetsignTestCase
{
    public function testRunStoresManualValue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit ResidualValueFormController Store');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC ResidualValueFormController Store');

        (new ResidualValueFormController())->run([
            'itemtype'     => 'Computer',
            'items_id'     => $computer->getID(),
            'manual_value' => '199,90',
        ]);

        $residual = ResidualValue::getForItem('Computer', $computer->getID());
        $this->assertNotNull($residual);
        $this->assertSame(199.90, (float) $residual->fields['manual_value']);
    }

    public function testRunWithEmptyValueClearsBackToAutomatic(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit ResidualValueFormController Clear');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC ResidualValueFormController Clear');
        ResidualValue::upsertForItem('Computer', $computer->getID(), 50.0);

        (new ResidualValueFormController())->run([
            'itemtype'     => 'Computer',
            'items_id'     => $computer->getID(),
            'manual_value' => '',
        ]);

        $residual = ResidualValue::getForItem('Computer', $computer->getID());
        $this->assertNotNull($residual);
        $this->assertNull($residual->fields['manual_value']);
    }

    public function testRunRejectsNegativeValue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit ResidualValueFormController Negative');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC ResidualValueFormController Negative');

        $this->expectException(InvalidArgumentException::class);
        (new ResidualValueFormController())->run([
            'itemtype'     => 'Computer',
            'items_id'     => $computer->getID(),
            'manual_value' => '-10',
        ]);
    }

    public function testRunThrowsForInvalidItemtype(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ResidualValueFormController())->run([
            'itemtype'     => 'NotARealClass',
            'items_id'     => 1,
            'manual_value' => '10',
        ]);
    }

    public function testRunThrowsWhenItemNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ResidualValueFormController())->run([
            'itemtype'     => 'Computer',
            'items_id'     => 999999999,
            'manual_value' => '10',
        ]);
    }
}
