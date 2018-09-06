<?php
declare(strict_types = 1);

namespace Middlewares\MatomoTracker;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

class Tracker
{
    const API_VERSION = 1;

    private $apiUrl;
    private $idSite;
    private $idPageview;
    private $userId;
    private $requests = [];
    private $tokenAuth;
    private $ip;
    private $url;
    private $urlRef;
    private $acceptLanguage;
    private $userAgent;

    public function __construct(ServerRequestInterface $serverRequest, string $apiUrl, int $idSite)
    {
        $this->apiUrl = $apiUrl;
        $this->idSite = $idSite;

        $this->idPageview = self::generateRandomId(6);

        $server = $serverRequest->getServerParams();

        $this->ip = $server['REMOTE_ADDR'] ?? null;
        $this->url = (string) $serverRequest->getUri();
        $this->urlRef = $serverRequest->getHeaderLine('Referer') ?: null;
        $this->acceptLanguage = $serverRequest->getHeaderLine('Accept-Language');
        $this->userAgent = $serverRequest->getHeaderLine('User-Agent');
    }

    /**
     * Push a new request to the tracker
     */
    public function push(array $params = []): self
    {
        $params += [
            'idsite' => $this->idSite,
            'rec' => 1,
            'apiv' => self::API_VERSION,
            'send_image' => 0,

            'pv_id' => $this->idPageview,
            'url' => $this->url,
            'urlref' => $this->urlRef,

            'cip' => $this->ip,
            'uid' => $this->userId,
        ];

        $this->requests[] = self::buildQuery($params);

        return $this;
    }

    public function createRequest(): HttpClient
    {
        $client = new HttpClient('POST', $this->apiUrl);

        $client->setUserAgent($this->userAgent)
            ->addHeader("Accept-Language: {$this->acceptLanguage}")
            ->addHeader('Content-Type: application/json')
            ->setData([
                'requests' => $this->requests,
                'token_auth' => $this->tokenAuth,
            ]);

        return $client;
    }

    /**
     * Tracks a page view
     *
     * @param string $documentTitle Page title as it will appear in the Actions > Page titles report
     */
    public function trackPageView(string $documentTitle): self
    {
        return $this->push(['action_name' => $documentTitle]);
    }

    /**
     * Tracks an event
     *
     * @param string $category The Event Category (Videos, Music, Games...)
     * @param string $action   The Event's Action (Play, Pause, Duration, Add Playlist, Downloaded, Clicked...)
     * @param string $name     The Event's object Name (a particular Movie name, or Song name, or File name...)
     * @param float  $value    The Event's value
     */
    public function trackEvent(string $category, string $action, $name = null, $value = null): self
    {
        if (strlen($category) === 0) {
            throw new InvalidArgumentException('You must specify an Event Category name (Music, Videos, Games...).');
        }

        if (strlen($action) === 0) {
            throw new InvalidArgumentException('You must specify an Event action (click, view, add...).');
        }

        return $this->push([
            'e_c' => $category,
            'e_a' => $action,
            'e_n' => $name,
            'e_v' => $value,
        ]);
    }

    /**
     * Tracks a content impression
     *
     * @param string $name        The name of the content. For instance 'Ad Foo Bar'
     * @param string $piece       The actual content. For instance the path to an image, video, audio, any text
     * @param string $target      The target of the content. For instance the URL of a landing page.
     * @param string $interaction The name of the interaction with the content. For instance a 'click'
     */
    public function trackContent(string $name, string $piece = 'Unknown', $target = null, $interaction = null): self
    {
        if (strlen($name) === 0) {
            throw new InvalidArgumentException('You must specify a content name');
        }

        return $this->push([
            'c_i' => $interaction,
            'c_n' => $name,
            'c_p' => $piece,
            'c_t' => $target,
        ]);
    }

    /**
     * Tracks an internal Site Search query, and optionally tracks the Search Category, and Search results Count.
     * These are used to populate reports in Actions > Site Search.
     *
     * @param string $keyword      Searched query on the site
     * @param string $category     Search engine category if applicable
     * @param int    $countResults results displayed on the search result page. Used to track "zero result" keywords.
     */
    public function trackSiteSearch(string $keyword, string $category = null, $countResults = null): self
    {
        return $this->push([
            'search' => $keyword,
            'search_cat' => $category,
            'search_count' => $countResults,
        ]);
    }

    /**
     * Records a Goal conversion
     *
     * @param int   $idGoal  Id Goal to record a conversion
     * @param float $revenue Revenue for this conversion
     */
    public function trackGoal(int $idGoal, float $revenue = 0.0): self
    {
        return $this->push([
            'idgoal' => $idGoal,
            'revenue' => $revenue,
        ]);
    }

    /**
     * Tracks a download
     */
    public function trackDownload(string $url): self
    {
        return $this->push(['download' => $url]);
    }

    /**
     * Overrides IP address
     * Allowed only for Admin/Super User, must be used along with setTokenAuth()
     */
    public function setIp(string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }

    /**
     * Force the action to be recorded for a specific User. The User ID is a string representing a given user in your system.
     *
     * A User ID can be a username, UUID or an email address, or any number or string that uniquely identifies a user or client.
     */
    public function setUserId(string $userId): self
    {
        if ($userId === '') {
            throw new InvalidArgumentException('User ID cannot be empty.');
        }

        $this->userId = $userId;

        return $this;
    }

    /**
     * Some Tracking API functionality requires express authentication, using either the
     * Super User token_auth, or a user with 'admin' access to the website.
     *
     * The following features require access:
     * - force the visitor IP
     * - force the date & time of the tracking requests rather than track for the current datetime
     *
     * @param string $tokenAuth
     */
    public function setTokenAuth(string $tokenAuth): self
    {
        $this->tokenAuth = $tokenAuth;

        return $this;
    }

    private static function generateRandomId(int $length)
    {
        return substr(md5(uniqid((string) rand(), true)), 0, $length);
    }

    private static function buildQuery(array $params): string
    {
        $params = array_filter($params, function ($value) {
            return !is_null($value);
        });

        if (empty($params)) {
            return '';
        }

        return '?'.http_build_query($params);
    }
}
