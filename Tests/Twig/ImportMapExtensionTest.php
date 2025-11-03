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

use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;
use Valksor\Component\Sse\Twig\ImportMapExtension;
use Valksor\Component\Sse\Twig\ImportMapRuntime;

final class ImportMapExtensionTest extends TestCase
{
    private ImportMapExtension $extension;

    public function testExtensionHasNoFilters(): void
    {
        $filters = $this->extension->getFilters();
        $this->assertEmpty($filters);
    }

    public function testExtensionHasNoTags(): void
    {
        $tags = $this->extension->getTokenParsers();
        $this->assertEmpty($tags);
    }

    public function testExtensionHasNoTests(): void
    {
        $tests = $this->extension->getTests();
        $this->assertEmpty($tests);
    }

    public function testExtensionHasOperators(): void
    {
        $operators = $this->extension->getOperators();
        $this->assertIsArray($operators);
        // The extension might have operators, so just check it returns an array
    }

    public function testFunctionNamesAreCorrect(): void
    {
        foreach ($this->extension->getFunctions() as $function) {
            $this->assertInstanceOf(TwigFunction::class, $function);
            $this->assertIsString($function->getName());
            $this->assertNotEmpty($function->getName());
        }
    }

    public function testFunctionsDelegateToRuntime(): void
    {
        foreach ($this->extension->getFunctions() as $function) {
            // Check that the function has a callable
            $callable = $function->getCallable();
            $this->assertIsArray($callable);
            $this->assertCount(2, $callable);
            $this->assertSame(ImportMapRuntime::class, $callable[0]);
        }
    }

    public function testGetFunctions(): void
    {
        $functions = $this->extension->getFunctions();

        $this->assertIsArray($functions);
        $this->assertNotEmpty($functions);

        // Check for expected function names
        $functionNames = array_map(static fn ($function) => $function->getName(), $functions);

        $this->assertContains('valksor_sse_importmap_definition', $functionNames);
        $this->assertContains('valksor_sse_importmap_scripts', $functionNames);
        $this->assertContains('valksor_sse_ping', $functionNames);
    }

    protected function setUp(): void
    {
        $this->extension = new ImportMapExtension();
    }
}
