<?php

namespace GlpiPlugin\Assetsign\Notification;

/**
 * Contenu (sujet + corps HTML, dans les 5 langues supportees par l'interface
 * du plugin) seme a l'installation pour chaque evenement de notification —
 * extrait de NotificationTargetAssetsign::install() pour que cette derniere
 * reste une pure orchestration (creation/idempotence/ciblage) sans aucune
 * chaine HTML en dur.
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
       return match ($event) {
           'new' => [
               'name'  => 'Assetsign : nouveau document à signer',
               'fr_FR' => [
                   'subject' => 'Un document de ##assetsign.type## vous attend pour signature',
                   'html'    => '<p>Bonjour ##assetsign.user.name##,</p>'
                       . '<p>Un document de ##assetsign.type## pour le matériel <strong>##assetsign.item.name##</strong> vous attend.</p>'
                       . '<p><a href="##assetsign.sign_url##">Consulter et signer le document</a></p>'
                       . '<p>Ce lien est valable jusqu\'au ##assetsign.deadline##.</p>',
               ],
               'en_GB' => [
                   'subject' => '##assetsign.type## document awaiting your signature',
                   'html'    => '<p>Hello ##assetsign.user.name##,</p>'
                       . '<p>A ##assetsign.type## document for the equipment <strong>##assetsign.item.name##</strong> is waiting for you.</p>'
                       . '<p><a href="##assetsign.sign_url##">View and sign the document</a></p>'
                       . '<p>This link is valid until ##assetsign.deadline##.</p>',
               ],
               'es_ES' => [
                   'subject' => 'Un documento de ##assetsign.type## le espera para su firma',
                   'html'    => '<p>Hola ##assetsign.user.name##,</p>'
                       . '<p>Un documento de ##assetsign.type## para el equipo <strong>##assetsign.item.name##</strong> le espera.</p>'
                       . '<p><a href="##assetsign.sign_url##">Consultar y firmar el documento</a></p>'
                       . '<p>Este enlace es válido hasta el ##assetsign.deadline##.</p>',
               ],
               'de_DE' => [
                   'subject' => 'Ein ##assetsign.type##-Dokument wartet auf Ihre Unterschrift',
                   'html'    => '<p>Hallo ##assetsign.user.name##,</p>'
                       . '<p>Ein ##assetsign.type##-Dokument für das Gerät <strong>##assetsign.item.name##</strong> wartet auf Sie.</p>'
                       . '<p><a href="##assetsign.sign_url##">Dokument ansehen und unterschreiben</a></p>'
                       . '<p>Dieser Link ist gültig bis ##assetsign.deadline##.</p>',
               ],
               'it_IT' => [
                   'subject' => 'Un documento di ##assetsign.type## attende la vostra firma',
                   'html'    => '<p>Ciao ##assetsign.user.name##,</p>'
                       . '<p>Un documento di ##assetsign.type## per il dispositivo <strong>##assetsign.item.name##</strong> vi attende.</p>'
                       . '<p><a href="##assetsign.sign_url##">Consulta e firma il documento</a></p>'
                       . '<p>Questo link è valido fino al ##assetsign.deadline##.</p>',
               ],
           ],
           'reminder' => [
               'name'  => 'Assetsign : relance de signature',
               'fr_FR' => [
                   'subject' => 'Rappel : document de ##assetsign.type## en attente de signature',
                   'html'    => '<p>Bonjour ##assetsign.user.name##,</p>'
                       . '<p>Le document de ##assetsign.type## pour <strong>##assetsign.item.name##</strong> n\'a pas encore été signé.</p>'
                       . '<p><a href="##assetsign.sign_url##">Consulter et signer le document</a></p>'
                       . '<p>Ce lien est valable jusqu\'au ##assetsign.deadline##.</p>',
               ],
               'en_GB' => [
                   'subject' => 'Reminder: ##assetsign.type## document pending signature',
                   'html'    => '<p>Hello ##assetsign.user.name##,</p>'
                       . '<p>The ##assetsign.type## document for <strong>##assetsign.item.name##</strong> has not been signed yet.</p>'
                       . '<p><a href="##assetsign.sign_url##">View and sign the document</a></p>'
                       . '<p>This link is valid until ##assetsign.deadline##.</p>',
               ],
               'es_ES' => [
                   'subject' => 'Recordatorio: documento de ##assetsign.type## pendiente de firma',
                   'html'    => '<p>Hola ##assetsign.user.name##,</p>'
                       . '<p>El documento de ##assetsign.type## para <strong>##assetsign.item.name##</strong> todavía no se ha firmado.</p>'
                       . '<p><a href="##assetsign.sign_url##">Consultar y firmar el documento</a></p>'
                       . '<p>Este enlace es válido hasta el ##assetsign.deadline##.</p>',
               ],
               'de_DE' => [
                   'subject' => 'Erinnerung: ##assetsign.type##-Dokument wartet auf Unterschrift',
                   'html'    => '<p>Hallo ##assetsign.user.name##,</p>'
                       . '<p>Das ##assetsign.type##-Dokument für <strong>##assetsign.item.name##</strong> wurde noch nicht unterschrieben.</p>'
                       . '<p><a href="##assetsign.sign_url##">Dokument ansehen und unterschreiben</a></p>'
                       . '<p>Dieser Link ist gültig bis ##assetsign.deadline##.</p>',
               ],
               'it_IT' => [
                   'subject' => 'Promemoria: documento di ##assetsign.type## in attesa di firma',
                   'html'    => '<p>Ciao ##assetsign.user.name##,</p>'
                       . '<p>Il documento di ##assetsign.type## per <strong>##assetsign.item.name##</strong> non è ancora stato firmato.</p>'
                       . '<p><a href="##assetsign.sign_url##">Consulta e firma il documento</a></p>'
                       . '<p>Questo link è valido fino al ##assetsign.deadline##.</p>',
               ],
           ],
           'signed' => [
               'name'  => 'Assetsign : document signé',
               'fr_FR' => [
                   'subject' => 'Document de ##assetsign.type## signé',
                   'html'    => '<p>Le document de ##assetsign.type## pour <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) a été signé et archivé dans GLPI.</p>',
               ],
               'en_GB' => [
                   'subject' => '##assetsign.type## document signed',
                   'html'    => '<p>The ##assetsign.type## document for <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) has been signed and archived in GLPI.</p>',
               ],
               'es_ES' => [
                   'subject' => 'Documento de ##assetsign.type## firmado',
                   'html'    => '<p>El documento de ##assetsign.type## para <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) se ha firmado y archivado en GLPI.</p>',
               ],
               'de_DE' => [
                   'subject' => '##assetsign.type##-Dokument unterschrieben',
                   'html'    => '<p>Das ##assetsign.type##-Dokument für <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) wurde unterschrieben und in GLPI archiviert.</p>',
               ],
               'it_IT' => [
                   'subject' => 'Documento di ##assetsign.type## firmato',
                   'html'    => '<p>Il documento di ##assetsign.type## per <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) è stato firmato e archiviato in GLPI.</p>',
               ],
           ],
           'expired' => [
               'name'  => 'Assetsign : document expiré',
               'fr_FR' => [
                   'subject' => 'Document de ##assetsign.type## expiré sans signature',
                   'html'    => '<p>Le document de ##assetsign.type## pour <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) a expiré sans avoir été signé.</p>',
               ],
               'en_GB' => [
                   'subject' => '##assetsign.type## document expired without signature',
                   'html'    => '<p>The ##assetsign.type## document for <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) has expired without being signed.</p>',
               ],
               'es_ES' => [
                   'subject' => 'Documento de ##assetsign.type## caducado sin firmar',
                   'html'    => '<p>El documento de ##assetsign.type## para <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) ha caducado sin haberse firmado.</p>',
               ],
               'de_DE' => [
                   'subject' => '##assetsign.type##-Dokument ohne Unterschrift abgelaufen',
                   'html'    => '<p>Das ##assetsign.type##-Dokument für <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) ist abgelaufen, ohne unterschrieben worden zu sein.</p>',
               ],
               'it_IT' => [
                   'subject' => 'Documento di ##assetsign.type## scaduto senza firma',
                   'html'    => '<p>Il documento di ##assetsign.type## per <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) è scaduto senza essere stato firmato.</p>',
               ],
           ],
           'expiring_soon' => [
               'name'  => 'Assetsign : document sur le point d\'expirer',
               'fr_FR' => [
                   'subject' => 'Document de ##assetsign.type## bientôt expiré sans signature',
                   'html'    => '<p>Le document de ##assetsign.type## pour <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) n\'est toujours pas signé et expirera le ##assetsign.deadline##.</p>'
                       . '<p>Pensez à relancer le bénéficiaire autrement (appel, passage sur place) avant l\'expiration du lien.</p>',
               ],
               'en_GB' => [
                   'subject' => '##assetsign.type## document soon to expire without signature',
                   'html'    => '<p>The ##assetsign.type## document for <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) is still unsigned and will expire on ##assetsign.deadline##.</p>'
                       . '<p>Consider reaching out to the beneficiary another way (call, in person) before the link expires.</p>',
               ],
               'es_ES' => [
                   'subject' => 'Documento de ##assetsign.type## a punto de caducar sin firma',
                   'html'    => '<p>El documento de ##assetsign.type## para <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) sigue sin firmarse y caducará el ##assetsign.deadline##.</p>'
                       . '<p>Considere contactar al beneficiario de otra forma (llamada, visita presencial) antes de que caduque el enlace.</p>',
               ],
               'de_DE' => [
                   'subject' => '##assetsign.type##-Dokument läuft bald ohne Unterschrift ab',
                   'html'    => '<p>Das ##assetsign.type##-Dokument für <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) ist noch nicht unterschrieben und läuft am ##assetsign.deadline## ab.</p>'
                       . '<p>Erwägen Sie, den Empfänger vor Ablauf des Links auf anderem Weg zu kontaktieren (Anruf, persönlicher Besuch).</p>',
               ],
               'it_IT' => [
                   'subject' => 'Documento di ##assetsign.type## in scadenza senza firma',
                   'html'    => '<p>Il documento di ##assetsign.type## per <strong>##assetsign.item.name##</strong> '
                       . '(##assetsign.user.name##) non è ancora firmato e scadrà il ##assetsign.deadline##.</p>'
                       . '<p>Si consiglia di contattare il beneficiario in altro modo (chiamata, visita di persona) prima della scadenza del link.</p>',
               ],
           ],
           default => throw new \RuntimeException("Plugin assetsign : évènement de notification inconnu ($event)."),
       };
   }
}
