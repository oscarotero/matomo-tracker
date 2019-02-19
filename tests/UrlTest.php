<?php

namespace MatomoTracker\Tests;

use InvalidArgumentException;
use MatomoTracker\Url;
use Middlewares\Utils\Factory;
use PHPUnit\Framework\TestCase;

class UrlTest extends TestCase
{
    public function testUrlWithCreatingFromServerRequest()
    {
        $request = Factory::createServerRequest('GET', 'https://example.com');
        $url = Url::createFromServerRequest($request, 'https://tracker.com/piwik.php', 1)
            ->pageId('123456')
            ->title('action_name')
            ->rand('1550572778')
            ->event('Videos', 'Play', 'object_name', 'value')
            ->content('Ad Foo Bar', 'Unknown', 'https://example.com/landing_page', 'click')
            ->siteSearch('keyword', 'Videos', 0)
            ->goal(1234567890)
            ->download('https://example.com/download')
            ->ip('127.0.0.1')
            ->userId('this_is_user_id')
            ->tokenAuth('auth_token');

        $expectedString = <<<EOD
https://tracker.com/piwik.php?
idsite=1&
rec=1&
apiv=1&
pv_id=123456&
url=https%3A%2F%2Fexample.com&
action_name=action_name&
rand=1550572778&
e_c=Videos&
e_a=Play&
e_n=object_name&
e_v=value&
c_i=click&
c_n=Ad+Foo+Bar&
c_p=Unknown&
c_t=https%3A%2F%2Fexample.com%2Flanding_page&
search=keyword&
search_cat=Videos&
search_count=0&
idgoal=1234567890&
revenue=0&
download=https%3A%2F%2Fexample.com%2Fdownload&
cip=127.0.0.1&
uid=this_is_user_id&
token_auth=auth_token
EOD;
        $expectedString = str_replace("\n", '', $expectedString);

        $this->assertSame(
            $expectedString,
            (string) $url
        );
    }

    public function testUrlWithCreatingFromServerArray()
    {
        $server = [
            'HTTPS' => 'on',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
        ];
        $url = Url::createFromServerArray($server, 'https://tracker.com/piwik.php', 1)
            ->pageId('123456')
            ->title('action_name')
            ->rand('1550572778')
            ->event('Videos', 'Play', 'object_name', 'value')
            ->content('Ad Foo Bar', 'Unknown', 'https://example.com/landing_page', 'click')
            ->siteSearch('keyword', 'Videos', 0)
            ->goal(1234567890)
            ->download('https://example.com/download')
            ->ip('127.0.0.1')
            ->userId('this_is_user_id')
            ->tokenAuth('auth_token');

        $expectedString = <<<EOD
https://tracker.com/piwik.php?
idsite=1&
rec=1&
apiv=1&
pv_id=123456&
url=https%3A%2F%2Fexample.com%2F&
action_name=action_name&
rand=1550572778&
e_c=Videos&
e_a=Play&
e_n=object_name&
e_v=value&
c_i=click&
c_n=Ad+Foo+Bar&
c_p=Unknown&
c_t=https%3A%2F%2Fexample.com%2Flanding_page&
search=keyword&search_cat=Videos&
search_count=0&
idgoal=1234567890&
revenue=0&
download=https%3A%2F%2Fexample.com%2Fdownload&
cip=127.0.0.1&
uid=this_is_user_id&
token_auth=auth_token
EOD;
        $expectedString = str_replace("\n", '', $expectedString);

        $this->assertSame(
            $expectedString,
            (string) $url
        );
    }

    public function testUrlEventWithEmptyCategory()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You must specify an Event Category name (Music, Videos, Games...).');

        $server = [
            'HTTPS' => 'on',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
        ];
        $url = Url::createFromServerArray($server, 'https://tracker.com/piwik.php', 1)
            ->event('', 'action_name');
    }

    public function testUrlEventWithEmptyAction()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You must specify an Event action (click, view, add...).');

        $server = [
            'HTTPS' => 'on',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
        ];
        $url = Url::createFromServerArray($server, 'https://tracker.com/piwik.php', 1)
            ->event('Videos', '');
    }

    public function testUrlContentWithEmptyName()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You must specify a content name');

        $server = [
            'HTTPS' => 'on',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
        ];
        $url = Url::createFromServerArray($server, 'https://tracker.com/piwik.php', 1)
            ->content('');
    }

    public function testUrlIpWithEmptyIpAddress()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IP cannot be empty.');

        $server = [
            'HTTPS' => 'on',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
        ];
        $url = Url::createFromServerArray($server, 'https://tracker.com/piwik.php', 1)
            ->ip('');
    }

    public function testUrlUserIdWithEmptyUserId()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User ID cannot be empty.');

        $server = [
            'HTTPS' => 'on',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
        ];
        $url = Url::createFromServerArray($server, 'https://tracker.com/piwik.php', 1)
            ->userId('');
    }

    public function testUrlPageIdWithEmptyPageId()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page ID cannot be empty.');

        $server = [
            'HTTPS' => 'on',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
        ];
        $url = Url::createFromServerArray($server, 'https://tracker.com/piwik.php', 1)
            ->pageId('');
    }

    public function testUrlTokenAuthWithEmptyPageId()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid authorization key.');

        $server = [
            'HTTPS' => 'on',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
        ];
        $url = Url::createFromServerArray($server, 'https://tracker.com/piwik.php', 1)
            ->tokenAuth('00000000000000000000000000000000');
    }
}
