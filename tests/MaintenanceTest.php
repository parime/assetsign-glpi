<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Maintenance;
use GlpiPlugin\Assetsign\MaintenanceChecklistItem;
use GlpiPlugin\Assetsign\Signature;
use RuntimeException;

/**
 * Couvre la checklist de maintenance a types multiples (case a cocher / texte
 * libre / menu deroulant), ajoutee sans suite automatisee jusqu'ici (verifiee
 * uniquement via des scripts Docker manuels, cf. historique), ainsi que la
 * generation systematique du PDF et la signature optionnelle du technicien
 * (Config::enable_maintenance_signature).
 */
class MaintenanceTest extends AssetsignTestCase
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

    public function testCreateWithChecklistAlwaysGeneratesAPdfEvenWithoutSignature(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Maintenance PDF');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Maintenance PDF');

        // Config par defaut (enable_maintenance_signature = 0) : le PDF doit
        // neanmoins etre genere, cf. USER_GUIDE.md (le telechargement PDF n'est
        // pas conditionne par la signature, contrairement a la signature elle-meme).
        $id = Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], 'Commentaire');

        $maintenance = new Maintenance();
        $maintenance->getFromDB($id);

        $this->assertGreaterThan(0, (int) $maintenance->fields['document_id'], 'Le PDF doit avoir ete genere et attache, meme sans signature activee.');
        $this->assertNull(Signature::getForMaintenance($id), "Aucune preuve de signature ne doit exister quand la signature n'est pas activee pour l'entite.");
    }

    public function testCreateWithChecklistRecordsSignatureProofWhenEnabledAndValid(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Maintenance Signature OK');
        Config::upsertForEntity($entityId, ['enable_maintenance_signature' => '1']);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Maintenance Signature OK');

        $id = Maintenance::createWithChecklist(
            'Computer',
            $computer->getID(),
            $entityId,
            [],
            'Commentaire',
            [],
            self::signatureStrokeDataUri()
        );

        $maintenance = new Maintenance();
        $maintenance->getFromDB($id);
        $this->assertGreaterThan(0, (int) $maintenance->fields['document_id']);

        $proof = Signature::getForMaintenance($id);
        $this->assertNotNull($proof, 'Une preuve de signature doit avoir ete enregistree.');
        $this->assertNotEmpty($proof['document_hash'], "L'empreinte du PDF signe doit etre enregistree.");
    }

    public function testCreateWithChecklistRejectsMissingSignatureWhenEnabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Maintenance Signature Missing');
        Config::upsertForEntity($entityId, ['enable_maintenance_signature' => '1']);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Maintenance Signature Missing');

        $this->expectException(RuntimeException::class);

        try {
            Maintenance::createWithChecklist('Computer', $computer->getID(), $entityId, [], 'Commentaire');
        } finally {
            $count = countElementsInTable(Maintenance::getTable(), [
                'itemtype' => 'Computer',
                'items_id' => $computer->getID(),
            ]);
            $this->assertSame(0, $count, "Aucune fiche ne doit avoir ete creee quand la signature requise est absente.");
        }
    }

    public function testCreateWithChecklistRejectsEmptyCanvasSignatureWhenEnabled(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Maintenance Signature Empty');
        Config::upsertForEntity($entityId, ['enable_maintenance_signature' => '1']);
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Maintenance Signature Empty');

        $this->expectException(RuntimeException::class);
        Maintenance::createWithChecklist(
            'Computer',
            $computer->getID(),
            $entityId,
            [],
            'Commentaire',
            [],
            self::emptyCanvasDataUri()
        );
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

    /** Meme generateur que SignatureImageValidatorTest (canevas GD isole, cette classe etend AssetsignTestCase et ne peut pas en heriter directement). */
    private static function signatureStrokeDataUri(int $width = 300, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        $ink = imagecolorallocatealpha($image, 0, 0, 0, 0);
        imageline($image, 20, (int) ($height / 2), $width - 20, (int) ($height / 2), $ink);
        imageline($image, (int) ($width / 3), 15, (int) ($width / 2), $height - 15, $ink);

        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($binary);
    }

    private static function emptyCanvasDataUri(int $width = 300, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($binary);
    }
}
