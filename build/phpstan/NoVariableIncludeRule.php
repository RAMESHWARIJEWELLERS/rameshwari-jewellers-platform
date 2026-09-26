<?php

declare(strict_types=1);

namespace Rameshwari\Build\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Include_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans include/require paths built from anything other than string
 * literals, constants, __DIR__/__FILE__, concatenation of those, and
 * dirname() of those. RJ_PATH . 'src/Foo.php' passes; $file does not.
 *
 * @implements Rule<Include_>
 */
final class NoVariableIncludeRule implements Rule
{
    public function getNodeType(): string
    {
        return Include_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->isStatic($node->expr)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Variable include paths are banned: build the path from literals and constants only.')
                ->identifier('rameshwari.variableInclude')
                ->build(),
        ];
    }

    private function isStatic(Expr $expr): bool
    {
        if (
            $expr instanceof Node\Scalar\String_
            || $expr instanceof Node\Scalar\MagicConst\Dir
            || $expr instanceof Node\Scalar\MagicConst\File
            || $expr instanceof Expr\ConstFetch
            || $expr instanceof Expr\ClassConstFetch
        ) {
            return true;
        }

        if ($expr instanceof Expr\BinaryOp\Concat) {
            return $this->isStatic($expr->left) && $this->isStatic($expr->right);
        }

        if (
            $expr instanceof Expr\FuncCall
            && $expr->name instanceof Node\Name
            && strtolower($expr->name->toString()) === 'dirname'
        ) {
            foreach ($expr->getArgs() as $arg) {
                if (! $this->isStatic($arg->value)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }
}
