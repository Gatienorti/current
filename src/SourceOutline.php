<?php

namespace Current;

use Current\Support\Sanitizer;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

final class SourceOutline
{
    private const MAX_CACHE_BYTES = 2097152;

    /** Only compact outline JSON is retained, never source text, tokens or ASTs. */
    private array $cache = [];

    private int $cacheBytes = 0;

    public function __construct(private ParserFactory $parserFactory) {}

    public function capture(string $class): array
    {
        try {
            $method = new ReflectionMethod($class, 'handle');
            $file = $method->getFileName();
            $relative = $file ? app(Sanitizer::class)->location($file) : null;
            if (! $relative) {
                return [];
            }
            $maxBytes = max(1, min(1048576, (int) config('current.max_source_bytes', 262144)));
            // Read a bounded snapshot rather than trusting cached file size/mtime.
            $source = @file_get_contents($file, false, null, 0, $maxBytes + 1);
            if ($source === false || strlen($source) > $maxBytes) {
                return [];
            }
            $hash = hash('sha256', $source);
            $limit = max(0, min(1000, (int) config('current.max_source_operations', 100)));
            $capacity = max(0, min(512, (int) config('current.source_cache_entries', 128)));
            $this->trim($capacity);
            $key = hash('sha256', $file."\0".$method->getDeclaringClass()->getName()."\0".$method->getStartLine()."\0".$limit."\0".$hash);
            if (isset($this->cache[$key])) {
                $json = $this->cache[$key];
                unset($this->cache[$key]);
                $this->cache[$key] = $json;

                return $this->snapshot($relative, $hash, $json);
            }
            $ast = $this->parserFactory->createForNewestSupportedVersion()->parse($source);
            $finder = new NodeFinder;
            $handle = $finder->findFirst($ast, fn (Node $node) => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === 'handle' && $node->getStartLine() === $method->getStartLine());
            if (! $handle) {
                return [];
            }
            $calls = $finder->find($handle->stmts ?? [], fn (Node $node) => $node instanceof Node\Expr\CallLike || $node instanceof Node\Stmt\Throw_);
            $operations = [];
            foreach (array_slice($calls, 0, $limit) as $call) {
                $label = match (true) {
                    $call instanceof Node\Expr\MethodCall, $call instanceof Node\Expr\NullsafeMethodCall => ($call->var instanceof Node\Expr\Variable && is_string($call->var->name) ? '$'.$call->var->name : 'object').'->'.($call->name instanceof Node\Identifier ? $call->name->toString() : '{dynamic}').'()',
                    $call instanceof Node\Expr\StaticCall => ($call->class instanceof Node\Name ? $call->class->toString() : '{dynamic}').'::'.($call->name instanceof Node\Identifier ? $call->name->toString() : '{dynamic}').'()',
                    $call instanceof Node\Expr\FuncCall => ($call->name instanceof Node\Name ? $call->name->toString() : '{dynamic}').'()',
                    $call instanceof Node\Expr\New_ => 'new '.($call->class instanceof Node\Name ? $call->class->toString() : '{dynamic}'),
                    default => 'throw',
                };
                $operations[] = ['label' => mb_substr($label, 0, 160), 'line' => $call->getStartLine(), 'end_line' => $call->getEndLine()];
            }

            $json = json_encode($operations, JSON_THROW_ON_ERROR);
            if ($capacity > 0 && strlen($json) <= self::MAX_CACHE_BYTES) {
                $this->cache[$key] = $json;
                $this->cacheBytes += strlen($json);
                $this->trim($capacity);
            }

            return $this->snapshot($relative, $hash, $json);
        } catch (Throwable) {
            return [];
        }
    }

    private function snapshot(string $file, string $hash, string $operations): array
    {
        return [
            'source_file' => $file,
            'source_hash' => $hash,
            'source_revision' => config('current.source_revision'),
            'source_operations' => $operations,
        ];
    }

    private function trim(int $capacity): void
    {
        while (count($this->cache) > $capacity || $this->cacheBytes > self::MAX_CACHE_BYTES) {
            $key = array_key_first($this->cache);
            $this->cacheBytes -= strlen($this->cache[$key]);
            unset($this->cache[$key]);
        }
    }
}
