<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\ChecklistItem;

/**
 * Couvre le catalogue de checklists qualite reutilisables sur les mouvements
 * Assetsign (cf. ROADMAP.md V1, issue #74, docs/design/ADR-passeport-v1.md) :
 * encodage JSON de movement_types, filtrage par type de mouvement applicable,
 * meme motif que MaintenanceChecklistItem mais VOLONTAIREMENT une classe/table
 * separee.
 */
class ChecklistItemTest extends AssetsignTestCase
{
    public function testParseOptionsFiltersEmptyLines(): void
    {
        $options = ChecklistItem::parseOptions("Bon\n\nMoyen\r\nMauvais\n  \n");

        $this->assertSame(['Bon', 'Moyen', 'Mauvais'], $options);
    }

    public function testAddEncodesMovementTypesToJson(): void
    {
        $item = new ChecklistItem();
        $id = (int) $item->add([
            'entities_id'    => 0,
            'name'           => 'PHPUnit Checklist Accessoires',
            'type'           => ChecklistItem::TYPE_CHECKBOX,
            'is_active'      => 1,
            'movement_types' => [Assetsign::TYPE_HANDOVER, Assetsign::TYPE_RETURN],
        ]);
        $item->getFromDB($id);

        $this->assertSame('[0,1]', $item->fields['movement_types']);
        $this->assertSame([0, 1], $item->getMovementTypes());
    }

    public function testGetActiveItemsForMovementTypeFiltersByApplicability(): void
    {
        $handoverOnly = new ChecklistItem();
        $handoverOnly->add([
            'entities_id'    => 0,
            'name'           => 'PHPUnit Checklist Handover Only',
            'type'           => ChecklistItem::TYPE_CHECKBOX,
            'is_active'      => 1,
            'movement_types' => [Assetsign::TYPE_HANDOVER],
        ]);

        $returnOnly = new ChecklistItem();
        $returnOnly->add([
            'entities_id'    => 0,
            'name'           => 'PHPUnit Checklist Return Only',
            'type'           => ChecklistItem::TYPE_TEXT,
            'is_active'      => 1,
            'movement_types' => [Assetsign::TYPE_RETURN],
        ]);

        $inactive = new ChecklistItem();
        $inactive->add([
            'entities_id'    => 0,
            'name'           => 'PHPUnit Checklist Inactive',
            'type'           => ChecklistItem::TYPE_CHECKBOX,
            'is_active'      => 0,
            'movement_types' => [Assetsign::TYPE_HANDOVER],
        ]);

        $forHandover = ChecklistItem::getActiveItemsForMovementType(Assetsign::TYPE_HANDOVER);
        $this->assertArrayHasKey($handoverOnly->getID(), $forHandover);
        $this->assertArrayNotHasKey($returnOnly->getID(), $forHandover);
        $this->assertArrayNotHasKey($inactive->getID(), $forHandover, 'Un point desactive ne doit jamais apparaitre dans le catalogue actif.');

        $forReturn = ChecklistItem::getActiveItemsForMovementType(Assetsign::TYPE_RETURN);
        $this->assertArrayHasKey($returnOnly->getID(), $forReturn);
        $this->assertArrayNotHasKey($handoverOnly->getID(), $forReturn);
    }

    public function testGetActiveItemsForMovementTypeExposesTypeAndOptions(): void
    {
        $select = new ChecklistItem();
        $select->add([
            'entities_id'    => 0,
            'name'           => 'PHPUnit Checklist Select',
            'type'           => ChecklistItem::TYPE_SELECT,
            'options'        => "Bon\nMoyen\nMauvais",
            'is_active'      => 1,
            'movement_types' => [Assetsign::TYPE_VENTE],
        ]);

        $forVente = ChecklistItem::getActiveItemsForMovementType(Assetsign::TYPE_VENTE);
        $this->assertSame(ChecklistItem::TYPE_SELECT, $forVente[$select->getID()]['type']);
        $this->assertSame(['Bon', 'Moyen', 'Mauvais'], $forVente[$select->getID()]['options']);
    }
}
