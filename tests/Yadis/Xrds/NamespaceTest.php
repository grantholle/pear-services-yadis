<?php

namespace Tests\Yadis\Xrds;

use Pear\Services\Yadis\Xrds\XrdsNamespace;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class NamespaceTest extends TestCase
{
    public function testInitialState()
    {
        $name = new XrdsNamespace();
        $this->assertEquals(array('xrds' => 'xri://$xrds','xrd' => 'xri://$xrd*($v*2.0)'), $name->getNamespaces());
    }

    public function testAddNamespace()
    {
        $name = new XrdsNamespace;
        $name->addNamespace('test', 'http://example.com/test');
        $this->assertEquals('http://example.com/test', $name->getNamespace('test'));
    }

    public function testAddNamespaces()
    {
        $initial = array(
            'xrds' => 'xri://$xrds',
            'xrd' => 'xri://$xrd*($v*2.0)'
        );
        $spaces = array(
           'test'=>'http://example.com/test',
           'test2'=>'http://example.com/test'
        );
        $name = new XrdsNamespace;
        $name->addNamespaces($spaces);
        $this->assertEquals($initial + $spaces, $name->getNamespaces());
    }

    // tests that if provider changes namespaces, our code's XPath can still
    // substitute the prior prefix
    public function testRegisterXpathNamespaces()
    {
        $string = <<<XML
<a xmlns:t2="http://example.com/t">
 <b>
  <t2:c>text</t2:c>
 </b>
</a>
XML;
        $xml = new SimpleXMLElement($string);
        $name = new XrdsNamespace;
        $name->addNamespace('t', 'http://example.com/t');
        $name->registerXpathNamespaces($xml);
        $c = $xml->xpath('//t:c');
        $this->assertEquals('text', (string) $c[0]);
    }

}