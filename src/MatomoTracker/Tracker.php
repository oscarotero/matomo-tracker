<?php
declare(strict_types = 1);

namespace Middlewares\MatomoTracker;

use Exception;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Piwik - free/libre analytics platform
 *
 * For more information, see README.md
 *
 * @license released under BSD License http://www.opensource.org/licenses/bsd-license.php
 * @link http://piwik.org/docs/tracking-api/
 *
 * @category Piwik
 */

/**
 * PiwikTracker implements the Piwik Tracking Web API.
 *
 * For more information, see README.md
 *
 * @api
 */
class Tracker
{
    /**
     * API Version
     */
    const VERSION = 1;

    /**
     * Visitor ID length
     */
    const VISITOR_ID_LENGTH = 16;

    /**
     * See piwik.js
     */
    const FIRST_PARTY_COOKIES_PREFIX = '_pk_';

    /**
     * Ecommerce item page view tracking stores item's metadata in these Custom Variables slots.
     */
    const CVAR_INDEX_ECOMMERCE_ITEM_PRICE = 2;
    const CVAR_INDEX_ECOMMERCE_ITEM_SKU = 3;
    const CVAR_INDEX_ECOMMERCE_ITEM_NAME = 4;
    const CVAR_INDEX_ECOMMERCE_ITEM_CATEGORY = 5;

    private $serverRequest;
    private $apiUrl;
    private $idSite;
    private $pageCharset;
    private $ip;
    private $proxy;
    private $currentTs;
    private $createTs;
    private $visitorCustomVar;
    private $ecommerceItems = [];
    private $attributionInfo;
    private $eventCustomVar = [];
    private $forcedDatetime = false;
    private $forcedNewVisit = false;
    private $generationTime;
    private $pageCustomVar;
    private $customParameters = [];
    private $customData;
    private $hasCookies;
    private $token_auth;
    private $country;
    private $region;
    private $city;
    private $lat;
    private $long;
    private $resolution;
    private $plugins = [];
    private $localHour;
    private $localMinute;
    private $localSecond;
    private $idPageview;
    private $sendImageResponse = true;
    private $cookies = [];

    // Life of the visitor cookie (in sec)
    private $configVisitorCookieTimeout = 33955200; // 13 months (365 + 28 days)
    // Life of the session cookie (in sec)
    private $configSessionCookieTimeout = 1800; // 30 minutes
    // Life of the referral cookie (in sec)
    private $configReferralCookieTimeout = 15768000; // 6 months

    // Visitor Ids in order
    private $userId;
    private $forcedVisitorId = false;
    private $cookieVisitorId = false;
    private $randomVisitorId = false;
    private $visitCount = 0;
    private $currentVisitTs;
    private $lastVisitTs = false;
    private $ecommerceLastOrderTimestamp;

    // Allow debug while blocking the request
    private $requestTimeout = 600;
    private $storedTrackingActions = [];

    private $configCookiesDisabled = false;
    private $configCookiePath = '/';
    private $configCookieDomain = '';


    /**
     * Builds a PiwikTracker object, used to track visits, pages and Goal conversions
     * for a specific website, by using the Piwik Tracking API.
     */
    public function __construct(ServerRequestInterface $serverRequest, string $apiUrl, int $idSite)
    {
        $server = $serverRequest->getServerParams();

        $this->serverRequest = $serverRequest;
        $this->idSite = $idSite;
        $this->ip = $server['REMOTE_ADDR'] ?? null;
        $this->apiUrl = $apiUrl;

        $this->setNewVisitorId();

        $this->createTs = $this->currentTs = time();
        $this->visitorCustomVar = $this->getCustomVariablesFromCookie();
    }

    /**
     * By default, Piwik expects utf-8 encoded values, for example
     * for the page URL parameter values, Page Title, etc.
     * It is recommended to only send UTF-8 data to Piwik.
     * If required though, you can also specify another charset using this function.
     */
    public function setPageCharset(string $charset = ''): self
    {
        $this->pageCharset = $charset;
        return $this;
    }

    /**
     * Sets the time that generating the document on the server side took.
     */
    public function setGenerationTime(int $timeMs): self
    {
        $this->generationTime = $timeMs;
        return $this;
    }

    /**
     * Sets the attribution information to the visit, so that subsequent Goal conversions are
     * properly attributed to the right Referrer URL, timestamp, Campaign Name & Keyword.
     *
     * This must be a JSON encoded string that would typically be fetched from the JS API:
     * piwikTracker.getAttributionInfo() and that you have JSON encoded via JSON2.stringify()
     *
     * If you call enableCookies() then these referral attribution values will be set
     * to the 'ref' first party cookie storing referral information.
     *
     * @see function getAttributionInfo() in https://github.com/piwik/piwik/blob/master/js/piwik.js
     */
    public function setAttributionInfo(array $attributionInfo): self
    {
        $this->attributionInfo = $attributionInfo;
        return $this;
    }

    /**
     * Sets Visit Custom Variable.
     * See http://piwik.org/docs/custom-variables/
     *
     * @param int    $id    Custom variable slot ID from 1-5
     * @param string $name  Custom variable name
     * @param string $value Custom variable value
     * @param string $scope Custom variable scope. Possible values: visit, page, event
     *
     * @throws Exception
     */
    public function setCustomVariable(int $id, string $name, string $value, string $scope = 'visit'): self
    {
        switch ($scope) {
            case 'page':
                $this->pageCustomVar[$id] = [$name, $value];
                return $this;
            case 'event':
                $this->eventCustomVar[$id] = [$name, $value];
                return $this;
            case 'visit':
                $this->visitorCustomVar[$id] = [$name, $value];
                return $this;
        }

        throw new InvalidArgumentException("Invalid 'scope' parameter value: {$scope}");
    }

    /**
     * Returns the currently assigned Custom Variable.
     *
     * If scope is 'visit', it will attempt to read the value set in the first party cookie created by Piwik Tracker
     *
     * @param int    $id    Custom Variable integer index to fetch from cookie. Should be a value from 1 to 5
     * @param string $scope Custom variable scope. Possible values: visit, page, event
     *
     * @throws InvalidArgumentException
     *
     * @return array|bool An array with this format: array( 0 => CustomVariableName, 1 => CustomVariableValue ) or false
     * @see Piwik.js getCustomVariable()
     */
    public function getCustomVariable(int $id, string $scope = 'visit')
    {
        switch ($scope) {
            case 'page':
                return $this->pageCustomVar[$id] ?? false;
            case 'event':
                return $this->eventCustomVar[$id] ?? false;
            case 'visit':
                if (!empty($this->visitorCustomVar[$id])) {
                    return $this->visitorCustomVar[$id];
                }

                $cookieDecoded = $this->getCustomVariablesFromCookie();

                if (!is_array($cookieDecoded)
                    || !isset($cookieDecoded[$id])
                    || !is_array($cookieDecoded[$id])
                    || count($cookieDecoded[$id]) !== 2
                ) {
                    return false;
                }

                return $cookieDecoded[$id];
        }

        throw new InvalidArgumentException("Invalid 'scope' parameter value: {$scope}");
    }

    /**
     * Clears any Custom Variable that may be have been set.
     *
     * This can be useful when you have enabled bulk requests,
     * and you wish to clear Custom Variables of 'visit' scope.
     */
    public function clearCustomVariables()
    {
        $this->visitorCustomVar = [];
        $this->pageCustomVar = [];
        $this->eventCustomVar = [];
    }

    /**
     * Sets a custom tracking parameter. This is useful if you need to send any tracking parameters for a 3rd party
     * plugin that is not shipped with Piwik itself. Please note that custom parameters are cleared after each
     * tracking request.
     *
     * @param string $trackingApiParameter The name of the tracking API parameter, eg 'dimension1'
     * @param string $value                Tracking parameter value that shall be sent for this tracking parameter.
     */
    public function setCustomTrackingParameter(string $trackingApiParameter, string $value): self
    {
        $this->customParameters[$trackingApiParameter] = $value;
        return $this;
    }

    /**
     * Clear / reset all previously set custom tracking parameters.
     */
    public function clearCustomTrackingParameters()
    {
        $this->customParameters = [];
    }

    /**
     * Sets the current visitor ID to a random new one.
     */
    public function setNewVisitorId(): self
    {
        $this->randomVisitorId = substr(md5(uniqid((string) rand(), true)), 0, self::VISITOR_ID_LENGTH);
        $this->userId = false;
        $this->forcedVisitorId = false;
        $this->cookieVisitorId = false;
        return $this;
    }

    /**
     * Sets the country of the visitor. If not used, Piwik will try to find the country
     * using either the visitor's IP address or language.
     *
     * Allowed only for Admin/Super User, must be used along with setTokenAuth().
     */
    public function setCountry(string $country): self
    {
        $this->country = $country;
        return $this;
    }

    /**
     * Sets the region of the visitor. If not used, Piwik may try to find the region
     * using the visitor's IP address (if configured to do so).
     *
     * Allowed only for Admin/Super User, must be used along with setTokenAuth().
     */
    public function setRegion(string $region): self
    {
        $this->region = $region;
        return $this;
    }

    /**
     * Sets the city of the visitor. If not used, Piwik may try to find the city
     * using the visitor's IP address (if configured to do so).
     *
     * Allowed only for Admin/Super User, must be used along with setTokenAuth().
     */
    public function setCity(string $city): self
    {
        $this->city = $city;
        return $this;
    }

    /**
     * Sets the latitude of the visitor. If not used, Piwik may try to find the visitor's
     * latitude using the visitor's IP address (if configured to do so).
     *
     * Allowed only for Admin/Super User, must be used along with setTokenAuth().
     */
    public function setLatitude(float $lat): self
    {
        $this->lat = $lat;
        return $this;
    }

    /**
     * Sets the longitude of the visitor. If not used, Piwik may try to find the visitor's
     * longitude using the visitor's IP address (if configured to do so).
     *
     * Allowed only for Admin/Super User, must be used along with setTokenAuth().
     */
    public function setLongitude(float $long): self
    {
        $this->long = $long;
        return $this;
    }

    /**
     * Enable Cookie Creation - this will cause a first party VisitorId cookie to be set when the VisitorId is set or reset
     *
     * @param string $domain (optional) Set first-party cookie domain.
     *                       Accepted values: example.com, .example.com or subdomain.example.com
     * @param string $path   (optional) Set first-party cookie path
     */
    public function enableCookies(string $domain = '', string $path = '/'): self
    {
        $this->configCookiesDisabled = false;
        $this->configCookieDomain = $domain;
        $this->configCookiePath = $path;
        return $this;
    }

    /**
     * If image response is disabled Piwik will respond with a HTTP 204 header instead of responding with a gif.
     */
    public function disableSendImageResponse(): self
    {
        $this->sendImageResponse = false;
        return $this;
    }

    /**
     * Tracks a page view
     *
     * @param  string $documentTitle Page title as it will appear in the Actions > Page titles report
     */
    public function trackPageView(string $documentTitle): HttpClient
    {
        $this->generateNewPageviewId();

        $url = $this->buildUrl($this->idSite, [
            'action_name' => $documentTitle,
        ]);

        return $this->createRequest($url);
    }

    /**
     * Tracks an event
     *
     * @param  string      $category The Event Category (Videos, Music, Games...)
     * @param  string      $action   The Event's Action (Play, Pause, Duration, Add Playlist, Downloaded, Clicked...)
     * @param  string|bool $name     (optional) The Event's object Name (a particular Movie name, or Song name, or File name...)
     * @param  float|bool  $value    (optional) The Event's value
     */
    public function trackEvent(string $category, string $action, $name = false, $value = false): HttpClient
    {
        if (strlen($category) === 0) {
            throw new InvalidArgumentException('You must specify an Event Category name (Music, Videos, Games...).');
        }

        if (strlen($action) === 0) {
            throw new InvalidArgumentException('You must specify an Event action (click, view, add...).');
        }

        $url = $this->buildUrl($this->idSite, [
            'e_c' => $category,
            'e_a' => $action,
            'e_n' => $name,
            'e_v' => $value,
        ]);

        return $this->createRequest($url);
    }

    /**
     * Tracks a content impression
     *
     * @param  string      $contentName   The name of the content. For instance 'Ad Foo Bar'
     * @param  string      $contentPiece  The actual content. For instance the path to an image, video, audio, any text
     * @param  string|bool $contentTarget (optional) The target of the content. For instance the URL of a landing page.
     */
    public function trackContentImpression(string $contentName, string $contentPiece = 'Unknown', $contentTarget = false): HttpClient
    {
        if (strlen($name) == 0) {
            throw new InvalidArgumentException('You must specify a content name');
        }

        $url = $this->buildUrl($this->idSite, [
            'c_n' => $name,
            'c_p' => $piece,
            'c_t' => $target,
        ]);

        return $this->createRequest($url);
    }

    /**
     * Tracks a content interaction. Make sure you have tracked a content impression using the same content name and
     * content piece, otherwise it will not count. To do so you should call the method doTrackContentImpression();
     *
     * @param  string      $interaction   The name of the interaction with the content. For instance a 'click'
     * @param  string      $contentName   The name of the content. For instance 'Ad Foo Bar'
     * @param  string      $contentPiece  The actual content. For instance the path to an image, video, audio, any text
     * @param  string|bool $contentTarget (optional) The target the content leading to when an interaction occurs. For instance the URL of a landing page.
     */
    public function trackContentInteraction(
        string $interaction,
        string $contentName,
        string $contentPiece = 'Unknown',
        $contentTarget = false
    ): HttpClient {
        if (strlen($interaction) == 0) {
            throw new InvalidArgumentException('You must specify a name for the interaction');
        }

        if (strlen($name) == 0) {
            throw new InvalidArgumentException('You must specify a content name');
        }

        $url = $this->buildUrl($this->idSite, [
            'c_i' => $interaction,
            'c_n' => $name,
            'c_p' => $piece,
            'c_t' => $target,
        ]);

        return $this->createRequest($url);
    }

    /**
     * Tracks an internal Site Search query, and optionally tracks the Search Category, and Search results Count.
     * These are used to populate reports in Actions > Site Search.
     *
     * @param string   $keyword      Searched query on the site
     * @param string   $category     (optional) Search engine category if applicable
     * @param bool|int $countResults (optional) results displayed on the search result page. Used to track "zero result" keywords.
     */
    public function trackSiteSearch(string $keyword, string $category = '', $countResults = false): HttpClient
    {
        $url = $this->buildUrl($this->idSite, [
            'search' => $keyword,
            'search_cat' => $category,
            'search_count' => $countResults,
        ]);

        return $this->createRequest($url);
    }

    /**
     * Records a Goal conversion
     *
     * @param  int   $idGoal  Id Goal to record a conversion
     * @param  float $revenue Revenue for this conversion
     */
    public function trackGoal(int $idGoal, float $revenue = 0.0): HttpClient
    {
        $url = $this->buildUrl($this->idSite, [
            'idgoal' => $idGoal,
            'revenue' => $revenue,
        ]);

        return $this->createRequest($url);
    }

    /**
     * Tracks a download or outlink
     *
     * @param  string $actionType Type of the action: 'download' or 'link'
     * @param  string $actionUrl  URL of the download or outlink
     */
    public function trackAction(string $actionType, string $actionUrl): HttpClient
    {
        // Referrer could be udpated to be the current URL temporarily (to mimic JS behavior)
        $url = $this->buildUrl($this->idSite, [
            $actionType => $actionUrl,
        ]);

        return $this->createRequest($url);
    }

    /**
     * Adds an item in the Ecommerce order.
     *
     * This should be called before doTrackEcommerceOrder(), or before doTrackEcommerceCartUpdate().
     * This function can be called for all individual products in the cart (or order).
     * SKU parameter is mandatory. Other parameters are optional (set to false if value not known).
     * Ecommerce items added via this function are automatically cleared when doTrackEcommerceOrder() or getUrlTrackEcommerceOrder() is called.
     *
     * @param  string                   $sku      (required) SKU, Product identifier
     * @param  string                   $name     (optional) Product name
     * @param  string|array             $category (optional) Product category, or array of product categories (up to 5 categories can be specified for a given product)
     * @param  float|int                $price    (optional) Individual product price (supports integer and decimal prices)
     * @param  int                      $quantity (optional) Product quantity. If not specified, will default to 1 in the Reports
     * @throws InvalidArgumentException
     */
    public function addEcommerceItem(string $sku, string $name = '', $category = '', float $price = 0.0, int $quantity = 1): self
    {
        if (empty($sku)) {
            throw new InvalidArgumentException('You must specify a SKU for the Ecommerce item');
        }

        $this->ecommerceItems[] = [$sku, $name, $category, $price, $quantity];

        return $this;
    }

    /**
     * Tracks a Cart Update (add item, remove item, update item).
     *
     * On every Cart update, you must call addEcommerceItem() for each item (product) in the cart,
     * including the items that haven't been updated since the last cart update.
     * Items which were in the previous cart and are not sent in later Cart updates will be deleted from the cart (in the database).
     *
     * @param  float $grandTotal Cart grandTotal (typically the sum of all items' prices)
     */
    public function trackEcommerceCartUpdate(float $grandTotal): HttpClient
    {
        $url = $this->getUrlTrackEcommerce($grandTotal);

        return $this->createRequest($url);
    }

    /**
     * Tracks an Ecommerce order.
     *
     * If the Ecommerce order contains items (products), you must call first the addEcommerceItem() for each item in the order.
     * All revenues (grandTotal, subTotal, tax, shipping, discount) will be individually summed and reported in Piwik reports.
     * Only the parameters $orderId and $grandTotal are required.
     *
     * @param  string|int $orderId    (required) Unique Order ID.
     *                                This will be used to count this order only once in the event the order page is reloaded several times.
     *                                orderId must be unique for each transaction, even on different days, or the transaction will not be recorded by Piwik.
     * @param  float      $grandTotal (required) Grand Total revenue of the transaction (including tax, shipping, etc.)
     * @param  float      $subTotal   (optional) Sub total amount, typically the sum of items prices for all items in this order (before Tax and Shipping costs are applied)
     * @param  float      $tax        (optional) Tax amount for this order
     * @param  float      $shipping   (optional) Shipping amount for this order
     * @param  float      $discount   (optional) Discounted amount in this order
     * @return mixed      Response or true if using bulk request
     */
    public function trackEcommerceOrder(
        float $orderId,
        float $grandTotal,
        float $subTotal = 0.0,
        float $tax = 0.0,
        float $shipping = 0.0,
        float $discount = 0.0
    ): HttpClient {
        if (empty($orderId)) {
            throw new Exception('You must specifiy an orderId for the Ecommerce order');
        }

        $url = $this->getUrlTrackEcommerce($grandTotal, $subTotal, $tax, $shipping, $discount, $orderId);

        $this->ecommerceLastOrderTimestamp = $this->getTimestamp();

        return $this->createRequest($url);
    }

    /**
     * Sends a ping request.
     *
     * Ping requests do not track new actions. If they are sent within the standard visit length (see global.ini.php),
     * they will extend the existing visit and the current last action for the visit. If after the standard visit length,
     * ping requests will create a new visit using the last action in the last known visit.
     *
     * @return mixed Response or true if using bulk request
     */
    public function doPing(): HttpClient
    {
        $url = $this->buildUrl($this->idSite, ['ping' => 1]);

        return $this->createRequest($url);
    }

    /**
     * Sets the current page view as an item (product) page view, or an Ecommerce Category page view.
     *
     * This must be called before doTrackPageView() on this product/category page.
     * It will set 3 custom variables of scope "page" with the SKU, Name and Category for this page view.
     * Note: Custom Variables of scope "page" slots 3, 4 and 5 will be used.
     *
     * On a category page, you may set the parameter $category only and set the other parameters to false.
     *
     * Tracking Product/Category page views will allow Piwik to report on Product & Categories
     * conversion rates (Conversion rate = Ecommerce orders containing this product or category / Visits to the product or category)
     *
     * @param string       $sku      Product SKU being viewed
     * @param string       $name     Product Name being viewed
     * @param string|array $category Category being viewed. On a Product page, this is the product's category.
     *                               You can also specify an array of up to 5 categories for a given page view.
     * @param float        $price    Specify the price at which the item was displayed
     */
    public function setEcommerceView(string $sku = '', string $name = '', $category = '', float $price = 0.0): self
    {
        if (!empty($category)) {
            if (is_array($category)) {
                $category = json_encode($category);
            }
        } else {
            $category = '';
        }
        $this->pageCustomVar[self::CVAR_INDEX_ECOMMERCE_ITEM_CATEGORY] = ['_pkc', $category];

        if (!empty($price)) {
            $this->pageCustomVar[self::CVAR_INDEX_ECOMMERCE_ITEM_PRICE] = ['_pkp', $price];
        }

        // On a category page, do not record "Product name not defined"
        if (empty($sku) && empty($name)) {
            return $this;
        }
        if (!empty($sku)) {
            $this->pageCustomVar[self::CVAR_INDEX_ECOMMERCE_ITEM_SKU] = ['_pks', $sku];
        }
        if (empty($name)) {
            $name = '';
        }
        $this->pageCustomVar[self::CVAR_INDEX_ECOMMERCE_ITEM_NAME] = ['_pkn', $name];
        return $this;
    }

    /**
     * Overrides server date and time for the tracking requests.
     * By default Piwik will track requests for the "current datetime" but this function allows you
     * to track visits in the past. All times are in UTC.
     *
     * Allowed only for Admin/Super User, must be used along with setTokenAuth()
     * @see setTokenAuth()
     * @param string $dateTime Date with the format 'Y-m-d H:i:s', or a UNIX timestamp.
     *                         If the datetime is older than one day (default value for tracking_requests_require_authentication_when_custom_timestamp_newer_than), then you must call setTokenAuth() with a valid Admin/Super user token.
     */
    public function setForceVisitDateTime(string $dateTime): self
    {
        $this->forcedDatetime = $dateTime;
        return $this;
    }

    /**
     * Forces Piwik to create a new visit for the tracking request.
     *
     * By default, Piwik will create a new visit if the last request by this user was more than 30 minutes ago.
     * If you call setForceNewVisit() before calling doTrack*, then a new visit will be created for this request.
     */
    public function setForceNewVisit(): self
    {
        $this->forcedNewVisit = true;
        return $this;
    }

    /**
     * Overrides IP address
     *
     * Allowed only for Admin/Super User, must be used along with setTokenAuth()
     * @see setTokenAuth()
     * @param string $ip IP string, eg. 130.54.2.1
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
     *
     * @param  string    $userId Any user ID string (eg. email address, ID, username). Must be non empty. Set to false to de-assign a user id previously set.
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
     * Hash function used internally by Piwik to hash a User ID into the Visitor ID.
     *
     * Note: matches implementation of Tracker\Request->getUserIdHashed()
     */
    public static function getUserIdHashed(string $id): string
    {
        return substr(sha1($id), 0, 16);
    }

    /**
     * If the user initiating the request has the Piwik first party cookie,
     * this function will try and return the ID parsed from this first party cookie (found in $_COOKIE).
     *
     * If you call this function from a server, where the call is triggered by a cron or script
     * not initiated by the actual visitor being tracked, then it will return
     * the random Visitor ID that was assigned to this visit object.
     *
     * This can be used if you wish to record more visits, actions or goals for this visitor ID later on.
     *
     * @return string 16 hex chars visitor ID string
     */
    public function getVisitorId(): string
    {
        if (!empty($this->userId)) {
            return $this->getUserIdHashed($this->userId);
        }
        if (!empty($this->forcedVisitorId)) {
            return $this->forcedVisitorId;
        }
        if ($this->loadVisitorIdCookie()) {
            return $this->cookieVisitorId;
        }

        return $this->randomVisitorId;
    }

    /**
     * Returns the User ID string, which may have been set via:
     *     $v->setUserId('username@example.org');
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Deletes all first party cookies from the client
     */
    public function deleteCookies()
    {
        $cookies = ['id', 'ses', 'cvar', 'ref'];
        foreach ($cookies as $cookie) {
            $this->setCookie($cookie, '', -86400);
        }
    }

    /**
     * Returns the currently assigned Attribution Information stored in a first party cookie.
     *
     * This function will only work if the user is initiating the current request, and his cookies
     * can be read by PHP from the $_COOKIE array.
     *
     * @return string JSON Encoded string containing the Referrer information for Goal conversion attribution.
     *                Will return false if the cookie could not be found
     * @see Piwik.js getAttributionInfo()
     */
    public function getAttributionInfo()
    {
        if (!empty($this->attributionInfo)) {
            return json_encode($this->attributionInfo);
        }

        return $this->getCookieMatchingName('ref');
    }

    /**
     * Some Tracking API functionality requires express authentication, using either the
     * Super User token_auth, or a user with 'admin' access to the website.
     *
     * The following features require access:
     * - force the visitor IP
     * - force the date &  time of the tracking requests rather than track for the current datetime
     *
     * @param string $token_auth token_auth 32 chars token_auth string
     */
    public function setTokenAuth(string $token_auth): self
    {
        $this->token_auth = $token_auth;
        return $this;
    }

    /**
     * Sets local visitor time
     *
     * @param string $time HH:MM:SS format
     */
    public function setLocalTime(string $time): self
    {
        list($hour, $minute, $second) = explode(':', $time);
        $this->localHour = (int) $hour;
        $this->localMinute = (int) $minute;
        $this->localSecond = (int) $second;
        return $this;
    }

    /**
     * Sets user resolution width and height.
     */
    public function setResolution(int $width, int $height): self
    {
        $this->resolution = [$width, $height];
        return $this;
    }

    /**
     * Sets if the browser supports cookies
     * This is reported in "List of plugins" report in Piwik.
     */
    public function setBrowserHasCookies(bool $bool): self
    {
        $this->hasCookies = $bool;
        return $this;
    }

    /**
     * Sets visitor browser supported plugins
     */
    public function setPlugins(
        bool $flash = false,
        bool $java = false,
        bool $director = false,
        bool $quickTime = false,
        bool $realPlayer = false,
        bool $pdf = false,
        bool $windowsMedia = false,
        bool $gears = false,
        bool $silverlight = false
    ): self {
        $this->plugins = [
            'fla' => (int) $flash,
            'java' => (int) $java,
            'dir' => (int) $director,
            'qt' => (int) $quickTime,
            'realp' => (int) $realPlayer,
            'pdf' => (int) $pdf,
            'wma' => (int) $windowsMedia,
            'gears' => (int) $gears,
            'ag' => (int) $silverlight,
        ];

        return $this;
    }

    /**
     * By default, PiwikTracker will read first party cookies
     * from the request and write updated cookies in the response (using setrawcookie).
     * This can be disabled by calling this function.
     */
    public function disableCookieSupport(): self
    {
        $this->configCookiesDisabled = true;

        return $this;
    }

    /**
     * Returns the maximum number of seconds the tracker will spend waiting for a response
     * from Piwik. Defaults to 600 seconds.
     */
    public function getRequestTimeout(): int
    {
        return $this->requestTimeout;
    }

    /**
     * Sets the maximum number of seconds that the tracker will spend waiting for a response
     * from Piwik.
     *
     * @throws InvalidArgumentException
     */
    public function setRequestTimeout(int $timeout): self
    {
        if ($timeout < 0) {
            throw new InvalidArgumentException("Invalid value supplied for request timeout: $timeout");
        }

        $this->requestTimeout = $timeout;
        return $this;
    }

    /**
     * If a proxy is needed to look up the address of the Piwik site, set it with this
     * @param string $proxy IP as string, for example "173.234.92.107"
     */
    public function setProxy(string $proxy, int $proxyPort = 80): self
    {
        $this->proxy = "{$proxy}:{$port}";

        return $this;
    }

    /**
     * Sets a cookie to be sent to the tracking server.
     *
     * @param $name
     * @param $value
     */
    public function setCookies($name, $value): self
    {
        if ($value === null) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
        return $this;
    }

    /**
     * @ignore
     * @param null|mixed $data
     */
    private function createRequest(string $url, string $method = 'GET', $data = null): HttpClient
    {
        $acceptLanguage = $this->serverRequest->getHeaderLine('Accept-Language');
        $userAgent = $this->serverRequest->getHeaderLine('User-Agent');

        $client = new HttpClient($method, $url);

        $client->setUserAgent($userAgent)
            ->setData($data)
            ->setTimeout($this->requestTimeout)
            ->setAcceptLanguage($acceptLanguage)
            ->setProxy($this->proxy)
            ->setCookies($this->cookies);

        return $client;
    }

    /**
     * Returns current timestamp, or forced timestamp/datetime if it was set
     */
    private function getTimestamp(): int
    {
        return !empty($this->forcedDatetime) ? strtotime($this->forcedDatetime) : time();
    }

    /**
     * @ignore
     */
    private function buildUrl(int $idSite, array $extraParams = []): string
    {
        $this->setFirstPartyCookies();

        $getParams = $this->serverRequest->getQueryParams();

        $params = [
            'idsite' => $idSite,
            'rec' => 1,
            'apiv' => self::VERSION,
            '&r' => substr(strval(mt_rand()), 2, 6),

            // XDEBUG_SESSIONS_START and KEY are related to the PHP Debugger, this can be ignored in other languages
            'XDEBUG_SESSION_START' => $getParams['XDEBUG_SESSION_START'] ?? null,
            'KEY' => $getParams['KEY'] ?? null,

            // Only allowed for Admin/Super User, token_auth required,
            'cip' => $this->ip,
            'uid' => $this->userId,
            'cdt' => $this->forcedDatetime,
            'new_visit' => 1,
            'token_auth' => $this->token_auth,

            // Values collected from cookie
            '_idts' => $this->createTs,
            '_idvc' => $this->visitCount,
            '_viewts' => $this->lastVisitTs ?? null,
            '_ects' => $this->ecommerceLastOrderTimestamp ?? null,

            // These parameters are set by the JS, but optional when using API
            'h' => $this->localHour,
            'm' => $this->localMinute,
            's' => $this->localSecond,
            'res' => $this->resolution ? implode('x', $this->resolution) : null,
            'cookie' => $this->hasCookies,

            // Various important attributes
            'data' => $this->customData,
            '_cvar' => $this->visitorCustomVar,
            'cvar' => $this->pageCustomVar,
            'e_cvar' => $this->eventCustomVar,
            'gt_ms' => $this->generationTime,

            // URL parameters
            'url' => (string) $this->serverRequest->getUri(),
            'urlref' => $this->serverRequest->getHeaderLine('Referer'),
            'cs' => $this->pageCharset,

            // unique pageview id
            'pv_id' => $this->idPageview,

            // Attribution information, so that Goal conversions are attributed to the right referrer or campaign
            // Campaign name
            '_rcn' => $this->attributionInfo[0] ?? null,
            // Campaign keyword
            '_rck' => $this->attributionInfo[1] ?? null,
            // Timestamp at which the referrer was set
            '_refts' => $this->attributionInfo[2] ?? null,
            // Referrer URL
            '_ref' => $this->attributionInfo[3] ?? null,

            // custom location info
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'lat' => $this->lat,
            'long' => $this->long,

            'send_image' => $this->sendImageResponse ? null : 0,
        ];

        //Visitor id
        if (!empty($this->forcedVisitorId)) {
            $params['cid'] = $this->forcedVisitorId;
        } else {
            $params['_id'] = $this->getVisitorId();
        }

        $query = self::buildQuery($extraParams + $params + $this->plugins + $this->customParameters);

        $url = $this->apiUrl.$query;

        // Reset page level custom variables after this page view
        $this->pageCustomVar = [];
        $this->eventCustomVar = [];
        $this->clearCustomTrackingParameters();

        // force new visit only once, user must call again setForceNewVisit()
        $this->forcedNewVisit = false;

        return $url;
    }

    /**
     * Returns a first party cookie which name contains $name
     *
     * @return string|bool String value of cookie, or false if not found
     * @ignore
     */
    private function getCookieMatchingName(string $name)
    {
        if ($this->configCookiesDisabled) {
            return false;
        }

        $cookies = $this->serverRequest->getCookieParams();

        if (empty($cookies)) {
            return false;
        }

        $name = $this->getCookieName($name);

        // Piwik cookie names use dots separators in piwik.js,
        // but PHP Replaces . with _ http://www.php.net/manual/en/language.variables.predefined.php#72571
        $name = str_replace('.', '_', $name);

        foreach ($cookies as $cookieName => $cookieValue) {
            if (strpos($cookieName, $name) !== false) {
                return $cookieValue;
            }
        }

        return false;
    }

    /**
     * Sets the first party cookies as would the piwik.js
     * All cookies are supported: 'id' and 'ses' and 'ref' and 'cvar' cookies.
     */
    private function setFirstPartyCookies(): self
    {
        if ($this->configCookiesDisabled) {
            return $this;
        }

        if (empty($this->cookieVisitorId)) {
            $this->loadVisitorIdCookie();
        }

        // Set the 'ref' cookie
        $attributionInfo = $this->getAttributionInfo();

        if (!empty($attributionInfo)) {
            $this->setCookie('ref', $attributionInfo, $this->configReferralCookieTimeout);
        }

        // Set the 'ses' cookie
        $this->setCookie('ses', '*', $this->configSessionCookieTimeout);

        // Set the 'id' cookie
        $visitCount = $this->visitCount + 1;
        $cookieValue = sprintf(
            '%s.%s.%s.%s.%s.%s',
            $this->getVisitorId(),
            $this->createTs,
            $visitCount,
            $this->currentTs,
            $this->lastVisitTs,
            $this->ecommerceLastOrderTimestamp
        );

        $this->setCookie('id', $cookieValue, $this->configVisitorCookieTimeout);

        // Set the 'cvar' cookie
        $this->setCookie('cvar', json_encode($this->visitorCustomVar), $this->configSessionCookieTimeout);
        return $this;
    }

    /**
     * Sets a first party cookie to the client to improve dual JS-PHP tracking.
     *
     * This replicates the piwik.js tracker algorithms for consistency and better accuracy.
     *
     * @param $cookieValue
     * @param $cookieTTL
     */
    private function setCookie(string $cookieName, $cookieValue, $cookieTTL): self
    {
        $cookieExpire = $this->currentTs + $cookieTTL;

        if (!headers_sent()) {
            setcookie(
                $this->getCookieName($cookieName),
                $cookieValue,
                $cookieExpire,
                $this->configCookiePath,
                $this->configCookieDomain
            );
        }
        return $this;
    }

    /**
     * @return bool|mixed
     */
    private function getCustomVariablesFromCookie()
    {
        $cookie = $this->getCookieMatchingName('cvar');

        return $cookie ? json_decode($cookie, $assoc = true) : false;
    }

    /**
     * Loads values from the VisitorId Cookie
     *
     * @return bool True if cookie exists and is valid, False otherwise
     */
    private function loadVisitorIdCookie(): bool
    {
        $idCookie = $this->getCookieMatchingName('id');

        if ($idCookie === false) {
            return false;
        }

        $parts = explode('.', $idCookie);

        if (strlen($parts[0]) !== self::VISITOR_ID_LENGTH) {
            return false;
        }

        /* $this->cookieVisitorId provides backward compatibility since getVisitorId()
        didn't change any existing VisitorId value */
        $this->cookieVisitorId = $parts[0];
        $this->createTs = $parts[1];
        $this->visitCount = (int) $parts[2];
        $this->currentVisitTs = $parts[3];
        $this->lastVisitTs = $parts[4];

        if (isset($parts[5])) {
            $this->ecommerceLastOrderTimestamp = $parts[5];
        }

        return true;
    }

    /**
     * Get cookie name with prefix and domain hash
     */
    private function getCookieName(string $cookieName): string
    {
        // NOTE: If the cookie name is changed, we must also update the method in piwik.js with the same name.
        $host = $this->serverRequest->getUri()->getHost() ?: 'unknown';
        $hash = substr(sha1(($this->configCookieDomain === '' ? $host : $this->configCookieDomain) . $this->configCookiePath), 0, 4);

        return sprintf('%%s.%s.%s', self::FIRST_PARTY_COOKIES_PREFIX, $cookieName, $this->idSite, $hash);
    }

    /**
     * Returns URL used to track Ecommerce orders
     *
     * Calling this function will reinitializes the property ecommerceItems to empty array
     * so items will have to be added again via addEcommerceItem()
     *
     * @ignore
     */
    private function getUrlTrackEcommerce(float $grandTotal, float $subTotal = 0.0, float $tax = 0.0, float $shipping = 0.0, float $discount = 0.0, float $orderId = null): string
    {
        $url = $this->buildUrl($this->idSite, [
            'idgoal' => 0,
            'revenue' => $grandTotal ?: null,
            'ec_st' => $subTotal ?: null,
            'ec_tx' => $tax ?: null,
            'ec_sh' => $shipping ?: null,
            'ec_dt' => $discount ?: null,
            'ec_items' => $this->ecommerceItems ? json_encode($this->ecommerceItems) : null,
            'ec_id' => $orderId,
        ]);

        $this->ecommerceItems = [];

        return $url;
    }

    private function generateNewPageviewId()
    {
        $this->idPageview = substr(md5(uniqid(rand(), true)), 0, 6);
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
