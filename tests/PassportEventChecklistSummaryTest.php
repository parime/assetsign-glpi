<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\ChecklistItem;
use GlpiPlugin\Assetsign\PassportEvent;

/**
 * Couvre PassportEvent::attachChecklistSummaries() (cf. ROADMAP.md V1, issue #74,
 * docs/design/ADR-passeport-v1.md) : resume "X/Y controles" fusionne dans la frise
 * du Passeport, PUREMENT calcule a l'affichage (jamais copie dans
 * glpi_plugin_assetsign_events), absent si aucun point n'est configure pour ce
 * type de mouvement.
 */
class PassportEventChecklistSummaryTest extends AssetsignTestCase
{
    /**
     * ChecklistItem::install() seme deja quelques points par defaut (globaux,
     * sans filtre d'entite, cf. ChecklistItem::getActiveItemsForMovementType()) :
     * desactives ici (annule par le rollback de tearDown()) pour que chaque test
     * ci-dessous compte EXACTEMENT les points qu'il cree lui-meme, sans dependre
     * du jeu de donnees seme a l'installation.
     */
    private function deactivateExistingChecklistItems(): void
    {
        global $DB;
        $DB->update('glpi_plugin_assetsign_checklistitems', ['is_active' => 0], ['id' => ['>', 0]]);
    }

    public function testShowForItemDisplaysPartialChecklistBadge(): void
    {
        $this->deactivateExistingChecklistItems();

        $entityId = $this->createTestEntity(0, 'PHPUnit PassportChecklist Partial');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC PassportChecklist Partial');

        $itemA = new ChecklistItem();
        $itemA->add(['entities_id' => 0, 'name' => 'PHPUnit A', 'type' => ChecklistItem::TYPE_CHECKBOX, 'is_active' => 1, 'movement_types' => [Assetsign::TYPE_HANDOVER]]);
        $itemB = new ChecklistItem();
        $itemB->add(['entities_id' => 0, 'name' => 'PHPUnit B', 'type' => ChecklistItem::TYPE_CHECKBOX, 'is_active' => 1, 'movement_types' => [Assetsign::TYPE_HANDOVER]]);

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $this->createTestUser('Jean', 'Checklist');
        Assetsign::handleItemAssignment($computer);

        $assetsign = new Assetsign();
        $assetsign->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID()]);
        $assetsign->setChecklistValues([$itemA->getID() => '1']);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString('1/2', $html, "Un seul des deux points configures rempli doit afficher '1/2'.");
        $this->assertStringContainsString('#f76707', $html, 'Rempli partiellement : badge orange attendu.');
    }

    public function testShowForItemDisplaysCompleteChecklistBadgeInGreen(): void
    {
        $this->deactivateExistingChecklistItems();

        $entityId = $this->createTestEntity(0, 'PHPUnit PassportChecklist Complete');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC PassportChecklist Complete');

        $itemA = new ChecklistItem();
        $itemA->add(['entities_id' => 0, 'name' => 'PHPUnit Only', 'type' => ChecklistItem::TYPE_CHECKBOX, 'is_active' => 1, 'movement_types' => [Assetsign::TYPE_HANDOVER]]);

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $this->createTestUser('Marie', 'Checklist');
        Assetsign::handleItemAssignment($computer);

        $assetsign = new Assetsign();
        $assetsign->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID()]);
        $assetsign->setChecklistValues([$itemA->getID() => '1']);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringContainsString('1/1', $html);
        $this->assertStringContainsString('#2fb344', $html, 'Tous les points remplis : badge vert attendu.');
    }

    public function testShowForItemOmitsBadgeWhenNoChecklistConfiguredForThisMovementType(): void
    {
        $this->deactivateExistingChecklistItems();

        $entityId = $this->createTestEntity(0, 'PHPUnit PassportChecklist None');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC PassportChecklist None');

        // Aucun point actif applicable a l'Attribution : l'absence de checklist
        // configuree n'est pas un defaut a signaler (cf. ADR, risque "performance
        // des indicateurs"), jamais un faux badge "0/0".
        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $this->createTestUser('Paul', 'NoChecklist');
        Assetsign::handleItemAssignment($computer);

        ob_start();
        PassportEvent::showForItem($computer);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('contrôles qualité', $html);
    }
}
