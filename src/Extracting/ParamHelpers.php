<?php

namespace Hahadu\ApiDoc\Extracting;

use Faker\Factory;
use stdClass;

trait ParamHelpers
{
    protected function generateDummyValue(string $type): mixed
    {
        $faker = Factory::create();
        if ($this->config->get('faker_seed')) {
            $faker->seed($this->config->get('faker_seed'));
        }
        $fakeFactories = [
            'integer' => fn () => $faker->numberBetween(1, 20),
            'number' => fn () => $faker->randomFloat(),
            'float' => fn () => $faker->randomFloat(),
            'boolean' => fn () => $faker->boolean(),
            'string' => fn () => $faker->word,
            'array' => fn () => [],
            'object' => fn () => new stdClass(),
        ];

        $fakeFactory = $fakeFactories[$type] ?? $fakeFactories['string'];

        return $fakeFactory();
    }

    protected function castToType(string $value, string $type): mixed
    {
        $casts = [
            'integer' => 'intval',
            'int' => 'intval',
            'float' => 'floatval',
            'number' => 'floatval',
            'double' => 'floatval',
            'boolean' => 'boolval',
            'bool' => 'boolval',
        ];

        if ($value == 'false' && ($type == 'boolean' || $type == 'bool')) {
            return false;
        }

        if (isset($casts[$type])) {
            return $casts[$type]($value);
        }

        return $value;
    }

    protected function normalizeParameterType(string $type): string
    {
        $typeMap = [
            'int' => 'integer',
            'bool' => 'boolean',
            'double' => 'float',
        ];

        return $type ? ($typeMap[$type] ?? $type) : 'string';
    }

    protected function shouldExcludeExample(string $description): bool
    {
        return str_contains($description, ' No-example');
    }

    protected function parseParamDescription(string $description, string $type): array
    {
        $example = null;
        if (preg_match('/(.*)\bExample:\s*(.+)\s*/', $description, $content)) {
            $description = trim($content[1]);

            // examples are parsed as strings by default, we need to cast them properly
            $example = $this->castToType($content[2], $type);
        }

        return [$description, $example];
    }
}
