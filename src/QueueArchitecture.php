<?php

namespace HorizonLens;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Throwable;

/** Static routing declarations only. Never loads or constructs application job classes. */
final class QueueArchitecture
{
    public function jobs(): array
    {
        $jobs = [];
        $sites = [];
        $partial = false;
        $files = [];
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        foreach ((array) config('horizon-lens.source_roots', [app_path()]) as $root) {
            $root = realpath($root);
            if (! $root || ! is_dir($root)) {
                $partial = true;

                continue;
            }
            try {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isLink() || $file->getExtension() !== 'php') {
                        continue;
                    }
                    $path = $file->getRealPath();
                    if (! $path || isset($files[$path])) {
                        continue;
                    }
                    if (count($files) >= 2000) {
                        $partial = true;
                        break;
                    }
                    $files[$path] = true;
                    $source = file_get_contents($path, false, null, 0, 262145);
                    if ($source === false || strlen($source) > 262144) {
                        $partial = true;

                        continue;
                    }
                    try {
                        $traverser = new NodeTraverser;
                        $traverser->addVisitor(new NameResolver);
                        $nodes = $traverser->traverse($parser->parse($source) ?? []);
                        $calls = $finder->findInstanceOf($nodes, Node\Expr\MethodCall::class);
                        $innerCalls = [];
                        foreach ($calls as $call) {
                            if ($call->var instanceof Node\Expr\MethodCall) {
                                $innerCalls[spl_object_id($call->var)] = true;
                            }
                        }
                        foreach ($calls as $call) {
                            if (isset($innerCalls[spl_object_id($call)])) {
                                continue;
                            }
                            $rootCall = $call;
                            $route = [];
                            while ($rootCall instanceof Node\Expr\MethodCall) {
                                $method = $rootCall->name instanceof Node\Identifier ? $rootCall->name->toString() : '';
                                $field = ['onQueue' => 'queue', 'onConnection' => 'connection'][$method] ?? null;
                                if ($field && ! array_key_exists($field, $route)) {
                                    $argument = $rootCall->args[0]->value ?? null;
                                    $route[$field] = $argument instanceof Node\Scalar\String_ ? $argument->value : null;
                                }
                                $rootCall = $rootCall->var;
                            }
                            if (! array_key_exists('queue', $route)) {
                                continue;
                            }
                            $target = null;
                            if ($rootCall instanceof Node\Expr\StaticCall && $rootCall->class instanceof Node\Name && $rootCall->name instanceof Node\Identifier && in_array($rootCall->name->toString(), ['dispatch', 'dispatchIf', 'dispatchUnless'], true)) {
                                $target = $rootCall->class->toString();
                            }
                            if ($target) {
                                $sites[] = ['class' => $target, 'queue' => $route['queue'], 'connection' => $route['connection'] ?? null, 'dynamic_connection' => array_key_exists('connection', $route) && $route['connection'] === null, 'source' => basename($root).'/'.substr($path, strlen($root) + 1)];
                            }
                        }
                        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                            if (! $class->name || $class->isAbstract()) {
                                continue;
                            }
                            $interfaces = array_map(fn ($name) => $name->toString(), $class->implements);
                            if (! array_intersect($interfaces, ['Illuminate\\Contracts\\Queue\\ShouldQueue', 'Illuminate\\Contracts\\Queue\\ShouldBeEncrypted']) && ! (str_contains($path, '/Jobs/') && $class->getMethod('handle'))) {
                                continue;
                            }
                            $name = $class->namespacedName?->toString() ?? $class->name->toString();
                            $values = ['queue' => [], 'connection' => []];
                            $dynamic = $class->extends !== null;
                            foreach ($class->getProperties() as $property) {
                                foreach ($property->props as $prop) {
                                    $field = $prop->name->toString();
                                    if (isset($values[$field]) && $prop->default !== null) {
                                        if ($prop->default instanceof Node\Scalar\String_) {
                                            $values[$field][] = $prop->default->value;
                                        } elseif (! ($prop->default instanceof Node\Expr\ConstFetch && strtolower($prop->default->name->toString()) === 'null')) {
                                            $dynamic = true;
                                        }
                                    }
                                }
                            }
                            // Inspect only constructor routing on $this, not unrelated calls in handle().
                            foreach ($finder->find($class->getMethod('__construct')?->stmts ?? [], fn (Node $node) => $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\Assign) as $node) {
                                $field = null;
                                $value = null;
                                $receiver = $node instanceof Node\Expr\MethodCall ? $node->var : null;
                                while ($receiver instanceof Node\Expr\MethodCall) {
                                    $receiver = $receiver->var;
                                }
                                if ($node instanceof Node\Expr\MethodCall && $receiver instanceof Node\Expr\Variable && $receiver->name === 'this' && $node->name instanceof Node\Identifier) {
                                    $field = ['onQueue' => 'queue', 'onConnection' => 'connection'][$node->name->toString()] ?? null;
                                    $value = $node->args[0]->value ?? null;
                                } elseif ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\PropertyFetch && $node->var->var instanceof Node\Expr\Variable && $node->var->var->name === 'this' && $node->var->name instanceof Node\Identifier) {
                                    $field = $node->var->name->toString();
                                    $value = $node->expr;
                                }
                                if ($field !== null && isset($values[$field])) {
                                    if ($value instanceof Node\Scalar\String_) {
                                        $values[$field][] = $value->value;
                                    } else {
                                        $dynamic = true;
                                    }
                                }
                            }
                            $connection = count(array_unique($values['connection'])) === 1 ? $values['connection'][0] : config('queue.default');
                            if (count(array_unique($values['connection'])) > 1) {
                                $dynamic = true;
                            }
                            $queues = array_values(array_unique($values['queue']));
                            $default = ! $queues;
                            if ($default && ! $dynamic) {
                                $queues = [(string) config("queue.connections.{$connection}.queue", 'default')];
                            }
                            $jobs[$name] = ['class' => $name, 'connection' => $connection, 'queues' => $queues,
                                'dynamic' => $dynamic, 'default' => $default, 'source' => basename($root).'/'.substr($path, strlen($root) + 1)];
                        }
                    } catch (Throwable) {
                        $partial = true;
                    }
                }
            } catch (Throwable) {
                $partial = true;
            }
        }
        foreach ($sites as $site) {
            if (! isset($jobs[$site['class']])) {
                continue;
            }
            if ($site['queue'] === null || $site['dynamic_connection']) {
                $jobs[$site['class']]['dynamic'] = true;

                continue;
            }
            $connection = $site['connection'] ?? $jobs[$site['class']]['connection'];
            if ($site['connection'] === null && $jobs[$site['class']]['dynamic']) {
                continue;
            }
            $key = $site['class'].'@'.$connection.':'.$site['queue'];
            $jobs[$key] = ['class' => $site['class'], 'connection' => $connection, 'queues' => [$site['queue']], 'dynamic' => false, 'default' => false, 'source' => $site['source']];
        }
        ksort($jobs);

        return ['jobs' => $jobs, 'partial' => $partial];
    }
}
