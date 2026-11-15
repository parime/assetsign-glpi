<?php

namespace GlpiPlugin\Assetsign\Compat;

use GlpiPlugin\Assetsign\Config;

/**
 * Point de passage unique de TOUS les appels HTTP sortants du plugin (issues #335 et #347) :
 * webhooks de messagerie et verification de la derniere version publiee.
 *
 * - Client HTTP du cœur GLPI, donc son proxy : Glpi\Toolbox\HttpClient en GLPI 12,
 *   Toolbox::getGuzzleClient() en GLPI 11 (le premier n'existe pas en GLPI 11).
 * - Delai d'expiration total, en plus du delai de connexion du cœur.
 * - Redirections jamais suivies : une reponse 3xx est un echec (une redirection pourrait mener
 *   vers une adresse interne apres le controle, ou emmener le corps de la requete ailleurs).
 * - Schemas http et https seulement.
 * - Adresses internes refusees (boucle locale, reseaux prives, lien local dont les metadonnees
 *   cloud 169.254.169.254, adresses reservees) : le nom est resolu, chaque adresse obtenue est
 *   controlee, et la connexion est epinglee sur l'adresse controlee (pas de second DNS qui
 *   pourrait repondre autre chose). Un administrateur peut les autoriser dans la page de
 *   configuration du plugin (messagerie interne : Mattermost, Rocket.Chat...) ; en GLPI 12, le
 *   cœur exige EN PLUS que la classe Config::class figure dans la constante
 *   GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS (config/local_define.php).
 * - Aucun en-tete d'authentification GLPI (jeton de session, jeton d'application, cookie) n'est
 *   jamais transmis : ils sont retires s'ils sont fournis par erreur.
 */
final class Http
{
    /** Contexte passe au client du cœur GLPI 12 (proxy et reseaux prives par contexte). */
   public const CONTEXT = Config::class;

    /** Reglage global (table de configuration du cœur) : adresses internes autorisees. */
   public const CONFIG_CONTEXT = 'plugin:assetsign';
   public const CONFIG_ALLOW_PRIVATE = 'http_allow_private_networks';

   public const DEFAULT_TIMEOUT = 10;

   public const ERROR_SCHEME = 'scheme_not_allowed';
   public const ERROR_HOST = 'invalid_host';
   public const ERROR_INTERNAL = 'internal_address';
   public const ERROR_UNRESOLVED = 'unresolved_host';
   public const ERROR_GLPI_CONTEXT = 'glpi_private_network_context_required';
   public const ERROR_REDIRECT = 'redirect_refused';
   public const ERROR_STATUS = 'http_status';
   public const ERROR_TRANSPORT = 'transport_error';

    /** En-tetes jamais envoyes hors de GLPI. */
   private const FORBIDDEN_HEADER_PATTERN = '/^(session-token|app-token|x-glpi-.*|cookie)$/i';

    /**
     * Les administrateurs ont-ils autorise les adresses internes (page de configuration) ?
     */
   public static function allowsPrivateNetworks(): bool {
       $values = \Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_ALLOW_PRIVATE]);
       return !empty($values[self::CONFIG_ALLOW_PRIVATE]);
   }

   public static function setAllowPrivateNetworks(bool $allow): void {
       \Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_ALLOW_PRIVATE => $allow ? 1 : 0]);
   }

    /**
     * Adresse IP interne (non routable sur Internet) : boucle locale, privee, lien local,
     * partagee (CGNAT), reservee, multicast, non specifiee ; IPv4 encapsulee dans IPv6 comprise.
     * Une chaine qui n'est pas une adresse IP est consideree comme interne (refus par defaut).
     */
   public static function isInternalIp(string $ip): bool {
       $ip = trim($ip, '[]');
      if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
          return true;
      }
       // ::ffff:127.0.0.1, ::127.0.0.1 : l'adresse IPv4 portee est celle qui compte.
      if (preg_match('/^::(ffff(:0{1,4})?:)?(\d{1,3}(\.\d{1,3}){3})$/i', $ip, $m) === 1) {
          return self::isInternalIp($m[3]);
      }
       $public = filter_var(
           $ip,
           FILTER_VALIDATE_IP,
           FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
       );
      if ($public === false) {
          return true;
      }
       // Multicast (224.0.0.0/4, ff00::/8) : jamais une cible HTTP legitime.
      if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
          return ((int) explode('.', $ip)[0]) >= 224;
      }
       return stripos($ip, 'ff') === 0;
   }

    /**
     * Controle d'une adresse avant tout appel. Renvoie null si elle est acceptable, sinon le code
     * d'erreur (constantes ERROR_*). Avec $resolve, le nom d'hote est resolu et chaque adresse
     * obtenue controlee ; $resolvedIp recoit alors l'adresse sur laquelle epingler la connexion
     * (null pour une adresse IP litterale). Sans $resolve (enregistrement d'un reglage), seules
     * les adresses IP litterales et les noms locaux sont controles.
     *
     * @param bool|null $allowPrivate null : reglage de la page de configuration.
     */
   public static function checkUrl(string $url, bool $resolve = true, ?bool $allowPrivate = null, ?string &$resolvedIp = null): ?string {
       $resolvedIp = null;
       $parts = parse_url(trim($url));
      if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
          return self::ERROR_SCHEME;
      }
       $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
      if ($host === '' || preg_match('/[^a-z0-9.:_-]/', $host) === 1) {
          return self::ERROR_HOST;
      }
       $allowPrivate ??= self::allowsPrivateNetworks();

      if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
          return !$allowPrivate && self::isInternalIp($host) ? self::ERROR_INTERNAL : null;
      }
       // Ecritures numeriques d'une adresse (2130706433, 0x7f.1, 017700000001) que la resolution
       // transformerait en adresse IP : refusees, une vraie adresse s'ecrit en notation pointee.
      if (preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+)){0,3}\.?$/i', $host) === 1) {
          return self::ERROR_HOST;
      }
      if (!$allowPrivate && preg_match('/(^|\.)(localhost|localdomain|local|internal|home\.arpa)\.?$/', $host) === 1) {
          return self::ERROR_INTERNAL;
      }
      if (!$resolve) {
          return null;
      }

       $ips = self::resolve($host);
      if ($ips === []) {
          // Derriere un proxy, c'est lui qui resout : le serveur GLPI peut ne pas connaitre le nom.
          return self::usesProxy() ? null : self::ERROR_UNRESOLVED;
      }
      if (!$allowPrivate) {
         foreach ($ips as $ip) {
            if (self::isInternalIp($ip)) {
                return self::ERROR_INTERNAL;
            }
         }
      }
       $resolvedIp = $ips[0];
       return null;
   }

    /**
     * Requete HTTP sortante. Renvoie le code et le corps de la reponse (code 2xx seulement), ou
     * null en cas d'echec, $error recevant alors « code : detail » (code = constante ERROR_*).
     *
     * @param array{headers?: array<string, string>, json?: array<mixed>, timeout?: int} $options
     * @return array{status: int, body: string}|null
     */
   public static function request(string $method, string $url, array $options = [], ?string &$error = null): ?array {
       $error = null;
       $allowPrivate = self::allowsPrivateNetworks();
       $pinnedIp = null;
       $refusal = self::checkUrl($url, true, $allowPrivate, $pinnedIp);
      if ($refusal !== null) {
          $error = $refusal;
          return null;
      }

       $glpi12 = class_exists(\Glpi\Toolbox\HttpClient::class);
       $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
      if ($glpi12 && $allowPrivate && self::isInternalIp($pinnedIp ?? $host) && !self::glpiAllowsPrivateNetworks()) {
          $error = self::ERROR_GLPI_CONTEXT;
          return null;
      }

       $timeout = max(1, (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT));
       $headers = ['User-Agent' => 'GLPI-assetsign/' . (defined('PLUGIN_ASSETSIGN_VERSION') ? PLUGIN_ASSETSIGN_VERSION : 'dev')];
      foreach ($options['headers'] ?? [] as $name => $value) {
         if (preg_match(self::FORBIDDEN_HEADER_PATTERN, (string) $name) !== 1) {
             $headers[(string) $name] = (string) $value;
         }
      }
       // Epinglage sur l'adresse controlee (sans proxy : avec un proxy, c'est lui qui se connecte).
       $pin = $pinnedIp !== null && !self::usesProxy() ? $pinnedIp : null;

      try {
         if ($glpi12) {
             [$status, $body] = self::sendWithGlpi12($method, $url, $host, $pin, $headers, $timeout, $options);
         } else {
             [$status, $body] = self::sendWithGlpi11($method, $url, $host, $pin, $headers, $timeout, $options);
         }
      } catch (\Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface $e) {
          $error = self::ERROR_REDIRECT . ': HTTP ' . $e->getResponse()->getStatusCode();
          return null;
      } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
          $error = self::ERROR_STATUS . ': HTTP ' . $e->getResponse()->getStatusCode();
          return null;
      } catch (\Throwable $e) {
          $error = self::ERROR_TRANSPORT . ': ' . $e->getMessage();
          return null;
      }

      if ($status >= 300 && $status < 400) {
          $error = self::ERROR_REDIRECT . ': HTTP ' . $status;
          return null;
      }
      if ($status < 200 || $status >= 300) {
          $error = self::ERROR_STATUS . ': HTTP ' . $status;
          return null;
      }
       return ['status' => $status, 'body' => $body];
   }

    /**
     * @return string Le corps de la reponse, ou '' en cas d'echec, $error decrivant alors la cause.
     */
   public static function getContent(string $url, ?string &$error = null): string {
       $response = self::request('GET', $url, [], $error);
       return $response['body'] ?? '';
   }

    /**
     * Envoie un corps JSON en POST (issue #231, webhooks de messagerie). Vrai si le serveur a
     * repondu par un code 2xx ; sinon faux, $error decrivant la cause.
     *
     * @param array<string, mixed> $payload
     */
   public static function postJson(string $url, array $payload, ?string &$error = null): bool {
       return self::request('POST', $url, ['json' => $payload, 'timeout' => 5], $error) !== null;
   }

    /**
     * Texte lisible pour un code d'erreur (message a l'administrateur).
     */
   public static function getErrorMessage(string $code): string {
       $code = explode(':', $code, 2)[0];
       return match ($code) {
           self::ERROR_SCHEME       => __('Seules les adresses http:// et https:// sont acceptées.', 'assetsign'),
           self::ERROR_INTERNAL     => __('Cette adresse vise le serveur lui-même ou un réseau interne. Un administrateur peut autoriser les adresses internes dans la configuration du plugin.', 'assetsign'),
           self::ERROR_GLPI_CONTEXT => sprintf(
               __('GLPI refuse les adresses internes pour ce plugin : ajoutez %s à la constante GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS (config/local_define.php).', 'assetsign'),
               self::CONTEXT
           ),
           self::ERROR_REDIRECT     => __('Le serveur a répondu par une redirection, qui n\'est jamais suivie : indiquez l\'adresse finale.', 'assetsign'),
           default                  => __('Adresse invalide.', 'assetsign'),
       };
   }

    /**
     * GLPI 12 : la classe du plugin figure-t-elle dans les contextes autorises a joindre un
     * reseau prive (constante du cœur) ?
     */
   public static function glpiAllowsPrivateNetworks(): bool {
       $contexts = defined('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS')
           ? constant('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS') : [];
       return is_array($contexts) && in_array(self::CONTEXT, $contexts, true);
   }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array{0: int, 1: string}
     */
   private static function sendWithGlpi12(string $method, string $url, string $host, ?string $pin, array $headers, int $timeout, array $options): array {
       $request = ['headers' => $headers, 'timeout' => $timeout, 'max_duration' => $timeout, 'max_redirects' => 0];
      if (isset($options['json'])) {
          $request['json'] = $options['json'];
      }
      if ($pin !== null) {
          $request['resolve'] = [$host => $pin];
      }
       $response = (new \Glpi\Toolbox\HttpClient(self::CONTEXT))->request(strtoupper($method), $url, $request);
       return [$response->getStatusCode(), $response->getContent(false)];
   }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array{0: int, 1: string}
     */
   private static function sendWithGlpi11(string $method, string $url, string $host, ?string $pin, array $headers, int $timeout, array $options): array {
       $request = [
           'headers'         => $headers,
           'timeout'         => $timeout,
           'allow_redirects' => false,
           'http_errors'     => false,
       ];
       if (isset($options['json'])) {
          $request['json'] = $options['json'];
       }
       if (defined('CURLOPT_PROTOCOLS')) {
          $request['curl'] = [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
          if ($pin !== null) {
             $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
             $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
             $request['curl'][CURLOPT_RESOLVE] = [sprintf('%s:%d:%s', $host, $port, str_contains($pin, ':') ? '[' . $pin . ']' : $pin)];
          }
       }
       $response = \Toolbox::getGuzzleClient(['timeout' => $timeout])->request(strtoupper($method), $url, $request);
       return [$response->getStatusCode(), (string) $response->getBody()];
   }

    /** @return list<string> */
   private static function resolve(string $host): array {
       $ips = gethostbynamel($host) ?: [];
      if (function_exists('dns_get_record')) {
          $records = @dns_get_record($host, DNS_AAAA);
         if (is_array($records)) {
             $ips = array_merge($ips, array_column($records, 'ipv6'));
         }
      }
       return array_values(array_unique(array_map('strval', $ips)));
   }

   private static function usesProxy(): bool {
       global $CFG_GLPI;
       return !empty($CFG_GLPI['proxy_name']);
   }
}
