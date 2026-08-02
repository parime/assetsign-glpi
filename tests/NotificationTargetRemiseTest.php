<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\NotificationTargetRemise;
use GlpiPlugin\Remise\Remise;
use Notification;

/**
 * Couvre la logique propre au plugin dans NotificationTargetRemise : quelle
 * cible (Beneficiaire/Technicien) est proposee pour quel evenement
 * (addAdditionalTargets()), et quelles balises de gabarit sont renseignees
 * (addDataForTemplate()) — jamais couvert par une suite automatisee jusqu'ici
 * (seulement verifie a la main, par reception reelle d'e-mails, cf.
 * TROUBLESHOOTING.md).
 *
 * Volontairement PAS de test de bout en bout jusqu'a la resolution reelle du
 * destinataire (addUserFieldByEmail()/addToRecipientsList()) : cette derniere
 * etape traverse une bonne partie de la tuyauterie interne de
 * NotificationTarget (setEvent(), addAdditionnalUserInfo()...) qui n'est pas
 * du code du plugin — la couverture s'arrete a la frontiere de ce que ce
 * plugin controle reellement.
 */
class NotificationTargetRemiseTest extends RemiseTestCase
{
    /** @return array Cibles de type utilisateur (Beneficiaire ET/OU Technicien) proposees pour cet evenement. */
    private function availableUserTargets(NotificationTargetRemise $target): array
    {
        return $target->notification_targets_labels[Notification::USER_TYPE] ?? [];
    }

    public function testNewEventTargetsOnlyBeneficiary(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit NotifTarget New');
        $remise = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        $target = new NotificationTargetRemise($entityId, 'new', $remise);
        $targets = $this->availableUserTargets($target);

        $this->assertArrayHasKey(NotificationTargetRemise::TARGET_BENEFICIARY, $targets);
        $this->assertArrayNotHasKey(NotificationTargetRemise::TARGET_TECHNICIAN, $targets);
    }

    public function testExpiringSoonEventTargetsOnlyTechnicianNotBeneficiary(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit NotifTarget ExpiringSoon');
        $remise = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        $target = new NotificationTargetRemise($entityId, 'expiring_soon', $remise);
        $targets = $this->availableUserTargets($target);

        // Le beneficiaire recoit deja des relances periodiques pendant la meme
        // fenetre ; lui envoyer aussi expiring_soon (adresse au technicien)
        // ferait doublon, cf. commentaire de addAdditionalTargets().
        $this->assertArrayNotHasKey(NotificationTargetRemise::TARGET_BENEFICIARY, $targets);
        $this->assertArrayHasKey(NotificationTargetRemise::TARGET_TECHNICIAN, $targets);
    }

    public function testSignedEventTargetsBothBeneficiaryAndTechnician(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit NotifTarget Signed');
        $remise = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SIGNED);

        $target = new NotificationTargetRemise($entityId, 'signed', $remise);
        $targets = $this->availableUserTargets($target);

        $this->assertArrayHasKey(NotificationTargetRemise::TARGET_BENEFICIARY, $targets);
        $this->assertArrayHasKey(NotificationTargetRemise::TARGET_TECHNICIAN, $targets);
    }

    public function testAddDataForTemplatePopulatesExpectedTags(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit NotifTarget Data');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC NotifTarget');

        $remise = new Remise();
        $id = (int) $remise->add([
            'entities_id' => $entityId,
            'itemtype'    => 'Computer',
            'items_id'    => $computer->getID(),
            'users_id'    => 2,
            'type'        => Remise::TYPE_HANDOVER,
            'status'      => Remise::STATUS_SENT,
        ]);
        $remise->getFromDB($id);

        $target = new NotificationTargetRemise($entityId, 'new', $remise);
        $target->addDataForTemplate('new');

        $this->assertSame($id, $target->data['##remise.id##']);
        $this->assertSame(Remise::getTypes()[Remise::TYPE_HANDOVER], $target->data['##remise.type##']);
        $this->assertSame('PHPUnit PC NotifTarget', $target->data['##remise.item.name##']);
        $this->assertArrayHasKey('##remise.user.name##', $target->data);
        $this->assertArrayHasKey('##remise.sign_url##', $target->data);
        $this->assertArrayHasKey('##remise.deadline##', $target->data);
    }
}
