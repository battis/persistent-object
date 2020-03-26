<?php


namespace Battis\PersistentObject\Parts;


class Condition
{
    /** @var string */
    protected $condition = '';

    /** @var array<string, string> */
    protected $params = [];

    private function __construct()
    {
    }

    public static function fromExpression(string $expression)
    {
        if (empty($expression)) {
            return null;
        }
        $instance = new Condition();
        $instance->condition = "($expression)";
        return $instance;
    }

    public static function fromExpressions(array $expressions = [])
    {
        if (empty ($expressions)) {
            return null;
        }
        $instance = new Condition();
        $instance->condition = '((' . implode(') AND (', $expressions) . '))';
        return $instance;
    }

    public static function fromPairedValues(array $pairedValues = [])
    {
        if (empty($pairedValues)) {
            return null;
        }
        $instance = new Condition();
        $expressions = [];
        foreach ($pairedValues as $key => $value) {
            $_key = preg_replace('/[^a-z0-9]/i', '_', $key);
            if ($value === null) {
                $expressions[] = "`$key` IS NULL";
            } else {
                $expressions[] = "`$key` = :$_key";
                $instance->params[$_key] = $value;
            }
        }
        $instance->condition = '(' . implode(' AND ', $expressions) . ')';
        return $instance;
    }

    public function __toString(): string
    {
        return $this->condition;
    }

    public function parameters(array $additionalParameters = [])
    {
        return array_merge($this->params, $additionalParameters);
    }

    public static function merge(Condition $left = null, Condition $right = null)
    {
        if (empty($left->condition)) {
            return $right;
        } elseif (empty($right->condition)) {
            return $left;
        } else {
            $merged = new Condition();
            $merged->condition = "({$left->condition} AND {$right->condition})";
            $merged->params = array_merge($left->params, $right->params);
            return $merged;
        }
    }
}
