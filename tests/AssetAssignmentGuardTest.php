<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Security\AssetAssignmentGuard;

/**
 * Guard extracted from front/assign_user_asset.php (see its own docblock) — tested here against
 * real fixtures rather than only reviewed by hand, same reasoning as OpcacheResetGuardTest for the
 * sibling front-script guard.
 */
final class AssetAssignmentGuardTest extends AssetsignTestCase
{
   public function testReturnsNullForAnItemtypeThatIsNotACommonDbtmSubclass(): void {
       $item = (new AssetAssignmentGuard())->resolveAssignableItem('DateTime', 1);

       $this->assertNull($item);
   }

   public function testReturnsNullForAnUnknownClassName(): void {
       $item = (new AssetAssignmentGuard())->resolveAssignableItem('NotARealClass', 1);

       $this->assertNull($item);
   }

   public function testReturnsNullWhenTheItemDoesNotExist(): void {
       $item = (new AssetAssignmentGuard())->resolveAssignableItem('Computer', 999999999);

       $this->assertNull($item);
   }

   public function testReturnsNullWhenTheItemIsOutsideTheSessionActiveEntities(): void {
       $hiddenEntityId = $this->createTestEntity(0, 'PHPUnit AssetAssignmentGuard Hidden');
       $computer = $this->createTestComputer($hiddenEntityId, 'PHPUnit PC AssetAssignmentGuard Hidden');

       // Simulates a session whose active entities do NOT cover this one — same technique
       // AssetsignTest uses for its own cross-entity-disclosure regression guard.
       $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$hiddenEntityId]));

       $item = (new AssetAssignmentGuard())->resolveAssignableItem('Computer', $computer->getID());

       $this->assertNull($item, 'A material outside the session active entities must never be resolvable, even with a valid itemtype.');
   }

   public function testReturnsTheLoadedItemWhenItExistsAndIsWithinScope(): void {
       $entityId = $this->createTestEntity(0, 'PHPUnit AssetAssignmentGuard Visible');
       $computer = $this->createTestComputer($entityId, 'PHPUnit PC AssetAssignmentGuard Visible');

       $item = (new AssetAssignmentGuard())->resolveAssignableItem('Computer', $computer->getID());

       $this->assertNotNull($item);
       $this->assertInstanceOf(\Computer::class, $item);
       $this->assertSame($computer->getID(), $item->getID());
   }
}
