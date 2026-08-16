<?php

namespace GlpiPlugin\Assetsign\Tests;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetsign\Assetsign;

/**
 * Rendu reel des gabarits Twig d'administration : jusqu'ici, aucune suite
 * automatisee n'inspectait le HTML produit (seulement la logique PHP), ce qui
 * a laisse passer un bug reel decouvert en preparant des captures d'ecran
 * documentaires (cf. TROUBLESHOOTING.md) — {{ call('User::dropdown', ...) }}
 * imprimait, en plus du widget lui-meme, la valeur de retour de
 * Dropdown::show() (l'entier aleatoire de l'id DOM genere, cf. Dropdown.php),
 * affichee comme un nombre parasite juste apres le menu deroulant. Corrige en
 * {% do call(...) %} (execute l'appel sans imprimer son retour).
 *
 * Le garde-fou utilise (assertNoStrayNumericTextNode(), sur AssetsignTestCase,
 * partage avec OtherTemplateRenderingTest) ne se contente pas de verifier
 * l'absence du nombre observe a l'epoque (different a chaque appel, cf.
 * field_id aleatoire) : il recherche la FORME du defaut (un entier de 5
 * chiffres ou plus comme seul contenu d'un noeud texte, entre deux balises)
 * pour rester valable si un futur {{ call(...) }} mal utilise reapparait
 * ailleurs dans ces gabarits.
 */
class TemplateRenderingTest extends AssetsignTestCase
{
    public function testAssetsignTabTemplateDoesNotLeakDropdownFieldId(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit TemplateRendering AssetsignTab');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC TemplateRendering');

        $html = TemplateRenderer::getInstance()->render('@assetsign/assetsign_tab.html.twig', [
            'item'               => $computer,
            'assetsigns'            => [],
            'statuses'           => Assetsign::getStatuses(),
            'manual_types'       => [Assetsign::TYPE_DON => 'Don', Assetsign::TYPE_VENTE => 'Vente'],
            'type_vente'         => Assetsign::TYPE_VENTE,
            // Force le rendu du formulaire de creation manuelle (bloc contenant
            // {% do call('User::dropdown', ...) %}), quel que soit le droit
            // reel de la session de test.
            'can_create_manual'  => true,
            'csrf_token'         => 'phpunit-test-token',
        ]);

        // name="users_id" (pas un libelle traduit) : le test doit rester valable
        // quelle que soit la langue de l'environnement d'execution (echec reel
        // constate en CI, qui rend en anglais - "Destinataire" n'y apparait pas).
        $this->assertStringContainsString('name="users_id"', $html, 'Le formulaire de creation manuelle doit etre rendu pour que ce test ait un sens.');
        $this->assertNoStrayNumericTextNode($html, 'assetsign_tab.html.twig (menu Destinataire)');
    }

    public function testAssetsignFormTemplateDoesNotLeakAccessoryDropdownFieldId(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit TemplateRendering AssetsignForm');
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_DON, Assetsign::STATUS_SENT);

        $html = TemplateRenderer::getInstance()->render('@assetsign/assetsign_form.html.twig', [
            'item'                      => $assetsign,
            'params'                    => [],
            'statuses'                  => Assetsign::getStatuses(),
            'types'                     => Assetsign::getTypes(),
            'beneficiary'               => [],
            'target_item'               => [],
            'reminders'                 => 0,
            'can_remind'                => false,
            'accessories'               => [],
            // Force le rendu du formulaire d'ajout d'accessoire (bloc contenant
            // {% do call('GlpiPlugin\Assetsign\Accessory::dropdown', ...) %}).
            'can_edit_accessories'      => true,
            'observations_enabled'      => false,
            'damage_annotation_enabled' => false,
            'damage_views'              => [],
            'damage_images'             => [],
            'damage_markers_by_view'    => [],
            'can_edit_damage_markers'   => false,
            'type_vente'                => Assetsign::TYPE_VENTE,
            'vente_details'             => null,
            'can_edit_vente_details'    => false,
            'signature_proof'           => null,
            'csrf_token'                => 'phpunit-test-token',
        ]);

        // name="plugin_assetsign_accessories_id" (pas un libelle traduit) : meme
        // raison que ci-dessus (independance a la langue de l'environnement).
        $this->assertStringContainsString('name="plugin_assetsign_accessories_id"', $html, 'Le formulaire d\'ajout d\'accessoire doit etre rendu pour que ce test ait un sens.');
        $this->assertNoStrayNumericTextNode($html, 'assetsign_form.html.twig (menu Ajouter un accessoire)');
    }
}
