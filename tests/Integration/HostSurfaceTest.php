<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Mcp\ServerFactory;
use Infocyph\FoundationMcp\Mcp\Tool\ReadTool;
use Infocyph\FoundationMcp\Mcp\Tool\SearchTool;
use Infocyph\FoundationMcp\Mcp\Tool\SymbolTool;
use Infocyph\FoundationMcp\Mcp\Tool\ToolServices;
use Infocyph\FoundationMcp\Mcp\Tool\UsagesTool;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Tests\Support\TempProject;

it('integrates detection shared services source analysis reads and explicit server construction', function (): void {
    $root = TempProject::create(
        composer: [
            'name' => 'fixture/custom-foundation-host',
            'require' => ['php' => '^8.4', 'infocyph/foundation' => '^2.1'],
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'spec/']],
        ],
        directories: ['src', 'spec', 'bootstrap', 'config', 'routes'],
        files: [
            'src/Greeting.php' => "<?php\nnamespace App;\nfinal class Greeting { public function hello(): string { return 'hello'; } }\n",
            'spec/GreetingSpec.php' => "<?php\nnamespace Tests;\nuse App\\Greeting;\nfinal class GreetingSpec { public function testGreeting(): void { (new Greeting())->hello(); } }\n",
            'README.md' => "# Integration fixture\n",
        ],
    );

    try {
        $project = (new ProjectDetector())->detect($root);
        $services = new ToolServices($project, gitEnabled: false);

        expect($project->supported())->toBeTrue();

        $search = (new SearchTool($services))->execute('Greeting', 'project', 'symbol', null, 20);
        expect($search['results'])->not->toBeEmpty();

        $symbol = (new SymbolTool($services))->execute('App\\Greeting');
        expect($symbol['status'])->toBe('resolved')
            ->and($symbol['declaration']['symbol'])->toBe('App\\Greeting');

        $usages = (new UsagesTool($services))->execute('App\\Greeting', null, null, 20);
        expect($usages['usages'])->not->toBeEmpty();

        $read = (new ReadTool($services))->execute('README.md', 'project', null, 1, 20);
        expect($read['content'])->toContain('Integration fixture');

        expect((new ServerFactory($project, gitEnabled: false))->create())->not->toBeNull();
    } finally {
        TempProject::remove($root);
    }
});
