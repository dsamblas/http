<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Url;
use Guzzle\Parser\ParserRegistry;

/**
 * Default HTTP request factory used to create the default {@see Request} and {@see EntityEnclosingRequest} objects.
 */
class RequestFactory implements RequestFactoryInterface
{
    /**
     * @var RequestFactory Singleton instance of the default request factory
     */
    protected static $instance;

    /**
     * @var string Class to instantiate for requests with no body
     */
    protected $requestClass = 'Guzzle\\Http\\Message\\Request';

    /**
     * @var string Class to instantiate for requests with a body
     */
    protected $entityEnclosingRequestClass = 'Guzzle\\Http\\Message\\EntityEnclosingRequest';

    /**
     * Get a cached instance of the default request factory
     *
     * @return RequestFactory
     */
    public static function getInstance()
    {
        // @codeCoverageIgnoreStart
        if (!static::$instance) {
            static::$instance = new static();
        }
        // @codeCoverageIgnoreEnd

        return static::$instance;
    }

    /**
     * {@inheritdoc}
     */
    public function fromMessage($message)
    {
        $parsed = ParserRegistry::getInstance()->getParser('message')->parseRequest($message);

        if (!$parsed) {
            return false;
        }

        $request = $this->fromParts($parsed['method'], $parsed['request_url'],
            $parsed['headers'], $parsed['body'], $parsed['protocol'],
            $parsed['version']);

        // EntityEnclosingRequest adds an "Expect: 100-Continue" header when using a raw request body for PUT or POST
        // requests. This factory method should accurately reflect the message, so here we are removing the Expect
        // header if one was not supplied in the message.
        if (!isset($parsed['headers']['Expect']) && !isset($parsed['headers']['expect'])) {
            $request->removeHeader('Expect');
        }

        return $request;
    }

    /**
     * {@inheritdoc}
     */
    public function fromParts(
        $method,
        array $urlParts,
        $headers = null,
        $body = null,
        $protocol = 'HTTP',
        $protocolVersion = '1.1'
    ) {
        return $this->create($method, Url::buildUrl($urlParts, true), $headers, $body)
                    ->setProtocolVersion($protocolVersion);
    }

    /**
     * {@inheritdoc}
     */
    public function create($method, $url, $headers = null, $body = null)
    {
        $method = strtoupper($method);

        if ($method == 'GET' || $method == 'HEAD' || $method == 'TRACE' || $method == 'OPTIONS') {
            // Handle non-entity-enclosing request methods
            $request = new $this->requestClass($method, $url, $headers);
            if ($body) {
                // The body is where the response body will be stored
                $request->setResponseBody($body);
            }
            return $request;
        }

        // Create an entity enclosing request by default
        $request = new $this->entityEnclosingRequestClass($method, $url, $headers);

        if ($body) {
            // Add POST fields and files to an entity enclosing request if an array is used
            if (is_array($body) || $body instanceof Collection) {
                // Normalize PHP style cURL uploads with a leading '@' symbol
                foreach ($body as $key => $value) {
                    if (is_string($value) && substr($value, 0, 1) == '@') {
                        $request->addPostFile($key, $value);
                        unset($body[$key]);
                    }
                }
                // Add the fields if they are still present and not all files
                $request->addPostFields($body);
            } else {
                // Add a raw entity body body to the request
                $request->setBody(
                    $body,
                    (string) $request->getHeader('Content-Type'),
                    (string) $request->getHeader('Transfer-Encoding') == 'chunked'
                );
            }
        }

        return $request;
    }
}
