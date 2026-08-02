<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Reminder;
use GlpiPlugin\Remise\Remise;
use MassiveAction;

/**
 * Couvre les actions groupees "Relancer maintenant"/"Annuler la demande"
 * (getSpecificMassiveActions()/processMassiveActionsForOneItemtype()), cf.
 * TROUBLESHOOTING.md — jusqu'ici verifiees uniquement a la main (curl contre
 * une vraie instance), jamais par une suite automatisee.
 *
 * Un MassiveAction est construit directement au stade 'process' (comme le
 * fait front/massiveaction.php), avec le POST minimal que ce stade attend
 * (items/action/processor/action_name) : reproduire les stades 'initial'/
 * 'specialize' qui le precedent normalement demanderait un vrai contexte
 * HTTP (formulaire de selection, sous-formulaire de confirmation) hors de
 * portee d'un test unitaire.
 */
class MassiveActionTest extends RemiseTestCase
{
    private function runMassiveAction(string $action, array $ids): array
    {
        $items = [];
        foreach ($ids as $id) {
            $items[$id] = $id;
        }

        $ma = new MassiveAction([
            'items'       => [Remise::class => $items],
            'action'      => $action,
            'processor'   => Remise::class,
            'action_name' => $action,
        ], [], 'process');

        return $ma->process();
    }

    public function testSendReminderMarksOkAndIncrementsReminderCount(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MassiveAction SendReminder');
        $remise = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        $results = $this->runMassiveAction('send_reminder', [$remise->getID()]);

        $this->assertSame(1, $results['ok']);
        $this->assertSame(0, $results['ko']);
        $this->assertSame(
            1,
            Reminder::countForRemise($remise->getID()),
            'sendReminderNow() doit avoir ete reellement execute, pas seulement marque OK.'
        );
    }

    public function testSendReminderMarksKoForAlreadySignedRemiseWithoutBlockingOthers(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MassiveAction Mixed');
        $pending = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);
        $signed  = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SIGNED);

        // Meme selection melangeant une remise encore en attente et une deja
        // signee : la seconde doit echouer en KO (sendReminderNow() leve une
        // RuntimeException, cf. Remise.php) SANS empecher la premiere de
        // reussir malgre l'exception levee sur l'autre — c'est precisement le
        // comportement documente dans TROUBLESHOOTING.md.
        $results = $this->runMassiveAction('send_reminder', [$pending->getID(), $signed->getID()]);

        $this->assertSame(1, $results['ok'], 'La remise encore en attente doit reussir.');
        $this->assertSame(1, $results['ko'], 'La remise deja signee doit echouer en KO, pas planter tout le lot.');
    }

    public function testCancelRequestMarksOkAndCancelsPendingRemise(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MassiveAction CancelRequest');
        $remise = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        $results = $this->runMassiveAction('cancel_request', [$remise->getID()]);

        $this->assertSame(1, $results['ok']);
        $remise->getFromDB($remise->getID());
        $this->assertSame(Remise::STATUS_CANCELLED, (int) $remise->fields['status']);
    }

    public function testCancelRequestMarksKoForAlreadyCancelledRemise(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MassiveAction CancelRequest Ko');
        $remise = $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_CANCELLED);

        $results = $this->runMassiveAction('cancel_request', [$remise->getID()]);

        $this->assertSame(0, $results['ok']);
        $this->assertSame(1, $results['ko']);
    }
}
