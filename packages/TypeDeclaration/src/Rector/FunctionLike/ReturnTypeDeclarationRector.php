<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\Rector\FunctionLike;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\Php\ReturnTypeInfo;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

final class ReturnTypeDeclarationRector extends AbstractTypeDeclarationRector
{
    /**
     * @var string[]
     */
    private const EXCLUDED_METHOD_NAMES = ['__construct', '__destruct', '__clone'];

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'Change @return types and type from static analysis to type declarations if not a BC-break',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
<?php

class SomeClass
{
    /**
     * @return int
     */
    public function getCount()
    {
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
<?php

class SomeClass
{
    /**
     * @return int
     */
    public function getCount(): int
    {
    }
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @param ClassMethod|Function_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isAtLeastPhpVersion('7.0')) {
            return null;
        }

        // skip excluded methods
        if ($node instanceof ClassMethod && $this->isNames($node, self::EXCLUDED_METHOD_NAMES)) {
            return null;
        }

        // already set → skip
        $hasNewType = false;
        if ($node->returnType !== null) {
            $hasNewType = $node->returnType->getAttribute(self::HAS_NEW_INHERITED_TYPE, false);
            if (! $hasNewType) {
                return null;
            }
        }

        $returnTypeInfo = $this->resolveReturnType($node);
        if ($returnTypeInfo === null) {
            return null;
        }

        // this could be void
        if ($returnTypeInfo->getTypeNode() === null) {
            if ($returnTypeInfo->getTypeCount() !== 0) {
                return null;
            }

            // use
            $returnTypeInfo = $this->functionLikeManipulator->resolveStaticReturnTypeInfo($node);

            if ($returnTypeInfo === null) {
                return null;
            }
        }

        // @todo is it violation?
        if ($hasNewType) {
            // should override - is it subtype?
            $possibleOverrideNewReturnType = $returnTypeInfo->getTypeNode();

            if ($possibleOverrideNewReturnType !== null) {
                if ($node->returnType !== null) {
                    if ($this->isSubtypeOf($possibleOverrideNewReturnType, $node->returnType)) {
                        // allow override
                        $node->returnType = $returnTypeInfo->getTypeNode();
                    }
                } else {
                    $node->returnType = $returnTypeInfo->getTypeNode();
                }
            }
        } else {
            $node->returnType = $returnTypeInfo->getTypeNode();
        }

        $this->populateChildren($node, $returnTypeInfo);

        return $node;
    }

    /**
     * @param ClassMethod|Function_ $functionLike
     */
    private function resolveReturnType(FunctionLike $functionLike): ?ReturnTypeInfo
    {
        $docReturnTypeInfo = $this->docBlockManipulator->getReturnTypeInfo($functionLike);
        $codeReturnTypeInfo = $this->functionLikeManipulator->resolveStaticReturnTypeInfo($functionLike);

        // code has priority over docblock
        if ($docReturnTypeInfo === null) {
            return $codeReturnTypeInfo;
        }

        if ($codeReturnTypeInfo && $codeReturnTypeInfo->getTypeNode()) {
            return $codeReturnTypeInfo;
        }

        return $docReturnTypeInfo;
    }

    /**
     * Add typehint to all children
     */
    private function populateChildren(Node $node, ReturnTypeInfo $returnTypeInfo): void
    {
        if (! $node instanceof ClassMethod) {
            return;
        }

        $methodName = $this->getName($node);
        if ($methodName === null) {
            throw new ShouldNotHappenException();
        }

        $className = $node->getAttribute(AttributeKey::CLASS_NAME);
        if (! is_string($className)) {
            throw new ShouldNotHappenException();
        }

        $childrenClassLikes = $this->parsedNodesByType->findClassesAndInterfacesByType($className);

        // update their methods as well
        foreach ($childrenClassLikes as $childClassLike) {
            if (! $childClassLike instanceof Class_) {
                continue;
            }

            $usedTraits = $this->parsedNodesByType->findUsedTraitsInClass($childClassLike);
            foreach ($usedTraits as $trait) {
                $this->addReturnTypeToMethod($trait, $methodName, $node, $returnTypeInfo);
            }

            $this->addReturnTypeToMethod($childClassLike, $methodName, $node, $returnTypeInfo);
        }
    }

    private function addReturnTypeToMethod(
        ClassLike $classLike,
        string $methodName,
        Node $node,
        ReturnTypeInfo $returnTypeInfo
    ): void {
        $classMethod = $classLike->getMethod($methodName);
        if ($classMethod === null) {
            return;
        }

        // already has a type
        if ($classMethod->returnType !== null) {
            return;
        }

        $resolvedChildType = $this->resolveChildType($returnTypeInfo, $node, $classMethod);
        if ($resolvedChildType === null) {
            return;
        }

        $resolvedChildType = $this->resolveChildType($returnTypeInfo, $node, $classMethod);
        if ($resolvedChildType === null) {
            return;
        }

        $classMethod->returnType = $resolvedChildType;

        // let the method now it was changed now
        $classMethod->returnType->setAttribute(self::HAS_NEW_INHERITED_TYPE, true);

        $this->notifyNodeChangeFileInfo($classMethod);
    }
}
