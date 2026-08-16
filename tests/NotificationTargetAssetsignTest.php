<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\NotificationTargetAssetsign;
use GlpiPlugin\Assetsign\Assetsign;
use Notification;

/**
 * Couvre la logique propre au plugin dans NotificationTargetAssetsign : quelle
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
class NotificationTargetAssetsignTest extends AssetsignTestCase
{
    /** @return array Cibles de type utilisateur (Beneficiaire ET/OU Technicien) proposees pour cet evenement. */
    private function availableUserTargets(NotificationTargetAssetsign $target): array
    {
        return $target->notification_targets_labels[Notification::USER_TYPE] ?? [];
    }

    public function testNewEventTargetsOnlyBeneficiary(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit NotifTarget New');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $target = new NotificationTargetAssetsign($entityId, 'new', $assetsign);
        $targets = $this->availableUserTargets($target);

        $this->assertArrayHasKey(NotificationTargetAssetsign::TARGET_BENEFICIARY, $targets);
        $this->assertArrayNotHasKey(NotificationTargetAssetsign::TARGET_TECHNICIAN, $targets);
    }

    public function testExpiringSoonEventTargetsOnlyTechnicianNotBeneficiary(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit NotifTarget ExpiringSoon');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $target = new NotificationTargetAssetsign($entityId, 'expiring_soon', $assetsign);
        $targets = $this->availableUserTargets($target);

        // Le beneficiaire recoit deja des relances periodiques pendant la meme
        // fenetre ; lui envoyer aussi expiring_soon (adresse au technicien)
        // ferait doublon, cf. commentaire de addAdditionalTargets().
        $this->assertArrayNotHasKey(NotificationTargetAssetsign::TARGET_BENEFICIARY, $targets);
        $this->assertArrayHasKey(NotificationTargetAssetsign::TARGET_TECHNICIAN, $targets);
    }

    public function testSignedEventTargetsBothBeneficiaryAndTechnician(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit NotifTarget Signed');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SIGNED);

        $target = new NotificationTargetAssetsign($entityId, 'signed', $assetsign);
        $targets = $this->availableUserTargets($target);

        $this->assertArrayHasKey(NotificationTargetAssetsign::TARGET_BENEFICIARY, $targets);
        $this->assertArrayHasKey(NotificationTargetAssetsign::TARGET_TECHNICIAN, $targets);
    }

    public function testAddDataForTemplatePopulatesExpectedTags(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit NotifTarget Data');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC NotifTarget');

        $assetsign = new Assetsign();
        $id = (int) $assetsign->add([
            'entities_id' => $entityId,
            'itemtype'    => 'Computer',
            'items_id'    => $computer->getID(),
            'users_id'    => 2,
            'type'        => Assetsign::TYPE_HANDOVER,
            'status'      => Assetsign::STATUS_SENT,
        ]);
        $assetsign->getFromDB($id);

        $target = new NotificationTargetAssetsign($entityId, 'new', $assetsign);
        $target->addDataForTemplate('new');

        $this->assertSame((string) $id, $target->data['##assetsign.id##']);
        $this->assertSame(Assetsign::getTypes()[Assetsign::TYPE_HANDOVER], $target->data['##assetsign.type##']);
        $this->assertSame('PHPUnit PC NotifTarget', $target->data['##assetsign.item.name##']);
        $this->assertArrayHasKey('##assetsign.user.name##', $target->data);
        $this->assertArrayHasKey('##assetsign.sign_url##', $target->data);
        $this->assertArrayHasKey('##assetsign.deadline##', $target->data);
    }
}
