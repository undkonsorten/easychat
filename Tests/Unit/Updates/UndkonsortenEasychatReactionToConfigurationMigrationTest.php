<?php

declare(strict_types=1);

namespace Undkonsorten\Easychat\Tests\Unit\Updates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Undkonsorten\Easychat\Updates\UndkonsortenEasychatReactionToConfigurationMigration;

final class UndkonsortenEasychatReactionToConfigurationMigrationTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function reactionUrls(): iterable
    {
        yield 'path only, as the Readme recommended' => ['/typo3/reaction/afce5efb-861e-4e0e-8a8b-d159f194670d', 'afce5efb-861e-4e0e-8a8b-d159f194670d'];
        yield 'full URL' => ['https://my-domain.com/typo3/reaction/AFCE5EFB-861E-4E0E-8A8B-D159F194670D', 'afce5efb-861e-4e0e-8a8b-d159f194670d'];
        yield 'with whitespace' => ["  /typo3/reaction/afce5efb-861e-4e0e-8a8b-d159f194670d \n", 'afce5efb-861e-4e0e-8a8b-d159f194670d'];
        yield 'no identifier' => ['/typo3/reaction/', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('reactionUrls')]
    public function testExtractsTheReactionIdentifierFromTheUrl(string $url, ?string $expected): void
    {
        self::assertSame($expected, UndkonsortenEasychatReactionToConfigurationMigration::extractReactionIdentifier($url));
    }

    public function testReplacesUrlAndSecretWithTheConfigurationAndKeepsEverythingElse(): void
    {
        $flexForm = [
            'data' => [
                'sDEF' => [
                    'lDEF' => [
                        'settings.url' => ['vDEF' => '/typo3/reaction/afce5efb-861e-4e0e-8a8b-d159f194670d'],
                        'settings.apiKey' => ['vDEF' => 'secret'],
                        'settings.introMessage' => ['vDEF' => 'Hello!'],
                        'settings.enableDataProtectionConsent' => ['vDEF' => 'consent'],
                        'settings.cookieExpirationMinutes' => ['vDEF' => '1440'],
                    ],
                ],
            ],
            'meta' => [],
        ];

        $migrated = UndkonsortenEasychatReactionToConfigurationMigration::migrateFlexFormArray($flexForm, 5);
        $fields = $migrated['data']['sDEF']['lDEF'];

        self::assertSame(['vDEF' => '5'], $fields['settings.configuration']);
        self::assertArrayNotHasKey('settings.url', $fields);
        self::assertArrayNotHasKey('settings.apiKey', $fields);
        self::assertSame('Hello!', $fields['settings.introMessage']['vDEF']);
        self::assertSame('consent', $fields['settings.enableDataProtectionConsent']['vDEF']);
        self::assertSame('1440', $fields['settings.cookieExpirationMinutes']['vDEF']);
        self::assertSame([], $migrated['meta']);
    }
}
