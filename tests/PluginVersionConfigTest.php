<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign\Tests;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Compat\Http;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\PluginVersion;

/**
 * Issue #396 : composant commun « Version du plugin » dans la page de configuration. Le réglage
 * de vérification est global (contexte plugin:assetsign du cœur) et actif par défaut ; l'encadré
 * est en tête de l'onglet Général. Assertions sur le balisage (identifiants, icônes, liens),
 * jamais sur un texte traduit : la CI rend en anglais.
 */
final class PluginVersionConfigTest extends AssetsignTestCase
{
   public function testVersionCheckIsEnabledByDefaultAndGlobal(): void {
       \Config::deleteConfigurationValues(Http::CONFIG_CONTEXT, [Config::VERSION_CHECK_KEY]);
       $this->assertTrue(Config::isVersionCheckEnabled(), 'actif sans valeur enregistrée');

       Config::setVersionCheckEnabled(false);
       $this->assertFalse(Config::isVersionCheckEnabled());
       $this->assertSame(PluginVersion::REASON_DISABLED, Config::getPluginVersionStatus()['reason']);

       Config::setVersionCheckEnabled(true);
       $this->assertTrue(Config::isVersionCheckEnabled());
   }

   public function testBoxIsAtTheTopOfTheGeneralTab(): void {
       // Vérification désactivée : aucun appel à GitHub pendant le test.
       Config::setVersionCheckEnabled(false);
       $entityId = $this->createTestEntity(0, 'PHPUnit PluginVersion Config');

       ob_start();
       Config::showConfigForm($entityId);
       $html = (string) ob_get_clean();

       $general = strpos($html, 'id="tab-general"');
       $box = strpos($html, 'id="assetsign-plugin-version"');
       $this->assertNotFalse($general);
       $this->assertNotFalse($box, "L'encadré « Version du plugin » doit s'afficher.");
       $this->assertGreaterThan($general, $box);
       // Rien d'autre que des espaces et des commentaires entre l'onglet et l'encadré.
       $between = substr($html, $general, $box - $general);
       $this->assertStringNotContainsString('<input', $between);
       $this->assertStringContainsString('v' . PLUGIN_ASSETSIGN_VERSION, $html);
       $this->assertStringContainsString('ti-circle-dashed', $html, 'badge « Dernière version inconnue »');
       $this->assertStringContainsString('name="version_check"', $html);
       $this->assertStringNotContainsString('latest_github_version', $html);
   }

   public function testPartialShowsUpdateLinksAndReason(): void {
       $base = [
           'installed'    => '2.0.0',
           'latest'       => '2.1.0',
           'state'        => PluginVersion::STATE_UPDATE_AVAILABLE,
           'reason'       => null,
           'stale'        => false,
           'release_url'  => 'https://github.com/parime/assetsign-glpi/releases/tag/v2.1.0',
           'download_url' => 'https://github.com/parime/assetsign-glpi/releases/download/v2.1.0/assetsign-v2.1.0.tar.bz2',
           'last_success' => '2026-01-15 10:00:00',
           'checked_at'   => '2026-01-15 10:00:00',
       ];
       $render = static fn (array $status): string => TemplateRenderer::getInstance()
           ->render('@assetsign/_plugin_version.html.twig', ['plugin_version' => $status]);

       $html = $render($base);
       $this->assertStringContainsString('ti-alert-triangle', $html, 'badge « Mise à jour disponible »');
       $this->assertStringContainsString('v2.1.0', $html);
       $this->assertStringContainsString('href="' . $base['download_url'] . '"', $html);
       $this->assertStringContainsString('rel="noopener noreferrer"', $html);
       $this->assertStringContainsString('ti-clock', $html, 'date de la dernière vérification');

       $html = $render(['state' => PluginVersion::STATE_UP_TO_DATE, 'latest' => '2.0.0'] + $base);
       $this->assertStringContainsString('ti-circle-check', $html, 'badge « À jour »');
       $this->assertStringNotContainsString($base['download_url'], $html);

       $html = $render([
           'state'  => PluginVersion::STATE_UNKNOWN,
           'latest' => null,
           'reason' => PluginVersion::REASON_RATE_LIMITED,
       ] + $base);
       $this->assertStringContainsString('ti-circle-dashed', $html);
       $this->assertStringContainsString('ti-info-circle', $html, 'raison de l\'échec affichée');
       $this->assertStringNotContainsString('rate_limited', $html, 'raison traduite, pas son code');
   }
}
