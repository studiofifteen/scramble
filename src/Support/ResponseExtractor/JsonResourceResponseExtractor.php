<?php

namespace Dedoc\Documentor\Support\ResponseExtractor;

use Dedoc\Documentor\Support\Generator\OpenApi;
use Dedoc\Documentor\Support\Generator\Response;
use Dedoc\Documentor\Support\Generator\Schema;
use Dedoc\Documentor\Support\Generator\Types\ObjectType;
use Dedoc\Documentor\Support\Generator\Types\StringType;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\FirstFindingVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;

class JsonResourceResponseExtractor
{
    private string $class;

    private OpenApi $openApi;

    public function __construct(OpenApi $openApi, string $class)
    {
        $this->class = $class;
        $this->openApi = $openApi;
    }

    public function extract(): ?Response
    {
        if ($this->openApi->components->hasSchema($schemaName = class_basename($this->class))) {
            return Response::make(200)
                ->setContent(
                    'application/json',
                    Schema::reference('schemas', $schemaName)
                );
        }

        $reflectionClass = new ReflectionClass($this->class);
        $classSourceCode = file_get_contents($reflectionClass->getFileName());
        $classAst = (new ParserFactory)->create(ParserFactory::PREFER_PHP7)->parse($classSourceCode);

        /** @var Node\Stmt\ClassMethod|null $methodNode */
        [$methodNode, $getFqName] = $this->findFirstNode(
            $classAst,
            fn (Node $node) => $node instanceof Node\Stmt\ClassMethod && $node->name->name === 'toArray'
        );

        if (! $methodNode) {
            return null;
        }

        $modelClass = $this->getModelName($reflectionClass, $getFqName);

        /*
         * To describe the response Documentor uses 2 strategies:
         *
         * 1. Trying to execute the resource's `toArray` method.
         * 2. If 1 fails, simple static analysis is used to get the values of the array.
         * 2.1. Simple static analysis is really simple. It works only when array is returned from `toArray` and
         * can understand only few things regarding typing:
         * - key type when string
         * - value type from property fetch on resource ($this->id)
         * - value type from property fetch on resource prop ($this->resource->id)
         * - value type from resource construction (new SomeResource(xxx)), when xxx is `when` call, key is optional
         * - optionally merged arrays:
         * -- mergeWhen +
         * -- merge +
         * -- when
         */

        /** @var Node\Stmt\Return_|null $returnNode */
        $returnNode = (new NodeFinder())->findFirst(
            $methodNode,
            fn (Node $node) => $node instanceof Node\Stmt\Return_
        );

        if (! $returnNode) {
//            dump('no return node', $this->class);
            return null;
        }

        if ($returnNode->expr instanceof Node\Expr\Array_) {
            [$sampleResponse, $requiredFields] = (new ArrayNodeSampleInferer($this->openApi, $returnNode->expr, $getFqName))();
        }

        if (! isset($sampleResponse)) {
//            dump('no $sampleResponse node', $this->class);
            return null;
        }

//        return null;
//
//        dd('fuck');
//
//        [$sampleResponse, $requiredFields] = $this->getSampleResponse($modelClass);

        $schema = Schema::fromType($type = new ObjectType);
        collect($sampleResponse)->each(function ($v, $key) use ($type) {
            if (! is_string($key)) {
                return;
            }

            if (is_string($v)) {
                $type->addProperty($key, new StringType);
            } elseif ($v instanceof Schema) {
                $type->addProperty($key, $v);
            }
        })->filter();
        $type->setRequired($requiredFields);

        // Each resource is saved as a schema.
        $this->openApi
            ->components
            ->addSchema($schemaName, $schema);

        return Response::make(200)
            ->setContent('application/json', Schema::reference('schemas', $schemaName));
    }

    private function getSampleResponse(string $modelClass)
    {
        $resource = new $this->class(app($modelClass));

        return [
            $result = $resource->toArray(request()),
            array_keys($result),
        ];
    }

    private function findFirstNode(?array $classAst, \Closure $param)
    {
        $visitor = new FirstFindingVisitor($param);

        $nameResolver = new NameResolver();
        $traverser = new NodeTraverser;
        $traverser->addVisitor($nameResolver);
        $traverser->addVisitor($visitor);
        $traverser->traverse($classAst);

        /** @var Node\Stmt\ClassMethod $methodNode */
        $methodNode = $visitor->getFoundNode();

        // @todo Fix dirty way of getting the map of aliases
        $context = $nameResolver->getNameContext();
        $reflection = new ReflectionClass($context);
        $property = $reflection->getProperty('origAliases');
        $property->setAccessible(true);
        $value = $property->getValue($context);
        $ns = count($classAst) === 1 && $classAst[0] instanceof Node\Stmt\Namespace_
            ? $classAst[0]->name->toString()
            : null;
        $aliases = array_map(fn (Node\Name $n) => $n->toCodeString(), $value[1]);

        $getFqName = function (string $shortName) use ($ns, $aliases) {
            if (array_key_exists($shortName, $aliases)) {
                return $aliases[$shortName];
            }

            if ($ns && ($fqName = $ns.'\\'.$shortName) && class_exists($fqName)) {
                return $fqName;
            }

            return $shortName;
        };

        return [$methodNode, $getFqName];
    }

    private function getModelName(ReflectionClass $reflectionClass, callable $getFqName)
    {
        $phpDoc = $reflectionClass->getDocComment() ?: '';

        $mixinOrPropertyLine = Str::of($phpDoc)
            ->explode("\n")
            ->first(fn ($str) => Str::is(['*@property*$resource', '*@mixin*'], $str));

        if ($mixinOrPropertyLine) {
            $modelName = Str::replace(['@property', '$resource', '@mixin', ' ', '*'], '', $mixinOrPropertyLine);

            $modelClass = $getFqName($modelName);

            if (class_exists($modelClass)) {
                return $modelClass;
            }
        }

        $modelName = (string) Str::of(Str::of($this->class)->explode('\\')->last())->replace('Resource', '')->singular();

        $modelClass = 'App\\Models\\'.$modelName;
        if (! class_exists($modelClass)) {
            return null;
        }

        return null;
    }
}
