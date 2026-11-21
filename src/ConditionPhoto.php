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

namespace GlpiPlugin\Assetsign;

use CommonDBTM;
use Document;
use Document_Item;
use Migration;

/**
 * Issue #369 : photos de l'etat des lieux d'une fiche de remise ou de restitution.
 *
 * Chaque photo est un Document GLPI de l'entite de la fiche, rattache a la fiche (Document_Item).
 * Le fichier recu n'est jamais stocke tel quel : type et taille controles, image decodee puis
 * reencodee en JPEG, ce qui retire toutes les metadonnees (EXIF dont la position GPS, XMP,
 * commentaires) et tout contenu cache a la suite des donnees d'image. L'empreinte SHA-256 du
 * fichier stocke est conservee : elle figure dans le PDF signe.
 *
 * Ajout et suppression : droit de modification de la fiche, tant qu'elle n'est pas signee.
 * Affichage : droit de lecture de la fiche qui porte la photo (entites de la session comprises).
 */
class ConditionPhoto extends CommonDBTM
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Au-dela, le decodage demanderait trop de memoire (image « bombe »). */
    public const MAX_PIXELS = 24_000_000;

    /** Plus grand cote de la photo stockee, en pixels. */
    public const MAX_SIDE = 1600;

    public const MAX_PER_SHEET = 12;

    public const JPEG_QUALITY = 85;

    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function isApplicable(Assetsign $sheet): bool
    {
        return in_array((int) ($sheet->fields['type'] ?? -1), [Assetsign::TYPE_HANDOVER, Assetsign::TYPE_RETURN], true);
    }

    public static function canEditSheet(Assetsign $sheet): bool
    {
        return self::isApplicable($sheet)
            && $sheet->isStillEditable()
            && (bool) $sheet->can($sheet->getID(), UPDATE);
    }

    /**
     * Controle et nettoie le fichier recu, renvoie le JPEG a stocker.
     *
     * @throws \RuntimeException message pour l'utilisateur
     */
    public static function sanitize(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(__('Échec de l\'envoi de la photo.', 'assetsign'));
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            throw new \RuntimeException(__('Le fichier de la photo est vide.', 'assetsign'));
        }
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException(sprintf(__('La photo dépasse la taille maximale (%d Mo).', 'assetsign'), self::MAX_BYTES / 1024 / 1024));
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new \RuntimeException(__('Type de fichier refusé : joignez une photo au format JPEG, PNG ou WebP.', 'assetsign'));
        }
        $info = @getimagesize($path);
        if ($info === false || $info['mime'] !== $mime || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            throw new \RuntimeException(__('Le contenu du fichier n\'est pas une photo valide.', 'assetsign'));
        }
        if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            throw new \RuntimeException(sprintf(__('La photo est trop grande (%d mégapixels au plus).', 'assetsign'), self::MAX_PIXELS / 1_000_000));
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException(__('Le contenu du fichier n\'est pas une photo valide.', 'assetsign'));
        }
        if ($mime === 'image/jpeg') {
            $image = self::applyExifOrientation($image, $path);
        }
        $image = self::downscale($image);

        // Fond blanc sous la transparence eventuelle (PNG, WebP) : le JPEG n'en a pas.
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas instanceof \GdImage) {
            throw new \RuntimeException(__('Échec de l\'envoi de la photo.', 'assetsign'));
        }
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        ob_start();
        $written = imagejpeg($canvas, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();
        if (!$written || $jpeg === '') {
            throw new \RuntimeException(__('Échec de l\'envoi de la photo.', 'assetsign'));
        }
        return $jpeg;
    }

    /** Remet droite une photo prise telephone tourne (l'orientation EXIF disparait au reencodage). */
    private static function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $angle = match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)) {
            3       => 180,
            6       => 270,
            8       => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        return $rotated instanceof \GdImage ? $rotated : $image;
    }

    private static function downscale(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) <= self::MAX_SIDE) {
            return $image;
        }
        $scaled = $width >= $height
            ? imagescale($image, self::MAX_SIDE, (int) max(1, round($height * self::MAX_SIDE / $width)))
            : imagescale($image, (int) max(1, round($width * self::MAX_SIDE / $height)), self::MAX_SIDE);
        return $scaled instanceof \GdImage ? $scaled : $image;
    }

    /**
     * Joint une photo (fichier deja present sur le serveur) a la fiche.
     *
     * @return int id de la photo
     *
     * @throws \RuntimeException message pour l'utilisateur
     */
    public static function addToSheet(Assetsign $sheet, string $path, int $usersId): int
    {
        if (!self::isApplicable($sheet)) {
            throw new \RuntimeException(__('Les photos ne concernent que les fiches de remise et de restitution.', 'assetsign'));
        }
        if (!(bool) $sheet->can($sheet->getID(), UPDATE)) {
            throw new \RuntimeException(__('Vous n\'avez pas le droit de modifier cette fiche.', 'assetsign'));
        }
        if (!$sheet->isStillEditable()) {
            throw new \RuntimeException(__('Les photos ne peuvent plus changer : la fiche est signée, expirée ou annulée.', 'assetsign'));
        }
        if (count(self::getForAssetsign($sheet->getID())) >= self::MAX_PER_SHEET) {
            throw new \RuntimeException(sprintf(__('Une fiche compte %d photos au plus.', 'assetsign'), self::MAX_PER_SHEET));
        }

        $jpeg = self::sanitize($path);
        $hash = hash('sha256', $jpeg);
        $tmpName = uniqid('assetsign_photo_', true) . '.jpg';
        if (file_put_contents(GLPI_TMP_DIR . '/' . $tmpName, $jpeg) === false) {
            throw new \RuntimeException(__('Échec de l\'envoi de la photo.', 'assetsign'));
        }

        // Messages techniques du coeur (« Document copie avec succes ») : seul le message de
        // l'action doit s'afficher.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $document = new Document();
        $documentsId = (int) $document->add([
            'name'          => sprintf(__('Photo de l\'état des lieux - fiche n° %s', 'assetsign'), Assetsign::displayReference($sheet->fields)),
            'comment'       => 'SHA-256 : ' . $hash,
            'entities_id'   => (int) $sheet->fields['entities_id'],
            'is_recursive'  => 0,
            '_filename'     => [$tmpName],
            '_tag_filename' => [$tmpName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;

        if ($documentsId <= 0 || !$document->getFromDB($documentsId)) {
            throw new \RuntimeException(__('Échec de l\'envoi de la photo.', 'assetsign'));
        }
        $stored = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
        if (!is_file($stored) || hash_file('sha256', $stored) !== $hash) {
            $document->delete(['id' => $documentsId], true);
            throw new \RuntimeException(__('Échec de l\'envoi de la photo.', 'assetsign'));
        }

        (new Document_Item())->add([
            'documents_id' => $documentsId,
            'itemtype'     => Assetsign::class,
            'items_id'     => $sheet->getID(),
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;

        return (int) (new self())->add([
            'plugin_assetsign_assetsigns_id' => $sheet->getID(),
            'documents_id'                   => $documentsId,
            'sha256'                         => $hash,
            'users_id'                       => $usersId,
        ]);
    }

    /**
     * Retire une photo de la fiche (et son Document), memes conditions que l'ajout.
     *
     * @throws \RuntimeException
     */
    public static function removeFromSheet(Assetsign $sheet, int $photoId): void
    {
        if (!self::canEditSheet($sheet)) {
            throw new \RuntimeException(__('Vous n\'avez pas le droit de modifier cette fiche.', 'assetsign'));
        }
        $photo = new self();
        if (!$photo->getFromDB($photoId) || (int) $photo->fields['plugin_assetsign_assetsigns_id'] !== $sheet->getID()) {
            throw new \RuntimeException(__('Photo introuvable.', 'assetsign'));
        }
        $documentsId = (int) $photo->fields['documents_id'];
        $photo->delete(['id' => $photoId], true);

        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $document = new Document();
        if ($documentsId > 0 && $document->getFromDB($documentsId)) {
            $document->delete(['id' => $documentsId], true);
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
    }

    /**
     * @return list<array{id: int, documents_id: int, sha256: string, users_id: int, date_creation: string}>
     */
    public static function getForAssetsign(int $assetsignsId): array
    {
        global $DB;

        $photos = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_assetsign_assetsigns_id' => $assetsignsId],
            'ORDER' => 'id ASC',
        ]) as $row) {
            $photos[] = [
                'id'            => (int) $row['id'],
                'documents_id'  => (int) $row['documents_id'],
                'sha256'        => (string) $row['sha256'],
                'users_id'      => (int) $row['users_id'],
                'date_creation' => (string) $row['date_creation'],
            ];
        }
        return $photos;
    }

    /** @return list<int> documents des photos de la fiche (absents de la liste « Documents joints ») */
    public static function getDocumentIds(int $assetsignsId): array
    {
        return array_column(self::getForAssetsign($assetsignsId), 'documents_id');
    }

    /**
     * Remise dont la restitution est le pendant : derniere remise du meme materiel, envoyee ou
     * signee, anterieure a la restitution.
     */
    public static function findHandoverFor(Assetsign $return): ?int
    {
        global $DB;

        if ((int) $return->fields['type'] !== Assetsign::TYPE_RETURN) {
            return null;
        }
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Assetsign::getTable(),
            'WHERE'  => [
                'itemtype'   => (string) $return->fields['itemtype'],
                'items_id'   => (int) $return->fields['items_id'],
                'type'       => Assetsign::TYPE_HANDOVER,
                'status'     => [...Assetsign::STATUSES_AWAITING_SIGNATURE, ...Assetsign::STATUSES_SIGNED, Assetsign::STATUS_COMPLETED_NO_SIGNATURE],
                'is_deleted' => 0,
                ['id' => ['<', $return->getID()]],
            ],
            'ORDER'  => 'id DESC',
            'LIMIT'  => 1,
        ])->current();
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Bloc « Photos de l'etat des lieux » de la fiche ; pour une restitution, les photos de la
     * remise si l'utilisateur peut lire cette remise.
     *
     * @return array<string, mixed>|null
     */
    public static function getDisplayData(Assetsign $sheet): ?array
    {
        if (!self::isApplicable($sheet)) {
            return null;
        }
        $isReturn = (int) $sheet->fields['type'] === Assetsign::TYPE_RETURN;
        $before = null;
        $handoverId = $isReturn ? self::findHandoverFor($sheet) : null;
        if ($handoverId !== null) {
            $handover = new Assetsign();
            if ($handover->getFromDB($handoverId) && (bool) $handover->can($handoverId, READ)) {
                $before = [
                    'reference' => Assetsign::displayReference($handover->fields),
                    'url'       => $handover->getLinkURL(),
                    'photos'    => self::forDisplay($handover),
                ];
            }
        }

        return [
            'is_return' => $isReturn,
            'photos'    => self::forDisplay($sheet),
            'before'    => $before,
            'can_edit'  => self::canEditSheet($sheet),
            'max'       => self::MAX_PER_SHEET,
            'max_mb'    => (int) (self::MAX_BYTES / 1024 / 1024),
            'in_pdf'    => (bool) (Config::getForEntity((int) $sheet->fields['entities_id'])->fields['photos_in_pdf'] ?? false),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function forDisplay(Assetsign $sheet): array
    {
        $photos = [];
        foreach (self::getForAssetsign($sheet->getID()) as $index => $photo) {
            $photos[] = [
                'id'     => $photo['id'],
                'number' => $index + 1,
                'url'    => $sheet->getFormURLWithID($sheet->getID()) . '&photo=' . $photo['id'],
                'date'   => \Html::convDateTime($photo['date_creation']),
                'user'   => PaperSignature::userName($photo['users_id']),
                'sha256' => $photo['sha256'],
            ];
        }
        return $photos;
    }

    /**
     * Chemin du fichier d'une photo de CETTE fiche (le droit de lecture de la fiche est controle par
     * l'appelant), null si la photo n'existe pas ou appartient a une autre fiche.
     */
    public static function resolveFile(Assetsign $sheet, int $photoId): ?string
    {
        $photo = new self();
        if ($photoId <= 0 || !$photo->getFromDB($photoId) || (int) $photo->fields['plugin_assetsign_assetsigns_id'] !== $sheet->getID()) {
            return null;
        }
        $document = new Document();
        if (!$document->getFromDB((int) $photo->fields['documents_id'])) {
            return null;
        }
        $path = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
        return is_file($path) ? $path : null;
    }

    /**
     * Envoie la photo au navigateur (toujours en image/jpeg, jamais interpretee autrement).
     *
     * @throws \Glpi\Exception\Http\NotFoundHttpException
     */
    public static function send(Assetsign $sheet, int $photoId): void
    {
        $path = self::resolveFile($sheet, $photoId);
        if ($path === null) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }
        header('Content-Type: image/jpeg');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="photo-' . $photoId . '.jpg"');
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
    }

    /**
     * Section du PDF : empreintes toujours, images seulement si Config::photos_in_pdf. Pas de
     * controle de droit ici : le PDF est aussi rendu depuis la page de signature, sans session.
     *
     * @return array{with_images: bool, is_return: bool, photos: list<array<string, mixed>>, before: list<array<string, mixed>>}|null
     */
    public static function getForPdf(Assetsign $sheet, bool $withImages): ?array
    {
        if (!self::isApplicable($sheet) || $sheet->isNewItem()) {
            return null;
        }
        $isReturn = (int) $sheet->fields['type'] === Assetsign::TYPE_RETURN;
        $handoverId = $isReturn ? self::findHandoverFor($sheet) : null;
        $photos = self::pdfRows($sheet->getID(), $withImages);
        $before = $handoverId !== null ? self::pdfRows($handoverId, $withImages) : [];
        if ($photos === [] && $before === []) {
            return null;
        }
        return ['with_images' => $withImages, 'is_return' => $isReturn, 'photos' => $photos, 'before' => $before];
    }

    /** @return list<array<string, mixed>> */
    private static function pdfRows(int $assetsignsId, bool $withImages): array
    {
        $rows = [];
        foreach (self::getForAssetsign($assetsignsId) as $index => $photo) {
            $dataUri = null;
            if ($withImages) {
                $document = new Document();
                if ($document->getFromDB($photo['documents_id'])) {
                    $path = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
                    if (is_file($path)) {
                        $dataUri = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($path));
                    }
                }
            }
            $rows[] = [
                'number'   => $index + 1,
                'date'     => date('d/m/Y H:i', (int) strtotime($photo['date_creation'])),
                'sha256'   => $photo['sha256'],
                'data_uri' => $dataUri,
            ];
        }
        return $rows;
    }

    public static function install(Migration $migration): void
    {
        global $DB;

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage('Création de la table ' . $table);
            $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_assetsigns_id` int unsigned NOT NULL,
                `documents_id` int unsigned NOT NULL DEFAULT 0,
                `sha256` char(64) NOT NULL DEFAULT '',
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_assetsign_assetsigns_id` (`plugin_assetsign_assetsigns_id`),
                KEY `documents_id` (`documents_id`),
                CONSTRAINT `fk_cp_assetsign` FOREIGN KEY (`plugin_assetsign_assetsigns_id`) REFERENCES `glpi_plugin_assetsign_assetsigns` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }
    }
}
