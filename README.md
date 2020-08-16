# Yadis

Implementation of the [Yadis 1.0 protocol](http://archive.cweiske.de/yadis/yadis-v1.0.html).

## Installation

```bash
composer require grantholle/pear-services-yadis
```

## Usage

```php
use Pear\Services\Yadis\Yadis;

$openid = 'http://padraic.astrumfutura.com';
$yadis = new Yadis($openid);
$yadis->addNamespace('openid', 'http://openid.net/xmlns/1.0');
$serviceList = $yadis->discover();

foreach ($serviceList as $service) {
    $types = $service->getTypes();
    echo $types[0], ' at ', implode(', ', $service->getUris()), PHP_EOL;
    echo 'Priority is ', $service->getPriority(), PHP_EOL;
}
```
