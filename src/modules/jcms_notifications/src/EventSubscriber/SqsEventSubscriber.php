<?php

namespace Drupal\jcms_notifications\EventSubscriber;

use Drupal\jcms_notifications\Event\SqsEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SqsEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[SqsEvent::RECEIVE][] = ['onSqsMessageReceive'];
    return $events;
  }

  public function onSqsMessageReceive(SqsEvent $event) {
    $event->getMessage()->setDebug('nlisgo');
  }

}