<?php
declare(strict_types = 1);

namespace MatomoTracker;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

class Url
{
    const API_VERSION = 1;

    private $url;
    private $params;

    public static function createFromServerRequest(ServerRequestInterface $serverRequest, string $url, int $idSite): Url
    {
        $server = $serverRequest->getServerParams();

        return (new static($url, $idSite))
            ->url((string) $serverRequest->getUri())
            ->referrer($serverRequest->getHeaderLine('Referer') ?: null)
            ->ip($server['REMOTE_ADDR'] ?? null);
    }

    public function __construct(string $url, int $idSite)
    {
        $this->url = $url;

        $this->params = [
            'idsite' => $idSite,
            'rec' => 1,
            'apiv' => self::API_VERSION,
            'pv_id' => self::generateRandomId(6),
        ];
    }

    public function __toString()
    {
        return $this->url.self::buildQuery($this->params);
    }

    public function set(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->params[$key] = $value;
        }

        return $this;
    }

    /**
     * Set the full URL for the current action.
     */
    public function url(string $url = null): self
    {
        return $this->set(['url' => $url]);
    }

    /**
     * Set the full URL for the current action.
     */
    public function referrer(string $url = null): self
    {
        return $this->set(['urlref' => $url]);
    }

    /**
     * Tracks a page view
     *
     * @param string $documentTitle Page title as it will appear in the Actions > Page titles report
     */
    public function title(string $documentTitle): self
    {
        return $this->set(['action_name' => $documentTitle]);
    }

    /**
     * Set a random value to avoid the tracking request being cached by the browser or a proxy.
     * Set null to generate a value automatically
     */
    public function rand(string $rand = null): self
    {
        return $this->set(['rand' => $rand ?: time()]);
    }

    /**
     * Tracks an event
     *
     * @param string $category The Event Category (Videos, Music, Games...)
     * @param string $action   The Event's Action (Play, Pause, Duration, Add Playlist, Downloaded, Clicked...)
     * @param string $name     The Event's object Name (a particular Movie name, or Song name, or File name...)
     * @param float  $value    The Event's value
     */
    public function event(string $category, string $action, $name = null, $value = null): self
    {
        if (strlen($category) === 0) {
            throw new InvalidArgumentException('You must specify an Event Category name (Music, Videos, Games...).');
        }

        if (strlen($action) === 0) {
            throw new InvalidArgumentException('You must specify an Event action (click, view, add...).');
        }

        return $this->set([
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
    public function content(string $name, string $piece = 'Unknown', $target = null, $interaction = null): self
    {
        if (strlen($name) === 0) {
            throw new InvalidArgumentException('You must specify a content name');
        }

        return $this->set([
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
     * @param int    $countResults Results displayed on the search result page. Used to track "zero result" keywords.
     */
    public function siteSearch(string $keyword, string $category = null, $countResults = null): self
    {
        return $this->set([
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
    public function goal(int $idGoal, float $revenue = 0.0): self
    {
        return $this->set([
            'idgoal' => $idGoal,
            'revenue' => $revenue,
        ]);
    }

    /**
     * Tracks a download
     */
    public function download(string $url): self
    {
        return $this->set(['download' => $url]);
    }

    /**
     * Overrides IP address
     * Allowed only for Admin/Super User, must be used along with setTokenAuth()
     */
    public function ip(string $ip = null): self
    {
        if ($ip === '') {
            throw new InvalidArgumentException('IP cannot be empty.');
        }

        return $this->set(['cip' => $ip]);
    }

    /**
     * Force the action to be recorded for a specific User.
     * The User ID is a string representing a given user in your system.
     * A User ID can be a username, UUID or an email address,
     * or any number or string that uniquely identifies a user or client.
     */
    public function userId(string $userId = null): self
    {
        if ($userId === '') {
            throw new InvalidArgumentException('User ID cannot be empty.');
        }

        return $this->set(['uid' => $userId]);
    }

    /**
     * Set a six character unique ID that identifies which actions were performed on a specific page view.
     */
    public function pageId(string $pageId = null): self
    {
        if ($pageId === '') {
            throw new InvalidArgumentException('Page ID cannot be empty.');
        }

        return $this->set(['pv_id' => $pageId]);
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
    public function tokenAuth(string $tokenAuth): self
    {
        if (strlen($tokenAuth) === 32) {
            throw new InvalidArgumentException('Invalid authorization key.');
        }

        return $this->set(['token_auth' => $tokenAuth]);
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
