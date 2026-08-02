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
}
