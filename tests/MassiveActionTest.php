<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Reminder;
use GlpiPlugin\Assetsign\Assetsign;
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
class MassiveActionTest extends AssetsignTestCase
{
    private function runMassiveAction(string $action, array $ids): array
    {
        $items = [];
        foreach ($ids as $id) {
            $items[$id] = $id;
        }

        $ma = new MassiveAction([
            'items'       => [Assetsign::class => $items],
            'action'      => $action,
            'processor'   => Assetsign::class,
            'action_name' => $action,
        ], [], 'process');

        return $ma->process();
    }

    public function testSendReminderMarksOkAndIncrementsReminderCount(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MassiveAction SendReminder');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $results = $this->runMassiveAction('send_reminder', [$assetsign->getID()]);

        $this->assertSame(1, $results['ok']);
        $this->assertSame(0, $results['ko']);
        $this->assertSame(
            1,
            Reminder::countForAssetsign($assetsign->getID()),
            'sendReminderNow() doit avoir ete reellement execute, pas seulement marque OK.'
        );
    }

    public function testSendReminderMarksKoForAlreadySignedAssetsignWithoutBlockingOthers(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MassiveAction Mixed');
        $pending = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);
        $signed  = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SIGNED);

        // Meme selection melangeant une remise encore en attente et une deja
        // signee : la seconde doit echouer en KO (sendReminderNow() leve une
        // RuntimeException, cf. Assetsign.php) SANS empecher la premiere de
        // reussir malgre l'exception levee sur l'autre — c'est precisement le
        // comportement documente dans TROUBLESHOOTING.md.
        $results = $this->runMassiveAction('send_reminder', [$pending->getID(), $signed->getID()]);

        $this->assertSame(1, $results['ok'], 'La remise encore en attente doit reussir.');
        $this->assertSame(1, $results['ko'], 'La remise deja signee doit echouer en KO, pas planter tout le lot.');
    }

    public function testCancelRequestMarksOkAndCancelsPendingAssetsign(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MassiveAction CancelRequest');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $results = $this->runMassiveAction('cancel_request', [$assetsign->getID()]);

        $this->assertSame(1, $results['ok']);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_CANCELLED, (int) $assetsign->fields['status']);
    }

    public function testCancelRequestMarksKoForAlreadyCancelledAssetsign(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit MassiveAction CancelRequest Ko');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_CANCELLED);

        $results = $this->runMassiveAction('cancel_request', [$assetsign->getID()]);

        $this->assertSame(0, $results['ok']);
        $this->assertSame(1, $results['ko']);
    }

    /**
     * Faille reelle trouvee et corrigee en conditions reelles (cf.
     * TROUBLESHOOTING.md), meme famille que celle de Assetsign::createManual() :
     * le framework MassiveAction de GLPI ne filtre PAS lui-meme les ids
     * soumis par entite pour une action specifique de plugin - c'est a
     * processMassiveActionsForOneItemtype() de le faire. Simule ici une
     * remise dans une entite volontairement absente de
     * $_SESSION['glpiactiveentities'] (insertion directe, sans passer par
     * createTestEntity() qui l'y enregistre automatiquement).
     */
    public function testSendReminderAndCancelRequestRejectAssetsignInEntityOutsideCurrentAccess(): void
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
        $assetsign = $this->createBareAssetsign($inaccessibleEntityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $results = $this->runMassiveAction('send_reminder', [$assetsign->getID()]);
        $this->assertSame(0, $results['ok']);
        $this->assertSame(1, $results['noright']);
        $this->assertSame(0, Reminder::countForAssetsign($assetsign->getID()), "La relance n'aurait jamais du etre envoyee.");

        $results = $this->runMassiveAction('cancel_request', [$assetsign->getID()]);
        $this->assertSame(0, $results['ok']);
        $this->assertSame(1, $results['noright']);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_SENT, (int) $assetsign->fields['status'], "La remise n'aurait jamais du etre annulee.");
    }
}
