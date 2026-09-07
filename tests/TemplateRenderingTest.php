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
   public function testAssetsignTabTemplateDoesNotLeakDropdownFieldId(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit TemplateRendering AssetsignTab');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC TemplateRendering');

       $html = TemplateRenderer::getInstance()->render('@assetsign/assetsign_tab.html.twig', [
           'item'               => $computer,
           'assetsigns'            => [],
           'statuses'           => Assetsign::getStatuses(),
           'manual_types'       => [Assetsign::TYPE_DON => 'Don', Assetsign::TYPE_VENTE => 'Vente', Assetsign::TYPE_DESTRUCTION => 'Destruction'],
           'type_vente'         => Assetsign::TYPE_VENTE,
           'type_don'           => Assetsign::TYPE_DON,
           'type_destruction'   => Assetsign::TYPE_DESTRUCTION,
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
       $this->assertUserDropdownIsNotRestrictedToTheCurrentUser($html, 'assetsign_tab.html.twig (menu Destinataire)');
   }

    /**
     * Regression guard for issue #115's own class of bug (see the same fix already applied to
     * `assetsign_form.html.twig`/`sign_page.html.twig`'s delegation dropdowns): this "Destinataire"
     * picker on the manual Don/Vente/Destruction creation form used the same `User::dropdown()`
     * default (`right => 'id'`, which restricts the list to the currently logged-in user only,
     * regardless of profile) — never fixed alongside the delegation dropdowns, even though it's
     * the exact same underlying defect. `Html::jsAjaxDropdown()` embeds the resolved options as a
     * literal JSON blob in an inline `<script>` (confirmed by rendering this template directly and
     * inspecting the output), so `"right":"all"` appearing in the HTML is a direct, reliable proxy
     * for "this dropdown can actually list other users" — not just "the widget renders".
     */
   private function assertUserDropdownIsNotRestrictedToTheCurrentUser(string $html, string $context): void {
       $this->assertStringContainsString('"right":"all"', $html, "$context : la liste doit pouvoir proposer d'autres utilisateurs que celui actuellement connecte (right='all'), pas seulement lui (right='id', le defaut de User::dropdown()).");
   }

   public function testAssetsignFormTemplateDoesNotLeakAccessoryDropdownFieldId(): void {
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
           'type_don'                  => Assetsign::TYPE_DON,
           'don_details'               => null,
           'can_edit_don_details'      => false,
           'type_destruction'          => Assetsign::TYPE_DESTRUCTION,
           'destruction_details'       => null,
           'can_edit_destruction_details' => false,
           'attached_documents'        => [],
           'signature_proof'           => null,
           'csrf_token'                => 'phpunit-test-token',
           // Force le rendu du formulaire de delegation (bloc contenant
           // {% do call('User::dropdown', ..., {'right': 'all'}) %}) — voir
           // testAssetsignFormTemplateDelegateDropdownIsNotRestrictedToTheCurrentUser().
           'delegation_enabled'        => true,
           'can_delegate'              => true,
       ]);

       // name="plugin_assetsign_accessories_id" (pas un libelle traduit) : meme
       // raison que ci-dessus (independance a la langue de l'environnement).
       $this->assertStringContainsString('name="plugin_assetsign_accessories_id"', $html, 'Le formulaire d\'ajout d\'accessoire doit etre rendu pour que ce test ait un sens.');
       $this->assertNoStrayNumericTextNode($html, 'assetsign_form.html.twig (menu Ajouter un accessoire)');
       $this->assertStringContainsString('name="delegate_users_id"', $html, 'Le formulaire de delegation doit etre rendu pour que ce test ait un sens.');
       $this->assertUserDropdownIsNotRestrictedToTheCurrentUser($html, 'assetsign_form.html.twig (menu Deleguer a)');
   }

    /**
     * `sign_page.html.twig`'s delegate dropdown is a plain, server-populated `<select>`, not a
     * `User::dropdown()` AJAX widget (see `Assetsign::getDelegateCandidates()`'s own docblock for
     * why: this page loads no jQuery, so the select2 widget the other two dropdowns use never
     * initializes here — a real bug found by testing issue #115 with Playwright, independent of
     * the `right='id'` vs `'all'` fix already covered by the two tests above). This confirms every
     * candidate passed in actually renders as a real, selectable `<option>`.
     */
   public function testSignPageTemplateDelegateDropdownRendersEveryCandidateAsARealOption(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit TemplateRendering SignPage');
       $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_DON, Assetsign::STATUS_SENT);

       $html = TemplateRenderer::getInstance()->render('@assetsign/sign_page.html.twig', [
           'token'                    => 'phpunit-test-token',
           'csrf_token'               => 'phpunit-test-token',
           'assetsign'                => $assetsign->fields,
           'user'                     => [],
           'item'                     => [],
           'expiry'                   => '',
           'can_edit_damage_markers'  => false,
           'damage_annotation_enabled' => false,
           'damage_views'             => [],
           'damage_images'            => [],
           'damage_markers_by_view'   => [],
           'beneficiary_comment'      => '',
           'can_edit_comment'         => false,
           'self_service_delegation_enabled' => true,
           'is_delegate_signer'       => false,
           // Force le rendu du formulaire d'auto-delegation (bloc contenant le <select>).
           'can_delegate_self'        => true,
           'delegate_candidates'      => [
               ['id' => 123456, 'label' => 'PHPUnit Candidate One'],
               ['id' => 123457, 'label' => 'PHPUnit Candidate Two'],
           ],
           'delegate'                 => null,
           'page_title'               => 'PHPUnit',
           'pdf_url'                  => '',
           'error'                    => null,
       ]);

       $this->assertStringContainsString('name="delegate_users_id"', $html, 'Le formulaire d\'auto-delegation doit etre rendu pour que ce test ait un sens.');
       $this->assertStringContainsString('<option value="123456">PHPUnit Candidate One</option>', $html);
       $this->assertStringContainsString('<option value="123457">PHPUnit Candidate Two</option>', $html);
   }
}
