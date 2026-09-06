<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Api\PassportBackfillController;
use InvalidArgumentException;
use User;

/**
 * Couvre le dispatch de front/passport_backfill.php (bouton "Forcer la recherche"
 * des onglets Passeport materiel/utilisateur) desormais extrait dans
 * PassportBackfillController — meme motivation que les autres Api\*Controller
 * deja testes (AssetsignFormController, MaintenanceFormController,
 * MovementFormController...). PassportEventBackfillTest.php couvre deja
 * PassportEvent::backfillFromLogs()/backfillUserHistoryFromLogs() directement ;
 * ce test-ci couvre la logique propre au controleur qu'aucun des deux ne
 * teste : la garde itemtype/materiel/utilisateur (can(..., READ) inclus) et
 * le formatage du message de retour (singulier/pluriel, cas "aucun evenement").
 */
class PassportBackfillControllerTest extends AssetsignTestCase
{
    public function testRunForItemReturnsNoEventMessageForItemWithoutLogs(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit PassportBackfillController Item');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC PassportBackfillController');

        $message = (new PassportBackfillController())->runForItem([
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
        ]);

        // __() rather than a hardcoded French string: the test bootstrap's active session
        // language isn't guaranteed to be French, and this assertion is about the controller's
        // OWN formatting logic (does it call formatResult() at all, does it pick the "zero"
        // branch), not about a specific translation's wording.
        $this->assertSame(__('Aucun nouvel événement trouvé dans l\'historique.', 'assetsign'), $message);
    }

    public function testRunForItemThrowsForInvalidItemtype(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PassportBackfillController())->runForItem([
            'itemtype' => 'NotARealClass',
            'items_id' => 1,
        ]);
    }

    public function testRunForItemThrowsWhenItemNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PassportBackfillController())->runForItem([
            'itemtype' => 'Computer',
            'items_id' => 999999999,
        ]);
    }

    public function testRunForItemRejectsItemInEntityOutsideCurrentAccess(): void
    {
        global $DB;

        $inaccessibleEntityId = random_int(700000, 799999);
        $DB->insert('glpi_entities', [
            'id'           => $inaccessibleEntityId,
            'name'         => 'PHPUnit PassportBackfill Entite Inaccessible',
            'completename' => 'PHPUnit PassportBackfill Entite Inaccessible',
            'entities_id'  => 0,
            'level'        => 2,
        ]);
        // Jamais ajoutee a $_SESSION['glpiactiveentities'] : meme technique que
        // MaintenanceFormControllerTest/MovementFormControllerTest, simule un
        // utilisateur qui n'a pas acces a cette entite precise.
        $computer = $this->createTestComputer($inaccessibleEntityId, 'PHPUnit PC PassportBackfill Hors Portee');

        $this->expectException(InvalidArgumentException::class);
        (new PassportBackfillController())->runForItem([
            'itemtype' => 'Computer',
            'items_id' => $computer->getID(),
        ]);
    }

    public function testRunForUserReturnsNoEventMessageForUserWithoutLogs(): void
    {
        $user = new User();
        $userId = (int) $user->add([
            'name'     => 'phpunit_passport_backfill_user',
            'realname' => 'PHPUnit PassportBackfill User',
        ]);
        $this->assertGreaterThan(0, $userId);

        $message = (new PassportBackfillController())->runForUser(['users_id' => $userId]);

        // __() rather than a hardcoded French string: the test bootstrap's active session
        // language isn't guaranteed to be French, and this assertion is about the controller's
        // OWN formatting logic (does it call formatResult() at all, does it pick the "zero"
        // branch), not about a specific translation's wording.
        $this->assertSame(__('Aucun nouvel événement trouvé dans l\'historique.', 'assetsign'), $message);
    }

    public function testRunForUserThrowsWhenUserNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PassportBackfillController())->runForUser(['users_id' => 999999999]);
    }
}
