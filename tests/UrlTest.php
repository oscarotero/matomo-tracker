<?php

namespace MatomoTracker\Tests;

use MatomoTracker\Url;
use Middlewares\Utils\Factory;
use PHPUnit\Framework\TestCase;

class UrlTest extends TestCase
{
    public function testUrl()
    {
        $request = Factory::createServerRequest('GET', 'https://example.com');
        $url = Url::createFromServerRequest($request, 'https://tracker.com/piwik.php', 1)
            ->pageId('123456');

        $this->assertSame(
            'https://tracker.com/piwik.php?idsite=1&rec=1&apiv=1&pv_id=123456&url=https%3A%2F%2Fexample.com',
            (string) $url
        );
    }
}
