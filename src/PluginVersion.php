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

declare(strict_types=1);

namespace GlpiPlugin\Assetsign;

use GlpiPlugin\Assetsign\Compat\Http;

/**
 * Composant commun « Version du plugin » (charte section 9 bis, parime/glpi-plugin-template#12,
 * repris par l'issue #396) :
 * version installée et dernière release publiée sur GitHub, affichées par le partiel
 * templates/_plugin_version.html.twig dans l'onglet Général de la page de configuration.
 *
 * Reprend le mécanisme des plugins existants (GRC, AssetSign, Configuration GLPI Auto :
 * /releases/latest, hors pre-release, client HTTP du cœur, cache) et y ajoute :
 * - un délai court (3 s) : la page de configuration ne reste jamais bloquée ;
 * - un cache de 12 h, et le dernier résultat réussi réutilisé quand GitHub ne répond pas ;
 * - l'en-tête If-None-Match (ETag) : une réponse 304 ne compte pas dans la limite de 60 requêtes par
 *   heure de l'API GitHub sans authentification ;
 * - la raison d'un échec (pas d'accès à GitHub, limite atteinte, délai dépassé...) et la date de la
 *   dernière vérification réussie, au lieu d'un silence ;
 * - une option pour désactiver la vérification (instances hors ligne).
 *
 * Aucune donnée envoyée hormis la requête elle-même : ni identifiant d'instance, ni jeton.
 * Rien n'est écrit en base : l'état est gardé dans le cache de GLPI ($GLPI_CACHE), ce qui évite une
 * écriture de configuration (et une ligne d'historique) à chaque vérification.
 *
 * Générique : la clé du plugin et le dépôt sont des paramètres. Usage (Config::showConfigForm()) :
 *   $status = (new PluginVersion('assetsign', 'parime/assetsign-glpi', PLUGIN_ASSETSIGN_VERSION))
 *       ->status(Config::isVersionCheckEnabled());
 * puis, dans le Twig : {% include '@assetsign/_plugin_version.html.twig' with {plugin_version: status} %}
 * Dans AssetSign, l'appel passe par Compat\Http::get() : client du cœur (proxy), adresse contrôlée.
 */
final class PluginVersion
{
   public const STATE_UP_TO_DATE = 'up_to_date';
   public const STATE_UPDATE_AVAILABLE = 'update_available';
   public const STATE_UNKNOWN = 'unknown';

    /** Vérification désactivée par l'administrateur. */
   public const REASON_DISABLED = 'disabled';
    /** Pas d'accès à GitHub (nom inconnu, connexion refusée, proxy, TLS...). */
   public const REASON_UNREACHABLE = 'unreachable';
    /** Limite de requêtes de l'API GitHub atteinte (403 ou 429). */
   public const REASON_RATE_LIMITED = 'rate_limited';
    /** Délai dépassé (3 s). */
   public const REASON_TIMEOUT = 'timeout';
    /** Aucune release publiée sur le dépôt (404). */
   public const REASON_NO_RELEASE = 'no_release';
    /** Réponse inattendue (autre code HTTP, JSON illisible, étiquette qui n'est pas une version). */
   public const REASON_INVALID_RESPONSE = 'invalid_response';

    /** Durée de validité d'un résultat. */
   public const CACHE_TTL = 12 * 3600;
    /** Après un échec, nouvel essai au plus tôt une heure plus tard (pas 3 s d'attente à chaque page). */
   public const RETRY_AFTER_FAILURE = 3600;
    /** Le dernier résultat réussi reste affiché après un échec pendant 7 jours. */
   public const STALE_MAX_AGE = 7 * 86400;
    /** Délai total de la requête, en secondes. */
   public const TIMEOUT = 3;
    /** Durée de conservation de l'état dans le cache de GLPI. */
   private const STORE_TTL = 30 * 86400;

   private \Closure $fetch;
   private \Closure $load;
   private \Closure $save;
   private \Closure $now;

    /**
     * @param string $pluginKey        clé du plugin (nom du dossier dans plugins/), par exemple « assetsign »
     * @param string $repository       dépôt GitHub « propriétaire/nom », par exemple « parime/assetsign-glpi »
     * @param string $installedVersion version installée (constante PLUGIN_<CLÉ>_VERSION)
     * @param \Closure|null $fetch     tests : fn(string $url, array $headers, int $timeout): array{status, body,
     *                                 headers} (Compat\Http::get par défaut)
     * @param \Closure|null $load      tests : fn(): mixed, état enregistré ($GLPI_CACHE par défaut)
     * @param \Closure|null $save      tests : fn(array $state): void
     * @param \Closure|null $now       tests : fn(): int, horodatage courant
     */
   public function __construct(
        private readonly string $pluginKey,
        private readonly string $repository,
        private readonly string $installedVersion,
        ?\Closure $fetch = null,
        ?\Closure $load = null,
        ?\Closure $save = null,
        ?\Closure $now = null,
    ) {
      if (preg_match('/^[a-z0-9]+$/', $pluginKey) !== 1) {
          throw new \InvalidArgumentException('Invalid plugin key: ' . $pluginKey);
      }
      if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1) {
          throw new \InvalidArgumentException('Invalid GitHub repository: ' . $repository);
      }
       $cacheKey = self::cacheKey($pluginKey);
       $this->fetch = $fetch ?? static fn (string $url, array $headers, int $timeout): array
           => Http::get($url, $headers, $timeout);
       $this->load = $load ?? static function () use ($cacheKey): mixed {
           global $GLPI_CACHE;

           return $GLPI_CACHE->get($cacheKey);
       };
         $this->save = $save ?? static function (array $state) use ($cacheKey): void {
            global $GLPI_CACHE;

            $GLPI_CACHE->set($cacheKey, $state, self::STORE_TTL);
         };
         $this->now = $now ?? static fn (): int => time();
   }

    /**
     * État à afficher. Interroge GitHub seulement si la vérification est activée et que le dernier
     * résultat est plus vieux que 12 h (1 h après un échec). Ne lève jamais d'exception.
     *
     * @return array{
     *     installed: string,
     *     latest: string|null,
     *     state: string,
     *     reason: string|null,
     *     stale: bool,
     *     release_url: string,
     *     download_url: string|null,
     *     last_success: string|null,
     *     checked_at: string|null,
     * }
     *   state : STATE_* ; reason : REASON_* (null si la dernière vérification a réussi) ; stale : le
     *   résultat affiché vient d'une vérification réussie antérieure, la dernière ayant échoué ;
     *   last_success et checked_at : dates « Y-m-d H:i:s » (filtre Twig formatted_datetime).
     */
   public function status(bool $enabled = true): array {
      if (!$enabled) {
          return $this->build([], self::REASON_DISABLED);
      }
       $state = $this->loadState();
       $now = ($this->now)();
      if (!isset($state['next_check']) || $now >= $state['next_check']) {
          $state = $this->refresh($state, $now);
         try {
             ($this->save)($state);
         } catch (\Throwable) {
             // Cache indisponible : le résultat reste affiché, la vérification sera refaite.
             $state['next_check'] = $now;
         }
      }

       return $this->build($state, $state['reason'] ?? null);
   }

    /**
     * Compare deux versions (« v » initial accepté). Une version installée plus récente que la
     * dernière release (version de développement) est considérée comme à jour.
     *
     * @return string STATE_UP_TO_DATE, STATE_UPDATE_AVAILABLE, ou STATE_UNKNOWN si l'une des deux
     *                n'est pas un numéro de version
     */
   public static function compare(string $installed, string $latest): string {
       $installed = self::normalize($installed);
       $latest = self::normalize($latest);
      if ($installed === null || $latest === null) {
          return self::STATE_UNKNOWN;
      }

       return version_compare($installed, $latest, '<') ? self::STATE_UPDATE_AVAILABLE : self::STATE_UP_TO_DATE;
   }

    /**
     * Numéro de version d'une étiquette (« v1.2.3 » donne « 1.2.3 »), ou null si ce n'en est pas un.
     */
   public static function normalize(string $tag): ?string {
       $tag = trim($tag);
      if (preg_match('/^v?(\d+(?:\.\d+){0,3}(?:-[0-9A-Za-z.]+)?)$/i', $tag, $m) !== 1) {
          return null;
      }

       return $m[1];
   }

    /** Clé de l'état dans le cache de GLPI. */
   public static function cacheKey(string $pluginKey): string {
       return 'plugin_' . $pluginKey . '_version_check';
   }

    /** Désinstallation : retire l'état du cache de GLPI. */
   public static function uninstall(string $pluginKey): void {
       global $GLPI_CACHE;

      try {
          $GLPI_CACHE?->delete(self::cacheKey($pluginKey));
      } catch (\Throwable) {
          // Cache indisponible : l'entrée expirera d'elle-même.
          return;
      }
   }

    /**
     * @return array<string, mixed>
     */
   private function loadState(): array {
      try {
          $state = ($this->load)();
      } catch (\Throwable) {
          return [];
      }

       return is_array($state) ? $state : [];
   }

    /**
     * Une vérification : met à jour l'état (dernier résultat réussi conservé en cas d'échec).
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
   private function refresh(array $state, int $now): array {
       $state['checked_at'] = $now;
       $headers = [
           'Accept'               => 'application/vnd.github+json',
           'X-GitHub-Api-Version' => '2022-11-28',
           'User-Agent'           => 'GLPI-plugin-' . $this->pluginKey,
       ];
       $known = isset($state['latest']) && is_string($state['latest']);
       if ($known && !empty($state['etag']) && is_string($state['etag'])) {
           $headers['If-None-Match'] = $state['etag'];
       }

       try {
           $response = ($this->fetch)(
               'https://api.github.com/repos/' . $this->repository . '/releases/latest',
               $headers,
               self::TIMEOUT
           );
       } catch (\Throwable $e) {
           return $this->failed($state, $now, self::isTimeout($e) ? self::REASON_TIMEOUT : self::REASON_UNREACHABLE);
       }

       $status = (int) ($response['status'] ?? 0);
       $responseHeaders = array_change_key_case((array) ($response['headers'] ?? []), CASE_LOWER);
       if ($status === 304 && $known) {
           return $this->succeeded($state, $now);
       }
       // GitHub répond 403 (limite principale, X-RateLimit-Remaining: 0) ou 429 (limite secondaire).
       if ($status === 403 || $status === 429) {
           return $this->failed($state, $now, self::REASON_RATE_LIMITED);
       }
       if ($status === 404) {
           return $this->failed($state, $now, self::REASON_NO_RELEASE);
       }
       if ($status !== 200) {
           return $this->failed($state, $now, self::REASON_INVALID_RESPONSE);
       }

       $data = json_decode((string) ($response['body'] ?? ''), true);
       $latest = is_array($data) && is_string($data['tag_name'] ?? null) ? self::normalize($data['tag_name']) : null;
       if ($latest === null) {
           return $this->failed($state, $now, self::REASON_INVALID_RESPONSE);
       }
       $state['latest'] = $latest;
       $state['release_url'] = self::githubUrl($data['html_url'] ?? null);
       $state['download_url'] = self::downloadUrl($data['assets'] ?? null);
       $etag = (string) ($responseHeaders['etag'] ?? '');
       $state['etag'] = $etag !== '' && strlen($etag) <= 200 ? $etag : null;

       return $this->succeeded($state, $now);
   }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
   private function succeeded(array $state, int $now): array {
       $state['reason'] = null;
       $state['last_success'] = $now;
       $state['next_check'] = $now + self::CACHE_TTL;

       return $state;
   }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
   private function failed(array $state, int $now, string $reason): array {
       $state['reason'] = $reason;
       $state['next_check'] = $now + self::RETRY_AFTER_FAILURE;

       return $state;
   }

    /**
     * @param array<string, mixed> $state
     * @return array{
     *     installed: string, latest: string|null, state: string, reason: string|null, stale: bool,
     *     release_url: string, download_url: string|null, last_success: string|null, checked_at: string|null,
     * }
     */
   private function build(array $state, ?string $reason): array {
       $now = ($this->now)();
       $lastSuccess = is_int($state['last_success'] ?? null) ? $state['last_success'] : null;
       $latest = is_string($state['latest'] ?? null) ? $state['latest'] : null;
       // Après un échec, le dernier résultat réussi reste affiché tant qu'il n'est pas trop ancien.
       $usable = $latest !== null
           && $reason !== self::REASON_DISABLED
           && ($reason === null || ($lastSuccess !== null && $now - $lastSuccess <= self::STALE_MAX_AGE));
       $checkedAt = is_int($state['checked_at'] ?? null) ? $state['checked_at'] : null;

       return [
           'installed'    => $this->installedVersion,
           'latest'       => $usable ? $latest : null,
           'state'        => $usable ? self::compare($this->installedVersion, (string) $latest) : self::STATE_UNKNOWN,
           'reason'       => $reason,
           'stale'        => $usable && $reason !== null,
           'release_url'  => $usable && is_string($state['release_url'] ?? null)
               ? $state['release_url']
               : 'https://github.com/' . $this->repository . '/releases/latest',
           'download_url' => $usable && is_string($state['download_url'] ?? null) ? $state['download_url'] : null,
           'last_success' => $lastSuccess !== null ? date('Y-m-d H:i:s', $lastSuccess) : null,
           'checked_at'   => $checkedAt !== null ? date('Y-m-d H:i:s', $checkedAt) : null,
       ];
   }

    /** Adresse https://github.com/... de la réponse, sinon null (jamais un lien vers un autre site). */
   private static function githubUrl(mixed $url): ?string {
       return is_string($url) && str_starts_with($url, 'https://github.com/') && strlen($url) <= 500
           && preg_match('/[\s"<>]/', $url) !== 1 ? $url : null;
   }

    /** Archive du plugin jointe à la release (.tar.bz2, .tar.gz, .tgz ou .zip). */
   private static function downloadUrl(mixed $assets): ?string {
      if (!is_array($assets)) {
          return null;
      }
      foreach ($assets as $asset) {
          $url = is_array($asset) ? self::githubUrl($asset['browser_download_url'] ?? null) : null;
         if ($url !== null && preg_match('/\.(tar\.bz2|tar\.gz|tgz|zip)$/i', $url) === 1) {
             return $url;
         }
      }

       return null;
   }

    /** Délai dépassé : messages de cURL (erreur 28), de Guzzle et du client HTTP de Symfony. */
   private static function isTimeout(\Throwable $e): bool {
      for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
         if (preg_match('/timed?[ -]?out|max duration|cURL error 28/i', $cursor->getMessage()) === 1) {
            return true;
         }
      }

       return false;
   }
}
