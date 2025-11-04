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

namespace Valksor\Component\Sse\Tests\Twig;

use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Valksor\Component\Sse\Twig\ImportMapRuntime;

final class ImportMapRuntimeTest extends TestCase
{
    private ImportMapRuntime $runtime;

    public function testPingReturnsBoolean(): void
    {
        $result = $this->runtime->ping();

        $this->assertIsBool($result);
    }

    /**
     * @throws JsonException
     */
    public function testRenderDefinitionReturnsString(): void
    {
        $result = $this->runtime->renderDefinition(['app']);

        $this->assertIsString($result);
    }

    public function testRenderScriptsReturnsString(): void
    {
        $result = $this->runtime->renderScripts(['app']);

        $this->assertIsString($result);
    }

    protected function setUp(): void
    {
        $this->runtime = new ImportMapRuntime(
            $this->createStub(ImportMapGenerator::class),
            $this->createStub(Packages::class),
            $this->createStub(RequestStack::class),
            $this->createStub(ParameterBagInterface::class),
            $this->createStub(HttpClientInterface::class),
        );
    }
}
