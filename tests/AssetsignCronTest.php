<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Reminder;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Token;
use RuntimeException;

/**
 * Couvre la logique de relance/expiration/alerte automatiques (runReminders(),
 * runExpiration(), runExpiryWarnings()) : de la logique de dates fragile, deja
 * source d'un bug reel par le passe (resolution de config globale au lieu de
 * par-assetsign, cf. TROUBLESHOOTING.md), jamais verifiee par une suite automatisee jusqu'ici.
 */
class AssetsignCronTest extends AssetsignTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ces methodes parcourent TOUTE la table sans filtre d'entite : sans ce
        // nettoyage, d'anciennes assetsigns de tests manuels deja presentes en
        // base fausseraient les comptes retournes par chaque test.
        $this->clearAwaitingSignatureAssetsigns();
    }

    public function testRunRemindersSendsWhenDelayElapsed(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Reminder Due');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Reminder Due');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $this->backdateSentDate($assetsign, 4); // delai par defaut : 1ere relance a J+3

        $count = Assetsign::runReminders();

        $this->assertSame(1, $count);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(1, (int) $assetsign->fields['reminder_count']);
        $this->assertSame(1, Reminder::countForAssetsign($assetsign->getID()));
    }

    public function testRunRemindersSkipsWhenNotYetDue(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Reminder NotDue');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Reminder NotDue');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $this->backdateSentDate($assetsign, 1); // 1ere relance due a J+3, pas encore atteinte

        $count = Assetsign::runReminders();

        $this->assertSame(0, $count);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(0, (int) $assetsign->fields['reminder_count']);
    }

    public function testRunRemindersRespectsMaxRemindersLimit(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Reminder MaxReached');
        Config::upsertForEntity($entityId, ['max_reminders' => 1]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Reminder MaxReached');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $assetsign->update(['id' => $assetsign->getID(), 'reminder_count' => 1]);
        $this->backdateSentDate($assetsign, 30); // tres largement au-dela de tout delai

        $count = Assetsign::runReminders();

        $this->assertSame(0, $count, 'max_reminders=1 deja atteint : aucune relance supplementaire ne doit partir.');
    }

    public function testRunExpirationExpiresAssetsignBeyondValidity(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Expiration Due');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Expiration Due');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $this->backdateSentDate($assetsign, 31); // validite par defaut : 30 jours

        $count = Assetsign::runExpiration();

        $this->assertSame(1, $count);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_EXPIRED, (int) $assetsign->fields['status']);
        $this->assertNotEmpty($assetsign->fields['date_expired']);
        $this->assertNull(Token::getExpiryForAssetsign($assetsign->getID()), 'Le jeton doit avoir ete invalide par expiration.');
    }

    public function testRunExpirationLeavesRecentAssetsignUntouched(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Expiration NotYet');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Expiration NotYet');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $this->backdateSentDate($assetsign, 5);

        $count = Assetsign::runExpiration();

        $this->assertSame(0, $count);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(Assetsign::STATUS_SENT, (int) $assetsign->fields['status']);
    }

    public function testRunExpiryWarningsMarksSentWithinWindow(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Warning Window');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Warning Window');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        // Fenetre par defaut : [validite(30) - alerte(3), validite(30)] = [27, 30].
        $this->backdateSentDate($assetsign, 28);

        $count = Assetsign::runExpiryWarnings();

        $this->assertSame(1, $count);
        $assetsign->getFromDB($assetsign->getID());
        $this->assertSame(1, (int) $assetsign->fields['expiry_warning_sent']);
    }

    public function testRunExpiryWarningsSkipsAlreadyNotifiedAssetsign(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Warning AlreadySent');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Warning AlreadySent');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $this->backdateSentDate($assetsign, 28);
        $assetsign->update(['id' => $assetsign->getID(), 'expiry_warning_sent' => 1]);

        $count = Assetsign::runExpiryWarnings();

        $this->assertSame(0, $count, "Une remise deja notifiee ne doit pas l'etre une deuxieme fois.");
    }

    public function testRunExpiryWarningsDisabledForEntityIsSkipped(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Warning Disabled');
        Config::upsertForEntity($entityId, ['expiry_warning_days' => 0]);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Warning Disabled');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $this->backdateSentDate($assetsign, 28);

        $count = Assetsign::runExpiryWarnings();

        $this->assertSame(0, $count, 'expiry_warning_days=0 : alerte desactivee pour cette entite.');
    }

    public function testSendReminderNowThrowsWhenAssetsignNotAwaitingSignature(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron SendNow Guard');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron SendNow Guard');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $assetsign->update(['id' => $assetsign->getID(), 'status' => Assetsign::STATUS_SIGNED]);

        $this->expectException(RuntimeException::class);
        $assetsign->sendReminderNow();
    }

    /**
     * Garde-fou defensif ajoute suite a un vrai crash rencontre en testant les
     * commandes console (plugins:assetsign:run-reminders/run-expiration/warn-expiring) :
     * une remise au statut Envoye/Consulte sans date_sent (donnee corrompue -
     * structurellement impossible via launchWorkflow(), mais rencontree en
     * pratique avec une ligne manipulee directement en base) faisait planter
     * TOUT le lot avec une TypeError, bloquant les relances/expirations de
     * TOUTES les autres assetsigns du meme passage. createBareAssetsign() (comme la
     * ligne corrompue reelle) ne renseigne jamais date_sent.
     */
    public function testRunRemindersSkipsAssetsignWithMissingDateSentWithoutCrashing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Reminder MissingDateSent');
        $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $count = Assetsign::runReminders();

        $this->assertSame(0, $count, 'La remise sans date_sent doit etre ignoree, pas relancee.');
    }

    public function testRunExpirationSkipsAssetsignWithMissingDateSentWithoutCrashing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Expiration MissingDateSent');
        $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $count = Assetsign::runExpiration();

        $this->assertSame(0, $count, 'La remise sans date_sent doit etre ignoree, pas marquee expiree.');
    }

    public function testRunExpiryWarningsSkipsAssetsignWithMissingDateSentWithoutCrashing(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron ExpiryWarning MissingDateSent');
        $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $count = Assetsign::runExpiryWarnings();

        $this->assertSame(0, $count, 'La remise sans date_sent doit etre ignoree, pas alertee.');
    }

    /**
     * Une remise sans date_sent ne doit pas non plus empecher les AUTRES
     * assetsigns (legitimes) du meme lot d'etre correctement traitees - c'est le
     * scenario exact rencontre en conditions reelles (test des commandes
     * console avec plusieurs assetsigns de test presentes en meme temps).
     */
    public function testRunRemindersProcessesOtherAssetsignsDespiteOneWithMissingDateSent(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Cron Reminder Mixed');
        $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Cron Reminder Mixed Legit');
        $legit = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);
        $this->backdateSentDate($legit, 4);

        $count = Assetsign::runReminders();

        $this->assertSame(1, $count, 'La remise legitime doit etre relancee malgre la ligne corrompue dans le meme lot.');
    }

    private function backdateSentDate(Assetsign $assetsign, int $daysAgo): void
    {
        $assetsign->update([
            'id'        => $assetsign->getID(),
            'date_sent' => date('Y-m-d H:i:s', time() - $daysAgo * DAY_TIMESTAMP),
        ]);
    }
}
