<?php echo "<?php\n"; ?>

declare(strict_types=1);

namespace <?php echo $namespace; ?>;

use <?php echo $service_fqcn; ?>;
use PHPUnit\Framework\TestCase;

final class <?php echo $class_name; ?> extends TestCase
{
    public function testItDoesTheOneThingItIsResponsibleFor(): void
    {
        self::markTestIncomplete('Assert the behaviour described by @responsibility on <?php echo $service_short; ?>.');
    }
}
