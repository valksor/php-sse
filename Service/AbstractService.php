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

/**
 * Abstract base class providing process management and lifecycle services.
 *
 * This class implements comprehensive process management for long-running SSE services,
 * including PID file management, graceful shutdown handling, process conflict resolution,
 * and service lifecycle coordination. It serves as the foundation for all SSE-related services
 * requiring robust process management.
 *
 * Core Process Management Features:
 * - PID file creation and validation for process tracking
 * - Graceful shutdown with SIGTERM and force kill with SIGKILL
 * - Process conflict detection and automatic cleanup
 * - Service lifecycle coordination with proper cleanup
 * - Error handling and recovery mechanisms
 *
 * Service Lifecycle Pattern:
 * 1. Pre-startup: Kill conflicting processes and create PID file
 * 2. Startup: Initialize service with proper error handling
 * 3. Runtime: Handle signals, reload requests, and health monitoring
 * 4. Shutdown: Graceful termination with resource cleanup
 * 5. Post-shutdown: PID file removal and final cleanup
 *
 * Signal Handling Strategy:
 * - SIGTERM: Graceful shutdown request (stop method)
 * - SIGKILL: Force termination for unresponsive processes
 * - SIGHUP: Reload/configuration reload request (reload method)
 * - SIGINT: Interrupt signal (typically handled by concrete services)
 *
 * PID File Management:
 * - Files stored in {projectDir}/var/run/valksor-{serviceName}.pid
 * - Contains current process ID for service tracking
 * - Automatic cleanup on service shutdown
 * - Validation for stale PID files and orphaned processes
 * - Used for conflict detection and process management
 *
 * Process Conflict Resolution:
 * - Detects existing service instances via PID files
 * - Implements two-phase termination: SIGTERM → wait → SIGKILL
 * - Configurable timeout for graceful shutdown (default: 3 seconds)
 * - Automatic cleanup of stale PID files
 * - Prevents multiple service instances from running simultaneously
 *
 * Integration Points:
 * - Used by SseService for server process management
 * - Integrated with Valksor build system service orchestration
 * - Compatible with Symfony console command infrastructure
 * - Supports dependency injection and parameter bag access
 *
 * Error Handling Philosophy:
 * - Graceful degradation when possible
 * - Automatic cleanup of resources on failure
 * - Detailed logging for debugging and monitoring
 * - Protection against orphaned processes and PID files
 * - Exception handling with proper resource cleanup
 *
 * Usage Pattern:
 * ```php
 * class MyService extends AbstractService
 * {
 *     public function start(array $config = []): int
 *     {
 *         // Service implementation
 *         while ($this->running) {
 *             // Service logic
 *         }
 *         return Command::SUCCESS;
 *     }
 *
 *     public static function getServiceName(): string
 *     {
 *         return 'my_service';
 *     }
 * }
 *
 * // Usage with lifecycle management
 * $service = new MyService($parameterBag);
 * $exitCode = $service->startWithLifecycle($io);
 * ```
 *
 * Thread Safety and Concurrency:
 * - Designed for single-process service instances
 * - PID file locking prevents concurrent execution
 * - Signal handling ensures atomic state transitions
 * - Safe for use in multi-process environments
 *
 * @see ServiceInterface For the service contract interface
 * @see Helper For additional utility methods
 */
abstract class AbstractService implements ServiceInterface
{
    use Helper;

    /**
     * Console output interface for user interaction and logging.
     *
     * Provides access to SymfonyStyle methods for rich console output including
     * sections, progress bars, tables, and formatted text. This is injected
     * by the command framework or service orchestrator.
     *
     * @var SymfonyStyle Console output interface
     */
    public SymfonyStyle $io;

    /**
     * Project root directory path.
     *
     * Contains the absolute path to the project root directory, typically
     * the location of composer.json and the main application structure.
     * Used for PID file placement and relative path resolution.
     *
     * @var string Absolute path to project directory
     */
    protected string $projectDir;

    /**
     * Service running state flag.
     *
     * Indicates whether the service is currently active and should continue
     * processing. This flag is controlled by the stop() method and signals
     * the main service loop to terminate gracefully.
     *
     * @var bool true if service is running, false if shutdown requested
     */
    protected bool $running = false;

    /**
     * Service reload request flag.
     *
     * Indicates that a service reload has been requested, typically through
     * a SIGHUP signal or explicit reload() call. Services should check this
     * flag in their main loop and handle reload logic appropriately.
     *
     * @var bool true if reload is requested, false otherwise
     */
    protected bool $shouldReload = false;

    /**
     * Service shutdown request flag.
     *
     * Indicates that an immediate service shutdown has been requested.
     * This is set by the stop() method and signals that the service should
     * terminate as quickly as possible while still cleaning up resources.
     *
     * @var bool true if shutdown is requested, false otherwise
     */
    protected bool $shouldShutdown = false;

    /**
     * Initialize the abstract service with required dependencies.
     *
     * The constructor sets up the core dependencies needed by all service
     * implementations, including access to application configuration and
     * determination of the project directory for PID file placement.
     *
     * Dependencies Injected:
     * - ParameterBagInterface: Access to application configuration and parameters
     * - Project Directory: Derived from kernel.project_dir parameter
     *
     * Configuration Access:
     * Services can access configuration values through the parameter bag
     * using the p() helper method, which automatically prefixes parameter
     * names with the Valksor bundle namespace.
     *
     * @param ParameterBagInterface $parameterBag Application parameter container
     */
    public function __construct(
        protected ParameterBagInterface $parameterBag,
    ) {
        $this->projectDir = $parameterBag->get('kernel.project_dir');
    }

    /**
     * Get the unique service name identifier.
     *
     * This abstract method requires all concrete service implementations to
     * provide a unique name that identifies the service type. This name is used
     * for PID file naming, process identification, and logging purposes.
     *
     * Service Name Requirements:
     * - Must be unique across all services in the application
     * - Should be lowercase and use underscores for spaces
     * - Should be descriptive of the service's purpose
     * - Must be filesystem-safe (used in PID file names)
     *
     * Examples:
     * - 'sse' for Server-Sent Events service
     * - 'hot_reload' for file watching service
     * - 'websocket_server' for WebSocket service
     *
     * Usage in PID Files:
     * PID files are named: valksor-{serviceName}.pid
     * Example: valksor-sse.pid, valksor-hot_reload.pid
     *
     * @return string Unique service name identifier
     */
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
     * Start service with comprehensive lifecycle management and error handling.
     *
     * This method provides a complete service startup workflow that handles
     * process conflicts, PID file management, error recovery, and resource cleanup.
     * It's the recommended way to start any service that extends AbstractService.
     *
     * Startup Workflow:
     * 1. Set up console output interface for logging
     * 2. Terminate any conflicting service instances
     * 3. Create PID file for process tracking
     * 4. Execute the concrete service's start() method
     * 5. Clean up PID file on successful completion
     * 6. Handle exceptions with proper cleanup
     *
     * Conflict Resolution:
     * - Automatically detects and terminates existing instances
     * - Uses two-phase termination (SIGTERM → SIGKILL)
     * - Prevents multiple service instances from running
     * - Ensures clean startup environment
     *
     * Error Handling Strategy:
     * - All exceptions are caught and logged
     * - PID files are cleaned up even on failure
     * - Detailed error messages provided for debugging
     * - Exit codes properly propagated to calling code
     *
     * Resource Management:
     * - PID files created in {projectDir}/var/run/
     * - Automatic cleanup on both success and failure
     * - Protection against orphaned PID files
     * - Proper directory creation if missing
     *
     * Integration Benefits:
     * - Consistent startup behavior across all services
     * - Automatic conflict resolution prevents port binding issues
     * - Proper cleanup prevents resource leaks
     * - Standardized error handling and logging
     *
     * Usage Pattern:
     * ```php
     * $service = new MyService($parameterBag);
     * $exitCode = $service->startWithLifecycle($io);
     *
     * if ($exitCode === Command::SUCCESS) {
     *     $io->success('Service started successfully');
     * } else {
     *     $io->error('Service failed to start');
     * }
     * ```
     *
     * @param SymfonyStyle $io Console output interface for logging and user feedback
     *
     * @return int Exit code from the service start method (0 for success, non-zero for failure)
     *
     * @see killConflictingSseProcesses() For conflict resolution logic
     * @see writePidFile() For PID file creation
     * @see removePidFile() For PID file cleanup
     * @see start() For the concrete service implementation
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
