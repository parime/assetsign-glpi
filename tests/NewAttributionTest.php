<?php

namespace GlpiPlugin\Assetsign\Tests;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;

/**
 * Issue #282 : nouvelle attribution depuis la liste (recherche du materiel, choix de l'action en
 * cartes), fiches en attente de signature en tete de la liste. Chaque test verifie aussi que rien
 * n'est montre hors des droits GLPI (droit du type, entites actives, droit du plugin).
 */
class NewAttributionTest extends AssetsignTestCase
{
    /** @var array<string, mixed> */
   private array $savedProfile = [];

    /** @var list<int> */
   private array $savedEntities = [];

   protected function setUp(): void {
       parent::setUp();
       $this->savedProfile = $_SESSION['glpiactiveprofile'];
       $this->savedEntities = $_SESSION['glpiactiveentities'];
   }

   protected function tearDown(): void {
       $_SESSION['glpiactiveprofile'] = $this->savedProfile;
       $_SESSION['glpiactiveentities'] = $this->savedEntities;
       $_SESSION['glpiactiveentities_string'] = implode("','", $this->savedEntities);
       parent::tearDown();
   }

    /** Retire une entite des entites actives : l'utilisateur n'y a plus acces. */
   private function leaveEntity(int $entityId): void {
       $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$entityId]));
       $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);
   }

   private function setComputerFields(\Computer $computer, array $fields): void {
       global $DB;
       $DB->update('glpi_computers', $fields, ['id' => $computer->getID()]);
       $computer->getFromDB($computer->getID());
   }

   public function testSearchMatchesNameSerialAndInventoryNumber(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit NewAttribution recherche');
       $byName = $this->createTestComputer($entity, 'PHPUnit PC Zephyr-282');
       $bySerial = $this->createTestComputer($entity, 'PHPUnit PC serie 282');
       $this->setComputerFields($bySerial, ['serial' => 'SN-ZEPHYR-282-X']);
       $byInventory = $this->createTestComputer($entity, 'PHPUnit PC inventaire 282');
       $this->setComputerFields($byInventory, ['otherserial' => 'INV-ZEPHYR-282']);

       $found = array_column(Assetsign::searchItems('zephyr'), 'id');

       $this->assertContains($byName->getID(), $found);
       $this->assertContains($bySerial->getID(), $found);
       $this->assertContains($byInventory->getID(), $found);
   }

   public function testSearchNeedsTwoCharacters(): void {
       $this->assertSame([], Assetsign::searchItems(' z '));
   }

   public function testSearchIgnoresDeletedAndTemplateItems(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit NewAttribution corbeille');
       $deleted = $this->createTestComputer($entity, 'PHPUnit PC Quokka supprime');
       $this->setComputerFields($deleted, ['is_deleted' => 1]);
       $template = $this->createTestComputer($entity, 'PHPUnit PC Quokka gabarit');
       $this->setComputerFields($template, ['is_template' => 1]);

       $this->assertSame([], Assetsign::searchItems('Quokka'));
   }

   public function testSearchNeverReturnsItemsOutsideActiveEntities(): void {
       $visible = $this->createTestEntity(0, 'PHPUnit NewAttribution entite visible');
       $hidden = $this->createTestEntity(0, 'PHPUnit NewAttribution entite cachee');
       $mine = $this->createTestComputer($visible, 'PHPUnit PC Wombat visible');
       $theirs = $this->createTestComputer($hidden, 'PHPUnit PC Wombat cache');
       $this->leaveEntity($hidden);

       $results = Assetsign::searchItems('Wombat');

       $this->assertSame([$mine->getID()], array_column($results, 'id'));
       $this->assertStringNotContainsString('Wombat cache', json_encode($results));
       $this->assertNull(Assetsign::resolveReadableItem('Computer', $theirs->getID()));
   }

   public function testSearchNeverReturnsItemsOfATypeTheUserCannotRead(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit NewAttribution sans droit');
       $this->createTestComputer($entity, 'PHPUnit PC Okapi');
       $_SESSION['glpiactiveprofile']['computer'] = 0;

       $this->assertSame([], Assetsign::searchItems('Okapi'));
   }

   public function testResolveReadableItemRejectsUnmanagedTypesAndUnknownIds(): void {
       $this->assertNull(Assetsign::resolveReadableItem('User', 2));
       $this->assertNull(Assetsign::resolveReadableItem('Computer', 0));
       $this->assertNull(Assetsign::resolveReadableItem('Computer', 999999999));
       $this->assertNull(Assetsign::resolveReadableItem('Not\\A\\Class', 1));
   }

   public function testActionCardsExplainWhyAnActionIsUnavailable(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit NewAttribution cartes');
       Config::upsertForEntity($entity, [
           'sign_on_assignment' => 1, 'sign_on_return' => 1, 'sign_on_reassignment' => 1,
           'enable_don' => 1, 'enable_vente' => 0, 'enable_destruction' => 0,
       ]);
       $computer = $this->createTestComputer($entity, 'PHPUnit PC cartes');

       $cards = array_column(Assetsign::actionCards($computer)['cards'], null, 'type');

       $this->assertSame(['handover', 'return', 'manual', 'manual', 'manual'], array_column(array_values($cards), 'action'));
       $this->assertSame('', $cards[Assetsign::TYPE_HANDOVER]['reason']);
       $this->assertNotSame('', $cards[Assetsign::TYPE_RETURN]['reason'], 'Sans utilisateur, rien a restituer.');
       $this->assertSame('', $cards[Assetsign::TYPE_DON]['reason']);
       $this->assertNotSame('', $cards[Assetsign::TYPE_VENTE]['reason'], 'Vente desactivee dans l\'entite.');
       $this->assertNotSame('', $cards[Assetsign::TYPE_DESTRUCTION]['reason']);

       $this->setComputerFields($computer, ['users_id' => $this->createTestUser('Camille', 'Exemple')]);
       $cards = array_column(Assetsign::actionCards($computer)['cards'], null, 'type');
       $this->assertSame('', $cards[Assetsign::TYPE_RETURN]['reason']);
   }

   public function testHandoverAndReturnNeedTheRightToUpdateTheItem(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit NewAttribution lecture seule');
       Config::upsertForEntity($entity, ['sign_on_assignment' => 1, 'sign_on_return' => 1, 'sign_on_reassignment' => 1]);
       $computer = $this->createTestComputer($entity, 'PHPUnit PC lecture seule');
       $this->setComputerFields($computer, ['users_id' => $this->createTestUser('Dominique', 'Exemple')]);
       $_SESSION['glpiactiveprofile']['computer'] = READ;

       $cards = array_column(Assetsign::actionCards($computer)['cards'], null, 'type');

       $this->assertNotSame('', $cards[Assetsign::TYPE_HANDOVER]['reason']);
       $this->assertNotSame('', $cards[Assetsign::TYPE_RETURN]['reason']);
   }

   public function testPendingSheetsAreLimitedToActiveEntitiesAndAwaitingStatuses(): void {
       $this->clearAwaitingSignatureAssetsigns();
       $visible = $this->createTestEntity(0, 'PHPUnit NewAttribution attente visible');
       $hidden = $this->createTestEntity(0, 'PHPUnit NewAttribution attente cachee');
       $sent = $this->createBareAssetsign($visible, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);
       $this->createBareAssetsign($visible, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SIGNED);
       $this->createBareAssetsign($visible, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_DRAFT);
       $other = $this->createBareAssetsign($hidden, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);
       $this->leaveEntity($hidden);

       $ids = array_map(static fn (Assetsign $s): int => (int) $s->getID(), Assetsign::getPendingSheets());

       $this->assertContains((int) $sent->getID(), $ids);
       $this->assertNotContains((int) $other->getID(), $ids);
       $this->assertCount(1, $ids);
   }

   public function testLatestIdForDesignatesTheSheetJustCreated(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit NewAttribution derniere fiche');
       $computer = $this->createTestComputer($entity, 'PHPUnit PC derniere fiche');
       $before = Assetsign::latestIdFor('Computer', $computer->getID());

       $sheet = new Assetsign();
       $id = (int) $sheet->add([
           'entities_id' => $entity, 'itemtype' => 'Computer', 'items_id' => $computer->getID(),
           'users_id' => 2, 'type' => Assetsign::TYPE_HANDOVER, 'status' => Assetsign::STATUS_DRAFT,
       ]);

       $this->assertSame(0, $before);
       $this->assertSame($id, Assetsign::latestIdFor('Computer', $computer->getID()));
   }

   public function testHomeShowsNewButtonAndActionsOnlyWithTheRightToUpdate(): void {
       $this->clearAwaitingSignatureAssetsigns();
       $entity = $this->createTestEntity(0, 'PHPUnit NewAttribution accueil');
       $this->createBareAssetsign($entity, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

       ob_start();
       Assetsign::showHome();
       $withRight = (string) ob_get_clean();

       $_SESSION['glpiactiveprofile'][Assetsign::$rightname] = READ;
       ob_start();
       Assetsign::showHome();
       $readOnly = (string) ob_get_clean();

       $this->assertStringContainsString('assetsign-pending-sheets', $withRight);
       $this->assertStringContainsString('name="copy_link"', $withRight);
       $this->assertStringContainsString('ti-file-plus', $withRight);
       $this->assertStringContainsString('assetsign-pending-sheets', $readOnly, 'La liste reste lisible avec le droit de lecture.');
       $this->assertStringNotContainsString('name="copy_link"', $readOnly);
       $this->assertStringNotContainsString('name="relance"', $readOnly);
       $this->assertStringNotContainsString('ti-file-plus', $readOnly);
   }

   public function testNewAttributionPageRendersSummaryAndCards(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit NewAttribution page');
       Config::upsertForEntity($entity, ['enable_don' => 1, 'enable_vente' => 1, 'enable_destruction' => 1]);
       $computer = $this->createTestComputer($entity, 'PHPUnit PC <b>page</b>');
       $this->setComputerFields($computer, ['serial' => 'SN-PAGE-282']);

       ob_start();
       Assetsign::showNewAttribution('', $computer);
       $html = (string) ob_get_clean();

       $this->assertStringContainsString('SN-PAGE-282', $html);
       $this->assertStringContainsString('PHPUnit PC &lt;b&gt;page&lt;/b&gt;', $html);
       $this->assertStringNotContainsString('<b>page</b>', $html);
       $this->assertSame(5, substr_count($html, 'class="btn-check" name="assetsign_action"'));
       $this->assertSame(3, substr_count($html, 'name="_glpi_csrf_token"'), 'Chaque formulaire porte son jeton CSRF.');
       $this->assertStringContainsString('name="open_sheet"', $html);
       $this->assertStringContainsString($computer->getLinkURL(), $html, 'Lien « Ouvrir la fiche du matériel ».');
       $this->assertNoStrayNumericTextNode($html, 'assetsign_new.html.twig');
   }

   public function testNewAttributionSearchStepListsReadableItems(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit NewAttribution etape 1');
       $computer = $this->createTestComputer($entity, 'PHPUnit PC Ocelot');

       ob_start();
       Assetsign::showNewAttribution('Ocelot');
       $html = (string) ob_get_clean();

       $this->assertStringContainsString('PHPUnit PC Ocelot', $html);
       $this->assertStringContainsString('items_id=' . $computer->getID(), $html);
       $this->assertStringNotContainsString('name="assetsign_action"', $html);
   }

   public function testSheetFormShowsTheItemSummaryOnlyWhenTheItemIsReadable(): void {
       $entity = $this->createTestEntity(0, 'PHPUnit NewAttribution fiche');
       $computer = $this->createTestComputer($entity, 'PHPUnit PC fiche');
       $sheet = new Assetsign();
       $sheet->fields = ['itemtype' => 'Computer', 'items_id' => $computer->getID()];

       $this->assertSame('PHPUnit PC fiche', $sheet->getReadableTargetSummary()['name'] ?? null);

       $_SESSION['glpiactiveprofile']['computer'] = 0;
       $this->assertNull($sheet->getReadableTargetSummary());
   }

   public function testHomeTemplateEscapesPendingRows(): void {
       $html = TemplateRenderer::getInstance()->render('@assetsign/attributions_home.html.twig', [
           'counters' => [], 'can_create' => false, 'new_url' => '/f', 'pending_limit' => 20, 'pending_url' => '/l',
           'can_update' => true, 'statuses' => Assetsign::getStatuses(), 'types' => Assetsign::getTypes(),
           'type_icons' => Assetsign::getTypeIcons(), 'status_badges' => Assetsign::getStatusBadgeClasses(),
           'csrf_token' => 'phpunit-token',
           'pending' => [[
               'id' => 1, 'type' => Assetsign::TYPE_HANDOVER, 'status' => Assetsign::STATUS_SENT,
               'item' => '<script>x</script>', 'beneficiary' => '<img src=x>', 'date_sent' => '',
               'can_cancel' => false, 'shared_link' => '',
           ]],
       ]);

       $this->assertStringNotContainsString('<script>x</script>', $html);
       $this->assertStringNotContainsString('<img src=x>', $html);
       $this->assertStringNotContainsString('name="cancel_request"', $html);
   }
}
