<?php

declare(strict_types=1);

namespace App\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Yaml\Yaml;

/**
 * Left to Symfony, the logger keeps errors only, so the notices and warnings
 * README "Logs" promises (recovery actions, settings changes, catto-mail
 * failures) would never be written.
 */
final class ApplicationLogTest extends TestCase
{
    public function testTheApplicationLogKeepsNoticesOutsideTests(): void
    {
        /** @var array{parameters: array<string, mixed>, services: array<string, mixed>} $config */
        $config = Yaml::parseFile(dirname(__DIR__, 3) . '/config/services.yaml');
        self::assertSame('notice', $config['parameters']['app.log_level']);
        self::assertSame(['class' => Logger::class, 'arguments' => ['%app.log_level%', null, null, '@request_stack']], $config['services']['logger']);

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $logger = new Logger('notice', $stream);
        $logger->notice('recovery action recorded');
        $logger->info('request details dropped');
        rewind($stream);
        $written = (string) stream_get_contents($stream);
        self::assertStringContainsString('recovery action recorded', $written);
        self::assertStringNotContainsString('request details dropped', $written);
    }
}
