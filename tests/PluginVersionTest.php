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

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\PluginVersion;
use PHPUnit\Framework\TestCase;

/**
 * Issue #396: common "Plugin version" component (parime/glpi-plugin-template#12):
 * version comparison, 12-hour cache, ETag, failures with their reason and reuse of the last
 * successful result. No GLPI, no network: the HTTP call, the storage and the clock are injected.
 * Assertions are on codes (STATE_*, REASON_*), never on translated texts.
 */
final class PluginVersionTest extends TestCase
{
    private const START = 1_700_000_000;

    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    private int $now = self::START;

    /** @var list<array{url: string, headers: array<string, string>, timeout: int}> */
    private array $calls = [];

    /** @var list<array<string, mixed>|\Throwable> */
    private array $responses = [];

    private function checker(string $installed = '1.2.0'): PluginVersion
    {
        return new PluginVersion(
            'assetsign',
            'parime/assetsign-glpi',
            $installed,
            function (string $url, array $headers, int $timeout): array {
                $this->calls[] = ['url' => $url, 'headers' => $headers, 'timeout' => $timeout];
                $next = array_shift($this->responses);
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                self::assertNotNull($next, 'Unexpected HTTP call');

                return $next;
            },
            fn (): ?array => $this->stored,
            function (array $state): void {
                $this->stored = $state;
            },
            fn (): int => $this->now,
        );
    }

    /** @return array<string, mixed> */
    private static function release(string $tag, string $etag = '"abc"'): array
    {
        return [
            'status'  => 200,
            'headers' => ['ETag' => $etag],
            'body'    => (string) json_encode([
                'tag_name' => $tag,
                'html_url' => 'https://github.com/parime/assetsign-glpi/releases/tag/' . $tag,
                'assets'   => [
                    ['browser_download_url' => 'https://github.com/parime/assetsign-glpi/releases/download/'
                        . $tag . '/checksums.txt'],
                    ['browser_download_url' => 'https://github.com/parime/assetsign-glpi/releases/download/'
                        . $tag . '/assetsign-' . $tag . '.tar.bz2'],
                ],
            ]),
        ];
    }

    public function testCompareVersions(): void
    {
        self::assertSame(PluginVersion::STATE_UP_TO_DATE, PluginVersion::compare('1.2.0', 'v1.2.0'));
        self::assertSame(PluginVersion::STATE_UPDATE_AVAILABLE, PluginVersion::compare('1.2.0', 'v1.10.0'));
        self::assertSame(PluginVersion::STATE_UPDATE_AVAILABLE, PluginVersion::compare('v1.9.9', '2.0.0'));
        self::assertSame(PluginVersion::STATE_UPDATE_AVAILABLE, PluginVersion::compare('1.2.0-beta1', '1.2.0'));
        // A development build newer than the latest release is up to date.
        self::assertSame(PluginVersion::STATE_UP_TO_DATE, PluginVersion::compare('1.3.0', '1.2.9'));
        self::assertSame(PluginVersion::STATE_UNKNOWN, PluginVersion::compare('1.2.0', 'latest'));
        self::assertSame(PluginVersion::STATE_UNKNOWN, PluginVersion::compare('dev', '1.2.0'));
    }

    public function testNormalize(): void
    {
        self::assertSame('1.2.3', PluginVersion::normalize('v1.2.3'));
        self::assertSame('1.2.3', PluginVersion::normalize(' V1.2.3 '));
        self::assertSame('2.0.0-rc.1', PluginVersion::normalize('2.0.0-rc.1'));
        foreach (['', 'v', 'release-1', '1.2.3<script>', '1..2'] as $tag) {
            self::assertNull(PluginVersion::normalize($tag), $tag);
        }
    }

    public function testInvalidParametersAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PluginVersion('assetsign', 'parime/../../evil?x=1', '1.0.0');
    }

    public function testUpdateAvailableWithLinks(): void
    {
        $this->responses = [self::release('v1.3.0')];
        $status = $this->checker()->status();

        self::assertSame(PluginVersion::STATE_UPDATE_AVAILABLE, $status['state']);
        self::assertSame('1.3.0', $status['latest']);
        self::assertNull($status['reason']);
        self::assertFalse($status['stale']);
        self::assertSame('https://github.com/parime/assetsign-glpi/releases/tag/v1.3.0', $status['release_url']);
        self::assertStringEndsWith('/assetsign-v1.3.0.tar.bz2', (string) $status['download_url']);
        self::assertSame(date('Y-m-d H:i:s', self::START), $status['last_success']);

        $call = $this->calls[0];
        self::assertSame('https://api.github.com/repos/parime/assetsign-glpi/releases/latest', $call['url']);
        self::assertSame(PluginVersion::TIMEOUT, $call['timeout']);
        self::assertSame(3, $call['timeout']);
        self::assertArrayNotHasKey('If-None-Match', $call['headers']);
        // Nothing but the request itself: no token, no instance identifier.
        self::assertArrayNotHasKey('Authorization', $call['headers']);
    }

    public function testUpToDate(): void
    {
        $this->responses = [self::release('v1.2.0')];
        self::assertSame(PluginVersion::STATE_UP_TO_DATE, $this->checker()->status()['state']);
    }

    public function testResultIsCachedForTwelveHours(): void
    {
        $this->responses = [self::release('v1.3.0')];
        $this->checker()->status();

        $this->now = self::START + PluginVersion::CACHE_TTL - 1;
        $status = $this->checker()->status();
        self::assertCount(1, $this->calls, 'No new request within 12 hours');
        self::assertSame('1.3.0', $status['latest']);
        self::assertSame(12 * 3600, PluginVersion::CACHE_TTL);
    }

    public function testEtagIsSentAndNotModifiedKeepsTheResult(): void
    {
        $this->responses = [self::release('v1.3.0', '"etag-1"'), ['status' => 304, 'headers' => [], 'body' => '']];
        $this->checker()->status();

        $this->now = self::START + PluginVersion::CACHE_TTL;
        $status = $this->checker()->status();
        self::assertCount(2, $this->calls);
        self::assertSame('"etag-1"', $this->calls[1]['headers']['If-None-Match']);
        self::assertSame(PluginVersion::STATE_UPDATE_AVAILABLE, $status['state']);
        self::assertSame('1.3.0', $status['latest']);
        self::assertNull($status['reason']);
        self::assertSame(date('Y-m-d H:i:s', $this->now), $status['last_success']);
    }

    public function testFailureWithoutPreviousResultGivesTheReason(): void
    {
        $this->responses = [new \RuntimeException('cURL error 6: Could not resolve host: api.github.com')];
        $status = $this->checker()->status();

        self::assertSame(PluginVersion::STATE_UNKNOWN, $status['state']);
        self::assertSame(PluginVersion::REASON_UNREACHABLE, $status['reason']);
        self::assertNull($status['latest']);
        self::assertNull($status['last_success']);
        self::assertSame('https://github.com/parime/assetsign-glpi/releases/latest', $status['release_url']);
    }

    public function testTimeoutIsRecognised(): void
    {
        $messages = [
            'cURL error 28: Operation timed out after 3001 milliseconds',
            'Idle timeout reached for "https://api.github.com/repos/parime/assetsign-glpi/releases/latest".',
            'Max duration was reached for "https://api.github.com/".',
        ];
        foreach ($messages as $message) {
            $this->stored = null;
            $this->responses = [new \RuntimeException('wrapper', 0, new \RuntimeException($message))];
            self::assertSame(PluginVersion::REASON_TIMEOUT, $this->checker()->status()['reason'], $message);
        }
    }

    public function testHttpErrorsGiveTheirReason(): void
    {
        $cases = [
            [403, ['X-RateLimit-Remaining' => '0'], PluginVersion::REASON_RATE_LIMITED],
            [429, [], PluginVersion::REASON_RATE_LIMITED],
            [404, [], PluginVersion::REASON_NO_RELEASE],
            [500, [], PluginVersion::REASON_INVALID_RESPONSE],
            [200, [], PluginVersion::REASON_INVALID_RESPONSE],
        ];
        foreach ($cases as [$code, $headers, $reason]) {
            $this->stored = null;
            $this->responses = [['status' => $code, 'headers' => $headers, 'body' => '{"message":"x"}']];
            $status = $this->checker()->status();
            self::assertSame($reason, $status['reason'], 'HTTP ' . $code);
            self::assertSame(PluginVersion::STATE_UNKNOWN, $status['state'], 'HTTP ' . $code);
        }
    }

    public function testFailureReusesTheLastSuccessfulResult(): void
    {
        $this->responses = [self::release('v1.3.0')];
        $this->checker()->status();

        $this->now = self::START + PluginVersion::CACHE_TTL;
        $this->responses = [['status' => 403, 'headers' => ['X-RateLimit-Remaining' => '0'], 'body' => '']];
        $status = $this->checker()->status();
        self::assertSame(PluginVersion::STATE_UPDATE_AVAILABLE, $status['state']);
        self::assertSame('1.3.0', $status['latest']);
        self::assertTrue($status['stale']);
        self::assertSame(PluginVersion::REASON_RATE_LIMITED, $status['reason']);
        self::assertSame(date('Y-m-d H:i:s', self::START), $status['last_success']);
    }

    public function testFailureIsRetriedAfterOneHourOnly(): void
    {
        $this->responses = [new \RuntimeException('Connection refused')];
        $this->checker()->status();

        $this->now = self::START + PluginVersion::RETRY_AFTER_FAILURE - 1;
        self::assertSame(PluginVersion::REASON_UNREACHABLE, $this->checker()->status()['reason']);
        self::assertCount(1, $this->calls, 'No 3-second wait on every page after a failure');

        $this->now = self::START + PluginVersion::RETRY_AFTER_FAILURE;
        $this->responses = [self::release('v1.2.0')];
        $status = $this->checker()->status();
        self::assertCount(2, $this->calls);
        self::assertSame(PluginVersion::STATE_UP_TO_DATE, $status['state']);
        self::assertNull($status['reason']);
    }

    public function testOldResultIsNoLongerShownAfterAWeekOfFailures(): void
    {
        $this->responses = [self::release('v1.3.0')];
        $this->checker()->status();

        $this->now = self::START + PluginVersion::STALE_MAX_AGE + 1;
        $this->responses = [new \RuntimeException('Connection refused')];
        $status = $this->checker()->status();
        self::assertSame(PluginVersion::STATE_UNKNOWN, $status['state']);
        self::assertNull($status['latest']);
        self::assertSame(PluginVersion::REASON_UNREACHABLE, $status['reason']);
        self::assertSame(date('Y-m-d H:i:s', self::START), $status['last_success']);
    }

    public function testDisabledCheckMakesNoRequest(): void
    {
        $status = $this->checker()->status(false);
        self::assertSame([], $this->calls);
        self::assertSame(PluginVersion::STATE_UNKNOWN, $status['state']);
        self::assertSame(PluginVersion::REASON_DISABLED, $status['reason']);
        self::assertSame('1.2.0', $status['installed']);
    }

    public function testLinksOutsideGithubAreIgnored(): void
    {
        $this->responses = [[
            'status'  => 200,
            'headers' => [],
            'body'    => (string) json_encode([
                'tag_name' => 'v2.0.0',
                'html_url' => 'https://evil.example.org/phishing',
                'assets'   => [['browser_download_url' => 'https://evil.example.org/assetsign.tar.bz2']],
            ]),
        ]];
        $status = $this->checker()->status();
        self::assertSame('https://github.com/parime/assetsign-glpi/releases/latest', $status['release_url']);
        self::assertNull($status['download_url']);
    }

    public function testBrokenStorageNeverBreaksThePage(): void
    {
        $checker = new PluginVersion(
            'assetsign',
            'parime/assetsign-glpi',
            '1.2.0',
            fn (): array => self::release('v1.2.0'),
            fn (): mixed => throw new \RuntimeException('cache down'),
            fn (array $state): mixed => throw new \RuntimeException('cache down'),
            fn (): int => self::START,
        );
        self::assertSame(PluginVersion::STATE_UP_TO_DATE, $checker->status()['state']);
    }
}
