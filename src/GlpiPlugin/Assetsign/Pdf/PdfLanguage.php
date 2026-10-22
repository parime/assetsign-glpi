<?php

namespace GlpiPlugin\Assetsign\Pdf;

use Session;
use User;

/**
 * Langue des PDF (issue #208) : un PDF est rendu dans la langue de la personne a qui il appartient
 * (beneficiaire, partant, technicien d'une maintenance), jamais dans celle de la session qui
 * declenche sa generation. Un meme document est re-rendu par plusieurs personnes (PDF non signe
 * pendant l'affectation par le technicien, PDF signe pendant la signature du beneficiaire, PDF
 * contre-signe par le responsable) : sans langue fixee ici, ses versions successives changeraient
 * de langue selon qui agit — la raison pour laquelle les textes des PDF etaient jusqu'ici figes
 * en francais.
 */
final class PdfLanguage
{
    /** Langue du compte, sinon langue par defaut de GLPI (compte sans preference, externe...). */
   public static function forUser(int $usersId): string {
       $user = new User();
      if ($usersId > 0 && $user->getFromDB($usersId) && !empty($user->fields['language'])) {
          return (string) $user->fields['language'];
      }
       return (string) ($GLOBALS['CFG_GLPI']['language'] ?? 'fr_FR');
   }

    /**
     * Execute $render avec les traductions (GLPI et plugins) de $language, puis restaure a
     * l'identique celles de la session : la page qui a declenche le rendu reste dans la langue
     * de la personne connectee.
     *
     * @template T
     * @param callable(): T $render
     * @return T
     */
   public static function run(string $language, callable $render): mixed {
       global $TRANSLATE;

      if ($language === '' || ($TRANSLATE !== null && $TRANSLATE->getLocale() === $language)) {
          return $render();
      }

       $saved = [
           'translate'     => $TRANSLATE,
           'locale'        => class_exists(\Locale::class) ? \Locale::getDefault() : null,
           'pluralnumber'  => $_SESSION['glpipluralnumber'] ?? null,
           'isrtl'         => $_SESSION['glpiisrtl'] ?? null,
       ];
       try {
          Session::loadLanguage($language);
          return $render();
       } finally {
          $TRANSLATE = $saved['translate'];
          if ($saved['locale'] !== null) {
             \Locale::setDefault($saved['locale']);
          }
          foreach (['pluralnumber' => 'glpipluralnumber', 'isrtl' => 'glpiisrtl'] as $key => $sessionKey) {
             if ($saved[$key] === null) {
                unset($_SESSION[$sessionKey]);
             } else {
                $_SESSION[$sessionKey] = $saved[$key];
             }
          }
       }
   }

    /** Valeur de l'attribut lang du document HTML rendu (fr_FR -> fr). */
   public static function htmlLang(): string {
       global $TRANSLATE;
       return strtolower(substr((string) ($TRANSLATE?->getLocale() ?: 'fr'), 0, 2));
   }
}
