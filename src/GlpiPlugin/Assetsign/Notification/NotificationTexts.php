<?php

namespace GlpiPlugin\Assetsign\Notification;

use GlpiPlugin\Assetsign\TwigBlocks;

/**
 * Issue #236 : textes par défaut d'une notification (sujet et corps HTML par langue), lus dans
 * les blocs <evenement>__<langue>_subject / _html de templates/notifications/<gabarit>.html.twig.
 * Aucun HTML écrit en PHP.
 */
final class NotificationTexts
{
   public const LANGUAGES = ['fr_FR', 'en_GB', 'es_ES', 'de_DE', 'it_IT'];

   /**
    * Version texte d'un corps HTML de notification : une ligne par paragraphe ou élément de liste.
    */
   public static function plainText(string $html): string {
       return strip_tags((string) preg_replace('#</(p|li)\s*>#i', "\n", $html));
   }

   /**
    * @return array<string, array{subject: string, html: string}>
    */
   public static function load(string $template, string $event): array {
       $twig = TwigBlocks::environment()->load('@assetsign/notifications/' . $template . '.html.twig');
       $texts = [];
      foreach (self::LANGUAGES as $lang) {
          $texts[$lang] = [
              'subject' => $twig->renderBlock($event . '__' . $lang . '_subject'),
              'html'    => $twig->renderBlock($event . '__' . $lang . '_html'),
          ];
      }
       return $texts;
   }
}
