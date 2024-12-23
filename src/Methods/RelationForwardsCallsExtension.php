<?php

declare(strict_types=1);

namespace Larastan\Larastan\Methods;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Larastan\Larastan\Reflection\EloquentBuilderMethodReflection;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\MissingMethodFromReflectionException;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;

use function array_key_exists;
use function array_values;

final class RelationForwardsCallsExtension implements MethodsClassReflectionExtension
{
    /** @var array<string, MethodReflection> */
    private array $cache = [];

    public function __construct(private BuilderHelper $builderHelper, private ReflectionProvider $reflectionProvider)
    {
    }

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if (array_key_exists($classReflection->getCacheKey() . '-' . $methodName, $this->cache)) {
            return true;
        }

        $methodReflection = $this->findMethod($classReflection, $methodName);

        if ($methodReflection !== null) {
            $this->cache[$classReflection->getCacheKey() . '-' . $methodName] = $methodReflection;

            return true;
        }

        return false;
    }

    public function getMethod(
        ClassReflection $classReflection,
        string $methodName,
    ): MethodReflection {
        return $this->cache[$classReflection->getCacheKey() . '-' . $methodName];
    }

    /**
     * @throws MissingMethodFromReflectionException
     * @throws ShouldNotHappenException
     */
    private function findMethod(ClassReflection $classReflection, string $methodName): MethodReflection|null
    {
        if (! $classReflection->is(Relation::class)) {
            return null;
        }

        $relatedModel = $classReflection->getActiveTemplateTypeMap()->getType('TRelatedModel');

        if ($relatedModel === null) {
            return null;
        }

        if ($relatedModel->getObjectClassReflections() !== []) {
            $modelReflection = $relatedModel->getObjectClassReflections()[0];
        } else {
            $modelReflection = $this->reflectionProvider->getClass(Model::class);
        }

        if ($modelReflection->getName() !== Model::class && ! $modelReflection->isSubclassOf(Model::class)) {
            return null;
        }

        $builderName = $this->builderHelper->determineBuilderName($modelReflection->getName());

        $builderReflection = (new GenericObjectType($builderName, [new ObjectType($modelReflection->getName())]));

        if (! $builderReflection->hasMethod($methodName)->yes()) {
            return null;
        }

        $reflection = $builderReflection->getMethod($methodName, new OutOfClassScope());

        $parametersAcceptor = $reflection->getVariants()[0];
        $returnType         = $parametersAcceptor->getReturnType();

        if ((new ObjectType(Builder::class))->isSuperTypeOf($returnType)->yes()) {
            $returnType = new GenericObjectType(
                $classReflection->getName(),
                array_values($classReflection->getActiveTemplateTypeMap()->getTypes()),
            );
        }

        return new EloquentBuilderMethodReflection(
            $methodName,
            $reflection->getDeclaringClass(),
            $parametersAcceptor->getParameters(),
            $returnType,
            $parametersAcceptor->isVariadic(),
        );
    }
}
