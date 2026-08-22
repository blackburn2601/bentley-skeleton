<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Auditor;

use DH\Auditor\Auditor;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Forces the auditor to exist, which is the only thing it needs to do.
 *
 * `DoctrineProvider::registerAuditingService()` attaches the Doctrine listeners that record
 * changes — and it runs while the Auditor is being CONSTRUCTED. Symfony builds private
 * services lazily, so an Auditor that nothing injects is never constructed, its listeners are
 * never attached, and entity history is silently not recorded. The tables exist, the
 * configuration is right, and nothing is written: the worst kind of failure, because it looks
 * like it is working.
 *
 * `auditor-bundle` solves this with a compiler pass. Without the bundle (ADR-0017), this
 * listener does the same job: it depends on the Auditor, so resolving it builds one.
 *
 * Both entry points are covered. The console matters as much as HTTP — fixtures, migrations
 * and maintenance commands change entities too, and an audit trail with a hole where the
 * command-line changes should be is not much of an audit trail.
 */
#[AsEventListener(event: RequestEvent::class, priority: 2048)]
#[AsEventListener(event: ConsoleCommandEvent::class, method: 'onCommand', priority: 2048)]
final readonly class AuditorInitializer
{
    public function __construct(private Auditor $auditor)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $this->ensureRegistered();
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->ensureRegistered();
    }

    /**
     * Touch the auditor so the container actually builds it.
     *
     * Reading the configuration is a no-op with a purpose: an unused constructor property is
     * removed by nobody, but it also proves nothing — static analysis flags it as written and
     * never read, and rightly so. Asking the auditor a question makes the dependency real and
     * the intent legible.
     */
    private function ensureRegistered(): void
    {
        $this->auditor->getConfiguration()->isEnabled();
    }
}
