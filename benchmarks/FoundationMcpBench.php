<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Benchmarks;

use Infocyph\FoundationMcp\Mcp\ServerFactory;
use Infocyph\FoundationMcp\Mcp\Tool\ImpactTool;
use Infocyph\FoundationMcp\Mcp\Tool\ProjectTool;
use Infocyph\FoundationMcp\Mcp\Tool\SearchTool;
use Infocyph\FoundationMcp\Mcp\Tool\SymbolTool;
use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;
use Infocyph\FoundationMcp\Mcp\Tool\UsagesTool;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;
use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[Revs(1)]
#[Iterations(3)]
#[Warmup(1)]
#[BeforeMethods('setUp')]
#[AfterMethods('tearDown')]
final class FoundationMcpBench
{
    private Project $project;

    private string $root = '';

    private ToolServices $services;

    public function setUp(): void
    {
        $this->root = TempProject::create(
            composer: [
                'name' => 'benchmark/foundation-host',
                'require' => ['php' => '^8.4', 'infocyph/foundation' => '^2.1'],
                'autoload' => ['psr-4' => ['App\\' => 'app/']],
                'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
            ],
            directories: ['app', 'tests', 'bootstrap', 'config', 'routes'],
            files: [
                'app/Service.php' => "<?php\nnamespace App;\nfinal class Service { public function run(): int { return 1; } }\n",
                'app/Consumer.php' => "<?php\nnamespace App;\nfinal class Consumer { public function useIt(): int { return (new Service())->run(); } }\n",
                'tests/ServiceTest.php' => "<?php\nnamespace Tests;\nuse App\\Service;\nfinal class ServiceTest { public function testIt(): void { new Service(); } }\n",
                'routes/api.php' => "<?php\n// benchmark route fixture\n",
                'README.md' => "# Benchmark fixture\n",
            ],
        );

        $this->project = (new ProjectDetector())->detect($this->root);
        $this->services = new ToolServices($this->project, gitEnabled: false);

        $this->services->symbols()->project();
        $this->services->references()->project();
    }

    public function tearDown(): void
    {
        TempProject::remove($this->root);
    }

    public function benchDependencyGraph(): void
    {
        $this->services->composer()->graph();
    }

    public function benchExactSymbolLookup(): void
    {
        (new SymbolTool($this->services))->execute('App\\Service');
    }

    public function benchFileImpact(): void
    {
        (new ImpactTool($this->services))->execute('file', 'app/Service.php', 100);
    }

    public function benchProjectSummary(): void
    {
        (new ProjectTool($this->services))->execute();
    }

    public function benchSearch(): void
    {
        (new SearchTool($this->services))->execute('Service', 'project', 'symbol', null, 20);
    }

    public function benchServerConstruction(): void
    {
        (new ServerFactory($this->project, gitEnabled: false))->create();
    }

    public function benchUsages(): void
    {
        (new UsagesTool($this->services))->execute('App\\Service', null, null, 100);
    }
}
