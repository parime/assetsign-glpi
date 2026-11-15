<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Compat\Http;

/**
 * Issue #347 : point de passage unique des appels HTTP sortants (Compat\Http). Schemas autres que
 * http(s) et adresses internes refuses avant tout appel reseau ; redirection jamais suivie et
 * en-tetes d'authentification GLPI jamais transmis, verifies contre un petit serveur local
 * (php -S) qui journalise chaque requete recue.
 */
final class HttpTest extends AssetsignTestCase
{
    /** @var resource|null */
   private $server = null;
   private string $workDir = '';

   protected function tearDown(): void {
      if (is_resource($this->server)) {
          proc_terminate($this->server);
          proc_close($this->server);
      }
      if ($this->workDir !== '' && is_dir($this->workDir)) {
         foreach (glob($this->workDir . '/*') ?: [] as $file) {
             unlink($file);
         }
          rmdir($this->workDir);
      }
       parent::tearDown();
   }

   public function testSchemeOtherThanHttpIsRefused(): void {
      foreach (['ftp://example.com/x', 'file:///etc/passwd', 'gopher://example.com/', 'javascript:alert(1)', 'dict://example.com:11211/', 'pas une adresse'] as $url) {
          $this->assertSame(Http::ERROR_SCHEME, Http::checkUrl($url, false, true), $url);
          $error = null;
          $this->assertNull(Http::request('GET', $url, [], $error), $url);
          $this->assertSame(Http::ERROR_SCHEME, $error, $url);
      }
       $this->assertNull(Http::checkUrl('https://93.184.215.14/', false, false));
       $this->assertNull(Http::checkUrl('http://8.8.8.8/', false, false));
   }

   public function testInternalAddressIsRefused(): void {
       $internal = [
           'http://127.0.0.1/', 'http://127.1.2.3:8080/', 'https://10.0.0.5/hook', 'http://172.16.0.1/',
           'http://192.168.1.10/', 'http://169.254.169.254/latest/meta-data/', 'http://100.64.0.1/',
           'http://0.0.0.0/', 'http://224.0.0.1/', 'http://[::1]/', 'http://[fe80::1]/', 'http://[fd00::1]/',
           'http://[::ffff:127.0.0.1]/', 'http://[::ffff:169.254.169.254]/', 'http://localhost/',
           'http://LOCALHOST./', 'http://api.localhost/', 'http://nas.local/',
       ];
      foreach ($internal as $url) {
          $this->assertSame(Http::ERROR_INTERNAL, Http::checkUrl($url, false, false), $url);
      }
       // Ecritures numeriques de 127.0.0.1 : refusees comme adresse invalide.
      foreach (['http://2130706433/', 'http://0x7f.1/', 'http://017700000001/', 'http://127.1/'] as $url) {
          $this->assertNotNull(Http::checkUrl($url, false, false), $url);
      }
       // Autorisees explicitement par l'administrateur.
       $this->assertNull(Http::checkUrl('http://127.0.0.1/', false, true));
       $this->assertNull(Http::checkUrl('https://10.0.0.5/hook', false, true));

       // Par defaut (reglage absent), request() refuse sans rien envoyer.
       Http::setAllowPrivateNetworks(false);
       $this->assertFalse(Http::allowsPrivateNetworks());
       $error = null;
       $this->assertNull(Http::request('GET', 'http://169.254.169.254/latest/meta-data/', [], $error));
       $this->assertSame(Http::ERROR_INTERNAL, $error);
       $this->assertFalse(Http::postJson('http://127.0.0.1:9/hook', ['text' => 'x'], $error));
       $this->assertSame(Http::ERROR_INTERNAL, $error);

       Http::setAllowPrivateNetworks(true);
       $this->assertTrue(Http::allowsPrivateNetworks());
   }

   public function testIsInternalIp(): void {
      foreach (['127.0.0.1', '10.1.2.3', '192.168.0.1', '172.31.255.255', '169.254.169.254', '::1', 'fe80::1', 'fc00::1', '::ffff:10.0.0.1', 'ff02::1', 'nimportequoi'] as $ip) {
          $this->assertTrue(Http::isInternalIp($ip), $ip);
      }
      foreach (['8.8.8.8', '93.184.215.14', '2606:4700:4700::1111'] as $ip) {
          $this->assertFalse(Http::isInternalIp($ip), $ip);
      }
   }

   public function testRedirectIsNotFollowedAndGlpiTokensAreNeverSent(): void {
       $port = $this->startServer();
       Http::setAllowPrivateNetworks(true);
       $glpi12 = class_exists(\Glpi\Toolbox\HttpClient::class);

       $error = null;
       $this->assertNull(Http::request('GET', "http://127.0.0.1:$port/redirect", [], $error));
      if ($glpi12 && !Http::glpiAllowsPrivateNetworks()) {
          // GLPI 12 : le cœur refuse aussi les reseaux prives tant que la constante ne cite pas le plugin.
          $this->assertSame(Http::ERROR_GLPI_CONTEXT, $error);
      } else {
          $this->assertStringStartsWith(Http::ERROR_REDIRECT, (string) $error);
          $this->assertSame(['/redirect'], array_column($this->requests(), 'uri'), 'La cible de la redirection n\'est jamais appelée.');

          $response = Http::request('GET', "http://127.0.0.1:$port/ok", ['headers' => [
              'Session-Token' => 'secret-session', 'App-Token' => 'secret-app', 'Cookie' => 'glpi=1', 'X-Test' => 'kept',
          ]], $error);
          $this->assertNotNull($response, (string) $error);
          $this->assertSame('ok', $response['body']);
          $requests = $this->requests();
          $last = end($requests);
          $headers = array_change_key_case((array) ($last['headers'] ?? []));
          $this->assertSame('kept', $headers['x-test'] ?? null);
          $this->assertArrayNotHasKey('session-token', $headers);
          $this->assertArrayNotHasKey('app-token', $headers);
          $this->assertArrayNotHasKey('cookie', $headers);
          $this->assertStringStartsWith('GLPI-assetsign/', (string) ($headers['user-agent'] ?? ''));
      }
       $this->assertNotContains('/target', array_column($this->requests(), 'uri'));
   }

    /**
     * Petit serveur HTTP local : /redirect renvoie une redirection 302 vers /target, le reste
     * repond « ok ». Chaque requete est journalisee (chemin et en-tetes).
     */
   private function startServer(): int {
       global $CFG_GLPI;
      if (!function_exists('proc_open') || !empty($CFG_GLPI['proxy_name'])) {
          $this->markTestSkipped('proc_open indisponible ou proxy configuré.');
      }
       $this->workDir = sys_get_temp_dir() . '/assetsign-http-' . bin2hex(random_bytes(4));
       mkdir($this->workDir);
       $socket = stream_socket_server('tcp://127.0.0.1:0');
      if ($socket === false) {
          $this->markTestSkipped('Aucun port local libre.');
      }
       $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
       fclose($socket);

       file_put_contents($this->workDir . '/router.php', <<<'PHP'
<?php
$log = __DIR__ . '/requests.log';
file_put_contents($log, json_encode(['uri' => $_SERVER['REQUEST_URI'], 'headers' => getallheaders()]) . "\n", FILE_APPEND);
if ($_SERVER['REQUEST_URI'] === '/redirect') {
    header('Location: http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/target', true, 302);
    return true;
}
echo 'ok';
return true;
PHP);
       $this->server = proc_open(
           [PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->workDir . '/router.php'],
           [0 => ['pipe', 'r'], 1 => ['file', $this->workDir . '/out.log', 'a'], 2 => ['file', $this->workDir . '/out.log', 'a']],
           $pipes
       );
       for ($i = 0; $i < 50; $i++) {
           $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
         if ($probe !== false) {
             fclose($probe);
             return $port;
         }
           usleep(100000);
       }
       $this->markTestSkipped('Le serveur PHP local n\'a pas démarré.');
   }

    /** @return list<array{uri: string, headers: array<string, string>}> */
   private function requests(): array {
       $file = $this->workDir . '/requests.log';
      if (!is_file($file)) {
          return [];
      }
       return array_values(array_map(
           static fn(string $line): array => json_decode($line, true),
           array_filter(explode("\n", (string) file_get_contents($file)))
       ));
   }
}
