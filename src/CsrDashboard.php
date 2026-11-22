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

namespace GlpiPlugin\Assetsign;

use CommonGLPI;
use Session;

/**
 * Issue #142 : Parc > AssetSign, rubrique « Pilotage du parc », page « Tableau de bord RSE » (ancre CommonGLPI sans table, vers
 * front/csr.php), visible avec le droit de lecture assetsign.
 */
class CsrDashboard extends CommonGLPI
{
   public static function getTypeName($nb = 0): string {
       return __('Tableau de bord RSE', 'assetsign');
   }

   public static function getMenuName(): string {
       return self::getTypeName();
   }

   public static function getIcon(): string {
       return 'ti ti-leaf';
   }

   /**
    * @return array{title: string, page: string, icon: string}|false
    */
   public static function getMenuContent() {
      if (!Session::haveRight(Profile::RIGHT_ASSETSIGN, READ)) {
          return false;
      }
       // Première page de la rubrique « Pilotage du parc » de Parc > AssetSign (MenuSection).
       return [
           'title' => self::getMenuName(),
           'page'  => '/plugins/assetsign/front/csr.php',
           'icon'  => self::getIcon(),
       ];
   }
}
