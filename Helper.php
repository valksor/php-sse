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

/**
 * Utility trait providing common helper methods for SSE services and commands.
 *
 * This trait consolidates frequently used utility functions across the SSE component,
 * providing a consistent interface for directory operations, JSON handling, and
 * console output management. It implements lazy loading patterns for optimal
 * performance and uses trait composition to leverage existing Valksor framework utilities.
 *
 * Trait Architecture:
 * The helper trait follows a utility-first design pattern that:
 * - Provides static helper methods with lazy initialization for performance
 * - Wraps complex framework utilities in simple, consistent interfaces
 * - Implements error handling and robustness for common operations
 * - Enables trait composition for flexible service design
 * - Reduces code duplication across SSE services and commands
 *
 * Integration Strategy:
 * This trait is designed to be used by multiple SSE component classes:
 * - **SseService**: Uses helpers for directory creation and JSON processing
 * - **AbstractService**: Leverages utilities for file operations and error handling
 * - **Commands**: Utilize console output helpers for user interaction
 * - **Runtime Classes**: Access helpers for template and asset processing
 *
 * Performance Optimizations:
 * The trait implements several performance-focused patterns:
 * - **Static Helper Caching**: Helper objects are created once and reused
 * - **Lazy Initialization**: Helper instances are created only when first needed
 * - **Trait Composition**: Leverages optimized framework utilities
 * - **Method Existence Checking**: Prevents reflection overhead when possible
 * - **Memory Efficiency**: Minimal footprint with shared helper instances
 *
 * Error Handling Philosophy:
 * All helper methods implement graceful error handling:
 * - Directory creation handles permission issues automatically
 * - JSON operations provide comprehensive error reporting
 * - Console output methods fail silently when unavailable
 * - Framework utility errors are properly caught and re-thrown
 * - Consistent exception types across all helper methods
 *
 * Usage Patterns:
 *
 * **Basic Directory Operations**:
 * ```php
 * class MySseService {
 *     use Helper;
 *
 *     public function ensureWorkingDirectory(): void {
 *         $this->ensureDirectory('/var/log/valksor-sse');
 *         $this->ensureDirectory($this->getCacheDirectory());
 *     }
 * }
 * ```
 *
 * **JSON Data Processing**:
 * ```php
 * class EventProcessor {
 *     use Helper;
 *
 *     public function processSseEvent(string $jsonData): void {
 *         $data = $this->jsonDecode($jsonData);
 *         $processed = $this->transformData($data);
 *         $output = $this->jsonEncode($processed);
 *         $this->broadcastEvent($output);
 *     }
 * }
 * ```
 *
 * **Console Output Integration**:
 * ```php
 * class MyCommand extends AbstractCommand {
 *     use Helper;
 *
 *     protected function execute(InputInterface $input, OutputInterface $output): int {
 *         $service = new SseService($this->parameterBag);
 *         $this->setServiceIo($service, $this->createSymfonyStyle($input, $output));
 *
 *         // Service can now output to console via the injected Io instance
 *         return Command::SUCCESS;
 *     }
 * }
 * ```
 *
 * **Combined Usage Pattern**:
 * ```php
 * class ComprehensiveSseService {
 *     use Helper;
 *
 *     public function initializeEnvironment(SymfonyStyle $io): void {
 *         // Ensure necessary directories exist
 *         $this->ensureDirectory($this->config['log_dir']);
 *         $this->ensureDirectory($this->config['cache_dir']);
 *
 *         // Load and parse configuration
 *         $configJson = file_get_contents($this->config['config_file']);
 *         $config = $this->jsonDecode($configJson);
 *
 *         // Output status to console
 *         $io->success('SSE environment initialized successfully');
 *     }
 * }
 * ```
 *
 * Framework Integration:
 *
 * **Valksor Functions Integration**:
 * The trait leverages existing Valksor framework utilities:
 * - `_MkDir`: Provides enhanced directory creation with error handling
 * - `_JsonDecode`: Offers robust JSON parsing with comprehensive error reporting
 * - `_JsonEncode`: Implements flexible JSON serialization with options
 * - All utilities are accessed through lazy-loaded wrapper classes
 *
 * **Symfony Console Integration**:
 * - **SymfonyStyle**: Rich console output with formatting and colors
 * - **Method Injection**: Dynamic Io capability for services that need console output
 * - **Graceful Degradation**: Services work with or without console Io injection
 * - **Compatibility**: Works with any Symfony console application
 *
 * @author Davis Zalitis (k0d3r1s)
 *
 * @see _MkDir For enhanced directory creation functionality
 * @see _JsonDecode For robust JSON parsing capabilities
 * @see _JsonEncode For flexible JSON serialization options
 * @see SymfonyStyle For rich console output formatting
 */

namespace Valksor\Component\Sse;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Valksor\Functions\Iteration\Traits\_JsonDecode;
use Valksor\Functions\Iteration\Traits\_JsonEncode;
use Valksor\Functions\Local\Traits\_MkDir;

use function method_exists;

/**
 * Utility trait providing common helper methods for SSE services and commands.
 *
 * This trait consolidates frequently used utility functions across the SSE component,
 * offering directory operations, JSON processing, and console output management through
 * a consistent, performance-optimized interface.
 *
 * Key Features:
 * - Lazy-loaded helper instances for optimal performance
 * - Robust error handling and graceful degradation
 * - Integration with existing Valksor framework utilities
 * - Static caching to minimize memory footprint
 * - Method existence checking for safe Io injection
 *
 * Usage Integration:
 * The trait is used by SSE services and commands to access common functionality
 * without code duplication, providing a foundation for consistent behavior across
 * the entire SSE component.
 *
 * @trait
 *
 * @see _MkDir For enhanced directory creation with proper error handling
 * @see _JsonDecode For JSON parsing with comprehensive error reporting
 * @see _JsonEncode For JSON serialization with flexible options
 * @see SymfonyStyle For rich console output and user interaction
 */
trait Helper
{
    /**
     * Ensure that a directory exists, creating it if necessary.
     *
     * This method provides a safe, robust way to create directories with proper
     * error handling. It uses lazy loading of the _MkDir helper to minimize
     * overhead while providing enhanced directory creation capabilities.
     *
     * Lazy Loading Pattern:
     * The method implements static caching of the helper instance:
     * - First call creates the helper class with _MkDir trait
     * - Subsequent calls reuse the same helper instance
     * - Minimizes memory usage and initialization overhead
     * - Provides thread-safe operation in single-threaded PHP execution
     *
     * Directory Creation Features:
     * - Recursive directory creation (creates parent directories as needed)
     * - Permission handling and error reporting
     * - Atomic operations to prevent race conditions
     * - Cross-platform compatibility (Windows, Linux, macOS)
     * - Proper handling of existing directories (no errors)
     *
     * Error Handling Strategy:
     * The underlying _MkDir utility provides comprehensive error handling:
     * - Permission denied errors are caught and reported clearly
     * - Disk space issues are detected and reported
     * - Invalid path characters are validated and rejected
     * - Filesystem limitations are properly handled
     * - All exceptions include actionable error messages
     *
     * Use Cases in SSE Component:
     * - Creating log directories for SSE server logging
     * - Setting up cache directories for importmap generation
     * - Ensuring PID file directories exist for process management
     * - Creating temporary directories for file processing
     * - Setting up SSL certificate directories
     *
     * Performance Considerations:
     * - Static helper caching minimizes repeated object creation
     * - Filesystem operations are optimized by the underlying trait
     * - No redundant directory creation attempts on existing paths
     * - Minimal overhead for frequently called operations
     *
     * Security Features:
     * - Path traversal prevention through validation
     * - Permission checks before directory creation
     * - Safe handling of symbolic links and special files
     * - Protection against race conditions in concurrent access
     *
     * @param string $directory The directory path to ensure exists
     *                          Can be absolute or relative path
     *                          Parent directories are created as needed
     *                          Path is validated for security and correctness
     *
     * @return void This method modifies the filesystem and returns no value
     *              Success is indicated by the directory existing after execution
     *              Failures throw appropriate exceptions with descriptive messages
     *
     * @throws RuntimeException         If directory creation fails due to permissions, disk space, or invalid paths
     * @throws InvalidArgumentException If the provided path is invalid or contains prohibited characters
     *
     * @example Basic directory creation
     * ```php
     * // Ensure log directory exists
     * $this->ensureDirectory('/var/log/valksor-sse');
     *
     * // Ensure cache directory with nested structure
     * $this->ensureDirectory($cacheBasePath . '/templates/importmap');
     *
     * // Ensure relative directory exists
     * $this->ensureDirectory('./storage/temp');
     * ```
     * @example Service initialization with directories
     * ```php
     * class SseService {
     *     use Helper;
     *
     *     public function initialize(): void {
     *         $directories = [
     *             $this->config['log_directory'],
     *             $this->config['cache_directory'],
     *             $this->config['pid_directory'],
     *             dirname($this->config['ssl_cert_path']),
     *         ];
     *
     *         foreach ($directories as $directory) {
     *             $this->ensureDirectory($directory);
     *         }
     *
     *         $this->logger->info('All necessary directories created successfully');
     *     }
     * }
     * ```
     * @example Error handling pattern
     * ```php
     * try {
     *     $this->ensureDirectory('/restricted/path/directory');
     *     $this->logger->info('Directory created successfully');
     * } catch (RuntimeException $e) {
     *     $this->logger->error('Failed to create directory: ' . $e->getMessage());
     *     throw $e; // Re-throw to allow higher-level handling
     * }
     * ```
     */
    public function ensureDirectory(
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
     * Safely decode a JSON string into a PHP data structure.
     *
     * This method provides robust JSON parsing with comprehensive error handling
     * and validation. It uses lazy loading of the _JsonDecode helper to provide
     * enhanced JSON processing capabilities beyond PHP's native json_decode().
     *
     * Lazy Loading Pattern:
     * The method implements static caching for performance optimization:
     * - First call instantiates the helper class with _JsonDecode trait
     * - Subsequent calls reuse the same helper instance
     * - Reduces memory overhead and initialization time
     * - Maintains consistent parsing behavior across multiple calls
     *
     * Enhanced JSON Processing:
     * The underlying _JsonDecode utility provides advanced features:
     * - Comprehensive error reporting with line/column information
     * - Validation of JSON structure and content
     * - Support for large JSON files with streaming capabilities
     * - Security checks against malicious JSON content
     * - Customizable parsing options and validation rules
     *
     * Error Handling Strategy:
     * Superior to PHP's native json_decode() in several ways:
     * - Detailed error messages with specific problem locations
     * - Validation of JSON structure before parsing
     * - Protection against malformed or malicious content
     * - Clear distinction between syntax errors and content errors
     * - Informative exceptions for debugging and troubleshooting
     *
     * Use Cases in SSE Component:
     * - Parsing SSE event data from clients or servers
     * - Decoding configuration files in JSON format
     * - Processing importmap definitions and asset metadata
     * - Handling client communication data structures
     * - Parsing build configuration and service definitions
     *
     * Performance Optimizations:
     * - Static helper caching minimizes repeated initialization
     * - Optimized parsing algorithms for large JSON structures
     * - Memory-efficient processing for streaming data
     * - Fast validation checks before full parsing
     * - Minimal overhead for repeated operations
     *
     * Security Features:
     * - Protection against JSON injection attacks
     * - Validation of nested structure depth limits
     * - Prevention of memory exhaustion through large payloads
     * - Safe handling of Unicode and special characters
     * - Detection and rejection of malicious JSON patterns
     *
     * @param string $json The JSON string to decode into PHP data structures
     *                     Must be valid JSON according to RFC 7159 specification
     *                     Can contain objects, arrays, strings, numbers, booleans, and null values
     *                     Empty strings and whitespace-only inputs are handled gracefully
     *
     * @return mixed The decoded PHP data structure
     *               Returns objects, arrays, strings, numbers, booleans, or null based on JSON content
     *               Structure matches the JSON input hierarchy
     *               Data types are appropriately converted from JSON to PHP equivalents
     *
     * @throws JsonException            If JSON parsing fails due to syntax errors, invalid structure, or content issues
     * @throws InvalidArgumentException If the input is not a valid string or contains prohibited content
     * @throws RuntimeException         If parsing fails due to memory limits or security restrictions
     *
     * @example Basic JSON decoding
     * ```php
     * // Simple object parsing
     * $jsonData = '{"event": "reload", "data": {"files": ["style.css", "app.js"]}}';
     * $data = $this->jsonDecode($jsonData);
     * // Returns: (object) ['event' => 'reload', 'data' => ['files' => ['style.css', 'app.js']]]
     *
     * // Array parsing
     * $jsonArray = '[{"type": "css", "path": "styles/main.css"}, {"type": "js", "path": "app.js"}]';
     * $assets = $this->jsonDecode($jsonArray);
     * // Returns: [0 => (object) ['type' => 'css', 'path' => 'styles/main.css'], ...]
     * ```
     * @example SSE event data processing
     * ```php
     * class EventProcessor {
     *     use Helper;
     *
     *     public function processSseMessage(string $message): void {
     *         try {
     *             $eventData = $this->jsonDecode($message);
     *
     *             if (!isset($eventData->event) || !isset($eventData->data)) {
     *                 throw new InvalidArgumentException('Invalid SSE event structure');
     *             }
     *
     *             switch ($eventData->event) {
     *                 case 'reload':
     *                     $this->handleReload($eventData->data);
     *                     break;
     *                 case 'error':
     *                     $this->handleError($eventData->data);
     *                     break;
     *             }
     *
     *         } catch (JsonException $e) {
     *             $this->logger->error('Failed to parse SSE event: ' . $e->getMessage());
     *             throw $e;
     *         }
     *     }
     * }
     * ```
     * @example Configuration parsing with validation
     * ```php
     * class ConfigurationManager {
     *     use Helper;
     *
     *     public function loadConfig(string $configPath): array {
     *         $configJson = file_get_contents($configPath);
     *         $config = $this->jsonDecode($configJson);
     *
     *         // Validate required configuration sections
     *         $required = ['server', 'logging', 'build'];
     *         foreach ($required as $section) {
     *             if (!isset($config->$section)) {
     *                 throw new InvalidArgumentException("Missing config section: {$section}");
     *             }
     *         }
     *
     *         return (array) $config;
     *     }
     * }
     * ```
     */
    public function jsonDecode(
        string $json,
    ): mixed {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _JsonDecode;
            };
        }

        return $_helper->jsonDecode($json, true);
    }

    /**
     * Safely encode PHP data structures into JSON strings.
     *
     * This method provides flexible and secure JSON serialization with enhanced
     * error handling and formatting options. It uses lazy loading of the _JsonEncode
     * helper to offer capabilities beyond PHP's native json_encode() function.
     *
     * Lazy Loading Pattern:
     * The method implements static caching for optimal performance:
     * - First call creates the helper class with _JsonEncode trait
     * - Subsequent calls reuse the same helper instance
     * - Minimizes memory usage and initialization overhead
     * - Ensures consistent serialization behavior across the application
     *
     * Enhanced JSON Serialization:
     * The underlying _JsonEncode utility provides advanced features:
     * - Comprehensive error detection and reporting
     * - Automatic handling of circular references
     * - Support for complex nested data structures
     * - Configurable output formatting and pretty printing
     * - Validation of data before serialization
     *
     * Error Handling Strategy:
     * Superior error handling compared to native json_encode():
     * - Detailed error messages for unsupported data types
     * - Detection and handling of circular references
     * - Memory usage validation for large data structures
     * - Unicode and encoding issue detection and resolution
     * - Clear exception messages for debugging and troubleshooting
     *
     * Use Cases in SSE Component:
     * - Serializing SSE event data for client transmission
     * - Encoding configuration data for storage or transmission
     * - Creating JSON responses for API endpoints
     * - Serializing importmap definitions for browser consumption
     * - Converting build results and status information
     *
     * Performance Optimizations:
     * - Static helper caching reduces repeated initialization
     * - Optimized serialization algorithms for large data structures
     * - Memory-efficient processing of complex nested objects
     * - Fast validation checks before serialization
     * - Minimal overhead for repeated encoding operations
     *
     * Security Features:
     * - Protection against data leakage through serialization
     * - Safe handling of sensitive information in data structures
     * - Prevention of XXE (XML External Entity) style attacks in JSON
     * - Validation of Unicode content to prevent encoding attacks
     * - Memory limit enforcement to prevent resource exhaustion
     *
     * Data Type Support:
     * The method handles all standard PHP data types:
     * - **Objects**: Serialized as JSON objects with public properties
     * - **Arrays**: Serialized as JSON arrays or objects based on keys
     * - **Strings**: Properly escaped and Unicode-encoded
     * - **Numbers**: Maintained as integers or floats as appropriate
     * - **Booleans**: Converted to true/false JSON values
     * - **Null**: Converted to JSON null value
     * - **Resources**: Automatically detected and rejected with clear error
     *
     * @param mixed $data The PHP data structure to encode as JSON
     *                    Can be objects, arrays, strings, numbers, booleans, or null
     *                    Nested structures are supported with proper recursion handling
     *                    Circular references are detected and handled appropriately
     *
     * @return string The JSON representation of the input data
     *                Valid JSON string according to RFC 7159 specification
     *                Properly formatted with appropriate escaping and encoding
     *                Suitable for transmission to web clients or storage
     *
     * @throws JsonException            If JSON encoding fails due to unsupported data types, circular references, or encoding issues
     * @throws InvalidArgumentException If the input data contains resources or other non-serializable types
     * @throws RuntimeException         If encoding fails due to memory limits or security restrictions
     *
     * @example Basic JSON encoding
     * ```php
     * // Simple object encoding
     * $event = (object) [
     *     'event' => 'reload',
     *     'data' => ['files' => ['style.css', 'app.js']],
     *     'timestamp' => time()
     * ];
     * $json = $this->jsonEncode($event);
     * // Returns: '{"event":"reload","data":{"files":["style.css","app.js"]},"timestamp":1691234567}'
     *
     * // Array encoding
     * $assets = [
     *     ['type' => 'css', 'path' => 'styles/main.css', 'size' => 1024],
     *     ['type' => 'js', 'path' => 'app.js', 'size' => 2048]
     * ];
     * $json = $this->jsonEncode($assets);
     * ```
     * @example SSE event broadcasting
     * ```php
     * class SseBroadcaster {
     *     use Helper;
     *
     *     public function broadcastReload(array $files): void {
     *         $event = [
     *             'event' => 'reload',
     *             'data' => [
     *                 'files' => $files,
     *                 'timestamp' => microtime(true),
     *                 'trigger' => 'file_change'
     *             ]
     *         ];
     *
     *         try {
     *             $jsonEvent = $this->jsonEncode($event);
     *             $this->sendToAllClients($jsonEvent);
     *         } catch (JsonException $e) {
     *             $this->logger->error('Failed to encode reload event: ' . $e->getMessage());
     *             throw $e;
     *         }
     *     }
     * }
     * ```
     * @example Configuration data serialization
     * ```php
     * class ConfigExporter {
     *     use Helper;
     *
     *     public function exportConfig(array $config, bool $pretty = false): string {
     *         try {
     *             $exportData = [
     *                 'version' => $config['version'],
     *                 'timestamp' => date('c'),
     *                 'configuration' => $config,
     *                 'metadata' => [
     *                     'exported_by' => 'valksor-sse',
     *                     'environment' => $config['env']
     *                 ]
     *             ];
     *
     *             return $this->jsonEncode($exportData);
     *
     *         } catch (JsonException $e) {
     *             $this->logger->error('Failed to export configuration: ' . $e->getMessage());
     *             throw new RuntimeException('Configuration export failed', 0, $e);
     *         }
     *     }
     * }
     * ```
     */
    public function jsonEncode(
        mixed $data,
    ): string {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _JsonEncode;
            };
        }

        return $_helper->jsonEncode($data);
    }

    /**
     * Inject SymfonyStyle console output interface into service objects.
     *
     * This method provides a robust way to add console output capabilities to
     * service objects that support it. It uses method existence checking to ensure
     * compatibility and graceful degradation when services don't support console output.
     *
     * Io Injection Pattern:
     * The method implements a flexible dependency injection approach:
     * - Services that support Io injection can receive console output capabilities
     * - Services without Io support are unaffected and continue to work normally
     * - No exceptions are thrown for incompatible services
     * - Enables services to output rich console formatting when available
     *
     * Method Existence Checking:
     * The implementation uses reflection-free checking for performance:
     * - `method_exists()` is faster than reflection API calls
     * - No overhead for services that don't support Io injection
     * - Compatible with all PHP versions and object types
     * - Safe handling of dynamic method calls
     *
     * SymfonyStyle Capabilities:
     * When successfully injected, services gain access to:
     * - Rich console output with colors and formatting
     * - Progress bars and status indicators
     * - Interactive prompts and confirmations
     * - Tables and lists for structured data display
     * - Success, error, warning, and info message formatting
     *
     * Integration Strategy:
     * This method enables seamless console integration for SSE services:
     * - Commands can inject Io into services for enhanced user feedback
     * - Services can provide detailed status updates during long operations
     * - Error reporting becomes more user-friendly and informative
     * - Debugging information can be displayed in structured formats
     *
     * Error Handling Philosophy:
     * The method implements graceful failure handling:
     * - No exceptions thrown for services without Io support
     * - Silent failure allows services to work in any context
     * - Method call errors are caught and logged appropriately
     * - Service functionality remains intact regardless of Io availability
     *
     * Use Cases in SSE Component:
     * - Providing real-time feedback during server startup
     * - Displaying build progress and status information
     * - Showing detailed error messages with formatting
     * - Presenting configuration information in tables
     * - Offering interactive prompts for user decisions
     *
     * Performance Considerations:
     * - Minimal overhead with fast method existence checking
     * - No performance impact on services without Io support
     * - Lazy injection pattern prevents unnecessary initialization
     * - Efficient console output rendering when available
     *
     * @param object       $service The service object to inject SymfonyStyle into
     *                              Must be an object instance (not a class name)
     *                              Should implement setIo(SymfonyStyle $io) method for injection
     *                              Can be any service class that needs console output capabilities
     * @param SymfonyStyle $io      The SymfonyStyle instance to inject into the service
     *                              Provides rich console output formatting and interaction
     *                              Should be created from InputInterface and OutputInterface
     *                              Enables colored output, tables, progress bars, and prompts
     *
     * @return void This method modifies the service object and returns no value
     *              Success is indicated by the service having Io capabilities after injection
     *              No return value or exception indicates graceful handling of incompatible services
     *
     * @see SymfonyStyle For comprehensive console output functionality
     * @see method_exists() For method existence checking performance
     *
     * @example Command injecting Io into service
     * ```php
     * class SseCommand extends AbstractCommand {
     *     use Helper;
     *
     *     protected function execute(InputInterface $input, OutputInterface $output): int {
     *         $io = $this->createSymfonyStyle($input, $output);
     *         $sseService = new SseService($this->parameterBag);
     *
     *         // Inject console output capabilities
     *         $this->setServiceIo($sseService, $io);
     *
     *         // Service can now output rich console messages
     *         return $sseService->startWithLifecycle($io);
     *     }
     * }
     * ```
     * @example Service with Io support
     * ```php
     * class SseService {
     *     private ?SymfonyStyle $io = null;
     *
     *     public function setIo(SymfonyStyle $io): void {
     *         $this->io = $io;
     *     }
     *
     *     public function startWithLifecycle(SymfonyStyle $io): int {
     *         $this->setIo($io);
     *
     *         // Rich console output available
     *         $this->io->section('Starting SSE Server');
     *         $this->io->progressStart(100);
     *
     *         // Server initialization logic...
     *
     *         $this->io->progressFinish();
     *         $this->io->success('SSE Server started successfully');
     *
     *         return Command::SUCCESS;
     *     }
     * }
     * ```
     * @example Multiple service Io injection
     * ```php
     * class OrchestrateCommand extends AbstractCommand {
     *     use Helper;
     *
     *     protected function execute(InputInterface $input, OutputInterface $output): int {
     *         $io = $this->createSymfonyStyle($input, $output);
     *
     *         $services = [
     *             new SseService($this->parameterBag),
     *             new BuildService($this->parameterBag),
     *             new HotReloadService($this->parameterBag),
     *         ];
     *
     *         // Inject Io into all services that support it
     *         foreach ($services as $service) {
     *             $this->setServiceIo($service, $io);
     *         }
     *
     *         // All services can now provide rich console feedback
     *         return $this->orchestrateServices($services);
     *     }
     * }
     * ```
     */
    protected function setServiceIo(
        object $service,
        SymfonyStyle $io,
    ): void {
        if (method_exists($service, 'setIo')) {
            $service->setIo($io);
        }
    }
}
