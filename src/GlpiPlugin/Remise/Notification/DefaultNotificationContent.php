<?php

namespace GlpiPlugin\Remise\Notification;

/**
 * Contenu (sujet + corps HTML, dans les 5 langues supportees par l'interface
 * du plugin) seme a l'installation pour chaque evenement de notification —
 * extrait de NotificationTargetRemise::install() pour que cette derniere
 * reste une pure orchestration (creation/idempotence/ciblage) sans aucune
 * chaine HTML en dur.
 *
 * Le mot de type (##remise.type##, deja resolu par NotificationTargetRemise::
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
               'name'  => 'Remise : nouveau document à signer',
               'fr_FR' => [
                   'subject' => 'Un document de ##remise.type## vous attend pour signature',
                   'html'    => '<p>Bonjour ##remise.user.name##,</p>'
                       . '<p>Un document de ##remise.type## pour le matériel <strong>##remise.item.name##</strong> vous attend.</p>'
                       . '<p><a href="##remise.sign_url##">Consulter et signer le document</a></p>'
                       . '<p>Ce lien est valable jusqu\'au ##remise.deadline##.</p>',
               ],
               'en_GB' => [
                   'subject' => '##remise.type## document awaiting your signature',
                   'html'    => '<p>Hello ##remise.user.name##,</p>'
                       . '<p>A ##remise.type## document for the equipment <strong>##remise.item.name##</strong> is waiting for you.</p>'
                       . '<p><a href="##remise.sign_url##">View and sign the document</a></p>'
                       . '<p>This link is valid until ##remise.deadline##.</p>',
               ],
               'es_ES' => [
                   'subject' => 'Un documento de ##remise.type## le espera para su firma',
                   'html'    => '<p>Hola ##remise.user.name##,</p>'
                       . '<p>Un documento de ##remise.type## para el equipo <strong>##remise.item.name##</strong> le espera.</p>'
                       . '<p><a href="##remise.sign_url##">Consultar y firmar el documento</a></p>'
                       . '<p>Este enlace es válido hasta el ##remise.deadline##.</p>',
               ],
               'de_DE' => [
                   'subject' => 'Ein ##remise.type##-Dokument wartet auf Ihre Unterschrift',
                   'html'    => '<p>Hallo ##remise.user.name##,</p>'
                       . '<p>Ein ##remise.type##-Dokument für das Gerät <strong>##remise.item.name##</strong> wartet auf Sie.</p>'
                       . '<p><a href="##remise.sign_url##">Dokument ansehen und unterschreiben</a></p>'
                       . '<p>Dieser Link ist gültig bis ##remise.deadline##.</p>',
               ],
               'it_IT' => [
                   'subject' => 'Un documento di ##remise.type## attende la vostra firma',
                   'html'    => '<p>Ciao ##remise.user.name##,</p>'
                       . '<p>Un documento di ##remise.type## per il dispositivo <strong>##remise.item.name##</strong> vi attende.</p>'
                       . '<p><a href="##remise.sign_url##">Consulta e firma il documento</a></p>'
                       . '<p>Questo link è valido fino al ##remise.deadline##.</p>',
               ],
           ],
           'reminder' => [
               'name'  => 'Remise : relance de signature',
               'fr_FR' => [
                   'subject' => 'Rappel : document de ##remise.type## en attente de signature',
                   'html'    => '<p>Bonjour ##remise.user.name##,</p>'
                       . '<p>Le document de ##remise.type## pour <strong>##remise.item.name##</strong> n\'a pas encore été signé.</p>'
                       . '<p><a href="##remise.sign_url##">Consulter et signer le document</a></p>'
                       . '<p>Ce lien est valable jusqu\'au ##remise.deadline##.</p>',
               ],
               'en_GB' => [
                   'subject' => 'Reminder: ##remise.type## document pending signature',
                   'html'    => '<p>Hello ##remise.user.name##,</p>'
                       . '<p>The ##remise.type## document for <strong>##remise.item.name##</strong> has not been signed yet.</p>'
                       . '<p><a href="##remise.sign_url##">View and sign the document</a></p>'
                       . '<p>This link is valid until ##remise.deadline##.</p>',
               ],
               'es_ES' => [
                   'subject' => 'Recordatorio: documento de ##remise.type## pendiente de firma',
                   'html'    => '<p>Hola ##remise.user.name##,</p>'
                       . '<p>El documento de ##remise.type## para <strong>##remise.item.name##</strong> todavía no se ha firmado.</p>'
                       . '<p><a href="##remise.sign_url##">Consultar y firmar el documento</a></p>'
                       . '<p>Este enlace es válido hasta el ##remise.deadline##.</p>',
               ],
               'de_DE' => [
                   'subject' => 'Erinnerung: ##remise.type##-Dokument wartet auf Unterschrift',
                   'html'    => '<p>Hallo ##remise.user.name##,</p>'
                       . '<p>Das ##remise.type##-Dokument für <strong>##remise.item.name##</strong> wurde noch nicht unterschrieben.</p>'
                       . '<p><a href="##remise.sign_url##">Dokument ansehen und unterschreiben</a></p>'
                       . '<p>Dieser Link ist gültig bis ##remise.deadline##.</p>',
               ],
               'it_IT' => [
                   'subject' => 'Promemoria: documento di ##remise.type## in attesa di firma',
                   'html'    => '<p>Ciao ##remise.user.name##,</p>'
                       . '<p>Il documento di ##remise.type## per <strong>##remise.item.name##</strong> non è ancora stato firmato.</p>'
                       . '<p><a href="##remise.sign_url##">Consulta e firma il documento</a></p>'
                       . '<p>Questo link è valido fino al ##remise.deadline##.</p>',
               ],
           ],
           'signed' => [
               'name'  => 'Remise : document signé',
               'fr_FR' => [
                   'subject' => 'Document de ##remise.type## signé',
                   'html'    => '<p>Le document de ##remise.type## pour <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) a été signé et archivé dans GLPI.</p>',
               ],
               'en_GB' => [
                   'subject' => '##remise.type## document signed',
                   'html'    => '<p>The ##remise.type## document for <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) has been signed and archived in GLPI.</p>',
               ],
               'es_ES' => [
                   'subject' => 'Documento de ##remise.type## firmado',
                   'html'    => '<p>El documento de ##remise.type## para <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) se ha firmado y archivado en GLPI.</p>',
               ],
               'de_DE' => [
                   'subject' => '##remise.type##-Dokument unterschrieben',
                   'html'    => '<p>Das ##remise.type##-Dokument für <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) wurde unterschrieben und in GLPI archiviert.</p>',
               ],
               'it_IT' => [
                   'subject' => 'Documento di ##remise.type## firmato',
                   'html'    => '<p>Il documento di ##remise.type## per <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) è stato firmato e archiviato in GLPI.</p>',
               ],
           ],
           'expired' => [
               'name'  => 'Remise : document expiré',
               'fr_FR' => [
                   'subject' => 'Document de ##remise.type## expiré sans signature',
                   'html'    => '<p>Le document de ##remise.type## pour <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) a expiré sans avoir été signé.</p>',
               ],
               'en_GB' => [
                   'subject' => '##remise.type## document expired without signature',
                   'html'    => '<p>The ##remise.type## document for <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) has expired without being signed.</p>',
               ],
               'es_ES' => [
                   'subject' => 'Documento de ##remise.type## caducado sin firmar',
                   'html'    => '<p>El documento de ##remise.type## para <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) ha caducado sin haberse firmado.</p>',
               ],
               'de_DE' => [
                   'subject' => '##remise.type##-Dokument ohne Unterschrift abgelaufen',
                   'html'    => '<p>Das ##remise.type##-Dokument für <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) ist abgelaufen, ohne unterschrieben worden zu sein.</p>',
               ],
               'it_IT' => [
                   'subject' => 'Documento di ##remise.type## scaduto senza firma',
                   'html'    => '<p>Il documento di ##remise.type## per <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) è scaduto senza essere stato firmato.</p>',
               ],
           ],
           'expiring_soon' => [
               'name'  => 'Remise : document sur le point d\'expirer',
               'fr_FR' => [
                   'subject' => 'Document de ##remise.type## bientôt expiré sans signature',
                   'html'    => '<p>Le document de ##remise.type## pour <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) n\'est toujours pas signé et expirera le ##remise.deadline##.</p>'
                       . '<p>Pensez à relancer le bénéficiaire autrement (appel, passage sur place) avant l\'expiration du lien.</p>',
               ],
               'en_GB' => [
                   'subject' => '##remise.type## document soon to expire without signature',
                   'html'    => '<p>The ##remise.type## document for <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) is still unsigned and will expire on ##remise.deadline##.</p>'
                       . '<p>Consider reaching out to the beneficiary another way (call, in person) before the link expires.</p>',
               ],
               'es_ES' => [
                   'subject' => 'Documento de ##remise.type## a punto de caducar sin firma',
                   'html'    => '<p>El documento de ##remise.type## para <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) sigue sin firmarse y caducará el ##remise.deadline##.</p>'
                       . '<p>Considere contactar al beneficiario de otra forma (llamada, visita presencial) antes de que caduque el enlace.</p>',
               ],
               'de_DE' => [
                   'subject' => '##remise.type##-Dokument läuft bald ohne Unterschrift ab',
                   'html'    => '<p>Das ##remise.type##-Dokument für <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) ist noch nicht unterschrieben und läuft am ##remise.deadline## ab.</p>'
                       . '<p>Erwägen Sie, den Empfänger vor Ablauf des Links auf anderem Weg zu kontaktieren (Anruf, persönlicher Besuch).</p>',
               ],
               'it_IT' => [
                   'subject' => 'Documento di ##remise.type## in scadenza senza firma',
                   'html'    => '<p>Il documento di ##remise.type## per <strong>##remise.item.name##</strong> '
                       . '(##remise.user.name##) non è ancora firmato e scadrà il ##remise.deadline##.</p>'
                       . '<p>Si consiglia di contattare il beneficiario in altro modo (chiamata, visita di persona) prima della scadenza del link.</p>',
               ],
           ],
           default => throw new \RuntimeException("Plugin remise : évènement de notification inconnu ($event)."),
       };
   }
}
