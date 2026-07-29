<?php

namespace GlpiPlugin\Remise;

use CommonDBTM;
use Migration;
use RuntimeException;

/**
 * Jeton de signature a usage unique.
 *
 * Seul le hash SHA-256 du jeton brut est stocke en base : un acces a la base
 * de donnees seule ne permet pas de reconstituer un lien de signature valide.
 * Consequence assumee : le jeton brut ne peut jamais etre relu depuis la base.
 * Une relance ne "renvoie" donc pas l'ancien lien, elle en genere un nouveau
 * (l'ancien est invalide dans le meme mouvement) — c'est plus sûr et plus simple
 * que de conserver le jeton en clair.
 */
class Token extends CommonDBTM
{
    public static $rightname = Profile::RIGHT_REMISE;

    public static function createForRemise(Remise $remise, int $validityDays): string
    {
        global $DB;

        $raw = self::generateRaw();

        $DB->insert(self::getTable(), [
            'plugin_remise_remises_id' => $remise->getID(),
            'token_hash'               => self::hash($raw),
            'date_creation'            => date('Y-m-d H:i:s'),
            'date_expiration'          => date('Y-m-d H:i:s', time() + $validityDays * DAY_TIMESTAMP),
            'is_valid'                 => 1,
            'ip_created'               => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return $raw;
    }

    /**
     * Invalide les jetons existants et en emet un nouveau (utilise pour les relances).
     */
    public static function regenerateForRemise(Remise $remise, int $validityDays): string
    {
        self::invalidateForRemise($remise->getID());
        return self::createForRemise($remise, $validityDays);
    }

    public static function invalidateForRemise(int $remises_id): void
    {
        global $DB;
        $DB->update(self::getTable(), ['is_valid' => 0], ['plugin_remise_remises_id' => $remises_id]);
    }

    /**
     * Valide un jeton recu depuis la page publique de signature.
     * @throws RuntimeException si absent, expire, deja utilise ou invalide.
     */
    public static function validate(string $raw): self
    {
        global $DB;

        $token = new self();
        $found = $token->getFromDBByCrit(['token_hash' => self::hash($raw)]);

        if (!$found) {
            throw new RuntimeException('Lien de signature inconnu.');
        }
        if (!$token->fields['is_valid']) {
            throw new RuntimeException('Ce lien de signature n\'est plus valide.');
        }
        if (strtotime($token->fields['date_expiration']) < time()) {
            throw new RuntimeException('Ce lien de signature a expiré.');
        }
        if (!empty($token->fields['date_used'])) {
            throw new RuntimeException('Ce document a déjà été traité.');
        }

        $DB->update(self::getTable(), [
            'attempts' => $token->fields['attempts'] + 1,
            'ip_used'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ], ['id' => $token->getID()]);

        if ($token->fields['attempts'] + 1 > 20) {
            $DB->update(self::getTable(), ['is_valid' => 0], ['id' => $token->getID()]);
            throw new RuntimeException('Trop de tentatives, lien désactivé par sécurité.');
        }

        return $token;
    }

    public function markUsed(): void
    {
        global $DB;
        $DB->update(self::getTable(), [
            'is_valid'   => 0,
            'date_used'  => date('Y-m-d H:i:s'),
        ], ['id' => $this->getID()]);
    }

    public static function getExpiryForRemise(int $remises_id): ?string
    {
        global $DB;
        $rows = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_remise_remises_id' => $remises_id, 'is_valid' => 1],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => 1,
        ]));
        return count($rows) ? reset($rows)['date_expiration'] : null;
    }

    public static function cronInfo(string $name): array
    {
        return ['description' => __('Purge les jetons de signature expirés ou invalides', 'remise')];
    }

    public static function cronRemiseCleanupTokens(\CronTask $task): int
    {
        global $DB;
        $DB->delete(self::getTable(), [
            'is_valid' => 0,
            new \QueryExpression('date_creation < DATE_SUB(NOW(), INTERVAL 90 DAY)'),
        ]);
        $task->addVolume($DB->affectedRows());
        return 1;
    }

    private static function generateRaw(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
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
                `token_hash` char(64) NOT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `date_expiration` timestamp NOT NULL,
                `date_used` timestamp NULL DEFAULT NULL,
                `is_valid` tinyint NOT NULL DEFAULT 1,
                `attempts` int unsigned NOT NULL DEFAULT 0,
                `ip_created` varchar(46) DEFAULT NULL,
                `ip_used` varchar(46) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `token_hash` (`token_hash`),
                KEY `plugin_remise_remises_id` (`plugin_remise_remises_id`),
                KEY `date_expiration` (`date_expiration`),
                KEY `is_valid` (`is_valid`),
                CONSTRAINT `fk_token_remise` FOREIGN KEY (`plugin_remise_remises_id`) REFERENCES `glpi_plugin_remise_remises` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }
    }
}
