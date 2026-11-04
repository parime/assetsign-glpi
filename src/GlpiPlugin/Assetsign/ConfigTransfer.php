<?php

namespace GlpiPlugin\Assetsign;

use State;

/**
 * Issue #279 : export et import de la configuration d'une entité (réglages, gabarits par défaut,
 * logo) dans un fichier JSON, pour reproduire une configuration d'une instance à l'autre (recette
 * vers production) ou la sauvegarder avant une modification.
 *
 * Format repris des Blueprints de Configuration GLPI Auto (BlueprintSerializer) : en-tête
 * format_version / plugin_version / exported_at, section « config » où les colonnes stockées en
 * JSON sont décodées, colonnes propres à l'instance jamais exportées, aperçu des différences
 * avant d'appliquer.
 *
 * Sécurité : le fichier importé est une donnée non fiable. Schéma strict (clé inconnue = fichier
 * refusé, types et longueurs contrôlés), taille bornée, aucun chemin de fichier lu dans le
 * fichier. Les objets GLPI (statuts, blocs du plugin Fields) sont désignés par leur nom et
 * recherchés parmi ceux visibles depuis l'entité cible, jamais par un identifiant d'une autre
 * instance. Les secrets (adresse du webhook, configuration du fournisseur) ne sont jamais exportés
 * et sont ignorés s'ils figurent dans un fichier importé.
 */
final class ConfigTransfer
{
   public const FORMAT_VERSION = 1;

   /** Taille maximale du fichier importé (le logo, encodé en base64, en occupe l'essentiel). */
   public const MAX_FILE_BYTES = 3 * 1024 * 1024;

   /** Taille maximale du logo, une fois décodé. */
   public const LOGO_MAX_BYTES = 1024 * 1024;

   /** Longueur maximale d'un texte de gabarit (colonne TEXT). */
   private const TEMPLATE_TEXT_MAX = 65535;

   /** Nombre maximal d'éléments d'une liste (statuts, types...). */
   private const MAX_LIST = 200;

   private const SESSION_KEY = 'plugin_assetsign_config_import';

   /** Clés admises à la racine du fichier. */
   private const TOP_KEYS = [
      'format_version', 'plugin', 'plugin_version', 'exported_at', 'entity_name',
      'config', 'templates', 'logo', 'excluded',
   ];

   private const TEMPLATE_KEYS = ['type', 'name', 'content', 'charter_content', 'include_content', 'include_charter'];

   /** Colonnes propres à cette instance ou à cette ligne : jamais exportées ni importées. */
   public const INSTANCE_FIELDS = [
      'id', 'entities_id', 'is_recursive', 'date_creation', 'date_mod',
      'logo_documents_id', 'renewal_last_change',
   ];

   /** Secrets : jamais exportés, ignorés (et signalés) s'ils figurent dans un fichier importé. */
   public const SECRET_FIELDS = ['chat_webhook_url', 'provider_config'];

   /** Formats d'image admis pour le logo : type détecté => [type MIME, extension]. */
   private const LOGO_TYPES = [
      IMAGETYPE_PNG  => ['image/png', 'png'],
      IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
      IMAGETYPE_GIF  => ['image/gif', 'gif'],
      IMAGETYPE_WEBP => ['image/webp', 'webp'],
   ];

   /**
    * Schéma de chaque réglage exporté. Toute nouvelle colonne de la table de configuration doit
    * figurer ici, dans INSTANCE_FIELDS ou dans SECRET_FIELDS (vérifié par ConfigTransferTest).
    *
    * @var array<string, array<int, mixed>>
    */
   private const SCHEMA = [
      'sender_name'                          => ['string', 255],
      'sender_email'                         => ['email'],
      'logo_force_children'                  => ['bool'],
      'company_name'                         => ['string', 255],
      'protect_pdf'                          => ['bool'],
      'enable_passport'                      => ['bool'],
      'passport_retention_years'             => ['int', 0, 100],
      'passport_visible_types'               => ['passport_types'],
      'show_infocom_dates'                   => ['bool'],
      'show_linked_tickets'                  => ['bool'],
      'enable_health_score'                  => ['bool'],
      'health_weight_age'                    => ['int', 0, 100],
      'health_weight_incidents'              => ['int', 0, 100],
      'health_weight_damage'                 => ['int', 0, 100],
      'health_weight_movements'              => ['int', 0, 100],
      'health_weight_energy'                 => ['int', 0, 100],
      'health_score_good_threshold'          => ['int', 0, 100],
      'health_score_warning_threshold'       => ['int', 0, 100],
      'health_score_good_color'              => ['string', 7, '/^#[0-9a-fA-F]{6}$/'],
      'health_score_warning_color'           => ['string', 7, '/^#[0-9a-fA-F]{6}$/'],
      'health_score_critical_color'          => ['string', 7, '/^#[0-9a-fA-F]{6}$/'],
      'charter_url'                          => ['url'],
      'charter_campaign_enabled'             => ['bool'],
      'charter_renewal_months'               => ['int', 1, 60],
      'charter_deadline_days'                => ['int', 1, 365],
      'default_provider'                     => ['enum', ['canvas']],
      'reminder_delays'                      => ['string', 255, '/^\d{1,4}(,\d{1,4}){0,19}$/'],
      'max_reminders'                        => ['int', 0, 100],
      'link_validity_days'                   => ['int', 0, 3650],
      'expiry_warning_days'                  => ['int', 0, 3650],
      'draft_reminder_days'                  => ['int', 0, 3650],
      'signature_required'                   => ['bool'],
      'enable_observations'                  => ['bool'],
      'auto_attach_connected_items'          => ['bool'],
      'confirm_before_sending'               => ['enum', [0, 1, 2]],
      'ignore_inventory_changes'             => ['bool'],
      'assign_handed_items'                  => ['bool'],
      'handed_items_state'                   => ['handed_state'],
      'enable_don'                           => ['bool'],
      'enable_vente'                         => ['bool'],
      'enable_destruction'                   => ['bool'],
      'enable_damage_annotation'             => ['bool'],
      'enable_maintenance_signature'         => ['bool'],
      'enable_movements'                     => ['bool'],
      'enable_movement_signature'            => ['bool'],
      'enable_signature_delegation'          => ['bool'],
      'enable_self_service_delegation'       => ['bool'],
      'show_qr_code'                         => ['bool'],
      'enable_qr_label'                      => ['bool'],
      'currency_symbol'                      => ['string', 255],
      'preview_watermark_text'               => ['string', 255],
      'preview_watermark_opacity'            => ['int', 5, 100],
      'sign_on_assignment'                   => ['bool'],
      'sign_on_reassignment'                 => ['bool'],
      'sign_on_return'                       => ['bool'],
      'managed_itemtypes'                    => ['itemtypes'],
      'handover_states'                      => ['states'],
      'stock_states'                         => ['states'],
      'return_returned_state'                => ['state'],
      'return_damaged_state'                 => ['state'],
      'return_missing_ticket'                => ['bool'],
      'resolve_ticket_on_signature'          => ['bool'],
      'return_states'                        => ['states'],
      'donation_states'                      => ['states'],
      'vente_states'                         => ['states'],
      'destruction_states'                   => ['states'],
      'reforme_states'                       => ['states'],
      'enable_residual_value'                => ['bool'],
      'residual_value_duration_months'       => ['int', 1, 600],
      'enable_decision_aid'                  => ['bool'],
      'residual_value_low_threshold_percent' => ['int', 0, 100],
      'enable_environmental_passport'        => ['bool'],
      'enable_reuse_benefit'                 => ['bool'],
      'enable_co_signature'                  => ['bool'],
      'enable_pending_signatures_banner'     => ['bool'],
      'enable_in_person_signature'           => ['bool'],
      'allow_typed_signature'                => ['bool'],
      'departure_on_deactivation'            => ['bool'],
      'departure_on_end_date'                => ['bool'],
      'departure_end_date_days'              => ['int', 0, 90],
      'departure_ticket'                     => ['bool'],
      'loan_reminder_days'                   => ['int', 0, 60],
      'loan_overdue_ticket'                  => ['bool'],
      'arrival_on_creation'                  => ['bool'],
      'arrival_on_reactivation'              => ['bool'],
      'arrival_on_begin_date'                => ['bool'],
      'arrival_begin_date_days'              => ['int', 0, 90],
      'arrival_tasks'                        => ['string', 10000],
      'fields_containers'                    => ['fields_containers'],
      'chat_webhook_format'                  => ['enum', ['', 'teams', 'slack', 'mattermost']],
      'chat_on_send'                         => ['bool'],
      'chat_on_reminder'                     => ['bool'],
      'charter_resign_on_change'             => ['bool'],
      'renewal_default_years'                => ['int', 1, 15],
      'renewal_monthly_change'               => ['bool'],
      // Issue #273 : format de la référence de document (les compteurs, propres à l'instance, ne
      // sont jamais exportés : la numérotation repart de celle de l'entité cible).
      'reference_format'                     => ['string', DocumentReferenceFormat::MAX_FORMAT_LENGTH],
      'reference_prefix'                     => ['string', DocumentReferenceFormat::MAX_PREFIX_LENGTH],
      'reference_seq_digits'                 => ['int', 1, DocumentReferenceFormat::MAX_SEQ_DIGITS],
      'reference_yearly_reset'               => ['bool'],
   ];

   /** @return array<string, array<int, mixed>> */
   public static function getSchema(): array {
      return self::SCHEMA;
   }

   // ------------------------------------------------------------------------------------ Export

   /**
    * Configuration effective de l'entité, au format d'échange. L'appelant a vérifié le droit de
    * lecture de la configuration et l'accès à l'entité.
    *
    * @return array<string, mixed>
    */
   public static function export(int $entities_id, bool $withTemplates, bool $withLogo): array {
      $fields = Config::getForEntity($entities_id)->fields;
      $states = self::stateNamesById();
      $containers = self::containerNamesById();

      $config = [];
      foreach (self::SCHEMA as $key => $spec) {
         $config[$key] = self::exportValue($spec[0], $fields[$key] ?? null, $states, $containers);
      }

      return [
         'format_version' => self::FORMAT_VERSION,
         'plugin'         => 'assetsign',
         'plugin_version' => PLUGIN_ASSETSIGN_VERSION,
         'exported_at'    => date(DATE_ATOM),
         'entity_name'    => (string) \Dropdown::getDropdownName('glpi_entities', $entities_id),
         'config'         => $config,
         'templates'      => $withTemplates ? self::exportTemplates($entities_id) : [],
         'logo'           => $withLogo ? self::exportLogo((int) ($fields['logo_documents_id'] ?? 0)) : null,
         'excluded'       => self::SECRET_FIELDS,
      ];
   }

   /**
    * @param array<int, string> $states
    * @param array<int, string> $containers
    */
   private static function exportValue(string $type, mixed $value, array $states, array $containers): mixed {
      switch ($type) {
         case 'bool':
         case 'int':
            return (int) $value;
         case 'enum':
            return is_numeric($value) ? (int) $value : (string) $value;
         case 'states':
            return array_values(array_filter(array_map(
               static fn ($id) => $states[(int) $id] ?? null,
               self::decodeList($value)
            ), 'is_string'));
         case 'state':
            return $states[(int) $value] ?? '';
         case 'handed_state':
            return (int) $value > 0 ? ($states[(int) $value] ?? Config::HANDED_STATE_UNCHANGED) : (int) $value;
         case 'itemtypes':
            return array_map('strval', self::decodeList($value));
         case 'passport_types':
            return array_map('intval', self::decodeList($value));
         case 'fields_containers':
            return array_values(array_filter(array_map(
               static fn ($id) => $containers[(int) $id] ?? null,
               self::decodeList($value)
            ), 'is_string'));
         default:
            return (string) $value;
      }
   }

   /** @return list<array<string, mixed>> */
   private static function exportTemplates(int $entities_id): array {
      $templates = [];
      foreach (array_keys(Assetsign::getTypes()) as $type) {
         $template = Template::getDefaultFor((int) $type, $entities_id);
         if ($template === null || !$template->can($template->getID(), READ)) {
            continue;
         }
         $templates[] = [
            'type'            => (int) $type,
            'name'            => (string) $template->fields['name'],
            'content'         => (string) ($template->fields['content'] ?? ''),
            'charter_content' => (string) ($template->fields['charter_content'] ?? ''),
            'include_content' => (int) $template->fields['include_content'],
            'include_charter' => (int) $template->fields['include_charter'],
         ];
      }
      return $templates;
   }

   /** @return array{mime_type: string, data: string}|null */
   private static function exportLogo(int $documents_id): ?array {
      $document = new \Document();
      if ($documents_id <= 0 || !$document->getFromDB($documents_id)) {
         return null;
      }
      $path = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
      if (!is_file($path) || filesize($path) > self::LOGO_MAX_BYTES) {
         return null;
      }
      $bytes = (string) file_get_contents($path);
      try {
         return ['mime_type' => self::checkLogoBytes($bytes)[0], 'data' => base64_encode($bytes)];
      } catch (\InvalidArgumentException) {
         return null;
      }
   }

   public static function getExportFilename(int $entities_id): string {
      $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) \Dropdown::getDropdownName('glpi_entities', $entities_id));
      return 'assetsign-config-' . trim((string) $name, '_') . '-' . date('Ymd') . '.json';
   }

   // -------------------------------------------------------------------- Lecture et validation

   /**
    * Fichier téléversé : contrôle de l'envoi et de la taille, puis validation du contenu.
    *
    * @param array<string, mixed> $file entrée de $_FILES
    * @return array<string, mixed>
    */
   public static function readUpload(array $file): array {
      $tmp = (string) ($file['tmp_name'] ?? '');
      if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
         throw new \InvalidArgumentException(__('Choisissez un fichier de configuration (.json).', 'assetsign'));
      }
      if ((int) filesize($tmp) > self::MAX_FILE_BYTES) {
         throw new \InvalidArgumentException(self::tooLargeMessage());
      }
      return self::parse((string) file_get_contents($tmp));
   }

   private static function tooLargeMessage(): string {
      return sprintf(__('Le fichier dépasse la taille maximale (%d Mo).', 'assetsign'), self::MAX_FILE_BYTES / 1024 / 1024);
   }

   /**
    * Valide un fichier d'échange et le ramène à une forme normalisée (références par nom). Ne lit
    * pas la base : la résolution des noms, propre à l'entité cible, est faite par plan().
    *
    * @return array{config: array<string, mixed>, templates: list<array<string, mixed>>, logo: ?array{mime_type: string, data: string}, ignored: list<string>, plugin_version: string, entity_name: string}
    */
   public static function parse(string $json): array {
      if (strlen($json) > self::MAX_FILE_BYTES) {
         throw new \InvalidArgumentException(self::tooLargeMessage());
      }
      try {
         $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
      } catch (\JsonException) {
         throw new \InvalidArgumentException(__('Ce fichier n\'est pas un JSON valide.', 'assetsign'));
      }
      if (!is_array($data) || array_is_list($data) && $data !== []) {
         throw new \InvalidArgumentException(__('Ce fichier n\'est pas une configuration AssetSign.', 'assetsign'));
      }
      if (($data['plugin'] ?? null) !== 'assetsign' || ($data['format_version'] ?? null) !== self::FORMAT_VERSION) {
         throw new \InvalidArgumentException(__('Ce fichier n\'est pas une configuration AssetSign dans un format reconnu.', 'assetsign'));
      }
      foreach (array_keys($data) as $key) {
         if (!in_array($key, self::TOP_KEYS, true)) {
            throw self::invalid((string) $key);
         }
      }
      foreach (['plugin_version' => 32, 'exported_at' => 64, 'entity_name' => 255] as $key => $max) {
         if (isset($data[$key]) && (!is_string($data[$key]) || mb_strlen($data[$key]) > $max)) {
            throw self::invalid($key);
         }
      }
      if (isset($data['excluded']) && !self::isListOf($data['excluded'], static fn ($v) => is_string($v) && strlen($v) <= 64)) {
         throw self::invalid('excluded');
      }

      $config = $data['config'] ?? [];
      if (!is_array($config) || array_is_list($config) && $config !== []) {
         throw self::invalid('config');
      }
      $normalized = [];
      $ignored = [];
      foreach ($config as $key => $value) {
         $key = (string) $key;
         if (in_array($key, self::SECRET_FIELDS, true) || in_array($key, self::INSTANCE_FIELDS, true)) {
            $ignored[] = $key;
            continue;
         }
         if (!isset(self::SCHEMA[$key])) {
            throw self::invalid($key);
         }
         $normalized[$key] = self::validateValue($key, self::SCHEMA[$key], $value);
      }
      // Issue #273 : mêmes règles que l'écran de configuration (marqueurs connus, caractères sûrs).
      $referenceError = isset($normalized['reference_format']) ? DocumentReferenceFormat::validate(
         $normalized['reference_format'],
         (string) ($normalized['reference_prefix'] ?? ''),
         (bool) ($normalized['reference_yearly_reset'] ?? 0)
      ) : null;
      if ($referenceError !== null) {
         throw self::invalid('reference_format');
      }

      return [
         'config'         => $normalized,
         'templates'      => self::validateTemplates($data['templates'] ?? []),
         'logo'           => self::validateLogo($data['logo'] ?? null),
         'ignored'        => $ignored,
         'plugin_version' => (string) ($data['plugin_version'] ?? ''),
         'entity_name'    => (string) ($data['entity_name'] ?? ''),
      ];
   }

   private static function invalid(string $key): \InvalidArgumentException {
      $key = mb_substr($key, 0, 64);
      return new \InvalidArgumentException(sprintf(
         __('Fichier refusé : la valeur « %s » est inconnue ou invalide.', 'assetsign'),
         $key
      ));
   }

   /** @param array<int, mixed> $spec */
   private static function validateValue(string $key, array $spec, mixed $value): mixed {
      switch ($spec[0]) {
         case 'bool':
            if (is_bool($value) || $value === 0 || $value === 1) {
               return (int) $value;
            }
            break;
         case 'int':
            if (is_int($value) && $value >= $spec[1] && $value <= $spec[2]) {
               return $value;
            }
            break;
         case 'enum':
            if (in_array($value, $spec[1], true)) {
               return $value;
            }
            break;
         case 'string':
            if (is_string($value) && mb_strlen($value) <= $spec[1] && (!isset($spec[2]) || $value === '' || preg_match($spec[2], $value) === 1)) {
               return $value;
            }
            break;
         case 'email':
            if (is_string($value) && strlen($value) <= 255 && ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) !== false)) {
               return $value;
            }
            break;
         case 'url':
            if (is_string($value) && strlen($value) <= 255 && Config::sanitizeCharterUrl($value) === trim($value)) {
               return trim($value);
            }
            break;
         case 'state':
            if (is_string($value) && mb_strlen($value) <= 255) {
               return $value;
            }
            break;
         case 'handed_state':
            if ($value === Config::HANDED_STATE_SAME_AS_MAIN || $value === Config::HANDED_STATE_UNCHANGED) {
               return $value;
            }
            if (is_string($value) && $value !== '' && mb_strlen($value) <= 255) {
               return $value;
            }
            break;
         case 'passport_types':
            if (self::isListOf($value, 'is_int')) {
               $known = array_keys(PassportEvent::getTypeLabels());
               if (array_diff($value, $known) === []) {
                  return array_values(array_unique($value));
               }
            }
            break;
         case 'states':
         case 'itemtypes':
         case 'fields_containers':
            if (self::isListOf($value, static fn ($v) => is_string($v) && $v !== '' && mb_strlen($v) <= 255)) {
               return array_values(array_unique($value));
            }
            break;
      }
      throw self::invalid($key);
   }

   private static function isListOf(mixed $value, callable $check): bool {
      if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_LIST) {
         return false;
      }
      foreach ($value as $item) {
         if (!$check($item)) {
            return false;
         }
      }
      return true;
   }

   /** @return list<array<string, mixed>> */
   private static function validateTemplates(mixed $templates): array {
      $types = array_keys(Assetsign::getTypes());
      if (!is_array($templates) || !array_is_list($templates) || count($templates) > count($types)) {
         throw self::invalid('templates');
      }
      $result = [];
      foreach ($templates as $template) {
         if (!is_array($template) || array_diff(array_keys($template), self::TEMPLATE_KEYS) !== []) {
            throw self::invalid('templates');
         }
         $type = $template['type'] ?? null;
         if (!is_int($type) || !in_array($type, $types, true) || isset($result[$type])) {
            throw self::invalid('templates.type');
         }
         $name = $template['name'] ?? null;
         if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            throw self::invalid('templates.name');
         }
         $row = ['type' => $type, 'name' => trim($name)];
         foreach (['content', 'charter_content'] as $key) {
            $text = $template[$key] ?? '';
            if (!is_string($text) || strlen($text) > self::TEMPLATE_TEXT_MAX) {
               throw self::invalid('templates.' . $key);
            }
            $row[$key] = $text;
         }
         foreach (['include_content', 'include_charter'] as $key) {
            $row[$key] = self::validateValue('templates.' . $key, ['bool'], $template[$key] ?? 1);
         }
         $result[$type] = $row;
      }
      return array_values($result);
   }

   /** @return array{mime_type: string, data: string}|null */
   private static function validateLogo(mixed $logo): ?array {
      if ($logo === null) {
         return null;
      }
      if (!is_array($logo) || array_diff(array_keys($logo), ['mime_type', 'data']) !== [] || !is_string($logo['data'] ?? null)) {
         throw self::invalid('logo');
      }
      if (strlen($logo['data']) > (int) ceil(self::LOGO_MAX_BYTES * 4 / 3) + 4) {
         throw new \InvalidArgumentException(self::logoRefusedMessage());
      }
      $bytes = base64_decode($logo['data'], true);
      if ($bytes === false) {
         throw self::invalid('logo');
      }
      [$mime] = self::checkLogoBytes($bytes);
      if (($logo['mime_type'] ?? $mime) !== $mime) {
         throw new \InvalidArgumentException(self::logoRefusedMessage());
      }
      return ['mime_type' => $mime, 'data' => $logo['data']];
   }

   private static function logoRefusedMessage(): string {
      return sprintf(
         __('Logo refusé : image PNG, JPG, GIF ou WEBP attendue, de %1$d Ko et %2$d × %3$d pixels au plus.', 'assetsign'),
         self::LOGO_MAX_BYTES / 1024,
         Config::LOGO_MAX_PIXELS,
         Config::LOGO_MAX_PIXELS
      );
   }

   /**
    * Contrôle le contenu réel du logo (jamais le type annoncé ni une extension) : signature
    * d'image reconnue, type MIME détecté concordant, dimensions bornées. Le SVG (qui peut porter
    * du script) n'est pas une image matricielle et est donc toujours refusé.
    *
    * @return array{0: string, 1: string} type MIME et extension
    */
   public static function checkLogoBytes(string $bytes): array {
      if ($bytes === '' || strlen($bytes) > self::LOGO_MAX_BYTES) {
         throw new \InvalidArgumentException(self::logoRefusedMessage());
      }
      $size = @getimagesizefromstring($bytes);
      if ($size === false || !isset(self::LOGO_TYPES[$size[2]])) {
         throw new \InvalidArgumentException(self::logoRefusedMessage());
      }
      [$mime, $extension] = self::LOGO_TYPES[$size[2]];
      $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
      if ($detected !== $mime || (int) $size[0] > Config::LOGO_MAX_PIXELS || (int) $size[1] > Config::LOGO_MAX_PIXELS) {
         throw new \InvalidArgumentException(self::logoRefusedMessage());
      }
      return [$mime, $extension];
   }

   // ------------------------------------------------------------------- Simulation et application

   /**
    * Simulation : ce que l'import changerait dans l'entité cible, sans rien écrire. Les noms sont
    * résolus parmi les objets visibles depuis l'entité cible ; un nom introuvable est ignoré et
    * signalé. Les gabarits ne sont retenus que si l'utilisateur a le droit de les créer ou de les
    * modifier dans cette entité.
    *
    * @param array<string, mixed> $payload résultat de parse()
    * @param array{logo: bool, templates: bool} $options
    * @return array{changes: list<array{key: string, current: string, new: string}>, input: array<string, mixed>, templates: list<array<string, mixed>>, logo: string, report: list<string>, own_row: bool}
    */
   public static function plan(array $payload, int $entities_id, array $options): array {
      $report = [];
      foreach ($payload['ignored'] as $key) {
         $report[] = sprintf(__('« %s » n\'est jamais importé (secret ou propre à l\'instance d\'origine) : à ressaisir si besoin.', 'assetsign'), $key);
      }

      $current = Config::getForEntity($entities_id);
      $ownRow = (int) $current->fields['entities_id'] === $entities_id && !empty($current->fields['id']);
      $stateIds = self::visibleStates($entities_id);
      $containerIds = self::visibleContainers($entities_id);
      $itemtypes = Config::getAllManageableItemtypes();

      $overrides = [];
      foreach ($payload['config'] as $key => $value) {
         $type = self::SCHEMA[$key][0];
         switch ($type) {
            case 'states':
               $overrides[$key] = self::resolveNames($value, $stateIds, $key, $report);
               break;
            case 'state':
               $overrides[$key] = $value === '' ? 0 : (self::resolveNames([$value], $stateIds, $key, $report)[0] ?? 0);
               break;
            case 'handed_state':
               $overrides[$key] = is_int($value)
                  ? $value
                  : (self::resolveNames([$value], $stateIds, $key, $report)[0] ?? Config::HANDED_STATE_UNCHANGED);
               break;
            case 'fields_containers':
               $overrides[$key] = self::resolveNames($value, $containerIds, $key, $report);
               break;
            case 'itemtypes':
               $kept = array_values(array_intersect($value, $itemtypes));
               foreach (array_diff($value, $kept) as $missing) {
                  $report[] = sprintf(__('%1$s : « %2$s » n\'existe pas sur cette instance, ignoré.', 'assetsign'), $key, $missing);
               }
               $overrides[$key] = $kept;
               break;
            default:
               $overrides[$key] = $value;
         }
      }

      $input = self::buildFormInput($current->fields, $overrides);
      $newRow = Config::buildRow($input);
      $changes = [];
      $stateNames = array_flip($stateIds) + self::stateNamesById();
      foreach ($newRow as $key => $newValue) {
         $before = self::displayValue($key, $current->fields[$key] ?? '', $stateNames);
         $after = self::displayValue($key, $newValue, $stateNames);
         if ($before !== $after) {
            $changes[] = ['key' => $key, 'current' => $before, 'new' => $after];
         }
      }

      $logo = 'none';
      if ($options['logo'] && $payload['logo'] !== null) {
         $logo = 'replace';
         $document = new \Document();
         $currentLogo = (int) $current->fields['logo_documents_id'];
         if ($currentLogo > 0 && $document->getFromDB($currentLogo)
            && $document->fields['sha1sum'] === sha1((string) base64_decode($payload['logo']['data'], true))
         ) {
            $logo = 'unchanged';
         }
      }

      $templates = [];
      if ($options['templates']) {
         foreach ($payload['templates'] as $template) {
            $templates[] = self::planTemplate($template, $entities_id, $report);
         }
      }

      return [
         'changes'   => $changes,
         'input'     => $input,
         'templates' => $templates,
         'logo'      => $logo,
         'report'    => $report,
         'own_row'   => $ownRow,
      ];
   }

   /**
    * @param array<string, mixed> $template
    * @param list<string> $report
    * @return array<string, mixed>
    */
   private static function planTemplate(array $template, int $entities_id, array &$report): array {
      global $DB;

      $label = Assetsign::getTypes()[$template['type']] ?? (string) $template['type'];
      $existing = iterator_to_array($DB->request([
         'FROM'  => Template::getTable(),
         'WHERE' => ['type' => $template['type'], 'is_default' => 1, 'is_active' => 1, 'entities_id' => $entities_id],
         'ORDER' => 'id',
         'LIMIT' => 1,
      ]));
      $row = reset($existing);
      $item = new Template();
      $fields = ['name', 'content', 'charter_content', 'include_content', 'include_charter'];

      if ($row !== false) {
         $changed = [];
         foreach ($fields as $field) {
            if ((string) ($row[$field] ?? '') !== (string) $template[$field]) {
               $changed[] = $field;
            }
         }
         $action = $changed === [] ? 'unchanged' : 'update';
         if ($action === 'update' && !$item->can((int) $row['id'], UPDATE)) {
            $report[] = sprintf(__('Gabarit « %s » ignoré : vous n\'avez pas le droit de le modifier.', 'assetsign'), $label);
            $action = 'denied';
         }
         return ['type' => $template['type'], 'label' => $label, 'action' => $action, 'id' => (int) $row['id'], 'changed' => $changed, 'data' => $template];
      }

      $action = 'create';
      $input = ['entities_id' => $entities_id, 'type' => $template['type']];
      if (!$item->can(-1, CREATE, $input)) {
         $report[] = sprintf(__('Gabarit « %s » ignoré : vous n\'avez pas le droit d\'en créer dans cette entité.', 'assetsign'), $label);
         $action = 'denied';
      }
      return ['type' => $template['type'], 'label' => $label, 'action' => $action, 'id' => 0, 'changed' => $fields, 'data' => $template];
   }

   /**
    * Applique un import déjà simulé : le plan est recalculé ici (droits, entité et noms revérifiés
    * au moment d'écrire), puis chaque changement est consigné dans le journal des événements de
    * GLPI et dans files/_log/assetsign.log.
    *
    * @param array<string, mixed> $payload
    * @param array{logo: bool, templates: bool} $options
    * @return array{settings: int, templates: int, logo: bool}
    */
   public static function apply(array $payload, int $entities_id, array $options): array {
      $plan = self::plan($payload, $entities_id, $options);
      $input = $plan['input'];
      $log = [];

      $logoChanged = false;
      if ($plan['logo'] === 'replace') {
         [, $extension] = self::checkLogoBytes((string) base64_decode($payload['logo']['data'], true));
         $tmpName = 'assetsign_logo_' . bin2hex(random_bytes(12)) . '.' . $extension;
         if (file_put_contents(GLPI_TMP_DIR . '/' . $tmpName, base64_decode($payload['logo']['data'], true)) !== false) {
            $documents_id = Config::addLogoDocument($tmpName, $entities_id);
            if ($documents_id > 0) {
               $input['logo_documents_id'] = $documents_id;
               $logoChanged = true;
               $log[] = 'logo_documents_id: ' . $documents_id;
            }
         }
      }

      if ($plan['changes'] !== [] || $logoChanged) {
         Config::upsertForEntity($entities_id, $input);
         foreach ($plan['changes'] as $change) {
            $log[] = sprintf('%s: %s -> %s', $change['key'], self::shorten($change['current']), self::shorten($change['new']));
         }
      }

      $templatesChanged = 0;
      foreach ($plan['templates'] as $template) {
         $data = $template['data'];
         $values = [
            'name'            => $data['name'],
            'content'         => $data['content'],
            'charter_content' => $data['charter_content'],
            'include_content' => $data['include_content'],
            'include_charter' => $data['include_charter'],
         ];
         $item = new Template();
         if ($template['action'] === 'update' && $item->update(['id' => $template['id']] + $values)) {
            $templatesChanged++;
            $log[] = sprintf('template %d (#%d): %s', $template['type'], $template['id'], implode(', ', $template['changed']));
         } else if ($template['action'] === 'create') {
            $id = $item->add($values + [
               'type'         => $data['type'],
               'entities_id'  => $entities_id,
               'is_recursive' => 0,
               'is_default'   => 1,
               'is_active'    => 1,
            ]);
            if ($id) {
               $templatesChanged++;
               $log[] = sprintf('template %d (#%d): created', $template['type'], $id);
            }
         }
      }

      if ($log !== []) {
         $summary = sprintf(
            'AssetSign: configuration import into entity %d by %s (%d settings, %d templates%s)',
            $entities_id,
            (string) ($_SESSION['glpiname'] ?? ''),
            count($plan['changes']),
            $templatesChanged,
            $logoChanged ? ', logo' : ''
         );
         \Glpi\Event::log($entities_id, 'entity', 4, 'setup', $summary);
         \Toolbox::logInFile('assetsign', $summary . "\n  " . implode("\n  ", $log) . "\n");
      }

      return ['settings' => count($plan['changes']), 'templates' => $templatesChanged, 'logo' => $logoChanged];
   }

   private static function shorten(string $value): string {
      $value = str_replace(["\r", "\n"], ' ', $value);
      return mb_strlen($value) > 120 ? mb_substr($value, 0, 117) . '...' : $value;
   }

   /**
    * Entrée de formulaire équivalente à la configuration actuelle, modifiée par les valeurs
    * importées : passée à Config::buildRow()/upsertForEntity(), elle bénéficie des mêmes
    * contrôles qu'un enregistrement depuis l'écran de configuration. Les réglages absents du
    * fichier gardent leur valeur actuelle.
    *
    * @param array<string, mixed> $current
    * @param array<string, mixed> $overrides
    * @return array<string, mixed>
    */
   public static function buildFormInput(array $current, array $overrides): array {
      $input = $current;
      // Jamais transmis : un champ vide conserve l'adresse du webhook enregistrée.
      unset($input['chat_webhook_url'], $input['provider_config']);

      foreach (self::SCHEMA as $key => $spec) {
         if (in_array($spec[0], ['states', 'passport_types', 'fields_containers'], true)) {
            $input[$key] = array_map('intval', self::decodeList($current[$key] ?? '[]'));
         }
      }
      foreach ($overrides as $key => $value) {
         $input[$key] = $value;
      }

      $managed = $overrides['managed_itemtypes'] ?? self::decodeList($current['managed_itemtypes'] ?? '[]');
      unset($input['managed_itemtypes']);
      foreach ($managed as $itemtype) {
         $input['manage_' . $itemtype] = 1;
      }

      return $input;
   }

   /**
    * @param list<string> $names
    * @param array<string, int> $known nom => id
    * @param list<string> $report
    * @return list<int>
    */
   private static function resolveNames(array $names, array $known, string $key, array &$report): array {
      $ids = [];
      foreach ($names as $name) {
         if (isset($known[$name])) {
            $ids[] = $known[$name];
         } else {
            $report[] = sprintf(__('%1$s : « %2$s » est introuvable dans l\'entité cible, ignoré.', 'assetsign'), $key, $name);
         }
      }
      return $ids;
   }

   /** @param array<int, string> $stateNames */
   private static function displayValue(string $key, mixed $value, array $stateNames): string {
      $type = self::SCHEMA[$key][0] ?? null;
      if ($type === 'states') {
         $value = array_map(static fn ($id) => $stateNames[(int) $id] ?? '#' . (int) $id, self::decodeList($value));
      } else if (($type === 'state' || $type === 'handed_state') && (int) $value > 0) {
         $value = $stateNames[(int) $value] ?? '#' . (int) $value;
      } else if (in_array($type, ['itemtypes', 'passport_types', 'fields_containers'], true)) {
         $value = self::decodeList($value);
      }
      if (is_array($value)) {
         sort($value);
         return implode(', ', array_map('strval', $value));
      }
      return (string) $value;
   }

   /** @return list<mixed> */
   private static function decodeList(mixed $value): array {
      if (is_array($value)) {
         return array_values($value);
      }
      $decoded = json_decode((string) $value, true);
      return is_array($decoded) ? array_values($decoded) : [];
   }

   /** @return array<int, string> id => nom complet, tous statuts confondus (export) */
   private static function stateNamesById(): array {
      global $DB;
      $names = [];
      foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => State::getTable()]) as $row) {
         $names[(int) $row['id']] = (string) $row['completename'];
      }
      return $names;
   }

   /** @return array<string, int> nom complet => id, statuts visibles depuis l'entité cible */
   private static function visibleStates(int $entities_id): array {
      global $DB;
      $ids = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'completename'],
         'FROM'   => State::getTable(),
         'WHERE'  => getEntitiesRestrictCriteria(State::getTable(), '', $entities_id, true),
         'ORDER'  => 'id',
      ]) as $row) {
         $ids[(string) $row['completename']] ??= (int) $row['id'];
      }
      return $ids;
   }

   /** @return array<int, string> id => nom système des blocs du plugin Fields (export) */
   private static function containerNamesById(): array {
      global $DB;
      if (!FieldsPluginValues::isAvailable()) {
         return [];
      }
      $names = [];
      foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_fields_containers']) as $row) {
         $names[(int) $row['id']] = (string) $row['name'];
      }
      return $names;
   }

   /** @return array<string, int> nom système => id, blocs actifs visibles depuis l'entité cible */
   private static function visibleContainers(int $entities_id): array {
      global $DB;
      if (!FieldsPluginValues::isAvailable()) {
         return [];
      }
      $ids = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'name'],
         'FROM'   => 'glpi_plugin_fields_containers',
         'WHERE'  => ['is_active' => 1] + getEntitiesRestrictCriteria('glpi_plugin_fields_containers', '', $entities_id, true),
      ]) as $row) {
         $ids[(string) $row['name']] = (int) $row['id'];
      }
      return $ids;
   }

   // ------------------------------------------------------------------------------------ Session

   /**
    * Garde en session le fichier validé entre la simulation et l'application, derrière un jeton
    * aléatoire : rien de ce qui est appliqué ne revient du navigateur à la seconde étape.
    *
    * @param array<string, mixed> $payload
    * @param array{logo: bool, templates: bool} $options
    */
   public static function storePending(array $payload, int $entities_id, array $options): string {
      $token = bin2hex(random_bytes(16));
      $_SESSION[self::SESSION_KEY] = [
         'token'       => $token,
         'entities_id' => $entities_id,
         'options'     => $options,
         'payload'     => $payload,
      ];
      return $token;
   }

   /**
    * Import en attente correspondant au jeton (retiré de la session : un seul usage).
    *
    * @return array{entities_id: int, options: array{logo: bool, templates: bool}, payload: array<string, mixed>}|null
    */
   public static function takePending(string $token): ?array {
      $pending = $_SESSION[self::SESSION_KEY] ?? null;
      unset($_SESSION[self::SESSION_KEY]);
      if (!is_array($pending) || preg_match('/^[0-9a-f]{32}$/', $token) !== 1 || !hash_equals((string) $pending['token'], $token)) {
         return null;
      }
      return $pending;
   }

   // -------------------------------------------------------------------------------- Affichage

   /** @return array<int, string> entités accessibles (id => nom complet) */
   public static function getEntityChoices(): array {
      $choices = [];
      foreach ($_SESSION['glpiactiveentities'] ?? [] as $id) {
         $choices[(int) $id] = (string) \Dropdown::getDropdownName('glpi_entities', (int) $id);
      }
      asort($choices);
      return $choices;
   }
}
