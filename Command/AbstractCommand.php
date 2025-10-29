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
use Valksor\Bundle\Command\AbstractCommand as BundleAbstractCommand;
use Valksor\Bundle\ValksorBundle;
use Valksor\Component\Sse\Helper;

use function sprintf;

abstract class AbstractCommand extends BundleAbstractCommand
{
    use Helper;

    /**
     * @throws ExceptionInterface
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

    protected function p(
        string $name,
    ): mixed {
        return $this->parameterBag->get(sprintf('%s.%s', ValksorBundle::VALKSOR, $name));
    }
}
