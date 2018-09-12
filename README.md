# Matomo tracker

[![Build Status](https://travis-ci.com/oscarotero/matomo-tracker.svg?branch=master)](https://travis-ci.com/oscarotero/matomo-tracker)

Simple library to generate [Matomo](https://matomo.org/) tracker urls that you can use to insert tracking images in your site.
It's compatible with [PSR-7 `ServerRequestInterfaces`](https://www.php-fig.org/psr/psr-7/#321-psrhttpmessageserverrequestinterface)

## Requirements

* PHP >= 7.0

## Installation

This package is installable and autoloadable via Composer as [oscarotero/matomo-tracker](https://packagist.org/packages/oscarotero/matomo-tracker).

```sh
composer require oscarotero/matomo-tracker
```

## Example

```php
use MatomoTracker\Url;

$url = Url::createFromServerRequest($serverRequest, 'https://analytics.example.com/piwik.php', 1);

$url->title('Page title')
    ->userId($user->getId());

echo sprintf('<img src="%s">', (string) $url);
```


Please see [CHANGELOG](CHANGELOG.md) for more information about recent changes.

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
