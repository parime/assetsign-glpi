<?php

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use CommonGLPI;
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use Migration;
use Session;

/**
 * Issue #322 : attestation d'effacement des données d'un matériel, avant un don, une vente, une
 * destruction ou une réattribution (ISO 27001 A.7.14 et A.8.10).
 *
 * Le technicien déclare depuis l'onglet « Effacement des données » du matériel : la date, la
 * méthode, le niveau NIST SP 800-88 atteint (facultatif), les supports effacés (repris de
 * l'inventaire GLPI : disques et volumes, ou saisis), l'outil, le résultat de la vérification et
 * le rapport de l'outil (facultatif, empreinte SHA-256 conservée). Il signe à l'écran, comme pour
 * une fiche de maintenance : le PDF signé (avec l'en-tête de maîtrise documentaire, #274) devient
 * un Document GLPI lié au matériel, son empreinte SHA-256 est conservée.
 *
 * Une attestation n'est jamais modifiée. Une attestation saisie par erreur s'annule (motif
 * obligatoire) : elle reste visible mais ne compte plus. Elle est valable pour la sortie du
 * matériel si l'effacement est postérieur à sa dernière remise à une personne (cf. getValidFor()).
 * Les fiches de don, de vente et de destruction la mentionnent dans leur PDF ; le bénéficiaire qui
 * signe la fiche en prend donc connaissance par le circuit de signature habituel.
 */
class WipeAttestation extends Compat\Base\WipeAttestationBase
{
   public const RIGHTNAME = Profile::RIGHT_ASSETSIGN;

   public const METHOD_OVERWRITE     = 'overwrite';
   public const METHOD_SECURE_ERASE  = 'secure_erase';
   public const METHOD_CRYPTO_ERASE  = 'crypto_erase';
   public const METHOD_FACTORY_RESET = 'factory_reset';
   public const METHOD_PHYSICAL      = 'physical_destruction';

   public const LEVEL_NONE    = '';
   public const LEVEL_CLEAR   = 'clear';
   public const LEVEL_PURGE   = 'purge';
   public const LEVEL_DESTROY = 'destroy';

   public const RESULT_NOT_VERIFIED = 0;
   public const RESULT_VERIFIED     = 1;
   public const RESULT_FAILED       = 2;

    /** Rapport de l'outil d'effacement : types acceptés (contenu contrôlé, cf. validateReport()). */
   public const REPORT_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'csv', 'log', 'xml', 'json'];
   public const REPORT_MAX_BYTES = 20 * 1024 * 1024;

    /** Supports saisis à la main : nombre de lignes et longueur retenus au plus. */
   private const MAX_MANUAL_MEDIA = 20;
   private const MAX_TEXT = 255;

    /** Rattrapage : nombre de fiches de sortie examinées au plus (les plus récentes). */
   public const CATCH_UP_LIMIT = 200;

   public static function getTypeName($nb = 0): string {
       return $nb > 1
           ? __('Attestations d\'effacement des données', 'assetsign')
           : __('Attestation d\'effacement des données', 'assetsign');
   }

   public static function getIcon(): string {
       return 'ti ti-eraser';
   }

    /** Secteur 'tools' (Outils), comme Assetsign::getSectorizedDetails(). */
   public static function getSectorizedDetails(): array {
       return ['tools', self::class];
   }

    /** Pas de bouton « Ajouter » : une attestation se crée depuis l'onglet du matériel. */
   public static function getMenuContent(): array {
       return Assetsign::withoutAddLink(parent::getMenuContent());
   }

    /** @return array<string, string> */
   public static function getMethodLabels(): array {
       return [
           self::METHOD_OVERWRITE     => __('Effacement logiciel par réécriture (une ou plusieurs passes)', 'assetsign'),
           self::METHOD_SECURE_ERASE  => __('Effacement sécurisé intégré au disque (Secure Erase, Sanitize)', 'assetsign'),
           self::METHOD_CRYPTO_ERASE  => __('Effacement cryptographique (destruction de la clé de chiffrement)', 'assetsign'),
           self::METHOD_FACTORY_RESET => __('Réinitialisation d\'usine avec chiffrement actif (téléphone, tablette)', 'assetsign'),
           self::METHOD_PHYSICAL      => __('Destruction physique du support (broyage, perçage, démagnétisation)', 'assetsign'),
       ];
   }

    /**
     * Niveaux de la norme NIST SP 800-88 (nommés seulement, le texte de la norme n'est pas repris).
     *
     * @return array<string, string>
     */
   public static function getLevelLabels(): array {
       return [
           self::LEVEL_NONE    => __('Aucune norme suivie', 'assetsign'),
           self::LEVEL_CLEAR   => __('NIST SP 800-88 : Clear', 'assetsign'),
           self::LEVEL_PURGE   => __('NIST SP 800-88 : Purge', 'assetsign'),
           self::LEVEL_DESTROY => __('NIST SP 800-88 : Destroy', 'assetsign'),
       ];
   }

    /** @return array<string, string> aide courte de chaque niveau (rédigée pour le plugin) */
   public static function getLevelHelp(): array {
       return [
           self::LEVEL_CLEAR   => __('Données irrécupérables avec les outils courants.', 'assetsign'),
           self::LEVEL_PURGE   => __('Données irrécupérables même avec des moyens de laboratoire.', 'assetsign'),
           self::LEVEL_DESTROY => __('Support rendu physiquement inutilisable.', 'assetsign'),
       ];
   }

    /** @return array<int, string> */
   public static function getResultLabels(): array {
       return [
           self::RESULT_VERIFIED     => __('Vérifié : aucune donnée récupérable', 'assetsign'),
           self::RESULT_NOT_VERIFIED => __('Non vérifié', 'assetsign'),
           self::RESULT_FAILED       => __('Échec : des données restent lisibles', 'assetsign'),
       ];
   }

   public static function getResultColor(int $result): string {
       return match ($result) {
           self::RESULT_VERIFIED => '#2fb344',
           self::RESULT_FAILED   => '#d63939',
           default               => '#f76707',
       };
   }

   public function rawSearchOptions(): array {
       $table = self::getTable();
       return [
           ['id' => 'common', 'name' => self::getTypeName(1)],
           ['id' => 1, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'itemlink', 'itemtype' => self::class],
           ['id' => 2, 'table' => $table, 'field' => 'itemtype', 'name' => __('Type de matériel', 'assetsign'), 'datatype' => 'itemtype'],
           ['id' => 3, 'table' => $table, 'field' => 'items_id', 'name' => __('Matériel', 'assetsign'), 'datatype' => 'itemlink', 'itemlink_type' => ''],
           ['id' => 4, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech', 'name' => __('Technicien', 'assetsign'), 'datatype' => 'itemlink', 'itemlink_type' => 'User'],
           ['id' => 5, 'table' => $table, 'field' => 'date_wipe', 'name' => __('Date de l\'effacement', 'assetsign'), 'datatype' => 'datetime'],
           ['id' => 6, 'table' => $table, 'field' => 'method', 'name' => __('Méthode', 'assetsign'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
           ['id' => 7, 'table' => $table, 'field' => 'nist_level', 'name' => __('Norme', 'assetsign'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
           ['id' => 8, 'table' => $table, 'field' => 'verification_result', 'name' => __('Vérification', 'assetsign'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals']],
           ['id' => 9, 'table' => $table, 'field' => 'tool', 'name' => __('Outil utilisé', 'assetsign'), 'datatype' => 'string'],
           ['id' => 10, 'table' => $table, 'field' => 'is_cancelled', 'name' => __('Annulée', 'assetsign'), 'datatype' => 'bool'],
           ['id' => 11, 'table' => $table, 'field' => 'documents_id', 'name' => __('PDF', 'assetsign'), 'datatype' => 'specific', 'nosearch' => true],
           ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'datatype' => 'dropdown'],
       ];
   }

   public static function getSpecificValueToDisplay($field, $values, array $options = []) {
      if (!is_array($values)) {
          $values = [$field => $values];
      }
       switch ($field) {
         case 'method':
             return htmlescape(self::getMethodLabels()[(string) ($values[$field] ?? '')] ?? '');
         case 'nist_level':
             return htmlescape(self::getLevelLabels()[(string) ($values[$field] ?? '')] ?? '');
         case 'verification_result':
             $result = (int) ($values[$field] ?? 0);
             return TwigBlocks::fragment('components/status_badge.html.twig', [
                 'color' => self::getResultColor($result),
                 'label' => self::getResultLabels()[$result] ?? '',
             ]);
         case 'documents_id':
             $documentsId = (int) ($values[$field] ?? 0);
            if ($documentsId <= 0) {
                return '';
            }
             global $CFG_GLPI;
             return TwigBlocks::fragment('components/document_link.html.twig', [
                 'url'   => $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $documentsId,
                 'label' => __('Télécharger', 'assetsign'),
             ]);
       }
       return parent::getSpecificValueToDisplay($field, $values, $options);
   }

    // ------------------------------------------------------------------ Onglet du matériel

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
      if (!Session::haveRight(self::$rightname, READ) || !($item instanceof CommonDBTM) || $item->isNewItem()) {
          return '';
      }
      if (!in_array($item->getType(), Config::getAllManageableItemtypes(), true)) {
          return '';
      }
       $count = countElementsInTable(self::getTable(), ['itemtype' => $item->getType(), 'items_id' => $item->getID(), 'is_cancelled' => 0]);
       return self::createTabEntry(__('Effacement des données', 'assetsign'), $count, null, self::getIcon());
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
      if (!Session::haveRight(self::$rightname, READ) || !($item instanceof CommonDBTM)) {
          return false;
      }
       self::showForItem($item);
       return true;
   }

   public static function showForItem(CommonDBTM $item): void {
       global $DB;

       $rows = [];
      foreach (
           $DB->request([
               'FROM'  => self::getTable(),
               'WHERE' => ['itemtype' => $item->getType(), 'items_id' => $item->getID()],
               'ORDER' => 'date_wipe DESC',
           ]) as $row
       ) {
          $row['method_label'] = self::getMethodLabels()[$row['method']] ?? '';
          $row['level_label'] = $row['nist_level'] !== '' ? (self::getLevelLabels()[$row['nist_level']] ?? '') : '';
          $row['result_label'] = self::getResultLabels()[(int) $row['verification_result']] ?? '';
          $row['result_color'] = self::getResultColor((int) $row['verification_result']);
          $row['technician'] = (int) $row['users_id_tech'] > 0 ? getUserName((int) $row['users_id_tech']) : '';
          $rows[] = $row;
      }

       $entitiesId = (int) ($item->fields['entities_id'] ?? 0);
       $config = Config::getForEntity($entitiesId);
       $valid = self::getValidFor($item->getType(), $item->getID());
       $requiredTypes = [];
      foreach (WipeRequirement::getTypeLabels() as $type => $label) {
         if (WipeRequirement::modeForType($config, $type) !== WipeRequirement::MODE_DISABLED) {
             $requiredTypes[] = $label;
         }
      }

       TemplateRenderer::getInstance()->display('@assetsign/wipeattestation_tab.html.twig', [
           'item'             => $item,
           'attestations'     => $rows,
           'valid_id'         => $valid !== null ? (int) $valid['id'] : 0,
           'requirement_mode' => WipeRequirement::normalizeMode($config->fields['wipe_requirement'] ?? 0),
           'required_types'   => $requiredTypes,
           'can_create'       => Session::haveRight(self::$rightname, CREATE),
           'method_labels'    => self::getMethodLabels(),
           'level_labels'     => self::getLevelLabels(),
           'level_help'       => self::getLevelHelp(),
           'result_labels'    => self::getResultLabels(),
           'inventory_media'  => self::getInventoryMedia($item),
           'report_accept'    => '.' . implode(',.', self::REPORT_EXTENSIONS),
           'now'              => date('Y-m-d\TH:i'),
           // Signature du technicien : toujours exigée (cf. technician_signature_edit.html.twig).
           'signature_required' => true,
           'csrf_token'       => Session::getNewCSRFToken(),
       ]);
   }

    /**
     * Supports de stockage connus de l'inventaire GLPI : disques physiques (composants « Disque
     * dur », avec numéro de série) et volumes (onglet « Volumes », avec leur chiffrement).
     *
     * @return list<array{key: string, kind: string, label: string, serial: string, capacity: string, encryption: string}>
     */
   public static function getInventoryMedia(CommonDBTM $item): array {
       global $DB;

       $media = [];
      foreach (
           $DB->request([
               'SELECT'    => [
                   'glpi_items_deviceharddrives.id',
                   'glpi_items_deviceharddrives.serial',
                   'glpi_items_deviceharddrives.capacity',
                   'glpi_deviceharddrives.designation',
               ],
               'FROM'      => 'glpi_items_deviceharddrives',
               'LEFT JOIN' => [
                   'glpi_deviceharddrives' => ['FKEY' => ['glpi_items_deviceharddrives' => 'deviceharddrives_id', 'glpi_deviceharddrives' => 'id']],
               ],
               'WHERE'     => [
                   'glpi_items_deviceharddrives.itemtype'   => $item->getType(),
                   'glpi_items_deviceharddrives.items_id'   => $item->getID(),
                   'glpi_items_deviceharddrives.is_deleted' => 0,
               ],
               'ORDER'     => 'glpi_items_deviceharddrives.id',
           ]) as $row
       ) {
          $media[] = [
              'key'        => 'drive:' . (int) $row['id'],
              'kind'       => 'drive',
              'label'      => (string) ($row['designation'] ?: __('Disque', 'assetsign')),
              'serial'     => (string) ($row['serial'] ?? ''),
              'capacity'   => self::formatMegabytes((int) ($row['capacity'] ?? 0)),
              'encryption' => '',
          ];
      }
      foreach (
           $DB->request([
               'FROM'  => 'glpi_items_disks',
               'WHERE' => ['itemtype' => $item->getType(), 'items_id' => $item->getID(), 'is_deleted' => 0],
               'ORDER' => 'id',
           ]) as $row
       ) {
          $label = trim((string) ($row['name'] ?? ''));
          $mount = trim((string) ($row['mountpoint'] ?? ''));
          $media[] = [
              'key'        => 'volume:' . (int) $row['id'],
              'kind'       => 'volume',
              'label'      => sprintf(__('Volume %s', 'assetsign'), trim($label . ($mount !== '' && $mount !== $label ? ' (' . $mount . ')' : '')) ?: '#' . (int) $row['id']),
              'serial'     => '',
              'capacity'   => self::formatMegabytes((int) ($row['totalsize'] ?? 0)),
              'encryption' => match ((int) ($row['encryption_status'] ?? 0)) {
                  1 => __('Chiffré', 'assetsign'),
                  2 => __('Partiellement chiffré', 'assetsign'),
                  default => __('Non chiffré', 'assetsign'),
              },
          ];
      }
       return $media;
   }

   private static function formatMegabytes(int $megabytes): string {
      if ($megabytes <= 0) {
          return '';
      }
      if ($megabytes >= 1024 * 1024) {
          return sprintf('%s %s', rtrim(rtrim(number_format($megabytes / 1024 / 1024, 1, ',', ''), '0'), ','), __('To', 'assetsign'));
      }
      if ($megabytes >= 1024) {
          return sprintf('%s %s', rtrim(rtrim(number_format($megabytes / 1024, 1, ',', ''), '0'), ','), __('Go', 'assetsign'));
      }
       return sprintf('%d %s', $megabytes, __('Mo', 'assetsign'));
   }

    /**
     * Supports retenus : ceux cochés parmi l'inventaire (clés de getInventoryMedia()), puis ceux
     * saisis à la main (une ligne par support). Instantané : l'inventaire peut changer ensuite.
     *
     * @param list<string> $selectedKeys
     * @return list<array{label: string, serial: string, capacity: string, encryption: string, source: string}>
     */
   public static function buildMediaSnapshot(CommonDBTM $item, array $selectedKeys, string $manual): array {
       $snapshot = [];
      foreach (self::getInventoryMedia($item) as $medium) {
         if (in_array($medium['key'], $selectedKeys, true)) {
             $snapshot[] = [
                 'label'      => $medium['label'],
                 'serial'     => $medium['serial'],
                 'capacity'   => $medium['capacity'],
                 'encryption' => $medium['encryption'],
                 'source'     => 'inventory',
             ];
         }
      }
       $lines = array_filter(array_map('trim', preg_split('/\R/', $manual) ?: []), static fn (string $line): bool => $line !== '');
      foreach (array_slice(array_values($lines), 0, self::MAX_MANUAL_MEDIA) as $line) {
          $snapshot[] = ['label' => mb_substr($line, 0, self::MAX_TEXT), 'serial' => '', 'capacity' => '', 'encryption' => '', 'source' => 'manual'];
      }
       return $snapshot;
   }

    /** @return list<array{label: string, serial: string, capacity: string, encryption: string, source: string}> */
   public function getMedia(): array {
       $media = json_decode((string) ($this->fields['media'] ?? ''), true);
       return is_array($media) ? array_values(array_filter($media, 'is_array')) : [];
   }

    // ------------------------------------------------------------------ Création

    /**
     * Crée et signe une attestation. Tout est contrôlé avant la moindre écriture : méthode, niveau,
     * résultat, date (jamais dans le futur), au moins un support, signature, rapport joint.
     *
     * @param array{date_wipe?: string, method?: string, nist_level?: string, verification_result?: int|string, tool?: string, comment?: string, media?: list<string>, media_other?: string} $input
     * @param array{name?: string, tmp_name?: string, error?: int}|null $report fichier envoyé ($_FILES)
     * @param array{ip?: string, user_agent?: string} $meta
     * @throws \RuntimeException message pour l'utilisateur
     */
   public static function create(CommonDBTM $item, array $input, string $signatureImage, ?array $report = null, array $meta = []): self {
       $method = (string) ($input['method'] ?? '');
      if (!array_key_exists($method, self::getMethodLabels())) {
          throw new \RuntimeException(__('Choisissez la méthode d\'effacement utilisée.', 'assetsign'));
      }
       $level = (string) ($input['nist_level'] ?? '');
      if (!array_key_exists($level, self::getLevelLabels())) {
          throw new \RuntimeException(__('Norme d\'effacement inconnue.', 'assetsign'));
      }
       $result = (int) ($input['verification_result'] ?? self::RESULT_NOT_VERIFIED);
      if (!array_key_exists($result, self::getResultLabels())) {
          throw new \RuntimeException(__('Résultat de vérification inconnu.', 'assetsign'));
      }
       $dateWipe = self::normalizeDate((string) ($input['date_wipe'] ?? ''));

       $media = self::buildMediaSnapshot(
           $item,
           array_map('strval', is_array($input['media'] ?? null) ? $input['media'] : []),
           (string) ($input['media_other'] ?? '')
       );
      if ($media === []) {
          throw new \RuntimeException(__('Indiquez au moins un support effacé : cochez un disque de l\'inventaire ou saisissez-le.', 'assetsign'));
      }

       Pdf\SignatureImageValidator::assertValid($signatureImage);

       $reportFile = null;
      if ($report !== null && ($report['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
         if (($report['error'] ?? null) !== UPLOAD_ERR_OK || empty($report['tmp_name']) || !is_uploaded_file((string) $report['tmp_name'])) {
             throw new \RuntimeException(__('Échec de l\'envoi du document.', 'assetsign'));
         }
          self::validateReport((string) $report['tmp_name'], (string) ($report['name'] ?? ''));
          $reportFile = ['path' => (string) $report['tmp_name'], 'name' => basename((string) ($report['name'] ?? 'rapport'))];
      }

       return self::store($item, [
           'date_wipe'           => $dateWipe,
           'method'              => $method,
           'nist_level'          => $level,
           'verification_result' => $result,
           'tool'                => mb_substr(trim((string) ($input['tool'] ?? '')), 0, self::MAX_TEXT),
           'comment'             => trim((string) ($input['comment'] ?? '')),
           'media'               => $media,
       ], $signatureImage, $reportFile, $meta);
   }

    /**
     * Écriture une fois les contrôles passés (aussi appelée par les tests, avec un rapport déjà
     * présent sur le disque).
     *
     * @param array{date_wipe: string, method: string, nist_level: string, verification_result: int, tool: string, comment: string, media: list<array<string, string>>} $data
     * @param array{path: string, name: string}|null $reportFile
     * @param array{ip?: string, user_agent?: string} $meta
     */
   public static function store(CommonDBTM $item, array $data, string $signatureImage, ?array $reportFile = null, array $meta = []): self {
       $usersId = (int) (Session::getLoginUserID() ?: 0);
       $attestation = new self();
       $id = (int) $attestation->add([
           'entities_id'         => (int) ($item->fields['entities_id'] ?? 0),
           'itemtype'            => $item->getType(),
           'items_id'            => $item->getID(),
           'users_id_tech'       => $usersId,
           'date_wipe'           => $data['date_wipe'],
           'method'              => $data['method'],
           'nist_level'          => $data['nist_level'],
           'verification_result' => $data['verification_result'],
           'tool'                => $data['tool'],
           'comment'             => $data['comment'],
           'media'               => json_encode($data['media'], JSON_UNESCAPED_UNICODE),
       ]);
      if ($id <= 0 || !$attestation->getFromDB($id)) {
          throw new \RuntimeException(__('Échec de la création de l\'attestation.', 'assetsign'));
      }

      if ($reportFile !== null) {
          [$reportId, $reportHash] = $attestation->storeReport($reportFile['path'], $reportFile['name']);
          $attestation->update(['id' => $id, 'report_documents_id' => $reportId, 'report_hash' => $reportHash]);
          $attestation->getFromDB($id);
      }

       $signedAt = date('Y-m-d H:i:s');
       $technician = $attestation->getTechnician();
       $signerName = Pdf\PdfMetadata::personName($technician);
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
       $built = (new Pdf\WipeAttestationPdfBuilder())->build($attestation, $signatureImage, $signedAt);
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
       $attestation->update([
           'id'           => $id,
           'documents_id' => $built['document']->getID(),
           'pdf_hash'     => $built['hash'],
           'signer_name'  => mb_substr($signerName, 0, self::MAX_TEXT),
           'signed_at'    => $signedAt,
           'ip_address'   => mb_substr((string) ($meta['ip'] ?? ''), 0, 46),
           'user_agent'   => mb_substr((string) ($meta['user_agent'] ?? ''), 0, 512),
       ]);
       $attestation->getFromDB($id);

       PassportEvent::recordForWipe($attestation);

       return $attestation;
   }

    /** Date de l'effacement (champ datetime-local), jamais dans le futur ; vide = maintenant. */
   public static function normalizeDate(string $value): string {
       $value = trim(str_replace('T', ' ', $value));
      if ($value === '') {
          return date('Y-m-d H:i:s');
      }
       $timestamp = strtotime($value);
      if ($timestamp === false) {
          throw new \RuntimeException(__('Date de l\'effacement invalide.', 'assetsign'));
      }
      if ($timestamp > time() + 300) {
          throw new \RuntimeException(__('La date de l\'effacement ne peut pas être dans le futur.', 'assetsign'));
      }
       return date('Y-m-d H:i:s', $timestamp);
   }

    /**
     * Contrôle du rapport : taille, extension, et contenu réel (un .pdf doit être un PDF, une image
     * une image décodable, un texte ne doit pas contenir de code PHP).
     *
     * @throws \RuntimeException
     */
   public static function validateReport(string $path, string $originalName): void {
      if (!is_file($path) || !is_readable($path)) {
          throw new \RuntimeException(__('Échec de l\'envoi du document.', 'assetsign'));
      }
       $size = (int) filesize($path);
      if ($size <= 0) {
          throw new \RuntimeException(__('Le rapport joint est vide.', 'assetsign'));
      }
      if ($size > self::REPORT_MAX_BYTES) {
          throw new \RuntimeException(sprintf(__('Le rapport dépasse la taille maximale (%d Mo).', 'assetsign'), self::REPORT_MAX_BYTES / 1024 / 1024));
      }
       $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
      if (!in_array($extension, self::REPORT_EXTENSIONS, true) || Document::isValidDoc($originalName) === '') {
          throw new \RuntimeException(sprintf(__('Type de fichier refusé : le rapport doit être au format %s.', 'assetsign'), strtoupper(implode(', ', self::REPORT_EXTENSIONS))));
      }
       $head = (string) file_get_contents($path, false, null, 0, 8);
       $valid = match ($extension) {
           'pdf'         => str_starts_with($head, '%PDF-'),
           'png'         => (getimagesize($path)[2] ?? null) === IMAGETYPE_PNG,
           'jpg', 'jpeg' => (getimagesize($path)[2] ?? null) === IMAGETYPE_JPEG,
           default       => self::isPlainText($path),
       };
      if (!$valid) {
          throw new \RuntimeException(__('Le contenu du rapport ne correspond pas à son type de fichier.', 'assetsign'));
      }
   }

   private static function isPlainText(string $path): bool {
       $content = (string) file_get_contents($path);
       return !str_contains($content, "\0")
           && preg_match('/<\?(php|=)/i', $content) !== 1
           && mb_check_encoding($content, 'UTF-8');
   }

    /**
     * Rapport de l'outil enregistré comme Document GLPI, lié à l'attestation et au matériel.
     *
     * @return array{0: int, 1: string} id du document, empreinte SHA-256 du fichier reçu
     */
   private function storeReport(string $path, string $originalName): array {
       $hash = (string) hash_file('sha256', $path);
       $tmpName = uniqid('assetsign_wipe_report_', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
      if (!copy($path, GLPI_TMP_DIR . '/' . $tmpName)) {
          throw new \RuntimeException(__('Échec de l\'envoi du document.', 'assetsign'));
      }
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
       $documentsId = (int) (new Document())->add([
           'name'          => sprintf(__('Rapport d\'effacement - %s', 'assetsign'), $originalName),
           'entities_id'   => (int) $this->fields['entities_id'],
           '_filename'     => [$tmpName],
           '_tag_filename' => [$tmpName],
       ]);
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
      if ($documentsId <= 0) {
          throw new \RuntimeException(__('Échec de l\'envoi du document.', 'assetsign'));
      }
      foreach ([[self::class, $this->getID()], [(string) $this->fields['itemtype'], (int) $this->fields['items_id']]] as [$itemtype, $itemsId]) {
          (new Document_Item())->add(['documents_id' => $documentsId, 'itemtype' => $itemtype, 'items_id' => $itemsId]);
      }
       return [$documentsId, $hash];
   }

    // ------------------------------------------------------------------ Annulation

    /**
     * Annule une attestation saisie par erreur : elle reste consultable (PDF compris) mais ne
     * compte plus. Seul changement possible après la création, toujours par une action explicite.
     *
     * @throws \RuntimeException
     */
   public function cancel(string $reason, int $usersId): void {
      if ((int) $this->fields['is_cancelled'] === 1) {
          throw new \RuntimeException(__('Cette attestation est déjà annulée.', 'assetsign'));
      }
       $reason = trim($reason);
      if ($reason === '') {
          throw new \RuntimeException(__('Indiquez le motif de l\'annulation.', 'assetsign'));
      }
       $this->update([
           'id'              => $this->getID(),
           'is_cancelled'    => 1,
           'cancel_reason'   => mb_substr($reason, 0, 1000),
           'users_id_cancel' => $usersId,
           'date_cancelled'  => date('Y-m-d H:i:s'),
       ]);
       $this->getFromDB($this->getID());
   }

    // ------------------------------------------------------------------ Validité

    /**
     * Attestation valable pour la sortie du matériel : non annulée, sans échec de vérification,
     * et réalisée après la dernière remise du matériel à une personne (avant $before si fourni :
     * rattrapage d'une fiche déjà créée). La plus récente, ou null.
     *
     * @return array<string, mixed>|null
     */
   public static function getValidFor(string $itemtype, int $itemsId, ?string $before = null): ?array {
       global $DB;

      if (!$DB->tableExists(self::getTable())) {
          return null;
      }
       $criteria = [
           'itemtype'     => $itemtype,
           'items_id'     => $itemsId,
           'is_cancelled' => 0,
           'NOT'          => ['verification_result' => self::RESULT_FAILED],
       ];
       $since = WipeRequirement::lastHandoverDate($itemtype, $itemsId, $before);
      if ($since !== null) {
          $criteria[] = ['date_wipe' => ['>=', $since]];
      }
       $row = $DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => $criteria,
           'ORDER' => ['date_wipe DESC', 'id DESC'],
           'LIMIT' => 1,
       ])->current();
       return $row ?: null;
   }

    /**
     * Résumé de l'attestation valable, pour le PDF d'une fiche de sortie (don, vente, destruction),
     * ou null.
     *
     * @return array{reference: int, date: string, method: string, level: string, technician: string, result: string, pdf_hash: string}|null
     */
   public static function summaryFor(string $itemtype, int $itemsId, ?string $before = null): ?array {
       $row = self::getValidFor($itemtype, $itemsId, $before);
      if ($row === null) {
          return null;
      }
       return [
           'reference'  => (int) $row['id'],
           'date'       => (string) \Html::convDateTime((string) $row['date_wipe']),
           'method'     => self::getMethodLabels()[$row['method']] ?? '',
           'level'      => $row['nist_level'] !== '' ? (self::getLevelLabels()[$row['nist_level']] ?? '') : '',
           'technician' => (string) ($row['signer_name'] ?? ''),
           'result'     => self::getResultLabels()[(int) $row['verification_result']] ?? '',
           'pdf_hash'   => (string) ($row['pdf_hash'] ?? ''),
       ];
   }

    /**
     * Rattrapage : fiches de don, de vente et de destruction (non annulées) dont le matériel n'avait
     * pas d'attestation valable, dans les entités de l'utilisateur, plus récentes d'abord.
     *
     * @return list<array{id: int, reference: string, type: string, item: string, item_url: string, date: string, status: string, tab_url: string}>
     */
   public static function getExitsWithoutAttestation(): array {
       global $DB;

       $types = Assetsign::getTypes();
       $statuses = Assetsign::getStatuses();
       $rows = [];
      foreach (
           $DB->request([
               'FROM'  => Assetsign::getTable(),
               'WHERE' => [
                   'type'       => [Assetsign::TYPE_DON, Assetsign::TYPE_VENTE, Assetsign::TYPE_DESTRUCTION],
                   'is_deleted' => 0,
                   'NOT'        => ['status' => [Assetsign::STATUS_DRAFT, Assetsign::STATUS_CANCELLED]],
               ] + getEntitiesRestrictCriteria(Assetsign::getTable(), '', '', true),
               'ORDER' => 'date_creation DESC',
               'LIMIT' => self::CATCH_UP_LIMIT,
           ]) as $sheet
       ) {
          $itemtype = (string) $sheet['itemtype'];
          $itemsId = (int) $sheet['items_id'];
         if (self::getValidFor($itemtype, $itemsId, (string) $sheet['date_creation']) !== null) {
             continue;
         }
          $item = Assetsign::resolveReadableItem($itemtype, $itemsId);
          $rows[] = [
              'id'        => (int) $sheet['id'],
              'reference' => Assetsign::displayReference($sheet),
              'type'      => (string) ($types[(int) $sheet['type']] ?? ''),
              'item'      => $item !== null ? (string) $item->getName() : $itemtype . ' #' . $itemsId,
              'item_url'  => $item !== null ? (string) $item->getLinkURL() : '',
              'tab_url'   => $item !== null ? $itemtype::getFormURLWithID($itemsId) . '&forcetab=' . rawurlencode(self::class . '$1') : '',
              'date'      => (string) $sheet['date_creation'],
              'status'    => (string) ($statuses[(int) $sheet['status']] ?? ''),
          ];
      }
       return $rows;
   }

    // ------------------------------------------------------------------ Fiche

   public function showForm($ID, array $options = []): bool {
       $this->initForm($ID, $options);
       $itemtype = (string) ($this->fields['itemtype'] ?? '');
       $item = Assetsign::resolveReadableItem($itemtype, (int) ($this->fields['items_id'] ?? 0));

       TemplateRenderer::getInstance()->display('@assetsign/wipeattestation_form.html.twig', [
           'attestation'   => $this->fields,
           'item_name'     => $item !== null ? (string) $item->getName() : $itemtype . ' #' . (int) ($this->fields['items_id'] ?? 0),
           'item_url'      => $item !== null ? (string) $item->getLinkURL() : '',
           'item_type'     => $itemtype !== '' ? Assetsign::getItemtypeLabel($itemtype) : '',
           'technician'    => (int) ($this->fields['users_id_tech'] ?? 0) > 0 ? getUserName((int) $this->fields['users_id_tech']) : '',
           'cancelled_by'  => (int) ($this->fields['users_id_cancel'] ?? 0) > 0 ? getUserName((int) $this->fields['users_id_cancel']) : '',
           'method_label'  => self::getMethodLabels()[$this->fields['method'] ?? ''] ?? '',
           'level_label'   => ($this->fields['nist_level'] ?? '') !== '' ? (self::getLevelLabels()[$this->fields['nist_level']] ?? '') : '',
           'result_label'  => self::getResultLabels()[(int) ($this->fields['verification_result'] ?? 0)] ?? '',
           'result_color'  => self::getResultColor((int) ($this->fields['verification_result'] ?? 0)),
           'media'         => $this->getMedia(),
           'report_name'   => $this->getReportName(),
           'is_valid'      => self::getValidFor($itemtype, (int) ($this->fields['items_id'] ?? 0)) !== null
               && (int) (self::getValidFor($itemtype, (int) ($this->fields['items_id'] ?? 0))['id'] ?? 0) === $this->getID(),
           'can_cancel'    => (int) ($this->fields['is_cancelled'] ?? 0) === 0 && $this->can($this->getID(), UPDATE),
           'csrf_token'    => Session::getNewCSRFToken(),
       ]);
       return true;
   }

   private function getReportName(): string {
       $documentsId = (int) ($this->fields['report_documents_id'] ?? 0);
       $document = new Document();
       return $documentsId > 0 && $document->getFromDB($documentsId) ? (string) $document->fields['filename'] : '';
   }

    /** Technicien qui a réalisé l'effacement, avec son e-mail. */
   public function getTechnician(): array {
       $user = new \User();
       $user->getFromDB((int) $this->fields['users_id_tech']);
       $fields = $user->fields;
       $fields['email'] = \UserEmail::getDefaultForUser((int) $this->fields['users_id_tech']) ?: '';
       return $fields;
   }

    /** Matériel concerné, avec marque et modèle résolus (même forme que Maintenance::getTargetItem()). */
   public function getTargetItem(): array {
       $itemtype = (string) $this->fields['itemtype'];
      if (!is_subclass_of($itemtype, CommonDBTM::class)) {
          return [];
      }
       $item = new $itemtype();
      if (!$item->getFromDB((int) $this->fields['items_id'])) {
          return [];
      }
       $fields = $item->fields;
       $fields['manufacturer_name'] = Assetsign::resolveManufacturerName($item);
       $fields['model_name'] = Assetsign::resolveModelName($item);
       return $fields;
   }

    /** Nom du Document PDF : date en tête pour le tri, comme Maintenance::getDocumentTitle(). */
   public function getDocumentTitle(): string {
       $item = $this->getTargetItem();
       $date = date('Y-m-d', strtotime((string) ($this->fields['date_wipe'] ?: 'now')));
       return trim(sprintf(
           '%s - %s - %s (%s)',
           $date,
           __('Attestation d\'effacement des données', 'assetsign'),
           (string) ($item['name'] ?? '') ?: (string) $this->fields['itemtype'],
           Pdf\PdfMetadata::personName($this->getTechnician()) ?: '?'
       ));
   }

    // ------------------------------------------------------------------ Installation

   public static function install(Migration $migration): void {
       global $DB;

       $table = self::getTable();
      if ($DB->tableExists($table)) {
          return;
      }
       $migration->displayMessage('Création de la table ' . $table);
       $DB->doQuery("CREATE TABLE `$table` (
             `id` int unsigned NOT NULL AUTO_INCREMENT,
             `entities_id` int unsigned NOT NULL DEFAULT 0,
             `itemtype` varchar(100) NOT NULL,
             `items_id` int unsigned NOT NULL DEFAULT 0,
             `users_id_tech` int unsigned NOT NULL DEFAULT 0,
             `date_wipe` timestamp NULL DEFAULT NULL,
             `method` varchar(32) NOT NULL DEFAULT '',
             `nist_level` varchar(16) NOT NULL DEFAULT '',
             `verification_result` tinyint NOT NULL DEFAULT 0,
             `tool` varchar(255) NOT NULL DEFAULT '',
             `media` longtext,
             `comment` text,
             `report_documents_id` int unsigned NOT NULL DEFAULT 0,
             `report_hash` varchar(64) NOT NULL DEFAULT '',
             `documents_id` int unsigned NOT NULL DEFAULT 0,
             `pdf_hash` varchar(64) NOT NULL DEFAULT '',
             `signer_name` varchar(255) NOT NULL DEFAULT '',
             `signed_at` timestamp NULL DEFAULT NULL,
             `ip_address` varchar(46) NOT NULL DEFAULT '',
             `user_agent` varchar(512) NOT NULL DEFAULT '',
             `is_cancelled` tinyint NOT NULL DEFAULT 0,
             `cancel_reason` text,
             `users_id_cancel` int unsigned NOT NULL DEFAULT 0,
             `date_cancelled` timestamp NULL DEFAULT NULL,
             `date_creation` timestamp NULL DEFAULT NULL,
             `date_mod` timestamp NULL DEFAULT NULL,
             PRIMARY KEY (`id`),
             KEY `item` (`itemtype`, `items_id`),
             KEY `entities_id` (`entities_id`),
             KEY `users_id_tech` (`users_id_tech`),
             KEY `date_wipe` (`date_wipe`),
             KEY `is_cancelled` (`is_cancelled`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

       // Nouveau type d'événement du passeport (effacement) : visible dans les entités qui ont
       // déjà choisi leurs types, comme les autres. Une seule fois, à la création de la table.
      foreach ($DB->request(['SELECT' => ['id', 'passport_visible_types'], 'FROM' => Config::getTable()]) as $row) {
          $types = json_decode((string) ($row['passport_visible_types'] ?? ''), true);
         if (is_array($types) && !in_array(PassportEvent::TYPE_WIPE, array_map('intval', $types), true)) {
             $types[] = PassportEvent::TYPE_WIPE;
             $DB->update(Config::getTable(), ['passport_visible_types' => json_encode(array_values(array_map('intval', $types)))], ['id' => $row['id']]);
         }
      }

       $rank = 1;
      foreach ([3, 5, 6, 8, 4, 11] as $searchOptionId) {
          $DB->insert('glpi_displaypreferences', [
              'itemtype'  => self::class,
              'num'       => $searchOptionId,
              'rank'      => $rank++,
              'users_id'  => 0,
              'interface' => 'central',
          ]);
      }
   }
}
