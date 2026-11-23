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

/**
 * Force la langue de session en anglais (en_GB) pendant chaque test de la classe, puis la
 * restaure (issue #397) : la CI tourne en anglais et l'instance de développement en français.
 * À réserver aux tests qui vérifient un texte composé (libellé + nombre) faute d'identifiant
 * stable ; la méthode concernée est déclarée dans .glpi-security-exceptions.
 */
trait ForcesEnglishLanguage
{
    private string $previousLanguage = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousLanguage = (string) ($_SESSION['glpilanguage'] ?? '');
        $_SESSION['glpilanguage'] = 'en_GB';
        \Session::loadLanguage('en_GB');
    }

    protected function tearDown(): void
    {
        $_SESSION['glpilanguage'] = $this->previousLanguage;
        \Session::loadLanguage($this->previousLanguage);
        parent::tearDown();
    }
}
