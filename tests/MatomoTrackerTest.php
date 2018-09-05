<?php

namespace Middlewares\Tests;

use Middlewares\MatomoTracker;
use Middlewares\Utils\Dispatcher;
use Middlewares\Utils\Factory;
use PHPUnit\Framework\TestCase;

class MatomoTrackerTest extends TestCase
{
    public function testMatomoTracker()
    {
        $request = Factory::createServerRequest('GET', '/');

        $response = Dispatcher::run([
            new MatomoTracker('https://matomo.example.com', 1),
        ], $request);

        $this->assertSame(200, $response->getStatusCode());
    }
}
