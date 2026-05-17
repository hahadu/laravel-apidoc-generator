<?php

namespace Hahadu\ApiDoc\Writing;

use Hahadu\YuQue\Client as YuQue;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Hahadu\ApiDoc\Tools\DocumentationConfig;
use Hahadu\Documentarian\Documentarian;
use Illuminate\Support\Facades\Redis;

class Writer
{
    final public const API_DOC_MARKDOWN_KEY = "api-doc-markdown";
    final public const API_DOC_COMPARE_MARKDOWN_KEY = 'api-doc-compare-markdown';

    protected Command $output;

    private DocumentationConfig $config;

    private \Redis $redis;

    private string $baseUrl;

    private bool $forceIt;

    private bool $shouldGeneratePostmanCollection = true;

    private Documentarian $documentarian;

    private bool $isStatic;

    private string $sourceOutputPath;

    private string $outputPath;

    public function __construct(Command $output, ?DocumentationConfig $config = null, bool $forceIt = false)
    {
        $this->config = $config ?: new DocumentationConfig(config('apidoc'));
        $this->baseUrl = $this->config->get('base_url') ?? config('app.url');
        $this->forceIt = $forceIt;
        $this->output = $output;
        $this->shouldGeneratePostmanCollection = $this->config->get('postman.enabled', false);
        $this->documentarian = new Documentarian();
        $this->isStatic = $this->config->get('type') === 'static';
        $this->sourceOutputPath = 'resources/docs';
        $this->outputPath = $this->isStatic ? ($this->config->get('output_folder') ?? 'public/docs') : 'resources/views/apidoc';
        $this->redis = Redis::connection()->client();
    }

    public function writeDocs(Collection $routes): void
    {
        $this->writeMarkdownAndSourceFiles($routes);

        $this->writeHtmlDocs();

        $this->writePostmanCollection($routes);
    }

    public function writeMarkdownAndSourceFiles(Collection $parsedRoutes): void
    {
        $targetFile = $this->sourceOutputPath . '/source/index.md';
        $compareFile = $this->sourceOutputPath . '/source/.compare.md';

        $infoText = view('apidoc::partials.info')
            ->with('outputPath', 'docs')
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection);

        $settings = [
            'languages' => $this->config->get('example_languages'),
            'title' => $this->config->get('DocTitle'),
        ];
        $parsedRouteOutput = $this->generateMarkdownOutputForEachRoute($parsedRoutes, $settings);

        $frontmatter = view('apidoc::partials.frontmatter')
            ->with('settings', $settings);

        if (file_exists($targetFile) && file_exists($compareFile)) {
            $generatedDocumentation = file_get_contents($targetFile);
            $compareDocumentation = file_get_contents($compareFile);

            $parsedRouteOutput->transform(function (Collection $routeGroup) use ($generatedDocumentation, $compareDocumentation) {
                return $routeGroup->transform(function (array $route) use ($generatedDocumentation, $compareDocumentation) {
                    if (preg_match('/<!-- START_' . $route['id'] . ' -->(.*)<!-- END_' . $route['id'] . ' -->/is', $generatedDocumentation, $existingRouteDoc)) {
                        $routeDocumentationChanged = (preg_match('/<!-- START_' . $route['id'] . ' -->(.*)<!-- END_' . $route['id'] . ' -->/is', $compareDocumentation, $lastDocWeGeneratedForThisRoute) && $lastDocWeGeneratedForThisRoute[1] !== $existingRouteDoc[1]);
                        if ($routeDocumentationChanged === false || $this->forceIt) {
                            if ($routeDocumentationChanged) {
                                $this->output->warn('Discarded manual changes for route [' . implode(',', $route['methods']) . '] ' . $route['uri']);
                            }
                        } else {
                            $this->output->warn('Skipping modified route [' . implode(',', $route['methods']) . '] ' . $route['uri']);
                            $route['modified_output'] = $existingRouteDoc[0];
                        }
                    }

                    return $route;
                });
            });
        }

        $prependFileContents = $this->getMarkdownToPrepend();
        $appendFileContents = $this->getMarkdownToAppend();

        $markdown = view('apidoc::documentarian')
            ->with('writeCompareFile', false)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $infoText)
            ->with('prependMd', $prependFileContents)
            ->with('appendMd', $appendFileContents)
            ->with('outputPath', $this->config->get('output'))
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection)
            ->with('parsedRoutes', $parsedRouteOutput);

        $this->output->info('Writing index.md and source files to: ' . $this->sourceOutputPath);

        if (! is_dir($this->sourceOutputPath)) {
            $documentarian = new Documentarian();
            $documentarian->create($this->sourceOutputPath);
        }

        file_put_contents($targetFile, $markdown);
        file_put_contents(base_path('README.md'), $markdown);
        $this->redis->set('shop-admin-' . self::API_DOC_MARKDOWN_KEY, (string) $markdown, 7200);

        $compareMarkdown = view('apidoc::documentarian')
            ->with('writeCompareFile', true)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $infoText)
            ->with('prependMd', $prependFileContents)
            ->with('appendMd', $appendFileContents)
            ->with('outputPath', $this->config->get('output'))
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection)
            ->with('parsedRoutes', $parsedRouteOutput);

        file_put_contents($compareFile, $compareMarkdown);
        $this->redis->set('shop-admin-' . self::API_DOC_COMPARE_MARKDOWN_KEY, (string) $compareMarkdown, 7200);

        $this->output->info('Wrote index.md and source files to: ' . $this->sourceOutputPath);
    }

    public function generateMarkdownOutputForEachRoute(Collection $parsedRoutes, array $settings): Collection
    {
        return $parsedRoutes->map(function (Collection $routeGroup) use ($settings) {
            return $routeGroup->map(function (array $route) use ($settings) {
                if (count($route['cleanBodyParameters']) && ! isset($route['headers']['Content-Type'])) {
                    $route['headers']['Content-Type'] = 'application/json';
                }

                $hasRequestOptions = ! empty($route['headers']) || ! empty($route['cleanQueryParameters']) || ! empty($route['cleanBodyParameters']);
                $route['output'] = (string) view('apidoc::partials.route')
                    ->with('hasRequestOptions', $hasRequestOptions)
                    ->with('route', $route)
                    ->with('settings', $settings)
                    ->with('baseUrl', $this->baseUrl)
                    ->render();

                return $route;
            });
        });
    }

    protected function writePostmanCollection(Collection $parsedRoutes): void
    {
        if ($this->shouldGeneratePostmanCollection) {
            $this->output->info('Generating Postman collection');

            $collection = $this->generatePostmanCollection($parsedRoutes);
            if ($this->isStatic) {
                $collectionPath = "{$this->outputPath}/collection.json";
                file_put_contents($collectionPath, $collection);
            } else {
                $storageInstance = Storage::disk($this->config->get('storage'));
                $storageInstance->put('apidoc/collection.json', $collection, 'public');
                if ($this->config->get('storage') == 'local') {
                    $collectionPath = 'storage/app/apidoc/collection.json';
                } else {
                    $collectionPath = $storageInstance->url('collection.json');
                }
            }

            $this->output->info("Wrote Postman collection to: {$collectionPath}");
        }
    }

    public function generatePostmanCollection(Collection $routes): string
    {
        /** @var PostmanCollectionWriter $writer */
        $writer = app()->makeWith(
            PostmanCollectionWriter::class,
            ['routeGroups' => $routes, 'baseUrl' => $this->baseUrl]
        );

        return $writer->getCollection();
    }

    protected function getMarkdownToPrepend(): string
    {
        $prependFile = $this->sourceOutputPath . '/source/prepend.md';

        return file_exists($prependFile)
            ? file_get_contents($prependFile) . "\n" : '';
    }

    protected function getMarkdownToAppend(): string
    {
        $appendFile = $this->sourceOutputPath . '/source/append.md';

        return file_exists($appendFile)
            ? "\n" . file_get_contents($appendFile) : '';
    }

    protected function copyAssetsFromSourceFolderToPublicFolder(): void
    {
        $publicPath = $this->config->get('output_folder') ?? 'public/docs';
        if (! is_dir($publicPath)) {
            mkdir($publicPath, 0777, true);
            mkdir("{$publicPath}/css");
            mkdir("{$publicPath}/js");
        }
        copy("{$this->sourceOutputPath}/js/all.js", "{$publicPath}/js/all.js");
        rcopy("{$this->sourceOutputPath}/images", "{$publicPath}/images");
        rcopy("{$this->sourceOutputPath}/css", "{$publicPath}/css");

        if ($logo = $this->config->get('logo')) {
            copy($logo, "{$publicPath}/images/logo.png");
        }
    }

    protected function moveOutputFromSourceFolderToTargetFolder(): void
    {
        if ($this->isStatic) {
            rename("{$this->sourceOutputPath}/index.html", "{$this->outputPath}/index.html");
        } else {
            if (! is_dir($this->outputPath)) {
                mkdir($this->outputPath);
            }
            rename("{$this->sourceOutputPath}/index.html", "$this->outputPath/index.blade.php");
            $contents = file_get_contents("$this->outputPath/index.blade.php");
            $contents = str_replace('href="css/style.css"', 'href="{{ asset(\'/docs/css/style.css\') }}"', $contents);
            $contents = str_replace('src="js/all.js"', 'src="{{ asset(\'/docs/js/all.js\') }}"', $contents);
            $contents = str_replace('src="images/', 'src="/docs/images/', $contents);
            $contents = preg_replace('#href="https?://.+?/docs/collection.json"#', 'href="{{ route("apidoc.json") }}"', $contents);
            file_put_contents("$this->outputPath/index.blade.php", $contents);
        }
    }

    public function writeHtmlDocs(): void
    {
        $this->output->info('Generating API HTML code');

        $this->documentarian->generate($this->sourceOutputPath);

        $this->copyAssetsFromSourceFolderToPublicFolder();

        $this->moveOutputFromSourceFolderToTargetFolder();

        $this->output->info("Wrote HTML documentation to: {$this->outputPath}");
    }
}
