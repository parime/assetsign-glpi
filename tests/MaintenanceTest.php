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

    public function testCreateWithChecklistGeneratesPdfDocument(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Maintenance PDF');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Maintenance PDF');
        $checkboxId = $this->createChecklistItem('PHPUnit Checkbox PDF', MaintenanceChecklistItem::TYPE_CHECKBOX);

        $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [$checkboxId => '1'], 'Commentaire PDF');

        $maintenance = new Maintenance();
        $maintenance->getFromDB($id);

        $documentsId = (int) $maintenance->fields['document_id'];
        $this->assertGreaterThan(0, $documentsId, "createWithChecklist() doit generer un PDF et enregistrer son document_id.");

        $document = new \Document();
        $this->assertTrue($document->getFromDB($documentsId));
        $this->assertSame($entityId, (int) $document->fields['entities_id'], "Le PDF doit appartenir a la meme entite que la fiche de maintenance.");

        $fullpath = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
        $this->assertFileExists($fullpath, 'Le fichier PDF genere doit reellement exister sur disque.');
        $this->assertGreaterThan(0, filesize($fullpath));
    }

    public function testGetTargetItemIncludesManufacturerAndModel(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Maintenance TargetItem');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC TargetItem');

        $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');
        $maintenance = new Maintenance();
        $maintenance->getFromDB($id);

        $item = $maintenance->getTargetItem();

        $this->assertSame('PHPUnit PC TargetItem', $item['name']);
        // Ni marque ni modele configures sur ce Computer minimal : les deux
        // cles doivent exister quand meme (Remise::resolveManufacturerName()/
        // resolveModelName() renvoient une chaine vide, jamais une absence de
        // cle), pour que le gabarit PDF (qui teste juste leur troncature)
        // ne plante pas sur une cle manquante.
        $this->assertArrayHasKey('manufacturer_name', $item);
        $this->assertArrayHasKey('model_name', $item);
        $this->assertSame('', $item['manufacturer_name']);
    }

    public function testGetTechnicianReturnsTheUserWhoCreatedTheRecord(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Maintenance Technician');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Technician');

        // $_SESSION['glpiID'] : createWithChecklist() y lit users_id_tech via
        // Session::getLoginUserID() (cf. son propre code) - la session de test
        // n'authentifie personne par defaut (cf. RemiseTestCase::setUp()),
        // simule ici la connexion du compte 'glpi' (id=2) comme technicien.
        $_SESSION['glpiID'] = 2;

        $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], '');
        $maintenance = new Maintenance();
        $maintenance->getFromDB($id);

        $this->assertSame(2, (int) $maintenance->fields['users_id_tech']);

        $technician = $maintenance->getTechnician();
        $this->assertSame('glpi', $technician['name']);
        $this->assertNotEmpty($technician['email'], "L'e-mail du technicien doit etre fusionne depuis glpi_useremails (absent de glpi_users).");
    }

    public function testGetSpecificValueToDisplayRendersDownloadLinkForDocumentId(): void
    {
        $html = Maintenance::getSpecificValueToDisplay('document_id', ['document_id' => 42]);
        $this->assertStringContainsString('document.send.php?docid=42', $html);

        $this->assertSame('', Maintenance::getSpecificValueToDisplay('document_id', ['document_id' => 0]), 'Aucun document : aucun lien a afficher.');
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
