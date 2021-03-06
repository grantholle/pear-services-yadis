<?php

namespace Pear\Services\Yadis;

use DOMDocument;
use Pear\Http\Request2;
use Pear\Http\Request2\Exceptions\Request2Exception;
use Pear\Http\Request2\Response;
use Pear\Services\Yadis\Exceptions\YadisException;
use Pear\Services\Yadis\Xrds\XrdsNamespace;
use Pear\Services\Yadis\Xrds\XrdsService;
use SimpleXMLElement;

/**
 * Implementation of the Yadis Specification 1.0 protocol for service
 * discovery from an Identity URI/XRI or other.
 *
 * PHP version 5
 *
 * LICENSE:
 *
 * Copyright (c) 2007 Pádraic Brady <padraic.brady@yahoo.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *    * Redistributions of source code must retain the above copyright
 *      notice, this list of conditions and the following disclaimer.
 *    * Redistributions in binary form must reproduce the above copyright
 *      notice, this list of conditions and the following disclaimer in the
 *      documentation and/or other materials provided with the distribution.
 *    * The name of the author may not be used to endorse or promote products
 *      derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS
 * IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,
 * THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
 * PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR
 * CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
 * EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
 * PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
 * PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
 * OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @category Services
 * @package  Yadis
 * @author   Pádraic Brady <padraic.brady@yahoo.com>
 * @license  http://opensource.org/licenses/bsd-license.php New BSD License
 * @link     http://pear.php.net/Yadis
 */

/**
 * Yadis class
 *
 * Yadis will provide a method of Service Discovery implemented
 * in accordance with the Yadis Specification 1.0. This describes a protocol
 * for locating an XRD document which details Services available. The XRD is
 * typically specific to a single user, identified by their Yadis ID.
 * Yadis_XRDS will be a wrapper which is responsible for parsing
 * and presenting an iterable list of Yadis_Service objects
 * holding the data for each specific Service discovered.
 *
 * Note that class comments cannot substitute for a full understanding of the
 * rules and nuances required to implement the Yadis protocol. Where doubt
 * exists, refer to the Yadis Specification 1.0 at:
 *      http://yadis.org/papers/yadis-v1.0.pdf
 * Departures from the specification should be regarded as bugs ;).
 *
 * Example usage:
 *
 *      Example 1: OpenID Service Discovery
 *
 *      $openid = 'http://padraic.astrumfutura.com';
 *      $yadis = new Yadis($openid);
 *      $yadis->addNamespace('openid', 'http://openid.net/xmlns/1.0');
 *      $serviceList = $yadis->discover();
 *
 *      foreach ($serviceList as $service) {
 *          $types = $service->getTypes();
 *          echo $types[0], ' at ', implode(', ', $service->getUris()), PHP_EOL;
 *          echo 'Priority is ', $service->->getPriority(), PHP_EOL;
 *      }
 *
 *      Possible Result @index[0] (indicates we may send Auth 2.0 requests for
 *      OpenID):
 *
 *      http://specs.openid.net/auth/2.0/server at http://www.myopenid.com/server
 *      Priority is 0
 *
 * @category Services
 * @package  Yadis
 * @author   Pádraic Brady <padraic.brady@yahoo.com>
 * @license  http://opensource.org/licenses/bsd-license.php New BSD License
 * @link     http://pear.php.net/Yadis
 */
class Yadis
{

    /**
     * Constants referring to Yadis response types
     */
    const XRDS_META_HTTP_EQUIV = 2;
    const XRDS_LOCATION_HEADER = 4;
    const XRDS_CONTENT_TYPE    = 8;

    /**
     * The current Yadis ID; this is the raw form initially submitted prior
     * to any transformation/validation as an URL. This *may* allow IRI support
     * in the future given IRIs map to URIs and adoption of the IRI standard
     * and are entering common use internationally.
     *
     * @var string
     */
    protected $yadisId = '';

    /**
     * The current Yadis URL; this is a URL either validated or transformed
     * from the initial Yadis ID. This URL is used to make the initial HTTP
     * GET request during Service Discovery.
     *
     * @var string
     */
    protected $yadisUrl = '';

    /**
     * Holds the response received during Service Discovery.
     *
     * @var Response
     */
    protected $httpResponse = null;

    /**
     * A URL parsed from a HTML document's <meta> element inserted in
     * accordance with the Yadis Specification and which points to a Yadis
     * XRD document.
     *
     * @var string
     */
    protected $metaHttpEquivUrl = '';

    /**
     * A URI parsed from an X-XRDS-Location response-header. This value must
     * point to a Yadis XRD document otherwise the Yadis discovery process
     * should be considered to have failed.
     *
     * @var string
     */
    protected $xrdsLocationHeaderUrl = '';

    /**
     * Instance of XrdsNamespace for managing namespaces
     * associated with an XRDS document.
     *
     * @var XrdsNamespace
     */
    protected $namespace = null;

    /**
     * Array of valid HTML Content-Types. Required since Yadis states agents
     * must parse a document if received as the first response and with an
     * MIME type indicating HTML or XHTML. Listed in order of priority, with
     * HTML taking priority over XHTML.
     *
     * @link http://www.w3.org/International/articles/serving-xhtml/Overview.en.php
     * @var array
     */
    protected $validHtmlContentTypes = array(
        'text/html',
        'application/xhtml+xml',
        'application/xml',
        'text/xml'
    );

    /*
     * Array of characters which if found at the 0 index of a Yadis ID string
     * may indicate the use of an XRI.
     *
     * @var array
     */
    protected $xriIdentifiers = array(
        '=', '$', '!', '@', '+'
    );

    protected $httpRequestOptions = array();

    /**
     * Request2 object utilised by this class if externally set
     *
     * @var Request2
     */
    protected $httpRequest = null;

    /**
     * Class Constructor
     *
     * Allows settings of the initial Yadis ID (an OpenID URL for example) and
     * an optional list of additional namespaces. For example, OpenID uses a
     * namespace such as: xmlns:openid="http://openid.net/xmlns/1.0"
     * Namespaces are assigned to a XrdsNamespace container
     * object to be passed more easily to other objects being
     *
     * @param string|null $yadisId Optional Yadis ID
     * @param array $namespaces Optional array of namespaces
     * @throws YadisException
     */
    public function __construct(string $yadisId = null, array $namespaces = array())
    {
        $this->namespace = new XrdsNamespace;
        $this->addNamespaces($namespaces);

        if (isset($yadisId)) {
            $this->setYadisId($yadisId);
        }
    }

    /**
     * Set options to be passed to the PEAR Request2 constructor
     *
     * @param array $options Array of options for HTTP_Request
     * @return Yadis
     */
    public function setHttpRequestOptions(array $options)
    {
        $this->httpRequestOptions = $options;

        return $this;
    }

    /**
     * Get options to be passed to the PEAR Request2 constructor
     *
     * @return array
     */
    public function getHttpRequestOptions()
    {
        return $this->httpRequestOptions;
    }

    /**
     * A Yadis ID is usually an URL, but can also include an IRI, or XRI i-name.
     * The initial version will support URLs as standard before examining options
     * for supporting alternatives (IRI,XRI,i-name) since they require additional
     * validation and conversion steps (e.g. Punycode for IRI) before use.
     *
     * Note: The current Validate classes currently do not have complete IDNA
     * validation support for Internationalised Domain Names. To be addressed.
     *
     * @param string $yadisId The Yadis ID
     * @return void
     * @throws YadisException
     */
    public function setYadisId($yadisId)
    {
        $this->yadisId = $yadisId;
        $this->setYadisUrl($yadisId);
    }

    /**
     * Returns the original Yadis ID string set for this class.
     *
     * @return string
     * @throws YadisException
     */
    public function getYadisId()
    {
        if (!isset($this->yadisId)) {
            throw new YadisException(
                'No Yadis ID has been set on this object yet'
            );
        }

        return $this->yadisId;
    }

    /**
     * Attempts to create a valid URI based on the value of the parameter
     * which would typically be the Yadis ID.
     * Note: This currently only supports XRI transformations.
     *
     * @param string $yadisId The Yadis ID
     * @return Yadis
     * @throws YadisException
     */
    public function setYadisUrl($yadisId)
    {
        /**
         * This step should validate IDNs (see ZF-881)
         */
        if (self::validateURI($yadisId)) {
            $this->yadisUrl = $yadisId;
            return $this;
        }

        /**
         * Check if the Yadis ID is an XRI
         */
        if (stripos($yadisId, 'xri://') === 0 ||
            in_array($yadisId[0], $this->xriIdentifiers)) {

            $xri = Xri::getInstance();

            $xri->setHttpRequestOptions($this->getHttpRequestOptions());
            $this->yadisUrl = $xri->setNamespace($this->namespace)->toUri($yadisId);

            return $this;
        }

        /**
         * The use of IRIs (International Resource Identifiers) is governed by
         * RFC 3490-3495. Not yet available for validation in PEAR.
         */
        throw new YadisException(
            'Unable to validate a Yadis ID as a URI, '
            . 'or to transform a Yadis ID into a valid URI.'
        );
    }

    /**
     * Returns the Yadis URL. This will usually be identical to the Yadis ID,
     * unless the Yadis ID (in the future) was one of IRI, XRI or i-name which
     * required transformation to a valid URI.
     *
     * @return string
     * @throws YadisException
     */
    public function getYadisUrl()
    {
        if (!isset($this->yadisUrl)) {
            throw new YadisException(
                'No Yadis ID/URL has been set on this object yet'
            );
        }

        return $this->yadisUrl;
    }

    /**
     * Add a list (array) of additional namespaces to be utilised by the XML
     * parser when it receives a valid XRD document.
     *
     * @param array $namespaces Array of namespaces
     * @return  Yadis
     * @throws YadisException
     */
    public function addNamespaces(array $namespaces)
    {
        $this->namespace->addNamespaces($namespaces);

        return $this;
    }

    /**
     * Add a single namespace to be utilised by the XML parser when it receives
     * a valid XRD document.
     *
     * @param string $namespace The namespace name
     * @param string $namespaceUrl The namespace url
     * @return Yadis
     * @throws YadisException
     */
    public function addNamespace(string $namespace, string $namespaceUrl)
    {
        $this->namespace->addNamespace($namespace, $namespaceUrl);

        return $this;
    }

    /**
     * Return the value of a specific namespace.
     *
     * @param string $namespace Namespace name
     *
     * @return string|null
     */
    public function getNamespace($namespace)
    {
        return $this->namespace->getNamespace($namespace);
    }

    /**
     * Returns an array of all currently set namespaces.
     *
     * @return array
     */
    public function getNamespaces()
    {
        return $this->namespace->getNamespaces();
    }

    /**
     * Performs Service Discovery, i.e. the requesting and parsing of a valid
     * Yadis (XRD) document into a list of Services and Service Data. The
     * return value will be an instance of Yadis_Xrds which will
     * implement SeekableIterator. Returns FALSE on failure.
     *
     * @return XrdsService
     * @throws Request2Exception
     * @throws Request2\Exceptions\Exception
     * @throws Request2\Exceptions\LogicException
     * @throws YadisException
     */
    public function discover()
    {
        $currentUri   = $this->getYadisUrl();
        $xrdsDocument = null;
        $request      = null;
        $xrdStatus    = false;

        // Check XRI first
        if (in_array($this->yadisId[0], $this->xriIdentifiers)) {
            $xri                = Xri::getInstance();
            $xrds               = $xri->toCanonicalID($xri->getXri());
            $this->httpResponse = $xri->getHTTPResponse();

            return new XrdsService($xrds, $this->namespace);
        }

        while ($xrdsDocument === null) {
            $this->httpResponse = $this->get($currentUri);
            $responseType = $this->getResponseType($this->httpResponse);

            /**
             * If prior response type was a location header, or a http-equiv
             * content value, then it should have contained a valid URI to
             * an XRD document. Each of these when detected would set the
             * xrdStatus flag to true.
             */
            if (!$responseType == self::XRDS_CONTENT_TYPE && $xrdStatus == true) {
                throw new YadisException(
                    'Yadis protocol could not locate a valid XRD document'
                );
            }

            /**
             * The Yadis Spec 1.0 specifies that we must use a valid response
             * header in preference to other responses. So even if we receive
             * an XRDS Content-Type, if it also includes an X-XRDS-Location
             * header we must request the Location URI and ignore the response
             * body.
             */
            switch($responseType) {
            case self::XRDS_LOCATION_HEADER:
                $xrdStatus  = true;
                $currentUri = $this->xrdsLocationHeaderUrl;
                break;
            case self::XRDS_META_HTTP_EQUIV:
                $xrdStatus  = true;
                $currentUri = $this->metaHttpEquivUrl;
                break;
            case self::XRDS_CONTENT_TYPE:
                $xrdsDocument = $this->httpResponse->getBody();
                break;
            default:
                throw new YadisException(
                    'Yadis protocol could not locate a valid XRD document'
                );
            }
        }

        try {
            $serviceList = $this->parseXrds($xrdsDocument);
        } catch (\Exception $e) {
            throw new YadisException(
                'XRD Document could not be parsed with the following message: '
                . $e->getMessage(), $e->getCode());
        }

        return $serviceList;
    }

    /**
     * Return the final HTTP response
     *
     * @return Response
     */
    public function getHTTPResponse()
    {
        if ($this->httpResponse instanceof Response) {
            return $this->httpResponse;
        }
        return null;
    }

    /**
     * Setter for custom Request2 type object
     *
     * @param Request2 $request Instance of Request2
     *
     * @return void
     */
    public function setHttpRequest(Request2 $request)
    {
        $this->httpRequest = $request;
    }

    /**
     * Gets the Request2 object
     *
     * @return Request2
     * @throws Request2\Exceptions\LogicException
     */
    public function getHttpRequest()
    {
        if ($this->httpRequest === null) {
            $this->httpRequest = new Request2();
            $this->httpRequest->setConfig($this->getHttpRequestOptions());
        }
        return $this->httpRequest;
    }

    /**
     * Run any instance of Response through a set of filters to
     * determine the Yadis Response type which in turns determines how the
     * response should be reacted to or dealt with.
     *
     * @param Response $response
     * @return integer
     * @throws Request2Exception
     * @throws YadisException
     */
    protected function getResponseType(Response $response)
    {
        if ($this->isXrdsContentType($response)) {
            return self::XRDS_CONTENT_TYPE;
        } elseif ($this->isXrdsLocationHeader($response)) {
            return self::XRDS_LOCATION_HEADER;
        } elseif ($this->isMetaHttpEquiv($response)) {
            return self::XRDS_META_HTTP_EQUIV;
        }
        return false;
    }

    /**
     * Use the Request2 to issue an HTTP GET request carrying the
     * "Accept" header value of "application/xrds+xml". This can allow
     * servers to quickly respond with a valid XRD document rather than
     * forcing the client to follow the X-XRDS-Location bread crumb trail.
     *
     * @param string $url URL
     * @return Response
     * @throws Request2\Exceptions\Exception
     * @throws Request2\Exceptions\LogicException
     * @throws YadisException
     */
    protected function get($url)
    {
        $request = $this->getHttpRequest();
        $request->setUrl($url);
        $request->setMethod(Request2::METHOD_GET);
        $request->setHeader('Accept', 'application/xrds+xml');

        try {
            return $request->send();
        } catch (Request2Exception $e) {
            throw new YadisException(
                'Invalid response to Yadis protocol received: ' . $e->getMessage(),
                $e->getCode()
            );
        }
    }

    /**
     * Checks whether the Response contains headers which detail where
     * we can find the XRDS resource for this user. If exists, the value
     * is set to the private $xrdsLocationHeaderUrl property.
     *
     * @param Response $response Instance of Response
     * @return boolean
     * @throws YadisException
     */
    protected function isXrdsLocationHeader(Response $response)
    {
        if ($response->getHeader('x-xrds-location')) {
            $location = $response->getHeader('x-xrds-location');
        } elseif ($response->getHeader('x-yadis-location')) {
            $location = $response->getHeader('x-yadis-location');
        }
        if (empty($location)) {
            return false;
        } elseif (!self::validateURI($location)) {
            throw new YadisException(
                'Invalid URI found during Discovery for location of XRDS document:'
                . htmlentities($location, ENT_QUOTES, 'utf-8')
            );
        }
        $this->xrdsLocationHeaderUrl = $location;
        return true;
    }

    /**
     * Checks whether the Response contains the XRDS resource. It should, per
     * the specifications always be served as application/xrds+xml
     *
     * @param Response $response Instance of Response
     *
     * @return boolean
     */
    protected function isXrdsContentType(Response $response)
    {
        if (!$response->getHeader('Content-Type')
            || stripos($response->getHeader('Content-Type'),
                       'application/xrds+xml') === false) {

            return false;
        }
        return true;
    }

    /**
     * Assuming this user is hosting a third party sourced identity under an
     * alias personal URL, we'll need to check if the website's HTML body
     * has a http-equiv meta element with a content attribute pointing to where
     * we can fetch the XRD document.
     *
     * @param Response $response Instance of Response
     *
     * @return boolean
     * @throws YadisException|\Pear\Http\Request2\Exceptions\Request2Exception
     */
    protected function isMetaHttpEquiv(Response $response)
    {
        $location = null;
        if (!in_array($response->getHeader('Content-Type'),
                      $this->validHtmlContentTypes)) {

            return false;
        }

        /**
         * Find a match for a relevant <meta> element, then iterate through the
         * results to see if a valid http-equiv value and matching content URI
         * exist.
         */
        $html = new DOMDocument();
        $html->loadHTML($response->getBody());
        $head = $html->getElementsByTagName('head');
        if ($head->length > 0) {
            $metas = $head->item(0)->getElementsByTagName('meta');
            if ($metas->length > 0) {
                foreach ($metas as $meta) {
                    $equiv = strtolower($meta->getAttribute('http-equiv'));
                    if ($equiv == 'x-xrds-location'
                        || $equiv == 'x-yadis-location') {

                        $location = $meta->getAttribute('content');
                    }
                }
            }
        }

        if (is_null($location)) {
            return false;
        } elseif (!self::validateURI($location)) {
            throw new YadisException(
                'The URI parsed from the HTML Alias document appears to be invalid, '
                . 'or could not be found: '
                . htmlentities($location, ENT_QUOTES, 'utf-8')
            );
        }
        /**
         * Should now contain the content value of the http-equiv type pointing
         * to an XRDS resource for the user's Identity Provider, as found by
         * passing the meta regex across the response body.
         */
        $this->metaHttpEquivUrl = $location;
        return true;
    }

    /**
     * Creates a new Yadis_Xrds object which uses SimpleXML to
     * parse the XML into a list of Iterable Yadis_Service
     * objects.
     *
     * @param string $xrdsDocument The plaintext XRDS document
     * @return XrdsService
     * @throws YadisException
     */
    protected function parseXrds($xrdsDocument)
    {
        $xrds = new SimpleXMLElement($xrdsDocument);

        return new XrdsService($xrds, $this->namespace);
    }

    public static function validateURI(string $url, $options = null): bool
    {
//        $strict = ';/?:@$,';
        $strict = '#[' . preg_quote(';/?:@$,', '#') . ']#';
        $domain_check = false;
        $allowed_schemes = null;

        if (is_array($options)) {
            extract($options);
        }

        if (
            preg_match(
             '&^(?:([a-z][-+.a-z0-9]*):)?                             # 1. scheme
              (?://                                                   # authority start
              (?:((?:%[0-9a-f]{2}|[-a-z0-9_.!~*\'();:\&=+$,])*)@)?    # 2. authority-userinfo
              (?:((?:[a-z0-9](?:[-a-z0-9]*[a-z0-9])?\.)*[a-z](?:[a-z0-9]+)?\.?)  # 3. authority-hostname OR
              |([0-9]{1,3}(?:\.[0-9]{1,3}){3}))                       # 4. authority-ipv4
              (?::([0-9]*))?)                                        # 5. authority-port
              ((?:/(?:%[0-9a-f]{2}|[-a-z0-9_.!~*\'():@\&=+$,;])*)*/?)? # 6. path
              (?:\?([^#]*))?                                          # 7. query
              (?:\#((?:%[0-9a-f]{2}|[-a-z0-9_.!~*\'();/?:@\&=+$,])*))? # 8. fragment
              $&xi', $url, $matches) !== 1
        ) {
            return false;
        }

        $scheme = isset($matches[1]) ? $matches[1] : '';
        $authority = isset($matches[3]) ? $matches[3] : '' ;
        if (
            is_array($allowed_schemes) &&
            !in_array($scheme, $allowed_schemes)
        ) {
            return false;
        }

        if (!empty($matches[4])) {
            $parts = explode('.', $matches[4]);
            foreach ($parts as $part) {
                if ($part > 255) {
                    return false;
                }
            }
        } elseif ($domain_check && function_exists('checkdnsrr')) {
            if (!checkdnsrr($authority, 'A')) {
                return false;
            }
        }

        return !(
            (!empty($matches[7]) && preg_match($strict, $matches[7])) ||
            (!empty($matches[8]) && preg_match($strict, $matches[8]))
        );
    }
}
