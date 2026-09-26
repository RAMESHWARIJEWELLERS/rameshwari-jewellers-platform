<?php

declare(strict_types=1);

namespace Rameshwari\Build\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ShellExec;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans the backtick shell operator. Execution functions (exec, shell_exec,
 * system, passthru, proc_open, popen, pcntl_exec) are banned by
 * spaze/phpstan-disallowed-calls, loaded in phpstan.neon.
 *
 * @implements Rule<ShellExec>
 */
final class NoShellExecRule implements Rule
{
    public function getNodeType(): string
    {
        return ShellExec::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return [
            RuleErrorBuilder::message('The backtick shell operator is banned: shell execution is prohibited.')
                ->identifier('rameshwari.shellExec')
                ->build(),
        ];
    }
}
