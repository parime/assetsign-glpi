<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Profile;
use Migration;
use ProfileRight;

/**
 * `Profile::install()`/`uninstall()` had no dedicated test. Safe to exercise the real thing here
 * (unlike a plain `DELETE`, a `DROP TABLE` would still need care) — `AssetsignTestCase` wraps every
 * test in a transaction rolled back in `tearDown()`, and both methods only ever run plain
 * `INSERT`/`UPDATE`/`DELETE` statements against `glpi_profilerights`, never DDL.
 */
final class ProfileTest extends AssetsignTestCase
{
   private function allRights(): array {
       return [Profile::RIGHT_ASSETSIGN, Profile::RIGHT_CONFIG, Profile::RIGHT_TEMPLATE, Profile::RIGHT_MAINTENANCE];
   }

   private function rightsRow(int $profilesId, string $right): ?array {
       global $DB;

       $row = $DB->request(['FROM' => ProfileRight::getTable(), 'WHERE' => ['profiles_id' => $profilesId, 'name' => $right]])->current();

       return $row === null ? null : $row;
   }

   private function profileId(string $name): int {
       global $DB;

       $row = $DB->request(['FROM' => \Profile::getTable(), 'WHERE' => ['name' => $name]])->current();

       return (int) $row['id'];
   }

   public function testInstallGrantsReadCreateUpdateToAdminAndTechnicianOnTheOperationalRights(): void {
       Profile::install(new Migration('1.0.0'));

       $adminId = $this->profileId('Admin');
      foreach ([Profile::RIGHT_ASSETSIGN, Profile::RIGHT_MAINTENANCE] as $right) {
          $row = $this->rightsRow($adminId, $right);
          $this->assertNotNull($row);
          $this->assertSame(READ | CREATE | UPDATE, (int) $row['rights']);
      }

       $technicianId = $this->profileId('Technician');
      foreach ([Profile::RIGHT_ASSETSIGN, Profile::RIGHT_MAINTENANCE] as $right) {
          $row = $this->rightsRow($technicianId, $right);
          $this->assertNotNull($row);
          $this->assertSame(READ | CREATE | UPDATE, (int) $row['rights']);
      }
   }

   public function testInstallLeavesConfigAndTemplateRightsAtZeroForOperationalProfiles(): void {
       Profile::install(new Migration('1.0.0'));

       $adminId = $this->profileId('Admin');
      foreach ([Profile::RIGHT_CONFIG, Profile::RIGHT_TEMPLATE] as $right) {
          $row = $this->rightsRow($adminId, $right);
          $this->assertNotNull($row);
          $this->assertSame(0, (int) $row['rights'], "'$right' is plugin configuration, not day-to-day operation — Admin gets it at 0 by default.");
      }
   }

    /**
     * Regression guard for the exact behaviour documented in `install()`: an operational right is
     * only ever set at row *creation* — an admin who revokes it afterward must see that choice
     * survive a plugin upgrade (which replays `install()`), not get silently re-granted.
     */
   public function testInstallDoesNotReGrantAnOperationalRightThatWasManuallyRevoked(): void {
       Profile::install(new Migration('1.0.0'));

       global $DB;
       $adminId = $this->profileId('Admin');
       $DB->update(ProfileRight::getTable(), ['rights' => 0], ['profiles_id' => $adminId, 'name' => Profile::RIGHT_ASSETSIGN]);

       Profile::install(new Migration('1.0.0'));

       $row = $this->rightsRow($adminId, Profile::RIGHT_ASSETSIGN);
       $this->assertSame(0, (int) $row['rights'], 'A manually revoked operational right must not be re-granted by a replayed install().');
   }

    /**
     * Opposite behaviour, also documented directly in `install()`: Super-Admin's rights are
     * unconditionally reset on every run — the plugin's own safety net must always stay fully
     * usable by at least one profile, even if someone fat-fingered it away.
     */
   public function testInstallAlwaysResetsSuperAdminToFullRightsEvenIfPreviouslyRevoked(): void {
       Profile::install(new Migration('1.0.0'));

       global $DB;
       $superAdminId = $this->profileId('Super-Admin');
       $DB->update(ProfileRight::getTable(), ['rights' => 0], ['profiles_id' => $superAdminId, 'name' => Profile::RIGHT_ASSETSIGN]);

       Profile::install(new Migration('1.0.0'));

       $row = $this->rightsRow($superAdminId, Profile::RIGHT_ASSETSIGN);
       $this->assertSame(ALLSTANDARDRIGHT, (int) $row['rights'], "Super-Admin's rights must always be reset to full on every install() run.");
   }

   public function testInstallIsIdempotentAndDoesNotDuplicateRightsRows(): void {
       Profile::install(new Migration('1.0.0'));
       Profile::install(new Migration('1.0.0'));

       global $DB;
      foreach ($this->allRights() as $right) {
          $count = $DB->request(['FROM' => ProfileRight::getTable(), 'WHERE' => ['name' => $right]])->count();
          $profileCount = $DB->request(['FROM' => \Profile::getTable()])->count();
          $this->assertSame($profileCount, $count, "Exactly one '$right' row per real profile — no duplicate from running install() twice.");
      }
   }

   public function testUninstallRemovesEveryPluginRight(): void {
       Profile::install(new Migration('1.0.0'));

       Profile::uninstall();

       global $DB;
      foreach ($this->allRights() as $right) {
          $count = $DB->request(['FROM' => ProfileRight::getTable(), 'WHERE' => ['name' => $right]])->count();
          $this->assertSame(0, $count, "uninstall() must remove every '$right' row.");
      }
   }

   public function testUninstallThenReinstallRestoresSuperAdminAccess(): void {
       Profile::install(new Migration('1.0.0'));
       Profile::uninstall();
       Profile::install(new Migration('1.0.0'));

       $superAdminId = $this->profileId('Super-Admin');
      foreach ($this->allRights() as $right) {
          $row = $this->rightsRow($superAdminId, $right);
          $this->assertNotNull($row);
          $this->assertSame(ALLSTANDARDRIGHT, (int) $row['rights']);
      }
   }
}
