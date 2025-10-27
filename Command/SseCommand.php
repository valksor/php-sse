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

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Valksor\Component\Sse\Service\SseService;

#[AsCommand(name: 'valksor:sse', description: 'Run the SSE server for programmatic reloads.')]
final class SseCommand extends AbstractCommand
{
    public function __construct(
        ParameterBagInterface $bag,
        private readonly SseService $sseService,
    ) {
        parent::__construct($bag);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);

        $this->killConflictingSseProcesses($io);

        $this->setServiceIo($this->sseService, $io);

        $pidFile = $this->createPidFilePath('sse');
        $this->sseService->writePidFile($pidFile);

        $exitCode = $this->sseService->start();

        $this->sseService->removePidFile($pidFile);

        return $exitCode;
    }

    protected function getSseProcessesToKill(): array
    {
        return ['sse'];
    }
}
