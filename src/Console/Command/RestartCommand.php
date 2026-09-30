<?php

declare(strict_types=1);

namespace Loongs\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'restart', description: 'Stop the running master (if any), then start again')]
final class RestartCommand extends StartCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setHelp(<<<'TXT'
<info>stop</info> (SIGTERM, wait) followed by <info>start</info> with the same options.
Use <info>reload</info> for a graceful in-place worker reload instead.

  <info>./loongs restart -d</info>
  <info>./loongs restart --only=http,rpc</info>
TXT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = $this->processManager();
        try {
            // A same-named service of another project would make the start fail: check before stopping.
            $manager->assertNoForeignSameName();
        } catch (\RuntimeException $e) {
            $this->io($input, $output)->error($e->getMessage());

            return self::FAILURE;
        }
        $code = StopCommand::stopServer($manager, $this->io($input, $output));
        if ($code !== self::SUCCESS) {
            return $code;
        }

        return $this->startServer($input, $output);
    }
}
