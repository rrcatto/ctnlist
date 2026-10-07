<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Command\DiagnoseCommand;
use PHPUnit\Framework\TestCase;

/** Removed settings stay removed: nothing reads them, the templates do not offer them, and the two warning lists agree. */
final class ObsoleteSettingsTest extends TestCase
{
    public function testNothingReadsOrOffersAnObsoleteSetting(): void
    {
        $root = dirname(__DIR__, 2);
        // Documentation may record when a setting was removed (release history); code, templates and the env templates may not use it.
        $files = [$root . '/.env.example', $root . '/dev/podman/ctnlist.env.dist'];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with((string) $file, '.php') && !str_ends_with((string) $file, 'DiagnoseCommand.php')) {
                $files[] = (string) $file;
            }
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/templates', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = (string) $file;
        }
        foreach (DiagnoseCommand::OBSOLETE as $name) {
            foreach ($files as $file) {
                self::assertStringNotContainsString($name, (string) file_get_contents($file), $name . ' in ' . substr($file, strlen($root) + 1));
            }
        }
    }

    public function testBinDevWarnsAboutTheSameSettings(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/dev');
        $list = preg_match('/^OBSOLETE_ENV="([^"]*)"/m', $script, $m) === 1 ? $m[1] : self::fail('OBSOLETE_ENV not found in bin/dev');
        self::assertSame(DiagnoseCommand::OBSOLETE, explode(' ', $list));
    }
}
