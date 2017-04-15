<?php

namespace Drupal\jcms_notifications;

use Aws\AwsClientInterface;
use Aws\Sqs\SqsClient;
use Drupal\Core\Site\Settings;
use Drupal\jcms_notifications\Event\SqsEvent;
use Drupal\jcms_notifications\Queue\SqsMessage;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class QueueService.
 *
 * @package Drupal\jcms_notifications
 */
final class QueueService {

  /**
   * @var \Aws\Sqs\SqsClient
   */
  protected $sqsClient;

  protected $endpoint = '';

  protected $queueName = '';

  protected $region = '';

  /**
   * @var EventDispatcherInterface
   */
  private $eventDispatcher;

  /**
   * QueueService constructor.
   *
   * @param \Aws\AwsClientInterface|NULL $sqs_client
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   */
  public function __construct(AwsClientInterface $sqs_client = NULL, EventDispatcherInterface $event_dispatcher) {
    $this->endpoint = Settings::get('jcms_sqs_endpoint');
    $this->queueName = Settings::get('jcms_sqs_queue');
    $this->region = Settings::get('jcms_sqs_region');
    $config = [
      'profile' => 'default',
      'version' => 'latest',
      'region' => $this->region,
    ];
    if (!empty($this->endpoint)) {
      $config['endpoint'] = $this->endpoint;
    }
    $this->sqsClient = $sqs_client ?: new SqsClient($config);
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * Gets the queue.
   *
   * @return \Aws\Result
   */
  protected function getQueue() {
    return $this->sqsClient->getQueueUrl([
      'QueueName' => $this->queueName,
    ]);
  }

  /**
   * Gets the article data from SQS.
   *
   * @return \Drupal\jcms_notifications\Queue\SqsMessage|null
   */
  public function getMessage() {
    $message = NULL;
    $queue = $this->getQueue();
    while (!$message) {
      $receiveMessage = $this->sqsClient->receiveMessage([
        'QueueUrl' => $queue['QueueUrl'],
        'VisibilityTimeout' => 60,
        'WaitTimeSeconds' => 20,
      ]);
      $response = $receiveMessage->get('Messages');
      if ($response === NULL) {
        break;
      }
      $message = $this->mapSqsMessage($response);
      $this->eventDispatcher->dispatch(SqsEvent::RECEIVE, new SqsEvent($message));
    }
    return $message;
  }

  /**
   * Delete a message from the queue.
   *
   * @param \Drupal\jcms_notifications\Queue\SqsMessage $sqsMessage
   *
   * @return \Aws\Result
   */
  public function deleteMessage(SqsMessage $sqsMessage) {
    $queue = $this->getQueue();
    $this->eventDispatcher->dispatch(SqsEvent::DELETE, new SqsEvent($sqsMessage));
    return $this->sqsClient->deleteMessage([
      'QueueUrl' => $queue['QueueUrl'],
      'ReceiptHandle' => $sqsMessage->getReceipt(),
    ]);
  }

  /**
   * Helper method to map values to a SqsMessage object.
   *
   * @param array $message
   *
   * @return \Drupal\jcms_notifications\Queue\SqsMessage
   * @throws \Exception
   */
  protected function mapSqsMessage(array $message) : SqsMessage {
    if (!empty($message)) {
      $message = array_shift($message);
      $message_id = $message['MessageId'] ?? '';
      $body = isset($message['Body']) ? json_decode($message['Body'], TRUE) : [];
      $id = $body['id'] ?? 0;
      $type = $body['type'] ?? 'article';
      $receipt = $message['ReceiptHandle'] ?? '';
      if ($message_id && $receipt) {
        return new SqsMessage($message_id, $id, $type, $body, $receipt);
      }
    }
    throw new \Exception('Missing arguments for SQS message.');
  }

}
