<?php

namespace Pear\Services\Yadis\Xrds;

use Pear\Services\Yadis\Exceptions\YadisException;
use SimpleXMLElement;

class Xrds
{
    /**
     * @var int
     */
    protected $currentKey = 0;

    /**
     * @var SimpleXMLElement
     */
    protected $xrdNodes = null;

    /**
     * @var XrdsNamespace
     */
    protected $namespace = null;

    /**
     * Xrds constructor.
     *
     * @param SimpleXMLElement $xrds
     * @param XrdsNamespace $namespace
     * @throws YadisException
     */
    public function __construct(SimpleXMLElement $xrds, XrdsNamespace $namespace)
    {
        $this->namespace = $namespace;
        $xrdNodes = $this->getValidXrdsNodes($xrds);

        if (!$xrdNodes) {
            throw new YadisException('The XRD document was found to be invalid');
        }

        $this->xrdNodes = $xrdNodes;
    }

    /**
     * @param array $unsorted
     * @return array
     */
    public static function sortByPriority(array $unsorted)
    {
        ksort($unsorted);
        $flattened = array();
        foreach ($unsorted as $priority) {
            if (count($priority) > 1) {
                shuffle($priority);
                $flattened = array_merge($flattened, $priority);
            } else {
                $flattened[] = $priority[0];
            }
        }
        return $flattened;
    }

    /**
     * Add a list (array) of additional namespaces to be utilised by the XML
     * parser when it receives a valid XRD document.
     *
     * @param array $namespaces
     * @return Xrds
     * @throws \Pear\Services\Yadis\Exceptions\YadisException
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
     * @param string $namespace
     * @param string $namespaceUrl
     * @return Xrds
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
     * @param string $namespace
     * @return string|boolean
     */
    public function getNamespace(string $namespace)
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

    protected function getValidXrdsNodes(SimpleXMLElement $xrds)
    {
        $this->namespace->registerXpathNamespaces($xrds);

        $root = $xrds->xpath('/xrds:XRDS[1]');

        if (empty($root)) {
            return null;
        }

        /**
         * Check namespace urls of standard xmlns (no suffix) or xmlns:xrd
         * (if present and of priority) for validity.
         * No loss if neither exists, but they really should be.
         */
        $namespaces = $xrds->getDocNamespaces();

        if (array_key_exists('xrd', $namespaces) && $namespaces['xrd'] != 'xri://$xrd*($v*2.0)') {
            return null;
        }

        if (array_key_exists('', $namespaces) && $namespaces[''] != 'xri://$xrd*($v*2.0)') {
            // Hack for the namespace declaration in the XRD node, which SimpleXML misses
            $xrdHack = false;
            if (!isset($xrds->XRD)) {
                return null;
            }

            foreach ($xrds->XRD as $xrd) {
                $namespaces = $xrd->getNamespaces();
                if (array_key_exists('', $namespaces)
                    && $namespaces[''] == 'xri://$xrd*($v*2.0)') {

                    $xrdHack = true;
                    break;
                }
            }

            if (!$xrdHack) {
                return null;
            }
        }

        /**
         * Grab the XRD elements which contains details of the service provider's
         * Server url, service types, and other details. Concrete subclass may
         * have additional requirements concerning node priority or valid position
         * in relation to other nodes. E.g. Yadis requires only using the *last*
         * node.
         */
        $xrdNodes = $xrds->xpath('/xrds:XRDS[1]/xrd:XRD');

        if (!$xrdNodes) {
            return null;
        }

        return $xrdNodes;
    }
}