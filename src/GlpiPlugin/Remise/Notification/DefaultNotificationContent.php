<?php

namespace GlpiPlugin\Remise\Notification;

/**
 * Contenu (sujet + corps HTML, fr_FR et en_GB) seme a l'installation pour
 * chaque evenement de notification — extrait de NotificationTargetRemise::
 * install() pour que cette derniere reste une pure orchestration (creation/
 * idempotence/ciblage) sans aucune chaine HTML en dur.
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
     *     en_GB: array{subject: string, html: string}
     * }
     */
    public static function forEvent(string $event): array
    {
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
            ],
            default => throw new \RuntimeException("Plugin remise : évènement de notification inconnu ($event)."),
        };
    }
}
