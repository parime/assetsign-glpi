<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Accessory;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignAccessory;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Kit;

/**
 * Couvre Assetsign::getKit()/updateKit()/getKitCompleteness() et le report
 * automatique du Kit de l'Attribution vers la Restitution (cf. ROADMAP.md V3,
 * issue #83, "Kits/accessoires avec controle automatique au retour",
 * docs/design/ADR-passeport-v1.md) : meme garde isStillEditable() que
 * addAccessory()/updateVenteDetails(), meme principe de non-invention
 * (null si aucun kit assigne) que getResidualValue().
 */
class AssetsignKitTest extends AssetsignTestCase
{
   private function createAccessory(string $name): int {
       $accessory = new Accessory();
       return (int) $accessory->add(['entities_id' => 0, 'name' => $name, 'is_active' => 1]);
   }

   private function createKit(string $name, array $accessoryIds): Kit {
       $kit = new Kit();
       $id = (int) $kit->add([
           'entities_id'    => 0,
           'name'           => $name,
           'is_active'      => 1,
           'accessories_id' => $accessoryIds,
       ]);
       $kit->getFromDB($id);
       return $kit;
   }

   public function testGetKitReturnsNullWhenNoneAssigned(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignKit None');
       $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_RETURN, Assetsign::STATUS_SENT);

       $this->assertNull($assetsign->getKit());
       $this->assertNull($assetsign->getKitCompleteness(), 'Aucun kit assigne : pas de resultat a afficher, jamais un tableau vide.');
   }

   public function testUpdateKitAssignsAndCanBeRetrieved(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignKit Assign');
       $kit = $this->createKit('PHPUnit Kit Assign', []);
       $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

       $assetsign->updateKit($kit->getID());
       $assetsign->getFromDB($assetsign->getID());

       $this->assertNotNull($assetsign->getKit());
       $this->assertSame($kit->getID(), $assetsign->getKit()->getID());
   }

   public function testUpdateKitNoOpWhenNoLongerEditable(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignKit NotEditable');
       $kit = $this->createKit('PHPUnit Kit NotEditable', []);
       // STATUS_SIGNED : hors de STATUSES_STILL_EDITABLE, meme garde que
       // addAccessory()/setChecklistValues() deja couvertes ailleurs.
       $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SIGNED);

       $assetsign->updateKit($kit->getID());
       $assetsign->getFromDB($assetsign->getID());

       $this->assertNull($assetsign->getKit(), 'Une fiche signee est une preuve figee : le kit ne doit plus pouvoir etre assigne.');
   }

   public function testGetKitCompletenessNullWhenKitHasNoExpectedAccessory(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignKit EmptyKit');
       $kit = $this->createKit('PHPUnit Kit Vide', []);
       $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_RETURN, Assetsign::STATUS_SENT);
       $assetsign->updateKit($kit->getID());
       $assetsign->getFromDB($assetsign->getID());

       $this->assertNull($assetsign->getKitCompleteness(), 'Un kit sans aucun accessoire attendu configure : rien a comparer.');
   }

   public function testGetKitCompletenessCompleteWhenEverythingWasReturned(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignKit Complete');
       $charger = $this->createAccessory('PHPUnit Chargeur');
       $bag = $this->createAccessory('PHPUnit Sacoche');
       $kit = $this->createKit('PHPUnit Kit Complet', [$charger, $bag]);

       $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_RETURN, Assetsign::STATUS_SENT);
       $assetsign->updateKit($kit->getID());
       $assetsign->getFromDB($assetsign->getID());
       AssetsignAccessory::attach($assetsign->getID(), $charger, 1);
       AssetsignAccessory::attach($assetsign->getID(), $bag, 1);

       $completeness = $assetsign->getKitCompleteness();

       $this->assertNotNull($completeness);
       $this->assertSame(2, $completeness['expected_total']);
       $this->assertSame(2, $completeness['returned_count']);
       $this->assertSame([], $completeness['missing_names']);
       $this->assertTrue($completeness['complete']);
       $this->assertSame('#2fb344', $completeness['color']);
   }

   public function testGetKitCompletenessFlagsMissingAccessoryByName(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignKit Missing');
       $charger = $this->createAccessory('PHPUnit Chargeur Manquant');
       $mouse = $this->createAccessory('PHPUnit Souris Manquante');
       $kit = $this->createKit('PHPUnit Kit Incomplet', [$charger, $mouse]);

       $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_RETURN, Assetsign::STATUS_SENT);
       $assetsign->updateKit($kit->getID());
       $assetsign->getFromDB($assetsign->getID());
       // Seul le chargeur revient : la souris est manquante.
       AssetsignAccessory::attach($assetsign->getID(), $charger, 1);

       $completeness = $assetsign->getKitCompleteness();

       $this->assertNotNull($completeness);
       $this->assertSame(2, $completeness['expected_total']);
       $this->assertSame(1, $completeness['returned_count']);
       $this->assertSame(['PHPUnit Souris Manquante'], $completeness['missing_names']);
       $this->assertFalse($completeness['complete']);
       $this->assertSame('#f76707', $completeness['color']);
   }

   public function testGetKitCompletenessRedWhenNothingCameBack(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignKit AllMissing');
       $charger = $this->createAccessory('PHPUnit Chargeur Perdu');
       $kit = $this->createKit('PHPUnit Kit Tout Perdu', [$charger]);

       $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_RETURN, Assetsign::STATUS_SENT);
       $assetsign->updateKit($kit->getID());
       $assetsign->getFromDB($assetsign->getID());

       $completeness = $assetsign->getKitCompleteness();

       $this->assertNotNull($completeness);
       $this->assertSame(0, $completeness['returned_count']);
       $this->assertSame(['PHPUnit Chargeur Perdu'], $completeness['missing_names']);
       $this->assertSame('#dc3545', $completeness['color']);
   }

    /**
     * Coeur de "controle AUTOMATIQUE" (titre de l'issue #83) : le Kit assigne a
     * la derniere Attribution d'un materiel doit etre report automatiquement
     * sur la Restitution creee ensuite pour ce meme materiel, sans que le
     * technicien ait a le re-choisir — cf.
     * Assetsign::resolveKitForAutomaticCreation(), meme motif que
     * writeDecommissionDateIfMissing() (copier un fait reel, jamais en deviner
     * un).
     */
   public function testKitIsCarriedForwardFromHandoverToAutomaticReturn(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignKit CarryForward');
       // sign_on_return vaut 0 par defaut (cf. Config::DEFAULTS) : sans ce
       // reglage, aucune Restitution n'est meme creee au retrait de
       // l'utilisateur, rendant ce test inoperant. sign_on_assignment doit
       // etre repasse explicitement (upsertForEntity remet a 0/defaut tout
       // champ absent du tableau, cf. commentaire equivalent dans
       // AssetsignTest.php), sinon l'Attribution elle-meme ne serait plus
       // creee non plus.
       Config::upsertForEntity($entityId, ['sign_on_assignment' => 1, 'sign_on_return' => 1]);
       $charger = $this->createAccessory('PHPUnit Chargeur CarryForward');
       $kit = $this->createKit('PHPUnit Kit CarryForward', [$charger]);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC CarryForward');
       $userId = $this->createTestUser('Jean', 'CarryForward');

       // Attribution automatique (affectation d'utilisateur), puis assignation
       // du kit sur cette Attribution.
       $computer->oldvalues = ['users_id' => 0];
       $computer->fields['users_id'] = $userId;
       Assetsign::handleItemAssignment($computer);

       $handover = new Assetsign();
       $handover->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'type' => Assetsign::TYPE_HANDOVER]);
       $handover->updateKit($kit->getID());

       // Restitution automatique (retrait de l'utilisateur) : doit heriter du
       // meme kit, sans aucune action manuelle supplementaire.
       $computer->oldvalues = ['users_id' => $userId];
       $computer->fields['users_id'] = 0;
       Assetsign::handleItemAssignment($computer);

       $return = new Assetsign();
       $return->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'type' => Assetsign::TYPE_RETURN]);

       $this->assertNotNull($return->getKit(), 'Le kit de la derniere Attribution doit etre reporte automatiquement sur la Restitution.');
       $this->assertSame($kit->getID(), $return->getKit()->getID());
   }

   public function testNoKitCarriedForwardWhenHandoverHadNone(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignKit NoCarryForward');
       Config::upsertForEntity($entityId, ['sign_on_assignment' => 1, 'sign_on_return' => 1]);
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC NoCarryForward');
       $userId = $this->createTestUser('Marie', 'NoCarryForward');

       $computer->oldvalues = ['users_id' => 0];
       $computer->fields['users_id'] = $userId;
       Assetsign::handleItemAssignment($computer);
       // Aucun kit assigne a l'Attribution.

       $computer->oldvalues = ['users_id' => $userId];
       $computer->fields['users_id'] = 0;
       Assetsign::handleItemAssignment($computer);

       $return = new Assetsign();
       $return->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'type' => Assetsign::TYPE_RETURN]);

       $this->assertNull($return->getKit(), 'Aucun kit sur l\'Attribution : rien a reporter, jamais un kit invente.');
   }
}
