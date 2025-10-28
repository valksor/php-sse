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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Valksor\Bundle\ValksorBundle;
use Valksor\Component\Sse\Helper;

use function sprintf;

abstract class AbstractCommand extends Command
{
    use Helper;

    protected string $projectDir;

    public function __construct(
        protected ParameterBagInterface $bag,
    ) {
        parent::__construct();
        $this->projectDir = $bag->get('kernel.project_dir');
    }

    protected function createSymfonyStyle(
        InputInterface $input,
        OutputInterface $output,
    ): SymfonyStyle {
        return new SymfonyStyle($input, $output);
    }

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

    protected function handleCommandError(
        string $message = '',
        ?SymfonyStyle $io = null,
    ): int {
        if ($message && $io) {
            $io->error($message);
        }

        return Command::FAILURE;
    }

    protected function handleCommandSuccess(
        string $message = '',
        ?SymfonyStyle $io = null,
    ): int {
        if ($message && $io) {
            $io->success($message);
        }

        return Command::SUCCESS;
    }

    protected function p(
        string $name,
    ): mixed {
        return $this->bag->get(sprintf('%s.%s', ValksorBundle::VALKSOR, $name));
    }

    protected function setServiceIo(
        object $service,
        SymfonyStyle $io,
    ): void {
        $service->setIo($io);
    }
}
