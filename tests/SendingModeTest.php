<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignItem;
use GlpiPlugin\Assetsign\Config;

/**
 * Issue #163 : mode d'envoi manuel (rien ne part sans clic) et changements d'utilisateur faits par
 * l'inventaire automatique ignores sur demande.
 */
final class SendingModeTest extends AssetsignTestCase
{
    private function configure(array $fields): void
    {
        $config = Config::getForEntity(0);
        $config->update(['id' => $config->getID(), 'sign_on_assignment' => 1, 'sign_on_return' => 1] + $fields);
    }

    /** @return list<array<string, mixed>> */
    private function sheetsFor(\Computer $computer): array
    {
        global $DB;
        return array_values(iterator_to_array($DB->request([
            'FROM'  => Assetsign::getTable(),
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => $computer->getID()],
            'ORDER' => 'id',
        ])));
    }

    public function testManualModeKeepsEverySheetAsADraftUntilSent(): void
    {
        $this->configure(['confirm_before_sending' => Config::SENDING_MANUAL, 'ignore_inventory_changes' => 0]);
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');
        $monitor = (int) (new \Monitor())->add(['name' => 'PHPUNIT-ECRAN', 'entities_id' => 0]);
        (new \Glpi\Asset\Asset_PeripheralAsset())->add([
            'itemtype_asset' => 'Computer', 'items_id_asset' => $computer->getID(),
            'itemtype_peripheral' => 'Monitor', 'items_id_peripheral' => $monitor,
        ]);

        $computer->update(['id' => $computer->getID(), 'users_id' => 2]);

        $sheets = $this->sheetsFor($computer);
        $this->assertCount(1, $sheets);
        $this->assertSame(Assetsign::STATUS_DRAFT, (int) $sheets[0]['status']);
        $this->assertSame(0, (int) $sheets[0]['document_id_unsigned'], 'aucun PDF ni envoi avant le clic');
        $this->assertCount(1, AssetsignItem::getForAssetsign((int) $sheets[0]['id']), 'peripherique connecte pre-coche');

        // La restitution aussi reste en brouillon, et remplace le brouillon d'attribution.
        $computer->update(['id' => $computer->getID(), 'users_id' => 0]);
        $sheets = $this->sheetsFor($computer);
        $this->assertSame(Assetsign::STATUS_CANCELLED, (int) $sheets[0]['status']);
        $this->assertSame(Assetsign::TYPE_RETURN, (int) $sheets[1]['type']);
        $this->assertSame(Assetsign::STATUS_DRAFT, (int) $sheets[1]['status']);

        $return = new Assetsign();
        $return->getFromDB((int) $sheets[1]['id']);
        $return->sendDraft();
        $return->getFromDB($return->getID());
        $this->assertNotSame(Assetsign::STATUS_DRAFT, (int) $return->fields['status']);
        $this->assertGreaterThan(0, (int) $return->fields['document_id_unsigned']);
    }

    public function testImmediateModeSendsRightAway(): void
    {
        $this->configure(['confirm_before_sending' => Config::SENDING_IMMEDIATE, 'ignore_inventory_changes' => 0]);
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');

        $computer->update(['id' => $computer->getID(), 'users_id' => 2]);

        $this->assertNotSame(Assetsign::STATUS_DRAFT, (int) $this->sheetsFor($computer)[0]['status']);
    }

    public function testInventoryChangesCanBeIgnored(): void
    {
        $this->configure(['confirm_before_sending' => Config::SENDING_IMMEDIATE, 'ignore_inventory_changes' => 1]);
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');

        // Mise a jour par l'inventaire automatique (GLPI la marque par '_auto').
        $computer->update(['id' => $computer->getID(), 'users_id' => 2, '_auto' => 1]);
        $computer->update(['id' => $computer->getID(), 'users_id' => 0, '_auto' => 1]);
        $this->assertSame([], $this->sheetsFor($computer));

        // Une attribution faite par une personne declenche toujours la feuille.
        $computer->update(['id' => $computer->getID(), 'users_id' => 2]);
        $this->assertCount(1, $this->sheetsFor($computer));
    }

    private function draft(int $status = Assetsign::STATUS_DRAFT): Assetsign
    {
        $assetsign = new Assetsign();
        $assetsign->getFromDB((int) $assetsign->add([
            'entities_id' => 0,
            'itemtype'    => 'Computer',
            'items_id'    => $this->createTestComputer(0, 'PHPUNIT-PC')->getID(),
            'users_id'    => 2,
            'type'        => Assetsign::TYPE_HANDOVER,
            'status'      => $status,
        ]));
        return $assetsign;
    }

    public function testTheTechnicianCanChangeTheRecipientAndTemplateBeforeSending(): void
    {
        $draft = $this->draft();
        $recipient = $this->createTestUser('Nouveau', 'Beneficiaire');
        $template = (int) (new \GlpiPlugin\Assetsign\Template())->add([
            'name' => 'PHPUnit modele', 'type' => Assetsign::TYPE_HANDOVER, 'is_active' => 1, 'entities_id' => 0,
        ]);

        $draft->updateDraft($recipient, $template);

        $draft->getFromDB($draft->getID());
        $this->assertSame($recipient, (int) $draft->fields['users_id']);
        $this->assertSame($template, (int) $draft->fields['plugin_assetsign_templates_id']);
        $this->assertStringStartsWith('%PDF', $draft->renderDraftPreviewPdf());
    }

    public function testATemplateOfAnotherTypeIsRefused(): void
    {
        $draft = $this->draft();
        $returnTemplate = (int) (new \GlpiPlugin\Assetsign\Template())->add([
            'name' => 'PHPUnit restitution', 'type' => Assetsign::TYPE_RETURN, 'is_active' => 1, 'entities_id' => 0,
        ]);

        $this->expectException(\RuntimeException::class);
        $draft->updateDraft(0, $returnTemplate);
    }

    public function testASentSheetCanNoLongerBeChanged(): void
    {
        $sent = $this->draft(Assetsign::STATUS_SENT);

        $this->expectException(\RuntimeException::class);
        $sent->updateDraft($this->createTestUser('Trop', 'Tard'), 0);
    }

    public function testInventoryChangesTriggerSheetsByDefault(): void
    {
        $this->configure(['confirm_before_sending' => Config::SENDING_IMMEDIATE, 'ignore_inventory_changes' => 0]);
        $computer = $this->createTestComputer(0, 'PHPUNIT-PC');

        $computer->update(['id' => $computer->getID(), 'users_id' => 2, '_auto' => 1]);

        $this->assertCount(1, $this->sheetsFor($computer));
    }
}
