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

use RuntimeException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidParameterTypeException;
use Symfony\Component\DependencyInjection\Exception\ParameterCircularReferenceException;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Valksor\Bundle\Command\AbstractCommand as BundleAbstractCommand;
use Valksor\Bundle\ValksorBundle;
use Valksor\Component\Sse\Helper;

use function sprintf;

/**
 * Abstract base class providing common functionality for Valksor SSE console commands.
 *
 * This class extends the bundle's AbstractCommand to provide SSE-specific utilities
 * and helper methods for command implementations. It serves as the foundation for
 * SSE-related console commands, offering standardized access to configuration,
 * sub-command execution, and common SSE operational patterns.
 *
 * Command Architecture:
 * The abstract class implements a layered approach to command functionality:
 * - Bundle AbstractCommand: Provides base console command infrastructure
 * - SSE AbstractCommand: Adds SSE-specific utilities and configuration access
 * - Concrete Commands: Implement specific SSE functionality (server startup, etc.)
 *
 * Key Features:
 * - Parameter access with Valksor-specific prefixing
 * - Sub-command execution for complex workflows
 * - Helper trait integration for utility functions
 * - Consistent error handling and logging patterns
 * - Configuration access patterns optimized for SSE operations
 *
 * Configuration Access Pattern:
 * The p() method provides a standardized way to access Valksor configuration
 * parameters with automatic prefixing. This ensures consistent parameter access
 * across all SSE commands and reduces boilerplate code.
 *
 * Sub-Command Execution:
 * The executeSubCommand() method enables complex command workflows by allowing
 * commands to delegate to other commands. This is useful for:
 * - Multi-step operations (start server → configure → monitor)
 * - Conditional command execution based on configuration
 * - Command chaining and automation
 * - Modular command design
 *
 * Integration Points:
 * - **Bundle Infrastructure**: Extends core command functionality
 * -Configuration System**: Seamless access to Valksor parameters
 * -Helper Utilities**: Common functions for SSE operations
 * -Console Component**: Rich input/output handling
 *
 * Usage Patterns:
 *
 * **Basic Command Implementation**:
 * ```php
 * class MySseCommand extends AbstractCommand
 * {
 *     protected function execute(InputInterface $input, OutputInterface $output): int
 *     {
 *         $port = $this->p('sse.port');
 *         $this->info("Starting SSE server on port {$port}");
 *         // Command implementation
 *         return Command::SUCCESS;
 *     }
 * }
 * ```
 *
 * **Sub-Command Execution**:
 * ```php
 * class OrchestrateCommand extends AbstractCommand
 * {
 *     protected function execute(InputInterface $input, OutputInterface $output): int
 *     {
 *         // Start the SSE server
 *         $this->executeSubCommand('valksor:sse', $output);
 *
 *         // Configure hot reload
 *         $this->executeSubCommand('valksor:hot-reload', $output, ['--watch' => true]);
 *
 *         return Command::SUCCESS;
 *     }
 * }
 * ```
 *
 * **Configuration Access**:
 * ```php
 * class ConfigCommand extends AbstractCommand
 * {
 *     protected function execute(InputInterface $input, OutputInterface $output): int
 *     {
 *         $sseConfig = [
 *             'bind' => $this->p('sse.bind'),
 *             'port' => $this->p('sse.port'),
 *             'domain' => $this->p('sse.domain'),
 *         ];
 *
 *         $this->info('SSE Configuration:');
 *         $this->io->table(['Setting', 'Value'], array_map(null, array_keys($sseConfig), $sseConfig));
 *
 *         return Command::SUCCESS;
 *     }
 * }
 * ```
 *
 * Error Handling Strategy:
 * - Sub-command execution throws RuntimeException for missing commands
 * - Parameter access exceptions are handled by the parameter bag
 * - Console output errors are handled gracefully with proper formatting
 * - Command execution failures return appropriate exit codes
 *
 * Performance Considerations:
 * - Parameter access uses efficient string formatting
 * - Sub-command execution reuses existing command instances
 * - Helper methods avoid code duplication and maintenance overhead
 * - Configuration values are cached by the parameter bag
 *
 * Security Features:
 * - Parameter access is limited to configured values
 * - Sub-command execution validates command existence
 * - No direct file system access from command utilities
 * - Configuration follows Symfony security patterns
 *
 * @see BundleAbstractCommand For base command functionality
 * @see Helper For SSE-specific utility functions
 * @see SseCommand For concrete implementation example
 *
 * @author Davis Zalitis (k0d3r1s)
 */
abstract class AbstractCommand extends BundleAbstractCommand
{
    use Helper;

    /**
     * Execute another console command as a sub-command with proper error handling.
     *
     * This method enables command composition and complex workflows by allowing commands
     * to execute other commands within the same application context. This is particularly
     * useful for SSE operations that require multiple steps or coordination between
     * different services.
     *
     * Command Execution Pattern:
     * The method follows Symfony's command execution patterns:
     * 1. Find the command in the application's command registry
     * 2. Create a new ArrayInput with the command name and arguments
     * 3. Execute the command with the provided output interface
     * 4. Return the exit code for proper error propagation
     *
     * Use Cases for SSE Commands:
     * - **Orchestration**: Starting SSE server, then configuring hot reload
     * - **Conditional Logic**: Checking configuration before executing commands
     * - **Automation**: Scripting multi-step SSE setup processes
     * - **Testing**: Executing commands in sequence for integration testing
     *
     * Error Handling:
     * - Command not found: Throws RuntimeException with descriptive message
     * - Command execution errors: Propagated through exit codes
     * - Input validation: Handled by the target command
     * - Output errors: Passed through to the provided output interface
     *
     * Argument Format:
     * Arguments should be provided as a key-value array where keys are the
     * argument/option names (without dashes) and values are the argument values.
     * The 'command' key is automatically added with the command name.
     *
     * Security Considerations:
     * - Only commands registered in the application can be executed
     * - No shell injection risk as execution stays within Symfony console
     * - Argument validation handled by target commands
     * - Proper access control through Symfony's security system
     *
     * @param string          $commandName The name of the command to execute
     *                                     Must be registered in the console application
     *                                     Example: 'valksor:sse', 'valksor:hot-reload'
     * @param OutputInterface $output      Output interface for command output and logging
     *                                     Typically the same output as the parent command
     * @param array           $arguments   Optional arguments and options for the sub-command
     *                                     Format: ['option' => 'value', 'argument' => 'value']
     *                                     Avoid dashes in keys, use option/argument names directly
     *
     * @return int Exit code from the executed command
     *             0 for success, non-zero for failure following Unix conventions
     *
     * @throws RuntimeException   If the specified command is not found in the application
     * @throws ExceptionInterface If there are issues with command input or execution
     *
     * @see https://symfony.com/doc/current/console/calling_commands.html For official documentation
     * @see ArrayInput For input argument format and validation
     *
     * @example Basic sub-command execution
     * ```php
     * // Execute the SSE server command
     * $exitCode = $this->executeSubCommand('valksor:sse', $output);
     * if ($exitCode !== Command::SUCCESS) {
     *     $this->error('Failed to start SSE server');
     *     return $exitCode;
     * }
     * ```
     * @example Sub-command with arguments
     * ```php
     * // Execute hot reload with watch option
     * $exitCode = $this->executeSubCommand('valksor:hot-reload', $output, [
     *     'watch' => true,
     *     'debounce' => 0.5
     * ]);
     * ```
     * @example Command orchestration
     * ```php
     * protected function execute(InputInterface $input, OutputInterface $output): int
     * {
     *     // Step 1: Start SSE server
     *     $serverExit = $this->executeSubCommand('valksor:sse', $output);
     *     if ($serverExit !== Command::SUCCESS) {
     *         return $serverExit;
     *     }
     *
     *     // Step 2: Start file watching
     *     $watchExit = $this->executeSubCommand('valksor:watch', $output);
     *     if ($watchExit !== Command::SUCCESS) {
     *         return $watchExit;
     *     }
     *
     *     // Step 3: Start hot reload
     *     return $this->executeSubCommand('valksor:hot-reload', $output);
     * }
     * ```
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

    /**
     * Retrieve a Valksor configuration parameter with automatic prefixing.
     *
     * This method provides a convenient shorthand for accessing Valksor configuration
     * parameters from the Symfony parameter bag. It automatically applies the Valksor
     * bundle prefix to ensure consistent parameter access across all SSE commands.
     *
     * Parameter Access Pattern:
     * The method follows a consistent naming convention:
     * - Input: 'sse.port' → Resolves to: 'valksor.sse.port'
     * - Input: 'build.services' → Resolves to: 'valksor.build.services'
     * - Input: 'spx_profiler' → Resolves to: 'valksor.spx_profiler'
     *
     * Configuration Hierarchy:
     * Valksor configuration follows a hierarchical structure:
     * ```
     * valksor:
     *         sse:
     *             bind: "0.0.0.0"
     *             port: 3000
     *             path: "/sse"
     *         build:
     *             services:
     *                 tailwind: {...}
     *                 importmap: {...}
     *         spx_profiler: true
     * ```
     *
     * Common SSE Parameters:
     * - 'sse.bind': Server bind address (string)
     * - 'sse.port': Server port number (integer)
     * - 'sse.path': SSE endpoint path (string)
     * - 'sse.domain': SSL domain (string)
     * - 'sse.ssl_cert_path': SSL certificate path (string|null)
     * - 'sse.ssl_key_path': SSL private key path (string|null)
     *
     * Build System Parameters:
     * - 'build.services': Array of build service configurations
     * - 'project.apps_dir': Applications directory path
     * - 'project.infrastructure_dir': Infrastructure directory path
     *
     * Performance Considerations:
     * - Parameter values are cached by the parameter bag
     * - String concatenation uses efficient sprintf formatting
     * - Method is lightweight with minimal overhead
     * - Consistent prefixing reduces parameter lookup errors
     *
     * Error Handling:
     * - ParameterNotFoundException: Thrown when parameter doesn't exist
     * - ParameterCircularReferenceException: Thrown for circular dependencies
     * - InvalidParameterTypeException: Thrown for type conversion failures
     * - All exceptions are handled by Symfony's parameter system
     *
     * Type Safety:
     * The method returns mixed types to accommodate different parameter types:
     * - Strings: Configuration values, file paths
     * - Integers: Port numbers, timeouts
     * - Booleans: Feature flags, debug settings
     * - Arrays: Service configurations, file lists
     * - Null: Optional or unset parameters
     *
     * @param string $name The parameter name without the 'valksor.' prefix
     *                     Uses dot notation for nested parameters
     *                     Examples: 'sse.port', 'build.services.tailwind.enabled'
     *
     * @return mixed The parameter value with appropriate type
     *               Type depends on the parameter definition and configuration
     *
     * @throws ParameterNotFoundException          If parameter is not defined
     * @throws ParameterCircularReferenceException If parameter has circular reference
     * @throws InvalidParameterTypeException       If parameter type is invalid
     *
     * @see ParameterBagInterface For parameter access methods
     * @see ValksorBundle::VALKSOR For the prefix constant used
     *
     * @example Basic parameter access
     * ```php
     * $port = $this->p('sse.port');        // Returns: 3000
     * $bind = $this->p('sse.bind');        // Returns: "0.0.0.0"
     * $path = $this->p('sse.path');        // Returns: "/sse"
     * ```
     * @example Configuration validation
     * ```php
     * protected function validateConfig(): void
     * {
     *     $port = $this->p('sse.port');
     *     if ($port < 1024 || $port > 65535) {
     *         throw new InvalidArgumentException("Invalid port: {$port}");
     *     }
     *
     *     $bind = $this->p('sse.bind');
     *     if (!in_array($bind, ['0.0.0.0', '127.0.0.1'])) {
     *         throw new InvalidArgumentException("Invalid bind address: {$bind}");
     *     }
     * }
     * ```
     * @example Build configuration access
     * ```php
     * protected function listEnabledServices(): array
     * {
     *     $services = $this->p('build.services');
     *     return array_filter($services, fn($service) => $service['enabled'] ?? false);
     * }
     * ```
     * @example Dynamic configuration
     * ```php
     * protected function getServerUrl(): string
     * {
     *     $domain = $this->p('sse.domain');
     *     $port = $this->p('sse.port');
     *     $path = $this->p('sse.path');
     *
     *     return sprintf('https://%s:%d%s', $domain, $port, $path);
     * }
     * ```
     */
    protected function p(
        string $name,
    ): mixed {
        return $this->parameterBag->get(sprintf('%s.%s', ValksorBundle::VALKSOR, $name));
    }
}
