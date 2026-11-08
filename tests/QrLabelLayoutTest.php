<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\QrLabelLayout;
use PHPUnit\Framework\TestCase;

/**
 * Issue #278 : mise en page des étiquettes QR et texte paramétrable (règles pures, sans base).
 */
final class QrLabelLayoutTest extends TestCase
{
   public function testNormalizeBoundsValuesAndRejectsUnknownChoices(): void {
       $settings = QrLabelLayout::normalize([
           'qr_label_preset'        => 'a4_99x99',
           'qr_label_width'         => '9999',
           'qr_label_height'        => '-5',
           'qr_label_qr_size'       => 'abc',
           'qr_label_font_size'     => '7.6',
           'qr_label_text_position' => '<script>',
           'qr_label_cut_frame'     => '0',
           'qr_label_text'          => ['{name}'],
       ]);

       $this->assertSame('a4_3x8', $settings['qr_label_preset']);
       $this->assertSame(200, $settings['qr_label_width']);
       $this->assertSame(10, $settings['qr_label_height']);
       $this->assertSame(30, $settings['qr_label_qr_size'], 'Valeur non numérique : défaut.');
       $this->assertSame(8, $settings['qr_label_font_size']);
       $this->assertSame(QrLabelLayout::POSITION_BESIDE, $settings['qr_label_text_position']);
       $this->assertSame(0, $settings['qr_label_cut_frame']);
       $this->assertSame('', $settings['qr_label_text'], 'Valeur non scalaire ignorée.');
   }

   public function testNormalizeKeepsFallbackForMissingKeys(): void {
       $entity = QrLabelLayout::normalize(['qr_label_preset' => 'custom', 'qr_label_width' => 50]);
       $adjusted = QrLabelLayout::normalize(['qr_label_font_size' => 12], $entity);

       $this->assertSame('custom', $adjusted['qr_label_preset']);
       $this->assertSame(50, $adjusted['qr_label_width']);
       $this->assertSame(12, $adjusted['qr_label_font_size']);
   }

   public function testDefaultTextIsStoredEmptyAndTextIsBounded(): void {
       $this->assertSame('', QrLabelLayout::normalizeText(QrLabelLayout::defaultText()));
       $this->assertSame("{name}\n{serial}", QrLabelLayout::normalizeText("{name}\r\n{serial}\r\n"));
       $this->assertSame(
           QrLabelLayout::TEXT_MAX_LENGTH,
           mb_strlen(QrLabelLayout::normalizeText(str_repeat('é', 2000)))
       );
   }

   public function testLinesResolvePlaceholdersAndDropEmptyLines(): void {
       $label = [
           'item_name'         => 'PC-FICTIF-01',
           'item_serial'       => '',
           'item_otherserial'  => 'INV-0042',
           'manufacturer_name' => 'Fabricant Démo',
           'entity_name'       => 'Entité Démo',
           'itemtype_label'    => 'Ordinateur',
       ];
       $template = "{name}\nS/N : {serial}\n\n{manufacturers_id} - {otherserial}\n{entity}\nTexte fixe\n{inconnu}";

       $this->assertSame(
           ['PC-FICTIF-01', 'Fabricant Démo - INV-0042', 'Entité Démo', 'Texte fixe', '{inconnu}'],
           QrLabelLayout::lines($template, $label)
       );
   }

   public function testLinesKeepUserTextRawForTwigEscaping(): void {
       $lines = QrLabelLayout::lines('{name}', ['item_name' => '<b>PC</b>']);
       $this->assertSame(['<b>PC</b>'], $lines, 'Pas d\'échappement ici : Twig échappe à l\'affichage.');
   }

   public function testEmptyTemplateUsesDefaultText(): void {
       $lines = QrLabelLayout::lines('', ['item_name' => 'PC-FICTIF-02', 'item_serial' => 'SN-1', 'itemtype_label' => 'Ordinateur']);
       $this->assertCount(3, $lines);
       $this->assertSame('PC-FICTIF-02', $lines[1]);
       $this->assertStringEndsWith('SN-1', $lines[2]);
   }

   public function testPresetGeometryFillsTheA4Sheet(): void {
       foreach (array_keys(QrLabelLayout::PRESETS) as $preset) {
           $layout = QrLabelLayout::geometry(QrLabelLayout::normalize(['qr_label_preset' => $preset]));
           $width = $layout['margin_left'] + $layout['columns'] * $layout['label_width']
               + ($layout['columns'] - 1) * $layout['column_gap'];
           $height = $layout['margin_top'] + $layout['rows'] * $layout['label_height']
               + ($layout['rows'] - 1) * $layout['row_gap'];
           $this->assertLessThanOrEqual(QrLabelLayout::PAGE_WIDTH + 0.01, $width, $preset);
           $this->assertLessThanOrEqual(QrLabelLayout::PAGE_HEIGHT + 0.01, $height, $preset);
       }

       $layout = QrLabelLayout::geometry(QrLabelLayout::normalize(['qr_label_preset' => 'a4_3x8']));
       $this->assertSame(24, $layout['per_page']);
       $this->assertEquals(30, $layout['qr_size'], 'QR de 30 mm dans 37 mm de haut : tient.');
   }

   public function testCustomGeometryAndQrSizeFitTheLabel(): void {
       $layout = QrLabelLayout::geometry(QrLabelLayout::normalize([
           'qr_label_preset'   => 'custom',
           'qr_label_width'    => 60,
           'qr_label_height'   => 20,
           'qr_label_qr_size'  => 80,
           'qr_label_margin'   => 10,
           'qr_label_spacing'  => 5,
       ]));

       $this->assertSame(3, $layout['columns'], '3 x 60 mm + 2 x 5 mm = 210 mm - 2 x 10 mm.');
       $this->assertSame(11, $layout['rows']);
       $this->assertEquals(16, $layout['qr_size'], 'QR réduit à la hauteur de l\'étiquette moins les marges.');
   }
}
