<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$routing = file_get_contents($root . '/piwigo_display.routing.yml');
$formatter = file_get_contents($root . '/src/Plugin/Field/FieldFormatter/PiwigoImageFormatter.php');
$controller = file_get_contents($root . '/src/Controller/DerivativeController.php');

function expect_frontend_proxy(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
  }
}

expect_frontend_proxy(is_string($routing), 'Unable to read routing definition.');
expect_frontend_proxy(is_string($formatter), 'Unable to read Piwigo formatter.');
expect_frontend_proxy(is_string($controller), 'Unable to read derivative controller.');

expect_frontend_proxy(str_contains($routing, 'piwigo_display.derivative:'), 'Protected derivative route is missing.');
expect_frontend_proxy(str_contains($routing, "_entity_access: 'media.view'"), 'Protected derivative route must use Media view access.');
expect_frontend_proxy(str_contains($routing, "type: 'entity:media'"), 'Protected derivative route must upcast the Media entity.');

expect_frontend_proxy(str_contains($formatter, '$this->piwigoClient->usesAuthentication()'), 'Formatter must distinguish authenticated Piwigo connections.');
expect_frontend_proxy(str_contains($formatter, "Url::fromRoute('piwigo_display.derivative'"), 'Authenticated frontend images must use the protected Drupal route.');
expect_frontend_proxy(str_contains($formatter, '$this->piwigoClient->getDerivativeUrl($image, $derivative)'), 'Public Piwigo images must retain direct derivative rendering.');

expect_frontend_proxy(str_contains($controller, "getPluginId() !== 'piwigo_image'"), 'Proxy must reject Media entities from other sources.');
expect_frontend_proxy(str_contains($controller, '$source->getSourceFieldValue($media)'), 'Proxy must resolve the Piwigo ID from the Media source field.');
expect_frontend_proxy(str_contains($controller, '$this->piwigoClient->fetchAsset($url)'), 'Proxy must fetch the binary server-side through the hardened Piwigo client.');
expect_frontend_proxy(str_contains($controller, '$response->setPrivate()'), 'Protected derivative responses must be private-cache responses.');
expect_frontend_proxy(str_contains($controller, "'X-Content-Type-Options' => 'nosniff'"), 'Protected derivative responses must disable MIME sniffing.');
expect_frontend_proxy(str_contains($controller, "'Content-Security-Policy' => \"default-src 'none'; sandbox\""), 'Protected derivative responses must carry a restrictive CSP.');
expect_frontend_proxy(str_contains($controller, 'return NULL;'), 'Unknown binary image formats must be rejected.');

fwrite(STDOUT, "Protected frontend derivative contract OK\n");
