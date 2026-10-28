<?php

namespace GlpiPlugin\Assetsign\Notification;

use Glpi\Application\View\TemplateRenderer;

/**
 * Issue #236 : textes par défaut d'une notification (sujet et corps HTML par langue), lus dans
 * les blocs <evenement>__<langue>_subject / _html de templates/notifications/<gabarit>.html.twig.
 * Aucun HTML écrit en PHP.
 */
final class NotificationTexts
{
   public const LANGUAGES = ['fr_FR', 'en_GB', 'es_ES', 'de_DE', 'it_IT'];

   /**
    * @return array<string, array{subject: string, html: string}>
    */
   /**
    * Environnement Twig de GLPI, avec l'espace de noms @assetsign. Ces textes sont lus à
    * l'installation, avant l'activation du plugin : GLPI n'a alors pas encore enregistré les
    * gabarits du plugin (« There are no registered paths for namespace assetsign »).
    */
   public static function environment(): \Twig\Environment {
       $environment = TemplateRenderer::getInstance()->getEnvironment();
       $loader = $environment->getLoader();
      if ($loader instanceof \Twig\Loader\FilesystemLoader && !in_array('assetsign', $loader->getNamespaces(), true)) {
          $loader->addPath(dirname(__DIR__, 4) . '/templates', 'assetsign');
      }
       return $environment;
   }

   public static function load(string $template, string $event): array {
       $twig = self::environment()->load('@assetsign/notifications/' . $template . '.html.twig');
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
