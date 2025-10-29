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

namespace Valksor\Component\Sse\Service;

use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Valksor\Bundle\ValksorBundle;
use Valksor\Component\Sse\Helper;

use function array_filter;
use function array_map;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function is_file;
use function is_numeric;
use function posix_kill;
use function sleep;
use function sprintf;
use function trim;
use function unlink;
use function usleep;

use const SIGKILL;
use const SIGTERM;

abstract class AbstractService implements ServiceInterface
{
    use Helper;

    public SymfonyStyle $io;
    protected string $projectDir;
    protected bool $running = false;
    protected bool $shouldReload = false;
    protected bool $shouldShutdown = false;

    public function __construct(
        protected ParameterBagInterface $parameterBag,
    ) {
        $this->projectDir = $parameterBag->get('kernel.project_dir');
    }

    abstract public static function getServiceName(): string;

    public function createPidFilePath(
        string $serviceName,
    ): string {
        $path = $this->projectDir . '/var/run/';
        $this->ensureDirectory($path);

        return $path . 'valksor-' . $serviceName . '.pid';
    }

    public function getIo(): SymfonyStyle
    {
        return $this->io;
    }

    public function isProcessRunning(
        string $serviceName,
    ): bool {
        $pidFile = $this->createPidFilePath($serviceName);

        if (!is_file($pidFile)) {
            return false;
        }

        $previousPid = trim(file_get_contents($pidFile));

        if (!is_numeric($previousPid)) {
            @unlink($pidFile);

            return false;
        }

        return posix_kill((int) $previousPid, 0);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function killConflictingSseProcesses(
        SymfonyStyle $io,
    ): void {
        foreach ($this->getSseProcessesToKill() as $serviceName) {
            if ($this->isProcessRunning($serviceName)) {
                $this->killPreviousProcess($serviceName, $io);
            }
        }
    }

    public function killPreviousProcess(
        string $serviceName,
        SymfonyStyle $io,
    ): void {
        $pidFile = $this->createPidFilePath($serviceName);

        if (!is_file($pidFile)) {
            return;
        }

        $previousPid = trim(file_get_contents($pidFile));

        if (!is_numeric($previousPid)) {
            $io->warning('[valksor] invalid PID file found, removing it...');
            @unlink($pidFile);

            return;
        }

        $previousPid = (int) $previousPid;

        if (!posix_kill($previousPid, 0)) {
            $io->text('[valksor] removing stale PID file...');
            @unlink($pidFile);

            return;
        }

        $io->warning(sprintf('[valksor] previous %s process found (PID %d), terminating it...', $serviceName, $previousPid));

        if (posix_kill($previousPid, SIGTERM)) {
            $timeout = 3;
            $waitTime = 0;
            $sleepInterval = 500000;

            while ($waitTime < $timeout) {
                if (!posix_kill($previousPid, 0)) {
                    $io->success(sprintf('[valksor] previous %s process (PID %d) terminated successfully.', $serviceName, $previousPid));
                    sleep(1);

                    return;
                }

                usleep($sleepInterval);
                $waitTime += 0.5;
            }

            $io->warning(sprintf('[valksor] previous process did not terminate gracefully, force killing %d...', $previousPid));
            posix_kill($previousPid, SIGKILL);
            sleep(1);
        } else {
            $io->error(sprintf('[valksor] failed to terminate previous %s process (PID %d). You may need to kill it manually.', $serviceName, $previousPid));
        }

        @unlink($pidFile);
    }

    public function p(
        string $name,
    ): mixed {
        return $this->parameterBag->get(sprintf('%s.%s', ValksorBundle::VALKSOR, $name));
    }

    public function parseCommaSeparatedList(
        string $input,
    ): array {
        return array_filter(array_map('trim', explode(',', $input)));
    }

    public function reload(): void
    {
        $this->shouldReload = true;
    }

    public function removePidFile(): void
    {
        $pidFile = $this->createPidFilePath(static::getServiceName());

        if (is_file($pidFile)) {
            @unlink($pidFile);
        }
    }

    public function setIo(
        SymfonyStyle $io,
    ): static {
        $this->io = $io;

        return $this;
    }

    /**
     * Start service with full lifecycle management (PID file, error handling, cleanup).
     *
     * @param SymfonyStyle $io The console IO for output
     *
     * @return int Exit code from the service start method
     */
    public function startWithLifecycle(
        SymfonyStyle $io,
    ): int {
        $this->setIo($io);

        // Kill any conflicting processes before starting
        $this->killConflictingSseProcesses($io);

        // Set up PID file
        $this->createPidFilePath(static::getServiceName());
        $this->writePidFile();

        try {
            $exitCode = $this->start();
            $this->removePidFile();

            return $exitCode;
        } catch (Exception $e) {
            $this->removePidFile();
            $io->error(sprintf('Service %s failed: %s', static::getServiceName(), $e->getMessage()));

            return 1;
        }
    }

    public function stop(): void
    {
        $this->shouldShutdown = true;
        $this->running = false;
    }

    public function writePidFile(): void
    {
        file_put_contents($this->createPidFilePath(static::getServiceName()), (string) getmypid());
    }

    protected function getSseProcessesToKill(): array
    {
        return [];
    }
}
