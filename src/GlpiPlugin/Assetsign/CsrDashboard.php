<?php

namespace GlpiPlugin\Assetsign;

use CommonGLPI;
use Session;

/**
 * Issue #142 : entrée de menu Outils > « Tableau de bord RSE » (ancre CommonGLPI sans table, vers
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
       return [
           'title' => self::getMenuName(),
           'page'  => '/plugins/assetsign/front/csr.php',
           'icon'  => self::getIcon(),
       ];
   }
}
