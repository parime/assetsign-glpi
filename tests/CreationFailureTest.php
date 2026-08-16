<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\CreationFailure;
use GlpiPlugin\Assetsign\Dashboard\CardProvider;
use GlpiPlugin\Assetsign\Assetsign;

/**
 * Couvre CreationFailure et la carte de tableau de bord associee
 * (CardProvider::failures()) : rendre visible un echec de creation
 * automatique (createAssetsign()/launchWorkflow()) jusqu'ici isole en silence
 * par design (cf. TROUBLESHOOTING.md, plugin_assetsign_item_assignment()) pour
 * ne jamais faire planter la sauvegarde du materiel, mais du coup invisible
 * sans consulter files/_log/assetsign.log a la main - demande explicite apres
 * une question sur l'absence d'alerte en cas de changement d'Etat sans
 * utilisateur assigne.
 */
class CreationFailureTest extends AssetsignTestCase
{
    public function testRecordInsertsRetrievableRow(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit CreationFailure Record');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC CreationFailure');

        CreationFailure::record('Computer', $computer->getID(), $entityId, Assetsign::TYPE_HANDOVER, 'Raison de test PHPUnit.');

        global $DB;
        $rows = iterator_to_array($DB->request([
            'FROM'  => CreationFailure::getTable(),
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => $computer->getID()],
        ]));
        $row = reset($rows);

        $this->assertNotFalse($row, 'La ligne doit avoir ete inseree.');
        $this->assertSame($entityId, (int) $row['entities_id']);
        $this->assertSame(Assetsign::TYPE_HANDOVER, (int) $row['assetsign_type']);
        $this->assertSame('Raison de test PHPUnit.', $row['reason']);
    }

    public function testRecordAcceptsNullAssetsignTypeForGenericHookFailures(): void
    {
        // Cas du catch generique de plugin_assetsign_item_assignment() (hook.php) :
        // le type Assetsign attendu n'est pas forcement connu a ce niveau.
        $entityId = $this->createTestEntity(0, 'PHPUnit CreationFailure NullType');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC CreationFailure NullType');

        CreationFailure::record('Computer', $computer->getID(), $entityId, null, 'Exception generique interceptee.');

        global $DB;
        $rows = iterator_to_array($DB->request([
            'FROM'  => CreationFailure::getTable(),
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => $computer->getID()],
        ]));
        $row = reset($rows);

        $this->assertNull($row['assetsign_type']);
    }

    public function testDashboardCardCountsOnlyRecentFailuresInActiveEntity(): void
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit CreationFailure Card');
        $otherEntityId = $this->createTestEntity(0, 'PHPUnit CreationFailure Card Other');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC CreationFailure Card');

        // Meme entite, dans la fenetre recente (RECENT_WINDOW_DAYS) : doit compter.
        CreationFailure::record('Computer', $computer->getID(), $entityId, Assetsign::TYPE_HANDOVER, 'Recent, bonne entite.');

        // Meme entite, mais TROP ANCIEN (hors fenetre) : ne doit PAS compter.
        $DB->insert(CreationFailure::getTable(), [
            'entities_id'   => $entityId,
            'itemtype'      => 'Computer',
            'items_id'      => $computer->getID(),
            'assetsign_type'   => Assetsign::TYPE_HANDOVER,
            'reason'        => 'Trop ancien.',
            'date_creation' => date('Y-m-d H:i:s', strtotime('-' . (CreationFailure::RECENT_WINDOW_DAYS + 5) . ' days')),
        ]);

        // Entite DIFFERENTE, recent : ne doit pas compter dans le total restreint
        // a l'entite active choisie ci-dessous.
        CreationFailure::record('Computer', 99999, $otherEntityId, Assetsign::TYPE_HANDOVER, 'Bonne fenetre, mauvaise entite.');

        // getEntitiesRestrictCriteria() lit $_SESSION['glpiactiveentities'] (repli
        // sur l'entite 0 si absent, cf. verification manuelle avant d'ecrire ce
        // test) : sans le fixer explicitement sur $entityId, AUCUN des deux
        // enregistrements ci-dessus (tous deux sur des entites de test fraiches,
        // jamais 0) ne serait compte, faussant ce test. Restaure l'etat
        // precedent apres coup (meme raison que withEntityAccessAndBypassedRights()
        // dans OtherTemplateRenderingTest : ces tests partagent le meme process
        // PHPUnit que les autres).
        $previousActiveEntities = $_SESSION['glpiactiveentities'] ?? null;
        $_SESSION['glpiactiveentities'] = [$entityId];
        try {
            $card = CardProvider::failures();
        } finally {
            if ($previousActiveEntities === null) {
                unset($_SESSION['glpiactiveentities']);
            } else {
                $_SESSION['glpiactiveentities'] = $previousActiveEntities;
            }
        }

        $this->assertSame(1, $card['number'], 'Seul l\'echec recent de l\'entite active doit etre compte.');
        $this->assertArrayHasKey('label', $card);
        $this->assertArrayHasKey('url', $card);
    }
}
