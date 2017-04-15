<?php

namespace Drupal\jcms_notifications\Event;

use Drupal\jcms_notifications\Queue\SqsMessage;
use Symfony\Component\EventDispatcher\Event;

class SqsEvent extends Event {
  const RECEIVE = 'sqs.receive';
  const DELETE = 'sqs.delete';

  /**
   * @var \Drupal\jcms_notifications\Queue\SqsMessage
   */
  private $sqsMessage;

  public function __construct(SqsMessage $sqs_message) {
    $this->sqsMessage = $sqs_message;
  }

  /**
   * @return \Drupal\jcms_notifications\Queue\SqsMessage
   */
  public function getMessage() : SqsMessage {
    return $this->sqsMessage;
  }

}