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
    protected $timeout = 1;
    protected $cookies = [];
    protected $headers = [];
    protected $content = '';

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

    public function addHeader(string $header): self
    {
        $this->headers[] = $header;

        return $this;
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function setCookies(array $cookies): self
    {
        $this->cookies = $cookies;

        return $this;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function send()
    {
        $options = [
            CURLOPT_URL => $this->url,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->headers,
        ];

        if ($this->method === 'POST') {
            $options[CURLOPT_POST] = true;
        }

        if (!empty($this->data)) {
            $options[CURLOPT_POSTFIELDS] = $this->data;
        }

        if (!empty($this->cookies)) {
            $options[CURLOPT_COOKIE] = http_build_query($this->cookies);
        }

        $connection = curl_init();
        curl_setopt_array($connection, $options);

        ob_start();
        $response = @curl_exec($connection);
        curl_close($connection);
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
     */
    private static function parseCookies(array $headers): array
    {
        $parsedCookies = [];

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

                parse_str($cookies, $parsedCookies);
            }
        }

        return $parsedCookies;
    }
}
