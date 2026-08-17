<?php

namespace GlpiPlugin\Assetsign;

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
   public static $rightname = Profile::RIGHT_ASSETSIGN;

    /** Au-dela de ce nombre de tentatives d'acces, le jeton est desactive par securite. */
   private const MAX_ATTEMPTS = 20;

    /**
     * Duree de conservation des jetons invalides/utilises avant purge automatique
     * (jours). Public : reutilise tel quel dans la description du CronTask
     * (hook.php) pour eviter de dupliquer cette valeur en toutes lettres a deux
     * endroits qui pourraient diverger.
     */
   public const CLEANUP_RETENTION_DAYS = 90;

   public static function createForAssetsign(Assetsign $assetsign, int $validityDays): string {
       global $DB;

       $raw = self::generateRaw();

       // date_expiration calculee cote base (DATE_ADD(NOW(), ...)), pas via
       // PHP time() : un serveur/conteneur dont l'horloge derive (piege reel
       // signale par un utilisateur - cf. TROUBLESHOOTING.md) rendrait sinon
       // un jeton fraichement cree deja "expire" des qu'une autre instance
       // PHP (une autre requete web, un cron sur un autre conteneur...) avec
       // une horloge legerement differente le valide juste apres. S'appuyer
       // sur l'horloge du SERVEUR DE BASE DE DONNEES (une source unique,
       // partagee par tous les processus PHP qui s'y connectent) elimine ce
       // risque de derive inter-processus/conteneurs. date_creation n'est pas
       // non plus fournie explicitement : la colonne a deja un DEFAULT
       // CURRENT_TIMESTAMP (meme raisonnement).
       $DB->insert(self::getTable(), [
           'plugin_assetsign_assetsigns_id' => $assetsign->getID(),
           'token_hash'               => self::hash($raw),
           'date_expiration'          => new \QueryExpression('DATE_ADD(NOW(), INTERVAL ' . (int) $validityDays . ' DAY)'),
           'is_valid'                 => 1,
           'ip_created'               => $_SERVER['REMOTE_ADDR'] ?? null,
       ]);

       return $raw;
   }

    /**
     * Invalide les jetons existants et en emet un nouveau (utilise pour les relances).
     */
   public static function regenerateForAssetsign(Assetsign $assetsign, int $validityDays): string {
       self::invalidateForAssetsign($assetsign->getID());
       return self::createForAssetsign($assetsign, $validityDays);
   }

   public static function invalidateForAssetsign(int $assetsigns_id): void {
       global $DB;
       $DB->update(self::getTable(), ['is_valid' => 0], ['plugin_assetsign_assetsigns_id' => $assetsigns_id]);
   }

    /**
     * Valide un jeton recu depuis la page publique de signature.
     * @throws RuntimeException si absent, expire, deja utilise ou invalide.
     */
   public static function validate(string $raw): self {
       global $DB;

       $token = new self();
       $found = $token->getFromDBByCrit(['token_hash' => self::hash($raw)]);

      if (!$found) {
          throw new \RuntimeException(__('Lien de signature inconnu.', 'assetsign'));
      }
      // Verifie AVANT le controle generique is_valid ci-dessous : markUsed()
      // met is_valid a 0 ET date_used en meme temps (meme UPDATE), donc un
      // jeton deja traite avec succes matchait jusqu'ici le controle
      // generique en premier et affichait "n'est plus valide" — message
      // alarmant a tort si le beneficiaire recharge la page de signature
      // juste apres avoir signe (ou si sa connexion a coupe pendant que le
      // traitement serveur se terminait quand meme en arriere-plan).
      if (!empty($token->fields['date_used'])) {
          throw new \RuntimeException(__('Ce document a déjà été traité.', 'assetsign'));
      }
      if (!$token->fields['is_valid']) {
          throw new \RuntimeException(__('Ce lien de signature n\'est plus valide.', 'assetsign'));
      }
      // Comparaison contre l'horloge de la base (pas PHP time()) : meme
      // raisonnement qu'a la creation du jeton, cf. createForAssetsign().
      if (strtotime($token->fields['date_expiration']) < strtotime(self::dbNow())) {
          throw new \RuntimeException(__('Ce lien de signature a expiré.', 'assetsign'));
      }

       $DB->update(self::getTable(), [
           'attempts' => $token->fields['attempts'] + 1,
           'ip_used'  => $_SERVER['REMOTE_ADDR'] ?? null,
       ], ['id' => $token->getID()]);

      if ($token->fields['attempts'] + 1 > self::MAX_ATTEMPTS) {
          $DB->update(self::getTable(), ['is_valid' => 0], ['id' => $token->getID()]);
          throw new \RuntimeException(__('Trop de tentatives, lien désactivé par sécurité.', 'assetsign'));
      }

       return $token;
   }

   public function markUsed(): void {
       global $DB;
       $DB->update(self::getTable(), [
           'is_valid'   => 0,
           'date_used'  => date('Y-m-d H:i:s'),
       ], ['id' => $this->getID()]);
   }

   public static function getExpiryForAssetsign(int $assetsigns_id): ?string {
       global $DB;
       $rows = iterator_to_array($DB->request([
           'FROM'  => self::getTable(),
           'WHERE' => ['plugin_assetsign_assetsigns_id' => $assetsigns_id, 'is_valid' => 1],
           'ORDER' => 'date_creation DESC',
           'LIMIT' => 1,
       ]));
       return count($rows) ? reset($rows)['date_expiration'] : null;
   }

   public static function cronInfo(string $name): array {
       return ['description' => __('Purge les jetons de signature expirés ou invalides', 'assetsign')];
   }

   public static function cronAssetsignCleanupTokens(\CronTask $task): int {
       global $DB;
       $DB->delete(self::getTable(), [
           'is_valid' => 0,
           new \QueryExpression('date_creation < DATE_SUB(NOW(), INTERVAL ' . self::CLEANUP_RETENTION_DAYS . ' DAY)'),
       ]);
       $task->addVolume($DB->affectedRows());
       return 1;
   }

    /**
     * Horloge du serveur de base de donnees plutot que celle du processus PHP
     * courant (time()) — cf. le commentaire detaille sur createForAssetsign().
     */
   private static function dbNow(): string {
       global $DB;
       $result = $DB->doQuery('SELECT NOW() AS n');
       return $DB->fetchAssoc($result)['n'];
   }

   private static function generateRaw(): string {
       return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
   }

   private static function hash(string $raw): string {
       return hash('sha256', $raw);
   }

   public static function install(Migration $migration): void {
       global $DB;
       $table = self::getTable();

      if (!$DB->tableExists($table)) {
          $migration->displayMessage('Création de la table ' . $table);
          $DB->doQuery("CREATE TABLE `$table` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_assetsign_assetsigns_id` int unsigned NOT NULL,
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
                KEY `plugin_assetsign_assetsigns_id` (`plugin_assetsign_assetsigns_id`),
                KEY `date_expiration` (`date_expiration`),
                KEY `is_valid` (`is_valid`),
                CONSTRAINT `fk_token_assetsign` FOREIGN KEY (`plugin_assetsign_assetsigns_id`) REFERENCES `glpi_plugin_assetsign_assetsigns` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
      }
   }
}
