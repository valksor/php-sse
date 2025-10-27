<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s)
 * (c) SIA Valksor <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Valksor\Component\Sse\Command;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Valksor\Bundle\ValksorBundle;
use Valksor\Functions\Local\Traits\_MkDir;

use function array_filter;
use function array_map;
use function explode;
use function file_get_contents;
use function is_file;
use function is_numeric;
use function method_exists;
use function posix_kill;
use function sleep;
use function sprintf;
use function trim;
use function unlink;
use function usleep;

use const SIGKILL;
use const SIGTERM;

abstract class AbstractCommand extends Command
{
    protected string $projectDir;

    public function __construct(
        protected ParameterBagInterface $bag,
    ) {
        parent::__construct();
        $this->projectDir = $this->bag->get('kernel.project_dir');
    }

    protected function createPidFilePath(
        string $serviceName,
    ): string {
        $path = $this->projectDir . '/var/run/';
        $this->ensureDirectory($path);

        return $path . 'valksor-' . $serviceName . '.pid';
    }

    protected function createSymfonyStyle(
        InputInterface $input,
        OutputInterface $output,
    ): SymfonyStyle {
        return new SymfonyStyle($input, $output);
    }

    protected function ensureDirectory(
        string $directory,
    ): void {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _MkDir;
            };
        }

        $_helper->mkdir($directory);
    }

    /**
     * @throws ExceptionInterface
     */
    protected function executeSubCommand(
        string $commandName,
        OutputInterface $output,
        array $arguments = [],
    ): int {
        $command = $this->getApplication()?->find($commandName);

        if (!$command) {
            throw new RuntimeException("Command '$commandName' not found");
        }

        $input = new ArrayInput(['command' => $commandName] + $arguments);

        return $command->run($input, $output);
    }

    protected function getAppsDir(): string
    {
        return $this->projectDir . $this->p('project.apps_dir');
    }

    protected function getSharedDir(): string
    {
        return $this->projectDir . $this->p('project.infrastructure_dir');
    }

    protected function getSseProcessesToKill(): array
    {
        return [];
    }

    protected function handleCommandError(
        string $message = '',
        ?SymfonyStyle $io = null,
    ): int {
        if ($message && $io) {
            $io->error($message);
        }

        return Command::FAILURE;
    }

    protected function handleCommandSuccess(
        string $message = '',
        ?SymfonyStyle $io = null,
    ): int {
        if ($message && $io) {
            $io->success($message);
        }

        return Command::SUCCESS;
    }

    protected function isProcessRunning(
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

    protected function killConflictingSseProcesses(
        SymfonyStyle $io,
    ): void {
        foreach ($this->getSseProcessesToKill() as $serviceName) {
            if ($this->isProcessRunning($serviceName)) {
                $this->killPreviousProcess($serviceName, $io);
            }
        }
    }

    protected function killPreviousProcess(
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

    protected function p(
        string $name,
    ): mixed {
        return $this->bag->get(sprintf('%s.%s', ValksorBundle::VALKSOR, $name));
    }

    protected function parseCommaSeparatedList(
        string $input,
    ): array {
        return array_filter(array_map('trim', explode(',', $input)));
    }

    protected function removePidFile(
        object $service,
        string $serviceName,
    ): void {
        if (method_exists($service, 'removePidFile')) {
            $pidFile = $this->createPidFilePath($serviceName);
            $service->removePidFile($pidFile);
        }
    }

    protected function setServiceIo(
        object $service,
        SymfonyStyle $io,
    ): void {
        $service->io = $io;
    }

    protected function writePidFile(
        object $service,
        string $serviceName,
    ): void {
        if (method_exists($service, 'writePidFile')) {
            $pidFile = $this->createPidFilePath($serviceName);
            $service->writePidFile($pidFile);
        }
    }
}
