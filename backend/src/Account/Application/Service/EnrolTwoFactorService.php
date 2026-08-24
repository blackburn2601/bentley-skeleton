<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\QrCode;
use App\Account\Application\Totp;
use App\Account\Application\TotpEnrollment;
use App\Account\Domain\AccountException;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Shared\Domain\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Holds a provisional TOTP secret for the caller to enroll an authenticator.
 *
 * The secret is stored only in the provisional slot, encrypted at rest. Nothing changes about
 * the caller's authentication until a first code confirms the authenticator captured it — so an
 * abandoned enrollment leaves the existing second factor (or none) untouched. Re-enrolling
 * overwrites a stale provisional, never the live secret.
 */
final readonly class EnrolTwoFactorService
{
    public function __construct(
        private UserRepository $users,
        private Totp $totp,
        private SecretEncryptor $encryptor,
        private QrCode $qr,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId): TotpEnrollment
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            // A valid session whose user has since been erased.
            throw AccountException::invalidToken();
        }

        $secret = $this->totp->generateSecret();
        $user->beginTotpEnrollment($this->encryptor->encrypt($secret));
        $this->em->flush();

        $uri = $this->totp->provisioningUri($user->username(), $secret);

        // The plaintext secret travels in the response so an authenticator that cannot scan the
        // QR can be configured by typing it. It is shown once; the server keeps only the
        // encrypted form, and nothing is live until confirm.
        return new TotpEnrollment(
            secret: $secret,
            provisioningUri: $uri,
            qrDataUrl: $this->qr->dataUrlFor($uri),
        );
    }
}
