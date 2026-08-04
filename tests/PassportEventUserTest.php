<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\PassportEvent;
use GlpiPlugin\Remise\Remise;

/**
 * Couvre la vue symetrique "Passeport utilisateur" (cf. ROADMAP.md, "Extension
 * proposee : Passeport utilisateur") : meme socle que PassportEventTest (aucun
 * nouvel evenement, uniquement une nouvelle lecture filtree par users_id), plus
 * les bornes de compte (creation/desactivation) lues directement sur User.
 */
class PassportEventUserTest extends RemiseTestCase
{
    private function createTestComputerWithSerial(int $entitiesId, string $name, string $serial): \Computer
    {
        $computer = new \Computer();
        $id = (int) $computer->add([
            'name'        => $name,
            'entities_id' => $entitiesId,
            'serial'      => $serial,
        ]);
        $computer->getFromDB($id);
        return $computer;
    }

    public function testShowForUserRendersDeviceNameAndSerial(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Passport User Render');
        $computer = $this->createTestComputerWithSerial($entityId, 'PHPUnit PC Passport User', 'SN-12345');
        $userId = $this->createTestUser('Jean', 'Dupont');
        $user = new \User();
        $user->getFromDB($userId);

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $userId;
        Remise::handleItemAssignment($computer);

        ob_start();
        PassportEvent::showForUser($user);
        $html = ob_get_clean();

        $this->assertStringContainsString('PHPUnit PC Passport User', $html);
        $this->assertStringContainsString('SN-12345', $html);
        $this->assertStringContainsString(__('Attribution', 'remise'), $html);
        $this->assertNoStrayNumericTextNode($html, 'Le Passeport utilisateur doit se rendre sans fuite Twig.');
    }

    public function testShowForUserOnlyIncludesEventsOfThatUser(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Passport User Scope');
        $computer = $this->createTestComputerWithSerial($entityId, 'PHPUnit PC Passport Scope', '');
        $userId = $this->createTestUser('Jean', 'Dupont');
        $otherUserId = $this->createTestUser('Marie', 'Martin');
        $user = new \User();
        $user->getFromDB($userId);

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $otherUserId;
        Remise::handleItemAssignment($computer);

        ob_start();
        PassportEvent::showForUser($user);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('Aucun événement enregistré pour le moment.', 'remise'), $html);
    }

    public function testShowForUserFallsBackWhenDeviceIsPurged(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Passport User Purged');
        $computer = $this->createTestComputerWithSerial($entityId, 'PHPUnit PC Passport Purged', 'SN-PURGED');
        $userId = $this->createTestUser('Jean', 'Dupont');
        $user = new \User();
        $user->getFromDB($userId);

        $computer->oldvalues = ['users_id' => 0];
        $computer->fields['users_id'] = $userId;
        Remise::handleItemAssignment($computer);
        $computer->delete(['id' => $computer->getID()], true);

        ob_start();
        PassportEvent::showForUser($user);
        $html = ob_get_clean();

        $this->assertStringContainsString(__('matériel supprimé', 'remise'), $html);
        $this->assertStringNotContainsString('SN-PURGED', $html);
    }

    public function testGetAccountBoundsUsesBeginDateWhenSet(): void
    {
        $userId = $this->createTestUser('Jean', 'Dupont', ['begin_date' => '2020-01-15 00:00:00']);
        $user = new \User();
        $user->getFromDB($userId);

        $bounds = PassportEvent::getAccountBounds($user);

        $this->assertSame('2020-01-15 00:00:00', $bounds['start']);
        $this->assertNull($bounds['end'], 'Un compte actif ne doit jamais avoir de borne de fin.');
        $this->assertFalse($bounds['is_deleted']);
    }

    public function testGetAccountBoundsFallsBackToDateCreationWhenNoBeginDate(): void
    {
        $userId = $this->createTestUser('Jean', 'Dupont');
        $user = new \User();
        $user->getFromDB($userId);

        $bounds = PassportEvent::getAccountBounds($user);

        $this->assertSame($user->fields['date_creation'], $bounds['start']);
    }

    public function testGetAccountBoundsReturnsEndDateWhenAccountDisabled(): void
    {
        $userId = $this->createTestUser('Jean', 'Dupont', [
            'is_active' => 0,
            'end_date'  => '2023-06-01 00:00:00',
        ]);
        $user = new \User();
        $user->getFromDB($userId);

        $bounds = PassportEvent::getAccountBounds($user);

        $this->assertSame('2023-06-01 00:00:00', $bounds['end']);
        $this->assertFalse($bounds['is_deleted']);
    }

    public function testGetAccountBoundsFallsBackToDateModWhenDisabledWithoutEndDate(): void
    {
        $userId = $this->createTestUser('Jean', 'Dupont', ['is_active' => 0]);
        $user = new \User();
        $user->getFromDB($userId);

        $bounds = PassportEvent::getAccountBounds($user);

        $this->assertNotNull($bounds['end'], 'Sans end_date, le repli sur date_mod doit quand meme fournir une borne de fin.');
        $this->assertSame($user->fields['date_mod'], $bounds['end']);
    }

    public function testGetAccountBoundsMarksIsDeletedWhenAccountDeleted(): void
    {
        $userId = $this->createTestUser('Jean', 'Dupont', ['is_deleted' => 1]);
        $user = new \User();
        $user->getFromDB($userId);

        $bounds = PassportEvent::getAccountBounds($user);

        $this->assertTrue($bounds['is_deleted']);
        $this->assertNotNull($bounds['end']);
    }
}
