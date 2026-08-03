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

    /**
     * Faille reelle trouvee et corrigee en conditions reelles (cf.
     * TROUBLESHOOTING.md), meme famille que celle de Remise::createManual() :
     * le framework MassiveAction de GLPI ne filtre PAS lui-meme les ids
     * soumis par entite pour une action specifique de plugin - c'est a
     * processMassiveActionsForOneItemtype() de le faire. Simule ici une
     * remise dans une entite volontairement absente de
     * $_SESSION['glpiactiveentities'] (insertion directe, sans passer par
     * createTestEntity() qui l'y enregistre automatiquement).
     */
    public function testSendReminderAndCancelRequestRejectRemiseInEntityOutsideCurrentAccess(): void
    {
        global $DB;

        $inaccessibleEntityId = random_int(700000, 799999);
        $DB->insert('glpi_entities', [
            'id'           => $inaccessibleEntityId,
            'name'         => 'PHPUnit MassiveAction Entite Inaccessible',
            'completename' => 'PHPUnit MassiveAction Entite Inaccessible',
            'entities_id'  => 0,
            'level'        => 2,
        ]);
        $remise = $this->createBareRemise($inaccessibleEntityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        $results = $this->runMassiveAction('send_reminder', [$remise->getID()]);
        $this->assertSame(0, $results['ok']);
        $this->assertSame(1, $results['noright']);
        $this->assertSame(0, Reminder::countForRemise($remise->getID()), "La relance n'aurait jamais du etre envoyee.");

        $results = $this->runMassiveAction('cancel_request', [$remise->getID()]);
        $this->assertSame(0, $results['ok']);
        $this->assertSame(1, $results['noright']);
        $remise->getFromDB($remise->getID());
        $this->assertSame(Remise::STATUS_SENT, (int) $remise->fields['status'], "La remise n'aurait jamais du etre annulee.");
    }
}
