<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Mcp\Tool;

use Infocyph\FoundationMcp\Analysis\ImpactAnalyzer;
use Infocyph\FoundationMcp\Analysis\PhpAnalyzer;
use Infocyph\FoundationMcp\Analysis\ReferenceIndex;
use Infocyph\FoundationMcp\Analysis\SearchEngine;
use Infocyph\FoundationMcp\Analysis\SourceFileFinder;
use Infocyph\FoundationMcp\Analysis\SymbolIndex;
use Infocyph\FoundationMcp\Analysis\TestLocator;
use Infocyph\FoundationMcp\Composer\ComposerInspector;
use Infocyph\FoundationMcp\Composer\DependencyChangeAnalyzer;
use Infocyph\FoundationMcp\Foundation\ArchitectureInspector;
use Infocyph\FoundationMcp\Foundation\CommandInspector;
use Infocyph\FoundationMcp\Foundation\ConfigInspector;
use Infocyph\FoundationMcp\Foundation\FoundationWorkerInspector;
use Infocyph\FoundationMcp\Foundation\ModuleCatalogReader;
use Infocyph\FoundationMcp\Foundation\OmnibusWorkerInspector;
use Infocyph\FoundationMcp\Foundation\ProviderInspector;
use Infocyph\FoundationMcp\Foundation\RouteInspector;
use Infocyph\FoundationMcp\Foundation\RuntimeInspector;
use Infocyph\FoundationMcp\Foundation\ScheduleInspector;
use Infocyph\FoundationMcp\Foundation\WorkerInspector;
use Infocyph\FoundationMcp\Git\GitRunner;
use Infocyph\FoundationMcp\Git\WorkspaceInspector;
use Infocyph\FoundationMcp\Project\Project;
use Infocyph\FoundationMcp\Project\ProjectDetector;
use Infocyph\FoundationMcp\Resource\ResourceReader;

/** Shared lazy domain services for the explicit MCP surface. */
final class ToolServices
{
    private ?PhpAnalyzer $analyzer = null;

    private ?ArchitectureInspector $architecture = null;

    private ?CommandInspector $commands = null;

    private ?ComposerInspector $composer = null;

    private ?ConfigInspector $config = null;

    private ?DependencyChangeAnalyzer $dependencies = null;

    private ?SourceFileFinder $files = null;

    private ?GitRunner $git = null;

    private ?ImpactAnalyzer $impact = null;

    private string $metadataState;

    private ?ModuleCatalogReader $modules = null;

    private ?ProviderInspector $providers = null;

    private ?ResourceReader $reader = null;

    private ?ReferenceIndex $references = null;

    private ?RouteInspector $routes = null;

    private ?RuntimeInspector $runtime = null;

    private ?ScheduleInspector $schedules = null;

    private ?SearchEngine $search = null;

    private ?SymbolIndex $symbols = null;

    private ?TestLocator $tests = null;

    private ?WorkerInspector $workers = null;

    private ?WorkspaceInspector $workspace = null;

    public function __construct(
        public Project $project,
        private readonly bool $gitEnabled = true,
    ) {
        $this->metadataState = $this->currentMetadataState();
    }

    public function analyzer(): PhpAnalyzer
    {
        $this->refreshMetadata();

        return $this->analyzer ??= new PhpAnalyzer($this->project, $this->composer());
    }

    public function architecture(): ArchitectureInspector
    {
        $this->refreshMetadata();

        return $this->architecture ??= new ArchitectureInspector(
            $this->project,
            $this->composer(),
            $this->modules(),
            $this->providers(),
            $this->runtime(),
        );
    }

    public function commands(): CommandInspector
    {
        $this->refreshMetadata();

        return $this->commands ??= new CommandInspector(
            $this->project,
            $this->composer(),
            symbols: $this->symbols(),
        );
    }

    public function composer(): ComposerInspector
    {
        $this->refreshMetadata();

        return $this->composer ??= new ComposerInspector($this->project);
    }

    public function config(): ConfigInspector
    {
        $this->refreshMetadata();

        return $this->config ??= new ConfigInspector(
            $this->project,
            $this->composer(),
            symbols: $this->symbols(),
        );
    }

    public function dependencies(): DependencyChangeAnalyzer
    {
        $this->refreshMetadata();

        return $this->dependencies ??= new DependencyChangeAnalyzer(
            $this->project,
            $this->composer(),
            $this->git(),
            $this->references(),
        );
    }

    public function files(): SourceFileFinder
    {
        $this->refreshMetadata();

        return $this->files ??= new SourceFileFinder($this->project, $this->composer());
    }

    public function git(): GitRunner
    {
        $this->refreshMetadata();

        return $this->git ??= new GitRunner($this->project, enabled: $this->gitEnabled);
    }

    public function impact(): ImpactAnalyzer
    {
        $this->refreshMetadata();

        return $this->impact ??= new ImpactAnalyzer(
            $this->project,
            $this->composer(),
            $this->symbols(),
            $this->references(),
            $this->tests(),
            $this->workspace(),
            $this->dependencies(),
        );
    }

    public function modules(): ModuleCatalogReader
    {
        $this->refreshMetadata();

        return $this->modules ??= new ModuleCatalogReader($this->project, $this->composer());
    }

    public function providers(): ProviderInspector
    {
        $this->refreshMetadata();

        return $this->providers ??= new ProviderInspector(
            $this->project,
            $this->composer(),
            symbols: $this->symbols(),
        );
    }

    public function reader(): ResourceReader
    {
        $this->refreshMetadata();

        return $this->reader ??= new ResourceReader($this->project, $this->composer());
    }

    public function references(): ReferenceIndex
    {
        $this->refreshMetadata();

        return $this->references ??= new ReferenceIndex(
            $this->project,
            $this->composer(),
            $this->analyzer(),
            $this->files(),
            $this->symbols(),
        );
    }

    public function routes(): RouteInspector
    {
        $this->refreshMetadata();

        return $this->routes ??= new RouteInspector(
            $this->project,
            $this->composer(),
            finder: $this->files(),
        );
    }

    public function runtime(): RuntimeInspector
    {
        $this->refreshMetadata();

        return $this->runtime ??= new RuntimeInspector($this->project, $this->composer());
    }

    public function schedules(): ScheduleInspector
    {
        $this->refreshMetadata();

        return $this->schedules ??= new ScheduleInspector($this->project, $this->composer());
    }

    public function search(): SearchEngine
    {
        $this->refreshMetadata();

        return $this->search ??= new SearchEngine(
            $this->project,
            $this->composer(),
            $this->symbols(),
        );
    }

    public function symbols(): SymbolIndex
    {
        $this->refreshMetadata();

        return $this->symbols ??= new SymbolIndex(
            $this->project,
            $this->composer(),
            $this->analyzer(),
            $this->files(),
        );
    }

    public function tests(): TestLocator
    {
        $this->refreshMetadata();

        return $this->tests ??= new TestLocator(
            $this->project,
            $this->composer(),
            $this->symbols(),
            $this->references(),
            $this->files(),
        );
    }

    public function workers(): WorkerInspector
    {
        $this->refreshMetadata();

        return $this->workers ??= new WorkerInspector(
            new FoundationWorkerInspector($this->project, $this->composer()),
            new OmnibusWorkerInspector($this->project, $this->composer()),
        );
    }

    public function workspace(): WorkspaceInspector
    {
        $this->refreshMetadata();

        return $this->workspace ??= new WorkspaceInspector(
            $this->project,
            $this->composer(),
            $this->git(),
            $this->analyzer(),
            $this->tests(),
        );
    }

    private function currentMetadataState(): string
    {
        return implode('|', array_map(
            $this->fileState(...),
            [
                $this->project->root . DIRECTORY_SEPARATOR . 'composer.json',
                $this->project->root . DIRECTORY_SEPARATOR . 'composer.lock',
                $this->project->root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json',
            ],
        ));
    }

    private function fileState(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!is_array($stat)) {
            return 'missing';
        }

        $size = is_int($stat['size'] ?? null) ? $stat['size'] : 0;
        $mtime = is_int($stat['mtime'] ?? null) ? $stat['mtime'] : 0;
        $ctime = is_int($stat['ctime'] ?? null) ? $stat['ctime'] : 0;

        return $path . ':' . $size . ':' . $mtime . ':' . $ctime;
    }

    private function refreshMetadata(): void
    {
        $state = $this->currentMetadataState();
        if ($state === $this->metadataState) {
            return;
        }

        $this->project = new ProjectDetector()->detect($this->project->root);
        $this->resetServices();
        $this->metadataState = $this->currentMetadataState();
    }

    private function resetServices(): void
    {
        $this->composer = null;
        $this->analyzer = null;
        $this->files = null;
        $this->symbols = null;
        $this->references = null;
        $this->tests = null;
        $this->search = null;
        $this->reader = null;
        $this->modules = null;
        $this->routes = null;
        $this->commands = null;
        $this->providers = null;
        $this->config = null;
        $this->workers = null;
        $this->schedules = null;
        $this->runtime = null;
        $this->architecture = null;
        $this->git = null;
        $this->workspace = null;
        $this->dependencies = null;
        $this->impact = null;
    }
}
