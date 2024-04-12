<?php
namespace Muensmedia\PartialContentExport\Service;

use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Package\PackageManager;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\SiteService;
use Neos\Utility\Exception\FilesException;
use Neos\Utility\Files;
use Neos\Neos\Domain\Exception as NeosException;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\ImportExport\NodeExportService;
use XMLWriter;

/**
 * Partial Content Export Service
 *
 * @Flow\Scope("singleton")
 */
class PartialContentExportService
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

    /**
     * @Flow\Inject
     *
     * @var NodeExportService
     */
    protected NodeExportService $nodeExportService;

    /**
     * @Flow\Inject
     *
     * @var NodeDataRepository
     */
    protected NodeDataRepository $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var PackageManager
     */
    protected PackageManager $packageManager;

    /**
     * @Flow\Inject
     * @var NodePathNormalizerService
     */
    protected NodePathNormalizerService $nodePathNormalizer;

    /**
     * Absolute path to exported resources, or NULL if resources should be inlined in the exported XML
     *
     * @var null|string
     */
    protected ?string $resourcesPath = null;

    protected ?array $normalizedSegments = null;

    /**
     * The XMLWriter that is used to construct the export.
     *
     * @var XMLWriter
     */
    protected XMLWriter $xmlWriter;

    /**
     * Exports the content under a given path into a package.
     * @param string $sourcePath
     * @param string $packageKey
     * @param bool $tidy
     * @param string|null $nodeTypeFilter
     * @param string|null $filename
     * @return void
     * @throws FilesException
     * @throws NeosException
     */
    public function exportToPackage(string $sourcePath, string $packageKey, bool $tidy = true, ?string $nodeTypeFilter = null, ?string $filename = null): void
    {
        // Check if the package is available
        if (!$this->packageManager->isPackageAvailable($packageKey))
            throw new NeosException(sprintf('Error: Package "%s" is not active.', $packageKey), 1404375719);

        // Normalize segment path and define XML file name
        $this->normalizedSegments = $this->nodePathNormalizer->normalizeSegments($sourcePath);

        // Generate filename, ensure it always ends with .xml
        $filename ??= sprintf('PartialContent-%s.xml', $this->normalizedSegments[count($this->normalizedSegments)-1]);
        if (!str_ends_with( $filename, '.xml' )) $filename .= '.xml';

        $contentPathAndFilename = sprintf('resource://%s/Private/Content/%s', $packageKey, $filename);

        // Resources path
        $this->resourcesPath = Files::concatenatePaths([dirname($contentPathAndFilename), 'Resources']);
        Files::createDirectoryRecursively($this->resourcesPath);

        // Begin export
        $this->xmlWriter = new XMLWriter();
        $this->xmlWriter->openUri($contentPathAndFilename);
        $this->xmlWriter->setIndent($tidy);

        if ($this->export($nodeTypeFilter))
            $this->xmlWriter->flush();
    }

    /**
     * Exports the content under a given path into specific location.
     * @param string $source
     * @param string $pathAndFilename
     * @param bool $tidy
     * @param string|null $nodeTypeFilter
     * @return void
     * @throws FilesException
     * @throws NeosException
     */
    public function exportToFile(string $source, string $pathAndFilename, bool $tidy = true, ?string $nodeTypeFilter = null): void
    {
        // Normalize segment path
        $this->normalizedSegments = $this->nodePathNormalizer->normalizeSegments($source);

        // Resources path
        $this->resourcesPath = Files::concatenatePaths([dirname($pathAndFilename), 'Resources']);
        Files::createDirectoryRecursively($this->resourcesPath);

        // Begin export
        $this->xmlWriter = new \XMLWriter();
        $this->xmlWriter->openUri($pathAndFilename);
        $this->xmlWriter->setIndent($tidy);

        if ($this->export($nodeTypeFilter))
            $this->xmlWriter->flush();
    }

    /**
     * @param string $sourcePath
     * @param bool $tidy
     * @param string|null $nodeTypeFilter
     * @return string
     * @throws NeosException
     */
    public function exportToString(string $sourcePath, bool $tidy = false, ?string $nodeTypeFilter = null): string
    {
        // Normalize segment path
        $this->normalizedSegments = $this->nodePathNormalizer->normalizeSegments($sourcePath);

        // Export to string
        $this->xmlWriter = new \XMLWriter();
        $this->xmlWriter->openMemory();
        $this->xmlWriter->setIndent($tidy);

        return $this->export($nodeTypeFilter)
            ? $this->xmlWriter->outputMemory(true)
            : '';
    }

    /**
     * @param string|null $nodeTypeFilter
     * @return bool
     * @throws NeosException|NodeException
     */
    protected function export(?string $nodeTypeFilter): bool
    {
        $sites = $this->siteRepository->findByNodeName($this->normalizedSegments[0])->toArray();
        if (count($sites) !== 1) throw new NeosException(sprintf('Error: Unable to locate site "%s".', $this->normalizedSegments[0]), 1706886736);

        /** @var ContentContext $contentContext */
        $contentContext = $this->contextFactory->create([
            'currentSite' => $sites[0],
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ]);
        $fullPath = SiteService::SITES_ROOT_PATH . '/' . implode('/', $this->normalizedSegments);
        $siteNode = $contentContext->getCurrentSiteNode();
        $node = $contentContext->getNode( $fullPath );

        $this->xmlWriter->startDocument('1.0', 'UTF-8');
        $this->xmlWriter->startElement('root');

        $this->xmlWriter->startElement('partial');
        $this->xmlWriter->writeAttribute('siteResourcesPackageKey', $sites[0]->getSiteResourcesPackageKey());
        $this->xmlWriter->writeAttribute('siteNodeName', $siteNode->getName());
        $this->xmlWriter->writeAttribute('info', "PartialExportDummy");
        $this->xmlWriter->writeAttribute('sourcePath', implode('/', $this->normalizedSegments));
        $this->xmlWriter->writeAttribute('sourceIdentifier', $node->getIdentifier());
        $this->xmlWriter->writeAttribute('sourceName', $node->getProperty('title') ?? $node->getProperty('name') ?? $node->getNodeName());
        $this->xmlWriter->writeAttribute('sourceNodeType', $node->getNodeType()->getName());

        $this->nodeExportService->export($fullPath, $contentContext->getWorkspaceName(), $this->xmlWriter, false, false, $this->resourcesPath, $nodeTypeFilter);

        $this->xmlWriter->endElement();

        $this->xmlWriter->endElement();
        $this->xmlWriter->endDocument();

        return true;
    }

}
