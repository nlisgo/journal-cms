<?php

namespace Drupal\jcms_migrate\Plugin\migrate\process;

use Drupal\Core\Site\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;

trait JMCSGetRemoteFileTrait {
  function getFile($filename, $options = []) {
    $guzzle = new Client();
    try {
      $options += [
        'timeout' => 13,
        'http_errors' => FALSE,
      ];
      $response = $guzzle->get($filename, $options);
      if ($response->getStatusCode() == 200) {
        return $response->getBody()->getContents();
      }

      error_log(sprintf("File %s didn't download. (return code %d)", $filename, $response->getStatusCode()));
      return FALSE;
    }
    catch (ConnectException $e) {
      error_log(sprintf("File %s didn't download. (%s)", $filename, $e->getMessage()));
    }
  }
}
