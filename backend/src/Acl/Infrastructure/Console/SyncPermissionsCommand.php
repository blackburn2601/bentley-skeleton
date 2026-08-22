<?php

declare(strict_types=1);

namespace App\Acl\Infrastructure\Console;

use App\Acl\Application\Service\EnsureBaselineRolesService;
use App\Acl\Application\Service\SyncPermissionsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:acl:sync-permissions',
    description: 'Sync permissions from PermissionCatalog and ensure the baseline roles exist',
)]
final class SyncPermissionsCommand extends Command
{
    public function __construct(
        private readonly SyncPermissionsService $sync,
        private readonly EnsureBaselineRolesService $baselineRoles,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = ($this->sync)();

        if ([] === $result['added']) {
            $io->writeln('No new permissions to add.');
        } else {
            $io->success(\sprintf('Added %d permission(s):', \count($result['added'])));
            $io->listing($result['added']);
        }

        // Roles after permissions: the baseline role grants permissions that must exist first.
        $roles = ($this->baselineRoles)();

        if ([] !== $roles['created']) {
            $io->success('Created baseline role(s):');
            $io->listing($roles['created']);
        }

        if ([] !== $roles['granted']) {
            $io->writeln(\sprintf('Granted %d permission(s) to the baseline user role.', \count($roles['granted'])));
        }

        if ([] !== $result['orphaned']) {
            $io->warning(\sprintf(
                '%d permission(s) exist in the database but are no longer declared in '
                .'PermissionCatalog. They have NOT been removed — grants may still reference '
                .'them, and deleting the row would cascade those grants away. Remove them '
                .'deliberately if that is what you want:',
                \count($result['orphaned']),
            ));
            $io->listing($result['orphaned']);
        }

        return Command::SUCCESS;
    }
}
