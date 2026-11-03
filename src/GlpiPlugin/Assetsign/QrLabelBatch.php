<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use CommonGLPI;
use GlpiPlugin\Assetsign\Api\QrLabelController;
use MassiveAction;
use Session;

/**
 * Issue #278 : impression des étiquettes QR de plusieurs matériels d'un coup, depuis une action
 * de masse des listes de matériel. La sélection est gardée en session derrière un jeton aléatoire
 * (jamais d'identifiants dans l'URL) ; chaque matériel est revérifié au moment d'imprimer par
 * Api\QrLabelController::resolve() (type géré, droit READ natif et entité, étiquette activée pour
 * l'entité) : un matériel refusé est simplement compté, jamais affiché.
 */
final class QrLabelBatch extends CommonGLPI
{
   public const ACTION = 'print_qr_labels';

    /** Au-delà, la planche serait inutilisable et coûteuse à générer. */
   public const MAX_ITEMS = 500;

    /** Lots gardés en session (les plus anciens sont oubliés au-delà). */
   private const MAX_BATCHES = 5;

   private const SESSION_KEY = 'plugin_assetsign_qr_batches';

   public static function getTypeName($nb = 0) {
       return __('Étiquettes QR', 'assetsign');
   }

    /**
     * Action proposée sur les listes des types gérés, avec le même droit que l'étiquette unitaire.
     *
     * @return array<string, string>
     */
   public static function massiveActionsFor(string $itemtype): array {
      if (!in_array($itemtype, Config::getAllManageableItemtypes(), true)
           || !Session::haveRight(PassportEvent::$rightname, READ)
       ) {
          return [];
      }

       return [
           self::class . MassiveAction::CLASS_ACTION_SEPARATOR . self::ACTION
               => '<i class="ti ti-qrcode"></i>' . __('Imprimer les étiquettes QR', 'assetsign'),
       ];
   }

   public static function showMassiveActionsSubForm(MassiveAction $ma): bool {
      if ($ma->getAction() === self::ACTION) {
          echo TwigBlocks::fragment('components/massive_action_confirm.html.twig', [
              'message' => sprintf(
                  __('Une planche d\'étiquettes s\'ouvrira pour les matériels sélectionnés (%d au maximum).', 'assetsign'),
                  self::MAX_ITEMS
              ),
          ]);

          return true;
      }

       return false;
   }

    /**
     * @param array<int|string> $ids
     */
   public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids): void {
      if ($ma->getAction() !== self::ACTION) {
          return;
      }
      if (!Session::haveRight(PassportEvent::$rightname, READ)) {
         foreach ($ids as $id) {
             $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
         }

          return;
      }
       $kept = [];
      foreach ($ids as $id) {
          // Le framework des actions de masse ne filtre pas lui-même les droits par élément.
         if (count($kept) < self::MAX_ITEMS && $item->can((int) $id, READ)) {
             $kept[] = (int) $id;
             $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
         } else {
             $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
         }
      }
      if ($kept !== []) {
          $ma->setRedirect(self::getPrintURL(self::store($item->getType(), $kept)));
      }
   }

   public static function getPrintURL(string $token): string {
       global $CFG_GLPI;

       return $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/qrlabels.php?batch=' . rawurlencode($token);
   }

    /**
     * @param list<int> $ids
     */
   public static function store(string $itemtype, array $ids): string {
       $token = bin2hex(random_bytes(16));
       $batches = $_SESSION[self::SESSION_KEY] ?? [];
       $batches[$token] = ['itemtype' => $itemtype, 'ids' => array_slice($ids, 0, self::MAX_ITEMS)];
       $_SESSION[self::SESSION_KEY] = array_slice($batches, -self::MAX_BATCHES, null, true);

       return $token;
   }

    /**
     * Étiquettes d'un lot de la session courante, chaque matériel revérifié. Lot inconnu : null.
     *
     * @return array{labels: list<array<string, mixed>>, skipped: int}|null
     */
   public static function resolve(string $token): ?array {
       $batch = $_SESSION[self::SESSION_KEY][$token] ?? null;
      if (!is_array($batch) || preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
          return null;
      }
       $controller = new QrLabelController();
       $labels = [];
       $skipped = 0;
      foreach ($batch['ids'] as $id) {
         try {
             $labels[] = $controller->resolve((string) $batch['itemtype'], (int) $id);
         } catch (\InvalidArgumentException) {
             $skipped++;
         }
      }

       return ['labels' => $labels, 'skipped' => $skipped];
   }
}
