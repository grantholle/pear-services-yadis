<?php

namespace Tests\Yadis;

use PHPUnit\Framework\TestCase;

class XrdsTest extends TestCase
{
    protected $_namespace = null;

    public function setUp(): void
    {
        $this->_namespace = $this->getMock('Services_Yadis_Xrds_Namespace');
    }
}