<?php

namespace GlpiPlugin\Assetsign;

use Glpi\Application\View\TemplateRenderer;

/**
 * Issue #236 : textes figés (contenus par défaut des e-mails et des fiches) rangés dans des blocs
 * Twig plutôt qu'écrits en PHP.
 */
final class TwigBlocks
{
   /**
    * Environnement Twig de GLPI, avec l'espace de noms @assetsign. Ces textes sont aussi lus à
    * l'installation, avant l'activation du plugin : GLPI n'a alors pas encore enregistré les
    * gabarits du plugin (« There are no registered paths for namespace assetsign »).
    */
   public static function environment(): \Twig\Environment {
       $environment = TemplateRenderer::getInstance()->getEnvironment();
       $loader = $environment->getLoader();
      if ($loader instanceof \Twig\Loader\FilesystemLoader && !in_array('assetsign', $loader->getNamespaces(), true)) {
          $loader->addPath(dirname(__DIR__, 3) . '/templates', 'assetsign');
      }
       return $environment;
   }

   /** Rendu d'un bloc d'un gabarit du plugin (chemin relatif à templates/). */
   public static function render(string $template, string $block): string {
       return self::environment()->load('@assetsign/' . $template)->renderBlock($block);
   }

   /**
    * Petit gabarit du plugin rendu en chaîne (valeur d'une colonne de liste, zone d'un formulaire…).
    *
    * @param array<string, mixed> $variables
    */
   public static function fragment(string $template, array $variables = []): string {
       return trim(self::environment()->load('@assetsign/' . $template)->render($variables));
   }

   /**
    * Contenu riche (ticket, tâche, suivi…) composé de blocs, rendu par
    * components/rich_content.html.twig : le texte est toujours échappé par Twig.
    *
    * @param list<array<string, mixed>|null> $blocks blocs p(), lines(), list(), link() ; null ignoré
    */
   public static function content(array $blocks): string {
       return trim(self::environment()->load('@assetsign/components/rich_content.html.twig')->render([
           'blocks' => array_values(array_filter($blocks)),
       ]));
   }

   /** @return array{type: string, text: string} paragraphe */
   public static function p(string $text): array {
       return ['type' => 'p', 'text' => $text];
   }

   /** @return array{type: string, text: string} paragraphe dont les retours à la ligne sont gardés */
   public static function lines(string $text): array {
       return ['type' => 'lines', 'text' => $text];
   }

   /**
    * @param array<string> $items
    * @return array{type: string, items: list<string>} liste à puces
    */
   public static function list(array $items): array {
       return ['type' => 'list', 'items' => array_values($items)];
   }

   /** @return array{type: string, url: string, text: string} paragraphe contenant un lien */
   public static function link(string $url, string $text): array {
       return ['type' => 'link', 'url' => $url, 'text' => $text];
   }
}
