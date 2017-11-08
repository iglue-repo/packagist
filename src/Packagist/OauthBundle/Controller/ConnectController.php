<?php
namespace Packagist\OauthBundle\Controller;

use Github\Client;
use Github\HttpClient\Builder;
use HWI\Bundle\OAuthBundle\Controller\ConnectController as BaseController;
use HWI\Bundle\OAuthBundle\OAuth\ResourceOwnerInterface;
use HWI\Bundle\OAuthBundle\Security\Core\Authentication\Token\OAuthToken;
use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;

class ConnectController extends BaseController {
  /**
   * Shows a registration form if there is no user logged in and connecting
   * is enabled.
   *
   * @param Request $request A request.
   * @param string  $key     Key used for retrieving the right information for the registration form.
   *
   * @return Response
   *
   * @throws NotFoundHttpException if `connect` functionality was not enabled
   * @throws AccessDeniedException if any user is authenticated
   * @throws \Exception
   */
  public function registrationAction(Request $request, $key)
  {
    $connect = $this->container->getParameter('hwi_oauth.connect');
    if (!$connect) {
      throw new NotFoundHttpException();
    }

    $hasUser = $this->isGranted('IS_AUTHENTICATED_REMEMBERED');
    if ($hasUser) {
      throw new AccessDeniedException('Cannot connect already registered account.');
    }

    $session = $request->getSession();
    $error = $session->get('_hwi_oauth.registration_error.'.$key);
    $session->remove('_hwi_oauth.registration_error.'.$key);

    if (!$error instanceof AccountNotLinkedException || time() - $key > 300) {
      // todo: fix this
      throw new \Exception('Cannot register an account.');
    }

    $userInformation = $this
      ->getResourceOwnerByName($error->getResourceOwnerName())
      ->getUserInformation($error->getRawToken())
    ;

    // Check whether the user is in the iglue organization.
    $githubClient = new Client(
      new Builder($this->container->get('httplug.client.github_api')),
      '3'
    );
    $githubClient->authenticate($userInformation->getAccessToken(), null, Client::AUTH_HTTP_TOKEN);

    $memberships = $githubClient->user()->orgs();
    $orgs = array_map(function($membership) {
      return $membership['login'];
    }, $memberships);

    if (! in_array($this->getParameter('github_authorized_org'), $orgs)) {
      throw new AccessDeniedHttpException(
        sprintf('Access is limited to members of the %s organization on GitHub.', $this->getParameter('github_authorized_org'))
      );
    }

    // enable compatibility with FOSUserBundle 1.3.x and 2.x
    if (interface_exists('FOS\UserBundle\Form\Factory\FactoryInterface')) {
      $form = $this->container->get('hwi_oauth.registration.form.factory')->createForm();
    } else {
      $form = $this->container->get('hwi_oauth.registration.form');
    }

    $formHandler = $this->container->get('hwi_oauth.registration.form.handler');
    if ($formHandler->process($request, $form, $userInformation)) {
      $this->container->get('hwi_oauth.account.connector')->connect($form->getData(), $userInformation);

      // Authenticate the user
      $this->authenticateUser($request, $form->getData(), $error->getResourceOwnerName(), $error->getRawToken());

      return $this->render('HWIOAuthBundle:Connect:registration_success.html.'.$this->getTemplatingEngine(), array(
        'userInformation' => $userInformation,
      ));
    }

    // reset the error in the session
    $key = time();
    $session->set('_hwi_oauth.registration_error.'.$key, $error);

    return $this->render('HWIOAuthBundle:Connect:registration.html.'.$this->getTemplatingEngine(), array(
      'key' => $key,
      'form' => $form->createView(),
      'userInformation' => $userInformation,
    ));
  }

}