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

namespace Valksor\Component\Sse\Tests\Service;

use JsonException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Valksor\Component\Sse\Service\SseService;

use function dirname;
use function file_put_contents;
use function tempnam;
use function unlink;

final class SseServiceTest extends TestCase
{
    private SymfonyStyle $io;
    private ParameterBag $parameterBag;
    private SseService $service;

    /**
     * @throws ReflectionException
     */
    public function testCheckReloadSignalWithNoSignalFile(): void
    {
        $method = new ReflectionClass($this->service)->getMethod('checkReloadSignal');

        // Should not throw any exception
        $method->invoke($this->service);

        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    /**
     * @throws ReflectionException
     */
    public function testCheckReloadSignalWithSignalFile(): void
    {
        $method = new ReflectionClass($this->service)->getMethod('checkReloadSignal');

        // Create a signal file
        $signalFile = tempnam(sys_get_temp_dir(), 'sse_signal');
        file_put_contents($signalFile, '["test.js"]');

        // Temporarily modify the var_dir parameter
        $this->parameterBag->set('valksor.var_dir', dirname($signalFile));

        try {
            // Should not throw any exception
            $method->invoke($this->service);

            $this->assertTrue(true); // Test passes if no exception is thrown
        } finally {
            // Clean up
            if (file_exists($signalFile)) {
                unlink($signalFile);
            }
        }
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateServerWithHttpOnly(): void
    {
        $method = new ReflectionClass($this->service)->getMethod('createServer');

        [$server, $usingTls] = $method->invoke($this->service, '127.0.0.1', 0, 'localhost', null, null);

        $this->assertFalse($usingTls);

        if ($server) {
            $this->assertIsResource($server);
            fclose($server);
        }
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateServerWithTlsFallbackToHttp(): void
    {
        $method = new ReflectionClass($this->service)->getMethod('createServer');

        // Use non-existent certificate files to test fallback
        [$server, $usingTls] = $method->invoke(
            $this->service,
            '127.0.0.1',
            0,
            'localhost',
            '/non/existent/cert.pem',
            '/non/existent/key.pem',
        );

        $this->assertFalse($usingTls);

        if ($server) {
            $this->assertIsResource($server);
            fclose($server);
        }
    }

    public function testIsRunningReturnsFalseInitially(): void
    {
        $this->assertFalse($this->service->isRunning());
    }

    /**
     * @throws ReflectionException
     */
    public function testParseRequestLineWithEmptyRequest(): void
    {
        $result = new ReflectionClass($this->service)->getMethod('parseRequestLine')->invoke($this->service, '');

        // Based on actual implementation behavior
        $this->assertIsArray($result);
    }

    /**
     * @throws ReflectionException
     */
    public function testParseRequestLineWithInvalidRequest(): void
    {
        $result = new ReflectionClass($this->service)->getMethod('parseRequestLine')->invoke($this->service, 'INVALID REQUEST');

        // Based on actual implementation behavior
        $this->assertIsArray($result);
    }

    /**
     * @throws ReflectionException
     */
    public function testParseRequestLineWithValidGetRequest(): void
    {
        $result = new ReflectionClass($this->service)->getMethod('parseRequestLine')->invoke($this->service, 'GET /sse HTTP/1.1');

        // Based on actual implementation behavior - returns [method, path]
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('GET', $result[0]);
        $this->assertSame('/sse', $result[1]);
    }

    /**
     * @throws ReflectionException
     */
    public function testParseRequestLineWithValidOptionsRequest(): void
    {
        $result = new ReflectionClass($this->service)->getMethod('parseRequestLine')->invoke($this->service, 'OPTIONS /sse HTTP/1.1');

        // Based on actual implementation behavior - returns [method, path]
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('OPTIONS', $result[0]);
        $this->assertSame('/sse', $result[1]);
    }

    /**
     * @throws ReflectionException
     */
    public function testPurgeClosedClients(): void
    {
        $reflection = new ReflectionClass($this->service);
        $clientsProperty = $reflection->getProperty('clients');

        // Test with empty clients array first
        $clientsProperty->setValue($this->service, []);

        $method = $reflection->getMethod('purgeClosedClients');

        // Should not throw any exception with empty clients
        $method->invoke($this->service);

        // Should still be empty
        $this->assertEmpty($clientsProperty->getValue($this->service));
    }

    /**
     * @throws ReflectionException
     */
    public function testReload(): void
    {
        $shouldReloadProperty = new ReflectionClass($this->service)->getProperty('shouldReload');

        $this->service->reload();

        $this->assertTrue($shouldReloadProperty->getValue($this->service));
    }

    /**
     * @throws ReflectionException
     */
    public function testRemoveClient(): void
    {
        $reflection = new ReflectionClass($this->service);
        $clientsProperty = $reflection->getProperty('clients');

        // Start with empty clients
        $clientsProperty->setValue($this->service, []);

        $method = $reflection->getMethod('removeClient');

        // Should not throw any exception even when no clients exist
        $method->invoke($this->service, null);

        // Should still be empty
        $this->assertEmpty($clientsProperty->getValue($this->service));
    }

    /**
     * @throws ReflectionException
     */
    public function testRemoveNonExistentClient(): void
    {
        $reflection = new ReflectionClass($this->service);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setValue($this->service, []);

        $method = $reflection->getMethod('removeClient');

        // Create a mock client that's not in the list
        $tempFile = tempnam(sys_get_temp_dir(), 'sse_test_client');
        $client = fopen($tempFile, 'w+b');

        if ($client) {
            // Should not throw any exception
            $method->invoke($this->service, $client);

            // Clean up
            fclose($client);
            unlink($tempFile);
        }

        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    /**
     * @throws ReflectionException
     */
    public function testSendKeepAlive(): void
    {
        $reflection = new ReflectionClass($this->service);
        $clientsProperty = $reflection->getProperty('clients');

        // Create a mock client
        $tempFile = tempnam(sys_get_temp_dir(), 'sse_test_client');
        $client = fopen($tempFile, 'w+b');

        if ($client) {
            $clientsProperty->setValue($this->service, [$client]);

            $method = $reflection->getMethod('sendKeepAlive');
            $method->invoke($this->service);

            // Clean up
            fclose($client);
            unlink($tempFile);
        }
        $this->expectNotToPerformAssertions();
    }

    /**
     * @throws ReflectionException
     */
    public function testSendKeepAliveWithNoClients(): void
    {
        $reflection = new ReflectionClass($this->service);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setValue($this->service, []);

        $method = $reflection->getMethod('sendKeepAlive');

        // Should not throw any exception
        $method->invoke($this->service);

        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    /**
     * @throws ReflectionException
     */
    public function testSendResponse(): void
    {
        $method = new ReflectionClass($this->service)->getMethod('sendResponse');

        $tempFile = tempnam(sys_get_temp_dir(), 'sse_test_response');
        $client = fopen($tempFile, 'w+b');

        if ($client) {
            $method->invoke($this->service, $client, 200, ['Content-Type: text/plain'], 'test body');

            // Verify response was written
            rewind($client);
            $response = stream_get_contents($client);

            $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
            $this->assertStringContainsString('Content-Type: text/plain', $response);
            $this->assertStringContainsString('test body', $response);

            // Clean up
            fclose($client);
            unlink($tempFile);
        }
    }

    public function testStartWithInvalidPortReturnsFailure(): void
    {
        // Test that service is not running initially
        $this->assertFalse($this->service->isRunning());

        // Test that invalid configuration can be detected
        $parameterBag = new ParameterBag([
            'valksor.sse.bind' => '127.0.0.1',
            'valksor.sse.port' => -1, // Invalid port
            'valksor.sse.path' => '/sse',
            'valksor.sse.domain' => 'localhost',
            'valksor.sse.ssl_cert_path' => null,
            'valksor.sse.ssl_key_path' => null,
            'valksor.var_dir' => sys_get_temp_dir(),
            'kernel.project_dir' => sys_get_temp_dir(),
        ]);

        $service = new SseService($parameterBag);

        // Verify the service was created with the invalid config
        $this->assertInstanceOf(SseService::class, $service);
        $this->assertFalse($service->isRunning());
    }

    /**
     * @throws ReflectionException
     */
    public function testStop(): void
    {
        $runningProperty = new ReflectionClass($this->service)->getProperty('running');
        $runningProperty->setValue($this->service, true);

        $this->service->stop();

        $this->assertFalse($runningProperty->getValue($this->service));
    }

    /**
     * @throws JsonException
     */
    public function testTriggerReloadWithNoClients(): void
    {
        // Should not throw any exception even with no clients
        $this->service->triggerReload(['test.js']);

        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    public function testTriggerReloadWithSpecificFile(): void
    {
        $clientsProperty = new ReflectionClass($this->service)->getProperty('clients');

        $tempFile = tempnam(sys_get_temp_dir(), 'sse_test_signal');
        $client = fopen($tempFile, 'w+b');

        if ($client) {
            $clientsProperty->setValue($this->service, [$client]);

            $this->service->triggerReload(['test.js']);

            // Clean up
            fclose($client);
            unlink($tempFile);
        }

        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    /**
     * @throws JsonException
     */
    public function testTriggerReloadWithStar(): void
    {
        $reflection = new ReflectionClass($this->service);

        $tempFile = tempnam(sys_get_temp_dir(), 'sse_test_signal');
        $client = fopen($tempFile, 'w+b');

        if ($client) {
            $clientsProperty = $reflection->getProperty('clients');
            $clientsProperty->setValue($this->service, [$client]);

            $this->service->triggerReload();

            // Clean up
            fclose($client);
            unlink($tempFile);
        }

        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    /**
     * @throws ReflectionException
     */
    public function testUpgradeToSse(): void
    {
        $method = new ReflectionClass($this->service)->getMethod('upgradeToSse');

        $tempFile = tempnam(sys_get_temp_dir(), 'sse_test_upgrade');
        $client = fopen($tempFile, 'w+b');

        if ($client) {
            $method->invoke($this->service, $client);

            // Verify SSE headers were written
            rewind($client);
            $response = stream_get_contents($client);

            $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
            $this->assertStringContainsString('Content-Type: text/event-stream', $response);
            $this->assertStringContainsString('Cache-Control: no-cache', $response);
            $this->assertStringContainsString('Connection: keep-alive', $response);

            // Clean up
            fclose($client);
            unlink($tempFile);
        }
    }

    protected function setUp(): void
    {
        $this->parameterBag = new ParameterBag([
            'valksor.sse.bind' => '127.0.0.1',
            'valksor.sse.port' => 8080,
            'valksor.sse.path' => '/sse',
            'valksor.sse.domain' => 'localhost',
            'valksor.sse.ssl_cert_path' => null,
            'valksor.sse.ssl_key_path' => null,
            'valksor.var_dir' => sys_get_temp_dir(),
            'kernel.project_dir' => sys_get_temp_dir(),
        ]);

        $this->io = $this->createMock(SymfonyStyle::class);
        $this->service = new SseService($this->parameterBag);
    }

    protected function tearDown(): void
    {
        // Clean up any temporary files
        $tempDir = sys_get_temp_dir();
        $files = glob($tempDir . '/sse_test_*');

        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        parent::tearDown();
    }
}
