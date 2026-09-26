<?php

declare(strict_types=1);

namespace Rameshwari\Build\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Eval_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans eval(). Dynamic evaluation is prohibited platform-wide.
 *
 * @implements Rule<Eval_>
 */
final class NoEvalRule implements Rule
{
    public function getNodeType(): string
    {
        return Eval_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return [
            RuleErrorBuilder::message('eval() is banned: dynamic evaluation is prohibited.')
                ->identifier('rameshwari.eval')
                ->build(),
        ];
    }
}
