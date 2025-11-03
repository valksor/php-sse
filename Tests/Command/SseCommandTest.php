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

namespace Valksor\Component\Sse\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Valksor\Component\Sse\Command\SseCommand;
use Valksor\Component\Sse\Service\SseService;

final class SseCommandTest extends TestCase
{
    private SseCommand $command;

    public function testCommandHasCorrectName(): void
    {
        $this->assertSame('valksor:sse', $this->command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
        $this->assertStringContainsString('SSE', $this->command->getDescription());
    }

    public function testCommandIsConfigured(): void
    {
        $this->assertTrue($this->command->isEnabled());
    }

    protected function setUp(): void
    {
        $parameterBag = new ParameterBag([
            'valksor.sse.bind' => '127.0.0.1',
            'valksor.sse.port' => 8080,
            'valksor.sse.path' => '/sse',
            'valksor.sse.domain' => 'localhost',
            'valksor.sse.ssl_cert_path' => null,
            'valksor.sse.ssl_key_path' => null,
            'valksor.var_dir' => sys_get_temp_dir(),
            'kernel.project_dir' => sys_get_temp_dir(),
        ]);

        $service = new SseService($parameterBag);
        $this->command = new SseCommand($parameterBag, $service);
    }
}
