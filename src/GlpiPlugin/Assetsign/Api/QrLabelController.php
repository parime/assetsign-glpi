<?php

namespace GlpiPlugin\Assetsign\Api;

use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\PassportEvent;
use GlpiPlugin\Assetsign\QrCode;

/**
 * Logique de front/qrlabel.php (étiquette QR code imprimable du Passeport
 * matériel, cf. ROADMAP.md V3, issue #82) — même motivation que
 * Api\PassportBackfillController : rendre ce dispatch testable en PHPUnit,
 * sans passer par le vrai front/*.php qui appelle Html::displayNotFoundError()/
 * exit() (cf. TROUBLESHOOTING.md).
 */
final class QrLabelController
{
    /**
     * @return array{item_name: string, item_serial: string, itemtype_label: string, qr_data_uri: ?string, target_url: string}
     * @throws \InvalidArgumentException si l'itemtype n'est pas geré par le plugin, si le matériel
     *         est introuvable/hors de portée (droit READ natif GLPI, entité comprise), ou si
     *         l'étiquette QR code est désactivée pour l'entité de ce matériel.
     */
   public function resolve(string $itemtype, int $items_id): array {
       global $CFG_GLPI;

       // Meme restriction que l'onglet Passeport materiel lui-meme (PassportEvent::
       // isEnabledForItem()) : un itemtype hors de la liste geree par le plugin n'a de
       // toute facon aucun onglet Passeport vers lequel pointer.
      if (!in_array($itemtype, Config::getAllManageableItemtypes(), true)) {
          throw new \InvalidArgumentException(__('Type de matériel invalide.', 'assetsign'));
      }

       $item = new $itemtype();
       // can(..., READ) regroupe le droit GLPI natif sur ce type de materiel ET
       // l'appartenance a une entite accessible par l'utilisateur courant - le seul
       // droit generique du plugin (deja verifie par l'appelant, cf. front/qrlabel.php)
       // ne suffirait pas a lui seul, meme faille de principe que celle deja corrigee
       // dans Assetsign::createManual() (cf. TROUBLESHOOTING.md).
      if (!$item->getFromDB($items_id) || !$item->can($items_id, READ)) {
          throw new \InvalidArgumentException(__('Matériel introuvable.', 'assetsign'));
      }

       $config = Config::getForEntity((int) $item->fields['entities_id']);
      if (!$config->fields['enable_qr_label']) {
          throw new \InvalidArgumentException(__('Étiquette QR code désactivée pour cette entité.', 'assetsign'));
      }

       // URL ABSOLUE (domaine inclus, pas seulement root_doc) : ce lien est encode
       // dans un QR code destine a etre scanne depuis un appareil EXTERNE (telephone),
       // une URL relative n'aurait aucun sens en dehors du navigateur qui affiche
       // cette page-ci. `forcetab=...$1` : meme convention que Assetsign::
       // handleStateBasedTrigger() pour ouvrir directement un onglet precis -
       // PassportEvent::getTabNameForItem() n'enregistre qu'UN SEUL onglet par
       // materiel, donc toujours le suffixe $1 (verifie en conditions reelles, cf.
       // description de la Pull Request).
       $targetUrl = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
           . $itemtype::getFormURLWithID($items_id)
           . '&forcetab=' . urlencode(PassportEvent::class . '$1');

       return [
           'item_name'      => (string) ($item->fields['name'] ?? ''),
           'item_serial'    => (string) ($item->fields['serial'] ?? ''),
           'itemtype_label' => $itemtype::getTypeName(1),
           'qr_data_uri'    => QrCode::toDataUri($targetUrl),
           'target_url'     => $targetUrl,
       ];
   }
}
