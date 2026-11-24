<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Paymenter loads extension classes with a plain PSR-4 autoloader: one class per file, named after the class.
 * The test setup preloads files through composer.json, which once hid a class living in a shared file
 * ("Class RegisterRequest not found" on the first real registration). This fails if any shipped file would not
 * be found the way Paymenter looks for it.
 */
class AutoloadTest extends TestCase
{
    private const NAMESPACE_ROOT = 'Paymenter\\Extensions\\Servers\\ResellerClub\\';

    /** @return array<string,string> relative path => file contents, for every shipped PHP file */
    private function shippedFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (!str_ends_with($rel, '.php') || str_starts_with($rel, 'vendor/') || str_starts_with($rel, 'tests/')
                || str_starts_with($rel, 'database/') || str_starts_with($rel, 'routes/') || str_starts_with($rel, 'resources/')) {
                continue;
            }
            $out[$rel] = file_get_contents($file->getPathname());
        }

        return $out;
    }

    public function test_every_class_is_alone_in_a_file_named_after_it(): void
    {
        $checked = 0;
        foreach ($this->shippedFiles() as $rel => $code) {
            preg_match_all('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $code, $m, PREG_SET_ORDER);
            // Anonymous classes ("new class") never match: the pattern needs a name after the keyword.
            if (!$m) {
                continue;
            }
            $names = array_column($m, 2);
            $this->assertCount(1, $names, "$rel declares several types (" . implode(', ', $names) . '): move each to its own file');
            $this->assertSame(basename($rel, '.php'), $names[0], "$rel must be named after the type it declares ({$names[0]})");
            $checked++;
        }
        $this->assertGreaterThan(20, $checked, 'the scan found suspiciously few files');
    }

    public function test_every_internal_import_points_at_a_file_that_exists(): void
    {
        $root = dirname(__DIR__, 2);
        $count = 0;
        foreach ($this->shippedFiles() as $rel => $code) {
            preg_match_all('/^use\s+' . preg_quote(self::NAMESPACE_ROOT, '/') . '([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $code, $m);
            foreach ($m[1] as $import) {
                $this->assertFileExists($root . '/' . str_replace('\\', '/', $import) . '.php', "$rel imports $import, which has no file");
                $count++;
            }
        }
        $this->assertGreaterThan(20, $count);
    }

    public function test_the_namespace_matches_the_folder(): void
    {
        foreach ($this->shippedFiles() as $rel => $code) {
            if (!preg_match('/^namespace\s+([^;]+);/m', $code, $m)) {
                continue;
            }
            $dir = dirname($rel);
            $expected = rtrim(self::NAMESPACE_ROOT . ($dir === '.' ? '' : str_replace('/', '\\', $dir)), '\\');
            $this->assertSame($expected, $m[1], "$rel has namespace {$m[1]} but PSR-4 expects $expected");
        }
    }
}
