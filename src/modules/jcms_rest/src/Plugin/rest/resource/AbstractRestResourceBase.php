<?php

namespace Drupal\jcms_rest\Plugin\rest\resource;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\rest\Plugin\ResourceBase;

abstract class AbstractRestResourceBase extends ResourceBase {

  protected $defaultOptions = [
    'per-page' => 10,
    'page' => 1,
    'order' => 'desc',
  ];

  protected static $requestOptions = [];

  protected $imageSizes = [
    'banner' => [
      '2:1' => [
        900 => '450',
        1800 => '900',
      ],
    ],
    'thumbnail' => [
      '16:9' => [
        250 => '141',
        500 => '282',
      ],
      '1:1' => [
        70 => '70',
        140 => '140',
      ],
    ],
  ];

  /**
   * Process default values.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @param string $id
   * @param string|int $id_key
   * @return array
   */
  protected function processDefault(EntityInterface $entity, $id = NULL, $id_key = 'id') {
    return [
      $id_key => !is_null($id) ? $id : substr($entity->uuid(), -8),
      'title' => $entity->getTitle(),
      'published' => \Drupal::service('date.formatter')->format($entity->getCreatedTime(), 'html_datetime'),
    ];
  }

  /**
   * Set default request option.
   *
   * @param string $option
   * @param string|int|array $default
   */
  protected function setDefaultOption($option, $default) {
    $this->defaultOptions[$option] = $default;
  }

  /**
   * Returns an array of Drupal request options.
   *
   * @return array
   */
  protected function getRequestOptions() {
    if (empty($this::$requestOptions)) {
      $request = \Drupal::request();
      $this::$requestOptions = [
        'page' => (int) $request->query->get('page', $this->defaultOptions['page']),
        'per-page' => (int) $request->query->get('per-page', $this->defaultOptions['per-page']),
        'order' => $request->query->get('order', $this->defaultOptions['order']),
        'subject' => (array) $request->query->get('subject', $this->defaultOptions['subject']),
      ];
    }
    return $this::$requestOptions;
  }

  /**
   * Return named request option.
   *
   * @param string $option
   * @return int|string|array|NULL
   */
  protected function getRequestOption($option) {
    $requestOptions = $this->getRequestOptions();
    if ($requestOptions[$option]) {
      return $requestOptions[$option];
    }

    return NULL;
  }

  /**
   * @param \Drupal\Core\Field\FieldItemListInterface $data
   * @param bool $required
   * @param array|string $size_types
   * @return array
   */
  protected function processFieldImage(FieldItemListInterface $data, $required = FALSE, $size_types = ['banner', 'thumbnail']) {
    if ($required || $data->count()) {
      $image = $this->getImageSizes($size_types);

      foreach ($image as $type => $image_sizes) {
        $image_uri = $data->first()->get('entity')->getTarget()->get('uri')->first()->getValue()['value'];
        $image[$type]['alt'] = $data->first()->getValue()['alt'];
        foreach ($image_sizes['sizes'] as $ar => $sizes) {
          foreach ($sizes as $width => $height) {
            $image_style = [
              'crop',
              str_replace(':', 'x', $ar),
              $width . 'x' . $height,
            ];
            $image[$type]['sizes'][$ar][$width] = ImageStyle::load(implode('_', $image_style))->buildUrl($image_uri);
          }
        }
      }

      if (count($image) === 1) {
        $keys = array_keys($image);
        $image = $image[$keys[0]];
      }

      return $image;
    }

    return [];
  }

  protected function getImageSizes($size_types = ['banner', 'thumbnail']) {
    $sizes = [];
    $size_types = (array) $size_types;
    foreach ($size_types as $size_type) {
      if (isset($this->imageSizes[$size_type])) {
        $sizes[$size_type]['sizes'] = $this->imageSizes[$size_type];
      }
    }

    return $sizes;
  }

  /**
   * @param \Drupal\Core\Field\FieldItemListInterface $data
   * @param bool $required
   * @return array
   */
  protected function processFieldContent(FieldItemListInterface $data, $required = FALSE) {
    $handle_paragraphs = function($content) use (&$handle_paragraphs) {
      $result = [];
      foreach ($content as $paragraph) {
        $content_item = $paragraph->get('entity')->getTarget()->getValue();
        $content_type = $content_item->getType();
        $result_item = [
          'type' => $content_type,
        ];
        switch ($content_type) {
          case 'section':
            $result_item['title'] = $content_item->get('field_block_title')->first()->getValue()['value'];
            $result_item['content'] = $handle_paragraphs($content_item->get('field_block_content'));
            break;
          case 'paragraph':
            if ($content_item->get('field_block_html')->first()) {
              $result_item['text'] = $content_item->get('field_block_html')->first()->getValue()['value'];
            }
            else {
              unset($result_item);
            }
            break;
          case 'image':
            if ($image = $content_item->get('field_block_image')->first()) {
              $image = $content_item->get('field_block_image')->first();
              $result_item['alt'] = (string) $image->getValue()['alt'];
              $result_item['uri'] = file_create_url($image->get('entity')->getTarget()->get('uri')->first()->getValue()['value']);
              if ($content_item->get('field_block_html')->count()) {
                $result_item['title'] = $content_item->get('field_block_html')->first()->getValue()['value'];
              }
            }
            else {
              unset($result_item);
            }
            break;
          case 'blockquote':
            $result_item['text'] = $content_item->get('field_block_html')->first()->getValue()['value'];
            if ($content_item->get('field_block_citation')->count()) {
              $result_item['citation'] = $content_item->get('field_block_citation')->first()->getValue()['value'];
            }
            break;
          case 'youtube':
            $result_item['id'] = $content_item->get('field_block_youtube_id')->first()->getValue()['value'];
            $result_item['width'] = (int) $content_item->get('field_block_youtube_width')->first()->getValue()['value'];
            $result_item['height'] = (int) $content_item->get('field_block_youtube_height')->first()->getValue()['value'];
            break;
          case 'table':
            $result_item['tables'] = [preg_replace('/\n/', '', $content_item->get('field_block_html')->first()->getValue()['value'])];
            break;
          case 'list':
            $result_item['prefix'] = $content_item->get('field_block_list_ordered')->first()->getValue()['value'] ? 'number' : 'bullet';
            $result_item['items'] = $handle_paragraphs($content_item->get('field_block_list_items'));
            break;
          case 'list_item':
            $result_item = $content_item->get('field_block_html')->first()->getValue()['value'];
            break;
          default:
            unset($result_item['type']);
        }

        if (!empty($result_item)) {
          $result[] = $result_item;
        }
      }

      return $result;
    };

    if ($required || $data->count()) {
      return $handle_paragraphs($data);
    }

    return [];
  }

  /**
   * @param \Drupal\Core\Field\FieldItemListInterface $field_subjects
   * @param bool $required
   * @return array
   */
  protected function processSubjects(FieldItemListInterface $field_subjects, $required = FALSE) {
    $subjects = [];
    if ($required || $field_subjects->count()) {
      /* @var \Drupal\taxonomy\Entity\Term $term */
      foreach ($field_subjects->referencedEntities() as $term) {
        $subjects[] = [
          'id' => $term->get('field_subject_id')->first()->getValue()['value'],
          'name' => $term->toLink()->getText(),
        ];
      }
    }
    return $subjects;
  }

  /**
   * Apply filter for subjects by amending query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   */
  protected function filterSubjects(QueryInterface &$query) {
    $subjects = $this->getRequestOption('subject');
    if (!empty($subjects)) {
      $query->condition('field_subjects.entity.field_subject_id.value', $subjects, 'IN');
    }
  }

  /**
   * Apply filter for page, per-page and order.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   * @param string
   */
  protected function filterPageAndOrder(QueryInterface &$query, $sort_by = 'created') {
    $request_options = $this->getRequestOptions();
    $query->range(($request_options['page'] - 1) * $request_options['per-page'], $request_options['per-page']);
    $query->sort($sort_by, $request_options['order']);
  }

}