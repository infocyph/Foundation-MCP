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
use Infocyph\FoundationMcp\Resource\ResourceReader;

/** Shared lazy domain services for the explicit MCP tool surface. */
final class ToolServices
{
    private ?ComposerInspector $composer = null;
    private ?PhpAnalyzer $analyzer = null;
    private ?SourceFileFinder $files = null;
    private ?SymbolIndex $symbols = null;
    private ?ReferenceIndex $references = null;
    private ?TestLocator $tests = null;
    private ?SearchEngine $search = null;
    private ?ResourceReader $reader = null;
    private ?ModuleCatalogReader $modules = null;
    private ?RouteInspector $routes = null;
    private ?CommandInspector $commands = null;
    private ?ProviderInspector $providers = null;
    private ?ConfigInspector $config = null;
    private ?WorkerInspector $workers = null;
    private ?ScheduleInspector $schedules = null;
    private ?RuntimeInspector $runtime = null;
    private ?GitRunner $git = null;
    private ?WorkspaceInspector $workspace = null;
    private ?DependencyChangeAnalyzer $dependencies = null;
    private ?ImpactAnalyzer $impact = null;

    public function __construct(
        public readonly Project $project,
    ) {
    }

    public function composer(): ComposerInspector
    {
        return $this->composer ??= new ComposerInspector($this->project);
    }

    public function analyzer(): PhpAnalyzer
    {
        return $this->analyzer ??= new PhpAnalyzer($this->project, $this->composer());
    }

    public function files(): SourceFileFinder
    {
        return $this->files ??= new SourceFileFinder($this->project, $this->composer());
    }

    public function symbols(): SymbolIndex
    {
        return $this->symbols ??= new SymbolIndex(
            $this->project,
            $this->composer(),
            $this->analyzer(),
            $this->files(),
        );
    }

    public function references(): ReferenceIndex
    {
        return $this->references ??= new ReferenceIndex(
            $this->project,
            $this->composer(),
            $this->analyzer(),
            $this->files(),
            $this->symbols(),
        );
    }

    public function tests(): TestLocator
    {
        return $this->tests ??= new TestLocator(
            $this->project,
            $this->composer(),
            $this->symbols(),
            $this->references(),
            $this->files(),
        );
    }

    public function search(): SearchEngine
    {
        return $this->search ??= new SearchEngine(
            $this->project,
            $this->composer(),
            $this->symbols(),
        );
    }

    public function reader(): ResourceReader
    {
        return $this->reader ??= new ResourceReader($this->project, $this->composer());
    }

    public function modules(): ModuleCatalogReader
    {
        return $this->modules ??= new ModuleCatalogReader($this->project, $this->composer());
    }

    public function routes(): RouteInspector
    {
        return $this->routes ??= new RouteInspector(
            $this->project,
            $this->composer(),
            finder: $this->files(),
        );
    }

    public function commands(): CommandInspector
    {
        return $this->commands ??= new CommandInspector(
            $this->project,
            $this->composer(),
            symbols: $this->symbols(),
        );
    }

    public function providers(): ProviderInspector
    {
        return $this->providers ??= new ProviderInspector(
            $this->project,
            $this->composer(),
            symbols: $this->symbols(),
        );
    }

    public function config(): ConfigInspector
    {
        return $this->config ??= new ConfigInspector(
            $this->project,
            $this->composer(),
            symbols: $this->symbols(),
        );
    }

    public function workers(): WorkerInspector
    {
        return $this->workers ??= new WorkerInspector(
            new FoundationWorkerInspector($this->project, $this->composer()),
            new OmnibusWorkerInspector($this->project, $this->composer()),
        );
    }

    public function schedules(): ScheduleInspector
    {
        return $this->schedules ??= new ScheduleInspector($this->project, $this->composer());
    }

    public function runtime(): RuntimeInspector
    {
        return $this->runtime ??= new RuntimeInspector($this->project, $this->composer());
    }

    public function git(): GitRunner
    {
        return $this->git ??= new GitRunner($this->project);
    }

    public function workspace(): WorkspaceInspector
    {
        return $this->workspace ??= new WorkspaceInspector(
            $this->project,
            $this->composer(),
            $this->git(),
            $this->analyzer(),
            $this->tests(),
        );
    }

    public function dependencies(): DependencyChangeAnalyzer
    {
        return $this->dependencies ??= new DependencyChangeAnalyzer(
            $this->project,
            $this->composer(),
            $this->git(),
            $this->references(),
        );
    }

    public function impact(): ImpactAnalyzer
    {
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
}
