<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Accessory;
use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\AssetsignAccessory;
use GlpiPlugin\Assetsign\Kit;
use GlpiPlugin\Assetsign\PassportEvent;

/**
 * Couvre PassportEvent::attachKitSummaries() (cf. ROADMAP.md V3, issue #83,
 * docs/design/ADR-passeport-v1.md) : badge "X/Y accessoires du kit restitués"
 * fusionné dans la frise du Passeport matériel, sur l'événement TYPE_RETURN
 * uniquement, PUREMENT calculé à l'affichage (jamais copié dans
 * glpi_plugin_assetsign_events), absent si aucun kit n'est assigné à la
 * Restitution.
 */
class PassportEventKitSummaryTest extends AssetsignTestCase
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

    /**
     * Insertion directe (comme AssetsignTestCase::createBareAssetsign(), mais
     * rattachee au vrai Computer de test et avec un kit assigne des la
     * creation) : evite de passer par launchWorkflow()/le vrai workflow
     * automatique, hors de propos pour isoler attachKitSummaries().
     */
   private function createReturnAssetsign(int $entitiesId, \Computer $computer, int $kitsId): Assetsign {
       $assetsign = new Assetsign();
       $id = (int) $assetsign->add([
           'entities_id'              => $entitiesId,
           'itemtype'                 => 'Computer',
           'items_id'                 => $computer->getID(),
           'users_id'                 => 2,
           'type'                     => Assetsign::TYPE_RETURN,
           'status'                   => Assetsign::STATUS_SIGNED,
           'plugin_assetsign_kits_id' => $kitsId,
       ]);
       $assetsign->getFromDB($id);
       return $assetsign;
   }

   public function testShowForItemDisplaysCompleteKitBadgeInGreen(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit PassportKit Complete');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC PassportKit Complete');
       $charger = $this->createAccessory('PHPUnit PassportKit Chargeur Complet');
       $kit = $this->createKit('PHPUnit PassportKit Kit Complet', [$charger]);

       $assetsign = $this->createReturnAssetsign($entityId, $computer, $kit->getID());
       AssetsignAccessory::attach($assetsign->getID(), $charger, 1);
       PassportEvent::recordForAssetsign($assetsign);

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       $this->assertStringContainsString('1/1', $html);
       $this->assertStringContainsString('#2fb344', $html, 'Kit complet : badge vert attendu.');
   }

   public function testShowForItemDisplaysMissingAccessoryBadgeInOrange(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit PassportKit Missing');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC PassportKit Missing');
       $charger = $this->createAccessory('PHPUnit PassportKit Chargeur Manquant');
       $mouse = $this->createAccessory('PHPUnit PassportKit Souris Manquante');
       $kit = $this->createKit('PHPUnit PassportKit Kit Incomplet', [$charger, $mouse]);

       $assetsign = $this->createReturnAssetsign($entityId, $computer, $kit->getID());
       // Seul le chargeur revient : la souris est manquante.
       AssetsignAccessory::attach($assetsign->getID(), $charger, 1);
       PassportEvent::recordForAssetsign($assetsign);

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       $this->assertStringContainsString('1/2', $html);
       $this->assertStringContainsString('#f76707', $html, 'Un accessoire manquant sur deux : badge orange attendu.');
       $this->assertStringContainsString('PHPUnit PassportKit Souris Manquante', $html, 'Le nom de l\'accessoire manquant doit etre affiche.');
   }

   public function testShowForItemDisplaysTotalLossBadgeInRed(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit PassportKit AllMissing');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC PassportKit AllMissing');
       $charger = $this->createAccessory('PHPUnit PassportKit Chargeur Perdu');
       $kit = $this->createKit('PHPUnit PassportKit Kit Tout Perdu', [$charger]);

       $assetsign = $this->createReturnAssetsign($entityId, $computer, $kit->getID());
       // Aucun accessoire n'est revenu.
       PassportEvent::recordForAssetsign($assetsign);

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       $this->assertStringContainsString('0/1', $html);
       $this->assertStringContainsString('#dc3545', $html, 'Rien de revenu : perte totale, badge rouge attendu.');
   }

   public function testShowForItemOmitsBadgeWhenNoKitAssignedToReturn(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit PassportKit None');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC PassportKit None');

       // Aucun kit assigne (plugin_assetsign_kits_id = 0).
       $assetsign = $this->createReturnAssetsign($entityId, $computer, 0);
       PassportEvent::recordForAssetsign($assetsign);

       ob_start();
       PassportEvent::showForItem($computer);
       $html = ob_get_clean();

       $this->assertStringNotContainsString('accessoires du kit restitués', $html);
   }
}
