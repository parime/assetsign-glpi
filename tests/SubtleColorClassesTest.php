<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Assetsign\Tests;

use PHPUnit\Framework\TestCase;

/**
 * parime/glpi-plugin-template#10 (charter section 8): GLPI and Tabler only ship the Bootstrap 5.3
 * `bg-X-subtle`, `text-X-emphasis` and `border-X-subtle` classes for the theme colors (primary,
 * secondary, success...). For the named Tabler colors (orange, blue, green...) they do not exist,
 * and a badge using them is rendered without a background.
 *
 * AssetSign only uses theme colors today (inventory report: success, warning, danger, secondary),
 * so it does not ship the template's color stylesheet. This test guards the future: it fails as
 * soon as the code uses such a class that neither Tabler nor public/css/assetsign.css defines,
 * written literally (`bg-orange-subtle`) or built from a color name (`bg-{{ row.color }}-subtle`
 * with `'color' => 'orange'` on the PHP side). The fix is then to copy the template's rules
 * (public/css/monplugin.css of parime/glpi-plugin-template) into public/css/assetsign.css.
 *
 * Files only: no database, no language (the CI runs in English).
 */
final class SubtleColorClassesTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** Theme colors for which GLPI 11/12 (Tabler 1.x, Bootstrap 5.3) define the classes. */
    private const THEME_COLORS = ['primary', 'secondary', 'success', 'info', 'warning', 'danger', 'light', 'dark'];

    /** @return list<string> */
    private static function knownTablerClasses(): array
    {
        $classes = ['text-body-emphasis'];
        foreach (self::THEME_COLORS as $color) {
            $classes[] = 'bg-' . $color . '-subtle';
            $classes[] = 'text-' . $color . '-emphasis';
            $classes[] = 'border-' . $color . '-subtle';
        }

        return $classes;
    }

    /** @return list<string> */
    private static function pluginStylesheetClasses(): array
    {
        $css = file_get_contents(self::ROOT . '/public/css/assetsign.css');
        self::assertIsString($css);
        preg_match_all('/^\.((?:bg|text|border)-[a-z]+-(?:subtle|emphasis))\s*\{/m', $css, $matches);

        return $matches[1];
    }

    /** @return array<string, string> path => contents of every Twig, PHP and JS source file */
    private static function sourceFiles(): array
    {
        $files = [];
        foreach (['templates', 'src', 'front', 'inc', 'ajax', 'js', 'public'] as $dir) {
            $path = self::ROOT . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && preg_match('/\.(twig|php|js)$/', $file->getFilename()) === 1) {
                    $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        foreach (['setup.php', 'hook.php'] as $file) {
            $files[$file] = (string) file_get_contents(self::ROOT . '/' . $file);
        }

        return $files;
    }

    private static function isDefined(string $class): bool
    {
        return in_array($class, self::knownTablerClasses(), true)
            || in_array($class, self::pluginStylesheetClasses(), true);
    }

    public function testEveryLiteralSubtleOrEmphasisClassIsDefined(): void
    {
        $missing = [];
        $found = 0;
        foreach (self::sourceFiles() as $path => $contents) {
            preg_match_all('/(?<![\w-])((?:bg|text|border)-[a-z]+-(?:subtle|emphasis))(?![\w-])/', $contents, $matches);
            foreach (array_unique($matches[1]) as $class) {
                $found++;
                if (!self::isDefined($class)) {
                    $missing[] = $class . ' (' . basename($path) . ')';
                }
            }
        }

        $this->assertGreaterThan(0, $found, 'The scan must find the subtle/emphasis classes of the plugin.');
        $this->assertSame(
            [],
            $missing,
            'Classes used by the code but defined neither by Tabler nor by public/css/assetsign.css.'
        );
    }

    public function testEveryColorNameFeedingADynamicClassIsDefined(): void
    {
        $colors = [];
        foreach (self::sourceFiles() as $path => $contents) {
            // Twig: bg-{{ expr }}-subtle / text-{{ expr }}-emphasis -> quoted literals inside expr.
            $dynamicClass = '/(?:bg|text|border)-\{\{((?:(?!\}\}|\{\{).)*)\}\}-(?:subtle|emphasis)/s';
            preg_match_all($dynamicClass, $contents, $matches);
            foreach ($matches[1] as $expression) {
                // Operands of a comparison (line.decision == 'remove') are not colors.
                $comparison = '/(?:==|!=|\bin)\s*\'[a-z]+\'|\'[a-z]+\'\s*(?:==|!=)/';
                $expression = (string) preg_replace($comparison, '', $expression);
                preg_match_all("/'([a-z]+)'/", $expression, $literals);
                foreach ($literals[1] as $literal) {
                    $colors[$literal][] = basename($path);
                }
            }
            // PHP: 'color' => 'orange', 'tone' => 'red', 'avg_color' => ...
            preg_match_all("/'[a-z_]*(?:color|tone)' *=> *'([a-z]+)'/", $contents, $matches);
            foreach ($matches[1] as $literal) {
                $colors[$literal][] = basename($path);
            }
        }

        // The inventory badges (bg-{{ row.color }}-subtle) take their color from
        // PhysicalInventory::getStatusColors(): theme colors, checked below as well.
        foreach (\GlpiPlugin\Assetsign\PhysicalInventory::getStatusColors() as $color) {
            $colors[$color][] = 'PhysicalInventory::getStatusColors()';
        }

        $missing = [];
        foreach ($colors as $color => $paths) {
            if (!self::isDefined('bg-' . $color . '-subtle') || !self::isDefined('text-' . $color . '-emphasis')) {
                $missing[] = $color . ' (' . implode(', ', array_unique($paths)) . ')';
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Color names used in bg-X-subtle / text-X-emphasis classes without a definition.'
        );
    }
}
