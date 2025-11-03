<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Davis Zalitis (k0d3r1s)
 * (c) SIA Valksor <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Valksor\Component\Sse\Tests;

use FilesystemIterator;
use JsonException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Valksor\Component\Sse\Helper;

use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @covers \Valksor\Component\Sse\Helper
 */
final class HelperTest extends TestCase
{
    public function testEnsureDirectoryCreatesMissingPath(): void
    {
        $baseDir = sys_get_temp_dir() . '/valksor_helper_' . uniqid('', true);
        $targetDir = $baseDir . '/nested/path';

        $helper = new HelperAdapter();
        $helper->ensureDirectory($targetDir);

        self::assertDirectoryExists($targetDir);

        $this->removeDirectory($baseDir);
    }

    /**
     * @throws JsonException
     */
    public function testJsonEncodeDecodeRoundTrip(): void
    {
        $payload = [
            'event' => 'reload',
            'files' => ['app.js', 'style.css'],
            'meta' => ['timestamp' => 1234567890],
        ];

        $helper = new HelperAdapter();
        $encoded = $helper->jsonEncode($payload);
        $decoded = $helper->jsonDecode($encoded);

        self::assertSame($payload, $decoded);
    }

    public function testSetServiceIoInjectsWhenServiceSupportsIt(): void
    {
        $helper = new HelperAdapter();
        $service = new ServiceWithIo();
        $io = $this->createSymfonyStyle();

        $helper->injectIo($service, $io);

        self::assertSame($io, $service->io);
    }

    public function testSetServiceIoSkipsWhenServiceDoesNotSupportIt(): void
    {
        $helper = new HelperAdapter();
        $service = new ServiceWithoutIo();
        $io = $this->createSymfonyStyle();

        // Should not throw even though service does not implement setIo()
        $helper->injectIo($service, $io);

        self::assertTrue(true);
    }

    private function createSymfonyStyle(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    private function removeDirectory(
        string $path,
    ): void {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}

/**
 * @internal
 *
 * Lightweight helper adapter exposing the trait for testing purposes
 */
final class HelperAdapter
{
    use Helper;

    public function injectIo(
        object $service,
        SymfonyStyle $io,
    ): void {
        $this->setServiceIo($service, $io);
    }
}

/**
 * @internal
 */
final class ServiceWithIo
{
    public ?SymfonyStyle $io = null;

    public function setIo(
        SymfonyStyle $io,
    ): void {
        $this->io = $io;
    }
}

/**
 * @internal
 */
final class ServiceWithoutIo
{
}
