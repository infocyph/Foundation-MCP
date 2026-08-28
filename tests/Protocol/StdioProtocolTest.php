<?php

declare(strict_types=1);

use Infocyph\FoundationMcp\Tests\Support\TempProject;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Exception\RequestException;
use Mcp\Schema\Enum\ProtocolVersion;

it('serves the complete explicit MCP surface over real STDIO in both protocol eras', function (ProtocolVersion $version): void {
    $root = TempProject::create(
        composer: [
            'name' => 'fixture/foundation-host',
            'require' => ['php' => '^8.4', 'infocyph/foundation' => '^2.1'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ],
        directories: ['app', 'bootstrap', 'config', 'routes', 'tests'],
        files: [
            'app/Demo.php' => "<?php\nnamespace App;\nfinal class Demo { public function run(): string { return 'ok'; } }\n",
            'README.md' => "# Fixture\n",
        ],
    );

    $client = null;

    try {
        $client = protocolClient($version);
        $client->connect(protocolTransport($root));

        $toolNames = array_map(static fn($tool): string => $tool->name, $client->listTools()->tools);
        expect($toolNames)->toBe([
            'foundation_project',
            'foundation_search',
            'foundation_read',
            'foundation_symbol',
            'foundation_usages',
            'foundation_inspect',
            'foundation_packages',
            'foundation_changes',
            'foundation_impact',
        ]);

        $resourceUris = array_map(static fn($resource): string => $resource->uri, $client->listResources()->resources);
        expect($resourceUris)->toBe([
            'foundation://project/summary',
            'foundation://project/architecture',
            'foundation://project/composer',
            'foundation://project/module-catalog',
            'foundation://project/standards',
        ]);

        $templates = array_map(static fn($template): string => $template->uriTemplate, $client->listResourceTemplates()->resourceTemplates);
        expect($templates)->toBe([
            'foundation://project/file/{path}',
            'foundation://package/{package}/file/{path}',
            'foundation://symbol/{symbol}',
        ]);

        $search = $client->callTool('foundation_search', [
            'query' => 'Demo',
            'scope' => 'project',
            'kind' => 'symbol',
            'limit' => 10,
        ]);
        expect($search->isError)->toBeFalse()
            ->and($search->structuredContent)->toBeArray();

        $read = $client->readResource('foundation://project/file/README.md');
        expect($read->contents)->toHaveCount(1)
            ->and($read->contents[0]->text)->toContain('# Fixture');

        expect(fn() => $client->callTool('foundation_missing', []))->toThrow(RequestException::class);
        expect(array_map(static fn($tool): string => $tool->name, $client->listTools()->tools))->toBe($toolNames);
    } finally {
        $client?->disconnect();
        TempProject::remove($root);
    }
})->with([
    'handshake' => ProtocolVersion::V2025_11_25,
    'modern' => ProtocolVersion::V2026_07_28,
]);

function protocolClient(ProtocolVersion $version): Client
{
    return Client::builder()
        ->setClientInfo('foundation-mcp-protocol-test', '1.0.0')
        ->setProtocolVersion($version)
        ->setInitTimeout(10)
        ->setRequestTimeout(30)
        ->setMaxRetries(0)
        ->build();
}

function protocolTransport(string $root): StdioTransport
{
    return new StdioTransport(
        command: PHP_BINARY,
        args: [dirname(__DIR__, 2) . '/bin/foundation-mcp', 'serve', '--root=' . $root, '--no-git'],
        cwd: dirname(__DIR__, 2),
    );
}
