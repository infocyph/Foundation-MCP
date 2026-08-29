<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis\Internal;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use RuntimeException;

/** @internal */
final class PhpNodeBudgetVisitor extends NodeVisitorAbstract
{
    private const int MAX_NODES = 200_000;

    private int $visited = 0;

    public function enterNode(Node $node)
    {
        ++$this->visited;

        if ($this->visited > self::MAX_NODES) {
            throw new RuntimeException(sprintf(
                'PHP syntax tree exceeds the %d-node analysis limit near %s on line %d.',
                self::MAX_NODES,
                $node::class,
                $node->getStartLine(),
            ));
        }

        return null;
    }
}
