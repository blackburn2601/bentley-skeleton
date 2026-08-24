<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use LogicException;

/**
 * `TEXT(expr)` in DQL — the SQL `CAST(expr AS TEXT)`.
 *
 * It exists for exactly one reason: a `uuid` column cannot be pattern-matched. PostgreSQL
 * stores those ids in a binary type, so `LIKE '%3b46%'` against one is a type error, not an
 * empty result. Searching an id by a fragment of its canonical text therefore needs the value
 * rendered as text first, and DQL has no CAST of its own (ADR-0025).
 *
 * Registered as a string function in config/packages/doctrine.yaml.
 *
 * Deliberately narrow: it casts to TEXT and nothing else. A general-purpose CAST taking a
 * target type would put a caller-supplied identifier into SQL, which is the one position a
 * bound parameter cannot protect. There is no type argument here, so there is nothing to
 * inject.
 */
final class TextCastFunction extends FunctionNode
{
    /** String as well as Node: the parser resolves some primaries straight to a SQL fragment. */
    private Node|string|null $expression = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->expression = $parser->SimpleArithmeticExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        if (null === $this->expression) {
            throw new LogicException('TEXT() was walked before it was parsed.');
        }

        return 'CAST('.$sqlWalker->walkSimpleArithmeticExpression($this->expression).' AS TEXT)';
    }
}
