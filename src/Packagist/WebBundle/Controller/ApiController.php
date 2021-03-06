<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle\Controller;

use Composer\Console\HtmlOutputFormatter;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Repository\InvalidRepositoryException;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Repository\VcsRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ApiController extends Controller
{
    /**
     * @Route("/packages.json", name="packages", defaults={"_format" = "json"})
     * @Method({"GET"})
     */
    public function packagesAction()
    {
        // fallback if any of the dumped files exist
        $rootJson = $this->container->getParameter('kernel.root_dir').'/../web/packages_root.json';
        if (file_exists($rootJson)) {
            return new Response(file_get_contents($rootJson));
        }
        $rootJson = $this->container->getParameter('kernel.root_dir').'/../web/packages.json';
        if (file_exists($rootJson)) {
            return new Response(file_get_contents($rootJson));
        }

        $this->get('logger')->alert('packages.json is missing and the fallback controller is being hit, you need to use app/console packagist:dump');

        return new Response('Horrible misconfiguration or the dumper script messed up, you need to use app/console packagist:dump', 404);
    }

    /**
     * @Route("/api/github", name="github_postreceive", defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function updatePackageAction(Request $request)
    {
        if (! $this->container->get('kernel')->getEnvironment() == 'dev') {
            // Verify message is signed by GitHub
            $rawSignature = $request->headers->get('X-Hub-Signature');
            list($algo, $hexits) = explode('=', $rawSignature, 2);
            $correctHexits = hash_hmac(
              $algo,
              $request->getContent(),
              $this->container->getParameter('github_org_webhook_secret')
            );
            if (! hash_equals($correctHexits, $hexits)) {
                return new JsonResponse(['status' => 'error', 'message' => 'Invalid X-Hub-Signature'], 403);
            }
        }

        // parse the payload
        $payload = json_decode($request->request->get('payload'), true);
        if (!$payload && $request->headers->get('Content-Type') === 'application/json') {
            $payload = json_decode($request->getContent(), true);
        }

        if (!$payload) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Missing payload parameter'), 406);
        }

        if (isset($payload['repository']['url'])) { // github/anything hook
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['repository']['url'];
            $ghRepoName = $payload['repository']['name'];
            $ghMaintainerId = null;
            if ($payload['ref_type'] == 'tag' && $payload['pusher_type'] == 'user') {
                $ghMaintainerId = $payload['sender']['id'];
            }
            $url = str_replace('https://api.github.com/repos', 'https://github.com', $url);
            $githubOrgSecret = '';
        } else {
            return new JsonResponse(array('status' => 'error', 'message' => 'Missing or invalid payload'), 406);
        }

        return $this->receivePost($request, $ghRepoName, $ghMaintainerId, $url, $urlRegex, $githubOrgSecret);
    }

    /**
     * @Route("/downloads/{name}", name="track_download", requirements={"name"="[@A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function trackDownloadAction(Request $request, $name)
    {
        $result = $this->getPackageAndVersionId($name, $request->request->get('version_normalized'));

        if (!$result) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Package not found'), 200);
        }

        $this->get('packagist.download_manager')->addDownloads(['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $request->getClientIp()]);

        return new JsonResponse(array('status' => 'success'), 201);
    }

    /**
     * Expects a json like:
     *
     * {
     *     "downloads": [
     *         {"name": "foo/bar", "version": "1.0.0.0"},
     *         // ...
     *     ]
     * }
     *
     * The version must be the normalized one
     *
     * @Route("/downloads/", name="track_download_batch", defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function trackDownloadsAction(Request $request)
    {
        $contents = json_decode($request->getContent(), true);
        if (empty($contents['downloads']) || !is_array($contents['downloads'])) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Invalid request format, must be a json object containing a downloads key filled with an array of name/version objects'), 200);
        }

        $failed = array();

        $ip = $request->headers->get('X-'.$this->container->getParameter('trusted_ip_header'));
        if (!$ip) {
            $ip = $request->getClientIp();
        }

        $jobs = [];
        foreach ($contents['downloads'] as $package) {
            $result = $this->getPackageAndVersionId($package['name'], $package['version']);

            if (!$result) {
                $failed[] = $package;
                continue;
            }

            $jobs[] = ['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $ip];
        }
        $this->get('packagist.download_manager')->addDownloads($jobs);

        if ($failed) {
            return new JsonResponse(array('status' => 'partial', 'message' => 'Packages '.json_encode($failed).' not found'), 200);
        }

        return new JsonResponse(array('status' => 'success'), 201);
    }

    /**
     * @param string $name
     * @param string $version
     * @return array
     */
    protected function getPackageAndVersionId($name, $version)
    {
        return $this->get('doctrine.dbal.default_connection')->fetchAssoc(
            'SELECT p.id, v.id vid
            FROM package p
            LEFT JOIN package_version v ON p.id = v.package_id
            WHERE p.name = ?
            AND v.normalizedVersion = ?
            LIMIT 1',
            array($name, $version)
        );
    }

    /**
     * Perform the package update
     *
     * @param Request $request the current request
     * @param string $ghRepoName The name of the repository on GitHub
     * @param string $ghMaintainerId The github id of a user that should be set as a maintainer.
     * @param string $url the repository's URL (deducted from the request)
     * @param string $urlRegex the regex used to split the user packages into domain and path
     * @param string $githubOrgSecret The shared secret value github sends us in organizational webhook events.
     *
     * @return Response
     */
    protected function receivePost(Request $request, $ghRepoName,
      $ghMaintainerId, $url, $urlRegex, $githubOrgSecret)
    {
        // try to parse the URL first to avoid the DB lookup on malformed requests
        if (!preg_match($urlRegex, $url)) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Could not parse payload repository URL')), 406);
        }

        // Locate the package associated to the github repository, or create it.
        // GitHub repositories must follow the naming convention that the upstream
        // packagist vendor is given followed by a '.', followed by the upstream
        // package name. Thus 'drupal/drupal' in packagist becomes 'drupal.drupal'
        // in github.com/iglue-repo. In case the upstream vendor name contains a
        // '.', it is represented as two consecutive dots in GitHub.
        $repoNameRegex = '/^([a-z0-9](?:(?:-|\.\.|_)?[a-z0-9]+)*)\.([a-z0-9](?:[_.-]?[a-z0-9]+)*)$/';
        $ghRepoNameParts = [];
        if (! preg_match($repoNameRegex, $ghRepoName, $ghRepoNameParts)) {
            return new Response(json_encode(['status' => 'error', 'message' => 'Could not parse repository name into vendor and package']), 406);
        }
        $expectedVendor = str_replace('..', '.', $ghRepoNameParts[1]);
        $expectedPackage = $ghRepoNameParts[2];
        $expectedFullPackage = "$expectedVendor@iglue/$expectedPackage";

        $packageOrmRepo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $existingPackage = $packageOrmRepo->findOneByName($expectedFullPackage);

        $em = $this->get('doctrine.orm.entity_manager');

        if (! $existingPackage) {
            // Try to create a new package.
            $package = new Package;
            $package->setEntityRepository($packageOrmRepo);
            $package->setRouter($this->get('router'));

            if ($ghMaintainerId !== null) {
                $this->ensurePackageHasMaintainer($package, $ghMaintainerId);
            }

            // Most of the magic happens here; this results in accessing the
            // composer.json on github and setting most Package properties.
            $package->setRepository($url);
            $errors = $this->get('validator')->validate($package);
            if (count($errors) > 0) {
                $errorArray = array();
                foreach ($errors as $error) {
                    $errorArray[$error->getPropertyPath()] =  $error->getMessage();
                }
                return new JsonResponse(array('status' => 'error', 'message' => $errorArray), 406);
            }

            if ($package->getName() !== $expectedFullPackage) {
                $actualName = $package->getName();
                return new Response(json_encode(['status' => 'error', 'message' => "GitHub repository name matches package '$expectedFullPackage', but composer.json disagrees with '$actualName'"]), 406);
            }
            $em->persist($package);
            $existingPackage = $package;
        }
        $packages = [$existingPackage];

        // don't die if this takes a while
        set_time_limit(3600);

        // put both updating the database and scanning the repository in a transaction
        $updater = $this->get('packagist.package_updater');
        $config = Factory::createConfig();
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));
        $io->loadConfiguration($config);
        $iglueTargetRepo = $this->container->getParameter('iglue_repository_number');

        try {
            /** @var Package $package */
            foreach ($packages as $package) {
                $em->transactional(function($em) use ($package, $iglueTargetRepo, $ghMaintainerId, $updater, $io, $config) {
                    // prepare dependencies
                    $loader = new ValidatingArrayLoader(new ArrayLoader(), false);

                    // prepare repository
                    $repository = new VcsRepository(
                      array('url' => $package->getRepository()),
                      $iglueTargetRepo,
                      $io,
                      $config
                    );
                    $repository->setLoader($loader);

                    // perform the actual update (fetch and re-scan the repository's source)
                    $updater->update($io, $config, $package, $repository);

                    if ($ghMaintainerId !== null) {
                        $this->ensurePackageHasMaintainer($package, $ghMaintainerId);
                    }

                    // update the package entity
                    $package->setAutoUpdated(true);
                    $em->flush($package);
                });
            }
        } catch (\Exception $e) {
            if ($e instanceof InvalidRepositoryException) {
                $this->get('packagist.package_manager')->notifyUpdateFailure($package, $e, $io->getOutput());
            }

            $this->get('logger')->error('Failed update of '.$package->getName(), ['exception' => $e]);

            return new Response(json_encode(array(
                'status' => 'error',
                'message' => '['.get_class($e).'] '.$e->getMessage(),
                'details' => '<pre>'.$io->getOutput().'</pre>'
            )), 400);
        }

        return new JsonResponse(array('status' => 'success'), 202);
    }

    /**
     * Find a user by his username and API token
     *
     * @param Request $request
     * @return User|null the found user or null otherwise
     */
    protected function findUser(Request $request)
    {
        $username = $request->request->has('username') ?
            $request->request->get('username') :
            $request->query->get('username');

        $apiToken = $request->request->has('apiToken') ?
            $request->request->get('apiToken') :
            $request->query->get('apiToken');

        $user = $this->get('packagist.user_repository')
            ->findOneBy(array('username' => $username, 'apiToken' => $apiToken));

        return $user;
    }

    /**
     * Find a user package given by its full URL
     *
     * @param User $user
     * @param string $url
     * @param string $urlRegex
     * @return array the packages found
     */
    protected function findPackagesByUrl(User $user, $url, $urlRegex)
    {
        if (!preg_match($urlRegex, $url, $matched)) {
            return array();
        }

        $packages = array();
        foreach ($user->getPackages() as $package) {
            if (preg_match($urlRegex, $package->getRepository(), $candidate)
                && strtolower($candidate['host']) === strtolower($matched['host'])
                && strtolower($candidate['path']) === strtolower($matched['path'])
            ) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    protected function ensurePackageHasMaintainer(Package $package, $githubMaintainerId) {
        // See if we can assign a maintainer by matching the github committer to a local user.
        $userOrmRepo = $this->getDoctrine()->getRepository('PackagistWebBundle:User');
        /**
         * @var User $maintainer
         */
        $maintainer = $userOrmRepo->findOneByGithubId($githubMaintainerId);
        if ($maintainer) {
            if (! $package->getMaintainers()->contains($maintainer))
            {
                $package->addMaintainer($maintainer);
            }
        }
    }
}
