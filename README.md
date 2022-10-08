## Laravel API Documentation Generator

Automatically generate your API documentation from your existing Laravel/Lumen/[Dingo](https://github.com/dingo/api) routes.


[![Latest Stable Version](https://poser.pugx.org/mpociot/laravel-apidoc-generator/v/stable)](https://packagist.org/packages/mpociot/laravel-apidoc-generator)[![Total Downloads](https://poser.pugx.org/mpociot/laravel-apidoc-generator/downloads)](https://packagist.org/packages/mpociot/laravel-apidoc-generator)
[![License](https://poser.pugx.org/mpociot/laravel-apidoc-generator/license)](https://packagist.org/packages/mpociot/laravel-apidoc-generator)
[![codecov.io](https://codecov.io/github/mpociot/laravel-apidoc-generator/coverage.svg?branch=master)](https://codecov.io/github/mpociot/laravel-apidoc-generator?branch=master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/mpociot/laravel-apidoc-generator/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/mpociot/laravel-apidoc-generator/?branch=master)
[![Build Status](https://travis-ci.org/mpociot/laravel-apidoc-generator.svg?branch=master)](https://travis-ci.org/mpociot/laravel-apidoc-generator)
[![StyleCI](https://styleci.io/repos/57999295/shield?style=flat)](https://styleci.io/repos/57999295)

## Installation
PHP 7.2 and Laravel/Lumen 8.1 or higher are required.

> If your application does not meet these requirements, you can check out the 3.x branch for older releases.

```sh
composer require hahadu/laravel-apidoc-generator
```
and
```sh
composer dump or composer update 
```

### Laravel
Publish the config file by running:

```bash
php artisan vendor:publish --provider="Hahadu\ApiDoc\ApiDocGeneratorServiceProvider" --tag=apidoc-config
```

This will create an `apidoc.php` file in your `config` folder.

### Lumen
- When using Lumen, you will need to run `composer require mpociot/laravel-apidoc-generator` instead.
- Register the service provider in your `bootstrap/app.php`:

```php
$app->register(\Hahadu\ApiDoc\ApiDocGeneratorServiceProvider::class);
```

- Copy the config file from `vendor/mpociot/laravel-apidoc-generator/config/apidoc.php` to your project as `config/apidoc.php`. Then add to your `bootstrap/app.php`:

```php
$app->configure('apidoc');
```

## Documentation
```shell

php artisan apidoc:generate
```

Check out the documentation at the [Beyond Code homepage](https://beyondco.de/docs/laravel-apidoc-generator/).

### License

The Laravel API Documentation Generator is free software licensed under the MIT license.
