<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PhpatRules;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * The shape of the Api layer, enforced by phpat inside PHPStan.
 *
 * deptrac says which layers may talk to which. These say what a class in the Api layer is
 * allowed to look like. Together they mean a controller cannot drift into a service: it is
 * one action, it is final, and it delegates.
 *
 * These are not tests in the PHPUnit sense — each method returns a rule that PHPStan
 * evaluates. The directory is excluded from the phpunit architecture suite for that reason.
 */
final class ApiShapeRules
{
    public function test_controllers_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Api'))
            ->excluding(Selector::isInterface(), Selector::isAbstract(), Selector::isEnum())
            ->shouldBeFinal()
            ->because('a subclassed controller is a second endpoint sharing one name and one test (INV-07)');
    }

    public function test_controllers_are_single_action(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('/^App\\\\Api\\\\.*Controller$/', true))
            ->shouldHaveOnlyOnePublicMethodNamed('__invoke')
            ->because('one endpoint per class keeps routing, authorization and the response view in one readable file (INV-07)');
    }
}
