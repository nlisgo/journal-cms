<?php

namespace Drupal\jcms_rest\Plugin\rest\resource;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\jcms_rest\Exception\JCMSBadRequestHttpException;
use Drupal\jcms_rest\JMCSImageUriTrait;
use Drupal\jcms_rest\PathMediaTypeMapper;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\rest\Plugin\ResourceBase;

abstract class AbstractRestResourceBase extends ResourceBase {

  use JMCSImageUriTrait;

  protected $defaultOptions = [
    'per-page' => 10,
    'page' => 1,
    'order' => 'desc',
    'subject' => [],
    'start-date' => '2000-01-01',
    'end-date' => '2999-12-31',
    'use-date' => 'default',
    'show' => 'all',
    'sort' => 'date',
    'type' => NULL,
  ];

  protected static $requestOptions = [];

  protected $defaultSortBy = 'created';

  /**
   * Process default values.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @param string $id
   * @param string|int $id_key
   * @return array
   */
  protected function processDefault(EntityInterface $entity, $id = NULL, $id_key = 'id') {
    $defaults = [
      $id_key => !is_null($id) ? $id : substr($entity->uuid(), -8),
      'title' => $entity->getTitle(),
      'published' => $this->formatDate($entity->getCreatedTime()),
    ];

    if ($entity->getRevisionCreationTime() > $entity->getCreatedTime()) {
      $defaults['updated'] = $this->formatDate($entity->getRevisionCreationTime());
    }

    return $defaults;
  }

  /**
   * Format date.
   *
   * @param null|int $date
   * @return mixed
   */
  protected function formatDate($date = NULL) {
    $date = is_null($date) ? time() : $date;
    return \Drupal::service('date.formatter')->format($date, 'api_datetime');
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
        'start-date' => $request->query->get('start-date', $this->defaultOptions['start-date']),
        'end-date' => $request->query->get('end-date', $this->defaultOptions['end-date']),
        'use-date' => $request->query->get('use-date', $this->defaultOptions['use-date']),
        'show' => $request->query->get('show', $this->defaultOptions['show']),
        'sort' => $request->query->get('sort', $this->defaultOptions['sort']),
        'type' => $request->query->get('type', $this->defaultOptions['type']),
      ];
    }

    if (!in_array($this::$requestOptions['order'], ['asc', 'desc'])) {
      throw new JCMSBadRequestHttpException(t('Invalid order option'));
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
   * @return array
   */
  protected function processFieldContent(FieldItemListInterface $data, $required = FALSE) {
    $handle_paragraphs = function($content, $list_flag = FALSE) use (&$handle_paragraphs) {
      $result = [];
      foreach ($content as $paragraph) {
        $content_item = $paragraph->get('entity')->getTarget()->getValue();
        $content_type = $content_item->getType();
        $result_item = [
          'type' => $content_type,
        ];
        switch ($content_type) {
          case 'section':
            $result_item['title'] = $content_item->get('field_block_title')->getString();
            $result_item['content'] = $handle_paragraphs($content_item->get('field_block_content'));
            break;
          case 'paragraph':
            if ($content_item->get('field_block_html')->count()) {
              // Split paragraphs in the UI into separate paragraph blocks.
              $texts = $this->splitParagraphs($this->fieldValueFormatted($content_item->get('field_block_html')));
              foreach ($texts as $text) {
                if (!is_array($text)) {
                  $text = trim($text);
                  $loop_result_item = $result_item;
                  if (!empty($text)) {
                    $loop_result_item['text'] = $text;
                    if ($list_flag && $content_type != 'list_item') {
                      $loop_result_item = [$loop_result_item];
                    }
                    $result[] = $loop_result_item;
                  }
                }
                else {
                  $result[] = $text;
                }
              }
            }

            unset($result_item);
            break;
          case 'question':
            $result_item['question'] = $content_item->get('field_block_title')->getString();
            $result_item['answer'] = $handle_paragraphs($content_item->get('field_block_question_answer'));
            break;
          case 'image':
            if ($image = $content_item->get('field_block_image')->first()) {
              $result_item['image'] = $this->processFieldImage($content_item->get('field_block_image'), NULL, TRUE, 'banner', TRUE);
              $result_item['image'] = array_diff_key($result_item['image'], array_flip(['sizes']));
              if ($content_item->get('field_block_html')->count()) {
                $result_item['title'] = $this->fieldValueFormatted($content_item->get('field_block_html'));
              }
              if ($content_item->get('field_block_attribution')->count()) {
                $result_item['attribution'] = array_values(array_filter(preg_split("(\r\n?|\n)", $content_item->get('field_block_attribution')->getString())));
              }
            }
            else {
              unset($result_item);
            }
            break;
          case 'blockquote':
            $result_item['type'] = 'quote';
            $result_item['text'] = [
              [
                'type' => 'paragraph',
                'text' => $this->fieldValueFormatted($content_item->get('field_block_html')),
              ],
            ];
            break;
          case 'youtube':
            $result_item['id'] = $content_item->get('field_block_youtube_id')->getString();
            $result_item['width'] = (int) $content_item->get('field_block_youtube_width')->getString();
            $result_item['height'] = (int) $content_item->get('field_block_youtube_height')->getString();
            break;
          case 'table':
            $table_content = preg_replace('/\n/', '', $this->fieldValueFormatted($content_item->get('field_block_html')));
            if (preg_match("~(?P<table><table[^>]*>(?:.|\n)*?</table>)~", $table_content, $match)) {
              $result_item['tables'] = [$match['table']];
            }
            else {
              $result_item['tables'] = ['<table>' . $table_content . '</table>'];
            }
            break;
          case 'code':
            $result_item['code'] = $content_item->get('field_block_code')->getString();
            break;
          case 'list':
            $result_item['prefix'] = $content_item->get('field_block_list_ordered')->getString() ? 'number' : 'bullet';
            $result_item['items'] = $handle_paragraphs($content_item->get('field_block_list_items'), TRUE);
            break;
          case 'list_item':
            $result_item = $this->fieldValueFormatted($content_item->get('field_block_html'));
            break;
          case 'button':
            $result_item['text'] = $content_item->get('field_block_button')->first()->getValue()['title'];
            $result_item['uri'] = $content_item->get('field_block_button')->first()->getValue()['uri'];
            break;
          default:
            unset($result_item['type']);
        }

        if (!empty($result_item)) {
          if ($list_flag && $content_type != 'list_item') {
            $result_item = [$result_item];
          }
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
          'id' => $term->get('field_subject_id')->getString(),
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
   * @param mixed
   */
  protected function filterPageAndOrder(QueryInterface &$query, $sort_by = NULL) {
    $sort_bys = (array) $this->setSortBy($sort_by);

    if (!in_array($this->getRequestOption('sort'), ['date', 'page-views'])) {
      throw new JCMSBadRequestHttpException(t('Invalid sort option'));
    }

    $request_options = $this->getRequestOptions();
    $query->range(($request_options['page'] - 1) * $request_options['per-page'], $request_options['per-page']);
    foreach ($sort_bys as $sort_by) {
      $query->sort($sort_by, $request_options['order']);
    }
  }

  /**
   * Apply filter by show parameter: all, open or closed.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   * @param string
   */
  protected function filterShow(QueryInterface &$query) {
    $show_option = $this->getRequestOption('show');
    $options = [
      'closed' => 'end-date',
      'open' => 'start-date',
    ];

    if (in_array($show_option, array_keys($options))) {
      self::$requestOptions[$options[$show_option]] = date('Y-m-d');
      $this->filterDateRange($query, 'field_event_datetime.end_value', NULL, FALSE);
    }
    elseif ($show_option != 'all') {
      throw new JCMSBadRequestHttpException(t('Invalid show option'));
    }
  }

  /**
   * Apply filter for date range by amending query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   * @param string $default_field
   * @param string|NULL $published_field
   * @param bool $timestamp
   */
  protected function filterDateRange(QueryInterface &$query, $default_field = 'field_order_date.value', $published_field = 'created', $timestamp = TRUE) {
    $start_date = DateTimeImmutable::createFromFormat('Y-m-d', $originalStartDate = $this->getRequestOption('start-date'), new DateTimeZone('Z'));
    $end_date = DateTimeImmutable::createFromFormat('Y-m-d', $originalEndDate = $this->getRequestOption('end-date'), new DateTimeZone('Z'));
    $use_date = $this->getRequestOption('use-date');

    if (!$start_date || $start_date->format('Y-m-d') !== $this->getRequestOption('start-date')) {
      throw new JCMSBadRequestHttpException(t('Invalid start date'));
    }
    elseif (!$end_date || $end_date->format('Y-m-d') !== $this->getRequestOption('end-date')) {
      throw new JCMSBadRequestHttpException(t('Invalid end date'));
    }
    elseif (!in_array($use_date, ['published', 'default'])) {
      throw new JCMSBadRequestHttpException(t('Invalid use date'));
    }

    $start_date = $start_date->setTime(0, 0, 0);
    $end_date = $end_date->setTime(23, 59, 59);

    if ($end_date < $start_date) {
      throw new JCMSBadRequestHttpException(t('End date must be on or after start date'));
    }

    $field = (!is_null($published_field) && $use_date == 'published') ? $published_field : $default_field;

    if ($timestamp) {
      $query->condition($field, $start_date->getTimestamp(), '>=');
      $query->condition($field, $end_date->getTimestamp(), '<=');
    }
    else {
      $query->condition($field, $start_date->format(DATETIME_DATETIME_STORAGE_FORMAT), '>=');
      $query->condition($field, $end_date->format(DATETIME_DATETIME_STORAGE_FORMAT), '<=');
    }
  }

  /**
   * Set the "sort by" field.
   *
   * @param string|null|bool $sort_by
   * @param bool $force
   * @return string
   */
  protected function setSortBy($sort_by = NULL, $force = FALSE) {
    static $cache = NULL;

    if ($force || is_null($cache)) {
      if (!is_null($sort_by)) {
        $cache = $sort_by;
      }
      else {
        $cache = $this->defaultSortBy;
      }
    }

    return $cache;
  }

  /**
   * Get the "sort by" field.
   *
   * @return string
   */
  protected function getSortBy() {
    return $this->setSortBy();
  }

  /**
   * Prepare the value from formatted field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $data
   * @return mixed|null
   */
  protected function fieldValueFormatted(FieldItemListInterface $data) {
    $view = $data->view();
    unset($view['#theme']);
    $output = \Drupal::service('renderer')->renderPlain($view);
    $output = preg_replace('/(<img [^>]*src=\")(\/[^\"]+)/', '$1' . \Drupal::request()->getSchemeAndHttpHost() . '$2', $output);
    return str_replace(chr(194) . chr(160), ' ', $output);
  }

  /**
   * Split paragraphs into array of paragraphs and lists.
   *
   * @param string $paragraphs
   * @return array
   */
  public function splitParagraphs(string $paragraphs) {
    $dom = Html::load($paragraphs);
    $xpath = new \DOMXPath($dom);
    foreach ($xpath->query('//body/ul | //body/ol') as $node) {
      $html = $node->ownerDocument->saveHTML($node);
      $new_node = $dom->createElement($node->nodeName);
      $frag = $dom->createDocumentFragment();
      $frag->appendXML(preg_replace('~(?!</(ul|ol)>\s*)(\n|\t)+~', '', $html));
      $new_node->appendChild($frag);
      $node->parentNode->replaceChild($new_node->firstChild, $node);
    }
    $html = preg_replace('~</(ul|ol)><(ol|ul)~', "</$1>\n<$2", Html::serialize($dom));
    $split = preg_split('/\n+/', $html);

    return array_map([$this, 'convertHtmlListToSchema'], $split);
  }

  /**
   * Convert HTML list on single line to schema structure.
   *
   * @param string $html
   * @return array|string
   */
  public function convertHtmlListToSchema(string $html) {
    if (!preg_match('~^\s*<(ul|ol)[^>]*>.*</\1>\s*$~', $html)) {
      return $html;
    }
    $dom = Html::load($html);
    $xpath = new \DOMXPath($dom);
    $node = $xpath->query('//body/*')->item(0);
    $schema = [
      'type' => 'list',
      'prefix' => ($node->nodeName == 'ol') ? 'number' : 'bullet',
      'items' => [],
    ];
    foreach ($node->getElementsByTagName('li') as $item) {
      $item_value = '';
      $child_list = [];
      foreach ($item->childNodes as $child) {
        if (in_array($child->nodeName, ['ol', 'ul'])) {
          $item->removeChild($child);
          $child_list = [$this->convertHtmlListToSchema($child->ownerDocument->saveXML($child))];
        }
        else {
          $item_value .= $child->ownerDocument->saveXML($child);
        }
      }
      if (!empty($item_value)) {
        $schema['items'][] = $item_value;
      }
      if (!empty($child_list)) {
        $schema['items'][] = $child_list;
      }
    }
    return $schema;
  }

  /**
   * Get the article snippet from article node.
   *
   * @param \Drupal\node\Entity\Node $node
   * @return array
   */
  protected function getArticleSnippet(Node $node) {
    $crud_service = \Drupal::service('jcms_article.article_crud');
    return $crud_service->getArticle($node, $this->viewUnpublished());
  }

  /**
   * Get subject list from articles array.
   *
   * @param array $articles
   * @return array
   */
  protected function subjectsFromArticles($articles) {
    $subjects = [];
    foreach ($articles as $article) {
      if (property_exists($article, 'subjects') && !empty($article->subjects)) {
        foreach ($article->subjects as $subject) {
          if (!isset($subjects[$subject->id])) {
            $subjects[$subject->id] = $subject;
          }
        }
      }
    }

    return array_values($subjects);
  }

  /**
   * Convert venue paragraph into array prepared for response.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $venue_field
   * @return array
   */
  protected function getVenue(Paragraph $venue_field) {
      $venue = [
        'name' => array_values(array_filter(preg_split("(\r\n?|\n)", $venue_field->get('field_block_title_multiline')->getString()))),
      ];

      // Venue address is optional.
      if ($venue_field->get('field_block_address')->count()) {
        $locale = 'en';
        /* @var \CommerceGuys\Addressing\AddressInterface $address  */
        $address = $venue_field->get('field_block_address')->first();
        $postal_label_formatter = \Drupal::service('address.postal_label_formatter');
        $postal_label_formatter->setOriginCountryCode('no_origin');
        $postal_label_formatter->setLocale($locale);
        $components = [
          'streetAddress' => ['getAddressLine1', 'getAddressLine2'],
          'locality' => ['getLocality', 'getDependentLocality'],
          'area' => ['getAdministrativeArea'],
        ];

        $venue['address'] = [
          'formatted' => explode("\n", $postal_label_formatter->format($address)),
          'components' => [],
        ];

        foreach ($components as $section => $methods) {
          $values = [];
          foreach ($methods as $method) {
            if ($value = $address->{$method}()) {
              $values[] = $value;
            }
          }

          if (!empty($values)) {
            $venue['address']['components'][$section] = $values;
          }
        }

        $country_repository = \Drupal::service('address.country_repository');
        $countries = $country_repository->getList($locale);
        $venue['address']['components']['country'] = $countries[$address->getCountryCode()];

        if ($postal_code = $address->getPostalCode()) {
          $venue['address']['components']['postalCode'] = $postal_code;
        }
        elseif ($postal_code = $address->getSortingCode()) {
          $venue['address']['components']['postalCode'] = $postal_code;
        }
      }

      return $venue;
  }

  /**
   * Takes a node and builds an item from it.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   * @param \Drupal\Core\Field\FieldItemListInterface $related_field
   * @param bool $image
   *
   * @return array|bool
   */
  public function getEntityQueueItem(EntityInterface $node, FieldItemListInterface $related_field, $image = TRUE) {
    if (empty($related_field->first()->get('entity')->getTarget())) {
      return FALSE;
    }

    /* @var Node $node */
    /* @var Node $related */
    $related = $related_field->first()->get('entity')->getTarget()->getValue();
    $rest_resource = [
      'blog_article' => new BlogArticleListRestResource([], 'blog_article_list_rest_resource', [], $this->serializerFormats, $this->logger),
      'collection' => new CollectionListRestResource([], 'collection_list_rest_resource', [], $this->serializerFormats, $this->logger),
      'event' => new EventListRestResource([], 'event_list_rest_resource', [], $this->serializerFormats, $this->logger),
      'interview' => new InterviewListRestResource([], 'interview_list_rest_resource', [], $this->serializerFormats, $this->logger),
      'labs_experiment' => new LabsExperimentListRestResource([], 'labs_experiment_list_rest_resource', [], $this->serializerFormats, $this->logger),
      'podcast_episode' => new PodcastEpisodeListRestResource([], 'podcast_episode_list_rest_resource', [], $this->serializerFormats, $this->logger),
      'podcast_chapter' => new PodcastEpisodeItemRestResource([], 'podcast_episode_item_rest_resource', [], $this->serializerFormats, $this->logger),
    ];

    $item_values = [
      'title' => $node->getTitle(),
    ];

    if ($image) {
      $attribution = ($node->hasField('field_image_attribution')) ? $node->get('field_image_attribution') : NULL;
      $item_values['image'] = $this->processFieldImage($node->get('field_image'), $attribution, TRUE, 'banner', TRUE);
    }

    if ($related->getType() == 'article') {
      if ($article = $this->getArticleSnippet($related)) {
        $item_values['item'] = $article;
      }
    }
    elseif ($related->getType() == 'podcast_chapter') {
      $item_values['item']['type'] = 'podcast-episode-chapter';
      $item_values['item'] += $rest_resource[$related->getType()]->getChapterItem($related, 0, TRUE);
    }
    else {
      if (!empty($rest_resource[$related->getType()])) {
        $type = ($related->getType() == 'labs_experiment') ? 'labs-post' : str_replace('_', '-', $related->getType());
        $item_values['item']['type'] = $type;
        $item_values['item'] += $rest_resource[$related->getType()]->getItem($related);
      }
    }

    if (empty($item_values['item'])) {
      return FALSE;
    }

    return $item_values;
  }

  /**
   * Returns the endpoint from the rest resource "canonical" annotation.
   *
   * @return string
   * @throws \Exception
   */
  function getEndpoint(): string {
    $r = new \ReflectionClass(static::class);
    $annotation = $r->getDocComment();
    preg_match("/\"canonical\" = \"(.+)\"/", $annotation, $endpoint);
    if (!$endpoint) {
      throw new \Exception('Canonical URI not found in rest resource.');
    }
    return $endpoint[1];
  }

  /**
   * Gets the content type for the current rest resource.
   *
   * @param int $version
   *
   * @return string
   * @throws \Exception
   * @todo Handle this in the response object optionally.
   * @todo Handle versioning properly.
   */
  public function getContentType(int $version = 1): string {
    $endpoint = $this->getEndpoint();
    $mapper = new PathMediaTypeMapper();
    $content_type = $mapper->getMediaTypeByPath($endpoint);
    if (!$content_type) {
      throw new \Exception('Content type not found for specified rest resource.');
    }
    return $content_type . ';version=' . $version;
  }

  /**
   * Determine if the request user can view unpublished content.
   *
   * @return bool
   */
  public function viewUnpublished() {
    static $view_unpublished = NULL;

    if (is_null($view_unpublished)) {
      $request = \Drupal::request();
      $consumer = $request->headers->get('X-Consumer-Groups', 'user');
      $view_unpublished = ($consumer == 'admin');
    }

    return $view_unpublished;
  }

  /**
   * Process people names.
   *
   * @param string $preferred_name
   * @param FieldItemListInterface $index_name
   * @return array
   */
  public function processPeopleNames(string $preferred_name, FieldItemListInterface $index_name) {
    return [
      'preferred' => $preferred_name,
      'index' => ($index_name->count()) ? $index_name->getString() : preg_replace('/^(?P<first_names>.*)\s+(?P<last_name>[^\s]+)$/', '$2, $1', $name['preferred']),
    ];
  }

}
