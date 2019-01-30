<?php

namespace Nuwave\Lighthouse\Defer;

use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\TypeNode;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Exceptions\ParseClientException;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\Directive;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class DeferrableDirective extends BaseDirective implements Directive, FieldMiddleware
{
    const NAME = 'deferrable';

    /**
     * @var Defer
     */
    private $defer;

    /**
     * @param  \Nuwave\Lighthouse\Defer\Defer  $defer
     * @return void
     */
    public function __construct(Defer $defer)
    {
        $this->defer = $defer;
    }

    /**
     * Name of the directive.
     *
     * @return string
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Resolve the field directive.
     *
     * @param  \Nuwave\Lighthouse\Schema\Values\FieldValue  $value
     * @param  \Closure  $next
     * @return \Nuwave\Lighthouse\Schema\Values\FieldValue
     */
    public function handleField(FieldValue $value, \Closure $next): FieldValue
    {
        $resolver = $value->getResolver();
        $fieldType = $value->getField()->type;

        $value->setResolver(
            function ($root, $args, GraphQLContext $context, ResolveInfo $info) use ($resolver, $fieldType) {
                $wrappedResolver = function () use ($resolver, $root, $args, $context, $info) {
                    return $resolver($root, $args, $context, $info);
                };
                $path = implode('.', $info->path);

                if ($this->shouldDefer($fieldType, $info)) {
                    return $this->defer->defer($wrappedResolver, $path);
                }

                return $this->defer->isStreaming()
                    ? $this->defer->findOrResolve($wrappedResolver, $path)
                    : $resolver($root, $args, $context, $info);
            }
        );

        return $next($value);
    }

    /**
     * Determine of field should be deferred.
     *
     * @param  \GraphQL\Language\AST\TypeNode  $fieldType
     * @param  \GraphQL\Type\Definition\ResolveInfo  $info
     * @return bool
     *
     * @throws \Nuwave\Lighthouse\Exceptions\ParseClientException
     */
    protected function shouldDefer(TypeNode $fieldType, ResolveInfo $info): bool
    {
        if (strtolower($info->operation->operation) === 'mutation') {
            return false;
        }

        foreach ($info->fieldNodes as $fieldNode) {
            $deferDirective = ASTHelper::directiveDefinition($fieldNode, 'defer');

            if (! $deferDirective) {
                return false;
            }

            if (! ASTHelper::directiveArgValue($deferDirective, 'if', true)) {
                return false;
            }

            $skipDirective = ASTHelper::directiveDefinition($fieldNode, 'skip');
            $includeDirective = ASTHelper::directiveDefinition($fieldNode, 'include');

            $shouldSkip = $skipDirective
                ? ASTHelper::directiveArgValue($skipDirective, 'if', false)
                : false;
            $shouldInclude = $includeDirective
                ? ASTHelper::directiveArgValue($includeDirective, 'if', false)
                : false;

            if ($shouldSkip || $shouldInclude) {
                return false;
            }
        }

        if ($fieldType instanceof NonNullTypeNode) {
            throw new ParseClientException('The @defer directive cannot be placed on a Non-Nullable field.');
        }

        return true;
    }
}
