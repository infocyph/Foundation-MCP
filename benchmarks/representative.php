#!/usr/bin/env php
<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Mcp\ServerFactory;
use Infocyph\FoundationMcp\Mcp\Tool\ChangesTool;
use Infocyph\FoundationMcp\Mcp\Tool\ImpactTool;
use Infocyph\FoundationMcp\Mcp\Tool\InspectTool;
use Infocyph\FoundationMcp\Mcp\Tool\ProjectTool;
use Infocyph\FoundationMcp\Mcp\Tool\SearchTool;
use Infocyph\FoundationMcp\Mcp\Tool\SymbolTool;
use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;
use Infocyph\FoundationMcp\Mcp\Tool\UsagesTool;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

require dirname(__DIR__) . '/vendor/autoload.php';

const BENCHMARK_RESULT = '.phpforge-report/benchmark-result.json';
const STABILITY_SPREAD_PERCENT = 15.0;

$sizes = [
    'small' => ['files' => 8, 'repetitions' => 3],
    'medium' => ['files' => 64, 'repetitions' => 2],
    'large' => ['files' => 256, 'repetitions' => 1],
];
$stableEnvironment = getenv('FOUNDATION_MCP_BENCH_STABLE') === '1';
$workloads = [];
$failures = [];

foreach ($sizes as $size => $settings) {
    $root = createBenchmarkProject($settings['files']);

    try {
        initializeGitFixture($root);
        $project = (new ProjectDetector())->detect($root);
        $warm = new ToolServices($project, gitEnabled: true);
        warmServices($warm);
        $factory = new ServerFactory($project, gitEnabled: true);
        $factory->create();

        foreach (benchmarkCases($project, $warm, $factory) as $case) {
            foreach (['cold', 'warm'] as $mode) {
                $operation = $case[$mode];
                $name = implode('.', [$size, $mode, $case['name']]);
                $workload = measureWorkload(
                    $name,
                    $size,
                    $mode,
                    $settings['files'],
                    $settings['repetitions'],
                    $mode === 'warm' ? 1 : 0,
                    $operation,
                    $stableEnvironment,
                );
                $workloads[] = $workload;

                if ($workload['result']['failed_operations'] > 0) {
                    $failures[] = $name;
                }
            }
        }
    } finally {
        TempProject::remove($root);
    }
}

$environment = benchmarkEnvironment($stableEnvironment);
$result = [
    'schema_version' => 1,
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'environment' => $environment,
    'workloads' => $workloads,
];

$directory = dirname(BENCHMARK_RESULT);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    fwrite(STDERR, 'Unable to create benchmark report directory.' . PHP_EOL);
    exit(2);
}

file_put_contents(
    BENCHMARK_RESULT,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);

$summary = array_map(
    static fn(array $workload): array => [
        'workload' => $workload['name'],
        'successful_rpm' => $workload['result']['successful_rpm'],
        'latency_ms' => $workload['result']['latency_ms']['average'],
        'stability' => $workload['result']['stability']['status'],
    ],
    $workloads,
);

echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

if ($failures !== []) {
    fwrite(STDERR, 'Representative benchmark failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

/** @return array<string, bool|string|list<string>> */
function benchmarkEnvironment(bool $stable): array
{
    $extensions = get_loaded_extensions();
    sort($extensions, SORT_STRING);
    $cpuModel = cpuModel();
    $fingerprintParts = [
        PHP_VERSION,
        PHP_SAPI,
        PHP_OS_FAMILY,
        php_uname('m'),
        $cpuModel,
        (string) ini_get('memory_limit'),
        (string) ini_get('opcache.enable_cli'),
        (string) ini_get('opcache.jit'),
        extension_loaded('xdebug') ? 'xdebug' : 'no-xdebug',
        implode(',', $extensions),
    ];

    return [
        'stable' => $stable,
        'fingerprint' => hash('sha256', implode('|', $fingerprintParts)),
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'operating_system' => PHP_OS_FAMILY . ' ' . php_uname('r'),
        'cpu_model' => $cpuModel,
        'memory_limit' => (string) ini_get('memory_limit'),
        'opcache' => (string) ini_get('opcache.enable_cli'),
        'jit' => (string) ini_get('opcache.jit'),
        'xdebug' => extension_loaded('xdebug'),
        'extensions' => $extensions,
        'runner' => 'foundation-mcp-representative-v1',
    ];
}

/** @return list<array{name:string,cold:Closure,warm:Closure}> */
function benchmarkCases(Project $project, ToolServices $warm, ServerFactory $factory): array
{
    return [
        [
            'name' => 'server_startup',
            'cold' => static fn(): mixed => (new ServerFactory($project, gitEnabled: true))->create(),
            'warm' => static fn(): mixed => $factory->create(),
        ],
        [
            'name' => 'foundation_project',
            'cold' => static fn(): array => (new ProjectTool(new ToolServices($project, gitEnabled: true)))->execute(),
            'warm' => static fn(): array => (new ProjectTool($warm))->execute(),
        ],
        [
            'name' => 'exact_symbol',
            'cold' => static fn(): array => (new SymbolTool(new ToolServices($project, gitEnabled: true)))->execute('App\\Service'),
            'warm' => static fn(): array => (new SymbolTool($warm))->execute('App\\Service'),
        ],
        [
            'name' => 'search',
            'cold' => static fn(): array => (new SearchTool(new ToolServices($project, gitEnabled: true)))->execute('Service', 'project', 'symbol', null, 20),
            'warm' => static fn(): array => (new SearchTool($warm))->execute('Service', 'project', 'symbol', null, 20),
        ],
        [
            'name' => 'usages',
            'cold' => static fn(): array => (new UsagesTool(new ToolServices($project, gitEnabled: true)))->execute('App\\Service', null, null, 100),
            'warm' => static fn(): array => (new UsagesTool($warm))->execute('App\\Service', null, null, 100),
        ],
        [
            'name' => 'module_inspection',
            'cold' => static fn(): array => (new InspectTool(new ToolServices($project, gitEnabled: true)))->execute('modules'),
            'warm' => static fn(): array => (new InspectTool($warm))->execute('modules'),
        ],
        [
            'name' => 'route_inspection',
            'cold' => static fn(): array => (new InspectTool(new ToolServices($project, gitEnabled: true)))->execute('routes'),
            'warm' => static fn(): array => (new InspectTool($warm))->execute('routes'),
        ],
        [
            'name' => 'dependency_graph',
            'cold' => static fn(): array => new ToolServices($project, gitEnabled: true)->composer()->graph()->dependencies('infocyph/foundation', 10),
            'warm' => static fn(): array => $warm->composer()->graph()->dependencies('infocyph/foundation', 10),
        ],
        [
            'name' => 'change_analysis',
            'cold' => static fn(): array => (new ChangesTool(new ToolServices($project, gitEnabled: true)))->execute(),
            'warm' => static fn(): array => (new ChangesTool($warm))->execute(),
        ],
        [
            'name' => 'impact_analysis',
            'cold' => static fn(): array => (new ImpactTool(new ToolServices($project, gitEnabled: true)))->execute('changes', null, 100),
            'warm' => static fn(): array => (new ImpactTool($warm))->execute('changes', null, 100),
        ],
    ];
}

function benchmarkPercentile(array $sorted, float $percentile): ?float
{
    if ($sorted === []) {
        return null;
    }

    $index = (int) ceil((count($sorted) - 1) * $percentile);

    return $sorted[$index];
}

function cpuModel(): string
{
    if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/cpuinfo')) {
        $handle = fopen('/proc/cpuinfo', 'rb');
        if ($handle !== false) {
            try {
                $contents = stream_get_contents($handle, 65_536);
            } finally {
                fclose($handle);
            }
            if (is_string($contents) && preg_match('/^model name\s*:\s*(.+)$/mi', $contents, $match) === 1) {
                return trim($match[1]);
            }
        }
    }

    return php_uname('m');
}

function createBenchmarkProject(int $scale): string
{
    $locked = [
        [
            'name' => 'infocyph/foundation',
            'version' => '2.1.1',
            'source' => ['reference' => 'foundation-ref'],
            'require' => ['infocyph/webrick' => '^3.3', 'bench/pkg-0001' => '^1.0'],
        ],
        [
            'name' => 'infocyph/webrick',
            'version' => '3.3.0',
            'source' => ['reference' => 'webrick-ref'],
        ],
    ];
    $installed = [
        [
            'name' => 'infocyph/foundation',
            'version' => '2.1.1',
            'source' => ['reference' => 'foundation-ref'],
            'install-path' => '../infocyph/foundation',
        ],
        [
            'name' => 'infocyph/webrick',
            'version' => '3.3.0',
            'source' => ['reference' => 'webrick-ref'],
            'install-path' => '../infocyph/webrick',
        ],
    ];
    $directories = [
        'app',
        'tests',
        'bootstrap',
        'config',
        'routes',
        'vendor/composer',
        'vendor/infocyph/foundation/src/Application',
        'vendor/infocyph/foundation/src/Module',
        'vendor/infocyph/foundation/src/Routing',
        'vendor/infocyph/webrick/src/Constants',
    ];
    $files = [
        'app/Service.php' => "<?php\nnamespace App;\nfinal class Service { public function run(): int { return 1; } }\n",
        'app/Consumer.php' => "<?php\nnamespace App;\nfinal class Consumer { public function useIt(): int { return (new Service())->run(); } }\n",
        'tests/ServiceTest.php' => "<?php\nnamespace Tests;\nuse App\\Service;\nfinal class ServiceTest { public function testIt(): void { new Service(); } }\n",
        'bootstrap/app.php' => "<?php\nuse Infocyph\\Foundation\\Foundation;\nreturn Foundation::web([]);\n",
        'config/app.php' => "<?php\nreturn ['name' => 'benchmark'];\n",
        'README.md' => "# Representative benchmark fixture\n",
        'vendor/infocyph/foundation/src/Foundation.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation;
use Infocyph\Foundation\Application\RuntimeMode;
final class Foundation
{
    public static function web(array $config = []): mixed
    {
        return RuntimeFactory::createFor(RuntimeMode::Web);
    }
}
PHP,
        'vendor/infocyph/foundation/src/Module/ModuleCatalog.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation\Module;
final class ModuleCatalog
{
    private const array MODULES = [
        'web' => [
            'packages' => ['infocyph/webrick' => '^3.3'],
            'built_in' => false,
            'description' => 'Web routing.',
            'aliases' => ['router'],
            'config' => ['router.php'],
            'schemas' => ['router'],
        ],
    ];
}
PHP,
        'vendor/infocyph/foundation/src/Routing/RouteFileLoader.php' => <<<'PHP'
<?php
namespace Infocyph\Foundation\Routing;
final class RouteFileLoader
{
    public function __construct(private array $files = ['api.php']) {}
}
PHP,
        'vendor/infocyph/webrick/src/Constants/HttpMethodEnum.php' => <<<'PHP'
<?php
namespace Infocyph\Webrick\Constants;
enum HttpMethodEnum: string
{
    case GET = 'GET';
    case POST = 'POST';
}
PHP,
    ];

    $routeLines = [
        '<?php',
        'use Infocyph\\Webrick\\Router\\Facade\\Router;',
    ];

    for ($index = 1; $index <= $scale; ++$index) {
        $class = sprintf('Generated%04d', $index);
        $files['app/' . $class . '.php'] = sprintf(
            "<?php\nnamespace App;\nfinal class %s { public function run(Service $service): int { return $service->run(); } }\n",
            $class,
        );
        $routeLines[] = sprintf("Router::get('/bench/%d', 'App\\\\Service::run');", $index);

        $package = sprintf('bench/pkg-%04d', $index);
        $next = $index < $scale ? sprintf('bench/pkg-%04d', $index + 1) : null;
        $lockedPackage = [
            'name' => $package,
            'version' => '1.0.0',
            'source' => ['reference' => sprintf('bench-ref-%04d', $index)],
        ];
        if ($next !== null) {
            $lockedPackage['require'] = [$next => '^1.0'];
        }
        $locked[] = $lockedPackage;
        $installed[] = [
            'name' => $package,
            'version' => '1.0.0',
            'source' => ['reference' => sprintf('bench-ref-%04d', $index)],
            'install-path' => sprintf('../bench/pkg-%04d', $index),
        ];
        $directories[] = sprintf('vendor/bench/pkg-%04d', $index);
    }

    $files['routes/api.php'] = implode("\n", $routeLines) . "\n";
    $files['config/router.php'] = "<?php\nreturn ['attributes' => ['enabled' => false]];\n";
    $files['composer.lock'] = json_encode(['packages' => $locked, 'packages-dev' => []], JSON_THROW_ON_ERROR);
    $files['vendor/composer/installed.json'] = json_encode(['packages' => $installed], JSON_THROW_ON_ERROR);

    return TempProject::create(
        composer: [
            'name' => 'benchmark/foundation-host',
            'require' => [
                'php' => '^8.4',
                'infocyph/foundation' => '^2.1.1',
                'infocyph/webrick' => '^3.3',
            ],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ],
        directories: $directories,
        files: $files,
    );
}

function initializeGitFixture(string $root): void
{
    runGit($root, ['init', '--quiet']);
    runGit($root, ['config', 'user.name', 'Foundation MCP Benchmark']);
    runGit($root, ['config', 'user.email', 'benchmark@example.invalid']);
    runGit($root, ['add', '--all']);
    runGit($root, ['commit', '--quiet', '-m', 'benchmark baseline']);

    file_put_contents(
        $root . '/app/Service.php',
        "<?php\nnamespace App;\nfinal class Service { public function run(): int { return 2; } public function changed(): bool { return true; } }\n",
    );

    $lockPath = $root . '/composer.lock';
    $lock = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);
    if (is_array($lock['packages'] ?? null) && isset($lock['packages'][0]) && is_array($lock['packages'][0])) {
        $lock['packages'][0]['source']['reference'] = 'foundation-ref-changed';
    }
    file_put_contents($lockPath, json_encode($lock, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** @return array<string,mixed> */
function measureWorkload(
    string $name,
    string $size,
    string $mode,
    int $files,
    int $repetitions,
    int $warmupOperations,
    Closure $operation,
    bool $stableEnvironment,
): array {
    for ($index = 0; $index < $warmupOperations; ++$index) {
        $operation();
    }

    $latencies = [];
    $successful = 0;
    $failed = 0;

    for ($index = 0; $index < $repetitions; ++$index) {
        $start = hrtime(true);
        try {
            $operation();
            ++$successful;
        } catch (Throwable) {
            ++$failed;
        } finally {
            $latencies[] = (hrtime(true) - $start) / 1_000_000;
        }
    }

    sort($latencies, SORT_NUMERIC);
    $average = $latencies === [] ? 0.0 : array_sum($latencies) / count($latencies);
    $minimum = $latencies[0] ?? null;
    $maximum = $latencies === [] ? null : $latencies[array_key_last($latencies)];
    $spread = $average > 0.0 && $maximum !== null && $minimum !== null
        ? (($maximum - $minimum) / $average) * 100
        : 0.0;
    $secondsPerOperation = max($average / 1000, 0.000000001);

    return [
        'name' => $name,
        'type' => 'component',
        'metadata' => [
            'surface' => substr($name, strrpos($name, '.') + 1),
            'fixture_size' => $size,
            'mode' => $mode,
            'generated_php_files' => $files,
        ],
        'repetitions' => $repetitions,
        'warmup_operations' => $warmupOperations,
        'duration_seconds' => 0.0,
        'concurrency' => 1,
        'result' => [
            'attempted_operations' => $repetitions,
            'successful_operations' => $successful,
            'failed_operations' => $failed,
            'timeouts' => 0,
            'successful_rpm' => $successful === 0 ? 0.0 : 60.0 / $secondsPerOperation,
            'error_rate' => $repetitions === 0 ? 0.0 : $failed / $repetitions,
            'latency_ms' => [
                'minimum' => $minimum,
                'average' => $average,
                'p50' => benchmarkPercentile($latencies, 0.50),
                'p95' => benchmarkPercentile($latencies, 0.95),
                'p99' => benchmarkPercentile($latencies, 0.99),
                'maximum' => $maximum,
            ],
            'cpu' => ['average_percent' => null, 'peak_percent' => null],
            'memory' => ['average_mb' => null, 'peak_mb' => null, 'growth_mb' => null],
            'stability' => [
                'status' => $stableEnvironment && $spread <= STABILITY_SPREAD_PERCENT ? 'stable' : 'unverified',
                'spread_percent' => $spread,
            ],
        ],
    ];
}

function runGit(string $root, array $arguments): void
{
    $command = ['git', '-C', $root, ...$arguments];
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Git for representative benchmark setup.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            'Git benchmark setup failed (%d): %s%s',
            $exitCode,
            is_string($stderr) ? trim($stderr) : '',
            is_string($stdout) && trim($stdout) !== '' ? ' ' . trim($stdout) : '',
        ));
    }
}

function warmServices(ToolServices $services): void
{
    $services->composer()->graph();
    $services->symbols()->project();
    $services->references()->project();
    $services->modules()->modules();
    $services->routes()->inspect();
    $services->workspace()->inspect();
    (new ProjectTool($services))->execute();
    (new ChangesTool($services))->execute();
    (new ImpactTool($services))->execute('changes', null, 100);
}
