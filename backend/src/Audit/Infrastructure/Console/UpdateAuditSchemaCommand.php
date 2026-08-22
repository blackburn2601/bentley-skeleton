<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Console;

use DH\Auditor\Auditor;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/console app:audit:schema-update` — create or update the `*_audit` tables.
 *
 * The auditor's storage tables are derived from the list of audited entities, not from
 * Doctrine mappings, so `doctrine:migrations:diff` cannot see them. `auditor-bundle` would
 * ship this command; since the bundle cannot be installed here (ADR-0017), it is ours.
 *
 * `--dump-sql` exists so the statements can be pasted into a migration rather than run
 * against production directly. That matters: `make migrate` is the only thing that should
 * change a production schema, and a command that quietly alters tables outside the migration
 * history is how two environments end up different in ways nobody can reconstruct.
 */
#[AsCommand(
    name: 'app:audit:schema-update',
    description: 'Create or update the entity-history tables (ADR-0017)',
)]
final class UpdateAuditSchemaCommand extends Command
{
    public function __construct(private readonly Auditor $auditor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dump-sql',
            null,
            InputOption::VALUE_NONE,
            'Print the statements instead of executing them, to paste into a migration',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $provider = $this->auditor->getProvider(DoctrineProvider::class);

        if (!$provider instanceof DoctrineProvider) {
            $io->error('The Doctrine audit provider is not registered.');

            return Command::FAILURE;
        }

        $schemaManager = new SchemaManager($provider);
        $statements = $schemaManager->getUpdateAuditSchemaSql();
        $flattened = $this->flatten($statements);

        if ([] === $flattened) {
            $io->success('The entity-history tables are already up to date.');

            return Command::SUCCESS;
        }

        if (true === $input->getOption('dump-sql')) {
            foreach ($flattened as $sql) {
                $output->writeln($sql.';');
            }

            return Command::SUCCESS;
        }

        $schemaManager->updateAuditSchema($statements);
        $io->success(\sprintf('Applied %d statement(s) to the entity-history tables.', \count($flattened)));

        return Command::SUCCESS;
    }

    /**
     * The schema manager returns statements grouped by storage service; this is one flat list.
     *
     * @param array<array-key, mixed> $statements
     *
     * @return list<string>
     */
    private function flatten(array $statements): array
    {
        $flattened = [];

        foreach ($statements as $forStorage) {
            foreach ((array) $forStorage as $sql) {
                if (\is_string($sql)) {
                    $flattened[] = $sql;
                }
            }
        }

        return $flattened;
    }
}
