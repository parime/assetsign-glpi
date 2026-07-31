<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Maintenance;
use GlpiPlugin\Remise\MaintenanceChecklistItem;

/**
 * Couvre la checklist de maintenance a types multiples (case a cocher / texte
 * libre / menu deroulant), ajoutee sans suite automatisee jusqu'ici (verifiee
 * uniquement via des scripts Docker manuels, cf. historique).
 */
class MaintenanceTest extends RemiseTestCase
{
    public function testGetActiveChecklistItemsReturnsTypeAndOptions(): void
    {
        $checkboxId = $this->createChecklistItem('PHPUnit Checkbox', MaintenanceChecklistItem::TYPE_CHECKBOX);
        $textId = $this->createChecklistItem('PHPUnit Texte', MaintenanceChecklistItem::TYPE_TEXT);
        $selectId = $this->createChecklistItem('PHPUnit Select', MaintenanceChecklistItem::TYPE_SELECT, "Bon\nMoyen\nMauvais");

        $items = Maintenance::getActiveChecklistItems();

        $this->assertSame(MaintenanceChecklistItem::TYPE_CHECKBOX, $items[$checkboxId]['type']);
        $this->assertSame(MaintenanceChecklistItem::TYPE_TEXT, $items[$textId]['type']);
        $this->assertSame(MaintenanceChecklistItem::TYPE_SELECT, $items[$selectId]['type']);
        $this->assertSame(['Bon', 'Moyen', 'Mauvais'], $items[$selectId]['options']);
        $this->assertSame([], $items[$checkboxId]['options'], "Un point sans options n'en renvoie aucune.");
    }

    public function testInactiveChecklistItemIsExcludedFromActiveList(): void
    {
        $item = new MaintenanceChecklistItem();
        $id = (int) $item->add([
            'entities_id' => 0,
            'name'        => 'PHPUnit Inactif',
            'is_active'   => 0,
            'type'        => MaintenanceChecklistItem::TYPE_CHECKBOX,
        ]);

        $this->assertArrayNotHasKey($id, Maintenance::getActiveChecklistItems());
    }

    public function testCreateWithChecklistPersistsAValuePerType(): void
    {
        $checkboxId = $this->createChecklistItem('PHPUnit Checkbox Create', MaintenanceChecklistItem::TYPE_CHECKBOX);
        $textId = $this->createChecklistItem('PHPUnit Texte Create', MaintenanceChecklistItem::TYPE_TEXT);
        $selectId = $this->createChecklistItem('PHPUnit Select Create', MaintenanceChecklistItem::TYPE_SELECT, "Bon\nMoyen\nMauvais");

        $entityId = $this->createTestEntity(0, 'PHPUnit Maintenance Create');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Maintenance');

        $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [
            $checkboxId => '1',
            $textId     => 'Ecran fissuré',
            $selectId   => 'Moyen',
        ], 'Commentaire de test');

        $this->assertGreaterThan(0, $id);

        $maintenance = new Maintenance();
        $maintenance->getFromDB($id);
        $results = $maintenance->getChecklistResults();

        $byId = [];
        foreach ($results as $result) {
            $byId[$result['name']] = $result;
        }

        $this->assertNull($byId['PHPUnit Checkbox Create']['value'], "Une case a cocher n'enregistre aucune valeur, seule sa presence compte.");
        $this->assertSame('Ecran fissuré', $byId['PHPUnit Texte Create']['value']);
        $this->assertSame('Moyen', $byId['PHPUnit Select Create']['value']);
    }

    public function testCreateWithChecklistSkipsEmptyTextAndSelectValues(): void
    {
        $textId = $this->createChecklistItem('PHPUnit Texte Vide', MaintenanceChecklistItem::TYPE_TEXT);

        $entityId = $this->createTestEntity(0, 'PHPUnit Maintenance Empty');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Maintenance Empty');

        $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [
            $textId => '   ', // uniquement des espaces : considere vide une fois trim()
        ], '');

        $maintenance = new Maintenance();
        $maintenance->getFromDB($id);

        $this->assertSame(
            [],
            $maintenance->getChecklistResults(),
            "Une valeur texte vide (ou uniquement des espaces) ne doit pas etre enregistree comme point renseigne."
        );
    }

    public function testCreateWithChecklistIgnoresValuesForInactiveItems(): void
    {
        $activeId = $this->createChecklistItem('PHPUnit Actif Ignore', MaintenanceChecklistItem::TYPE_CHECKBOX);

        $inactiveItem = new MaintenanceChecklistItem();
        $inactiveId = (int) $inactiveItem->add([
            'entities_id' => 0,
            'name'        => 'PHPUnit Inactif Ignore',
            'is_active'   => 0,
            'type'        => MaintenanceChecklistItem::TYPE_CHECKBOX,
        ]);

        $entityId = $this->createTestEntity(0, 'PHPUnit Maintenance Inactive');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Maintenance Inactive');

        $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [
            $activeId   => '1',
            $inactiveId => '1',
        ], '');

        $maintenance = new Maintenance();
        $maintenance->getFromDB($id);
        $names = array_column($maintenance->getChecklistResults(), 'name');

        $this->assertContains('PHPUnit Actif Ignore', $names);
        $this->assertNotContains('PHPUnit Inactif Ignore', $names, "Un point desactive AVANT la creation de la fiche ne doit pas pouvoir y etre ajoute.");
    }

    private function createChecklistItem(string $name, int $type, string $options = ''): int
    {
        $item = new MaintenanceChecklistItem();
        return (int) $item->add([
            'entities_id' => 0,
            'name'        => $name,
            'is_active'   => 1,
            'type'        => $type,
            'options'     => $options,
        ]);
    }
}
