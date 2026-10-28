<?php

namespace GlpiPlugin\Assetsign\Notification;

use GlpiPlugin\Assetsign\Config;

/**
 * Contenu (sujet + corps HTML, dans les 5 langues supportees par l'interface
 * du plugin) seme a l'installation pour chaque evenement de notification —
 * extrait de NotificationTargetAssetsign::install() pour que cette derniere
 * reste une pure orchestration (creation/idempotence/ciblage) sans aucune
 * chaine HTML en dur.
 *
 * Issue #236 : le texte est dans templates/notifications/<evenement>.html.twig (un bloc
 * name, puis <langue>_subject et <langue>_html par langue), l'habillage dans
 * templates/notifications/_branded.html.twig ; aucun HTML ecrit en PHP.
 *
 * Le mot de type (##assetsign.type##, deja resolu par NotificationTargetAssetsign::
 * addDataForTemplate()) est utilise directement dans le sujet et le corps :
 * un seul jeu de gabarits par evenement fonctionne donc aussi bien pour une
 * remise que pour une restitution (et pour tout futur type enregistre dans
 * Workflow\WorkflowTypeRegistry), sans avoir a dupliquer une notification par
 * type de fiche.
 *
 * Ce contenu n'est utilise que par install() lors du premier semis (nouvelle
 * installation) : une installation existante garde le contenu tel qu'elle l'a
 * personnalise depuis Configuration > Notifications, jamais ecrase automatiquement.
 */
final class DefaultNotificationContent
{
   public const EVENTS = [
       'new', 'reminder', 'signed', 'expired', 'expiring_soon', 'draft_pending', 'loan_due',
       'delegated_beneficiary', 'delegated', 'cosignature_requested',
   ];
   public const LANGUAGES = ['fr_FR', 'en_GB', 'es_ES', 'de_DE', 'it_IT'];

    /**
     * @return array{
     *     name: string,
     *     fr_FR: array{subject: string, html: string},
     *     en_GB: array{subject: string, html: string},
     *     es_ES: array{subject: string, html: string},
     *     de_DE: array{subject: string, html: string},
     *     it_IT: array{subject: string, html: string}
     * }
     */
   public static function forEvent(string $event): array {
      if (!in_array($event, self::EVENTS, true)) {
          throw new \RuntimeException("Plugin assetsign : évènement de notification inconnu ($event).");
      }
       // Lu à l'installation, avant l'activation : NotificationTexts::environment() enregistre @assetsign.
       $environment = NotificationTexts::environment();
       $template = $environment->load('@assetsign/notifications/' . $event . '.html.twig');
       $branded = $environment->load('@assetsign/notifications/_branded.html.twig');

       // Habille chaque corps HTML d'un en-tete/pied de page sobres (bande de
       // couleur, nom de l'entreprise si renseigne), cf. _branded.html.twig. Genere une
       // seule fois a l'installation (comme le reste de ce fichier, cf.
       // docblock de la classe) a partir du nom d'entreprise de l'entite
       // racine — une organisation multi-entites avec un nom different par
       // entite peut toujours personnaliser le contenu ensuite depuis
       // Configuration > Notifications, comme n'importe quel gabarit natif.
       $companyName = trim((string) (Config::getForEntity(0)->fields['company_name'] ?? ''));

       $content = ['name' => $template->renderBlock('name')];
      foreach (self::LANGUAGES as $lang) {
          $content[$lang] = [
              'subject' => $template->renderBlock($lang . '_subject'),
              'html'    => $branded->render([
                  'company_name'   => $companyName,
                  'body_safe_html' => $template->renderBlock($lang . '_html'),
              ]),
          ];
      }

       return $content;
   }
}
