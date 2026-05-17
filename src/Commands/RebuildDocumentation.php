<?php

namespace Hahadu\ApiDoc\Commands;

use Illuminate\Console\Command;
use Hahadu\ApiDoc\Tools\DocumentationConfig;
use Hahadu\ApiDoc\Writing\Writer;

class RebuildDocumentation extends Command
{
    protected $signature = 'apidoc:rebuild';

    protected $description = 'Rebuild your API documentation from your markdown file.';

    public function handle(): int
    {
        $sourceOutputPath = 'resources/docs/source';
        if (! is_dir($sourceOutputPath)) {
            $this->error('There is no existing documentation available at ' . $sourceOutputPath . '.');

            return 1;
        }

        $this->info('Rebuilding API documentation from ' . $sourceOutputPath . '/index.md');

        $writer = new Writer($this, new DocumentationConfig(config('apidoc')));
        $writer->writeHtmlDocs();

        return 0;
    }
}
