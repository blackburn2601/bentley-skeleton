<?php echo "<?php\n"; ?>

declare(strict_types=1);

namespace <?php echo $namespace; ?>;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class <?php echo $class_name; ?> extends WebTestCase
{
    public function testItRejectsAnAnonymousCaller(): void
    {
        $client = self::createClient();
        $client->request('<?php echo $http_method; ?>', '<?php echo $path; ?>');

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutThePermission(): void
    {
        self::markTestIncomplete('Log in as a user without "<?php echo $permission; ?>" and assert 403 or 404.');
    }

    public function testItSucceedsForAPermittedCaller(): void
    {
        self::markTestIncomplete('Log in as a permitted user and assert the response view.');
    }
}
