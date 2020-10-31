<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Twig\NodeVisitor;

use Symfony\Bridge\Twig\Node\TransNode;
use Twig\Environment;
use Twig\Node\Expression\Binary\ConcatBinary;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use Twig\NodeVisitor\AbstractNodeVisitor;

/**
 * TranslationNodeVisitor extracts translation messages.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class TranslationNodeVisitor extends AbstractNodeVisitor
{
    public const UNDEFINED_DOMAIN = '_undefined';

    private $enabled = false;
    /**
     * This array stores found messages.
     *
     * The data structure of this array is as follows:
     *
     *     [
     *         0 => [
     *             0 => 'message',
     *             1 => 'domain',
     *             2 => [
     *                 0 => 'variable1',
     *                 1 => 'variable2',
     *                 ...
     *             ]
     *         ],
     *         ...
     *     ]
     */
    private $messages = [];

    public function enable(): void
    {
        $this->enabled = true;
        $this->messages = [];
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->messages = [];
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * {@inheritdoc}
     */
    protected function doEnterNode(Node $node, Environment $env): Node
    {
        if (!$this->enabled) {
            return $node;
        }

        if (
            $node instanceof FilterExpression &&
            'trans' === $node->getNode('filter')->getAttribute('value') &&
            $node->getNode('node') instanceof ConstantExpression
        ) {
            // extract constant nodes with a trans filter
            $this->messages[] = [
                $node->getNode('node')->getAttribute('value'),
                $this->getReadDomainFromArguments($node->getNode('arguments'), 1),
                $this->getReadVariablesFromArguments($node->getNode('arguments'), 0),
            ];
        } elseif (
            $node instanceof FilterExpression &&
            'trans' === $node->getNode('filter')->getAttribute('value') &&
            $node->getNode('node') instanceof FunctionExpression &&
            't' === $node->getNode('node')->getAttribute('name')
        ) {
            // extract t() nodes with a trans filter applied
            $functionNodeArguments = $node->getNode('node')->getNode('arguments');

            if ($functionNodeArguments->getIterator()->current() instanceof ConstantExpression) {
                $this->messages[] = [
                    $this->getReadMessageFromArguments($functionNodeArguments, 0),
                    $this->getReadDomainFromArguments($functionNodeArguments, 2),
                    $this->getReadVariablesFromArguments($functionNodeArguments, 1),
                ];
            }
        } elseif ($node instanceof TransNode) {
            // extract trans nodes
            $this->messages[] = [
                $node->getNode('body')->getAttribute('data'),
                $node->hasNode('domain') ? $this->getReadDomainFromNode($node->getNode('domain')) : null,
                $this->getReadVariablesFromArguments($node, 0),
            ];
        } elseif (
            $node instanceof FilterExpression &&
            'trans' === $node->getNode('filter')->getAttribute('value') &&
            $node->getNode('node') instanceof ConcatBinary &&
            $message = $this->getConcatValueFromNode($node->getNode('node'), null)
        ) {
            $this->messages[] = [
                $message,
                $this->getReadDomainFromArguments($node->getNode('arguments'), 1),
            ];
        }

        return $node;
    }

    /**
     * {@inheritdoc}
     */
    protected function doLeaveNode(Node $node, Environment $env): ?Node
    {
        return $node;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 0;
    }

    private function getReadMessageFromArguments(Node $arguments, int $index): ?string
    {
        if ($arguments->hasNode('message')) {
            $argument = $arguments->getNode('message');
        } elseif ($arguments->hasNode($index)) {
            $argument = $arguments->getNode($index);
        } else {
            return null;
        }

        return $this->getReadMessageFromNode($argument);
    }

    private function getReadMessageFromNode(Node $node): ?string
    {
        if ($node instanceof ConstantExpression) {
            return $node->getAttribute('value');
        }

        return null;
    }

    private function getReadVariablesFromArguments(Node $arguments, int $index): array
    {
        if ($arguments->hasNode('vars')) {
            $argument = $arguments->getNode('vars');
        } elseif ($arguments->hasNode($index)) {
            $argument = $arguments->getNode($index);
        } else {
            return [];
        }

        return $this->getReadVariablesFromNode($argument);
    }

    private function getReadVariablesFromNode(Node $node): ?array
    {
        if (!empty($node)) {
            $variables = [];

            foreach ($node as $key => $variable) {
                // Odd children are variable names, even ones are values
                if (1 == $key % 2) {
                    continue;
                }

                $variables[] = $variable->getAttribute('value');
            }

            return $variables;
        }

        return [];
    }

    private function getReadDomainFromArguments(Node $arguments, int $index): ?string
    {
        if ($arguments->hasNode('domain')) {
            $argument = $arguments->getNode('domain');
        } elseif ($arguments->hasNode($index)) {
            $argument = $arguments->getNode($index);
        } else {
            return null;
        }

        return $this->getReadDomainFromNode($argument);
    }

    private function getReadDomainFromNode(Node $node): ?string
    {
        if ($node instanceof ConstantExpression) {
            return $node->getAttribute('value');
        }

        return self::UNDEFINED_DOMAIN;
    }

    private function getConcatValueFromNode(Node $node, ?string $value): ?string
    {
        if ($node instanceof ConcatBinary) {
            foreach ($node as $nextNode) {
                if ($nextNode instanceof ConcatBinary) {
                    $nextValue = $this->getConcatValueFromNode($nextNode, $value);
                    if (null === $nextValue) {
                        return null;
                    }
                    $value .= $nextValue;
                } elseif ($nextNode instanceof ConstantExpression) {
                    $value .= $nextNode->getAttribute('value');
                } else {
                    // this is a node we cannot process (variable, or translation in translation)
                    return null;
                }
            }
        } elseif ($node instanceof ConstantExpression) {
            $value .= $node->getAttribute('value');
        }

        return $value;
    }
}
