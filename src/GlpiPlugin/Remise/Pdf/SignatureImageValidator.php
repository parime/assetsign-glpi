<?php

namespace GlpiPlugin\Remise\Pdf;

use RuntimeException;

/**
 * Verifie cote serveur qu'une image de signature soumise depuis la page publique
 * n'est pas triviale (canvas quasi vide, un simple point, une image trafiquee).
 * La verification cote client (signature_pad.isEmpty()) ne protege que contre les
 * erreurs d'usage normales : un client modifie (ou un appel direct a l'API) peut
 * envoyer n'importe quoi, d'ou ce controle independant cote serveur.
 */
final class SignatureImageValidator
{
    private const MIN_WIDTH = 40;
    private const MIN_HEIGHT = 20;
    private const MIN_INK_PIXELS = 150;
    private const MIN_INK_RATIO = 0.002; // 0.2% de la surface du canevas
    private const ALPHA_OPAQUE_THRESHOLD = 100; // GD : 0 = opaque, 127 = transparent

    /**
     * @param string $dataUri ex: "data:image/png;base64,iVBORw0KG..."
     * @throws RuntimeException si l'image est absente, illisible, ou visuellement vide
     */
    public static function assertValid(string $dataUri): void
    {
        if (!preg_match('/^data:image\/png;base64,(.+)$/', $dataUri, $matches)) {
            throw new RuntimeException('Format de signature invalide.');
        }

        $binary = base64_decode($matches[1], true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Signature illisible.');
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new RuntimeException('Signature illisible.');
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT) {
                throw new RuntimeException('Signature trop petite, merci de recommencer.');
            }

            $inkPixels = self::countInkPixels($image, $width, $height);
            $ratio = $inkPixels / ($width * $height);

            if ($inkPixels < self::MIN_INK_PIXELS || $ratio < self::MIN_INK_RATIO) {
                throw new RuntimeException('Signature vide ou trop peu tracée, merci de signer à nouveau.');
            }
        } finally {
            imagedestroy($image);
        }
    }

    private static function countInkPixels($image, int $width, int $height): int
    {
        $count = 0;

        // Echantillonnage : parcourir toute l'image serait couteux pour rien,
        // une grille reguliere suffit a detecter un trace reel vs un canevas vide.
        $stepX = max(1, (int) ($width / 200));
        $stepY = max(1, (int) ($height / 200));

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha < self::ALPHA_OPAQUE_THRESHOLD) {
                    $count++;
                }
            }
        }

        // Remet a l'echelle du nombre reel de pixels puisque l'echantillonnage
        // saute des colonnes/lignes (sinon MIN_INK_PIXELS deviendrait trop strict
        // sur une grande image et trop laxiste sur une petite).
        $sampledPixels = ceil($width / $stepX) * ceil($height / $stepY);
        $totalPixels = $width * $height;

        return (int) round($count * ($totalPixels / max(1, $sampledPixels)));
    }
}
