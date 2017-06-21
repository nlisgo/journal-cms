<?php

namespace Drupal\jcms_rest;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Site\Settings;
use Drupal\crop\Entity\Crop;

trait JMCSImageUriTrait {

  protected $imageSizes = [
    'banner',
    'thumbnail',
  ];

  /**
   * Get the IIIF or web path to the image.
   *
   * @param string $image_uri
   * @param string $type
   * @param null|string $filemime
   * @return string
   */
  protected function processImageUri($image_uri, $type = 'source', $filemime = NULL) {
    $iiif = Settings::get('jcms_iiif_base_uri');
    if ($iiif) {
      $iiif_mount = Settings::get('jcms_iiif_mount', '/');
      $iiif_mount = trim($iiif_mount, '/');
      $iiif_mount .= (!empty($iiif_mount)) ? '/' : '';
      $image_uri = str_replace('public://' . $iiif_mount, '', $image_uri);
      if ($type == 'source') {
        switch ($filemime ?? \Drupal::service('file.mime_type.guesser')->guess($image_uri)) {
          case 'image/gif':
            $ext = 'gif';
            break;
          case 'image/png':
            $ext = 'png';
            break;
          default:
            $ext = 'jpg';
        }
        return $iiif . $image_uri . '/full/full/0/default.' . $ext;
      }
      else {
        return $iiif . $image_uri;
      }
    }
    else {
      return file_create_url($image_uri);
    }
  }

  /**
   * Process image field and return json string.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $data
   * @param \Drupal\Core\Field\FieldItemListInterface|NULL $attribution
   * @param bool $required
   * @param array|string $size_types
   * @param bool $bump
   * @return array
   */
  protected function processFieldImage(FieldItemListInterface $data, $attribution = NULL, $required = FALSE, $size_types = ['banner', 'thumbnail'], $bump = FALSE) {
    if ($required || $data->count()) {
      $image = $this->getImageSizes($size_types);

      $image_uri = $data->first()->get('entity')->getTarget()->get('uri')->getString();
      $image_uri_info = $this->processImageUri($image_uri, 'info');
      $image_alt = (string) $data->first()->getValue()['alt'];
      $filemime = $data->first()->get('entity')->getTarget()->get('filemime')->getString();
      $filename = basename($image_uri);
      $width = (int) $data->first()->getValue()['width'];
      $height = (int) $data->first()->getValue()['height'];

      if ($attribution instanceof FieldItemListInterface && $attribution->count()) {
        $view = $attribution->view();
        unset($view['#theme']);
        $attribution_text = \Drupal::service('renderer')->renderPlain($view);
      }
      else {
        $attribution_text = NULL;
      }

      // @todo - elife - nlisgo - this is a temporary fix until we can trust mimetype of images.
      if (\Drupal::service('file.mime_type.guesser')->guess($image_uri) == 'image/png') {
        $filemime = 'image/jpeg';
        $filename = preg_replace('/\.png$/', '.jpg', $filename);
      }

      $image_uri_source = $this->processImageUri($image_uri, 'source', $filemime);
      foreach ($image as $type => $array) {
        $image[$type]['uri'] = $image_uri_info;
        $image[$type]['alt'] = $image_alt;
        $image[$type]['source'] = [
          'mediaType' => $filemime,
          'uri' => $image_uri_source,
          'filename' => $filename,
        ];
        $image[$type]['size'] = [
          'width' => $width,
          'height' => $height,
        ];

        // Focal point is optional.
        $crop_type = \Drupal::config('focal_point.settings')->get('crop_type');
        $crop = Crop::findCrop($image_uri, $crop_type);
        if ($crop) {
          $anchor = \Drupal::service('focal_point.manager')
            ->absoluteToRelative($crop->x->value, $crop->y->value, $image[$type]['size']['width'], $image[$type]['size']['height']);

          $image[$type]['focalPoint'] = $anchor;
          if (!empty($attribution_text)) {
            $image[$type]['attribution'] = $attribution_text;
          }
        }
      }

      if ($bump && count($image) === 1) {
        $keys = array_keys($image);
        $image = $image[$keys[0]];
      }

      return $image;
    }

    return [];
  }

  /**
   * Get image sizes for the requested presets.
   *
   * @param array $size_types
   * @return array
   */
  protected function getImageSizes($size_types = ['banner', 'thumbnail']) {
    $sizes = [];
    $size_types = (array) $size_types;
    foreach ($size_types as $size_type) {
      if (in_array($size_type, $this->imageSizes)) {
        $sizes[$size_type] = [];
      }
    }

    return $sizes;
  }
}
