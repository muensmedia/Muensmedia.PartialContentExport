<?php
namespace Muensmedia\PartialContentExport\Service;

use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Composer\ComposerUtility;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Package\PackageManager;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\SiteService;
use Neos\Utility\Exception\FilesException;
use Neos\Utility\Files;
use Neos\Neos\Domain\Exception as NeosException;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\ImportExport\NodeExportService;
use Sitegeist\Taxonomy\Service\TaxonomyService;
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
    protected $contextFactory;

    /**
     * @Flow\Inject
     *
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     *
     * @var NodeExportService
     */
    protected $nodeExportService;

    /**
     * @Flow\Inject
     *
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var PackageManager
     */
    protected $packageManager;

    /**
     * @Flow\Inject
     * @var NodePathNormalizerService
     */
    protected $nodePathNormalizer;

    /**
     * @var ObjectManagerInterface
     * @Flow\Inject
     */
    protected $objectManager;

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
     * @param string[] $extensions
     * @return void
     * @throws FilesException
     * @throws NeosException
     * @throws NodeException
     */
    public function exportToPackage(string $sourcePath, string $packageKey, bool $tidy = true, ?string $nodeTypeFilter = null, ?string $filename = null, array $extensions = []): void
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

        if ($this->export($nodeTypeFilter,$extensions))
            $this->xmlWriter->flush();
    }

    /**
     * Exports the content under a given path into specific location.
     * @param string $source
     * @param string $pathAndFilename
     * @param bool $tidy
     * @param string|null $nodeTypeFilter
     * @param string[] $extensions
     * @return void
     * @throws FilesException
     * @throws NeosException
     * @throws NodeException
     */
    public function exportToFile(string $source, string $pathAndFilename, bool $tidy = true, ?string $nodeTypeFilter = null, array $extensions = []): void
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

        if ($this->export($nodeTypeFilter, $extensions))
            $this->xmlWriter->flush();
    }

    /**
     * @param string $sourcePath
     * @param bool $tidy
     * @param string|null $nodeTypeFilter
     * @param string[] $extensions
     * @return string
     * @throws NeosException
     * @throws NodeException
     */
    public function exportToString(string $sourcePath, bool $tidy = false, ?string $nodeTypeFilter = null, array $extensions = []): string
    {
        // Normalize segment path
        $this->normalizedSegments = $this->nodePathNormalizer->normalizeSegments($sourcePath);

        // Export to string
        $this->xmlWriter = new \XMLWriter();
        $this->xmlWriter->openMemory();
        $this->xmlWriter->setIndent($tidy);

        return $this->export($nodeTypeFilter, $extensions)
            ? $this->xmlWriter->outputMemory(true)
            : '';
    }

    private function writeExtensionPackage(string $package, callable $callback) {
        $version = ComposerUtility::getPackageVersion($package);
        if (empty($version))
            throw new NeosException(sprintf('Error: Package "%s" is not installed.', $package), 1714134317);

        $this->xmlWriter->startElement('extension');
        $this->xmlWriter->writeAttribute('package', $package);
        $this->xmlWriter->writeAttribute('version', $version);

        $callback();

        $this->xmlWriter->endElement();
    }

    /**
     * @param string|null $nodeTypeFilter
     * @param array $extensions
     * @return bool
     * @throws NeosException
     * @throws NodeException
     */
    protected function export(?string $nodeTypeFilter, array $extensions = []): bool
    {
        $sites = $this->siteRepository->findByNodeName($this->normalizedSegments[0])->toArray();
        if (count($sites) !== 1) throw new NeosException(sprintf('Error: Unable to locate site "%s".', $this->normalizedSegments[0]), 1706886736);

        foreach ($extensions as $package)
            if (empty(ComposerUtility::getPackageVersion($package)))
                throw new NeosException(sprintf('Error: Package "%s" is not installed.', $package), 1714134317);

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

        foreach ($extensions as $extension) {
            switch ($extension) {
                case 'sitegeist/taxonomy':
                    $fn = fn() => $this->exportPackageSitegeistTaxonomy();
                    break;
                default: $fn = null;
            }

            if ($fn !== null) $this->writeExtensionPackage($extension, $fn);
        }

        $this->xmlWriter->endElement();
        $this->xmlWriter->endDocument();

        return true;
    }

    private function exportPackageSitegeistTaxonomy(): void {
        $taxonomyService = $this->objectManager->get(TaxonomyService::class);
        foreach ((new FlowQuery([$taxonomyService->getRoot()]))
                ->children("[instanceof {$taxonomyService->getVocabularyNodeType()}]")
                ->get() as $vocabulary) {

            $this->xmlWriter->startElement('vocabulary');
            $this->xmlWriter->writeAttribute('name', $vocabulary->getName());
            $this->nodeExportService->export($vocabulary->getPath(), 'live', $this->xmlWriter,  false, false);
            $this->xmlWriter->endElement();
        }
    }

}
