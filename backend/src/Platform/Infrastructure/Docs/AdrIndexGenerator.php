<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Docs;

use App\Shared\Application\Docs\DocumentGenerator;
use App\Shared\Application\Docs\GeneratedFileHeader;

/**
 * docs/adr/README.md — the decision index.
 *
 * Also the format check: it reads the number, title and status out of every ADR, so a file
 * that does not follow the template shows up here as broken rather than being quietly
 * skipped.
 */
final readonly class AdrIndexGenerator implements DocumentGenerator
{
    public function __construct(private string $projectDir)
    {
    }

    public function key(): string
    {
        return 'adr';
    }

    public function path(): string
    {
        return 'docs/adr/README.md';
    }

    public function generate(): string
    {
        $md = GeneratedFileHeader::for('Architecture decision records', 'the files in docs/adr/');

        $md .= "\nWhy this system is the way it is. Each record states the decision, its consequences\n"
            ."(including the negative ones), the alternatives that were rejected **and why**, and what\n"
            ."reversing it would cost.\n\n"
            ."**Read the relevant record before proposing to change a decision.** Most of the obvious\n"
            ."objections were considered; the record says what happened to them.\n\n"
            ."New decision? `make adr TITLE=\"...\"`. See [../cookbook/add-adr.md](../cookbook/add-adr.md).\n\n"
            ."| # | Decision | Status |\n|---|---|---|\n";

        $records = $this->collect();

        foreach ($records as $adr) {
            $md .= \sprintf(
                "| %s | [%s](%s) | %s |\n",
                $adr['number'],
                $adr['title'],
                $adr['file'],
                $adr['status'],
            );
        }

        if ([] === $records) {
            $md .= "| — | _No records yet._ | — |\n";
        }

        return $md;
    }

    /** @return list<array{number: string, title: string, file: string, status: string}> */
    private function collect(): array
    {
        $records = [];

        foreach ((array) glob($this->projectDir.'/../docs/adr/[0-9][0-9][0-9][0-9]-*.md') as $file) {
            if (!\is_string($file)) {
                continue;
            }

            $contents = file_get_contents($file);
            if (false === $contents) {
                continue;
            }

            $number = substr(basename($file), 0, 4);

            $title = 1 === preg_match('/^#\s*\d+\.\s*(.+)$/m', $contents, $m)
                ? trim($m[1])
                : '**malformed heading** — expected `# NNNN. Title`';

            $status = 1 === preg_match('/^-\s*\*\*Status:\*\*\s*(.+)$/m', $contents, $m)
                ? trim($m[1])
                : '**missing**';

            $records[] = [
                'number' => $number,
                'title' => $title,
                'file' => basename($file),
                'status' => $status,
            ];
        }

        usort($records, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return $records;
    }
}
