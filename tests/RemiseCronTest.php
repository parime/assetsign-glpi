<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Config;
use GlpiPlugin\Remise\Reminder;
use GlpiPlugin\Remise\Remise;
use GlpiPlugin\Remise\Token;
use RuntimeException;

/**
 * Couvre la logique de relance/expiration/alerte automatiques (runReminders(),
 * runExpiration(), runExpiryWarnings()) : de la logique de dates fragile, deja
 * source d'un bug reel par le passe (resolution de config globale au lieu de
 * par-remise, cf. TROUBLESHOOTING.md), jamais verifiee par une suite automatisee jusqu'ici.
 */
class RemiseCronTest extends RemiseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ces methodes parcourent TOUTE la table sans filtre d'entite : sans ce
        // nettoyage, d'anciennes remises de tests manuels deja presentes en
        // base fausseraient les comptes retournes par chaque test.
        $this->clearAwaitingSignatureRemises();
    }

    public function testRunRemindersSendsWhenDelayElapsed(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Reminder Due');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Reminder Due');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $this->backdateSentDate($remise, 4); // delai par defaut : 1ere relance a J+3

        $count = Remise::runReminders();

        $this->assertSame(1, $count);
        $remise->getFromDB($remise->getID());
        $this->assertSame(1, (int) $remise->fields['reminder_count']);
        $this->assertSame(1, Reminder::countForRemise($remise->getID()));
    }

    public function testRunRemindersSkipsWhenNotYetDue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Reminder NotDue');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Reminder NotDue');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $this->backdateSentDate($remise, 1); // 1ere relance due a J+3, pas encore atteinte

        $count = Remise::runReminders();

        $this->assertSame(0, $count);
        $remise->getFromDB($remise->getID());
        $this->assertSame(0, (int) $remise->fields['reminder_count']);
    }

    public function testRunRemindersRespectsMaxRemindersLimit(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Reminder MaxReached');
        Config::upsertForEntity($entityId, ['max_reminders' => 1]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Reminder MaxReached');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $remise->update(['id' => $remise->getID(), 'reminder_count' => 1]);
        $this->backdateSentDate($remise, 30); // tres largement au-dela de tout delai

        $count = Remise::runReminders();

        $this->assertSame(0, $count, 'max_reminders=1 deja atteint : aucune relance supplementaire ne doit partir.');
    }

    public function testRunExpirationExpiresRemiseBeyondValidity(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Expiration Due');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Expiration Due');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $this->backdateSentDate($remise, 31); // validite par defaut : 30 jours

        $count = Remise::runExpiration();

        $this->assertSame(1, $count);
        $remise->getFromDB($remise->getID());
        $this->assertSame(Remise::STATUS_EXPIRED, (int) $remise->fields['status']);
        $this->assertNotEmpty($remise->fields['date_expired']);
        $this->assertNull(Token::getExpiryForRemise($remise->getID()), 'Le jeton doit avoir ete invalide par expiration.');
    }

    public function testRunExpirationLeavesRecentRemiseUntouched(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Expiration NotYet');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Expiration NotYet');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $this->backdateSentDate($remise, 5);

        $count = Remise::runExpiration();

        $this->assertSame(0, $count);
        $remise->getFromDB($remise->getID());
        $this->assertSame(Remise::STATUS_SENT, (int) $remise->fields['status']);
    }

    public function testRunExpiryWarningsMarksSentWithinWindow(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Warning Window');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Warning Window');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        // Fenetre par defaut : [validite(30) - alerte(3), validite(30)] = [27, 30].
        $this->backdateSentDate($remise, 28);

        $count = Remise::runExpiryWarnings();

        $this->assertSame(1, $count);
        $remise->getFromDB($remise->getID());
        $this->assertSame(1, (int) $remise->fields['expiry_warning_sent']);
    }

    public function testRunExpiryWarningsSkipsAlreadyNotifiedRemise(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Warning AlreadySent');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Warning AlreadySent');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $this->backdateSentDate($remise, 28);
        $remise->update(['id' => $remise->getID(), 'expiry_warning_sent' => 1]);

        $count = Remise::runExpiryWarnings();

        $this->assertSame(0, $count, "Une remise deja notifiee ne doit pas l'etre une deuxieme fois.");
    }

    public function testRunExpiryWarningsDisabledForEntityIsSkipped(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Warning Disabled');
        Config::upsertForEntity($entityId, ['expiry_warning_days' => 0]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Warning Disabled');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $this->backdateSentDate($remise, 28);

        $count = Remise::runExpiryWarnings();

        $this->assertSame(0, $count, 'expiry_warning_days=0 : alerte desactivee pour cette entite.');
    }

    public function testSendReminderNowThrowsWhenRemiseNotAwaitingSignature(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron SendNow Guard');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron SendNow Guard');
        $remise = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $remise->update(['id' => $remise->getID(), 'status' => Remise::STATUS_SIGNED]);

        $this->expectException(RuntimeException::class);
        $remise->sendReminderNow();
    }

    /**
     * Garde-fou defensif ajoute suite a un vrai crash rencontre en testant les
     * commandes console (plugins:remise:run-reminders/run-expiration/warn-expiring) :
     * une remise au statut Envoye/Consulte sans date_sent (donnee corrompue -
     * structurellement impossible via launchWorkflow(), mais rencontree en
     * pratique avec une ligne manipulee directement en base) faisait planter
     * TOUT le lot avec une TypeError, bloquant les relances/expirations de
     * TOUTES les autres remises du meme passage. createBareRemise() (comme la
     * ligne corrompue reelle) ne renseigne jamais date_sent.
     */
    public function testRunRemindersSkipsRemiseWithMissingDateSentWithoutCrashing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Reminder MissingDateSent');
        $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        $count = Remise::runReminders();

        $this->assertSame(0, $count, 'La remise sans date_sent doit etre ignoree, pas relancee.');
    }

    public function testRunExpirationSkipsRemiseWithMissingDateSentWithoutCrashing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Expiration MissingDateSent');
        $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        $count = Remise::runExpiration();

        $this->assertSame(0, $count, 'La remise sans date_sent doit etre ignoree, pas marquee expiree.');
    }

    public function testRunExpiryWarningsSkipsRemiseWithMissingDateSentWithoutCrashing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron ExpiryWarning MissingDateSent');
        $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        $count = Remise::runExpiryWarnings();

        $this->assertSame(0, $count, 'La remise sans date_sent doit etre ignoree, pas alertee.');
    }

    /**
     * Une remise sans date_sent ne doit pas non plus empecher les AUTRES
     * remises (legitimes) du meme lot d'etre correctement traitees - c'est le
     * scenario exact rencontre en conditions reelles (test des commandes
     * console avec plusieurs remises de test presentes en meme temps).
     */
    public function testRunRemindersProcessesOtherRemisesDespiteOneWithMissingDateSent(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Reminder Mixed');
        $this->createBareRemise($entityId, Remise::TYPE_HANDOVER, Remise::STATUS_SENT);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Reminder Mixed Legit');
        $legit = Remise::createManual('Computer', $computer->getID(), Remise::TYPE_DON, 2);
        $this->backdateSentDate($legit, 4);

        $count = Remise::runReminders();

        $this->assertSame(1, $count, 'La remise legitime doit etre relancee malgre la ligne corrompue dans le meme lot.');
    }

    private function backdateSentDate(Remise $remise, int $daysAgo): void
    {
        $remise->update([
            'id'        => $remise->getID(),
            'date_sent' => date('Y-m-d H:i:s', time() - $daysAgo * DAY_TIMESTAMP),
        ]);
    }
}
