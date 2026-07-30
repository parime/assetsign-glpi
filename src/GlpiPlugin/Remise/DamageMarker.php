<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use Migration;

/**
 * Marqueur de dommage depose par un technicien sur l'une des 3 vues de
 * reference generiques (public/images/damage-views/, illustrations fournies
 * par l'utilisateur — pas des croquis maison) : "etat des lieux visuel".
 * Coordonnees stockees en POURCENTAGE de l'image (pas en pixels) :
 * independant de la taille d'affichage a l'ecran ET de la taille de rendu
 * dans le PDF, qui ne sont jamais identiques (cf. HandoverPdfBuilder).
 * Pas de front dedie : gere via front/damagemarker.php (AJAX, cf.
 * public/js/sign/damage-annotation.js) et affiche dans remise_form.html.twig.
 */
class DamageMarker extends CommonDBTM
{
    public static $rightname = Profile::RIGHT_REMISE;

    public const SEVERITY_MINOR = 0;
    public const SEVERITY_MAJOR = 1;

    /** Nombre de vues de reference disponibles (cf. public/images/damage-views/). */
    public const VIEW_COUNT = 3;

    public static function getViewLabels(): array
    {
        return [
            0 => __('Vue arrière — capot fermé', 'remise'),
            1 => __('Vue de face — écran ouvert', 'remise'),
            2 => __('Dessous', 'remise'),
        ];
    }

    /**
     * Libelles fixes (toujours en francais), pour le PDF (reel ou apercu)
     * UNIQUEMENT — jamais getViewLabels() (traduit) ici, meme principe que
     * Remise::getCanonicalItemtypeLabel() : un PDF est genere soit pendant
     * le hook d'affectation (session du technicien), soit pendant la
     * signature (session du beneficiaire) — le contenu archive ne doit pas
     * dependre de la langue de qui l'a declenche.
     */
    public static function getCanonicalViewLabels(): array
    {
        return [
            0 => 'Vue arrière — capot fermé',
            1 => 'Vue de face — écran ouvert',
            2 => 'Dessous',
        ];
    }

    public static function getViewImageFilenames(): array
    {
        return [
            0 => 'arriere.jpg',
            1 => 'avant.jpg',
            2 => 'dessous.jpg',
        ];
    }

    /** @return self[] Toutes les vues et markers pour une remise, une entree par view_index. */
    public static function getForRemise(int $remises_id): array
    {
        global $DB;

        $markers = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_remise_remises_id' => $remises_id],
            'ORDER' => 'view_index, id',
        ]) as $row) {
            $markers[] = $row;
        }
        return $markers;
    }

    public static function addMarker(int $remises_id, int $viewIndex, float $x, float $y, string $description, int $severity): int
    {
        $marker = new self();
        return (int) $marker->add([
            'plugin_remise_remises_id' => $remises_id,
            'view_index'               => $viewIndex,
            'x_percent'                => $x,
            'y_percent'                => $y,
            'description'              => $description,
            'severity'                 => $severity,
        ]);
    }

    /**
     * Met a jour la position (glisser-deposer) et/ou la description/gravite
     * d'un marqueur existant. Meme verification d'appartenance que deleteMarker().
     */
    public static function updateMarker(int $id, int $remises_id, array $changes): bool
    {
        $marker = new self();
        if (!$marker->getFromDB($id) || (int) $marker->fields['plugin_remise_remises_id'] !== $remises_id) {
            return false;
        }
        return (bool) $marker->update(['id' => $id] + $changes);
    }

    public static function deleteMarker(int $id, int $remises_id): bool
    {
        $marker = new self();
        // Verifie l'appartenance a CETTE remise avant de supprimer : sans cela,
        // un id de marqueur devine (numerotation auto-incrementee) permettrait
        // de supprimer le marqueur d'une AUTRE remise.
        if (!$marker->getFromDB($id) || (int) $marker->fields['plugin_remise_remises_id'] !== $remises_id) {
            return false;
        }
        return $marker->delete(['id' => $id]);
    }

    public static function install(Migration $migration): void
    {
        global $DB;
        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage('Création de la table ' . $table);
            $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_remise_remises_id` int unsigned NOT NULL,
                `view_index` tinyint unsigned NOT NULL DEFAULT 0,
                `x_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
                `y_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
                `description` varchar(255) DEFAULT NULL,
                `severity` tinyint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `plugin_remise_remises_id` (`plugin_remise_remises_id`),
                CONSTRAINT `fk_dm_remise` FOREIGN KEY (`plugin_remise_remises_id`) REFERENCES `glpi_plugin_remise_remises` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }
    }
}
