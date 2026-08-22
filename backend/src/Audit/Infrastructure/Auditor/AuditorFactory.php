<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Auditor;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration;
use DH\Auditor\Provider\Doctrine\Configuration as DoctrineConfiguration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Wires the auditor core library by hand (ADR-0017).
 *
 * `auditor-bundle` would normally do this. It cannot be installed here — every 7.x release
 * requires `symfony/framework-bundle ^8.0` and this is Symfony 7.4 LTS — and the newest one
 * that could install drags Twig, asset and translation into a headless API purely for an HTML
 * audit viewer nothing renders.
 *
 * So this is the bundle's job, minus the UI: a configuration, a Doctrine provider, and the
 * subscribers that record entity changes.
 *
 * What this records is different from `security_event`, and both are needed:
 *
 *   - `security_event` records *what happened* — a login, a lockout, a token reuse. Written
 *     deliberately by the code that did it.
 *   - the auditor records *what changed* — field-level before and after values on entities.
 *     Written automatically, which is exactly why it catches the change nobody thought to log.
 *
 * A compliance question like "who changed this user's role, and from what?" needs the second.
 *
 * The audited-entity list is CONFIGURATION, not code here: naming Account's and Acl's entities
 * in this class would make Audit depend on their internals, which deptrac rejects (INV-02).
 * It lives in config/packages/auditor.yaml, which is also where auditor-bundle would have put
 * it — and where an operator would look for it.
 */
final readonly class AuditorFactory
{
    /**
     * Columns never written to the audit store.
     *
     * An audit trail is read by more people than the user table is, and a password hash or a
     * TOTP secret copied into it is the same secret in a second, less-guarded place.
     *
     * @var list<string>
     */
    private const array IGNORED_COLUMNS = [
        'password_hash',
        'totp_secret_encrypted',
        'token_hash',
    ];

    /**
     * @param list<string> $auditedEntities fully-qualified class names, from configuration
     */
    public function __construct(
        private array $auditedEntities,
        private EntityManagerInterface $entityManager,
        // Symfony's dispatcher, not PSR-14: the auditor calls addSubscriber(), which is a
        // Symfony extension the PSR interface does not carry.
        private EventDispatcherInterface $dispatcher,
        private bool $enabled = true,
    ) {
    }

    public function create(): Auditor
    {
        $auditor = new Auditor(
            new Configuration([
                'enabled' => $this->enabled,
                'timezone' => 'UTC',
                // No user or security provider: the caller is already recorded on every
                // security_event row, and giving the auditor its own view of "who" would
                // create a second answer to that question.
                'user_provider' => null,
                'security_provider' => null,
                'role_checker' => null,
            ]),
            $this->dispatcher,
        );

        $provider = new DoctrineProvider(new DoctrineConfiguration([
            'table_prefix' => '',
            'table_suffix' => '_audit',
            'ignored_columns' => self::IGNORED_COLUMNS,
            'entities' => array_fill_keys($this->auditedEntities, []),
            // The bundle's HTML viewer, which this application has no use for.
            'viewer' => false,
        ]));

        $provider->registerAuditingService(
            new \DH\Auditor\Provider\Doctrine\Service\AuditingService('default', $this->entityManager),
        );
        $provider->registerStorageService(
            new \DH\Auditor\Provider\Doctrine\Service\StorageService('default', $this->entityManager),
        );

        $auditor->registerProvider($provider);

        return $auditor;
    }
}
