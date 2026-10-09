<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Command\DiagnoseCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ctnlist:diagnose, Composer and the README agree on the PHP extensions ctnlist needs. */
final class PhpExtensionsTest extends TestCase
{
    public function testDiagnoseRequiresExactlyWhatComposerRequiresForAnInstallation(): void
    {
        $required = array_keys(DiagnoseCommand::REQUIRED_EXTENSIONS);
        sort($required);
        self::assertSame(self::composerExtensions(), $required);
    }

    public function testRecommendedExtensionsAreNotRequired(): void
    {
        self::assertSame([], array_values(array_intersect(array_keys(DiagnoseCommand::RECOMMENDED_EXTENSIONS), self::composerExtensions())));
    }

    /** Each required extension, if missing, is one ERROR naming it and what needs it; nothing else is reported. */
    #[DataProvider('required')]
    public function testAMissingRequiredExtensionIsAnError(string $extension): void
    {
        $findings = DiagnoseCommand::extensionFindings(static fn(string $loaded): bool => $loaded !== $extension);
        self::assertSame([['ERROR', 'PHP extension ' . $extension . ' is missing (' . DiagnoseCommand::REQUIRED_EXTENSIONS[$extension] . ')']], self::problems($findings));
    }

    public function testAMissingRecommendedExtensionIsOnlyAWarning(): void
    {
        $findings = DiagnoseCommand::extensionFindings(static fn(string $loaded): bool => $loaded !== 'intl');
        self::assertSame([['WARN', 'PHP extension intl is not loaded (recommended: international addresses and text)']], self::problems($findings));
    }

    public function testEverythingLoadedIsAllOk(): void
    {
        $findings = DiagnoseCommand::extensionFindings(static fn(string $loaded): bool => true);
        self::assertSame([], self::problems($findings));
        self::assertCount(count(DiagnoseCommand::REQUIRED_EXTENSIONS), $findings);
    }

    public function testTheReadmeRequirementsNameEveryExtension(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $line = preg_match('/^- PHP 8\.4\.1 or later .*$/m', $readme, $m) === 1 ? $m[0] : self::fail('the PHP requirement is not in README.md');
        foreach (array_keys(DiagnoseCommand::REQUIRED_EXTENSIONS + DiagnoseCommand::RECOMMENDED_EXTENSIONS) as $extension) {
            self::assertStringContainsString('`' . $extension . '`', $line, $extension . ' in the README requirements');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function required(): iterable
    {
        foreach (array_keys(DiagnoseCommand::REQUIRED_EXTENSIONS) as $extension) {
            yield $extension => [$extension];
        }
    }

    /**
     * The extensions `composer install --no-dev` insists on: ctnlist's own (composer.json) and its
     * packages' (composer.lock "packages"; the development packages are not installed in production).
     *
     * @return list<string>
     */
    private static function composerExtensions(): array
    {
        $root = dirname(__DIR__, 2);
        /** @var array{require: array<string, string>} $json */
        $json = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        /** @var array{packages: list<array{require?: array<string, string>}>} $lock */
        $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $requirements = array_keys($json['require']);
        foreach ($lock['packages'] as $package) {
            array_push($requirements, ...array_keys($package['require'] ?? []));
        }
        $extensions = array_unique(array_map(static fn(string $name): string => substr($name, 4), array_filter($requirements, static fn(string $name): bool => str_starts_with($name, 'ext-'))));
        sort($extensions);
        return $extensions;
    }

    /**
     * @param list<array{0: 'OK'|'WARN'|'ERROR', 1: string}> $findings
     * @return list<array{0: 'OK'|'WARN'|'ERROR', 1: string}>
     */
    private static function problems(array $findings): array
    {
        return array_values(array_filter($findings, static fn(array $finding): bool => $finding[0] !== 'OK'));
    }
}
