<?php
declare(strict_types = 1);

namespace Middlewares\MatomoTracker;

class HttpClient
{
    protected $url;
    protected $method;
    protected $data;
    protected $userAgent;
    protected $acceptLanguage;
    protected $timeout;
    protected $proxy;
    protected $cookies;

    protected $incomingBody = '';

    public function __construct(string $method, string $url)
    {
        $this->url = $url;
        $this->method = $method;
    }

    public function setData($data): self
    {
        $this->data = $data;
        return $this;
    }

    public function setUserAgent($userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function setAcceptLanguage($acceptLanguage): self
    {
        $this->acceptLanguage = $acceptLanguage;
        return $this;
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function setProxy($proxy): self
    {
        $this->proxy = $proxy;
        return $this;
    }

    public function setCookies(array $cookies): self
    {
        $this->cookies = $cookies;
        return $this;
    }

    public function getCookies(): array
    {
        return $this->incomingCookies;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function send()
    {
        $options = [
            CURLOPT_URL => $this->url,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Accept-Language: {$this->acceptLanguage}",
            ],
        ];

        if (defined('PATH_TO_CERTIFICATES_FILE')) {
            $options[CURLOPT_CAINFO] = PATH_TO_CERTIFICATES_FILE;
        }

        if (isset($this->proxy)) {
            $options[CURLOPT_PROXY] = $this->proxy;
        }

        if ($this->method === 'POST') {
            $options[CURLOPT_POST] = true;
        }

        // only supports JSON data
        if (!empty($this->data)) {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER][] = 'Expect:';
            $options[CURLOPT_POSTFIELDS] = $data;
        }

        if (!empty($this->cookies)) {
            $options[CURLOPT_COOKIE] = http_build_query($this->cookies);
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        ob_start();
        $response = @curl_exec($ch);
        ob_end_clean();
        $header = $content = '';

        if (!empty($response)) {
            list($header, $content) = explode("\r\n\r\n", $response, 2);
        }

        $this->cookies = self::parseCookies(explode("\r\n", $header));
        $this->content = $content;
    }

    /**
     * Reads incoming tracking server cookies.
     *
     * @param $headers Array with HTTP response headers as values
     */
    private static function parseCookies(array $headers): array
    {
        $cookies = [];

        if (!empty($headers)) {
            $headerName = 'set-cookie:';
            $headerNameLength = strlen($headerName);

            foreach ($headers as $header) {
                if (strpos(strtolower($header), $headerName) !== 0) {
                    continue;
                }

                $cookies = trim(substr($header, $headerNameLength));
                $posEnd = strpos($cookies, ';');

                if ($posEnd !== false) {
                    $cookies = substr($cookies, 0, $posEnd);
                }

                parse_str($cookies, $cookies);
            }
        }

        return $cookies;
    }
}
