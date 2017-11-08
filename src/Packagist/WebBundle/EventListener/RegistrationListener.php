<?php

namespace Packagist\WebBundle\EventListener;

use FOS\UserBundle\Event\FormEvent;
use FOS\UserBundle\Event\GetResponseUserEvent;
use FOS\UserBundle\FOSUserEvents;
use FOS\UserBundle\Util\TokenGenerator;
use Packagist\WebBundle\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Sullivan Senechal <soullivaneuh@gmail.com>
 */
class RegistrationListener implements EventSubscriberInterface
{
    /**
     * @var TokenGenerator
     */
    private $tokenGenerator;

    /**
     * @param TokenGenerator $tokenGenerator
     */
    public function __construct(TokenGenerator $tokenGenerator)
    {
        $this->tokenGenerator = $tokenGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
          FOSUserEvents::REGISTRATION_SUCCESS => 'onRegistrationSuccess',
          FOSUserEvents::REGISTRATION_INITIALIZE => 'onRegistrationInitialize'
        ];
    }

    public function onRegistrationSuccess(FormEvent $event)
    {
        /** @var User $user */
        $user = $event->getForm()->getData();
        $apiToken = substr($this->tokenGenerator->generateToken(), 0, 20);
        $user->setApiToken($apiToken);
    }

    public function onRegistrationInitialize(GetResponseUserEvent $event) {
      // Disallow open registration; must be via a github linked account.
      $event->setResponse(new Response('This repository does not allow open registrations.'));
    }
}
