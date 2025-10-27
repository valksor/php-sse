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

namespace Valksor\Component\Sse\Service;

use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_key_exists;
use function array_merge;
use function array_values;
use function count;
use function fclose;
use function feof;
use function fgets;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function fwrite;
use function getmypid;
use function is_array;
use function is_file;
use function json_decode;
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

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const SIGHUP;
use const SIGINT;
use const SIGTERM;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;

/**
 * SSE server for programmatic reloads.
 *
 * This service provides SSE server functionality for broadcasting events
 * to connected clients. Use triggerReload() to programmatically trigger
 * reload events from other services.
 */
final class SseService extends AbstractService
{
    private const float KEEP_ALIVE_INTERVAL = 25.0; // seconds

    /** @var array<int,resource> */
    private array $clients = [];
    private float $nextKeepAliveAt = 0.0;
    private bool $running = false;
    private bool $shouldReload = false;
    private bool $shouldShutdown = false;

    public function __construct(
        private readonly ParameterBagInterface $bag,
    ) {
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function reload(): void
    {
        $this->shouldReload = true;
    }

    public function removePidFile(
        string $pidFile,
    ): void {
        if (is_file($pidFile)) {
            @unlink($pidFile);
        }
    }

    public function start(
        array $config = [],
    ): int {
        $bindAddress = $this->bag->get('valksor.sse.bind');
        $port = $this->bag->get('valksor.sse.port');
        $basePath = $this->bag->get('valksor.sse.path');
        $domain = $this->bag->get('valksor.sse.domain');

        $this->io->note('Starting SSE server');

        [$server, $usingTls] = $this->createServer($bindAddress, $port, $domain);

        if (!$server) {
            $this->io->error('Unable to create socket server.');

            return Command::FAILURE;
        }

        $protocol = $usingTls ? 'https' : 'http';
        $this->io->success(sprintf('Listening on %s://%s:%d%s', $protocol, $bindAddress, $port, $basePath));

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
                $this->io->section('Force reloading all clients...');
                $this->shouldReload = false;

                try {
                    $this->broadcast('reload', ['files' => ['*']]);
                    $this->io->success('Force reload broadcast sent.');
                } catch (JsonException $e) {
                    $this->io->error('Failed to broadcast reload: ' . $e->getMessage());
                }
            }
        }

        foreach ($this->clients as $client) {
            @fclose($client);
        }
        @fclose($server);

        return Command::SUCCESS;
    }

    public function stop(): void
    {
        $this->shouldShutdown = true;
        $this->running = false;
    }

    /**
     * Trigger a reload broadcast to all connected clients.
     *
     * @param array<int,string>   $files    List of files that changed (use ['*'] for full reload)
     * @param array<string,mixed> $metadata Optional metadata to include in broadcast
     */
    public function triggerReload(
        array $files = ['*'],
        array $metadata = [],
    ): void {
        try {
            $payload = array_merge(['files' => $files], $metadata);
            $this->broadcast('reload', $payload);

            if (isset($this->io)) {
                $this->io->success('Reload triggered via service call with ' . count($files) . ' files');
            }
        } catch (JsonException $e) {
            if (isset($this->io)) {
                $this->io->error('Failed to trigger reload: ' . $e->getMessage());
            }
        }
    }

    public function writePidFile(
        string $pidFile,
    ): void {
        $pid = getmypid();
        file_put_contents($pidFile, (string) $pid);
    }

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

        if (Request::METHOD_GET !== $method) {
            $this->sendResponse($client, Response::HTTP_METHOD_NOT_ALLOWED, ['Allow: GET, OPTIONS']);
            fclose($client);

            return;
        }

        if ($path === $basePath) {
            $this->upgradeToSse($client, $path);

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
     * @throws JsonException
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
        $signalFile = $this->bag->get('kernel.project_dir') . '/var/run/valksor-reload.signal';

        if (!is_file($signalFile)) {
            return;
        }

        $signalData = file_get_contents($signalFile);

        if (false === $signalData) {
            return;
        }

        $data = json_decode($signalData, true);

        if (null === $data) {
            return;
        }

        // Remove signal file
        @unlink($signalFile);

        // Trigger reload with files from signal
        $files = $data['files'] ?? ['*'];
        $this->triggerReload($files);
    }

    /** @return array{0,1} */
    private function createServer(
        string $bindAddress,
        int $port,
        string $domain,
    ): array {
        $certDir = '/etc/ssl/private';
        $certPath = $certDir . '/' . $domain . '.crt';
        $keyPath = $certDir . '/' . $domain . '.key';

        $server = null;
        $usingTls = false;

        if (is_file($certPath) && is_file($keyPath)) {
            $context = stream_context_create([
                'ssl' => [
                    'local_cert' => $certPath,
                    'local_pk' => $keyPath,
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
                $this->io->warning(sprintf('TLS setup failed (%s). Falling back to HTTP.', $errstr));
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
                $this->io->error(sprintf('Failed to bind socket: [%d] %s', $errno, $errstr));

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
        $method = $parts[0] ?? 'GET';
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
            204 => 'No Content',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            default => 'OK',
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
        string $path,
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

        $this->sendResponse($client, 200, $headers, "\n");

        $id = (int) $client;
        $this->clients[$id] = $client;
    }
}
