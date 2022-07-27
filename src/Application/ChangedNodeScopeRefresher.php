<?php

declare(strict_types=1);

namespace Rector\Core\Application;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PHPStan\Analyser\MutatingScope;
use Rector\Core\Contract\PhpParser\Node\StmtsAwareInterface;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\NodeAnalyzer\ScopeAnalyzer;
use Rector\Core\PhpParser\Node\CustomNode\FileWithoutNamespace;
use Rector\Core\Provider\CurrentFileProvider;
use Rector\Core\ValueObject\Application\File;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PHPStan\Scope\PHPStanNodeScopeResolver;
use Rector\NodeTypeResolver\PHPStan\Scope\ScopeFactory;
use Symplify\SmartFileSystem\SmartFileInfo;

/**
 * In case of changed node, we need to re-traverse the PHPStan Scope to make all the new nodes aware of what is going on.
 */
final class ChangedNodeScopeRefresher
{
    public function __construct(
        private readonly PHPStanNodeScopeResolver $phpStanNodeScopeResolver,
        private readonly ScopeAnalyzer $scopeAnalyzer,
        private readonly CurrentFileProvider $currentFileProvider,
        private readonly ScopeFactory $scopeFactory
    ) {
    }

    public function refresh(Node $node, ?MutatingScope $mutatingScope, ?SmartFileInfo $smartFileInfo = null): void
    {
        // nothing to refresh
        if (! $this->scopeAnalyzer->hasScope($node)) {
            return;
        }

        if (! $smartFileInfo instanceof SmartFileInfo) {
            /** @var File $file */
            $file = $this->currentFileProvider->getFile();
            $smartFileInfo = $file->getSmartFileInfo();
        }

        if ($this->scopeAnalyzer->isScopeResolvableFromFile($node, $mutatingScope)) {
            $mutatingScope = $this->scopeFactory->createFromFile($smartFileInfo);
        }

        $parent = $node->getAttribute(AttributeKey::PARENT_NODE);
        if (! $mutatingScope instanceof MutatingScope && $node instanceof Expr && $parent instanceof Node) {
            $mutatingScope = $parent->getAttribute(AttributeKey::SCOPE);
        }

        if (! $mutatingScope instanceof MutatingScope) {
            $errorMessage = sprintf(
                'Node "%s" with parent of "%s" is missing scope required for scope refresh.',
                $node::class,
                $parent::class
            );

            throw new ShouldNotHappenException($errorMessage);
        }

        // note from flight: when we traverse ClassMethod, the scope must be already in Class_, otherwise it crashes
        // so we need to somehow get a parent scope that is already in the same place the $node is

        if ($node instanceof Attribute) {
            // we'll have to fake-traverse 2 layers up, as PHPStan skips Scope for AttributeGroups and consequently Attributes
            $attributeGroup = new AttributeGroup([$node]);
            $node = new Property(0, [], [], null, [$attributeGroup]);
        }

        $this->reIndexNodeAttributes($node);

        $stmts = $this->resolveStmts($node);
        $this->phpStanNodeScopeResolver->processNodes($stmts, $smartFileInfo, $mutatingScope);
    }

    private function reIndexNodeAttributes(Node $node): void
    {
        if (($node instanceof FileWithoutNamespace || $node instanceof Namespace_ || $node instanceof ClassLike || $node instanceof StmtsAwareInterface) && $node->stmts !== null) {
            $node->stmts = array_values($node->stmts);
        }

        if ($node instanceof FunctionLike) {
            /** @var ClassMethod|Function_|Closure $node */
            $node->params = array_values($node->params);

            if ($node instanceof Closure) {
                $node->uses = array_values($node->uses);
            }
        }

        if ($node instanceof CallLike) {
            /** @var FuncCall|MethodCall|New_|NullsafeMethodCall|StaticCall $node */
            $node->args = array_values($node->args);
        }

        if ($node instanceof If_) {
            $node->elseifs = array_values($node->elseifs);
        }

        if ($node instanceof TryCatch) {
            $node->catches = array_values($node->catches);
        }

        if ($node instanceof Switch_) {
            $node->cases = array_values($node->cases);
        }
    }

    /**
     * @return Stmt[]
     */
    private function resolveStmts(Node $node): array
    {
        if ($node instanceof Stmt) {
            return [$node];
        }

        if ($node instanceof Expr) {
            return [new Expression($node)];
        }

        $errorMessage = sprintf('Complete parent node of "%s" be a stmt.', $node::class);
        throw new ShouldNotHappenException($errorMessage);
    }
}
