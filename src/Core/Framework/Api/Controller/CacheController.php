<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Api\Controller;

use Shopware\Core\Framework\Adapter\Cache\CacheClearer;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\Annotation\Acl;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Storefront\Framework\Cache\CacheWarmer\CacheWarmer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route(defaults={"_routeScope"={"api"}})
 */
#[Package('system-settings')]
class CacheController extends AbstractController
{
    private CacheClearer $cacheClearer;

    private AdapterInterface $adapter;

    private ?CacheWarmer $cacheWarmer;

    private EntityIndexerRegistry $indexerRegistry;

    /**
     * @internal
     */
    public function __construct(
        CacheClearer $cacheClearer,
        AdapterInterface $adapter,
        ?CacheWarmer $cacheWarmer,
        EntityIndexerRegistry $indexerRegistry
    ) {
        $this->cacheClearer = $cacheClearer;
        $this->adapter = $adapter;
        $this->cacheWarmer = $cacheWarmer;
        $this->indexerRegistry = $indexerRegistry;
    }

    /**
     * @Since("6.2.0.0")
     * @Route("/api/_action/cache_info", name="api.action.cache.info", methods={"GET"}, defaults={"_acl"={"system:cache:info"}})
     */
    public function info(): JsonResponse
    {
        return new JsonResponse([
            'environment' => $this->getParameter('kernel.environment'),
            'httpCache' => $this->container->get('parameter_bag')->has('shopware.http.cache.enabled') && $this->getParameter('shopware.http.cache.enabled'),
            'cacheAdapter' => $this->getUsedCache($this->adapter),
        ]);
    }

    /**
     * @Since("6.2.0.0")
     * @Route("/api/_action/index", name="api.action.cache.index", methods={"POST"}, defaults={"_acl"={"api_action_cache_index"}})
     */
    public function index(RequestDataBag $dataBag): Response
    {
        $data = $dataBag->all();
        $skip = !empty($data['skip']) && \is_array($data['skip']) ? $data['skip'] : [];

        $this->indexerRegistry->sendIndexingMessage([], $skip);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @Since("6.2.0.0")
     * @Route("/api/_action/cache_warmup", name="api.action.cache.delete_and_warmup", methods={"DELETE"}, defaults={"_acl"={"system:clear:cache"}})
     */
    public function clearCacheAndScheduleWarmUp(): Response
    {
        if ($this->cacheWarmer === null) {
            throw new \RuntimeException('Storefront is not installed');
        }

        $this->cacheWarmer->warmUp(Random::getAlphanumericString(32));

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @Since("6.0.0.0")
     * @Route("/api/_action/cache", name="api.action.cache.delete", methods={"DELETE"}, defaults={"_acl"={"system:clear:cache"}})
     */
    public function clearCache(): Response
    {
        $this->cacheClearer->clear();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @Since("6.2.0.0")
     * @Route("/api/_action/cleanup", name="api.action.cache.cleanup", methods={"DELETE"}, defaults={"_acl"={"system:clear:cache"}})
     */
    public function clearOldCacheFolders(): Response
    {
        $this->cacheClearer->scheduleCacheFolderCleanup();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @Since("6.2.0.0")
     * @Route("/api/_action/container_cache", name="api.action.container-cache.delete", methods={"DELETE"}, defaults={"_acl"={"system:clear:cache"}})
     */
    public function clearContainerCache(): Response
    {
        $this->cacheClearer->clearContainerCache();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function getUsedCache(AdapterInterface $adapter): string
    {
        if ($adapter instanceof TagAwareAdapter || $adapter instanceof TraceableAdapter) {
            // Do not declare function as static
            $func = \Closure::bind(function () use ($adapter) {
                return $adapter->pool;
            }, $adapter, \get_class($adapter));

            $adapter = $func();
        }

        if ($adapter instanceof TraceableAdapter) {
            return $this->getUsedCache($adapter);
        }

        $name = \get_class($adapter);
        \assert(\is_string($name));
        $parts = explode('\\', $name);
        $name = str_replace('Adapter', '', end($parts));

        return $name;
    }
}
