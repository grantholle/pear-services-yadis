<?php

namespace Tests;

use Pear\Http\Request2;
use Pear\Http\Request2\Adapters\Mock;
use Pear\Http\Request2\Exceptions\Request2Exception;
use Pear\Services\Yadis\Exceptions\YadisException;
use Pear\Services\Yadis\Yadis;
use PHPUnit\Framework\TestCase;

class YadisTest extends TestCase
{
    public function testGetException()
    {
        $this->expectException(YadisException::class);
        $this->expectExceptionMessage('Invalid response to Yadis protocol received: A test error');
        $httpMock = new Mock();
        $httpMock->addResponse(
            new Request2Exception('A test error', 500)
        );

        $http = new Request2();
        $http->setAdapter($httpMock);

        $sy = new Yadis('http://example.org/openid');
        $sy->setHttpRequest($http);
        $sy->discover();
    }
}
