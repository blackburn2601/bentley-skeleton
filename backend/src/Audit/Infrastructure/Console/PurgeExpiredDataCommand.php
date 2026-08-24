<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Console;

use App\Audit\Application\Service\PurgeExpiredDataService;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gdpr:purge',
    description: 'Delete expired tokens whose retention period has ended',
)]
final class PurgeExpiredDataCommand extends Command
{
    public function __construct(private readonly PurgeExpiredDataService $purge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'before',
            null,
            InputOption::VALUE_REQUIRED,
            'Purge data that expired before this date (default: 30 days ago)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $before = $input->getOption('before');

        $cutoff = \is_string($before) ? new DateTimeImmutable($before) : null;
        $result = ($this->purge)($cutoff);

        $io->success(\sprintf(
            'Purged %d expired refresh token(s).',
            $result['refreshTokens'],
        ));

        $io->note(
            'Security events are NOT purged here. They are append-only and the application '
            .'role cannot delete them (ADR-0012); audit retention runs separately as the owner '
            .'role. See docs/OPERATIONS.md.',
        );

        return Command::SUCCESS;
    }
}
