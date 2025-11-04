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

namespace Valksor\Component\Sse\Command;

use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Valksor\Component\Sse\Service\SseService;

/**
 * Console command for starting the Valksor SSE (Server-Sent Events) server.
 *
 * This command serves as the primary entry point for launching the SSE server that enables
 * real-time communication between the build system and web browsers for hot reload functionality.
 * It provides a simple interface for starting the server with proper process management,
 * lifecycle handling, and graceful shutdown capabilities.
 *
 * Command Integration:
 * The command integrates with Symfony's console component and follows established patterns
 * for Valksor commands. It leverages dependency injection to access the SseService and
 * parameter bag for configuration management. The command extends AbstractCommand which
 * provides common functionality for parameter access and sub-command execution.
 *
 * Server Lifecycle Management:
 * When executed, this command delegates to SseService->startWithLifecycle() which handles:
 * - Process conflict detection and resolution (kills existing SSE processes)
 * - PID file management for process tracking
 * - Server initialization with SSL/TLS support if configured
 * - Signal handling for graceful shutdown (SIGTERM, SIGINT)
 * - Client connection management and event broadcasting
 * - Resource cleanup on server shutdown
 *
 * Usage Patterns:
 *
 * **Basic Development Usage**:
 * ```bash
 * php bin/console valksor:sse
 * ```
 * Starts the SSE server with default configuration (localhost:3000, no SSL)
 *
 * **Production Usage**:
 * ```bash
 * php bin/console valksor:sse --env=prod
 * ```
 * Starts the server with production configuration from environment variables
 *
 * **Background Process**:
 * ```bash
 * php bin/console valksor:sse > /var/log/valksor-sse.log 2>&1 &
 * ```
 * Runs the server as a background process with logging
 *
 * **Process Management**:
 * ```bash
 * # Start the server
 * php bin/console valksor:sse
 *
 * # Stop gracefully (Ctrl+C or kill -TERM <pid>)
 * # Force stop if needed (kill -KILL <pid>)
 * ```
 *
 * Configuration Dependencies:
 * The server configuration is loaded from the Valksor SSE configuration section:
 * - bind: Server bind address (default: 0.0.0.0)
 * - port: Server port (default: 3000)
 * - path: SSE endpoint path (default: /sse)
 * - domain: Domain for SSL certificate lookup
 * - ssl_cert_path: Custom SSL certificate path
 * - ssl_key_path: Custom SSL private key path
 *
 * Error Handling:
 * - Process conflicts are automatically resolved
 * - SSL certificate issues are logged with helpful messages
 * - Port binding errors provide clear diagnostic information
 * - JSON parsing errors in event data are caught and logged
 * - Network errors are handled gracefully with automatic recovery
 *
 * Integration Points:
 * - **Hot Reload System**: Works with file watching services to trigger browser refreshes
 * - **Asset Pipeline**: Integrates with importmap and AssetMapper for module reloading
 * - **Build Services**: Coordinates with Tailwind CSS, importmap, and other build tools
 * - **Development Tools**: Supports SPX profiler and other development utilities
 *
 * Performance Considerations:
 * - Server runs as a long-running process for optimal performance
 * - Keep-alive messages maintain client connections efficiently
 * - Event broadcasting is optimized for multiple concurrent clients
 * - Signal handling ensures immediate response to shutdown requests
 *
 * Security Features:
 * - SSL/TLS support for secure client-server communication
 * - Configurable bind addresses for network access control
 * - Process isolation prevents interference with other services
 * - Certificate validation for secure deployments
 *
 * Monitoring and Debugging:
 * - Real-time connection status logging
 * - Event broadcasting confirmation
 * - Error reporting with detailed diagnostic information
 * - Process lifecycle tracking through PID files
 *
 * @see SseService For the core server implementation
 * @see AbstractCommand For base command functionality
 * @see SseConfiguration For server configuration options
 *
 * @author Davis Zalitis (k0d3r1s)
 */
#[AsCommand(name: 'valksor:sse', description: 'Start the Valksor SSE server for hot reload functionality')]
final class SseCommand extends AbstractCommand
{
    /**
     * Initialize the SSE command with required dependencies.
     *
     * The constructor uses dependency injection to receive the SseService instance
     * and parameter bag for configuration access. The SseService handles all server
     * operations including lifecycle management, client connections, and event broadcasting.
     *
     * Dependency Injection Pattern:
     * - ParameterBagInterface: Provides access to application configuration parameters
     * - SseService: Core SSE server implementation with process management
     * - Constructor follows standard Symfony DI patterns for testability and flexibility
     *
     * @param ParameterBagInterface $parameterBag Application parameter container for configuration access
     *                                            Provides access to Valksor configuration values
     * @param SseService            $sseService   Core SSE server service instance
     *                                            Handles server lifecycle, client management, and event broadcasting
     */
    public function __construct(
        ParameterBagInterface $parameterBag,
        private readonly SseService $sseService,
    ) {
        parent::__construct($parameterBag);
    }

    /**
     * Execute the SSE command and start the server with proper lifecycle management.
     *
     * This method serves as the main entry point for the command execution. It creates
     * a SymfonyStyle instance for enhanced console output and delegates the actual
     * server startup to the SseService with comprehensive lifecycle management.
     *
     * Execution Flow:
     * 1. Create SymfonyStyle instance for rich console interactions
     * 2. Delegate to SseService->startWithLifecycle() for server startup
     * 3. Return the exit code from the service execution
     *
     * Lifecycle Management Features:
     * The delegated service method handles:
     * - Process conflict detection and automatic resolution
     * - PID file creation and cleanup
     * - SSL/TLS certificate validation and loading
     * - Server socket initialization and binding
     * - Signal handler setup for graceful shutdown
     * - Client connection acceptance and management
     * - Event broadcasting to connected clients
     * - Resource cleanup and process termination
     *
     * Console Output:
     * The SymfonyStyle instance provides:
     * - Colored output for better readability
     * - Progress bars and status indicators
     * - Success/error/warning message formatting
     * - Interactive prompts and confirmations
     * - Table and list formatting for structured data
     *
     * Error Handling:
     * - All exceptions are caught by the service layer
     * - Error messages are logged with appropriate severity
     * - Exit codes properly indicate success or failure
     * - Resources are cleaned up even on failure
     * - Diagnostic information provided for troubleshooting
     *
     * Return Values:
     * - Command::SUCCESS (0): Server started and terminated successfully
     * - Non-zero: Server failed to start or encountered errors during execution
     *
     * @param InputInterface  $input  Console input interface (no additional arguments supported)
     *                                Standard Symfony console input with command options and arguments
     * @param OutputInterface $output Console output interface for status and logging messages
     *                                Used for creating SymfonyStyle instance
     *
     * @return int Exit code indicating success (0) or failure (non-zero)
     *             Follows standard command line exit code conventions
     *
     * @throws JsonException    If JSON event data parsing fails (handled by service layer)
     * @throws RuntimeException If process management or server startup fails
     *
     * @see SseService::startWithLifecycle() For the actual server implementation
     * @see SymfonyStyle For enhanced console output functionality
     *
     * @example Basic command execution
     * ```bash
     * php bin/console valksor:sse
     * # Output:
     * # [INFO] Starting SSE server on localhost:3000...
     * # [INFO] Server started successfully
     * # [INFO] Listening for connections...
     * ```
     * @example Command with different environment
     * ```bash
     * php bin/console valksor:sse --env=prod
     * # Uses production configuration from config/packages/prod/valksor.yaml
     * ```
     */
    /**
     * Execute the SSE command and start the server with proper lifecycle management.
     *
     * This method serves as the main entry point for the command execution. It creates
     * a SymfonyStyle instance for enhanced console output and delegates the actual
     * server startup to the SseService with comprehensive lifecycle management.
     *
     * Execution Flow:
     * 1. Create SymfonyStyle instance for rich console interactions
     * 2. Delegate to SseService->startWithLifecycle() for server startup
     * 3. Return the exit code from the service execution
     *
     * Lifecycle Management Features:
     * The delegated service method handles:
     * - Process conflict detection and automatic resolution
     * - PID file creation and cleanup
     * - SSL/TLS certificate validation and loading
     * - Server socket initialization and binding
     * - Signal handler setup for graceful shutdown
     * - Client connection acceptance and management
     * - Event broadcasting to connected clients
     * - Resource cleanup and process termination
     *
     * Console Output:
     * The SymfonyStyle instance provides:
     * - Colored output for better readability
     * - Progress bars and status indicators
     * - Success/error/warning message formatting
     * - Interactive prompts and confirmations
     * - Table and list formatting for structured data
     *
     * Error Handling:
     * - All exceptions are caught by the service layer
     * - Error messages are logged with appropriate severity
     * - Exit codes properly indicate success or failure
     * - Resources are cleaned up even on failure
     * - Diagnostic information provided for troubleshooting
     *
     * Return Values:
     * - Command::SUCCESS (0): Server started and terminated successfully
     * - Non-zero: Server failed to start or encountered errors during execution
     *
     * @param InputInterface  $input  Console input interface (no additional arguments supported)
     *                                Standard Symfony console input with command options and arguments
     * @param OutputInterface $output Console output interface for status and logging messages
     *                                Used for creating SymfonyStyle instance
     *
     * @return int Exit code indicating success (0) or failure (non-zero)
     *             Follows standard command line exit code conventions
     *
     * @throws RuntimeException If process management or server startup fails
     *
     * @see SseService::startWithLifecycle() For the actual server implementation
     * @see SymfonyStyle For enhanced console output functionality
     *
     * @example Basic command execution
     * ```bash
     * php bin/console valksor:sse
     * # Output:
     * # [INFO] Starting SSE server on localhost:3000...
     * # [INFO] Server started successfully
     * # [INFO] Listening for connections...
     * ```
     * @example Command with different environment
     * ```bash
     * php bin/console valksor:sse --env=prod
     * # Uses production configuration from config/packages/prod/valksor.yaml
     * ```
     */
    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);

        return $this->sseService->startWithLifecycle($io);
    }
}
