<?php

namespace Hahadu\ApiDoc\Tools;

class DocumentationConfig
{
    private array $data;

    public function __construct(array $config = [])
    {
        $this->data = $config;
    }

    public function get($key, $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}
