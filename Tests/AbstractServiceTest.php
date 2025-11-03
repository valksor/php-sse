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
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Valksor\Component\Sse\Service\AbstractService;

use function file_put_contents;
use function getmypid;
use function is_dir;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @covers \Valksor\Component\Sse\Service\AbstractService
 */
final class AbstractServiceTest extends TestCase
{
    private ParameterBag $parameterBag;
    private string $projectDir;

    public function testIsProcessRunningRemovesInvalidPidFile(): void
    {
        $service = $this->createService();
        $pidFile = $service->createPidFilePath(TestService::getServiceName());
        file_put_contents($pidFile, 'not-a-pid');

        self::assertFalse($service->isProcessRunning(TestService::getServiceName()));
        self::assertFalse(is_file($pidFile));
    }

    public function testIsProcessRunningReturnsTrueForExistingProcess(): void
    {
        $service = $this->createService();
        $pidFile = $service->createPidFilePath(TestService::getServiceName());
        file_put_contents($pidFile, (string) getmypid());

        self::assertTrue($service->isProcessRunning(TestService::getServiceName()));
    }

    public function testPReadsValuesWithNamespacePrefix(): void
    {
        $service = $this->createService();

        self::assertSame('expected-value', $service->p('test.value'));
    }

    public function testParseCommaSeparatedList(): void
    {
        $result = $this->createService()->parseCommaSeparatedList(' foo, bar , ,baz ,,');

        self::assertCount(3, $result);
        self::assertSame(['foo', 'bar', 'baz'], array_values($result));
    }

    public function testRemovePidFileDeletesExistingFile(): void
    {
        $service = $this->createService();
        $pidFile = $service->createPidFilePath(TestService::getServiceName());
        file_put_contents($pidFile, '123');

        $service->removePidFile();

        self::assertFalse(is_file($pidFile));
    }

    public function testStartWithLifecycleCreatesPidFileAndCleansUp(): void
    {
        $service = $this->createService();
        $service->processesToKill = ['test-service'];

        [$io] = $this->createIo();
        $exitCode = $service->startWithLifecycle($io);

        self::assertSame([TestService::EVENT_KILL_CONFLICTS, TestService::EVENT_START], $service->events);
        self::assertTrue($service->pidFileExistsDuringStart);
        self::assertNotNull($service->pidFilePath);
        self::assertFalse(is_file($service->pidFilePath));
        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testStartWithLifecycleHandlesFailuresAndRemovesPidFile(): void
    {
        $service = new FailingService($this->parameterBag);
        [$io, $output] = $this->createIo();

        $exitCode = $service->startWithLifecycle($io);
        $outputContent = $output->fetch();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Service failing-service failed: start failure', $outputContent);
        self::assertNotNull($service->pidFilePath);
        self::assertFalse(is_file($service->pidFilePath));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir() . '/valksor_service_' . uniqid('', true);

        if (!is_dir($this->projectDir) && !@mkdir($this->projectDir, recursive: true)) {
            throw new RuntimeException('Unable to create temporary project directory');
        }

        $this->parameterBag = new ParameterBag([
            'kernel.project_dir' => $this->projectDir,
            'valksor.test.value' => 'expected-value',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
        parent::tearDown();
    }

    /**
     * @return array{SymfonyStyle, BufferedOutput}
     */
    private function createIo(): array
    {
        $output = new BufferedOutput();

        return [new SymfonyStyle(new ArrayInput([]), $output), $output];
    }

    private function createService(): TestService
    {
        return new TestService($this->parameterBag);
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
 * Tailored concrete service for exercising AbstractService behaviour
 */
final class TestService extends AbstractService
{
    public const string EVENT_KILL_CONFLICTS = 'kill_conflicts';

    public const string EVENT_START = 'start';

    /** @var list<string> */
    public array $events = [];

    public ?bool $pidFileExistsDuringStart = null;

    public ?string $pidFilePath = null;

    /** @var list<string> */
    public array $processesToKill = [];

    public function killConflictingSseProcesses(
        SymfonyStyle $io,
    ): void {
        $this->events[] = self::EVENT_KILL_CONFLICTS;
        $this->processesToKill = $this->processesToKill ?: [];
        parent::killConflictingSseProcesses($io);
    }

    public function start(
        array $config = [],
    ): int {
        $this->pidFilePath = $this->createPidFilePath(self::getServiceName());
        $this->pidFileExistsDuringStart = is_file($this->pidFilePath);
        $this->events[] = self::EVENT_START;

        return Command::SUCCESS;
    }

    public static function getServiceName(): string
    {
        return 'test-service';
    }

    protected function getSseProcessesToKill(): array
    {
        return $this->processesToKill;
    }
}

/**
 * @internal
 *
 * Concrete service that simulates a startup failure
 */
final class FailingService extends AbstractService
{
    public ?string $pidFilePath = null;

    public function start(
        array $config = [],
    ): int {
        $this->pidFilePath = $this->createPidFilePath(self::getServiceName());

        throw new RuntimeException('start failure');
    }

    public static function getServiceName(): string
    {
        return 'failing-service';
    }
}
