<?php

namespace GlpiPlugin\Remise\Tests;

use GlpiPlugin\Remise\Config;

/**
 * Verifie l'heritage de configuration par entite : une entite sans config
 * propre doit heriter de son ancetre le PLUS PROCHE qui en a une (pas
 * directement de la racine), cf. INSTALLATION.md, section "3. Configurer".
 */
class ConfigTest extends RemiseTestCase
{
    /**
     * Garde-fou structurel : User ne doit JAMAIS figurer parmi les types geres
     * par le plugin (Config::getAllManageableItemtypes()), puisque setup.php
     * enregistre les hooks ITEM_ADD/ITEM_UPDATE precisement a partir de cette
     * liste. Si User y figurait, un simple changement de nom (mariage,
     * divorce...) sur une fiche utilisateur declencherait a tort le
     * mecanisme de detection d'affectation (handleUserBasedTrigger()) et
     * generarait une fiche de remise/restitution parasite. Le nom affiche
     * ailleurs (fiche admin, notifications futures) reste a jour via un
     * appel direct a User::getFromDB() dans Remise::getBeneficiary() - aucun
     * declenchement necessaire pour ca.
     */
    public function testUserIsNeverAManagedItemtype(): void
    {
        $this->assertNotContains('User', Config::getAllManageableItemtypes());
    }

    public function testChildEntityInheritsClosestAncestorConfigNotRoot(): void
    {
        $regionId = $this->createTestEntity(0, 'PHPUnit Region');
        $siteId = $this->createTestEntity($regionId, 'PHPUnit Site');

        Config::upsertForEntity($regionId, ['sender_name' => 'Config de la region PHPUnit']);

        $configForSite = Config::getForEntity($siteId);
        $this->assertSame(
            'Config de la region PHPUnit',
            $configForSite->fields['sender_name'],
            'Le site (sans config propre) doit heriter de la config de la region (son parent direct), pas sauter directement a la racine.'
        );

        $configForRegion = Config::getForEntity($regionId);
        $this->assertSame('Config de la region PHPUnit', $configForRegion->fields['sender_name']);
    }

    public function testEntityWithoutOwnConfigInheritsRootConfig(): void
    {
        // L'entite racine (id=0) a toujours une ligne de config, seme des
        // l'installation du plugin (cf. Config::install()) : une entite sans
        // config propre et sans ancetre intermediaire configure recupere donc
        // TOUJOURS la ligne de la racine (entities_id=0 dans la ligne renvoyee),
        // jamais un tableau de defaut "orphelin" en memoire.
        $entityId = $this->createTestEntity(0, 'PHPUnit Orphan');

        $config = Config::getForEntity($entityId);

        $this->assertSame(0, (int) $config->fields['entities_id']);
        $this->assertNotEmpty($config->fields['managed_itemtypes']);
    }

    public function testDirectConfigTakesPrecedenceOverAncestor(): void
    {
        $regionId = $this->createTestEntity(0, 'PHPUnit Region Precedence');
        $siteId = $this->createTestEntity($regionId, 'PHPUnit Site Precedence');

        Config::upsertForEntity($regionId, ['sender_name' => 'Config region']);
        Config::upsertForEntity($siteId, ['sender_name' => 'Config du site lui-meme']);

        $config = Config::getForEntity($siteId);
        $this->assertSame('Config du site lui-meme', $config->fields['sender_name']);
    }
    public function testHealthScoreThresholdsAndColorsArePersisted(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Health Appearance');
        Config::upsertForEntity($entityId, [
            'health_score_good_threshold' => 65,
            // Une valeur superieure au seuil Bon est corrigee pour garder
            // une echelle lisible et sans zone impossible.
            'health_score_warning_threshold' => 80,
            'health_score_good_color' => '#0055aa',
            'health_score_warning_color' => '#cc7700',
            'health_score_critical_color' => '#772244',
        ]);

        $config = Config::getForEntity($entityId);
        $this->assertSame(65, (int) $config->fields['health_score_good_threshold']);
        $this->assertSame(65, (int) $config->fields['health_score_warning_threshold']);
        $this->assertSame('#0055aa', $config->fields['health_score_good_color']);
        $this->assertSame('#cc7700', $config->fields['health_score_warning_color']);
        $this->assertSame('#772244', $config->fields['health_score_critical_color']);
    }

    public function testHealthScoreColorFollowsConfiguredThresholds(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Health Colors');
        Config::upsertForEntity($entityId, [
            'health_score_good_threshold' => 75,
            'health_score_warning_threshold' => 45,
            'health_score_good_color' => '#1155cc',
            'health_score_warning_color' => '#dd8800',
            'health_score_critical_color' => '#aa1133',
        ]);

        $config = Config::getForEntity($entityId);
        $this->assertSame('#1155cc', $config->getHealthScoreColor(75));
        $this->assertSame('#dd8800', $config->getHealthScoreColor(74));
        $this->assertSame('#dd8800', $config->getHealthScoreColor(45));
        $this->assertSame('#aa1133', $config->getHealthScoreColor(44));
    }
}
