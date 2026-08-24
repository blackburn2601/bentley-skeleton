<?php

declare(strict_types=1);

namespace App\Api\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Gates the half-authenticated MFA state (ADR-0026).
 *
 * @extends Voter<string, mixed>
 *
 * While a second factor is pending, the caller holds a real access token for a real user — so
 * the AclVoter would happily grant their normal permissions, which is exactly wrong: the
 * session is not authenticated yet. This voter denies the permission attributes (and the
 * `MFA_VERIFIED` attribute) while the stage is pending, grants only the explicit `MFA_PENDING`
 * check, and otherwise abstains so the AclVoter decides as usual.
 *
 * It must ABSTAIN on Symfony's own attributes — `PUBLIC_ACCESS`, `IS_AUTHENTICATED_*`,
 * `ROLE_*`. The firewall's `access_control` votes `PUBLIC_ACCESS` for every `/api/` route so
 * the request reaches the controller, where the real gate is the controller's own
 * `#[IsGranted('MFA_PENDING')]`. Denying `PUBLIC_ACCESS` here would short-circuit the
 * `unanimous` decision at the firewall and strand the pending caller with a 403 before the
 * controller attribute ever runs.
 *
 * Overrides `vote()` rather than `supports()`/`voteOnAttribute()` because it must ABSTAIN in
 * the common case (a verified caller on a permission check). The boolean `voteOnAttribute`
 * API cannot express abstain, but `vote()` can — and returning ABSTAIN is what keeps this
 * voter from second-guessing the ACL for every ordinary request.
 *
 * Requires the `unanimous` access strategy: under affirmative, an AclVoter grant on the same
 * call would override this voter's deny. See security.yaml.
 */
final class MfaStageVoter extends Voter
{
    public const string MFA_PENDING = 'MFA_PENDING';
    public const string MFA_VERIFIED = 'MFA_VERIFIED';

    /** Matches the dotted permission attributes the AclVoter owns (see AclVoter::supports). */
    private const string PERMISSION_ATTRIBUTE = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    public function vote(TokenInterface $token, mixed $subject, array $attributes): int
    {
        $user = $token->getUser();

        if (!$user instanceof AuthenticatedUser) {
            // Anonymous: nothing to say. The entry point and the other voters handle it.
            return self::ACCESS_ABSTAIN;
        }

        // Keep only the attributes this voter owns: the MFA_* attributes and the dotted
        // permission attributes the AclVoter decides. Anything else (PUBLIC_ACCESS,
        // IS_AUTHENTICATED_*, ROLE_*) belongs to Symfony's own voters — abstaining lets them
        // grant the firewall's access_control so the request reaches the controller.
        $ours = $this->ownedAttributes($attributes);

        if ([] === $ours) {
            return self::ACCESS_ABSTAIN;
        }

        return MfaStage::Pending === $user->mfaStage()
            ? $this->decidePending($ours)
            : $this->decideVerified($ours);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Never claims Symfony's own attributes; those belong to their voters.
        return \in_array($attribute, [self::MFA_PENDING, self::MFA_VERIFIED], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // vote() does the real work; this is here only because Voter requires it. vote() is
        // overridden below and never delegates here.
        return false;
    }

    /**
     * @param array<int|string, mixed> $attributes
     *
     * @return list<string>
     */
    private function ownedAttributes(array $attributes): array
    {
        $ours = [];
        foreach ($attributes as $attribute) {
            if (\is_string($attribute) && $this->owns($attribute)) {
                $ours[] = $attribute;
            }
        }

        return $ours;
    }

    private function owns(string $attribute): bool
    {
        return \in_array($attribute, [self::MFA_PENDING, self::MFA_VERIFIED], true)
            || 1 === preg_match(self::PERMISSION_ATTRIBUTE, $attribute);
    }

    /**
     * @param list<string> $ours
     */
    private function decidePending(array $ours): int
    {
        // The caller may reach ONLY the endpoints that require a pending second factor; every
        // permission and the MFA_VERIFIED attribute are refused (fail-closed, on top of the
        // empty roles the pending token already carries).
        return \in_array(self::MFA_PENDING, $ours, true) ? self::ACCESS_GRANTED : self::ACCESS_DENIED;
    }

    /**
     * @param list<string> $ours
     */
    private function decideVerified(array $ours): int
    {
        // Verified: weigh in only on the explicit MFA_* attributes; leave permissions to the
        // AclVoter.
        if (\in_array(self::MFA_VERIFIED, $ours, true)) {
            return self::ACCESS_GRANTED;
        }

        if (\in_array(self::MFA_PENDING, $ours, true)) {
            return self::ACCESS_DENIED;
        }

        return self::ACCESS_ABSTAIN;
    }
}
