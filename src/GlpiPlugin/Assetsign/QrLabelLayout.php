<?php

namespace GlpiPlugin\Assetsign;

/**
 * Issue #278 : mise en page des étiquettes QR (planche en lot et étiquette unitaire) et texte
 * paramétrable sous le QR. Règles pures, testées sans base de données
 * (tests/QrLabelLayoutTest.php) : le rendu reste en Twig + CSS (unités mm pour l'impression).
 *
 * Les réglages par défaut sont ceux de l'entité (configuration, onglet Compléments) ; la planche
 * permet de les ajuster pour une impression (paramètres d'URL), toujours bornés ici.
 */
final class QrLabelLayout
{
   public const PRESET_CUSTOM = 'custom';

   public const POSITION_BELOW = 'below';
   public const POSITION_BESIDE = 'beside';
   public const POSITION_NONE = 'none';

   public const POSITIONS = [self::POSITION_BELOW, self::POSITION_BESIDE, self::POSITION_NONE];

   /** Feuille A4 (mm). */
   public const PAGE_WIDTH = 210;
   public const PAGE_HEIGHT = 297;

   /** Marge intérieure de l'étiquette (mm) et écart entre le QR et le texte. */
   public const PADDING = 2;
   public const TEXT_GAP = 1.5;

   public const TEXT_MAX_LENGTH = 500;

   public const PLACEHOLDERS = ['name', 'serial', 'otherserial', 'manufacturers_id', 'entity', 'type'];

   /**
    * Planches d'étiquettes adhésives courantes : largeur, hauteur, colonnes, lignes, marge haute,
    * marge gauche, écart entre colonnes, écart entre lignes (mm).
    *
    * @var array<string, list<float|int>>
    */
   public const PRESETS = [
       'a4_2x4'  => [105, 74, 2, 4, 0.5, 0, 0, 0],
       'a4_3x7'  => [70, 42.3, 3, 7, 0.45, 0, 0, 0],
       'a4_3x8'  => [70, 37, 3, 8, 0.5, 0, 0, 0],
       'a4_2x7'  => [99.1, 38.1, 2, 7, 15.15, 4.65, 2.5, 0],
       'a4_4x10' => [52.5, 29.7, 4, 10, 0, 0, 0, 0],
   ];

   /** Bornes des réglages numériques : [minimum, maximum, défaut]. */
   public const BOUNDS = [
       'qr_label_width'     => [15, 200, 70],
       'qr_label_height'    => [10, 280, 37],
       'qr_label_qr_size'   => [8, 100, 30],
       'qr_label_font_size' => [5, 24, 8],
       'qr_label_spacing'   => [0, 20, 0],
       'qr_label_margin'    => [0, 30, 8],
   ];

   public const DEFAULTS = [
       'qr_label_preset'        => 'a4_3x8',
       'qr_label_width'         => 70,
       'qr_label_height'        => 37,
       'qr_label_qr_size'       => 30,
       'qr_label_font_size'     => 8,
       'qr_label_spacing'       => 0,
       'qr_label_margin'        => 8,
       'qr_label_text_position' => self::POSITION_BESIDE,
       'qr_label_cut_frame'     => 1,
       'qr_label_text'          => '',
   ];

   /**
    * Réglages nettoyés : valeur absente ou hors liste = défaut, nombres bornés. Sert à
    * l'enregistrement de la configuration et aux ajustements de la planche.
    *
    * @param array<string, mixed> $input
    * @param array<string, mixed> $fallback réglages à garder pour les clés absentes de $input
    * @return array<string, int|string>
    */
   public static function normalize(array $input, array $fallback = []): array {
       $fallback += self::DEFAULTS;
       // Valeur absente ou non scalaire (tableau glissé dans l'URL) : réglage de repli.
       $pick = static fn (string $key): mixed => isset($input[$key]) && is_scalar($input[$key])
           ? $input[$key]
           : $fallback[$key];

       $preset = (string) $pick('qr_label_preset');
       $position = (string) $pick('qr_label_text_position');
       $settings = [
           'qr_label_preset'        => $preset === self::PRESET_CUSTOM || isset(self::PRESETS[$preset])
               ? $preset
               : self::DEFAULTS['qr_label_preset'],
           'qr_label_text_position' => in_array($position, self::POSITIONS, true)
               ? $position
               : self::DEFAULTS['qr_label_text_position'],
           'qr_label_cut_frame'     => (int) !empty($pick('qr_label_cut_frame')),
           'qr_label_text'          => self::normalizeText((string) $pick('qr_label_text')),
       ];
       foreach (self::BOUNDS as $key => [$min, $max, $default]) {
          $value = $pick($key);
          $settings[$key] = is_numeric($value) ? max($min, min($max, (int) round((float) $value))) : $default;
       }

       return $settings;
   }

   /** Fins de ligne unifiées, longueur bornée ; un texte identique au texte par défaut est vidé. */
   public static function normalizeText(string $text): string {
       $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
       $text = mb_substr($text, 0, self::TEXT_MAX_LENGTH);

       return $text === self::defaultText() ? '' : $text;
   }

   /** Texte imprimé quand l'entité n'en a pas défini : type, nom et numéro de série. */
   public static function defaultText(): string {
       return "{type}\n{name}\n" . __('N° série', 'assetsign') . ' : {serial}';
   }

   /** Texte effectif (celui de l'entité, ou le texte par défaut). */
   public static function effectiveText(string $text): string {
       return trim($text) === '' ? self::defaultText() : $text;
   }

   /**
    * Lignes imprimées sous (ou à côté du) QR. Une ligne vide, ou dont tous les marqueurs sont
    * vides (« N° série : {serial}» sans numéro de série), est retirée. Texte brut : l'échappement
    * est fait par Twig à l'affichage.
    *
    * @param array<string, mixed> $label données de QrLabelController::resolve()
    * @return list<string>
    */
   public static function lines(string $template, array $label): array {
       $values = [
           'name'             => (string) ($label['item_name'] ?? ''),
           'serial'           => (string) ($label['item_serial'] ?? ''),
           'otherserial'      => (string) ($label['item_otherserial'] ?? ''),
           'manufacturers_id' => (string) ($label['manufacturer_name'] ?? ''),
           'entity'           => (string) ($label['entity_name'] ?? ''),
           'type'             => (string) ($label['itemtype_label'] ?? ''),
       ];
       $pattern = '/\{(' . implode('|', self::PLACEHOLDERS) . ')\}/';

       $lines = [];
       foreach (explode("\n", self::effectiveText($template)) as $line) {
          $found = preg_match_all($pattern, $line, $matches);
          $filled = 0;
          $rendered = (string) preg_replace_callback(
              $pattern,
              static function (array $match) use ($values, &$filled): string {
                  $value = trim($values[$match[1]]);
               if ($value !== '') {
                   $filled++;
               }
                  return $value;
              },
              $line
           );
           $rendered = trim($rendered);
          if ($rendered === '' || ($found > 0 && $filled === 0)) {
              continue;
          }
          $lines[] = $rendered;
       }

       return $lines;
   }

   /**
    * Géométrie de la planche et de l'étiquette (mm), prête pour le CSS.
    *
    * @param array<string, int|string> $settings réglages nettoyés (normalize())
    * @return array<string, float|int|string>
    */
   public static function geometry(array $settings): array {
       $preset = (string) $settings['qr_label_preset'];
      if (isset(self::PRESETS[$preset])) {
          [$width, $height, $columns, $rows, $top, $left, $columnGap, $rowGap] = self::PRESETS[$preset];
      } else {
          $width = (int) $settings['qr_label_width'];
          $height = (int) $settings['qr_label_height'];
          $top = $left = (int) $settings['qr_label_margin'];
          $columnGap = $rowGap = (int) $settings['qr_label_spacing'];
          $columns = self::fit(self::PAGE_WIDTH - 2 * $left, $width, $columnGap);
          $rows = self::fit(self::PAGE_HEIGHT - 2 * $top, $height, $rowGap);
      }

       $position = (string) $settings['qr_label_text_position'];
       // Le QR tient toujours dans l'étiquette, marges comprises.
       $qrMax = min($width, $height) - 2 * self::PADDING;
       $qr = max(5, min((float) $settings['qr_label_qr_size'], $qrMax));

       return [
           'label_width'   => $width,
           'label_height'  => $height,
           'columns'       => $columns,
           'rows'          => $rows,
           'per_page'      => $columns * $rows,
           'margin_top'    => $top,
           'margin_left'   => $left,
           'column_gap'    => $columnGap,
           'row_gap'       => $rowGap,
           'qr_size'       => $qr,
           'font_size'     => (int) $settings['qr_label_font_size'],
           'text_position' => $position,
           'cut_frame'     => (int) $settings['qr_label_cut_frame'],
           'padding'       => self::PADDING,
           'text_gap'      => self::TEXT_GAP,
       ];
   }

   /** Nombre d'étiquettes de $size mm séparées de $gap mm qui tiennent dans $available mm (au moins 1). */
   private static function fit(float $available, float $size, float $gap): int {
       return max(1, (int) floor(($available + $gap) / ($size + $gap)));
   }

   /**
    * Formats proposés dans la liste (dimensions en mm, non traduites).
    *
    * @return array<string, string>
    */
   public static function presetLabels(): array {
       $labels = [];
      foreach (self::PRESETS as $key => [$width, $height, $columns, $rows]) {
          $labels[$key] = sprintf(
              'A4 %d x %d (%s x %s mm)',
              $columns,
              $rows,
              self::formatMm($width),
              self::formatMm($height)
          );
      }
       $labels[self::PRESET_CUSTOM] = __('Personnalisé', 'assetsign');

       return $labels;
   }

   /**
    * Dimensions des formats, pour remplir les champs du formulaire quand on choisit un format.
    *
    * @return array<string, array{width: int, height: int}>
    */
   public static function presetSizes(): array {
       $sizes = [];
      foreach (self::PRESETS as $key => [$width, $height]) {
          $sizes[$key] = ['width' => (int) round($width), 'height' => (int) round($height)];
      }

       return $sizes;
   }

   /**
    * @return array<string, string>
    */
   public static function positionLabels(): array {
       return [
           self::POSITION_BELOW  => __('Sous le QR code', 'assetsign'),
           self::POSITION_BESIDE => __('À côté du QR code', 'assetsign'),
           self::POSITION_NONE   => __('Aucun texte', 'assetsign'),
       ];
   }

   /**
    * Variables du formulaire de réglages (configuration et planche).
    *
    * @param array<string, int|string> $settings
    * @return array<string, mixed>
    */
   public static function formData(array $settings): array {
       return [
           'settings'         => $settings,
           'text'             => self::effectiveText((string) $settings['qr_label_text']),
           'preset_labels'    => self::presetLabels(),
           'preset_sizes'     => self::presetSizes(),
           'position_labels'  => self::positionLabels(),
           'placeholders'     => array_map(static fn (string $p): string => '{' . $p . '}', self::PLACEHOLDERS),
           'bounds'           => self::BOUNDS,
       ];
   }

   private static function formatMm(float $value): string {
       return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
   }
}
