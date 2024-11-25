<?php

declare(strict_types=1);

namespace Larastan\Larastan\ReturnTypes\Helpers;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\ObjectShapeType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use stdClass;

use function count;

class LiteralExtension implements DynamicFunctionReturnTypeExtension
{
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'literal';
    }

    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): Type {
        $args = $functionCall->getArgs();

        if (count($args) === 0) {
            // No arguments provided, return an empty object with `stdClass`
            return TypeCombinator::intersect(new ObjectShapeType([], []), new ObjectType(stdClass::class));
        }

        // Handle the case of a single argument, returning its type directly
        if (count($args) === 1) {
            $argType = $scope->getType($args[0]->value);

            // Handle special case for `new StdClass`
            if ($argType->isObject()->yes() && $argType->getClassReflection()?->getName() === stdClass::class) {
                return TypeCombinator::intersect(new ObjectShapeType([], []), $argType);
            }

            return $argType;
        }

        $properties = [];
        foreach ($args as $argExpression) {
            $nameOfParam = $argExpression->getAttributes()['originalArg']->name->name ?? null;

            if ($nameOfParam === null) {
                // Handle unnamed arguments or skip
                continue;
            }

            $properties[$nameOfParam] = $scope->getType($argExpression->value);
        }

        return TypeCombinator::intersect(
            new ObjectShapeType($properties, []),
            new ObjectType(stdClass::class),
        );
    }
}
