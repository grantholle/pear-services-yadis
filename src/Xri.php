<?php

namespace Pear\Services\Yadis;

use Pear\Http\Request2;
use Pear\Http\Request2\Exceptions\Request2Exception;
use Pear\Http\Request2\Response;
use Pear\Net\Url2;
use Pear\Services\Yadis\Exceptions\YadisException;
use Pear\Services\Yadis\Xrds\XrdsNamespace;
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
 * @package  Services_Yadis
 * @author   Pádraic Brady <padraic.brady@yahoo.com>
 * @license  http://opensource.org/licenses/bsd-license.php New BSD License
 * @link     http://pear.php.net/package/services_yadis
 */

/**
 * Provides methods for translating an XRI into a URI.
 *
 * @category Services
 * @package  Services_Yadis
 * @author   Pádraic Brady <padraic.brady@yahoo.com>
 * @license  http://opensource.org/licenses/bsd-license.php New BSD License
 * @link     http://pear.php.net/package/services_yadis
 */
class Xri
{

    /**
     * Hold an instance of this object per the Singleton Pattern.
     *
     * @var Xri
     */
    protected static $instance = null;

    /*
     * Array of characters which if found at the 0 index of a Yadis ID string
     * may indicate the use of an XRI.
     *
     * @var array
     */
    protected $xriIdentifiers = array(
        '=', '$', '!', '@', '+'
    );

    /**
     * Default proxy to append XRI identifier to when forming a valid URI.
     *
     * @var string
     */
    protected $proxy = 'http://xri.net/';

    /**
     * Instance of XrdsNamespace for managing namespaces
     * associated with an XRDS document.
     *
     * @var XrdsNamespace
     */
    protected $namespace = null;

    /**
     * The XRI string.
     *
     * @var string
     */
    protected $xri = null;

    /**
     * The URI as translated from an XRI and appended to a Proxy.
     *
     * @var string
     */
    protected $uri = null;

    /**
     * A Canonical ID if requested, and parsed from the XRDS document found
     * by requesting the URI created from a valid XRI.
     *
     * @var string
     */
    protected $canonicalID = null;

    protected $httpRequestOptions = array();

    /**
     * Stores an array of previously performed requests.  The array key is a
     * combination of the url, service type, and http request options.
     *
     * @see get()
     * @var array
     */
    protected $requests = array();

    /**
     * The last response using Request2
     *
     * @var Response
     */
    protected $httpResponse = null;

    /**
     * @var string
     */
    private $_serviceType;

    /**
     * Constructor; protected since this class is a singleton.
     */
    protected function __construct()
    {
    }

    /**
     * Return a singleton instance of this class.
     *
     * @return  Xri
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * Set a Namespace object which contains all relevant namespaces
     * for XPath queries on this Yadis resource.
     *
     * @param XrdsNamespace $namespace Instance
     *
     * @return Xri
     */
    public function setNamespace(XrdsNamespace $namespace)
    {
        $this->namespace = $namespace;
        return $this;
    }

    /**
     * Set an XRI proxy URI. A default of "http://xri.net/" is available.
     *
     * @param string $proxy The Proxy server URI
     *
     * @return Xri
     * @throws YadisException
     */
    public function setProxy($proxy)
    {
        if (!Yadis::validateURI($proxy)) {
            throw new YadisException(
                'Invalid URI; unable to set as an XRI proxy'
            );
        }

        $this->proxy = $proxy;
        return $this;
    }

    /**
     * Return the URI of the current proxy.
     *
     * @return string
     */
    public function getProxy()
    {
        return $this->proxy;
    }

    /**
     * Set an XRI to be translated to a URI.
     *
     * @param string $xri XRI to be translated
     *
     * @return Xri
     * @throws YadisException
     */
    public function setXri($xri)
    {
        /**
         * Check if the passed string is a likely XRI.
         */
        if (stripos($xri, 'xri://') === false
            && !in_array($xri[0], $this->xriIdentifiers)) {

            throw new YadisException('Invalid XRI string submitted');
        }
        $this->xri = $xri;
        return $this;
    }

    /**
     * Return the original XRI string.
     *
     * @return string
     */
    public function getXri()
    {
        return $this->xri;
    }

    /**
     * Attempts to convert an XRI into a URI. In simple terms this involves
     * removing the "xri://" prefix and appending the remainder to the URI of
     * an XRI proxy such as "http://xri.net/".
     *
     * @param string|null $xri XRI
     * @param string|null $serviceType The Service Type
     * @return string
     * @throws YadisException
     * @uses Validate
     */
    public function toUri(string $xri = null, string $serviceType = null)
    {
        if (!is_null($serviceType)) {
            $this->_serviceType = (string) $serviceType;
        }
        if (isset($xri)) {
            $this->setXri($xri);
        }

        /**
         * Get rid of the xri:// prefix before assembling the URI
         * including any IP or DNS wildcards
         */
        if (stripos($this->xri, 'xri://') === 0) {
            if (stripos($this->xri, 'xri://$ip*') === 0) {
                $iname = substr($xri, 10);
            } elseif (stripos($this->xri, 'xri://$dns*') === 0) {
                $iname = substr($xri, 11);
            } else {
                $iname = substr($xri, 6);
            }
        } else {
            $iname = $xri;
        }

        $uri = $this->getProxy() . $iname;

        if (!Yadis::validateURI($uri)) {
            throw new YadisException(
                'Unable to translate XRI to a valid URI using proxy: '
                . $this->getProxy()
            );
        }

        $this->uri = $uri;

        return $uri;
    }

    /**
     * Based on an XRI, will request the XRD document located at the proxy
     * prefixed URI and parse in search of the XRI Canonical Id. This is
     * a flexible requirement. OpenID 2.0 requires the use of the Canonical
     * ID instead of the raw i-name. 2idi.com, on the other hand, does not.
     *
     * @param string|null $xri The XRI
     * @return string
     * @throws Request2Exception
     * @throws Request2\Exceptions\LogicException
     * @throws YadisException
     * @todo Imcomplete; requires interface from Yadis main class
     */
    public function toCanonicalId(string $xri = null)
    {
        if (!isset($xri) && !isset($this->uri)) {
            throw new YadisException(
                'No XRI passed as parameter as required unless called after '
                . 'Xri:toUri'
            );
        } elseif (isset($xri)) {
            $uri = $this->toUri($xri);
        } else {
            $uri = $this->uri;
        }

        $this->httpResponse = $this->get($uri);
        if (
            stripos(
                $this->httpResponse->getHeader('Content-Type'),
                'application/xrds+xml'
            ) === false
        ) {
            throw new YadisException(
                'The response header indicates the response body is not '
                . 'an XRDS document'
            );
        }

        $xrds = new SimpleXMLElement($this->httpResponse->getBody());
        $this->namespace->registerXpathNamespaces($xrds);
        $id = $xrds->xpath('//xrd:CanonicalID[last()]');
        $this->canonicalID = (string)array_shift($id);

        if (!$this->canonicalID) {
            throw new YadisException(
                'Unable to determine canonicalID'
            );
        }

        return $xrds;
    }

    /**
     * Gets the Canonical ID
     *
     * @return string
     * @throws Request2Exception
     * @throws Request2\Exceptions\LogicException
     * @throws YadisException if the XRI is null
     */
    public function getCanonicalId()
    {
        if ($this->canonicalID !== null) {
            return $this->canonicalID;
        }
        if ($this->xri === null) {
            throw new YadisException(
                'Unable to get a Canonical Id since no XRI value has been set'
            );
        }

        $this->toCanonicalId($this->xri);

        return $this->canonicalID;
    }

    /**
     * Set options to be passed to the PEAR Request2 constructor
     *
     * @param array $options Array of Request2 options
     * @return Xri
     */
    public function setHttpRequestOptions(array $options = [])
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
     * Required to request the root i-name (XRI) XRD which will provide an
     * error message that the i-name does not exist, or else return a valid
     * XRD document containing the i-name's Canonical ID.
     *
     * @param string $url URI
     * @param string|null $serviceType Optional service type
     * @return Response
     * @throws Request2\Exceptions\LogicException
     * @throws YadisException
     * @todo   Finish this a bit better using the QXRI rules.
     */
    protected function get(string $url, string $serviceType = null)
    {
        $request = new Request2(
            $url,
            Request2::METHOD_GET,
            $this->getHttpRequestOptions()
        );

        $netURL = new Url2($url);
        $request->setHeader('Accept', 'application/xrds+xml');

        if ($serviceType) {
            $netURL->setQueryVariable('_xrd_r', 'application/xrds+xml');
            $netURL->setQueryVariable('_xrd_t', $serviceType);
        } else {
            $netURL->setQueryVariable('_xrd_r', 'application/xrds+xml;sep=false');
        }

        $request->setURL($netURL->getURL());

        try {
            return $request->send();
        } catch (Request2\Exceptions\Exception $e) {
            throw new YadisException(
                'Invalid response to Yadis protocol received: ' . $e->getMessage(),
                $e->getCode()
            );
        }
    }

    /**
     * Returns the most recent Response object.
     *
     * @return Response|null
     */
    public function getHTTPResponse()
    {
        return $this->httpResponse;
    }
}
