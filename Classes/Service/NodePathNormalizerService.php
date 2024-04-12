<?php
namespace Muensmedia\PartialContentExport\Service;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\SiteService;
use Neos\Neos\Domain\Exception as NeosException;

/**
 * Node path helper service.
 *
 * @Flow\Scope("singleton")
 */
class NodePathNormalizerService
{
    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected ContextFactoryInterface $contextFactory;

    /**
     * @Flow\Inject
     *
     * @var SiteRepository
     */
    protected SiteRepository $siteRepository;

    protected function resolveContextFromString(string $siteNode): Context|ContentContext|null {
        $sites = $this->siteRepository->findByNodeName($siteNode)->toArray();
        if (count($sites) !== 1) throw new NeosException(sprintf('Error: Unable to locate site "%s".', $siteNode), 1706886736);

        return $this->contextFactory->create([
            'currentSite' => $sites[0],
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ]);
    }

    protected function resolveContext(Context|string|null $contextOrSite, string $sourcePathOrIdentifier): Context|ContentContext {
        if ($contextOrSite === null)
            $contextOrSite = $this->normalizeSegments( $sourcePathOrIdentifier )[0];

        if (is_string($contextOrSite)) $contextOrSite = $this->resolveContextFromString($contextOrSite);
        return $contextOrSite;
    }

    /**
     * Disassembles a source path into a normalized array. The first element of the array is the site name, the last
     * element is the exported node. Empty segments or a starting /sites/ part are truncated.
     * @param TraversableNodeInterface|string $sourcePath
     * @return array<string>
     * @throws NeosException
     */
    public function normalizeSegments(TraversableNodeInterface|string $sourcePath): array {
        // Resove node
        if (is_a( $sourcePath, TraversableNodeInterface::class ))
            $sourcePath = (string)$sourcePath->findNodePath();

        // If /sites/ is the first segment, remove it
        if (str_starts_with( $sourcePath, SiteService::SITES_ROOT_PATH ))
            $sourcePath = substr( $sourcePath, strlen( SiteService::SITES_ROOT_PATH ) );

        // Explode by /, remove empty segments
        $normalizedSegments = array_values( array_filter( explode( '/', $sourcePath ), fn(string $s) => !empty($s) ) );

        // Ensure we have at least one element in our segments
        if (count($normalizedSegments) < 1) throw new NeosException(sprintf('Error: Invalid path "%s".', $sourcePath), 1706886737);

        return $normalizedSegments;
    }

    /**
     * Normalizes the given path so that it starts with the site name (or root path)
     * @param string $sourcePath
     * @param bool $withRootPath
     * @return string
     * @throws NeosException
     */
    public function getFullNodePath(string $sourcePath, bool $withRootPath = true): string {
        return ($withRootPath ? (SiteService::SITES_ROOT_PATH . '/') : '') . implode('/', $this->normalizeSegments( $sourcePath ));
    }

    /**
     * Takes either a node identifier or node path and returns the associated node, if possible. Returns null if no
     * node s found.
     * @param Context|string|null $contextOrSite Node Context
     * @param string $sourcePathOrIdentifier Node identifier or node path
     * @return NodeInterface|null
     * @throws NeosException
     */
    public function getNodeFromPathOrIdentifier(Context|string|null $contextOrSite, string $sourcePathOrIdentifier): ?TraversableNodeInterface {
        $node = ($context = $this->resolveContext( $contextOrSite, $sourcePathOrIdentifier ))?->getNodeByIdentifier( $sourcePathOrIdentifier );
        if ($node) return $node;

        return $context->getNode( $this->getFullNodePath( $sourcePathOrIdentifier ) );
    }

    /**
     * Takes either a node identifier or node path and returns the node path.
     * @param Context|string|null $contextOrSite Node Context or site node name
     * @param string $sourcePathOrIdentifier Node identifier or node path
     * @return ?string
     * @throws NeosException
     */
    public function toNodePath(Context|string|null $contextOrSite, string $sourcePathOrIdentifier): ?string {
        return $this->getNodeFromPathOrIdentifier( $contextOrSite, $sourcePathOrIdentifier )?->findNodePath();
    }

    /**
     * @param Context|string|null $contextOrSite
     * @param string $sourcePathOrIdentifier
     * @return TraversableNodeInterface[]
     * @throws NeosException
     */
    public function allNodesOnPath( Context|string|null $contextOrSite, string $sourcePathOrIdentifier ): array {
        $context = $this->resolveContext( $contextOrSite, $sourcePathOrIdentifier );
        return $context->getNodesOnPath( $context->getCurrentSiteNode(), $this->toNodePath( $context, $sourcePathOrIdentifier ) );
    }

}
