<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;

/**
 * Issue #179 : feuilles restees en brouillon - rappel au technicien et liste de la page d'accueil.
 */
final class DraftReminderTest extends AssetsignTestCase
{
    private function sheet(int $tech, int $status = Assetsign::STATUS_DRAFT, string $created = '-5 days'): Assetsign
    {
        global $DB;
        $sheet = new Assetsign();
        $id = (int) $sheet->add([
            'entities_id'   => 0,
            'itemtype'      => 'Computer',
            'items_id'      => $this->createTestComputer(0, 'PHPUNIT-PC')->getID(),
            'users_id'      => 2,
            'users_id_tech' => $tech,
            'type'          => Assetsign::TYPE_HANDOVER,
            'status'        => $status,
        ]);
        $DB->update(Assetsign::getTable(), ['date_creation' => date('Y-m-d H:i:s', strtotime($created))], ['id' => $id]);
        $sheet->getFromDB($id);
        return $sheet;
    }

    private function setDelay(int $days): void
    {
        $config = Config::getForEntity(0);
        $config->update(['id' => $config->getID(), 'draft_reminder_days' => $days]);
    }

    private function reminded(Assetsign $sheet): bool
    {
        $sheet->getFromDB($sheet->getID());
        return !empty($sheet->fields['draft_reminder_sent_at']);
    }

    public function testAStaleDraftIsRemindedOnceAWeek(): void
    {
        $this->setDelay(3);
        $tech = $this->createTestUser('Tech', 'Rappel');
        $stale = $this->sheet($tech);
        $recent = $this->sheet($tech, Assetsign::STATUS_DRAFT, '-1 day');
        $sent = $this->sheet($tech, Assetsign::STATUS_SENT);

        Assetsign::runDraftReminders();

        $this->assertTrue($this->reminded($stale));
        $this->assertFalse($this->reminded($recent), 'pas encore assez ancien');
        $this->assertFalse($this->reminded($sent), 'pas un brouillon');

        // Deuxieme passage le meme jour : pas de nouveau rappel.
        global $DB;
        $before = $stale->fields['draft_reminder_sent_at'];
        Assetsign::runDraftReminders();
        $stale->getFromDB($stale->getID());
        $this->assertSame($before, $stale->fields['draft_reminder_sent_at']);

        // Une semaine plus tard : nouveau rappel.
        $DB->update(Assetsign::getTable(), ['draft_reminder_sent_at' => date('Y-m-d H:i:s', strtotime('-8 days'))], ['id' => $stale->getID()]);
        Assetsign::runDraftReminders();
        $stale->getFromDB($stale->getID());
        $this->assertGreaterThan(strtotime('-1 hour'), strtotime((string) $stale->fields['draft_reminder_sent_at']));
    }

    public function testRemindersCanBeDisabled(): void
    {
        $this->setDelay(0);
        $stale = $this->sheet($this->createTestUser('Tech', 'Sans rappel'));

        Assetsign::runDraftReminders();

        $this->assertFalse($this->reminded($stale));
    }

    public function testHomePageListsMyDraftsAndAutomaticOnes(): void
    {
        $me = $this->createTestUser('Moi', 'Technicien');
        $colleague = $this->createTestUser('Autre', 'Technicien');
        $mine = $this->sheet($me);
        $automatic = $this->sheet(0);
        $theirs = $this->sheet($colleague);
        $sent = $this->sheet($me, Assetsign::STATUS_SENT);

        $ids = array_column(Assetsign::getDraftsToSend($me, 50), 'id');

        $this->assertContains($mine->getID(), $ids);
        $this->assertContains($automatic->getID(), $ids, 'brouillon cree sans technicien (inventaire)');
        $this->assertNotContains($theirs->getID(), $ids);
        $this->assertNotContains($sent->getID(), $ids);
    }
}
