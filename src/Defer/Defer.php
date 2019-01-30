<?php

namespace Nuwave\Lighthouse\Defer;

use Closure;
use Illuminate\Support\Arr;
use Nuwave\Lighthouse\GraphQL;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use Nuwave\Lighthouse\Events\ManipulatingAST;
use Nuwave\Lighthouse\Support\Contracts\GraphQLResponse;
use Symfony\Component\HttpFoundation\Response;
use Nuwave\Lighthouse\Execution\GraphQLRequest;
use Nuwave\Lighthouse\Schema\AST\PartialParser;
use Nuwave\Lighthouse\Support\Contracts\CanStreamResponse;

class Defer implements GraphQLResponse
{
    /**
     * @var \Nuwave\Lighthouse\Support\Contracts\CanStreamResponse
     */
    protected $stream;

    /**
     * @var \Nuwave\Lighthouse\GraphQL
     */
    protected $graphQL;

    /**
     * @var \Nuwave\Lighthouse\Execution\GraphQLRequest
     */
    protected $request;

    /**
     * @var mixed[]
     */
    protected $result = [];

    /**
     * @var mixed[]
     */
    protected $deferred = [];

    /**
     * @var mixed[]
     */
    protected $resolved = [];

    /**
     * @var bool
     */
    protected $deferMore = true;

    /**
     * @var bool
     */
    protected $isStreaming = false;

    /**
     * @var int
     */
    protected $maxExecutionTime = 0;

    /**
     * @var int
     */
    protected $maxNestedFields = 0;

    /**
     * @param  \Nuwave\Lighthouse\Support\Contracts\CanStreamResponse  $stream
     * @param  \Nuwave\Lighthouse\GraphQL  $graphQL
     * @param  \Nuwave\Lighthouse\Execution\GraphQLRequest  $request
     * @return void
     */
    public function __construct(CanStreamResponse $stream, GraphQL $graphQL, GraphQLRequest $request)
    {
        $this->stream = $stream;
        $this->graphQL = $graphQL;
        $this->request = $request;
        $this->maxNestedFields = config('lighthouse.defer.max_nested_fields', 0);
    }

    /**
     * Set the tracing directive on all fields of the query to enable tracing them.
     *
     * @param  \Nuwave\Lighthouse\Events\ManipulatingAST  $manipulatingAST
     * @return void
     */
    public function handleManipulatingAST(ManipulatingAST $manipulatingAST): void
    {
        $manipulatingAST->ast = ASTHelper::attachDirectiveToObjectTypeFields(
            $manipulatingAST->ast,
            PartialParser::directive('@deferrable')
        );

        $manipulatingAST->ast->setDefinition(
            PartialParser::directiveDefinition('directive @defer(if: Boolean) on FIELD')
        );
    }

    /**
     * @return bool
     */
    public function isStreaming(): bool
    {
        return $this->isStreaming;
    }

    /**
     * Register deferred field.
     *
     * @param  \Closure  $resolver
     * @param  string  $path
     * @return mixed
     */
    public function defer(Closure $resolver, string $path)
    {
        if ($data = Arr::get($this->result, "data.{$path}")) {
            return $data;
        }

        if ($this->isDeferred($path) || ! $this->deferMore) {
            return $this->resolve($resolver, $path);
        }

        $this->deferred[$path] = $resolver;
    }

    /**
     * @param  \Closure  $originalResolver
     * @param  string  $path
     * @return mixed
     */
    public function findOrResolve(Closure $originalResolver, string $path)
    {
        if (! $this->hasData($path)) {
            if (isset($this->deferred[$path])) {
                unset($this->deferred[$path]);
            }

            return $this->resolve($originalResolver, $path);
        }

        return Arr::get($this->result, "data.{$path}");
    }

    /**
     * Resolve field with data or resolver.
     *
     * @param  \Closure  $originalResolver
     * @param  string  $path
     * @return mixed
     */
    public function resolve(Closure $originalResolver, string $path)
    {
        $isDeferred = $this->isDeferred($path);
        $resolver = $isDeferred
            ? $this->deferred[$path]
            : $originalResolver;

        if ($isDeferred) {
            $this->resolved[] = $path;

            unset($this->deferred[$path]);
        }

        return $resolver();
    }

    /**
     * @param  string  $path
     * @return bool
     */
    public function isDeferred(string $path): bool
    {
        return isset($this->deferred[$path]);
    }

    /**
     * @param  string  $path
     * @return bool
     */
    public function hasData(string $path): bool
    {
        return Arr::has($this->result, "data.{$path}");
    }

    /**
     * Return either a final response or a stream of responses.
     *
     * @param  mixed[]  $data
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function create(array $data): Response
    {
        if (empty($this->deferred)) {
            return response($data);
        }

        return response()->stream(
            function () use ($data): void {
                $nested = 1;
                $this->result = $data;
                $this->isStreaming = true;
                $this->stream->stream($data, [], empty($this->deferred));

                if ($executionTime = config('lighthouse.defer.max_execution_ms', 0)) {
                    $this->maxExecutionTime = microtime(true) + ($executionTime * 1000);
                }

                // TODO: Allow nested_levels to be set in config
                // to break out of loop early.
                while (
                    count($this->deferred) &&
                    ! $this->executionTimeExpired() &&
                    ! $this->maxNestedFieldsResolved($nested)
                ) {
                    $nested++;
                    $this->executeDeferred();
                }

                // We've hit the max execution time or max nested levels of deferred fields.
                // Process remaining deferred fields.
                if (count($this->deferred)) {
                    $this->deferMore = false;
                    $this->executeDeferred();
                }
            },
            200,
            [
                // TODO: Allow headers to be set in config
                'X-Accel-Buffering' => 'no',
                'Content-Type' => 'multipart/mixed; boundary="-"',
            ]
        );
    }

    /**
     * @param  int  $time
     * @return void
     */
    public function setMaxExecutionTime(int $time): void
    {
        $this->maxExecutionTime = $time;
    }

    /**
     * Override max nested fields.
     *
     * @param  int  $max
     * @return void
     */
    public function setMaxNestedFields(int $max): void
    {
        $this->maxNestedFields = $max;
    }

    /**
     * Check if the maximum execution time has expired.
     *
     * @return bool
     */
    protected function executionTimeExpired(): bool
    {
        if ($this->maxExecutionTime === 0) {
            return false;
        }

        return $this->maxExecutionTime <= microtime(true);
    }

    /**
     * Check if the maximum number of nested field has been resolved.
     *
     * @param  int  $nested
     * @return bool
     */
    protected function maxNestedFieldsResolved(int $nested): bool
    {
        if ($this->maxNestedFields === 0) {
            return false;
        }

        return $nested >= $this->maxNestedFields;
    }

    /**
     * Execute deferred fields.
     *
     * @return void
     */
    protected function executeDeferred(): void
    {
        $this->result = $this->graphQL->executeRequest(
            $this->request
        );

        $this->stream->stream(
            $this->result,
            $this->resolved,
            empty($this->deferred)
        );

        $this->resolved = [];
    }
}
