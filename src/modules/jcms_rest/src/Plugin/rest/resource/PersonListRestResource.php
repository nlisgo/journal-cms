<?php

namespace Drupal\jcms_rest\Plugin\rest\resource;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\jcms_rest\Exception\JCMSBadRequestHttpException;
use Drupal\jcms_rest\Response\JCMSRestResponse;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "person_list_rest_resource",
 *   label = @Translation("Person list rest resource"),
 *   uri_paths = {
 *     "canonical" = "/people"
 *   }
 * )
 */
class PersonListRestResource extends AbstractRestResourceBase {
  /**
   * Responds to GET requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   *
   * @todo - elife - nlisgo - Handle version specific requests
   */
  public function get() {
    $base_query = \Drupal::entityQuery('node')
      ->condition('status', \Drupal\node\NodeInterface::PUBLISHED)
      ->condition('changed', \Drupal::time()->getRequestTime(), '<')
      ->condition('field_archive.value', 0)
      ->condition('type', 'person');

    $this->filterSubjects($base_query);
    $this->filterType($base_query);

    $count_query = clone $base_query;
    $items_query = clone $base_query;
    $response_data = [
      'total' => 0,
      'items' => [],
    ];
    $nodes = [];
    if ($total = $count_query->count()->execute()) {
      $person_item_rest_resource = new PersonItemRestResource([], 'person_item_rest_resource', [], $this->serializerFormats, $this->logger);
      $response_data['total'] = (int) $total;
      $this->filterPageAndOrder($items_query, 'field_person_index_name.value');
      $nids = $items_query->execute();
      $nodes = Node::loadMultiple($nids);
      if (!empty($nodes)) {
        foreach ($nodes as $node) {
          $response_data['items'][] = $person_item_rest_resource->getItem($node);
        }
      }
    }
    $response = new JCMSRestResponse($response_data, Response::HTTP_OK, ['Content-Type' => $this->getContentType()]);
    $response->addCacheableDependencies($nodes);
    return $response;
  }

  /**
   * Apply filter for subjects by amending query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   */
  protected function filterSubjects(QueryInterface &$query) {
    $subjects = $this->getRequestOption('subject');
    if (!empty($subjects)) {
      // @todo - elife - nlisgo - Ideally we would just filter on $query.
      // The below query doesn't work.
      // $query->condition('field_research_details.entity.field_research_expertises.entity.field_subject_id.value', $subjects, 'IN');
      $subjects_query = Database::getConnection()->select('node__field_research_details', 'rd');
      $subjects_query->addField('rd', 'entity_id');
      $subjects_query->innerJoin('paragraph__field_research_expertises', 're', 're.entity_id = rd.field_research_details_target_id');
      $subjects_query->innerJoin('taxonomy_term__field_subject_id', 'si', 'si.entity_id = re.field_research_expertises_target_id');
      $subjects_query->condition('rd.bundle', 'person');
      $subjects_query->condition('si.field_subject_id_value', $subjects, 'IN');
      if ($results = $subjects_query->execute()->fetchCol()) {
        $query->condition('nid', $results, 'IN');
      }
      else {
        // Force no results if there are no matches for subject ids.
        $query->notExists('nid');
      }
    }
  }

  /**
   * Apply filter for type by amending query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   */
  protected function filterType(QueryInterface &$query) {
    $type = $this->getRequestOption('type');
    if (!is_null($type)) {
      $entityManager = \Drupal::service('entity_field.manager');
      $fields = $entityManager->getFieldStorageDefinitions('node', 'person');
      $options = options_allowed_values($fields['field_person_type']);
      if (!in_array($type, array_keys($options))) {
        throw new JCMSBadRequestHttpException(t('Invalid type'));
      }
      else {
        $query->condition('field_person_type.value', $type);
      }
    }
  }

  /**
   * Takes a node and builds an item from it.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *
   * @return array
   */
  public function getItem(EntityInterface $node) {
    $entityManager = \Drupal::service('entity_field.manager');
    $fields = $entityManager->getFieldStorageDefinitions('node', 'person');
    $options = options_allowed_values($fields['field_person_type']);
    $type_label = ($node->get('field_person_type_label')->count()) ? $node->get('field_person_type_label')->getString() : $options[$node->get('field_person_type')->getString()];
    $item = [
      'id' => substr($node->uuid(), -8),
      'type' => ['id' => $node->get('field_person_type')->getString(), 'label' => $type_label],
      'name' => $this->processPeopleNames($node->getTitle(), $node->get('field_person_index_name')),
    ];

    // Orcid is optional.
    if ($node->get('field_person_orcid')->count()) {
      $item['orcid'] = $node->get('field_person_orcid')->getString();
    }

    // Image is optional.
    if ($image = $this->processFieldImage($node->get('field_image'), NULL, FALSE, 'thumbnail', TRUE)) {
      $item['image'] = $image;
    }

    return $item;
  }

}
