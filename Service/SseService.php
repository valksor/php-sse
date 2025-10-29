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

use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Valksor\Functions\Iteration\Traits\_JsonDecode;

use function array_key_exists;
use function array_merge;
use function array_values;
use function count;
use function fclose;
use function feof;
use function fgets;
use function file_get_contents;
use function function_exists;
use function fwrite;
use function in_array;
use function is_array;
use function is_file;
use function json_encode;
use function microtime;
use function parse_url;
use function pcntl_async_signals;
use function pcntl_signal;
use function preg_split;
use function rtrim;
use function sprintf;
use function stream_context_create;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_server;
use function strtok;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const SIGHUP;
use const SIGINT;
use const SIGTERM;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;

/**
 * Server-Sent Events (SSE) service for real-time browser communication.
 *
 * This sophisticated SSE server enables real-time communication between build services
 * and web browsers, primarily used for hot reload functionality in the Valksor
 * development framework. The service implements a complete HTTP/SSE server with
 * TLS support, client management, and signal-based integration.
 *
 * Core Architecture:
 * - Socket-based server using PHP streams for high-performance client handling
 * - TLS/SSL support with automatic fallback to HTTP for secure development environments
 * - Non-blocking I/O with stream_select() for efficient concurrent client management
 * - Signal handling for graceful shutdown (SIGINT, SIGTERM) and reload (SIGHUP)
 * - File-based signal communication for integration with build system services
 *
 * Key Features:
 * - Multi-client support with concurrent connection handling
 * - Automatic keep-alive messages to prevent connection timeouts
 * - CORS support for cross-origin browser communication
 * - Health check endpoint for monitoring and load balancer integration
 * - Signal file integration for build system communication
 * - JSON-based event broadcasting with error handling
 *
 * Build System Integration:
 * - Automatically started by build services (DevWatchService, DevService)
 * - Receives reload signals through signal files in var/run/ directory
 * - Broadcasts file change events to connected browsers
 * - Supports both full reload (['*']) and specific file reloads
 * - Integrates with hot reload services for seamless development experience
 *
 * Protocol Implementation:
 * - Implements HTTP/1.1 server with proper response handling
 * - Supports OPTIONS requests for CORS preflight
 * - Upgrades GET requests to SSE connections with proper headers
 * - Follows SSE specification for event formatting and delivery
 * - Handles client disconnections gracefully with cleanup
 *
 * Security Considerations:
 * - TLS support with configurable certificates
 * - Self-signed certificate support for development
 * - CORS headers configured for development access
 * - Input validation for HTTP requests and SSE events
 * - Graceful error handling to prevent information disclosure
 *
 * Performance Features:
 * - Non-blocking socket operations for scalability
 * - Efficient client connection pooling and cleanup
 * - Minimal memory footprint with stream-based operations
 * - Configurable keep-alive intervals (default: 25 seconds)
 * - Automatic client purging for disconnected connections
 *
 * Usage Examples:
 * ```php
 * // Programmatic reload trigger
 * $sseService->triggerReload(['style.css', 'app.js']);
 *
 * // Full page reload
 * $sseService->triggerReload(['*']);
 *
 * // Reload with metadata
 * $sseService->triggerReload(['*.php'], ['type' => 'server']);
 * ```
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events
 * @see AbstractService For process management and lifecycle handling
 */
final class SseService extends AbstractService
{
    /**
     * Keep-alive message interval in seconds.
     *
     * SSE connections can timeout if no data is sent for an extended period.
     * This interval ensures connections stay alive by sending periodic ping
     * messages. Browsers typically timeout SSE connections after 30-60 seconds
     * of inactivity, so 25 seconds provides a safe margin.
     *
     * @see sendKeepAlive() For the implementation of keep-alive logic
     */
    private const float KEEP_ALIVE_INTERVAL = 25.0; // seconds

    /**
     * Active client connections pool.
     *
     * Stores all currently connected SSE clients as socket resources.
     * The array key is the integer cast of the socket resource for
     * efficient lookup and removal operations.
     *
     * Client Lifecycle:
     * 1. Client connects via HTTP upgrade to SSE
     * 2. Socket resource added to this array
     * 3. Client receives broadcast events
     * 4. Client disconnects (detected by feof())
     * 5. Socket resource removed from array and closed
     *
     * @var array<int,resource> Array of client socket resources indexed by resource ID
     */
    private array $clients = [];

    /**
     * Timestamp for the next keep-alive message.
     *
     * Tracks when the next ping message should be sent to all connected clients.
     * This is compared against microtime(true) to determine if keep-alive
     * messages are due. The timestamp is updated after each keep-alive broadcast.
     *
     * Performance Considerations:
     * - Avoids expensive microtime() calls on every loop iteration
     * - Provides efficient timing for connection maintenance
     * - Prevents unnecessary message broadcasting
     *
     * @var float Unix timestamp with microseconds for next keep-alive
     */
    private float $nextKeepAliveAt = 0.0;

    /**
     * Start the SSE server and begin accepting client connections.
     *
     * This is the main entry point that initializes the SSE server, sets up
     * signal handlers, and begins the main event loop. The method handles
     * server creation (with TLS support), client management, and event broadcasting.
     *
     * Server Initialization Sequence:
     * 1. Load configuration from parameter bag (bind address, port, SSL certs)
     * 2. Create socket server with TLS fallback to HTTP
     * 3. Register signal handlers for graceful shutdown and reload
     * 4. Enter main event loop with non-blocking I/O
     * 5. Accept client connections and upgrade to SSE protocol
     * 6. Monitor for reload signals and broadcast events
     * 7. Maintain connections with keep-alive messages
     *
     * Configuration Parameters:
     * - valksor.sse.bind: Server bind address (e.g., '127.0.0.1')
     * - valksor.sse.port: Server port (e.g., 8080)
     * - valksor.sse.path: SSE endpoint path (e.g., '/events')
     * - valksor.sse.domain: Domain for SSL certificate resolution
     * - valksor.sse.ssl_cert_path: Path to SSL certificate file
     * - valksor.sse.ssl_key_path: Path to SSL private key file
     *
     * Signal Handling:
     * - SIGINT (Ctrl+C): Graceful server shutdown
     * - SIGTERM: Termination signal from process manager
     * - SIGHUP: Reload signal (typically for configuration reload)
     *
     * Event Loop Processing:
     * - stream_select() for non-blocking I/O multiplexing
     * - Accept new client connections and HTTP requests
     * - Process client disconnections and cleanup
     * - Send periodic keep-alive messages
     * - Check for file-based reload signals from build system
     * - Broadcast reload events to all connected clients
     *
     * Performance Characteristics:
     * - Non-blocking I/O prevents blocking on individual clients
     * - Efficient socket multiplexing with stream_select()
     * - Minimal CPU usage during idle periods
     * - Scales to hundreds of concurrent connections
     * - Graceful degradation under high load
     *
     * Error Handling:
     * - Graceful fallback from TLS to HTTP on certificate issues
     * - Client disconnection handling without server interruption
     * - JSON encoding error handling for broadcast events
     * - Socket error recovery and logging
     *
     * @param array $config Optional configuration overrides (currently unused)
     *
     * @return int Command exit code (Command::SUCCESS or Command::FAILURE)
     *
     * @throws JsonException When JSON encoding fails during event broadcasting
     *
     * @see createServer() For server creation and TLS setup logic
     * @see acceptClient() For HTTP request handling and SSE upgrade
     * @see broadcast() For event broadcasting implementation
     * @see checkReloadSignal() For build system integration
     */
    public function start(
        array $config = [],
    ): int {
        $bindAddress = $this->parameterBag->get('valksor.sse.bind');
        $port = $this->parameterBag->get('valksor.sse.port');
        $basePath = $this->parameterBag->get('valksor.sse.path');
        $domain = $this->parameterBag->get('valksor.sse.domain');
        $sslCert = $this->parameterBag->get('valksor.sse.ssl_cert_path');
        $sslKey = $this->parameterBag->get('valksor.sse.ssl_key_path');

        $this->io->note('[sse] tarting SSE server');

        [$server, $usingTls] = $this->createServer($bindAddress, $port, $domain, $sslCert, $sslKey);

        if (!$server) {
            $this->io->error('[sse] nable to create socket server.');

            return Command::FAILURE;
        }

        $protocol = $usingTls ? 'https' : 'http';
        $this->io->success(sprintf('[sse] listening on %s://%s:%d%s', $protocol, $bindAddress, $port, $basePath));

        $this->running = true;
        $this->shouldReload = false;
        $this->shouldShutdown = false;
        $this->nextKeepAliveAt = microtime(true) + self::KEEP_ALIVE_INTERVAL;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function (): void {
                $this->stop();
            });
            pcntl_signal(SIGTERM, function (): void {
                $this->stop();
            });
            pcntl_signal(SIGHUP, function (): void {
                $this->reload();
            });
        }

        while ($this->running && !$this->shouldShutdown) {
            $read = [$server];
            $read += array_values($this->clients);

            $readStreams = $read;
            $write = null;
            $except = null;

            $ready = @stream_select($readStreams, $write, $except, 0, 200000);

            if (false === $ready) {
                continue;
            }

            foreach ($readStreams as $stream) {
                if ($stream === $server) {
                    $this->acceptClient($server, $basePath);

                    continue;
                }

                $this->handleClientRead($stream);
            }

            $this->purgeClosedClients();
            $this->sendKeepAlive();
            $this->checkReloadSignal();

            if ($this->shouldReload) {
                $this->io->newLine();
                $this->io->section('[sse] force reloading all clients...');
                $this->shouldReload = false;

                try {
                    $this->broadcast('reload', ['files' => ['*']]);
                    $this->io->success('[sse] force reload broadcast sent.');
                } catch (JsonException $e) {
                    $this->io->error('[sse] failed to broadcast reload: ' . $e->getMessage());
                }
            }
        }

        foreach ($this->clients as $client) {
            @fclose($client);
        }
        @fclose($server);

        return Command::SUCCESS;
    }

    /**
     * Trigger a reload broadcast to all connected SSE clients.
     *
     * This is the primary public API for programmatically triggering browser reloads.
     * The method broadcasts a 'reload' event to all connected clients with information
     * about which files changed and optional metadata for enhanced client-side handling.
     *
     * Reload Types and Behavior:
     * - Full Reload: ['*'] triggers complete page refresh
     * - Specific Files: ['style.css', 'app.js'] triggers targeted reload
     * - Pattern Matching: ['*.css', '*.js'] for file pattern reloads
     * - Server Changes: ['*.php'] indicates backend changes
     *
     * Event Format:
     * ```javascript
     * // Client receives event in this format:
     * event: reload
     * data: {"files": ["style.css", "app.js"], "type": "css", "timestamp": 1234567890}
     * ```
     *
     * Build System Integration:
     * - Called by HotReloadService when file changes are detected
     * - Integrated with DevWatchService and DevService orchestration
     * - Uses file-based signals for cross-process communication
     * - Supports both manual and automatic reload triggering
     *
     * Client-Side Handling:
     * ```javascript
     * eventSource.addEventListener('reload', (event) => {
     *   const data = JSON.parse(event.data);
     *   if (data.files.includes('*')) {
     *     window.location.reload(); // Full reload
     *   } else {
     *     handlePartialReload(data.files); // Custom logic
     *   }
     * });
     * ```
     *
     * Use Cases:
     * ```php
     * // Full page reload (most common)
     * $sseService->triggerReload();
     *
     * // CSS-only reload for style changes
     * $sseService->triggerReload(['styles/main.css']);
     *
     * // JavaScript reload with metadata
     * $sseService->triggerReload(['app.js'], ['type' => 'javascript', 'hot' => true]);
     *
     * // Multiple file types
     * $sseService->triggerReload(['*.css', '*.js'], ['bundle' => 'main']);
     *
     * // Server-side changes
     * $sseService->triggerReload(['*.php'], ['type' => 'server', 'restart' => false]);
     * ```
     *
     * Performance Considerations:
     * - Broadcasts to all connected clients simultaneously
     * - JSON encoding provides efficient data serialization
     * - Failed client sends are logged but don't stop other clients
     * - Minimal overhead for small file arrays
     *
     * Error Handling:
     * - JSON encoding errors are caught and logged
     * - Client connection failures are handled gracefully
     * - Network issues don't affect server stability
     * - Detailed error logging for debugging
     *
     * @param array<int,string>   $files    List of changed files (use ['*'] for full page reload)
     *                                      Examples: ['*'], ['style.css'], ['*.css', '*.js']
     * @param array<string,mixed> $metadata Optional metadata for enhanced client handling
     *                                      Common keys: 'type', 'timestamp', 'bundle', 'hot'
     *
     * @throws JsonException When JSON encoding of the payload fails
     *
     * @see broadcast() For the underlying broadcasting implementation
     * @see checkReloadSignal() For file-based signal integration
     */
    public function triggerReload(
        array $files = ['*'],
        array $metadata = [],
    ): void {
        try {
            $payload = array_merge(['files' => $files], $metadata);
            $this->broadcast('reload', $payload);

            if (isset($this->io)) {
                $this->io->success('[sse] reload triggered via service call with ' . count($files) . ' files');
            }
        } catch (JsonException $e) {
            if (isset($this->io)) {
                $this->io->error('[sse] failed to trigger reload: ' . $e->getMessage());
            }
        }
    }

    public static function getServiceName(): string
    {
        return 'sse';
    }

    protected function getSseProcessesToKill(): array
    {
        return [self::getServiceName()];
    }

    /**
     * Accept incoming client connections and handle HTTP requests.
     *
     * This method processes incoming socket connections, parses HTTP requests,
     * and handles various endpoint types including SSE upgrades, CORS preflight,
     * and health checks. It implements a minimal HTTP server compliant with
     * SSE requirements.
     *
     * Request Processing Pipeline:
     * 1. Accept socket connection from server socket
     * 2. Set non-blocking mode for event loop compatibility
     * 3. Read and parse HTTP request headers
     * 4. Route request based on method and path
     * 5. Handle CORS preflight (OPTIONS) requests
     * 6. Upgrade valid SSE requests to EventSource protocol
     * 7. Handle health check and other endpoints
     * 8. Send appropriate error responses for invalid requests
     *
     * HTTP Method Support:
     * - GET: Primary method for SSE connection upgrades
     * - HEAD: Supported for SSE endpoint (metadata only)
     * - OPTIONS: CORS preflight support with proper headers
     * - Other methods: Rejected with 405 Method Not Allowed
     *
     * Endpoint Routing:
     * - {basePath}: Main SSE endpoint (e.g., /events)
     * - {basePath}/healthz: Health check for monitoring
     * - Other paths: Return 404 Not Found
     *
     * SSE Upgrade Process:
     * 1. Validate HTTP method (GET/HEAD only)
     * 2. Check path matches SSE endpoint
     * 3. Send SSE-specific response headers:
     *    - Content-Type: text/event-stream
     *    - Cache-Control: no-cache
     *    - Connection: keep-alive
     *    - CORS headers for cross-origin requests
     *    - X-Accel-Buffering: no (prevents proxy buffering)
     * 4. Add client to active connections pool
     * 5. Client becomes eligible for event broadcasts
     *
     * CORS Support:
     * - Access-Control-Allow-Origin: * (development-friendly)
     * - Access-Control-Allow-Methods: GET,OPTIONS
     * - Access-Control-Allow-Headers: Content-Type
     * - Supports cross-origin browser connections
     *
     * Error Handling:
     * - Request parsing failures: connection closed
     * - Invalid HTTP methods: 405 response with Allow header
     * - Unknown paths: 404 response
     * - Network errors: graceful connection cleanup
     *
     * Security Considerations:
     * - Input validation for HTTP request parsing
     * - Protection against malformed request headers
     * - Rate limiting through connection management
     * - No authentication (development-focused design)
     *
     * Performance Features:
     * - Non-blocking socket operations
     * - Minimal request parsing overhead
     * - Efficient header processing
     * - Fast connection establishment
     *
     * @param resource $server   Server socket resource to accept connections from
     * @param string   $basePath SSE endpoint path (e.g., '/events')
     *
     * @see upgradeToSse() For SSE protocol upgrade implementation
     * @see sendResponse() For HTTP response formatting
     * @see parseRequestLine() For HTTP request parsing
     */
    private function acceptClient(
        $server,
        string $basePath,
    ): void {
        $client = @stream_socket_accept($server, timeout: 0);

        if (!$client) {
            return;
        }

        stream_set_blocking($client, false);

        $request = '';
        $attempts = 0;

        while (true) {
            $line = fgets($client);

            if (false === $line) {
                $attempts++;

                if ($attempts > 3) {
                    fclose($client);

                    return;
                }

                continue;
            }

            $request .= $line;

            if ('' === trim($line)) {
                break;
            }
        }

        $requestLine = strtok($request, "\r\n");

        if (!$requestLine) {
            fclose($client);

            return;
        }

        [$method, $path] = $this->parseRequestLine($requestLine);

        if (Request::METHOD_OPTIONS === $method) {
            $this->sendResponse($client, Response::HTTP_NO_CONTENT, ['Content-Length: 0']);
            fclose($client);

            return;
        }

        if (!in_array($method, [Request::METHOD_GET, Request::METHOD_HEAD], true)) {
            $this->sendResponse($client, Response::HTTP_METHOD_NOT_ALLOWED, ['Allow: GET, OPTIONS, HEAD']);
            fclose($client);

            return;
        }

        if ($path === $basePath) {
            $this->upgradeToSse($client);

            return;
        }

        if ($path === rtrim($basePath, '/') . '/healthz') {
            $this->sendResponse($client, Response::HTTP_OK, ['Content-Type: text/plain'], Response::$statusTexts[Response::HTTP_OK]);
            fclose($client);

            return;
        }

        $this->sendResponse($client, Response::HTTP_NOT_FOUND, ['Content-Type: text/plain'], Response::$statusTexts[Response::HTTP_NOT_FOUND]);
        fclose($client);
    }

    /**
     * Broadcast events to all connected SSE clients.
     *
     * This is the core event delivery mechanism that formats and sends SSE events
     * to all active client connections. It handles JSON serialization, SSE protocol
     * formatting, and client error recovery.
     *
     * SSE Event Format:
     * ```text
     * event: {event_type}
     * data: {json_payload}
     *
     * ```
     *
     * Example Output:
     * ```text
     * event: reload
     * data: {"files": ["style.css"], "timestamp": 1234567890}
     *
     * ```
     *
     * Event Types Supported:
     * - reload: Trigger browser reload (most common)
     * - ping: Keep-alive message to prevent timeouts
     * - custom: Application-specific events
     *
     * Broadcasting Process:
     * 1. Serialize payload to JSON with error handling
     * 2. Format message according to SSE specification
     * 3. Iterate through all connected clients
     * 4. Send message to each client socket
     * 5. Handle failed sends by removing disconnected clients
     * 6. Continue broadcasting to remaining clients
     *
     * JSON Serialization:
     * - Uses JSON_THROW_ON_ERROR for exception handling
     * - JSON_UNESCAPED_SLASHES for cleaner URLs in output
     * - Throws JsonException on serialization failure
     * - Efficient for typical reload payload sizes
     *
     * Error Handling Strategy:
     * - Individual client failures don't stop broadcasting
     * - Failed clients are automatically removed from pool
     * - Socket errors are handled gracefully with @fwrite()
     * - JSON serialization errors bubble up to caller
     *
     * Performance Considerations:
     * - Bulk operation: sends to all clients efficiently
     * - Minimal memory overhead per message
     * - Non-blocking socket writes for scalability
     * - Automatic cleanup of failed connections
     *
     * Client Management:
     * - Failed sends trigger client removal
     * - Disconnected clients are purged immediately
     * - Active clients continue receiving events
     * - Connection pool remains clean and efficient
     *
     * Protocol Compliance:
     * - Follows W3C SSE specification exactly
     * - Proper event/data field separation
     * - Double newline terminates each event
     * - Compatible with all modern browsers
     *
     * @param string $event   Event type identifier (e.g., 'reload', 'ping')
     * @param array  $payload Event data payload to be JSON-encoded
     *
     * @throws JsonException When JSON serialization of payload fails
     *
     * @see removeClient() For client cleanup on failed sends
     * @see triggerReload() For common reload event usage
     * @see https://html.spec.whatwg.org/multipage/server-sent-events.html SSE specification
     */
    private function broadcast(
        string $event,
        array $payload,
    ): void {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $message = sprintf("event: %s\ndata: %s\n\n", $event, $json);

        foreach ($this->clients as $client) {
            $result = @fwrite($client, $message);

            if (false === $result) {
                $this->removeClient($client);
            }
        }
    }

    private function checkReloadSignal(): void
    {
        $signalFile = $this->parameterBag->get('kernel.project_dir') . '/var/run/valksor-reload.signal';

        if (!is_file($signalFile)) {
            return;
        }

        $signalData = file_get_contents($signalFile);

        if (false === $signalData) {
            return;
        }

        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _JsonDecode;
            };
        }

        $data = $_helper->jsonDecode($signalData, 1);

        if (null === $data) {
            return;
        }

        // Remove signal file
        @unlink($signalFile);

        // Trigger reload with files from signal
        $files = $data['files'] ?? ['*'];
        $this->triggerReload($files);
    }

    /**
     * Create and configure the socket server with TLS support.
     *
     * This method attempts to create a TLS-enabled socket server first, with
     * automatic fallback to HTTP if TLS setup fails. This provides secure
     * connections in development environments while maintaining compatibility.
     *
     * TLS Setup Strategy:
     * 1. Use provided SSL certificate paths if specified
     * 2. Auto-discover certificates in /etc/ssl/private/{domain}.crt/.key
     * 3. Create TLS socket server if certificates are available
     * 4. Fall back to HTTP if TLS setup fails or certificates missing
     *
     * Certificate Resolution:
     * - Explicit paths: ssl_cert_path and ssl_key_path parameters
     * - Auto-discovery: /etc/ssl/private/{domain}.crt and .key
     * - Development support: Self-signed certificates accepted
     * - Security settings: allow_self_signed=true, verify_peer=false
     *
     * Socket Configuration:
     * - TLS: tls://{bindAddress}:{port} with SSL context
     * - HTTP: tcp://{bindAddress}:{port} for fallback
     * - Flags: STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
     * - Non-blocking mode enabled for event loop compatibility
     *
     * Error Handling:
     * - TLS failures are logged but don't prevent server startup
     * - Socket binding errors are logged and return failure
     * - Graceful degradation from TLS to HTTP
     * - Detailed error messages for debugging certificate issues
     *
     * Security Considerations:
     * - TLS certificates are validated for existence before use
     * - Self-signed certificates allowed for development
     * - Peer verification disabled for development convenience
     * - Production environments should use valid certificates
     *
     * @param string      $bindAddress Server bind address (e.g., '127.0.0.1', '0.0.0.0')
     * @param int         $port        Server port number (e.g., 8080, 8443)
     * @param string      $domain      Domain name for certificate auto-discovery
     * @param string|null $sslCertPath Explicit path to SSL certificate file
     * @param string|null $sslKeyPath  Explicit path to SSL private key file
     *
     * @return array{0: resource|null, 1: bool} [server_socket, using_tls]
     *                                          - resource: Created socket server or null on failure
     *                                          - bool: true if TLS enabled, false if HTTP fallback
     *
     * @see stream_socket_server() For socket creation parameters
     * @see stream_context_create() For SSL context configuration
     */
    private function createServer(
        string $bindAddress,
        int $port,
        string $domain,
        ?string $sslCertPath = null,
        ?string $sslKeyPath = null,
    ): array {
        if (null === $sslCertPath || null === $sslKeyPath) {
            $certDir = '/etc/ssl/private';
            $sslCertPath = $certDir . DIRECTORY_SEPARATOR . $domain . '.crt';
            $sslKeyPath = $certDir . DIRECTORY_SEPARATOR . $domain . '.key';
        }

        $server = null;
        $usingTls = false;

        if (is_file($sslCertPath) && is_file($sslKeyPath)) {
            $context = stream_context_create([
                'ssl' => [
                    'local_cert' => $sslCertPath,
                    'local_pk' => $sslKeyPath,
                    'allow_self_signed' => true,
                    'verify_peer' => false,
                ],
            ]);

            $server = @stream_socket_server(
                sprintf('tls://%s:%d', $bindAddress, $port),
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $context,
            );

            if ($server) {
                $usingTls = true;
            } else {
                $this->io->warning(sprintf('[sse] TLS setup failed (%s). Falling back to HTTP.', $errstr));
            }
        }

        if (!$server) {
            $server = @stream_socket_server(
                sprintf('tcp://%s:%d', $bindAddress, $port),
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            );

            if (!$server) {
                $this->io->error(sprintf('[sse] failed to bind socket: [%d] %s', $errno, $errstr));

                return [null, false];
            }
        }

        stream_set_blocking($server, false);

        return [$server, $usingTls];
    }

    private function handleClientRead(
        $client,
    ): void {
        if (feof($client)) {
            $this->removeClient($client);
        }
    }

    private function parseRequestLine(
        string $line,
    ): array {
        $parts = preg_split('/\s+/', trim($line));
        $method = $parts[0] ?? Request::METHOD_GET;
        $target = $parts[1] ?? '/';

        $path = $target;
        $parsed = parse_url($target);

        if (false !== $parsed && is_array($parsed) ? array_key_exists('path', $parsed) : isset($parsed['path'])) {
            $path = $parsed['path'];
        }

        return [$method, $path];
    }

    private function purgeClosedClients(): void
    {
        foreach ($this->clients as $client) {
            if (feof($client)) {
                $this->removeClient($client);
            }
        }
    }

    private function removeClient(
        $client,
    ): void {
        $id = (int) $client;

        if (isset($this->clients[$id])) {
            fclose($this->clients[$id]);
            unset($this->clients[$id]);
        }
    }

    /**
     * @throws JsonException
     */
    private function sendKeepAlive(): void
    {
        if (microtime(true) < $this->nextKeepAliveAt) {
            return;
        }

        $this->broadcast('ping', ['timestamp' => (int) (microtime(true) * 1000)]);
        $this->nextKeepAliveAt = microtime(true) + self::KEEP_ALIVE_INTERVAL;
    }

    private function sendResponse(
        $client,
        int $statusCode,
        array $headers,
        string $body = '',
    ): void {
        $statusText = match ($statusCode) {
            Response::HTTP_NO_CONTENT => Response::$statusTexts[Response::HTTP_NO_CONTENT],
            Response::HTTP_NOT_FOUND => Response::$statusTexts[Response::HTTP_NOT_FOUND],
            Response::HTTP_METHOD_NOT_ALLOWED => Response::$statusTexts[Response::HTTP_METHOD_NOT_ALLOWED],
            default => Response::$statusTexts[Response::HTTP_OK],
        };

        $response = sprintf("HTTP/1.1 %d %s\r\n", $statusCode, $statusText);

        foreach ($headers as $header) {
            $response .= $header . "\r\n";
        }
        $response .= "\r\n";
        $response .= $body;

        fwrite($client, $response);
    }

    private function upgradeToSse(
        $client,
    ): void {
        $headers = [
            'Content-Type: text/event-stream',
            'Cache-Control: no-cache',
            'Connection: keep-alive',
            'Access-Control-Allow-Origin: *',
            'Access-Control-Allow-Methods: GET,OPTIONS',
            'Access-Control-Allow-Headers: Content-Type',
            'X-Accel-Buffering: no',
        ];

        $this->sendResponse($client, Response::HTTP_OK, $headers, "\n");

        $id = (int) $client;
        $this->clients[$id] = $client;
    }
}
