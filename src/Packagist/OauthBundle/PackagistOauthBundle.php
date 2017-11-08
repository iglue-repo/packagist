<?php


namespace Packagist\OauthBundle;


use Symfony\Component\HttpKernel\Bundle\Bundle;

class PackagistOauthBundle extends Bundle {
  public function getParent() {
    return 'HWIOAuthBundle';
  }
}
