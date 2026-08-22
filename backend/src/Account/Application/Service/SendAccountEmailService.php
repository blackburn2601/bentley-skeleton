<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * @responsibility Sends the transactional emails the account lifecycle depends on.
 */
final readonly class SendAccountEmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private string $senderAddress,
        private string $senderName,
        private string $frontendBaseUrl,
    ) {
    }

    public function verification(User $user, string $plaintextToken): void
    {
        $this->send($user, 'Confirm your email address', 'verify_email', [
            'url' => $this->link('/verify-email', $plaintextToken),
        ]);
    }

    /**
     * Sent when someone tries to register an address that already has an account.
     *
     * The registration endpoint must not reveal that the address is taken (that would make it
     * an enumeration oracle), so the person who owns it is told instead. If it was them, the
     * email explains what happened; if it was not, they learn someone is probing.
     */
    public function duplicateRegistrationAttempt(User $user): void
    {
        $this->send($user, 'Someone tried to register your email address', 'duplicate_registration', [
            'resetUrl' => $this->frontendBaseUrl.'/forgot-password',
        ]);
    }

    public function passwordReset(User $user, string $plaintextToken): void
    {
        $this->send($user, 'Reset your password', 'reset_password', [
            'url' => $this->link('/reset-password', $plaintextToken),
        ]);
    }

    /**
     * A password change is worth telling the owner about even though they just did it: if
     * they did not, this is the only warning they will get.
     */
    public function passwordChanged(User $user): void
    {
        $this->send($user, 'Your password was changed', 'password_changed', []);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(User $user, string $subject, string $template, array $context): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->senderAddress, $this->senderName))
            ->to(new Address($user->email()))
            ->subject($subject)
            ->htmlTemplate(\sprintf('email/%s.html.twig', $template))
            // Not 'email': TemplatedEmail reserves that context key for the message itself,
            // and passing it throws.
            ->context($context + ['recipient' => $user->email()]);

        // Sent synchronously (ADR-0010). These are infrequent and rate-limited, and a failure
        // surfacing in the request is better than one disappearing into a queue nobody watches.
        $this->mailer->send($email);
    }

    private function link(string $path, string $token): string
    {
        return \sprintf('%s%s?token=%s', $this->frontendBaseUrl, $path, urlencode($token));
    }
}
