<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\PassportEvent;

/**
 * Issue #182 : l'element remis avec un materiel a sa propre trace dans le passeport.
 */
final class PassportHandedItemsTest extends AssetsignTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::getForEntity(0);
        $config->update(['id' => $config->getID(), 'enable_passport' => 1, 'assign_handed_items' => 0]);
    }

    private function sheetWithMonitor(int $type, int $status): array
    {
        $monitor = (int) (new \Monitor())->add(['name' => 'PHPUNIT-ECRAN', 'entities_id' => 0]);
        $sheet = new Assetsign();
        $sheet->getFromDB((int) $sheet->add([
            'entities_id' => 0, 'itemtype' => 'Computer', 'items_id' => $this->createTestComputer(0, 'PHPUNIT-PC')->getID(),
            'users_id' => 2, 'type' => $type, 'status' => Assetsign::STATUS_DRAFT,
        ]));
        AssetsignItem::attach($sheet, 'Monitor', $monitor);
        global $DB;
        $DB->update(Assetsign::getTable(), ['status' => $status], ['id' => $sheet->getID()]);
        $sheet->getFromDB($sheet->getID());
        return [$sheet, $monitor];
    }

    /** @return list<array<string, mixed>> */
    private function eventsOf(int $monitor): array
    {
        return array_values((new PassportEvent())->find(['itemtype' => 'Monitor', 'items_id' => $monitor], ['id']));
    }

    public function testSendingRecordsAHandedOverEventOnTheItemOnce(): void
    {
        [$sheet, $monitor] = $this->sheetWithMonitor(Assetsign::TYPE_HANDOVER, Assetsign::STATUS_PENDING);

        PassportEvent::recordHandedItems($sheet);
        PassportEvent::recordHandedItems($sheet);

        $events = $this->eventsOf($monitor);
        $this->assertCount(1, $events);
        $this->assertSame(PassportEvent::TYPE_ATTRIBUTION, (int) $events[0]['event_type']);
        $this->assertStringContainsString('PHPUNIT-PC', (string) $events[0]['comment']);
        $this->assertSame($sheet->getID(), (int) $events[0]['source_items_id']);
    }

    public function testTheReturnStateIsRecordedAndUpdated(): void
    {
        [$sheet, $monitor] = $this->sheetWithMonitor(Assetsign::TYPE_RETURN, Assetsign::STATUS_PENDING);
        $row = AssetsignItem::getForAssetsign($sheet->getID())[0];

        $sheet->saveReturnCheck([(int) $row['id'] => AssetsignItem::RETURN_MISSING]);
        $sheet->saveReturnCheck([(int) $row['id'] => AssetsignItem::RETURN_RETURNED]);

        $events = $this->eventsOf($monitor);
        $this->assertCount(1, $events, 'un seul evenement, mis a jour');
        $this->assertSame(PassportEvent::TYPE_RETURN, (int) $events[0]['event_type']);
        $this->assertStringContainsString('PHPUNIT-PC', (string) $events[0]['comment']);
    }

    public function testExistingSheetsAreBackfilled(): void
    {
        [, $monitor] = $this->sheetWithMonitor(Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SIGNED);
        [, $draftMonitor] = $this->sheetWithMonitor(Assetsign::TYPE_HANDOVER, Assetsign::STATUS_DRAFT);

        PassportEvent::backfillHandedItems('Monitor', $monitor);
        PassportEvent::backfillHandedItems('Monitor', $draftMonitor);

        $this->assertCount(1, $this->eventsOf($monitor));
        $this->assertSame([], $this->eventsOf($draftMonitor), 'brouillon : rien n\'a ete remis');
    }
}
